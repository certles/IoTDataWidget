<?php
/**
 * Generator gotowych snippetów konfiguracyjnych dla kanałów push (v2.0,
 * docs/archiwum/mvp-v2-0-push-mode-plan.md §4 — "właściwy produkt": to za to płaci
 * klient, nie za sam widget). Każdy snippet ma podstawiony PRAWDZIWY adres
 * strony, PRAWDZIWY token i PRAWDZIWE nazwy encji — nigdy placeholdery.
 *
 * Shelly (Gen2+) zweryfikowane fizycznie na żywym urządzeniu (Shelly Plug S
 * Gen3, fw 2.0.0) — patrz komentarze przy poszczególnych metodach. Tasmota
 * zweryfikowana fizycznie później (zgłoszenie 2026-09-07, Sonoff RF R2,
 * ESP8285N08, Tasmota 15.6.0.1 — patrz komentarz przy tasmota() niżej).
 * ESPHome/Home Assistant są zbudowane wg oficjalnej dokumentacji producenta,
 * ale NIE zweryfikowane fizycznie na tym etapie (brak sprzętu ESPHome pod
 * ręką) — oznaczone niżej, ten sam ostrzegawczy wzorzec co 'beta' w
 * IOTDW_Connector_Presets.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Push_Snippet_Generator
 */
class IOTDW_Push_Snippet_Generator {

	/**
	 * Słowa kluczowe w nazwie encji → pole odpowiedzi Shelly Switch.GetStatus
	 * (§4 planu, "Shelly Gen2+ ... webhook na zmianę stanu lub cykliczne").
	 * Zweryfikowane na żywo na Shelly Plug S Gen3 — dokładnie te ścieżki są
	 * naprawdę zwracane przez urządzenie (patrz też IOTDW_Connector_Presets,
	 * preset 'shelly_gen2', które te same ścieżki mapuje dla trybu pull).
	 *
	 * @var array<string, array{path: string, unit: string}>
	 */
	const SHELLY_FIELD_KEYWORDS = array(
		'power'   => array(
			'path' => 'apower',
			'unit' => 'W',
		),
		'moc'     => array(
			'path' => 'apower',
			'unit' => 'W',
		),
		'volt'    => array(
			'path' => 'voltage',
			'unit' => 'V',
		),
		'napiec'  => array(
			'path' => 'voltage',
			'unit' => 'V',
		),
		'current' => array(
			'path' => 'current',
			'unit' => 'A',
		),
		'prad'    => array(
			'path' => 'current',
			'unit' => 'A',
		),
		'temp'    => array(
			'path' => 'temperature.tC',
			'unit' => 'C',
		),
		'energy'  => array(
			'path' => 'aenergy.total',
			'unit' => 'Wh',
		),
		'energia' => array(
			'path' => 'aenergy.total',
			'unit' => 'Wh',
		),
		'freq'    => array(
			'path' => 'freq',
			'unit' => 'Hz',
		),
	);

	/**
	 * Buduje snippety dla wszystkich zakładek (nie tylko wybranej platformy)
	 * — kreator pokazuje jedną aktywną, ale admin może później sprawdzić
	 * inne bez ponownego przechodzenia kreatora (§4 planu: "Zakładka per
	 * platforma").
	 *
	 * @param string               $push_type     Wybrana/podstawowa platforma (patrz IOTDW_Setup_Wizard::get_push_platforms()).
	 * @param array<string,string> $connection    Zapisany kanał (id, name).
	 * @param string               $raw_token     Surowy token (dostępny tylko raz — patrz IOTDW_Setup_Wizard::PUSH_TOKEN_TRANSIENT_PREFIX).
	 * @param string[]             $entity_keys   Zadeklarowane nazwy encji kanału.
	 * @return array<string, array{label: string, verified: bool, blocks: array<int, array{title: string, language: string, code: string}>, notes: string[]}>
	 */
	public static function generate( string $push_type, array $connection, string $raw_token, array $entity_keys ): array {
		$ingest_url = untrailingslashit( rest_url( IOTDW_Rest_Api::NAMESPACE ) ) . '/ingest';

		$snippets = array(
			'shelly_gen2'    => self::shelly_gen2( $ingest_url, $raw_token, $entity_keys ),
			'tasmota'        => self::tasmota( $ingest_url, $raw_token, $entity_keys ),
			'esphome'        => self::esphome( $ingest_url, $raw_token, $entity_keys ),
			'home_assistant' => self::home_assistant( $ingest_url, $raw_token, $entity_keys ),
		);

		if ( self::points_at_a_local_hostname( $ingest_url ) ) {
			$warning = __( 'Your site\'s address currently resolves to a local hostname ("localhost"/127.0.0.1/a .local/.test/.local domain) — a physical device cannot reach that address, since to the device "localhost" means itself. This snippet will only work once pasted on your real, publicly (or at least LAN-)reachable site; it\'s fine to copy it now for reference, but test it against your actual site address, not this local development one.', 'iot-data-widget' );
			foreach ( $snippets as $platform_key => $snippet ) {
				array_unshift( $snippets[ $platform_key ]['notes'], $warning );
			}
		}

		return $snippets;
	}

	/**
	 * Zgłoszenie 2026-09-07: generator wstawia host z rest_url()/site_url(),
	 * czyli na instalacji lokalnej (wp-env, Local, MAMP...) "localhost" albo
	 * podobnie — bez sensu dla fizycznego urządzenia (dla niego "localhost"
	 * znaczy "ja sam"), a snippet wygląda identycznie jak dla produkcji, więc
	 * użytkownik nie ma jak się zorientować bez czytania kodu.
	 *
	 * @param string $url Pełny URL endpointu /ingest.
	 * @return bool
	 */
	private static function points_at_a_local_hostname( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return false;
		}

		if ( in_array( $host, array( 'localhost', '127.0.0.1', '::1' ), true ) ) {
			return true;
		}

		$local_suffixes = array( '.local', '.test', '.localhost', '.example' );
		foreach ( $local_suffixes as $suffix ) {
			if ( substr_compare( $host, $suffix, -strlen( $suffix ) ) === 0 ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Dopasowuje nazwę encji do pola Shelly po słowach kluczowych — patrz
	 * SHELLY_FIELD_KEYWORDS. Brak dopasowania = traktowana jako stan
	 * logiczny przekaźnika (najczęstszy przypadek dla pojedynczej,
	 * niejednoznacznie nazwanej encji, np. "przekaznik"/"gniazdko").
	 *
	 * @param string $entity_key Nazwa encji zadeklarowana przy tworzeniu kanału.
	 * @return array{path: string, unit: string}|null Null = stan logiczny (przekaźnik), nie metryka liczbowa.
	 */
	private static function match_shelly_field( string $entity_key ): ?array {
		foreach ( self::SHELLY_FIELD_KEYWORDS as $keyword => $field ) {
			if ( false !== strpos( $entity_key, $keyword ) ) {
				return $field;
			}
		}

		return null;
	}

	/**
	 * Shelly (Gen2+) — DWA mechanizmy, oba zweryfikowane fizycznie (Shelly
	 * Plug S Gen3, fw 2.0.0, 2026-08-21):
	 *
	 * 1. Webhook (Settings → Actions/Webhooks w aplikacji Shelly, albo
	 *    Webhook.Create przez RPC) na zdarzenie switch.on/switch.off — proste
	 *    GET pod jeden, stały URL. Sam mechanizm webhooków Shelly wysyła GET,
	 *    NIE POST z ciałem JSON, mimo że "webhook" mogłoby sugerować inaczej
	 *    — stąd wartość na sztywno w query stringu (encja jest wtedy z natury
	 *    logiczna: 0/1), nie szablon podstawiany przez Shelly.
	 * 2. Skrypt (Scripts w aplikacji/panelu, JS) na okresowe wysyłanie
	 *    metryk liczbowych (moc/napięcie/temperatura/...) — POST z ciałem
	 *    JSON (wariant zbiorczy, patrz IOTDW_Push_Ingest::parse_readings()),
	 *    bo webhooki Shelly nie obsługują zdarzeń zmiany metryk liczbowych
	 *    komponentu "switch" (sprawdzone przez Webhook.ListSupported na
	 *    żywym urządzeniu — nie ma takiego zdarzenia dla switch:0).
	 *
	 * @param string   $ingest_url  Pełny URL endpointu /ingest.
	 * @param string   $raw_token   Surowy token.
	 * @param string[] $entity_keys Zadeklarowane nazwy encji.
	 * @return array{label: string, verified: bool, blocks: array<int, array{title: string, language: string, code: string}>, notes: string[]}
	 */
	private static function shelly_gen2( string $ingest_url, string $raw_token, array $entity_keys ): array {
		$boolean_entities = array();
		$numeric_readings = array();

		foreach ( $entity_keys as $entity_key ) {
			$field = self::match_shelly_field( $entity_key );

			if ( null === $field ) {
				$boolean_entities[] = $entity_key;
			} else {
				$numeric_readings[] = array(
					'entity' => $entity_key,
					'path'   => $field['path'],
					'unit'   => $field['unit'],
				);
			}
		}

		$blocks = array();
		$notes  = array();

		foreach ( $boolean_entities as $entity_key ) {
			$on_url  = add_query_arg(
				array(
					'token'  => rawurlencode( $raw_token ),
					'entity' => rawurlencode( $entity_key ),
					'value'  => '1',
				),
				$ingest_url
			);
			$off_url = add_query_arg(
				array(
					'token'  => rawurlencode( $raw_token ),
					'entity' => rawurlencode( $entity_key ),
					'value'  => '0',
				),
				$ingest_url
			);

			$blocks[] = array(
				'title'    => sprintf(
					/* translators: %s: entity name */
					__( 'Webhook for "%s" (relay/switch state) — Settings → Actions/Webhooks in the Shelly app', 'iot-data-widget' ),
					$entity_key
				),
				'language' => 'text',
				'code'     => sprintf(
					"%s:\n  %s\n\n%s:\n  %s",
					__( 'Event "Switch On" — URL', 'iot-data-widget' ),
					$on_url,
					__( 'Event "Switch Off" — URL', 'iot-data-widget' ),
					$off_url
				),
			);
		}

		if ( ! empty( $numeric_readings ) ) {
			$readings_js = implode(
				",\n      ",
				array_map(
					static function ( $reading ) {
						return sprintf(
							'{entity: "%s", value: status.%s, unit: "%s"}',
							$reading['entity'],
							$reading['path'],
							$reading['unit']
						);
					},
					$numeric_readings
				)
			);

			$blocks[] = array(
				'title'    => __( 'Script (Scripts in the Shelly app/panel) — periodic numeric readings', 'iot-data-widget' ),
				'language' => 'javascript',
				'code'     => sprintf(
					'let URL = "%1$s";' . "\n\n" .
					'function pushReadings() {' . "\n" .
					'  Shelly.call("Switch.GetStatus", {id: 0}, function (status, error_code) {' . "\n" .
					'    if (error_code !== 0) { return; }' . "\n" .
					'    let body = JSON.stringify({' . "\n" .
					'      readings: [' . "\n" .
					'      %2$s' . "\n" .
					'      ]' . "\n" .
					'    });' . "\n" .
					'    Shelly.call("HTTP.POST", {url: URL, body: body, content_type: "application/json"});' . "\n" .
					'  });' . "\n" .
					'}' . "\n\n" .
					'Timer.set(60000, true, pushReadings); // every 60s',
					esc_url_raw( add_query_arg( 'token', rawurlencode( $raw_token ), $ingest_url ) ),
					$readings_js
				),
			);

			$notes[] = __( 'Paste the script under Scripts in the device web UI (or the Shelly app), start it, then use "Check if data is arriving" below.', 'iot-data-widget' );
		}

		if ( empty( $blocks ) ) {
			$notes[] = __( 'No entities declared for this channel yet.', 'iot-data-widget' );
		}

		return array(
			'label'    => __( 'Shelly (Gen2+)', 'iot-data-widget' ),
			'verified' => true,
			'blocks'   => $blocks,
			'notes'    => $notes,
		);
	}

	/**
	 * Tasmota — WebSend w regule, np.:
	 *   Rule1 ON Power1#State DO WebSend [ingest-host] /wp-json/.../ingest?token=...&entity=...&value=%value% ENDON
	 *   Rule1 1
	 * WebSend ma ograniczoną długość i NIE ustawia własnych nagłówków — stąd
	 * token w query stringu (§2 planu), nie w nagłówku. Zweryfikowane
	 * fizycznie (zgłoszenie 2026-09-07, Sonoff RF R2, ESP8285N08, Tasmota
	 * 15.6.0.1) — reguła WebSend zapisana w całości (174/511 znaków), Power
	 * TOGGLE poprawnie odzwierciedlony w bazie (patrz
	 * tests/Push_Snippet_Generator_Test.php).
	 *
	 * @param string   $ingest_url  Pełny URL endpointu /ingest.
	 * @param string   $raw_token   Surowy token.
	 * @param string[] $entity_keys Zadeklarowane nazwy encji.
	 * @return array{label: string, verified: bool, blocks: array<int, array{title: string, language: string, code: string}>, notes: string[]}
	 */
	private static function tasmota( string $ingest_url, string $raw_token, array $entity_keys ): array {
		$host_and_path = preg_replace( '#^https?://#', '', $ingest_url );
		$entity_key    = $entity_keys[0] ?? 'entity';

		$rule = sprintf(
			'Rule1 ON Power1#State DO WebSend [%1$s]%2$s?token=%3$s&entity=%4$s&value=%%value%% ENDON' . "\n" . 'Rule1 1',
			wp_parse_url( $ingest_url, PHP_URL_HOST ),
			wp_parse_url( $ingest_url, PHP_URL_PATH ),
			rawurlencode( $raw_token ),
			rawurlencode( $entity_key )
		);

		return array(
			'label'    => __( 'Tasmota', 'iot-data-widget' ),
			'verified' => true,
			'blocks'   => array(
				array(
					'title'    => sprintf(
						/* translators: %s: entity name */
						__( 'Console rule for "%s" (relay/switch state)', 'iot-data-widget' ),
						$entity_key
					),
					'language' => 'text',
					'code'     => $rule,
				),
			),
			'notes'    => array(
				__( 'WebSend cannot set custom headers, so the token travels in the URL (see the warning under "Security" in this plugin\'s documentation). WebSend also has a length limit (511 characters on most builds) — confirmed on real hardware that a channel token plus this rule fits with plenty of room to spare.', 'iot-data-widget' ),
				__( 'For a numeric sensor (temperature, power...), replace "Power1#State" with the matching Tasmota rule trigger for your sensor and "%value%" stays the same.', 'iot-data-widget' ),
			),
		);
	}

	/**
	 * ESPHome — http_request + on_value w YAML. Zbudowane wg dokumentacji
	 * ESPHome (components/http_request, "on_value" trigger) — NIE
	 * zweryfikowane fizycznie w tej sesji (brak sprzętu ESPHome pod ręką).
	 *
	 * @param string   $ingest_url  Pełny URL endpointu /ingest.
	 * @param string   $raw_token   Surowy token.
	 * @param string[] $entity_keys Zadeklarowane nazwy encji.
	 * @return array{label: string, verified: bool, blocks: array<int, array{title: string, language: string, code: string}>, notes: string[]}
	 */
	private static function esphome( string $ingest_url, string $raw_token, array $entity_keys ): array {
		$entity_key = $entity_keys[0] ?? 'entity';

		$yaml = sprintf(
			"http_request:\n" .
			"  useragent: esphome\n\n" .
			"sensor:\n" .
			"  - platform: ... # your existing sensor\n" .
			"    id: my_sensor\n" .
			"    on_value:\n" .
			"      then:\n" .
			"        - http_request.post:\n" .
			'          url: "%1$s"' . "\n" .
			"          headers:\n" .
			'            X-IoT-Token: "%2$s"' . "\n" .
			"          json: |-\n" .
			'            {"entity": "%3$s", "value": ${x}}',
			esc_url_raw( $ingest_url ),
			$raw_token,
			$entity_key
		);

		return array(
			'label'    => __( 'ESPHome', 'iot-data-widget' ),
			'verified' => false,
			'blocks'   => array(
				array(
					'title'    => sprintf(
						/* translators: %s: entity name */
						__( 'YAML for "%s" — add under your existing sensor', 'iot-data-widget' ),
						$entity_key
					),
					'language' => 'yaml',
					'code'     => $yaml,
				),
			),
			'notes'    => array(
				__( 'Not yet verified against real ESPHome hardware — built from the official http_request component documentation. ESPHome supports custom headers, so the token travels in a header here, not the URL.', 'iot-data-widget' ),
			),
		);
	}

	/**
	 * Home Assistant — rest_command w configuration.yaml + automatyzacja
	 * wyzwalana zmianą stanu. Zbudowane wg dokumentacji integracji
	 * rest_command — NIE zweryfikowane fizycznie w tej sesji.
	 *
	 * @param string   $ingest_url  Pełny URL endpointu /ingest.
	 * @param string   $raw_token   Surowy token.
	 * @param string[] $entity_keys Zadeklarowane nazwy encji.
	 * @return array{label: string, verified: bool, blocks: array<int, array{title: string, language: string, code: string}>, notes: string[]}
	 */
	private static function home_assistant( string $ingest_url, string $raw_token, array $entity_keys ): array {
		$entity_key = $entity_keys[0] ?? 'entity';

		$rest_command = sprintf(
			"rest_command:\n" .
			"  iot_data_widget_push:\n" .
			'    url: "%1$s"' . "\n" .
			"    method: POST\n" .
			"    headers:\n" .
			'      X-IoT-Token: "%2$s"' . "\n" .
			"    content_type: 'application/json'\n" .
			'    payload: \'{"entity": "{{ entity }}", "value": {{ value }}}\'',
			esc_url_raw( $ingest_url ),
			$raw_token
		);

		$automation = sprintf(
			"automation:\n" .
			'  - alias: "Push %1$s to website"' . "\n" .
			"    trigger:\n" .
			'      - platform: state' . "\n" .
			'        entity_id: sensor.YOUR_HA_ENTITY # replace with your real HA entity_id' . "\n" .
			"    action:\n" .
			"      - service: rest_command.iot_data_widget_push\n" .
			"        data:\n" .
			'          entity: "%1$s"' . "\n" .
			'          value: "{{ trigger.to_state.state }}"',
			$entity_key
		);

		return array(
			'label'    => __( 'Home Assistant', 'iot-data-widget' ),
			'verified' => false,
			'blocks'   => array(
				array(
					'title'    => __( 'configuration.yaml — rest_command (add once)', 'iot-data-widget' ),
					'language' => 'yaml',
					'code'     => $rest_command,
				),
				array(
					'title'    => sprintf(
						/* translators: %s: entity name */
						__( 'Automation for "%s" — one per entity you want to push', 'iot-data-widget' ),
						$entity_key
					),
					'language' => 'yaml',
					'code'     => $automation,
				),
			),
			'notes'    => array(
				__( 'Not yet verified against a real Home Assistant instance — built from the official rest_command integration documentation.', 'iot-data-widget' ),
				__( 'Home Assistant can usually reach your site\'s public URL directly and be reached back the same way — pull mode (Home Assistant connector) is often simpler if your WordPress and Home Assistant are on the same network. Use push here mainly if your site is hosted externally and Home Assistant is not otherwise reachable from it (e.g. no Nabu Casa/reverse proxy).', 'iot-data-widget' ),
			),
		);
	}
}
