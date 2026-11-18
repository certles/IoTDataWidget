<?php
/**
 * Kanały push (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md) — urządzenie samo
 * wysyła wartość do WordPressa zamiast czekać, aż WordPress je odpyta.
 * Rozwiązuje przypadek, w którym WordPress stoi na zwykłym hostingu
 * współdzielonym, a urządzenie jest za NAT-em w sieci domowej — pull nigdy
 * by tam nie dotarł.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Push_Channel_Manager
 *
 * Kanał to zwykłe połączenie (IOTDW_Connector_Manager, connector='push')
 * z dopisanym `token_hash` — reużywa istniejący magazyn połączeń zamiast
 * osobnej tabeli na konfigurację, bo ta się zmienia rzadko (patrz
 * CLAUDE.md/docs/archiwum/mvp-v2-0-push-mode-plan.md §1 o unikaniu spuchniętego
 * wp_options — to dotyczy częstych zapisów, nie tej konfiguracji).
 *
 * Ta klasa zarządza wyłącznie tabelą wp_iotdw_push_entities — CZĘSTO
 * aktualizowanym stanem per (kanał, encja): ostatnia wartość/jednostka/czas
 * odbioru. Wiersz w tej tabeli PEŁNI TEŻ ROLĘ whitelisty — encja, która nie
 * ma tu wiersza, jest z definicji nieznana dla danego kanału i
 * IOTDW_Push_Ingest odrzuca dla niej każdy request. Wiersze są zakładane
 * raz, przy tworzeniu/edycji kanału (patrz set_allowed_entities()), nigdy
 * przez sam ingest.
 *
 * Historia (do wykresów/raportów e-mail/alertów) NIE jest tu — ląduje
 * bezpośrednio w istniejącej wp_iotdw_history przez
 * IOTDW_History_Recorder::record_value(), żeby wykresy/raporty/alerty
 * działały dla push dokładnie tym samym kodem co dla pull, bez
 * rozgałęziania (§6 planu).
 */
class IOTDW_Push_Channel_Manager {

	/**
	 * Nazwa tabeli (bez prefiksu WP).
	 */
	const TABLE = 'iotdw_push_entities';

	/**
	 * Typ konektora zarejestrowany w IOTDW_Connector_Manager.
	 */
	const CONNECTOR_TYPE = 'push';

	/**
	 * Po ilu godzinach bez żadnego odbioru kanał liczy się jako nieaktywny
	 * (§5 planu, "Kanał, który nie odebrał nic od 24h, oznaczyć jako nieaktywny").
	 */
	const INACTIVE_AFTER_HOURS = 24;

	/**
	 * Tworzy tabelę (wołane z IOTDW_Plugin::activate()/maybe_upgrade_db()).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			connection_id VARCHAR(36) NOT NULL,
			entity_key VARCHAR(120) NOT NULL,
			unit VARCHAR(32) NULL,
			last_value_num DOUBLE NULL,
			last_value_txt VARCHAR(255) NULL,
			last_recorded_at DATETIME NULL,
			PRIMARY KEY  (connection_id, entity_key)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Generuje nowy, surowy token kanału — pokazywany użytkownikowi
	 * DOKŁADNIE RAZ, przy tworzeniu/regeneracji (§3 planu). 24 bajty
	 * (192 bity) losowości, ten sam rząd wielkości co IOTDW_Embed::generate_token(),
	 * zakodowane hex, żeby bezpiecznie trafiało w query string (Tasmota
	 * WebSend, patrz §2 planu) bez dodatkowego escapowania.
	 *
	 * @return string
	 */
	public static function generate_token(): string {
		return bin2hex( random_bytes( 24 ) );
	}

	/**
	 * Haszuje surowy token do formy zapisywanej w bazie (§3 planu: "W bazie
	 * wyłącznie hash"). HMAC z solą WP (wp_salt()), nie samo hash('sha256'),
	 * żeby ktoś z dostępem do zrzutu bazy (ale bez sekretów WP z wp-config.php)
	 * nie mógł zbudować tęczowej tabeli dla tokenów tego formatu. Zwykły,
	 * szybki hasz (nie bcrypt/password_hash) celowo — token ma 192 bity
	 * losowości, nie ludzkie hasło, a ten endpoint może być wołany co
	 * kilkanaście sekund przez wiele urządzeń naraz, więc czas haszowania
	 * musi być pomijalny, nie celowo spowolniony.
	 *
	 * @param string $raw_token Surowy token (np. z nagłówka/query stringu żądania).
	 * @return string
	 */
	public static function hash_token( string $raw_token ): string {
		return hash_hmac( 'sha256', $raw_token, wp_salt( 'auth' ) );
	}

	/**
	 * Czy $raw_token pasuje do zapisanego hasza — hash_equals() (nie ===)
	 * celowo, żeby porównanie było odporne na timing attack.
	 *
	 * @param string $raw_token   Token przesłany przez urządzenie.
	 * @param string $stored_hash Zapisany hash z configu kanału.
	 * @return bool
	 */
	public static function verify_token( string $raw_token, string $stored_hash ): bool {
		if ( '' === $raw_token || '' === $stored_hash ) {
			return false;
		}

		return hash_equals( $stored_hash, self::hash_token( $raw_token ) );
	}

	/**
	 * Ustawia listę dozwolonych encji dla kanału — wołane przy
	 * tworzeniu/edycji kanału w kreatorze/adminie, NIGDY przez ingest.
	 * Nowe nazwy dostają wiersz z pustym stanem (nic jeszcze nie odebrano);
	 * nazwy usunięte z listy tracą swój wiersz (i tym samym przestają być
	 * akceptowane, ich dotychczasowa ostatnia wartość znika — świadome,
	 * whitelist to whitelist). Nazwy POZOSTAJĄCE na liście zachowują swój
	 * dotychczasowy stan (nie są kasowane i odtwarzane na pusto).
	 *
	 * @param string   $connection_id ID kanału.
	 * @param string[] $entity_keys   Znormalizowane (patrz IOTDW_Push_Ingest::sanitize_entity_key()) nazwy encji.
	 */
	public function set_allowed_entities( string $connection_id, array $entity_keys ): void {
		global $wpdb;

		$table       = $wpdb->prefix . self::TABLE;
		$entity_keys = array_values( array_unique( array_filter( $entity_keys, 'strlen' ) ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$existing = $wpdb->get_col(
			$wpdb->prepare( "SELECT entity_key FROM {$table} WHERE connection_id = %s", $connection_id )
		);

		foreach ( array_diff( $entity_keys, $existing ) as $new_key ) {
			$wpdb->insert(
				$table,
				array(
					'connection_id' => $connection_id,
					'entity_key'    => $new_key,
				),
				array( '%s', '%s' )
			);
		}

		$removed = array_diff( $existing, $entity_keys );

		if ( ! empty( $removed ) ) {
			$placeholders = implode( ', ', array_fill( 0, count( $removed ), '%s' ) );
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM {$table} WHERE connection_id = %s AND entity_key IN ({$placeholders})",
					array_merge( array( $connection_id ), array_values( $removed ) )
				)
			);
		}
		// phpcs:enable
	}

	/**
	 * Czy $entity_key jest na whitelist kanału — wiersz musi istnieć,
	 * niezależnie od tego, czy ma już jakąś zapisaną wartość.
	 *
	 * @param string $connection_id ID kanału.
	 * @param string $entity_key    Nazwa encji.
	 * @return bool
	 */
	public function is_entity_allowed( string $connection_id, string $entity_key ): bool {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$found = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT 1 FROM ' . $wpdb->prefix . self::TABLE . ' WHERE connection_id = %s AND entity_key = %s LIMIT 1',
				$connection_id,
				$entity_key
			)
		);
		// phpcs:enable

		return null !== $found;
	}

	/**
	 * Zapisuje ostatnią wartość jednej encji kanału — wołane WYŁĄCZNIE dla
	 * encji już obecnych na whiteliście (patrz is_entity_allowed(), sprawdzane
	 * przez wywołującego PRZED tym wywołaniem). $value_num musi być już
	 * znormalizowane (patrz IOTDW_Value_Normalizer) — ta metoda tylko
	 * zapisuje, nie normalizuje.
	 *
	 * @param string      $connection_id ID kanału.
	 * @param string      $entity_key    Nazwa encji.
	 * @param float       $value_num     Znormalizowana wartość liczbowa.
	 * @param string|null $display_value Czytelny tekst (dla stanów logicznych), albo null dla czysto liczbowych.
	 * @param string|null $unit          Jednostka, jeśli podana w żądaniu.
	 */
	public function record_reading( string $connection_id, string $entity_key, float $value_num, ?string $display_value, ?string $unit ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::TABLE,
			array(
				'unit'             => $unit,
				'last_value_num'   => $value_num,
				'last_value_txt'   => $display_value,
				'last_recorded_at' => current_time( 'mysql', true ),
			),
			array(
				'connection_id' => $connection_id,
				'entity_key'    => $entity_key,
			),
			array( '%s', '%f', '%s', '%s' ),
			array( '%s', '%s' )
		);
	}

	/**
	 * Zwraca zapisany stan jednej encji kanału (do renderowania widgetu przez
	 * IOTDW_Push_Channel_Connector) — null, jeśli encja nie istnieje na
	 * whiteliście tego kanału w ogóle.
	 *
	 * @param string $connection_id ID kanału.
	 * @param string $entity_key    Nazwa encji.
	 * @return array{unit: string|null, last_value_num: float|null, last_value_txt: string|null, last_recorded_at: string|null}|null
	 */
	public function get_entity_state( string $connection_id, string $entity_key ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT unit, last_value_num, last_value_txt, last_recorded_at FROM ' . $wpdb->prefix . self::TABLE . ' WHERE connection_id = %s AND entity_key = %s',
				$connection_id,
				$entity_key
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( null === $row ) {
			return null;
		}

		return array(
			'unit'             => $row['unit'],
			'last_value_num'   => null !== $row['last_value_num'] ? (float) $row['last_value_num'] : null,
			'last_value_txt'   => $row['last_value_txt'],
			'last_recorded_at' => $row['last_recorded_at'],
		);
	}

	/**
	 * Zwraca wszystkie zadeklarowane encje kanału (whitelist), z ich
	 * bieżącym stanem — do listy kanałów w adminie i do ekranu "sprawdź, czy
	 * dane docierają".
	 *
	 * @param string $connection_id ID kanału.
	 * @return array<int, array{entity_key: string, unit: string|null, last_value_num: float|null, last_value_txt: string|null, last_recorded_at: string|null}>
	 */
	public function get_entities_for_channel( string $connection_id ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT entity_key, unit, last_value_num, last_value_txt, last_recorded_at FROM ' . $wpdb->prefix . self::TABLE . ' WHERE connection_id = %s ORDER BY entity_key ASC',
				$connection_id
			),
			ARRAY_A
		);
		// phpcs:enable

		return array_map(
			static function ( $row ) {
				$row['last_value_num'] = null !== $row['last_value_num'] ? (float) $row['last_value_num'] : null;
				return $row;
			},
			$rows
		);
	}

	/**
	 * Czas ostatniego odbioru DOWOLNEJ encji kanału (do statusu na liście
	 * kanałów) — null, jeśli kanał nigdy niczego nie odebrał.
	 *
	 * @param string $connection_id ID kanału.
	 * @return string|null Data w formacie MySQL (UTC), albo null.
	 */
	public function get_last_received_at( string $connection_id ): ?string {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$value = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT MAX(last_recorded_at) FROM ' . $wpdb->prefix . self::TABLE . ' WHERE connection_id = %s',
				$connection_id
			)
		);
		// phpcs:enable

		return $value ? $value : null;
	}

	/**
	 * Czy kanał odebrał cokolwiek w ciągu ostatnich INACTIVE_AFTER_HOURS —
	 * §5 planu, status "nieaktywny" na liście kanałów.
	 *
	 * @param string $connection_id ID kanału.
	 * @return bool
	 */
	public function is_channel_active( string $connection_id ): bool {
		$last_received_at = $this->get_last_received_at( $connection_id );

		if ( null === $last_received_at ) {
			return false;
		}

		return ( time() - strtotime( $last_received_at . ' UTC' ) ) < ( self::INACTIVE_AFTER_HOURS * HOUR_IN_SECONDS );
	}
}
