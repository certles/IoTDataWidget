<?php
/**
 * Widok: Krok 0 kreatora (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md) — pytanie
 * o lokalizację WordPressa, przed wyborem typu połączenia. Push zalecany
 * jako wybór domyślny/bezpieczny — większość hostingu współdzielonego
 * (typowy przypadek u klientów tej wtyczki) nigdy nie osiągnie prywatnego
 * adresu IP urządzenia w cudzej sieci domowej.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$iotdw_base_url = admin_url( 'admin.php?page=' . IOTDW_Setup_Wizard::PAGE_SLUG );
$iotdw_skip_url = admin_url( 'admin.php?page=iot-data-widget' );
?>
<div class="wrap iot-dw-wizard">
	<h1><?php esc_html_e( 'IoT Data Widget — Setup', 'iot-data-widget' ); ?></h1>
	<h2><?php esc_html_e( 'Where does your WordPress site run?', 'iot-data-widget' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'This determines how your device and WordPress can reach each other — most setup problems and support requests come from getting this backwards.', 'iot-data-widget' ); ?>
	</p>

	<p class="iot-dw-wizard__options">
		<a class="button button-hero button-primary" href="<?php echo esc_url( add_query_arg( 'mode', 'push', $iotdw_base_url ) ); ?>">
			<?php esc_html_e( 'On external/shared hosting (typical) — my device sends data to my site', 'iot-data-widget' ); ?>
		</a>
		<?php // 1.0.2: ta opcja włącza też "Allow local network addresses" (IOTDW_Local_Network::handle_enable()). ?>
		<a class="button button-hero" href="<?php echo esc_url( IOTDW_Local_Network::enable_url( add_query_arg( 'mode', 'pull', $iotdw_base_url ) ) ); ?>">
			<?php esc_html_e( 'Same network as my devices (Raspberry Pi, NAS, home server) — my site fetches data from the device', 'iot-data-widget' ); ?>
		</a>
		<?php
		/**
		 * Poprawka 2026-08-28 — trzeci, honest przypadek: hosting zewnętrzny,
		 * ALE urządzenie jest osiągalne przez WŁASNĄ chmurę producenta (Supla,
		 * Shelly Cloud) albo publicznie wystawioną instancję Home Assistant —
		 * WordPress łączy się WYCHODZĄCO do tej chmury, nie do prywatnego
		 * IP urządzenia, więc to wciąż "pull" (ta sama ścieżka co druga
		 * opcja, mode=pull — Krok 1 i tak pokazuje pełną listę typów,
		 * łącznie z grupą "Cloud"). Wcześniej taki użytkownik musiał wybrać
		 * opcję "ta sama sieć", mimo że to nieprawda — zgłoszone przy okazji
		 * dodania presetu Supla.
		 */
		?>
		<a class="button button-hero" href="<?php echo esc_url( add_query_arg( 'mode', 'pull', $iotdw_base_url ) ); ?>">
			<?php
			// Shelly Cloud i preset Supla są Pro — na wersji darmowej oznaczone.
			if ( class_exists( 'IOTDW_Connector_Presets' ) ) {
				esc_html_e( 'On external/shared hosting, but my device has its own cloud service (Shelly Cloud, Supla, a publicly-reachable Home Assistant) — my site fetches data from there', 'iot-data-widget' );
			} else {
				esc_html_e( 'On external/shared hosting, but my device has its own cloud service (a publicly-reachable Home Assistant; Shelly Cloud and Supla in Pro) — my site fetches data from there', 'iot-data-widget' );
			}
			?>
		</a>
	</p>
	<p>
		<a href="<?php echo esc_url( add_query_arg( 'mode', 'push', $iotdw_base_url ) ); ?>">
			<?php esc_html_e( "Not sure? Choose the first option — it's the safer default.", 'iot-data-widget' ); ?>
		</a>
	</p>

	<p class="description">
		<?php esc_html_e( 'Devices behind a home router (NAT) almost never accept incoming connections from the internet — if your WordPress is hosted externally, it typically cannot reach a device address like 192.168.x.x at all. Sending data out from the device instead always works, and so does fetching from a cloud service the device already reports to.', 'iot-data-widget' ); ?>
	</p>
	<?php
	/**
	 * Zgłoszenie 2026-09-07 (docs/archiwum/iot-data-widget-poswiadczenia.md, sekcja 3
	 * — "tryb push jako rozwiązanie architektoniczne"): najmocniejszy
	 * argument za push nigdy nie był tak nazwany wprost. W trybie push
	 * wtyczka w ogóle nie przechowuje poświadczeń do URZĄDZEŃ (token HA,
	 * hasło MQTT) — jedyny "sekret" to token kanału push, który daje dostęp
	 * wyłącznie do zapisu jednej encji w jednym kanale, nie do samego
	 * urządzenia/instancji.
	 */
	?>
	<p class="description">
		<?php esc_html_e( 'Security note: in push mode, this plugin never stores any credentials to your devices — the device sends data out, authenticated by a token this plugin generates that only allows writing one entity to one channel. In pull mode, a copy of your device\'s own credentials (e.g. a Home Assistant access token) is stored in your WordPress database.', 'iot-data-widget' ); ?>
	</p>

	<p>
		<a href="<?php echo esc_url( $iotdw_skip_url ); ?>"><?php esc_html_e( "Skip the wizard, I'll configure manually", 'iot-data-widget' ); ?></a>
	</p>
</div>
