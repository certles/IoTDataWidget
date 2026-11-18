<?php
/**
 * Kreator pierwszego uruchomienia (onboarding wizard) — v1.4 Tydzień 3.
 * Opcjonalna, szybsza ścieżka konfiguracji pierwszego połączenia; standardowe
 * ekrany ustawień (IOTDW_Admin_Page) zostają bez zmian i działają
 * niezależnie (patrz docs/archiwum/mvp-v1-4-plan.md, Ustalenie #5).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Setup_Wizard
 *
 * Kreator to PHP + routing po parametrach zapytania (`step`/`group`/`type`),
 * nie osobna aplikacja React (patrz Ustalenie #2 w planie) — prostota ponad
 * rozbudowę. Rejestrowana jako zwykła, widoczna podstrona menu (ten sam
 * wzorzec co Connections/Alerts/Email reports/Settings) — próba ukrycia jej
 * przez `add_submenu_page( null, ... )` (nie rozwiązuje tytułu strony,
 * `strip_tags( null )` + "headers already sent" w admin-header.php przy
 * każdym wejściu) i przez `add_submenu_page()` + `remove_submenu_page()`
 * (blokuje też bezpośredni URL — `user_can_access_admin_page()` szuka wpisu
 * we `$submenu`, którego już tam nie ma, i kończy 403) zostały obie
 * wypróbowane i złapane na żywo (Playwright) jako realne, WP-owe pułapki —
 * żadna nie jest bezpiecznym sposobem na "zarejestrowana, ale ukryta"
 * stronę. Zwykła widoczność jest prostsza i nic w planie jej nie zabrania.
 */
class IOTDW_Setup_Wizard {

	/**
	 * Slug strony admina.
	 */
	const PAGE_SLUG = 'iot-data-widget-setup';

	/**
	 * Nazwa transienta ustawianego przy aktywacji wtyczki
	 * (IOTDW_Plugin::activate()) — sygnał dla maybe_redirect_after_activation()
	 * (v1.4 Tydzień 8), żeby przekierować do kreatora przy najbliższym
	 * ładowaniu strony admina, ale tylko raz i tylko jeśli to NIE była
	 * masowa aktywacja kilku wtyczek naraz.
	 */
	const SHOW_WIZARD_TRANSIENT = 'iotdw_show_setup_wizard';

	/**
	 * Hook suffix strony (z add_submenu_page()) — do warunkowego enqueue JS
	 * tylko na tej jednej stronie admina, ten sam wzorzec co IOTDW_Admin_Page.
	 *
	 * @var string
	 */
	private $page_hook_suffix = '';

	/**
	 * Menedżer konektorów — lista typów do pól formularza w Kroku 2 (Tydzień 4).
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Obsługa shortcode — Krok 4 (Tydzień 6) renderuje podgląd DOKŁADNIE tym
	 * samym kodem co front strony i edytor bloku (IOTDW_Rest_Api::get_preview()),
	 * żadnej trzeciej, zduplikowanej ścieżki renderowania.
	 *
	 * @var IOTDW_Shortcode
	 */
	private $shortcode;

	/**
	 * Zapisane encje per połączenie (kreator multi-entity, v1.9 Tydzień 4) —
	 * zapisywane tu z Kroku 3 dla konektorów Listable, patrz handle_save_entities().
	 *
	 * @var IOTDW_Saved_Entities
	 */
	private $saved_entities;

	/**
	 * Nazwa akcji admin-post.php dla zapisu zaznaczonych w Kroku 3 encji
	 * (konektory Listable — v1.9 Tydzień 3/4). Osobna od zapisu połączenia
	 * (Krok 2, IOTDW_Admin_Page::handle_save()) — to inny formularz, inny
	 * moment w przepływie.
	 */
	const SAVE_ENTITIES_ACTION = 'iotdw_wizard_save_entities';

	/**
	 * Nazwa akcji admin-post.php dla "Wypróbuj z danymi demo" (Krok 1,
	 * poprawka 2.1, docs/archiwum/poprawki-ogolne.md) — jedyny przycisk w Kroku 1,
	 * który zapisuje coś do bazy zamiast tylko nawigować dalej, stąd własna
	 * akcja/nonce, ten sam wzorzec co SAVE_ENTITIES_ACTION.
	 */
	const USE_DEMO_ACTION = 'iotdw_wizard_use_demo';

	/**
	 * Nazwa zapisanego demo-połączenia — stała i rozpoznawalna, żeby
	 * wielokrotne kliknięcia "Wypróbuj z danymi demo" (albo różni admini na
	 * tej samej instalacji) reużywały TO SAMO połączenie zamiast mnożyć
	 * duplikaty na liście Connections. Widoczna użytkownikowi wprost (nazwa
	 * połączenia), więc zrozumiała, nie techniczny identyfikator.
	 */
	const DEMO_CONNECTION_NAME = 'Demo — Open-Meteo (auto)';

	/**
	 * Publiczne, bez-autoryzacyjne API pogodowe (Open-Meteo) — współrzędne
	 * Warszawy, byle jakieś realne miejsce; wybór lokalizacji nie ma tu
	 * żadnego znaczenia poza tym, że zwraca prawdziwe, zmieniające się dane.
	 */
	const DEMO_BASE_URL = 'https://api.open-meteo.com/v1/forecast?latitude=52.23&longitude=21.01&current_weather=true';

	/**
	 * JSONPath (tryb zaawansowany REST) do temperatury w odpowiedzi Open-Meteo.
	 */
	const DEMO_ENTITY_PATH = 'current_weather.temperature';

	/**
	 * Jednostka dla demo-widgetu (poprawka 4, docs/archiwum/iot-data-widget-do-poprawy.md)
	 * — tryb zaawansowany REST Connectora (dowolny JSONPath, bez presetu) nie
	 * ma skąd jej znać, więc bez tego pierwszy ekran, jaki widzi recenzent
	 * Envato/klient bez własnego Home Assistant, pokazywał wartość bez
	 * jednostki. Fizyczna jednostka, nie tekst do tłumaczenia — ten sam
	 * wzorzec co 'unit' w IOTDW_Connector_Presets (np. '°C', 'W').
	 * Przekazywane jako atrybut shortcode'u 'unit' (patrz
	 * IOTDW_Shortcode::render()), razem z etykietą (tłumaczoną osobno w
	 * render_step_4(), patrz tam) — NIE jako pola configu połączenia:
	 * configu NIE da się bezpiecznie rozszerzyć o niestandardowe klucze, bo
	 * IOTDW_Admin_Page::handle_save() przebudowuje go od zera przez
	 * build_config() przy każdym zapisie z panelu (np. gdyby admin kiedyś
	 * otworzył i zapisał to połączenie przez zwykły edytor) — nieznane mu
	 * klucze zostałyby po cichu utracone.
	 */
	const DEMO_UNIT = '°C';

	/**
	 * Nazwa akcji admin-post.php dla utworzenia kanału push (v2.0,
	 * docs/archiwum/mvp-v2-0-push-mode-plan.md) — Krok "utwórz kanał" w ścieżce push.
	 */
	const PUSH_CREATE_CHANNEL_ACTION = 'iotdw_wizard_push_create_channel';

	/**
	 * Nazwa akcji admin-post.php dla unieważnienia i wygenerowania nowego
	 * tokenu istniejącego kanału push (§3 planu: "Odwoływalność — jeden
	 * przycisk 'unieważnij i wygeneruj nowy'"). Osobna od tworzenia kanału —
	 * wołana zarówno z ekranu snippetów w kreatorze, jak (docelowo) z listy
	 * połączeń, nie tylko z wnętrza kreatora.
	 */
	const PUSH_REGENERATE_TOKEN_ACTION = 'iotdw_wizard_push_regenerate_token';

	/**
	 * Prefiks transienta niosącego surowy token DOKŁADNIE RAZ, od momentu
	 * utworzenia/regeneracji do następnego załadowania strony (§3 planu: "W
	 * bazie wyłącznie hash... Pokazywany użytkownikowi raz") — w bazie
	 * połączeń istnieje tylko hash, więc to jedyne miejsce, skąd surowy
	 * token da się jeszcze pokazać użytkownikowi. Skasowany od razu po
	 * pierwszym odczycie (patrz render_push_snippet_step()), nie po prostu
	 * po wygaśnięciu TTL — nawet krótkotrwałe drugie wyświetlenie (np.
	 * odświeżenie strony) nie powinno być możliwe.
	 */
	const PUSH_TOKEN_TRANSIENT_PREFIX = 'iotdw_push_token_';

	/**
	 * Prefiks transienta z błędami walidacji formularza tworzenia kanału
	 * push — ten sam ogólny wzorzec co IOTDW_Admin_Page::FORM_ERROR_TRANSIENT_PREFIX,
	 * osobny bo to inny formularz/handler.
	 */
	const PUSH_CHANNEL_FORM_ERROR_TRANSIENT_PREFIX = 'iotdw_push_channel_form_error_';

	/**
	 * Whitelist encji + tokeny kanałów push (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md).
	 *
	 * @var IOTDW_Push_Channel_Manager
	 */
	private $push_channel_manager;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager    Menedżer konektorów.
	 * @param IOTDW_Shortcode            $shortcode            Obsługa shortcode.
	 * @param IOTDW_Saved_Entities       $saved_entities       Zapisane encje per połączenie.
	 * @param IOTDW_Push_Channel_Manager $push_channel_manager Whitelist encji + tokeny kanałów push (v2.0).
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Shortcode $shortcode, IOTDW_Saved_Entities $saved_entities, IOTDW_Push_Channel_Manager $push_channel_manager ) {
		$this->connector_manager    = $connector_manager;
		$this->shortcode            = $shortcode;
		$this->saved_entities       = $saved_entities;
		$this->push_channel_manager = $push_channel_manager;

		// Priorytet 20 (v1.7 Tydzień 2, docs/archiwum/audyt-ui-v1-7.md): druga pozycja
		// podmenu, zaraz po Connections (priorytet 10) — konceptualnie to
		// alternatywna, prowadzona ścieżka do tego samego celu (dodanie
		// połączenia), więc grupujemy je razem, nie w środku/na końcu menu.
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_init', array( $this, 'maybe_redirect_after_activation' ) );
		add_action( 'admin_post_' . self::SAVE_ENTITIES_ACTION, array( $this, 'handle_save_entities' ) );
		add_action( 'admin_post_' . self::USE_DEMO_ACTION, array( $this, 'handle_use_demo' ) );
		add_action( 'admin_post_' . self::PUSH_CREATE_CHANNEL_ACTION, array( $this, 'handle_create_push_channel' ) );
		add_action( 'admin_post_' . self::PUSH_REGENERATE_TOKEN_ACTION, array( $this, 'handle_regenerate_push_token' ) );
	}

	/**
	 * Przekierowuje do kreatora raz, przy pierwszym ładowaniu strony admina
	 * po aktywacji wtyczki (v1.4 Tydzień 8) — patrz IOTDW_Plugin::activate()
	 * (ustawia transient) i komentarz przy SHOW_WIZARD_TRANSIENT. Transient
	 * jest kasowany od razu po odczycie, niezależnie od tego, czy faktycznie
	 * przekierujemy — inaczej masowa aktywacja zostawiłaby go "wiszący" i
	 * odpaliłaby przekierowanie przy zupełnie kolejnej, niezwiązanej wizycie
	 * w adminie.
	 */
	public function maybe_redirect_after_activation(): void {
		if ( ! get_transient( self::SHOW_WIZARD_TRANSIENT ) ) {
			return;
		}

		delete_transient( self::SHOW_WIZARD_TRANSIENT );

		// Masowa aktywacja kilku wtyczek naraz: WP dokleja activate-multi=true
		// (nie activate=true) do URL-a przekierowania po aktywacji — jedyny
		// niezawodny sygnał na tym etapie (patrz komentarz w activate()).
		if ( isset( $_GET['activate-multi'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		if ( ! current_user_can( 'manage_options' ) || wp_doing_ajax() ) {
			return;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		exit;
	}

	/**
	 * Rejestruje stronę w menu administracyjnym.
	 */
	public function register_page(): void {
		$this->page_hook_suffix = add_submenu_page(
			'iot-data-widget',
			__( 'IoT Data Widget — Setup', 'iot-data-widget' ),
			__( 'Setup Wizard', 'iot-data-widget' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Ładuje JS przełączający pola formularza (ten sam co zwykła strona
	 * połączeń) i JS przycisku testu — tylko na tej jednej stronie admina.
	 *
	 * @param string $hook_suffix Hook suffix bieżącej strony admina.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'iot-data-widget-connector-form',
			IOTDW_PLUGIN_URL . 'admin/js/connector-form.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_enqueue_script(
			'iot-data-widget-wizard-entity-picker',
			IOTDW_PLUGIN_URL . 'admin/js/wizard-entity-picker.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_enqueue_script(
			'iot-data-widget-wizard-step4',
			IOTDW_PLUGIN_URL . 'admin/js/wizard-step4.js',
			array(),
			IOTDW_VERSION,
			true
		);

		// Ekran "sprawdź czy dane docierają" (v2.0 §6) — tylko na ekranie
		// snippetu jednego konkretnego kanału, gdzie 'channel' jest znane;
		// localize'ujemy tu, nie w render_push_snippet_step(), bo ten hook
		// (admin_enqueue_scripts) odpala się PRZED renderowaniem widoku.
		if ( 'push' === ( isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : '' ) && isset( $_GET['channel'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$channel_id = sanitize_text_field( wp_unslash( $_GET['channel'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

			wp_enqueue_script(
				'iot-data-widget-push-check-receiving',
				IOTDW_PLUGIN_URL . 'admin/js/push-check-receiving.js',
				array(),
				IOTDW_VERSION,
				true
			);

			wp_localize_script(
				'iot-data-widget-push-check-receiving',
				'iotdwPushCheck',
				array(
					'restUrl'           => esc_url_raw( rest_url( IOTDW_Rest_Api::NAMESPACE . '/push-channels/' . rawurlencode( $channel_id ) . '/status' ) ),
					'nonce'             => wp_create_nonce( 'wp_rest' ),
					'listeningLabel'    => __( 'Listening for data…', 'iot-data-widget' ),
					'noDataLabel'       => __( '— no data yet —', 'iot-data-widget' ),
					'networkErrorLabel' => __( 'Could not reach the server. Check your internet connection and try again.', 'iot-data-widget' ),
					'timeoutLabel'      => __( 'Nothing received yet after 60 seconds — double-check the snippet was pasted correctly and the device is online, then try again.', 'iot-data-widget' ),
					/* translators: %1$s is the entity name and %2$s the received value, both replaced client-side (JS) — keep the literal "%1$s"/"%2$s". */
					'receivedLabel'     => __( '✓ Received "%1$s" = %2$s', 'iot-data-widget' ),
				)
			);
		}

		// Krok 4 (Tydzień 6) renderuje podgląd server-side na stronie admina,
		// która normalnie nigdy nie przechodzi przez wp_enqueue_scripts front
		// strony (IOTDW_Shortcode::maybe_enqueue_assets(), gated is_singular())
		// — bez tego podgląd wyglądałby bez stylów. Ten sam problem i to samo
		// rozwiązanie (ręczny enqueue) co IOTDW_Embed::render_embed_html().
		wp_enqueue_style(
			'iot-data-widget',
			IOTDW_PLUGIN_URL . 'public/css/widget-styles.css',
			array(),
			IOTDW_VERSION
		);

		IOTDW_Rest_Api::enqueue_test_connection_script();

		// admin-toolkit.css (`iot-dw-wizard` i pozostałe partiale toolkitu) —
		// ładowane centralnie, patrz
		// IOTDW_Plugin::maybe_enqueue_admin_toolkit_css() (code review v1.8).
	}

	/**
	 * Grupy typów połączeń do Kroku 1 — dwupoziomowy wybór (grupa, potem
	 * ew. podtyp), żeby nie przytłoczyć użytkownika płaską listą 8 opcji
	 * naraz (patrz plan, Tydzień 3, krok 2). Grupy z dokładnie jednym typem
	 * prowadzą prosto do Kroku 2, bez zbędnego dodatkowego kliknięcia.
	 *
	 * @return array<string, array{label: string, types: array<string, array{label: string, connector: string, preset: string}>}>
	 */
	public static function get_groups(): array {
		return array(
			// Poprawka 2.1 (docs/archiwum/poprawki-ogolne.md) — jedyna grupa z kluczem
			// 'action': zamiast prowadzić do Kroku 2 (konfiguracja), submituje
			// bezpośrednio do handle_use_demo() (POST, zapisuje/reużywa gotowe
			// demo-połączenie) i ląduje prosto w Kroku 4 z podglądem — patrz
			// step-1-connection-type.php (renderuje tę jedną grupę jako
			// przycisk formularza, nie link) i find_type()/routing w
			// render_page() (typ 'demo_weather' MUSI być rozpoznawalny przez
			// find_type(), stąd nadal żyje w 'types' poniżej, mimo że Krok 1
			// nigdy nie linkuje do niego przez Krok 2).
			'demo'           => array(
				'label'  => __( 'Try it with demo data (no setup)', 'iot-data-widget' ),
				'action' => self::USE_DEMO_ACTION,
				'types'  => array(
					'demo_weather' => array(
						'label'     => __( 'Demo weather data', 'iot-data-widget' ),
						'connector' => 'rest',
						'preset'    => '',
					),
				),
			),
			'home_assistant' => array(
				'label' => __( 'Home Assistant', 'iot-data-widget' ),
				'types' => array(
					'home_assistant' => array(
						'label'     => __( 'Home Assistant', 'iot-data-widget' ),
						'connector' => 'home_assistant',
						'preset'    => '',
					),
				),
			),
			'local_device'   => array(
				'label' => __( 'Local device', 'iot-data-widget' ),
				'types' => array(
					'shelly_gen1' => array(
						'label'     => __( 'Shelly (Gen1)', 'iot-data-widget' ),
						'connector' => 'rest',
						'preset'    => 'shelly_gen1',
					),
					'shelly_gen2' => array(
						'label'     => __( 'Shelly (Gen2+)', 'iot-data-widget' ),
						'connector' => 'rest',
						'preset'    => 'shelly_gen2',
					),
					'tasmota'     => array(
						'label'     => __( 'Tasmota', 'iot-data-widget' ),
						'connector' => 'rest',
						'preset'    => 'tasmota',
					),
					'esphome'     => array(
						'label'     => __( 'ESPHome (web_server)', 'iot-data-widget' ),
						'connector' => 'rest',
						'preset'    => 'esphome_web_server',
					),
				),
			),
			'cloud'          => array(
				'label' => __( 'Cloud', 'iot-data-widget' ),
				'types' => array(
					'shelly_cloud' => array(
						'label'     => __( 'Shelly Cloud', 'iot-data-widget' ),
						'connector' => 'shelly_cloud',
						'preset'    => '',
					),
					// Poprawka 2026-08-28 — reużywa preset 'supla_direct'
					// (IOTDW_Connector_Presets), ten sam mechanizm co
					// Local device poniżej: connector='rest' + preset
					// wybierają tryb "Choose device type" na Kroku 2, tam
					// Krok 2 pokazuje już poprawnie zrelabelowane pole na
					// link zamiast adresu IP (patrz connector-fields.php).
					'supla_direct' => array(
						'label'     => __( 'Supla', 'iot-data-widget' ),
						'connector' => 'rest',
						'preset'    => 'supla_direct',
					),
				),
			),
			'mqtt'           => array(
				'label' => __( 'MQTT', 'iot-data-widget' ),
				'types' => array(
					'mqtt_polling'  => array(
						'label'     => __( 'MQTT (polling via WP-Cron)', 'iot-data-widget' ),
						'connector' => 'mqtt_polling',
						'preset'    => '',
					),
					'mqtt_listener' => array(
						'label'     => __( 'MQTT (listener — requires server access)', 'iot-data-widget' ),
						'connector' => 'mqtt_listener',
						'preset'    => '',
					),
				),
			),
			'advanced'       => array(
				'label' => __( 'Advanced', 'iot-data-widget' ),
				'types' => array(
					'rest_advanced' => array(
						'label'     => __( 'Generic REST (advanced/JSONPath)', 'iot-data-widget' ),
						'connector' => 'rest',
						'preset'    => '',
					),
				),
			),
		);
	}

	/**
	 * Metoda get_groups() zawężona do typów FIZYCZNIE dostępnych w tej
	 * paczce — do renderowania Kroku 1. Celowo NIE zmienia get_groups()
	 * samego (find_type() musi rozpoznawać WSZYSTKIE typy niezależnie od
	 * tego, co jest dziś zainstalowane — np. przy edycji istniejącego
	 * połączenia zapisanego, gdy Pro było jeszcze aktywne), tylko filtruje
	 * tu, przy renderowaniu wyboru dla NOWEGO połączenia.
	 *
	 * Poprawki po recenzji wp.org (Guideline 5, 2026-09-24) — dawniej
	 * `iotdw_is_premium()` + `get_free_tier_types()` (runtime license-check
	 * gating kodu, który i tak fizycznie istniał w paczce darmowej). Teraz
	 * WYŁĄCZNIE fizyczna obecność: preset (Shelly/Tasmota/ESPHome/Supla)
	 * wymaga `IOTDW_Connector_Presets` (Pro), reszta typów wymaga, żeby ich
	 * connector był w ogóle zarejestrowany w IOTDW_Connector_Manager (co
	 * samo w sobie jest już class_exists()-gated dla Shelly Cloud/MQTT —
	 * patrz jej konstruktor) — bez osobnej listy do utrzymywania w dwóch
	 * miejscach.
	 *
	 * 'demo' zostaje ZAWSZE — to bezpłatny podgląd na publicznym API, nie
	 * prawdziwe użycie konektora REST/JSONPath; blokowanie go zaprzeczałoby
	 * jego własnemu celowi ("wypróbuj przed konfiguracją").
	 *
	 * @return array<string, array{label: string, types: array, action?: string}>
	 */
	public static function get_groups_for_current_tier(): array {
		$groups                = self::get_groups();
		$connector_manager     = new IOTDW_Connector_Manager();
		$registered_connectors = array_keys( $connector_manager->get_types() );

		foreach ( $groups as $group_key => $group ) {
			if ( 'demo' === $group_key ) {
				continue;
			}

			$group['types'] = array_filter(
				$group['types'],
				static function ( array $type ) use ( $registered_connectors ) {
					if ( '' !== ( $type['preset'] ?? '' ) ) {
						return class_exists( 'IOTDW_Connector_Presets' );
					}

					return in_array( $type['connector'], $registered_connectors, true );
				}
			);

			if ( empty( $group['types'] ) ) {
				unset( $groups[ $group_key ] );
				continue;
			}

			$groups[ $group_key ] = $group;
		}

		return $groups;
	}

	/**
	 * Znajduje definicję typu po jego kluczu, niezależnie od grupy — do
	 * użycia w Kroku 2 (Tydzień 4+), żeby przełożyć wybór z Kroku 1 na
	 * config konektora (connector/preset).
	 *
	 * @param string $type_key Klucz typu (np. "shelly_gen1").
	 * @return array{label: string, connector: string, preset: string}|null
	 */
	public static function find_type( string $type_key ): ?array {
		if ( '' === $type_key ) {
			return null;
		}

		foreach ( self::get_groups() as $group ) {
			if ( isset( $group['types'][ $type_key ] ) ) {
				return $group['types'][ $type_key ];
			}
		}

		return null;
	}

	/**
	 * Zwraca klucz grupy dla danego typu, TYLKO jeśli ta grupa ma więcej niż
	 * jeden typ (czyli faktycznie ma osobną podlistę w Kroku 1, do której
	 * "Back" z Kroku 2 ma sens wracać — patrz step-1-connection-type.php:
	 * grupy z DOKŁADNIE jednym typem, np. "Home Assistant", linkują od razu
	 * do step=2 i nie mają żadnej podlisty do powrotu). Pusty string dla
	 * grup jednotypowych albo nieznalezionego typu — "Back" ląduje wtedy na
	 * liście grup najwyższego poziomu, co jest poprawnym zachowaniem.
	 *
	 * @param string $type_key Klucz typu.
	 * @return string
	 */
	private static function find_group_key_for_type( string $type_key ): string {
		foreach ( self::get_groups() as $group_slug => $group ) {
			if ( isset( $group['types'][ $type_key ] ) && count( $group['types'] ) > 1 ) {
				return $group_slug;
			}
		}

		return '';
	}

	/**
	 * Renderuje stronę kreatora — routing pomiędzy krokami po parametrach
	 * zapytania. Czysta nawigacja (GET, bez zmiany stanu), stąd brak
	 * nonce'a — ten sam wzorzec co np. parametr "edit" w ustawieniach
	 * połączeń (IOTDW_Admin_Page). Każdy krok wyższy niż 1 wymaga
	 * poprawnych parametrów (typ, a od Kroku 3 też istniejącego połączenia)
	 * — ich brak/nieprawidłowość bezpiecznie spada z powrotem do Kroku 1,
	 * zamiast renderować zepsutą stronę.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Push mode (v2.0) — ścieżka całkowicie osobna od pull poniżej,
		// sprawdzana PRZED czymkolwiek innym, żeby 'mode=push' nigdy nie
		// trafiło przez pomyłkę w routing pull. Patrz render_push_mode().
		$mode = isset( $_GET['mode'] ) ? sanitize_key( wp_unslash( $_GET['mode'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'push' === $mode ) {
			$this->render_push_mode();
			return;
		}

		$step          = isset( $_GET['step'] ) ? absint( $_GET['step'] ) : 1; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$type_key      = isset( $_GET['type'] ) ? sanitize_key( wp_unslash( $_GET['type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$group_key     = isset( $_GET['group'] ) ? sanitize_key( wp_unslash( $_GET['group'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$connection_id = isset( $_GET['connection'] ) ? sanitize_text_field( wp_unslash( $_GET['connection'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		// array|string zależnie od Kroku 3 (kreator multi-entity, v1.9): dla
		// konektorów Listable to entity[]=a&entity[]=b (kilka zaznaczonych),
		// dla pozostałych — jak dotychczas, pojedyncze entity=x. Znormalizowane
		// od razu do tablicy, żeby Krok 4/5 obsługiwały oba przypadki jednym
		// kodem. Sanityzacja przy odczycie (wymóg wp.org, 1.0.10); od MAINT-2
		// ID zmienione przez sanityzację (np. %ab) nie przechodzą dalej po
		// cichu okrojone — patrz sanitize_entity_input(). Odczyt przez
		// wp_kses_data() (usuwa HTML, nie zmienia %xx), żeby dało się wykryć
		// ID, które sanitize_text_field() by okroiła. map_deep() podaje
		// wp_kses_data() tylko skalary — spreparowane zagnieżdżone tablice nie
		// wywołują błędu (odrzuca je sanitize_entity_input()).
		$entity_read  = isset( $_GET['entity'] ) ? map_deep( wp_unslash( $_GET['entity'] ), 'wp_kses_data' ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$entity_input = self::sanitize_entity_input( $entity_read );
		$entity_ids   = $entity_input['ids'];
		// Powrót z handle_save_entities() po odrzuceniu ID (flaga, nie dane).
		$entity_error = ! empty( $entity_input['rejected'] ) || ( isset( $_GET['entity_error'] ) && 1 === absint( $_GET['entity_error'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		// Zupełnie świeże wejście (brak trybu, brak typu) — najpierw pytanie
		// o lokalizację WordPressa (§ "Decyzja architektoniczna",
		// docs/archiwum/mvp-v2-0-push-mode-plan.md), zanim pokażemy listę typów
		// urządzeń. 'mode=pull' oznacza, że użytkownik już odpowiedział na
		// Kroku 0 — od tego momentu reszta (Krok 1-5 poniżej) zostaje
		// DOKŁADNIE tym, czym była przed v2.0, zero zmian ("Nie
		// przepisywać istniejącego pulla przy okazji", §7 planu).
		// Zgłoszenie 2026-09-07 (Playwright e2e, blok 5.3 listy do premiery):
		// linki generowane WEWNĄTRZ Kroku 1 dla grup z więcej niż jednym typem
		// ("Local device", "Cloud" — patrz step-1-connection-type.php) budują
		// URL od zera (add_query_arg(array('group'=>...), $base_url)) i NIE
		// niosą 'mode' — bez $group_key tutaj jako DODATKOWEGO dowodu "user już
		// minął Krok 0", taki klik wracał na Krok 0 zamiast pokazać listę
		// urządzeń w tej grupie (Kroki 1-5 dla grup jednotypowych, np. "Home
		// Assistant", nie miały tego problemu — ich link idzie od razu do
		// step=2&type=..., co samo w sobie już zwalnia z tego warunku).
		if ( '' === $mode && '' === $group_key && null === self::find_type( $type_key ) ) {
			$this->render_step_0();
			return;
		}

		if ( null === self::find_type( $type_key ) ) {
			$this->render_step_1( $group_key, $mode );
			return;
		}

		if ( 2 === $step ) {
			$this->render_step_2( $type_key, $connection_id, $mode );
			return;
		}

		$connection = '' !== $connection_id ? $this->connector_manager->find_by_id_or_name( $connection_id ) : null;

		// Odrzucone ID encji: Krok 3 z komunikatem zamiast Kroku 4/5 z
		// okrojonym identyfikatorem.
		if ( null !== $connection && ( 3 === $step || ( $entity_error && $step >= 4 ) ) ) {
			$this->render_step_3( $type_key, $connection, $entity_error );
			return;
		}

		if ( 4 === $step && null !== $connection && ! empty( $entity_ids ) ) {
			$this->render_step_4( $type_key, $connection, $entity_ids );
			return;
		}

		if ( 5 === $step && null !== $connection && ! empty( $entity_ids ) ) {
			$this->render_step_5( $type_key, $connection, $entity_ids );
			return;
		}

		$this->render_step_1( '', $mode );
	}

	/**
	 * Renderuje Krok 0 (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md) — pytanie o
	 * lokalizację WordPressa, PRZED wyborem typu urządzenia. Push jest
	 * domyślnie zalecany ("Nie wiem? → push, bezpieczniejszy domyślny
	 * wybór" — większość hostingu współdzielonego nigdy nie osiągnie
	 * prywatnego adresu urządzenia, więc pull jako domyślny wybór częściej
	 * kończyłby się nieudanym pierwszym wrażeniem).
	 */
	private function render_step_0(): void {
		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-0-location.php';
	}

	/**
	 * Platformy wspierane przez generator snippetów push (§4-5 planu) —
	 * osobna, mniejsza lista niż pełne get_groups() (pull): push nie
	 * dotyczy MQTT (protokół z natury już "push" na poziomie brokera, nie
	 * potrzebuje tego mechanizmu), Shelly Cloud (chmura producenta, nie
	 * urządzenie użytkownika) ani trybu zaawansowanego (brak jednego,
	 * konkretnego firmware'u, do którego dałoby się wygenerować snippet).
	 *
	 * @return array<string, string> [klucz platformy => etykieta].
	 */
	public static function get_push_platforms(): array {
		return array(
			'shelly_gen2'    => __( 'Shelly (Gen2+)', 'iot-data-widget' ),
			'tasmota'        => __( 'Tasmota', 'iot-data-widget' ),
			'esphome'        => __( 'ESPHome', 'iot-data-widget' ),
			'home_assistant' => __( 'Home Assistant', 'iot-data-widget' ),
		);
	}

	/**
	 * Routing wewnątrz trybu push (mode=push) — osobny od routingu pull w
	 * render_page(), bo push ma inny kształt kroków (platforma → kanał →
	 * snippet, nie grupa/typ → config → encja → podgląd → koniec).
	 */
	private function render_push_mode(): void {
		$push_type     = isset( $_GET['push_type'] ) ? sanitize_key( wp_unslash( $_GET['push_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$connection_id = isset( $_GET['channel'] ) ? sanitize_text_field( wp_unslash( $_GET['channel'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( '' === $push_type || ! array_key_exists( $push_type, self::get_push_platforms() ) ) {
			$this->render_push_platform_step();
			return;
		}

		$connection = '' !== $connection_id ? $this->connector_manager->find_by_id_or_name( $connection_id ) : null;

		if ( null === $connection || IOTDW_Push_Channel_Manager::CONNECTOR_TYPE !== ( $connection['connector'] ?? '' ) ) {
			$this->render_push_channel_form_step( $push_type );
			return;
		}

		$this->render_push_snippet_step( $push_type, $connection );
	}

	/**
	 * Krok "wybierz platformę" ścieżki push — analogiczny do Kroku 1 pull
	 * (get_groups()), ale bez podziału na grupy: tylko 4 platformy naraz,
	 * płasko (get_push_platforms()), bo nie ma wystarczająco dużo pozycji,
	 * żeby podział na grupy miał sens.
	 */
	private function render_push_platform_step(): void {
		$iotdw_platforms = self::get_push_platforms();

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-push-1-platform.php';
	}

	/**
	 * Krok "utwórz kanał" ścieżki push — formularz (nazwa + lista nazw
	 * encji), submitowany do handle_create_push_channel().
	 *
	 * @param string $push_type Wybrana platforma (patrz get_push_platforms()).
	 */
	private function render_push_channel_form_step( string $push_type ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- $push_type is used by the required view below, not directly in this method.
		$errors  = array();
		$editing = array(
			'name'     => '',
			'entities' => '',
		);

		$error_transient_key = self::PUSH_CHANNEL_FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id();
		$form_error          = get_transient( $error_transient_key );

		if ( is_array( $form_error ) ) {
			delete_transient( $error_transient_key );
			$errors  = $form_error['errors'];
			$editing = $form_error['data'];
		}

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-push-2-channel.php';
	}

	/**
	 * Rozbija surowy tekst (jeden na linię ALBO oddzielone przecinkami — oba
	 * naraz też działają, na wypadek wklejenia listy z arkusza) na listę
	 * znormalizowanych nazw encji. Sama normalizacja (lowercase/trim) musi
	 * być IDENTYCZNA z IOTDW_Push_Ingest::process_reading(), inaczej
	 * whitelist zapisana tutaj nigdy nie pasowałaby do tego, co faktycznie
	 * porówna ingest przy odbiorze.
	 *
	 * @param string $raw Surowa treść pola tekstowego formularza.
	 * @return string[]
	 */
	private function parse_entity_list( string $raw ): array {
		$parts = preg_split( '/[\r\n,]+/', $raw );
		$keys  = array_map(
			static function ( $part ) {
				return strtolower( trim( $part ) );
			},
			false !== $parts ? $parts : array()
		);

		return array_values( array_unique( array_filter( $keys, 'strlen' ) ) );
	}

	/**
	 * Obsługuje utworzenie kanału push — generuje token (pokazany raz, patrz
	 * PUSH_TOKEN_TRANSIENT_PREFIX), zapisuje połączenie (connector='push',
	 * tylko hash tokenu) i whitelistę encji, przekierowuje do ekranu snippetów.
	 */
	public function handle_create_push_channel(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'iot-data-widget' ), 403 );
		}

		check_admin_referer( self::PUSH_CREATE_CHANNEL_ACTION );

		$push_type = isset( $_POST['push_type'] ) ? sanitize_key( wp_unslash( $_POST['push_type'] ) ) : '';
		$name      = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$raw_list  = isset( $_POST['entities'] ) ? sanitize_textarea_field( wp_unslash( $_POST['entities'] ) ) : '';

		$entity_keys = $this->parse_entity_list( $raw_list );
		$errors      = array();

		if ( '' === $name ) {
			$errors[] = __( 'Please enter a name for this channel.', 'iot-data-widget' );
		}

		if ( empty( $entity_keys ) ) {
			$errors[] = __( 'Please list at least one entity name.', 'iot-data-widget' );
		}

		foreach ( $entity_keys as $entity_key ) {
			if ( ! preg_match( IOTDW_Push_Ingest::ENTITY_KEY_PATTERN, $entity_key ) ) {
				$errors[] = sprintf(
					/* translators: %s: the invalid entity name as typed */
					__( '"%s" is not a valid entity name — use only lowercase letters, numbers, and _ . : -', 'iot-data-widget' ),
					$entity_key
				);
			}
		}

		$connections = $this->connector_manager->get_connections();

		if ( ! empty( $errors ) ) {
			set_transient(
				self::PUSH_CHANNEL_FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'errors' => $errors,
					'data'   => array(
						'name'     => $name,
						'entities' => $raw_list,
					),
				),
				MINUTE_IN_SECONDS
			);

			wp_safe_redirect(
				add_query_arg(
					array(
						'page'      => self::PAGE_SLUG,
						'mode'      => 'push',
						'push_type' => $push_type,
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$connection_id = wp_generate_uuid4();
		$token         = IOTDW_Push_Channel_Manager::generate_token();

		$connections[] = array(
			'id'         => $connection_id,
			'name'       => $name,
			'connector'  => IOTDW_Push_Channel_Manager::CONNECTOR_TYPE,
			'token_hash' => IOTDW_Push_Channel_Manager::hash_token( $token ),
		);
		$this->connector_manager->save_connections( $connections );

		$this->push_channel_manager->set_allowed_entities( $connection_id, $entity_keys );

		// TTL krótki celowo — tylko na czas przekierowania, nie "chwilę do
		// obejrzenia": patrz komentarz PUSH_TOKEN_TRANSIENT_PREFIX o
		// jednorazowości.
		set_transient( self::PUSH_TOKEN_TRANSIENT_PREFIX . $connection_id, $token, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'mode'      => 'push',
					'push_type' => $push_type,
					'channel'   => $connection_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Obsługuje unieważnienie i wygenerowanie nowego tokenu (§3 planu).
	 * Wołane z ekranu snippetów kreatora — przekierowuje z powrotem tam,
	 * ten sam mechanizm jednorazowego transienta co przy tworzeniu kanału.
	 */
	public function handle_regenerate_push_token(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'iot-data-widget' ), 403 );
		}

		check_admin_referer( self::PUSH_REGENERATE_TOKEN_ACTION );

		$connection_id = isset( $_POST['channel'] ) ? sanitize_text_field( wp_unslash( $_POST['channel'] ) ) : '';
		$push_type     = isset( $_POST['push_type'] ) ? sanitize_key( wp_unslash( $_POST['push_type'] ) ) : '';
		$connection    = $this->connector_manager->find_by_id_or_name( $connection_id );

		if ( null === $connection || IOTDW_Push_Channel_Manager::CONNECTOR_TYPE !== ( $connection['connector'] ?? '' ) ) {
			wp_die( esc_html__( 'Unknown push channel.', 'iot-data-widget' ), 404 );
		}

		$token = IOTDW_Push_Channel_Manager::generate_token();

		$connections = array_map(
			function ( $existing ) use ( $connection_id, $token ) {
				if ( $existing['id'] === $connection_id ) {
					$existing['token_hash'] = IOTDW_Push_Channel_Manager::hash_token( $token );
				}
				return $existing;
			},
			$this->connector_manager->get_connections()
		);
		$this->connector_manager->save_connections( $connections );

		set_transient( self::PUSH_TOKEN_TRANSIENT_PREFIX . $connection_id, $token, 5 * MINUTE_IN_SECONDS );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'      => self::PAGE_SLUG,
					'mode'      => 'push',
					'push_type' => $push_type,
					'channel'   => $connection_id,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Krok "snippet" ścieżki push — pokazuje (jeśli dostępny, patrz
	 * PUSH_TOKEN_TRANSIENT_PREFIX) surowy token DOKŁADNIE RAZ, gotowe
	 * snippety per platforma z podstawionym prawdziwym adresem/tokenem/
	 * nazwami encji, i panel "sprawdź, czy dane docierają" (§6 planu).
	 *
	 * @param string               $push_type  Wybrana platforma.
	 * @param array<string,string> $iotdw_connection Zapisany kanał (połączenie 'push').
	 */
	private function render_push_snippet_step( string $push_type, array $iotdw_connection ): void {
		$token_transient_key = self::PUSH_TOKEN_TRANSIENT_PREFIX . $iotdw_connection['id'];
		$raw_token           = get_transient( $token_transient_key );

		if ( false !== $raw_token ) {
			delete_transient( $token_transient_key );
		}

		$entities = $this->push_channel_manager->get_entities_for_channel( $iotdw_connection['id'] );
		$snippets = false !== $raw_token
			? IOTDW_Push_Snippet_Generator::generate( $push_type, $iotdw_connection, $raw_token, wp_list_pluck( $entities, 'entity_key' ) )
			: array();

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-push-3-snippet.php';
	}

	/**
	 * Renderuje Krok 1 (grupa/podtyp) — wydzielone z render_page(), żeby
	 * dało się je wywołać zarówno domyślnie, jak i jako bezpieczny fallback
	 * z wyższych kroków, gdy ich parametry są nieprawidłowe.
	 *
	 * @param string $group_key Wybrana grupa (pusty string = jeszcze nie wybrano żadnej).
	 * @param string $mode      'pull'/'push'/'' — patrz render_page(); wątek dalej do widoku, żeby linki grup wielotypowych ("Local device", "Cloud") go nie gubiły (zgłoszenie 2026-09-07).
	 */
	private function render_step_1( string $group_key, string $mode = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $group_key/$mode used by the required view below, not directly in this method.
		$groups = self::get_groups_for_current_tier();

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-1-connection-type.php';
	}

	/**
	 * Obsługuje "Wypróbuj z danymi demo" (Krok 1, poprawka 2.1,
	 * docs/archiwum/poprawki-ogolne.md) — zapisuje/reużywa jedno, stałe demo-połączenie
	 * (Open-Meteo, bez autoryzacji) i ląduje prosto w Kroku 4 z gotowym
	 * podglądem, pomijając Krok 2 (konfiguracja) i Krok 3 (wybór encji)
	 * całkowicie — nie ma czego konfigurować ani wybierać, encja jest znana
	 * z góry (DEMO_ENTITY_PATH). POST + nonce, nie czysta GET-nawigacja jak
	 * reszta Kroku 1 — to jedyny przycisk tam, który faktycznie coś zapisuje.
	 */
	public function handle_use_demo(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'iot-data-widget' ), 403 );
		}

		check_admin_referer( self::USE_DEMO_ACTION );

		$connection = $this->find_or_create_demo_connection();

		if ( null === $connection ) {
			// Przekierowanie na listę połączeń (nie z powrotem do kreatora) —
			// to ten sam widok/mechanizm notice co IOTDW_Admin_Page, żeby nie
			// duplikować obsługi 'iotdw_notice' w widoku kreatora dla
			// jednego, rzadkiego przypadku brzegowego.
			wp_safe_redirect(
				add_query_arg( 'iotdw_notice', 'demo_failed', admin_url( 'admin.php?page=iot-data-widget' ) )
			);
			exit;
		}

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'       => self::PAGE_SLUG,
					'step'       => 4,
					'type'       => 'demo_weather',
					'connection' => $connection['id'],
					'entity'     => self::DEMO_ENTITY_PATH,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Zwraca istniejące demo-połączenie (po nazwie, patrz DEMO_CONNECTION_NAME)
	 * albo tworzy je, jeśli to pierwsze kliknięcie "Wypróbuj z danymi demo"
	 * na tej instalacji — nigdy nie mnoży duplikatów.
	 *
	 * Demo liczy się do limitu połączeń wersji darmowej tak samo jak każde
	 * inne (Faza 5 planu freemium) — celowo bez wyjątku, żeby nie
	 * komplikować jedynego choke pointu w save_connections(). Użytkownik z
	 * już jednym prawdziwym połączeniem, który kliknie "Wypróbuj demo",
	 * dostaje null i czytelny komunikat (patrz handle_use_demo()) zamiast
	 * cichej porażki albo złamania limitu.
	 *
	 * @return array<string, string>|null Null — odrzucone przez limit wersji darmowej.
	 */
	private function find_or_create_demo_connection(): ?array {
		$existing = $this->connector_manager->find_by_id_or_name( self::DEMO_CONNECTION_NAME );

		if ( null !== $existing ) {
			return $existing;
		}

		$connection = array(
			'id'        => wp_generate_uuid4(),
			'name'      => self::DEMO_CONNECTION_NAME,
			'connector' => 'rest',
			'base_url'  => self::DEMO_BASE_URL,
			'token'     => '',
			'preset'    => '',
			'device_ip' => '',
		);

		$connections   = $this->connector_manager->get_connections();
		$connections[] = $connection;

		if ( ! $this->connector_manager->save_connections( $connections ) ) {
			return null;
		}

		return $connection;
	}

	/**
	 * Renderuje Krok 2 — konfiguracja + test na żywo (v1.4 Tydzień 4),
	 * teraz z prawdziwym zapisem (Tydzień 5): formularz submituje się do
	 * IOTDW_Admin_Page::handle_save() (ten sam handler co zwykła strona
	 * połączeń — patrz jej docblock), z ukrytym polem 'iotdw_wizard_type',
	 * które mówi handle_save(), żeby po sukcesie/błędzie wrócić tutaj (Krok
	 * 3), a nie do listy połączeń.
	 *
	 * $connection_id (jeśli obecny w URL — powrót z Kroku 3 przez "Back")
	 * wczytuje PEŁNE zapisane połączenie do pól, żeby ponowny zapis
	 * zaktualizował ten sam rekord zamiast tworzyć duplikat (kryterium
	 * akceptacji Tygodnia 5). Błąd walidacji z poprzedniej próby (transient,
	 * ten sam mechanizm co IOTDW_Admin_Page::render_page()) ma pierwszeństwo
	 * przed obiema tymi ścieżkami.
	 *
	 * @param string $iotdw_type_key      Wybrany klucz typu (już zweryfikowany przez find_type() w render_page()).
	 * @param string $connection_id ID istniejącego połączenia (powrót z Kroku 3), albo pusty string dla nowego.
	 * @param string $mode          'pull'/'push'/'' — do linku "Back" (zgłoszenie 2026-09-07: bez tego "Back" gubił mode/group i wracał na Krok 0 zamiast na Krok 1).
	 */
	private function render_step_2( string $iotdw_type_key, string $connection_id, string $mode = '' ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $mode used by the required view below, not directly in this method.
		$type      = self::find_type( $iotdw_type_key );
		$group_key = self::find_group_key_for_type( $iotdw_type_key );
		$errors    = array();
		$editing   = null;

		$error_transient_key = IOTDW_Admin_Page::FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id();
		$form_error          = get_transient( $error_transient_key );

		if ( is_array( $form_error ) ) {
			delete_transient( $error_transient_key );
			$editing = $form_error['data'];
			$errors  = $form_error['errors'];
		} elseif ( '' !== $connection_id ) {
			$editing = $this->connector_manager->find_by_id_or_name( $connection_id );
		}

		if ( null === $editing ) {
			$editing = array(
				'connector' => $type['connector'],
				'preset'    => $type['preset'],
			);
		}

		$connector_types = $this->connector_manager->get_types();

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-2-configure.php';
	}

	/**
	 * Renderuje Krok 3 — wybór pierwszej encji (v1.4 Tydzień 5). Połączenie
	 * jest już zapisane (Krok 2). Sposób wyboru zależy od FAKTYCZNIE
	 * zapisanego połączenia ($connection['connector']/['preset']), nie od
	 * statycznej definicji typu z Kroku 1 — użytkownik mógł np. w Kroku 2
	 * przełączyć REST z trybu presetu na zaawansowany.
	 *
	 * Dwie różne ścieżki dalej stąd (kreator multi-entity, v1.9 Tydzień 3-4,
	 * patrz step-3-entity.php): konektory Listable (HA) submitują checkboxy
	 * przez POST do handle_save_entities() poniżej (zapisuje zaznaczone encje
	 * do iotdw_saved_entities, potem przekierowuje do Kroku 4 z entity[]=...);
	 * pozostałe konektory submitują tak jak dotychczas — zwykły GET z
	 * pojedynczym entity=..., bez zapisu, bez zmian w tym przepływie.
	 *
	 * @param string                $iotdw_type_key     Wybrany klucz typu (do linku "Back").
	 * @param array<string, string> $iotdw_connection   Zapisane połączenie (Krok 2).
	 * @param bool                  $iotdw_entity_error Czy pokazać komunikat o odrzuconym ID encji (MAINT-2).
	 */
	private function render_step_3( string $iotdw_type_key, array $iotdw_connection, bool $iotdw_entity_error = false ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $iotdw_entity_error used by the required view below.
		$metrics = array();

		if ( '' !== ( $iotdw_connection['preset'] ?? '' ) && class_exists( 'IOTDW_Connector_Presets' ) ) {
			$preset  = IOTDW_Connector_Presets::get( $iotdw_connection['preset'] );
			$metrics = null !== $preset ? $preset['metrics'] : array();
		}

		list( $manual_label, $manual_placeholder ) = $this->manual_entity_hint( $iotdw_connection );

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-3-entity.php';
	}

	/**
	 * Etykieta + placeholder pola wolnego wpisu encji, dopasowane do typu
	 * połączenia — używane w Kroku 3 tylko wtedy, gdy połączenie nie ma ani
	 * przeglądarki encji (HA), ani listy metryk presetu (Shelly/Tasmota).
	 *
	 * @param array<string, string> $connection Zapisane połączenie.
	 * @return array{0: string, 1: string} [etykieta, placeholder].
	 */
	private function manual_entity_hint( array $connection ): array {
		$is_mqtt = in_array(
			$connection['connector'] ?? '',
			array( IOTDW_Cron_Value_Cache::CONNECTOR_TYPE, IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE ),
			true
		);

		if ( $is_mqtt ) {
			return array( __( 'MQTT topic', 'iot-data-widget' ), 'home/livingroom/temperature' );
		}

		if ( 'shelly_cloud' === ( $connection['connector'] ?? '' ) ) {
			return array( __( 'Path in the Shelly Cloud response', 'iot-data-widget' ), 'data.device_status.switch:0.apower' );
		}

		$preset = ( '' !== ( $connection['preset'] ?? '' ) && class_exists( 'IOTDW_Connector_Presets' ) ) ? IOTDW_Connector_Presets::get( $connection['preset'] ) : null;

		if ( null !== $preset && ! empty( $preset['entity_in_url'] ) ) {
			return array( __( 'Entity path', 'iot-data-widget' ), 'sensor/outside_temperature' );
		}

		return array( __( 'Path in the API response (JSONPath)', 'iot-data-widget' ), 'data.temperature' );
	}

	/**
	 * Obsługuje submit checkboxów Kroku 3 dla konektorów Listable (kreator
	 * multi-entity, v1.9 Tydzień 3-4, docs/archiwum/poprawka-kreator-multi-entity.md).
	 * Jedyny punkt w kreatorze, gdzie krok wyższy niż 2 faktycznie zapisuje
	 * coś do bazy — stąd POST + nonce, w odróżnieniu od czystej GET-nawigacji
	 * reszty kroków (patrz docblock render_page()). Zaznaczone encje (id +
	 * czytelna etykieta) przychodzą jako JSON w ukrytym polu, zbudowanym przez
	 * wizard-entity-picker.js tuż przed submitem — checkboxy same w sobie nie
	 * mają atrybutu 'name' (patrz widok), więc to JEDYNE źródło wyboru
	 * użytkownika tutaj.
	 */
	public function handle_save_entities(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'iot-data-widget' ), 403 );
		}

		check_admin_referer( self::SAVE_ENTITIES_ACTION );

		$type_key      = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$connection_id = isset( $_POST['connection'] ) ? sanitize_text_field( wp_unslash( $_POST['connection'] ) ) : '';
		// MAINT-2: najpierw json_decode, potem sanityzacja pole po polu —
		// sanitize_text_field() na surowym JSON-ie gubił całą listę przy „<”
		// w nazwie z Home Assistanta i po cichu okrajał %xx. Pole przychodzi
		// jako zapis szesnastkowy UTF-8 (wizard-entity-picker.js) — znaki
		// 0-9a-f przechodzą przez sanitize_text_field() bez zmian; dalej:
		// hex2bin → json_decode → sanityzacja pole po polu.
		$entities_hex  = isset( $_POST['entities_json'] ) ? sanitize_text_field( wp_unslash( $_POST['entities_json'] ) ) : '';
		$entities_json = ( '' !== $entities_hex && 0 === strlen( $entities_hex ) % 2 && ctype_xdigit( $entities_hex ) ) ? (string) hex2bin( $entities_hex ) : '';
		$parsed        = self::sanitize_entities_json( $entities_json );
		$entities      = $parsed['entities'];

		$redirect_args = array(
			'page'       => self::PAGE_SLUG,
			'type'       => $type_key,
			'connection' => $connection_id,
		);

		// ID, które sanityzacja by zmieniła, nie są zapisywane po cichu
		// okrojone — powrót do Kroku 3 z komunikatem (kontrakt ID: patrz
		// sanitize_entities_json()).
		if ( '' !== $connection_id && ! empty( $parsed['rejected'] ) ) {
			$redirect_args['step']         = 3;
			$redirect_args['entity_error'] = 1;
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		// Brak zaznaczenia (JS wyłączony/oszukany, formularz wysłany mimo
		// disabled Continue) — wraca do Kroku 3 zamiast łamać się na Kroku 4,
		// który wymaga co najmniej jednej encji (patrz render_page()).
		if ( '' === $connection_id || empty( $entities ) ) {
			$redirect_args['step'] = 3;
			wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
			exit;
		}

		$this->saved_entities->save_many( $connection_id, $entities );

		$redirect_args['step']   = 4;
		$redirect_args['entity'] = wp_list_pluck( $entities, 'id' );

		wp_safe_redirect( add_query_arg( $redirect_args, admin_url( 'admin.php' ) ) );
		exit;
	}

	/**
	 * Dekoduje JSON z zaznaczonymi encjami (wizard-entity-picker.js) i
	 * sanityzuje go pole po polu — patrz handle_save_entities(). Kolejność
	 * (MAINT-2): wp_unslash → json_decode → pola. Nieprawidłowy JSON/kształt
	 * degraduje się do pustej listy, tak jak inny nieprawidłowy input.
	 *
	 * Kontrakt danych:
	 * - id — identyfikator encji Home Assistanta (ta ścieżka obsługuje tylko
	 *   konektory Listable, czyli HA: `domena.obiekt`, małe litery, cyfry,
	 *   podkreślenia). ID, które sanitize_text_field() by zmieniła (np. %ab,
	 *   HTML, znaki sterujące), trafiają do 'rejected' i nie są zapisywane;
	 * - label — dowolny tekst widoczny dla użytkownika: bez HTML, ale z
	 *   zachowanymi sekwencjami %xx (sanitize_label()).
	 *
	 * @param mixed $raw Odslashowana wartość $_POST['entities_json'].
	 * @return array{entities: array<int, array{id: string, label: string}>, rejected: string[]}
	 */
	public static function sanitize_entities_json( $raw ): array {
		$result  = array(
			'entities' => array(),
			'rejected' => array(),
		);
		$decoded = is_string( $raw ) ? json_decode( $raw, true ) : null;

		if ( ! is_array( $decoded ) ) {
			return $result;
		}

		foreach ( $decoded as $item ) {
			$id = is_array( $item ) && is_scalar( $item['id'] ?? null ) ? (string) $item['id'] : '';

			if ( '' === $id ) {
				continue;
			}

			$clean_id = sanitize_text_field( $id );

			if ( $clean_id !== $id ) {
				$result['rejected'][] = $clean_id;
				continue;
			}

			$result['entities'][] = array(
				'id'    => $clean_id,
				'label' => self::sanitize_label( is_scalar( $item['label'] ?? null ) ? (string) $item['label'] : '' ),
			);
		}

		return $result;
	}

	/**
	 * Etykieta encji (np. friendly_name z Home Assistanta): to samo co
	 * sanitize_text_field() — poprawny UTF-8, bez HTML, jedna linia, bez
	 * zbędnych spacji — ale BEZ usuwania sekwencji %xx, które w zwykłym
	 * tekście („Moc 50%ab”) nie są kodowaniem URL.
	 *
	 * Etykieta to zwykły tekst, nie HTML: po usunięciu znaczników pozostałe
	 * „<” (np. „Temp <20” z Home Assistanta) zostają dosłownie, a nie jako
	 * encja &lt; — inaczej edytor bloku (etykiety z REST jako tekst w React)
	 * pokazywałby „&lt;”. Znaczniki są już usunięte; wynik jest escapowany przy
	 * każdym wyświetleniu (esc_* w PHP, tekst w React).
	 *
	 * @param string $raw Etykieta.
	 * @return string
	 */
	public static function sanitize_label( string $raw ): string {
		$label = wp_check_invalid_utf8( $raw );

		if ( str_contains( $label, '<' ) ) {
			$label = wp_pre_kses_less_than( $label );
			$label = wp_strip_all_tags( $label, false );
			$label = str_replace( '&lt;', '<', $label );
		}

		$label = (string) preg_replace( '/[\r\n\t ]+/', ' ', $label );

		return trim( $label );
	}

	/**
	 * ID encji z URL-a Kroku 3 → 4/5 (string albo tablica). Jak
	 * sanitize_entities_json(): ID zmienione przez sanitize_text_field()
	 * (np. ręcznie wpisany temat MQTT/ścieżka JSON z %ab) nie przechodzą
	 * dalej po cichu okrojone, tylko trafiają do 'rejected' — kreator wraca
	 * do Kroku 3 z komunikatem.
	 *
	 * @param mixed $raw Odslashowana wartość $_GET['entity'].
	 * @return array{ids: string[], rejected: string[]}
	 */
	public static function sanitize_entity_input( $raw ): array {
		$result = array(
			'ids'      => array(),
			'rejected' => array(),
		);

		foreach ( is_array( $raw ) ? $raw : array( $raw ) as $value ) {
			if ( ! is_scalar( $value ) || '' === (string) $value ) {
				continue;
			}

			$clean = sanitize_text_field( (string) $value );

			if ( $clean !== (string) $value ) {
				$result['rejected'][] = $clean;
			} elseif ( '' !== $clean ) {
				$result['ids'][] = $clean;
			}
		}

		return $result;
	}

	/**
	 * Renderuje Krok 4 — podgląd na żywo + gotowy shortcode (v1.4 Tydzień 6).
	 * Podgląd to DOKŁADNIE ta sama ścieżka renderowania co front strony i
	 * edytor bloku — IOTDW_Shortcode::render(), nie osobna, trzecia kopia
	 * logiki renderującej (patrz jej reużycie już w IOTDW_Rest_Api::get_preview()).
	 * Styl na sztywno "card" — najprostszy, najbardziej uniwersalny; wybór
	 * innych stylów zostaje w standardowym edytorze bloku (Ustalenie z planu,
	 * krok 3), nie trzeba tego dublować w kreatorze.
	 *
	 * @param string                $iotdw_type_key    Wybrany klucz typu (do linku "Back").
	 * @param array<string, string> $iotdw_connection  Zapisane połączenie.
	 * @param string[]              $entity_ids  Encje zaznaczone w Kroku 3 — jedna dla konektorów nie-Listable
	 *                                            (bez zmian), kilka dla Listable (kreator multi-entity, v1.9 Tydzień 5).
	 */
	private function render_step_4( string $iotdw_type_key, array $iotdw_connection, array $entity_ids ): void {
		$previews = array();

		// Poprawka 4 (docs/archiwum/iot-data-widget-do-poprawy.md) — bez czytelnej
		// etykiety/jednostki demo-widget pokazywał surowe
		// "current_weather.temperature" i wartość bez jednostki (tryb
		// zaawansowany REST Connectora nie ma skąd ich znać, patrz
		// DEMO_UNIT). Dotyczy wyłącznie demo-połączenia — realne połączenia
		// użytkownika (HA/presety) już mają własną, poprawną etykietę/jednostkę.
		$is_demo               = self::DEMO_CONNECTION_NAME === ( $iotdw_connection['name'] ?? '' );
		$demo_label            = __( 'Outdoor temperature', 'iot-data-widget' );
		$shortcode_extra_attrs = '';

		if ( $is_demo ) {
			$shortcode_extra_attrs = sprintf(
				' label="%s" unit="%s"',
				self::escape_shortcode_attr( $demo_label ),
				self::escape_shortcode_attr( self::DEMO_UNIT )
			);
		}

		foreach ( $entity_ids as $entity_id ) {
			// Poprawka 3 (docs/archiwum/iot-data-widget-do-poprawy.md) — id połączenia,
			// nie jego nazwa: zmiana nazwy połączenia (np. z panelu Connections)
			// nie psuje już wygenerowanych tu shortcode'ów. Wsteczna
			// kompatybilność ze shortcode'ami zapisanymi wcześniej z nazwą
			// (albo wpisanymi ręcznie) jest już zapewniona przez
			// IOTDW_Connector_Manager::find_by_id_or_name(), którego
			// IOTDW_Shortcode::render() używa niezależnie od tej zmiany.
			$atts = array(
				'connection' => $iotdw_connection['id'],
				'entity'     => $entity_id,
				'style'      => 'card',
			);

			if ( $is_demo ) {
				$atts['label'] = $demo_label;
				$atts['unit']  = self::DEMO_UNIT;
			}

			$previews[] = array(
				'entity_id'      => $entity_id,
				'preview_html'   => $this->shortcode->render( $atts ),
				'shortcode_text' => sprintf(
					'[iotdw_widget style="card" connection="%s" entity="%s"%s]',
					self::escape_shortcode_attr( $iotdw_connection['id'] ),
					self::escape_shortcode_attr( $entity_id ),
					$shortcode_extra_attrs
				),
			);
		}

		$iotdw_back_url = add_query_arg(
			array(
				'page'       => self::PAGE_SLUG,
				'step'       => 3,
				'type'       => $iotdw_type_key,
				'connection' => $iotdw_connection['id'],
			),
			admin_url( 'admin.php' )
		);

		$iotdw_finish_url = add_query_arg(
			array(
				'page'       => self::PAGE_SLUG,
				'step'       => 5,
				'type'       => $iotdw_type_key,
				'connection' => $iotdw_connection['id'],
				'entity'     => $entity_ids,
			),
			admin_url( 'admin.php' )
		);

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-4-preview.php';
	}

	/**
	 * Renderuje Krok 5 — ekran końcowy (v1.4 Tydzień 7). Podsumowanie tego,
	 * co zostało skonfigurowane, plus zachęta do dalszej eksploracji — TA
	 * sama lista linków (IOTDW_Admin_Page::next_steps_links()) co
	 * dismissible checklista na głównym ekranie wtyczki, bo oba miejsca mają
	 * dokładnie ten sam cel: delikatne wskazanie kolejnych kroków, nie
	 * duplikować dwóch różnych list.
	 *
	 * @param string                $type_key   Wybrany klucz typu.
	 * @param array<string, string> $iotdw_connection Zapisane połączenie — użyte przez wymagany niżej widok (step-5-done.php), nie bezpośrednio w tej metodzie.
	 * @param string[]              $entity_ids Encje zaznaczone w Kroku 3 (jedna albo kilka, v1.9) — jak wyżej, użyte przez widok.
	 */
	private function render_step_5( string $type_key, array $iotdw_connection, array $entity_ids ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- $iotdw_connection/$entity_ids are used by the required view below.
		$type            = self::find_type( $type_key );
		$connections_url = admin_url( 'admin.php?page=iot-data-widget' );
		$next_steps      = IOTDW_Admin_Page::next_steps_links();

		require IOTDW_PLUGIN_DIR . 'admin/views/wizard/step-5-done.php';
	}

	/**
	 * Ucieka wartość atrybutu shortcode'u tak, żeby nie dało się wyjść poza
	 * cudzysłów — ten sam, prosty wzorzec (zamiana na apostrof) co
	 * blocks/iot-widget/src/save.js::escapeAttr(), żeby tekst shortcode'u z
	 * kreatora był spójny z tym generowanym przez edytor bloku.
	 *
	 * @param string $value Wartość atrybutu.
	 * @return string
	 */
	private static function escape_shortcode_attr( string $value ): string {
		return str_replace( '"', "'", $value );
	}
}
