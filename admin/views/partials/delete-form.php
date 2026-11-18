<?php
/**
 * Partial: formularz "Usuń" z potwierdzeniem JS (v1.8, code review) —
 * wspólny znacznik dla wierszy tabel akcji w panelu admina (połączenia,
 * reguły alertów, raporty e-mail, ręcznie śledzone encje). Wcześniej ten
 * sam ~15-liniowy blok był kopiowany osobno w czterech widokach, różniąc
 * się tylko nazwą akcji admin-post.php, polem "id" i tekstem
 * potwierdzenia — jedna zmiana w UX usuwania (np. dialog AJAX zamiast
 * confirm()) wymagałaby czterech zsynchronizowanych edycji.
 *
 * @package IoT_Data_Widget
 *
 * @var string     $iotdw_delete_action  Nazwa akcji admin-post.php BEZ prefiksu "admin_post_" — jednocześnie akcja nonce (te dwie zawsze się zgadzają w istniejących wywołaniach, patrz handler w klasie strony).
 * @var int|string $iotdw_delete_id      Wartość pola "id".
 * @var string     $iotdw_delete_confirm Tekst potwierdzenia JS (confirm()).
 * @var string     $iotdw_delete_label   Widoczny tekst przycisku. Domyślnie "Delete".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_delete_label = $iotdw_delete_label ?? __( 'Delete', 'iot-data-widget' );
?>
<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" style="display:inline">
	<input type="hidden" name="action" value="<?php echo esc_attr( $iotdw_delete_action ); ?>" />
	<input type="hidden" name="id" value="<?php echo esc_attr( $iotdw_delete_id ); ?>" />
	<?php wp_nonce_field( $iotdw_delete_action ); ?>
	<button
		type="submit"
		class="button-link"
		onclick="return confirm('<?php echo esc_js( $iotdw_delete_confirm ); ?>');"
	>
		<?php echo esc_html( $iotdw_delete_label ); ?>
	</button>
</form>
