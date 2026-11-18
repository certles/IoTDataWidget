<?php
/**
 * Główna klasa wtyczki.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Plugin
 */
final class IOTDW_Plugin {

	/**
	 * Jedyna instancja klasy.
	 *
	 * @var IOTDW_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Menedżer konektorów.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	public $connector_manager;

	/**
	 * Obsługa shortcode.
	 *
	 * @var IOTDW_Shortcode
	 */
	public $shortcode;

	/**
	 * Panel administracyjny.
	 *
	 * @var IOTDW_Admin_Page
	 */
	public $admin_page;

	/**
	 * Warstwa cache.
	 *
	 * @var IOTDW_Cache
	 */
	public $cache;

	/**
	 * Renderer HTML.
	 *
	 * @var IOTDW_Renderer
	 */
	public $renderer;

	/**
	 * REST API dla edytora bloku.
	 *
	 * @var IOTDW_Rest_Api
	 */
	public $rest_api;

	/**
	 * Rejestracja bloku Gutenberg.
	 *
	 * @var IOTDW_Gutenberg_Block
	 */
	public $gutenberg_block;

	/**
	 * Śledzenie użycia widgetów chart/sparkline w treści postów.
	 *
	 * @var IOTDW_Widget_Usage_Tracker
	 */
	public $widget_usage_tracker;

	/**
	 * Zbieranie i czyszczenie historii wartości encji.
	 *
	 * @var IOTDW_History_Recorder
	 */
	public $history_recorder;

	/**
	 * WP-Cron poller dla połączeń MQTT.
	 *
	 * @var IOTDW_Cron_Value_Cache
	 */
	public $mqtt_poller;

	/**
	 * Ustawienia ogólne (branding) + eksport/import konfiguracji.
	 *
	 * @var IOTDW_Settings_Page
	 */
	public $settings_page;

	/**
	 * Magazyn reguł alertów progowych — CRUD i schemat tabeli, zostaje na obu
	 * tierach (v1.2 Tydzień 7; ocena reguł wydzielona do $alert_evaluator
	 * przy audycie paczki darmowej, Faza 6 planu freemium, 2026-09-14 — patrz
	 * docblock IOTDW_Alert_Manager).
	 *
	 * @var IOTDW_Alert_Manager
	 */
	public $alert_manager;

	/**
	 * Ocena reguł alertów (nasłuch na nowe odczyty, histereza) + zapis/
	 * usuwanie reguł (v1.2 Tydzień 7). Null, jeśli plik fizycznie nie
	 * istnieje (wersja darmowa, Faza 6) albo instalacja nie ma Pro/Agency
	 * (Faza 5 planu freemium — alerty to funkcja Pro), patrz init_modules().
	 *
	 * @var IOTDW_Alert_Evaluator|null
	 */
	public $alert_evaluator;

	/**
	 * Wysyłka powiadomień o alertach (e-mail/webhook, v1.2 Tydzień 8). Null,
	 * jeśli plik fizycznie nie istnieje (wersja darmowa, Faza 6) albo
	 * instalacja nie ma Pro/Agency, patrz init_modules().
	 *
	 * @var IOTDW_Alert_Notifier|null
	 */
	public $alert_notifier;

	/**
	 * Panel administracyjny reguł alertów (v1.2 Tydzień 8). Null na wersji
	 * darmowej (Faza 5 planu freemium — alerty to funkcja Pro), patrz
	 * init_modules().
	 *
	 * @var IOTDW_Alert_Rules_Page|null
	 */
	public $alert_rules_page;

	/**
	 * Publiczny tryb embedu — renderowanie posta bez motywu WP pod
	 * zabezpieczonym tokenem URL-em (v1.3 Tydzień 5). Null, jeśli plik
	 * fizycznie nie istnieje (wersja darmowa, Faza 6) albo instalacja nie
	 * ma Pro/Agency (Faza 5 planu freemium — embed to funkcja Pro), patrz
	 * init_modules().
	 *
	 * @var IOTDW_Embed|null
	 */
	public $embed;

	/**
	 * Eksport CSV surowej historii wartości do panelu admina (v1.3 Tydzień 6;
	 * wydzielone z IOTDW_History_Export do osobnej klasy przy audycie paczki
	 * darmowej, Faza 6 planu freemium, 2026-09-14 — patrz docblock
	 * IOTDW_History_Csv_Export). Null, jeśli plik fizycznie nie istnieje
	 * (wersja darmowa) albo instalacja nie ma Pro/Agency, patrz init_modules().
	 * Samo odczytanie historii (IOTDW_History_Export::fetch_all()) zostaje
	 * statyczną klasą bez instancji — brak odpowiadającej jej property tutaj.
	 *
	 * @var IOTDW_History_Csv_Export|null
	 */
	public $history_csv_export;

	/**
	 * Magazyn konfiguracji cyklicznych raportów e-mail (v1.3 Tydzień 7).
	 *
	 * @var IOTDW_Email_Report_Manager
	 */
	public $email_report_manager;

	/**
	 * Wysyłka cyklicznych raportów e-mail (v1.3 Tydzień 7).
	 *
	 * @var IOTDW_Email_Report_Sender
	 */
	public $email_report_sender;

	/**
	 * Panel administracyjny raportów e-mail (v1.3 Tydzień 7). Null na wersji
	 * darmowej (Faza 5 planu freemium — raporty e-mail to funkcja Pro),
	 * patrz init_modules().
	 *
	 * @var IOTDW_Email_Reports_Page|null
	 */
	public $email_reports_page;

	/**
	 * Kreator pierwszego uruchomienia (v1.4 Tydzień 3).
	 *
	 * @var IOTDW_Setup_Wizard
	 */
	public $setup_wizard;

	/**
	 * Panel administracyjny globalnych ustawień wyglądu (v1.5 Tydzień 5).
	 *
	 * @var IOTDW_Style_Settings_Page
	 */
	public $style_settings_page;

	/**
	 * Panel administracyjny strony diagnostyki (v1.6 Tydzień 3).
	 *
	 * @var IOTDW_Diagnostics_Page
	 */
	public $diagnostics_page;

	/**
	 * Prośba o opinię na WordPress.org.
	 *
	 * @var IOTDW_Review_Notice
	 */
	public $review_notice;

	/**
	 * Zapisane encje per połączenie — kreator multi-entity (v1.9 Tydzień 4).
	 *
	 * @var IOTDW_Saved_Entities
	 */
	public $saved_entities;

	/**
	 * Whitelist encji + ostatnia wartość per kanał push (v2.0,
	 * docs/archiwum/mvp-v2-0-push-mode-plan.md).
	 *
	 * @var IOTDW_Push_Channel_Manager
	 */
	public $push_channel_manager;

	/**
	 * Publiczny endpoint odbioru danych push (v2.0).
	 *
	 * @var IOTDW_Push_Ingest
	 */
	public $push_ingest;

	/**
	 * Zwraca jedyną instancję klasy (singleton).
	 *
	 * @return IOTDW_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Nazwa opcji przechowującej wersję, dla której schemat bazy był ostatnio
	 * utworzony/zaktualizowany (v1.8, code review) — patrz maybe_upgrade_db().
	 */
	const DB_VERSION_OPTION = 'iotdw_db_version';

	/**
	 * Opcja-znacznik "migracja poświadczeń na szyfrowanie już się odbyła"
	 * (docs/archiwum/iot-data-widget-poswiadczenia.md) — patrz maybe_migrate_credentials().
	 * CELOWO osobna opcja od DB_VERSION_OPTION/maybe_upgrade_db(): tamta
	 * metoda ma udokumentowaną, pilnowaną testem (Plugin_Test.php) właściwość
	 * "nigdy nie dotyka iotdw_connections" (wniosek z incydentu utraty
	 * danych 2026-08-31) — migracja poświadczeń MUSI dotykać connections z
	 * definicji, więc żyje jako świadomie odrębna metoda, nie dopisana do
	 * tamtej.
	 */
	const CREDENTIAL_MIGRATION_OPTION = 'iotdw_credentials_migrated';

	/**
	 * Konstruktor prywatny — wymusza użycie instance().
	 */
	private function __construct() {
		self::load_freemius_translation();
		self::load_bundled_translation();
		$this->includes();
		$this->maybe_upgrade_db();
		$this->init_modules();
		$this->maybe_migrate_credentials();
		// Osobno od maybe_upgrade_db() (ta nie czyta połączeń) — jednorazowo,
		// dopóki ustawienie "Allow local network addresses" nie ma wartości.
		IOTDW_Local_Network::maybe_migrate( $this->connector_manager );

		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_admin_toolkit_css' ) );
	}

	/**
	 * Polskie tłumaczenie interfejsu SDK Freemiusa (ekran zgody, konto,
	 * cennik, menu "Ulepsz"/"Kontakt" — 1.0.3, decyzja właściciela
	 * 2026-10-09): SDK nie ma własnego pl_PL, a jego klucze nie nadają się
	 * do nadpisywania (te same klucze dla różnych tekstów), więc dokładamy
	 * pełny katalog dla domeny "freemius" z languages/ wtyczki. Tylko w
	 * panelu i tylko dla języka pl_PL. Domena "freemius" jest wspólna dla
	 * wszystkich wtyczek z tym SDK — ich ekrany Freemiusa też będą po
	 * polsku (te same teksty SDK).
	 */
	public static function load_freemius_translation(): void {
		if ( ! is_admin() || 'pl_PL' !== determine_locale() ) {
			return;
		}

		$mofile = IOTDW_PLUGIN_DIR . 'languages/freemius-pl_PL.mo';

		if ( is_readable( $mofile ) ) {
			load_textdomain( 'freemius', $mofile, 'pl_PL' );
		}
	}

	/**
	 * Tłumaczenie z languages/ wtyczki, jeśli jest dla bieżącego języka.
	 * Paczka językowa z translate.wordpress.org (wp-content/languages/plugins/)
	 * powstaje z wersji darmowej i nie ma tekstów Pro (np. „History chart for
	 * %s”) — WordPress ładował tylko ją i na polskiej stronie z Pro część
	 * napisów zostawała po angielsku (przegląd demo 2026-10-22). Plik
	 * dołączony do wtyczki jest pełny; ładowany jako pierwszy, więc wygrywa,
	 * a load_textdomain() nie blokuje późniejszego doładowania paczki.
	 */
	public static function load_bundled_translation(): void {
		$locale = determine_locale();
		$mofile = IOTDW_PLUGIN_DIR . 'languages/iot-data-widget-' . $locale . '.mo';

		if ( 'en_US' !== $locale && is_readable( $mofile ) ) {
			load_textdomain( 'iot-data-widget', $mofile, $locale );
		}
	}

	/**
	 * Ładuje wspólny arkusz stylów dla partiali admina (v1.7 Tydzień 3-6:
	 * notice/empty-state/data-table) — JEDNO miejsce zamiast osobnej
	 * rejestracji w każdej z siedmiu klas ekranów admina (code review
	 * v1.8: to samo wywołanie wp_enqueue_style() z tym samym uchwytem/URL-em
	 * powtórzone identycznie w class-admin-page.php, class-alert-rules-page.php,
	 * class-email-reports-page.php, class-settings-page.php,
	 * class-diagnostics-page.php, class-setup-wizard.php). Warunek to proste dopasowanie podłańcucha w
	 * hook_suffix — wszystkie podstrony tej wtyczki wiszą pod tym samym
	 * rodzicem (`add_submenu_page( 'iot-data-widget', ... )`), więc WP
	 * zawsze osadza "iot-data-widget" w ich hook_suffix, bez potrzeby
	 * osobnego zbierania $page_hook_suffix z każdej klasy ekranu.
	 *
	 * @param string $hook_suffix Hook suffix bieżącej strony admina.
	 */
	public function maybe_enqueue_admin_toolkit_css( string $hook_suffix ): void {
		if ( false === strpos( $hook_suffix, 'iot-data-widget' ) ) {
			return;
		}

		wp_enqueue_style(
			'iot-data-widget-admin-toolkit',
			IOTDW_PLUGIN_URL . 'admin/css/admin-toolkit.css',
			array(),
			IOTDW_VERSION
		);
	}

	/**
	 * Dogania schemat bazy dla instalacji, które NIGDY nie przeszły przez
	 * register_activation_hook() z bieżącą wersją — czyli KAŻDA aktualizacja
	 * przez auto-update (Gumroad/WP.org, v1.5), bo auto-update nie odpala
	 * hooka aktywacji, tylko podmienia pliki. Bez tego nowe indeksy/kolumny
	 * dodane do którejś z create_table() (np. connection_entity w
	 * IOTDW_Widget_Usage_Tracker, dodany przy code review v1.8 — patrz
	 * jej komentarz) nigdy by nie dotarły do już działających instalacji,
	 * tylko do świeżych. dbDelta() jest bezpieczny do wielokrotnego
	 * wywołania (dodaje wyłącznie brakujące elementy, nigdy nic nie
	 * kasuje), więc ponowne odpalenie go przy każdej zmianie wersji jest
	 * tanie i bezpieczne. Porównanie z opcją sprawia, że realna praca
	 * (zapytania do bazy) dzieje się tylko RAZ na wersję, nie przy każdym
	 * ładowaniu strony — reszta odwiedzin to jeden tani odczyt opcji.
	 */
	private function maybe_upgrade_db(): void {
		if ( get_option( self::DB_VERSION_OPTION ) === IOTDW_VERSION ) {
			return;
		}

		IOTDW_Widget_Usage_Tracker::create_table();
		IOTDW_History_Recorder::create_table();
		IOTDW_Cron_Value_Cache::create_table();
		IOTDW_Alert_Manager::create_table();
		IOTDW_Email_Report_Manager::create_table();
		IOTDW_Diagnostics_Logger::create_tables();
		IOTDW_Saved_Entities::create_table();
		IOTDW_Push_Channel_Manager::create_table();

		// 1.0.2: aktualizacja z wersji darmowej <= 1.0.1 zdejmuje z
		// harmonogramu crony funkcji Pro (patrz sync_cron_events()).
		self::sync_cron_events();

		update_option( self::DB_VERSION_OPTION, IOTDW_VERSION );
	}

	/**
	 * Planuje crony wtyczki — te funkcji Pro tylko wtedy, gdy plik Pro
	 * fizycznie istnieje w paczce (ten sam test file_exists() co
	 * init_modules()), w przeciwnym razie zdejmuje je z harmonogramu.
	 * Wersja darmowa <= 1.0.1 planowała wszystkie cztery (zgłoszenie
	 * 2026-10-06): MQTT/raporty nic nie robiły, a snapshot historii odpytywał
	 * urządzenia dla wykresów, których wersja darmowa nie wyświetla.
	 * Wołane przy aktywacji i przy każdej zmianie wersji.
	 */
	public static function sync_cron_events(): void {
		$premium = IOTDW_PLUGIN_DIR . 'includes/premium/';

		// Niestandardowe harmonogramy muszą być znane, zanim wp_schedule_event() ich użyje.
		add_filter( 'cron_schedules', array( 'IOTDW_History_Recorder', 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected
		add_filter( 'cron_schedules', array( 'IOTDW_Cron_Value_Cache', 'register_cron_schedule' ) ); // phpcs:ignore WordPress.WP.CronInterval.ChangeDetected

		$events = array(
			// Historia zasila wykresy/sparkline i alerty — obie funkcje Pro.
			IOTDW_History_Recorder::SNAPSHOT_HOOK => array( IOTDW_History_Recorder::CRON_SCHEDULE, file_exists( $premium . 'class-renderer-chart.php' ) || file_exists( $premium . 'class-alert-evaluator.php' ) ),
			// Czyści też dziennik błędów — potrzebny na obu wersjach.
			IOTDW_History_Recorder::CLEANUP_HOOK  => array( 'daily', true ),
			IOTDW_Cron_Value_Cache::POLL_HOOK     => array( IOTDW_Cron_Value_Cache::CRON_SCHEDULE, file_exists( $premium . 'class-mqtt-poll-engine.php' ) ),
			IOTDW_Email_Report_Sender::SEND_HOOK  => array( 'hourly', file_exists( $premium . 'class-email-report-dispatcher.php' ) ),
		);

		foreach ( $events as $hook => list( $recurrence, $wanted ) ) {
			if ( ! $wanted ) {
				wp_clear_scheduled_hook( $hook );
			} elseif ( ! wp_next_scheduled( $hook ) ) {
				wp_schedule_event( time(), $recurrence, $hook );
			}
		}
	}

	/**
	 * Jednorazowa migracja istniejących połączeń na szyfrowanie poświadczeń
	 * (docs/archiwum/iot-data-widget-poswiadczenia.md, Faza 2 planu freemium) —
	 * bramkowana OSOBNĄ opcją (CREDENTIAL_MIGRATION_OPTION), nie
	 * DB_VERSION_OPTION, żeby nigdy nie stać się częścią maybe_upgrade_db()
	 * (patrz jej docblock i CREDENTIAL_MIGRATION_OPTION powyżej).
	 *
	 * Sama migracja to `save_connections(get_connections())` — nic więcej.
	 * get_connections() już deszyfruje/przepuszcza (patrz
	 * IOTDW_Credential_Cipher::decrypt(), przezroczyste dla starego
	 * jawnego tekstu), save_connections() już szyfruje — więc ten
	 * jednorazowy przebieg tylko WYMUSZA zaszyfrowanie WSZYSTKICH połączeń
	 * od razu, zamiast czekać, aż użytkownik kiedyś ręcznie zapisze/edytuje
	 * każde z nich. Idempotentne z definicji: uruchomienie drugi raz
	 * deszyfruje już-zaszyfrowane wartości (no-op) i szyfruje je ponownie
	 * (nowy IV, ta sama wartość jawna) — nieszkodliwe.
	 *
	 * Zero zapytań, jeśli połączeń nie ma (get_connections() zwraca pustą
	 * tablicę, save_connections() zapisuje pustą tablicę) — tani no-op dla
	 * świeżych instalacji.
	 */
	private function maybe_migrate_credentials(): void {
		if ( get_option( self::CREDENTIAL_MIGRATION_OPTION ) ) {
			return;
		}

		$this->connector_manager->save_connections( $this->connector_manager->get_connections() );

		update_option( self::CREDENTIAL_MIGRATION_OPTION, true );
	}

	/**
	 * Ładuje wymagane pliki klas.
	 */
	private function includes() {
		// Composer autoloader — na razie tylko dla php-mqtt/client (konektor
		// MQTT, tryb "socket"). Osłonięte file_exists(), żeby brak
		// `composer install` degradował się do "MQTT socket niedostępny",
		// nie do fatal errora całej wtyczki.
		if ( file_exists( IOTDW_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'vendor/autoload.php';
		}

		require_once IOTDW_PLUGIN_DIR . 'includes/class-http-client.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-local-network.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-value-normalizer.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-json-path.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/interfaces/interface-listable-connector.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/connectors/abstract-connector.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/connectors/class-ha-connector.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/connectors/class-rest-connector.php';

		// Poprawki po recenzji wp.org (Guideline 5, 2026-09-24) — presety
		// urządzeń (Shelly/Tasmota/ESPHome/Supla), Shelly Cloud, MQTT i styl
		// "grid" są teraz Pro WYŁĄCZNIE przez fizyczny brak tych plików w
		// paczce darmowej (ten sam wzorzec wykluczalności co
		// includes/premium/class-renderer-chart.php niżej), nie przez
		// osobny runtime license-check — dawny `get_free_tier_types()` +
		// twarde blokady w admin/class-admin-page.php zostały usunięte
		// właśnie dlatego, że kod pod nimi (te 4 pliki) i tak fizycznie
		// istniał w paczce darmowej, więc to był dokładnie "trialware"
		// wzorzec zakazany przez Guideline 5, nie fizyczny brak kodu.
		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-grid-items.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-grid-items.php';
		}

		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-connector-presets.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-connector-presets.php';
		}

		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/connectors/class-shelly-cloud-connector.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/connectors/class-shelly-cloud-connector.php';
		}

		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/connectors/class-mqtt-polling-connector.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/connectors/class-mqtt-polling-connector.php';
		}

		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-mqtt-poll-engine.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-mqtt-poll-engine.php';
		}

		require_once IOTDW_PLUGIN_DIR . 'includes/class-push-channel-manager.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/connectors/class-push-channel-connector.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-credential-cipher.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-connector-manager.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-diagnostics-logger.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-cache.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-style-settings.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-state-rules.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-renderer.php';

		// Faza 4 planu freemium (docs/archiwum/iot-data-widget-plan-freemium.md) —
		// style "chart"/"sparkline" żyją w pliku, który musi dać się
		// wykluczyć CAŁKOWICIE (usunięcie pliku, nie warunek w kodzie).
		// file_exists() zamiast bezwarunkowego require_once — dokładnie ta
		// właściwość jest testowana na żywo w tej fazie (usunięcie/przywrócenie
		// pliku, sprawdzenie że reszta wtyczki działa bez błędów).
		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-renderer-chart.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-renderer-chart.php';
		}

		require_once IOTDW_PLUGIN_DIR . 'includes/class-shortcode.php';

		// Faza 5 planu freemium — tryb embedu pełnoekranowego z tokenem to
		// funkcja Pro w całości (nie ma darmowego wariantu), więc cały plik
		// żyje w includes/premium/ — ten sam wzorzec wykluczalności co
		// class-renderer-chart.php (Faza 4).
		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-embed.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-embed.php';
		}

		require_once IOTDW_PLUGIN_DIR . 'includes/class-rest-api.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-gutenberg-block.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-widget-usage-tracker.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-saved-entities.php';

		// Faza 6 planu freemium (docs/archiwum/iot-data-widget-plan-freemium.md) —
		// decyzja o tabeli historii dla wersji darmowej: ZOSTAJE zawsze
		// aktywna, w OBU paczkach (darmowej WP.org i premium Freemius),
		// mimo że żadna funkcja darmowa jej nie czyta (alerty/chart/
		// sparkline/CSV/e-mail — wszystkie Pro). Powody:
		// 1. Ciągłość przy upgrade'zie: free-tier użytkownik, który kupi
		// Pro na TEJ SAMEJ instalacji (aktywacja licencji Freemius nie
		// przechodzi przez register_activation_hook), od razu dostaje
		// wsteczną historię zamiast pustych wykresów przez pierwsze dni.
		// 2. Odwracalność: co się dzieje z danymi przy przejściu Pro->free
		// i z powrotem — NIC, dane po prostu dalej się zbierają
		// niezależnie od tieru, tylko dostęp do ich odczytu
		// (alerty/eksport/wykresy) jest gatowany osobno (Faza 5) — ten
		// sam, już ustalony wzorzec co przy limicie połączeń (dane nigdy
		// nie znikają same z siebie przez zmianę licencji).
		// Świadomy koszt: zasoby (miejsce w bazie, WP-Cron co 15 min) zużyte
		// na darmowej instalacji, która nigdy z tej historii nie skorzysta —
		// zaakceptowany jako mniejsze zło niż złamana ciągłość przy
		// upgrade'zie. Z tego samego powodu ten plik NIE trafia do
		// @fs_premium_only w iot-data-widget.php (patrz komentarz tam) — jest
		// w OBU paczkach, tylko funkcje, które go CZYTAJĄ, są gatowane.
		require_once IOTDW_PLUGIN_DIR . 'includes/class-history-recorder.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-history-export.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-push-ingest.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-push-snippet-generator.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-cron-value-cache.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-alert-manager.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-email-report-manager.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-email-report-sender.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-config-import-export.php';

		// Faza 6 planu freemium (audyt paczki darmowej, 2026-09-14) — logika
		// Pro wydzielona z alert-manager/alert-notifier/email-report-sender/
		// history-export do includes/premium/, ten sam wzorzec wykluczalności
		// co class-renderer-chart.php (Faza 4)/class-embed.php (Faza 5)
		// wyżej: file_exists() zamiast bezwarunkowego require_once, bo w
		// paczce darmowej te pliki fizycznie nie istnieją
		// (@fs_premium_only w iot-data-widget.php). Kolejność ma znaczenie:
		// class-alert-evaluator.php odwołuje się do IOTDW_Alert_Manager::TABLE,
		// class-email-report-dispatcher.php do IOTDW_History_Export::fetch_all()/
		// ::BATCH_SIZE — obie klasy bazowe muszą być już załadowane (są, patrz
		// wyżej).
		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-alert-evaluator.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-alert-evaluator.php';
		}

		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-alert-notifier.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-alert-notifier.php';
		}

		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-email-report-dispatcher.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-email-report-dispatcher.php';
		}

		if ( file_exists( IOTDW_PLUGIN_DIR . 'includes/premium/class-history-csv-export.php' ) ) {
			require_once IOTDW_PLUGIN_DIR . 'includes/premium/class-history-csv-export.php';
		}
		require_once IOTDW_PLUGIN_DIR . 'admin/class-admin-page.php';
		require_once IOTDW_PLUGIN_DIR . 'admin/class-settings-page.php';
		require_once IOTDW_PLUGIN_DIR . 'admin/class-alert-rules-page.php';
		require_once IOTDW_PLUGIN_DIR . 'admin/class-email-reports-page.php';
		require_once IOTDW_PLUGIN_DIR . 'admin/class-setup-wizard.php';
		require_once IOTDW_PLUGIN_DIR . 'admin/class-style-settings-page.php';
		require_once IOTDW_PLUGIN_DIR . 'admin/class-diagnostics-page.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-review-notice.php';
	}

	/**
	 * Inicjalizuje moduły wtyczki.
	 */
	private function init_modules() {
		$this->connector_manager = new IOTDW_Connector_Manager();
		$this->cache             = new IOTDW_Cache();
		// Faza 4 planu freemium — klasa premium (jeśli plik istnieje, patrz
		// includes(), gdzie ładowany jest warunkowo) rozszerza rdzeń o style
		// "chart"/"sparkline". Wszystko inne w wtyczce trzyma tylko
		// $this->renderer i woła jego (wspólne, publiczne) metody — nie musi
		// wiedzieć, którą konkretną klasę dostało (patrz np.
		// IOTDW_Shortcode, IOTDW_Style_Settings_Page).
		//
		// Poprawki po recenzji wp.org (Guideline 5, 2026-09-24) — usunięty
		// dodatkowy `&& iotdw_is_premium()`. Ten warunek miał sens tylko dla
		// jednolitej paczki premium sprzedawanej przez Freemius (wszystkie
		// pliki zawsze obecne, licencja rozstrzyga w runtime) — ale to
		// DOKŁADNIE wzorzec "trialware" zakazany przez Guideline 5, gdy
		// (jak tutaj) te same pliki źródłowe budują też paczkę darmową.
		// Odtąd WYŁĄCZNIE fizyczna obecność pliku decyduje o tierze —
		// class_exists() samo wystarcza.
		$this->renderer         = class_exists( 'IOTDW_Renderer_Chart' ) ? new IOTDW_Renderer_Chart() : new IOTDW_Renderer();
		$this->history_recorder = new IOTDW_History_Recorder( $this->connector_manager );
		$this->shortcode        = new IOTDW_Shortcode( $this->connector_manager, $this->cache, $this->renderer, $this->history_recorder );
		$this->embed            = class_exists( 'IOTDW_Embed' ) ? new IOTDW_Embed( $this->shortcode ) : null;
		$this->admin_page       = new IOTDW_Admin_Page( $this->connector_manager );
		$this->gutenberg_block  = new IOTDW_Gutenberg_Block();

		// Musi powstać PRZED IOTDW_Rest_Api (v1.8 Tydzień 1) — ten ostatni
		// wstrzykuje go do walidacji publicznego endpointu odświeżania danych.
		// $this->saved_entities też musi powstać przed IOTDW_Rest_Api (v1.9
		// Tydzień 6) — wstrzykiwane do endpointu szybkiego wyboru w edytorze
		// bloku. $this->push_channel_manager też (v2.0) — wstrzykiwane do
		// endpointu statusu kanału push ("sprawdź czy dane docierają").
		$this->widget_usage_tracker = new IOTDW_Widget_Usage_Tracker( $this->connector_manager );
		$this->saved_entities       = new IOTDW_Saved_Entities();
		$this->push_channel_manager = new IOTDW_Push_Channel_Manager();
		$this->rest_api             = new IOTDW_Rest_Api( $this->connector_manager, $this->cache, $this->shortcode, $this->widget_usage_tracker, $this->saved_entities, $this->push_channel_manager );
		// IOTDW_History_Export (fetch_all()/fetch_batch(), zawsze załadowane)
		// nie ma odpowiadającej instancji — statyczna, patrz jej docblock.
		$this->history_csv_export = class_exists( 'IOTDW_History_Csv_Export' ) ? new IOTDW_History_Csv_Export( $this->connector_manager ) : null;

		// Push mode (v2.0) — push_ingest wstrzykuje ten sam push_channel_manager
		// utworzony wyżej.
		$this->push_ingest   = new IOTDW_Push_Ingest( $this->connector_manager, $this->push_channel_manager, $this->history_recorder );
		$this->mqtt_poller   = new IOTDW_Cron_Value_Cache( $this->connector_manager );
		$this->settings_page = new IOTDW_Settings_Page( $this->connector_manager, $this->widget_usage_tracker );
		// 1.0.2 — "Allow local network addresses": powiadomienie + kreator (same hooki).
		new IOTDW_Local_Network();
		// alert_manager (skeleton: CRUD + schemat) zostaje zawsze —
		// IOTDW_Diagnostics_Page je wstrzykuje (raport "Alert rules" działa
		// niezależnie od tieru, pokazuje po prostu pustą sekcję na free).
		// alert_evaluator/alert_notifier — fizycznie nieobecne w paczce
		// darmowej, więc samo class_exists() wystarcza (Guideline 5,
		// 2026-09-24 — patrz komentarz przy $this->renderer wyżej). Ich brak
		// na free jest niegroźny — nasłuch na nowe odczyty (evaluator) i tak
		// nigdy nic by nie zrobił (żadna reguła nie może istnieć bez
		// alert_rules_page niżej), notifier nasłuchuje zdarzenia, które
		// evaluator bez tego nigdy by nie odpalił.
		$this->alert_manager    = new IOTDW_Alert_Manager();
		$this->alert_evaluator  = class_exists( 'IOTDW_Alert_Evaluator' ) ? new IOTDW_Alert_Evaluator() : null;
		$this->alert_notifier   = class_exists( 'IOTDW_Alert_Notifier' ) ? new IOTDW_Alert_Notifier() : null;
		$this->alert_rules_page = null !== $this->alert_evaluator ? new IOTDW_Alert_Rules_Page( $this->connector_manager, $this->alert_manager, $this->alert_evaluator ) : null;

		// email_report_manager/_sender (skeleton: CRUD + SEND_HOOK) zostają
		// zawsze (SEND_HOOK zaplanowany w activate(), diagnostyka pokazuje
		// jego status niezależnie od tieru) — email_report_sender samo w
		// sobie deleguje do IOTDW_Email_Report_Dispatcher (Pro) tylko gdy
		// ten fizycznie istnieje, patrz IOTDW_Email_Report_Sender::send_due_reports().
		// email_reports_page (ekran admina do konfigurowania raportów) gatowany
		// tym samym class_exists() — bez sensu pokazywać ekran konfiguracji
		// funkcji, której silnik (dispatcher) fizycznie nie istnieje.
		$this->email_report_manager = new IOTDW_Email_Report_Manager();
		$this->email_report_sender  = new IOTDW_Email_Report_Sender( $this->connector_manager, $this->email_report_manager );
		$this->email_reports_page   = class_exists( 'IOTDW_Email_Report_Dispatcher' ) ? new IOTDW_Email_Reports_Page( $this->connector_manager, $this->email_report_manager ) : null;

		$this->setup_wizard = new IOTDW_Setup_Wizard( $this->connector_manager, $this->shortcode, $this->saved_entities, $this->push_channel_manager );

		$this->style_settings_page = new IOTDW_Style_Settings_Page( $this->renderer );

		$this->diagnostics_page = new IOTDW_Diagnostics_Page( $this->connector_manager, $this->alert_manager, $this->push_channel_manager );

		$this->review_notice = new IOTDW_Review_Notice( $this->connector_manager, $this->push_channel_manager );
	}

	/**
	 * Wołane przy aktywacji wtyczki (register_activation_hook) — tworzy
	 * tabele historii/użycia widgetów i planuje zadania WP-Cron. Ładuje
	 * pliki klas bezpośrednio, bo w tym momencie wtyczka nie jest jeszcze
	 * w pełni zbootstrapowana (hook 'init' jeszcze nie odpalił).
	 */
	public static function activate(): void {
		require_once IOTDW_PLUGIN_DIR . 'includes/class-widget-usage-tracker.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-saved-entities.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-history-recorder.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-cron-value-cache.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-alert-manager.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-email-report-manager.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-email-report-sender.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-diagnostics-logger.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-push-channel-manager.php';

		IOTDW_Widget_Usage_Tracker::create_table();
		IOTDW_History_Recorder::create_table();
		IOTDW_Cron_Value_Cache::create_table();
		IOTDW_Alert_Manager::create_table();
		IOTDW_Email_Report_Manager::create_table();
		IOTDW_Diagnostics_Logger::create_tables();
		IOTDW_Saved_Entities::create_table();
		IOTDW_Push_Channel_Manager::create_table();

		// Zgrane z maybe_upgrade_db() — bez tego następne ładowanie strony
		// (hook 'init') od razu uznałoby świeżą instalację za "nieznaną
		// wersję" i niepotrzebnie odpaliło dbDelta() drugi raz (nieszkodliwe,
		// ale zbędne).
		update_option( self::DB_VERSION_OPTION, IOTDW_VERSION );

		self::sync_cron_events();

		require_once IOTDW_PLUGIN_DIR . 'includes/class-review-notice.php';
		IOTDW_Review_Notice::ensure_activation_time();

		// Sygnał "pokaż kreator" dla IOTDW_Setup_Wizard::maybe_redirect_after_activation()
		// (v1.4 Tydzień 8) — NIE przekierowujemy stąd bezpośrednio. WP
		// wywołuje activation hook przed przekierowaniem po aktywacji (a przy
		// masowej aktywacji ($_GET['activate-multi']) ten parametr jeszcze
		// nie istnieje w momencie wywołania tego hooka), więc jedyny
		// niezawodny sposób odróżnienia pojedynczej aktywacji od masowej to
		// sprawdzenie tego dopiero na NASTĘPNYM ładowaniu strony (hook
		// 'admin_init'), stąd transient jako przekazanie sygnału w czasie.
		require_once IOTDW_PLUGIN_DIR . 'admin/class-setup-wizard.php';
		set_transient( IOTDW_Setup_Wizard::SHOW_WIZARD_TRANSIENT, 1, MINUTE_IN_SECONDS );
	}

	/**
	 * Wołane przy deaktywacji wtyczki (register_deactivation_hook) — usuwa
	 * zaplanowane zadania WP-Cron. Nie usuwa tabel/danych — to rola
	 * uninstall() (wołanej przez Freemius na zdarzeniu 'after_uninstall'),
	 * żeby zwykła deaktywacja (np. przy aktualizacji) nie kasowała historii.
	 */
	public static function deactivate(): void {
		require_once IOTDW_PLUGIN_DIR . 'includes/class-history-recorder.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-cron-value-cache.php';
		require_once IOTDW_PLUGIN_DIR . 'includes/class-email-report-sender.php';

		wp_clear_scheduled_hook( IOTDW_History_Recorder::SNAPSHOT_HOOK );
		wp_clear_scheduled_hook( IOTDW_History_Recorder::CLEANUP_HOOK );
		wp_clear_scheduled_hook( IOTDW_Cron_Value_Cache::POLL_HOOK );
		wp_clear_scheduled_hook( IOTDW_Email_Report_Sender::SEND_HOOK );

		// Zgłoszenie z audytu Fazy 1 (docs/archiwum/iot-data-widget-plan-freemium.md,
		// sprzątanie po Gumroad) — potwierdzone na żywo: instalacje, które
		// miały ten cron zaplanowany PRZED usunięciem IOTDW_License_Manager,
		// zostawały z osieroconym zdarzeniem WP-Cron na zawsze (do_action()
		// bez żadnego zarejestrowanego callbacka nic nie robi, ale zdarzenie
		// samo w sobie nigdy nie znika, bo poprzednia wersja deactivate()
		// czyściła je przez referencję do klasy, która już nie istnieje).
		// Nazwa hooka na sztywno (nie stała klasy) celowo — klasa nie
		// istnieje, żeby ją zreferencować.
		wp_clear_scheduled_hook( 'iotdw_reverify_license' );

		// Faza 7 planu freemium — znalezione na żywo (`wp cron event list`)
		// PRZY OKAZJI sprawdzania crona retencji: ten sam dokładnie problem
		// co wyżej, tylko dla biblioteki `yahnisl/plugin-update-checker`
		// (usuniętej w Fazie 1, patrz composer.json) — jej własny, wewnętrzny
		// cron `puc_cron_check_updates-{slug}` NIGDY nie był czyszczony przez
		// deactivate() tej wtyczki (biblioteka rejestrowała go sama, nasz kod
		// nigdy jej deaktywacji nie wołał). Nazwa hooka odtworzona ręcznie wg
		// konwencji PUC (`puc_cron_check_updates-` + slug wtyczki), bo klasa
		// PUC już nie jest ładowana.
		wp_clear_scheduled_hook( 'puc_cron_check_updates-iot-data-widget' );
	}

	/**
	 * Wołane przez Freemius SDK po zakończeniu procesu odinstalowania
	 * (zdarzenie 'after_uninstall', zarejestrowane w iot-data-widget.php) —
	 * następca dawnego uninstall.php, przeniesiony tu przy migracji na ten
	 * hook (patrz komentarz przy rejestracji w iot-data-widget.php).
	 *
	 * Czyszczenie jest opt-in, wyłączone domyślnie (IOTDW_Settings_Page::handle_save_settings(),
	 * pole 'remove_data_on_uninstall'). Bez zaznaczenia tego checkboksa w
	 * Ustawieniach, odinstalowanie wtyczki (np. przez pomyłkę, albo z
	 * zamiarem ponownej instalacji) zostawia połączenia/historię/alerty
	 * nietknięte — tylko deaktywacja + świadome usunięcie plików wtyczki PO
	 * zaznaczeniu tej opcji faktycznie czyści bazę. Sama deactivate() nigdy
	 * nie dotyka danych niezależnie od tego ustawienia — to wyłącznie
	 * odpina crony.
	 */
	public static function uninstall(): void {
		$iotdw_settings = get_option( 'iotdw_settings', array() );

		if ( empty( $iotdw_settings['remove_data_on_uninstall'] ) ) {
			return;
		}

		delete_option( 'iotdw_settings' );
		delete_option( 'iotdw_connections' );
		delete_option( 'iotdw_history_interval_minutes' );
		delete_option( 'iotdw_history_retention_days' );
		delete_option( 'iotdw_mqtt_poll_interval_minutes' );
		// Dodane w v1.5 Tydzień 2 (IOTDW_License_Manager) — dopisane przy
		// tworzeniu opcji tym razem, nie znalezione później jak
		// iotdw_alert_rules/iotdw_email_reports wcześniej (patrz komentarze
		// przy DROP TABLE poniżej).
		delete_option( 'iotdw_license' );
		// Dodane w v1.8 (IOTDW_Plugin::maybe_upgrade_db(), code review) —
		// dopisane przy tworzeniu opcji, nie znalezione później.
		delete_option( 'iotdw_db_version' );
		// Dodane w v1.5 Tydzień 5 (IOTDW_Style_Settings) — znalezione przy
		// audycie 1.1 (docs/archiwum/poprawki-ogolne.md): brakowało tu, tak jak
		// wcześniej iotdw_alert_rules/iotdw_email_reports brakowało wśród
		// tabel poniżej.
		delete_option( 'iotdw_style_settings' );
		// Dodane w Fazie 6 planu freemium (docs/archiwum/iot-data-widget-plan-freemium.md)
		// — ten sam rodzaj przeoczenia co iotdw_push_entities niżej: opcja-
		// znacznik migracji szyfrowania poświadczeń (self::CREDENTIAL_MIGRATION_OPTION,
		// Faza 2) nigdy nie trafiła tu od kiedy w ogóle powstała.
		delete_option( 'iotdw_credentials_migrated' );
		delete_option( 'iotdw_activated_at' );
		delete_metadata( 'user', 0, 'iotdw_review_notice', '', true );

		global $wpdb;

		// Brak natywnego WP API do masowego usuwania transientów po
		// prefiksie — bezpośrednie zapytanie jest tu jedyną opcją. Uruchamia
		// się raz, przy odinstalowaniu, więc cachowanie wyniku nie ma
		// zastosowania.
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_iotdw_' ) . '%'
			)
		);

		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_timeout_iotdw_' ) . '%'
			)
		);

		// Status cronów (v1.6 Tydzień 1, IOTDW_Diagnostics_Logger::CRON_STATUS_OPTION_PREFIX)
		// — jedna opcja NA HOOK (np. "iotdw_cron_status_iotdw_history_snapshot"),
		// nie jedna zbiorcza opcja, więc tak jak transienty wyżej: tylko LIKE
		// po prefiksie znajdzie je wszystkie. Znalezione przy audycie 1.4
		// (docs/archiwum/poprawki-ogolne.md) — brakowało tu od kiedy ta opcja w ogóle
		// powstała.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( 'iotdw_cron_status_' ) . '%'
			)
		);

		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_history" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_widget_usage" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_mqtt_cache" );
		// Dodane w v1.2 Tydzień 7 (IOTDW_Alert_Manager) — brakowało tu przy
		// tworzeniu tabeli, znalezione i naprawione przy okazji Tygodnia 11
		// (aktualizacja readme.txt, które twierdzi, że odinstalowanie usuwa
		// wszystkie dane wtyczki — musiało to być prawdą, nie tylko
		// deklaracją).
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_alert_rules" );
		// Dodane w v1.3 Tydzień 7 (IOTDW_Email_Report_Manager) — ten sam
		// rodzaj przeoczenia co iotdw_alert_rules powyżej, złapane tym razem
		// PRZED aktualizacją readme.txt (Tydzień 10), nie po fakcie.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_email_reports" );
		// Dodane w v1.6 Tydzień 1/3 (IOTDW_Diagnostics_Logger) — znalezione
		// przy audycie 1.4, ten sam rodzaj przeoczenia co iotdw_alert_rules
		// powyżej.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_connection_status" );
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_error_log" );
		// Dodane w v1.9 Tydzień 4 (IOTDW_Saved_Entities, kreator
		// multi-entity) — dopisane od razu, nie znalezione później.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_saved_entities" );
		// Dodane w v2.0 (IOTDW_Push_Channel_Manager) — ten sam rodzaj
		// przeoczenia co iotdw_alert_rules/iotdw_email_reports/iotdw_connection_status
		// wcześniej: brakowało tu od kiedy ta tabela w ogóle powstała.
		// Znalezione przy audycie bezpieczeństwa przed recenzją Envato (blok
		// 1.4 listy do premiery, 2026-09-07) — bez tego wpisu ostatnia znana
		// wartość KAŻDEGO kanału push (i whitelist encji) przeżywała
		// odinstalowanie z zaznaczoną opcją "Usuń wszystkie dane wtyczki", w
		// przeciwieństwie do wszystkich pozostałych tabel wtyczki.
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}iotdw_push_entities" );

		// Token trybu embed (v1.3 Tydzień 5, IOTDW_Embed::TOKEN_META_KEY) to
		// meta posta, nie osobna tabela/opcja — brak natywnego WP API do
		// masowego usuwania meta po kluczu dla wszystkich postów naraz, stąd
		// bezpośrednie zapytanie, tak samo jak dla transientów powyżej.
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->postmeta} WHERE meta_key = %s",
				'_iotdw_embed_token'
			)
		);

		// Stan zwinięcia dismissible checklisty (v1.3 Tydzień 7,
		// IOTDW_Admin_Page::CHECKLIST_DISMISSED_META_KEY) — meta UŻYTKOWNIKA
		// (per admin, nie per instalacja), stąd bezpośrednie zapytanie po
		// meta_key, zamiast delete_option() — ten sam powód co dla postmeta
		// embedu wyżej. Znalezione przy audycie 1.4 (docs/archiwum/poprawki-ogolne.md).
		$wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s",
				'iotdw_checklist_dismissed'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
	}

	/**
	 * Blokuje klonowanie instancji.
	 */
	private function __clone() {}

	/**
	 * Blokuje deserializację instancji.
	 *
	 * @throws \Exception Zawsze — deserializacja singletona jest zabroniona.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialize the IOTDW_Plugin singleton.' );
	}
}
