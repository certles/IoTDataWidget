<?php
/**
 * Widok: Krok 4 kreatora — podgląd na żywo + gotowy shortcode (v1.4
 * Tydzień 6), teraz jedna karta na KAŻDĄ zaznaczoną w Kroku 3 encję (kreator
 * multi-entity, v1.9 Tydzień 5) — dla konektorów nie-Listable to nadal
 * dokładnie jedna karta, jak dotychczas. Każdy 'preview_html' pochodzi z
 * IOTDW_Shortcode::render() — dokładnie ten sam kod co front strony, więc
 * jest już w pełni zescape'owany (patrz
 * Renderer_Test::test_render_card_escapes_html_in_label_and_value), tak samo
 * jak w IOTDW_Embed::render_embed_html().
 *
 * @package IoT_Data_Widget
 *
 * @var array<int, array{entity_id: string, preview_html: string, shortcode_text: string}> $previews   Jedna pozycja na zaznaczoną encję.
 * @var string                                                                              $iotdw_back_url   Link "Back" do Kroku 3.
 * @var string                                                                              $iotdw_finish_url Link "Finish" do Kroku 5 (ekran końcowy, v1.4 Tydzień 7).
 * @var array<string, string>                                                               $iotdw_connection Zapisane połączenie.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_skip_url = admin_url( 'admin.php?page=iot-data-widget' );
$iotdw_is_multi = count( $previews ) > 1;
$iotdw_is_demo  = IOTDW_Setup_Wizard::DEMO_CONNECTION_NAME === ( $iotdw_connection['name'] ?? '' );
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2>
		<?php
		echo esc_html( $iotdw_is_multi ? __( 'Here they are!', 'iot-data-widget' ) : __( 'Here it is!', 'iot-data-widget' ) );
		?>
	</h2>

	<?php
	// Poprawka 2.1 (docs/archiwum/poprawki-ogolne.md), kryterium akceptacji "jasno
	// oznaczone jako demo, nie mylące z realnym połączeniem" — widoczne od
	// razu, zanim użytkownik zdąży pomylić tę kartę z prawdziwym urządzeniem.
	// Jawnie nazwany Open-Meteo (audyt bezpieczeństwa, P1 #8,
	// docs/archiwum/iot-data-widget-do-poprawy.md) — jedyne miejsce w całej wtyczce,
	// gdzie żądanie do zewnętrznego serwisu wychodzi bez ręcznie wpisanego
	// przez użytkownika URL-a/adresu (patrz też readme.txt, sekcja
	// "External services and privacy").
	if ( $iotdw_is_demo ) :
		$iotdw_notice_type        = 'info';
		$iotdw_notice_message     = __( 'This is example weather data from Open-Meteo (open-meteo.com), a public weather API — configure your own device to see real data.', 'iot-data-widget' );
		$iotdw_notice_dismissible = false;
		require IOTDW_PLUGIN_DIR . 'admin/views/partials/notice.php';
	endif;
	?>

	<?php foreach ( $previews as $iotdw_item ) : ?>
		<div class="iot-dw-wizard__entity-preview">
			<div class="iot-dw-wizard__preview-box">
				<?php echo wp_kses( $iotdw_item['preview_html'], IOTDW_Renderer::allowed_widget_html() ); ?>
			</div>
			<p>
				<code class="iot-dw-wizard__shortcode iot-dw-wizard-shortcode">
					<?php echo esc_html( $iotdw_item['shortcode_text'] ); ?>
				</code>
				<button type="button" class="button iot-dw-copy-shortcode">
					<?php esc_html_e( 'Copy', 'iot-data-widget' ); ?>
				</button>
				<span class="iot-dw-copy-shortcode-result" role="status" aria-live="polite"></span>
			</p>
		</div>
	<?php endforeach; ?>

	<?php if ( $iotdw_is_multi ) : ?>
		<p>
			<button type="button" class="button" id="iot-dw-copy-all-shortcodes">
				<?php esc_html_e( 'Copy all', 'iot-data-widget' ); ?>
			</button>
			<span id="iot-dw-copy-all-result" role="status" aria-live="polite"></span>
		</p>
	<?php endif; ?>

	<p class="description">
		<?php esc_html_e( 'Paste this shortcode into any post or page (classic editor, Shortcode block, or most page builders) to show this widget there.', 'iot-data-widget' ); ?>
	</p>

	<p>
		<a href="<?php echo esc_url( $iotdw_finish_url ); ?>" class="button button-primary">
			<?php esc_html_e( 'Finish', 'iot-data-widget' ); ?> &rarr;
		</a>
	</p>
	<p>
		<a href="<?php echo esc_url( $iotdw_back_url ); ?>">&larr; <?php esc_html_e( 'Back', 'iot-data-widget' ); ?></a>
		&nbsp;|&nbsp;
		<a href="<?php echo esc_url( $iotdw_skip_url ); ?>"><?php esc_html_e( 'Go to Connections', 'iot-data-widget' ); ?></a>
	</p>
</div>
