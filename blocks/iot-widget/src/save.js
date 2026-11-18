/**
 * Blok jest "wrapperem" na shortcode [iotdw_widget] — zapisuje jego tekstową
 * postać do treści posta. Renderowanie na froncie robi wyłącznie PHP
 * (IOTDW_Shortcode), więc front nigdy nie ładuje reactowego kodu edytora.
 */

/**
 * Ucieka wartość atrybutu tak, żeby nie dało się wyjść poza cudzysłów
 * shortcode'u (proste zastąpienie, bo to zwykłe stringi z panelu admina).
 *
 * @param {string} value
 * @return {string}
 */
function escapeAttr( value ) {
	return String( value ).replace( /"/g, "'" );
}

/**
 * Buduje tekst shortcode'u z atrybutów bloku.
 *
 * @param {Object} attributes Atrybuty bloku.
 * @return {string}
 */
export function buildShortcode( attributes ) {
	const {
		connection, style, entity, entities, items, label, onLabel, offLabel, states, min, max, range, points,
		styleMode, bgColor, textColor, accentColor, borderRadius, fontSize,
	} = attributes;

	const parts = [ 'iotdw_widget', `style="${ escapeAttr( style ) }"` ];

	// Override wyglądu per widget (v1.5 Tydzień 6) — tylko gdy admin świadomie
	// wybrał "Dostosuj dla tego widgetu". W trybie domyślnym te atrybuty są
	// całkiem pominięte (nie wysłane jako puste), żeby
	// IOTDW_Style_Settings::sanitize_overrides() po stronie PHP potraktowało
	// to jako brak override — widget dziedziczy bieżące ustawienia globalne
	// z :root, zamiast zamrażać wartości sprzed przełączenia trybu.
	if ( 'custom' === styleMode ) {
		parts.push(
			`bg_color="${ escapeAttr( bgColor ) }"`,
			`text_color="${ escapeAttr( textColor ) }"`,
			`accent_color="${ escapeAttr( accentColor ) }"`,
			`border_radius="${ escapeAttr( borderRadius ) }"`,
			`font_size="${ escapeAttr( fontSize ) }"`
		);
	}

	// Etykiety on/off (poprawka 2026-08-28) — per widget, nie per encja, więc
	// dokładane tu, PRZED rozgałęzieniem na grid/list/pojedynczą encję;
	// IOTDW_Shortcode stosuje je jednakowo do card/list/grid. Puste = brak
	// override, PHP sam decyduje (patrz IOTDW_Shortcode::apply_boolean_label_override()).
	if ( onLabel ) {
		parts.push( `on_label="${ escapeAttr( onLabel ) }"` );
	}

	if ( offLabel ) {
		parts.push( `off_label="${ escapeAttr( offLabel ) }"` );
	}

	// Mapowanie stanów (1.0.4) — tylko gdy są reguły: istniejące bloki bez
	// reguł zapisują dokładnie ten sam shortcode co w 1.0.3 (bez
	// unieważnienia bloku w edytorze).
	if ( states ) {
		parts.push( `states="${ escapeAttr( states ) }"` );
	}

	// "grid" (v1.3 Tydzień 4) łączy pary z różnych połączeń w jednym
	// widgecie — nie ma jednego "connection" dla całego shortcode'u, każda
	// para niesie własne w atrybucie "items" (patrz IOTDW_Grid_Items).
	if ( 'grid' === style ) {
		parts.push( `items="${ escapeAttr( items ) }"` );

		return `[${ parts.join( ' ' ) }]`;
	}

	parts.push( `connection="${ escapeAttr( connection ) }"` );

	if ( 'list' === style ) {
		parts.push( `entities="${ escapeAttr( entities ) }"` );
	} else {
		parts.push( `entity="${ escapeAttr( entity ) }"` );

		if ( label ) {
			parts.push( `label="${ escapeAttr( label ) }"` );
		}

		if ( 'gauge' === style ) {
			parts.push( `min="${ escapeAttr( min ) }"`, `max="${ escapeAttr( max ) }"` );
		}

		if ( 'chart' === style || 'sparkline' === style ) {
			parts.push( `range="${ escapeAttr( range ) }"` );
		}

		if ( 'sparkline' === style ) {
			parts.push( `points="${ escapeAttr( points ) }"` );
		}
	}

	return `[${ parts.join( ' ' ) }]`;
}

export default function save( { attributes } ) {
	return buildShortcode( attributes );
}
