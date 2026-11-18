<?php
/**
 * Panel administracyjny wtyczki — zarządzanie cyklicznymi raportami e-mail
 * (v1.3 Tydzień 7). Osobna podstrona, ten sam wzorzec CRUD co
 * IOTDW_Alert_Rules_Page (Tydzień 8 v1.2).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Email_Reports_Page
 */
class IOTDW_Email_Reports_Page {

	/**
	 * Nazwa transienta z błędami walidacji ostatniej próby zapisu (per
	 * użytkownik) — ten sam wzorzec co IOTDW_Alert_Rules_Page::FORM_ERROR_TRANSIENT_PREFIX.
	 */
	const FORM_ERROR_TRANSIENT_PREFIX = 'iotdw_email_report_form_error_';

	/**
	 * Dozwolone częstotliwości.
	 *
	 * @var string[]
	 */
	const VALID_FREQUENCIES = array( 'daily', 'weekly' );

	/**
	 * Hook suffix strony (z add_submenu_page()) — do warunkowego enqueue JS
	 * tylko na tej jednej stronie admina.
	 *
	 * @var string
	 */
	private $page_hook_suffix = '';

	/**
	 * Menedżer konektorów — lista połączeń do wyboru w formularzu.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Magazyn konfiguracji raportów.
	 *
	 * @var IOTDW_Email_Report_Manager
	 */
	private $report_manager;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager Menedżer konektorów.
	 * @param IOTDW_Email_Report_Manager $report_manager    Magazyn konfiguracji raportów.
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Email_Report_Manager $report_manager ) {
		$this->connector_manager = $connector_manager;
		$this->report_manager    = $report_manager;

		// Priorytet 50 (v1.7 Tydzień 2, docs/archiwum/audyt-ui-v1-7.md) — kolejność podmenu.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 50 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_iotdw_save_email_report', array( $this, 'handle_save' ) );
		add_action( 'admin_post_iotdw_delete_email_report', array( $this, 'handle_delete' ) );
	}

	/**
	 * Rejestruje podstronę "Email reports" pod głównym menu wtyczki.
	 */
	public function register_menu(): void {
		$this->page_hook_suffix = add_submenu_page(
			'iot-data-widget',
			__( 'IoT Data Widget — Email reports', 'iot-data-widget' ),
			__( 'Email reports', 'iot-data-widget' ),
			'manage_options',
			'iot-data-widget-email-reports',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Ładuje JS obsługujący wybór encji (lista/wolny wpis, ten sam mechanizm
	 * co formularz reguł alertów) — tylko na tej stronie admina.
	 *
	 * @param string $hook_suffix Hook suffix bieżącej strony admina.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'iot-data-widget-email-report-form',
			IOTDW_PLUGIN_URL . 'admin/js/email-report-form.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_localize_script(
			'iot-data-widget-email-report-form',
			'iotdwEmailReportForm',
			array(
				'restUrl' => esc_url_raw( rest_url( IOTDW_Rest_Api::NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// admin-toolkit.css — ładowane centralnie, patrz
		// IOTDW_Plugin::maybe_enqueue_admin_toolkit_css() (code review v1.8).
	}

	/**
	 * Renderuje stronę raportów (lista + formularz dodania/edycji).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$reports           = $this->report_manager->get_reports();
		$connections       = $this->connector_manager->get_connections();
		$connections_by_id = array_column( $connections, null, 'id' );
		$editing           = null;
		$errors            = array();

		$error_transient_key = self::FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id();
		$form_error          = get_transient( $error_transient_key );

		if ( is_array( $form_error ) ) {
			delete_transient( $error_transient_key );
			$editing = $form_error['data'];
			$errors  = $form_error['errors'];
		} elseif ( isset( $_GET['edit'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$edit_id = absint( $_GET['edit'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$editing = $this->report_manager->find_report( $edit_id );
		}

		require IOTDW_PLUGIN_DIR . 'includes/premium/admin-views/email-reports-page.php';
	}

	/**
	 * Obsługuje zapis (dodanie lub edycję) konfiguracji raportu.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_save_email_report' );

		$id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$entity_mode = isset( $_POST['entity_mode'] ) && 'manual' === $_POST['entity_mode'] ? 'manual' : 'list';

		$report = array(
			'id'            => $id,
			'connection_id' => isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '',
			'entity_id'     => 'manual' === $entity_mode
				? ( isset( $_POST['entity_id_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_id_manual'] ) ) : '' )
				: ( isset( $_POST['entity_id_list'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_id_list'] ) ) : '' ),
			'frequency'     => isset( $_POST['frequency'] ) && 'weekly' === $_POST['frequency'] ? 'weekly' : 'daily',
			'destination'   => isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '',
		);

		$errors = $this->validate_report( $report );

		if ( ! empty( $errors ) ) {
			set_transient(
				self::FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'errors' => $errors,
					'data'   => $report,
				),
				MINUTE_IN_SECONDS
			);

			$redirect_url = admin_url( 'admin.php?page=iot-data-widget-email-reports' );

			if ( $id ) {
				$redirect_url = add_query_arg( 'edit', $id, $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		$this->report_manager->save_report( $report );

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'saved', admin_url( 'admin.php?page=iot-data-widget-email-reports' ) ) );
		exit;
	}

	/**
	 * Waliduje pola konfiguracji raportu przed zapisem.
	 *
	 * @param array<string, mixed> $report Kandydat na zapisany raport.
	 * @return string[] Lista komunikatów błędów (pusta = brak błędów).
	 */
	private function validate_report( array $report ): array {
		$errors = array();

		if ( null === $this->connector_manager->find_by_id_or_name( $report['connection_id'] ) ) {
			$errors[] = __( 'Please choose a valid connection.', 'iot-data-widget' );
		}

		if ( '' === trim( $report['entity_id'] ) ) {
			$errors[] = __( 'Entity is required.', 'iot-data-widget' );
		}

		if ( ! in_array( $report['frequency'], self::VALID_FREQUENCIES, true ) ) {
			$errors[] = __( 'Please choose a valid frequency.', 'iot-data-widget' );
		}

		if ( ! is_email( $report['destination'] ) ) {
			$errors[] = __( 'A valid email address is required.', 'iot-data-widget' );
		}

		return $errors;
	}

	/**
	 * Obsługuje usunięcie konfiguracji raportu.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_delete_email_report' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$this->report_manager->delete_report( $id );

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'deleted', admin_url( 'admin.php?page=iot-data-widget-email-reports' ) ) );
		exit;
	}
}
