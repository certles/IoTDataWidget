<?php
/**
 * Widok: strona diagnostyki — sekcje "Environment", "Scheduled tasks" (v1.6
 * Tydzień 3), "Connections", "Alert rules" (v1.6 Tydzień 4), "Recent errors",
 * "Data size" (v1.6 Tydzień 5) i przycisk "Copy report" (v1.6 Tydzień 6).
 *
 * @package IoT_Data_Widget
 *
 * @var array{php_version: string, wp_version: string, active_theme: string, active_plugins: string[], memory_limit: string, max_execution_time: string} $environment Dane środowiska.
 * @var array<int, array{hook: string, label: string, is_scheduled: bool, next_run: int|false, last_run: array{ran_at: string, success: bool, message: string}|null}> $cron_jobs Zaplanowane zadania.
 * @var array<int, array{name: string, connector_label: string, last_checked_at: string|null, last_status: string|null, consecutive_errors: int, last_error_message: string|null, is_push: bool, push_active: bool|null, last_received_at: string|null}> $connections Skonfigurowane połączenia + ich status.
 * @var array<int, array<string, mixed>>|null $alert_rules       Zapisane reguły alertów (IOTDW_Alert_Manager::get_rules()); null = wersja darmowa, sekcja ukryta.
 * @var array<string, array<string, string>> $connections_by_id Połączenia indeksowane po ID (do nazw w tabeli reguł alertów).
 * @var array<int, array{id: int, occurred_at: string, source: string, message: string}> $recent_errors Najnowsze wpisy logu błędów.
 * @var array<int, array{label: string, count: int}> $data_sizes  Liczba wierszy w tabelach własnych wtyczki.
 * @var string                                        $report_text Pełny tekstowy raport diagnostyczny (już zredagowany).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="wrap">
	<h1><?php esc_html_e( 'IoT Data Widget — Diagnostics', 'iot-data-widget' ); ?></h1>
	<p>
		<?php esc_html_e( "A snapshot of this site's environment and IoT Data Widget's own scheduled tasks — useful when reporting an issue.", 'iot-data-widget' ); ?>
	</p>

	<p>
		<button type="button" id="iot-dw-copy-report" class="button button-primary">
			<?php esc_html_e( 'Copy diagnostic report', 'iot-data-widget' ); ?>
		</button>
		<span id="iot-dw-copy-report-status" role="status" class="iot-dw-admin-copy-status"></span>
		<br />
		<span class="description">
			<?php esc_html_e( 'Copies everything on this page as plain text, ready to paste into a support email. Tokens, keys and passwords are stripped before anything is recorded — the report never includes them.', 'iot-data-widget' ); ?>
		</span>
	</p>
	<textarea id="iot-dw-report-text" readonly hidden><?php echo esc_textarea( $report_text ); ?></textarea>

	<h2><?php esc_html_e( 'Environment', 'iot-data-widget' ); ?></h2>
	<table class="widefat striped">
		<tbody>
			<tr>
				<th class="iot-dw-admin-table__label-col"><?php esc_html_e( 'PHP version', 'iot-data-widget' ); ?></th>
				<td><?php echo esc_html( $environment['php_version'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'WordPress version', 'iot-data-widget' ); ?></th>
				<td><?php echo esc_html( $environment['wp_version'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Active theme', 'iot-data-widget' ); ?></th>
				<td><?php echo esc_html( $environment['active_theme'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Memory limit', 'iot-data-widget' ); ?></th>
				<td><?php echo esc_html( $environment['memory_limit'] ); ?></td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Max execution time', 'iot-data-widget' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: %s: max_execution_time PHP setting, in seconds */
						esc_html__( '%ss', 'iot-data-widget' ),
						esc_html( $environment['max_execution_time'] )
					);
					?>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Other active plugins', 'iot-data-widget' ); ?></th>
				<td>
					<?php if ( empty( $environment['active_plugins'] ) ) : ?>
						<?php esc_html_e( 'None', 'iot-data-widget' ); ?>
					<?php else : ?>
						<ul class="iot-dw-admin-table-cell-list">
							<?php foreach ( $environment['active_plugins'] as $iotdw_plugin_name ) : ?>
								<li><?php echo esc_html( $iotdw_plugin_name ); ?></li>
							<?php endforeach; ?>
						</ul>
					<?php endif; ?>
				</td>
			</tr>
		</tbody>
	</table>

	<h2><?php esc_html_e( 'Scheduled tasks', 'iot-data-widget' ); ?></h2>
	<?php
	$iotdw_table_columns = array(
		'task'     => __( 'Task', 'iot-data-widget' ),
		'status'   => __( 'Status', 'iot-data-widget' ),
		'next_run' => __( 'Next run', 'iot-data-widget' ),
		'last_run' => __( 'Last run', 'iot-data-widget' ),
	);

	$iotdw_table_rows = array();

	foreach ( $cron_jobs as $iotdw_job ) {
		if ( ! $iotdw_job['is_scheduled'] ) {
			$iotdw_status_html = '<span class="iot-dw-admin-status--error">&#10007; ' . esc_html__( 'Not scheduled', 'iot-data-widget' ) . '</span>';
		} elseif ( null === $iotdw_job['last_run'] ) {
			$iotdw_status_html = '<span class="iot-dw-admin-status--warning">&#9679; ' . esc_html__( 'Scheduled, never run yet', 'iot-data-widget' ) . '</span>';
		} elseif ( $iotdw_job['last_run']['success'] ) {
			$iotdw_status_html = '<span class="iot-dw-admin-status--ok">&#10003; ' . esc_html__( 'Scheduled & running', 'iot-data-widget' ) . '</span>';
		} else {
			$iotdw_status_html = '<span class="iot-dw-admin-status--error">&#10007; ' . esc_html__( 'Scheduled, last run failed', 'iot-data-widget' ) . '</span>';
		}

		$iotdw_next_run_text = false !== $iotdw_job['next_run']
			? sprintf(
				/* translators: %s: next scheduled run date/time, in UTC */
				__( '%s UTC', 'iot-data-widget' ),
				gmdate( 'Y-m-d H:i:s', $iotdw_job['next_run'] )
			)
			: '—';

		if ( null === $iotdw_job['last_run'] ) {
			$iotdw_last_run_html = '—';
		} else {
			$iotdw_last_run_html = esc_html(
				sprintf(
					/* translators: %s: last run date/time, in UTC */
					__( '%s UTC', 'iot-data-widget' ),
					$iotdw_job['last_run']['ran_at']
				)
			);

			if ( ! $iotdw_job['last_run']['success'] && '' !== $iotdw_job['last_run']['message'] ) {
				$iotdw_last_run_html .= '<br /><span class="description">' . esc_html( IOTDW_Diagnostics_Logger::redact_secrets( $iotdw_job['last_run']['message'] ) ) . '</span>';
			}
		}

		$iotdw_table_rows[] = array(
			'task'     => $iotdw_job['label'],
			'status'   => array( 'html' => $iotdw_status_html ),
			'next_run' => $iotdw_next_run_text,
			'last_run' => array( 'html' => $iotdw_last_run_html ),
		);
	}

	$iotdw_table_empty_message = __( 'No scheduled tasks found.', 'iot-data-widget' );

	require __DIR__ . '/partials/data-table.php';
	?>

	<h2><?php esc_html_e( 'Connections', 'iot-data-widget' ); ?></h2>
	<?php
	$iotdw_table_columns = array(
		'name'    => __( 'Connection', 'iot-data-widget' ),
		'type'    => __( 'Type', 'iot-data-widget' ),
		'checked' => __( 'Last checked', 'iot-data-widget' ),
		'status'  => __( 'Status', 'iot-data-widget' ),
		'errors'  => __( 'Consecutive errors', 'iot-data-widget' ),
		'last'    => __( 'Last error', 'iot-data-widget' ),
	);

	$iotdw_table_rows = array();

	foreach ( $connections as $iotdw_connection ) {
		// Kanały push nie przechodzą przez Cache Layer/Diagnostics Logger —
		// "Last checked"/"Status"/"Consecutive errors" byłyby zawsze puste
		// dla nich, więc dostają własny, znaczący status (Faza 7 planu
		// freemium — is_active()/get_last_received_at() istniały, ale nie
		// były wcześniej wołane z żadnego widoku).
		if ( $iotdw_connection['is_push'] ) {
			$iotdw_received_text = null !== $iotdw_connection['last_received_at']
				? sprintf(
					/* translators: %s: last received date/time, in UTC */
					__( '%s UTC', 'iot-data-widget' ),
					$iotdw_connection['last_received_at']
				)
				: '—';

			if ( null === $iotdw_connection['push_active'] ) {
				$iotdw_status_html = '<span class="iot-dw-admin-status--muted">' . esc_html__( 'No data received yet', 'iot-data-widget' ) . '</span>';
			} elseif ( $iotdw_connection['push_active'] ) {
				$iotdw_status_html = '<span class="iot-dw-admin-status--ok">&#10003; ' . esc_html__( 'Active', 'iot-data-widget' ) . '</span>';
			} else {
				$iotdw_status_html = '<span class="iot-dw-admin-status--warning">&#9888; ' . esc_html__( 'Inactive', 'iot-data-widget' ) . '</span>';
			}

			$iotdw_table_rows[] = array(
				'_row_class' => ( false === $iotdw_connection['push_active'] ) ? 'iot-dw-admin-table-row--error' : '',
				'name'       => $iotdw_connection['name'],
				'type'       => $iotdw_connection['connector_label'],
				'checked'    => $iotdw_received_text,
				'status'     => array( 'html' => $iotdw_status_html ),
				'errors'     => '—',
				'last'       => '—',
			);

			continue;
		}

		$iotdw_is_persistent = $iotdw_connection['consecutive_errors'] > IOTDW_Diagnostics_Logger::PERSISTENT_ERROR_THRESHOLD;

		$iotdw_checked_text = null !== $iotdw_connection['last_checked_at']
			? sprintf(
				/* translators: %s: last checked date/time, in UTC */
				__( '%s UTC', 'iot-data-widget' ),
				$iotdw_connection['last_checked_at']
			)
			: '—';

		if ( null === $iotdw_connection['last_status'] ) {
			$iotdw_status_html = '<span class="iot-dw-admin-status--muted">' . esc_html__( 'No data yet', 'iot-data-widget' ) . '</span>';
		} elseif ( 'ok' === $iotdw_connection['last_status'] ) {
			$iotdw_status_html = '<span class="iot-dw-admin-status--ok">&#10003; ' . esc_html__( 'OK', 'iot-data-widget' ) . '</span>';
		} else {
			$iotdw_status_html = '<span class="iot-dw-admin-status--error">&#10007; ' . esc_html__( 'Error', 'iot-data-widget' ) . '</span>';
		}

		$iotdw_errors_html = $iotdw_is_persistent
			? '<strong class="iot-dw-admin-status--error">' . esc_html( (string) $iotdw_connection['consecutive_errors'] ) . '</strong>'
			: esc_html( (string) $iotdw_connection['consecutive_errors'] );

		$iotdw_table_rows[] = array(
			'_row_class' => $iotdw_is_persistent ? 'iot-dw-admin-table-row--error' : '',
			'name'       => $iotdw_connection['name'],
			'type'       => $iotdw_connection['connector_label'],
			'checked'    => $iotdw_checked_text,
			'status'     => array( 'html' => $iotdw_status_html ),
			'errors'     => array( 'html' => $iotdw_errors_html ),
			'last'       => $iotdw_connection['last_error_message'] ?? '—',
		);
	}

	$iotdw_table_empty_message = __( 'No connections configured yet.', 'iot-data-widget' );

	require __DIR__ . '/partials/data-table.php';
	?>

	<?php if ( null !== $alert_rules ) : // Alerty są Pro — na wersji darmowej sekcji nie ma. ?>
	<h2><?php esc_html_e( 'Alert rules', 'iot-data-widget' ); ?></h2>
		<?php
		$iotdw_table_columns = array(
			'connection' => __( 'Connection', 'iot-data-widget' ),
			'entity'     => __( 'Entity', 'iot-data-widget' ),
			'condition'  => __( 'Condition', 'iot-data-widget' ),
			'status'     => __( 'Status', 'iot-data-widget' ),
			'triggered'  => __( 'Last triggered', 'iot-data-widget' ),
		);

		$iotdw_table_rows = array();

		foreach ( $alert_rules as $iotdw_rule ) {
			$iotdw_rule_connection = $connections_by_id[ $iotdw_rule['connection_id'] ] ?? null;

			$iotdw_status_html = 'triggered' === $iotdw_rule['last_state']
			? '<strong class="iot-dw-admin-status--error">' . esc_html__( 'Triggered', 'iot-data-widget' ) . '</strong>'
			: '<span>' . esc_html__( 'Normal', 'iot-data-widget' ) . '</span>';

			$iotdw_triggered_text = ! empty( $iotdw_rule['last_triggered_at'] )
			? sprintf(
				/* translators: %s: last triggered date/time, in UTC */
				__( '%s UTC', 'iot-data-widget' ),
				$iotdw_rule['last_triggered_at']
			)
				: '—';

			$iotdw_table_rows[] = array(
				'connection' => $iotdw_rule_connection['name'] ?? $iotdw_rule['connection_id'],
				'entity'     => array( 'html' => '<code>' . esc_html( $iotdw_rule['entity_id'] ) . '</code>' ),
				'condition'  => ( 'above' === $iotdw_rule['condition_type'] ? '> ' : '< ' ) . $iotdw_rule['threshold_value'],
				'status'     => array( 'html' => $iotdw_status_html ),
				'triggered'  => $iotdw_triggered_text,
			);
		}

		$iotdw_table_empty_message = __( 'No alert rules configured yet.', 'iot-data-widget' );

		require __DIR__ . '/partials/data-table.php';
		?>
	<?php endif; ?>

	<h2><?php esc_html_e( 'Recent errors', 'iot-data-widget' ); ?></h2>
	<?php
	$iotdw_table_columns = array(
		'when'    => __( 'When', 'iot-data-widget' ),
		'source'  => __( 'Source', 'iot-data-widget' ),
		'message' => __( 'Message', 'iot-data-widget' ),
	);

	$iotdw_table_rows = array();

	foreach ( $recent_errors as $iotdw_error_entry ) {
		$iotdw_table_rows[] = array(
			'when'    => sprintf(
				/* translators: %s: date/time the error occurred, in UTC */
				__( '%s UTC', 'iot-data-widget' ),
				$iotdw_error_entry['occurred_at']
			),
			'source'  => array( 'html' => '<code>' . esc_html( $iotdw_error_entry['source'] ) . '</code>' ),
			'message' => $iotdw_error_entry['message'],
		);
	}

	// "No errors recorded" is good news, not a gap — phrased as such, per
	// v1.7 Tydzień 6's rule for transient-empty lists (docs/archiwum/mvp-v1-7-plan.md).
	$iotdw_table_empty_message = __( 'No errors recorded — everything is working.', 'iot-data-widget' );

	require __DIR__ . '/partials/data-table.php';
	?>

	<h2><?php esc_html_e( 'Data size', 'iot-data-widget' ); ?></h2>
	<?php
	// data-table.php (code review, docs/archiwum/poprawki-ogolne.md) — ta tabela była
	// hand-rolled, jedyna z pięciu na tej stronie nie reużywająca wspólnego
	// partiala, mimo identycznego dwukolumnowego kształtu danych.
	$iotdw_table_columns = array(
		'table' => __( 'Table', 'iot-data-widget' ),
		'rows'  => __( 'Rows', 'iot-data-widget' ),
	);

	$iotdw_table_rows = array();

	foreach ( $data_sizes as $iotdw_table ) {
		$iotdw_table_rows[] = array(
			'table' => $iotdw_table['label'],
			'rows'  => number_format_i18n( $iotdw_table['count'] ),
		);
	}

	// data_size_info() zawsze zwraca stałą listę tabel wtyczki (nigdy pustą)
	// — ta wiadomość w praktyce nigdy się nie wyświetli, ale partial jej
	// wymaga.
	$iotdw_table_empty_message = __( 'No data yet', 'iot-data-widget' );

	require __DIR__ . '/partials/data-table.php';
	?>
</div>
