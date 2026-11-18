<?php
/**
 * Normalizuje surowe wartości konektorów do kontraktu 'value'
 * (zawsze liczba, jedyne pole zapisywane do historii — DECIMAL(12,4)) +
 * opcjonalnego 'display_value' (czytelny tekst do pokazania zamiast
 * value+unit w stylach karta/lista). Patrz CLAUDE.md, sekcja "Kontrakt
 * danych konektora".
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Value_Normalizer
 *
 * Współdzielona między konektorami (HA — Tydzień 2 v1.2; docelowo REST
 * presety Shelly/Tasmota — Tygodnie 3+) — jedno miejsce z regułami
 * mapowania stanów tekstowych na liczby, żeby nie duplikować tej logiki.
 */
class IOTDW_Value_Normalizer {

	/**
	 * Stany mapowane na 1 ("włączone"/"aktywne"/prawda). Celowo praktyczny,
	 * nie wyczerpujący zestaw — pokrywa najczęstsze domeny/device_class HA
	 * (binary_sensor, person, lock, cover) i typowe stany urządzeń IoT
	 * (Shelly/Tasmota), nie każdy możliwy device_class.
	 *
	 * @var string[]
	 */
	const TRUE_STATES = array( 'on', 'true', 'home', 'open', 'opening', 'locked', 'detected', 'active', 'yes', 'wet' );

	/**
	 * Stany mapowane na 0 ("wyłączone"/"nieaktywne"/fałsz).
	 *
	 * @var string[]
	 */
	const FALSE_STATES = array( 'off', 'false', 'not_home', 'away', 'closed', 'closing', 'unlocked', 'clear', 'inactive', 'no', 'dry' );

	/**
	 * Stany "brak odczytu" (HA unavailable/unknown, puste wartości z JSON) —
	 * nie są pomiarem: nie trafiają do historii ani nie nadpisują ostatniej
	 * znanej wartości (przegląd demo 2026-10-22: niedostępny czujnik
	 * zapisywał się jako 0 °C).
	 *
	 * @var string[]
	 */
	const UNAVAILABLE_STATES = array( 'unavailable', 'unknown', 'none', 'null', 'nan', 'n/a' );

	/**
	 * Czy surowa wartość to stan "brak odczytu" (patrz UNAVAILABLE_STATES).
	 *
	 * @param mixed $raw_value Surowa wartość/tekst stanu.
	 * @return bool
	 */
	public static function is_unavailable( $raw_value ): bool {
		if ( ! is_string( $raw_value ) ) {
			return false;
		}

		return in_array( strtolower( trim( $raw_value ) ), self::UNAVAILABLE_STATES, true );
	}

	/**
	 * Normalizuje surową wartość ze źródła danych.
	 *
	 * Liczby (int/float/liczbowy string) przechodzą bez zmian, bez
	 * display_value — dokładnie dzisiejsze zachowanie, zero regresji dla
	 * encji numerycznych. Rozpoznane stany tekstowe (patrz TRUE_STATES/
	 * FALSE_STATES) mapują się na 0/1 w 'value', z oryginalnym tekstem w
	 * 'display_value'. Nierozpoznane wartości tekstowe (np. "unavailable",
	 * dowolny stan enumeratywny spoza słownika) przechodzą BEZ ZMIAN — tak
	 * jak dziś: karta/lista pokażą surowy tekst, a historia/wykres i tak je
	 * pomijają (is_numeric() już to filtruje, patrz
	 * IOTDW_History_Recorder::record_snapshot()) — ta klasa niczego tu nie
	 * psuje ani nie naprawia, tylko nie zgaduje mapowania, którego nie zna.
	 *
	 * @param mixed $raw_value Surowa wartość z konektora (np. stan encji HA).
	 * @return array{value: mixed, display_value: string|null}
	 */
	public static function normalize( $raw_value ): array {
		if ( is_numeric( $raw_value ) ) {
			return array(
				'value'         => (float) $raw_value,
				'display_value' => null,
			);
		}

		$text  = is_bool( $raw_value ) ? ( $raw_value ? 'true' : 'false' ) : (string) $raw_value;
		$state = strtolower( trim( $text ) );

		if ( in_array( $state, self::TRUE_STATES, true ) ) {
			return array(
				'value'         => 1.0,
				'display_value' => $text,
			);
		}

		if ( in_array( $state, self::FALSE_STATES, true ) ) {
			return array(
				'value'         => 0.0,
				'display_value' => $text,
			);
		}

		return array(
			'value'         => $raw_value,
			'display_value' => null,
		);
	}

	/**
	 * Tekst stanu do pokazania człowiekowi: dosłowne "true"/"false" (surowy
	 * bool z JSON, np. Shelly switch:0.output) oraz "on"/"off" w dowolnej
	 * wielkości liter (HA, Tasmota, ESPHome — przegląd demo 2026-10-22: na
	 * polskiej stronie widać było „off”) → przetłumaczone "On"/"Off". Każdy
	 * inny tekst (HA "home", "locked", "heat"...) wraca bez zmian. Nie dla
	 * webhooków — tam display_value zostaje surowe (kontrakt dla odbiorców
	 * maszynowych).
	 *
	 * @param string $display_value display_value z konektora.
	 * @return string
	 */
	public static function human_display( string $display_value ): string {
		switch ( strtolower( trim( $display_value ) ) ) {
			case 'true':
			case 'on':
				return __( 'On', 'iot-data-widget' );
			case 'false':
			case 'off':
				return __( 'Off', 'iot-data-widget' );
			default:
				return $display_value;
		}
	}
}
