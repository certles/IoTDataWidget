<?php
/**
 * Generic REST Connector — dowolne API zwracające JSON.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_REST_Connector
 *
 * Trzy tryby, wybierane per połączenie (pole 'preset', puste = tryb
 * zaawansowany):
 * - Zaawansowany (domyślny, zachowanie z MVP/v1.0-1.1): $entity_id
 *   (parametr fetch_data()) to nie ID encji, tylko uproszczona ścieżka
 *   JSONPath do wartości w odpowiedzi (notacja kropkowa, np.
 *   "data.temperature") — to NIE jest pełna specyfikacja JSONPath (bez
 *   filtrów, wildcardów, zakresów), celowe uproszczenie pod MVP.
 * - Preset urządzenia z jedną odpowiedzią na wiele metryk (v1.2 Tydzień 3,
 *   np. Shelly/Tasmota): $entity_id to klucz metryki z
 *   IOTDW_Connector_Presets (np. "relay_state"), tłumaczony na właściwy
 *   json_path/etykietę/jednostkę z definicji presetu. Rozpoznawany jest
 *   też ALIAS — surowy klucz JSON zwracany przez samo urządzenie (np.
 *   Tasmota "POWER", patrz IOTDW_Connector_Presets::find_metric_key_by_json_path(),
 *   zgłoszenie 2026-08-31) — traktowany identycznie jak trafienie w klucz
 *   metryki wprost. $entity_id nierozpoznany ANI jako klucz metryki, ANI
 *   jako alias (np. wolny wpis użytkownika) spada z powrotem do trybu
 *   zaawansowanego — traktowany wprost jako JSONPath.
 * - Preset z encją w URL (v1.2 Tydzień 6, ESPHome web_server —
 *   preset['entity_in_url'] === true): urządzenie nie ma jednej wspólnej
 *   odpowiedzi z wieloma polami — każda encja ma własny endpoint
 *   (GET /<domena>/<id>). $entity_id to wtedy wolny wpis użytkownika
 *   (np. "sensor/outside_temperature", patrz docs/podlaczanie-esphome.md),
 *   doklejany jako ścieżka do base_url, a json_path jest zawsze stały —
 *   "value" (ESPHome web_server zawsze zwraca tam gotową liczbę/bool).
 */
class IOTDW_REST_Connector extends IOTDW_Abstract_Connector {

	/**
	 * Pełny adres endpointu REST API.
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Opcjonalny, surowy nagłówek autoryzacji w formacie "Nazwa: wartość",
	 * np. "Authorization: Bearer xyz" albo "X-API-Key: abc123". Puste = brak
	 * autoryzacji (publiczne API). Dla presetu 'tasmota' (patrz
	 * build_request_url()) to pole zmienia znaczenie: samo hasło Tasmoty
	 * (WebPassword), nie nagłówek — Tasmota nie obsługuje autoryzacji przez
	 * Authorization header w ogóle, tylko user/password w query stringu
	 * (zgłoszenie 2026-08-28, patrz tam).
	 *
	 * @var string
	 */
	private $auth_header;

	/**
	 * Klucz presetu (IOTDW_Connector_Presets), albo pusty string dla trybu
	 * zaawansowanego.
	 *
	 * @var string
	 */
	private $preset;

	/**
	 * Adres IP/hostname urządzenia (tryb presetu) — potrzebny do budowy
	 * URL-a PER METRYKA, gdy metryka ma własny url_template (Tasmota
	 * relay_state/relay_N, patrz IOTDW_Connector_Presets::build_metric_url());
	 * puste dla trybu zaawansowanego/presetów bez metryk z override.
	 *
	 * @var string
	 */
	private $device_ip;

	/**
	 * Konstruktor.
	 *
	 * @param array{base_url?: string, token?: string, preset?: string, device_ip?: string} $config Konfiguracja połączenia ('token' = surowy nagłówek autoryzacji, albo hasło Tasmoty dla presetu 'tasmota').
	 */
	public function __construct( array $config ) {
		$this->base_url    = (string) ( $config['base_url'] ?? '' );
		$this->auth_header = (string) ( $config['token'] ?? '' );
		$this->preset      = (string) ( $config['preset'] ?? '' );
		$this->device_ip   = (string) ( $config['device_ip'] ?? '' );
	}

	/**
	 * Pobiera JSON z endpointu i wyciąga z niego wartość — po ścieżce z
	 * presetu, jeśli $entity_id jest jedną z jego znanych metryk, przez
	 * doklejenie $entity_id do URL dla presetów z 'entity_in_url', inaczej
	 * wprost jako JSONPath (tryb zaawansowany, patrz komentarz klasy).
	 *
	 * @param string $entity_id JSONPath (tryb zaawansowany), klucz metryki presetu, albo ścieżka encji dla presetów 'entity_in_url'.
	 * @return array{value: mixed, unit: string|null, label: string|null, last_updated: string|null, error: string|null, display_value: string|null}
	 */
	public function fetch_data( string $entity_id ): array {
		if ( '' === trim( $this->base_url ) ) {
			return $this->error_response(
				__( 'Missing URL in the connection settings.', 'iot-data-widget' )
			);
		}

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro
		// (includes/premium/class-connector-presets.php); jeśli fizycznie
		// nie istnieje, $this->preset (gdyby mimo wszystko był ustawiony —
		// stara/ręcznie spreparowana konfiguracja) jest po prostu ignorowany
		// i cała reszta tej metody degraduje się do zwykłego trybu
		// zaawansowanego (JSONPath wprost z $entity_id), zamiast fatal errora.
		$preset_definition = ( '' !== $this->preset && class_exists( 'IOTDW_Connector_Presets' ) ) ? IOTDW_Connector_Presets::get( $this->preset ) : null;
		$entity_in_url     = null !== $preset_definition && ! empty( $preset_definition['entity_in_url'] );
		// Rozwiązane TU, nie dopiero po requeście (jak dawniej) — potrzebne
		// już do zbudowania URL-a, bo relay_state/relay_N Tasmoty (zgłoszenie
		// 2026-08-28) mają WŁASNY url_template zamiast współdzielonego z
		// resztą presetu, patrz build_request_url().
		//
		// $metric_key (zgłoszenie 2026-08-31) — KANONICZNY klucz metryki,
		// osobno od samego $entity_id: jeśli $entity_id trafia wprost w
		// klucz metryki (typowy przypadek, np. "relay_state"), oba są
		// identyczne; jeśli $entity_id to zamiast tego RAW klucz JSON
		// zwracany przez samo urządzenie (np. Tasmota "POWER" — dokładnie
		// to, co widać w odpowiedzi cmnd=Power, NIE nasza wewnętrzna nazwa),
		// IOTDW_Connector_Presets::find_metric_key_by_json_path() rozpoznaje
		// go jako alias i zwraca prawdziwy klucz metryki. Musi zostać
		// przekazany do build_request_url() OSOBNO od $entity_id — inaczej
		// per-metrykowy url_template (Tasmota) nigdy by się nie uruchomił
		// dla aliasu, bo IOTDW_Connector_Presets::build_metric_url()
		// wymaga prawdziwego klucza metryki, nie surowego wejścia
		// użytkownika (to był realny bug, znaleziony na fizycznym sprzęcie:
		// "POWER" poprawnie rozpoznawane niżej jako metryka, ale URL i tak
		// budował się ze WSPÓLNEGO, nie metrykowego szablonu presetu).
		$metric_key = null;
		$metric     = null;

		if ( null !== $preset_definition && ! $entity_in_url ) {
			$metric_key = IOTDW_Connector_Presets::get_metric( $this->preset, $entity_id ) ? $entity_id : IOTDW_Connector_Presets::find_metric_key_by_json_path( $this->preset, $entity_id );
			$metric     = null !== $metric_key ? IOTDW_Connector_Presets::get_metric( $this->preset, $metric_key ) : null;
		}

		$request_url = $this->build_request_url( $entity_id, $entity_in_url, $metric, $metric_key );
		$headers     = array( 'Accept' => 'application/json' );

		// Tasmota nie obsługuje nagłówka Authorization — autoryzacja to
		// user/password w query stringu, dołożone już wewnątrz
		// build_request_url() (patrz tam) zamiast tutaj jako nagłówek.
		if ( 'tasmota' !== $this->preset && '' !== trim( $this->auth_header ) && false !== strpos( $this->auth_header, ':' ) ) {
			list( $header_name, $header_value ) = array_map( 'trim', explode( ':', $this->auth_header, 2 ) );
			$headers[ $header_name ]            = $header_value;
		}

		$response = IOTDW_Http_Client::get(
			$request_url,
			array(
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
				'headers' => $headers,
			)
		);

		if ( is_wp_error( $response ) ) {
			$message = $this->maybe_explain_local_network_error( $response->get_error_message(), $request_url );
			$message = $this->maybe_explain_tasmota_empty_response( $message );

			return $this->error_response( $message );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $status_code || 403 === $status_code ) {
			return $this->error_response(
				__( 'Authorization failed — check the auth header in the connection settings.', 'iot-data-widget' )
			);
		}

		if ( 200 !== $status_code ) {
			return $this->error_response(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Server returned an unexpected response code: %d.', 'iot-data-widget' ),
					$status_code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) ) {
			return $this->error_response(
				__( 'Server response is not valid JSON.', 'iot-data-widget' )
			);
		}

		$json_path = null !== $metric ? $metric['json_path'] : ( $entity_in_url ? 'value' : $entity_id );

		$value = IOTDW_Json_Path::resolve( $body, $json_path );

		if ( null === $value ) {
			return $this->error_response(
				sprintf(
					/* translators: %s: JSONPath given in the widget */
					__( 'Path "%s" was not found in the JSON response.', 'iot-data-widget' ),
					$json_path
				)
			);
		}

		$scalar_value = is_scalar( $value ) ? $value : wp_json_encode( $value );

		// Rzutowanie na float dla wartości liczbowych, nawet gdy API zwróci
		// liczbę jako string JSON (np. "21.5" zamiast 21.5) — bez tego 'value'
		// mogło zostać stringiem, luźniej niż dosłowny kontrakt z CLAUDE.md
		// ("value musi zawsze być liczbą"); funkcjonalnie nieszkodliwe
		// (is_numeric()/(float) downstream i tak by to obsłużyły poprawnie),
		// ale niespójne z resztą konektorów. Znalezione przy pierwszym pełnym
		// audycie (docs/archiwum/review-log.md). is_numeric(bool) jest zawsze false w
		// PHP, więc to nie dotyka wartości boolean (np. Shelly "ison": true).
		if ( is_numeric( $scalar_value ) ) {
			$scalar_value = (float) $scalar_value;
		}

		$display_value = null;

		// value_type steruje normalizacją — patrz IOTDW_Value_Normalizer.
		// "numeric" zostaje surowym przejściem (identyczne z trybem
		// zaawansowanym, zero regresji); tylko nienumeryczne value_type
		// (na razie: "boolean") przechodzą przez normalizator. Presety
		// 'entity_in_url' nie mają metryk (żadnej stałej listy), więc nie
		// znamy value_type z góry — normalizujemy zawsze; normalize()
		// przepuszcza liczby bez zmian, więc to bezpieczne również dla
		// zwykłych sensorów numerycznych.
		//
		// Poprawka (zgłoszenie 2026-08-28, Supla przez link bezpośredni):
		// tryb zaawansowany (brak presetu, $metric === null) wcześniej
		// pomijał normalizację całkowicie — surowa wartość JSON typu bool
		// (np. Suplowe "value":false) trafiała do 'value' jako PHP-owy
		// bool, łamiąc kontrakt "value musi zawsze być liczbą" z CLAUDE.md
		// (is_numeric(false) === false, więc rzutowanie na float wyżej jej
		// nie dotyka), a 'display_value' zostawało null — render_value_content()
		// wtedy wypisuje esc_html(false), czyli PUSTY string (karta wygląda
		// na pustą, nie tylko "brzydką"). $metric === null trafia teraz do
		// tej samej gałęzi co metryki nienumeryczne.
		if ( $entity_in_url || null === $metric || 'numeric' !== $metric['value_type'] ) {
			$normalized    = IOTDW_Value_Normalizer::normalize( $scalar_value );
			$scalar_value  = $normalized['value'];
			$display_value = $normalized['display_value'];
		}

		return array(
			'value'         => $scalar_value,
			'unit'          => null !== $metric ? $metric['unit'] : null,
			'label'         => null !== $metric ? $metric['label'] : $entity_id,
			'last_updated'  => gmdate( 'c' ),
			'error'         => null,
			'display_value' => $display_value,
		);
	}

	/**
	 * Buduje URL requestu: doklejenie ścieżki encji dla presetów
	 * 'entity_in_url' (bez zmian), metryka z WŁASNYM url_template (Tasmota
	 * relay_state/relay_N, zgłoszenie 2026-08-28 — patrz
	 * IOTDW_Connector_Presets::build_metric_url()), albo po prostu
	 * $this->base_url (domyślne, bez zmian). Autoryzacja Tasmoty (user/
	 * password w query stringu, NIE nagłówek Authorization — Tasmota go nie
	 * obsługuje) dokładana tu do KAŻDEGO requestu do presetu 'tasmota',
	 * niezależnie od tego, która z trzech gałęzi zbudowała bazowy URL.
	 *
	 * @param string      $entity_id     ID/ścieżka encji (patrz fetch_data()) — surowe wejście użytkownika, może być aliasem (patrz $metric_key).
	 * @param bool        $entity_in_url Czy preset ma encję doklejaną do URL (ESPHome web_server).
	 * @param array|null  $metric        Rozwiązana definicja metryki presetu (patrz fetch_data()), albo null.
	 * @param string|null $metric_key    KANONICZNY klucz metryki (patrz fetch_data()) — MUSI być użyty zamiast $entity_id przy wołaniu build_metric_url(), bo $entity_id bywa surowym aliasem (np. Tasmota "POWER"), którego build_metric_url() nie rozpozna jako klucza.
	 * @return string
	 */
	private function build_request_url( string $entity_id, bool $entity_in_url, ?array $metric, ?string $metric_key ): string {
		if ( $entity_in_url && '' !== trim( $entity_id ) ) {
			// Doklejamy $entity_id jako ścieżkę (np. "sensor/outside_temp"),
			// każdy segment osobno urlencode'owany, żeby spacje/UTF-8 w
			// nazwach encji ESPHome (dozwolone w YAML) nie psuły URL, ale
			// struktura "domena/nazwa" (i ew. sub-device) zostawała.
			$segments    = array_map( 'rawurlencode', explode( '/', trim( $entity_id, '/' ) ) );
			$request_url = rtrim( $this->base_url, '/' ) . '/' . implode( '/', $segments );
		} elseif ( null !== $metric && ! empty( $metric['url_template'] ) && null !== $metric_key && '' !== trim( $this->device_ip ) ) {
			$request_url = IOTDW_Connector_Presets::build_metric_url( $this->preset, $metric_key, $this->device_ip );
		} else {
			$request_url = $this->base_url;
		}

		if ( 'tasmota' === $this->preset && '' !== trim( $this->auth_header ) ) {
			// Tasmota: /cm?...&user=admin&password=xxx — "admin" jest
			// jedynym obsługiwanym przez Tasmotę użytkownikiem dla tego
			// mechanizmu (WebPassword nie ma osobnego pola "login"), więc nie
			// jest konfigurowalny; $this->auth_header niesie tu samo hasło
			// (patrz docblock przy tym polu).
			// add_query_arg() już samo url-enkoduje wartości — nie robimy
			// tego drugi raz (rawurlencode() tutaj podwójnie enkodowałoby
			// hasła ze znakami specjalnymi, np. "&"/"%"/"=").
			$request_url = add_query_arg(
				array(
					'user'     => 'admin',
					'password' => $this->auth_header,
				),
				$request_url
			);
		}

		return $request_url;
	}

	/**
	 * Dokłada podpowiedź o SetOption128, gdy błąd wygląda na pustą odpowiedź
	 * HTTP z urządzenia Tasmota (zgłoszenie 2026-08-28, potwierdzone na
	 * żywo: Sonoff RF R2, Tasmota 15.6.0.1 — /cm zwracało cURL error 52
	 * (ERR_EMPTY_RESPONSE) przy w pełni działającym panelu WWW/konsoli,
	 * dopóki nie ustawiono `SetOption128 1` w konsoli urządzenia). Bez tej
	 * podpowiedzi użytkownik nie ma jak się domyślić, że problem nie leży w
	 * adresie/sieci, tylko w jednym konkretnym ustawieniu firmware'u.
	 *
	 * @param string $message Komunikat błędu (już ew. z dopisaną podpowiedzią o sieci lokalnej).
	 * @return string
	 */
	private function maybe_explain_tasmota_empty_response( string $message ): string {
		if ( 'tasmota' !== $this->preset ) {
			return $message;
		}

		if ( false === stripos( $message, 'empty reply' ) && false === stripos( $message, 'curl error 52' ) ) {
			return $message;
		}

		return $message . ' ' . __( 'Tasmota devices sometimes refuse HTTP API requests with an empty response until "SetOption128 1" is set in the device console (Tools → Console in the web UI) — try that if the device otherwise works fine in its own web interface.', 'iot-data-widget' );
	}

	/**
	 * Zwraca listę metryk presetu — do autouzupełniania w edytorze bloku
	 * (ten sam mechanizm co IOTDW_HA_Connector::list_entities()). Tryb
	 * zaawansowany (brak presetu) zwraca pustą listę, dokładnie jak dziś —
	 * edytor pokazuje wtedy zwykłe pole tekstowe na JSONPath. 'value_type'
	 * (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md §5) dołączone od razu z definicji
	 * presetu — edytor bloku wie z góry, czy metryka jest logiczna
	 * (np. "relay_state"), bez potrzeby zgadywania z samej nazwy/etykiety, i
	 * może odpowiednio zablokować style gauge/chart/sparkline.
	 *
	 * @return array<int, array{id: string, label: string, value_type: string}>
	 */
	public function list_entities(): array {
		if ( '' === $this->preset || ! class_exists( 'IOTDW_Connector_Presets' ) ) {
			return array();
		}

		$preset = IOTDW_Connector_Presets::get( $this->preset );

		if ( null === $preset ) {
			return array();
		}

		$entities = array();

		foreach ( $preset['metrics'] as $metric_key => $metric ) {
			$entities[] = array(
				'id'         => $metric_key,
				'label'      => $metric['label'],
				'value_type' => $metric['value_type'],
			);
		}

		return $entities;
	}
}
