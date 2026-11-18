<?php
/**
 * Obsługa shortcode [iotdw_widget].
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Shortcode
 */
class IOTDW_Shortcode {

	/**
	 * Menedżer konektorów — wyszukiwanie połączeń i tworzenie konektorów.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Warstwa cache.
	 *
	 * @var IOTDW_Cache
	 */
	private $cache;

	/**
	 * Renderer HTML.
	 *
	 * @var IOTDW_Renderer
	 */
	private $renderer;

	/**
	 * Zbieranie/odczyt historii wartości — potrzebne tylko dla stylu "chart".
	 *
	 * @var IOTDW_History_Recorder
	 */
	private $history_recorder;

	/**
	 * Dane widgetów typu "chart" wyrenderowanych na bieżącej stronie, zebrane
	 * podczas przetwarzania shortcode'ów (patrz prepare_chart_args()) i
	 * przekazane do JS jednym wp_localize_script() w print_chart_data() —
	 * czekamy do wp_footer, żeby mieć komplet, zamiast polegać na tym, czy
	 * wp_localize_script() wołany kilka razy dla tego samego uchwytu scala
	 * dane, czy je nadpisuje.
	 *
	 * @var array<string, array{labels: string[], values: float[], unit: string}>
	 */
	private $chart_widgets = array();

	/**
	 * Czy na tej stronie faktycznie wyrenderował się co najmniej jeden
	 * widget — steruje print_branding() (patrz), żeby stopka "Powered by"
	 * nie pojawiała się na stronach bez żadnego [iotdw_widget].
	 *
	 * @var bool
	 */
	private $rendered_widget = false;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager $connector_manager Menedżer konektorów.
	 * @param IOTDW_Cache             $cache             Warstwa cache.
	 * @param IOTDW_Renderer          $renderer          Renderer HTML.
	 * @param IOTDW_History_Recorder  $history_recorder  Odczyt historii wartości (styl "chart").
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Cache $cache, IOTDW_Renderer $renderer, IOTDW_History_Recorder $history_recorder ) {
		$this->connector_manager = $connector_manager;
		$this->cache             = $cache;
		$this->renderer          = $renderer;
		$this->history_recorder  = $history_recorder;

		add_shortcode( 'iotdw_widget', array( $this, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
		add_action( 'wp_footer', array( $this, 'print_chart_data' ), 1 );
		add_action( 'wp_footer', array( $this, 'print_branding' ), 20 );
	}

	/**
	 * Renderuje shortcode [iotdw_widget].
	 *
	 * @param array|string $atts Atrybuty shortcode.
	 * @return string
	 */
	public function render( $atts ): string {
		$html = $this->render_widget( $atts );

		// Blokowy wrapper (1.0.2): motywy wyrównują swoje bloki do kolumny
		// treści przez margin: auto, co nie działa na elementach inline-block
		// (card/gauge stały przy krawędzi strony, np. w Twenty Twenty-One).
		// Wrapper dostaje szerokość kolumny, widget stoi w nim do lewej.
		// Pusty wynik (błąd ukryty przed gościem) — bez wrappera, żeby nie
		// zostawiał pustego odstępu.
		return '' === trim( $html ) ? $html : '<div class="iot-dw-widget-wrap">' . $html . '</div>';
	}

	/**
	 * Właściwe renderowanie widgetu (bez wrappera, patrz render()).
	 *
	 * @param array|string $atts Atrybuty shortcode.
	 * @return string
	 */
	private function render_widget( $atts ): string {
		$this->rendered_widget = true;

		$atts = shortcode_atts(
			array(
				'connection'    => '',
				'entity'        => '',
				'entities'      => '',
				'labels'        => '',
				'items'         => '',
				'style'         => 'card',
				'label'         => '',
				'unit'          => '',
				'on_label'      => '',
				'off_label'     => '',
				'states'        => '',
				'ttl'           => IOTDW_Cache::DEFAULT_TTL,
				'min'           => 0,
				'max'           => 100,
				'range'         => '24h',
				'points'        => 20,
				'bg_color'      => '',
				'text_color'    => '',
				'accent_color'  => '',
				'border_radius' => '',
				'font_size'     => '',
			),
			$atts,
			'iotdw_widget'
		);

		$this->enqueue_late( (string) $atts['style'] );

		if ( 'list' === $atts['style'] ) {
			return $this->render_list( $atts );
		}

		if ( 'grid' === $atts['style'] ) {
			// class_exists() (Guideline 5, 2026-09-24) — grid jest Pro
			// (includes/premium/class-grid-items.php); na wersji darmowej ten
			// plik fizycznie nie istnieje, więc render_grid() (który go
			// wymaga) nigdy się nie odpala — czytelny komunikat zamiast
			// fatal errora, ten sam wzorzec co IOTDW_Renderer::render_history_style()
			// dla chart/sparkline.
			if ( ! class_exists( 'IOTDW_Grid_Items' ) ) {
				$error = $this->error_data( __( 'This style requires the Pro version.', 'iot-data-widget' ) );
				if ( function_exists( 'idw_fs' ) ) {
					$error['upgrade_url'] = idw_fs()->pricing_url();
				}
				return $this->renderer->render( $error, 'card' );
			}

			return $this->render_grid( $atts );
		}

		if ( '' === $atts['connection'] || '' === $atts['entity'] ) {
			return $this->renderer->render(
				$this->error_data(
					__( 'Fill in the "connection" and "entity" attributes in the [iotdw_widget] shortcode.', 'iot-data-widget' )
				),
				$atts['style']
			);
		}

		$resolved = $this->resolve_connection( $atts['connection'] );

		if ( null === $resolved ) {
			return $this->renderer->render(
				$this->error_data(
					sprintf(
						/* translators: %s: connection name or ID given in the shortcode */
						__( 'Connection "%s" was not found.', 'iot-data-widget' ),
						$atts['connection']
					)
				),
				$atts['style']
			);
		}

		$connector = $resolved['connector'];

		$data = $this->fetch_cached( $connector, $resolved['connection'], $atts['entity'], (int) $atts['ttl'] );

		if ( '' !== $atts['label'] && empty( $data['error'] ) ) {
			$data['label'] = $atts['label'];
		}

		// Poprawka 4 (docs/archiwum/iot-data-widget-do-poprawy.md) — symetryczne do
		// 'label' powyżej. Potrzebne bo konektor REST w trybie zaawansowanym
		// (dowolny JSONPath, bez presetu) nie ma skąd znać jednostki —
		// dotyczy to połączenia demo (kreator, "Wypróbuj z danymi demo",
		// Open-Meteo) w pierwszej kolejności, ale jest ogólnym atrybutem
		// shortcode'u, przydatnym dla każdego użytkownika w trybie
		// zaawansowanym, nie hackiem tylko pod demo.
		if ( '' !== $atts['unit'] && empty( $data['error'] ) ) {
			$data['unit']          = $atts['unit'];
			$data['unit_override'] = $atts['unit'];
		}

		$data = self::apply_boolean_label_override( $data, $atts['on_label'], $atts['off_label'] );
		$data = IOTDW_State_Rules::apply( $data, (string) $atts['states'] );

		$render_args = array( 'style_vars' => $this->style_vars_from_atts( $atts ) );

		if ( 'gauge' === $atts['style'] ) {
			$render_args['min'] = $atts['min'];
			$render_args['max'] = $atts['max'];
		} elseif ( 'chart' === $atts['style'] && class_exists( 'IOTDW_Renderer_Chart' ) ) {
			// class_exists() (Guideline 5, 2026-09-24; dawniej dodatkowo
			// iotdw_is_premium(), usunięte jako runtime license-check) —
			// bez tego warunku prepare_chart_args()/prepare_sparkline_args()
			// (historia z bazy, wpis do $this->chart_widgets) wykonywały się
			// na darmowym tierze mimo że IOTDW_Renderer (bazowy, jedyny
			// renderer bez Pro) i tak pokazuje komunikat "wymaga Pro" i
			// nigdy nie odwołuje się do $render_args — czysty balast. Metody
			// SAME W SOBIE nie są martwym kodem: IOTDW_Renderer_Chart (Pro)
			// czyta dokładnie te klucze (widget_id/labels/values/unit),
			// patrz jej render_chart()/render_sparkline().
			$render_args = array_merge( $render_args, $this->prepare_chart_args( $atts, $data ) );
		} elseif ( 'sparkline' === $atts['style'] && class_exists( 'IOTDW_Renderer_Chart' ) ) {
			$render_args = array_merge( $render_args, $this->prepare_sparkline_args( $atts, $data ) );
		}

		return $this->renderer->render( $data, $atts['style'], $render_args );
	}

	/**
	 * Pobiera dane jednej pary (connection_id, entity_id) przez cache —
	 * WSPÓLNE dla card/list/grid (code review, docs/archiwum/poprawki-ogolne.md):
	 * wcześniej ta sama logika (klucz cache + stemplowanie connection_id/
	 * entity_id na wynik) była duplikowana osobno w render()/render_list()/
	 * render_grid(), każda kopia z własnym komentarzem ostrzegającym przed
	 * tym samym, raz już znalezionym bugiem — partial edit pomijający jedną
	 * z trzech kopii cicho przywracałby dokładnie ten błąd.
	 *
	 * Klucz cache po ID KANONICZNYM, NIE po surowym atrybucie shortcode'u —
	 * znalezione podczas code review: edytor bloku zapisuje NAZWĘ połączenia
	 * jako atrybut "connection" (patrz blocks/iot-widget/src/edit.js), a
	 * publiczny endpoint odświeżania z v1.8 Tydzień 1
	 * (IOTDW_Rest_Api::get_widget_data()) cache'uje TE SAME dane pod
	 * kanonicznym ID. Różne klucze = dwa niezależne wpisy cache dla dokładnie
	 * tej samej pary, każdy wygasający na własnym zegarze — realnie ok. 2x
	 * częstsze odpytywanie backendu (HA/REST/Shelly) niż obiecuje CLAUDE.md
	 * ("realne zapytanie nie wyjdzie częściej niż raz na TTL cache"). Stąd
	 * $connection_id tutaj musi już być kanoniczny — wywołujący go rozwiązują
	 * przez resolve_connection() PRZED wywołaniem tej metody.
	 *
	 * connection_id/entity_id (v1.8 Tydzień 3) stemplowane na wynik — nie
	 * surowy atrybut shortcode'u, który może być nazwą — do
	 * data-iot-dw-connection/entity w markupie, żeby
	 * public/js/widget-refresh.js wiedziało, jakiej pary użyć przy
	 * odpytywaniu publicznego endpointu z Tygodnia 1. Renderer po prostu
	 * przepisuje te dwa klucze do atrybutów, jeśli są ustawione — brak ich
	 * (np. wywołanie renderera spoza shortcode'u, patrz podgląd wyglądu w
	 * adminie) po prostu daje widget bez atrybutów, więc JS go pomija.
	 *
	 * $min_ttl (zgłoszenie 2026-08-28/2026-08-31, "P1#8", Tasmota na
	 * ESP8285): niektóre urządzenia mają tak ograniczone zasoby (jednoczesne
	 * połączenia TCP, pamięć), że zbyt częste odpytywanie potrafi je
	 * całkowicie zawiesić na kilkanaście sekund — nawet dla własnej strony
	 * WWW urządzenia, nie tylko API. IOTDW_Connector_Manager::enforce_min_ttl()
	 * łączy GLOBALNY floor (30s, dla wszystkich połączeń robiących
	 * synchroniczne żądanie HTTP podczas renderowania) z ewentualnym,
	 * surowszym limitem specyficznym dla presetu (Tasmota: 60s) — podany
	 * $ttl (z atrybutu shortcode'u/bloku) NIGDY nie schodzi poniżej tego,
	 * niezależnie od tego, co poprosił autor widgetu. Cichy floor, nie błąd
	 * (widget ma dalej normalnie działać, tylko rzadziej się odświeżać).
	 * Push/MQTT (polling i listener) są z tego floora wyłączone —
	 * fetch_data() dla nich czyta z lokalnego magazynu, nigdy nie robi
	 * żądania HTTP podczas renderowania strony.
	 *
	 * @param IOTDW_Abstract_Connector $connector     Instancja konektora dla tego połączenia.
	 * @param array<string, mixed>     $connection_row Zapisane połączenie (musi zawierać 'id'/'connector', opcjonalnie 'preset') — do wyliczenia $min_ttl, patrz enforce_min_ttl().
	 * @param string                   $entity_id     ID/ścieżka encji.
	 * @param int                      $ttl           TTL cache dla udanej odpowiedzi (sekundy), zażądany przez shortcode/blok.
	 * @return array Znormalizowana struktura danych konektora, ze stemplowanymi connection_id/entity_id.
	 */
	private function fetch_cached( IOTDW_Abstract_Connector $connector, array $connection_row, string $entity_id, int $ttl ): array {
		$connection_id = $connection_row['id'];

		// Zgłoszenie z listy do premiery (blok 2.1) — patrz docblock
		// IOTDW_Connector_Manager::bypasses_render_cache(): push nie ma nic
		// do ochrony przed cache'owaniem (fetch_data() to lokalny odczyt, nie
		// żądanie sieciowe), a cache tylko sztucznie opóźniał widoczność
		// świeżo wysłanej wartości.
		if ( IOTDW_Connector_Manager::bypasses_render_cache( $connection_row ) ) {
			$data = $this->safe_fetch( $connector, $entity_id );
		} else {
			$ttl  = IOTDW_Connector_Manager::enforce_min_ttl( $ttl, $connection_row );
			$data = $this->cache->remember(
				$connection_id . '|' . $entity_id,
				function () use ( $connector, $entity_id ) {
					return $this->safe_fetch( $connector, $entity_id );
				},
				$ttl,
				IOTDW_Cache::ERROR_TTL,
				$connection_id
			);
		}

		$data['connection_id'] = $connection_id;
		$data['entity_id']     = $entity_id;

		return $data;
	}

	/**
	 * Przygotowuje dane dla stylu "sparkline": ostatnie N punktów historii
	 * (żadnego JS, żadnego wp_localize_script — renderer buduje SVG od razu
	 * po stronie PHP). Brak połączenia daje pusty array — renderer wtedy
	 * pokaże płaską linię (patrz IOTDW_Renderer::sparkline_svg()), nie błąd.
	 * 'labels'/'unit' (v1.8 Tydzień 7) — obok samych wartości, do ukrytej
	 * tabeli danych (patrz IOTDW_Renderer::history_table_html(),
	 * docs/archiwum/mvp-v1-8-plan.md Ustalenie #3); wcześniej znaczniki czasu z
	 * historii były po prostu odrzucane (tylko 'v' plucked), bo SVG-owa
	 * linia trendu ich nie potrzebowała. ID połączenia brane z
	 * $data['connection_id'] (code review — render() już je tam wstawia
	 * PRZED wywołaniem tej metody, patrz komentarz tam), nie przez
	 * ponowne find_by_id_or_name($atts['connection']) — ten sam lookup,
	 * który render() już zrobił chwilę wcześniej przez resolve_connection().
	 *
	 * @param array $atts Znormalizowane atrybuty shortcode.
	 * @param array $data Znormalizowane dane bieżącego odczytu (connection_id, jednostka).
	 * @return array{values?: float[], labels?: string[], unit?: string}
	 */
	private function prepare_sparkline_args( array $atts, array $data ): array {
		if ( empty( $data['connection_id'] ) ) {
			return array();
		}

		$history = $this->history_recorder->get_history( $data['connection_id'], $atts['entity'], $atts['range'] );
		$values  = wp_list_pluck( $history, 'v' );
		$labels  = self::local_time_labels( wp_list_pluck( $history, 't' ) );

		$points = max( 2, (int) $atts['points'] );

		if ( count( $values ) > $points ) {
			$values = array_slice( $values, -$points );
			$labels = array_slice( $labels, -$points );
		}

		return array(
			'values' => $values,
			'labels' => $labels,
			'unit'   => $data['unit'] ?? '',
		);
	}

	/**
	 * Przygotowuje dane dla stylu "chart": pobiera historię, zapamiętuje ją
	 * pod unikalnym ID widgetu (do wysłania do JS w print_chart_data()) i
	 * zwraca ten ID jako render_args dla renderera, RAZEM z samymi
	 * labels/values/unit (v1.8 Tydzień 7) — Renderer buduje z nich ukrytą
	 * tabelę danych PO STRONIE SERWERA (patrz history_table_html()), bo
	 * <canvas> jest niewidoczny dla crawlerów/czytników ekranu (Ustalenie #3
	 * planu v1.8) i nie może być jedynym miejscem, gdzie te liczby istnieją
	 * — inaczej niż dane dla Chart.js, które muszą poczekać na JS/wp_footer,
	 * dane w markupie muszą być gotowe od razu, bez JS. Pusty error w
	 * danych bieżącego odczytu (już sprawdzony wyżej w render()) i brak
	 * połączenia są jedynymi powodami zwrócenia pustych args — renderer
	 * wtedy pokaże błąd zamiast pustego wykresu.
	 *
	 * ID połączenia brane z $data['connection_id'] (code review), nie przez
	 * ponowne find_by_id_or_name($atts['connection']) — patrz ten sam
	 * komentarz w prepare_sparkline_args().
	 *
	 * @param array $atts Znormalizowane atrybuty shortcode.
	 * @param array $data Znormalizowane dane bieżącego odczytu (connection_id, etykieta/jednostka).
	 * @return array{widget_id?: string, labels?: string[], values?: float[], unit?: string}
	 */
	private function prepare_chart_args( array $atts, array $data ): array {
		if ( empty( $data['connection_id'] ) ) {
			return array();
		}

		$history = $this->history_recorder->get_history( $data['connection_id'], $atts['entity'], $atts['range'] );

		$widget_id = 'iot-dw-chart-' . wp_generate_uuid4();
		$labels    = self::local_time_labels( wp_list_pluck( $history, 't' ) );
		$values    = wp_list_pluck( $history, 'v' );
		$unit      = $data['unit'] ?? '';

		$this->chart_widgets[ $widget_id ] = array(
			'labels' => $labels,
			'values' => $values,
			'unit'   => $unit,
		);

		return array(
			'widget_id' => $widget_id,
			'labels'    => $labels,
			'values'    => $values,
			'unit'      => $unit,
		);
	}

	/**
	 * Zwraca zebrane dane widgetów "chart" wyrenderowanych do tej pory. Używane
	 * przez IOTDW_Embed (v1.3 Tydzień 5) — strona embed nie przechodzi przez
	 * normalny cykl wp_enqueue_scripts/wp_footer motywu (celowo, bez motywu
	 * WP), więc zamiast polegać na wp_localize_script()+wp_footer (patrz
	 * print_chart_data()), sama wstrzykuje te dane bezpośrednio do własnego,
	 * minimalnego szablonu.
	 *
	 * @return array<string, array{labels: string[], values: float[], unit: string}>
	 */
	public function get_chart_widgets(): array {
		return $this->chart_widgets;
	}

	/**
	 * Wysyła zebrane dane wszystkich widgetów "chart" na stronie do JS,
	 * jednym wywołaniem wp_localize_script() — patrz komentarz przy
	 * $chart_widgets. Wołane na wp_footer (priorytet 1), czyli już po tym,
	 * jak wszystkie shortcode'y w treści zdążyły się wyrenderować.
	 */
	public function print_chart_data(): void {
		if ( empty( $this->chart_widgets ) ) {
			return;
		}

		wp_localize_script( 'iot-data-widget-chart', 'iotdwChartData', $this->chart_widgets );
	}

	/**
	 * Drukuje dyskretną stopkę "Powered by IoT Data Widget", raz na stronę
	 * (nie per-widget — kilka widgetów na jednej stronie nie powtarzają jej).
	 * Sterowana filtrem 'iotdw_show_branding', którego domyślna wartość to
	 * ustawienie z panelu admina (IOTDW_Settings_Page).
	 *
	 * Poprawki po recenzji wp.org (Guideline 10, 2026-09-24) — domyślnie
	 * WYŁĄCZONA na OBU tierach, bez żadnego rozróżnienia licencji: dawniej
	 * branding był wymuszony na wersji darmowej (bez opcji wyłączenia) i
	 * dopiero Pro dostawał ustawienie/filtr — to jest dokładnie "trialware"
	 * pattern, którego zakazuje Guideline 5, nie tylko problem Guideline 10.
	 * Ustawienie i filtr działają identycznie niezależnie od tieru.
	 */
	public function print_branding(): void {
		if ( ! $this->rendered_widget ) {
			return;
		}

		$default = (bool) ( get_option( IOTDW_Settings_Page::OPTION_NAME, array() )['show_branding'] ?? false );

		if ( ! apply_filters( 'iotdw_show_branding', $default ) ) {
			return;
		}

		echo '<p class="iot-dw-branding">' . esc_html__( 'Powered by IoT Data Widget', 'iot-data-widget' ) . '</p>';
	}

	/**
	 * Obsługuje styl "list" — kilka encji naraz z atrybutu "entities"
	 * (oddzielonych przecinkami). Błąd jednej encji nie blokuje pozostałych.
	 *
	 * @param array $atts Znormalizowane atrybuty shortcode.
	 * @return string
	 */
	private function render_list( array $atts ): string {
		if ( '' === $atts['connection'] || '' === $atts['entities'] ) {
			return $this->renderer->render(
				$this->error_data(
					__( 'Fill in the "connection" and "entities" attributes (comma-separated entity IDs) in the [iotdw_widget style="list"] shortcode.', 'iot-data-widget' )
				),
				'card'
			);
		}

		$resolved = $this->resolve_connection( $atts['connection'] );

		if ( null === $resolved ) {
			return $this->renderer->render(
				$this->error_data(
					sprintf(
						/* translators: %s: connection name or ID given in the shortcode */
						__( 'Connection "%s" was not found.', 'iot-data-widget' ),
						$atts['connection']
					)
				),
				'card'
			);
		}

		$connector      = $resolved['connector'];
		$connection_row = $resolved['connection'];

		$entity_ids = array_values( array_filter( array_map( 'trim', explode( ',', $atts['entities'] ) ) ) );
		// 1.0.6: labels="Pompa ciepła,Salon" — etykiety w kolejności entities;
		// pusta pozycja albo brak = etykieta z konektora jak dotąd.
		$labels = array_map( 'sanitize_text_field', array_map( 'trim', explode( ',', (string) $atts['labels'] ) ) );
		$items  = array();

		foreach ( $entity_ids as $index => $entity_id ) {
			$data = $this->fetch_cached( $connector, $connection_row, $entity_id, (int) $atts['ttl'] );

			if ( '' !== ( $labels[ $index ] ?? '' ) && empty( $data['error'] ) ) {
				$data['label'] = $labels[ $index ];
			}

			$data           = self::apply_boolean_label_override( $data, $atts['on_label'], $atts['off_label'] );
			$data           = IOTDW_State_Rules::apply( $data, (string) $atts['states'] );
			$data['entity'] = $entity_id;
			$items[]        = $data;
		}

		return $this->renderer->render_list( $items, $this->style_vars_from_atts( $atts ) );
	}

	/**
	 * Obsługuje styl "grid" (widget multi-encja, v1.3 Tydzień 4) — kilka par
	 * (połączenie, encja) naraz, z RÓŻNYCH połączeń, w przeciwieństwie do
	 * stylu "list" (jedno połączenie, wiele encji). Każda para ma swój
	 * własny wpis w cache (ten sam klucz "connection|entity" co pojedynczy
	 * widget na tę samą parę — naturalne współdzielenie cache, bez żadnego
	 * dodatkowego mechanizmu). Błąd jednej pary (zła encja, nieznane
	 * połączenie) nie blokuje pozostałych komórek siatki.
	 *
	 * @param array $atts Znormalizowane atrybuty shortcode.
	 * @return string
	 */
	private function render_grid( array $atts ): string {
		$pairs = IOTDW_Grid_Items::parse( $atts['items'] );

		if ( empty( $pairs ) ) {
			// Zgłoszenie z audytu (2026-09-18): "items" niepuste, ale bez
			// żadnego separatora "|" (np. "temperatura,przekaznik" zamiast
			// "connection|temperatura,connection|przekaznik") dawało
			// DOKŁADNIE ten sam komunikat co całkowicie pusty/brakujący atrybut
			// — mylące, bo sugeruje, że atrybutu w ogóle nie podano, podczas
			// gdy realny problem to zły format tokenu (patrz
			// IOTDW_Grid_Items::parse() — token bez "|" jest po cichu
			// odrzucany). Rozróżnienie po trim($atts['items']): pusty ->
			// stary komunikat, niepusty ale nic się nie sparsowało -> nowy,
			// wskazujący wprost brakujący "|".
			$message = '' === trim( $atts['items'] )
				? __( 'Add at least one entity to the [iotdw_widget style="grid"] shortcode via the "items" attribute.', 'iot-data-widget' )
				: __( 'The "items" attribute of the [iotdw_widget style="grid"] shortcode must be a comma-separated list of "connection|entity" pairs (e.g. items="Home|sensor.temperature,Workshop|relay_state") — none of the given items matched that format.', 'iot-data-widget' );

			return $this->renderer->render(
				$this->error_data( $message ),
				'card'
			);
		}

		$items = array();

		foreach ( $pairs as $pair ) {
			$resolved = $this->resolve_connection( $pair['connection'] );

			if ( null === $resolved ) {
				$items[] = array_merge(
					$this->error_data(
						sprintf(
							/* translators: %s: connection name or ID given in the shortcode */
							__( 'Connection "%s" was not found.', 'iot-data-widget' ),
							$pair['connection']
						)
					),
					array( 'entity' => $pair['entity'] )
				);
				continue;
			}

			$connector = $resolved['connector'];

			$data           = $this->fetch_cached( $connector, $resolved['connection'], $pair['entity'], (int) $atts['ttl'] );
			$data           = self::apply_boolean_label_override( $data, $atts['on_label'], $atts['off_label'] );
			$data           = IOTDW_State_Rules::apply( $data, (string) $atts['states'] );
			$data['entity'] = $pair['entity'];
			$items[]        = $data;
		}

		return $this->renderer->render_grid( $items, $this->style_vars_from_atts( $atts ) );
	}

	/**
	 * Wyciąga override wyglądu per widget z atrybutów shortcode'u (bg_color,
	 * text_color, accent_color, border_radius, font_size — puste = brak
	 * override, dziedziczy z ustawień globalnych) i tłumaczy na gotowe do
	 * wstrzyknięcia zmienne CSS (v1.5 Tydzień 4).
	 *
	 * @param array $atts Znormalizowane atrybuty shortcode.
	 * @return array<string,string>
	 */
	private function style_vars_from_atts( array $atts ): array {
		return IOTDW_Style_Settings::css_variables( IOTDW_Style_Settings::sanitize_overrides( $atts ) );
	}

	/**
	 * Ładuje CSS widgetu, ale tylko na stronach faktycznie zawierających shortcode.
	 * Chart.js (i jego JS/CSS) dokładają się tylko na stronach, które mają
	 * widget stylu "chart" — reszta stron go nie pobiera.
	 */
	public function maybe_enqueue_assets(): void {
		if ( ! is_singular() ) {
			return;
		}

		$post = get_post();

		if ( ! $post || ! has_shortcode( $post->post_content, 'iotdw_widget' ) ) {
			return;
		}

		$this->enqueue_styles();

		// class_exists() (Guideline 5, 2026-09-24) — chart-widget.js/css są
		// plikami premium-only, w paczce darmowej fizycznie nie istnieją.
		if ( IOTDW_Widget_Usage_Tracker::content_has_style( $post->post_content, array( 'chart' ) ) ) {
			$this->enqueue_chart_assets();
		}

		// v1.8 Tydzień 3 — hybrydowe doładowanie. "chart" celowo poza listą
		// stylów (patrz komentarz nagłówkowy public/js/widget-refresh.js).
		if ( IOTDW_Widget_Usage_Tracker::content_has_style( $post->post_content, array( 'card', 'gauge', 'sparkline', 'list', 'grid' ) ) ) {
			$this->enqueue_refresh_script();
		}
	}

	/**
	 * Późne ładowanie zasobów dla widgetu wyrenderowanego POZA treścią
	 * pojedynczego posta (1.0.2: obszar widgetów motywu klasycznego, lista
	 * wpisów, szablon) — maybe_enqueue_assets() widzi tylko post_content
	 * strony pojedynczej. WordPress drukuje style/skrypty dodane po wp_head
	 * w stopce. Tylko front-end — podglądy w adminie/REST mają własne.
	 *
	 * @param string $style Styl widgetu.
	 */
	private function enqueue_late( string $style ): void {
		if ( is_admin() || ! did_action( 'wp_enqueue_scripts' ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		$this->enqueue_styles();

		if ( 'chart' === $style ) {
			$this->enqueue_chart_assets();
		} else {
			$this->enqueue_refresh_script();
		}
	}

	/**
	 * CSS widgetu + zmienne wyglądu (raz na żądanie).
	 */
	private function enqueue_styles(): void {
		if ( wp_style_is( 'iot-data-widget-theme', 'enqueued' ) ) {
			return;
		}

		// v1.8 Tydzień 6 — tokeny muszą się załadować przed widget-styles.css.
		wp_enqueue_style(
			'iot-data-widget-tokens',
			IOTDW_PLUGIN_URL . 'public/css/design-tokens.css',
			array(),
			IOTDW_VERSION
		);

		wp_enqueue_style(
			'iot-data-widget',
			IOTDW_PLUGIN_URL . 'public/css/widget-styles.css',
			array( 'iot-data-widget-tokens' ),
			IOTDW_VERSION
		);

		wp_enqueue_style(
			'iot-data-widget-theme',
			IOTDW_PLUGIN_URL . 'public/css/widget-theme.css',
			array( 'iot-data-widget' ),
			IOTDW_VERSION
		);

		wp_add_inline_style(
			'iot-data-widget-theme',
			IOTDW_Style_Settings::build_css_rule( IOTDW_Style_Settings::css_variables( IOTDW_Style_Settings::get_settings() ) )
		);
	}

	/**
	 * Chart.js + chart-widget.js/css (tylko Pro — pliki istnieją tylko tam).
	 */
	private function enqueue_chart_assets(): void {
		if ( ! class_exists( 'IOTDW_Renderer_Chart' ) || wp_script_is( 'iot-data-widget-chart', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'chart-js',
			IOTDW_PLUGIN_URL . 'public/js/vendor/chart.umd.min.js',
			array(),
			'4.5.1',
			true
		);

		wp_enqueue_script(
			'iot-data-widget-chart',
			IOTDW_PLUGIN_URL . 'public/js/chart-widget.js',
			array( 'chart-js' ),
			IOTDW_VERSION,
			true
		);

		wp_enqueue_style(
			'iot-data-widget-chart',
			IOTDW_PLUGIN_URL . 'public/css/chart-widget.css',
			array( 'iot-data-widget' ),
			IOTDW_VERSION
		);
	}

	/**
	 * Skrypt jednorazowego odświeżenia wartości po załadowaniu strony.
	 */
	private function enqueue_refresh_script(): void {
		if ( wp_script_is( 'iot-data-widget-widget-refresh', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script(
			'iot-data-widget-widget-refresh',
			IOTDW_PLUGIN_URL . 'public/js/widget-refresh.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_localize_script(
			'iot-data-widget-widget-refresh',
			'iotdwWidgetRefresh',
			array(
				'restUrl'      => esc_url_raw( rest_url( IOTDW_Rest_Api::NAMESPACE ) ),
				// Mapowanie stanów (1.0.4): nazwa koloru dla czytników ekranu,
				// gdy reguła ma sam kolor — ten sam tekst co IOTDW_Renderer.
				/* translators: %s: status colour name, e.g. "green" */
				'statusFormat' => __( 'Status: %s', 'iot-data-widget' ),
				'statusColors' => array_combine( IOTDW_State_Rules::COLORS, array_map( array( 'IOTDW_State_Rules', 'color_label' ), IOTDW_State_Rules::COLORS ) ),
			)
		);
	}

	/**
	 * Znajduje zapisane połączenie po ID/nazwie i buduje dla niego konektor
	 * jednym wywołaniem. Od v1.6 Tydzień 1 wywołujący (render/render_list/
	 * render_grid) potrzebuje samego rozwiązanego $connection['id'] (UUID),
	 * nie tylko gotowego konektora — IOTDW_Cache::remember() musi dostać
	 * prawdziwe ID połączenia do IOTDW_Diagnostics_Logger, a atrybut
	 * shortcode'u może być nazwą połączenia zamiast ID (patrz
	 * IOTDW_Connector_Manager::find_by_id_or_name()).
	 *
	 * @param string $id_or_name ID lub nazwa połączenia z atrybutu shortcode'u.
	 * @return array{connection: array<string,string>, connector: IOTDW_Abstract_Connector}|null
	 */
	private function resolve_connection( string $id_or_name ): ?array {
		$connection = $this->connector_manager->find_by_id_or_name( $id_or_name );

		if ( null === $connection ) {
			return null;
		}

		$connector = $this->connector_manager->make( $connection );

		if ( null === $connector ) {
			return null;
		}

		return array(
			'connection' => $connection,
			'connector'  => $connector,
		);
	}

	/**
	 * Woła fetch_data() konektora, łapiąc dowolny \Throwable — błąd/bug
	 * wewnątrz konektora (np. w kolejnym, dodanym w przyszłości) ma
	 * degradować do czytelnego komunikatu, a nie wywalać stronę fatalnym
	 * błędem PHP. Public (v1.8 Tydzień 1) — reużywane przez
	 * IOTDW_Rest_Api::get_widget_data() (publiczny endpoint odświeżania),
	 * żeby ta sama ochrona przed fatal errorem obowiązywała tam bez
	 * duplikowania jej osobno.
	 *
	 * @param IOTDW_Abstract_Connector $connector Instancja konektora.
	 * @param string                   $entity_id ID encji / ścieżka do wartości.
	 * @return array
	 */
	public function safe_fetch( IOTDW_Abstract_Connector $connector, string $entity_id ): array {
		try {
			return $connector->fetch_data( $entity_id );
		} catch ( \Throwable $e ) {
			return $this->error_data(
				sprintf(
					/* translators: %s: PHP error message */
					__( 'Unexpected connector error: %s', 'iot-data-widget' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Woła list_entities() konektora Listable, łapiąc dowolny \Throwable —
	 * ta sama ochrona co safe_fetch(), dla listowania zamiast pojedynczej
	 * encji (kreator multi-entity, poprawka Tydzień 1-2, patrz
	 * docs/archiwum/poprawka-kreator-multi-entity.md). Wywołujący sprawdza
	 * `instanceof IOTDW_Listable_Connector_Interface` PRZED wywołaniem —
	 * ta metoda nie robi tego sama, bo $connector jest już otypowany jako
	 * IOTDW_Abstract_Connector (bazowa klasa nie ma list_entities(), które
	 * rzuca — tylko domyślne, zawsze puste, patrz jej komentarz), więc
	 * wołający musi sam zawęzić typ.
	 *
	 * @param IOTDW_Abstract_Connector $connector Instancja konektora (musi implementować IOTDW_Listable_Connector_Interface).
	 * @return array{entities: array<int, array{id: string, label: string, domain: string, current_state: string}>, error: string|null}
	 */
	public function safe_list_entities( IOTDW_Abstract_Connector $connector ): array {
		try {
			return array(
				'entities' => $connector->list_entities(),
				'error'    => null,
			);
		} catch ( \RuntimeException $e ) {
			// Celowe błędy konektora (brak połączenia, 401, adres zablokowany)
			// — komunikat już jest dla użytkownika, bez "Unexpected".
			return array(
				'entities' => array(),
				'error'    => $e->getMessage(),
			);
		} catch ( \Throwable $e ) {
			return array(
				'entities' => array(),
				'error'    => sprintf(
					/* translators: %s: PHP error message */
					__( 'Unexpected connector error: %s', 'iot-data-widget' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Buduje znormalizowaną strukturę błędu do przekazania rendererowi.
	 * Public (v1.8 Tydzień 1) — patrz safe_fetch().
	 *
	 * @param string $message Komunikat błędu.
	 * @return array{value: null, unit: null, label: null, last_updated: null, error: string, display_value: null}
	 */
	public function error_data( string $message ): array {
		return array(
			'value'         => null,
			'unit'          => null,
			'label'         => null,
			'last_updated'  => null,
			'error'         => $message,
			'display_value' => null,
		);
	}

	/**
	 * Nadpisuje display_value dla wartości logicznych (0.0/1.0 z rozpoznanym
	 * display_value — patrz IOTDW_Value_Normalizer) etykietami "on"/"off"
	 * skonfigurowanymi per widget (zgłoszenie 2026-08-28: Supla przez link
	 * bezpośredni pokazywała pusty widget, Shelly switch:0.output/Tasmota
	 * POWER dają nieprzyjazne albo niedostosowywalne teksty).
	 *
	 * Dwa tryby (patrz resolve_boolean_labels()):
	 * - Użytkownik skonfigurował on_label/off_label w widgetcie → zawsze
	 *   wygrywają (dowolny tekst, np. "Otwarte"/"Zamknięte" dla czujnika
	 *   kontaktowego), brakująca strona pary dostaje domyślne "On"/"Off".
	 * - Brak konfiguracji → nadpisujemy WYŁĄCZNIE generyczne "true"/"false"
	 *   (dosłowny fallback IOTDW_Value_Normalizer::normalize() dla surowego
	 *   bool z JSON, bez żadnego sensownego słowa stanu) domyślnym
	 *   "On"/"Off" — semantycznie znaczący tekst, który konektor już podał
	 *   (HA "home", Shelly/Tasmota "ON", HA "locked"...) zostaje BEZ ZMIAN,
	 *   żeby nie podmienić dobrze dobranego stanu na mylącą etykietę
	 *   przełącznika (np. osoba "w domu" nie powinna nagle stać się
	 *   "Włączona").
	 *
	 * Nie dotyka niczego, co nie jest rozpoznaną wartością logiczną — pusty
	 * display_value (zwykły sensor numeryczny) albo wartość spoza {0.0, 1.0}
	 * (np. wilgotność=0%, która przypadkiem jest liczbą całkowitą) wychodzi
	 * bez zmian.
	 *
	 * Dodatkowo stempluje 'bool_label_on'/'bool_label_off' (OBIE rozwiązane
	 * etykiety, nie tylko aktualnie aktywną) na $data — IOTDW_Renderer::
	 * refresh_data_attr() przepisuje je na data-iot-dw-on-label/off-label,
	 * które public/js/widget-refresh.js odczytuje przy jednorazowym
	 * doładowaniu po starcie strony. Bez tego JEDNORAZOWE odświeżenie z
	 * publicznego endpointu (IOTDW_Rest_Api::get_widget_data(), który nie
	 * zna atrybutów shortcode'u/bloku — cache kluczowany tylko po
	 * connection|entity) cofałoby świeżo naprawioną/dostosowaną etykietę z
	 * powrotem do surowego display_value konektora ułamek sekundy po
	 * załadowaniu strony.
	 *
	 * @param array  $data      Znormalizowane dane (pojedynczy odczyt albo jeden element listy/siatki).
	 * @param string $on_label  Własna etykieta dla wartości 1.0 (pusty string = brak override).
	 * @param string $off_label Własna etykieta dla wartości 0.0 (pusty string = brak override).
	 * @return array
	 */
	private static function apply_boolean_label_override( array $data, string $on_label, string $off_label ): array {
		if ( ! is_numeric( $data['value'] ?? null ) ) {
			return $data;
		}

		$value = (float) $data['value'];

		if ( 0.0 !== $value && 1.0 !== $value ) {
			return $data;
		}

		$labels = self::resolve_boolean_labels( $data, $on_label, $off_label );

		if ( null === $labels ) {
			return $data;
		}

		$data['display_value']  = 1.0 === $value ? $labels['on'] : $labels['off'];
		$data['bool_label_on']  = $labels['on'];
		$data['bool_label_off'] = $labels['off'];

		return $data;
	}

	/**
	 * Znaczniki czasu historii (recorded_at w UTC) → czas strony. Wcześniej
	 * oś wykresu i ukryta tabela pokazywały surowe UTC (przegląd demo
	 * 2026-10-22: „dane stoją od 17:05” o 19:10 czasu polskiego).
	 *
	 * @param string[] $utc_labels Znaczniki 'Y-m-d H:i:s' w UTC.
	 * @return string[]
	 */
	private static function local_time_labels( array $utc_labels ): array {
		return array_map(
			static function ( $utc ) {
				$timestamp = strtotime( $utc . ' UTC' );

				return false !== $timestamp ? wp_date( 'Y-m-d H:i', $timestamp ) : (string) $utc;
			},
			$utc_labels
		);
	}

	/**
	 * Rozwiązuje PARĘ etykiet on/off (nie tylko tę pasującą do bieżącej
	 * wartości) — patrz komentarz w apply_boolean_label_override() o tym, po
	 * co obie naraz. Zwraca null, gdy żadna reguła się nie stosuje (encja nie
	 * jest rozpoznaną wartością logiczną, albo jest, ale ma już sensowny
	 * tekst i nikt go nie nadpisał ręcznie) — w takim wypadku wywołujący nie
	 * zmienia $data w ogóle.
	 *
	 * @param array  $data      Znormalizowane dane.
	 * @param string $on_label  Własna etykieta dla wartości 1.0.
	 * @param string $off_label Własna etykieta dla wartości 0.0.
	 * @return array{on: string, off: string}|null
	 */
	private static function resolve_boolean_labels( array $data, string $on_label, string $off_label ): ?array {
		if ( empty( $data['display_value'] ) ) {
			return null;
		}

		$is_generic = in_array( strtolower( (string) $data['display_value'] ), array( 'true', 'false' ), true );

		if ( '' === $on_label && '' === $off_label && ! $is_generic ) {
			return null;
		}

		return array(
			'on'  => '' !== $on_label ? $on_label : __( 'On', 'iot-data-widget' ),
			'off' => '' !== $off_label ? $off_label : __( 'Off', 'iot-data-widget' ),
		);
	}
}
