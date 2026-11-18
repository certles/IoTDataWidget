<?php
/**
 * Panel administracyjny — ustawienia globalne wyglądu widgetu (v1.5 Tydzień
 * 5). Osobna podstrona od IOTDW_Settings_Page (branding/eksport) — inny
 * rodzaj sprawy, ten sam wzorzec CRUD-na-jednej-opcji co reszta panelu.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Style_Settings_Page
 */
class IOTDW_Style_Settings_Page {

	/**
	 * Renderer HTML — buduje przykładowy widget do podglądu na żywo, żeby
	 * podgląd był dokładnie tym samym znacznikiem co front-end, nie osobną
	 * kopią markupu utrzymywaną w widoku.
	 *
	 * @var IOTDW_Renderer
	 */
	private $renderer;

	/**
	 * Hook suffix strony (z add_submenu_page()) — do warunkowego enqueue JS/CSS
	 * tylko na tej jednej stronie admina.
	 *
	 * @var string
	 */
	private $page_hook_suffix = '';

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Renderer $renderer Renderer HTML.
	 */
	public function __construct( IOTDW_Renderer $renderer ) {
		$this->renderer = $renderer;

		// Priorytet 30 (v1.7 Tydzień 2, docs/archiwum/audyt-ui-v1-7.md) — kolejność podmenu.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 30 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'admin_post_iotdw_save_style_settings', array( $this, 'handle_save' ) );
	}

	/**
	 * Rejestruje podstronę "Appearance" pod głównym menu wtyczki.
	 */
	public function register_menu(): void {
		$this->page_hook_suffix = add_submenu_page(
			'iot-data-widget',
			__( 'IoT Data Widget — Appearance', 'iot-data-widget' ),
			__( 'Appearance', 'iot-data-widget' ),
			'manage_options',
			'iot-data-widget-appearance',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Ładuje CSS widgetu (żeby podgląd wyglądał dokładnie jak na froncie) i
	 * JS podglądu na żywo — tylko na tej stronie admina.
	 *
	 * @param string $hook_suffix Hook suffix bieżącej strony admina.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook_suffix ) {
			return;
		}

		// v1.8 Tydzień 6 — patrz komentarz w IOTDW_Shortcode::maybe_enqueue_assets();
		// podgląd na żywo tutaj musi renderować się identycznie jak front-end.
		wp_enqueue_style(
			'iot-data-widget-tokens',
			IOTDW_PLUGIN_URL . 'public/css/design-tokens.css',
			array(),
			IOTDW_VERSION
		);

		wp_enqueue_style(
			'iot-data-widget',
			IOTDW_PLUGIN_URL . 'public/css/widget-styles.css',
			array( 'iot-data-widget-tokens' ),
			IOTDW_VERSION
		);

		wp_enqueue_style(
			'iot-data-widget-theme',
			IOTDW_PLUGIN_URL . 'public/css/widget-theme.css',
			array( 'iot-data-widget' ),
			IOTDW_VERSION
		);

		wp_enqueue_script(
			'iot-data-widget-style-settings-preview',
			IOTDW_PLUGIN_URL . 'admin/js/style-settings-preview.js',
			array(),
			IOTDW_VERSION,
			true
		);

		// v1.7 Tydzień 8 (a11y) — komunikaty ostrzeżenia o kontraście, patrz
		// docs/archiwum/audyt-a11y-v1-7.md. "%s" podmieniane w JS na policzony
		// współczynnik kontrastu, nie escapowane tu jako placeholder.
		wp_localize_script(
			'iot-data-widget-style-settings-preview',
			'iotdwStyleSettings',
			array(
				/* translators: %s: computed contrast ratio, e.g. "3.20" — substituted client-side in style-settings-preview.js, not by PHP. */
				'textContrastWarning'   => __( 'Low contrast: text vs. background is %s:1 — WCAG AA recommends at least 4.5:1 for readable text.', 'iot-data-widget' ),
				/* translators: %s: computed contrast ratio, e.g. "2.10" — substituted client-side in style-settings-preview.js, not by PHP. */
				'accentContrastWarning' => __( 'Low contrast: accent vs. background is %s:1 — WCAG AA recommends at least 3:1 for the gauge/sparkline to stay visible.', 'iot-data-widget' ),
			)
		);
	}

	/**
	 * Renderuje stronę ustawień wyglądu.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$settings     = IOTDW_Style_Settings::get_settings();
		$preview_html = $this->renderer->render( $this->sample_data(), 'card' );

		require IOTDW_PLUGIN_DIR . 'admin/views/style-settings-page.php';
	}

	/**
	 * Dane przykładowego odczytu do podglądu na żywo — te same kształty co
	 * kontrakt konektora, zmyślona, ale wiarygodna wartość (nie 0/puste, żeby
	 * podgląd faktycznie coś pokazywał).
	 *
	 * @return array{value: float, unit: string, label: string, last_updated: string, error: null}
	 */
	private function sample_data(): array {
		return array(
			'value'        => 21.5,
			'unit'         => '°C',
			'label'        => __( 'Living room temperature', 'iot-data-widget' ),
			'last_updated' => gmdate( 'c' ),
			'error'        => null,
		);
	}

	/**
	 * Zapisuje globalne ustawienia wyglądu.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( 'iotdw_save_style_settings' );

		$posted = array();

		foreach ( array_keys( IOTDW_Style_Settings::DEFAULTS ) as $key ) {
			$posted[ $key ] = isset( $_POST[ $key ] ) ? sanitize_text_field( wp_unslash( $_POST[ $key ] ) ) : '';
		}

		update_option( IOTDW_Style_Settings::OPTION_NAME, IOTDW_Style_Settings::sanitize( $posted ) );

		wp_safe_redirect( add_query_arg( 'iotdw_notice', 'style_settings_saved', admin_url( 'admin.php?page=iot-data-widget-appearance' ) ) );
		exit;
	}
}
