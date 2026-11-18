<?php
/**
 * REST API dla edytora bloku Gutenberg (lista połączeń, autouzupełnianie
 * encji, podgląd na żywo). Wyłącznie dla zalogowanych administratorów —
 * to nie jest publiczne API.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Rest_Api
 */
class IOTDW_Rest_Api {

	/**
	 * Przestrzeń nazw REST API wtyczki.
	 */
	const NAMESPACE = 'iot-data-widget/v1';

	/**
	 * TTL cache listy encji dla konektorów implementujących
	 * IOTDW_Listable_Connector_Interface (v1.3 Tydzień 2) — dłuższy niż
	 * domyślny (`ENTITIES_TTL`), bo pełna lista encji (np. HA /api/states)
	 * jest droższa do pobrania i rzadziej się zmienia niż odczyt pojedynczego
	 * widgetu. `?refresh=1` pozwala świadomie pominąć cache, gdyby ktoś
	 * właśnie dodał nową encję i chce ją zobaczyć bez czekania na wygaśnięcie.
	 */
	const LISTABLE_ENTITIES_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * TTL cache listy encji dla pozostałych konektorów (presety REST — stała,
	 * z góry znana lista metryk, tania do przeliczenia; MQTT/REST-zaawansowany
	 * — zawsze pusta) — bez zmian względem zachowania sprzed v1.3.
	 */
	const ENTITIES_TTL = 30;

	/**
	 * Menedżer konektorów.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Warstwa cache.
	 *
	 * @var IOTDW_Cache
	 */
	private $cache;

	/**
	 * Obsługa shortcode — podgląd używa dokładnie tego samego renderowania
	 * co front strony, żadnej zduplikowanej logiki.
	 *
	 * @var IOTDW_Shortcode
	 */
	private $shortcode;

	/**
	 * Śledzenie użycia widgetów — do autoryzacji publicznego endpointu
	 * odświeżania danych (v1.8 Tydzień 1, patrz get_widget_data()).
	 *
	 * @var IOTDW_Widget_Usage_Tracker
	 */
	private $widget_usage_tracker;

	/**
	 * Zapisane encje per połączenie — szybki wybór w edytorze bloku bez
	 * requestu do źródła danych (kreator multi-entity, v1.9 Tydzień 6).
	 *
	 * @var IOTDW_Saved_Entities
	 */
	private $saved_entities;

	/**
	 * Whitelist encji + ostatnia wartość per kanał push (v2.0,
	 * docs/archiwum/mvp-v2-0-push-mode-plan.md §6, ekran "sprawdź czy dane docierają").
	 *
	 * @var IOTDW_Push_Channel_Manager
	 */
	private $push_channel_manager;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager    Menedżer konektorów.
	 * @param IOTDW_Cache                $cache                Warstwa cache.
	 * @param IOTDW_Shortcode            $shortcode            Obsługa shortcode.
	 * @param IOTDW_Widget_Usage_Tracker $widget_usage_tracker Śledzenie użycia widgetów.
	 * @param IOTDW_Saved_Entities       $saved_entities       Zapisane encje per połączenie.
	 * @param IOTDW_Push_Channel_Manager $push_channel_manager Whitelist encji + ostatnia wartość per kanał push (v2.0).
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Cache $cache, IOTDW_Shortcode $shortcode, IOTDW_Widget_Usage_Tracker $widget_usage_tracker, IOTDW_Saved_Entities $saved_entities, IOTDW_Push_Channel_Manager $push_channel_manager ) {
		$this->connector_manager    = $connector_manager;
		$this->cache                = $cache;
		$this->shortcode            = $shortcode;
		$this->widget_usage_tracker = $widget_usage_tracker;
		$this->saved_entities       = $saved_entities;
		$this->push_channel_manager = $push_channel_manager;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Rejestruje trasy REST API.
	 */
	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/connections',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_connections' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<connection>[^/]+)/entities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_entities' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'refresh' => array(
						'description' => __( 'Skip the cache and force a fresh fetch from the source (v1.3).', 'iot-data-widget' ),
						'type'        => 'boolean',
						'required'    => false,
						'default'     => false,
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/connections/(?P<connection>[^/]+)/saved-entities',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_saved_entities' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'get_preview' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/push-channels/(?P<channel>[^/]+)/status',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_push_channel_status' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/test-connection',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'test_connection' ),
				'permission_callback' => array( $this, 'check_permission' ),
			)
		);

		// Publiczny (v1.8 Tydzień 1) — jedyny endpoint w całej wtyczce bez
		// wymogu manage_options, patrz get_widget_data() i CLAUDE.md
		// "Rozszerzenie z v1.8". permission_callback celowo '__return_true':
		// autoryzacja NIE jest "kto jest zalogowany", tylko "czy ta konkretna
		// para (connection_id, entity_id) jest faktycznie widoczna publicznie"
		// — to sprawdza sam callback przez widget_usage_tracker, nie warstwa
		// uprawnień WP REST.
		//
		// connection_id/entity_id jako parametry query, NIE segmenty ścieżki
		// — encje MQTT to nazwy topiców, które rutynowo zawierają "/" (patrz
		// demo: "demo/livingroom/co2"); jako segment ścieżki wymagałoby to
		// rawurlencode("/") -> "%2F", które WP/serwer często dekodują z
		// powrotem PRZED dopasowaniem trasy — dokładnie ta sama klasa błędu,
		// co spacje w nazwach połączeń w get_entities() (patrz komentarz tam),
		// tylko gorsza, bo "/" jest separatorem samej trasy. Query string
		// nie ma tego problemu.
		register_rest_route(
			self::NAMESPACE,
			'/widget-data',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_widget_data' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'connection_id' => array(
						'type'     => 'string',
						'required' => true,
					),
					'entity_id'     => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
	}

	/**
	 * Ładuje admin/js/test-connection.js + localizuje URL endpointu/nonce —
	 * współdzielone przez IOTDW_Admin_Page (zwykła strona
	 * dodawania/edycji połączenia) i IOTDW_Setup_Wizard (Krok 2 kreatora,
	 * v1.4 Tydzień 4), żeby nie duplikować tego samego enqueue+localize w
	 * dwóch miejscach (patrz "Uwaga ogólna" w docs/archiwum/mvp-v1-4-plan.md).
	 * Statyczna i bezstanowa celowo — obie klasy wołające to nie mają (i nie
	 * powinny potrzebować) instancji IOTDW_Rest_Api.
	 */
	public static function enqueue_test_connection_script(): void {
		wp_enqueue_script(
			'iot-data-widget-test-connection',
			IOTDW_PLUGIN_URL . 'admin/js/test-connection.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_localize_script(
			'iot-data-widget-test-connection',
			'iotdwTestConnection',
			array(
				'restUrl'                   => esc_url_raw( rest_url( self::NAMESPACE ) ),
				'nonce'                     => wp_create_nonce( 'wp_rest' ),
				'testingLabel'              => __( 'Testing…', 'iot-data-widget' ),
				'networkErrorLabel'         => __( 'Could not reach the server. Check your internet connection and try again.', 'iot-data-widget' ),
				'noEntitiesLabel'           => __( 'No entities found for this connection.', 'iot-data-widget' ),
				'noMatchesLabel'            => __( 'No entities match your search/filter — try a different term.', 'iot-data-widget' ),
				/* translators: %d is replaced client-side (JS), not by sprintf — keep the literal "%d". */
				'selectedCountLabel'        => __( '%d selected', 'iot-data-widget' ),
				'copiedLabel'               => __( 'Copied!', 'iot-data-widget' ),
				'copyFailedLabel'           => __( 'Could not copy — select the text and copy it manually.', 'iot-data-widget' ),
				// %s zastępowane po stronie klienta (wykryty host), nie przez
				// sprintf() — ten sam wzorzec co selectedCountLabel wyżej.
				// Reużywane przez admin/js/connector-form.js (współdzielone
				// enqueue tej samej strony/partiala, patrz docblock
				// connector-fields.php) — ostrzeżenie dla pola "URL", gdy
				// wpisany host jest adresem sieci lokalnej (patrz
				// IOTDW_Abstract_Connector::maybe_explain_local_network_error()
				// po tej samej stronie, dla PHP).
				/* translators: %s is replaced client-side (JS) with the detected local-network host — keep the literal "%s". */
				'localNetworkWarningLabel'  => __( 'This looks like a local network address (%s) — it will only work if your WordPress server is on the same network as the device.', 'iot-data-widget' ),
				// Etykieta/placeholder pola "Device IP address" dla presetów
				// 'direct_url' (Supla — patrz IOTDW_Connector_Presets),
				// przełączane w connector-form.js po wyborze presetu; to
				// samo pole formularza (name="device_ip") niesie tam CAŁY
				// link, nie adres IP.
				'deviceIpFieldLabel'        => __( 'Device IP address', 'iot-data-widget' ),
				'directUrlFieldLabel'       => __( 'Supla direct link', 'iot-data-widget' ),
				'directUrlFieldPlaceholder' => 'https://svrX.supla.org/state/CHANNEL/HASH/read?format=json',
				// Etykieta pola "Authorization" (name="token") dla presetów
				// 'query_string_auth' (Tasmota — patrz IOTDW_Connector_Presets),
				// przełączane w connector-form.js po wyborze presetu; to pole
				// niesie tam samo hasło Tasmoty, nie surowy nagłówek.
				'authFieldLabel'            => __( 'Authorization', 'iot-data-widget' ),
				'tasmotaAuthFieldLabel'     => __( 'Password (Tasmota WebPassword)', 'iot-data-widget' ),
				// Klucze metryk KAŻDEGO presetu REST (Shelly/Tasmota/...),
				// do podpowiedzi (<datalist>) w polu "Entity/topic to test
				// with" (poprawka 2026-08-31) — bez tego użytkownik nie ma
				// jak się domyślić wewnętrznego klucza presetu (np.
				// "relay_state") i naturalnie wpisuje surowy klucz z JSON-a
				// zwracanego przez urządzenie (np. "POWER" dla Tasmoty),
				// który dla większości presetów jest zupełnie innym
				// ciągiem — patrz connector-form.js, updateTestEntitySuggestions().
				// Prezety bez metryk (tryb zaawansowany, ESPHome
				// entity_in_url) dają tu po prostu pustą tablicę.
				'presetMetrics'             => self::preset_metrics_for_js(),
			)
		);
	}

	/**
	 * Buduje [preset_key => [{id, label}, ...]] z pełnego rejestru presetów
	 * — patrz komentarz przy 'presetMetrics' w enqueue_test_connection_script().
	 *
	 * @return array<string, array<int, array{id: string, label: string}>>
	 */
	private static function preset_metrics_for_js(): array {
		$result = array();

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro; na
		// wersji darmowej ten plik fizycznie nie istnieje, więc formularz
		// połączenia po prostu nie dostaje żadnych podpowiedzi metryk
		// (tryb "advanced" nie ich potrzebuje) zamiast fatal errora.
		if ( ! class_exists( 'IOTDW_Connector_Presets' ) ) {
			return $result;
		}

		foreach ( IOTDW_Connector_Presets::get_all() as $preset_key => $preset ) {
			$result[ $preset_key ] = array();

			foreach ( $preset['metrics'] as $metric_key => $metric ) {
				$result[ $preset_key ][] = array(
					'id'    => $metric_key,
					'label' => $metric['label'],
				);
			}
		}

		return $result;
	}

	/**
	 * Dostęp tylko dla adminów — to narzędzie edytora, nie publiczne API.
	 *
	 * @return bool
	 */
	public function check_permission(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Zwraca listę połączeń (id + nazwa, nigdy token).
	 *
	 * @return WP_REST_Response
	 */
	public function get_connections(): WP_REST_Response {
		$connections = array_map(
			function ( $connection ) {
				return array(
					'id'   => $connection['id'] ?? '',
					'name' => $connection['name'] ?? '',
				);
			},
			$this->connector_manager->get_connections()
		);

		return rest_ensure_response( array_values( $connections ) );
	}

	/**
	 * Zwraca stan encji jednego kanału push — do ekranu "sprawdź, czy dane
	 * docierają" (§6 planu, docs/archiwum/mvp-v2-0-push-mode-plan.md), które odpytuje
	 * ten endpoint co kilka sekund przez ~60s po pokazaniu snippetu. Chroniony
	 * jak reszta tego API (manage_options) — to WEWNĘTRZNE narzędzie panelu
	 * admina, nie ten sam publiczny endpoint co IOTDW_Push_Ingest::handle_ingest()
	 * (który przyjmuje DANE od urządzeń); ten tylko CZYTA, co już odebrano.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return WP_REST_Response
	 */
	public function get_push_channel_status( WP_REST_Request $request ): WP_REST_Response {
		$connection_id = sanitize_text_field( rawurldecode( (string) $request->get_param( 'channel' ) ) );
		$connection    = $this->connector_manager->find_by_id_or_name( $connection_id );

		if ( null === $connection || IOTDW_Push_Channel_Manager::CONNECTOR_TYPE !== ( $connection['connector'] ?? '' ) ) {
			return rest_ensure_response( array( 'entities' => array() ) );
		}

		return rest_ensure_response(
			array( 'entities' => $this->push_channel_manager->get_entities_for_channel( $connection['id'] ) )
		);
	}

	/**
	 * Zwraca listę encji dla danego połączenia (do autouzupełniania w
	 * edytorze bloku i, dla połączeń implementujących
	 * IOTDW_Listable_Connector_Interface — v1.3 Tydzień 2/3, do przeglądarki
	 * encji). Świadomie JEDEN endpoint dla obu przypadków, nie osobna trasa —
	 * patrz uzasadnienie w CLAUDE.md ("Przeglądarka encji — jeden endpoint,
	 * nie dwa"). Odpowiedź zawsze jest tablicą (nigdy zawiniętym obiektem),
	 * żeby dotychczasowi konsumenci (edytor bloku, strona Alerts, sekcja
	 * "Manually tracked entities") działali bez żadnej zmiany — konektory
	 * implementujące nowy interfejs dokładają dodatkowe pola `domain`/
	 * `current_state` do każdego elementu, czysto addytywnie.
	 *
	 * Wolno tu żądanie #/connections/(?P<connection>[^/]+)/entities — WP nie
	 * dekoduje procentowo tego segmentu ścieżki automatycznie przed
	 * przekazaniem go do get_param(), więc np. "Demo Shelly" (nazwa
	 * połączenia ze spacją) dociera tu jako dosłowne "Demo%20Shelly", które
	 * nigdy nie dopasuje się do żadnej nazwy połączenia. Znalezione na żywo
	 * przy nagrywaniu dema v1.2 (Tydzień 11) — edytor bloku po cichu
	 * pokazywał puste pole tekstowe zamiast listy metryk presetu dla
	 * KAŻDEGO połączenia, którego nazwa zawierała spację (albo inny znak
	 * wymagający kodowania), od kiedy tylko istniał ten endpoint. rawurldecode()
	 * naprawia to bez zmiany kontraktu — połączenia bez takich znaków w
	 * nazwie (większość dotychczasowych testów, stąd błąd był niewidoczny)
	 * przechodzą przez to bez zmiany.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return WP_REST_Response|WP_Error Błąd tylko wtedy, gdy IOTDW_Listable_Connector_Interface::list_entities() rzuci wyjątkiem (np. HA nieosiągalne) — dotychczasowi konsumenci (Array.isArray()) i tak potraktują to jak "brak listy", bez żadnej zmiany zachowania.
	 */
	public function get_entities( WP_REST_Request $request ) {
		$connection_id = sanitize_text_field( rawurldecode( (string) $request->get_param( 'connection' ) ) );
		$connector     = $this->connector_manager->get_connector_for( $connection_id );

		if ( null === $connector ) {
			return rest_ensure_response( array() );
		}

		$cache_key = 'entities-list-' . $connection_id;

		if ( $request->get_param( 'refresh' ) ) {
			$this->cache->forget( $cache_key );
		}

		$is_listable = $connector instanceof IOTDW_Listable_Connector_Interface;
		$ttl         = $is_listable ? self::LISTABLE_ENTITIES_TTL : self::ENTITIES_TTL;

		try {
			$entities = $this->cache->remember(
				$cache_key,
				function () use ( $connector ) {
					return $connector->list_entities();
				},
				$ttl
			);
		} catch ( \Throwable $e ) {
			return new WP_Error(
				'iotdw_entities_unavailable',
				$e->getMessage(),
				array( 'status' => 502 )
			);
		}

		return rest_ensure_response( $entities );
	}

	/**
	 * Zwraca zapisane wcześniej (w kreatorze, Krok 3) encje dla połączenia —
	 * do szybkiego wyboru w edytorze bloku BEZ requestu do źródła danych
	 * (kreator multi-entity, v1.9 Tydzień 6). Pusta tablica (połączenie bez
	 * zapisanych encji — np. dodane spoza kreatora) to prawidłowy wynik, nie
	 * błąd: edit.js degraduje wtedy do dotychczasowego zachowania (pełny
	 * fetch get_entities() od razu), więc zero regresji dla połączeń, które
	 * nigdy nie przeszły przez kreator.
	 *
	 * Ta sama dekodowanie procentowe segmentu ścieżki co get_entities() (patrz
	 * jej komentarz) — z tego samego powodu (nazwy połączeń ze spacją).
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return WP_REST_Response
	 */
	public function get_saved_entities( WP_REST_Request $request ): WP_REST_Response {
		$id_or_name = sanitize_text_field( rawurldecode( (string) $request->get_param( 'connection' ) ) );
		$connection = $this->connector_manager->find_by_id_or_name( $id_or_name );

		if ( null === $connection ) {
			return rest_ensure_response( array() );
		}

		return rest_ensure_response( $this->saved_entities->get_for_connection( $connection['id'] ) );
	}

	/**
	 * Renderuje podgląd widgetu z bieżącymi atrybutami bloku — dokładnie
	 * przez ten sam kod co shortcode na froncie strony.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return WP_REST_Response
	 */
	public function get_preview( WP_REST_Request $request ): WP_REST_Response {
		$atts = array(
			'connection'    => sanitize_text_field( (string) $request->get_param( 'connection' ) ),
			'entity'        => sanitize_text_field( (string) $request->get_param( 'entity' ) ),
			'entities'      => sanitize_text_field( (string) $request->get_param( 'entities' ) ),
			'items'         => sanitize_text_field( (string) $request->get_param( 'items' ) ),
			'style'         => sanitize_key( (string) $request->get_param( 'style' ) ),
			'label'         => sanitize_text_field( (string) $request->get_param( 'label' ) ),
			'on_label'      => sanitize_text_field( (string) $request->get_param( 'on_label' ) ),
			'off_label'     => sanitize_text_field( (string) $request->get_param( 'off_label' ) ),
			'states'        => sanitize_text_field( (string) $request->get_param( 'states' ) ),
			'min'           => (float) $request->get_param( 'min' ),
			'max'           => (float) $request->get_param( 'max' ),
			'range'         => sanitize_text_field( (string) $request->get_param( 'range' ) ),
			'points'        => (int) $request->get_param( 'points' ),
			// Override wyglądu per widget (v1.5 Tydzień 6) — puste stringi, gdy
			// edytor nie wysłał danego pola (tryb domyślny), więc
			// IOTDW_Style_Settings::sanitize_overrides() poprawnie potraktuje
			// je jako brak override, tak samo jak pusty atrybut shortcode'u.
			'bg_color'      => sanitize_text_field( (string) $request->get_param( 'bg_color' ) ),
			'text_color'    => sanitize_text_field( (string) $request->get_param( 'text_color' ) ),
			'accent_color'  => sanitize_text_field( (string) $request->get_param( 'accent_color' ) ),
			'border_radius' => sanitize_text_field( (string) $request->get_param( 'border_radius' ) ),
			'font_size'     => sanitize_text_field( (string) $request->get_param( 'font_size' ) ),
		);

		return rest_ensure_response( array( 'html' => $this->shortcode->render( $atts ) ) );
	}

	/**
	 * Testuje połączenie z surowym configiem, PRZED jego zapisem do bazy
	 * (v1.4 Tydzień 2 — kreator pierwszego uruchomienia, patrz
	 * `docs/archiwum/mvp-v1-4-plan.md` i `docs/archiwum/audyt-v1-4-tydzien-1.md`). Reużywalne
	 * też ze zwykłej strony ustawień (Tydzień 4), nie tylko z kreatora —
	 * jeden endpoint, dwa miejsca użycia. Konektor budowany jest wyłącznie
	 * w pamięci (`IOTDW_Connector_Manager::make()`), nigdy nie trafia do
	 * `save_connections()` — zero zapisu do bazy w trakcie testu, zgodnie z
	 * Ustaleniem #1 planu.
	 *
	 * MQTT (polling/listener) jest z natury protokołu publish/subscribe
	 * niemożliwy do przetestowania natychmiast (patrz Tydzień 1 audytu) —
	 * dostaje osobną gałąź zwracającą uczciwy status "pending", zamiast
	 * wołania fetch_data(), które i tak zwróciłoby tylko "brak danych
	 * jeszcze" nierozróżnialne od prawdziwego błędu konfiguracji.
	 *
	 * @param WP_REST_Request $request Żądanie REST — body: 'connector' (typ) + pola configu specyficzne dla typu (patrz IOTDW_Connector_Manager::build_config()) + 'entity_id'.
	 * @return WP_REST_Response Zawsze 200 — wynik testu (sukces/błąd/pending) jest w treści odpowiedzi ('status'), nie w kodzie HTTP, żeby JS mógł go obsłużyć jednym spójnym `.json()` bez rozróżniania ścieżek fetch() na podstawie response.ok.
	 */
	public function test_connection( WP_REST_Request $request ): WP_REST_Response {
		// get_params() (nie get_json_params()) — scala JSON/POST/query, więc
		// endpoint działa identycznie niezależnie od tego, czy JS wysyła
		// application/json (fetch z body: JSON.stringify(...)) czy zwykły
		// POST formularza; ułatwia to też testy (WP_REST_Request::set_param()).
		$params = $request->get_params();

		$connector_type = isset( $params['connector'] ) ? sanitize_key( (string) $params['connector'] ) : '';
		$entity_id      = isset( $params['entity_id'] ) ? sanitize_text_field( (string) $params['entity_id'] ) : '';

		if ( '' === $connector_type || ! array_key_exists( $connector_type, $this->connector_manager->get_types() ) ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					'message' => __( 'Unknown connection type.', 'iot-data-widget' ),
					'data'    => null,
				)
			);
		}

		$config = $this->connector_manager->build_config( $connector_type, $params );
		// make() zwraca null tylko wtedy, gdy $connector_type nie ma wpisu w
		// tym samym rejestrze $this->connectors, który już sprawdził
		// array_key_exists() z get_types() wyżej — $connector_type się między
		// tymi dwoma sprawdzeniami nie zmienia, więc null tutaj nie może się
		// zdarzyć (code review, zweryfikowane wprost w IOTDW_Connector_Manager::make()).
		$connector = $this->connector_manager->make( $config );

		$is_mqtt = in_array(
			$connector_type,
			array( IOTDW_Cron_Value_Cache::CONNECTOR_TYPE, IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE ),
			true
		);

		if ( $is_mqtt ) {
			return rest_ensure_response(
				array(
					'status'  => 'pending',
					'message' => __( 'MQTT connections can\'t be tested instantly — save the connection, then the first value will appear after the next poll (or once the listener receives a retained message).', 'iot-data-widget' ),
					'data'    => null,
				)
			);
		}

		// Konektory Listable (dziś: HA) — test = list_entities() na surowym,
		// jeszcze niezapisanym configu (kreator multi-entity, poprawka
		// Tydzień 2, docs/archiwum/poprawka-kreator-multi-entity.md). Zastępuje
		// dotychczasowe fetch_data() na jednej, ręcznie wpisanej encji —
		// próba wpisania encji, której użytkownik jeszcze nie zna (nie widział
		// listy), była realną niespójnością UX: Krok 3 kreatora i tak zaraz
		// potem pokazuje pełną przeglądarkę encji z tego samego połączenia.
		// Sukces = niepusta lista bez wyjątku = autoryzacja działa. Krok 3
		// przeglądarki encji i tak woła GET .../entities osobno (inny request,
		// po przeładowaniu strony po zapisie) — ta odpowiedź tylko potwierdza
		// tutaj, w Kroku 2, że autoryzacja działa i odblokowuje "Continue",
		// zwraca listę na wypadek przyszłego reużycia w JS, ale nic dziś jej
		// nie konsumuje po stronie klienta poza licznikiem w komunikacie.
		if ( $connector instanceof IOTDW_Listable_Connector_Interface ) {
			$result = $this->shortcode->safe_list_entities( $connector );

			if ( null !== $result['error'] ) {
				return rest_ensure_response(
					array(
						'status'  => 'error',
						'message' => IOTDW_Diagnostics_Logger::redact_secrets( $result['error'] ),
						'data'    => null,
					)
				);
			}

			$count = count( $result['entities'] );

			return rest_ensure_response(
				array(
					'status'  => 'ok',
					/* translators: %d: number of entities found */
					'message' => sprintf( _n( 'Found %d entity.', 'Found %d entities.', $count, 'iot-data-widget' ), $count ),
					'data'    => array( 'entities' => $result['entities'] ),
				)
			);
		}

		// safe_fetch() (nie fetch_data() wprost) — code review: łapie
		// dowolny \Throwable z konektora, dokładnie ta sama ochrona, którą
		// IOTDW_Shortcode::render()/get_widget_data() już mają. Bez tego
		// błąd wewnątrz konektora (np. przyszły konektor z bugiem) wywalałby
		// ten endpoint fatal errorem zamiast zwrócić czytelny {status:'error'}
		// jak każda inna ścieżka błędu tutaj — złamanie zasady z CLAUDE.md
		// ("żaden błąd konektora nie może wywołać fatal error").
		$result = $this->shortcode->safe_fetch( $connector, $entity_id );

		if ( null !== $result['error'] ) {
			return rest_ensure_response(
				array(
					'status'  => 'error',
					// Redakcja (code review) — ten endpoint jest tylko
					// manage_options, więc ryzyko niższe niż na publicznym
					// get_widget_data(), ale ta sama zasada obowiązuje wszędzie,
					// gdzie komunikat błędu konektora trafia poza logi diagnostyki.
					'message' => IOTDW_Diagnostics_Logger::redact_secrets( (string) $result['error'] ),
					'data'    => null,
				)
			);
		}

		// Wynik pokazywany wprost adminowi ("✓ …") — "false" z surowego bool
		// JSON jako "Off", jak na karcie/liście.
		if ( ! empty( $result['display_value'] ) ) {
			$result['display_value'] = IOTDW_Value_Normalizer::human_display( (string) $result['display_value'] );
		}

		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'message' => null,
				'data'    => $result,
			)
		);
	}

	/**
	 * Publiczny endpoint odświeżania danych widgetu (v1.8 Tydzień 1) —
	 * fundament asynchronicznego doładowania front-endu (Ustalenie #1 planu
	 * v1.8): strona nadal renderuje się w pełni po stronie serwera, JS
	 * dociąga świeższą wartość PO załadowaniu, bez blokowania TTFB. To
	 * jedyny endpoint w całej wtyczce dostępny bez logowania — walidacja
	 * poniżej jest jego JEDYNĄ linią obrony (Ustalenie #2), więc jest
	 * celowo restrykcyjna: zarówno "para nie istnieje", jak i "para istnieje,
	 * ale post nie jest opublikowany" kończą się identycznym, ogólnym 404 —
	 * rozróżnienie mogłoby posłużyć do enumeracji connection_id (patrz
	 * Tydzień 1, kryterium akceptacji #2).
	 *
	 * Cache Layer jest reużyty, nie duplikowany — żądanie trafiające w okres
	 * ważności cache jest serwowane z cache bez żadnego realnego zapytania do
	 * zewnętrznego API, więc liczba odwiedzających odświeżających widget nie
	 * wpływa na częstotliwość realnych zapytań (Ustalenie #2, punkt 2) —
	 * gating przez cache, nie przez licznik żądań. TTL celowo
	 * IOTDW_Cache::DEFAULT_TTL, nie atrybut "ttl" konkretnego shortcode'u —
	 * ta tabela nie przechowuje niestandardowego TTL per para, więc to
	 * świadome uproszczenie (najczęstszy przypadek to i tak brak override'u),
	 * nie przeoczenie. Diagnostyka (v1.6) dostaje wpis za darmo dzięki
	 * przekazaniu $connection_id do remember() — bez dodatkowego kodu tutaj.
	 *
	 * @param WP_REST_Request $request Żądanie REST — query: connection_id, entity_id.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_widget_data( WP_REST_Request $request ) {
		$connection_id = sanitize_text_field( (string) $request->get_param( 'connection_id' ) );
		$entity_id     = sanitize_text_field( (string) $request->get_param( 'entity_id' ) );

		if ( ! $this->widget_usage_tracker->is_publicly_used( $connection_id, $entity_id ) ) {
			return self::widget_not_found_error();
		}

		$connection_row = $this->connector_manager->find_by_id_or_name( $connection_id );
		$connector      = null !== $connection_row ? $this->connector_manager->make( $connection_row ) : null;

		// Rzadki przypadek: para wciąż w widget_usage (post opublikowany), ale
		// samo połączenie zostało usunięte od czasu ostatniego zapisu posta —
		// ten sam ogólny 404, nie 500 ani nie odsłanianie wewnętrznego stanu.
		if ( null === $connector || null === $connection_row ) {
			return self::widget_not_found_error();
		}

		// Zgłoszenie z listy do premiery (blok 2.1, weryfikacja na żywo
		// hybrydowego doładowania) — push jest wyłączony z tego cache,
		// patrz IOTDW_Connector_Manager::bypasses_render_cache(): jego
		// fetch_data() nigdy nie robi żądania sieciowego, więc dzielenie
		// klucza cache z SSR (jak robi to nadal poniższa gałąź dla trybu
		// pull) tylko zwracałoby TĘ SAMĄ nieaktualną wartość, którą ten
		// endpoint ma właśnie odświeżyć — czyniąc doładowanie bezużytecznym
		// dla push. Dla pull klucz cache pozostaje dzielony z
		// IOTDW_Shortcode::fetch_cached() (SSR) celowo — w normalnym
		// przepływie ten wpis jest już ustawiony (z egzekwowanym floorem,
		// patrz tam), więc to zwykle zwykły odczyt cache-hit. min_ttl
		// liczony tu też, na wszelki wypadek (obrona w głębi — zgłoszenie
		// 2026-08-31, "P1#8"), gdyby ten publiczny endpoint kiedykolwiek
		// pierwszy trafił w cache-miss dla danej pary.
		if ( IOTDW_Connector_Manager::bypasses_render_cache( $connection_row ) ) {
			$data = $this->shortcode->safe_fetch( $connector, $entity_id );
		} else {
			$data = $this->cache->remember(
				$connection_id . '|' . $entity_id,
				function () use ( $connector, $entity_id ) {
					return $this->shortcode->safe_fetch( $connector, $entity_id );
				},
				IOTDW_Connector_Manager::enforce_min_ttl( IOTDW_Cache::DEFAULT_TTL, $connection_row ),
				IOTDW_Cache::ERROR_TTL,
				$connection_id
			);
		}

		// Komunikat błędu konektora może zawierać surowy URL/nagłówek z
		// tokenem (patrz IOTDW_Diagnostics_Logger::redact_secrets()) — ten
		// endpoint jest publiczny, więc redakcja jest obowiązkowa tutaj
		// niezależnie od tego, co IOTDW_Cache::remember() już zrobił dla
		// samego zapisu do logu diagnostyki (osobna troska, ten sam mechanizm).
		// Ten sam tekst stanu co w pierwszym renderze serwera („Wyłączony”,
		// nie surowe „off”) — inaczej odświeżenie JS cofałoby tłumaczenie.
		// display_raw: surowy stan do reguł tekstowych (states="off:…") w JS —
		// dopasowanie jak w IOTDW_State_Rules::match(), nie do tłumaczenia.
		if ( ! empty( $data['display_value'] ) ) {
			$data['display_raw']   = (string) $data['display_value'];
			$data['display_value'] = IOTDW_Value_Normalizer::human_display( (string) $data['display_value'] );
		}

		if ( ! empty( $data['error'] ) ) {
			$data['error'] = IOTDW_Diagnostics_Logger::redact_secrets( (string) $data['error'] );
		}

		return rest_ensure_response( $data );
	}

	/**
	 * Ogólny 404 dla get_widget_data() (code review v1.8) — wydzielone z
	 * dwóch identycznych wywołań `new WP_Error(...)`, żeby oba przypadki
	 * ("para spoza widget_usage" i "połączenie usunięte po fakcie")
	 * pozostały GWARANTOWANIE identyczne (patrz docblock get_widget_data()
	 * o zamierzonym braku rozróżnienia — anty-enumeracja), nie tylko przez
	 * ręczne utrzymywanie dwóch kopii w zgodzie.
	 *
	 * @return WP_Error
	 */
	private static function widget_not_found_error(): WP_Error {
		return new WP_Error(
			'iotdw_widget_not_found',
			__( 'Not found.', 'iot-data-widget' ),
			array( 'status' => 404 )
		);
	}
}
