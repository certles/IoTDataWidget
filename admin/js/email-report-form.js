/**
 * Wypełnia listę encji z REST API wtyczki dla formularza raportów e-mail
 * (v1.3 Tydzień 7) i przełącza między wyborem z listy a wolnym wpisem — ten
 * sam mechanizm co admin/js/alert-rule-form.js (Tydzień 8 v1.2), łącznie z
 * preselekcją bieżącej encji przy edycji.
 */
( function () {
	'use strict';

	function setMode( mode ) {
		var listRow   = document.getElementById( 'iot-dw-email-report-entity-list-row' );
		var manualRow = document.getElementById( 'iot-dw-email-report-entity-manual-row' );
		var modeField = document.getElementById( 'iotdw_email_report_entity_mode' );

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
		var select = document.getElementById( 'iotdw_email_report_entity_select' );

		if ( ! select ) {
			return;
		}

		select.innerHTML = '';

		if ( ! connectionId ) {
			setMode( 'list' );
			return;
		}

		fetch( iotdwEmailReportForm.restUrl + '/connections/' + encodeURIComponent( connectionId ) + '/entities', {
			headers: { 'X-WP-Nonce': iotdwEmailReportForm.nonce },
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
					var textField = document.getElementById( 'iotdw_email_report_entity_text' );
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
		var connectionSelect = document.getElementById( 'iotdw_email_report_connection' );
		var manualToggleBtn  = document.getElementById( 'iotdw_email_report_manual_toggle' );
		var listToggleBtn    = document.getElementById( 'iotdw_email_report_list_toggle' );

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
