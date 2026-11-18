<?php
/**
 * Warstwa cache — wrapper na WP Transients API.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Cache
 *
 * Cache jest zawsze aktywny — bez opcji wyłączenia w MVP, żeby użytkownicy
 * nie zabijali requestami swojej instancji Home Assistant / API.
 */
class IOTDW_Cache {

	/**
	 * Domyślny czas życia wpisu w cache (sekundy).
	 */
	const DEFAULT_TTL = 60;

	/**
	 * Czas życia wpisu, gdy $callback zwróci błąd (niepusty klucz 'error') —
	 * celowo krótszy niż DEFAULT_TTL. Bez tego chwilowa awaria źródła danych
	 * (kilkusekundowy network blip) "zamrażała się" w cache na pełny TTL —
	 * widoczny błąd u gościa trwał dłużej niż faktyczna awaria, bo cache nie
	 * odświeżał się przed wygaśnięciem nawet gdyby ponowna próba już się
	 * powiodła. Znalezione przy pierwszym pełnym audycie (docs/archiwum/review-log.md).
	 * Wystarczająco krótkie, żeby błąd nie "wisiał" długo po ustąpieniu
	 * przyczyny, wystarczająco długie, żeby nie zabić API powtarzającymi się
	 * requestami przy realnej, dłuższej awarii.
	 */
	const ERROR_TTL = 10;

	/**
	 * Czas życia "blokady odświeżania" (poprawka 2.3, docs/archiwum/poprawki-ogolne.md)
	 * — ustawiana TUŻ PRZED wywołaniem $callback() (realny request do
	 * zewnętrznego API) i kasowana zaraz po. Kolejne równoległe żądania dla
	 * TEJ SAMEJ pary, trafiające w to okno, serwują ostatnią znaną wartość
	 * (patrz LAST_KNOWN_TTL) zamiast dublować ten sam request. Wartość dość
	 * duża względem timeoutu konektorów (5s, patrz CLAUDE.md), żeby zdążyć
	 * pokryć normalny czas odpowiedzi + narzut, dość mała, żeby pojedynczy
	 * zawieszony/przerwany request (proces zabity w trakcie, blokada nigdy
	 * nie skasowana normalnie) nie blokował odświeżania na długo — po
	 * wygaśnięciu blokada po prostu przestaje istnieć, żadnego ręcznego
	 * czyszczenia.
	 */
	const LOCK_TTL = 10;

	/**
	 * Czas życia "ostatniej znanej wartości" (poprawka 2.3) — świadomie
	 * znacznie dłuższy niż DEFAULT_TTL. To NIE jest normalny cache (ten
	 * zostaje bez zmian, patrz $ttl/$effective_ttl niżej) — to wyłącznie
	 * awaryjne źródło na czas trwania równoległego odświeżania (LOCK_TTL),
	 * żeby mieć czym nakarmić "kolejkujące się" żądania zamiast ich completely
	 * odrzucać. Nigdy nie jest jedynym źródłem prawdy poza tym oknem —
	 * normalny cache-miss (bez aktywnej blokady) zawsze woła $callback()
	 * normalnie, ignorując tę wartość.
	 */
	const LAST_KNOWN_TTL = HOUR_IN_SECONDS;

	/**
	 * Górny pułap wykładniczego backoffu błędów (zgłoszenie 2026-08-31,
	 * "P1#8" — urządzenie z Tasmotą kilkukrotnie nie odpowiadało, raz przez
	 * sprzętowy watchdog restart z braku pamięci). Bez pułapu trwale martwe
	 * połączenie odpytywane byłoby coraz rzadziej bez końca — 5 minut to
	 * rozsądny kompromis: dość rzadko, żeby nie dobijać restartującego się
	 * urządzenia, dość często, żeby powrót został zauważony w rozsądnym czasie.
	 */
	const MAX_ERROR_BACKOFF_TTL = 5 * MINUTE_IN_SECONDS;

	/**
	 * Zwraca dane z cache, a gdy ich brak (lub wygasły) — pobiera je przez
	 * $callback i zapisuje w cache na $ttl sekund ($error_ttl, jeśli wynik
	 * zawiera niepusty klucz 'error' — patrz ERROR_TTL).
	 *
	 * Ochrona przed cache stampede (poprawka 2.3, docs/archiwum/poprawki-ogolne.md):
	 * na ruchliwej stronie moment wygaśnięcia cache mógłby wywołać kilka
	 * jednoczesnych żądań od różnych odwiedzających naraz, każde trafiające
	 * w cache-miss i każde dublujące ten sam realny request do zewnętrznego
	 * API (HA/Shelly Cloud/etc) — niepotrzebne obciążenie infrastruktury
	 * użytkownika końcowego. Prosty transient-owy "zamek": pierwsze żądanie w
	 * oknie cache-miss ustawia blokadę i faktycznie odpytuje $callback();
	 * każde KOLEJNE równoległe żądanie, które zastanie tę blokadę, dostaje
	 * ostatnią znaną wartość (nawet nieco nieświeżą) zamiast też odpytywać.
	 * Zwykły transient (get-potem-set, nie atomowa blokada na poziomie bazy)
	 * jest tu świadomie wystarczający — to optymalizacja "zredukuj duplikaty
	 * o rząd wielkości", nie sekcja krytyczna wymagająca twardej gwarancji
	 * "dokładnie jeden": rzadkie, wąskie okno wyścigu (dwa żądania czytają
	 * "brak blokady" tuż przed sobą) w najgorszym razie wpuszcza 2 requesty
	 * zamiast 1, wciąż drastycznie lepiej niż N bez żadnej ochrony.
	 *
	 * @param string   $key           Unikalny klucz (np. id połączenia . entity_id).
	 * @param callable $callback      Funkcja zwracająca znormalizowaną strukturę danych.
	 * @param int      $ttl           Czas życia w sekundach dla udanej odpowiedzi.
	 * @param int      $error_ttl     BAZOWY (pierwsza kolejna awaria) czas życia w sekundach dla odpowiedzi z błędem — patrz error_backoff_ttl(), realny czas rośnie wykładniczo z kolejnymi awariami tego samego połączenia.
	 * @param string   $connection_id ID połączenia (v1.6 Tydzień 1) — gdy podane, wynik cache-miss loguje się do IOTDW_Diagnostics_Logger (status połączenia + log błędów) I steruje wykładniczym backoffem (patrz error_backoff_ttl()). Puste = brak logowania/backoffu — celowo dla wywołań spoza kontekstu jednego połączenia (np. lista encji, test przed zapisem z kreatora), patrz Ustalenie #1 w docs/archiwum/mvp-v1-6-plan.md.
	 * @return array
	 */
	public function remember( string $key, callable $callback, int $ttl = self::DEFAULT_TTL, int $error_ttl = self::ERROR_TTL, string $connection_id = '' ): array {
		$cache_key = $this->build_key( $key );
		$cached    = get_transient( $cache_key );

		if ( false !== $cached ) {
			return $cached;
		}

		$lock_key = $cache_key . '_lock';
		$last_key = $cache_key . '_last';

		if ( false !== get_transient( $lock_key ) ) {
			$last_known = get_transient( $last_key );

			// Brak nawet nieświeżej wartości (pierwsze zapytanie w ogóle dla
			// tej pary, złapane dokładnie w oknie czyjegoś równoległego
			// pierwszego odpytania) — nie ma czym nakarmić, więc mimo
			// aktywnej blokady i tak trzeba spróbować prawdziwego requestu.
			// Rzadki przypadek, tylko przy zupełnie pierwszym użyciu pary.
			if ( false !== $last_known ) {
				return $last_known;
			}
		}

		set_transient( $lock_key, 1, self::LOCK_TTL );

		try {
			$data = $callback();
		} finally {
			delete_transient( $lock_key );
		}

		$is_error   = is_array( $data ) && ! empty( $data['error'] );
		$last_known = get_transient( $last_key );

		if ( $is_error ) {
			$effective_ttl = self::error_backoff_ttl( $error_ttl, $connection_id );

			// Poprawka 2026-08-31 ("P1#8", zgłoszenie o urządzeniu Tasmota
			// które przestawało odpowiadać, m.in. przez sprzętowy watchdog
			// restart): jeśli mamy jakąkolwiek wcześniejszą DOBRĄ wartość,
			// pokazujemy JĄ zamiast surowego błędu — IOTDW_Renderer::is_stale()
			// i tak wyszarzy/oznaczy ją znacznikiem czasu, gdy stanie się
			// wystarczająco stara (próg konfigurowalny w Ustawieniach), więc
			// gość i tak widzi uczciwą informację "dane mogą być
			// nieaktualne", zamiast nagłego, brzydkiego komunikatu błędu przy
			// pierwszej chwilowej awarii. Diagnostyka (record_connection_result()
			// niżej) dostaje mimo to PRAWDZIWY $data z błędem — administrator
			// nadal widzi realny stan połączenia, tylko odwiedzający strony
			// dostają łagodniejszą degradację.
			$return_data = false !== $last_known ? $last_known : $data;
		} else {
			$effective_ttl = $ttl;
			$return_data   = $data;
		}

		set_transient( $cache_key, $return_data, max( 1, $effective_ttl ) );

		// Tylko udane odpowiedzi karmią "ostatnią znaną wartość" — błąd nie
		// powinien nigdy STAĆ SIĘ tym, co stampede-fallback pokazuje
		// bystanderom; lepiej zostawić im poprzednią, prawdziwą wartość (jeśli
		// jakaś jest) niż podmienić ją na świeży błąd.
		if ( ! $is_error ) {
			set_transient( $last_key, $data, self::LAST_KNOWN_TTL );
		}

		if ( '' !== $connection_id && is_array( $data ) ) {
			IOTDW_Diagnostics_Logger::record_connection_result( $connection_id, $data );
		}

		return $return_data;
	}

	/**
	 * Wykładniczy backoff czasu cache'owania błędu (zgłoszenie 2026-08-31,
	 * "P1#8") — im więcej KOLEJNYCH awarii pod rząd to samo połączenie już
	 * miało, tym rzadziej próbujemy ponownie: 10s / 20s / 40s / 80s / 160s /
	 * … aż do pułapu MAX_ERROR_BACKOFF_TTL (5 min). Odczytuje licznik
	 * SPRZED tego odczytu (IOTDW_Diagnostics_Logger::record_connection_result(),
	 * wołane PO tej metodzie, dopiero zwiększy go o 1 dla TEGO niepowodzenia)
	 * — stąd "+1" w formule: liczymy TĘ awarię, nie tylko poprzednie.
	 * Puste $connection_id (wywołania spoza kontekstu jednego połączenia,
	 * patrz docblock remember()) = brak historii do policzenia, zawsze
	 * bazowy $base_error_ttl.
	 *
	 * @param int    $base_error_ttl Bazowy czas życia błędu (pierwsza awaria).
	 * @param string $connection_id  ID połączenia.
	 * @return int
	 */
	private static function error_backoff_ttl( int $base_error_ttl, string $connection_id ): int {
		if ( '' === $connection_id ) {
			return $base_error_ttl;
		}

		$failure_number = IOTDW_Diagnostics_Logger::get_consecutive_errors( $connection_id ) + 1;
		// Pułap wykładnika (nie tylko końcowej wartości) — bez niego
		// 2 ** $failure_number dla trwale martwego połączenia po kilkuset
		// nieudanych próbach przekroczyłoby zakres int/float na długo przed
		// tym, jak min() zdążyłby to przyciąć z powrotem do MAX_ERROR_BACKOFF_TTL.
		$backoff_ttl = $base_error_ttl * ( 2 ** min( $failure_number - 1, 10 ) );

		return min( $backoff_ttl, self::MAX_ERROR_BACKOFF_TTL );
	}

	/**
	 * Usuwa wpis z cache przed jego naturalnym wygaśnięciem — np. dla
	 * parametru `?refresh=1` przeglądarki encji (v1.3 Tydzień 2), gdzie
	 * użytkownik świadomie chce pominąć dłuższy TTL zamiast czekać na jego
	 * wygaśnięcie.
	 *
	 * @param string $key Klucz bazowy (ten sam, co przekazany do remember()).
	 */
	public function forget( string $key ): void {
		delete_transient( $this->build_key( $key ) );
	}

	/**
	 * Buduje klucz transienta z prefiksem wtyczki. Hashowanie trzyma klucz
	 * w limicie długości nazwy opcji WP niezależnie od długości wejścia.
	 *
	 * @param string $key Klucz bazowy.
	 * @return string
	 */
	private function build_key( string $key ): string {
		return 'iotdw_' . md5( $key );
	}
}
