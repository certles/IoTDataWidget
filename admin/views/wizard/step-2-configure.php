<?php
/**
 * Widok: Krok 2 kreatora — konfiguracja połączenia + test na żywo (v1.4
 * Tydzień 4), teraz z prawdziwym zapisem (Tydzień 5). Reużywa te same pola
 * co zwykła strona dodawania połączenia
 * (admin/views/partials/connector-fields.php, admin/js/connector-form.js,
 * admin/js/test-connection.js) — patrz komentarz klasy IOTDW_Setup_Wizard.
 * Formularz submituje się do IOTDW_Admin_Page::handle_save() — ten sam
 * handler co zwykła strona połączeń, patrz jej docblock ("jeden endpoint
 * zapisu, dwa miejsca użycia"). Przycisk "Continue" startuje zablokowany —
 * odblokowuje go dopiero udany test (admin/js/test-connection.js), zgodnie
 * z Ustaleniem #1 planu: połączenie zapisuje się dopiero po udanym teście.
 *
 * @package IoT_Data_Widget
 *
 * @var array{label: string, connector: string, preset: string} $type            Wybrany typ z Kroku 1.
 * @var array<string, string>                                    $editing         Wstępne wartości pól (nowe połączenie z Kroku 1, powrót z Kroku 3, albo dane z nieudanej poprzedniej próby).
 * @var string[]                                                 $errors          Błędy walidacji z poprzedniej próby zapisu.
 * @var array<string, string>                                    $connector_types Dostępne typy konektorów [typ => etykieta].
 * @var string                                                    $group_key       Grupa typu (tylko dla grup wielotypowych — patrz find_group_key_for_type()), pusty string inaczej.
 * @var string                                                    $mode            'pull'/'push'/'' — zgłoszenie 2026-09-07: bez tego "Back" gubił kontekst Kroku 0 i wracał tam zamiast na Krok 1.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_back_url = add_query_arg(
	array_filter(
		array(
			'mode'  => $mode,
			'group' => $group_key,
		)
	),
	admin_url( 'admin.php?page=' . IOTDW_Setup_Wizard::PAGE_SLUG )
);
$iotdw_skip_url = admin_url( 'admin.php?page=iot-data-widget' );
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2>
		<?php
		printf(
			/* translators: %s: nazwa wybranego typu połączenia, np. "Shelly (Gen1)" */
			esc_html__( 'Configure %s', 'iot-data-widget' ),
			esc_html( $type['label'] )
		);
		?>
	</h2>

	<?php if ( ! empty( $errors ) ) : ?>
		<?php
		$iotdw_notice_list_heading = __( 'Please fix the following before continuing:', 'iot-data-widget' );
		$iotdw_notice_list_items   = $errors;
		require IOTDW_PLUGIN_DIR . 'admin/views/partials/notice-list.php';
		?>
	<?php endif; ?>

	<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
		<input type="hidden" name="action" value="iotdw_save_connection" />
		<input type="hidden" name="id" value="<?php echo esc_attr( $editing['id'] ?? '' ); ?>" />
		<input type="hidden" name="iotdw_wizard_type" value="<?php echo esc_attr( $iotdw_type_key ); ?>" />
		<?php wp_nonce_field( 'iotdw_save_connection' ); ?>

		<?php require IOTDW_PLUGIN_DIR . 'admin/views/partials/connector-fields.php'; ?>

		<p>
			<?php
			submit_button(
				__( 'Continue', 'iot-data-widget' ),
				'primary',
				'submit',
				false,
				array(
					'id'       => 'iot-dw-wizard-continue',
					'disabled' => 'disabled',
				)
			);
			?>
			<span class="description"><?php esc_html_e( 'Test the connection first — the button unlocks automatically once the test passes (or, for MQTT, once it explains what happens next).', 'iot-data-widget' ); ?></span>
		</p>
	</form>

	<p>
		<a href="<?php echo esc_url( $iotdw_back_url ); ?>">&larr; <?php esc_html_e( 'Back', 'iot-data-widget' ); ?></a>
		&nbsp;|&nbsp;
		<a href="<?php echo esc_url( $iotdw_skip_url ); ?>"><?php esc_html_e( "Skip the wizard, I'll configure manually", 'iot-data-widget' ); ?></a>
	</p>
</div>
