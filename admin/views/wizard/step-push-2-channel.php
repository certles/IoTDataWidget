<?php
/**
 * Widok: Krok "utwórz kanał" ścieżki push (v2.0,
 * docs/archiwum/mvp-v2-0-push-mode-plan.md §1, §3) — nazwa kanału + lista nazw encji,
 * które urządzenie będzie wysyłać (whitelist, patrz IOTDW_Push_Channel_Manager).
 *
 * @package IoT_Data_Widget
 *
 * @var string                  $push_type Wybrana platforma.
 * @var string[]                $errors    Błędy walidacji z poprzedniej próby (jeśli dotyczy).
 * @var array{name: string, entities: string} $editing Dane z poprzedniej, nieudanej próby (do odtworzenia formularza).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_base_url     = admin_url( 'admin.php?page=' . IOTDW_Setup_Wizard::PAGE_SLUG );
$iotdw_platform_url = add_query_arg(
	array(
		'mode' => 'push',
	),
	$iotdw_base_url
);
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2><?php esc_html_e( 'Create a push channel', 'iot-data-widget' ); ?></h2>
	<p class="description">
		<?php esc_html_e( "List the names you'll use for each value your device sends (e.g. \"temperature\", \"power\", \"relay\") — only these exact names will be accepted later, everything else is rejected.", 'iot-data-widget' ); ?>
	</p>

	<?php if ( ! empty( $errors ) ) : ?>
		<?php
		$iotdw_notice_list_heading = __( 'Please fix the following before saving:', 'iot-data-widget' );
		$iotdw_notice_list_items   = $errors;
		require __DIR__ . '/../partials/notice-list.php';
		?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="<?php echo esc_attr( IOTDW_Setup_Wizard::PUSH_CREATE_CHANNEL_ACTION ); ?>" />
		<input type="hidden" name="push_type" value="<?php echo esc_attr( $push_type ); ?>" />
		<?php wp_nonce_field( IOTDW_Setup_Wizard::PUSH_CREATE_CHANNEL_ACTION ); ?>

		<table class="form-table">
			<tr>
				<th><label for="iotdw_push_channel_name"><?php esc_html_e( 'Channel name', 'iot-data-widget' ); ?></label></th>
				<td>
					<input
						type="text"
						id="iotdw_push_channel_name"
						name="name"
						class="regular-text"
						required
						value="<?php echo esc_attr( $editing['name'] ); ?>"
					/>
				</td>
			</tr>
			<tr>
				<th><label for="iotdw_push_channel_entities"><?php esc_html_e( 'Entity names', 'iot-data-widget' ); ?></label></th>
				<td>
					<textarea
						id="iotdw_push_channel_entities"
						name="entities"
						class="large-text code"
						rows="5"
						required
					><?php echo esc_textarea( $editing['entities'] ); ?></textarea>
					<p class="description">
						<?php esc_html_e( 'One per line (or comma-separated). Lowercase letters, numbers, and _ . : - only.', 'iot-data-widget' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Create channel', 'iot-data-widget' ) ); ?>
	</form>

	<p><a href="<?php echo esc_url( $iotdw_platform_url ); ?>">&larr; <?php esc_html_e( 'Back', 'iot-data-widget' ); ?></a></p>
</div>
