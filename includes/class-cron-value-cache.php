<?php
/**
 * Orkiestracja WP-Cron (harmonogram, tabela cache, hook crona) dla wartości
 * odświeżanych w tle zamiast na żywo przy renderze strony — dziś jedynym
 * konsumentem jest MQTT (Pro), ale ta klasa sama w sobie o MQTT nic nie wie
 * (żadnej wzmianki w nazwie klasy/pliku/stałych) i zostaje ZAWSZE w obu
 * paczkach, bo IOTDW_Connector_Manager, IOTDW_Widget_Usage_Tracker i
 * IOTDW_Diagnostics_Page odwołują się do jej stałych niezależnie od tieru
 * (te odwołania same w sobie nie mają wartości do ochrony — to tylko
 * nazwy/stałe).
 *
 * Neutralna nazwa (zamiast dawnej IOTDW_MQTT_Poller) — zgłoszenie z testu na
 * żywo (2026-09-24, oddzielna sesja): klasa o nazwie zawierającej "MQTT"
 * fizycznie obecna w paczce darmowej ujawnia istnienie tej funkcji w Pro
 * każdemu czytającemu publiczne źródło wtyczki na wp.org SVN, mimo że sama
 * klasa jest bezużytecznym bez silnika Pro szkieletem — czysto informacyjny
 * wyciek (nie luka bezpieczeństwa, ale niepotrzebny). Faktyczna logika
 * biznesowa MQTT (poll_topic()/fetch_via_socket()/fetch_via_emqx_rest(),
 * zależność php-mqtt/client — jedyna prawdziwa wartość do ochrony, sekcja 1
 * briefu: MQTT jest Pro) zostaje bez zmian w
 * includes/premium/class-mqtt-poll-engine.php, ten sam wzorzec co już
 * istniejący podział IOTDW_Email_Report_Sender (szkielet, zostaje) /
 * IOTDW_Email_Report_Dispatcher (logika, Pro). poll_all() niżej deleguje do
 * silnika WYŁĄCZNIE gdy fizycznie istnieje (class_exists()) — na wersji
 * darmowej cron nadal się odpala na czas, ale nic nie robi (brak silnika),
 * zamiast fatal errora albo cichego blokowania przez license-check.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Cron_Value_Cache
 */
class IOTDW_Cron_Value_Cache {

	/**
	 * Nazwa tabeli cache (bez prefiksu WP).
	 */
	const CACHE_TABLE = 'iotdw_mqtt_cache';

	/**
	 * Opcja z interwałem pollingu w minutach.
	 */
	const OPTION_INTERVAL_MINUTES = 'iotdw_mqtt_poll_interval_minutes';

	const DEFAULT_INTERVAL_MINUTES = 2;

	/**
	 * Nazwa niestandardowego harmonogramu WP-Cron.
	 */
	const CRON_SCHEDULE = 'iotdw_mqtt_poll_interval';

	/**
	 * Hook crona.
	 */
	const POLL_HOOK = 'iotdw_mqtt_poll';

	/**
	 * Typ konektora, dla którego ten poller działa — musi zgadzać się z
	 * kluczem rejestracji w IOTDW_Connector_Manager (rejestrowany tylko
	 * gdy IOTDW_MQTT_Polling_Connector fizycznie istnieje — Pro). Połączenia
	 * typu LISTENER_CONNECTOR_TYPE są CELOWO pomijane przez poll_all() —
	 * użytkownik uruchamia dla nich bin/mqtt-listener.php zamiast WP-Cron,
	 * ale czytają dokładnie tę samą tabelę cache przez dokładnie ten sam
	 * IOTDW_MQTT_Polling_Connector.
	 */
	const CONNECTOR_TYPE = 'mqtt_polling';

	/**
	 * Typ konektora dla wariantu "listener w tle" — osobny wpis w dropdownie
	 * panelu admina, żeby użytkownik świadomie wybrał wariant wymagający
	 * dostępu SSH/serwera, zamiast domyślnego pollingu. Współdzieli klasę
	 * konektora i tabelę cache z CONNECTOR_TYPE — jedyna różnica to KTO
	 * zapisuje do cache (WP-Cron vs zewnętrzny proces), co jest niewidoczne
	 * dla fetch_data().
	 */
	const LISTENER_CONNECTOR_TYPE = 'mqtt_listener';

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

		add_action(
			self::POLL_HOOK,
			function () {
				IOTDW_Diagnostics_Logger::run_cron( self::POLL_HOOK, array( $this, 'poll_all' ) );
			}
		);
	}

	/**
	 * Tworzy tabelę cache (wołane z IOTDW_Plugin::activate()). Klucz główny
	 * to (connection_id, topic), nie sam topic — dwa różne brokery mogą mieć
	 * temat o tej samej nazwie, sam topic jako PRIMARY KEY nadpisywałby
	 * wartości między połączeniami.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::CACHE_TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			connection_id VARCHAR(36) NOT NULL,
			topic VARCHAR(191) NOT NULL,
			value TEXT NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (connection_id, topic)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Rejestruje niestandardowy harmonogram WP-Cron z interwałem czytanym
	 * z opcji — ten sam wzorzec co IOTDW_History_Recorder::register_cron_schedule().
	 *
	 * @param array $schedules Zarejestrowane harmonogramy WP-Cron.
	 * @return array
	 */
	public static function register_cron_schedule( array $schedules ): array {
		$minutes = max( 1, (int) get_option( self::OPTION_INTERVAL_MINUTES, self::DEFAULT_INTERVAL_MINUTES ) );

		$schedules[ self::CRON_SCHEDULE ] = array(
			'interval' => $minutes * MINUTE_IN_SECONDS,
			/* translators: %d: liczba minut */
			'display'  => sprintf( __( 'Every %d minutes (IoT Data Widget scheduled cache refresh)', 'iot-data-widget' ), $minutes ),
		);

		return $schedules;
	}

	/**
	 * Deleguje faktyczne odpytywanie brokera do silnika Pro (patrz docblock
	 * klasy) — na wersji darmowej ten plik fizycznie nie istnieje, więc cron
	 * odpala się o czasie, ale nic nie robi (brak jakichkolwiek połączeń
	 * 'mqtt_polling' do znalezienia i tak, bo IOTDW_Connector_Manager nie
	 * rejestruje tego typu bez IOTDW_MQTT_Polling_Connector).
	 */
	public function poll_all(): void {
		if ( ! class_exists( 'IOTDW_MQTT_Poll_Engine' ) ) {
			return;
		}

		( new IOTDW_MQTT_Poll_Engine( $this->connector_manager ) )->poll_all();
	}

	/**
	 * Zapisuje/nadpisuje wartość tematu w cache. Publiczna i statyczna
	 * celowo — to jedyne miejsce w wtyczce, które pisze do
	 * wp_iotdw_mqtt_cache, współdzielone przez IOTDW_MQTT_Poll_Engine (Pro)
	 * ORAZ przez bin/mqtt-listener.php. Zostaje w szkielecie (nie w
	 * silniku Pro), żeby wp_iotdw_mqtt_cache i IOTDW_MQTT_Polling_Connector::fetch_data()
	 * (który ją czyta) miały wspólny, zawsze obecny kontrakt niezależnie od
	 * tieru — sama ta metoda to prosty INSERT/UPDATE, zero logiki biznesowej
	 * do ochrony.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $topic         Temat MQTT.
	 * @param string $value         Nowa wartość.
	 */
	public static function write_cache( string $connection_id, string $topic, string $value ): void {
		global $wpdb;

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::CACHE_TABLE,
			array(
				'connection_id' => $connection_id,
				'topic'         => $topic,
				'value'         => $value,
				'updated_at'    => current_time( 'mysql', true ),
			),
			array( '%s', '%s', '%s', '%s' )
		);
	}
}
