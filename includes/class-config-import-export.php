<?php
/**
 * Eksport/import konfiguracji połączeń — logika czysta (bez $_POST/$_FILES),
 * testowalna niezależnie od panelu admina (patrz IOTDW_Settings_Page, który
 * jest tylko cienkim kontrolerem wokół tych metod).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Config_Import_Export
 */
class IOTDW_Config_Import_Export {

	/**
	 * Wersja formatu eksportu — do ewentualnej przyszłej migracji formatu.
	 */
	const EXPORT_VERSION = 1;

	/**
	 * Pola nigdy nie trafiające do eksportu w czystym tekście — zamiast
	 * szyfrowania (które i tak nie przetrwałoby przenosin między
	 * instalacjami z różnymi kluczami wp-config.php) po prostu je czyścimy;
	 * admin wpisuje je ponownie po imporcie. Prostsze i bezpieczniejsze niż
	 * fałszywe poczucie bezpieczeństwa ze słabego szyfrowania.
	 *
	 * @var string[]
	 */
	const REDACTED_FIELDS = array( 'token', 'mqtt_password', 'auth_key' );

	/**
	 * Buduje strukturę do eksportu — tokeny/hasła zredagowane, reszta pól
	 * połączenia zachowana bez zmian.
	 *
	 * @param array<int, array<string, mixed>> $connections Zapisane połączenia.
	 * @return array{plugin: string, export_version: int, exported_at: string, connections: array}
	 */
	public static function build_export( array $connections ): array {
		return array(
			'plugin'         => 'iot-data-widget',
			'export_version' => self::EXPORT_VERSION,
			'exported_at'    => gmdate( 'c' ),
			'connections'    => array_map( array( __CLASS__, 'redact_connection' ), $connections ),
		);
	}

	/**
	 * Zwraca kopię połączenia z wrażliwymi polami wyczyszczonymi.
	 *
	 * Zgłoszenie 2026-09-07 (docs/archiwum/iot-data-widget-poswiadczenia.md) — Supla
	 * "link bezpośredni" ma sekret wbudowany wprost w `base_url`, nie w
	 * jednym z REDACTED_FIELDS. Bez tej poprawki eksport configu wynosiłby
	 * ten sekret w postaci jawnej, mimo że token/auth_key/mqtt_password były
	 * już wtedy poprawnie czyszczone dla wszystkich innych presetów — ta
	 * sama logika co IOTDW_Connector_Manager::secret_fields_for().
	 *
	 * @param array<string, mixed> $connection Połączenie.
	 * @return array<string, mixed>
	 */
	private static function redact_connection( array $connection ): array {
		$fields = self::REDACTED_FIELDS;

		$preset = (string) ( $connection['preset'] ?? '' );

		// class_exists() (Guideline 5, 2026-09-24) — presety są Pro; na
		// wersji darmowej ten plik fizycznie nie istnieje. Bez tego guarda
		// eksport połączenia z zapisanym presetem (np. sprzed przejścia z
		// Pro na free) fatal-errorowałby zamiast po prostu pominąć redakcję
		// base_url specyficzną dla presetu.
		if ( '' !== $preset && class_exists( 'IOTDW_Connector_Presets' ) ) {
			$preset_definition = IOTDW_Connector_Presets::get( $preset );

			if ( null !== $preset_definition && ! empty( $preset_definition['direct_url'] ) ) {
				$fields[] = 'base_url';
			}
		}

		foreach ( $fields as $field ) {
			if ( array_key_exists( $field, $connection ) ) {
				$connection[ $field ] = '';
			}
		}

		return $connection;
	}

	/**
	 * Sprawdza ogólny kształt zdekodowanego JSON-a importu — zanim w ogóle
	 * spojrzymy na poszczególne połączenia.
	 *
	 * @param mixed $decoded Wynik json_decode( $content, true ).
	 * @return string[] Lista błędów (pusta = poprawny kształt).
	 */
	public static function validate_structure( $decoded ): array {
		if ( ! is_array( $decoded ) ) {
			return array( __( 'The file is not valid JSON.', 'iot-data-widget' ) );
		}

		if ( ! isset( $decoded['connections'] ) || ! is_array( $decoded['connections'] ) ) {
			return array( __( 'This does not look like an IoT Data Widget export — missing a "connections" list.', 'iot-data-widget' ) );
		}

		return array();
	}

	/**
	 * Dzieli wpisy połączeń z importu na poprawne i pominięte (z powodem) —
	 * jeden zepsuty wpis nie blokuje importu reszty.
	 *
	 * @param array<int, mixed> $connections_data Surowe wpisy z 'connections' importu.
	 * @param string[]          $known_types      Zarejestrowane typy konektorów (klucze z IOTDW_Connector_Manager::get_types()).
	 * @return array{valid: array<int, array<string, mixed>>, skipped: array<int, array{entry: mixed, reason: string}>}
	 */
	public static function partition_connections( array $connections_data, array $known_types ): array {
		$valid   = array();
		$skipped = array();

		foreach ( $connections_data as $entry ) {
			$reason = self::validate_connection_entry( $entry, $known_types );

			if ( null !== $reason ) {
				$skipped[] = array(
					'entry'  => $entry,
					'reason' => $reason,
				);
				continue;
			}

			$valid[] = $entry;
		}

		return array(
			'valid'   => $valid,
			'skipped' => $skipped,
		);
	}

	/**
	 * Waliduje pojedynczy wpis połączenia z importu.
	 *
	 * @param mixed    $entry       Wpis (oczekiwana tablica asocjacyjna).
	 * @param string[] $known_types Zarejestrowane typy konektorów.
	 * @return string|null Powód odrzucenia, albo null jeśli wpis jest poprawny.
	 */
	private static function validate_connection_entry( $entry, array $known_types ): ?string {
		if ( ! is_array( $entry ) ) {
			return __( 'Entry is not a valid object.', 'iot-data-widget' );
		}

		foreach ( array( 'id', 'name', 'connector' ) as $required_field ) {
			if ( empty( $entry[ $required_field ] ) ) {
				return sprintf(
					/* translators: %s: nazwa brakującego pola */
					__( 'Missing required field "%s".', 'iot-data-widget' ),
					$required_field
				);
			}
		}

		if ( ! in_array( $entry['connector'], $known_types, true ) ) {
			return sprintf(
				/* translators: %s: nierozpoznany typ konektora */
				__( 'Unknown connector type "%s".', 'iot-data-widget' ),
				(string) $entry['connector']
			);
		}

		return null;
	}

	/**
	 * Scala zaimportowane połączenia z istniejącymi — nadpisuje po ID,
	 * dopisuje nowe. Połączenia nieobecne w imporcie zostają nietknięte.
	 *
	 * @param array<int, array<string, mixed>> $existing Obecnie zapisane połączenia.
	 * @param array<int, array<string, mixed>> $imported Poprawne wpisy z importu (patrz partition_connections()).
	 * @return array<int, array<string, mixed>>
	 */
	public static function merge_connections( array $existing, array $imported ): array {
		$by_id = array();

		foreach ( $existing as $connection ) {
			$by_id[ $connection['id'] ] = $connection;
		}

		foreach ( $imported as $connection ) {
			$by_id[ $connection['id'] ] = $connection;
		}

		return array_values( $by_id );
	}
}
