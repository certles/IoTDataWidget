<?php
/**
 * Centralny log statusu połączeń i błędów (v1.6 Tydzień 1).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Diagnostics_Logger
 *
 * Zapisywany WYŁĄCZNIE przez IOTDW_Cache::remember() na cache-miss, nigdy
 * przez poszczególne klasy konektorów — to jedyne miejsce, przez które
 * przechodzi każdy typ połączenia (HA, REST, presety, MQTT), więc przyszłe
 * konektory dostają logowanie statusu za darmo, bez zmian w swoim kodzie.
 * Patrz docs/archiwum/mvp-v1-6-plan.md, Ustalenie #1.
 */
class IOTDW_Diagnostics_Logger {

	/**
	 * Nazwy tabel (bez prefiksu WP).
	 */
	const TABLE_CONNECTION_STATUS = 'iotdw_connection_status';
	const TABLE_ERROR_LOG         = 'iotdw_error_log';

	/**
	 * Ile najnowszych wpisów logu błędów zachować — reszta jest przycinana
	 * przez rozszerzony IOTDW_History_Recorder::cleanup() (v1.6 Tydzień 2,
	 * Ustalenie #4 — reużycie istniejącego crona, nie nowy).
	 */
	const ERROR_LOG_LIMIT = 200;

	/**
	 * Próg kolejnych błędów, od którego połączenie liczy się jako
	 * persystentny problem, nie jednorazowy błąd (v1.6 Tydzień 4, sekcja
	 * "Connections" strony diagnostyki — wizualne wyróżnienie).
	 */
	const PERSISTENT_ERROR_THRESHOLD = 5;

	/**
	 * Tworzy tabele statusu połączeń i logu błędów (wołane z
	 * IOTDW_Plugin::activate() oraz tests/bootstrap.php).
	 *
	 * Uwaga: connection_id celowo VARCHAR(36), NIE BIGINT UNSIGNED jak w
	 * pierwotnym przykładzie z planu — ID połączeń w tym pluginie to string
	 * UUID (wp_generate_uuid4(), patrz IOTDW_Admin_Page::handle_save()),
	 * dokładnie jak w iotdw_history/iotdw_alert_rules (ten sam zastrzeżony
	 * komentarz już raz zapisany w IOTDW_Alert_Manager::create_table() dla
	 * identycznej niezgodności planu v1.2 ze schematem reszty wtyczki).
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$status_table    = $wpdb->prefix . self::TABLE_CONNECTION_STATUS;
		$error_table     = $wpdb->prefix . self::TABLE_ERROR_LOG;

		$sql = "CREATE TABLE {$status_table} (
			connection_id VARCHAR(36) NOT NULL,
			last_checked_at DATETIME NOT NULL,
			last_status ENUM('ok','error') NOT NULL,
			last_error_message TEXT NULL,
			consecutive_errors INT UNSIGNED NOT NULL DEFAULT 0,
			PRIMARY KEY  (connection_id)
		) {$charset_collate};
		CREATE TABLE {$error_table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			occurred_at DATETIME NOT NULL,
			source VARCHAR(100) NOT NULL,
			message TEXT NOT NULL,
			PRIMARY KEY  (id),
			KEY occurred_at (occurred_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Zapisuje wynik realnego (niecachowanego) odczytu jednego połączenia —
	 * wołane wyłącznie z IOTDW_Cache::remember() na cache-miss, i tylko gdy
	 * wywołujący przekazał $connection_id. Puste $connection_id (np.
	 * IOTDW_Rest_Api::get_entities() — lista encji, nie odczyt wartości —
	 * albo efemeryczny test połączenia z kreatora, który nigdy nie ma
	 * jeszcze zapisanego ID) świadomie NIE trafia do statusu połączeń, patrz
	 * Ustalenie #1.
	 *
	 * @param string $connection_id ID połączenia (UUID).
	 * @param array  $data          Znormalizowana odpowiedź konektora (kontrakt z CLAUDE.md) — liczy się tylko klucz 'error'.
	 */
	public static function record_connection_result( string $connection_id, array $data ): void {
		if ( '' === $connection_id ) {
			return;
		}

		global $wpdb;

		$table    = $wpdb->prefix . self::TABLE_CONNECTION_STATUS;
		$is_error = ! empty( $data['error'] );

		// Atomowy INSERT ... ON DUPLICATE KEY UPDATE (code review v1.8) —
		// POPRZEDNIO osobny SELECT (odczyt consecutive_errors) + osobny
		// REPLACE (zapis przeliczonej wartości) miało realny wyścig: dwa
		// równoległe cache-missy dla tego samego połączenia (np. dwa
		// żądania strony akurat przy wygaśnięciu 60s cache'a) mogły oba
		// przeczytać tę samą starą wartość i oba zapisać swoje +1/reset, po
		// cichu gubiąc jeden przyrost. Jedno atomowe zapytanie liczy
		// consecutive_errors względem AKTUALNEGO wiersza w bazie w momencie
		// zapisu (MySQL/MariaDB gwarantuje to atomowo), nie względem
		// wartości odczytanej wcześniej w osobnym zapytaniu PHP.
		//
		// NULLIF(%s, '') zamiast przekazania PHP null pod %s — $wpdb->prepare()
		// (w przeciwieństwie do $wpdb->insert()/replace()) NIE zamienia null
		// na SQL NULL dla %s, tylko na pusty string, co zepsułoby
		// `null !== $connection['last_error_message']` gdzie indziej w
		// kodzie (np. IOTDW_Diagnostics_Page::build_report_text()). Pusty
		// string nigdy nie jest prawdziwym komunikatem błędu (redact_secrets()
		// na niepustym wejściu nigdy nie zwraca pustego stringu), więc
		// NULLIF() bezpiecznie odróżnia "brak błędu" od każdego realnego komunikatu.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (connection_id, last_checked_at, last_status, last_error_message, consecutive_errors)
				VALUES (%s, %s, %s, NULLIF(%s, ''), %d)
				ON DUPLICATE KEY UPDATE
					last_checked_at = VALUES(last_checked_at),
					last_status = VALUES(last_status),
					last_error_message = VALUES(last_error_message),
					consecutive_errors = IF(VALUES(last_status) = 'error', consecutive_errors + 1, 0)",
				$connection_id,
				current_time( 'mysql', true ),
				$is_error ? 'error' : 'ok',
				$is_error ? self::redact_secrets( (string) $data['error'] ) : '',
				$is_error ? 1 : 0
			)
		);
		// phpcs:enable

		if ( $is_error ) {
			self::log_error( 'connection:' . $connection_id, (string) $data['error'] );
		}
	}

	/**
	 * Zwraca AKTUALNĄ (sprzed tego odczytu) liczbę kolejnych błędów jednego
	 * połączenia — do wykładniczego backoffu w IOTDW_Cache (zgłoszenie
	 * 2026-08-31, "P1#8": urządzenie z Tasmotą kilkukrotnie nie odpowiadało,
	 * m.in. przez watchdog restart z braku pamięci — im dłużej połączenie
	 * jest w stanie błędu, tym rzadziej powinniśmy je odpytywać, zamiast
	 * bić w martwe/restartujące się urządzenie co stały, krótki interwał).
	 * Lekki, pojedynczy SELECT — celowo NIE woła get_all_connection_statuses()
	 * (całą tabelę), bo to wołane na KAŻDYM cache-missie z niepustym
	 * connection_id, więc musi być tanie. 0 dla połączenia bez wpisu w ogóle
	 * (jeszcze nigdy nie odpytane naprawdę) — nie błąd, tylko "brak historii
	 * błędów jeszcze", dokładnie jak przy pierwszym odczycie.
	 *
	 * @param string $connection_id ID połączenia (UUID).
	 * @return int
	 */
	public static function get_consecutive_errors( string $connection_id ): int {
		if ( '' === $connection_id ) {
			return 0;
		}

		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$count = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT consecutive_errors FROM ' . $wpdb->prefix . self::TABLE_CONNECTION_STATUS . ' WHERE connection_id = %s',
				$connection_id
			)
		);
		// phpcs:enable

		return null !== $count ? (int) $count : 0;
	}

	/**
	 * Zwraca status wszystkich połączeń, które kiedykolwiek miały realny
	 * (niecachowany) odczyt — indeksowane po connection_id (v1.6 Tydzień 4,
	 * sekcja "Connections" strony diagnostyki). Połączenie skonfigurowane,
	 * ale jeszcze nigdy nie odpytane (np. świeżo dodane, bez żadnego widgetu
	 * na opublikowanej stronie) po prostu nie ma tu wpisu — wywołujący musi
	 * to rozróżnić od realnego błędu, nie zakładać "brak wiersza = błąd".
	 * last_error_message jest tu redagowany drugi raz (patrz redact_secrets()
	 * — Ustalenie #3 wymaga tego zarówno przy zapisie, jak i przy każdym
	 * wyświetleniu).
	 *
	 * @return array<string, array{connection_id: string, last_checked_at: string, last_status: string, last_error_message: string|null, consecutive_errors: int}>
	 */
	public static function get_all_connection_statuses(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE_CONNECTION_STATUS, ARRAY_A );

		foreach ( $rows as &$row ) {
			if ( null !== $row['last_error_message'] ) {
				$row['last_error_message'] = self::redact_secrets( $row['last_error_message'] );
			}
		}
		unset( $row );

		return array_column( $rows, null, 'connection_id' );
	}

	/**
	 * Zwraca najnowsze wpisy ogólnego logu błędów, najnowsze pierwsze (v1.6
	 * Tydzień 5, sekcja "Recent errors" strony diagnostyki) — redagowane
	 * drugi raz przy odczycie, ten sam powód co get_all_connection_statuses().
	 * $limit ogranicza tylko to, co pokazuje strona — niezależne od
	 * ERROR_LOG_LIMIT (ile wierszy w ogóle trzyma baza, patrz Tydzień 2).
	 *
	 * @param int $limit Maksymalna liczba wpisów do zwrócenia.
	 * @return array<int, array{id: int, occurred_at: string, source: string, message: string}>
	 */
	public static function get_recent_errors( int $limit = 50 ): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE_ERROR_LOG . ' ORDER BY id DESC LIMIT %d', $limit ),
			ARRAY_A
		);
		// phpcs:enable

		foreach ( $rows as &$row ) {
			$row['message'] = self::redact_secrets( $row['message'] );
		}
		unset( $row );

		return $rows;
	}

	/**
	 * Prefiks nazwy opcji WP przechowującej wynik ostatniego uruchomienia
	 * danego hooka crona (v1.6 Tydzień 2) — jedna opcja per hook, nie tabela:
	 * to stała, mała liczba hooków (4), więc opcja jest prostsza i tańsza niż
	 * osobna tabela na coś, co zawsze ma dokładnie jeden aktualny wiersz na
	 * hook.
	 */
	const CRON_STATUS_OPTION_PREFIX = 'iotdw_cron_status_';

	/**
	 * Wykonuje $callback i loguje wynik pod $hook — sukces, gdy $callback nie
	 * rzuci wyjątku. Owija rejestrację `add_action()` istniejących callbacków
	 * cronowych (patrz IOTDW_History_Recorder/IOTDW_Cron_Value_Cache), zamiast
	 * wklejać try/catch do wnętrza każdej z metod osobno — jedno miejsce,
	 * ten sam mechanizm dla każdego crona, a błąd wewnątrz callbacka trafia
	 * do logu zamiast zniknąć jako niezalogowany fatal error (Tydzień 2,
	 * kryterium akceptacji).
	 *
	 * $callback może OPCJONALNIE zwrócić array{success: bool, message?: string}
	 * (code review v1.8, dodane dla IOTDW_License_Manager — jedynego crona
	 * z bogatszymi stanami niż samo sukces/wyjątek: "brak klucza", "jeszcze
	 * nie pora", "zweryfikowano", "błąd sieci", każdy z własnym komunikatem
	 * sukcesu, nie tylko komunikatem błędu z wyjątku). Zwrócona wartość, gdy
	 * obecna, całkowicie zastępuje domyślne "sukces bez komunikatu" — brak
	 * zwróconej wartości (dwaj pozostali wywołujący, History Recorder/MQTT
	 * Poller) zachowuje się dokładnie jak wcześniej.
	 *
	 * @param string   $hook     Hook crona, np. IOTDW_History_Recorder::SNAPSHOT_HOOK.
	 * @param callable $callback Właściwy callback crona do wykonania — opcjonalnie zwraca array{success: bool, message?: string}.
	 */
	public static function run_cron( string $hook, callable $callback ): void {
		try {
			$result = $callback();

			if ( is_array( $result ) && isset( $result['success'] ) ) {
				self::record_cron_run( $hook, (bool) $result['success'], (string) ( $result['message'] ?? '' ) );
				return;
			}

			self::record_cron_run( $hook, true );
		} catch ( \Throwable $e ) {
			self::record_cron_run( $hook, false, $e->getMessage() );
		}
	}

	/**
	 * Zapisuje wynik jednego uruchomienia crona — timestamp, sukces/błąd i
	 * opcjonalny krótki komunikat. Błąd dodatkowo trafia do ogólnego logu
	 * błędów (source "cron:{hook}"), tak jak błędy połączeń.
	 *
	 * @param string $hook    Hook crona.
	 * @param bool   $success Czy to uruchomienie zakończyło się powodzeniem.
	 * @param string $message Krótki komunikat — puste przy sukcesie bez uwag.
	 */
	public static function record_cron_run( string $hook, bool $success, string $message = '' ): void {
		$message = self::redact_secrets( $message );

		update_option(
			self::CRON_STATUS_OPTION_PREFIX . $hook,
			array(
				'ran_at'  => current_time( 'mysql', true ),
				'success' => $success,
				'message' => $message,
			),
			false
		);

		if ( ! $success ) {
			self::log_error( 'cron:' . $hook, $message );
		}
	}

	/**
	 * Zwraca wynik ostatniego uruchomienia danego hooka crona (v1.6 Tydzień
	 * 3 — sekcja "Zaplanowane zadania" na stronie diagnostyki), albo null,
	 * gdy hook jeszcze nigdy się nie wykonał.
	 *
	 * @param string $hook Hook crona.
	 * @return array{ran_at: string, success: bool, message: string}|null
	 */
	public static function get_cron_status( string $hook ): ?array {
		$value = get_option( self::CRON_STATUS_OPTION_PREFIX . $hook, null );

		return is_array( $value ) ? $value : null;
	}

	/**
	 * Dopisuje wpis do ogólnego logu błędów (crony w v1.6 Tydzień 2 wołają
	 * to pośrednio, przez record_cron_run(); connection_status — bezpośrednio,
	 * patrz record_connection_result()). Redakcja sekretów dzieje się TUTAJ,
	 * przed zapisem — patrz Ustalenie #3.
	 *
	 * @param string $source  Źródło błędu, np. "connection:{id}" albo "cron:{hook}".
	 * @param string $message Surowy komunikat błędu (redagowany wewnątrz tej metody).
	 */
	public static function log_error( string $source, string $message ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::TABLE_ERROR_LOG,
			array(
				'occurred_at' => current_time( 'mysql', true ),
				'source'      => $source,
				'message'     => self::redact_secrets( $message ),
			),
			array( '%s', '%s', '%s' )
		);
	}

	/**
	 * Usuwa z komunikatu wszystko, co wygląda na token/klucz/hasło —
	 * obowiązkowe przed KAŻDYM zapisem do logu i przed KAŻDYM wyświetleniem
	 * w UI (Ustalenie #3) — druga redakcja przy odczycie (strona diagnostyki,
	 * v1.6 Tydzień 4-6) jest podwójnym zabezpieczeniem na wypadek gdyby coś
	 * trafiło do bazy nieredagowane zanim ta funkcja powstała, nie
	 * zastępstwem dla redakcji przy zapisie.
	 *
	 * @param string $message Surowy komunikat.
	 * @return string
	 */
	public static function redact_secrets( string $message ): string {
		// "klucz=wartość" w query stringu / treści formularza, gdzie nazwa
		// klucza sugeruje sekret (nie łapie zwykłego "id=42" czy "foo=bar").
		$redacted = preg_replace(
			'/((?:token|api[_-]?key|apikey|secret|password|passwd|pwd|auth_key|access_token)=)[^&\s"\']+/i',
			'$1[REDACTED]',
			$message
		);
		$message  = null !== $redacted ? $redacted : $message;

		// Nagłówek "Authorization: Bearer/Basic xxx" wklejony w treść komunikatu.
		$redacted = preg_replace(
			'/(Authorization:\s*(?:Bearer|Basic)\s+)\S+/i',
			'$1[REDACTED]',
			$message
		);
		$message  = null !== $redacted ? $redacted : $message;

		// Dowolny INNY nagłówek, którego NAZWA sugeruje sekret, np.
		// "X-API-Key: abc123" (code review v1.8) — wcześniej ta funkcja
		// łapała wyłącznie "Authorization: Bearer/Basic", mimo że
		// IOTDW_Rest_Connector jawnie dokumentuje dowolny nagłówek
		// (np. "X-API-Key: xxx") jako obsługiwany format pola "token".
		// Wyklucza jawnie "Authorization:" (już obsłużone wzorcem wyżej, który
		// zjada CAŁY token PO słowie Bearer/Basic) — bez tego wykluczenia ten
		// wzorzec dopasowałby się od nowa do już zredagowanej linii i zjadłby
		// samo słowo "Bearer"/"Basic", dając mylące
		// "Authorization: [REDACTED] [REDACTED]" zamiast "Authorization: Bearer [REDACTED]"
		// (code review v1.8 — pierwsza wersja tego komentarza uznawała to za
		// nieszkodliwie kosmetyczne, ale test pokazał realną regresję formatu).
		$redacted = preg_replace(
			'/(?!Authorization:)([\w-]*(?:key|token|secret|auth)[\w-]*:\s*)\S+/i',
			'$1[REDACTED]',
			$message
		);
		$message  = null !== $redacted ? $redacted : $message;

		// Link "bezpośredni" Supla (preset 'supla_direct', poprawka
		// 2026-08-28) niesie klucz dostępu wprost W ŚCIEŻCE URL-a
		// (/state/{channel}/{HASH}/read), nie w query stringu ani nagłówku —
		// żaden ze wzorców wyżej go nie łapie. Struktura ścieżki jest stała
		// (dokumentacja Supla Cloud), więc rozpoznawalna bez fałszywych
		// trafień na inne domeny/URL-e.
		$redacted = preg_replace(
			'#(supla\.org/state/\d+/)[^/\s]+(/read)#i',
			'$1[REDACTED]$2',
			$message
		);

		return null !== $redacted ? $redacted : $message;
	}
}
