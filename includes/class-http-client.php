<?php
/**
 * Klient HTTP z ochroną SSRF — port Veloxa\Solar\Core\Http_Client
 * (SolarProductionWidget, includes/core/class-http-client.php), patrz
 * SHARED.md tam: "Jeśli IoT kiedykolwiek dostanie własną, sprawdzić czy
 * Solar powinien przejść na ten sam mechanizm".
 *
 * Jak w Solarze: adresy sieci lokalnej (loopback, 10/8, 172.16/12,
 * 192.168/16, 100.64/10, fc00::/7, *.local) są domyślnie BLOKOWANE;
 * odblokowuje je ustawienie "Allow local network addresses" (Settings,
 * manage_options; na multisite manage_network_options — patrz
 * can_manage_local_network()). Instalacje sprzed 1.0.2 z połączeniem na
 * adres lokalny dostają je włączone automatycznie (IOTDW_Local_Network::maybe_migrate(),
 * decyzja właściciela 2026-10-06), kreator włącza je przy wyborze "Same
 * network as my devices".
 *
 * Zawsze blokowane (bez wyjątku): link-local 169.254.0.0/16 i fe80::/10
 * (usługi metadanych chmury, np. 169.254.169.254) oraz 0.0.0.0/8. Każde
 * przekierowanie jest sprawdzane tak samo jak pierwszy URL, a schemat
 * musi być http/https.
 *
 * Ograniczenie (jak w Solarze): host jest rozwiązywany raz, przed
 * wp_remote_*(), które rozwiązuje go ponownie — wąskie okno na DNS
 * rebinding, świadomie zaakceptowane.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Http_Client
 */
class IOTDW_Http_Client {

	/**
	 * Kod WP_Error dla zablokowanego żądania.
	 */
	const BLOCKED_CODE = 'iotdw_ssrf_blocked';

	/**
	 * Maksymalna liczba przekierowań śledzonych przez get().
	 */
	const MAX_REDIRECTS = 3;

	/**
	 * Kody HTTP przekierowań.
	 */
	const REDIRECT_CODES = array( 301, 302, 303, 307, 308 );

	/**
	 * Domyślny timeout, gdy wywołujący go nie poda.
	 */
	const DEFAULT_TIMEOUT = 3;

	/**
	 * Klucz w opcji IOTDW_Settings_Page::OPTION_NAME.
	 */
	const SETTING_KEY = 'allow_local_network';

	/**
	 * GET z ochroną SSRF i ręcznym śledzeniem przekierowań (wp_remote_get()
	 * śledziłoby je samo, bez sprawdzenia, dokąd prowadzą).
	 *
	 * @param string $url  Pełny URL.
	 * @param array  $args Argumenty wp_remote_get().
	 * @return array|WP_Error
	 */
	public static function get( string $url, array $args = array() ) {
		$args['timeout']     = $args['timeout'] ?? self::DEFAULT_TIMEOUT;
		$args['redirection'] = 0;

		for ( $hop = 0; $hop <= self::MAX_REDIRECTS; $hop++ ) {
			$blocked = self::check_blocked( $url );

			if ( null !== $blocked ) {
				return $blocked;
			}

			$response = wp_remote_get( $url, $args );

			if ( is_wp_error( $response ) || ! in_array( (int) wp_remote_retrieve_response_code( $response ), self::REDIRECT_CODES, true ) ) {
				return $response;
			}

			$location = wp_remote_retrieve_header( $response, 'location' );
			$location = is_array( $location ) ? (string) end( $location ) : (string) $location;

			if ( '' === $location ) {
				return $response;
			}

			$url = WP_Http::make_absolute_url( $location, $url );
		}

		return new WP_Error( 'iotdw_too_many_redirects', __( 'The server redirected too many times.', 'iot-data-widget' ) );
	}

	/**
	 * POST z tą samą kontrolą. Przekierowania nie są śledzone (3xx wraca do
	 * wywołującego) — ponowne wysłanie treści pod inny adres nie jest potrzebne.
	 *
	 * @param string $url  Pełny URL.
	 * @param array  $args Argumenty wp_remote_post().
	 * @return array|WP_Error
	 */
	public static function post( string $url, array $args = array() ) {
		$blocked = self::check_blocked( $url );

		if ( null !== $blocked ) {
			return $blocked;
		}

		$args['timeout']     = $args['timeout'] ?? self::DEFAULT_TIMEOUT;
		$args['redirection'] = 0;

		return wp_remote_post( $url, $args );
	}

	/**
	 * Null, gdy wolno odpytać $url, WP_Error w przeciwnym razie.
	 *
	 * @param string $url Pełny URL.
	 * @return WP_Error|null
	 */
	public static function check_blocked( string $url ): ?WP_Error {
		$host   = (string) wp_parse_url( $url, PHP_URL_HOST );
		$scheme = strtolower( (string) wp_parse_url( $url, PHP_URL_SCHEME ) );

		if ( '' === $host || ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
			return new WP_Error( 'iotdw_invalid_url', __( 'The configured URL has no valid host (only http:// and https:// addresses are supported).', 'iot-data-widget' ) );
		}

		$allow_local = self::allow_local_network( $host );

		if ( self::is_local_hostname( $host ) ) {
			// Nazwy .local/localhost i tak nie rozwiązują się przez zwykły DNS.
			return $allow_local ? null : self::local_blocked_error( $host );
		}

		foreach ( self::resolve_to_ips( $host ) as $ip ) {
			$ip = self::unmap_ipv4( $ip );

			if ( self::is_always_blocked_ip( $ip ) ) {
				return new WP_Error(
					self::BLOCKED_CODE,
					sprintf(
						/* translators: %s: the host that was blocked */
						__( 'Refusing to connect to "%s" — it resolves to a link-local or reserved address (such as a cloud metadata service). This is always blocked.', 'iot-data-widget' ),
						$host
					)
				);
			}

			if ( self::is_local_ip( $ip ) ) {
				if ( ! $allow_local ) {
					return self::local_blocked_error( $host );
				}
				continue;
			}

			// Pozostałe zakresy zarezerwowane (np. 240.0.0.0/4) — nie są ani
			// publiczne, ani siecią domową.
			if ( false === filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error(
					self::BLOCKED_CODE,
					sprintf(
						/* translators: %s: the host that was blocked */
						__( 'Refusing to connect to "%s" — it resolves to a link-local or reserved address (such as a cloud metadata service). This is always blocked.', 'iot-data-widget' ),
						$host
					)
				);
			}
		}

		return null;
	}

	/**
	 * Czy wolno łączyć się z adresami sieci lokalnej: ustawienie "Allow
	 * local network addresses" (domyślnie wyłączone), z filtrem do
	 * nadpisania w kodzie.
	 *
	 * @param string $host Host żądania.
	 * @return bool
	 */
	public static function allow_local_network( string $host = '' ): bool {
		$settings = get_option( 'iotdw_settings', array() );
		$enabled  = is_array( $settings ) && ! empty( $settings[ self::SETTING_KEY ] );

		/**
		 * Pozwala łączyć się z adresami lokalnymi/prywatnymi (loopback, sieci
		 * prywatne, .local). Link-local i 0.0.0.0/8 są blokowane zawsze.
		 *
		 * @param bool   $allow Wartość ustawienia "Allow local network addresses".
		 * @param string $host  Host żądania.
		 */
		return true === apply_filters( 'iotdw_allow_private_hosts', $enabled, $host );
	}

	/**
	 * Kto może zmieniać "Allow local network addresses": manage_options, a
	 * na multisite manage_network_options (administrator pojedynczej witryny
	 * sieci nie powinien sam otwierać dostępu do sieci serwera).
	 *
	 * @return bool
	 */
	public static function can_manage_local_network(): bool {
		return is_multisite() ? current_user_can( 'manage_network_options' ) : current_user_can( 'manage_options' );
	}

	/**
	 * Czy URL wskazuje na adres sieci lokalnej (do migracji 1.0.2 —
	 * niezależnie od bieżącego ustawienia).
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	public static function is_local_url( string $url ): bool {
		$host = (string) wp_parse_url( $url, PHP_URL_HOST );

		if ( '' === $host ) {
			return false;
		}

		if ( self::is_local_hostname( $host ) ) {
			return true;
		}

		foreach ( self::resolve_to_ips( $host ) as $ip ) {
			if ( self::is_local_ip( self::unmap_ipv4( $ip ) ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Błąd dla adresu lokalnego przy wyłączonym dostępie (filtr).
	 *
	 * @param string $host Host.
	 * @return WP_Error
	 */
	private static function local_blocked_error( string $host ): WP_Error {
		return new WP_Error(
			self::BLOCKED_CODE,
			sprintf(
				/* translators: %s: the host that was blocked */
				__( 'Refusing to connect to "%s" — it is a local network address. If WordPress runs on the same network as the device, enable "Allow local network addresses" under IoT Data Widget → Settings. Otherwise, use a push channel instead.', 'iot-data-widget' ),
				$host
			)
		);
	}

	/**
	 * Lokalna z samej nazwy: localhost, *.localhost, *.local (mDNS).
	 *
	 * @param string $host Host.
	 * @return bool
	 */
	private static function is_local_hostname( string $host ): bool {
		$host = strtolower( rtrim( $host, '.' ) );

		return 'localhost' === $host || str_ends_with( $host, '.local' ) || str_ends_with( $host, '.localhost' );
	}

	/**
	 * Adresy IP hosta (goły adres IP wraca bez odpytywania DNS).
	 *
	 * @param string $host Host (bez schematu/portu).
	 * @return string[]
	 */
	private static function resolve_to_ips( string $host ): array {
		// wp_parse_url() zwraca literał IPv6 w nawiasach ("[::1]").
		$host = trim( $host, '[]' );

		if ( false !== filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return array( $host );
		}

		/**
		 * Pozwala podać adresy IP hosta bez zapytania DNS (testy). Null =
		 * zwykłe rozwiązywanie.
		 *
		 * @param string[]|null $ips  Adresy albo null.
		 * @param string        $host Host.
		 */
		$pre = apply_filters( 'iotdw_pre_resolve_host', null, $host );

		if ( is_array( $pre ) ) {
			return $pre;
		}

		$ips = array();

		// A + AAAA — gethostbyname() widzi tylko IPv4.
		$records = @dns_get_record( $host, DNS_A + DNS_AAAA ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- brak rekordów/błąd DNS = pusta lista, żądanie i tak zawiedzie naturalnie.

		if ( is_array( $records ) ) {
			foreach ( $records as $record ) {
				if ( ! empty( $record['ip'] ) ) {
					$ips[] = $record['ip'];
				} elseif ( ! empty( $record['ipv6'] ) ) {
					$ips[] = $record['ipv6'];
				}
			}
		}

		return $ips;
	}

	/**
	 * IPv4-mapped IPv6 (::ffff:127.0.0.1) → osadzony IPv4.
	 *
	 * @param string $ip Adres IP.
	 * @return string
	 */
	private static function unmap_ipv4( string $ip ): string {
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- niepoprawny adres = false.

		if ( false !== $packed && 16 === strlen( $packed ) && str_repeat( "\0", 10 ) . "\xff\xff" === substr( $packed, 0, 12 ) ) {
			return (string) inet_ntop( substr( $packed, 12 ) );
		}

		return $ip;
	}

	/**
	 * 169.254.0.0/16, fe80::/10, 0.0.0.0/8 — blokowane zawsze.
	 *
	 * @param string $ip Adres IP.
	 * @return bool
	 */
	private static function is_always_blocked_ip( string $ip ): bool {
		return self::in_cidr( $ip, '169.254.0.0', 16 ) || self::in_cidr( $ip, '0.0.0.0', 8 ) || self::in_cidr( $ip, 'fe80::', 10 );
	}

	/**
	 * Loopback, sieci prywatne, CGNAT 100.64/10, fc00::/7.
	 *
	 * @param string $ip Adres IP.
	 * @return bool
	 */
	private static function is_local_ip( string $ip ): bool {
		foreach ( array( array( '127.0.0.0', 8 ), array( '10.0.0.0', 8 ), array( '172.16.0.0', 12 ), array( '192.168.0.0', 16 ), array( '100.64.0.0', 10 ), array( '::1', 128 ), array( 'fc00::', 7 ) ) as $range ) {
			if ( self::in_cidr( $ip, $range[0], $range[1] ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Czy $ip należy do $subnet/$bits (ta sama rodzina adresów).
	 *
	 * @param string $ip     Adres IP.
	 * @param string $subnet Adres sieci.
	 * @param int    $bits   Długość prefiksu.
	 * @return bool
	 */
	private static function in_cidr( string $ip, string $subnet, int $bits ): bool {
		$ip_bin     = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- niepoprawny adres = false.
		$subnet_bin = @inet_pton( $subnet ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- stała poprawna.

		if ( false === $ip_bin || false === $subnet_bin || strlen( $ip_bin ) !== strlen( $subnet_bin ) ) {
			return false;
		}

		$bytes = intdiv( $bits, 8 );

		if ( substr( $ip_bin, 0, $bytes ) !== substr( $subnet_bin, 0, $bytes ) ) {
			return false;
		}

		$rest = $bits % 8;

		if ( 0 === $rest ) {
			return true;
		}

		$mask = ( 0xFF << ( 8 - $rest ) ) & 0xFF;

		return ( ord( $ip_bin[ $bytes ] ) & $mask ) === ( ord( $subnet_bin[ $bytes ] ) & $mask );
	}
}
