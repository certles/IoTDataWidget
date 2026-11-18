<?php
/**
 * Widok: Krok "snippet" ścieżki push (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md
 * §4, §6) — token (raz), gotowe snippety per platforma, panel "sprawdź czy
 * dane docierają".
 *
 * @package IoT_Data_Widget
 *
 * @var string                  $push_type Wybrana/podstawowa platforma.
 * @var array<string,string>    $iotdw_connection Zapisany kanał (id, name).
 * @var string|false            $raw_token Surowy token, TYLKO jeśli to pierwsze wyświetlenie od utworzenia/regeneracji (patrz IOTDW_Setup_Wizard::PUSH_TOKEN_TRANSIENT_PREFIX) — inaczej false.
 * @var array<string, array{label: string, verified: bool, blocks: array<int, array{title: string, language: string, code: string}>, notes: string[]}> $snippets Puste, jeśli $raw_token === false (token już pokazany wcześniej — trzeba zregenerować, żeby zobaczyć znów).
 * @var array<int, array{entity_key: string, unit: string|null, last_value_num: float|null, last_value_txt: string|null, last_recorded_at: string|null}> $entities Zadeklarowane encje kanału + ich bieżący stan.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_finish_url = admin_url( 'admin.php?page=iot-data-widget' );
$iotdw_platforms  = IOTDW_Setup_Wizard::get_push_platforms();
$iotdw_has_token  = false !== $raw_token;
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2><?php esc_html_e( 'Channel ready — configure your device', 'iot-data-widget' ); ?></h2>

	<?php if ( $iotdw_has_token ) : ?>
		<div class="notice notice-warning inline iot-dw-admin-notice--inline">
			<p>
				<strong><?php esc_html_e( 'Save this token now — it will not be shown again.', 'iot-data-widget' ); ?></strong>
			</p>
			<p>
				<code class="iot-dw-wizard__shortcode"><?php echo esc_html( $raw_token ); ?></code>
			</p>
			<p class="description">
				<?php esc_html_e( "It's already embedded in the snippets below — you only need to copy it separately if you're configuring something this generator doesn't cover.", 'iot-data-widget' ); ?>
			</p>
		</div>
	<?php else : ?>
		<?php
		$iotdw_notice_type        = 'info';
		$iotdw_notice_message     = __( 'The token for this channel was already shown once and cannot be displayed again. Regenerate it below to get a fresh one (the old one will stop working).', 'iot-data-widget' );
		$iotdw_notice_dismissible = false;
		require __DIR__ . '/../partials/notice.php';
		?>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="<?php echo esc_attr( IOTDW_Setup_Wizard::PUSH_REGENERATE_TOKEN_ACTION ); ?>" />
			<input type="hidden" name="channel" value="<?php echo esc_attr( $iotdw_connection['id'] ); ?>" />
			<input type="hidden" name="push_type" value="<?php echo esc_attr( $push_type ); ?>" />
			<?php wp_nonce_field( IOTDW_Setup_Wizard::PUSH_REGENERATE_TOKEN_ACTION ); ?>
			<?php submit_button( __( 'Regenerate token', 'iot-data-widget' ), 'secondary' ); ?>
		</form>
	<?php endif; ?>

	<?php if ( $iotdw_has_token ) : ?>
		<h3><?php esc_html_e( 'Configuration snippet', 'iot-data-widget' ); ?></h3>

		<?php foreach ( $iotdw_platforms as $iotdw_platform_key => $iotdw_platform_label ) : ?>
			<?php $iotdw_platform_snippet = $snippets[ $iotdw_platform_key ] ?? null; ?>
			<?php if ( null === $iotdw_platform_snippet ) : ?>
				<?php continue; ?>
			<?php endif; ?>
			<details <?php echo wp_kses( $iotdw_platform_key === $push_type ? 'open' : '', array() ); ?>>
				<summary>
					<strong><?php echo esc_html( $iotdw_platform_snippet['label'] ); ?></strong>
					<?php if ( ! $iotdw_platform_snippet['verified'] ) : ?>
						<span class="iot-dw-admin-status--warning">
							<?php esc_html_e( '(not yet verified on real hardware)', 'iot-data-widget' ); ?>
						</span>
					<?php endif; ?>
				</summary>

				<?php foreach ( $iotdw_platform_snippet['blocks'] as $iotdw_block ) : ?>
					<p><strong><?php echo esc_html( $iotdw_block['title'] ); ?></strong></p>
					<pre class="iot-dw-wizard__snippet"><code><?php echo esc_html( $iotdw_block['code'] ); ?></code></pre>
				<?php endforeach; ?>

				<?php foreach ( $iotdw_platform_snippet['notes'] as $iotdw_note ) : ?>
					<p class="description"><?php echo esc_html( $iotdw_note ); ?></p>
				<?php endforeach; ?>
			</details>
		<?php endforeach; ?>
	<?php endif; ?>

	<h3><?php esc_html_e( 'Check if data is arriving', 'iot-data-widget' ); ?></h3>
	<p class="description">
		<?php esc_html_e( 'Paste the snippet into your device first, then click below — we\'ll watch for the first reading for about a minute.', 'iot-data-widget' ); ?>
	</p>
	<p>
		<button type="button" class="button button-hero" id="iot-dw-push-check-receiving">
			<?php esc_html_e( 'Check if data is arriving', 'iot-data-widget' ); ?>
		</button>
		<span id="iot-dw-push-check-receiving-result" role="status" aria-live="polite"></span>
	</p>

	<?php if ( ! empty( $entities ) ) : ?>
		<?php
		/**
		 * Zgłoszenie 2026-09-07 ("push-check-receiving stale table") —
		 * $entities to migawka server-side sprzed renderowania tej strony;
		 * jeśli urządzenie wyśle odczyt PO załadowaniu strony (typowy
		 * przypadek — użytkownik dopiero teraz wkleja snippet do urządzenia),
		 * tabela zostaje na zawsze przy "— no data yet —", mimo że dane już
		 * są w bazie i przycisk "Check if data is arriving" wyżej poprawnie
		 * je wykrywa. data-entity-row/data-entity-value/data-entity-time
		 * pozwalają admin/js/push-check-receiving.js zaktualizować tę samą
		 * tabelę z odpowiedzi tego samego endpointu statusu, zamiast
		 * pozostawiać ją zamrożoną — ten sam wzorzec co hybrydowe
		 * doładowanie na froncie (public/js/widget-refresh.js).
		 */
		?>
		<table class="widefat striped" style="max-width:600px" id="iot-dw-push-entities-table">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Entity', 'iot-data-widget' ); ?></th>
					<th><?php esc_html_e( 'Last value', 'iot-data-widget' ); ?></th>
					<th><?php esc_html_e( 'Last received', 'iot-data-widget' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $entities as $iotdw_entity ) : ?>
					<tr data-entity-row="<?php echo esc_attr( $iotdw_entity['entity_key'] ); ?>">
						<td><code><?php echo esc_html( $iotdw_entity['entity_key'] ); ?></code></td>
						<td data-entity-value>
							<?php
							if ( null === $iotdw_entity['last_recorded_at'] ) {
								esc_html_e( '— no data yet —', 'iot-data-widget' );
							} elseif ( null !== $iotdw_entity['last_value_txt'] ) {
								echo esc_html( $iotdw_entity['last_value_txt'] );
							} else {
								echo esc_html( $iotdw_entity['last_value_num'] . ( $iotdw_entity['unit'] ? ' ' . $iotdw_entity['unit'] : '' ) );
							}
							?>
						</td>
						<td data-entity-time><?php echo esc_html( $iotdw_entity['last_recorded_at'] ?? '—' ); ?></td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
	<?php endif; ?>

	<p>
		<a href="<?php echo esc_url( $iotdw_finish_url ); ?>" class="button button-primary">
			<?php esc_html_e( 'Go to Connections', 'iot-data-widget' ); ?>
		</a>
	</p>
</div>
