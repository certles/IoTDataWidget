<?php
/**
 * Partial: komunikat admina (sukces/błąd/ostrzeżenie/info) — v1.7 Tydzień 3
 * toolkit UI (docs/archiwum/mvp-v1-7-plan.md, docs/archiwum/audyt-ui-v1-7.md). Owija WYŁĄCZNIE
 * standardowe klasy WP (`.notice.notice-{type}`, natywny `.is-dismissible`)
 * — audyt z Tygodnia 1 znalazł miejsca z ręcznie doklejonym, niestandardowym
 * `style=""` do tego samego efektu (patrz `settings-page.php`, checklist
 * "next steps") zamiast polegać na tym, co WP już oferuje za darmo.
 *
 * Nie obejmuje trwałego (server-side) dismissu przez URL — to inny
 * mechanizm niż natywny `.is-dismissible` (czysto JS, do następnego
 * przeładowania) i celowo zostaje rozwiązywany osobno tam, gdzie jest
 * naprawdę potrzebny (np. checklista "next steps"), żeby ten partial
 * pokrywał wspólny, prosty przypadek bez rozrostu API.
 *
 * @package IoT_Data_Widget
 *
 * @var string $iotdw_notice_type        Jedno z: 'success', 'error', 'warning', 'info'.
 * @var string $iotdw_notice_message     Treść komunikatu (zwykły tekst, escapowany wewnątrz partiala).
 * @var bool   $iotdw_notice_dismissible Czy dodać natywny, klikalny "x" WP (JS, chowa do następnego przeładowania). Domyślnie false.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_notice_dismissible = $iotdw_notice_dismissible ?? false;

$iotdw_notice_classes = array( 'notice', 'notice-' . $iotdw_notice_type );

if ( $iotdw_notice_dismissible ) {
	$iotdw_notice_classes[] = 'is-dismissible';
}
?>
<div class="<?php echo esc_attr( implode( ' ', $iotdw_notice_classes ) ); ?>">
	<p><?php echo esc_html( $iotdw_notice_message ); ?></p>
</div>
