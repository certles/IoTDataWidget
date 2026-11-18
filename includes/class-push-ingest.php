<?php
/**
 * Publiczny endpoint odbioru danych push (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md
 * §2-3). Jedyny, obok IOTDW_Rest_Api::get_widget_data(), publiczny
 * (bez-autoryzacyjny na poziomie WP REST) endpoint w całej wtyczce — dostęp
 * kontrolowany przez token per kanał, nie przez current_user_can(). Traktować
 * z taką samą ostrożnością jak get_widget_data() (patrz CLAUDE.md).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Push_Ingest
 */
class IOTDW_Push_Ingest {

	/**
	 * Limit żądań na kanał (§3 planu).
	 */
	const RATE_LIMIT_PER_MINUTE = 60;

	/**
	 * Twardy limit rozmiaru ciała żądania (§3 planu) — odrzucane PRZED
	 * jakimkolwiek parsowaniem, żeby duży payload nie kosztował nic poza
	 * jednym strlen().
	 */
	const MAX_PAYLOAD_BYTES = 8192;

	/**
	 * Wzorzec dozwolonej nazwy encji (§3 planu).
	 */
	const ENTITY_KEY_PATTERN = '/^[a-z0-9_.:-]{1,120}$/';

	/**
	 * Maksymalna długość wartości tekstowej (§3 planu).
	 */
	const MAX_TEXT_VALUE_LENGTH = 255;

	/**
	 * Menedżer połączeń — lista kanałów push do wyszukania po tokenie.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Whitelist encji + ostatnia wartość per kanał.
	 *
	 * @var IOTDW_Push_Channel_Manager
	 */
	private $channel_manager;

	/**
	 * Zapis do wspólnej historii (wykresy/raporty/alerty).
	 *
	 * @var IOTDW_History_Recorder
	 */
	private $history_recorder;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager Menedżer konektorów (lista kanałów push).
	 * @param IOTDW_Push_Channel_Manager $channel_manager   Whitelist encji + ostatnia wartość per kanał.
	 * @param IOTDW_History_Recorder     $history_recorder  Zapis do wspólnej historii (wykresy/raporty/alerty).
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Push_Channel_Manager $channel_manager, IOTDW_History_Recorder $history_recorder ) {
		$this->connector_manager = $connector_manager;
		$this->channel_manager   = $channel_manager;
		$this->history_recorder  = $history_recorder;

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Rejestruje /ingest dla GET *i* POST. permission_callback celowo
	 * '__return_true' — ten sam wzorzec i to samo uzasadnienie co
	 * IOTDW_Rest_Api::get_widget_data() (v1.8): autoryzacja nie jest "kto
	 * jest zalogowany", tylko "czy podany token pasuje do jakiegoś kanału",
	 * co sprawdza sam handle_ingest().
	 *
	 * GET dopisane po weryfikacji na żywo na prawdziwym sprzęcie: mechanizm
	 * webhooków Shelly Gen2+/Gen3 (Webhook.Create → 'urls') wysyła zwykłe
	 * żądanie GET pod skonfigurowany URL, nie POST z ciałem JSON, jak
	 * pierwotnie zakładano (§2 planu mówił o "POST", ale nie testował tego
	 * fizycznie) — bez GET webhook Shelly dostawał 404 mimo poprawnego
	 * tokenu/encji w query stringu. handle_ingest() już i tak obsługuje
	 * dane wyłącznie z query stringu/ciała niezależnie od metody, więc nie
	 * wymagało to żadnej zmiany poza samą rejestracją trasy.
	 */
	public function register_routes(): void {
		register_rest_route(
			IOTDW_Rest_Api::NAMESPACE,
			'/ingest',
			array(
				'methods'             => array( WP_REST_Server::READABLE, WP_REST_Server::CREATABLE ),
				'callback'            => array( $this, 'handle_ingest' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Obsługuje POST /ingest. Kolejność sprawdzeń celowa: rozmiar payloadu
	 * (najtańsze, przed jakimkolwiek parsowaniem) → token (kosztuje tyle co
	 * iteracja po połączeniach + hash_equals()) → limit żądań (dopiero gdy
	 * już wiemy, KTÓRY kanał limitować) → parsowanie i walidacja treści.
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return WP_REST_Response
	 */
	public function handle_ingest( WP_REST_Request $request ): WP_REST_Response {
		$raw_body = (string) $request->get_body();

		if ( strlen( $raw_body ) > self::MAX_PAYLOAD_BYTES ) {
			return $this->error_response( __( 'Request body is too large.', 'iot-data-widget' ), 400 );
		}

		$channel = $this->find_channel_by_token( $this->extract_token( $request ) );

		if ( null === $channel ) {
			return $this->error_response( __( 'Invalid or missing token.', 'iot-data-widget' ), 401 );
		}

		if ( $this->is_rate_limited( $channel['id'] ) ) {
			return $this->error_response(
				sprintf(
					/* translators: %d: requests per minute allowed */
					__( 'Rate limit exceeded — max %d requests per minute for this channel.', 'iot-data-widget' ),
					self::RATE_LIMIT_PER_MINUTE
				),
				429
			);
		}

		$readings = $this->parse_readings( $request, $raw_body );

		if ( empty( $readings ) ) {
			return $this->error_response( __( 'No valid reading found in the request body.', 'iot-data-widget' ), 400 );
		}

		$accepted = 0;
		$skipped  = 0;
		$errors   = array();

		foreach ( $readings as $reading ) {
			if ( ! is_array( $reading ) ) {
				continue;
			}

			$result = $this->process_reading( $channel['id'], $reading );

			if ( true === $result ) {
				++$accepted;
			} elseif ( null === $result ) {
				++$skipped;
			} else {
				$errors[] = $result;
			}
		}

		// Wiele encji w jednym żądaniu (§2 planu, wariant zbiorczy dla
		// Shelly) — jedna zła nazwa/wartość nie odrzuca reszty, ten sam
		// "błąd jednej encji nie blokuje pozostałych" co
		// IOTDW_Shortcode::render_list(). Cały request odrzucamy tylko,
		// gdy DOSŁOWNIE nic się nie zapisało.
		if ( 0 === $accepted && 0 === $skipped ) {
			return $this->error_response( implode( ' ', $errors ), 400 );
		}

		return rest_ensure_response( array( 'ok' => true ) );
	}

	/**
	 * Buduje jednolitą odpowiedź błędu.
	 *
	 * @param string $message Czytelny komunikat (dla człowieka debugującego regułę urządzenia — patrz §2 planu).
	 * @param int    $status  Kod HTTP.
	 * @return WP_REST_Response
	 */
	private function error_response( string $message, int $status ): WP_REST_Response {
		$response = rest_ensure_response(
			array(
				'ok'    => false,
				'error' => $message,
			)
		);
		$response->set_status( $status );

		return $response;
	}

	/**
	 * Wyciąga token z żądania — nagłówek X-IoT-Token, potem Authorization:
	 * Bearer, na końcu query string ?token= (§2 planu: Tasmota WebSend nie
	 * potrafi ustawiać własnych nagłówków, stąd ten trzeci, gorszy wariant
	 * MUSI być wspierany, mimo że token w URL-u to gorsza praktyka — patrz
	 * ostrzeżenie w generatorze snippetów Tasmoty).
	 *
	 * @param WP_REST_Request $request Żądanie REST.
	 * @return string Pusty string, jeśli nigdzie nie znaleziono tokenu.
	 */
	private function extract_token( WP_REST_Request $request ): string {
		$header = (string) $request->get_header( 'X-IoT-Token' );

		if ( '' !== $header ) {
			return $header;
		}

		$auth = (string) $request->get_header( 'Authorization' );

		if ( 0 === stripos( $auth, 'Bearer ' ) ) {
			return trim( substr( $auth, 7 ) );
		}

		// Wprost query string, NIE get_param() (który scala też ciało
		// JSON) — autoryzacja ma świadomie iść tylko przez nagłówek/query,
		// nie przez dowolne pole treści żądania.
		$query = $request->get_query_params();

		return isset( $query['token'] ) ? (string) $query['token'] : '';
	}

	/**
	 * Znajduje kanał (połączenie typu 'push'), którego hash pasuje do
	 * podanego tokenu. Iteracja po wszystkich połączeniach, nie indeksowane
	 * wyszukiwanie — realistyczna liczba kanałów na instalację (dziesiątki,
	 * nie tysiące) czyni to nieistotnym kosztowo, a hash_equals() i tak
	 * musi się wykonać per kandydat.
	 *
	 * @param string $token Surowy token z żądania.
	 * @return array<string, mixed>|null
	 */
	private function find_channel_by_token( string $token ): ?array {
		if ( '' === $token ) {
			return null;
		}

		foreach ( $this->connector_manager->get_connections() as $connection ) {
			if ( IOTDW_Push_Channel_Manager::CONNECTOR_TYPE !== ( $connection['connector'] ?? '' ) ) {
				continue;
			}

			if ( IOTDW_Push_Channel_Manager::verify_token( $token, (string) ( $connection['token_hash'] ?? '' ) ) ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Czy $connection_id przekroczył limit żądań w BIEŻĄCEJ minucie
	 * (kubełek liczony jako floor(czas / 60), nie przesuwane okno — prostsze,
	 * a "co najwyżej 2x w rzadkim przypadku na granicy minuty" jest tu
	 * akceptowalne, ten sam kompromis co IOTDW_Cache::remember() dla blokady
	 * odświeżania, patrz jej komentarz). Zwykły get/set_transient (nie
	 * atomowy inkrement) — najgorszy scenariusz to odrobinę nieprecyzyjny
	 * limit przy dokładnie równoczesnych żądaniach, nie jego całkowity brak.
	 *
	 * @param string $connection_id ID kanału.
	 * @return bool
	 */
	private function is_rate_limited( string $connection_id ): bool {
		$key   = 'iotdw_push_rate_' . $connection_id . '_' . floor( time() / MINUTE_IN_SECONDS );
		$count = (int) get_transient( $key );

		if ( $count >= self::RATE_LIMIT_PER_MINUTE ) {
			return true;
		}

		set_transient( $key, $count + 1, MINUTE_IN_SECONDS );

		return false;
	}

	/**
	 * Rozkłada ciało żądania na listę odczytów — obsługuje (§2 planu):
	 * pojedynczy JSON {"entity","value","unit"}, zbiorczy JSON
	 * {"readings":[...]}, application/x-www-form-urlencoded (entity/value/unit
	 * jako zwykłe pola POST albo w query stringu) i text/plain (encja+token w
	 * query stringu, samo ciało to wartość) — część firmware'ów (Tasmota
	 * WebSend) nie wysyła poprawnego JSON-a.
	 *
	 * @param WP_REST_Request $request  Żądanie REST.
	 * @param string          $raw_body Surowe (już zweryfikowane pod kątem rozmiaru) ciało żądania.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_readings( WP_REST_Request $request, string $raw_body ): array {
		$trimmed_body = trim( $raw_body );

		if ( '' !== $trimmed_body && ( '{' === $trimmed_body[0] || '[' === $trimmed_body[0] ) ) {
			$json = json_decode( $trimmed_body, true );

			if ( is_array( $json ) ) {
				if ( isset( $json['readings'] ) && is_array( $json['readings'] ) ) {
					return array_values( $json['readings'] );
				}

				if ( array_key_exists( 'entity', $json ) ) {
					return array( $json );
				}
			}
		}

		// application/x-www-form-urlencoded i text/plain: entity/unit z pól
		// POST-a albo z query stringu (get_param() scala oba — bezpieczne
		// tutaj, bo to NIE jest ścieżka autoryzacji, patrz extract_token()).
		$entity = (string) $request->get_param( 'entity' );

		if ( '' === $entity ) {
			return array();
		}

		$value = $request->get_param( 'value' );

		// text/plain: brak nazwanego pola "value" w ogóle — całe ciało JEST
		// wartością (np. Tasmota WebSend wysyłające samą liczbę/stan).
		if ( null === $value || '' === $value ) {
			$value = $trimmed_body;
		}

		return array(
			array(
				'entity' => $entity,
				'value'  => $value,
				'unit'   => (string) $request->get_param( 'unit' ),
			),
		);
	}

	/**
	 * Waliduje i zapisuje jeden odczyt. Zwraca `true` przy sukcesie, albo
	 * czytelny komunikat błędu (string) — nigdy nie rzuca wyjątku, każdy
	 * odczyt w żądaniu zbiorczym jest niezależny od pozostałych.
	 *
	 * @param string               $connection_id ID kanału.
	 * @param array<string, mixed> $reading       Jeden odczyt: 'entity', 'value', opcjonalnie 'unit'.
	 * @return true|string|null Null = poprawny odczyt „brak danych”, pominięty.
	 */
	private function process_reading( string $connection_id, array $reading ) {
		$entity_key = strtolower( trim( (string) ( $reading['entity'] ?? '' ) ) );

		if ( '' === $entity_key || ! preg_match( self::ENTITY_KEY_PATTERN, $entity_key ) ) {
			return sprintf(
				/* translators: %s: the rejected entity name as sent by the device */
				__( 'Invalid entity name: "%s".', 'iot-data-widget' ),
				sanitize_text_field( (string) ( $reading['entity'] ?? '' ) )
			);
		}

		if ( ! $this->channel_manager->is_entity_allowed( $connection_id, $entity_key ) ) {
			return sprintf(
				/* translators: %s: entity name not on the channel's whitelist */
				__( 'Unknown entity for this channel: "%s". Add it to the channel\'s entity list first.', 'iot-data-widget' ),
				$entity_key
			);
		}

		$raw_value = $reading['value'] ?? null;

		if ( null === $raw_value || '' === $raw_value ) {
			return sprintf(
				/* translators: %s: entity name */
				__( 'Missing value for entity "%s".', 'iot-data-widget' ),
				$entity_key
			);
		}

		// Ten sam normalizator co HA/REST/MQTT (IOTDW_Value_Normalizer) —
		// "ON"/"OFF"/"true"/"false" itp. mapują się na 0/1 z czytelnym
		// tekstem w display_value, liczby przechodzą bez zmian.
		//
		// W przeciwieństwie do pull (gdzie nierozpoznany tekst zostaje
		// nieliczbowy i record_snapshot() po prostu pomija zapis do
		// historii — patrz IOTDW_Value_Normalizer, "historia/wykres i tak
		// je pomijają") push MUSI zaakceptować DOWOLNY tekst stanu (§8 planu,
		// kryterium akceptacji 5: wartość z "<script>" ma się bezpiecznie
		// wyrenderować jako tekst, nie zniknąć jako "błąd" ani zostać
		// wykonana) — 'value' i tak musi zawsze być liczbą (kontrakt
		// konektora, CLAUDE.md), więc nierozpoznany tekst dostaje neutralny
		// numeryczny placeholder (1.0 — "odebrano jakiś stan tekstowy"), a
		// PRAWDZIWA wartość ląduje w display_value. Świadomie BEZ
		// sanitize_text_field()/strip_tags() na tym tekście — ono usuwa
		// całą zawartość <script>/<style>, co ukryłoby dokładnie to, co
		// kryterium akceptacji ma zweryfikować; jedyna, ale wystarczająca
		// linia obrony to esc_html() w IOTDW_Renderer przy wyświetlaniu
		// (już istnieje, dokładnie tak samo jak dla label/display_value z
		// KAŻDEGO innego konektora — patrz Renderer_Test).
		$is_string_value = is_string( $raw_value );
		$clean_text      = $is_string_value ? wp_check_invalid_utf8( $raw_value, true ) : (string) $raw_value;
		$normalized      = IOTDW_Value_Normalizer::normalize( $is_string_value ? $clean_text : $raw_value );

		// Brak odczytu (unavailable/unknown) albo tekst dla encji liczbowej
		// (ma jednostkę albo ostatnio przyszła liczba): nie zapisujemy ani
		// historii, ani stanu — widżet zostaje przy ostatniej wartości i po
		// progu „stare dane” pokaże znacznik „nieaktualne” (przegląd demo
		// 2026-10-22: niedostępny czujnik dawał 0 °C w historii).
		if ( IOTDW_Value_Normalizer::is_unavailable( $raw_value )
			|| ( ! is_numeric( $normalized['value'] ) && $this->expects_number( $connection_id, $entity_key, $reading ) ) ) {
			return null;
		}

		if ( is_numeric( $normalized['value'] ) ) {
			$value_num     = (float) $normalized['value'];
			$display_value = null !== $normalized['display_value']
				? mb_substr( $normalized['display_value'], 0, self::MAX_TEXT_VALUE_LENGTH )
				: null;
		} else {
			// Dowolny, nierozpoznany tekst — patrz komentarz wyżej.
			$value_num     = 1.0;
			$display_value = mb_substr( $clean_text, 0, self::MAX_TEXT_VALUE_LENGTH );
		}

		$unit = isset( $reading['unit'] ) ? (string) $reading['unit'] : '';
		$unit = '' !== $unit ? mb_substr( wp_check_invalid_utf8( $unit, true ), 0, 32 ) : null;

		$this->channel_manager->record_reading( $connection_id, $entity_key, $value_num, $display_value, $unit );

		$this->history_recorder->record_value(
			$connection_id,
			$entity_key,
			$value_num,
			array(
				'label'         => $entity_key,
				'unit'          => $unit,
				'display_value' => $display_value,
			)
		);

		return true;
	}

	/**
	 * Czy encja jest liczbowa: odczyt ma jednostkę albo ostatnia zapisana
	 * wartość była liczbą (bez tekstu stanu).
	 *
	 * @param string               $connection_id ID kanału.
	 * @param string               $entity_key    Nazwa encji.
	 * @param array<string, mixed> $reading       Odczyt.
	 * @return bool
	 */
	private function expects_number( string $connection_id, string $entity_key, array $reading ): bool {
		if ( '' !== trim( (string) ( $reading['unit'] ?? '' ) ) ) {
			return true;
		}

		$state = $this->channel_manager->get_entity_state( $connection_id, $entity_key );

		return null !== $state && null !== $state['last_recorded_at'] && null === $state['last_value_txt'];
	}
}
