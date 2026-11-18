<?php
/**
 * Mapowanie stanów (1.0.4, wersja darmowa — decyzja właściciela 2026-10-08):
 * reguły per widget „wartość albo zakres → tekst + kolor statusu”,
 * rozszerzenie istniejących on_label/off_label.
 *
 * Format (atrybut shortcode'u states=, ten sam tekst zapisuje blok):
 * reguły rozdzielone średnikiem, w każdej warunek:tekst:kolor —
 *   states="0:Wyłączony:red;1:Działa:green"
 *   states="..18:Zimno:grey;18..24:OK:green;24..:Gorąco:red"
 * Warunek: liczba (równa), "a..b" (a ≤ v < b), "..b" (v < b), "a.." (v ≥ a)
 * albo tekst stanu (1.0.6, np. "heat", "off" — bez rozróżniania wielkości
 * liter, porównywany z surowym tekstem stanu z konektora, nie z tłumaczeniem):
 *   states="heat:Grzanie:green;cool:Chłodzenie:green;off:Wyłączona:grey"
 * Tekst i kolor opcjonalne (np. "24..::red" — sam kolor, wartość zostaje).
 * Kolory: green, yellow, red, grey. Najwyżej MAX_RULES reguł; pierwsza
 * pasująca wygrywa. Bez reguł widget wygląda dokładnie jak w 1.0.3.
 *
 * @package IoT_Data_Widget
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class IOTDW_State_Rules
 */
class IOTDW_State_Rules {

	/**
	 * Najwięcej reguł na widget (brief 1.0.4).
	 */
	const MAX_RULES = 5;

	/**
	 * Dozwolone kolory statusu.
	 */
	const COLORS = array( 'green', 'yellow', 'red', 'grey' );

	/**
	 * Parsuje tekst reguł. Reguły z błędem są pomijane (patrz invalid()).
	 *
	 * @param string $raw Tekst atrybutu states.
	 * @return array<int, array{min: float|null, max: float|null, equals: float|null, equals_text: string|null, text: string, color: string}>
	 */
	public static function parse( string $raw ): array {
		$rules = array();

		foreach ( self::split( $raw ) as $part ) {
			$rule = self::parse_rule( $part );

			if ( null !== $rule ) {
				$rules[] = $rule;
			}

			if ( count( $rules ) >= self::MAX_RULES ) {
				break;
			}
		}

		return $rules;
	}

	/**
	 * Reguły, których nie dało się odczytać (do komunikatu dla administratora).
	 *
	 * @param string $raw Tekst atrybutu states.
	 * @return string[]
	 */
	public static function invalid( string $raw ): array {
		return array_values(
			array_filter(
				self::split( $raw ),
				static fn( string $part ): bool => null === self::parse_rule( $part )
			)
		);
	}

	/**
	 * Pierwsza reguła pasująca do wartości albo null.
	 *
	 * @param array<int, array<string, mixed>> $rules Reguły z parse().
	 * @param mixed                            $value Wartość (kontrakt konektora: liczba).
	 * @param string|null                      $state Surowy tekst stanu z konektora (display_value) — dla reguł tekstowych.
	 * @return array{min: float|null, max: float|null, equals: float|null, equals_text: string|null, text: string, color: string}|null
	 */
	public static function match( array $rules, $value, ?string $state = null ): ?array {
		$state   = null === $state ? null : strtolower( trim( $state ) );
		$numeric = is_numeric( $value );
		$value   = $numeric ? (float) $value : 0.0;

		foreach ( $rules as $rule ) {
			if ( null !== ( $rule['equals_text'] ?? null ) ) {
				if ( null !== $state && '' !== $state && $state === $rule['equals_text'] ) {
					return $rule;
				}
				continue;
			}

			if ( ! $numeric ) {
				continue;
			}

			if ( null !== $rule['equals'] ) {
				if ( abs( $value - $rule['equals'] ) < 1e-9 ) {
					return $rule;
				}
				continue;
			}

			if ( ( null === $rule['min'] || $value >= $rule['min'] ) && ( null === $rule['max'] || $value < $rule['max'] ) ) {
				return $rule;
			}
		}

		return null;
	}

	/**
	 * Dokłada do danych widgetu wynik reguł: state_text / state_color
	 * (pasująca reguła) i state_rules (do odświeżania na froncie). Bez reguł
	 * albo przy błędzie danych — dane bez zmian.
	 *
	 * @param array  $data Znormalizowane dane (kontrakt konektora).
	 * @param string $raw  Tekst atrybutu states.
	 * @return array
	 */
	public static function apply( array $data, string $raw ): array {
		$rules = self::parse( $raw );

		if ( empty( $rules ) || ! empty( $data['error'] ) ) {
			return $data;
		}

		$data['state_rules'] = $rules;
		$value               = $data['value'] ?? null;
		$state               = isset( $data['display_value'] ) && '' !== (string) $data['display_value']
			? (string) $data['display_value']
			: ( is_string( $value ) && ! is_numeric( $value ) ? $value : null );
		$rule                = self::match( $rules, $value, $state );

		if ( null !== $rule ) {
			$data['state_text']  = $rule['text'];
			$data['state_color'] = $rule['color'];
		}

		return $data;
	}

	/**
	 * Nazwa koloru dla czytników ekranu (kolor nigdy nie jest jedyną
	 * informacją, gdy reguła nie ma tekstu).
	 *
	 * @param string $color Kolor.
	 * @return string
	 */
	public static function color_label( string $color ): string {
		$labels = array(
			'green'  => __( 'green', 'iot-data-widget' ),
			'yellow' => __( 'yellow', 'iot-data-widget' ),
			'red'    => __( 'red', 'iot-data-widget' ),
			'grey'   => __( 'grey', 'iot-data-widget' ),
		);

		return $labels[ $color ] ?? $color;
	}

	/**
	 * Dzieli tekst na reguły.
	 *
	 * @param string $raw Tekst.
	 * @return string[]
	 */
	private static function split( string $raw ): array {
		return array_values( array_filter( array_map( 'trim', explode( ';', $raw ) ), 'strlen' ) );
	}

	/**
	 * Jedna reguła "warunek:tekst:kolor" albo null przy błędzie.
	 *
	 * @param string $part Tekst reguły.
	 * @return array{min: float|null, max: float|null, equals: float|null, equals_text: string|null, text: string, color: string}|null
	 */
	private static function parse_rule( string $part ) {
		$pieces    = array_map( 'trim', explode( ':', $part ) );
		$raw_cond  = $pieces[0];
		$condition = str_replace( ',', '.', $raw_cond );
		$text      = sanitize_text_field( $pieces[1] ?? '' );
		$color     = strtolower( $pieces[2] ?? '' );
		$color     = 'gray' === $color ? 'grey' : $color;

		if ( count( $pieces ) > 3 || ( '' !== $color && ! in_array( $color, self::COLORS, true ) ) || ( '' === $text && '' === $color ) ) {
			return null;
		}

		$rule = array(
			'min'         => null,
			'max'         => null,
			'equals'      => null,
			'equals_text' => null,
			'text'        => $text,
			'color'       => $color,
		);

		if ( false === strpos( $condition, '..' ) ) {
			if ( ! is_numeric( $condition ) ) {
				$state = strtolower( sanitize_text_field( $raw_cond ) );

				if ( '' === $state ) {
					return null;
				}

				$rule['equals_text'] = $state;

				return $rule;
			}

			$rule['equals'] = (float) $condition;

			return $rule;
		}

		list( $min, $max ) = array_map( 'trim', explode( '..', $condition, 2 ) );

		if ( ( '' === $min && '' === $max ) || ( '' !== $min && ! is_numeric( $min ) ) || ( '' !== $max && ! is_numeric( $max ) ) ) {
			return null;
		}

		$rule['min'] = '' === $min ? null : (float) $min;
		$rule['max'] = '' === $max ? null : (float) $max;

		if ( null !== $rule['min'] && null !== $rule['max'] && $rule['min'] >= $rule['max'] ) {
			return null;
		}

		return $rule;
	}
}
