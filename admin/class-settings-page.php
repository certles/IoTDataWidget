<?php
/**
 * Panel administracyjny wtyczki — ustawienia ogólne (branding) oraz
 * eksport/import konfiguracji połączeń. Osobna strona/menu od listy
 * połączeń (IOTDW_Admin_Page) — inny rodzaj sprawy, żeby nie przeładowywać
 * jednej strony.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Settings_Page
 */
class IOTDW_Settings_Page {

	/**
	 * Nazwa opcji z ustawieniami ogólnymi wtyczki.
	 */
	const OPTION_NAME = 'iotdw_settings';

	/**
	 * Maksymalny rozmiar akceptowanego pliku importu, w bajtach — plik
	 * konfiguracji to kilka/kilkanaście KB, więc kilka MB to już celowo
	 * szeroki margines błędu, nie realny limit dla poprawnego eksportu.
	 */
	const MAX_IMPORT_FILE_SIZE = 5 * MB_IN_BYTES;

	/**
	 * Nazwa transienta z błędami walidacji ostatniej próby dodania ręcznego
	 * wpisu śledzenia (per użytkownik) — ten sam wzorzec co
	 * IOTDW_Admin_Page::FORM_ERROR_TRANSIENT_PREFIX.
	 */
	const MANUAL_ENTRY_ERROR_TRANSIENT_PREFIX = 'iotdw_manual_entry_error_';

	/**
	 * Dozwolone style widgetu — te same wartości co w bloku Gutenberg
	 * (blocks/iot-widget/src/edit.js, STYLE_OPTIONS) i w atrybucie "style"
	 * shortcode'u. Decyduje o tym, czy ręcznie dodana encja trafia do
	 * historii (IOTDW_History_Recorder::snapshot_all() bierze tylko
	 * chart/sparkline).
	 *
	 * @var string[]
	 */
	const VALID_STYLES = array( 'card', 'list', 'gauge', 'chart', 'sparkline' );

	/**
	 * Menedżer konektorów.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Śledzenie użycia widgetów — magazyn ręcznie dodanych wpisów (v1.2 Tydzień 9).
	 *
	 * @var IOTDW_Widget_Usage_Tracker
	 */
	private $widget_usage_tracker;

	/**
	 * Hook suffix strony (z add_submenu_page()) — do warunkowego enqueue JS
	 * tylko na tej jednej stronie admina.
	 *
	 * @var string
	 */
	private $page_hook_suffix = '';

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager    Menedżer konektorów.
	 * @param IOTDW_Widget_Usage_Tracker $widget_usage_tracker Śledzenie użycia widgetów.
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Widget_Usage_Tracker $widget_usage_tracker ) {
		$this->connector_manager    = $connector_manager;
		$this->widget_usage_tracker = $widget_usage_tracker;

		// Priorytet 80 (v1.7 Tydzień 2, docs/archiwum/audyt-ui-v1-7.md) — ostatni w
		// podmenu: branding/eksport/import/CSV to ekrany "zaawansowane",
		// odwiedzane rzadko po pierwszej konfiguracji, w przeciwieństwie do
		// Connections/Alerts/Appearance.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 80 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_iotdw_save_settings', array( $this, 'handle_save_settings' ) );
		add_action( 'admin_post_iotdw_export_connections', array( $this, 'handle_export' ) );
		add_action( 'admin_post_iotdw_import_connections', array( $this, 'handle_import' ) );
		add_action( 'admin_post_iotdw_add_manual_entry', array( $this, 'handle_add_manual_entry' ) );
		add_action( 'admin_post_iotdw_delete_manual_entry', array( $this, 'handle_delete_manual_entry' ) );
	}

	/**
	 * Rejestruje podstronę "Settings" pod głównym menu wtyczki.
	 */
	public function register_menu(): void {
		$this->page_hook_suffix = add_submenu_page(
			'iot-data-widget',
			__( 'IoT Data Widget — Settings', 'iot-data-widget' ),
			__( 'Settings', 'iot-data-widget' ),
			'manage_options',
			'iot-data-widget-settings',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Ładuje JS obsługujący wybór encji (lista/wolny wpis) dla sekcji
	 * "Manually tracked entities" — tylko na tej stronie admina.
	 *
	 * @param string $hook_suffix Hook suffix bieżącej strony admina.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'iot-data-widget-manual-tracking-form',
			IOTDW_PLUGIN_URL . 'admin/js/manual-tracking-form.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_localize_script(
			'iot-data-widget-manual-tracking-form',
			'iotdwManualTrackingForm',
			array(
				'restUrl' => esc_url_raw( rest_url( IOTDW_Rest_Api::NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		wp_enqueue_script(
			'iot-data-widget-export-history-form',
			IOTDW_PLUGIN_URL . 'admin/js/export-history-form.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_localize_script(
			'iot-data-widget-export-history-form',
			'iotdwExportHistoryForm',
			array(
				'restUrl' => esc_url_raw( rest_url( IOTDW_Rest_Api::NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// admin-toolkit.css — ładowane centralnie, patrz
		// IOTDW_Plugin::maybe_enqueue_admin_toolkit_css() (code review v1.8).
	}

	/**
	 * Renderuje stronę ustawień.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings = get_option( self::OPTION_NAME, array() );
		// Guideline 10 (audyt wp.org, 2026-09-24) — domyślnie WYŁĄCZONY na
		// obu tierach; dawniej `true` na free wymuszało branding bez opcji
		// wyłączenia, co recenzent słusznie zakwalifikował jako naruszenie.
		$show_branding            = (bool) ( $settings['show_branding'] ?? false );
		$remove_data_on_uninstall = (bool) ( $settings['remove_data_on_uninstall'] ?? false );
		$stale_threshold_minutes  = (int) ( $settings[ IOTDW_Renderer::STALE_THRESHOLD_SETTING_KEY ] ?? IOTDW_Renderer::DEFAULT_STALE_MINUTES );
		$allow_local_network      = ! empty( $settings[ IOTDW_Http_Client::SETTING_KEY ] );
		$can_manage_local_network = IOTDW_Http_Client::can_manage_local_network();
		$import_result            = get_transient( $this->import_result_transient_key() );

		if ( false !== $import_result ) {
			delete_transient( $this->import_result_transient_key() );
		}

		$manual_entries       = $this->widget_usage_tracker->get_manual_entries();
		$connections          = $this->connector_manager->get_connections();
		$connections_by_id    = array_column( $connections, null, 'id' );
		$manual_entry_errors  = array();
		$manual_entry_editing = null;

		$manual_error_transient_key = self::MANUAL_ENTRY_ERROR_TRANSIENT_PREFIX . get_current_user_id();
		$manual_form_error          = get_transient( $manual_error_transient_key );

		if ( is_array( $manual_form_error ) ) {
			delete_transient( $manual_error_transient_key );
			$manual_entry_errors  = $manual_form_error['errors'];
			$manual_entry_editing = $manual_form_error['data'];
		}

		// Domyślny zakres formularza eksportu CSV (v1.3 Tydzień 6) — ostatnie
		// 30 dni, ten sam domyślny zakres co IOTDW_History_Export::resolve_range()
		// stosuje, gdy pola from/to zostaną wysłane puste.
		$export_default_to   = gmdate( 'Y-m-d' );
		$export_default_from = gmdate( 'Y-m-d', time() - 30 * DAY_IN_SECONDS );

		require IOTDW_PLUGIN_DIR . 'admin/views/general-settings-page.php';
	}

	/**
	 * Zapisuje ustawienia ogólne: branding + zgodę na czyszczenie danych przy
	 * odinstalowaniu (poprawka 1.5, docs/archiwum/poprawki-ogolne.md) — domyślnie
	 * wyłączoną (opt-in), sprawdzaną przez IOTDW_Plugin::uninstall() przed
	 * jakimkolwiek DROP TABLE/delete_option(). Sama deaktywacja nigdy nie
	 * czyta tej opcji — patrz IOTDW_Plugin::deactivate(), które tylko
	 * odpina crony.
	 */
	public function handle_save_settings(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_save_settings' );

		$settings                             = get_option( self::OPTION_NAME, array() );
		$settings['show_branding']            = ! empty( $_POST['show_branding'] );
		$settings['remove_data_on_uninstall'] = ! empty( $_POST['remove_data_on_uninstall'] );

		// Na multisite tylko manage_network_options (patrz IOTDW_Http_Client::can_manage_local_network()).
		if ( IOTDW_Http_Client::can_manage_local_network() ) {
			$settings[ IOTDW_Http_Client::SETTING_KEY ] = ! empty( $_POST['allow_local_network'] );
		}

		if ( isset( $_POST['stale_threshold_minutes'] ) ) {
			$settings[ IOTDW_Renderer::STALE_THRESHOLD_SETTING_KEY ] = max( 1, absint( $_POST['stale_threshold_minutes'] ) );
		}

		update_option( self::OPTION_NAME, $settings );

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'settings_saved', admin_url( 'admin.php?page=iot-data-widget-settings' ) ) );
		exit;
	}

	/**
	 * Strumieniuje eksport połączeń jako plik JSON do pobrania. Tokeny i
	 * hasła są zredagowane przez IOTDW_Config_Import_Export — nigdy nie
	 * trafiają do pliku w czystym tekście.
	 */
	public function handle_export(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_export_connections' );

		$export = IOTDW_Config_Import_Export::build_export( $this->connector_manager->get_connections() );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="iot-data-widget-export-' . gmdate( 'Y-m-d' ) . '.json"' );

		echo wp_json_encode( $export, JSON_PRETTY_PRINT );
		exit;
	}

	/**
	 * Obsługuje przesłany plik importu — waliduje kształt i poszczególne
	 * wpisy, scala poprawne z istniejącymi połączeniami, i wraca z
	 * czytelnym podsumowaniem (ile zaimportowano, ile pominięto i dlaczego).
	 */
	public function handle_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_import_connections' );

		// 1.0.10 (wymóg wp.org): z $_FILES bierzemy tylko trzy potrzebne pola,
		// każde sanityzowane według typu, tutaj — zaraz po sprawdzeniu nonce.
		// Że tmp_name to naprawdę plik z tego uploadu, potwierdza dalej
		// is_uploaded_file() w process_import_upload().
		$file = array();

		if ( isset( $_FILES['import_file'] ) && is_array( $_FILES['import_file'] ) ) {
			$file = array(
				'error'    => isset( $_FILES['import_file']['error'] ) ? absint( $_FILES['import_file']['error'] ) : UPLOAD_ERR_NO_FILE,
				'size'     => isset( $_FILES['import_file']['size'] ) ? absint( $_FILES['import_file']['size'] ) : 0,
				'tmp_name' => isset( $_FILES['import_file']['tmp_name'] ) && is_string( $_FILES['import_file']['tmp_name'] ) ? sanitize_text_field( $_FILES['import_file']['tmp_name'] ) : '',
			);
		}

		$result = $this->process_import_upload( $file );

		set_transient( $this->import_result_transient_key(), $result, MINUTE_IN_SECONDS );

		wp_safe_redirect( admin_url( 'admin.php?page=iot-data-widget-settings' ) );
		exit;
	}

	/**
	 * Waliduje i przetwarza przesłany plik, zwraca podsumowanie do wyświetlenia.
	 * Prawdziwa walidacja ścieżki to is_uploaded_file() poniżej (potwierdza, że
	 * tmp_name to plik utworzony przez PHP przy tym uploadzie, nie dowolna
	 * ścieżka na dysku).
	 *
	 * @param array{error?: int, size?: int, tmp_name?: string} $file Już sanityzowane pola z $_FILES['import_file'] (patrz handle_import()); pusta tablica = brak pliku.
	 * @return array{success: bool, errors: string[], imported: int, skipped: array<int, array{entry: mixed, reason: string}>}
	 */
	private function process_import_upload( array $file ): array {
		if ( UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return $this->failed_import( array( __( 'No file was uploaded, or the upload failed.', 'iot-data-widget' ) ) );
		}

		if ( ( (int) ( $file['size'] ?? 0 ) ) > self::MAX_IMPORT_FILE_SIZE ) {
			return $this->failed_import( array( __( 'The uploaded file is too large to be a valid export.', 'iot-data-widget' ) ) );
		}

		$tmp_name = (string) ( $file['tmp_name'] ?? '' );

		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			return $this->failed_import( array( __( 'Invalid upload.', 'iot-data-widget' ) ) );
		}

		$content = file_get_contents( $tmp_name ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		if ( false === $content ) {
			return $this->failed_import( array( __( 'Could not read the uploaded file.', 'iot-data-widget' ) ) );
		}

		$decoded = json_decode( $content, true );

		$structure_errors = IOTDW_Config_Import_Export::validate_structure( $decoded );

		if ( ! empty( $structure_errors ) ) {
			return $this->failed_import( $structure_errors );
		}

		$known_types = array_keys( $this->connector_manager->get_types() );
		$partitioned = IOTDW_Config_Import_Export::partition_connections( $decoded['connections'], $known_types );

		$merged = IOTDW_Config_Import_Export::merge_connections(
			$this->connector_manager->get_connections(),
			$partitioned['valid']
		);

		$this->connector_manager->save_connections( $merged );

		return array(
			'success'  => true,
			'errors'   => array(),
			'imported' => count( $partitioned['valid'] ),
			'skipped'  => $partitioned['skipped'],
		);
	}

	/**
	 * Buduje wynik nieudanego importu (spójny kształt z sukcesem, do
	 * jednolitego wyświetlenia w widoku).
	 *
	 * @param string[] $errors Komunikaty błędów.
	 * @return array{success: bool, errors: string[], imported: int, skipped: array}
	 */
	private function failed_import( array $errors ): array {
		return array(
			'success'  => false,
			'errors'   => $errors,
			'imported' => 0,
			'skipped'  => array(),
		);
	}

	/**
	 * Klucz transienta z wynikiem ostatniego importu (per użytkownik) —
	 * ten sam wzorzec co IOTDW_Admin_Page::FORM_ERROR_TRANSIENT_PREFIX.
	 *
	 * @return string
	 */
	private function import_result_transient_key(): string {
		return 'iotdw_import_result_' . get_current_user_id();
	}

	/**
	 * Dodaje ręcznie śledzony wpis (v1.2 Tydzień 9) — encję używaną poza
	 * treścią postów (np. w szablonie motywu), której save_post nigdy nie
	 * zobaczy. Ten sam mechanizm wyboru encji z listy/wolnego wpisu co w
	 * formularzu reguł alertów (IOTDW_Alert_Rules_Page, Tydzień 8).
	 */
	public function handle_add_manual_entry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_add_manual_entry' );

		$entity_mode = isset( $_POST['entity_mode'] ) && 'manual' === $_POST['entity_mode'] ? 'manual' : 'list';

		$entry = array(
			'connection_id' => isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '',
			'entity_id'     => 'manual' === $entity_mode
				? ( isset( $_POST['entity_id_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_id_manual'] ) ) : '' )
				: ( isset( $_POST['entity_id_list'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_id_list'] ) ) : '' ),
			'style'         => isset( $_POST['style'] ) ? sanitize_key( wp_unslash( $_POST['style'] ) ) : '',
		);

		$errors = $this->validate_manual_entry( $entry );

		if ( ! empty( $errors ) ) {
			set_transient(
				self::MANUAL_ENTRY_ERROR_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'errors' => $errors,
					'data'   => $entry,
				),
				MINUTE_IN_SECONDS
			);

			wp_safe_redirect( admin_url( 'admin.php?page=iot-data-widget-settings#iot-dw-manual-tracking' ) );
			exit;
		}

		$this->widget_usage_tracker->add_manual_entry( $entry['connection_id'], $entry['entity_id'], $entry['style'] );

		wp_safe_redirect(
			add_query_arg( 'iotdw_notice', 'manual_entry_saved', admin_url( 'admin.php?page=iot-data-widget-settings' ) ) . '#iot-dw-manual-tracking'
		);
		exit;
	}

	/**
	 * Waliduje pola ręcznie dodawanego wpisu śledzenia.
	 *
	 * @param array<string, string> $entry Kandydat na zapisany wpis.
	 * @return string[] Lista komunikatów błędów (pusta = brak błędów).
	 */
	private function validate_manual_entry( array $entry ): array {
		$errors = array();

		if ( null === $this->connector_manager->find_by_id_or_name( $entry['connection_id'] ) ) {
			$errors[] = __( 'Please choose a valid connection.', 'iot-data-widget' );
		}

		if ( '' === trim( $entry['entity_id'] ) ) {
			$errors[] = __( 'Entity is required.', 'iot-data-widget' );
		}

		if ( ! in_array( $entry['style'], self::VALID_STYLES, true ) ) {
			$errors[] = __( 'Please choose a valid widget style.', 'iot-data-widget' );
		}

		return $errors;
	}

	/**
	 * Usuwa ręcznie dodany wpis śledzenia.
	 */
	public function handle_delete_manual_entry(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_delete_manual_entry' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$this->widget_usage_tracker->delete_manual_entry( $id );

		wp_safe_redirect(
			add_query_arg( 'iotdw_notice', 'manual_entry_deleted', admin_url( 'admin.php?page=iot-data-widget-settings' ) ) . '#iot-dw-manual-tracking'
		);
		exit;
	}
}
