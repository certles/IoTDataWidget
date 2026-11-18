<?php
/**
 * Prośba o opinię na WordPress.org — tylko dla aktywnych użytkowników
 * (wytyczne wp.org: bez zachęt, bez sugerowania oceny, łatwe zamknięcie).
 *
 * Pokazuje się, gdy jednocześnie: administrator (manage_options), minęło
 * co najmniej 14 dni od pierwszej aktywacji, co najmniej jedno połączenie
 * ma udany odczyt (albo kanał push odebrał dane) z ostatnich 24 h, bieżący ekran to
 * strona wtyczki (poza kreatorem), a użytkownik nie zamknął komunikatu ani go nie odłożył.
 * Stan zamknięcia jest per administrator (user meta). Działa tak samo
 * w wersji darmowej i Pro.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class Review_Notice
 */
class IOTDW_Review_Notice {

	/**
	 * Czas pierwszej aktywacji (unix, UTC).
	 */
	const ACTIVATED_OPTION = 'iotdw_activated_at';

	/**
	 * Stan komunikatu per administrator: array{status: string, until: int}.
	 */
	const META_KEY = 'iotdw_review_notice';

	/**
	 * Akcja admin-ajax i admin-post oraz nazwa nonce.
	 */
	const ACTION = 'iotdw_review_notice';

	/**
	 * Po ilu sekundach od aktywacji komunikat może się pojawić.
	 */
	const DELAY = 14 * DAY_IN_SECONDS;

	/**
	 * Na ile sekund „Maybe later” odkłada komunikat.
	 */
	const SNOOZE = 30 * DAY_IN_SECONDS;

	/**
	 * Okno, w którym musi być udany odczyt (sekundy).
	 */
	const ACTIVITY_WINDOW = DAY_IN_SECONDS;

	/**
	 * Slug wtyczki na WordPress.org.
	 */
	const WPORG_SLUG = 'iot-data-widget';

	/**
	 * Akcje przycisków => stan po kliknięciu.
	 */
	const ACTIONS = array(
		'review' => 'dismissed',
		'done'   => 'dismissed',
		'later'  => 'snoozed',
	);

	/**
	 * Menedżer połączeń.
	 *
	 * @var IOTDW_Connector_Manager
	 */
	private $connector_manager;

	/**
	 * Menedżer kanałów push.
	 *
	 * @var IOTDW_Push_Channel_Manager
	 */
	private $push_channel_manager;

	/**
	 * Konstruktor.
	 *
	 * @param IOTDW_Connector_Manager    $connector_manager    Menedżer połączeń.
	 * @param IOTDW_Push_Channel_Manager $push_channel_manager Menedżer kanałów push.
	 */
	public function __construct( IOTDW_Connector_Manager $connector_manager, IOTDW_Push_Channel_Manager $push_channel_manager ) {
		$this->connector_manager    = $connector_manager;
		$this->push_channel_manager = $push_channel_manager;

		add_action( 'admin_init', array( __CLASS__, 'ensure_activation_time' ) );
		add_action( 'admin_notices', array( $this, 'render' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_' . self::ACTION, array( $this, 'handle_ajax' ) );
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_get' ) );
	}

	/**
	 * Zapisuje czas pierwszej aktywacji, jeśli go jeszcze nie ma. Wołane przy
	 * aktywacji i na admin_init — instalacja zaktualizowana z wersji bez tej
	 * opcji dostaje „teraz”, więc komunikat nie pojawia się od razu.
	 */
	public static function ensure_activation_time(): void {
		if ( false === get_option( self::ACTIVATED_OPTION ) ) {
			add_option( self::ACTIVATED_OPTION, time(), '', false );
		}
	}

	/**
	 * Czy ekran należy do wtyczki — strona główna albo podstrona pod
	 * menu „iot-data-widget”, bez kreatora konfiguracji.
	 *
	 * @param string $screen_id ID ekranu (WP_Screen::$id).
	 * @return bool
	 */
	public static function is_plugin_screen( string $screen_id ): bool {
		if ( 'toplevel_page_iot-data-widget' === $screen_id ) {
			return true;
		}

		if ( false !== strpos( $screen_id, '_page_' . IOTDW_Setup_Wizard::PAGE_SLUG ) ) {
			return false;
		}

		return 1 === preg_match( '/_page_iot-data-widget(-[a-z-]+)?$/', $screen_id );
	}

	/**
	 * Czy co najmniej jedno połączenie ma udany odczyt albo kanał push
	 * odebrał dane w ciągu ACTIVITY_WINDOW.
	 *
	 * @param int $now Bieżący czas (unix).
	 * @return bool
	 */
	public function has_recent_success( int $now ): bool {
		$since = $now - self::ACTIVITY_WINDOW;

		foreach ( IOTDW_Diagnostics_Logger::get_all_connection_statuses() as $status ) {
			if ( 'ok' === $status['last_status'] && self::utc_timestamp( (string) $status['last_checked_at'] ) >= $since ) {
				return true;
			}
		}

		foreach ( $this->connector_manager->get_connections() as $connection ) {
			$received_at = $this->push_channel_manager->get_last_received_at( (string) ( $connection['id'] ?? '' ) );

			if ( null !== $received_at && self::utc_timestamp( $received_at ) >= $since ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Data MySQL w UTC → unix.
	 *
	 * @param string $mysql_date Data 'Y-m-d H:i:s' (UTC).
	 * @return int 0 dla niepoprawnej daty.
	 */
	private static function utc_timestamp( string $mysql_date ): int {
		$timestamp = strtotime( $mysql_date . ' UTC' );

		return false === $timestamp ? 0 : $timestamp;
	}

	/**
	 * Czy pokazać komunikat.
	 *
	 * @param int    $user_id   ID użytkownika.
	 * @param string $screen_id ID bieżącego ekranu.
	 * @param int    $now       Bieżący czas (unix).
	 * @return bool
	 */
	public function should_show( int $user_id, string $screen_id, int $now ): bool {
		if ( ! user_can( $user_id, 'manage_options' ) || ! self::is_plugin_screen( $screen_id ) ) {
			return false;
		}

		$activated_at = (int) get_option( self::ACTIVATED_OPTION, 0 );

		if ( $activated_at <= 0 || $now - $activated_at < self::DELAY ) {
			return false;
		}

		$state = self::get_state( $user_id );

		if ( 'dismissed' === $state['status'] || ( 'snoozed' === $state['status'] && $now < $state['until'] ) ) {
			return false;
		}

		return $this->has_recent_success( $now );
	}

	/**
	 * Stan komunikatu użytkownika.
	 *
	 * @param int $user_id ID użytkownika.
	 * @return array{status: string, until: int}
	 */
	public static function get_state( int $user_id ): array {
		$state = get_user_meta( $user_id, self::META_KEY, true );

		return array(
			'status' => is_array( $state ) && isset( $state['status'] ) ? (string) $state['status'] : '',
			'until'  => is_array( $state ) && isset( $state['until'] ) ? (int) $state['until'] : 0,
		);
	}

	/**
	 * Zapisuje wybór użytkownika.
	 *
	 * @param int    $user_id ID użytkownika.
	 * @param string $action  review|done|later.
	 * @param int    $now     Bieżący czas (unix).
	 * @return bool False dla nieznanej akcji.
	 */
	public static function apply_action( int $user_id, string $action, int $now ): bool {
		if ( ! isset( self::ACTIONS[ $action ] ) ) {
			return false;
		}

		$status = self::ACTIONS[ $action ];

		update_user_meta(
			$user_id,
			self::META_KEY,
			array(
				'status' => $status,
				'until'  => 'snoozed' === $status ? $now + self::SNOOZE : 0,
			)
		);

		return true;
	}

	/**
	 * Sprawdza nonce i uprawnienia, potem zapisuje akcję.
	 *
	 * @param string $action Akcja z żądania (już po sanitize_key).
	 * @param string $nonce  Nonce z żądania.
	 * @return int Kod HTTP: 200, 400 (zła akcja) albo 403.
	 */
	public static function process( string $action, string $nonce ): int {
		if ( ! wp_verify_nonce( $nonce, self::ACTION ) || ! current_user_can( 'manage_options' ) ) {
			return 403;
		}

		return self::apply_action( get_current_user_id(), $action, time() ) ? 200 : 400;
	}

	/**
	 * Akcja z przycisku przez admin-ajax (z JS).
	 */
	public function handle_ajax(): void {
		if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['nonce'] ) ), self::ACTION ) ) {
			wp_send_json_error( null, 403 );
		}

		$code = self::process(
			isset( $_POST['notice_action'] ) ? sanitize_key( wp_unslash( $_POST['notice_action'] ) ) : '',
			sanitize_text_field( wp_unslash( $_POST['nonce'] ) )
		);

		if ( 200 === $code ) {
			wp_send_json_success();
		}

		wp_send_json_error( null, $code );
	}

	/**
	 * Akcja z linku bez JS (admin-post, GET z nonce). „Leave a review”
	 * przekierowuje na WordPress.org, pozostałe wracają na ekran wtyczki.
	 */
	public function handle_get(): void {
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), self::ACTION ) ) {
			wp_die( esc_html__( 'The link has expired. Go back and try again.', 'iot-data-widget' ), '', array( 'response' => 403 ) );
		}

		$action = isset( $_GET['notice_action'] ) ? sanitize_key( wp_unslash( $_GET['notice_action'] ) ) : '';
		$code   = self::process( $action, sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) );

		if ( 200 !== $code ) {
			wp_die( esc_html__( 'The link has expired. Go back and try again.', 'iot-data-widget' ), '', array( 'response' => (int) $code ) );
		}

		if ( 'review' === $action ) {
			add_filter( 'allowed_redirect_hosts', array( __CLASS__, 'allow_wporg_redirect' ) );
			wp_safe_redirect( self::review_url() );
			exit;
		}

		wp_safe_redirect( wp_get_referer() ? wp_get_referer() : admin_url( 'admin.php?page=iot-data-widget' ) );
		exit;
	}

	/**
	 * Dopuszcza przekierowanie na wordpress.org (tylko formularz opinii).
	 *
	 * @param string[] $hosts Dozwolone hosty.
	 * @return string[]
	 */
	public static function allow_wporg_redirect( $hosts ): array {
		$hosts   = (array) $hosts;
		$hosts[] = 'wordpress.org';

		return $hosts;
	}

	/**
	 * Adres formularza opinii na WordPress.org.
	 *
	 * @return string
	 */
	public static function review_url(): string {
		return 'https://wordpress.org/support/plugin/' . self::WPORG_SLUG . '/reviews/#new-post';
	}

	/**
	 * Adres forum pomocy na WordPress.org.
	 *
	 * @return string
	 */
	public static function support_url(): string {
		return 'https://wordpress.org/support/plugin/' . self::WPORG_SLUG . '/';
	}

	/**
	 * Adres akcji bez JS.
	 *
	 * @param string $action review|done|later.
	 * @return string
	 */
	private static function action_url( string $action ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'        => self::ACTION,
					'notice_action' => $action,
				),
				admin_url( 'admin-post.php' )
			),
			self::ACTION
		);
	}

	/**
	 * Czy pokazać komunikat bieżącemu użytkownikowi na bieżącym ekranie.
	 *
	 * @return bool
	 */
	private function should_show_now(): bool {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		return null !== $screen && $this->should_show( get_current_user_id(), (string) $screen->id, time() );
	}

	/**
	 * Mały skrypt: przyciski i krzyżyk bez przeładowania strony.
	 */
	public function enqueue_assets(): void {
		if ( ! $this->should_show_now() ) {
			return;
		}

		wp_enqueue_script( 'iot-data-widget-review-notice', IOTDW_PLUGIN_URL . 'admin/js/review-notice.js', array(), IOTDW_VERSION, true );
	}

	/**
	 * Wypisuje komunikat.
	 */
	public function render(): void {
		if ( ! $this->should_show_now() ) {
			return;
		}

		echo wp_kses( $this->html(), self::allowed_html() );
	}

	/**
	 * Tagi i atrybuty dozwolone w komunikacie (wp_kses na wyjściu).
	 *
	 * @return array<string, array<string, bool>>
	 */
	public static function allowed_html(): array {
		return array(
			'div' => array(
				'class'         => true,
				'data-nonce'    => true,
				'data-ajax-url' => true,
			),
			'p'   => array(),
			'a'   => array(
				'class'              => true,
				'href'               => true,
				'target'             => true,
				'rel'                => true,
				'data-review-action' => true,
			),
		);
	}

	/**
	 * HTML komunikatu.
	 *
	 * @return string
	 */
	public function html(): string {
		$message = sprintf(
			/* translators: %s: plugin name. */
			__( 'Is %s working well for you? A short review on WordPress.org helps other site owners find it — and helps me, a solo developer, keep improving it.', 'iot-data-widget' ),
			__( 'IoT Data Widget', 'iot-data-widget' )
		);

		return sprintf(
			'<div class="notice notice-info is-dismissible iotdw-review-notice" data-nonce="%1$s" data-ajax-url="%2$s"><p>%3$s</p><p><a class="button button-primary" href="%4$s" target="_blank" rel="noopener noreferrer" data-review-action="review">%5$s</a> <a class="button" href="%6$s" data-review-action="later">%7$s</a> <a class="button-link" href="%8$s" data-review-action="done">%9$s</a></p><p><a href="%10$s" target="_blank" rel="noopener noreferrer">%11$s</a></p></div>',
			esc_attr( wp_create_nonce( self::ACTION ) ),
			esc_url( admin_url( 'admin-ajax.php' ) ),
			esc_html( $message ),
			esc_url( self::action_url( 'review' ) ),
			esc_html__( 'Leave a review', 'iot-data-widget' ),
			esc_url( self::action_url( 'later' ) ),
			esc_html__( 'Maybe later', 'iot-data-widget' ),
			esc_url( self::action_url( 'done' ) ),
			esc_html__( 'I already did', 'iot-data-widget' ),
			esc_url( self::support_url() ),
			esc_html__( 'Something not working? Ask on the support forum', 'iot-data-widget' )
		);
	}
}
