<?php
/**
 * Panel administracyjny wtyczki — zarządzanie regułami alertów progowych
 * (v1.2 Tydzień 8). Osobna podstrona od listy połączeń (IOTDW_Admin_Page) i
 * ustawień ogólnych (IOTDW_Settings_Page), ten sam wzorzec CRUD.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Alert_Rules_Page
 */
class IOTDW_Alert_Rules_Page {

	/**
	 * Nazwa transienta z błędami walidacji ostatniej próby zapisu (per
	 * użytkownik) — ten sam wzorzec co IOTDW_Admin_Page::FORM_ERROR_TRANSIENT_PREFIX.
	 */
	const FORM_ERROR_TRANSIENT_PREFIX = 'iotdw_alert_form_error_';

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
	 * Magazyn reguł alertów — odczyt (get_rules()/find_rule()).
	 *
	 * @var IOTDW_Alert_Manager
	 */
	private $alert_manager;

	/**
	 * Ocena i mutacja reguł alertów — zapis/usuwanie (save_rule()/delete_rule()).
	 * Wydzielone z IOTDW_Alert_Manager do IOTDW_Alert_Evaluator przy audycie
	 * paczki darmowej (Faza 6 planu freemium, 2026-09-14) — ta strona jest
	 * instancjonowana wyłącznie gdy iotdw_is_premium() (patrz
	 * IOTDW_Plugin::init_modules()), więc $alert_evaluator tu zawsze
	 * istnieje mimo typu bez |null.
	 *
	 * @var IOTDW_Alert_Evaluator
	 */
	private $alert_evaluator;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager $connector_manager Menedżer konektorów.
	 * @param IOTDW_Alert_Manager     $alert_manager     Magazyn reguł (odczyt).
	 * @param IOTDW_Alert_Evaluator   $alert_evaluator   Ocena/mutacja reguł (zapis/usuwanie).
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Alert_Manager $alert_manager, IOTDW_Alert_Evaluator $alert_evaluator ) {
		$this->connector_manager = $connector_manager;
		$this->alert_manager     = $alert_manager;
		$this->alert_evaluator   = $alert_evaluator;

		// Priorytet 40 (v1.7 Tydzień 2, docs/archiwum/audyt-ui-v1-7.md) — kolejność podmenu.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 40 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_iotdw_save_alert_rule', array( $this, 'handle_save' ) );
		add_action( 'admin_post_iotdw_delete_alert_rule', array( $this, 'handle_delete' ) );
	}

	/**
	 * Rejestruje podstronę "Alerts" pod głównym menu wtyczki.
	 */
	public function register_menu(): void {
		$this->page_hook_suffix = add_submenu_page(
			'iot-data-widget',
			__( 'IoT Data Widget — Alerts', 'iot-data-widget' ),
			__( 'Alerts', 'iot-data-widget' ),
			'manage_options',
			'iot-data-widget-alerts',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Ładuje JS obsługujący wybór encji (lista/wolny wpis, ten sam mechanizm
	 * co edytor bloku z Tygodnia 3) — tylko na stronie ustawień alertów.
	 *
	 * @param string $hook_suffix Hook suffix bieżącej strony admina.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'iot-data-widget-alert-rule-form',
			IOTDW_PLUGIN_URL . 'admin/js/alert-rule-form.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_localize_script(
			'iot-data-widget-alert-rule-form',
			'iotdwAlertRuleForm',
			array(
				'restUrl' => esc_url_raw( rest_url( IOTDW_Rest_Api::NAMESPACE ) ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
			)
		);

		// admin-toolkit.css — ładowane centralnie, patrz
		// IOTDW_Plugin::maybe_enqueue_admin_toolkit_css() (code review v1.8).
	}

	/**
	 * Renderuje stronę alertów (lista reguł + formularz dodania/edycji).
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$rules             = $this->alert_manager->get_rules();
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
			$editing = $this->alert_manager->find_rule( $edit_id );
		}

		require IOTDW_PLUGIN_DIR . 'includes/premium/admin-views/alert-rules-page.php';
	}

	/**
	 * Obsługuje zapis (dodanie lub edycję) reguły alertu.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_save_alert_rule' );

		$id          = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$entity_mode = isset( $_POST['entity_mode'] ) && 'manual' === $_POST['entity_mode'] ? 'manual' : 'list';

		$rule = array(
			'id'              => $id,
			'connection_id'   => isset( $_POST['connection_id'] ) ? sanitize_text_field( wp_unslash( $_POST['connection_id'] ) ) : '',
			'entity_id'       => 'manual' === $entity_mode
				? ( isset( $_POST['entity_id_manual'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_id_manual'] ) ) : '' )
				: ( isset( $_POST['entity_id_list'] ) ? sanitize_text_field( wp_unslash( $_POST['entity_id_list'] ) ) : '' ),
			'condition_type'  => isset( $_POST['condition_type'] ) && 'below' === $_POST['condition_type'] ? 'below' : 'above',
			'threshold_value' => isset( $_POST['threshold_value'] ) ? (float) $_POST['threshold_value'] : 0.0,
			'channel'         => isset( $_POST['channel'] ) && 'webhook' === $_POST['channel'] ? 'webhook' : 'email',
			'destination'     => isset( $_POST['destination'] ) ? sanitize_text_field( wp_unslash( $_POST['destination'] ) ) : '',
		);

		$errors = $this->validate_rule( $rule );

		if ( ! empty( $errors ) ) {
			set_transient(
				self::FORM_ERROR_TRANSIENT_PREFIX . get_current_user_id(),
				array(
					'errors' => $errors,
					'data'   => $rule,
				),
				MINUTE_IN_SECONDS
			);

			$redirect_url = admin_url( 'admin.php?page=iot-data-widget-alerts' );

			if ( $id ) {
				$redirect_url = add_query_arg( 'edit', $id, $redirect_url );
			}

			wp_safe_redirect( $redirect_url );
			exit;
		}

		$this->alert_evaluator->save_rule( $rule );

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'saved', admin_url( 'admin.php?page=iot-data-widget-alerts' ) ) );
		exit;
	}

	/**
	 * Waliduje pola reguły alertu przed zapisem.
	 *
	 * @param array<string, mixed> $rule Kandydat na zapisaną regułę.
	 * @return string[] Lista komunikatów błędów (pusta = brak błędów).
	 */
	private function validate_rule( array $rule ): array {
		$errors = array();

		if ( null === $this->connector_manager->find_by_id_or_name( $rule['connection_id'] ) ) {
			$errors[] = __( 'Please choose a valid connection.', 'iot-data-widget' );
		}

		if ( '' === trim( $rule['entity_id'] ) ) {
			$errors[] = __( 'Entity is required.', 'iot-data-widget' );
		}

		if ( 'email' === $rule['channel'] && ! is_email( $rule['destination'] ) ) {
			$errors[] = __( 'A valid email address is required for the email channel.', 'iot-data-widget' );
		}

		if ( 'webhook' === $rule['channel'] && ( '' === $rule['destination'] || ! wp_http_validate_url( $rule['destination'] ) ) ) {
			$errors[] = __( 'A valid webhook URL is required for the webhook channel.', 'iot-data-widget' );
		}

		return $errors;
	}

	/**
	 * Obsługuje usunięcie reguły alertu.
	 */
	public function handle_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_delete_alert_rule' );

		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;

		$this->alert_evaluator->delete_rule( $id );

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'deleted', admin_url( 'admin.php?page=iot-data-widget-alerts' ) ) );
		exit;
	}
}
