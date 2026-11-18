/**
 * Krok 4 kreatora — przyciski "Copy" dla gotowych shortcode'ów (v1.4
 * Tydzień 6), jeden na kartę encji + opcjonalny "Copy all" dla kilku naraz
 * (kreator multi-entity, v1.9 Tydzień 5). No-op na każdej innej stronie/kroku
 * (brak .iot-dw-copy-shortcode).
 */
( function () {
	'use strict';

	/**
	 * @param {string} text
	 * @param {?HTMLElement} resultEl
	 */
	function copyText( text, resultEl ) {
		navigator.clipboard.writeText( text ).then(
			function () {
				if ( resultEl ) {
					resultEl.textContent = iotdwTestConnection.copiedLabel;
				}
			},
			function () {
				if ( resultEl ) {
					resultEl.textContent = iotdwTestConnection.copyFailedLabel;
				}
			}
		);
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		document.querySelectorAll( '.iot-dw-copy-shortcode' ).forEach( function ( button ) {
			button.addEventListener( 'click', function () {
				var wrapper = button.closest( '.iot-dw-wizard__entity-preview' );
				var code    = wrapper ? wrapper.querySelector( '.iot-dw-wizard-shortcode' ) : null;
				var result  = wrapper ? wrapper.querySelector( '.iot-dw-copy-shortcode-result' ) : null;

				if ( code ) {
					copyText( code.textContent.trim(), result );
				}
			} );
		} );

		var copyAllButton = document.getElementById( 'iot-dw-copy-all-shortcodes' );
		var copyAllResult = document.getElementById( 'iot-dw-copy-all-result' );

		if ( copyAllButton ) {
			copyAllButton.addEventListener( 'click', function () {
				var texts = Array.prototype.map.call(
					document.querySelectorAll( '.iot-dw-wizard-shortcode' ),
					function ( code ) {
						return code.textContent.trim();
					}
				);

				copyText( texts.join( '\n' ), copyAllResult );
			} );
		}
	} );
} )();
