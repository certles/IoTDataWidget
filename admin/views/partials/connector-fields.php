<?php
/**
 * Partial: pola konfiguracji połączenia + "Testuj połączenie" (v1.4
 * Tydzień 4) — wydzielone z connector-form.php, żeby dało się je osadzić
 * bez duplikacji ORAZ w Kroku 2 kreatora (admin/views/wizard/step-2-configure.php),
 * ORAZ na zwykłej stronie dodawania/edycji połączenia
 * (admin/views/connector-form.php). Identyczne ID pól w obu miejscach —
 * bezpieczne, bo żadne dwa widoki tego partiala nie renderują się na tej
 * samej stronie naraz — dzięki temu istniejące admin/js/connector-form.js
 * (przełączanie widoczności pól) i admin/js/test-connection.js (przycisk
 * testu) działają bez zmian niezależnie od tego, gdzie partial jest osadzony.
 *
 * @package IoT_Data_Widget
 *
 * @var array<string, string>|null $editing Wstępne wartości pól (edytowane połączenie ALBO — w kreatorze — tylko connector/preset z wyboru w Kroku 1).
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Guideline 5 (2026-09-25) — wiersze konektorów/presetów Pro żyją w
// includes/premium/admin-views/ i są dołączane tylko, gdy pliki fizycznie
// istnieją; w wersji darmowej formularz nie zawiera ich w ogóle (nawet
// ukrytych). admin/js/connector-form.js jest odporny na brak tych wierszy.
$iotdw_premium_views = IOTDW_PLUGIN_DIR . 'includes/premium/admin-views/';
?>
<table class="form-table">
	<tr>
		<th><label for="iotdw_name"><?php esc_html_e( 'Name', 'iot-data-widget' ); ?></label></th>
		<td>
			<input
				type="text"
				id="iotdw_name"
				name="name"
				class="regular-text"
				required
				value="<?php echo esc_attr( $editing['name'] ?? '' ); ?>"
			/>
		</td>
	</tr>
	<tr>
		<th><label for="iotdw_connector"><?php esc_html_e( 'Connector', 'iot-data-widget' ); ?></label></th>
		<td>
			<select id="iotdw_connector" name="connector">
				<?php foreach ( $connector_types as $iotdw_connector_type => $iotdw_connector_label ) : ?>
					<option value="<?php echo esc_attr( $iotdw_connector_type ); ?>" <?php selected( $editing['connector'] ?? '', $iotdw_connector_type ); ?>>
						<?php echo esc_html( $iotdw_connector_label ); ?>
					</option>
				<?php endforeach; ?>
			</select>
		</td>
	</tr>
	<?php
	if ( file_exists( $iotdw_premium_views . 'connector-fields-connector-rows.php' ) ) {
		require $iotdw_premium_views . 'connector-fields-connector-rows.php';
	}
	?>
	<?php
	// Zgłoszenie 2026-09-07 (docs/archiwum/iot-data-widget-poswiadczenia.md) — dla
	// presetów 'direct_url' (Supla) base_url NIESIE klucz dostępu wprost w
	// URL-u, więc trzeba go traktować jak sekret (nie pokazywać ponownie w
	// polu przy edycji) — ten sam wzorzec co token/auth_key/mqtt_password
	// niżej, nie zwykłe "value z bazy".
	$iotdw_editing_preset = $editing['preset'] ?? '';
	// class_exists() (Guideline 5, 2026-09-24) — presety są Pro; na wersji
	// darmowej ten plik fizycznie nie istnieje.
	$iotdw_editing_preset_def     = ( '' !== $iotdw_editing_preset && class_exists( 'IOTDW_Connector_Presets' ) ) ? IOTDW_Connector_Presets::get( $iotdw_editing_preset ) : null;
	$iotdw_base_url_is_secret     = null !== $iotdw_editing_preset_def && ! empty( $iotdw_editing_preset_def['direct_url'] );
	$iotdw_base_url_display_value = $iotdw_base_url_is_secret ? '' : ( $editing['base_url'] ?? '' );
	?>
	<tr class="iot-dw-field-url-based">
		<th><label for="iotdw_base_url"><?php esc_html_e( 'URL', 'iot-data-widget' ); ?></label></th>
		<td>
			<input
				type="<?php echo esc_attr( $iotdw_base_url_is_secret ? 'password' : 'url' ); ?>"
				id="iotdw_base_url"
				name="base_url"
				class="regular-text"
				placeholder="http://homeassistant.local:8123"
				<?php echo wp_kses( $iotdw_base_url_is_secret ? 'autocomplete="new-password"' : '', array() ); ?>
				value="<?php echo esc_attr( $iotdw_base_url_display_value ); ?>"
			/>
			<p class="description">
				<?php esc_html_e( 'Home Assistant: your instance URL (without /api). Generic REST: full URL of the JSON endpoint.', 'iot-data-widget' ); ?>
			</p>
			<?php if ( $iotdw_base_url_is_secret && $editing && ! empty( $editing['base_url'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Leave empty to keep the current value.', 'iot-data-widget' ); ?></p>
			<?php endif; ?>
			<p class="description iot-dw-admin-status--warning" id="iotdw_base_url_local_warning" style="display:none;"></p>
		</td>
	</tr>
	<?php
	/**
	 * Poprawka 2026-08-28 — osobna klasa od 'iot-dw-field-url-based' (na
	 * którym nadal jest samo pole 'base_url' wyżej): to pole musi być
	 * widoczne TAKŻE w trybie presetu REST (Shelly/Tasmota/ESPHome), nie
	 * tylko w trybie zaawansowanym — wcześniej `.iot-dw-field-url-based`
	 * ukrywało oba pola razem w trybie presetu, więc użytkownik presetu
	 * (np. Tasmoty z ustawionym hasłem WWW) w ogóle nie miał gdzie wpisać
	 * autoryzacji (zgłoszenie: "Użytkownik z zabezpieczonym urządzeniem nie
	 * skonfiguruje wtyczki"). Wyjątek: presety 'direct_url' (Supla) — tam
	 * autoryzacja jest zakodowana w samym linku, to pole zostaje ukryte
	 * (patrz connector-form.js, isDirectUrlPreset).
	 */
	?>
	<tr class="iot-dw-field-token">
		<th><label for="iotdw_token"><span id="iotdw_token_label"><?php esc_html_e( 'Authorization', 'iot-data-widget' ); ?></span></label></th>
		<td>
			<input
				type="password"
				id="iotdw_token"
				name="token"
				class="regular-text"
				autocomplete="new-password"
			/>
			<p class="description iot-dw-field-token-standard-description">
				<?php esc_html_e( 'Home Assistant: Long-Lived Access Token. Generic REST: leave empty (no authorization) or a full header, e.g. "Authorization: Bearer xxx" or "X-API-Key: xxx".', 'iot-data-widget' ); ?>
			</p>
			<?php
			if ( file_exists( $iotdw_premium_views . 'connector-fields-token-descriptions.php' ) ) {
				require $iotdw_premium_views . 'connector-fields-token-descriptions.php';
			}
			?>
			<?php
			/**
			 * Zgłoszenie 2026-09-07 (docs/archiwum/iot-data-widget-poswiadczenia.md,
			 * sekcja 2) — Home Assistant nie oferuje zakresów uprawnień dla
			 * Long-Lived Access Tokenów: token dziedziczy PEŁNE uprawnienia
			 * konta, które go utworzyło (odczyt STANU, ale też sterowanie
			 * urządzeniami), i nie wygasa aż do ręcznego odwołania. Rzeczowa
			 * podpowiedź, nie ostrzeżenie straszące — użytkownik i tak musi
			 * wkleić jakiś token, to tylko zmniejsza szkodę przy ewentualnym
			 * wycieku.
			 */
			?>
			<p class="description iot-dw-field-token-ha-description" style="display:none;">
				<?php
				printf(
					/* translators: %s: link to Home Assistant's user management docs */
					esc_html__( 'This token inherits the full permissions of the account that created it — Home Assistant has no way to limit it to read-only. We recommend creating a separate, non-administrator account just for this token: %s', 'iot-data-widget' ),
					'<a href="https://www.home-assistant.io/docs/authentication/multi-factor-auth/#managing-users" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Home Assistant user management docs', 'iot-data-widget' ) . '</a>'
				);
				?>
			</p>
			<?php if ( $editing && ! empty( $editing['token'] ) ) : ?>
				<p class="description"><?php esc_html_e( 'Leave empty to keep the current value.', 'iot-data-widget' ); ?></p>
			<?php endif; ?>
		</td>
	</tr>
	<tr class="iot-dw-field-test-entity">
		<th><label for="iotdw_test_entity"><?php esc_html_e( 'Entity/topic to test with', 'iot-data-widget' ); ?></label></th>
		<td>
			<?php
			/**
			 * Poprawka 2026-08-31 — zgłoszenie: użytkownik testujący
			 * połączenie z presetem Tasmota wpisywał tu surowy klucz JSON
			 * zwracany przez urządzenie (np. "POWER", widoczny w
			 * odpowiedzi cmnd=Power), a nie wewnętrzny klucz metryki
			 * presetu ("relay_state") — te dwa NIE są tym samym ciągiem dla
			 * żadnego presetu poza ESPHome, więc naturalna próba (wpisanie
			 * tego, co "widać" w JSON-ie) kończyła się błędem "ścieżka nie
			 * znaleziona", mylnie odczytanym jako regresja wcześniejszej
			 * poprawki endpointu. `list="..."` daje natywną podpowiedź
			 * przeglądarki z RZECZYWISTYMI kluczami presetu (patrz
			 * IOTDW_Rest_Api::enqueue_test_connection_script(),
			 * `presetMetrics`, i connector-form.js,
			 * updateTestEntitySuggestions()) — pusta dla presetów bez
			 * stałej listy metryk (tryb zaawansowany, ESPHome
			 * entity_in_url) i dla nie-REST konektorów, więc nie zmienia
			 * niczego tam, gdzie i tak nie ma z czego podpowiadać.
			 */
			?>
			<input
				type="text"
				id="iotdw_test_entity"
				class="regular-text"
				placeholder="sensor.temperature"
				list="iotdw_test_entity_suggestions"
				autocomplete="off"
			/>
			<datalist id="iotdw_test_entity_suggestions"></datalist>
			<p class="description">
				<?php esc_html_e( "Used only for the test below — you'll pick the entity for each widget separately later.", 'iot-data-widget' ); ?>
			</p>
			<?php
			if ( file_exists( $iotdw_premium_views . 'connector-fields-test-entity-descriptions.php' ) ) {
				require $iotdw_premium_views . 'connector-fields-test-entity-descriptions.php';
			}
			?>
		</td>
	</tr>
	<tr>
		<th></th>
		<td>
			<p class="description iot-dw-field-listable-test">
				<?php esc_html_e( "Verifies the connection by fetching the full entity list from Home Assistant — no need to know an entity ID yet, you'll pick from the list in the next step.", 'iot-data-widget' ); ?>
			</p>
			<p>
				<button type="button" class="button" id="iot-dw-test-connection">
					<?php esc_html_e( 'Test connection', 'iot-data-widget' ); ?>
				</button>
				<span id="iot-dw-test-connection-result" role="status" aria-live="polite"></span>
			</p>
		</td>
	</tr>
</table>
