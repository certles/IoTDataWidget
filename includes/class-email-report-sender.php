<?php
/**
 * Cykliczne raporty e-mail — wysyłka: orkiestracja crona (v1.3 Tydzień 7).
 * WP-Cron odpala send_due_reports() co godzinę; ta klasa zostaje ZAWSZE
 * (obie paczki, oba tiery), bo SEND_HOOK musi istnieć dla
 * IOTDW_Plugin::activate()/deactivate() (zaplanowanie/odpięcie crona) i dla
 * IOTDW_Diagnostics_Page (status crona widoczny na obu tierach).
 *
 * Metody is_due()/frequency_seconds() też zostają — trywialna, bezstanowa logika
 * bez wartości do ochrony. Na darmowym tierze pętla w send_due_reports() i
 * tak nigdy nic nie robi (get_reports() zawsze pusta — brak Pro-gatowanego
 * ekranu admina, na którym dałoby się dodać raport), ale samo GENEROWANIE
 * treści raportu (fetch historii, min/max/średnia, budowa e-maila HTML) to
 * faktyczna funkcja Pro — wydzielone do IOTDW_Email_Report_Dispatcher
 * (includes/premium/) przy audycie paczki darmowej (Faza 6 planu freemium,
 * 2026-09-14), fizycznie usuwanej z paczki darmowej (@fs_premium_only w
 * iot-data-widget.php).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Email_Report_Sender
 */
class IOTDW_Email_Report_Sender {

	/**
	 * Hook WP-Cron.
	 */
	const SEND_HOOK = 'iotdw_send_email_reports';

	/**
	 * Menedżer konektorów — przekazywany dalej do IOTDW_Email_Report_Dispatcher
	 * (Pro), sama ta klasa go nie używa.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Magazyn konfiguracji raportów.
	 *
	 * @var IOTDW_Email_Report_Manager
	 */
	private $report_manager;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager Menedżer konektorów.
	 * @param IOTDW_Email_Report_Manager $report_manager    Magazyn konfiguracji raportów.
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Email_Report_Manager $report_manager ) {
		$this->connector_manager = $connector_manager;
		$this->report_manager    = $report_manager;

		// Owinięte w IOTDW_Diagnostics_Logger::run_cron() (code review) — ten
		// sam wzorzec co pozostałe crony wtyczki (IOTDW_History_Recorder,
		// IOTDW_Cron_Value_Cache): loguje wynik uruchomienia i łapie wyjątki, bez
		// wklejania try/catch do wnętrza send_due_reports() samego w sobie.
		// send_due_reports() zostaje wywoływalne bezpośrednio (testy, ręczne
		// wywołania) bez efektu ubocznego logowania.
		add_action(
			self::SEND_HOOK,
			function () {
				IOTDW_Diagnostics_Logger::run_cron( self::SEND_HOOK, array( $this, 'send_due_reports' ) );
			}
		);
	}

	/**
	 * Wołane przez WP-Cron (co godzinę) — wysyła każdy raport, który jest
	 * "należny" (patrz is_due()). Błąd wysyłki jednego raportu (np. martwy
	 * connector) nigdy nie przerywa przetwarzania pozostałych. Na darmowym
	 * tierze get_reports() jest zawsze pusta (brak ekranu admina do dodania
	 * raportu, patrz IOTDW_Plugin::init_modules()) — pętla poniżej to
	 * no-op. class_exists() (Guideline 5, 2026-09-24) — dawniej dodatkowo
	 * `!iotdw_is_premium()`, usunięte jako runtime license-check nad kodem
	 * fizycznie obecnym w paczce darmowej.
	 */
	public function send_due_reports(): void {
		if ( ! class_exists( 'IOTDW_Email_Report_Dispatcher' ) ) {
			return;
		}

		$now        = time();
		$dispatcher = new IOTDW_Email_Report_Dispatcher( $this->connector_manager );

		foreach ( $this->report_manager->get_reports() as $report ) {
			if ( ! $this->is_due( $report, $now ) ) {
				continue;
			}

			try {
				$dispatcher->send_report( $report, $now );
			} catch ( \Throwable $e ) {
				if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
					error_log( // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						sprintf( '[IoT Data Widget] Email report #%d failed: %s', $report['id'], $e->getMessage() )
					);
				}
				continue;
			}

			$this->report_manager->mark_sent( (int) $report['id'], gmdate( 'Y-m-d H:i:s', $now ) );
		}
	}

	/**
	 * Czy dany raport powinien zostać wysłany teraz. Nigdy niewysłany raport
	 * jest zawsze należny (wysyła się przy najbliższym przebiegu crona po
	 * dodaniu). Sprawdzanie co godzinę (nie dokładnie co 24h/7d) jest celowe —
	 * wystarczająco częste dla dziennej/tygodniowej częstotliwości, odporne
	 * na pojedynczy pominięty przebieg crona (nadrobi przy następnym).
	 *
	 * @param array<string, mixed> $report Wiersz konfiguracji raportu.
	 * @param int                  $now    Bieżący znacznik czasu UTC (parametr, żeby dało się to przetestować deterministycznie).
	 * @return bool
	 */
	public function is_due( array $report, int $now ): bool {
		if ( empty( $report['last_sent_at'] ) ) {
			return true;
		}

		$last_sent = strtotime( $report['last_sent_at'] . ' UTC' );

		if ( false === $last_sent ) {
			return true;
		}

		return ( $now - $last_sent ) >= $this->frequency_seconds( $report['frequency'] );
	}

	/**
	 * Tłumaczy częstotliwość raportu na okno czasowe w sekundach.
	 *
	 * @param string $frequency 'daily' albo 'weekly'.
	 * @return int
	 */
	private function frequency_seconds( string $frequency ): int {
		return 'weekly' === $frequency ? 7 * DAY_IN_SECONDS : DAY_IN_SECONDS;
	}
}
