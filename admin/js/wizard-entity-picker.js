/**
 * Krok 3 kreatora — przeglądarka encji Home Assistant: wyszukiwanie + filtr
 * domeny (v1.3) + zaznaczanie wielokrotne checkboxami (kreator multi-entity,
 * v1.9 Tydzień 3). No-op na każdej innej stronie/kroku (brak
 * #iotdw_wizard_entity_list z data-connection-id). Reużywa dokładnie ten sam
 * endpoint co edytor bloku Gutenberg (IOTDW_Rest_Api::get_entities()) i
 * localizowane restUrl/nonce z iotdwTestConnection (Tydzień 4,
 * admin/js/test-connection.js) — obie wtyczkowe skrypty na tej stronie
 * dzielą ten sam REST namespace/nonce, nie ma powodu localizować drugi raz.
 *
 * Zaznaczenie żyje w JS (obiekt `selected`, id => encja), NIE w atrybutach
 * checkboxów — bo re-render po filtrowaniu (search/domain) tworzy nowe
 * elementy DOM za każdym razem, więc trwały stan musi żyć gdzie indziej.
 * Tuż przed submitem formularza cały wybór trafia do ukrytego pola
 * #iotdw_wizard_entities_json jako JSON (id + label), które
 * IOTDW_Setup_Wizard::handle_save_entities() odczytuje po stronie serwera —
 * checkboxy same w sobie nie mają atrybutu 'name', to jedyne źródło wyboru.
 */
( function () {
	'use strict';

	var allEntities = [];
	var selected     = {};

	/**
	 * @param {Array} entities
	 */
	function renderCheckboxes( entities ) {
		var container = document.getElementById( 'iotdw_wizard_entity_list' );
		container.innerHTML = '';

		entities.forEach( function ( entity ) {
			var row      = document.createElement( 'label' );
			var checkbox = document.createElement( 'input' );

			row.className = 'iot-dw-wizard__entity-row';

			checkbox.type    = 'checkbox';
			checkbox.value   = entity.id;
			checkbox.checked = !! selected[ entity.id ];

			checkbox.addEventListener( 'change', function () {
				if ( checkbox.checked ) {
					selected[ entity.id ] = entity;
				} else {
					delete selected[ entity.id ];
				}

				updateSelectionState();
			} );

			row.appendChild( checkbox );
			row.appendChild( document.createTextNode( ' ' + entity.label + ' (' + entity.id + ')' ) );
			container.appendChild( row );
		} );
	}

	/**
	 * Aktualizuje licznik "N selected" i włącza/wyłącza Continue — jedyne
	 * miejsce, gdzie liczba zaznaczonych wpływa na resztę formularza.
	 */
	function updateSelectionState() {
		var countEl     = document.getElementById( 'iotdw_wizard_entity_count' );
		var continueBtn = document.getElementById( 'iot-dw-wizard-step3-continue' );
		var count       = Object.keys( selected ).length;

		if ( countEl ) {
			countEl.textContent = iotdwTestConnection.selectedCountLabel.replace( '%d', String( count ) );
		}
		if ( continueBtn ) {
			continueBtn.disabled = 0 === count;
		}
	}

	/**
	 * @param {Array} entities
	 */
	function populateDomains( entities ) {
		var select  = document.getElementById( 'iotdw_wizard_entity_domain' );
		var domains = [];

		entities.forEach( function ( entity ) {
			if ( entity.domain && -1 === domains.indexOf( entity.domain ) ) {
				domains.push( entity.domain );
			}
		} );

		domains.sort();

		domains.forEach( function ( domain ) {
			var option = document.createElement( 'option' );
			option.value       = domain;
			option.textContent = domain;
			select.appendChild( option );
		} );
	}

	function applyFilters() {
		var search = document.getElementById( 'iotdw_wizard_entity_search' ).value.toLowerCase();
		var domain = document.getElementById( 'iotdw_wizard_entity_domain' ).value;
		var status = document.getElementById( 'iotdw_wizard_entity_status' );

		var filtered = allEntities.filter( function ( entity ) {
			var matchesDomain = '' === domain || entity.domain === domain;
			var matchesSearch = '' === search
				|| -1 !== entity.label.toLowerCase().indexOf( search )
				|| -1 !== entity.id.toLowerCase().indexOf( search );

			return matchesDomain && matchesSearch;
		} );

		renderCheckboxes( filtered );

		// v1.7 Tydzień 6: an empty <select> with zero <option>s and no
		// explanation reads as broken, not "no matches" — reuses the same
		// status line already shown for "loading"/"connection has zero
		// entities at all", so a filtered-to-zero search degrades exactly
		// like those other empty states instead of looking like a bug.
		if ( status ) {
			status.textContent = ( 0 === filtered.length && allEntities.length > 0 ) ? iotdwTestConnection.noMatchesLabel : '';
		}
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var list   = document.getElementById( 'iotdw_wizard_entity_list' );
		var status = document.getElementById( 'iotdw_wizard_entity_status' );
		var search = document.getElementById( 'iotdw_wizard_entity_search' );
		var domain = document.getElementById( 'iotdw_wizard_entity_domain' );
		var form   = document.getElementById( 'iot-dw-wizard-step3-form' );
		var json   = document.getElementById( 'iotdw_wizard_entities_json' );

		if ( ! list || ! list.dataset.connectionId || 'undefined' === typeof iotdwTestConnection ) {
			return;
		}

		updateSelectionState();

		fetch( iotdwTestConnection.restUrl + '/connections/' + encodeURIComponent( list.dataset.connectionId ) + '/entities', {
			headers: { 'X-WP-Nonce': iotdwTestConnection.nonce },
		} )
			.then( function ( response ) {
				return response.json();
			} )
			.then( function ( entities ) {
				if ( ! Array.isArray( entities ) || 0 === entities.length ) {
					if ( status ) {
						status.textContent = iotdwTestConnection.noEntitiesLabel;
					}
					return;
				}

				allEntities = entities;
				populateDomains( entities );
				renderCheckboxes( entities );

				if ( status ) {
					status.textContent = '';
				}
			} )
			.catch( function () {
				if ( status ) {
					status.textContent = iotdwTestConnection.networkErrorLabel;
				}
			} );

		if ( search ) {
			search.addEventListener( 'input', applyFilters );
		}
		if ( domain ) {
			domain.addEventListener( 'change', applyFilters );
		}

		if ( form && json ) {
			form.addEventListener( 'submit', function () {
				var payload = Object.keys( selected ).map( function ( id ) {
					return { id: id, label: selected[ id ].label };
				} );

				// MAINT-2: JSON jako zapis szesnastkowy bajtów UTF-8. Znaki 0-9a-f
				// przechodzą przez sanitize_text_field() bez zmian, więc PHP
				// sanityzuje pole przy odczycie, a nazwy z „<” lub „%ab” docierają
				// w całości do sanityzacji pole po polu
				// (IOTDW_Setup_Wizard::handle_save_entities()).
				var bytes = unescape( encodeURIComponent( JSON.stringify( payload ) ) );
				var hex   = '';

				for ( var i = 0; i < bytes.length; i++ ) {
					hex += ( '0' + bytes.charCodeAt( i ).toString( 16 ) ).slice( -2 );
				}

				json.value = hex;
			} );
		}
	} );
} )();
