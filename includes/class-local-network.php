<?php
/**
 * Ustawienie "Allow local network addresses" (1.0.2, ochrona SSRF —
 * patrz IOTDW_Http_Client): migracja istniejących instalacji, jednorazowe
 * powiadomienie i włączanie z kreatora.
 *
 * Decyzja właściciela (2026-10-06): adresy lokalne domyślnie blokowane;
 * instalacja sprzed 1.0.2, która ma już połączenie (albo webhook alertu)
 * na adres lokalny, dostaje ustawienie włączone automatycznie — nic nie
 * przestaje działać po aktualizacji — plus jednorazowe powiadomienie na
 * stronach wtyczki.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Local_Network
 */
class IOTDW_Local_Network {

	/**
	 * Klucz flagi powiadomienia w iotdw_settings (ta sama opcja co samo
	 * ustawienie — usuwana przy odinstalowaniu razem z nią).
	 */
	const NOTICE_KEY = 'local_network_notice';

	/**
	 * Akcja admin-post: włączenie z kreatora.
	 */
	const ENABLE_ACTION = 'iotdw_enable_local_network';

	/**
	 * Akcja admin-post: zamknięcie powiadomienia.
	 */
	const DISMISS_ACTION = 'iotdw_dismiss_local_network_notice';

	/**
	 * Konstruktor — hooki panelu.
	 */
	public function __construct() {
		add_action( 'admin_notices', array( $this, 'render_notices' ) );
		add_action( 'admin_post_' . self::ENABLE_ACTION, array( $this, 'handle_enable' ) );
		add_action( 'admin_post_' . self::DISMISS_ACTION, array( $this, 'handle_dismiss' ) );
	}

	/**
	 * Jednorazowa migracja: dopóki ustawienie nie ma żadnej wartości
	 * (instalacja sprzed 1.0.2 albo świeża), ustala ją — włączone, jeśli
	 * którekolwiek połączenie albo webhook alertu wskazuje na adres lokalny.
	 * Na świeżej instalacji (bez połączeń) zostaje wyłączone.
	 *
	 * @param IOTDW_Connector_Manager $connector_manager Menedżer konektorów.
	 */
	public static function maybe_migrate( IOTDW_Connector_Manager $connector_manager ): void {
		$settings = get_option( 'iotdw_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		if ( array_key_exists( IOTDW_Http_Client::SETTING_KEY, $settings ) ) {
			return;
		}

		$has_local = false;

		foreach ( self::configured_urls( $connector_manager ) as $url ) {
			if ( IOTDW_Http_Client::is_local_url( $url ) ) {
				$has_local = true;
				break;
			}
		}

		$settings[ IOTDW_Http_Client::SETTING_KEY ] = $has_local;

		if ( $has_local ) {
			$settings[ self::NOTICE_KEY ] = true;
		}

		update_option( 'iotdw_settings', $settings );
	}

	/**
	 * Adresy, pod które wtyczka wysyła żądania HTTP: połączenia (base_url,
	 * server_uri) i webhooki reguł alertów.
	 *
	 * @param IOTDW_Connector_Manager $connector_manager Menedżer konektorów.
	 * @return string[]
	 */
	private static function configured_urls( IOTDW_Connector_Manager $connector_manager ): array {
		global $wpdb;

		$urls = array();

		foreach ( $connector_manager->get_connections() as $connection ) {
			foreach ( array( 'base_url', 'server_uri' ) as $field ) {
				if ( ! empty( $connection[ $field ] ) && is_string( $connection[ $field ] ) ) {
					$urls[] = $connection[ $field ];
				}
			}
		}

		$table = $wpdb->prefix . IOTDW_Alert_Manager::TABLE;

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table ) {
			$urls = array_merge( $urls, $wpdb->get_col( "SELECT destination FROM {$table} WHERE channel = 'webhook'" ) );
		}
		// phpcs:enable

		return $urls;
	}

	/**
	 * URL opcji kreatora "Same network as my devices" — przez admin-post
	 * z nonce, bo zmienia ustawienie.
	 *
	 * @param string $redirect_to Dokąd wrócić po włączeniu.
	 * @return string
	 */
	public static function enable_url( string $redirect_to ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'      => self::ENABLE_ACTION,
					'redirect_to' => rawurlencode( $redirect_to ),
				),
				admin_url( 'admin-post.php' )
			),
			self::ENABLE_ACTION
		);
	}

	/**
	 * Włącza ustawienie (kreator, "Same network as my devices").
	 */
	public function handle_enable(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( self::ENABLE_ACTION );

		$redirect = isset( $_GET['redirect_to'] ) ? esc_url_raw( wp_unslash( $_GET['redirect_to'] ) ) : admin_url( 'admin.php?page=iot-data-widget' );
		$redirect = wp_validate_redirect( $redirect, admin_url( 'admin.php?page=iot-data-widget' ) );

		if ( IOTDW_Http_Client::allow_local_network() ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! IOTDW_Http_Client::can_manage_local_network() ) {
			wp_safe_redirect( add_query_arg( 'iotdw_local_network', 'denied', $redirect ) );
			exit;
		}

		$settings = get_option( 'iotdw_settings', array() );
		$settings = is_array( $settings ) ? $settings : array();

		$settings[ IOTDW_Http_Client::SETTING_KEY ] = true;
		update_option( 'iotdw_settings', $settings );

		wp_safe_redirect( add_query_arg( 'iotdw_local_network', 'enabled', $redirect ) );
		exit;
	}

	/**
	 * Zamyka powiadomienie po migracji (trwale).
	 */
	public function handle_dismiss(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Insufficient permissions.', 'iot-data-widget' ) );
		}

		check_admin_referer( self::DISMISS_ACTION );

		$settings = get_option( 'iotdw_settings', array() );

		if ( is_array( $settings ) ) {
			unset( $settings[ self::NOTICE_KEY ] );
			update_option( 'iotdw_settings', $settings );
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=iot-data-widget' ) );
		exit;
	}

	/**
	 * Powiadomienia — WYŁĄCZNIE na stronach wtyczki (Guideline 11).
	 */
	public function render_notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( ! current_user_can( 'manage_options' ) || null === $screen || false === strpos( (string) $screen->id, 'iot-data-widget' ) ) {
			return;
		}

		$settings_url = admin_url( 'admin.php?page=iot-data-widget-settings' );
		$state        = isset( $_GET['iotdw_local_network'] ) ? sanitize_key( wp_unslash( $_GET['iotdw_local_network'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tylko wybór komunikatu.

		if ( 'enabled' === $state ) {
			printf(
				'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
				esc_html__( 'Local network addresses are now allowed, so this site can reach devices on your network. You can change this under IoT Data Widget → Settings.', 'iot-data-widget' )
			);
		} elseif ( 'denied' === $state ) {
			printf(
				'<div class="notice notice-warning"><p>%s</p></div>',
				esc_html__( 'Connections to local network addresses are blocked on this site, and only a network administrator can allow them (IoT Data Widget → Settings). Until then, choose push mode instead.', 'iot-data-widget' )
			);
		}

		$settings = get_option( 'iotdw_settings', array() );

		if ( empty( $settings[ self::NOTICE_KEY ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-info"><p>%1$s <a href="%2$s">%3$s</a> · <a href="%4$s">%5$s</a></p></div>',
			esc_html__( 'Security update: IoT Data Widget now refuses connections to local network addresses unless "Allow local network addresses" is turned on. This site already connects to a device on your local network, so the setting was turned on automatically — nothing stopped working.', 'iot-data-widget' ),
			esc_url( $settings_url ),
			esc_html__( 'Review settings', 'iot-data-widget' ),
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=' . self::DISMISS_ACTION ), self::DISMISS_ACTION ) ),
			esc_html__( 'Dismiss', 'iot-data-widget' )
		);
	}
}
