<?php
/**
 * Widok: Krok 3 kreatora — wybór pierwszej encji (v1.4 Tydzień 5). Dwie
 * różne ścieżki dalej, zależnie od $iotdw_connection (kreator multi-entity, v1.9
 * Tydzień 3-4, docs/archiwum/poprawka-kreator-multi-entity.md):
 *
 * - Listable (HA): checkboxy (dowolna liczba), formularz submituje POST do
 *   admin-post.php?action=IOTDW_Setup_Wizard::SAVE_ENTITIES_ACTION — zapisuje
 *   zaznaczone encje do iotdw_saved_entities (IOTDW_Setup_Wizard::handle_save_entities()),
 *   potem przekierowuje do Kroku 4. Stąd nonce — to jedyny krok kreatora
 *   powyżej Kroku 2, który faktycznie zapisuje coś do bazy.
 * - Pozostałe (presety/wolny wpis): bez zmian względem v1.4 — pojedyncza
 *   encja, zwykły <form method="get"> bez nonce'a (czysta nawigacja, nic się
 *   nie zapisuje), wędruje dalej do Kroku 4 przez parametr 'entity' w URL.
 *
 * @package IoT_Data_Widget
 *
 * @var string                                                                                  $iotdw_type_key           Wybrany klucz typu z Kroku 1 (do linku "Back").
 * @var array<string, string>                                                                    $iotdw_connection         Zapisane połączenie (Krok 2) — decyduje o trybie wyboru (HA/preset/wolny wpis), nie $iotdw_type_key.
 * @var array<string, array{json_path: string, unit: string, label: string, value_type: string}> $metrics             Metryki presetu (puste dla HA/zaawansowanych/MQTT).
 * @var string                                                                                    $manual_label       Etykieta pola wolnego wpisu (gdy brak przeglądarki/listy).
 * @var string                                                                                    $manual_placeholder Placeholder pola wolnego wpisu.
 * @var bool                                                                                      $iotdw_entity_error Komunikat o odrzuconym ID encji (MAINT-2).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_is_ha           = 'home_assistant' === ( $iotdw_connection['connector'] ?? '' );
$iotdw_has_preset_list = ! empty( $metrics );
$iotdw_action_url      = admin_url( 'admin.php' );
$iotdw_back_url        = add_query_arg(
	array(
		'page'       => IOTDW_Setup_Wizard::PAGE_SLUG,
		'step'       => 2,
		'type'       => $iotdw_type_key,
		'connection' => $iotdw_connection['id'],
	),
	$iotdw_action_url
);
$iotdw_skip_url        = admin_url( 'admin.php?page=iot-data-widget' );
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2><?php esc_html_e( 'What do you want to display?', 'iot-data-widget' ); ?></h2>

	<?php
	// MAINT-2: odrzucone ID encji (patrz IOTDW_Setup_Wizard::sanitize_entity_input()).
	if ( ! empty( $iotdw_entity_error ) ) {
		$iotdw_notice_type        = 'error';
		$iotdw_notice_message     = __( 'The entity ID contains characters that are not allowed. Do not use a percent sign followed by two letters or digits (for example %ab), HTML tags or line breaks. Correct the ID and try again.', 'iot-data-widget' );
		$iotdw_notice_dismissible = false;
		require IOTDW_PLUGIN_DIR . 'admin/views/partials/notice.php';
	}
	?>

	<?php if ( $iotdw_is_ha ) : ?>
		<p><?php esc_html_e( 'Connection saved. Now choose one or more entities for your first widgets.', 'iot-data-widget' ); ?></p>

		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" id="iot-dw-wizard-step3-form">
			<input type="hidden" name="action" value="<?php echo esc_attr( IOTDW_Setup_Wizard::SAVE_ENTITIES_ACTION ); ?>" />
			<input type="hidden" name="type" value="<?php echo esc_attr( $iotdw_type_key ); ?>" />
			<input type="hidden" name="connection" value="<?php echo esc_attr( $iotdw_connection['id'] ); ?>" />
			<input type="hidden" name="entities_json" id="iotdw_wizard_entities_json" value="[]" />
			<?php wp_nonce_field( IOTDW_Setup_Wizard::SAVE_ENTITIES_ACTION ); ?>

			<p>
				<label for="iotdw_wizard_entity_search"><?php esc_html_e( 'Search', 'iot-data-widget' ); ?></label>
				<input
					type="text"
					id="iotdw_wizard_entity_search"
					class="regular-text"
					placeholder="<?php esc_attr_e( 'e.g. temperature', 'iot-data-widget' ); ?>"
				/>
				<label for="iotdw_wizard_entity_domain"><?php esc_html_e( 'Domain', 'iot-data-widget' ); ?></label>
				<select id="iotdw_wizard_entity_domain">
					<option value=""><?php esc_html_e( 'All domains', 'iot-data-widget' ); ?></option>
				</select>
			</p>

			<fieldset>
				<legend class="screen-reader-text"><?php esc_html_e( 'Entities', 'iot-data-widget' ); ?></legend>
				<div
					id="iotdw_wizard_entity_list"
					class="iot-dw-wizard__entity-list"
					data-connection-id="<?php echo esc_attr( $iotdw_connection['id'] ); ?>"
				></div>
			</fieldset>
			<p class="description" id="iotdw_wizard_entity_status">
				<?php esc_html_e( 'Loading entities…', 'iot-data-widget' ); ?>
			</p>
			<p>
				<strong id="iotdw_wizard_entity_count">
					<?php
					echo esc_html(
						sprintf(
							/* translators: %d: number of selected entities */
							__( '%d selected', 'iot-data-widget' ),
							0
						)
					);
					?>
				</strong>
			</p>

			<p>
				<?php
				submit_button(
					__( 'Continue', 'iot-data-widget' ),
					'primary',
					'submit',
					false,
					array(
						'id'       => 'iot-dw-wizard-step3-continue',
						'disabled' => 'disabled',
					)
				);
				?>
			</p>
		</form>
	<?php else : ?>
		<p><?php esc_html_e( 'Connection saved. Now choose the entity for your first widget.', 'iot-data-widget' ); ?></p>

		<form method="get" action="<?php echo esc_url( $iotdw_action_url ); ?>">
			<input type="hidden" name="page" value="<?php echo esc_attr( IOTDW_Setup_Wizard::PAGE_SLUG ); ?>" />
			<input type="hidden" name="step" value="4" />
			<input type="hidden" name="type" value="<?php echo esc_attr( $iotdw_type_key ); ?>" />
			<input type="hidden" name="connection" value="<?php echo esc_attr( $iotdw_connection['id'] ); ?>" />

			<?php if ( $iotdw_has_preset_list ) : ?>
				<p>
					<label for="iotdw_wizard_entity_select"><?php esc_html_e( 'Metric', 'iot-data-widget' ); ?></label><br />
					<select id="iotdw_wizard_entity_select" name="entity" required>
						<?php foreach ( $metrics as $iotdw_metric_key => $iotdw_metric ) : ?>
							<option value="<?php echo esc_attr( $iotdw_metric_key ); ?>"><?php echo esc_html( $iotdw_metric['label'] ); ?></option>
						<?php endforeach; ?>
					</select>
				</p>
			<?php else : ?>
				<p>
					<label for="iotdw_wizard_entity_manual"><?php echo esc_html( $manual_label ); ?></label><br />
					<input
						type="text"
						id="iotdw_wizard_entity_manual"
						name="entity"
						class="regular-text"
						placeholder="<?php echo esc_attr( $manual_placeholder ); ?>"
						required
					/>
				</p>
			<?php endif; ?>

			<p>
				<?php submit_button( __( 'Continue', 'iot-data-widget' ), 'primary', 'submit', false ); ?>
			</p>
		</form>
	<?php endif; ?>

	<p>
		<a href="<?php echo esc_url( $iotdw_back_url ); ?>">&larr; <?php esc_html_e( 'Back', 'iot-data-widget' ); ?></a>
		&nbsp;|&nbsp;
		<a href="<?php echo esc_url( $iotdw_skip_url ); ?>"><?php esc_html_e( "Skip the wizard, I'll configure manually", 'iot-data-widget' ); ?></a>
	</p>
</div>
