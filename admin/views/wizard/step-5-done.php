<?php
/**
 * Widok: Krok 5 kreatora — ekran końcowy (v1.4 Tydzień 7). Podsumowanie
 * tego, co zostało skonfigurowane, plus te same linki "kolejne kroki" co
 * dismissible checklista na stronie Connections (IOTDW_Admin_Page::next_steps_links()).
 *
 * @package IoT_Data_Widget
 *
 * @var array{label: string, connector: string, preset: string}            $type            Wybrany typ z Kroku 1.
 * @var array<string, string>                                              $iotdw_connection      Zapisane połączenie.
 * @var string[]                                                           $entity_ids      Encje zaznaczone w Kroku 3 (jedna albo kilka, v1.9).
 * @var string                                                             $connections_url Link do strony Connections.
 * @var array<int, array{label: string, description: string, url: string}> $next_steps      Linki "kolejne kroki".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2><?php esc_html_e( "Done! You're all set.", 'iot-data-widget' ); ?></h2>

	<table class="form-table" role="presentation">
		<tr>
			<th><?php esc_html_e( 'Connection type', 'iot-data-widget' ); ?></th>
			<td><?php echo esc_html( $type['label'] ); ?></td>
		</tr>
		<tr>
			<th><?php esc_html_e( 'Connection name', 'iot-data-widget' ); ?></th>
			<td><?php echo esc_html( $iotdw_connection['name'] ); ?></td>
		</tr>
		<tr>
			<th><?php echo esc_html( _n( 'Entity', 'Entities', count( $entity_ids ), 'iot-data-widget' ) ); ?></th>
			<td><?php echo esc_html( implode( ', ', $entity_ids ) ); ?></td>
		</tr>
	</table>

	<p>
		<a href="<?php echo esc_url( $connections_url ); ?>" class="button button-primary">
			<?php esc_html_e( 'Go to Connections', 'iot-data-widget' ); ?>
		</a>
	</p>

	<h2><?php esc_html_e( 'Try these next', 'iot-data-widget' ); ?></h2>
	<?php
	$iotdw_next_steps_class = 'iot-dw-admin-notice-list';
	require __DIR__ . '/../partials/next-steps-list.php';
	?>
</div>
