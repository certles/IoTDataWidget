<?php
/**
 * Widok: globalne ustawienia wyglądu widgetu (v1.5 Tydzień 5).
 *
 * @package IoT_Data_Widget
 *
 * @var array<string, string|int> $settings     Aktualne (zsanityzowane) ustawienia — patrz IOTDW_Style_Settings::get_settings().
 * @var string                    $preview_html Wyrenderowany przykładowy widget do podglądu na żywo.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'IoT Data Widget — Appearance', 'iot-data-widget' ); ?></h1>

	<?php
	// Code review (zamknięcie planu v1.8) — przeniesione na wspólny partial
	// (v1.7 Tydzień 3 toolkit), ta strona była jedyną w tej serii, która
	// wciąż ręcznie budowała ten sam znacznik co partials/notice.php.
	if ( isset( $_GET['iotdw_notice'] ) && 'style_settings_saved' === $_GET['iotdw_notice'] ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$iotdw_notice_type        = 'success';
		$iotdw_notice_message     = __( 'Appearance settings saved.', 'iot-data-widget' );
		$iotdw_notice_dismissible = true;
		require __DIR__ . '/partials/notice.php';
	endif;
	?>

	<p>
		<?php esc_html_e( 'These are the default colors, corner radius and font size used by every widget on your site. Individual widgets can still override them in the block editor.', 'iot-data-widget' ); ?>
	</p>

	<div class="iot-dw-style-settings__layout">
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="iot-dw-style-settings__form">
			<input type="hidden" name="action" value="iotdw_save_style_settings" />
			<?php wp_nonce_field( 'iotdw_save_style_settings' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="iot-dw-style-bg-color"><?php esc_html_e( 'Background color', 'iot-data-widget' ); ?></label></th>
					<td>
						<input
							type="color"
							id="iot-dw-style-bg-color"
							name="bg_color"
							data-iot-dw-css-var="--iot-dw-bg"
							value="<?php echo esc_attr( $settings['bg_color'] ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th><label for="iot-dw-style-text-color"><?php esc_html_e( 'Text color', 'iot-data-widget' ); ?></label></th>
					<td>
						<input
							type="color"
							id="iot-dw-style-text-color"
							name="text_color"
							data-iot-dw-css-var="--iot-dw-text"
							value="<?php echo esc_attr( $settings['text_color'] ); ?>"
						/>
					</td>
				</tr>
				<tr>
					<th><label for="iot-dw-style-accent-color"><?php esc_html_e( 'Accent color', 'iot-data-widget' ); ?></label></th>
					<td>
						<input
							type="color"
							id="iot-dw-style-accent-color"
							name="accent_color"
							data-iot-dw-css-var="--iot-dw-accent"
							value="<?php echo esc_attr( $settings['accent_color'] ); ?>"
						/>
						<p class="description"><?php esc_html_e( 'Used for the gauge fill and the sparkline line.', 'iot-data-widget' ); ?></p>
					</td>
				</tr>
				<tr id="iot-dw-style-contrast-warning-row" hidden>
					<th></th>
					<td>
						<div class="notice notice-warning inline iot-dw-admin-notice--inline" id="iot-dw-style-contrast-warning" role="status"></div>
					</td>
				</tr>
				<tr>
					<th><label for="iot-dw-style-border-radius"><?php esc_html_e( 'Corner radius', 'iot-data-widget' ); ?></label></th>
					<td>
						<input
							type="number"
							id="iot-dw-style-border-radius"
							name="border_radius"
							data-iot-dw-css-var="--iot-dw-radius"
							data-iot-dw-css-unit="px"
							min="0"
							max="40"
							step="1"
							value="<?php echo esc_attr( $settings['border_radius'] ); ?>"
						/>
						<?php esc_html_e( 'px', 'iot-data-widget' ); ?>
					</td>
				</tr>
				<tr>
					<th><label for="iot-dw-style-font-size"><?php esc_html_e( 'Font size', 'iot-data-widget' ); ?></label></th>
					<td>
						<input
							type="number"
							id="iot-dw-style-font-size"
							name="font_size"
							data-iot-dw-css-var="--iot-dw-font-size"
							data-iot-dw-css-unit="px"
							min="10"
							max="32"
							step="1"
							value="<?php echo esc_attr( $settings['font_size'] ); ?>"
						/>
						<?php esc_html_e( 'px', 'iot-data-widget' ); ?>
					</td>
				</tr>
			</table>
			<?php submit_button( __( 'Save appearance settings', 'iot-data-widget' ), 'primary', 'iotdw_submit_style_settings' ); ?>
		</form>

		<div class="iot-dw-style-settings__preview">
			<h2><?php esc_html_e( 'Live preview', 'iot-data-widget' ); ?></h2>
			<div id="iot-dw-style-preview">
				<?php echo wp_kses( $preview_html, IOTDW_Renderer::allowed_widget_html() ); ?>
			</div>
		</div>
	</div>
</div>
