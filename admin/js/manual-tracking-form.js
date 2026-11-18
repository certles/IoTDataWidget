/**
 * Wypełnia listę encji z REST API wtyczki (ten sam endpoint co edytor bloku
 * Gutenberg i formularz reguł alertów, patrz IOTDW_Rest_Api::get_entities())
 * i przełącza między wyborem z listy a wolnym wpisem — ten sam mechanizm co
 * admin/js/alert-rule-form.js (Tydzień 8), zduplikowany celowo zamiast
 * dzielony: dwa niezależne, małe formularze na różnych stronach, nie warto
 * tworzyć wspólnej abstrakcji dla dwóch użyć.
 */
( function () {
	'use strict';

	function setMode( mode ) {
		var listRow   = document.getElementById( 'iot-dw-manual-entity-list-row' );
		var manualRow = document.getElementById( 'iot-dw-manual-entity-manual-row' );
		var modeField = document.getElementById( 'iotdw_manual_entity_mode' );

		if ( ! listRow || ! manualRow || ! modeField ) {
			return;
		}

		listRow.style.display   = 'list' === mode ? '' : 'none';
		manualRow.style.display = 'manual' === mode ? '' : 'none';
		modeField.value         = mode;
	}

	/**
	 * @param {string} connectionId
	 */
	function loadEntities( connectionId ) {
		var select = document.getElementById( 'iotdw_manual_entity_select' );

		if ( ! select ) {
			return;
		}

		select.innerHTML = '';

		if ( ! connectionId ) {
			setMode( 'list' );
			return;
		}

		fetch( iotdwManualTrackingForm.restUrl + '/connections/' + encodeURIComponent( connectionId ) + '/entities', {
			headers: { 'X-WP-Nonce': iotdwManualTrackingForm.nonce },
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( entities ) {
				if ( ! Array.isArray( entities ) || 0 === entities.length ) {
					setMode( 'manual' );
					return;
				}

				entities.forEach( function ( entity ) {
					var option = document.createElement( 'option' );
					option.value       = entity.id;
					option.textContent = entity.label + ' (' + entity.id + ')';
					select.appendChild( option );
				} );

				setMode( 'list' );
			} )
			.catch( function () {
				setMode( 'manual' );
			} );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var connectionSelect = document.getElementById( 'iotdw_manual_connection' );
		var manualToggleBtn  = document.getElementById( 'iotdw_manual_entity_manual_toggle' );
		var listToggleBtn    = document.getElementById( 'iotdw_manual_entity_list_toggle' );

		if ( connectionSelect ) {
			connectionSelect.addEventListener( 'change', function () {
				loadEntities( connectionSelect.value );
			} );

			if ( connectionSelect.value ) {
				loadEntities( connectionSelect.value );
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
