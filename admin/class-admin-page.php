<?php
/**
 * Panel administracyjny wtyczki — zarządzanie połączeniami.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Admin_Page
 */
class IOTDW_Admin_Page {

	/**
	 * Nazwa transienta z błędami walidacji ostatniej próby zapisu (per
	 * użytkownik) — pozwala wrócić do formularza z wpisanymi danymi zamiast
	 * je gubić przy niepowodzeniu walidacji.
	 */
	const FORM_ERROR_TRANSIENT_PREFIX = 'iotdw_form_error_';

	/**
	 * User meta klucz — czy bieżący użytkownik trwale zamknął checklistę
	 * "kolejne kroki" (v1.4 Tydzień 7). User meta, nie option: różni adminowie
	 * na tej samej instalacji mogą chcieć widzieć/nie widzieć jej niezależnie.
	 */
	const CHECKLIST_DISMISSED_META_KEY = 'iotdw_checklist_dismissed';

	/**
	 * Hook suffix strony ustawień (z add_menu_page()) — do warunkowego
	 * enqueue JS tylko na tej jednej stronie admina.
	 *
	 * @var string
	 */
	private $page_hook_suffix = '';

	/**
	 * Menedżer konektorów — źródło dostępnych typów i magazyn połączeń.
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

		add_action( 'admin_menu', array( $this, 'register_menu' ), 10 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_iotdw_save_connection', array( $this, 'handle_save' ) );
		add_action( 'admin_post_iotdw_delete_connection', array( $this, 'handle_delete' ) );
		add_action( 'admin_post_iotdw_dismiss_checklist', array( $this, 'handle_dismiss_checklist' ) );
	}

	/**
	 * Rejestruje stronę w menu administracyjnym.
	 *
	 * V1.7 Tydzień 2 (audyt, `docs/archiwum/audyt-ui-v1-7.md`): `add_menu_page()` samo
	 * z siebie tworzy pierwszy punkt podmenu z tą samą etykietą co pozycja
	 * najwyższego poziomu — w efekcie sidebar pokazywał podmenu zaczynające
	 * się od "IoT Data Widget" (nic nie mówiące), mimo że ta strona faktycznie
	 * zarządza połączeniami. Jawne wywołanie `add_submenu_page()` z TYM SAMYM
	 * slugiem co rodzic ($menu_slug) nadpisuje tylko etykietę tego pierwszego
	 * punktu podmenu na "Connections" — sam adres URL/slug się nie zmienia,
	 * więc żaden istniejący link do `admin.php?page=iot-data-widget` nie
	 * przestaje działać. Priorytet 10 na hooku 'admin_menu' — pierwszy w
	 * kolejności spośród podstron wtyczki, patrz kolejne klasy dla priorytetów
	 * 20-80 ustalających resztę kolejności podmenu.
	 */
	public function register_menu(): void {
		$menu_slug = 'iot-data-widget';

		$this->page_hook_suffix = add_menu_page(
			__( 'IoT Data Widget', 'iot-data-widget' ),
			__( 'IoT Data Widget', 'iot-data-widget' ),
			'manage_options',
			$menu_slug,
			array( $this, 'render_page' ),
			'dashicons-chart-line'
		);

		add_submenu_page(
			$menu_slug,
			__( 'IoT Data Widget — Connections', 'iot-data-widget' ),
			__( 'Connections', 'iot-data-widget' ),
			'manage_options',
			$menu_slug,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Ładuje JS przełączający pola formularza (HA/REST/MQTT), ale tylko na
	 * stronie ustawień tej wtyczki.
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

		IOTDW_Rest_Api::enqueue_test_connection_script();
		// admin-toolkit.css (partiale empty-state.php itd.) — ładowane
		// centralnie, patrz IOTDW_Plugin::maybe_enqueue_admin_toolkit_css()
		// (code review v1.8, wcześniej osobno w każdej klasie ekranu admina).
	}

	/**
	 * Renderuje stronę ustawień (lista połączeń + formularz dodania/edycji).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$connections = $this->connector_manager->get_connections();
		$editing     = null;
		$errors      = array();

		$error_transient_key = self::FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id();
		$form_error          = get_transient( $error_transient_key );

		if ( is_array( $form_error ) ) {
			delete_transient( $error_transient_key );
			$editing = $form_error['data'];
			$errors  = $form_error['errors'];
		} elseif ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit_id = sanitize_text_field( wp_unslash( $_GET['edit'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$editing = $this->find_connection( $connections, $edit_id );
		}

		// Poprawki po recenzji wp.org (Guideline 5, 2026-09-24) — dropdown
		// pokazuje dokładnie get_types(): zawężenie do wersji darmowej dzieje
		// się teraz WYŁĄCZNIE przez fizyczną (nie)obecność klas konektorów
		// (patrz IOTDW_Connector_Manager::__construct()), nie osobny
		// runtime-check tutaj.
		$connector_types = $this->connector_manager->get_types();
		$show_checklist  = ! get_user_meta( get_current_user_id(), self::CHECKLIST_DISMISSED_META_KEY, true );
		$next_steps      = self::next_steps_links();

		require IOTDW_PLUGIN_DIR . 'admin/views/settings-page.php';
	}

	/**
	 * Sugerowane kolejne kroki po skonfigurowaniu pierwszego połączenia (v1.4
	 * Tydzień 7) — WSPÓŁDZIELONE przez dismissible checklistę na tej stronie
	 * i ekran końcowy kreatora (IOTDW_Setup_Wizard::render_step_5()), żeby
	 * nie utrzymywać dwóch osobnych list tych samych linków. Każdy link
	 * prowadzi do prawdziwego, działającego ekranu — nie do kolejnego kroku
	 * kreatora (kryterium akceptacji z planu). Ani embed, ani wykres/sparkline
	 * nie mają własnej dedykowanej strony ustawień (embed to meta box per
	 * post, styl wybiera się w edytorze bloku) — kierują więc do miejsca, z
	 * którego faktycznie da się z nich skorzystać.
	 *
	 * "Add an alert rule"/"Try public embed mode" pominięte na wersji
	 * darmowej (zgłoszenie z audytu paczki darmowej, Faza 6 planu freemium,
	 * 2026-09-15, testowane na żywo na czystej instalacji): obie strony admina
	 * (IOTDW_Alert_Rules_Page/IOTDW_Email_Reports_Page) są instancjonowane
	 * WYŁĄCZNIE gdy iotdw_is_premium() (patrz IOTDW_Plugin::init_modules()),
	 * więc ich add_submenu_page() nigdy się nie odpala na free — link do
	 * iot-data-widget-alerts kończył się surowym "Sorry, you are not allowed
	 * to access this page." rdzenia WP (nierozpoznana strona admin.php), nie
	 * przyjaznym komunikatem wtyczki. Embed nie hard-erroruje (edit.php samo
	 * w sobie działa), ale obiecany panel "Public embed" w sidebarze po
	 * prostu nie istnieje na free (IOTDW_Embed też instancjonowane tylko na
	 * Pro) — ta sama nietrafiona obietnica, inny objaw.
	 *
	 * Poprawka 1.0.1 (Priorytet 1d, zgłoszenie "brak drogi do zakupu Pro",
	 * 2026-10-06): "Chart/sparkline" ZOSTAJE na obu tierach jako pozycja na
	 * liście, ale na free prowadzi teraz do strony cennika (idw_fs()->pricing_url()),
	 * z etykietą oznaczoną "(Pro)" — wcześniej prowadziła do post-new.php, co
	 * samo w sobie nie było błędem (podgląd w edytorze bloku faktycznie
	 * pokazuje uczciwy komunikat "wymaga Pro", patrz IOTDW_Renderer::render_history_style()),
	 * ale w ramach audytu "brak drogi do zakupu Pro" uznano, że TA KONKRETNA
	 * pozycja checklisty powinna kierować prosto do checkoutu, nie przez
	 * dodatkowy krok. Na Pro etykieta/url bez zmian (bez "(Pro)", prowadzi do
	 * edytora — tam ten styl faktycznie działa).
	 *
	 * @return array<int, array{label: string, description: string, url: string}>
	 */
	public static function next_steps_links(): array {
		$is_premium = function_exists( 'idw_fs' ) && idw_fs()->is_premium();

		$links = array(
			array(
				'label'          => __( 'Add an alert rule', 'iot-data-widget' ),
				'description'    => __( 'Get notified by email or webhook when a value crosses a threshold.', 'iot-data-widget' ),
				'url'            => admin_url( 'admin.php?page=iot-data-widget-alerts' ),
				'requires_class' => 'IOTDW_Alert_Evaluator',
			),
			array(
				'label'          => __( 'Try public embed mode', 'iot-data-widget' ),
				'description'    => __( 'Show a widget full-screen, without the site theme — great for a kiosk tablet or iframe. Open any post with a widget and look for "Public embed" in the sidebar.', 'iot-data-widget' ),
				'url'            => admin_url( 'edit.php' ),
				'requires_class' => 'IOTDW_Embed',
			),
			array(
				'label'          => $is_premium
					? __( 'See the chart/sparkline style', 'iot-data-widget' )
					: __( 'See the chart/sparkline style (Pro)', 'iot-data-widget' ),
				'description'    => __( 'Plot value history over time instead of a single number — insert the IoT Widget block and choose "Chart" or "Sparkline".', 'iot-data-widget' ),
				'url'            => ( $is_premium || ! function_exists( 'idw_fs' ) ) ? admin_url( 'post-new.php' ) : idw_fs()->pricing_url(),
				'requires_class' => '',
			),
		);

		// class_exists() (Guideline 5, 2026-09-24; dawniej iotdw_is_premium())
		// — link pokazuje się dokładnie wtedy, gdy strona/funkcja, do której
		// prowadzi, fizycznie istnieje w tej paczce, niezależnie od licencji.
		$links = array_filter(
			$links,
			static fn( array $link ): bool => '' === $link['requires_class'] || class_exists( $link['requires_class'] )
		);

		// 'requires_class' jest wyłącznie wewnętrznym znacznikiem filtrowania
		// wyżej — nie wchodzi do publicznego kształtu tej metody (patrz
		// @return), który konsumują widoki (next-steps-list.php).
		return array_values(
			array_map(
				static fn( array $link ): array => array(
					'label'       => $link['label'],
					'description' => $link['description'],
					'url'         => $link['url'],
				),
				$links
			)
		);
	}

	/**
	 * Obsługuje trwałe zamknięcie checklisty "kolejne kroki" — zwykły link +
	 * nonce (nie AJAX), ten sam wzorzec co inne jednorazowe akcje w tej
	 * wtyczce (np. IOTDW_Embed::handle_regenerate_action()).
	 */
	public function handle_dismiss_checklist(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_dismiss_checklist' );

		update_user_meta( get_current_user_id(), self::CHECKLIST_DISMISSED_META_KEY, 1 );

		wp_safe_redirect( admin_url( 'admin.php?page=iot-data-widget' ) );
		exit;
	}

	/**
	 * Obsługuje zapis (dodanie lub edycję) połączenia.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_save_connection' );

		// 1.0.10 (wymóg wp.org): zamiast całego $_POST — tylko znane pola
		// formularza połączenia, każde sanityzowane według swojego typu.
		// Brakujące pole zostaje nieustawione, tak jak wcześniej, bo
		// build_config() rozróżnia isset() (np. mqtt_port).
		$fields = array();

		foreach ( array( 'base_url', 'server_uri' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$fields[ $key ] = esc_url_raw( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( array( 'connector', 'rest_mode', 'preset', 'mqtt_transport', 'iotdw_wizard_type' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$fields[ $key ] = sanitize_key( wp_unslash( $_POST[ $key ] ) );
			}
		}

		foreach ( array( 'id', 'name', 'token', 'device_ip', 'auth_key', 'device_id', 'mqtt_host', 'mqtt_username', 'mqtt_password' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$fields[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}

		if ( isset( $_POST['mqtt_port'] ) && is_scalar( $_POST['mqtt_port'] ) ) {
			$fields['mqtt_port'] = absint( wp_unslash( $_POST['mqtt_port'] ) );
		}

		if ( ! empty( $_POST['mqtt_tls'] ) ) {
			$fields['mqtt_tls'] = '1';
		}

		$connections = $this->connector_manager->get_connections();
		$id          = isset( $fields['id'] ) ? sanitize_text_field( (string) $fields['id'] ) : '';
		$existing    = $id ? $this->find_connection( $connections, $id ) : null;
		$type        = isset( $fields['connector'] ) ? sanitize_key( (string) $fields['connector'] ) : '';

		// build_config() samo sprawdza $fields['rest_mode'] przed uznaniem
		// 'preset' za obowiązujące (code review v1.8) — nie trzeba już go
		// tutaj ręcznie odfiltrowywać przed wywołaniem.
		//
		// build_config() (IOTDW_Connector_Manager, v1.4 Tydzień 2) —
		// mapowanie pól współdzielone z IOTDW_Rest_Api::test_connection(),
		// żeby nie utrzymywać dwóch kopii tej samej logiki (patrz jej docblock).
		$connection         = $this->connector_manager->build_config( $type, $fields );
		$connection['id']   = $id ? $id : wp_generate_uuid4();
		$connection['name'] = isset( $fields['name'] ) ? sanitize_text_field( (string) $fields['name'] ) : '';

		// Puste pole sekretu przy edycji = zachowaj obecny sekret —
		// build_config() celowo tego nie robi (dotyczy tylko zapisu edycji,
		// nie efemerycznego testu przed zapisem), więc dopełniamy tutaj.
		if ( '' === $connection['token'] && null !== $existing ) {
			$connection['token'] = $existing['token'] ?? '';
		}

		if ( array_key_exists( 'mqtt_password', $connection ) && '' === $connection['mqtt_password'] && null !== $existing ) {
			$connection['mqtt_password'] = $existing['mqtt_password'] ?? '';
		}

		if ( array_key_exists( 'auth_key', $connection ) && '' === $connection['auth_key'] && null !== $existing ) {
			$connection['auth_key'] = $existing['auth_key'] ?? '';
		}

		// Zgłoszenie 2026-09-07 (docs/archiwum/iot-data-widget-poswiadczenia.md) —
		// base_url NIE jest sekretem dla większości presetów (adres
		// hosta/IP), ale JEST nim dla presetów 'direct_url' (Supla "link
		// bezpośredni", klucz dostępu wbudowany wprost w URL) — ten sam
		// wyjątek co reszta pól-sekretów wyżej, ale wyłącznie dla tego
		// jednego przypadku, żeby zwykła zmiana adresu IP urządzenia nadal
		// wymagała faktycznego wpisania nowego adresu.
		$preset_definition = ( '' !== ( $connection['preset'] ?? '' ) && class_exists( 'IOTDW_Connector_Presets' ) ) ? IOTDW_Connector_Presets::get( $connection['preset'] ) : null;
		$is_direct_url     = null !== $preset_definition && ! empty( $preset_definition['direct_url'] );

		if ( $is_direct_url && '' === $connection['base_url'] && null !== $existing ) {
			$connection['base_url'] = $existing['base_url'] ?? '';
		}

		$errors = $this->validate_connection( $connection );

		// Poprawki po recenzji wp.org (Guideline 5, 2026-09-24): usunięty
		// limit połączeń wersji darmowej i limit typu konektora do tieru —
		// oba były runtime license-checkami nad kodem, który i tak fizycznie
		// istniał w paczce darmowej ("trialware", wprost zakazany). Zamiast
		// tego: zwykła walidacja "czy to w ogóle znany, zarejestrowany typ"
		// (ten sam wzorzec co IOTDW_Rest_Api::test_connection()) — identyczna
		// dla obu tierów, odrzuca tylko typy, których klasa fizycznie nie
		// istnieje w tej paczce (Shelly Cloud/MQTT na free), nie sprawdza
		// żadnej licencji.
		if ( ! array_key_exists( $type, $this->connector_manager->get_types() ) ) {
			$errors[] = __( 'Unknown connection type.', 'iot-data-widget' );
		}

		// Obecność tego pola (ukryte w admin/views/wizard/step-2-configure.php)
		// oznacza, że formularz przyszedł z Kroku 2 kreatora (v1.4 Tydzień 5),
		// nie ze zwykłej strony połączeń — ten sam handler zapisu, tylko
		// przekierowanie po sukcesie/błędzie wraca do kreatora zamiast do
		// listy połączeń. Patrz IOTDW_Setup_Wizard — "jeden endpoint zapisu,
		// dwa miejsca użycia", ten sam wzorzec co test-connection (Tydzień 2/4).
		$wizard_type = isset( $fields['iotdw_wizard_type'] ) ? sanitize_key( (string) $fields['iotdw_wizard_type'] ) : '';

		if ( ! empty( $errors ) ) {
			set_transient(
				self::FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'errors' => $errors,
					'data'   => $connection,
				),
				MINUTE_IN_SECONDS
			);

			if ( '' !== $wizard_type ) {
				$redirect_url = add_query_arg(
					array(
						'page' => IOTDW_Setup_Wizard::PAGE_SLUG,
						'step' => 2,
						'type' => $wizard_type,
					),
					admin_url( 'admin.php' )
				);

				if ( $id ) {
					$redirect_url = add_query_arg( 'connection', $id, $redirect_url );
				}

				wp_safe_redirect( $redirect_url );
				exit;
			}

			$redirect_url = admin_url( 'admin.php?page=iot-data-widget' );

			if ( $id ) {
				$redirect_url = add_query_arg( 'edit', $id, $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		if ( $existing ) {
			foreach ( $connections as $index => $item ) {
				if ( $item['id'] === $id ) {
					$connections[ $index ] = $connection;
					break;
				}
			}
		} else {
			$connections[] = $connection;
		}

		$this->connector_manager->save_connections( $connections );

		if ( '' !== $wizard_type ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => IOTDW_Setup_Wizard::PAGE_SLUG,
						'step'       => 3,
						'type'       => $wizard_type,
						'connection' => $connection['id'],
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'saved', admin_url( 'admin.php?page=iot-data-widget' ) ) );
		exit;
	}

	/**
	 * Waliduje pola specyficzne dla MQTT przed zapisem — adres/port brokera
	 * dla trybu "socket" (zawsze dotyczy też "listener" — nie ma innego
	 * trybu), URL dla trybu "emqx_rest" (tylko "polling"). HA/REST nie są tu
	 * dodatkowo walidowane (bez zmian względem dotychczasowego zachowania).
	 *
	 * @param array<string, string> $connection Kandydat na zapisane połączenie.
	 * @return string[] Lista komunikatów błędów (pusta = brak błędów).
	 */
	private function validate_connection( array $connection ): array {
		if ( 'rest' === $connection['connector'] && '' !== ( $connection['preset'] ?? '' ) ) {
			return $this->validate_rest_preset( $connection );
		}

		if ( 'shelly_cloud' === $connection['connector'] ) {
			return $this->validate_shelly_cloud( $connection );
		}

		$is_mqtt_connector = in_array(
			$connection['connector'],
			array( IOTDW_Cron_Value_Cache::CONNECTOR_TYPE, IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE ),
			true
		);

		if ( ! $is_mqtt_connector ) {
			return array();
		}

		$errors = array();

		if ( 'emqx_rest' === $connection['mqtt_transport'] ) {
			if ( '' === $connection['base_url'] || ! wp_http_validate_url( $connection['base_url'] ) ) {
				$errors[] = __( 'A valid EMQX REST API base URL is required.', 'iot-data-widget' );
			}

			return $errors;
		}

		if ( '' === trim( $connection['mqtt_host'] ) ) {
			$errors[] = __( 'Broker host is required.', 'iot-data-widget' );
		}

		$port = (int) $connection['mqtt_port'];

		if ( $port < 1 || $port > 65535 ) {
			$errors[] = __( 'Broker port must be a number between 1 and 65535.', 'iot-data-widget' );
		}

		return $errors;
	}

	/**
	 * Waliduje pola trybu presetu dla REST Connector — klucz presetu musi
	 * odpowiadać znanemu presetowi, adres IP nie może być pusty (URL jest
	 * wyliczany z tych dwóch pól, patrz handle_save()).
	 *
	 * @param array<string, string> $connection Kandydat na zapisane połączenie.
	 * @return string[] Lista komunikatów błędów (pusta = brak błędów).
	 */
	private function validate_rest_preset( array $connection ): array {
		$errors = array();

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro; na
		// wersji darmowej ten plik fizycznie nie istnieje, więc KAŻDY preset
		// (w tym prawidłowy na Pro) jest tu "nieznany", zamiast fatal errora
		// na ręcznym POST z pominięciem UI (która na free w ogóle nie oferuje
		// trybu presetu — patrz admin/views/partials/connector-fields.php).
		if ( ! class_exists( 'IOTDW_Connector_Presets' ) || null === IOTDW_Connector_Presets::get( $connection['preset'] ) ) {
			$errors[] = __( 'Unknown device type.', 'iot-data-widget' );
		}

		if ( '' === trim( $connection['device_ip'] ) ) {
			$errors[] = __( 'Device IP address is required.', 'iot-data-widget' );
		}

		return $errors;
	}

	/**
	 * Waliduje pola Shelly Cloud — wszystkie trzy (adres serwera, ID
	 * urządzenia, klucz autoryzacji) są wymagane, inaczej fetch_data() nie ma
	 * jak zbudować poprawnego żądania (patrz IOTDW_Shelly_Cloud_Connector).
	 *
	 * @param array<string, string> $connection Kandydat na zapisane połączenie.
	 * @return string[] Lista komunikatów błędów (pusta = brak błędów).
	 */
	private function validate_shelly_cloud( array $connection ): array {
		$errors = array();

		if ( '' === trim( $connection['server_uri'] ?? '' ) || ! wp_http_validate_url( $connection['server_uri'] ) ) {
			$errors[] = __( 'A valid Shelly Cloud server address is required.', 'iot-data-widget' );
		}

		if ( '' === trim( $connection['device_id'] ?? '' ) ) {
			$errors[] = __( 'Device ID is required.', 'iot-data-widget' );
		}

		if ( '' === trim( $connection['auth_key'] ?? '' ) ) {
			$errors[] = __( 'Authorization cloud key is required.', 'iot-data-widget' );
		}

		return $errors;
	}

	/**
	 * Obsługuje usunięcie połączenia.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_delete_connection' );

		$id = isset( $_POST['id'] ) ? sanitize_text_field( wp_unslash( $_POST['id'] ) ) : '';

		$connections = array_values(
			array_filter(
				$this->connector_manager->get_connections(),
				function ( $connection ) use ( $id ) {
					return $connection['id'] !== $id;
				}
			)
		);

		$this->connector_manager->save_connections( $connections );

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'deleted', admin_url( 'admin.php?page=iot-data-widget' ) ) );
		exit;
	}

	/**
	 * Znajduje połączenie po ID (dopasowanie ścisłe — na potrzeby edycji/CRUD).
	 *
	 * @param array  $connections Lista połączeń.
	 * @param string $id          Szukane ID.
	 * @return array|null
	 */
	private function find_connection( array $connections, string $id ): ?array {
		foreach ( $connections as $connection ) {
			if ( $connection['id'] === $id ) {
				return $connection;
			}
		}

		return null;
	}
}
