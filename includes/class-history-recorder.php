<?php
/**
 * Zbiera i czyści historyczne wartości encji śledzonych w widgetach
 * chart/sparkline (patrz IOTDW_Widget_Usage_Tracker).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_History_Recorder
 */
class IOTDW_History_Recorder {

	/**
	 * Nazwa tabeli (bez prefiksu WP).
	 */
	const TABLE = 'iotdw_history';

	/**
	 * Nazwy opcji sterujących interwałem i retencją — celowo nie hardkodowane,
	 * żeby dało się je zmienić (docelowo z poziomu UI, poza zakresem tego tygodnia).
	 */
	const OPTION_INTERVAL_MINUTES = 'iotdw_history_interval_minutes';
	const OPTION_RETENTION_DAYS   = 'iotdw_history_retention_days';

	const DEFAULT_INTERVAL_MINUTES = 15;
	const DEFAULT_RETENTION_DAYS   = 30;

	/**
	 * Nazwa niestandardowego harmonogramu WP-Cron rejestrowanego przez ten config.
	 */
	const CRON_SCHEDULE = 'iotdw_history_interval';

	/**
	 * Hooki cron.
	 */
	const SNAPSHOT_HOOK = 'iotdw_snapshot_history';
	const CLEANUP_HOOK  = 'iotdw_cleanup_history';

	/**
	 * Menedżer konektorów.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager $connector_manager Menedżer konektorów.
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager ) {
		$this->connector_manager = $connector_manager;

		add_filter( 'cron_schedules', array( __CLASS__, 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		// Owinięte w IOTDW_Diagnostics_Logger::run_cron() (v1.6 Tydzień 2) —
		// loguje wynik uruchomienia i łapie wyjątki, bez wklejania try/catch
		// do wnętrza snapshot_all()/cleanup() samych w sobie. snapshot_all()/
		// cleanup() zostają wywoływalne bezpośrednio (testy, ręczne
		// wywołania) bez efektu ubocznego logowania.
		add_action(
			self::SNAPSHOT_HOOK,
			function () {
				IOTDW_Diagnostics_Logger::run_cron( self::SNAPSHOT_HOOK, array( $this, 'snapshot_all' ) );
			}
		);
		add_action(
			self::CLEANUP_HOOK,
			function () {
				IOTDW_Diagnostics_Logger::run_cron( self::CLEANUP_HOOK, array( $this, 'cleanup' ) );
			}
		);
	}

	/**
	 * Tworzy tabelę historii (wołane z IOTDW_Plugin::activate()).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id VARCHAR(36) NOT NULL,
			entity_id VARCHAR(191) NOT NULL,
			value DECIMAL(12,4) NOT NULL,
			recorded_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY connection_entity_recorded (connection_id, entity_id, recorded_at)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Rejestruje niestandardowy harmonogram WP-Cron z interwałem czytanym
	 * z opcji — dzięki temu zmiana opcji realnie zmienia częstotliwość
	 * kolejnych odpaleń, bez potrzeby ręcznego przeplanowania zadania.
	 *
	 * @param array $schedules Zarejestrowane harmonogramy WP-Cron.
	 * @return array
	 */
	public static function register_cron_schedule( array $schedules ): array {
		$minutes = max( 1, (int) get_option( self::OPTION_INTERVAL_MINUTES, self::DEFAULT_INTERVAL_MINUTES ) );

		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: liczba minut */
			'display'  => sprintf( __( 'Every %d minutes (IoT Data Widget history)', 'iot-data-widget' ), $minutes ),
		);

		return $schedules;
	}

	/**
	 * Pobiera i zapisuje bieżącą wartość jednej pary (połączenie, encja).
	 * Wartości nieliczbowe (np. stan przełącznika "on"/"off") i błędy
	 * konektora są pomijane — historia śledzi tylko liczby do wykresów.
	 *
	 * @param string $connection_id ID (albo nazwa) połączenia.
	 * @param string $entity_id     ID encji / ścieżka JSON.
	 */
	public function record_snapshot( string $connection_id, string $entity_id ): void {
		$connector = $this->connector_manager->get_connector_for( $connection_id );

		if ( null === $connector ) {
			return;
		}

		try {
			$data = $connector->fetch_data( $entity_id );
		} catch ( \Throwable $e ) {
			return;
		}

		if ( ! empty( $data['error'] ) || ! is_numeric( $data['value'] ) || IOTDW_Value_Normalizer::is_unavailable( $data['display_value'] ?? null ) ) {
			return;
		}

		$this->record_value( $connection_id, $entity_id, (float) $data['value'], $data );
	}

	/**
	 * Zapisuje JUŻ ZNANĄ, znormalizowaną wartość liczbową do historii —
	 * wydzielone z record_snapshot() (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md
	 * §6, "Historia z pulla i z pusha ląduje w tej samej tabeli") — pull
	 * (record_snapshot(), cron) musi najpierw ODPYTAĆ konektor, żeby poznać
	 * wartość; push (IOTDW_Push_Ingest) ją już ma w momencie żądania
	 * urządzenia, więc woła tę metodę wprost, bez pośredniego fetch_data().
	 * Oba wywołujący dostają identyczne zachowanie: ten sam zapis do
	 * wp_iotdw_history (więc wykresy/raporty e-mail/retencja działają dla
	 * obu bez rozgałęziania kodu) i ten sam hook 'iotdw_snapshot_recorded'
	 * (więc alerty progowe reagują na push dokładnie tak samo jak na pull,
	 * za darmo, bez zmian w IOTDW_Alert_Manager/IOTDW_Alert_Notifier).
	 *
	 * @param string                                                                                                                                      $connection_id ID połączenia/kanału.
	 * @param string                                                                                                                                      $entity_id     ID encji / nazwa encji push.
	 * @param float                                                                                                                                       $value         Już znormalizowana wartość liczbowa.
	 * @param array{value?: mixed, unit?: string|null, label?: string|null, last_updated?: string|null, error?: string|null, display_value?: string|null} $data Pełny kontekst do przekazania w hooku (label/unit/display_value) — puste pola dopuszczalne, wywołujący nie zawsze ma je wszystkie.
	 */
	public function record_value( string $connection_id, string $entity_id, float $value, array $data = array() ): void {
		global $wpdb;

		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::TABLE,
			array(
				'connection_id' => $connection_id,
				'entity_id'     => $entity_id,
				'value'         => $value,
				'recorded_at'   => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%f', '%s' )
		);

		/**
		 * Odpala się po każdym udanym, liczbowym zapisie do historii —
		 * IOTDW_Alert_Manager (v1.2 Tydzień 7) porównuje tę wartość z
		 * regułami alertów dla tej pary. Zero bezpośredniej zależności w tę
		 * stronę: ten plik nic nie wie o alertach, tylko odpala hook.
		 * $data (pełna odpowiedź konektora — label/unit/display_value) jest
		 * przekazywana dalej, żeby IOTDW_Alert_Notifier (v1.2 Tydzień 8) mógł
		 * zbudować czytelną treść powiadomienia bez drugiego zapytania do API.
		 *
		 * @param string                                                                                            $connection_id ID połączenia.
		 * @param string                                                                                            $entity_id     ID encji / ścieżka JSON.
		 * @param float                                                                                             $value         Zapisana wartość liczbowa.
		 * @param array{value: mixed, unit: string|null, label: string|null, last_updated: string|null, error: string|null, display_value: string|null} $data Pełna odpowiedź konektora.
		 */
		do_action( 'iotdw_snapshot_recorded', $connection_id, $entity_id, $value, $data );
	}

	/**
	 * Odpala snapshot dla sumy dwóch źródeł par (połączenie, encja):
	 * widgetów chart/sparkline (potrzebują historii do wykresu) i reguł
	 * alertów (v1.2 Tydzień 7 — próg może dotyczyć encji, która nigdzie nie
	 * jest wyświetlana, więc alert-only pary też muszą być odpytywane przez
	 * ten sam cron, nie osobny równoległy).
	 */
	public function snapshot_all(): void {
		global $wpdb;

		$usage_table = $wpdb->prefix . IOTDW_Widget_Usage_Tracker::TABLE;
		$alert_table = $wpdb->prefix . IOTDW_Alert_Manager::TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$pairs = $wpdb->get_results(
			"SELECT DISTINCT connection_id, entity_id FROM {$usage_table} WHERE style IN ('chart','sparkline')
			UNION
			SELECT DISTINCT connection_id, entity_id FROM {$alert_table}"
		);
		// phpcs:enable

		$keys = array();

		foreach ( $pairs as $pair ) {
			$keys[ $pair->connection_id . '|' . $pair->entity_id ] = array( $pair->connection_id, $pair->entity_id );
		}

		// Wykresy w obszarach widgetów motywu (1.0.2) — nie ma ich w tabeli.
		foreach ( ( new IOTDW_Widget_Usage_Tracker( $this->connector_manager ) )->widget_area_usage() as $pair ) {
			if ( in_array( $pair['style'], array( 'chart', 'sparkline' ), true ) ) {
				$keys[ $pair['connection_id'] . '|' . $pair['entity_id'] ] = array( $pair['connection_id'], $pair['entity_id'] );
			}
		}

		foreach ( $keys as list( $connection_id, $entity_id ) ) {
			// Kanały push zapisuje do historii IOTDW_Push_Ingest przy każdym
			// odczycie — snapshot z crona dublował te wiersze co 15 min
			// (przegląd demo 2026-10-22). Jedno źródło: push.
			$connection = $this->connector_manager->find_by_id_or_name( $connection_id );

			if ( null !== $connection && IOTDW_Push_Channel_Manager::CONNECTOR_TYPE === (string) ( $connection['connector'] ?? '' ) ) {
				continue;
			}

			$this->record_snapshot( $connection_id, $entity_id );
		}
	}

	/**
	 * Zwraca chronologicznie posortowaną historię wartości dla pary
	 * (połączenie, encja) z ostatniego okresu — dane wejściowe dla renderera
	 * stylu "chart"/"sparkline". Nieznany $range cicho spada na domyślne 24h,
	 * zamiast błędu — to tylko zakres wykresu, nie coś krytycznego.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $entity_id     ID encji / ścieżka JSON.
	 * @param string $range         Jedno z: "1h", "6h", "24h", "7d", "30d".
	 * @return array<int, array{t: string, v: float}>
	 */
	public function get_history( string $connection_id, string $entity_id, string $range = '24h' ): array {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', time() - $this->range_to_seconds( $range ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT value, recorded_at FROM {$wpdb->prefix}" . self::TABLE . ' WHERE connection_id = %s AND entity_id = %s AND recorded_at >= %s ORDER BY recorded_at ASC',
				$connection_id,
				$entity_id,
				$since
			)
		);
		// phpcs:enable

		return array_map(
			static function ( $row ) {
				return array(
					't' => $row->recorded_at,
					'v' => (float) $row->value,
				);
			},
			$rows
		);
	}

	/**
	 * Tłumaczy skrót zakresu na liczbę sekund wstecz od teraz.
	 *
	 * @param string $range Jedno z: "1h", "6h", "24h", "7d", "30d".
	 * @return int
	 */
	private function range_to_seconds( string $range ): int {
		$ranges = array(
			'1h'  => HOUR_IN_SECONDS,
			'6h'  => 6 * HOUR_IN_SECONDS,
			'24h' => DAY_IN_SECONDS,
			'7d'  => 7 * DAY_IN_SECONDS,
			'30d' => 30 * DAY_IN_SECONDS,
		);

		return $ranges[ $range ] ?? DAY_IN_SECONDS;
	}

	/**
	 * Usuwa wpisy historii starsze niż skonfigurowany okres retencji.
	 */
	public function cleanup(): void {
		global $wpdb;

		$days = max( 1, (int) get_option( self::OPTION_RETENTION_DAYS, self::DEFAULT_RETENTION_DAYS ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->prefix}" . self::TABLE . ' WHERE recorded_at < %s',
				gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) )
			)
		);
		// phpcs:enable

		$this->trim_error_log();
	}

	/**
	 * Przycina log błędów diagnostyki do IOTDW_Diagnostics_Logger::ERROR_LOG_LIMIT
	 * najnowszych wpisów (v1.6 Tydzień 2, Ustalenie #4) — reużywa ten sam
	 * cron sprzątający zamiast dodawać kolejny hook do zarządzania. Podzapytanie
	 * z aliasem (zamiast prostego "id NOT IN (SELECT id FROM tej samej tabeli
	 * ORDER BY ... LIMIT ...)") jest konieczne — MySQL nie pozwala bezpośrednio
	 * SELECT-ować z tabeli, którą się właśnie DELETE-uje.
	 */
	private function trim_error_log(): void {
		global $wpdb;

		$error_table = $wpdb->prefix . IOTDW_Diagnostics_Logger::TABLE_ERROR_LOG;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query(
			$wpdb->prepare(
				'DELETE FROM ' . $error_table . ' WHERE id NOT IN ( SELECT id FROM ( SELECT id FROM ' . $error_table . ' ORDER BY id DESC LIMIT %d ) AS keep_ids )',
				IOTDW_Diagnostics_Logger::ERROR_LOG_LIMIT
			)
		);
		// phpcs:enable
	}
}
