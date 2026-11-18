/**
 * Wypełnia listę encji z REST API wtyczki (ten sam endpoint co edytor
 * bloku Gutenberg, patrz IOTDW_Rest_Api::get_entities()) i przełącza
 * między wyborem z listy a wolnym wpisem — ten sam mechanizm co
 * blocks/iot-widget/src/edit.js (Tydzień 3/6), tylko w zwykłym JS zamiast
 * React, bo ta strona admina nie jest edytorem bloku.
 */
( function () {
	'use strict';

	function setMode( mode ) {
		var listRow   = document.getElementById( 'iot-dw-alert-entity-list-row' );
		var manualRow = document.getElementById( 'iot-dw-alert-entity-manual-row' );
		var modeField = document.getElementById( 'iotdw_alert_entity_mode' );

		if ( ! listRow || ! manualRow || ! modeField ) {
			return;
		}

		listRow.style.display   = 'list' === mode ? '' : 'none';
		manualRow.style.display = 'manual' === mode ? '' : 'none';
		modeField.value         = mode;
	}

	/**
	 * @param {string} connectionId
	 * @param {string} preselectEntityId
	 */
	function loadEntities( connectionId, preselectEntityId ) {
		var select = document.getElementById( 'iotdw_alert_entity_select' );

		if ( ! select ) {
			return;
		}

		select.innerHTML = '';

		if ( ! connectionId ) {
			setMode( 'list' );
			return;
		}

		fetch( iotdwAlertRuleForm.restUrl + '/connections/' + encodeURIComponent( connectionId ) + '/entities', {
			headers: { 'X-WP-Nonce': iotdwAlertRuleForm.nonce },
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( entities ) {
				if ( ! Array.isArray( entities ) || 0 === entities.length ) {
					setMode( 'manual' );
					return;
				}

				var found = false;

				entities.forEach( function ( entity ) {
					var option = document.createElement( 'option' );
					option.value       = entity.id;
					option.textContent = entity.label + ' (' + entity.id + ')';

					if ( preselectEntityId && entity.id === preselectEntityId ) {
						option.selected = true;
						found           = true;
					}

					select.appendChild( option );
				} );

				if ( preselectEntityId && ! found ) {
					// Istniejąca reguła wskazuje encję spoza listy presetu
					// (np. wolny wpis JSONPath) — zostań w trybie ręcznym
					// zamiast po cichu przełączać na inną wartość z listy.
					var textField = document.getElementById( 'iotdw_alert_entity_text' );
					if ( textField ) {
						textField.value = preselectEntityId;
					}
					setMode( 'manual' );
				} else {
					setMode( 'list' );
				}
			} )
			.catch( function () {
				setMode( 'manual' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var connectionSelect = document.getElementById( 'iotdw_alert_connection' );
		var manualToggleBtn  = document.getElementById( 'iotdw_alert_manual_toggle' );
		var listToggleBtn    = document.getElementById( 'iotdw_alert_list_toggle' );

		if ( connectionSelect ) {
			connectionSelect.addEventListener( 'change', function () {
				loadEntities( connectionSelect.value, '' );
			} );

			if ( connectionSelect.value ) {
				loadEntities( connectionSelect.value, connectionSelect.dataset.currentEntity || '' );
			}
		}

		if ( manualToggleBtn ) {
			manualToggleBtn.addEventListener( 'click', function () {
				setMode( 'manual' );
			} );
		}

		if ( listToggleBtn ) {
			listToggleBtn.addEventListener( 'click', function () {
				setMode( 'list' );
			} );
		}
	} );
} )();
