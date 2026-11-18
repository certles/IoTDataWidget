<?php
/**
 * Widok: formularz dodawania/edycji połączenia.
 *
 * @package IoT_Data_Widget
 *
 * @var array<string, string>|null $editing         Edytowane połączenie (jeśli dotyczy).
 * @var array<string, string>      $connector_types Dostępne typy konektorów [typ => etykieta].
 * @var string[]                   $errors          Błędy walidacji z poprzedniej próby zapisu.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<?php if ( ! empty( $errors ) ) : ?>
	<?php
	$iotdw_notice_list_heading = __( 'Please fix the following before saving:', 'iot-data-widget' );
	$iotdw_notice_list_items   = $errors;
	require IOTDW_PLUGIN_DIR . 'admin/views/partials/notice-list.php';
	?>
<?php endif; ?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
	<input type="hidden" name="action" value="iotdw_save_connection" />
	<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ?? '' ); ?>" />
	<?php wp_nonce_field( 'iotdw_save_connection' ); ?>

	<?php require IOTDW_PLUGIN_DIR . 'admin/views/partials/connector-fields.php'; ?>

	<?php
	submit_button(
		$editing ? __( 'Save changes', 'iot-data-widget' ) : __( 'Add connection', 'iot-data-widget' )
	);
	?>
</form>
