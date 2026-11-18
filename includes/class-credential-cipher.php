<?php
/**
 * Szyfrowanie poświadczeń w spoczynku (docs/archiwum/iot-data-widget-poswiadczenia.md,
 * uzupełnienie do Fazy 2 planu freemium) — token Home Assistant, hasło MQTT,
 * klucz Shelly Cloud i link bezpośredni Supli (patrz
 * IOTDW_Connector_Manager::secret_fields_for()) leżały dotąd w
 * `wp_options` w postaci jawnej.
 *
 * Klucz pochodzi z `wp_salt('auth')` — ten sam mechanizm co hashowanie
 * tokenów push (IOTDW_Push_Channel_Manager::hash_token()). To chroni
 * przed wyciekiem SAMEJ bazy (kopia zapasowa, zrzut SQL, SQL injection w
 * INNEJ wtyczce na tej samej instalacji, klon na staging) — NIE chroni
 * przed pełnym przejęciem serwera, bo klucz leży w `wp-config.php` na tym
 * samym serwerze co baza. Uczciwie powiedziane w readme.txt, nie tylko tu.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Credential_Cipher
 */
class IOTDW_Credential_Cipher {

	/**
	 * Metoda szyfrowania OpenSSL. CBC, nie GCM — GCM wymaga PHP 7.1+ i tak
	 * jest dostępny (Requires PHP 8.1), ale CBC z osobnym, losowym IV per
	 * wartość daje ten sam realny poziom ochrony dla tego zagrożenia
	 * (wyciek bazy, nie aktywny atakujący modyfikujący ciphertext w locie)
	 * przy prostszej implementacji — brak tagu uwierzytelniającego do
	 * zarządzania.
	 */
	const CIPHER_METHOD = 'aes-256-cbc';

	/**
	 * Prefiks oznaczający wartość już zaszyfrowaną tym mechanizmem —
	 * pozwala odróżnić ją od starego, jawnego tekstu (migracja, patrz
	 * IOTDW_Connector_Manager::migrate_plaintext_credentials()) bez
	 * dodatkowej flagi w schemacie. "v1" na wypadek przyszłej zmiany
	 * algorytmu — decrypt() rozpozna starszy prefiks i wie, jak go obsłużyć,
	 * zamiast zgadywać po kształcie danych.
	 */
	const PREFIX = 'enc:v1:';

	/**
	 * Szyfruje wartość. Pusty string zostaje pusty (nic do ochrony, a
	 * szyfrowanie pustego stringa i tak dawałoby niepustą wartość — myliłoby
	 * "brak tokenu" z "token, którego nie znamy" przy odczycie).
	 *
	 * @param string $plaintext Wartość jawna.
	 * @return string Zaszyfrowana wartość z prefiksem, albo pusty string.
	 */
	public static function encrypt( string $plaintext ): string {
		if ( '' === $plaintext ) {
			return '';
		}

		$iv        = openssl_random_pseudo_bytes( openssl_cipher_iv_length( self::CIPHER_METHOD ) );
		$encrypted = openssl_encrypt( $plaintext, self::CIPHER_METHOD, self::derive_key(), OPENSSL_RAW_DATA, $iv );

		if ( false === $encrypted ) {
			// Nie powinno się zdarzyć z poprawnym kluczem/IV o właściwej
			// długości — ale lepiej zostawić wartość jawną niż rzucić
			// wyjątkiem, który zablokowałby zapis całego połączenia.
			return $plaintext;
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binarny IV/szyfrogram zapisywany jako tekst w opcji, nie obfuskacja kodu.
		return self::PREFIX . base64_encode( $iv ) . ':' . base64_encode( $encrypted );
	}

	/**
	 * Deszyfruje wartość. Wartość BEZ prefiksu jest zwracana bez zmian —
	 * to jest ta "przezroczysta migracja": stary, jawny tekst po prostu
	 * przechodzi przez tę funkcję niezmieniony (nie ma czego odszyfrować),
	 * więc odczyt połączeń zapisanych przed wdrożeniem tego mechanizmu
	 * działa identycznie jak wcześniej, bez osobnego kroku migracji
	 * wymaganego PRZED pierwszym odczytem.
	 *
	 * @param string $value Wartość z bazy (zaszyfrowana albo jawna).
	 * @return string Wartość jawna.
	 */
	public static function decrypt( string $value ): string {
		if ( ! self::is_encrypted( $value ) ) {
			return $value;
		}

		$parts = explode( ':', substr( $value, strlen( self::PREFIX ) ), 2 );

		if ( 2 !== count( $parts ) ) {
			return '';
		}

		list( $iv_b64, $ciphertext_b64 ) = $parts;
		// phpcs:disable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- odczyt binarnego IV/szyfrogramu zapisanego przez encrypt().
		$iv         = base64_decode( $iv_b64, true );
		$ciphertext = base64_decode( $ciphertext_b64, true );
		// phpcs:enable WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode

		if ( false === $iv || false === $ciphertext ) {
			return '';
		}

		$decrypted = openssl_decrypt( $ciphertext, self::CIPHER_METHOD, self::derive_key(), OPENSSL_RAW_DATA, $iv );

		return false !== $decrypted ? $decrypted : '';
	}

	/**
	 * Czy wartość jest zaszyfrowana przez encrypt() (ma prefiks szyfru).
	 *
	 * @param string $value Wartość z bazy.
	 * @return bool
	 */
	public static function is_encrypted( string $value ): bool {
		return 0 === strpos( $value, self::PREFIX );
	}

	/**
	 * Klucz AES-256 (32 surowych bajtów) pochodny od soli WP — ten sam
	 * pierwotny materiał co reszta kryptografii wtyczki (push token hash),
	 * ale osobno zahaszowany ("iotdw_credential_cipher" jako kontekst), żeby
	 * kompromitacja jednego zastosowania `wp_salt('auth')` w tym pliku nie
	 * dawała wprost klucza do drugiego.
	 *
	 * @return string 32 surowe bajty.
	 */
	private static function derive_key(): string {
		return hash_hmac( 'sha256', 'iotdw_credential_cipher', wp_salt( 'auth' ), true );
	}
}
