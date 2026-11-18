<?php
/**
 * Widok: lista połączeń + formularz dodawania/edycji. Od v1.7 Tydzień 4
 * (docs/archiwum/mvp-v1-7-plan.md, docs/archiwum/audyt-ui-v1-7.md) używa wspólnego toolkitu UI
 * z Tygodnia 3 — patrz admin/views/partials/{notice,empty-state,data-table}.php.
 *
 * @package IoT_Data_Widget
 *
 * @var array<int, array<string, string>>                            $connections     Zapisane połączenia.
 * @var array<string, string>|null                                   $editing         Edytowane połączenie (jeśli dotyczy).
 * @var array<string, string>                                        $connector_types Dostępne typy konektorów [typ => etykieta].
 * @var bool                                                         $show_checklist  Czy pokazać dismissible checklistę "kolejne kroki" (v1.4 Tydzień 7).
 * @var array<int, array{label: string, description: string, url: string}> $next_steps Linki checklisty (patrz IOTDW_Admin_Page::next_steps_links()).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'IoT Data Widget — Connections', 'iot-data-widget' ); ?></h1>

	<p>
		<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . IOTDW_Setup_Wizard::PAGE_SLUG ) ); ?>">
			<?php esc_html_e( 'Run the setup wizard again', 'iot-data-widget' ); ?>
		</a>
		— <?php esc_html_e( 'add another connection through the same guided steps (test, pick an entity, get a ready-to-use shortcode).', 'iot-data-widget' ); ?>
	</p>

	<?php
	if ( isset( $_GET['iotdw_notice'] ) ) : // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$iotdw_current_notice = sanitize_key( wp_unslash( $_GET['iotdw_notice'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( 'demo_failed' === $iotdw_current_notice ) {
			// Dawniej "The free version supports one connection" — pozostałość
			// po zniesionym limicie; zapis połączenia demo po prostu się nie udał.
			$iotdw_notice_type    = 'error';
			$iotdw_notice_message = __( 'The demo connection could not be saved. Please try again, or add a connection manually below.', 'iot-data-widget' );
		} else {
			$iotdw_notice_type    = 'success';
			$iotdw_notice_message = 'saved' === $iotdw_current_notice
				? __( 'Connection saved.', 'iot-data-widget' )
				: __( 'Connection deleted.', 'iot-data-widget' );
		}

		$iotdw_notice_dismissible = true;
		require __DIR__ . '/partials/notice.php';
	endif;
	?>

	<?php if ( $show_checklist && ! empty( $connections ) ) : ?>
		<div class="notice notice-info iot-dw-admin-notice--persistent-dismiss">
			<h2><?php esc_html_e( 'Now that you have a connection, try these next:', 'iot-data-widget' ); ?></h2>
			<?php require __DIR__ . '/partials/next-steps-list.php'; ?>
			<a
				href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=iotdw_dismiss_checklist' ), 'iotdw_dismiss_checklist' ) ); ?>"
				class="notice-dismiss"
			>
				<span class="screen-reader-text"><?php esc_html_e( 'Dismiss this notice.', 'iot-data-widget' ); ?></span>
			</a>
		</div>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Saved connections', 'iot-data-widget' ); ?></h2>

	<?php
	$iotdw_table_columns = array(
		'name'      => __( 'Name', 'iot-data-widget' ),
		'type'      => __( 'Type', 'iot-data-widget' ),
		'url'       => __( 'URL', 'iot-data-widget' ),
		'shortcode' => __( 'Shortcode', 'iot-data-widget' ),
		'actions'   => '',
	);

	$iotdw_table_rows = array();

	foreach ( $connections as $iotdw_saved_connection ) {
		$iotdw_is_mqtt_broker = IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE === $iotdw_saved_connection['connector']
			|| ( IOTDW_Cron_Value_Cache::CONNECTOR_TYPE === $iotdw_saved_connection['connector']
				&& 'emqx_rest' !== ( $iotdw_saved_connection['mqtt_transport'] ?? 'socket' ) );

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro; lista
		// połączeń może nadal zawierać stare, presetowe wpisy (np. sprzed
		// przejścia z Pro na free) — muszą się wyrenderować (adres jako
		// zwykły base_url niżej), nie fatal-errorować.
		$iotdw_is_rest_preset  = 'rest' === $iotdw_saved_connection['connector'] && '' !== ( $iotdw_saved_connection['preset'] ?? '' ) && class_exists( 'IOTDW_Connector_Presets' );
		$iotdw_preset          = $iotdw_is_rest_preset ? IOTDW_Connector_Presets::get( $iotdw_saved_connection['preset'] ) : null;
		$iotdw_is_shelly_cloud = 'shelly_cloud' === $iotdw_saved_connection['connector'];
		$iotdw_is_push_channel = IOTDW_Push_Channel_Manager::CONNECTOR_TYPE === $iotdw_saved_connection['connector'];

		if ( $iotdw_is_mqtt_broker ) {
			$iotdw_address = trim( ( $iotdw_saved_connection['mqtt_host'] ?? '' ) . ':' . ( $iotdw_saved_connection['mqtt_port'] ?? '' ), ':' );
		} elseif ( null !== $iotdw_preset ) {
			$iotdw_address = $iotdw_preset['label'] . ' @ ' . ( $iotdw_saved_connection['device_ip'] ?? '' );
		} elseif ( $iotdw_is_shelly_cloud ) {
			// auth_key jest celowo pominięty — to sekret, nigdy nie
			// pokazujemy go nawet częściowo w tej tabeli.
			$iotdw_address = ( $iotdw_saved_connection['device_id'] ?? '' ) . ' @ ' . ( $iotdw_saved_connection['server_uri'] ?? '' );
		} elseif ( $iotdw_is_push_channel ) {
			// Kanał push nie ma adresu do odpytania — to urządzenie wysyła
			// dane DO WordPressa, nie odwrotnie. Kolumna zostaje pusta.
			$iotdw_address = '';
		} else {
			$iotdw_address = $iotdw_saved_connection['base_url'] ?? '';
		}

		ob_start();
		?>
		<a href="<?php echo esc_url( add_query_arg( 'edit', $iotdw_saved_connection['id'] ) ); ?>">
			<?php esc_html_e( 'Edit', 'iot-data-widget' ); ?>
		</a>
		|
		<?php
		$iotdw_delete_action  = 'iotdw_delete_connection';
		$iotdw_delete_id      = $iotdw_saved_connection['id'];
		$iotdw_delete_confirm = __( 'Are you sure you want to delete this connection?', 'iot-data-widget' );
		require __DIR__ . '/partials/delete-form.php';

		$iotdw_actions_html = (string) ob_get_clean();

		$iotdw_table_rows[] = array(
			'name'      => $iotdw_saved_connection['name'],
			'type'      => $connector_types[ $iotdw_saved_connection['connector'] ] ?? $iotdw_saved_connection['connector'],
			'url'       => $iotdw_address,
			'shortcode' => array( 'html' => '<code>[iotdw_widget connection="' . esc_html( $iotdw_saved_connection['id'] ) . '" entity="…"]</code>' ),
			'actions'   => array( 'html' => $iotdw_actions_html ),
		);
	}

	$iotdw_table_empty_message   = __( 'No connections configured yet.', 'iot-data-widget' );
	$iotdw_table_empty_cta_label = __( 'Add your first connection', 'iot-data-widget' );
	$iotdw_table_empty_cta_url   = '#iot-dw-add-connection';

	require __DIR__ . '/partials/data-table.php';
	?>

	<h2 id="iot-dw-add-connection">
		<?php
		echo $editing
			? esc_html__( 'Edit connection', 'iot-data-widget' )
			: esc_html__( 'Add connection', 'iot-data-widget' );
		?>
	</h2>

	<?php require __DIR__ . '/connector-form.php'; ?>
</div>
