<?php
/**
 * Konektor Home Assistant (REST API).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_HA_Connector
 *
 * Pobiera stan encji z Home Assistant przez REST API, autoryzacja przez
 * Long-Lived Access Token (HA → Profil → Tokeny). Jedyny konektor
 * implementujący IOTDW_Listable_Connector_Interface (v1.3 Tydzień 2) — HA
 * ma /api/states (pełna lista), czego REST/MQTT nie mają w ogóle.
 */
class IOTDW_HA_Connector extends IOTDW_Abstract_Connector implements IOTDW_Listable_Connector_Interface {

	/**
	 * Adres instancji Home Assistant, bez końcowego ukośnika (np. http://homeassistant.local:8123).
	 *
	 * @var string
	 */
	private $base_url;

	/**
	 * Long-Lived Access Token wygenerowany w Home Assistant.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Konstruktor.
	 *
	 * @param array{base_url?: string, token?: string} $config Konfiguracja połączenia.
	 */
	public function __construct( array $config ) {
		$this->base_url     = untrailingslashit( (string) ( $config['base_url'] ?? '' ) );
		$this->access_token = (string) ( $config['token'] ?? '' );
	}

	/**
	 * Pobiera i normalizuje stan encji z Home Assistant. Stany tekstowe
	 * (np. binary_sensor "on"/"off", person "home"/"not_home") mapują się
	 * na 0/1 w 'value' — jedyne, co trafia do historii (DECIMAL(12,4)) —
	 * z oryginalnym tekstem w 'display_value' (patrz IOTDW_Value_Normalizer).
	 *
	 * @param string $entity_id Identyfikator encji, np. sensor.temperatura_salon.
	 * @return array{value: mixed, unit: string|null, label: string|null, last_updated: string|null, error: string|null, display_value: string|null}
	 */
	public function fetch_data( string $entity_id ): array {
		$response = IOTDW_Http_Client::get(
			$this->base_url . '/api/states/' . rawurlencode( $entity_id ),
			array(
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $this->error_response( $this->maybe_explain_local_network_error( $response->get_error_message(), $this->base_url ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $status_code || 403 === $status_code ) {
			return $this->error_response(
				__( 'Invalid or expired Home Assistant access token.', 'iot-data-widget' )
			);
		}

		if ( 404 === $status_code ) {
			return $this->error_response(
				sprintf(
					/* translators: %s: entity ID */
					__( 'Entity "%s" was not found in Home Assistant.', 'iot-data-widget' ),
					$entity_id
				)
			);
		}

		if ( 200 !== $status_code ) {
			return $this->error_response(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Home Assistant returned an unexpected response code: %d.', 'iot-data-widget' ),
					$status_code
				)
			);
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $body ) || ! isset( $body['state'] ) ) {
			return $this->error_response(
				__( 'Invalid response from Home Assistant.', 'iot-data-widget' )
			);
		}

		// unavailable/unknown to brak odczytu, nie wartość — błąd sprawia, że
		// IOTDW_Cache pokaże ostatnią dobrą wartość (ze znacznikiem
		// „nieaktualne”), a historia nic nie zapisze.
		if ( IOTDW_Value_Normalizer::is_unavailable( $body['state'] ) ) {
			return $this->error_response(
				sprintf(
					/* translators: %s: entity ID */
					__( 'Entity "%s" is unavailable in Home Assistant.', 'iot-data-widget' ),
					$entity_id
				)
			);
		}

		$attributes = isset( $body['attributes'] ) && is_array( $body['attributes'] ) ? $body['attributes'] : array();
		$normalized = IOTDW_Value_Normalizer::normalize( $body['state'] );

		return array(
			'value'         => $normalized['value'],
			'display_value' => $normalized['display_value'],
			'unit'          => $attributes['unit_of_measurement'] ?? null,
			'label'         => $attributes['friendly_name'] ?? $entity_id,
			'last_updated'  => $body['last_updated'] ?? null,
			'error'         => null,
		);
	}

	/**
	 * Zwraca listę wszystkich encji dostępnych w Home Assistant — do
	 * autouzupełniania w edytorze bloku (`id`/`label`, niezmienione od MVP)
	 * i do przeglądarki encji z v1.3 Tydzień 3 (`domain`/`current_state`,
	 * nowe pola — czysto addytywne, dotychczasowi konsumenci ich nie czytają,
	 * więc nic dla nich się nie zmienia). Błąd połączenia/autoryzacji rzuca
	 * wyjątkiem zamiast cicho zwracać pustą listę (patrz interfejs) — bez
	 * tego UI przeglądarki nie mogłoby odróżnić "HA nie ma jeszcze żadnych
	 * encji" od "nie udało się połączyć z HA w ogóle".
	 *
	 * @throws \RuntimeException Gdy pobranie listy się nie powiedzie.
	 * @return array<int, array{id: string, label: string, domain: string, current_state: string}>
	 */
	public function list_entities(): array {
		// Świadomie NIE self::HTTP_TIMEOUT_SECONDS (3s) — ten request nigdy
		// nie blokuje renderowania strony gościom (tylko przeglądarka encji
		// w adminie/kreatorze, interaktywne kliknięcie), a HA zwraca tu
		// zwykle znacznie więcej danych niż pojedynczy odczyt encji, więc
		// dłuższy zapas jest tu uzasadniony bez kosztu dla front-endu.
		$response = IOTDW_Http_Client::get(
			$this->base_url . '/api/states',
			array(
				'timeout' => 5,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
				),
			)
		);

		// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages, never rendered as HTML; caught and translated to a WP_Error in IOTDW_Rest_Api::get_entities().
		if ( is_wp_error( $response ) ) {
			throw new \RuntimeException( $this->maybe_explain_local_network_error( $response->get_error_message(), $this->base_url ) );
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 401 === $status_code || 403 === $status_code ) {
			throw new \RuntimeException(
				__( 'Invalid or expired Home Assistant access token.', 'iot-data-widget' )
			);
		}

		if ( 200 !== $status_code ) {
			throw new \RuntimeException(
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Home Assistant returned an unexpected response code: %d.', 'iot-data-widget' ),
					$status_code
				)
			);
		}

		$states = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! is_array( $states ) ) {
			throw new \RuntimeException(
				__( 'Invalid response from Home Assistant.', 'iot-data-widget' )
			);
		}
		// phpcs:enable WordPress.Security.EscapeOutput.ExceptionNotEscaped

		$entities = array();

		foreach ( $states as $state ) {
			if ( ! isset( $state['entity_id'] ) ) {
				continue;
			}

			$entity_id = (string) $state['entity_id'];
			$domain    = strstr( $entity_id, '.', true );

			$entities[] = array(
				'id'            => $entity_id,
				'label'         => $state['attributes']['friendly_name'] ?? $entity_id,
				'domain'        => false !== $domain ? $domain : '',
				'current_state' => isset( $state['state'] ) ? (string) $state['state'] : '',
			);
		}

		return $entities;
	}
}
