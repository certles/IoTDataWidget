<?php
/**
 * Bazowa klasa dla konektorów danych.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Abstract_Connector
 *
 * Każdy konektor zwraca wyłącznie znormalizowaną strukturę — renderer i cache
 * layer nigdy nie widzą surowej odpowiedzi API źródła danych.
 */
abstract class IOTDW_Abstract_Connector {

	/**
	 * Timeout (sekundy) dla KAŻDEGO wp_remote_get()/wp_remote_post() w
	 * konektorach robiących synchroniczne żądanie HTTP podczas renderowania
	 * strony (REST, Home Assistant, Shelly Cloud — nie push/MQTT, które
	 * czytają z lokalnego magazynu, patrz ich komentarze klas). Zmniejszone
	 * z 5s na 3s (zgłoszenie 2026-08-31, "P1#8": "widget nie może wydłużać
	 * ładowania odwiedzającym") — to wciąż górny pułap na PRAWDOPODOBIEŃSTWO
	 * zauważalnego opóźnienia strony przy zawieszonym/nieodpowiadającym
	 * urządzeniu (np. Tasmota po watchdog reset), nie normalny czas
	 * odpowiedzi (lokalne urządzenia IoT odpowiadają typowo w
	 * dziesiątkach/setkach milisekund).
	 */
	const HTTP_TIMEOUT_SECONDS = 3;

	/**
	 * Pobiera i normalizuje dane encji ze źródła. 'value' musi zawsze być
	 * liczbą (jedyne pole zapisywane do historii, DECIMAL(12,4)) —
	 * konektory zwracające stany tekstowe (np. HA "on"/"off") normalizują
	 * je przez IOTDW_Value_Normalizer i zwracają czytelny tekst w
	 * opcjonalnym 'display_value', pokazywanym zamiast value+unit w
	 * stylach karta/lista. Patrz CLAUDE.md, sekcja "Kontrakt danych konektora".
	 *
	 * @param string $entity_id Identyfikator encji u źródła danych.
	 * @return array{value: mixed, unit: string|null, label: string|null, last_updated: string|null, error: string|null, display_value?: string|null}
	 */
	abstract public function fetch_data( string $entity_id ): array;

	/**
	 * Zwraca listę dostępnych encji — opcjonalna zdolność, do autouzupełniania
	 * w edytorze bloku. Domyślnie pusta, bo nie każdy konektor (np. Generic
	 * REST) musi wspierać wyliczanie encji. Nie jest częścią kontraktu
	 * fetch_data() i nie zastępuje go.
	 *
	 * @return array<int, array{id: string, label: string}>
	 */
	public function list_entities(): array {
		return array();
	}

	/**
	 * Buduje znormalizowaną odpowiedź błędu — ten sam kształt co odpowiedź
	 * sukcesu, żeby renderer nigdy nie musiał sprawdzać różnych struktur.
	 *
	 * @param string $message Czytelny komunikat błędu.
	 * @return array{value: null, unit: null, label: null, last_updated: null, error: string, display_value: null}
	 */
	protected function error_response( string $message ): array {
		return array(
			'value'         => null,
			'unit'          => null,
			'label'         => null,
			'last_updated'  => null,
			'error'         => $message,
			'display_value' => null,
		);
	}

	/**
	 * Czy $host jest adresem sieci lokalnej (RFC 1918 + loopback + link-local
	 * + hostname ".local"/mDNS) — najczęstsza przyczyna "cURL error 7"/
	 * "Could not resolve host" u klientów na zwykłym hostingu współdzielonym:
	 * WordPress stojący na zewnętrznym serwerze fizycznie nie ma jak wysłać
	 * pakietu do prywatnego adresu w cudzej sieci domowej — to nie jest błąd
	 * konfiguracji, tylko fundamentalne ograniczenie modelu "pull" (patrz
	 * docs/archiwum/mvp-v2-0-push-mode-plan.md, tryb push ma to docelowo rozwiązać w
	 * v2.0). Używane, żeby DOPISAĆ wyjaśnienie do surowego błędu połączenia,
	 * nie żeby go zastąpić — surowy komunikat zostaje przydatny do debugowania.
	 *
	 * `FILTER_VALIDATE_IP` z flagami `FILTER_FLAG_NO_PRIV_RANGE`/
	 * `FILTER_FLAG_NO_RES_RANGE` obsługuje RFC 1918 (10/8, 172.16/12,
	 * 192.168/16), loopback (127/8, ::1) i link-local (169.254/16) za jednym
	 * wywołaniem, dla IPv4 i IPv6 — nie trzeba własnej listy zakresów CIDR.
	 *
	 * @param string $host Sam hostname/adres IP (nie pełny URL) — patrz wp_parse_url( $url, PHP_URL_HOST ) u wywołujących.
	 * @return bool
	 */
	protected static function is_local_network_host( string $host ): bool {
		$host = trim( $host );

		if ( '' === $host ) {
			return false;
		}

		if ( str_ends_with( strtolower( $host ), '.local' ) ) {
			return true;
		}

		if ( false === filter_var( $host, FILTER_VALIDATE_IP ) ) {
			// Nie jest gołym adresem IP — prawdziwy hostname (np.
			// "homeassistant.example.com"), zakładamy publiczny/routowalny.
			return false;
		}

		return false === filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE );
	}

	/**
	 * Dopisuje wyjaśnienie do komunikatu błędu połączenia, gdy skonfigurowany
	 * URL wskazuje na adres sieci lokalnej — patrz is_local_network_host().
	 * Wołane WYŁĄCZNIE z gałęzi is_wp_error() (błąd na poziomie połączenia:
	 * timeout, connection refused, DNS), nie dla błędów HTTP/aplikacyjnych
	 * (401/404/zła odpowiedź) — te nie mają nic wspólnego z zasięgiem sieci.
	 *
	 * @param string $message  Surowy komunikat błędu (np. z WP_Error::get_error_message()).
	 * @param string $base_url Pełny, skonfigurowany URL połączenia.
	 * @return string
	 */
	protected function maybe_explain_local_network_error( string $message, string $base_url ): string {
		$host = (string) wp_parse_url( $base_url, PHP_URL_HOST );

		if ( ! self::is_local_network_host( $host ) ) {
			return $message;
		}

		if ( null !== IOTDW_Http_Client::check_blocked( $base_url ) ) {
			// Odrzucone przed wysłaniem — komunikat IOTDW_Http_Client już mówi dlaczego.
			return $message;
		}

		return $message . ' ' . sprintf(
			/* translators: %s: the local-network host/IP that was configured */
			__( 'This looks like a local network address (%s) — it will only work if your WordPress server is on the same network as the device. Most external/shared hosting can\'t reach it; consider Shelly Cloud, a publicly reachable Home Assistant instance, or a differently-reachable REST endpoint instead.', 'iot-data-widget' ),
			$host
		);
	}
}
