<?php
/**
 * Partial: spójna tabela listy (v1.7 Tydzień 3 toolkit UI) — połączenia,
 * reguły alertów, log błędów itd., zamiast każdej pisanej osobno. Reużywa
 * natywne klasy WP `.widefat.striped` (już dziś używane niezależnie od
 * siebie w kilku miejscach, patrz `docs/archiwum/audyt-ui-v1-7.md`), nie wymyśla
 * własnego wyglądu tabeli. Pusty `$iotdw_table_rows` deleguje do partiala
 * empty-state.php zamiast duplikować osobny wariant "brak danych".
 *
 * Komórka wiersza to ALBO zwykły string (escapowany tutaj przez esc_html()
 * — bezpieczne domyślnie), ALBO tablica `[ 'html' => '<już bezpieczny markup>' ]`
 * dla przypadków, gdzie komórka naprawdę potrzebuje czegoś więcej niż tekst
 * (kolorowy status, `<code>`, link Edit/Delete) — wywołujący bierze wtedy
 * pełną odpowiedzialność za escapowanie tej wartości PRZED przekazaniem tutaj.
 * Opcjonalny klucz `_row_class` w wierszu (nie renderowany jako komórka —
 * żadna kolumna nigdy się tak nie nazywa) dodaje klasę CSS do `<tr>`, np. do
 * wyróżnienia połączenia z persystentnymi błędami (v1.7 Tydzień 5).
 *
 * @package IoT_Data_Widget
 *
 * @var array<string,string>                    $iotdw_table_columns       Nagłówki: klucz kolumny => etykieta.
 * @var array<int,array<string,string|array{html:string}>> $iotdw_table_rows Wiersze: każdy to tablica [klucz kolumny => wartość komórki], plus opcjonalny '_row_class'. Brakujący klucz kolumny w wierszu renderuje pustą komórkę.
 * @var string                                   $iotdw_table_empty_message Komunikat, gdy $iotdw_table_rows jest puste (przekazywany dalej do empty-state.php).
 * @var string|null                              $iotdw_table_empty_cta_label CTA pustego stanu — patrz empty-state.php, opcjonalne.
 * @var string|null                              $iotdw_table_empty_cta_url   CTA pustego stanu — patrz empty-state.php, opcjonalne.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( empty( $iotdw_table_rows ) ) {
	$iotdw_empty_state_message   = $iotdw_table_empty_message;
	$iotdw_empty_state_cta_label = $iotdw_table_empty_cta_label ?? null;
	$iotdw_empty_state_cta_url   = $iotdw_table_empty_cta_url ?? null;

	require __DIR__ . '/empty-state.php';

	return;
}
?>
<table class="widefat striped">
	<thead>
		<tr>
			<?php foreach ( $iotdw_table_columns as $iotdw_column_label ) : ?>
				<th><?php echo esc_html( $iotdw_column_label ); ?></th>
			<?php endforeach; ?>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $iotdw_table_rows as $iotdw_table_row ) : ?>
			<tr<?php echo isset( $iotdw_table_row['_row_class'] ) ? ' class="' . esc_attr( $iotdw_table_row['_row_class'] ) . '"' : ''; ?>>
				<?php foreach ( $iotdw_table_columns as $iotdw_column_key => $iotdw_column_label ) : ?>
					<td>
						<?php
						$iotdw_cell = $iotdw_table_row[ $iotdw_column_key ] ?? '';

						if ( is_array( $iotdw_cell ) ) {
							// Zgłoszenie recenzji wp.org (Runda 2, T2, 2026-09-27) —
							// "już escapowane przez wywołującego" nie wystarcza,
							// wymagana faktyczna funkcja escapująca DOKŁADNIE w
							// miejscu echo. Dozwolona lista pokrywa dokładnie to,
							// co wywołujący faktycznie tu wstawiają: <code>, <span>/
							// <strong> statusu, i formularz "Usuń" z partiala
							// delete-form.php (a/form/input/button, w tym onclick —
							// jedyny dynamiczny fragment tego atrybutu to
							// esc_js( $iotdw_delete_confirm ), stały, zdefiniowany przez
							// wtyczkę tekst tłumaczenia, nie dane użytkownika).
							echo wp_kses(
								$iotdw_cell['html'] ?? '',
								array(
									'code'   => array(),
									'span'   => array( 'class' => true ),
									'strong' => array( 'class' => true ),
									'a'      => array( 'href' => true ),
									'form'   => array(
										'method' => true,
										'action' => true,
										'style'  => true,
									),
									'input'  => array(
										'type'  => true,
										'name'  => true,
										'value' => true,
									),
									'button' => array(
										'type'    => true,
										'class'   => true,
										'onclick' => true,
									),
								)
							);
						} else {
							echo esc_html( (string) $iotdw_cell );
						}
						?>
					</td>
				<?php endforeach; ?>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
