<?php
/**
 * Widok: ustawienia ogólne (branding) + eksport/import konfiguracji połączeń.
 *
 * @package IoT_Data_Widget
 *
 * @var bool                               $show_branding         Aktualne ustawienie brandingu.
 * @var bool                               $remove_data_on_uninstall Aktualne ustawienie czyszczenia danych przy odinstalowaniu (poprawka 1.5, docs/archiwum/poprawki-ogolne.md).
 * @var array|false                        $import_result         Wynik ostatniego importu (jeśli był), patrz IOTDW_Settings_Page::process_import_upload().
 * @var array<int, array<string, mixed>>   $manual_entries        Ręcznie dodane wpisy śledzenia (v1.2 Tydzień 9).
 * @var array<int, array<string, string>>  $connections           Zapisane połączenia.
 * @var array<string, array<string, string>> $connections_by_id   Połączenia indeksowane po ID.
 * @var string[]                           $manual_entry_errors   Błędy walidacji z poprzedniej próby dodania ręcznego wpisu.
 * @var array<string, string>|null         $manual_entry_editing  Dane z poprzedniej, nieudanej próby (do odtworzenia formularza).
 * @var string                             $export_default_from   Domyślna data początkowa formularza eksportu CSV (v1.3 Tydzień 6).
 * @var string                             $export_default_to     Domyślna data końcowa formularza eksportu CSV (v1.3 Tydzień 6).
 * @var int                                $stale_threshold_minutes Próg "stare dane" w minutach (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md §5).
 * @var bool                               $allow_local_network   "Allow local network addresses" (1.0.2, IOTDW_Http_Client).
 * @var bool                               $can_manage_local_network Czy bieżący użytkownik może to zmienić (multisite: manage_network_options).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'IoT Data Widget — Settings', 'iot-data-widget' ); ?></h1>

	<?php
	$iotdw_simple_notices     = array(
		'settings_saved'       => __( 'Settings saved.', 'iot-data-widget' ),
		'manual_entry_saved'   => __( 'Manually tracked entity added.', 'iot-data-widget' ),
		'manual_entry_deleted' => __( 'Manually tracked entity removed.', 'iot-data-widget' ),
	);
	$iotdw_current_notice_key = isset( $_GET['iotdw_notice'] ) ? sanitize_key( wp_unslash( $_GET['iotdw_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

	if ( isset( $iotdw_simple_notices[ $iotdw_current_notice_key ] ) ) :
		$iotdw_notice_type        = 'success';
		$iotdw_notice_message     = $iotdw_simple_notices[ $iotdw_current_notice_key ];
		$iotdw_notice_dismissible = true;
		require __DIR__ . '/partials/notice.php';
	endif;
	?>

	<?php if ( false !== $import_result ) : ?>
		<?php if ( $import_result['success'] ) : ?>
			<div class="notice notice-success">
				<p>
					<?php
					printf(
						/* translators: %d: number of imported connections */
						esc_html( _n( 'Imported %d connection.', 'Imported %d connections.', $import_result['imported'], 'iot-data-widget' ) ),
						(int) $import_result['imported']
					);
					?>
				</p>
				<?php if ( ! empty( $import_result['skipped'] ) ) : ?>
					<p>
						<strong>
							<?php
							printf(
								/* translators: %d: number of skipped entries */
								esc_html( _n( 'Skipped %d entry:', 'Skipped %d entries:', count( $import_result['skipped'] ), 'iot-data-widget' ) ),
								count( $import_result['skipped'] )
							);
							?>
						</strong>
					</p>
					<ul class="iot-dw-admin-notice-list">
						<?php foreach ( $import_result['skipped'] as $iotdw_skipped_entry ) : ?>
							<li><?php echo esc_html( $iotdw_skipped_entry['reason'] ); ?></li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
				<p>
					<?php esc_html_e( 'Imported connections need their password/authorization re-entered before use — these are never included in an export, by design.', 'iot-data-widget' ); ?>
				</p>
			</div>
		<?php else : ?>
			<?php
			$iotdw_notice_list_heading = __( 'Import failed:', 'iot-data-widget' );
			$iotdw_notice_list_items   = $import_result['errors'];
			require __DIR__ . '/partials/notice-list.php';
			?>
		<?php endif; ?>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Branding', 'iot-data-widget' ); ?></h2>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="iotdw_save_settings" />
		<?php wp_nonce_field( 'iotdw_save_settings' ); ?>
		<table class="form-table">
			<tr>
				<th><?php esc_html_e( 'Front-end attribution', 'iot-data-widget' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="show_branding" value="1" <?php checked( $show_branding ); ?> />
						<?php esc_html_e( 'Show "Powered by IoT Data Widget" beneath widgets', 'iot-data-widget' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off by default. Turn this on if you\'d like to credit the plugin beneath your widgets.', 'iot-data-widget' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="iotdw_stale_threshold_minutes"><?php esc_html_e( 'Stale data threshold', 'iot-data-widget' ); ?></label></th>
				<td>
					<input
						type="number"
						id="iotdw_stale_threshold_minutes"
						name="stale_threshold_minutes"
						class="small-text"
						min="1"
						value="<?php echo esc_attr( (string) $stale_threshold_minutes ); ?>"
					/>
					<?php esc_html_e( 'minutes', 'iot-data-widget' ); ?>
					<p class="description">
						<?php esc_html_e( 'Past this age, widgets show the value grayed out with a timestamp instead of implying it\'s current — mainly relevant for push channels, where a device can stop sending data without any explicit connection error.', 'iot-data-widget' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Local network', 'iot-data-widget' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="allow_local_network" value="1" <?php checked( $allow_local_network ); ?> <?php disabled( ! $can_manage_local_network ); ?> />
						<?php esc_html_e( 'Allow local network addresses', 'iot-data-widget' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Turn this on only if WordPress runs on the same network as your devices (e.g. a home server) and connects to addresses like 192.168.x.x or homeassistant.local. Off by default: it stops a misconfigured or malicious URL from reaching other services on your server\'s network. Cloud metadata and link-local addresses are always blocked.', 'iot-data-widget' ); ?>
					</p>
					<?php if ( ! $can_manage_local_network ) : ?>
						<p class="description"><?php esc_html_e( 'On a multisite network, only a network administrator can change this.', 'iot-data-widget' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Uninstall', 'iot-data-widget' ); ?></th>
				<td>
					<label>
						<input type="checkbox" name="remove_data_on_uninstall" value="1" <?php checked( $remove_data_on_uninstall ); ?> />
						<?php esc_html_e( 'Remove all plugin data when the plugin is deleted', 'iot-data-widget' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off by default. Deactivating the plugin (e.g. during an update) never touches your data either way — only checking this box AND THEN deleting the plugin from the Plugins screen removes connections, history, alerts and every other setting. Leave this unchecked if you might reinstall later.', 'iot-data-widget' ); ?>
					</p>
				</td>
			</tr>
		</table>
		<?php submit_button( __( 'Save settings', 'iot-data-widget' ), 'primary', 'iotdw_submit_settings' ); ?>
	</form>

	<h2><?php esc_html_e( 'Export connections', 'iot-data-widget' ); ?></h2>
	<p><?php esc_html_e( 'Downloads a JSON file with all your connections. Passwords and authorization tokens are never included — re-enter them after importing.', 'iot-data-widget' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="iotdw_export_connections" />
		<?php wp_nonce_field( 'iotdw_export_connections' ); ?>
		<?php submit_button( __( 'Download export', 'iot-data-widget' ), 'secondary', 'iotdw_submit_export' ); ?>
	</form>

	<h2><?php esc_html_e( 'Import connections', 'iot-data-widget' ); ?></h2>
	<p><?php esc_html_e( 'Imports connections from a previously exported JSON file. Connections with a matching ID are overwritten; others are added. Existing connections not present in the file are left untouched.', 'iot-data-widget' ); ?></p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="iotdw_import_connections" />
		<?php wp_nonce_field( 'iotdw_import_connections' ); ?>
		<input type="file" name="import_file" accept="application/json" required />
		<?php submit_button( __( 'Import', 'iot-data-widget' ), 'secondary', 'iotdw_submit_import' ); ?>
	</form>

	<h2><?php esc_html_e( 'Export history data', 'iot-data-widget' ); ?></h2>
	<?php if ( ! class_exists( 'IOTDW_History_Csv_Export' ) ) : ?>
		<p class="description">
			<?php esc_html_e( 'CSV export of recorded history requires the Pro version.', 'iot-data-widget' ); ?>
		</p>
	<?php else : ?>
	<p class="description">
		<?php esc_html_e( 'Downloads the raw recorded history for one connection/entity as a CSV file, for further analysis outside the plugin. Only entities used by a Chart/Sparkline widget (or manually tracked below) have history recorded.', 'iot-data-widget' ); ?>
	</p>
	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="iotdw_export_history" />
		<input type="hidden" name="entity_mode" id="iotdw_export_entity_mode" value="list" />
		<?php wp_nonce_field( 'iotdw_export_history' ); ?>

		<table class="form-table">
			<tr>
				<th><label for="iotdw_export_connection"><?php esc_html_e( 'Connection', 'iot-data-widget' ); ?></label></th>
				<td>
					<select id="iotdw_export_connection" name="connection_id">
						<option value=""><?php esc_html_e( '— select connection —', 'iot-data-widget' ); ?></option>
						<?php foreach ( $connections as $iotdw_connection ) : ?>
							<option value="<?php echo esc_attr( $iotdw_connection['id'] ); ?>">
								<?php echo esc_html( $iotdw_connection['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr id="iot-dw-export-entity-list-row">
				<th><label for="iotdw_export_entity_select"><?php esc_html_e( 'Entity', 'iot-data-widget' ); ?></label></th>
				<td>
					<select id="iotdw_export_entity_select" name="entity_id_list"></select>
					<p>
						<button type="button" class="button-link" id="iotdw_export_entity_manual_toggle">
							<?php esc_html_e( 'Enter manually instead', 'iot-data-widget' ); ?>
						</button>
					</p>
				</td>
			</tr>
			<tr id="iot-dw-export-entity-manual-row" style="display:none">
				<th><label for="iotdw_export_entity_text"><?php esc_html_e( 'Entity', 'iot-data-widget' ); ?></label></th>
				<td>
					<input type="text" id="iotdw_export_entity_text" name="entity_id_manual" class="regular-text" />
					<p class="description">
						<?php esc_html_e( 'Useful if the entity was renamed/removed from the live connection since history was recorded.', 'iot-data-widget' ); ?>
					</p>
					<p>
						<button type="button" class="button-link" id="iotdw_export_entity_list_toggle">
							<?php esc_html_e( 'Choose from list instead', 'iot-data-widget' ); ?>
						</button>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="iotdw_export_from"><?php esc_html_e( 'From', 'iot-data-widget' ); ?></label></th>
				<td><input type="date" id="iotdw_export_from" name="from" value="<?php echo esc_attr( $export_default_from ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="iotdw_export_to"><?php esc_html_e( 'To', 'iot-data-widget' ); ?></label></th>
				<td><input type="date" id="iotdw_export_to" name="to" value="<?php echo esc_attr( $export_default_to ); ?>" /></td>
			</tr>
		</table>

		<?php submit_button( __( 'Download CSV', 'iot-data-widget' ), 'secondary', 'iotdw_submit_export_history' ); ?>
	</form>
	<?php endif; ?>

	<h2 id="iot-dw-manual-tracking"><?php esc_html_e( 'Manually tracked entities', 'iot-data-widget' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Widgets placed in post/page content are detected automatically. If you output a widget from a theme template instead (e.g. in a widget area or a custom page template), add it here so it still gets polled for history/alerts even though there\'s no post content to scan.', 'iot-data-widget' ); ?>
	</p>

	<?php
	$iotdw_table_columns = array(
		'connection' => __( 'Connection', 'iot-data-widget' ),
		'entity'     => __( 'Entity', 'iot-data-widget' ),
		'style'      => __( 'Style', 'iot-data-widget' ),
		'actions'    => '',
	);

	$iotdw_table_rows = array();

	foreach ( $manual_entries as $iotdw_manual_entry ) {
		$iotdw_manual_entry_connection = $connections_by_id[ $iotdw_manual_entry['connection_id'] ] ?? null;

		ob_start();
		$iotdw_delete_action  = 'iotdw_delete_manual_entry';
		$iotdw_delete_id      = $iotdw_manual_entry['id'];
		$iotdw_delete_confirm = __( 'Stop tracking this entity?', 'iot-data-widget' );
		$iotdw_delete_label   = __( 'Remove', 'iot-data-widget' );
		require __DIR__ . '/partials/delete-form.php';

		$iotdw_actions_html = (string) ob_get_clean();

		$iotdw_table_rows[] = array(
			'connection' => $iotdw_manual_entry_connection['name'] ?? $iotdw_manual_entry['connection_id'],
			'entity'     => array( 'html' => '<code>' . esc_html( $iotdw_manual_entry['entity_id'] ) . '</code>' ),
			'style'      => $iotdw_manual_entry['style'],
			'actions'    => array( 'html' => $iotdw_actions_html ),
		);
	}

	$iotdw_table_empty_message   = __( 'No manually tracked entities yet.', 'iot-data-widget' );
	$iotdw_table_empty_cta_label = __( 'Track your first entity', 'iot-data-widget' );
	$iotdw_table_empty_cta_url   = '#iotdw_manual_connection';

	require __DIR__ . '/partials/data-table.php';
	?>

	<?php if ( ! empty( $manual_entry_errors ) ) : ?>
		<?php
		$iotdw_notice_list_heading = __( 'Please fix the following before saving:', 'iot-data-widget' );
		$iotdw_notice_list_items   = $manual_entry_errors;
		require __DIR__ . '/partials/notice-list.php';
		?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="iotdw_add_manual_entry" />
		<input type="hidden" name="entity_mode" id="iotdw_manual_entity_mode" value="list" />
		<?php wp_nonce_field( 'iotdw_add_manual_entry' ); ?>

		<table class="form-table">
			<tr>
				<th><label for="iotdw_manual_connection"><?php esc_html_e( 'Connection', 'iot-data-widget' ); ?></label></th>
				<td>
					<select id="iotdw_manual_connection" name="connection_id">
						<option value=""><?php esc_html_e( '— select connection —', 'iot-data-widget' ); ?></option>
						<?php foreach ( $connections as $iotdw_connection ) : ?>
							<option value="<?php echo esc_attr( $iotdw_connection['id'] ); ?>" <?php selected( $manual_entry_editing['connection_id'] ?? '', $iotdw_connection['id'] ); ?>>
								<?php echo esc_html( $iotdw_connection['name'] ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<?php if ( empty( $connections ) ) : ?>
						<p class="description"><?php esc_html_e( 'No connections configured yet. Add one under IoT Data Widget → Connections first.', 'iot-data-widget' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<tr id="iot-dw-manual-entity-list-row">
				<th><label for="iotdw_manual_entity_select"><?php esc_html_e( 'Entity', 'iot-data-widget' ); ?></label></th>
				<td>
					<select id="iotdw_manual_entity_select" name="entity_id_list"></select>
					<p>
						<button type="button" class="button-link" id="iotdw_manual_entity_manual_toggle">
							<?php esc_html_e( 'Enter manually instead', 'iot-data-widget' ); ?>
						</button>
					</p>
				</td>
			</tr>
			<tr id="iot-dw-manual-entity-manual-row" style="display:none">
				<th><label for="iotdw_manual_entity_text"><?php esc_html_e( 'Entity', 'iot-data-widget' ); ?></label></th>
				<td>
					<input
						type="text"
						id="iotdw_manual_entity_text"
						name="entity_id_manual"
						class="regular-text"
						value="<?php echo esc_attr( $manual_entry_editing['entity_id'] ?? '' ); ?>"
					/>
					<p>
						<button type="button" class="button-link" id="iotdw_manual_entity_list_toggle">
							<?php esc_html_e( 'Choose from list instead', 'iot-data-widget' ); ?>
						</button>
					</p>
				</td>
			</tr>
			<tr>
				<th><label for="iotdw_manual_style"><?php esc_html_e( 'Widget style', 'iot-data-widget' ); ?></label></th>
				<td>
					<select id="iotdw_manual_style" name="style">
						<option value="card" <?php selected( $manual_entry_editing['style'] ?? 'card', 'card' ); ?>><?php esc_html_e( 'Card', 'iot-data-widget' ); ?></option>
						<option value="list" <?php selected( $manual_entry_editing['style'] ?? '', 'list' ); ?>><?php esc_html_e( 'Status list', 'iot-data-widget' ); ?></option>
						<option value="gauge" <?php selected( $manual_entry_editing['style'] ?? '', 'gauge' ); ?>><?php esc_html_e( 'Gauge', 'iot-data-widget' ); ?></option>
						<option value="chart" <?php selected( $manual_entry_editing['style'] ?? '', 'chart' ); ?>><?php esc_html_e( 'Chart', 'iot-data-widget' ); ?></option>
						<option value="sparkline" <?php selected( $manual_entry_editing['style'] ?? '', 'sparkline' ); ?>><?php esc_html_e( 'Mini trend (sparkline)', 'iot-data-widget' ); ?></option>
					</select>
					<p class="description">
						<?php esc_html_e( 'Must match the style actually rendered by your theme template — Chart/Sparkline are what determine whether this entity gets recorded into history.', 'iot-data-widget' ); ?>
					</p>
				</td>
			</tr>
		</table>

		<?php submit_button( __( 'Add manually tracked entity', 'iot-data-widget' ), 'primary', 'iotdw_submit_manual_entry' ); ?>
	</form>
</div>
