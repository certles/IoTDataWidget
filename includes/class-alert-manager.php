<?php
/**
 * Alerty progowe — magazyn reguł (CRUD) i schemat tabeli (v1.2 Tydzień 7).
 * Wyłącznie odczyt/zapis wierszy — bez logiki oceny progu, bez rejestracji
 * hooków. Ta klasa zostaje ZAWSZE (obie paczki, oba tiery), bo:
 *
 * 1. IOTDW_History_Recorder::snapshot_all() odpytuje encje "alert-only" przez
 *    UNION SQL bezpośrednio po self::TABLE — potrzebuje nazwy tabeli
 *    niezależnie od tieru.
 * 2. IOTDW_Diagnostics_Page::render_page() woła get_rules() zawsze (strona
 *    diagnostyki działa na obu tierach, po prostu pokazuje pustą listę, gdy
 *    reguł nie ma).
 *
 * Sama OCENA reguł (nasłuch na 'iotdw_snapshot_recorded', porównanie z
 * progiem, histereza) i ZAPIS/USUWANIE reguł (save_rule()/delete_rule() —
 * wołane wyłącznie z Pro-gatowanego IOTDW_Alert_Rules_Page) to faktyczna
 * funkcja Pro — żyje w IOTDW_Alert_Evaluator (includes/premium/), fizycznie
 * usuwanym z paczki darmowej (@fs_premium_only w iot-data-widget.php, dopisane
 * przy audycie paczki darmowej, Faza 6, 2026-09-14). get_rules()/find_rule()
 * zostają tutaj mimo bycia "read-only częścią CRUD", bo są potrzebne przez
 * kod zawsze-darmowy (diagnostyka) — bez nich Diagnostics_Page nie miałaby
 * skąd wziąć listy reguł do wyświetlenia.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Alert_Manager
 */
class IOTDW_Alert_Manager {

	/**
	 * Nazwa tabeli (bez prefiksu WP).
	 */
	const TABLE = 'iotdw_alert_rules';

	/**
	 * Tworzy tabelę reguł alertów (wołane z IOTDW_Plugin::activate()).
	 *
	 * Uwaga: kolumny connection_id/entity_id celowo VARCHAR (36/191), nie
	 * BIGINT UNSIGNED jak w pierwotnym przykładzie z planu — ID połączeń w
	 * tym pluginie to string UUID (wp_generate_uuid4(), patrz
	 * IOTDW_Admin_Page::handle_save()), dokładnie jak w
	 * iotdw_history/iotdw_widget_usage. BIGINT byłby niezgodny z resztą
	 * schematu i złamałby UNION w IOTDW_History_Recorder::snapshot_all().
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id VARCHAR(36) NOT NULL,
			entity_id VARCHAR(191) NOT NULL,
			condition_type ENUM('above','below') NOT NULL,
			threshold_value DECIMAL(12,4) NOT NULL,
			channel ENUM('email','webhook') NOT NULL,
			destination VARCHAR(255) NOT NULL,
			last_state ENUM('normal','triggered') NOT NULL DEFAULT 'normal',
			last_triggered_at DATETIME NULL,
			PRIMARY KEY  (id),
			KEY connection_entity (connection_id, entity_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Zwraca wszystkie reguły alertów (do listy w panelu admina i na stronie
	 * diagnostyki — obie działają na obu tierach).
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function get_rules(): array {
		global $wpdb;

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return $wpdb->get_results( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' ORDER BY id DESC', ARRAY_A );
	}

	/**
	 * Znajduje jedną regułę po ID (do edycji w panelu admina, Pro).
	 *
	 * @param int $id ID reguły.
	 * @return array<string, mixed>|null
	 */
	public function find_rule( int $id ): ?array {
		global $wpdb;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row(
			$wpdb->prepare( 'SELECT * FROM ' . $wpdb->prefix . self::TABLE . ' WHERE id = %d', $id ),
			ARRAY_A
		);
		// phpcs:enable

		return null === $row ? null : $row;
	}
}
