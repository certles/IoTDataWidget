/**
 * "Testuj połączenie" (v1.4 Tydzień 4, docs/archiwum/mvp-v1-4-plan.md) — wspólny
 * mechanizm dla zwykłej strony dodawania/edycji połączenia ORAZ Kroku 2
 * kreatora. Jeden endpoint (IOTDW_Rest_Api::test_connection(), Tydzień 2),
 * dwa miejsca użycia — działa identycznie w obu, bo pola formularza mają te
 * same ID w obu miejscach (ten sam partial, admin/views/partials/connector-fields.php).
 * Nigdy nie zapisuje połączenie do bazy — tylko odczytuje bieżące wartości
 * pól i woła endpoint testowy.
 */
( function () {
	'use strict';

	/**
	 * @param {string} id
	 * @return {string}
	 */
	function fieldValue( id ) {
		var el = document.getElementById( id );
		return el ? el.value : '';
	}

	/**
	 * @param {string} id
	 * @return {boolean}
	 */
	function isChecked( id ) {
		var el = document.getElementById( id );
		return !! ( el && el.checked );
	}

	/**
	 * Zbiera bieżące wartości pól formularza w kształcie, jakiego oczekuje
	 * IOTDW_Connector_Manager::build_config() po stronie serwera — 'preset'
	 * wysyłane tylko wtedy, gdy REST jest w trybie presetu (ten sam warunek
	 * co IOTDW_Admin_Page::handle_save()).
	 *
	 * @return {Object}
	 */
	function collectParams() {
		var connector = fieldValue( 'iotdw_connector' );
		var restMode  = fieldValue( 'iotdw_rest_mode' );

		return {
			connector: connector,
			base_url: fieldValue( 'iotdw_base_url' ),
			token: fieldValue( 'iotdw_token' ),
			// build_config() po stronie serwera buduje base_url z presetu +
			// device_ip TYLKO gdy rest_mode === 'preset' (patrz jej docblock)
			// — bez wysłania tego pola tutaj serwer zawsze zakładał
			// 'advanced', więc test połączenia dla presetu (Shelly/Tasmota/
			// ESPHome) zawsze dostawał pusty base_url i kończył się "Missing
			// URL in the connection settings", mimo poprawnie wypełnionego
			// adresu IP — znalezione na żywo (Playwright), nie tylko w kodzie.
			rest_mode: restMode,
			preset: 'rest' === connector && 'preset' === restMode ? fieldValue( 'iotdw_preset' ) : '',
			device_ip: fieldValue( 'iotdw_device_ip' ),
			server_uri: fieldValue( 'iotdw_server_uri' ),
			device_id: fieldValue( 'iotdw_device_id' ),
			auth_key: fieldValue( 'iotdw_auth_key' ),
			mqtt_transport: fieldValue( 'iotdw_mqtt_transport' ),
			mqtt_host: fieldValue( 'iotdw_mqtt_host' ),
			mqtt_port: fieldValue( 'iotdw_mqtt_port' ),
			mqtt_tls: isChecked( 'iotdw_mqtt_tls' ) ? '1' : '',
			mqtt_username: fieldValue( 'iotdw_mqtt_username' ),
			mqtt_password: fieldValue( 'iotdw_mqtt_password' ),
			entity_id: fieldValue( 'iotdw_test_entity' ),
		};
	}

	/**
	 * @param {HTMLElement} el
	 * @param {Object} response
	 */
	function renderResult( el, response ) {
		if ( 'ok' === response.status ) {
			var data = response.data || {};
			el.style.color = '#008a20';

			// Listable connectors (v1.9 kreator multi-entity): the server
			// tests by listing every entity instead of fetching one, so
			// there's no single value/unit to show — the count is already
			// formatted server-side in response.message ("Found 47 entities").
			if ( Array.isArray( data.entities ) ) {
				el.textContent = '✓ ' + response.message;
			} else {
				var display = data.display_value
					? data.display_value
					: data.value + ( data.unit ? ' ' + data.unit : '' );
				el.textContent = '✓ ' + display;
			}
		} else if ( 'pending' === response.status ) {
			el.style.color = '#646970';
			el.textContent = '… ' + response.message;
		} else {
			el.style.color = '#d63638';
			el.textContent = '✗ ' + response.message;
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var button       = document.getElementById( 'iot-dw-test-connection' );
		var result       = document.getElementById( 'iot-dw-test-connection-result' );
		var continueBtn  = document.getElementById( 'iot-dw-wizard-continue' );

		if ( ! button || ! result || 'undefined' === typeof iotdwTestConnection ) {
			return;
		}

		button.addEventListener( 'click', function () {
			button.disabled   = true;
			result.style.color = '#646970';
			result.textContent = iotdwTestConnection.testingLabel;

			if ( continueBtn ) {
				continueBtn.disabled = true;
			}

			fetch( iotdwTestConnection.restUrl + '/test-connection', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': iotdwTestConnection.nonce,
				},
				body: JSON.stringify( collectParams() ),
			} )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					button.disabled = false;
					renderResult( result, data );

					// "pending" (MQTT — patrz IOTDW_Rest_Api::test_connection())
					// odblokowuje Continue tak samo jak "ok": to nie jest błąd,
					// tylko uczciwa informacja, że wartość pojawi się dopiero po
					// zapisaniu połączenia. Tylko "error" zostawia przycisk
					// zablokowany.
					if ( continueBtn && 'error' !== data.status ) {
						continueBtn.disabled = false;
					}
				} )
				.catch( function () {
					button.disabled    = false;
					result.style.color = '#d63638';
					result.textContent = iotdwTestConnection.networkErrorLabel;
				} );
		} );
	} );
} )();
