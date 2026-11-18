<?php
/**
 * Generuje HTML widgetu na podstawie znormalizowanej struktury danych.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Renderer
 *
 * Operuje wyłącznie na znormalizowanej strukturze konektora (value/unit/
 * label/last_updated/error) — nigdy na surowej odpowiedzi API.
 */
class IOTDW_Renderer {

	/**
	 * Klucz w IOTDW_Settings_Page::OPTION_NAME z progiem "stare dane" w
	 * minutach (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md §5) — powyżej tego
	 * progu widget pokazuje wartość wyszarzoną ze znacznikiem czasu zamiast
	 * udawać świeżość. Dotyczy przede wszystkim kanałów push (urządzenie
	 * mogło przestać wysyłać dane bez żadnego jawnego błędu połączenia — w
	 * przeciwieństwie do pull, gdzie zerwane połączenie od razu daje czytelny
	 * błąd) — dla pull `last_updated` to zawsze "teraz" (świeże pobranie przy
	 * każdym renderze), więc próg praktycznie nigdy się tam nie aktywuje.
	 * Klucz w jednej wspólnej opcji (nie osobna opcja) — ten sam wzorzec co
	 * 'show_branding'/'remove_data_on_uninstall', patrz IOTDW_Settings_Page.
	 */
	const STALE_THRESHOLD_SETTING_KEY = 'stale_threshold_minutes';

	const DEFAULT_STALE_MINUTES = 15;

	/**
	 * Wszystkie nazwy stylów dopuszczalne w atrybucie style="" shortcode'u
	 * [iotdw_widget] — pojedyncza lista, do której odwołują się zarówno
	 * render() (błąd nieznanego stylu niżej), jak i IOTDW_Shortcode
	 * (te same nazwy w komunikatach błędów list/grid), żeby nie utrzymywać
	 * dwóch osobnych list, które mogłyby się rozjechać.
	 *
	 * Zgłoszenie z regresji (2026-09-10): nieznana nazwa stylu (np.
	 * "status_list" — pierwsze, co wpisałby ktoś ręcznie piszący shortcode,
	 * patrząc na etykietę "Status list"/"Lista statusów" w UI) cicho
	 * renderowała się jako "card", bez żadnego sygnału błędu — patrz
	 * gałąź default w switch() w render() niżej.
	 */
	const VALID_STYLES = array( 'card', 'list', 'gauge', 'chart', 'sparkline', 'grid' );

	/**
	 * Lista dozwolonych znaczników/atrybutów dla wp_kses() przy wypisywaniu
	 * gotowego markupu widgetu — dokładnie ten kształt, jaki render()/
	 * render_list()/render_grid() (i wariant Pro, IOTDW_Renderer_Chart) w
	 * ogóle emitują. Zgłoszenie recenzji wp.org (Runda 2, T2, 2026-09-27):
	 * "echo $var; // phpcs:ignore ... już zescape'owane w X()" nie wystarcza
	 * — recenzent wymaga faktycznej funkcji escapującej DOKŁADNIE w miejscu
	 * echo ("escaping late"), nie polegania na łańcuchu zaufania między
	 * metodami. Każda wartość wewnątrz i tak już przechodzi przez esc_html()/
	 * esc_attr() na etapie budowania (patrz render_value_content(),
	 * stale_badge_html(), icon_svg() itd.) — to dodatkowa, spójna bariera na
	 * granicy wyjścia. Współdzielone też przez admin/views/style-settings-page.php
	 * i admin/views/wizard/step-4-preview.php (wypisują already-built widget
	 * HTML zamiast budować go bezpośrednio w swoim pliku).
	 *
	 * @return array
	 */
	public static function allowed_widget_html(): array {
		$svg_attrs = array(
			'class'             => true,
			'fill'              => true,
			'stroke'            => true,
			'stroke-width'      => true,
			'stroke-linecap'    => true,
			'stroke-linejoin'   => true,
			'stroke-dasharray'  => true,
			'stroke-dashoffset' => true,
		);

		return array(
			'div'      => array(
				'class'                  => true,
				'style'                  => true,
				'data-iot-dw-connection' => true,
				'data-iot-dw-entity'     => true,
				'data-iot-dw-on-label'   => true,
				'data-iot-dw-off-label'  => true,
				'data-iot-dw-states'     => true,
				'data-iot-dw-unit'       => true,
				'data-iot-dw-min'        => true,
				'data-iot-dw-max'        => true,
				'data-iot-dw-chart'      => true,
			),
			'span'     => array(
				'class'       => true,
				'aria-hidden' => true,
			),
			'ul'       => array(
				'class'                  => true,
				'style'                  => true,
				'data-iot-dw-connection' => true,
				'data-iot-dw-entity'     => true,
			),
			'li'       => array(
				'class'                  => true,
				'data-iot-dw-connection' => true,
				'data-iot-dw-entity'     => true,
				'data-iot-dw-on-label'   => true,
				'data-iot-dw-off-label'  => true,
				'data-iot-dw-states'     => true,
				'data-iot-dw-unit'       => true,
			),
			'time'     => array(
				'class'    => true,
				'datetime' => true,
			),
			'table'    => array( 'class' => true ),
			'caption'  => array(),
			'thead'    => array(),
			'tbody'    => array(),
			'tr'       => array(),
			'th'       => array( 'scope' => true ),
			'td'       => array(),
			'canvas'   => array(
				'class'      => true,
				'role'       => true,
				'aria-label' => true,
			),
			'svg'      => array_merge(
				$svg_attrs,
				array(
					'viewbox'             => true,
					'preserveaspectratio' => true,
					'role'                => true,
					'aria-hidden'         => true,
					'aria-label'          => true,
					'xmlns'               => true,
				)
			),
			'g'        => $svg_attrs,
			'path'     => array_merge( $svg_attrs, array( 'd' => true ) ),
			'circle'   => array_merge(
				$svg_attrs,
				array(
					'cx' => true,
					'cy' => true,
					'r'  => true,
				)
			),
			'rect'     => array_merge(
				$svg_attrs,
				array(
					'x'      => true,
					'y'      => true,
					'width'  => true,
					'height' => true,
					'rx'     => true,
					'ry'     => true,
				)
			),
			'text'     => array_merge(
				$svg_attrs,
				array(
					'x'           => true,
					'y'           => true,
					'text-anchor' => true,
				)
			),
			'line'     => array_merge(
				$svg_attrs,
				array(
					'x1' => true,
					'y1' => true,
					'x2' => true,
					'y2' => true,
				)
			),
			'polyline' => array_merge( $svg_attrs, array( 'points' => true ) ),
		);
	}

	/**
	 * Renderuje widget pojedynczej encji w wybranym stylu ("card", "gauge",
	 * "chart" albo "sparkline"). Dla stylu "list" użyj render_list() —
	 * działa na kilku encjach naraz.
	 *
	 * @param array  $data  Znormalizowane dane (patrz kontrakt konektora).
	 * @param string $style Styl widgetu: "card", "gauge", "chart" albo "sparkline".
	 * @param array  $args  Dodatkowe opcje stylu (np. min/max dla gauge, widget_id dla chart, values dla sparkline,
	 *                      style_vars dla override wyglądu per widget — patrz IOTDW_Style_Settings::css_variables()).
	 * @return string
	 */
	public function render( array $data, string $style = 'card', array $args = array() ): string {
		if ( ! empty( $data['error'] ) ) {
			return $this->render_error( $data['error'], $data['upgrade_url'] ?? '' );
		}

		// Blok Gutenberga ukrywa Gauge/Chart/Mini trend dla encji o wartości
		// logicznej/tekstowej (display_value ustawione — patrz
		// IOTDW_Value_Normalizer i IOTDW_Push_Ingest::process_reading()),
		// ale to tylko UI edytora — shortcode wpisany ręcznie z
		// style="chart"/"gauge"/"sparkline" omija tę blokadę całkowicie
		// (zgłoszone, 2026-08-28: "przechodzi bez protestu"). Bez tej
		// asercji gauge/sparkline rysowały numeryczny placeholder (np. "1"
		// zamiast "ON" dla nierozpoznanego tekstu push) a chart — pusty,
		// niewypełniony <canvas>, bo render_chart() sam z siebie nie ma skąd
		// wziąć historii dla takiej pary connection/entity. Sprawdzane tu,
		// nie w poszczególnych render_*(), żeby styl błędu był identyczny z
		// każdym innym błędem tego widgetu (patrz komentarz render_error()).
		if ( in_array( $style, array( 'gauge', 'chart', 'sparkline' ), true ) && ! empty( $data['display_value'] ) ) {
			return $this->render_error(
				__( 'This entity holds an on/off-style value, not a continuous number — Gauge, Chart, and Mini trend aren\'t available for it. Use Status list (or Card) instead.', 'iot-data-widget' )
			);
		}

		$style_attr = self::style_attr( $args['style_vars'] ?? array() );

		switch ( $style ) {
			case 'gauge':
				return $this->render_gauge( $data, $args, $style_attr );
			case 'chart':
			case 'sparkline':
				return $this->render_history_style( $style, $data, $args, $style_attr );
			case 'card':
				return $this->render_card( $data, $style_attr );
			default:
				// Zgłoszenie z regresji (2026-09-10) — dawniej default cicho
				// renderował "card" dla DOWOLNEJ nierozpoznanej nazwy stylu
				// (np. "status_list", literówka najbardziej narzucająca się
				// komuś piszącemu shortcode ręcznie, patrząc na etykietę UI
				// "Status list"). Użytkownik dostawał widget, który wyglądał
				// poprawnie, w złym stylu, bez żadnego sygnału błędu. Teraz —
				// ten sam mechanizm co przy błędach connection/entity: czytelny
				// komunikat z listą dopuszczalnych nazw, widoczny tylko dla
				// adminów (patrz render_error()).
				return $this->render_error(
					sprintf(
						/* translators: 1: the unrecognized style name as typed in the shortcode, 2: comma-separated list of valid style names */
						__( 'Unknown style "%1$s" — valid styles are: %2$s.', 'iot-data-widget' ),
						$style,
						implode( ', ', self::VALID_STYLES )
					)
				);
		}
	}

	/**
	 * Punkt rozszerzenia Fazy 4 planu freemium
	 * (docs/archiwum/iot-data-widget-plan-freemium.md) — style "chart"/"sparkline"
	 * wymagają historii wartości i żyją w osobnym, wykluczalnym pliku
	 * (includes/premium/class-renderer-chart.php, klasa IOTDW_Renderer_Chart
	 * rozszerzająca tę klasę i nadpisująca WYŁĄCZNIE tę metodę). Ten
	 * (domyślny, rdzeniowy) wariant odpala się tylko wtedy, gdy tamten plik
	 * nie istnieje — IOTDW_Plugin::init_modules() decyduje, którą klasę
	 * zainstancjować, na podstawie file_exists(), patrz tam.
	 *
	 * Gauge (obok w switch() wyżej) CELOWO nie przechodzi przez ten
	 * mechanizm — nie dotyka historii, więc zostaje w rdzeniu bezwarunkowo
	 * (patrz Faza 0 planu, uzasadnienie podziału darmowa/Pro).
	 *
	 * @param string $style      "chart" albo "sparkline".
	 * @param array  $data       Znormalizowane dane.
	 * @param array  $args       Opcje stylu.
	 * @param string $style_attr Gotowy (już zescape'owany) atrybut style="" albo pusty string.
	 * @return string
	 */
	protected function render_history_style( string $style, array $data, array $args, string $style_attr ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- unused in this default; the premium override (IOTDW_Renderer_Chart) uses all four.
		return $this->render_error(
			__( 'This style requires the Pro version.', 'iot-data-widget' ),
			function_exists( 'idw_fs' ) ? idw_fs()->pricing_url() : ''
		);
	}

	/**
	 * Renderuje styl "Lista statusów" — kilka encji naraz. Błąd pojedynczej
	 * encji nie psuje całej listy, pokazuje się tylko w jej wierszu.
	 *
	 * @param array                $items      Lista elementów: znormalizowane dane + klucz 'entity' (ID encji, do etykiety błędu).
	 * @param array<string,string> $style_vars Override wyglądu per widget (patrz IOTDW_Style_Settings::css_variables()).
	 * @return string
	 */
	public function render_list( array $items, array $style_vars = array() ): string {
		$style_attr = self::style_attr( $style_vars );
		ob_start();
		?>
		<ul class="iot-dw-widget iot-dw-widget--list"<?php echo wp_kses( $style_attr, array() ); ?>>
			<?php foreach ( $items as $item ) : ?>
				<?php if ( ! empty( $item['error'] ) && ! current_user_can( 'manage_options' ) ) : ?>
					<?php continue; // Gościom pomijamy cały wiersz, nie tylko treść — bez pustej belki na liście. ?>
				<?php endif; ?>
				<li class="iot-dw-widget__list-item<?php echo esc_attr( empty( $item['error'] ) ? self::stale_class( $item ) : '' ); ?>"<?php echo wp_kses( ! empty( $item['error'] ) ? '' : self::refresh_data_attr( $item ), array() ); ?>>
					<?php if ( ! empty( $item['error'] ) ) : ?>
						<span class="iot-dw-widget__list-label"><?php echo esc_html( $item['entity'] ?? '' ); ?>:</span>
						<span class="iot-dw-widget__list-error">
							<?php echo wp_kses( self::error_icon_svg(), self::allowed_widget_html() ); ?>
							<?php echo esc_html( $item['error'] ); ?>
						</span>
					<?php else : ?>
						<span class="iot-dw-widget__list-label">
							<?php echo wp_kses( self::icon_svg( self::icon_for_entity( $item ) ), self::allowed_widget_html() ); ?>
							<?php echo esc_html( $item['label'] ?? '' ); ?>:
						</span>
						<span class="iot-dw-widget__list-value">
							<?php echo wp_kses( $this->render_value_content( $item ), self::allowed_widget_html() ); ?>
						</span>
						<?php echo wp_kses( self::stale_badge_html( $item ), self::allowed_widget_html() ); ?>
					<?php endif; ?>
				</li>
			<?php endforeach; ?>
		</ul>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renderuje styl "grid" (widget multi-encja, v1.3 Tydzień 4) — siatka
	 * komórek, każda jak styl "Karta". Świadomie jedna, wspólna prezentacja
	 * dla całej siatki (nie każda komórka nie ma osobnego wyboru stylu w tej
	 * wersji) — patrz uzasadnienie w docs/archiwum/mvp-v1-3-plan.md Tydzień 4. Błąd
	 * pojedynczej komórki nie psuje reszty siatki, tak jak w render_list().
	 *
	 * @param array                $items      Lista elementów: znormalizowane dane + klucz 'entity' (ID encji, do etykiety błędu).
	 * @param array<string,string> $style_vars Override wyglądu per widget (patrz IOTDW_Style_Settings::css_variables()).
	 * @return string
	 */
	public function render_grid( array $items, array $style_vars = array() ): string {
		$style_attr = self::style_attr( $style_vars );
		ob_start();
		?>
		<div class="iot-dw-widget iot-dw-widget--grid"<?php echo wp_kses( $style_attr, array() ); ?>>
			<?php foreach ( $items as $item ) : ?>
				<?php if ( ! empty( $item['error'] ) && ! current_user_can( 'manage_options' ) ) : ?>
					<?php continue; // Gościom pomijamy całą komórkę, nie tylko treść — bez pustej dziury w siatce. ?>
				<?php endif; ?>
				<?php if ( ! empty( $item['error'] ) ) : ?>
					<div class="iot-dw-widget__grid-cell iot-dw-widget__grid-cell--error">
						<span class="iot-dw-widget__label"><?php echo esc_html( $item['label'] ?? $item['entity'] ?? '' ); ?></span>
						<span class="iot-dw-widget__list-error">
							<?php echo wp_kses( self::error_icon_svg(), self::allowed_widget_html() ); ?>
							<?php echo esc_html( $item['error'] ); ?>
						</span>
					</div>
				<?php else : ?>
					<div class="iot-dw-widget__grid-cell<?php echo esc_attr( self::stale_class( $item ) ); ?>"<?php echo wp_kses( self::refresh_data_attr( $item ), array() ); ?>>
						<span class="iot-dw-widget__label"><?php echo esc_html( $item['label'] ?? '' ); ?></span>
						<span class="iot-dw-widget__value">
							<?php echo wp_kses( $this->render_value_content( $item ), self::allowed_widget_html() ); ?>
						</span>
						<?php echo wp_kses( self::stale_badge_html( $item ), self::allowed_widget_html() ); ?>
					</div>
				<?php endif; ?>
			<?php endforeach; ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renderuje styl "Karta": etykieta + wartość + jednostka (albo
	 * display_value, jeśli konektor je ustawił — patrz render_value_content()).
	 *
	 * @param array  $data       Znormalizowane dane.
	 * @param string $style_attr Gotowy (już zescape'owany) atrybut style="" albo pusty string — patrz style_attr().
	 * @return string
	 */
	private function render_card( array $data, string $style_attr = '' ): string {
		ob_start();
		?>
		<div class="iot-dw-widget iot-dw-widget--card<?php echo esc_attr( self::stale_class( $data ) ); ?>"<?php echo wp_kses( $style_attr . self::refresh_data_attr( $data ), array() ); ?>>
			<span class="iot-dw-widget__label">
				<?php echo wp_kses( self::icon_svg( self::icon_for_entity( $data ) ), self::allowed_widget_html() ); ?>
				<?php echo esc_html( $data['label'] ?? '' ); ?>
			</span>
			<span class="iot-dw-widget__value">
				<?php echo wp_kses( $this->render_value_content( $data ), self::allowed_widget_html() ); ?>
			</span>
			<?php echo wp_kses( self::stale_badge_html( $data ), self::allowed_widget_html() ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Buduje (już zescape'owaną) zawartość znacznika wartości: jeśli
	 * konektor ustawił 'display_value' (patrz IOTDW_Value_Normalizer),
	 * pokazujemy je zamiast value+unit — tak dla encji tekstowych (np. HA
	 * binary_sensor "on"/"off") karta/lista pokazują czytelny tekst, nie
	 * znormalizowane 0/1. Współdzielone przez render_card() i render_list()
	 * — jedyne style objęte tym mechanizmem (gauge/chart/sparkline zawsze
	 * pokazują liczbę, z natury tych wizualizacji).
	 *
	 * @param array $data Znormalizowane dane (pojedynczy odczyt albo jeden element listy).
	 * @return string
	 */
	private function render_value_content( array $data ): string {
		$dot = self::state_dot_html( $data );

		// Mapowanie stanów (1.0.4): tekst pasującej reguły zastępuje wartość.
		if ( ! empty( $data['state_text'] ) ) {
			return $dot . esc_html( $data['state_text'] );
		}

		if ( ! empty( $data['display_value'] ) ) {
			return $dot . esc_html( IOTDW_Value_Normalizer::human_display( (string) $data['display_value'] ) );
		}

		$html = esc_html( $this->format_value( $data['value'] ) );

		if ( ! empty( $data['unit'] ) ) {
			$html .= ' <span class="iot-dw-widget__unit">' . esc_html( $data['unit'] ) . '</span>';
		}

		return $dot . $html;
	}

	/**
	 * Kropka statusu z pasującej reguły (mapowanie stanów, 1.0.4). Kropka
	 * jest dekoracją (aria-hidden); gdy reguła nie ma tekstu, kolor podany
	 * też słowem dla czytników ekranu — kolor nigdy nie jest jedyną
	 * informacją.
	 *
	 * @param array $data Znormalizowane dane z ewentualnym state_color/state_text.
	 * @return string
	 */
	private static function state_dot_html( array $data ): string {
		if ( empty( $data['state_color'] ) || ! in_array( $data['state_color'], IOTDW_State_Rules::COLORS, true ) ) {
			return '';
		}

		$html = '<span class="iot-dw-widget__state-dot iot-dw-widget__state-dot--' . esc_attr( $data['state_color'] ) . '" aria-hidden="true"></span>';

		if ( empty( $data['state_text'] ) ) {
			/* translators: %s: status colour name, e.g. "green" */
			$html .= '<span class="iot-dw-widget__sr-only">' . esc_html( sprintf( __( 'Status: %s', 'iot-data-widget' ), IOTDW_State_Rules::color_label( $data['state_color'] ) ) ) . ' </span>';
		}

		return $html;
	}

	/**
	 * Linia statusu pod gauge'em (mapowanie stanów, 1.0.4) — gauge pokazuje
	 * liczbę na łuku, więc status (kropka + tekst reguły) idzie pod spód.
	 *
	 * @param array $data Znormalizowane dane.
	 * @return string
	 */
	private static function state_line_html( array $data ): string {
		if ( empty( $data['state_color'] ) && empty( $data['state_text'] ) ) {
			return '';
		}

		return '<span class="iot-dw-widget__state">' . self::state_dot_html( $data ) . esc_html( $data['state_text'] ?? '' ) . '</span>';
	}

	/**
	 * Renderuje prosty Gauge (SVG, łuk 180°) na podstawie min/max/wartości.
	 * Wartość spoza zakresu jest przycinana do min/max; wartość nieliczbowa
	 * (np. stan przełącznika) pokazuje pusty gauge zamiast się wywalać.
	 *
	 * @param array  $data       Znormalizowane dane.
	 * @param array  $args       Opcje: 'min' i 'max' (domyślnie 0/100).
	 * @param string $style_attr Gotowy (już zescape'owany) atrybut style="" albo pusty string — patrz style_attr().
	 * @return string
	 */
	private function render_gauge( array $data, array $args, string $style_attr = '' ): string {
		$min = isset( $args['min'] ) ? (float) $args['min'] : 0.0;
		$max = isset( $args['max'] ) ? (float) $args['max'] : 100.0;

		$has_numeric_value = isset( $data['value'] ) && is_numeric( $data['value'] );
		$raw_value         = $has_numeric_value ? (float) $data['value'] : $min;
		$value             = max( $min, min( $max, $raw_value ) );
		$percent           = $max > $min ? ( $value - $min ) / ( $max - $min ) : 0.0;
		$label             = (string) ( $data['label'] ?? '' );
		$unit              = (string) ( $data['unit'] ?? '' );

		if ( $has_numeric_value ) {
			$accessible_value = trim( $this->format_value( $data['value'] ) . ' ' . $unit );
			$accessible_text  = sprintf(
				/* translators: 1: measurement name, 2: current value and optional unit */
				__( '%1$s: %2$s', 'iot-data-widget' ),
				$label,
				$accessible_value
			);
		} else {
			$accessible_text = sprintf(
				/* translators: 1: measurement name */
				__( '%1$s: measurement unavailable', 'iot-data-widget' ),
				$label
			);
		}

		$radius        = 80;
		$circumference = M_PI * $radius;
		$offset        = $circumference * ( 1 - $percent );

		ob_start();
		?>
		<div class="iot-dw-widget iot-dw-widget--gauge<?php echo esc_attr( self::stale_class( $data ) ); ?>"<?php echo wp_kses( $style_attr . self::refresh_data_attr( $data ), array() ); ?> data-iot-dw-min="<?php echo esc_attr( (string) $min ); ?>" data-iot-dw-max="<?php echo esc_attr( (string) $max ); ?>">
			<svg viewBox="0 0 200 110" class="iot-dw-widget__gauge-svg" aria-hidden="true">
				<path
					d="M 20 100 A 80 80 0 0 1 180 100"
					class="iot-dw-widget__gauge-track"
					fill="none"
					stroke-width="16"
					stroke-linecap="round"
				/>
				<path
					d="M 20 100 A 80 80 0 0 1 180 100"
					class="iot-dw-widget__gauge-fill"
					fill="none"
					stroke-width="16"
					stroke-linecap="round"
					stroke-dasharray="<?php echo esc_attr( (string) ( $has_numeric_value ? $circumference : 0 ) ); ?>"
					stroke-dashoffset="<?php echo esc_attr( (string) $offset ); ?>"
				/>
				</svg>
			<span class="iot-dw-widget__gauge-value" aria-hidden="true">
					<?php if ( $has_numeric_value ) : ?>
						<?php echo esc_html( $this->format_value( $data['value'] ) ); ?>
						<?php echo ! empty( $data['unit'] ) ? esc_html( ' ' . $data['unit'] ) : ''; ?>
					<?php else : ?>
						<?php esc_html_e( 'No measurement available.', 'iot-data-widget' ); ?>
					<?php endif; ?>
				</span>
			<span class="iot-dw-widget__label" aria-hidden="true"><?php echo esc_html( $label ); ?></span>
			<span class="iot-dw-widget__sr-only iot-dw-widget__gauge-summary"><?php echo esc_html( $accessible_text ); ?></span>
			<?php echo wp_kses( self::state_line_html( $data ), self::allowed_widget_html() ); ?>
			<?php echo wp_kses( self::stale_badge_html( $data ), self::allowed_widget_html() ); ?>
		</div>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Buduje gotowy do wypisania atrybut style="" z pary zmienna CSS ->
	 * wartość (override wyglądu per widget — patrz
	 * IOTDW_Style_Settings::css_variables()), albo pusty string, gdy nie ma
	 * żadnego override (najczęstszy przypadek — wtedy wrapper w ogóle nie
	 * dostaje atrybutu style, dziedziczy zmienne CSS z ustawień globalnych
	 * wstrzykniętych na :root, patrz IOTDW_Shortcode::maybe_enqueue_assets()).
	 *
	 * @param array<string,string> $css_variables Para zmienna CSS -> wartość.
	 * @return string
	 */
	protected static function style_attr( array $css_variables ): string {
		if ( empty( $css_variables ) ) {
			return '';
		}

		$declarations = '';

		foreach ( $css_variables as $name => $value ) {
			$declarations .= esc_attr( $name ) . ':' . esc_attr( $value ) . ';';
		}

		return ' style="' . $declarations . '"';
	}

	/**
	 * Buduje atrybuty data-iot-dw-connection/entity (v1.8 Tydzień 3) — id
	 * kanoniczne (nie surowy atrybut shortcode'u, patrz komentarz w
	 * IOTDW_Shortcode::render()), po których public/js/widget-refresh.js
	 * rozpoznaje, którą parę odpytać na publicznym endpoincie z Tygodnia 1.
	 * Puste, gdy $data nie niesie obu kluczy — np. wywołania renderera spoza
	 * shortcode'u (podgląd wyglądu w adminie) po prostu dają widget bez
	 * atrybutów, JS go wtedy pomija, bez żadnej specjalnej obsługi z ich
	 * strony.
	 *
	 * 'bool_label_on'/'bool_label_off' (jeśli obecne — patrz
	 * IOTDW_Shortcode::apply_boolean_label_override()) dokładają
	 * data-iot-dw-on-label/off-label, żeby jednorazowe doładowanie z
	 * publicznego endpointu (który nie zna atrybutów on_label/off_label
	 * shortcode'u/bloku — cache kluczowany tylko po connection|entity) nie
	 * cofało dostosowanej etykiety z powrotem do surowego display_value
	 * konektora ułamek sekundy po załadowaniu strony (patrz komentarz
	 * apply_boolean_label_override() i public/js/widget-refresh.js).
	 *
	 * @param array $data Znormalizowane dane (patrz kontrakt konektora) — ewentualnie z dołożonymi 'connection_id'/'entity_id'/'bool_label_on'/'bool_label_off'.
	 * @return string
	 */
	protected static function refresh_data_attr( array $data ): string {
		if ( empty( $data['connection_id'] ) || ! isset( $data['entity_id'] ) || '' === $data['entity_id'] ) {
			return '';
		}

		$attr = ' data-iot-dw-connection="' . esc_attr( $data['connection_id'] ) . '" data-iot-dw-entity="' . esc_attr( $data['entity_id'] ) . '"';

		if ( isset( $data['bool_label_on'], $data['bool_label_off'] ) ) {
			$attr .= ' data-iot-dw-on-label="' . esc_attr( $data['bool_label_on'] ) . '" data-iot-dw-off-label="' . esc_attr( $data['bool_label_off'] ) . '"';
		}

		// Jednostka z atrybutu unit (1.0.4) — tak samo jak etykiety on/off:
		// endpoint odświeżania jej nie zna, a bez tego znikała po doładowaniu.
		if ( ! empty( $data['unit_override'] ) ) {
			$attr .= ' data-iot-dw-unit="' . esc_attr( $data['unit_override'] ) . '"';
		}

		// Mapowanie stanów (1.0.4): reguły jadą z widgetem, bo publiczny
		// endpoint odświeżania zna tylko parę connection|entity (jak etykiety
		// on/off wyżej) — public/js/widget-refresh.js stosuje je sam.
		if ( ! empty( $data['state_rules'] ) ) {
			$attr .= ' data-iot-dw-states="' . esc_attr( (string) wp_json_encode( $data['state_rules'] ) ) . '"';
		}

		return $attr;
	}

	/**
	 * Renderuje błąd. Szczegóły widoczne tylko dla adminów (manage_options) —
	 * dla gości pusty string, żeby nie ujawniać szczegółów konfiguracji
	 * połączenia (URL, powód błędu) na publicznej stronie.
	 *
	 * V1.7 Tydzień 7: to jedyny stan błędu, jaki dostają style pojedynczej
	 * encji (card/gauge/chart/sparkline) — `render()` łapie błąd PRZED
	 * przejściem do metody stylu (patrz góra tego pliku), więc złamany Gauge
	 * nie próbuje renderować "pustego łuku z błędem w środku", tylko
	 * zastępuje się tym samym, wspólnym boksem co każdy inny styl. To
	 * świadomy wybór z audytu (docs/archiwum/audyt-ui-v1-7.md): jeden dobrze
	 * zaprojektowany komunikat, spójny wszędzie, zamiast osobnego
	 * "stanu błędu" rysowanego wewnątrz kształtu każdego stylu z osobna.
	 *
	 * @param string $message     Komunikat błędu.
	 * @param string $upgrade_url Opcjonalny URL do strony cennika (Priorytet 1d, 2026-10-06) — gdy podany, dokleja link "Upgrade to Pro" pod komunikatem. Puste — bez linku.
	 * @return string
	 */
	protected function render_error( string $message, string $upgrade_url = '' ): string {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '';
		}

		$text = esc_html(
			sprintf(
				/* translators: %s: error message */
				__( 'IoT Data Widget: %s (visible to administrators only)', 'iot-data-widget' ),
				$message
			)
		);

		if ( '' !== $upgrade_url ) {
			$text .= ' ' . wp_kses(
				sprintf(
					'<a href="%s">%s</a>',
					esc_url( $upgrade_url ),
					esc_html__( 'Upgrade to Pro', 'iot-data-widget' )
				),
				array( 'a' => array( 'href' => array() ) )
			);
		}

		return sprintf(
			'<div class="iot-dw-widget iot-dw-widget--error">%s<span class="iot-dw-widget__error-text">%s</span></div>',
			wp_kses( self::error_icon_svg(), self::allowed_widget_html() ),
			$text
		);
	}

	/**
	 * SVG ikony błędu — współdzielona przez render_error() (style pojedynczej
	 * encji) i per-element błędy w render_list()/render_grid() (v1.7 Tydzień
	 * 7), żeby "wygląda jak błąd" znaczyło dokładnie to samo (ten sam kształt/
	 * kolor) niezależnie od tego, czy błąd dotyczy całego widgetu, czy
	 * jednego wiersza/komórki. `currentColor` — dziedziczy kolor tekstu z
	 * `.iot-dw-widget--error`/`.iot-dw-widget__list-error`, jedno miejsce do
	 * zmiany koloru zamiast dwóch. Statyczne kształty (żadnych danych
	 * wejściowych), bezpieczne do wypisania bez escapowania.
	 *
	 * @return string
	 */
	private static function error_icon_svg(): string {
		return '<svg class="iot-dw-widget__error-icon" viewBox="0 0 20 20" aria-hidden="true">'
			. '<circle cx="10" cy="10" r="9" fill="currentColor" />'
			. '<rect x="9" y="5" width="2" height="7" rx="1" fill="#fff" />'
			. '<rect x="9" y="13.5" width="2" height="2" rx="1" fill="#fff" />'
			. '</svg>';
	}

	/**
	 * Zachowuje dotychczasowe zaokrąglenie do 1 miejsca, ale zwiększa
	 * precyzję dla małej wartości niezerowej, która inaczej stałaby się zerem.
	 * Wartości poniżej rozdzielczości 3 miejsc pokazuje jako nierówność.
	 * Formatowanie liczb używa locale strony; wartości tekstowe pozostają bez zmian.
	 *
	 * @param mixed $value Surowa wartość z konektora.
	 * @return mixed
	 */
	protected function format_value( $value ) {
		if ( ! is_numeric( $value ) ) {
			return $value;
		}

		$value = (float) $value;

		if ( 0.0 === $value ) {
			return number_format_i18n( 0, 0 );
		}

		$rounded = round( $value, 1 );

		if ( 0.0 !== $rounded ) {
			return number_format_i18n( $rounded, floor( $rounded ) === $rounded ? 0 : 1 );
		}

		for ( $precision = 2; $precision <= 3; $precision++ ) {
			$rounded = round( $value, $precision );

			if ( 0.0 !== $rounded ) {
				return number_format_i18n( $rounded, $precision );
			}
		}

		$threshold = number_format_i18n( 0.001, 3 );

		return ( $value < 0 ? '-<' : '<' ) . $threshold;
	}

	/**
	 * Parsuje 'last_updated' na znacznik czasu Unix (UTC) — obsługuje ISO
	 * 8601 ze strefą (HA/REST/Shelly Cloud, zawsze "teraz" przy pull) I
	 * gołe "Y-m-d H:i:s" bez strefy (MQTT/push, zapisywane przez
	 * current_time('mysql', true) — UTC, ale bez jawnego znacznika), które
	 * strtotime() inaczej po cichu zinterpretowałoby w strefie serwera, nie
	 * UTC. Null, gdy brak wartości albo nie da się jej sensownie sparsować
	 * (np. świeżo dodana encja bez jeszcze jednego odczytu) — traktowane jak
	 * "nieznana świeżość", nie jak "stare".
	 *
	 * @param array $data Znormalizowane dane (patrz kontrakt konektora).
	 * @return int|null
	 */
	private static function last_updated_timestamp( array $data ): ?int {
		$raw = $data['last_updated'] ?? null;

		if ( empty( $raw ) ) {
			return null;
		}

		$raw               = trim( (string) $raw );
		$has_timezone_info = (bool) preg_match( '/[Zz]$|[+\-]\d{2}:?\d{2}$/', $raw );
		$timestamp         = strtotime( $has_timezone_info ? $raw : $raw . ' UTC' );

		return false !== $timestamp ? $timestamp : null;
	}

	/**
	 * Czy dane przekraczają skonfigurowany próg "stare dane" (§5 planu) —
	 * patrz last_updated_timestamp() o obsłudze formatów.
	 *
	 * @param array $data Znormalizowane dane.
	 * @return bool
	 */
	private static function is_stale( array $data ): bool {
		$timestamp = self::last_updated_timestamp( $data );

		if ( null === $timestamp ) {
			return false;
		}

		$settings          = get_option( IOTDW_Settings_Page::OPTION_NAME, array() );
		$threshold_minutes = max( 1, (int) ( $settings[ self::STALE_THRESHOLD_SETTING_KEY ] ?? self::DEFAULT_STALE_MINUTES ) );

		return ( time() - $timestamp ) > ( $threshold_minutes * MINUTE_IN_SECONDS );
	}

	/**
	 * Klasa CSS dodawana do zewnętrznego elementu widgetu, gdy dane są stare
	 * — pusty string inaczej (żeby nie dokładać nadmiarowej spacji w atrybucie
	 * class dla normalnego, świeżego przypadku).
	 *
	 * @param array $data Znormalizowane dane.
	 * @return string
	 */
	private static function stale_class( array $data ): string {
		return self::is_stale( $data ) ? ' iot-dw-widget--stale' : '';
	}

	/**
	 * Znacznik "Updated HH:MM · Data may be out of date" pokazywany przy
	 * starych danych we wszystkich stylach (1.0.10 — ten sam tekst co w Veloxa
	 * Solar Widget) — pusty string, gdy dane są świeże albo świeżość nieznana
	 * (patrz last_updated_timestamp()).
	 * Godzina w strefie czasowej strony (wp_date()), nie serwera/UTC — to,
	 * co widzi odwiedzający, powinno zgadzać się z jego oczekiwaniem
	 * względem reszty strony.
	 *
	 * @param array $data Znormalizowane dane.
	 * @return string
	 */
	private static function stale_badge_html( array $data ): string {
		if ( ! self::is_stale( $data ) ) {
			return '';
		}

		$timestamp = self::last_updated_timestamp( $data );

		if ( null === $timestamp ) {
			return '';
		}

		return sprintf(
			'<span class="iot-dw-widget__stale-badge"><time class="iot-dw-widget__stale-time" datetime="%s">%s</time> <span class="iot-dw-widget__stale-text">%s</span></span>',
			esc_attr( gmdate( 'c', $timestamp ) ),
			esc_html(
				sprintf(
					/* translators: %s: local date/time the value was last updated */
					__( 'Updated %s', 'iot-data-widget' ),
					wp_date( get_option( 'time_format' ) . ' ' . get_option( 'date_format' ), $timestamp )
				)
			),
			esc_html__( 'Data may be out of date', 'iot-data-widget' )
		);
	}

	/**
	 * Zgaduje ikonę typu encji (v1.8 Tydzień 6, style "card"/"list" — patrz
	 * docs/archiwum/mvp-v1-8-plan.md) na podstawie tego, co kontrakt konektora
	 * FAKTYCZNIE daje przy renderowaniu: 'unit' i obecność 'display_value'.
	 * Świadome ograniczenie: kontrakt konektora nie niesie "domeny"/typu
	 * encji (to jest dostępne tylko przy przeglądaniu listy encji przez
	 * IOTDW_Listable_Connector_Interface::list_entities(), NIE przy
	 * pojedynczym fetch_data()), więc to najlepsze możliwe zgadywanie z
	 * dostępnych danych, nie precyzyjna klasyfikacja — np. wilgotność i
	 * inny sensor procentowy (np. bateria) dostaną tę samą ikonę kropli,
	 * jeśli oba używają unit "%". Fallback 'generic' dla wszystkiego innego.
	 *
	 * @param array $data Znormalizowane dane (patrz kontrakt konektora).
	 * @return string Nazwa pliku w public/assets/icons/ (bez rozszerzenia).
	 */
	private static function icon_for_entity( array $data ): string {
		$unit = strtolower( trim( (string) ( $data['unit'] ?? '' ) ) );

		if ( '' !== $unit && false !== strpos( $unit, '°' ) ) {
			return 'temperature';
		}

		if ( in_array( $unit, array( 'w', 'kw', 'kwh', 'wh' ), true ) ) {
			return 'energy';
		}

		if ( '%' === $unit ) {
			return 'humidity';
		}

		if ( ! empty( $data['display_value'] ) ) {
			return 'switch';
		}

		return 'generic';
	}

	/**
	 * Wczytuje (i cache'uje w pamięci na czas requestu — kilka widgetów na
	 * stronie często dzieli tę samą ikonę) zawartość pliku SVG z
	 * public/assets/icons/ (v1.8 Tydzień 5) — jedno źródło prawdy zamiast
	 * duplikowania znaczników SVG w PHP jak error_icon_svg(). $name pochodzi
	 * wyłącznie z icon_for_entity() (stała, zamknięta lista wartości),
	 * nigdy bezpośrednio od użytkownika/konektora — bezpieczne bez
	 * dodatkowej walidacji ścieżki.
	 *
	 * @param string $name Nazwa pliku bez rozszerzenia (patrz icon_for_entity()).
	 * @return string
	 */
	private static function icon_svg( string $name ): string {
		static $cache = array();

		if ( isset( $cache[ $name ] ) ) {
			return $cache[ $name ];
		}

		$path = IOTDW_PLUGIN_DIR . 'public/assets/icons/' . $name . '.svg';

		if ( ! file_exists( $path ) ) {
			$cache[ $name ] = '';
			return '';
		}

		$svg = (string) file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- lokalny plik dołączony do wtyczki (public/assets/icons/), nie zdalny URL — wp_remote_get() tu nie ma zastosowania.
		$svg = (string) preg_replace( '/<svg /', '<svg class="iot-dw-widget__type-icon" ', $svg, 1 );

		$cache[ $name ] = $svg;

		return $svg;
	}
}
