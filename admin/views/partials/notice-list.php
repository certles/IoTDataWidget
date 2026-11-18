<?php
/**
 * Partial: notatka błędu z listą pozycji (pogrubiony nagłówek + `<ul>`) —
 * np. błędy walidacji formularza albo lista pominiętych/nieudanych wpisów
 * importu. Wydzielone z sześciu niemal identycznych kopii w
 * connector-form.php, alert-rule-form.php, email-report-form.php,
 * general-settings-page.php (x2) i wizard/step-2-configure.php (code
 * review, docs/archiwum/poprawki-ogolne.md) — ten sam duch co partials/notice.php
 * dla pojedynczego komunikatu, ale dla listy pozycji zamiast jednego zdania.
 * Wywołujący sam sprawdza `! empty( $items )` przed require — ten partial
 * zawsze renderuje (pusta lista i tak nie powinna się zdarzyć w praktyce).
 *
 * @package IoT_Data_Widget
 *
 * @var string   $iotdw_notice_list_heading Pogrubiony nagłówek nad listą (już przetłumaczony).
 * @var string[] $iotdw_notice_list_items   Pozycje listy (zwykły tekst, escapowany wewnątrz partiala).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}
?>
<div class="notice notice-error">
	<p><strong><?php echo esc_html( $iotdw_notice_list_heading ); ?></strong></p>
	<ul class="iot-dw-admin-notice-list">
		<?php foreach ( $iotdw_notice_list_items as $iotdw_notice_list_item ) : ?>
			<li><?php echo esc_html( $iotdw_notice_list_item ); ?></li>
		<?php endforeach; ?>
	</ul>
</div>
