/**
 * "Sprawdź, czy dane docierają" (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md §6)
 * — po utworzeniu/pokazaniu snippetu, odpytuje status kanału co kilka
 * sekund przez ~60s i pokazuje pierwszy odebrany odczyt, zamykając pętlę
 * ("skonfigurowałem i nic się nie dzieje" to najczęstsze zgłoszenie do
 * supportu, wg §4 planu — ten ekran ma temu zapobiec).
 *
 * Zgłoszenie 2026-09-07: tabela encji pod tym przyciskiem (patrz
 * step-push-3-snippet.php) jest server-side migawką sprzed załadowania tej
 * strony — jeśli urządzenie wyśle odczyt PO załadowaniu (typowy przypadek,
 * użytkownik dopiero teraz wkleja snippet), tabela zostawała na zawsze przy
 * "— no data yet —", mimo że dane już były w bazie i sam ten przycisk
 * poprawnie je wykrywał. refreshTable() aktualizuje te same wiersze z
 * odpowiedzi TEGO SAMEGO żądania statusu — wołane raz od razu przy
 * załadowaniu strony (na wypadek, gdyby coś przyszło między poprzednim
 * renderem a teraz) i przy każdym tyknięciu pollingu podczas nasłuchu.
 */
( function () {
	'use strict';

	var POLL_INTERVAL_MS = 3000;
	var TOTAL_TIMEOUT_MS = 60000;

	document.addEventListener( 'DOMContentLoaded', function () {
		var button = document.getElementById( 'iot-dw-push-check-receiving' );
		var result = document.getElementById( 'iot-dw-push-check-receiving-result' );

		if ( ! button || ! result || 'undefined' === typeof iotdwPushCheck ) {
			return;
		}

		/**
		 * Podmienia komórki "Last value"/"Last received" w tabeli poniżej dla
		 * KAŻDEJ encji obecnej w odpowiedzi — nie tylko tej, na którą akurat
		 * czeka poll(). Format wartości identyczny z PHP (step-push-3-snippet.php):
		 * last_value_txt, jeśli ustawione, inaczej last_value_num + jednostka,
		 * inaczej placeholder "— no data yet —"/"—".
		 *
		 * @param {Array<Object>} entities Odpowiedź endpointu (data.entities).
		 */
		function refreshTable( entities ) {
			( entities || [] ).forEach( function ( entity ) {
				var row = document.querySelector( '[data-entity-row="' + entity.entity_key + '"]' );
				if ( ! row ) {
					return;
				}

				var valueCell = row.querySelector( '[data-entity-value]' );
				var timeCell = row.querySelector( '[data-entity-time]' );

				if ( valueCell ) {
					if ( ! entity.last_recorded_at ) {
						valueCell.textContent = iotdwPushCheck.noDataLabel;
					} else if ( entity.last_value_txt !== null && entity.last_value_txt !== undefined ) {
						valueCell.textContent = entity.last_value_txt;
					} else {
						valueCell.textContent = entity.last_value_num + ( entity.unit ? ' ' + entity.unit : '' );
					}
				}

				if ( timeCell ) {
					timeCell.textContent = entity.last_recorded_at || '—';
				}
			} );
		}

		// Odśwież tabelę RAZ od razu przy załadowaniu strony — pokrywa
		// przypadek "dane przyszły między poprzednim renderem a teraz", bez
		// czekania aż użytkownik kliknie przycisk niżej.
		fetch( iotdwPushCheck.restUrl, { headers: { 'X-WP-Nonce': iotdwPushCheck.nonce } } )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( data ) {
				refreshTable( data.entities );
			} )
			.catch( function () {
				// Cisza celowa — tabela zostaje przy tym, co wyrenderował
				// serwer, tak samo jak public/js/widget-refresh.js przy błędzie.
			} );

		// Migawka last_recorded_at PRZED rozpoczęciem nasłuchu — porównujemy
		// względem niej, nie względem "cokolwiek niepuste", bo kanał mógł już
		// wcześniej coś odebrać (np. to drugie kliknięcie tego przycisku).
		var baseline = {};

		button.addEventListener( 'click', function () {
			button.disabled = true;
			result.textContent = iotdwPushCheck.listeningLabel;
			result.style.color = '#646970';

			fetch( iotdwPushCheck.restUrl, { headers: { 'X-WP-Nonce': iotdwPushCheck.nonce } } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					( data.entities || [] ).forEach( function ( entity ) {
						baseline[ entity.entity_key ] = entity.last_recorded_at;
					} );
					refreshTable( data.entities );
					poll( Date.now() );
				} )
				.catch( function () {
					finish( iotdwPushCheck.networkErrorLabel, '#d63638' );
				} );
		} );

		/**
		 * @param {number} startedAt
		 */
		function poll( startedAt ) {
			if ( Date.now() - startedAt > TOTAL_TIMEOUT_MS ) {
				finish( iotdwPushCheck.timeoutLabel, '#996800' );
				return;
			}

			fetch( iotdwPushCheck.restUrl, { headers: { 'X-WP-Nonce': iotdwPushCheck.nonce } } )
				.then( function ( response ) {
					return response.json();
				} )
				.then( function ( data ) {
					refreshTable( data.entities );

					var received = ( data.entities || [] ).find( function ( entity ) {
						return entity.last_recorded_at && entity.last_recorded_at !== baseline[ entity.entity_key ];
					} );

					if ( received ) {
						var display = received.last_value_txt !== null && received.last_value_txt !== undefined
							? received.last_value_txt
							: received.last_value_num + ( received.unit ? ' ' + received.unit : '' );

						finish(
							iotdwPushCheck.receivedLabel
								.replace( '%1$s', received.entity_key )
								.replace( '%2$s', display ),
							'#008a20'
						);
						return;
					}

					setTimeout( function () {
						poll( startedAt );
					}, POLL_INTERVAL_MS );
				} )
				.catch( function () {
					finish( iotdwPushCheck.networkErrorLabel, '#d63638' );
				} );
		}

		/**
		 * @param {string} message
		 * @param {string} color
		 */
		function finish( message, color ) {
			result.textContent = message;
			result.style.color = color;
			button.disabled = false;
		}
	} );
} )();
