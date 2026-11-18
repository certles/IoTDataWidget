<?php
/**
 * Menedżer konektorów danych.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Connector_Manager
 *
 * Rejestr dostępnych typów konektorów i fabryka ich instancji. Rejestracja
 * typu (register()) i tworzenie instancji (make()) nie wymagają zmian w
 * rdzeniu dla kolejnego konektora. build_config() (code review v1.8) jest
 * WYJĄTKIEM od tej zasady — mapuje surowe pola formularza na config per typ
 * konektora, więc każdy typ z własnymi polami (rest_mode/preset, MQTT,
 * Shelly Cloud) dopisuje tam własną, jawną gałąź warunkową; to świadomy
 * kompromis (prostota > pluggable field-mapping dla hipotetycznych
 * przyszłych konektorów), nie przeoczenie.
 */
class IOTDW_Connector_Manager {

	/**
	 * Nazwa opcji przechowującej połączenia skonfigurowane przez użytkownika.
	 */
	const CONNECTIONS_OPTION = 'iotdw_connections';

	/**
	 * Globalny minimalny TTL cache (sekundy) egzekwowany dla WSZYSTKICH
	 * połączeń robiących synchroniczne żądanie HTTP podczas renderowania
	 * strony (REST — dowolny preset i tryb zaawansowany, Home Assistant,
	 * Shelly Cloud) — patrz enforce_min_ttl(). Zgłoszenie 2026-08-31
	 * ("P1#8"): nawet urządzenia bez znanych, specyficznych problemów
	 * (patrz IOTDW_Connector_Presets::get_min_ttl() dla ostrzejszego
	 * limitu Tasmoty) zasługują na sensowną ochronę przed odpytywaniem
	 * częstszym niż raz na pół minuty — domyślny TTL shortcode'u (60s,
	 * IOTDW_Cache::DEFAULT_TTL) już to spełnia, więc ten floor chroni
	 * głównie przed świadomym/przypadkowym ustawieniem bardzo krótkiego
	 * atrybutu ttl="..." w shortcode'zie/bloku.
	 */
	const GLOBAL_MIN_POLL_TTL = 30;

	/**
	 * Zarejestrowane typy konektorów: typ => ['class' => ..., 'label' => ...].
	 *
	 * @var array<string, array{class: string, label: string}>
	 */
	private $connectors = array();

	/**
	 * Konstruktor — rejestruje wbudowane konektory.
	 */
	public function __construct() {
		$this->register( 'home_assistant', IOTDW_HA_Connector::class, __( 'Home Assistant', 'iot-data-widget' ) );
		$this->register( 'rest', IOTDW_REST_Connector::class, __( 'Generic REST', 'iot-data-widget' ) );

		// Poprawki po recenzji wp.org (Guideline 5, 2026-09-24) — Shelly
		// Cloud i MQTT są Pro WYŁĄCZNIE przez fizyczny brak tych klas w
		// paczce darmowej (patrz includes/premium/connectors/ i
		// includes/class-plugin.php::includes()), nie przez osobny
		// runtime license-check. class_exists() zamiast bezwarunkowej
		// rejestracji — na wersji darmowej te typy po prostu nie istnieją w
		// get_types(), więc nigdy nie pojawiają się w dropdownie ani nie
		// dają się zapisać przez ręczny POST z pominięciem UI.
		if ( class_exists( 'IOTDW_Shelly_Cloud_Connector' ) ) {
			$this->register( 'shelly_cloud', IOTDW_Shelly_Cloud_Connector::class, __( 'Shelly Cloud', 'iot-data-widget' ) );
		}

		if ( class_exists( 'IOTDW_MQTT_Polling_Connector' ) ) {
			$this->register( IOTDW_Cron_Value_Cache::CONNECTOR_TYPE, IOTDW_MQTT_Polling_Connector::class, __( 'MQTT (polling)', 'iot-data-widget' ) );
			$this->register( IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE, IOTDW_MQTT_Polling_Connector::class, __( 'MQTT (listener — requires server access)', 'iot-data-widget' ) );
		}

		// Push (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md) — urządzenie samo
		// wysyła dane do IOTDW_Push_Ingest zamiast czekać na odpytanie.
		$this->register( IOTDW_Push_Channel_Manager::CONNECTOR_TYPE, IOTDW_Push_Channel_Connector::class, __( 'Push (device sends data to your site)', 'iot-data-widget' ) );
	}

	/**
	 * Rejestruje typ konektora.
	 *
	 * @param string $type       Identyfikator typu, np. "home_assistant".
	 * @param string $class_name Pełna nazwa klasy implementującej IOTDW_Abstract_Connector.
	 * @param string $label      Czytelna nazwa do wyświetlenia w adminie.
	 */
	public function register( string $type, string $class_name, string $label ): void {
		$this->connectors[ $type ] = array(
			'class' => $class_name,
			'label' => $label,
		);
	}

	/**
	 * Zwraca zarejestrowane typy konektorów jako [typ => etykieta].
	 *
	 * @return array<string, string>
	 */
	public function get_types(): array {
		return wp_list_pluck( $this->connectors, 'label' );
	}

	/**
	 * Tworzy instancję konektora dla zapisanego połączenia. Przyjmuje całą
	 * tablicę połączenia (nie tylko URL+token), bo część konektorów (MQTT)
	 * potrzebuje więcej pól konfiguracyjnych niż HA/REST — każdy konektor
	 * sam wyciąga ze swojego konstruktora te klucze, których potrzebuje.
	 *
	 * @param array<string, mixed> $connection Połączenie (musi zawierać klucz 'connector').
	 * @return IOTDW_Abstract_Connector|null
	 */
	public function make( array $connection ): ?IOTDW_Abstract_Connector {
		$type = $connection['connector'] ?? '';

		if ( ! isset( $this->connectors[ $type ] ) ) {
			return null;
		}

		$class = $this->connectors[ $type ]['class'];

		return new $class( $connection );
	}

	/**
	 * Buduje tablicę configu połączenia z surowych pól formularza/żądania,
	 * dla danego typu konektora — WSPÓŁDZIELONE przez
	 * IOTDW_Admin_Page::handle_save() (zapis połączenia) i
	 * IOTDW_Rest_Api::test_connection() (v1.4 Tydzień 2, test przed
	 * zapisem), żeby nie utrzymywać dwóch kopii tego samego mapowania pól
	 * (patrz "Uwaga ogólna" w docs/archiwum/mvp-v1-4-plan.md). Świadomie NIE
	 * obsługuje "puste pole = zachowaj obecny sekret przy edycji" — to
	 * zostaje wyłącznie w IOTDW_Admin_Page::handle_save(), właściwe tylko
	 * dla zapisu istniejącego połączenia, nie dla efemerycznego testu bez
	 * zapisu (kreator z v1.4 tworzy tylko nowe połączenia, patrz Ustalenie #1
	 * w planie). Sama decyduje, czy pole 'preset' dla typu "rest" ma się
	 * liczyć na podstawie 'rest_mode' w $fields (code review v1.8) — obaj
	 * wywołujący dostają tę regułę za darmo, żadny nie musi jej pilnować.
	 *
	 * @param string               $connector_type Klucz zarejestrowanego typu konektora.
	 * @param array<string, mixed> $fields         Surowe pola (już odslashowane przez wywołującego, np. `wp_unslash( $_POST )` — ta metoda tylko sanitizuje typy/format).
	 * @return array<string, string> Config gotowy dla make() — zawsze zawiera klucz 'connector'.
	 */
	public function build_config( string $connector_type, array $fields ): array {
		$config = array(
			'connector' => $connector_type,
			'base_url'  => isset( $fields['base_url'] ) ? esc_url_raw( (string) $fields['base_url'] ) : '',
			'token'     => isset( $fields['token'] ) ? sanitize_text_field( (string) $fields['token'] ) : '',
			'preset'    => '',
			'device_ip' => '',
		);

		// Niepusty 'preset' sam w sobie NIE wystarcza (code review) — musi
		// się zgadzać jawne pole $rest_mode (patrz connector-form.php).
		// Wcześniej ten warunek sprawdzał tylko IOTDW_Admin_Page::handle_save()
		// (przez ręczne unset() PRZED wywołaniem tej metody), a
		// IOTDW_Rest_Api::test_connection() — drugi z dwóch wywołujących,
		// dla których ta metoda jest jawnie WSPÓŁDZIELONA — nie miał tego
		// zabezpieczenia wcale: test mógł po cichu zwalidować zupełnie inny
		// host niż ten, który faktycznie trafiał do zapisu. Sprawdzenie
		// przeniesione TUTAJ, do jedynego miejsca, gdzie ta reguła żyje,
		// zamiast wymagać, żeby każdy wywołujący pamiętał o niej osobno.
		$rest_mode = isset( $fields['rest_mode'] ) ? sanitize_key( (string) $fields['rest_mode'] ) : 'advanced';

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro
		// (includes/premium/class-connector-presets.php); na wersji
		// darmowej ten plik fizycznie nie istnieje, więc tryb "preset" po
		// prostu degraduje się do pustego base_url (walidacja niżej w
		// IOTDW_Admin_Page odrzuci taki zapis jako niekompletny) zamiast
		// fatal errora przy ręcznym POST z pominięciem UI.
		if ( 'rest' === $connector_type && 'preset' === $rest_mode && ! empty( $fields['preset'] ) && class_exists( 'IOTDW_Connector_Presets' ) ) {
			$config['preset']    = sanitize_key( (string) $fields['preset'] );
			$config['device_ip'] = isset( $fields['device_ip'] ) ? sanitize_text_field( (string) $fields['device_ip'] ) : '';
			// base_url jest wyliczany z presetu + IP, nie brany wprost z
			// (w tym trybie nieużywanego) pola base_url.
			$config['base_url'] = IOTDW_Connector_Presets::build_url( $config['preset'], $config['device_ip'] );
		}

		if ( 'shelly_cloud' === $connector_type ) {
			$config['server_uri'] = isset( $fields['server_uri'] ) ? esc_url_raw( (string) $fields['server_uri'] ) : '';
			$config['auth_key']   = isset( $fields['auth_key'] ) ? sanitize_text_field( (string) $fields['auth_key'] ) : '';
			$config['device_id']  = isset( $fields['device_id'] ) ? sanitize_text_field( (string) $fields['device_id'] ) : '';
		}

		$is_mqtt_connector = in_array(
			$connector_type,
			array( IOTDW_Cron_Value_Cache::CONNECTOR_TYPE, IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE ),
			true
		);

		if ( $is_mqtt_connector ) {
			// Listener nie ma wyboru trybu dostępu — zawsze bezpośrednie
			// połączenie z brokerem (ten sam wzorzec co IOTDW_Admin_Page::handle_save()).
			$config['mqtt_transport'] = IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE === $connector_type
				? 'socket'
				: ( isset( $fields['mqtt_transport'] ) && 'emqx_rest' === $fields['mqtt_transport'] ? 'emqx_rest' : 'socket' );
			$config['mqtt_host']      = isset( $fields['mqtt_host'] ) ? sanitize_text_field( (string) $fields['mqtt_host'] ) : '';
			$config['mqtt_port']      = isset( $fields['mqtt_port'] ) ? (string) absint( $fields['mqtt_port'] ) : '';
			$config['mqtt_tls']       = ! empty( $fields['mqtt_tls'] ) ? '1' : '';
			$config['mqtt_username']  = isset( $fields['mqtt_username'] ) ? sanitize_text_field( (string) $fields['mqtt_username'] ) : '';
			$config['mqtt_password']  = isset( $fields['mqtt_password'] ) ? sanitize_text_field( (string) $fields['mqtt_password'] ) : '';
		}

		return $config;
	}

	/**
	 * Zwraca połączenia skonfigurowane przez użytkownika w panelu admina —
	 * pola-sekrety (patrz secret_fields_for()) deszyfrowane tutaj, więc
	 * KAŻDY inny kod w wtyczce (konektory, kreator, formularz edycji)
	 * zawsze dostaje wartość jawną, bez wiedzy o samym istnieniu
	 * szyfrowania. Wartości sprzed wdrożenia szyfrowania (bez prefiksu
	 * IOTDW_Credential_Cipher::PREFIX) przechodzą przez decrypt()
	 * niezmienione — patrz docblock tej metody i
	 * docs/archiwum/iot-data-widget-poswiadczenia.md.
	 *
	 * @return array<int, array<string, string>>
	 */
	public function get_connections(): array {
		$connections = get_option( self::CONNECTIONS_OPTION, array() );

		if ( ! is_array( $connections ) ) {
			return array();
		}

		return array_map( array( $this, 'decrypt_secret_fields' ), $connections );
	}

	/**
	 * Zapisuje listę połączeń — pola-sekrety szyfrowane tutaj, więc
	 * wywołujący (formularz zapisu połączenia, import configu, migracja)
	 * zawsze przekazuje wartości jawne, tak jak dotychczas.
	 *
	 * Poprawki po recenzji wp.org (Guideline 5, 2026-09-24) — dawny limit
	 * połączeń wersji darmowej usunięty w całości (był runtime license-checkiem,
	 * nie fizycznym ograniczeniem — zakazany wzorzec "trialware"). Wersja
	 * darmowa nie ma już żadnego limitu liczby połączeń.
	 *
	 * @param array<int, array<string, string>> $connections Lista połączeń, wartości sekretów JAWNE.
	 * @return bool Zawsze true — zachowane jako typ zwrotny dla zgodności z
	 *              dotychczasowymi wywołującymi, które sprawdzają wynik.
	 */
	public function save_connections( array $connections ): bool {
		update_option( self::CONNECTIONS_OPTION, array_map( array( $this, 'encrypt_secret_fields' ), $connections ) );

		return true;
	}

	/**
	 * Pola, których wartość jest sekretem dla danego połączenia — zależnie
	 * od typu konektora/presetu, nie stała lista, bo Supla ("link
	 * bezpośredni") ma sekret wbudowany wprost w `base_url`, nie w osobnym
	 * polu jak reszta (patrz docs/archiwum/iot-data-widget-poswiadczenia.md, sekcja
	 * "Co jest dziś przechowywane jawnie").
	 *
	 * @param array<string, mixed> $connection Połączenie.
	 * @return string[]
	 */
	private function secret_fields_for( array $connection ): array {
		$fields = array( 'token', 'auth_key', 'mqtt_password' );

		$preset = (string) ( $connection['preset'] ?? '' );

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro; na
		// wersji darmowej ten plik fizycznie nie istnieje.
		if ( '' !== $preset && class_exists( 'IOTDW_Connector_Presets' ) ) {
			$preset_definition = IOTDW_Connector_Presets::get( $preset );

			if ( null !== $preset_definition && ! empty( $preset_definition['direct_url'] ) ) {
				$fields[] = 'base_url';
			}
		}

		return $fields;
	}

	/**
	 * Szyfruje pola-sekrety połączenia (patrz secret_fields_for()).
	 *
	 * @param array<string, mixed> $connection Połączenie z polami-sekretami JAWNYMI.
	 * @return array<string, mixed> Połączenie z polami-sekretami ZASZYFROWANYMI.
	 */
	private function encrypt_secret_fields( array $connection ): array {
		foreach ( $this->secret_fields_for( $connection ) as $field ) {
			if ( isset( $connection[ $field ] ) && is_string( $connection[ $field ] ) ) {
				$connection[ $field ] = IOTDW_Credential_Cipher::encrypt( $connection[ $field ] );
			}
		}

		return $connection;
	}

	/**
	 * Deszyfruje pola-sekrety połączenia (patrz secret_fields_for()).
	 *
	 * @param array<string, mixed> $connection Połączenie z polami-sekretami ZASZYFROWANYMI (albo starym jawnym tekstem).
	 * @return array<string, mixed> Połączenie z polami-sekretami JAWNYMI.
	 */
	private function decrypt_secret_fields( array $connection ): array {
		foreach ( $this->secret_fields_for( $connection ) as $field ) {
			if ( isset( $connection[ $field ] ) && is_string( $connection[ $field ] ) ) {
				$connection[ $field ] = IOTDW_Credential_Cipher::decrypt( $connection[ $field ] );
			}
		}

		return $connection;
	}

	/**
	 * Znajduje połączenie po ID albo (pomocniczo, dla wygody w shortcode) po nazwie.
	 *
	 * @param string $id_or_name ID lub nazwa połączenia.
	 * @return array<string, string>|null
	 */
	public function find_by_id_or_name( string $id_or_name ): ?array {
		foreach ( $this->get_connections() as $connection ) {
			if ( $connection['id'] === $id_or_name || $connection['name'] === $id_or_name ) {
				return $connection;
			}
		}

		return null;
	}

	/**
	 * Egzekwuje minimalny bezpieczny TTL cache dla danego połączenia —
	 * pułapuje $requested_ttl W GÓRĘ (nigdy w dół), łącząc globalny floor
	 * (GLOBAL_MIN_POLL_TTL) z ewentualnym, surowszym limitem specyficznym
	 * dla presetu (IOTDW_Connector_Presets::get_min_ttl(), np. Tasmota
	 * 60s). Zgłoszenie 2026-08-31 ("P1#8").
	 *
	 * Push i MQTT (polling ORAZ listener) są z tego floora CAŁKOWICIE
	 * wyłączone — ich fetch_data() czyta z lokalnego magazynu (transient/
	 * tabela wypełniana przez WP-Cron albo osobny proces nasłuchujący),
	 * nigdy nie robi synchronicznego żądania HTTP podczas renderowania
	 * strony, więc częstsze odczyty nie obciążają żadnego zewnętrznego
	 * urządzenia — floor tu tylko sztucznie spowalniałby "żywy" charakter
	 * tych trybów bez żadnej korzyści.
	 *
	 * @param int                  $requested_ttl TTL zażądany przez shortcode/blok (atrybut 'ttl') albo domyślny endpointu.
	 * @param array<string, mixed> $connection    Połączenie (musi zawierać 'connector', opcjonalnie 'preset').
	 * @return int
	 */
	public static function enforce_min_ttl( int $requested_ttl, array $connection ): int {
		$connector_type = (string) ( $connection['connector'] ?? '' );

		$no_floor_types = array(
			IOTDW_Cron_Value_Cache::CONNECTOR_TYPE,
			IOTDW_Cron_Value_Cache::LISTENER_CONNECTOR_TYPE,
			IOTDW_Push_Channel_Manager::CONNECTOR_TYPE,
		);

		if ( in_array( $connector_type, $no_floor_types, true ) ) {
			return $requested_ttl;
		}

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro; na
		// wersji darmowej ten plik fizycznie nie istnieje, więc brak presetu
		// specyficznego floora (nadal chroni GLOBAL_MIN_POLL_TTL niżej).
		$preset_min_ttl = class_exists( 'IOTDW_Connector_Presets' )
			? IOTDW_Connector_Presets::get_min_ttl( (string) ( $connection['preset'] ?? '' ) )
			: 0;

		return max( $requested_ttl, self::GLOBAL_MIN_POLL_TTL, $preset_min_ttl );
	}

	/**
	 * Zgłoszenie z listy do premiery (blok 2.1) — weryfikacja hybrydowego
	 * doładowania (widget-refresh.js) na żywym wp-env ujawniła, że kanały
	 * push były cache'owane pod tym samym kluczem/TTL co SSR
	 * (IOTDW_Shortcode::fetch_cached() i IOTDW_Rest_Api::get_widget_data()
	 * celowo dzielą klucz `connection_id|entity_id`, żeby refresh zwykle
	 * trafiał w cache-hit — słuszne dla trybu pull, bo oszczędza żądanie do
	 * fizycznego urządzenia). Dla push fetch_data() NIGDY nie robi żądania
	 * sieciowego (czysty odczyt z `iotdw_push_entities`, patrz
	 * IOTDW_Push_Channel_Connector) — nie ma tam niczego do ochrony przed
	 * odpytywaniem, a cache tylko sztucznie opóźniał widoczność świeżo
	 * wysłanej wartości o czas do MINUTY (IOTDW_Cache::DEFAULT_TTL) i
	 * czynił hybrydowe doładowanie bezużytecznym dla tego trybu (refresh
	 * trafiał w ten sam, wciąż ważny wpis cache co SSR). Konektory z tego
	 * samego powodu wyłączone z enforce_min_ttl() (MQTT) NIE są tu
	 * dołączone celowo: ich fetch_data() czyta z tabeli wypełnianej przez
	 * WP-Cron/nasłuch w tle na WŁASNym interwale, więc dodatkowe
	 * cache'owanie renderu nie wprowadza mierzalnej dodatkowej staleness
	 * ponad to, co już wynika z tego interwału — tylko push ma zerową
	 * inherentną staleness, którą ten cache psuje.
	 *
	 * @param array<string, mixed> $connection Połączenie (musi zawierać 'connector').
	 * @return bool
	 */
	public static function bypasses_render_cache( array $connection ): bool {
		return IOTDW_Push_Channel_Manager::CONNECTOR_TYPE === (string) ( $connection['connector'] ?? '' );
	}

	/**
	 * Buduje gotowy do użycia konektor dla zapisanego połączenia.
	 *
	 * @param string $id_or_name ID lub nazwa połączenia.
	 * @return IOTDW_Abstract_Connector|null
	 */
	public function get_connector_for( string $id_or_name ): ?IOTDW_Abstract_Connector {
		$connection = $this->find_by_id_or_name( $id_or_name );

		if ( null === $connection ) {
			return null;
		}

		return $this->make( $connection );
	}
}
