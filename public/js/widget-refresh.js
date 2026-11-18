/**
 * Hybrydowe doładowanie front-endu (v1.8 Tydzień 1-3, Ustalenie #1 planu
 * v1.8): strona renderuje się w pełni po stronie serwera — ten skrypt tylko
 * PO załadowaniu dogrywa świeższą wartość z publicznego endpointu
 * odświeżania (Tydzień 1) i podmienia ją w DOM. Wyłączony JS w przeglądarce
 * (albo błąd sieci/endpointu) zostawia po prostu to, co już wyrenderował
 * serwer — nigdy fatalnego błędu ani pustego widgetu.
 *
 * Świadomie JEDNORAZOWO przy starcie strony, nie cyklicznym pollingiem
 * (setInterval) — to hydratacja "może być świeższe niż to, co akurat
 * wyrenderował serwer/cache strony", nie żywy pulpit z ciągłym
 * odświeżaniem; plan v1.8 nigdzie nie opisuje periodycznego odpytywania.
 *
 * Widgety, które serwer wyrenderował jako błąd (styl "card"/"gauge"/
 * "sparkline" bez data-iot-dw-connection/entity, bo IOTDW_Renderer::render()
 * zamienia je w render_error() zanim atrybuty zdążą się dodać; wiersze/
 * komórki błędu w "list"/"grid" analogicznie bez atrybutów) są celowo
 * pomijane — ten skrypt ulepsza działające widgety, nie próbuje "naprawiać"
 * zepsutych po stronie klienta.
 *
 * Styl "chart" jest poza zakresem — jego "wartość" to cała historia do
 * narysowania na canvasie, nie pojedyncza liczba; endpoint z Tygodnia 1
 * zwraca tylko bieżący odczyt, więc nie ma z czego przerysować wykresu.
 *
 * v1.8 Tydzień 4: podmiana liczby jest animowana (odliczanie od starej do
 * nowej wartości), a w trakcie oczekiwania na odpowiedź endpointu wartość
 * subtelnie pulsuje (patrz [data-iot-dw-refreshing] w widget-styles.css) —
 * obie rzeczy pomijane całkowicie przy prefers-reduced-motion: reduce
 * (animateNumber() woła onFrame() raz z finalną wartością; CSS pulsowanie
 * jest opakowane w `@media (prefers-reduced-motion: no-preference)`).
 *
 * v1.8 Tydzień 8: podmiana jest OGŁASZANA czytnikom ekranu przez osobny,
 * wizualnie ukryty region `aria-live="polite"` (patrz announce()) —
 * CELOWO nie na samym widocznym znaczniku wartości. Ten drugi (widoczny)
 * znacznik jest w trakcie animacji z Tygodnia 4 mutowany ~30-60 razy w
 * ciągu 600ms (requestAnimationFrame) — aria-live wprost na nim
 * ogłaszałoby KAŻDĄ klatkę pośrednią ("5… 6.3… 11… 14.5…"), dokładnie ten
 * "zalew komunikatów", którego plan v1.8 (Tydzień 8) każe unikać. Osobny
 * region jest aktualizowany RAZ, wprost z surowych danych z odpowiedzi
 * (nie przez odczyt DOM po animacji) — niezależnie od tego, czy wizualna
 * animacja jeszcze trwa. Ogłoszenie jest też POMIJANE, gdy świeża wartość
 * jest identyczna z tym, co serwer już wyrenderował (typowy przypadek —
 * TTL cache'a jest krótki, więc "nowy" odczyt często jest tym samym, co
 * użytkownik już przeczytał) — nie ma sensu ogłaszać "bez zmian".
 *
 * Zwykły JS bez build stepu — CLAUDE.md zastrzega @wordpress/scripts build
 * tylko dla edytora bloku, nie dla frontu (patrz chart-widget.js).
 */
( function () {
	'use strict';

	/**
	 * Czas trwania animacji odliczania liczby (ms) — dość szybko, żeby nie
	 * sprawiać wrażenia zawieszonej strony, dość wolno, żeby faktycznie było
	 * widać ruch, nie tylko przeskok.
	 */
	var VALUE_TRANSITION_MS = 600;

	/**
	 * @return {boolean}
	 */
	function prefersReducedMotion() {
		return !! ( window.matchMedia && window.matchMedia( '(prefers-reduced-motion: reduce)' ).matches );
	}

	/**
	 * JS `Math.round()` zaokrągla połówki w stronę +nieskończoności
	 * (Math.round(-2.25 * 10) / 10 === -2.2), a PHP round() (tryb domyślny,
	 * PHP_ROUND_HALF_UP) zaokrągla połówki OD zera (round(-2.25, 1) === -2.3)
	 * — bez tej korekty wartości ujemne w połowie oczka wyświetlały się
	 * inaczej po odświeżeniu JS niż w pierwotnym renderze serwera (code
	 * review). `Math.abs()`+znak odtwarza zachowanie PHP dla dowolnego znaku.
	 *
	 * @param {number} value
	 * @param {number} decimals
	 * @return {number}
	 */
	function roundHalfAwayFromZero( value, decimals ) {
		var factor = Math.pow( 10, decimals );
		return ( value < 0 ? -1 : 1 ) * Math.round( Math.abs( value ) * factor ) / factor;
	}

	/**
	 * Zachowuje dotychczasową precyzję do 1 miejsca, zwiększając ją tylko
	 * wtedy, gdy zaokrąglenie zgubiłoby małą wartość niezerową. Zasada
	 * odpowiada IOTDW_Renderer::format_value() po stronie PHP.
	 *
	 * @param {*} value
	 * @return {string}
	 */
	function formatValue( value ) {
		if ( typeof value === 'number' ) {
			var precision = 1;
			var rounded = roundHalfAwayFromZero( value, precision );
			var locale = document.documentElement.lang || undefined;

			if ( value === 0 ) {
				precision = 0;
				rounded = 0;
			} else if ( rounded === 0 ) {
				for ( precision = 2; precision <= 3; precision++ ) {
					rounded = roundHalfAwayFromZero( value, precision );
					if ( rounded !== 0 ) {
						break;
					}
				}

				if ( rounded === 0 ) {
					try {
						return ( value < 0 ? '-<' : '<' ) + ( 0.001 ).toLocaleString( locale, { minimumFractionDigits: 3, maximumFractionDigits: 3 } );
					} catch ( e ) {
						return ( value < 0 ? '-<' : '<' ) + '0.001';
					}
				}
			}

			try {
				return rounded.toLocaleString( locale, {
					maximumFractionDigits: precision,
				} );
			} catch ( e ) {
				return String( rounded );
			}
		}
		return String( value );
	}

	/**
	 * Odczytuje display_value do pokazania — data-iot-dw-on-label/off-label
	 * (jeśli obecne na elemencie, patrz IOTDW_Renderer::refresh_data_attr()
	 * i IOTDW_Shortcode::apply_boolean_label_override()) wygrywają z
	 * surowym data.display_value ze świeżej odpowiedzi endpointu, dla
	 * data.value dokładnie 0 albo 1 — endpoint odświeżania nie zna
	 * atrybutów on_label/off_label shortcode'u/bloku (cache kluczowany tylko
	 * po connection|entity, dzielony między wieloma widgetami tej samej
	 * pary), więc bez tego jednorazowe doładowanie po starcie strony
	 * cofałoby dostosowaną/domyślną etykietę z powrotem do surowego tekstu
	 * konektora (np. "false"). Duplikuje logikę wyboru strony pary z PHP
	 * (IOTDW_Shortcode::apply_boolean_label_override()) tylko w tym sensie,
	 * że oba PATRZĄ na tę samą wartość 0/1 — sama treść etykiet jest już
	 * rozwiązana raz, po stronie PHP, i tu tylko odczytana.
	 *
	 * @param {Element} el
	 * @param {Object}  data
	 * @return {string|null}
	 */
	function resolveDisplayValue( el, data ) {
		var rule = matchStateRule( el, data );
		if ( rule && rule.text ) {
			return rule.text;
		}

		if ( typeof data.value === 'number' && ( data.value === 0 || data.value === 1 ) ) {
			var onLabel = el.getAttribute( 'data-iot-dw-on-label' );
			var offLabel = el.getAttribute( 'data-iot-dw-off-label' );
			if ( null !== onLabel && null !== offLabel ) {
				return data.value === 1 ? onLabel : offLabel;
			}
		}
		return data.display_value || null;
	}

	/**
	 * Mapowanie stanów (1.0.4): pierwsza reguła z data-iot-dw-states pasująca
	 * do wartości — ta sama logika co IOTDW_State_Rules::match() po stronie
	 * PHP (równość albo zakres min ≤ v < max, otwarte końce = null).
	 *
	 * @param {Element} el
	 * @param {Object}  data
	 * @return {Object|null}
	 */
	function matchStateRule( el, data ) {
		var raw = el.getAttribute( 'data-iot-dw-states' );
		var rules;

		if ( ! raw ) {
			return null;
		}

		var numeric = typeof data.value === 'number';
		var state = String(
			data.display_raw ||
				data.display_value ||
				( typeof data.value === 'string' ? data.value : '' )
		)
			.trim()
			.toLowerCase();

		try {
			rules = JSON.parse( raw );
		} catch ( e ) {
			return null;
		}

		for ( var i = 0; i < rules.length; i++ ) {
			var rule = rules[ i ];

			// Reguła tekstowa (1.0.6): surowy stan, bez rozróżniania wielkości liter.
			if ( rule.equals_text ) {
				if ( state !== '' && state === rule.equals_text ) {
					return rule;
				}
				continue;
			}

			if ( ! numeric ) {
				continue;
			}

			if ( null !== rule.equals ) {
				if ( Math.abs( data.value - rule.equals ) < 1e-9 ) {
					return rule;
				}
				continue;
			}

			if ( ( null === rule.min || data.value >= rule.min ) && ( null === rule.max || data.value < rule.max ) ) {
				return rule;
			}
		}

		return null;
	}

	/**
	 * Kropka statusu (i nazwa koloru dla czytników, gdy reguła nie ma
	 * tekstu) — ten sam znacznik co IOTDW_Renderer::state_dot_html().
	 *
	 * @param {Element}     parent
	 * @param {Object|null} rule
	 */
	function appendStateDot( parent, rule ) {
		if ( ! rule || ! rule.color ) {
			return;
		}

		var dot = document.createElement( 'span' );
		dot.className = 'iot-dw-widget__state-dot iot-dw-widget__state-dot--' + rule.color;
		dot.setAttribute( 'aria-hidden', 'true' );
		parent.appendChild( dot );

		if ( ! rule.text ) {
			var config = window.iotdwWidgetRefresh || {};
			var colors = config.statusColors || {};
			var sr = document.createElement( 'span' );
			sr.className = 'iot-dw-widget__sr-only';
			sr.appendChild( document.createTextNode( ( config.statusFormat || 'Status: %s' ).replace( '%s', colors[ rule.color ] || rule.color ) + ' ' ) );
			parent.appendChild( sr );
		}
	}

	/**
	 * Buduje tekst wartości dokładnie w tym samym formacie, w jakim
	 * wyświetla ją DOM (setValueText()/applyGaugeValue()) — display_value
	 * zamiast liczby+jednostki, gdy ustawiony. Używane W DWÓCH miejscach:
	 * do faktycznego wypełnienia widocznego znacznika ORAZ (v1.8 Tydzień 8)
	 * do zbudowania tekstu ogłoszenia i porównania go ze starą wartością —
	 * jeden format, więc porównanie tekstowe (announceIfChanged()) jest
	 * wiarygodne.
	 *
	 * @param {Element} el
	 * @param {Object}  data
	 * @return {string}
	 */
	function formatAnnouncementText( el, data ) {
		var displayValue = resolveDisplayValue( el, data );
		if ( displayValue ) {
			return displayValue;
		}
		var text = formatValue( data.value );
		if ( data.unit ) {
			text += ' ' + data.unit;
		}
		return text;
	}

	/**
	 * Odczytuje aktualnie wyświetlaną liczbę ze znacznika wartości — zawsze
	 * jego PIERWSZY węzeł tekstowy (patrz setValueText()/render_value_content()
	 * po stronie PHP: liczba/display_value zawsze jako pierwszy child, dopiero
	 * potem opcjonalny " " + <span class="unit">). Zwraca null zarówno gdy
	 * poprzedni odczyt był display_value (np. "off" — parseFloat da NaN), jak
	 * i gdy znacznik jest pusty — w obu przypadkach animateNumber() poniżej i
	 * tak pominie animację i wstawi finalną wartość wprost, co jest właściwym
	 * zachowaniem (nie ma z czego "odliczać").
	 *
	 * @param {Element} valueEl
	 * @return {number|null}
	 */
	function readCurrentNumber( valueEl ) {
		var first = valueEl.firstChild;
		// Kropka statusu (i jej opis dla czytników) stoi przed liczbą.
		while ( first && first.nodeType === Node.ELEMENT_NODE && first.className && /iot-dw-widget__(state-dot|sr-only)/.test( first.className ) ) {
			first = first.nextSibling;
		}
		if ( first && first.nodeType === Node.TEXT_NODE ) {
			var parsed = parseFloat( first.nodeValue );
			return isNaN( parsed ) ? null : parsed;
		}
		return null;
	}

	/**
	 * Animuje przejście liczby od `from` do `to`, wołając onFrame(currentValue)
	 * na każdej klatce (ease-out sześcienny — szybki start, łagodne
	 * dobiegnięcie). Pomija animację i woła onFrame(to) tylko raz, gdy: `from`
	 * nie jest liczbą (pierwsze odświeżenie po SSR z display_value, patrz
	 * readCurrentNumber()), albo gość poprosił system o mniej ruchu.
	 *
	 * @param {number|null} from
	 * @param {number}      to
	 * @param {function(number): void} onFrame
	 */
	function animateNumber( from, to, onFrame ) {
		if ( typeof from !== 'number' || isNaN( from ) || prefersReducedMotion() ) {
			onFrame( to );
			return;
		}

		var start = null;

		function step( timestamp ) {
			if ( null === start ) {
				start = timestamp;
			}

			var t = Math.min( ( timestamp - start ) / VALUE_TRANSITION_MS, 1 );
			var eased = 1 - Math.pow( 1 - t, 3 );

			onFrame( from + ( to - from ) * eased );

			if ( t < 1 ) {
				requestAnimationFrame( step );
			} else {
				onFrame( to ); // Finalna wartość dokładna, bez dryfu zmiennoprzecinkowego z ostatniej klatki.
			}
		}

		requestAnimationFrame( step );
	}

	/**
	 * Ustawia znacznik wartości na podaną (już sformatowaną) liczbę/tekst +
	 * opcjonalną jednostkę — dzielone przez wszystkie style poza gauge (który
	 * ma inną strukturę, sam <text> SVG bez osobnego <span> na jednostkę,
	 * patrz applyGaugeValue()).
	 *
	 * @param {Element} valueEl
	 * @param {string}  text
	 * @param {string}  [unit]
	 */
	function setValueText( valueEl, text, unit, rule ) {
		valueEl.textContent = '';
		appendStateDot( valueEl, rule );
		valueEl.appendChild( document.createTextNode( text ) );

		if ( unit ) {
			valueEl.appendChild( document.createTextNode( ' ' ) );
			var unitEl = document.createElement( 'span' );
			unitEl.className = 'iot-dw-widget__unit';
			unitEl.appendChild( document.createTextNode( unit ) );
			valueEl.appendChild( unitEl );
		}
	}

	/**
	 * Zwraca tekst etykiety widgetu (dla ogłoszenia — v1.8 Tydzień 8), z
	 * dowolnego stylu na raz: ".iot-dw-widget__label" (card/gauge/sparkline/
	 * grid) albo ".iot-dw-widget__list-label" (list — osobny znacznik, patrz
	 * IOTDW_Renderer). Ikona typu encji (Tydzień 6) nie ma własnego tekstu
	 * (same kształty SVG, żadnych <text>), więc nie zaśmieca wyniku.
	 *
	 * @param {Element} el
	 * @return {string}
	 */
	function getLabelText( el ) {
		var labelEl = el.querySelector( '.iot-dw-widget__label, .iot-dw-widget__list-label' );
		return labelEl ? labelEl.textContent.replace( /\s+/g, ' ' ).trim() : '';
	}

	/**
	 * Znajduje (albo tworzy, jeśli to pierwsze wywołanie dla tego widgetu)
	 * wizualnie ukryty region `aria-live="polite"` (v1.8 Tydzień 8) — patrz
	 * .iot-dw-widget__sr-only (Tydzień 7, ten sam mechanizm co ukryta
	 * tabela historii). Tworzony PUSTY, zanim jakikolwiek fetch() ruszy
	 * (patrz refreshAll()) — niektóre czytniki ekranu wymagają, żeby region
	 * `aria-live` istniał w DOM jeszcze PRZED zmianą treści, inaczej mogą
	 * przeoczyć pierwsze ogłoszenie po jego dopiero co dodaniu.
	 * `aria-atomic="true"` — ogłasza całą nową treść za każdym razem, nie
	 * próbuje "różnicować" zmiany.
	 *
	 * @param {Element} el
	 * @return {Element}
	 */
	function ensureAnnouncer( el ) {
		var announcer = el.querySelector( '.iot-dw-widget__announcer' );
		if ( announcer ) {
			return announcer;
		}

		announcer = document.createElement( 'div' );
		announcer.className = 'iot-dw-widget__sr-only iot-dw-widget__announcer';
		announcer.setAttribute( 'aria-live', 'polite' );
		announcer.setAttribute( 'aria-atomic', 'true' );
		el.appendChild( announcer );

		return announcer;
	}

	/**
	 * Ogłasza nową wartość czytnikowi ekranu (v1.8 Tydzień 8) — TYLKO gdy
	 * faktycznie różni się od tego, co było wyświetlone przed odświeżeniem
	 * (porównanie tekstowe w tym samym formacie, patrz formatAnnouncementText()).
	 * Wołane niezależnie od tego, czy widoczna animacja liczby (Tydzień 4)
	 * jeszcze trwa — ogłoszenie odzwierciedla PRAWDZIWĄ nową wartość od
	 * razu, nie czeka na dokończenie animacji (użytkownik czytnika ekranu i
	 * tak nie widzi animacji, więc synchronizacja z nią nie ma znaczenia).
	 *
	 * @param {Element} el
	 * @param {string}  oldText Tekst wartości sprzed odświeżenia (ten sam format co formatAnnouncementText()).
	 * @param {Object}  data    Odpowiedź endpointu.
	 */
	function announceIfChanged( el, oldText, data ) {
		var newText = formatAnnouncementText( el, data );

		if ( newText === oldText ) {
			return;
		}

		var label = getLabelText( el );
		var announcer = ensureAnnouncer( el );

		announcer.textContent = label ? label + ': ' + newText : newText;
	}

	/**
	 * Podmienia zawartość znacznika wartości stylu "card"/"list"/"grid" —
	 * dokładnie ta sama logika co IOTDW_Renderer::render_value_content():
	 * display_value zamiast value+unit, gdy ustawiony. Animowane odliczanie
	 * tylko gdy zarówno stara, jak i nowa wartość są liczbami (patrz
	 * animateNumber()) — display_value (np. "off"->"on") zamienia się wprost,
	 * bo tekstu nie da się "odliczyć".
	 *
	 * @param {Element} el
	 * @param {Element} valueEl
	 * @param {Object}  data
	 */
	function applyValueContent( el, valueEl, data ) {
		var oldText = valueEl.textContent.replace( /\s+/g, ' ' ).trim();
		var displayValue = resolveDisplayValue( el, data );
		var rule = matchStateRule( el, data );

		if ( displayValue ) {
			setValueText( valueEl, displayValue, null, rule );
		} else if ( typeof data.value === 'number' ) {
			var from = readCurrentNumber( valueEl );
			animateNumber( from, data.value, function ( current ) {
				setValueText( valueEl, formatValue( current ), data.unit, rule );
			} );
		} else {
			setValueText( valueEl, formatValue( data.value ), data.unit, rule );
		}

		announceIfChanged( el, oldText, data );
	}

	/**
	 * Sparkline pokazuje zawsze samą liczbę (nigdy display_value — patrz
	 * IOTDW_Renderer, komentarz przy render_value_content()); linia trendu
	 * SVG zostaje bez zmian (endpoint nie zwraca historii, tylko bieżący
	 * odczyt), animowana jest tylko liczba na górze.
	 *
	 * @param {Element} el
	 * @param {Element} valueEl
	 * @param {Object}  data
	 */
	function applySparklineValue( el, valueEl, data ) {
		var oldText = valueEl.textContent.replace( /\s+/g, ' ' ).trim();

		if ( typeof data.value !== 'number' ) {
			setValueText( valueEl, formatValue( data.value ), data.unit );
		} else {
			var from = readCurrentNumber( valueEl );
			animateNumber( from, data.value, function ( current ) {
				setValueText( valueEl, formatValue( current ), data.unit );
			} );
		}

		announceIfChanged( el, oldText, data );
	}

	/**
	 * Gauge: animuje liczbę w SVG <text> ORAZ przelicza łuk wypełnienia —
	 * sama liczba bez ruszenia łuku wyglądałaby zepsuta (wskazówka
	 * niezgodna z wyświetloną wartością). Ten sam wzór co
	 * IOTDW_Renderer::render_gauge() po stronie PHP (promień 80, min/max z
	 * data-iot-dw-min/max). Łuk NIE jest animowany tutaj przez
	 * requestAnimationFrame — .iot-dw-widget__gauge-fill ma już własne CSS
	 * `transition: stroke-dashoffset` (widget-styles.css), więc samo ustawienie
	 * finalnego stroke-dashoffset animuje go płynnie bez dodatkowego kodu;
	 * CSS transitions same z siebie nie uruchamiają się przy
	 * prefers-reduced-motion: reduce tylko jeśli tak zadeklarowano w arkuszu
	 * — tu celowo zostawione jak jest (istniejąca reguła sprzed v1.8), bo to
	 * niewielki, funkcjonalny ruch (wskazówka), nie dekoracja.
	 *
	 * @param {Element} widgetEl
	 * @param {Object}  data
	 */
	function applyGaugeValue( widgetEl, data ) {
		var valueEl = widgetEl.querySelector( '.iot-dw-widget__gauge-value' );
		var fillEl = widgetEl.querySelector( '.iot-dw-widget__gauge-fill' );
		var summaryEl = widgetEl.querySelector( '.iot-dw-widget__gauge-summary' );
		var labelEl = widgetEl.querySelector( '.iot-dw-widget__label' );

		if ( valueEl ) {
			var oldText = valueEl.textContent.replace( /\s+/g, ' ' ).trim();

			if ( typeof data.value === 'number' ) {
				var from = readCurrentNumber( valueEl );
				animateNumber( from, data.value, function ( current ) {
					valueEl.textContent = formatValue( current ) + ( data.unit ? ' ' + data.unit : '' );
				} );
			} else {
				valueEl.textContent = 'No measurement available.';
			}

			if ( summaryEl ) {
				var accessibleValue = typeof data.value === 'number'
					? formatValue( data.value ) + ( data.unit ? ' ' + data.unit : '' )
					: 'measurement unavailable';
				var label = labelEl ? labelEl.textContent.replace( /\s+/g, ' ' ).trim() : '';
				summaryEl.textContent = label ? label + ': ' + accessibleValue : accessibleValue;
			}

			if ( typeof data.value === 'number' ) {
				announceIfChanged( widgetEl, oldText, data );
			} else if ( oldText !== 'No measurement available.' ) {
				var announcer = ensureAnnouncer( widgetEl );
				announcer.textContent = summaryEl ? summaryEl.textContent : 'Measurement unavailable';
			}
		}

		if ( fillEl ) {
			var min = parseFloat( widgetEl.getAttribute( 'data-iot-dw-min' ) );
			var max = parseFloat( widgetEl.getAttribute( 'data-iot-dw-max' ) );
			var rawValue = typeof data.value === 'number' ? data.value : min;
			var clamped = Math.max( min, Math.min( max, rawValue ) );
			var percent = max > min ? ( clamped - min ) / ( max - min ) : 0;
			var circumference = Math.PI * 80;
			var offset = circumference * ( 1 - percent );

			fillEl.setAttribute( 'stroke-dasharray', typeof data.value === 'number' ? String( circumference ) : '0' );
			fillEl.setAttribute( 'stroke-dashoffset', String( offset ) );
		}

		// Linia statusu pod gauge'em (mapowanie stanów, 1.0.4) — jak
		// IOTDW_Renderer::state_line_html(): przebudowana albo usunięta.
		if ( widgetEl.hasAttribute( 'data-iot-dw-states' ) ) {
			var rule = matchStateRule( widgetEl, data );
			var line = widgetEl.querySelector( '.iot-dw-widget__state' );

			if ( ! rule ) {
				if ( line ) {
					line.parentNode.removeChild( line );
				}
				return;
			}

			if ( ! line ) {
				line = document.createElement( 'span' );
				line.className = 'iot-dw-widget__state';
				var labelEl = widgetEl.querySelector( '.iot-dw-widget__label' );
				widgetEl.insertBefore( line, labelEl ? labelEl.nextSibling : null );
			}

			line.textContent = '';
			appendStateDot( line, rule );
			line.appendChild( document.createTextNode( rule.text || '' ) );
		}
	}

	/**
	 * Rozpoznaje styl widgetu z jego własnych klas (nie z atrybutu — styl nie
	 * jest nigdzie zapisany wprost w markupie, patrz IOTDW_Renderer) i
	 * stosuje odpowiednią logikę podmiany.
	 *
	 * @param {Element} el   Element niosący data-iot-dw-connection/entity — dla card/gauge/sparkline to sam ".iot-dw-widget", dla list/grid to pojedynczy wiersz/komórka.
	 * @param {Object}  data Odpowiedź endpointu (kontrakt konektora).
	 */
	function applyToElement( el, data ) {
		// Jednostka z atrybutu unit shortcode'u/bloku (1.0.4) — endpoint jej
		// nie zna, bez tego znikała po doładowaniu (patrz refresh_data_attr()).
		var unitOverride = el.getAttribute( 'data-iot-dw-unit' );
		if ( unitOverride ) {
			data.unit = unitOverride;
		}

		if ( el.classList.contains( 'iot-dw-widget--gauge' ) ) {
			applyGaugeValue( el, data );
			return;
		}

		if ( el.classList.contains( 'iot-dw-widget--sparkline' ) ) {
			var sparklineValueEl = el.querySelector( '.iot-dw-widget__value' );
			if ( sparklineValueEl ) {
				applySparklineValue( el, sparklineValueEl, data );
			}
			return;
		}

		// Karta (".iot-dw-widget--card") albo wiersz listy/komórka siatki —
		// wszystkie trzy używają render_value_content() po stronie PHP
		// (display_value + jednostka), więc dzielą tę samą logikę tutaj.
		var valueEl = el.querySelector( '.iot-dw-widget__value, .iot-dw-widget__list-value' );
		if ( valueEl ) {
			applyValueContent( el, valueEl, data );
		}
	}

	/**
	 * Odpytuje endpoint dla jednej pary i, jeśli odpowiedź jest udana (status
	 * 200 i brak 'error' w treści), podmienia wartość. Każdy inny wynik
	 * (404 — para już nieaktualna, np. post cofnięty do szkicu; błąd sieci;
	 * błąd konektora w treści odpowiedzi) po cichu zostawia to, co już
	 * wyrenderował serwer — brak fetch() nie może nigdy pogorszyć tego, co
	 * gość już widzi. data-iot-dw-refreshing (v1.8 Tydzień 4) jest ustawiane
	 * PRZED fetch() i zdejmowane w finally(), niezależnie od wyniku — pulsowanie
	 * (patrz widget-styles.css) trwa dokładnie tak długo, jak realne
	 * oczekiwanie na odpowiedź, nie na sztywno.
	 *
	 * @param {Element} el
	 */
	function refreshOne( el ) {
		var connectionId = el.getAttribute( 'data-iot-dw-connection' );
		var entityId = el.getAttribute( 'data-iot-dw-entity' );

		var url = window.iotdwWidgetRefresh.restUrl
			+ '/widget-data?connection_id=' + encodeURIComponent( connectionId )
			+ '&entity_id=' + encodeURIComponent( entityId );

		el.setAttribute( 'data-iot-dw-refreshing', '' );

		fetch( url, { credentials: 'omit' } )
			.then( function ( response ) {
				if ( ! response.ok ) {
					return null;
				}
				return response.json();
			} )
			.then( function ( data ) {
				if ( data && ! data.error ) {
					applyToElement( el, data );
				}
			} )
			.catch( function () {
				// Cisza celowa — patrz komentarz funkcji.
			} )
			.finally( function () {
				el.removeAttribute( 'data-iot-dw-refreshing' );
			} );
	}

	function refreshAll() {
		if ( typeof window.iotdwWidgetRefresh === 'undefined' || ! window.iotdwWidgetRefresh.restUrl ) {
			return;
		}

		var elements = document.querySelectorAll( '[data-iot-dw-connection][data-iot-dw-entity]' );

		// v1.8 Tydzień 8 — regiony aria-live tworzone PUSTE dla wszystkich
		// widgetów NAJPIERW, przed jakimkolwiek fetch() — patrz komentarz w
		// ensureAnnouncer().
		elements.forEach( ensureAnnouncer );
		elements.forEach( refreshOne );
	}

	/**
	 * v1.8 Tydzień 9 — znalezione POMIAREM (Lighthouse), nie teoretycznie:
	 * odpalenie refreshAll() natychmiast po DOMContentLoaded (skrypt jest
	 * `in_footer`, więc w praktyce zwykle po prostu NATYCHMIAST — DOM jest
	 * już sparsowany, zanim ten kod w ogóle się wykona) uruchamiało od razu
	 * kilka równoległych fetch() + animacje requestAnimationFrame (Tydzień
	 * 4) DLA KAŻDEGO widgetu na stronie na raz — realnie mierzalnie
	 * zwiększało Total Blocking Time (530ms -> 1140ms na stronie testowej z
	 * 7 widgetami) i opóźniało LCP (2.1s -> 3.9s), bo ta praca konkurowała
	 * o wątek główny z krytycznym renderowaniem strony DOKŁADNIE w oknie,
	 * które LCP mierzy. requestIdleCallback (z fallbackiem na setTimeout
	 * dla Safari, które go nie ma) odkłada to na czas, gdy przeglądarka i
	 * tak nie ma nic pilniejszego do zrobienia — zgodne z tym, czym ten
	 * skrypt FAKTYCZNIE jest: opcjonalnym doładowaniem, nie czymś
	 * krytycznym dla pierwszego wrażenia (patrz Ustalenie #1 planu v1.8).
	 * timeout: 2000 gwarantuje, że nawet na stronie, która nigdy nie
	 * "odpoczywa", odświeżenie i tak w końcu ruszy, zamiast czekać w
	 * nieskończoność.
	 */
	function scheduleRefresh() {
		if ( typeof window.requestIdleCallback === 'function' ) {
			window.requestIdleCallback( refreshAll, { timeout: 2000 } );
		} else {
			setTimeout( refreshAll, 200 );
		}
	}

	if ( document.readyState === 'loading' ) {
		document.addEventListener( 'DOMContentLoaded', scheduleRefresh );
	} else {
		scheduleRefresh();
	}
} )();
