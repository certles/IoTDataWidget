<?php
/**
 * Partial: pusty stan listy (v1.7 Tydzień 3 toolkit UI) — ikona (opcjonalnie)
 * + tekst + CTA (opcjonalnie), zamiast każdego ekranu piszącego własny
 * wariant "Brak X" osobno. Audyt z Tygodnia 1 (`docs/archiwum/audyt-ui-v1-7.md`)
 * znalazł, że tekst pustych stanów jest już bardzo spójny (identyczny
 * wzorzec "No X configured yet." w 6/6 miejsc) — realna luka to brak
 * osadzonego przycisku CTA, którą ten partial rozwiązuje jako opcję, nie
 * wymóg (część ekranów ma formularz dodawania już widoczny niżej na tej
 * samej stronie i nie potrzebuje osobnego CTA).
 *
 * @package IoT_Data_Widget
 *
 * @var string      $iotdw_empty_state_message   Główny tekst (zwykły, escapowany wewnątrz partiala).
 * @var string|null $iotdw_empty_state_icon      Klasa dashicon (np. 'dashicons-admin-plugins'), albo null/pominięte — bez ikony.
 * @var string|null $iotdw_empty_state_cta_label Etykieta przycisku CTA, albo null/pominięte — bez przycisku.
 * @var string|null $iotdw_empty_state_cta_url   Adres URL przycisku CTA — wymagany, gdy podano $iotdw_empty_state_cta_label.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_empty_state_icon      = $iotdw_empty_state_icon ?? null;
$iotdw_empty_state_cta_label = $iotdw_empty_state_cta_label ?? null;
$iotdw_empty_state_cta_url   = $iotdw_empty_state_cta_url ?? null;
?>
<div class="iot-dw-admin-empty-state">
	<?php if ( null !== $iotdw_empty_state_icon ) : ?>
		<span class="dashicons <?php echo esc_attr( $iotdw_empty_state_icon ); ?> iot-dw-admin-empty-state__icon" aria-hidden="true"></span>
	<?php endif; ?>
	<p class="iot-dw-admin-empty-state__message"><?php echo esc_html( $iotdw_empty_state_message ); ?></p>
	<?php if ( null !== $iotdw_empty_state_cta_label && null !== $iotdw_empty_state_cta_url ) : ?>
		<p>
			<a href="<?php echo esc_url( $iotdw_empty_state_cta_url ); ?>" class="button button-primary">
				<?php echo esc_html( $iotdw_empty_state_cta_label ); ?>
			</a>
		</p>
	<?php endif; ?>
</div>
