<?php
/**
 * Interfejs dla konektorów wspierających przeglądanie pełnej listy encji ze
 * źródła danych (v1.3 Tydzień 2) — w przeciwieństwie do
 * IOTDW_Abstract_Connector::list_entities() (zawsze dostępna, domyślnie
 * pusta, każdy konektor ją ma), ten interfejs to jawny sygnał "ta lista jest
 * kompletną, przeszukiwalną listą encji ze źródła" (dziś: tylko Home
 * Assistant, GET /api/states), nie tylko "kilka znanych metryk presetu".
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface IOTDW_Listable_Connector_Interface
 */
interface IOTDW_Listable_Connector_Interface {

	/**
	 * Zwraca pełną listę encji ze źródła danych. Pusta lista jest prawidłowym
	 * wynikiem (źródło po prostu nie ma jeszcze żadnych encji) — błąd
	 * połączenia/autoryzacji sygnalizowany jest przez wyjątek, NIE przez
	 * cichy zwrot pustej tablicy, żeby UI przeglądarki encji (v1.3 Tydzień 3)
	 * mogło pokazać czytelny komunikat zamiast milczącej pustej listy.
	 *
	 * @throws \RuntimeException Gdy pobranie listy się nie powiedzie (błąd sieci, autoryzacji, nieprawidłowa odpowiedź).
	 * @return array<int, array{id: string, label: string, domain: string, current_state: string}>
	 */
	public function list_entities(): array;
}
