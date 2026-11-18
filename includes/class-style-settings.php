<?php
/**
 * Ustawienia wyglądu widgetu (v1.5 Tydzień 4) — pojedynczy zestaw pięciu
 * wartości (kolor tła, tekstu, akcentu, promień zaokrąglenia, rozmiar
 * czcionki) tłumaczonych na zmienne CSS. Czysta klasa narzędziowa (bez
 * stanu, bez UI) — ten sam podział co IOTDW_Config_Import_Export /
 * IOTDW_Grid_Items: logika tutaj, ekran ustawień (Tydzień 5) i panel
 * override w edytorze bloku (Tydzień 6) budują się na tym osobno.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Style_Settings
 */
class IOTDW_Style_Settings {

	/**
	 * Nazwa opcji z globalnymi ustawieniami wyglądu.
	 */
	const OPTION_NAME = 'iotdw_style_settings';

	/**
	 * Wartości domyślne — dobrane tak, żeby odpowiadały temu, co dotąd było
	 * zahardkodowane w public/css/widget-styles.css, więc instalacja bez
	 * żadnej konfiguracji wygląda tak samo jak przed Tygodniem 4.
	 *
	 * @var array<string, string|int>
	 */
	const DEFAULTS = array(
		'bg_color'      => '#ffffff',
		'text_color'    => '#222222',
		'accent_color'  => '#2980b9',
		'border_radius' => 8,
		'font_size'     => 16,
	);

	/**
	 * Mapowanie klucz ustawienia -> nazwa zmiennej CSS (patrz public/css/widget-theme.css).
	 *
	 * @var array<string, string>
	 */
	const CSS_VAR_MAP = array(
		'bg_color'      => '--iot-dw-bg',
		'text_color'    => '--iot-dw-text',
		'accent_color'  => '--iot-dw-accent',
		'border_radius' => '--iot-dw-radius',
		'font_size'     => '--iot-dw-font-size',
	);

	/**
	 * Zwraca zapisane ustawienia globalne, uzupełnione domyślnymi wartościami
	 * i przepuszczone przez sanityzację (na wypadek ręcznie zmienionej albo
	 * uszkodzonej opcji w bazie).
	 *
	 * @return array<string, string|int>
	 */
	public static function get_settings(): array {
		$saved = get_option( self::OPTION_NAME, array() );

		return self::sanitize( wp_parse_args( is_array( $saved ) ? $saved : array(), self::DEFAULTS ) );
	}

	/**
	 * Sanityzuje pełny zestaw ustawień — wszystkie klucze z DEFAULTS muszą
	 * być obecne na wejściu (patrz wp_parse_args() w get_settings()).
	 *
	 * @param array<string, mixed> $settings Kandydat na zestaw ustawień.
	 * @return array<string, string|int>
	 */
	public static function sanitize( array $settings ): array {
		return array(
			'bg_color'      => self::sanitize_color( $settings['bg_color'] ?? '', (string) self::DEFAULTS['bg_color'] ),
			'text_color'    => self::sanitize_color( $settings['text_color'] ?? '', (string) self::DEFAULTS['text_color'] ),
			'accent_color'  => self::sanitize_color( $settings['accent_color'] ?? '', (string) self::DEFAULTS['accent_color'] ),
			'border_radius' => self::sanitize_radius( $settings['border_radius'] ?? null ),
			'font_size'     => self::sanitize_font_size( $settings['font_size'] ?? null ),
		);
	}

	/**
	 * Sanityzuje częściowe nadpisanie (override per widget, Tydzień 6) —
	 * w przeciwieństwie do sanitize(), brakujące/puste klucze są po prostu
	 * pomijane (nie podstawiane domyślną wartością), żeby override mógł
	 * dotyczyć tylko wybranych właściwości — reszta dziedziczy z ustawień
	 * globalnych przez normalne dziedziczenie zmiennych CSS.
	 *
	 * @param array<string, mixed> $overrides Kandydat na częściowe nadpisanie (np. surowe atrybuty shortcode'u).
	 * @return array<string, string|int>
	 */
	public static function sanitize_overrides( array $overrides ): array {
		$clean = array();

		foreach ( self::DEFAULTS as $key => $default ) {
			if ( ! isset( $overrides[ $key ] ) || '' === $overrides[ $key ] ) {
				continue;
			}

			if ( 'border_radius' === $key ) {
				$clean[ $key ] = self::sanitize_radius( $overrides[ $key ] );
			} elseif ( 'font_size' === $key ) {
				$clean[ $key ] = self::sanitize_font_size( $overrides[ $key ] );
			} else {
				$clean[ $key ] = self::sanitize_color( $overrides[ $key ], (string) $default );
			}
		}

		return $clean;
	}

	/**
	 * Sanityzuje pojedynczy kolor.
	 *
	 * @param mixed  $value    Kandydat na kolor.
	 * @param string $fallback Wartość zastępcza, gdy $value nie jest poprawnym kolorem hex.
	 * @return string
	 */
	private static function sanitize_color( $value, string $fallback ): string {
		$sanitized = sanitize_hex_color( is_string( $value ) ? $value : '' );

		return $sanitized ? $sanitized : $fallback;
	}

	/**
	 * Sanityzuje promień zaokrąglenia (px). Poza rozsądnym zakresem cichy
	 * clamp zamiast odrzucenia — to tylko kosmetyka, nie ma powodu blokować
	 * zapisu.
	 *
	 * @param mixed $value Kandydat na promień zaokrąglenia (px).
	 * @return int
	 */
	private static function sanitize_radius( $value ): int {
		return max( 0, min( 40, (int) $value ) );
	}

	/**
	 * Sanityzuje rozmiar czcionki (px). Poza rozsądnym zakresem cały wpis
	 * wraca do domyślnej wartości (a nie clamp) — zbyt mała/duża czcionka to
	 * zwykle literówka w polu liczbowym, nie świadomy wybór.
	 *
	 * @param mixed $value Kandydat na rozmiar czcionki (px).
	 * @return int
	 */
	private static function sanitize_font_size( $value ): int {
		$size = (int) $value;

		return $size >= 10 && $size <= 32 ? $size : self::DEFAULTS['font_size'];
	}

	/**
	 * Tłumaczy zestaw ustawień (pełny albo częściowy, już zsanityzowany) na
	 * parę zmienna CSS -> sformatowana wartość gotowa do wypisania (kolory
	 * bez zmian, liczby z dopisanym "px").
	 *
	 * @param array<string, string|int> $settings Ustawienia, klucze z DEFAULTS.
	 * @return array<string, string>
	 */
	public static function css_variables( array $settings ): array {
		$variables = array();

		foreach ( self::CSS_VAR_MAP as $key => $css_var ) {
			if ( ! isset( $settings[ $key ] ) ) {
				continue;
			}

			$variables[ $css_var ] = in_array( $key, array( 'border_radius', 'font_size' ), true )
				? $settings[ $key ] . 'px'
				: $settings[ $key ];
		}

		return $variables;
	}

	/**
	 * Buduje regułę CSS gotową do wstrzyknięcia (np. przez wp_add_inline_style()).
	 * Wejście to zawsze już zsanityzowane wartości (kolor hex albo liczba+"px"
	 * z css_variables()) — esc_html() tutaj to tylko dodatkowa warstwa
	 * ostrożności, nie jedyna linia obrony.
	 *
	 * @param array<string, string> $css_variables Para zmienna CSS -> wartość (patrz css_variables()).
	 * @param string                $selector      Selektor CSS, domyślnie ":root".
	 * @return string
	 */
	public static function build_css_rule( array $css_variables, string $selector = ':root' ): string {
		if ( empty( $css_variables ) ) {
			return '';
		}

		$declarations = '';

		foreach ( $css_variables as $name => $value ) {
			$declarations .= sprintf( '%s:%s;', $name, esc_html( (string) $value ) );
		}

		return sprintf( '%s{%s}', $selector, $declarations );
	}
}
