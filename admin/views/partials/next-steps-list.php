<?php
/**
 * Partial: lista "co dalej" (v1.8, code review) — wspólna dla dismissible
 * checklisty na stronie Connections (IOTDW_Admin_Page::next_steps_links())
 * i identycznej listy na ekranie końcowym kreatora (Krok 5). Ta sama
 * struktura `<li>` była duplikowana bajt w bajt w obu widokach — różni się
 * tylko OPAKOWANIE (dismissible notice z nagłówkiem vs zwykły `<h2>`), które
 * zostaje w każdym pliku wywołującym osobno.
 *
 * @package IoT_Data_Widget
 *
 * @var array<int, array{label: string, description: string, url: string}> $next_steps       Linki "kolejne kroki".
 * @var string                                                              $iotdw_next_steps_class Klasa(y) na znaczniku `<ul>`, opcjonalne.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_next_steps_class = $iotdw_next_steps_class ?? '';
?>
<ul<?php echo '' !== $iotdw_next_steps_class ? ' class="' . esc_attr( $iotdw_next_steps_class ) . '"' : ''; ?>>
	<?php foreach ( $next_steps as $iotdw_step ) : ?>
		<li>
			<a href="<?php echo esc_url( $iotdw_step['url'] ); ?>"><strong><?php echo esc_html( $iotdw_step['label'] ); ?></strong></a>
			— <?php echo esc_html( $iotdw_step['description'] ); ?>
		</li>
	<?php endforeach; ?>
</ul>
