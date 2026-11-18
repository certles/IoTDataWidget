<?php
/**
 * Encje zapisane per połączenie (kreator multi-entity, poprawka
 * docs/archiwum/poprawka-kreator-multi-entity.md, Tydzień 4) — lista "rzeczy, które
 * mnie interesują i chcę mieć pod ręką", niezależna od tego, czy dana encja
 * jest już gdziekolwiek wstawiona jako widget. To NIE to samo co
 * IOTDW_Widget_Usage_Tracker: tamta tabela śledzi encje faktycznie użyte w
 * opublikowanych widgetach (do historii/alertów); ta tutaj istnieje wyłącznie
 * po to, żeby kreator (Krok 3) i edytor bloku mogły zaproponować szybki
 * wybór bez ponownego odpytywania źródła danych.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Saved_Entities
 */
class IOTDW_Saved_Entities {

	/**
	 * Nazwa tabeli (bez prefiksu WP).
	 */
	const TABLE = 'iotdw_saved_entities';

	/**
	 * Tworzy tabelę zapisanych encji (wołane z IOTDW_Plugin::activate() i
	 * maybe_upgrade_db(), ten sam wzorzec co pozostałe tabele wtyczki — patrz
	 * IOTDW_Widget_Usage_Tracker::create_table()).
	 */
	public static function create_table(): void {
		global $wpdb;

		$table           = $wpdb->prefix . self::TABLE;
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			connection_id VARCHAR(36) NOT NULL,
			entity_id VARCHAR(191) NOT NULL,
			friendly_name VARCHAR(255) NULL,
			added_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY connection_entity (connection_id, entity_id)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}

	/**
	 * Zapisuje kilka encji naraz dla jednego połączenia — duplikaty (już
	 * zapisana wcześniej encja) są ciche, nie przerywają reszty ani nie
	 * pokazują żadnego błędu użytkownikowi (UNIQUE KEY na
	 * connection_id+entity_id, INSERT IGNORE).
	 *
	 * @param string                                        $connection_id ID połączenia.
	 * @param array<int, array{id: string, label?: string}> $entities      Encje do zapisania (co najmniej klucz 'id').
	 */
	public function save_many( string $connection_id, array $entities ): void {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		foreach ( $entities as $entity ) {
			$entity_id = (string) ( $entity['id'] ?? '' );

			if ( '' === $entity_id ) {
				continue;
			}

			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query(
				$wpdb->prepare(
					"INSERT IGNORE INTO {$table} (connection_id, entity_id, friendly_name, added_at) VALUES (%s, %s, %s, %s)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$connection_id,
					$entity_id,
					(string) ( $entity['label'] ?? '' ),
					current_time( 'mysql' )
				)
			);
		}
	}

	/**
	 * Zwraca zapisane encje dla połączenia, najnowsze pierwsze — do szybkiego
	 * wyboru w edytorze bloku (Tydzień 6), bez requestu do źródła danych.
	 *
	 * @param string $connection_id ID połączenia.
	 * @return array<int, array{id: string, label: string}>
	 */
	public function get_for_connection( string $connection_id ): array {
		global $wpdb;

		$table = $wpdb->prefix . self::TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT entity_id, friendly_name FROM {$table} WHERE connection_id = %s ORDER BY added_at DESC",
				$connection_id
			),
			ARRAY_A
		);
		// phpcs:enable

		if ( ! is_array( $rows ) ) {
			return array();
		}

		return array_map(
			function ( $row ) {
				return array(
					'id'    => $row['entity_id'],
					'label' => '' !== $row['friendly_name'] ? $row['friendly_name'] : $row['entity_id'],
				);
			},
			$rows
		);
	}
}
