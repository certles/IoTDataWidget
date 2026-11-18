<?php
/**
 * Cykliczne raporty e-mail — magazyn konfiguracji (v1.3 Tydzień 7). Sama
 * wysyłka i logika "czy już czas wysłać" żyje w IOTDW_Email_Report_Sender —
 * ta klasa to wyłącznie CRUD nad tabelą.
 *
 * ŚWIADOMIE bez @fs_premium_only (audyt paczki darmowej, Faza 6 planu
 * freemium, 2026-09-14 — sprawdzone, nie przeoczone): w przeciwieństwie do
 * IOTDW_Alert_Manager (gdzie ocena/mutacja reguł powędrowała do
 * IOTDW_Alert_Evaluator, Pro) ta klasa nie ma nic do rozdzielenia — same
 * create/read/update/delete bez żadnej logiki biznesowej, więc żaden kawałek
 * nie kwalifikuje się jako "Pro do ochrony". Zostaje w całości, bo jest
 * typ-hintowana konstruktorem w DWÓCH miejscach zawsze-załadowanych: skeletonie
 * IOTDW_Email_Report_Sender (SEND_HOOK, potrzebny na obu tierach) i
 * Pro-gatowanym IOTDW_Email_Reports_Page — rozdzielenie kosztowałoby realne
 * rozluźnienie tego typowania bez żadnej korzyści.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Email_Report_Manager
 */
class IOTDW_Email_Report_Manager {

	/**
	 * Nazwa tabeli (bez prefiksu WP).
	 */
	const TABLE = 'iotdw_email_reports';

	/**
	 * Tworzy tabelę konfiguracji raportów (wołane z IOTDW_Plugin::activate()).
	 *
	 * Kolumny connection_id/entity_id VARCHAR (36/191) — ten sam schemat co
	 * iotdw_alert_rules/iotdw_history (ID połączeń to string UUID, nie
	 * BIGINT), żeby zapytania łączące te tabele (gdyby kiedyś były potrzebne)
	 * miały spójne typy.
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id VARCHAR(36) NOT NULL,
			entity_id VARCHAR(191) NOT NULL,
			frequency ENUM('daily','weekly') NOT NULL,
			destination VARCHAR(255) NOT NULL,
			last_sent_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY connection_entity (connection_id, entity_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Zwraca wszystkie skonfigurowane raporty (do listy w panelu admina).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_reports(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY id DESC', ARRAY_A );
	}

	/**
	 * Znajduje jeden raport po ID (do edycji w panelu admina).
	 *
	 * @param int $id ID raportu.
	 * @return array<string, mixed>|null
	 */
	public function find_report( int $id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		// phpcs:enable

		return null === $row ? null : $row;
	}

	/**
	 * Zapisuje raport (nowy albo edytowany — o tym decyduje obecność
	 * niezerowego 'id'). Edycja NIE resetuje last_sent_at — zmiana np.
	 * adresu e-mail nie powinna sama w sobie wymusić natychmiastowej
	 * wysyłki, kolejny raport i tak pójdzie zgodnie z ustalonym
	 * harmonogramem przy następnym przebiegu crona.
	 *
	 * @param array{id?: int, connection_id: string, entity_id: string, frequency: string, destination: string} $report Dane raportu.
	 * @return int ID zapisanego raportu.
	 */
	public function save_report( array $report ): int {
		global $wpdb;

		$table   = $wpdb->prefix . self::TABLE;
		$data    = array(
			'connection_id' => $report['connection_id'],
			'entity_id'     => $report['entity_id'],
			'frequency'     => $report['frequency'],
			'destination'   => $report['destination'],
		);
		$formats = array( '%s', '%s', '%s', '%s' );

		if ( ! empty( $report['id'] ) ) {
			$wpdb->update( $table, $data, array( 'id' => (int) $report['id'] ), $formats, array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

			return (int) $report['id'];
		}

		$wpdb->insert( $table, $data, $formats ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) $wpdb->insert_id;
	}

	/**
	 * Usuwa raport.
	 *
	 * @param int $id ID raportu.
	 */
	public function delete_report( int $id ): void {
		global $wpdb;

		$wpdb->delete( $wpdb->prefix . self::TABLE, array( 'id' => $id ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	}

	/**
	 * Oznacza raport jako wysłany o podanym czasie — wołane przez
	 * IOTDW_Email_Report_Sender zaraz po udanej wysyłce.
	 *
	 * @param int    $id      ID raportu.
	 * @param string $sent_at Znacznik czasu (MySQL DATETIME, UTC).
	 */
	public function mark_sent( int $id, string $sent_at ): void {
		global $wpdb;

		$wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->prefix . self::TABLE,
			array( 'last_sent_at' => $sent_at ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}
