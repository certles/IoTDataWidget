<?php
/**
 * Panel administracyjny — strona diagnostyki (v1.6 Tydzień 3). Jeden ekran,
 * jeden przycisk "kopiuj raport" (Tydzień 6) ma wystarczyć zamiast rundy
 * e-maili z prośbą o zrzuty ekranu, gdy klient napisze "nie działa mi
 * widget" — patrz docs/archiwum/mvp-v1-6-plan.md.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Diagnostics_Page
 */
class IOTDW_Diagnostics_Page {

	/**
	 * Menedżer konektorów — lista skonfigurowanych połączeń (v1.6 Tydzień 4).
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Menedżer reguł alertów — reużywane last_state/last_triggered_at
	 * z v1.2, żadnej nowej logiki (v1.6 Tydzień 4).
	 *
	 * @var IOTDW_Alert_Manager
	 */
	private $alert_manager;

	/**
	 * Menedżer kanałów push — status "aktywny/nieaktywny" i czas ostatniego
	 * odbioru (§5 planu push, is_channel_active()/get_last_received_at()). Faza 7
	 * planu freemium — znalezione na żywo: obie metody miały testy w
	 * Push_Channel_Manager_Test.php, ale nie były wołane z ŻADNEGO widoku
	 * (grep po całym repo poza definiującym plikiem — zero trafień) — kolumny
	 * "Last checked"/"Status" tej strony pochodzą z IOTDW_Diagnostics_Logger,
	 * którego kanały push nigdy nie zasilają (nie przechodzą przez Cache
	 * Layer, patrz IOTDW_Connector_Manager::bypasses_render_cache()), więc
	 * bez tego wiersz push zawsze pokazywał "No data yet" niezależnie od
	 * rzeczywistej aktywności kanału.
	 *
	 * @var IOTDW_Push_Channel_Manager
	 */
	private $push_channel_manager;

	/**
	 * Hook suffix strony (z add_submenu_page()) — do warunkowego enqueue JS
	 * tylko na tej jednej stronie admina (v1.6 Tydzień 6).
	 *
	 * @var string
	 */
	private $page_hook_suffix = '';

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager    Menedżer konektorów.
	 * @param IOTDW_Alert_Manager        $alert_manager        Menedżer reguł alertów.
	 * @param IOTDW_Push_Channel_Manager $push_channel_manager Menedżer kanałów push.
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Alert_Manager $alert_manager, IOTDW_Push_Channel_Manager $push_channel_manager ) {
		$this->connector_manager    = $connector_manager;
		$this->alert_manager        = $alert_manager;
		$this->push_channel_manager = $push_channel_manager;

		// Priorytet 60 (v1.7 Tydzień 2, docs/archiwum/audyt-ui-v1-7.md) — kolejność podmenu.
		add_action( 'admin_menu', array( $this, 'register_menu' ), 60 );
		add_action( 'admin_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Rejestruje podstronę "Diagnostics" pod głównym menu wtyczki.
	 */
	public function register_menu(): void {
		$this->page_hook_suffix = add_submenu_page(
			'iot-data-widget',
			__( 'IoT Data Widget — Diagnostics', 'iot-data-widget' ),
			__( 'Diagnostics', 'iot-data-widget' ),
			'manage_options',
			'iot-data-widget-diagnostics',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Ładuje JS przycisku "Copy report" (v1.6 Tydzień 6) — tylko na tej
	 * jednej stronie admina.
	 *
	 * @param string $hook_suffix Hook suffix bieżącej strony admina.
	 */
	public function maybe_enqueue_assets( string $hook_suffix ): void {
		if ( $hook_suffix !== $this->page_hook_suffix ) {
			return;
		}

		wp_enqueue_script(
			'iot-data-widget-diagnostics-report',
			IOTDW_PLUGIN_URL . 'admin/js/diagnostics-report.js',
			array(),
			IOTDW_VERSION,
			true
		);

		wp_localize_script(
			'iot-data-widget-diagnostics-report',
			'iotdwDiagnosticsReport',
			array(
				'copiedText' => __( 'Copied!', 'iot-data-widget' ),
			)
		);

		// admin-toolkit.css (partial data-table.php i klasy statusu) —
		// ładowane centralnie, patrz
		// IOTDW_Plugin::maybe_enqueue_admin_toolkit_css() (code review v1.8).
	}

	/**
	 * Renderuje stronę diagnostyki.
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$environment = $this->environment_info();
		$cron_jobs   = $this->cron_jobs_info();
		$connections = $this->connections_info();
		// Null = sekcja ukryta: alerty są Pro (klasa fizycznie tylko w paczce Pro).
		$alert_rules       = class_exists( 'IOTDW_Alert_Evaluator' ) ? $this->alert_manager->get_rules() : null;
		$connections_by_id = array_column( $this->connector_manager->get_connections(), null, 'id' );
		$recent_errors     = IOTDW_Diagnostics_Logger::get_recent_errors();
		$data_sizes        = $this->data_size_info();
		$report_text       = $this->build_report_text( $environment, $cron_jobs, $connections, $alert_rules, $connections_by_id, $recent_errors, $data_sizes );

		require IOTDW_PLUGIN_DIR . 'admin/views/diagnostics.php';
	}

	/**
	 * Buduje pełny tekstowy raport diagnostyczny łączący wszystkie sekcje
	 * strony (v1.6 Tydzień 6) — dokładnie ten tekst klient wklei do e-maila
	 * do supportu, więc każdy fragment tu użyty MUSI już przejść przez
	 * redakcję sekretów (Ustalenie #3). Wszystkie dane wejściowe tej metody
	 * pochodzą z gettery IOTDW_Diagnostics_Logger, które już redagują przy
	 * odczycie — jedyny NOWY surowy fragment wprowadzany tutaj to status
	 * licencji, gdzie świadomie pomijamy sam klucz (patrz niżej).
	 *
	 * @param array      $environment       Z environment_info().
	 * @param array      $cron_jobs         Z cron_jobs_info().
	 * @param array      $connections       Z connections_info().
	 * @param array|null $alert_rules  Z IOTDW_Alert_Manager::get_rules(); null = sekcja ukryta (wersja darmowa).
	 * @param array      $connections_by_id Połączenia indeksowane po ID.
	 * @param array      $recent_errors     Z IOTDW_Diagnostics_Logger::get_recent_errors().
	 * @param array      $data_sizes        Z data_size_info().
	 * @return string
	 */
	private function build_report_text( array $environment, array $cron_jobs, array $connections, ?array $alert_rules, array $connections_by_id, array $recent_errors, array $data_sizes ): string {
		// Code review v1.8: wcześniej wszystkie stringi w tej metodzie były
		// gołym angielskim tekstem, niezgodnie z CLAUDE.md ("i18n od
		// początku: wszystkie stringi przez __()/_e()") — ten ekran jest
		// jedynym w całej wtyczce pomyślanym do wklejenia wprost do e-maila
		// wsparcia, więc był jedynym miejscem, które NIGDY nie tłumaczyłoby
		// się niezależnie od języka profilu admina.
		$lines = array();

		$lines[] = __( 'IoT Data Widget — Diagnostic Report', 'iot-data-widget' );
		/* translators: %s: current date/time in UTC, e.g. "2026-08-13 12:00:00" */
		$lines[] = sprintf( __( 'Generated: %s UTC', 'iot-data-widget' ), current_time( 'mysql', true ) );
		$lines[] = '';

		$lines[] = __( '== Environment ==', 'iot-data-widget' );
		/* translators: %s: PHP version, e.g. "8.1.34" */
		$lines[] = sprintf( __( 'PHP version: %s', 'iot-data-widget' ), $environment['php_version'] );
		/* translators: %s: WordPress version, e.g. "7.0.3" */
		$lines[] = sprintf( __( 'WordPress version: %s', 'iot-data-widget' ), $environment['wp_version'] );
		/* translators: %s: active theme name and version, e.g. "Twenty Twenty-Five 1.4" */
		$lines[] = sprintf( __( 'Active theme: %s', 'iot-data-widget' ), $environment['active_theme'] );
		/* translators: %s: PHP memory limit, e.g. "256M" */
		$lines[] = sprintf( __( 'Memory limit: %s', 'iot-data-widget' ), $environment['memory_limit'] );
		/* translators: %s: PHP max execution time in seconds, e.g. "30" */
		$lines[] = sprintf( __( 'Max execution time: %ss', 'iot-data-widget' ), $environment['max_execution_time'] );
		$lines[] = sprintf(
			/* translators: %s: comma-separated list of other active plugin names, or "None" */
			__( 'Other active plugins: %s', 'iot-data-widget' ),
			empty( $environment['active_plugins'] ) ? __( 'None', 'iot-data-widget' ) : implode( ', ', $environment['active_plugins'] )
		);
		$lines[] = '';

		$lines[] = __( '== Scheduled tasks ==', 'iot-data-widget' );
		foreach ( $cron_jobs as $job ) {
			if ( ! $job['is_scheduled'] ) {
				$status_text = __( 'Not scheduled', 'iot-data-widget' );
			} elseif ( null === $job['last_run'] ) {
				$status_text = __( 'Scheduled, never run yet', 'iot-data-widget' );
			} elseif ( $job['last_run']['success'] ) {
				$status_text = __( 'Scheduled & running', 'iot-data-widget' );
			} else {
				$status_text = sprintf(
					/* translators: %s: reason the last cron run failed */
					__( 'Scheduled, last run failed: %s', 'iot-data-widget' ),
					IOTDW_Diagnostics_Logger::redact_secrets( $job['last_run']['message'] )
				);
			}

			$next_run_text = false !== $job['next_run'] ? gmdate( 'Y-m-d H:i:s', $job['next_run'] ) . ' UTC' : '—';
			$last_run_text = null !== $job['last_run'] ? $job['last_run']['ran_at'] . ' UTC' : '—';

			$lines[] = sprintf(
				/* translators: 1: task label, 2: status text, 3: next scheduled run, 4: last run time */
				__( '%1$s: %2$s (next: %3$s, last: %4$s)', 'iot-data-widget' ),
				$job['label'],
				$status_text,
				$next_run_text,
				$last_run_text
			);
		}
		$lines[] = '';

		$lines[] = __( '== Connections ==', 'iot-data-widget' );
		if ( empty( $connections ) ) {
			$lines[] = __( 'None configured.', 'iot-data-widget' );
		} else {
			foreach ( $connections as $connection ) {
				if ( $connection['is_push'] ) {
					$push_status_text = null === $connection['push_active']
						? __( 'no data received yet', 'iot-data-widget' )
						: ( $connection['push_active'] ? __( 'active', 'iot-data-widget' ) : __( 'inactive', 'iot-data-widget' ) );

					$lines[] = sprintf(
						/* translators: 1: connection name, 2: connector type label, 3: push status text, 4: last received time */
						__( '%1$s (%2$s): %3$s, last received %4$s', 'iot-data-widget' ),
						$connection['name'],
						$connection['connector_label'],
						$push_status_text,
						$connection['last_received_at'] ?? '—'
					);

					continue;
				}

				$status_text = null === $connection['last_status']
					? __( 'No data yet', 'iot-data-widget' )
					: ( 'ok' === $connection['last_status'] ? __( 'OK', 'iot-data-widget' ) : __( 'Error', 'iot-data-widget' ) );

				$lines[] = sprintf(
					/* translators: 1: connection name, 2: connector type label, 3: status text, 4: consecutive error count, 5: last checked time */
					__( '%1$s (%2$s): %3$s, %4$d consecutive errors, last checked %5$s', 'iot-data-widget' ),
					$connection['name'],
					$connection['connector_label'],
					$status_text,
					$connection['consecutive_errors'],
					$connection['last_checked_at'] ?? '—'
				);

				if ( null !== $connection['last_error_message'] ) {
					/* translators: %s: last recorded error message for this connection */
					$lines[] = sprintf( __( '  Last error: %s', 'iot-data-widget' ), $connection['last_error_message'] );
				}
			}
		}
		$lines[] = '';

		if ( null !== $alert_rules ) {
			$lines[] = __( '== Alert rules ==', 'iot-data-widget' );
			if ( empty( $alert_rules ) ) {
				$lines[] = __( 'None configured.', 'iot-data-widget' );
			}
			foreach ( $alert_rules as $rule ) {
				$rule_connection = $connections_by_id[ $rule['connection_id'] ] ?? null;

				$lines[] = sprintf(
					/* translators: 1: connection name, 2: entity ID, 3: comparison operator (> or <), 4: threshold value, 5: "Triggered" or "Normal", 6: optional " (last triggered: ...)" note or empty string */
					__( '%1$s / %2$s: %3$s %4$s — %5$s%6$s', 'iot-data-widget' ),
					$rule_connection['name'] ?? $rule['connection_id'],
					$rule['entity_id'],
					'above' === $rule['condition_type'] ? '>' : '<',
					$rule['threshold_value'],
					'triggered' === $rule['last_state'] ? __( 'Triggered', 'iot-data-widget' ) : __( 'Normal', 'iot-data-widget' ),
					! empty( $rule['last_triggered_at'] )
						/* translators: %s: last-triggered date/time in UTC */
						? sprintf( __( ' (last triggered: %s UTC)', 'iot-data-widget' ), $rule['last_triggered_at'] )
						: ''
				);
			}
			$lines[] = '';
		}

		$lines[] = __( '== Recent errors ==', 'iot-data-widget' );
		if ( empty( $recent_errors ) ) {
			$lines[] = __( 'None recorded.', 'iot-data-widget' );
		} else {
			foreach ( $recent_errors as $error_entry ) {
				$lines[] = sprintf(
					/* translators: 1: date/time in UTC, 2: error source, 3: error message */
					__( '[%1$s UTC] %2$s: %3$s', 'iot-data-widget' ),
					$error_entry['occurred_at'],
					$error_entry['source'],
					$error_entry['message']
				);
			}
		}
		$lines[] = '';

		$lines[] = __( '== Data size ==', 'iot-data-widget' );
		foreach ( $data_sizes as $table ) {
			$lines[] = sprintf(
				/* translators: 1: table label (already translated), 2: row count */
				__( '%1$s: %2$d', 'iot-data-widget' ),
				$table['label'],
				$table['count']
			);
		}

		return implode( "\n", $lines );
	}

	/**
	 * Zbiera dane sekcji "Data size" (v1.6 Tydzień 5, krok 2) — liczba
	 * wierszy w każdej tabeli własnej wtyczki, sygnał czy cleanup faktycznie
	 * działa (nieskończenie rosnąca iotdw_history sugerowałaby, że cleanup
	 * z v1.1 przestał działać).
	 *
	 * @return array<int, array{label: string, count: int}>
	 */
	private function data_size_info(): array {
		global $wpdb;

		// Tabele funkcji Pro tylko wtedy, gdy klasa Pro istnieje w paczce.
		$tables = array();

		if ( class_exists( 'IOTDW_Renderer_Chart' ) ) {
			$tables[ __( 'Recorded value history', 'iot-data-widget' ) ] = $wpdb->prefix . IOTDW_History_Recorder::TABLE;
		}

		$tables[ __( 'Widget usage tracking', 'iot-data-widget' ) ] = $wpdb->prefix . IOTDW_Widget_Usage_Tracker::TABLE;

		if ( class_exists( 'IOTDW_Alert_Evaluator' ) ) {
			$tables[ __( 'Alert rules', 'iot-data-widget' ) ] = $wpdb->prefix . IOTDW_Alert_Manager::TABLE;
		}

		if ( class_exists( 'IOTDW_MQTT_Poll_Engine' ) ) {
			$tables[ __( 'MQTT cache', 'iot-data-widget' ) ] = $wpdb->prefix . IOTDW_Cron_Value_Cache::CACHE_TABLE;
		}

		$tables[ __( 'Error log', 'iot-data-widget' ) ] = $wpdb->prefix . IOTDW_Diagnostics_Logger::TABLE_ERROR_LOG;

		$rows = array();

		foreach ( $tables as $label => $table ) {
			// $table is always $wpdb->prefix . a hardcoded TABLE constant
			// (never user input) — esc_sql() here is belt-and-suspenders
			// against Plugin Check's own DirectDB.UnescapedDBParameter sniff
			// (separate from, and not covered by, the WPCS-native
			// phpcs:disable below), not a real exploitability fix. COUNT(*)
			// FROM doesn't support a %s placeholder for the table name, so
			// $wpdb->prepare() doesn't apply here.
			$escaped_table = esc_sql( $table );

			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
			$count = (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . $escaped_table );
			// phpcs:enable

			$rows[] = array(
				'label' => $label,
				'count' => $count,
			);
		}

		return $rows;
	}

	/**
	 * Zbiera dane sekcji "Connections" (v1.6 Tydzień 4, krok 1) — łączy
	 * skonfigurowane połączenia z ich statusem z IOTDW_Diagnostics_Logger
	 * (Tydzień 1). Połączenie bez żadnego zapisanego statusu (nigdy jeszcze
	 * nie odpytane naprawdę) dostaje last_status = null — widok musi to
	 * pokazać jako "no data yet", nie jako błąd.
	 *
	 * @return array<int, array{name: string, connector_label: string, last_checked_at: string|null, last_status: string|null, consecutive_errors: int, last_error_message: string|null, is_push: bool, push_active: bool|null, last_received_at: string|null}>
	 */
	private function connections_info(): array {
		$statuses = IOTDW_Diagnostics_Logger::get_all_connection_statuses();
		$types    = $this->connector_manager->get_types();
		$rows     = array();

		foreach ( $this->connector_manager->get_connections() as $connection ) {
			$status         = $statuses[ $connection['id'] ] ?? null;
			$connector_type = $connection['connector'] ?? '';
			$is_push        = IOTDW_Push_Channel_Manager::CONNECTOR_TYPE === $connector_type;

			// is_channel_active() zwraca zwykły bool — false zarówno dla
			// "nieaktywny po progu 24h", jak i "nigdy nic nie odebrano"
			// (patrz Push_Channel_Manager_Test.php,
			// test_channel_is_inactive_when_nothing_ever_received). Te dwa
			// przypadki muszą się różnić na ekranie ("Inactive" ostrzega o
			// prawdziwym problemie, "No data received yet" to neutralny stan
			// świeżo utworzonego kanału) — stąd null tylko wtedy, gdy
			// last_received_at też jest null, nie przez sam is_channel_active().
			$last_received_at = $is_push ? $this->push_channel_manager->get_last_received_at( $connection['id'] ) : null;
			$push_active      = ( $is_push && null !== $last_received_at )
				? $this->push_channel_manager->is_channel_active( $connection['id'] )
				: null;

			$rows[] = array(
				'name'               => $connection['name'] ?? $connection['id'],
				'connector_label'    => $types[ $connector_type ] ?? $connector_type,
				'last_checked_at'    => $status['last_checked_at'] ?? null,
				'last_status'        => $status['last_status'] ?? null,
				'consecutive_errors' => null !== $status ? (int) $status['consecutive_errors'] : 0,
				'last_error_message' => $status['last_error_message'] ?? null,
				// Kanały push nie przechodzą przez Cache Layer/Diagnostics
				// Logger (pola wyżej są dla nich zawsze puste) — status
				// pochodzi z osobnego mechanizmu (§5 planu push).
				'is_push'            => $is_push,
				'push_active'        => $push_active,
				'last_received_at'   => $last_received_at,
			);
		}

		return $rows;
	}

	/**
	 * Zbiera dane sekcji "Środowisko" (v1.6 Tydzień 3, krok 2).
	 *
	 * @return array{php_version: string, wp_version: string, active_theme: string, active_plugins: string[], memory_limit: string, max_execution_time: string}
	 */
	private function environment_info(): array {
		$theme = wp_get_theme();

		return array(
			'php_version'        => PHP_VERSION,
			'wp_version'         => get_bloginfo( 'version' ),
			'active_theme'       => trim( $theme->get( 'Name' ) . ' ' . $theme->get( 'Version' ) ),
			'active_plugins'     => $this->other_active_plugins(),
			'memory_limit'       => (string) ini_get( 'memory_limit' ),
			'max_execution_time' => (string) ini_get( 'max_execution_time' ),
		);
	}

	/**
	 * Zwraca czytelną listę INNYCH aktywnych wtyczek (nazwa + wersja) — pod
	 * kątem ewentualnych konfliktów, nie licząc samej IoT Data Widget.
	 * Porównanie po dokładnym basename pluginu (nie substring na ścieżce),
	 * żeby nigdy przypadkiem nie pominąć/nie złapać złej wtyczki.
	 *
	 * @return string[]
	 */
	private function other_active_plugins(): array {
		if ( ! function_exists( 'get_plugin_data' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		$self   = plugin_basename( IOTDW_PLUGIN_FILE );
		$active = (array) get_option( 'active_plugins', array() );
		$names  = array();

		foreach ( $active as $plugin_file ) {
			if ( $plugin_file === $self ) {
				continue;
			}

			$full_path = WP_PLUGIN_DIR . '/' . $plugin_file;

			if ( ! file_exists( $full_path ) ) {
				continue;
			}

			$data = get_plugin_data( $full_path, false, false );
			$name = '' !== ( $data['Name'] ?? '' ) ? $data['Name'] : $plugin_file;

			$names[] = '' !== ( $data['Version'] ?? '' ) ? $name . ' ' . $data['Version'] : $name;
		}

		return $names;
	}

	/**
	 * Zbiera dane sekcji "Zaplanowane zadania" (v1.6 Tydzień 3, krok 3) dla
	 * czterech hooków cronowych logowanych od Tygodnia 2. Trzy rozróżniane
	 * stany (patrz kryteria akceptacji planu): zaplanowany i uruchamiany,
	 * zaplanowany ale nigdy nie uruchomiony, niezaplanowany wcale — ten
	 * ostatni to realny problem (np. deaktywacja/reaktywacja w złej
	 * kolejności zgubiła wp_schedule_event()).
	 *
	 * @return array<int, array{hook: string, label: string, is_scheduled: bool, next_run: int|false, last_run: array{ran_at: string, success: bool, message: string}|null}>
	 */
	private function cron_jobs_info(): array {
		// 'requires_class' — crony funkcji Pro tylko wtedy, gdy klasa Pro
		// fizycznie istnieje w paczce (wersja darmowa ich nie ma, więc wiersz
		// "Poll MQTT brokers" itp. był tam mylący).
		$hooks = array(
			IOTDW_History_Recorder::SNAPSHOT_HOOK => array( __( 'Record value history', 'iot-data-widget' ), 'IOTDW_Renderer_Chart' ),
			IOTDW_History_Recorder::CLEANUP_HOOK  => array( __( 'Clean up old history & error log', 'iot-data-widget' ), '' ),
			IOTDW_Cron_Value_Cache::POLL_HOOK     => array( __( 'Poll MQTT brokers', 'iot-data-widget' ), 'IOTDW_MQTT_Poll_Engine' ),
			IOTDW_Email_Report_Sender::SEND_HOOK  => array( __( 'Send due email reports', 'iot-data-widget' ), 'IOTDW_Email_Report_Dispatcher' ),
		);

		$jobs = array();

		foreach ( $hooks as $hook => list( $label, $requires_class ) ) {
			if ( '' !== $requires_class && ! class_exists( $requires_class ) ) {
				continue;
			}

			$jobs[] = array(
				'hook'         => $hook,
				'label'        => $label,
				'is_scheduled' => false !== wp_next_scheduled( $hook ),
				'next_run'     => wp_next_scheduled( $hook ),
				'last_run'     => IOTDW_Diagnostics_Logger::get_cron_status( $hook ),
			);
		}

		return $jobs;
	}
}
