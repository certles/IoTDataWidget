/**
 * Kopiuje pełny tekstowy raport diagnostyczny do schowka (v1.6 Tydzień 6) —
 * bez przeładowania strony. navigator.clipboard wymaga bezpiecznego
 * kontekstu (HTTPS albo localhost) — execCommand('copy') to fallback dla
 * starszych przeglądarek/niebezpiecznych kontekstów, gdzie clipboard API
 * nie istnieje.
 */
( function () {
	'use strict';

	document.addEventListener( 'DOMContentLoaded', function () {
		var button   = document.getElementById( 'iot-dw-copy-report' );
		var textarea = document.getElementById( 'iot-dw-report-text' );
		var status   = document.getElementById( 'iot-dw-copy-report-status' );

		if ( ! button || ! textarea ) {
			return;
		}

		function showCopied() {
			if ( status ) {
				status.textContent = iotdwDiagnosticsReport.copiedText;
			}
		}

		function copyWithExecCommand() {
			textarea.removeAttribute( 'hidden' );
			textarea.focus();
			textarea.select();
			document.execCommand( 'copy' );
			textarea.setAttribute( 'hidden', 'hidden' );
			showCopied();
		}

		button.addEventListener( 'click', function () {
			if ( navigator.clipboard && navigator.clipboard.writeText ) {
				navigator.clipboard.writeText( textarea.value ).then( showCopied, copyWithExecCommand );
			} else {
				copyWithExecCommand();
			}
		} );
	} );
} )();
