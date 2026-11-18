<?php
/**
 * Widok: Krok "wybierz platformę" ścieżki push (v2.0,
 * docs/archiwum/mvp-v2-0-push-mode-plan.md §4).
 *
 * @package IoT_Data_Widget
 *
 * @var array<string, string> $iotdw_platforms [klucz platformy => etykieta] (patrz IOTDW_Setup_Wizard::get_push_platforms()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_base_url = admin_url( 'admin.php?page=' . IOTDW_Setup_Wizard::PAGE_SLUG );
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2><?php esc_html_e( 'Which platform is your device?', 'iot-data-widget' ); ?></h2>
	<p class="description">
		<?php esc_html_e( "You'll get a ready-to-paste configuration snippet for whichever one you pick.", 'iot-data-widget' ); ?>
	</p>

	<p class="iot-dw-wizard__options">
		<?php foreach ( $iotdw_platforms as $iotdw_platform_key => $iotdw_label ) : ?>
			<?php
			$iotdw_platform_url = add_query_arg(
				array(
					'mode'      => 'push',
					'push_type' => $iotdw_platform_key,
				),
				$iotdw_base_url
			);
			?>
			<a class="button button-hero" href="<?php echo esc_url( $iotdw_platform_url ); ?>">
				<?php echo esc_html( $iotdw_label ); ?>
			</a>
		<?php endforeach; ?>
	</p>

	<p><a href="<?php echo esc_url( $iotdw_base_url ); ?>">&larr; <?php esc_html_e( 'Back', 'iot-data-widget' ); ?></a></p>
</div>
