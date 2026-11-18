<?php
/**
 * Śledzi, które pary (połączenie, encja) są używane w widgetach [iotdw_widget]
 * na stronie, niezależnie od stylu. Konsumenci tej tabeli sami filtrują wg
 * własnych potrzeb: IOTDW_History_Recorder bierze tylko style
 * "chart"/"sparkline" (bo tylko one potrzebują historii), a
 * IOTDW_Cron_Value_Cache bierze wszystkie style dla połączeń MQTT (bo
 * fetch_data() konektora MQTT tylko czyta z cache — nie robi żywego
 * zapytania do brokera, więc bez cron pollingu KAŻDY styl z MQTT pokazywałby
 * "brak danych").
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Widget_Usage_Tracker
 *
 * Skanuje treść posta pod kątem shortcode'u [iotdw_widget]. Wstawiony przez
 * blok Gutenberg widget zapisuje się w post_content jako dokładnie ten sam
 * tekst shortcode'u (patrz blocks/iot-widget/src/save.js) — jeden skan
 * shortcode'u pokrywa więc oba przypadki (ręczny shortcode i blok), bez
 * osobnego parsowania parse_blocks().
 */
class IOTDW_Widget_Usage_Tracker {

	/**
	 * Nazwa tabeli (bez prefiksu WP).
	 */
	const TABLE = 'iotdw_widget_usage';

	/**
	 * Menedżer konektorów — do rozwiązania nazwy/ID połączenia z shortcode'u
	 * na kanoniczne ID zapisywane w tabeli.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager $connector_manager Menedżer konektorów.
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager ) {
		$this->connector_manager = $connector_manager;

		add_action( 'save_post', array( $this, 'sync_post' ) );
		add_action( 'before_delete_post', array( $this, 'remove_post' ) );

		foreach ( array( 'sidebars_widgets', 'widget_block', 'widget_text', 'widget_custom_html', IOTDW_Connector_Manager::CONNECTIONS_OPTION ) as $option ) {
			add_action( 'update_option_' . $option, array( __CLASS__, 'flush_widget_area_usage' ) );
			add_action( 'add_option_' . $option, array( __CLASS__, 'flush_widget_area_usage' ) );
		}
	}

	/**
	 * Transient z parami użytymi w obszarach widgetów motywu klasycznego.
	 */
	const WIDGET_AREA_TRANSIENT = 'iotdw_widget_area_usage';

	/**
	 * Typy widgetów, w których treści szukamy [iotdw_widget] (id_base => pole treści).
	 */
	const WIDGET_CONTENT_FIELDS = array(
		'block'       => 'content',
		'text'        => 'text',
		'custom_html' => 'content',
	);

	/**
	 * Kasuje cache par z obszarów widgetów (zmiana widgetów/pasków/połączeń).
	 */
	public static function flush_widget_area_usage(): void {
		delete_transient( self::WIDGET_AREA_TRANSIENT );
	}

	/**
	 * Użycie widgetów w AKTYWNYCH obszarach widgetów motywu klasycznego —
	 * odpowiednik wierszy tabeli użycia dla postów (to samo: connection_id,
	 * entity_id, style), ale liczony z opcji widgetów zamiast zapisywany,
	 * żeby nie zmieniać schematu tabeli (post_id jest kluczem jej
	 * czyszczenia). Widgety nieaktywne (wp_inactive_widgets) się nie
	 * liczą — nie są nigdzie wyświetlane. Cache w transiencie, kasowany przy
	 * każdej zmianie widgetów/pasków/połączeń (patrz konstruktor).
	 *
	 * Konsumenci: is_publicly_used() (/widget-data), odpytywanie MQTT
	 * (IOTDW_MQTT_Poll_Engine) i zapis historii (IOTDW_History_Recorder).
	 *
	 * @return array<int, array{connection_id: string, entity_id: string, style: string}>
	 */
	public function widget_area_usage(): array {
		$cached = get_transient( self::WIDGET_AREA_TRANSIENT );

		if ( is_array( $cached ) && ( array() === $cached || isset( $cached[0]['style'] ) ) ) {
			return $cached;
		}

		$usage = array();
		// Opcja wprost — wp_get_sidebars_widgets() poza adminem zapamiętuje
		// wynik w globalnej zmiennej na całe żądanie.
		$sidebars = get_option( 'sidebars_widgets', array() );
		$sidebars = is_array( $sidebars ) ? $sidebars : array();

		foreach ( $sidebars as $sidebar_id => $widget_ids ) {
			if ( 'wp_inactive_widgets' === $sidebar_id || 'array_version' === $sidebar_id || ! is_array( $widget_ids ) ) {
				continue;
			}

			foreach ( $widget_ids as $widget_id ) {
				if ( ! preg_match( '/^(.+)-(\d+)$/', (string) $widget_id, $m ) || ! isset( self::WIDGET_CONTENT_FIELDS[ $m[1] ] ) ) {
					continue;
				}

				$instances = get_option( 'widget_' . $m[1], array() );
				$content   = $instances[ (int) $m[2] ][ self::WIDGET_CONTENT_FIELDS[ $m[1] ] ] ?? '';

				if ( ! is_string( $content ) || '' === $content ) {
					continue;
				}

				foreach ( $this->extract_tracked_pairs( $content ) as $pair ) {
					$usage[ $pair['connection_id'] . '|' . $pair['entity_id'] . '|' . $pair['style'] ] = $pair;
				}
			}
		}

		$usage = array_values( $usage );

		set_transient( self::WIDGET_AREA_TRANSIENT, $usage, DAY_IN_SECONDS );

		return $usage;
	}

	/**
	 * Tworzy tabelę użycia widgetów (wołane z IOTDW_Plugin::activate()).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		// dbDelta() below only ADDS indexes it doesn't recognize by name —
		// it never drops one that's no longer in the CREATE TABLE statement.
		// This 3-column unique key predates 'style' joining the uniqueness
		// constraint and, left in place, still blocks two different-style
		// widgets (e.g. Chart + Sparkline) for the same connection+entity in
		// one post — found live via demo recording, see docs/dev-setup.md.
		$table_exists = $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( $table_exists ) {
			// $table is always $wpdb->prefix . self::TABLE (a hardcoded
			// constant, never user input) — esc_sql() here is belt-and-suspenders
			// against PCP's own DirectDB.UnescapedDBParameter sniff (Plugin
			// Check's internal ruleset, separate from and not covered by the
			// WPCS-native phpcs:disable below), not a real exploitability fix.
			// SHOW INDEX/ALTER TABLE don't support %s placeholders for
			// identifiers, so $wpdb->prepare() doesn't apply here.
			$escaped_table = esc_sql( $table );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.SchemaChange
			$obsolete_index = $wpdb->get_var(
				"SHOW INDEX FROM {$escaped_table} WHERE Key_name = 'post_connection_entity'"
			);

			if ( null !== $obsolete_index ) {
				$wpdb->query( "ALTER TABLE {$escaped_table} DROP INDEX post_connection_entity" );
			}
			// phpcs:enable
		}

		// connection_entity (v1.8, code review): IOTDW_Rest_Api::get_widget_data()
		// (jedyny publiczny endpoint wtyczki) woła is_publicly_used() na
		// KAŻDE żądanie, celowo bez cache'owania decyzji (patrz jej
		// komentarz — cache'owanie autoryzacji cofnęłoby naprawę z Tygodnia
		// 2, gdzie post cofnięty do szkicu musi natychmiast przestać
		// działać). Zapytanie filtruje po (connection_id, entity_id), NIE
		// po post_id — bez tego indeksu jedyny istniejący klucz
		// (post_connection_entity_style, zaczynający się od post_id) nie
		// pomaga wcale, więc każde wywołanie robiło pełny skan tabeli.
		// Szybsze zapytanie, nie luźniejsza autoryzacja.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			post_id BIGINT UNSIGNED NOT NULL,
			connection_id VARCHAR(36) NOT NULL,
			entity_id VARCHAR(191) NOT NULL,
			style VARCHAR(50) NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY post_connection_entity_style (post_id, connection_id, entity_id, style),
			KEY connection_entity (connection_id, entity_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Odświeża wiersze użycia dla zapisywanego posta — usuwa stare, wstawia
	 * aktualne. To jednocześnie obsługuje przypadek "widget usunięty z treści".
	 *
	 * @param int $post_id ID zapisywanego posta.
	 */
	public function sync_post( int $post_id ): void {
		// post_id=0 nigdy nie jest prawdziwym postem WP — to sygnatura
		// ręcznie śledzonych wpisów (v1.2 Tydzień 9, patrz add_manual_entry()).
		// Jawna strażniczka, żeby to nigdy nie zależało od ubocznego
		// zachowania get_post(0) — zapis/edycja żadnego posta nie może
		// dotknąć ręcznie dodanych wierszy.
		if ( $post_id <= 0 || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$post = get_post( $post_id );

		if ( ! $post || 'auto-draft' === $post->post_status ) {
			return;
		}

		$this->replace_usage( $post_id, $this->extract_tracked_pairs( $post->post_content ) );
	}

	/**
	 * Usuwa wszystkie wiersze użycia powiązane z usuwanym postem.
	 *
	 * @param int $post_id ID usuwanego posta.
	 */
	public function remove_post( int $post_id ): void {
		// Ta sama strażniczka co w sync_post() — post_id=0 to ręcznie
		// śledzone wpisy, nigdy prawdziwy post.
		if ( $post_id <= 0 ) {
			return;
		}

		global $wpdb;

		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Zwraca ręcznie dodane wpisy śledzenia (post_id=0) — widgety poza
	 * treścią postów (np. w szablonie motywu), których save_post/before_delete_post
	 * nigdy nie zobaczy. Patrz add_manual_entry().
	 *
	 * @return array<int, array{id: int, connection_id: string, entity_id: string, style: string}>
	 */
	public function get_manual_entries(): array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results(
			'SELECT id, connection_id, entity_id, style FROM ' . $wpdb->prefix . self::TABLE . ' WHERE post_id = 0 ORDER BY id DESC',
			ARRAY_A
		);
		// phpcs:enable
	}

	/**
	 * Sprawdza, czy dana para (connection_id, entity_id) jest faktycznie
	 * użyta w treści co najmniej jednego OPUBLIKOWANEGO posta, DO KTÓREGO
	 * BIEŻĄCY ODWIEDZAJĄCY MA FAKTYCZNIE DOSTĘP (v1.8 Tydzień 1, poprawka
	 * 2026-09-24 po zgłoszeniu z testu na żywo) — to jedyna autoryzacja
	 * publicznego endpointu odświeżania danych (`GET /widget-data/...`,
	 * IOTDW_Rest_Api::get_widget_data()), więc musi być restrykcyjna z
	 * definicji: JOIN na wp_posts wymusza realny, opublikowany post, nie
	 * tylko istnienie wiersza w tej tabeli.
	 *
	 * Post chroniony hasłem (`post_password` niepuste) jest opublikowany
	 * (`post_status = 'publish'`), ale NADAL nie jest publicznie widoczny
	 * bez znajomości hasła — sama treść posta jest zamaskowana na froncie
	 * (patrz rdzeniowe `post_password_required()`), więc para osadzona
	 * WYŁĄCZNIE w takim poście nie powinna autoryzować publicznego
	 * odczytu danych, dopóki żądający nie udowodni znajomości hasła (WP-owe
	 * ciasteczko `wp-postpass_...`, ustawiane po poprawnym wpisaniu hasła w
	 * standardowym formularzu WP). Bez tej poprawki `/widget-data` był
	 * jedynym miejscem w całej wtyczce, które ignorowało ochronę hasłem
	 * posta — realny wyciek: encja pokazana WYŁĄCZNIE na stronie z hasłem
	 * była mimo to czytelna anonimowo przez ten endpoint.
	 *
	 * Ręcznie dodane wpisy (post_id=0, patrz add_manual_entry()) celowo NIE
	 * kwalifikują się — nie ma dla nich żadnego posta do zweryfikowania.
	 *
	 * 1.0.2: kwalifikuje się też widget w AKTYWNYM obszarze widgetów motywu
	 * klasycznego (patrz widget_area_usage()) — wyświetlany publicznie tak
	 * samo jak opublikowany post. Wcześniej taki widget nigdy się nie
	 * odświeżał (404 z /widget-data).
	 *
	 * @param string $connection_id ID połączenia (kanoniczne, nie nazwa).
	 * @param string $entity_id     ID encji.
	 * @return bool
	 */
	public function is_publicly_used( string $connection_id, string $entity_id ): bool {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$post_ids = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT DISTINCT wu.post_id FROM {$table} wu INNER JOIN {$wpdb->posts} p ON wu.post_id = p.ID WHERE wu.connection_id = %s AND wu.entity_id = %s AND p.post_status = 'publish'", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				$connection_id,
				$entity_id
			)
		);
		// phpcs:enable

		foreach ( $post_ids as $post_id ) {
			$post = get_post( (int) $post_id );

			// post_password_required() sama sprawdza ciasteczko wp-postpass_...
			// bieżącego żądania względem hasła TEGO KONKRETNEGO posta —
			// zwraca false zarówno gdy post w ogóle nie ma hasła, jak i gdy
			// ma, ale żądający je zna (poprawne ciasteczko).
			if ( null !== $post && ! post_password_required( $post ) ) {
				return true;
			}
		}

		foreach ( $this->widget_area_usage() as $pair ) {
			if ( $pair['connection_id'] === $connection_id && $pair['entity_id'] === $entity_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Dodaje (albo, przy dokładnym duplikacie, po cichu zastępuje) ręcznie
	 * śledzony wpis — post_id=0 wyklucza go z automatycznego czyszczenia
	 * przy zapisie/usunięciu jakiegokolwiek prawdziwego posta (patrz
	 * sync_post()/remove_post()). $wpdb->replace() jest tu bezpieczny: klucz
	 * unikalny (post_id, connection_id, entity_id, style) i tak wymusza co
	 * najwyżej jeden wiersz na tę trójkę.
	 *
	 * @param string $connection_id ID połączenia.
	 * @param string $entity_id     ID encji.
	 * @param string $style         Styl widgetu (np. "chart") — decyduje, czy encja trafia do historii (IOTDW_History_Recorder::snapshot_all() bierze tylko chart/sparkline).
	 */
	public function add_manual_entry( string $connection_id, string $entity_id, string $style ): void {
		global $wpdb;

		$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::TABLE,
			array(
				'post_id'       => 0,
				'connection_id' => $connection_id,
				'entity_id'     => $entity_id,
				'style'         => $style,
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Usuwa jeden ręcznie dodany wpis po ID. Warunek `post_id = 0` w WHERE
	 * jest celowy — nawet gdyby przez pomyłkę podano ID wiersza należącego
	 * do prawdziwego posta, ta metoda i tak go nie usunie.
	 *
	 * @param int $id ID wiersza w tabeli.
	 */
	public function delete_manual_entry( int $id ): void {
		global $wpdb;

		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::TABLE,
			array(
				'id'      => $id,
				'post_id' => 0,
			),
			array( '%d', '%d' )
		);
	}

	/**
	 * Sprawdza, czy treść zawiera co najmniej jeden [iotdw_widget] w jednym ze
	 * wskazanych stylów — bez wyciągania pełnych danych, tylko tak/nie.
	 * Używane m.in. przy warunkowym enqueue assetów (np. Chart.js tylko na
	 * stronach, które faktycznie mają widget stylu "chart").
	 *
	 * @param string   $content Treść posta (post_content).
	 * @param string[] $styles  Style do sprawdzenia, np. array( 'chart' ).
	 * @return bool
	 */
	public static function content_has_style( string $content, array $styles ): bool {
		if ( ! has_shortcode( $content, 'iotdw_widget' ) ) {
			return false;
		}

		$pattern = get_shortcode_regex( array( 'iotdw_widget' ) );

		if ( ! preg_match_all( '/' . $pattern . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return false;
		}

		foreach ( $matches as $match ) {
			$atts  = shortcode_parse_atts( $match[3] );
			$style = is_array( $atts ) ? ( $atts['style'] ?? 'card' ) : 'card';

			if ( in_array( $style, $styles, true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Wyciąga z treści posta pary (connection_id, entity_id, style) dla
	 * każdego widgetu [iotdw_widget] — bez względu na styl (patrz komentarz
	 * klasy dla uzasadnienia). Styl "list" ma wiele encji naraz
	 * (atrybut "entities", nie "entity") — każda dostaje swój własny wiersz.
	 *
	 * @param string $content Treść posta (post_content).
	 * @return array<int, array{connection_id: string, entity_id: string, style: string}>
	 */
	private function extract_tracked_pairs( string $content ): array {
		$pairs = array();

		if ( ! has_shortcode( $content, 'iotdw_widget' ) ) {
			return $pairs;
		}

		$pattern = get_shortcode_regex( array( 'iotdw_widget' ) );

		if ( ! preg_match_all( '/' . $pattern . '/', $content, $matches, PREG_SET_ORDER ) ) {
			return $pairs;
		}

		foreach ( $matches as $match ) {
			$atts = shortcode_parse_atts( $match[3] );

			if ( ! is_array( $atts ) ) {
				continue;
			}

			$style = $atts['style'] ?? 'card';

			// Styl "grid" (v1.3 Tydzień 4) nie ma jednego atrybutu "connection"
			// dla całego widgetu — każda para w "items" niesie własne połączenie,
			// więc rozwiązujemy je osobno zamiast (jak dla pozostałych stylów)
			// przez wspólne $connection ustalone raz dla całego shortcode'u.
			if ( 'grid' === $style ) {
				// class_exists() (Guideline 5, 2026-09-24) — grid jest Pro;
				// na wersji darmowej ten plik fizycznie nie istnieje. Bez
				// tego guarda ZAPIS DOWOLNEGO POSTA zawierającego tekst
				// [iotdw_widget style="grid"...] (np. wklejony z instalacji
				// Pro) fatal-errorowałby na free — zamiast tego po prostu
				// brak par do śledzenia dla tego widgetu (nie renderuje się
				// i tak, patrz IOTDW_Shortcode::render()).
				if ( class_exists( 'IOTDW_Grid_Items' ) ) {
					foreach ( IOTDW_Grid_Items::parse( $atts['items'] ?? '' ) as $grid_pair ) {
						$grid_connection = $this->connector_manager->find_by_id_or_name( $grid_pair['connection'] );

						if ( null === $grid_connection ) {
							continue;
						}

						$pairs[] = array(
							'connection_id' => $grid_connection['id'],
							'entity_id'     => $grid_pair['entity'],
							'style'         => $style,
						);
					}
				}

				continue;
			}

			$connection = $this->connector_manager->find_by_id_or_name( trim( $atts['connection'] ?? '' ) );

			if ( null === $connection ) {
				continue;
			}

			$entity_ids = 'list' === $style
				? array_filter( array_map( 'trim', explode( ',', $atts['entities'] ?? '' ) ) )
				: array_filter( array( trim( $atts['entity'] ?? '' ) ) );

			foreach ( $entity_ids as $entity_id ) {
				$pairs[] = array(
					'connection_id' => $connection['id'],
					'entity_id'     => $entity_id,
					'style'         => $style,
				);
			}
		}

		return $pairs;
	}

	/**
	 * Zastępuje wiersze użycia dla danego posta świeżym zestawem.
	 *
	 * @param int                                                                        $post_id ID posta.
	 * @param array<int, array{connection_id: string, entity_id: string, style: string}> $pairs   Pary do zapisania.
	 */
	private function replace_usage( int $post_id, array $pairs ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		$wpdb->delete( $table, array( 'post_id' => $post_id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		// Deduplikacja po (connection_id, entity_id, style) — zgłoszenie 1.0.1
		// (Priorytet 2, 2026-10-06): dwa widgety na jednej stronie, które po
		// rozwiązaniu referencji (find_by_id_or_name() wyżej w
		// extract_tracked_pairs()) wskazują na TO SAMO połączenie/encję/styl
		// (np. jeden przez UUID, drugi przez nazwę połączenia), inaczej
		// trafiały tu jako dwa identyczne wiersze i łamały UNIQUE KEY
		// post_connection_entity_style — WordPress database error, który pod
		// WP_DEBUG_DISPLAY wylewał się do odpowiedzi REST i psuł zapis w
		// edytorze bloków ("response is not a valid JSON response").
		$seen  = array();
		$pairs = array_values(
			array_filter(
				$pairs,
				static function ( array $pair ) use ( &$seen ): bool {
					$key = $pair['connection_id'] . '|' . $pair['entity_id'] . '|' . $pair['style'];
					if ( isset( $seen[ $key ] ) ) {
						return false;
					}
					$seen[ $key ] = true;
					return true;
				}
			)
		);

		foreach ( $pairs as $pair ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$table,
				array(
					'post_id'       => $post_id,
					'connection_id' => $pair['connection_id'],
					'entity_id'     => $pair['entity_id'],
					'style'         => $pair['style'],
				),
				array( '%d', '%s', '%s', '%s' )
			);
		}
	}
}
