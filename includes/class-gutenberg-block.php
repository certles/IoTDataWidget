<?php
/**
 * Rejestracja bloku Gutenberg.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Gutenberg_Block
 */
class IOTDW_Gutenberg_Block {

	/**
	 * Konstruktor.
	 *
	 * Rejestruje blok od razu (bez add_action( 'init', ... )) — cały bootstrap
	 * wtyczki i tak odpala się na hooku init (patrz IOTDW_Plugin), więc
	 * dopisanie kolejnego callbacka do init z wnętrza już trwającego init
	 * nie gwarantuje wykonania w tym samym przebiegu.
	 */
	public function __construct() {
		$this->register_block();
	}

	/**
	 * Rejestruje blok na podstawie zbudowanych plików (npm run build).
	 * Jeśli build nie istnieje, po cichu pomija rejestrację — reszta wtyczki
	 * (shortcode, panel admina) ma działać niezależnie od bloku.
	 */
	private function register_block(): void {
		$build_dir = IOTDW_PLUGIN_DIR . 'blocks/iot-widget/build';

		if ( ! file_exists( $build_dir . '/block.json' ) ) {
			return;
		}

		register_block_type( $build_dir );

		add_action( 'enqueue_block_editor_assets', array( $this, 'localize_editor_script' ) );
	}

	/**
	 * Udostępnia edytorowi bloku URL strony połączeń (przeglądarka encji,
	 * v1.3 Tydzień 3) oraz URL-e CSS widgetu + bieżące globalne zmienne
	 * wyglądu (v1.5 Tydzień 6) — edit.js sam wstrzykuje je do DOM canvasu
	 * (patrz komentarz przy wstrzyknięciu w edit.js), bo zwykłe
	 * wp_enqueue_style() na 'enqueue_block_editor_assets' ląduje w
	 * dokumencie NADRZĘDNYM edytora, nie w jego iframe'owanym canvasie —
	 * potwierdzone na żywo (Playwright): style bloku (editorStyle z
	 * block.json) trafiają do iframe'u automatycznie, ale dowolny inny
	 * enqueue już nie, więc front-endowego CSS trzeba dostarczyć inaczej.
	 * Handle 'iot-data-widget-widget-editor-script' to auto-wygenerowana przez
	 * register_block_type() nazwa dla editorScript "widget" z block.json —
	 * potwierdzone na żywo na wp-env, nie zgadywane.
	 */
	public function localize_editor_script(): void {
		wp_localize_script(
			'iot-data-widget-widget-editor-script',
			'iotdwEditorData',
			array(
				'connectionsUrl'    => admin_url( 'admin.php?page=iot-data-widget' ),
				'widgetStylesUrl'   => IOTDW_PLUGIN_URL . 'public/css/widget-styles.css',
				'widgetThemeUrl'    => IOTDW_PLUGIN_URL . 'public/css/widget-theme.css',
				'globalCssRule'     => IOTDW_Style_Settings::build_css_rule( IOTDW_Style_Settings::css_variables( IOTDW_Style_Settings::get_settings() ) ),
				// Style bez kodu w tej paczce — edytor dopisuje do nich "(Pro)".
				// Fizyczna obecność klas Pro (Guideline 5), nie licencja.
				'unavailableStyles' => array_values(
					array_filter(
						array(
							class_exists( 'IOTDW_Renderer_Chart' ) ? '' : 'chart',
							class_exists( 'IOTDW_Renderer_Chart' ) ? '' : 'sparkline',
							class_exists( 'IOTDW_Grid_Items' ) ? '' : 'grid',
						)
					)
				),
			)
		);
	}
}
