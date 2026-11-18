/**
 * Wypełnia listę encji z REST API wtyczki dla formularza eksportu CSV
 * (v1.3 Tydzień 6) i przełącza między wyborem z listy a wolnym wpisem —
 * ten sam mechanizm co admin/js/manual-tracking-form.js i
 * admin/js/alert-rule-form.js, zduplikowany celowo zamiast dzielony (patrz
 * komentarz w manual-tracking-form.js: kilka niezależnych, małych formularzy
 * na różnych stronach, nie warto tworzyć wspólnej abstrakcji).
 */
( function () {
	'use strict';

	function setMode( mode ) {
		var listRow   = document.getElementById( 'iot-dw-export-entity-list-row' );
		var manualRow = document.getElementById( 'iot-dw-export-entity-manual-row' );
		var modeField = document.getElementById( 'iotdw_export_entity_mode' );

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
		var select = document.getElementById( 'iotdw_export_entity_select' );

		if ( ! select ) {
			return;
		}

		select.innerHTML = '';

		if ( ! connectionId ) {
			setMode( 'list' );
			return;
		}

		fetch( iotdwExportHistoryForm.restUrl + '/connections/' + encodeURIComponent( connectionId ) + '/entities', {
			headers: { 'X-WP-Nonce': iotdwExportHistoryForm.nonce },
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
		var connectionSelect = document.getElementById( 'iotdw_export_connection' );
		var manualToggleBtn  = document.getElementById( 'iotdw_export_entity_manual_toggle' );
		var listToggleBtn    = document.getElementById( 'iotdw_export_entity_list_toggle' );

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
