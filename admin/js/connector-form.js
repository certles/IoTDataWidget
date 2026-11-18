/**
 * Przełącza widoczność pól formularza połączenia zależnie od wybranego
 * konektora (i, dla MQTT, wybranego trybu dostępu) — jeden formularz HTML
 * obsługuje HA/REST/MQTT, więc reszta jest tylko wizualnym filtrem, nie
 * osobnymi formularzami. display:none (nie visibility) celowo — przeglądarki
 * wyłączają wtedy walidację "required" na ukrytych polach automatycznie.
 */
( function () {
	'use strict';

	function toggleFields() {
		var connectorSelect = document.getElementById( 'iotdw_connector' );
		var transportSelect = document.getElementById( 'iotdw_mqtt_transport' );
		var restModeSelect  = document.getElementById( 'iotdw_rest_mode' );

		if ( ! connectorSelect ) {
			return;
		}

		var isMqttPolling  = 'mqtt_polling' === connectorSelect.value;
		var isMqttListener = 'mqtt_listener' === connectorSelect.value;
		var isMqtt         = isMqttPolling || isMqttListener;
		var isSocket       = isMqttPolling && transportSelect && 'socket' === transportSelect.value;
		var isRest         = 'rest' === connectorSelect.value;
		var isRestPreset   = isRest && restModeSelect && 'preset' === restModeSelect.value;
		var isShellyCloud  = 'shelly_cloud' === connectorSelect.value;
		var isUrlBased     = ! isMqttListener && ! isRestPreset && ! isSocket && ! isShellyCloud;
		var presetFlags    = getSelectedPresetFlags();

		// Today only 'home_assistant' implements IOTDW_Listable_Connector_Interface
		// server-side (add its connector key here too if that ever changes) —
		// for it, "Test connection" lists every entity instead of needing one
		// typed by hand (kreator multi-entity poprawka, Tydzień 2), so the
		// manual entity/topic field is redundant and hidden.
		var isListable = 'home_assistant' === connectorSelect.value;

		// Listener mode has no transport choice — it's always a direct broker
		// connection, so the broker fields show unconditionally for it.
		toggleRows( '.iot-dw-field-mqtt-broker', isMqttListener || isSocket );
		toggleRows( '.iot-dw-field-mqtt-polling-only', isMqttPolling );
		toggleRows( '.iot-dw-field-mqtt-listener', isMqttListener );
		toggleRows( '.iot-dw-field-rest-mode', isRest );
		toggleRows( '.iot-dw-field-rest-preset', isRestPreset );
		toggleRows( '.iot-dw-field-shelly-cloud', isShellyCloud );
		toggleRows( '.iot-dw-field-url-based', isUrlBased );
		// Poprawka 2026-08-28 — Authorization widoczne TAKŻE w trybie presetu
		// REST (nie tylko zaawansowanym/isUrlBased), inaczej użytkownik
		// presetu (np. Tasmoty z ustawionym hasłem WWW) nie ma gdzie wpisać
		// autoryzacji w ogóle. Wyjątek: presety 'direct_url' (Supla) — tam
		// sekret jest już w samym linku, osobne pole byłoby mylące.
		toggleRows( '.iot-dw-field-token', isUrlBased || ( isRestPreset && ! presetFlags.isDirectUrl ) );
		toggleRows( '.iot-dw-field-test-entity', ! isListable );
		toggleRows( '.iot-dw-field-test-entity-mqtt-hint', isMqtt );
		toggleRows( '.iot-dw-field-listable-test', isListable );

		updateDeviceIpFieldForPreset( presetFlags );
		updateTokenFieldForPreset( presetFlags );
		updateTestEntitySuggestions( isRestPreset );

		// Zgłoszenie 2026-09-07 (docs/archiwum/iot-data-widget-poswiadczenia.md) —
		// podpowiedź o dedykowanym koncie bez uprawnień administratora,
		// TYLKO dla Home Assistant (jedyny konektor z tokenem o pełnych,
		// nieograniczalnych uprawnieniach — patrz sekcja 2 tego dokumentu).
		toggleRows( '.iot-dw-field-token-ha-description', 'home_assistant' === connectorSelect.value );
	}

	/**
	 * Czyta flagi presetu (data-* na zaznaczonej `<option>` w
	 * connector-fields.php — patrz IOTDW_Connector_Presets) wprost z DOM-u,
	 * współdzielone przez wszystkie funkcje przełączające pola per preset,
	 * żeby dodanie kolejnej flagi nie wymagało powtarzania tego samego
	 * odczytu w kilku miejscach.
	 *
	 * @return {{isDirectUrl: boolean, isQueryStringAuth: boolean}}
	 */
	function getSelectedPresetFlags() {
		var presetSelect = document.getElementById( 'iotdw_preset' );

		if ( ! presetSelect ) {
			return { isDirectUrl: false, isQueryStringAuth: false };
		}

		var selectedOption = presetSelect.options[ presetSelect.selectedIndex ];

		return {
			isDirectUrl: !! ( selectedOption && '1' === selectedOption.getAttribute( 'data-direct-url' ) ),
			isQueryStringAuth: !! ( selectedOption && '1' === selectedOption.getAttribute( 'data-query-string-auth' ) ),
		};
	}

	/**
	 * Przełącza etykietę/opis pola "Device IP address" (name="device_ip")
	 * na wariant dla presetów 'direct_url' (na razie tylko Supla — patrz
	 * IOTDW_Connector_Presets), gdzie to pole niesie CAŁY link, nie adres IP.
	 *
	 * @param {{isDirectUrl: boolean, isQueryStringAuth: boolean}} presetFlags Patrz getSelectedPresetFlags().
	 */
	function updateDeviceIpFieldForPreset( presetFlags ) {
		var input = document.getElementById( 'iotdw_device_ip' );
		var label = document.getElementById( 'iotdw_device_ip_label' );

		if ( ! input || ! label ) {
			return;
		}

		var isDirectUrl = presetFlags.isDirectUrl;

		label.textContent = isDirectUrl
			? ( 'undefined' !== typeof iotdwTestConnection ? iotdwTestConnection.directUrlFieldLabel : label.textContent )
			: ( 'undefined' !== typeof iotdwTestConnection ? iotdwTestConnection.deviceIpFieldLabel : label.textContent );

		input.placeholder = isDirectUrl
			? ( 'undefined' !== typeof iotdwTestConnection ? iotdwTestConnection.directUrlFieldPlaceholder : '' )
			: '192.168.1.50';

		toggleRows( '.iot-dw-field-device-ip-standard-description', ! isDirectUrl );
		toggleRows( '.iot-dw-field-device-ip-direct-url-description', isDirectUrl );
	}

	/**
	 * Przełącza etykietę/opis pola "Authorization" (name="token") na wariant
	 * dla presetów 'query_string_auth' (na razie tylko Tasmota — patrz
	 * IOTDW_Connector_Presets) — tam to pole niesie samo hasło Tasmoty
	 * (WebPassword), nie surowy nagłówek (Tasmota nie obsługuje
	 * Authorization header dla swojego HTTP API, patrz zgłoszenie 2026-08-28).
	 *
	 * @param {{isDirectUrl: boolean, isQueryStringAuth: boolean}} presetFlags Patrz getSelectedPresetFlags().
	 */
	function updateTokenFieldForPreset( presetFlags ) {
		var label = document.getElementById( 'iotdw_token_label' );

		if ( ! label ) {
			return;
		}

		var isQueryStringAuth = presetFlags.isQueryStringAuth;

		label.textContent = isQueryStringAuth
			? ( 'undefined' !== typeof iotdwTestConnection ? iotdwTestConnection.tasmotaAuthFieldLabel : label.textContent )
			: ( 'undefined' !== typeof iotdwTestConnection ? iotdwTestConnection.authFieldLabel : label.textContent );

		toggleRows( '.iot-dw-field-token-standard-description', ! isQueryStringAuth );
		toggleRows( '.iot-dw-field-token-tasmota-description', isQueryStringAuth );
	}

	/**
	 * Wypełnia <datalist> pola "Entity/topic to test with" kluczami metryk
	 * WYBRANEGO presetu (poprawka 2026-08-31 — patrz obszerny komentarz w
	 * connector-fields.php i IOTDW_Rest_Api::enqueue_test_connection_script(),
	 * 'presetMetrics'): bez tego pole jest ślepym wolnym tekstem, a
	 * naturalna próba wpisania surowego klucza JSON zwracanego przez
	 * urządzenie (np. "POWER" dla Tasmoty, zamiast wewnętrznego klucza
	 * presetu "relay_state") kończy się mylącym błędem "ścieżka nie
	 * znaleziona". Presety bez stałej listy metryk (tryb zaawansowany,
	 * ESPHome entity_in_url) i wybór spoza trybu presetu REST dają po
	 * prostu pustą listę podpowiedzi — pole wraca do bycia zwykłym wolnym
	 * tekstem, bez żadnej specjalnej obsługi.
	 *
	 * @param {boolean} isRestPreset Czy REST jest aktualnie w trybie presetu.
	 */
	function updateTestEntitySuggestions( isRestPreset ) {
		var datalist = document.getElementById( 'iotdw_test_entity_suggestions' );
		var presetSelect = document.getElementById( 'iotdw_preset' );

		if ( ! datalist ) {
			return;
		}

		datalist.innerHTML = '';

		var metrics = ( isRestPreset && presetSelect && 'undefined' !== typeof iotdwTestConnection && iotdwTestConnection.presetMetrics )
			? ( iotdwTestConnection.presetMetrics[ presetSelect.value ] || [] )
			: [];

		metrics.forEach( function ( metric ) {
			var option = document.createElement( 'option' );
			option.value = metric.id;
			option.label = metric.label;
			datalist.appendChild( option );
		} );

		toggleRows( '.iot-dw-field-test-entity-preset-hint', metrics.length > 0 );
	}

	/**
	 * @param {string} selector
	 * @param {boolean} visible
	 */
	function toggleRows( selector, visible ) {
		document.querySelectorAll( selector ).forEach( function ( row ) {
			row.style.display = visible ? '' : 'none';
		} );
	}

	/**
	 * Czy $host jest adresem sieci lokalnej — JS-owy odpowiednik
	 * IOTDW_Abstract_Connector::is_local_network_host() (PHP), ten sam
	 * zakres RFC 1918 + loopback + link-local + ".local"/mDNS. Świadomie
	 * tylko IPv4 (realistyczny zasięg dla urządzeń IoT tego pluginu) —
	 * przeglądarka nie ma odpowiednika filter_var(), więc parsowanie ręczne.
	 *
	 * @param {string} host
	 * @return {boolean}
	 */
	function isLocalNetworkHost( host ) {
		host = ( host || '' ).trim().toLowerCase();

		if ( '' === host ) {
			return false;
		}

		if ( host.endsWith( '.local' ) ) {
			return true;
		}

		var match = host.match( /^(\d{1,3})\.(\d{1,3})\.(\d{1,3})\.(\d{1,3})$/ );

		if ( ! match ) {
			return false;
		}

		var octets = match.slice( 1 ).map( function ( part ) {
			return parseInt( part, 10 );
		} );

		if ( octets.some( function ( octet ) { return octet > 255; } ) ) {
			return false; // Not a valid IPv4 literal.
		}

		var a = octets[ 0 ];
		var b = octets[ 1 ];

		return (
			10 === a || // 10.0.0.0/8
			( 172 === a && b >= 16 && b <= 31 ) || // 172.16.0.0/12
			( 192 === a && 168 === b ) || // 192.168.0.0/16
			127 === a || // loopback
			( 169 === a && 254 === b ) // link-local
		);
	}

	/**
	 * Wyciąga sam hostname z pełnego URL-a (base_url pola "URL"), lub null
	 * jeśli wartość nie jest jeszcze poprawnym, kompletnym URL-em (np.
	 * użytkownik wciąż wpisuje) — URL API przeglądarki rzuca wyjątkiem
	 * wtedy, nie zwraca częściowego wyniku.
	 *
	 * @param {string} value
	 * @return {string|null}
	 */
	function hostnameFromUrl( value ) {
		try {
			return new URL( value ).hostname;
		} catch ( e ) {
			return null;
		}
	}

	/**
	 * Ostrzeżenie o adresie sieci lokalnej dla pola "URL" (HA / Generic REST
	 * zaawansowany) — na żywo, w miarę wpisywania. Pole "Device IP address"
	 * (presety) ma stały, zawsze widoczny opis w connector-fields.php zamiast
	 * tego (ten adres jest z definicji lokalny, nic tu nie trzeba wykrywać).
	 */
	function updateBaseUrlLocalNetworkWarning() {
		var input   = document.getElementById( 'iotdw_base_url' );
		var warning = document.getElementById( 'iotdw_base_url_local_warning' );

		if ( ! input || ! warning || 'undefined' === typeof iotdwTestConnection ) {
			return;
		}

		var host = hostnameFromUrl( input.value );

		if ( host && isLocalNetworkHost( host ) ) {
			warning.textContent = iotdwTestConnection.localNetworkWarningLabel.replace( '%s', host );
			warning.style.display = '';
		} else {
			warning.style.display = 'none';
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var connectorSelect = document.getElementById( 'iotdw_connector' );
		var transportSelect = document.getElementById( 'iotdw_mqtt_transport' );
		var restModeSelect  = document.getElementById( 'iotdw_rest_mode' );
		var presetSelect    = document.getElementById( 'iotdw_preset' );
		var baseUrlInput    = document.getElementById( 'iotdw_base_url' );

		if ( connectorSelect ) {
			connectorSelect.addEventListener( 'change', toggleFields );
		}
		if ( transportSelect ) {
			transportSelect.addEventListener( 'change', toggleFields );
		}
		if ( restModeSelect ) {
			restModeSelect.addEventListener( 'change', toggleFields );
		}
		if ( presetSelect ) {
			presetSelect.addEventListener( 'change', toggleFields );
		}
		if ( baseUrlInput ) {
			baseUrlInput.addEventListener( 'input', updateBaseUrlLocalNetworkWarning );
			updateBaseUrlLocalNetworkWarning();
		}

		toggleFields();
	} );
} )();
