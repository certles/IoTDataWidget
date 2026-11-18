<?php
/**
 * Odczyt surowej historii wartości jednej pary (połączenie, encja) — czyste,
 * bezstanowe odpytanie tabeli wp_iotdw_history, bez żadnej logiki
 * biznesowej. Statyczne (zero zależności instancji) — bo obaj konsumenci
 * (IOTDW_Email_Report_Sender, zawsze załadowany dla wiązania crona/
 * diagnostyki, i Pro-owy IOTDW_History_Csv_Export) wołają je bez potrzeby
 * wstrzykiwania IOTDW_Connector_Manager, którego to i tak nigdy nie
 * używało.
 *
 * Sam eksport CSV (formularz admina, streamowanie do przeglądarki,
 * `admin_post_iotdw_export_history`) to Pro (v1.3 Tydzień 6) — żyje w
 * IOTDW_History_Csv_Export (includes/premium/), fizycznie usuwanym z
 * paczki darmowej (@fs_premium_only w iot-data-widget.php, dopisane przy
 * audycie paczki darmowej, Faza 6 planu freemium, 2026-09-14). fetch_batch()/
 * fetch_all() zostają tutaj, bo IOTDW_Email_Report_Sender ich potrzebuje
 * niezależnie od tieru (raporty e-mail same w sobie są Pro, ale ta klasa nie
 * wie o tym — patrz jej komentarz nagłówkowy).
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_History_Export
 */
class IOTDW_History_Export {

	/**
	 * Liczba wierszy pobieranych z bazy na raz.
	 */
	const BATCH_SIZE = 1000;

	/**
	 * Pobiera CAŁĄ historię danej pary w zakresie (wszystkie porcje naraz,
	 * scalone) — dla konsumentów, którym zależy na kompletnym zbiorze do
	 * obliczeń (np. wysyłka raportów e-mail, Pro, licząc min/max/średnią),
	 * nie na strumieniowaniu. Bezpieczne przy typowej skali raportu
	 * dziennego/tygodniowego (setki wierszy, nie tysiące) — dla naprawdę
	 * dużych zakresów użyj fetch_batch() bezpośrednio, jak robi to
	 * IOTDW_History_Csv_Export::stream_csv() (Pro).
	 *
	 * @param string $connection_id Kanoniczne ID połączenia.
	 * @param string $entity_id     ID encji.
	 * @param int    $from_ts       Początek zakresu (UTC).
	 * @param int    $to_ts         Koniec zakresu (UTC).
	 * @return array<int, array{recorded_at: string, value: string}>
	 */
	public static function fetch_all( string $connection_id, string $entity_id, int $from_ts, int $to_ts ): array {
		$all    = array();
		$offset = 0;

		do {
			$batch      = self::fetch_batch( $connection_id, $entity_id, $from_ts, $to_ts, $offset );
			$batch_size = count( $batch );
			$all        = array_merge( $all, $batch );
			$offset    += self::BATCH_SIZE;
		} while ( self::BATCH_SIZE === $batch_size );

		return $all;
	}

	/**
	 * Pobiera jedną porcję wierszy historii — publiczna wyłącznie po to, żeby
	 * dało się ją przetestować bezpośrednio (kolejność, granice zakresu,
	 * paginacja) bez uruchamiania całego strumieniowania.
	 *
	 * @param string $connection_id Kanoniczne ID połączenia.
	 * @param string $entity_id     ID encji.
	 * @param int    $from_ts       Początek zakresu (UTC).
	 * @param int    $to_ts         Koniec zakresu (UTC).
	 * @param int    $offset        Przesunięcie (LIMIT/OFFSET).
	 * @return array<int, array{recorded_at: string, value: string}>
	 */
	public static function fetch_batch( string $connection_id, string $entity_id, int $from_ts, int $to_ts, int $offset ): array {
		global $wpdb;

		$table = $wpdb->prefix . IOTDW_History_Recorder::TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT recorded_at, value FROM {$table} WHERE connection_id = %s AND entity_id = %s AND recorded_at BETWEEN %s AND %s ORDER BY recorded_at ASC LIMIT %d OFFSET %d",
				$connection_id,
				$entity_id,
				gmdate( 'Y-m-d H:i:s', $from_ts ),
				gmdate( 'Y-m-d H:i:s', $to_ts ),
				self::BATCH_SIZE,
				$offset
			),
			ARRAY_A
		);
		// phpcs:enable

		return is_array( $rows ) ? $rows : array();
	}
}
