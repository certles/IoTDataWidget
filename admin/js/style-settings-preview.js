/**
 * Podgląd na żywo ustawień wyglądu (v1.5 Tydzień 5) — bez żadnego AJAX/REST,
 * po prostu ustawia te same zmienne CSS (--iot-dw-*) bezpośrednio na
 * przykładowym widgecie wyrenderowanym po stronie PHP (patrz
 * admin/views/style-settings-page.php), tak jak zrobiłby to inline
 * `style=""` z override per widget (IOTDW_Renderer::style_attr()) —
 * ten sam mechanizm, tylko sterowany z JS zamiast z shortcode'u.
 *
 * v1.7 Tydzień 8 (a11y): dokłada do tego samego listenera "input" żywe
 * ostrzeżenie o kontraście — liczone tym samym algorytmem WCAG co
 * docs/archiwum/audyt-a11y-v1-7.md, NIE blokujące zapisu (to strona użytkownika,
 * jego decyzja — patrz Ustalenie #5 w docs/archiwum/mvp-v1-7-plan.md).
 */
( function () {
	'use strict';

	/**
	 * @param {number} c 0-255.
	 * @return {number}
	 */
	function srgbToLinear( c ) {
		c = c / 255;
		return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
	}

	/**
	 * @param {string} hex "#rrggbb".
	 * @return {{r: number, g: number, b: number}|null}
	 */
	function hexToRgb( hex ) {
		var match = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec( hex );
		return match
			? { r: parseInt( match[1], 16 ), g: parseInt( match[2], 16 ), b: parseInt( match[3], 16 ) }
			: null;
	}

	/**
	 * @param {string} hex "#rrggbb".
	 * @return {number|null}
	 */
	function relativeLuminance( hex ) {
		var rgb = hexToRgb( hex );

		if ( ! rgb ) {
			return null;
		}

		return 0.2126 * srgbToLinear( rgb.r ) + 0.7152 * srgbToLinear( rgb.g ) + 0.0722 * srgbToLinear( rgb.b );
	}

	/**
	 * Kontrast WCAG między dwoma kolorami — (L_jaśniejszy+0.05)/(L_ciemniejszy+0.05).
	 *
	 * @param {string} hexA "#rrggbb".
	 * @param {string} hexB "#rrggbb".
	 * @return {number|null}
	 */
	function contrastRatio( hexA, hexB ) {
		var lumA = relativeLuminance( hexA );
		var lumB = relativeLuminance( hexB );

		if ( null === lumA || null === lumB ) {
			return null;
		}

		var lighter = Math.max( lumA, lumB );
		var darker  = Math.min( lumA, lumB );

		return ( lighter + 0.05 ) / ( darker + 0.05 );
	}

	document.addEventListener( 'DOMContentLoaded', function () {
		var preview = document.querySelector( '#iot-dw-style-preview .iot-dw-widget' );

		if ( ! preview ) {
			return;
		}

		var inputs = document.querySelectorAll( '[data-iot-dw-css-var]' );

		inputs.forEach( function ( input ) {
			var cssVar = input.getAttribute( 'data-iot-dw-css-var' );
			var unit   = input.getAttribute( 'data-iot-dw-css-unit' ) || '';

			input.addEventListener( 'input', function () {
				preview.style.setProperty( cssVar, input.value + unit );
				updateContrastWarning();
			} );
		} );

		var bgInput     = document.getElementById( 'iot-dw-style-bg-color' );
		var textInput   = document.getElementById( 'iot-dw-style-text-color' );
		var accentInput = document.getElementById( 'iot-dw-style-accent-color' );
		var warningRow  = document.getElementById( 'iot-dw-style-contrast-warning-row' );
		var warningBox  = document.getElementById( 'iot-dw-style-contrast-warning' );

		/**
		 * Tekst/tło potrzebuje 4.5:1 (zwykły tekst); akcent to element
		 * graficzny (łuk gauge/linia sparkline, nie tekst), więc 3:1 —
		 * patrz docs/archiwum/audyt-a11y-v1-7.md dla uzasadnienia progów.
		 */
		function updateContrastWarning() {
			if ( ! bgInput || ! textInput || ! warningRow || ! warningBox ) {
				return;
			}

			var messages   = [];
			var textRatio  = contrastRatio( textInput.value, bgInput.value );
			var accentRatio = accentInput ? contrastRatio( accentInput.value, bgInput.value ) : null;

			if ( null !== textRatio && textRatio < 4.5 ) {
				messages.push(
					iotdwStyleSettings.textContrastWarning.replace( '%s', textRatio.toFixed( 2 ) )
				);
			}

			if ( null !== accentRatio && accentRatio < 3.0 ) {
				messages.push(
					iotdwStyleSettings.accentContrastWarning.replace( '%s', accentRatio.toFixed( 2 ) )
				);
			}

			if ( messages.length ) {
				warningBox.innerHTML = messages.map( function ( message ) {
					return '<p>' + message + '</p>';
				} ).join( '' );
				warningRow.hidden = false;
			} else {
				warningBox.innerHTML = '';
				warningRow.hidden = true;
			}
		}

		updateContrastWarning();
	} );
} )();
