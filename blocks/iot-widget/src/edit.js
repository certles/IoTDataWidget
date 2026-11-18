import { __, sprintf } from '@wordpress/i18n';
import { useCallback, useEffect, useRef, useState } from '@wordpress/element';
import { InspectorControls, useBlockProps } from '@wordpress/block-editor';
import {
	PanelBody,
	SelectControl,
	ComboboxControl,
	FormTokenField,
	TextControl,
	Button,
	ToggleControl,
	BaseControl,
	// eslint-disable-next-line camelcase
	__experimentalNumberControl as NumberControl,
	Spinner,
	Notice,
} from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';
import GridItemEditor from './grid-item-editor';
import StateRulesEditor from './state-rules-editor';

/**
 * Kontrast WCAG (v1.7 Tydzień 8, a11y) — dokładnie ta sama formuła co
 * admin/js/style-settings-preview.js (ustawienia globalne) i
 * docs/archiwum/audyt-a11y-v1-7.md, zduplikowana celowo: ten plik buduje się przez
 * @wordpress/scripts (webpack), tamten ładuje się jako zwykły, osobny
 * skrypt — nie ma tu wspólnego pipeline'u modułów do współdzielenia jednego
 * pliku bez sztucznego komplikowania obu.
 *
 * @param {number} c 0-255.
 * @return {number}
 */
function srgbToLinear( c ) {
	c = c / 255;
	return c <= 0.03928 ? c / 12.92 : Math.pow( ( c + 0.055 ) / 1.055, 2.4 );
}

/**
 * @param {string} hex "#rrggbb".
 * @return {number|null}
 */
function relativeLuminance( hex ) {
	const match = /^#?([a-f\d]{2})([a-f\d]{2})([a-f\d]{2})$/i.exec( hex );

	if ( ! match ) {
		return null;
	}

	const r = parseInt( match[ 1 ], 16 );
	const g = parseInt( match[ 2 ], 16 );
	const b = parseInt( match[ 3 ], 16 );

	return 0.2126 * srgbToLinear( r ) + 0.7152 * srgbToLinear( g ) + 0.0722 * srgbToLinear( b );
}

/**
 * @param {string} hexA "#rrggbb".
 * @param {string} hexB "#rrggbb".
 * @return {number|null}
 */
function contrastRatio( hexA, hexB ) {
	const lumA = relativeLuminance( hexA );
	const lumB = relativeLuminance( hexB );

	if ( null === lumA || null === lumB ) {
		return null;
	}

	const lighter = Math.max( lumA, lumB );
	const darker = Math.min( lumA, lumB );

	return ( lighter + 0.05 ) / ( darker + 0.05 );
}

// Style, których kod Pro nie ma w tej paczce (lista z PHP — fizyczna
// obecność klas, nie licencja; patrz IOTDW_Gutenberg_Block::localize_editor_script()),
// dostają dopisek "(Pro)" — wybór zostaje możliwy, podgląd pokazuje wtedy
// komunikat z linkiem do cennika.
const UNAVAILABLE_STYLES =
	( window.iotdwEditorData && window.iotdwEditorData.unavailableStyles ) ||
	[];

const withProLabel = ( option ) =>
	UNAVAILABLE_STYLES.includes( option.value )
		? {
				...option,
				label: sprintf(
					/* translators: %s: style name, e.g. "Chart" */
					__( '%s (Pro)', 'iot-data-widget' ),
					option.label
				),
		  }
		: option;

const STYLE_OPTIONS = [
	{ label: __( 'Card', 'iot-data-widget' ), value: 'card' },
	{ label: __( 'Status list', 'iot-data-widget' ), value: 'list' },
	{ label: __( 'Gauge', 'iot-data-widget' ), value: 'gauge' },
	{ label: __( 'Chart', 'iot-data-widget' ), value: 'chart' },
	{ label: __( 'Mini trend (sparkline)', 'iot-data-widget' ), value: 'sparkline' },
	{ label: __( 'Grid (multiple entities)', 'iot-data-widget' ), value: 'grid' },
].map( withProLabel );

// Style'y wymagające liczby ciągłej (łuk gauge/punkt na wykresie) — puste/
// mylące dla wartości logicznej (poprawka §5, docs/archiwum/mvp-v2-0-push-mode-plan.md:
// "da się wybrać wykres dla przekaźnika i dostać pusty prostokąt").
const STYLES_REQUIRING_A_CONTINUOUS_NUMBER = [ 'gauge', 'chart', 'sparkline' ];

// Ten sam słownik co IOTDW_Value_Normalizer (PHP) — HA current_state trafia
// tu w tej samej postaci co tamten normalizator dostaje z API HA.
const LOGICAL_STATES = [
	'on', 'off', 'true', 'false', 'home', 'not_home', 'open', 'closed',
	'opening', 'closing', 'locked', 'unlocked', 'detected', 'clear',
	'active', 'inactive', 'yes', 'no', 'wet', 'dry',
];

/**
 * Czy zaznaczona encja niesie wartość logiczną/tekstową (przekaźnik ON/OFF,
 * binary_sensor, dowolny tekst z kanału push, ...), nie ciągłą liczbę — na
 * podstawie tego, co edytor już wie z listy encji: 'value_type' z presetu
 * REST (Shelly/Tasmota — patrz IOTDW_REST_Connector::list_entities()), z
 * kanału push (IOTDW_Push_Channel_Connector::list_entities() — 'text', gdy
 * ostatnia odebrana wartość nie była liczbą, patrz
 * IOTDW_Push_Ingest::process_reading()) albo 'current_state' z Home
 * Assistant (patrz IOTDW_HA_Connector::list_entities()). Brak wszystkich
 * trzech (np. kanał push, zanim przyjdzie pierwsza wartość) = nie wiemy, nie
 * ograniczamy — lepiej nie zablokować czegoś poprawnego niż fałszywie ograniczyć.
 *
 * @param {{value_type?: string, current_state?: string}|undefined} entityMeta
 * @return {boolean}
 */
function isLogicalEntity( entityMeta ) {
	if ( ! entityMeta ) {
		return false;
	}

	if ( 'boolean' === entityMeta.value_type || 'text' === entityMeta.value_type ) {
		return true;
	}

	if ( entityMeta.current_state ) {
		return LOGICAL_STATES.includes( String( entityMeta.current_state ).toLowerCase().trim() );
	}

	return false;
}

/**
 * Parsuje atrybut "items" ("connection|entity,connection|entity,...") na
 * listę wierszy repeatera — lustrzane do IOTDW_Grid_Items::parse() po
 * stronie PHP (patrz includes/class-grid-items.php), tylko bez tej samej
 * walidacji, bo tu chodzi wyłącznie o wstępne wypełnienie edytora.
 *
 * @param {string} raw
 * @return {Array<{connection: string, entity: string}>}
 */
function parseGridItems( raw ) {
	if ( ! raw ) {
		return [];
	}

	return raw
		.split( ',' )
		.map( ( token ) => {
			const [ conn, ent ] = token.split( '|' );
			return { connection: ( conn || '' ).trim(), entity: ( ent || '' ).trim() };
		} )
		.filter( ( row ) => row.connection && row.entity );
}

const RANGE_OPTIONS = [
	{ label: __( 'Last hour', 'iot-data-widget' ), value: '1h' },
	{ label: __( 'Last 6 hours', 'iot-data-widget' ), value: '6h' },
	{ label: __( 'Last 24 hours', 'iot-data-widget' ), value: '24h' },
	{ label: __( 'Last 7 days', 'iot-data-widget' ), value: '7d' },
	{ label: __( 'Last 30 days', 'iot-data-widget' ), value: '30d' },
];

const PREVIEW_DEBOUNCE_MS = 400;

export default function Edit( { attributes, setAttributes, clientId } ) {
	const {
		connection, style, entity, entities, items, label, onLabel, offLabel, states, min, max, range, points,
		styleMode, bgColor, textColor, accentColor, borderRadius, fontSize,
	} = attributes;
	const wrapperRef = useRef();
	const blockProps = useBlockProps( { ref: wrapperRef } );

	// Podgląd na żywo (patrz useEffect niżej) używa dokładnie tego samego
	// HTML-a co front strony, ale zwykły wp_enqueue_style() na
	// 'enqueue_block_editor_assets' ląduje w dokumencie NADRZĘDNYM edytora,
	// nie w jego iframe'owanym canvasie — potwierdzone na żywo (v1.5
	// Tydzień 6). Wstrzykujemy więc CSS ręcznie do ownerDocument naszego
	// własnego wrappera, co poprawnie trafia do canvasu niezależnie od
	// tego, czy edytor akurat renderuje w iframe czy nie. id-guard chroni
	// przed podwójnym wstrzyknięciem, gdy na stronie jest więcej niż jeden
	// blok IoT Data Widget (współdzielą ten sam dokument/iframe).
	useEffect( () => {
		const doc = wrapperRef.current ? wrapperRef.current.ownerDocument : null;
		const data = window.iotdwEditorData || {};

		if ( ! doc || ! data.widgetStylesUrl || doc.getElementById( 'iot-dw-widget-styles-css' ) ) {
			return;
		}

		const stylesLink = doc.createElement( 'link' );
		stylesLink.id = 'iot-dw-widget-styles-css';
		stylesLink.rel = 'stylesheet';
		stylesLink.href = data.widgetStylesUrl;
		doc.head.appendChild( stylesLink );

		const themeLink = doc.createElement( 'link' );
		themeLink.id = 'iot-dw-widget-theme-css';
		themeLink.rel = 'stylesheet';
		themeLink.href = data.widgetThemeUrl;
		doc.head.appendChild( themeLink );

		if ( data.globalCssRule ) {
			const themeVars = doc.createElement( 'style' );
			themeVars.id = 'iot-dw-widget-theme-vars';
			themeVars.textContent = data.globalCssRule;
			doc.head.appendChild( themeVars );
		}
	}, [] );

	const [ connections, setConnections ] = useState( [] );
	const [ entityOptions, setEntityOptions ] = useState( [] );
	const [ loadingEntities, setLoadingEntities ] = useState( false );
	const [ entitiesError, setEntitiesError ] = useState( '' );
	// Kreator multi-entity (v1.9 Tydzień 6): gdy true, entityOptions pochodzi
	// z szybkiej listy zapisanych encji (IOTDW_Saved_Entities, zero requestu
	// do źródła danych), nie z pełnego get_entities() — pokazuje link
	// "Browse all" do dociągnięcia reszty. false = już pokazujemy pełną listę
	// (albo połączenie nie ma żadnych zapisanych encji, więc od razu
	// spadliśmy na dotychczasowe zachowanie).
	const [ showingSavedOnly, setShowingSavedOnly ] = useState( false );
	// Tylko połączenia implementujące IOTDW_Listable_Connector_Interface (na
	// razie wyłącznie HA) zwracają pole 'domain' — dla reszty (presety REST,
	// zaawansowany REST, MQTT) filtr po domenie nie ma sensu i nie jest pokazywany.
	const [ domainFilter, setDomainFilter ] = useState( '' );
	const [ preview, setPreview ] = useState( '' );
	const [ loadingPreview, setLoadingPreview ] = useState( false );
	// Presety (v1.2) dają listę metryk do wyboru, ale np. ESPHome (Tydzień 6)
	// nie ma żadnej — więc nawet gdy lista jest dostępna, użytkownik musi
	// móc ją obejść i wpisać encję/JSONPath ręcznie.
	const [ manualEntity, setManualEntity ] = useState( false );
	// Wiersze repeatera stylu "grid" (v1.3 Tydzień 4) — lokalny stan zamiast
	// bezpośredniego parsowania atrybutu przy każdym renderze, żeby świeżo
	// dodany, jeszcze pusty wiersz (bez połączenia/encji) mógł istnieć w UI,
	// mimo że taka niepełna para nie da się zakodować w atrybucie "items"
	// (parser po stronie PHP i tak by ją pominął — patrz IOTDW_Grid_Items).
	const [ gridRows, setGridRows ] = useState( () => parseGridItems( items ) );

	const addGridRow = () => setGridRows( [ ...gridRows, { connection: '', entity: '' } ] );
	const updateGridRow = ( index, patch ) =>
		setGridRows( gridRows.map( ( row, i ) => ( i === index ? { ...row, ...patch } : row ) ) );
	const removeGridRow = ( index ) => setGridRows( gridRows.filter( ( _, i ) => i !== index ) );

	// Tylko kompletne pary (połączenie + encja) trafiają do atrybutu — to on
	// jest źródłem prawdy zapisywanym w treści posta (patrz save.js).
	useEffect( () => {
		const serialized = gridRows
			.filter( ( row ) => row.connection && row.entity )
			.map( ( row ) => `${ row.connection }|${ row.entity }` )
			.join( ',' );

		if ( serialized !== items ) {
			setAttributes( { items: serialized } );
		}
	}, [ gridRows ] );

	// Lista połączeń — wystarczy raz, przy montowaniu bloku.
	useEffect( () => {
		apiFetch( { path: '/iot-data-widget/v1/connections' } )
			.then( ( result ) => setConnections( Array.isArray( result ) ? result : [] ) )
			.catch( () => setConnections( [] ) );
	}, [] );

	// Poprawka 3 (docs/archiwum/iot-data-widget-do-poprawy.md): dropdown zapisuje
	// teraz id połączenia, nie nazwę (patrz connectionOptions niżej) — zmiana
	// nazwy połączenia nie psuje już zapisanych widgetów. Treść posta
	// zapisana PRZED tą poprawką może wciąż trzymać nazwę w atrybucie
	// "connection"/w wierszach "items" — renderowanie nadal działa
	// (IOTDW_Connector_Manager::find_by_id_or_name() akceptuje oba), ale
	// dropdown w edytorze pokazywałby się jako "nie wybrano", dopóki
	// użytkownik ręcznie nie kliknie ponownie. Ten efekt po cichu
	// "dogrywa" stary zapis do id, gdy tylko lista połączeń się załaduje —
	// samonaprawiające się przy pierwszym otwarciu bloku, bez działania użytkownika.
	useEffect( () => {
		if ( 0 === connections.length ) {
			return;
		}

		const idForName = ( value ) => {
			if ( ! value || connections.some( ( item ) => item.id === value ) ) {
				return value;
			}
			const match = connections.find( ( item ) => item.name === value );
			return match ? match.id : value;
		};

		const migratedConnection = idForName( connection );
		if ( migratedConnection !== connection ) {
			setAttributes( { connection: migratedConnection } );
		}

		const migratedRows = gridRows.map( ( row ) => ( { ...row, connection: idForName( row.connection ) } ) );
		if ( migratedRows.some( ( row, i ) => row.connection !== gridRows[ i ].connection ) ) {
			setGridRows( migratedRows );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- only re-run when the connection list itself changes, not on every gridRows/connection change (that would loop against the migration this effect performs).
	}, [ connections ] );

	// Pełna lista encji (autouzupełnianie) ze źródła danych — dotychczasowe
	// zachowanie (v1.3 Tydzień 3), teraz wywoływane albo automatycznie (gdy
	// połączenie nie ma żadnych zapisanych encji — patrz efekt niżej), albo
	// na żądanie przez "Browse all" (kreator multi-entity, v1.9 Tydzień 6).
	// Błąd (np. HA nieosiągalne, wygasły token — IOTDW_Rest_Api::get_entities()
	// zwraca wtedy 502) musi zostać pokazany wprost, nie po cichu zamieniony na
	// pustą listę — inaczej użytkownik widzi tylko milczące pole ręcznego wpisu
	// bez żadnej wskazówki, że coś w ogóle poszło nie tak.
	const fetchAllEntities = useCallback( ( forConnection ) => {
		setLoadingEntities( true );
		setEntitiesError( '' );

		apiFetch( { path: `/iot-data-widget/v1/connections/${ encodeURIComponent( forConnection ) }/entities` } )
			.then( ( result ) => {
				setEntityOptions( Array.isArray( result ) ? result : [] );
				setShowingSavedOnly( false );
			} )
			.catch( ( error ) => {
				setEntityOptions( [] );
				setShowingSavedOnly( false );
				setEntitiesError( ( error && error.message ) || __( 'Could not load entities for this connection.', 'iot-data-widget' ) );
			} )
			.finally( () => setLoadingEntities( false ) );
	}, [] );

	// Encje zapisane wcześniej dla tego połączenia (kreator, Krok 3) — szybki
	// wybór bez requestu do źródła danych (v1.9 Tydzień 6). Gdy połączenie
	// nie ma żadnych (nigdy nie przeszło przez kreator, albo dodane spoza
	// niego), od razu spadamy na pełny fetch — zero regresji względem
	// dotychczasowego zachowania dla takich połączeń.
	useEffect( () => {
		if ( ! connection ) {
			setEntityOptions( [] );
			setEntitiesError( '' );
			setShowingSavedOnly( false );
			return;
		}

		setLoadingEntities( true );
		setEntitiesError( '' );

		apiFetch( { path: `/iot-data-widget/v1/connections/${ encodeURIComponent( connection ) }/saved-entities` } )
			.then( ( saved ) => {
				if ( Array.isArray( saved ) && saved.length > 0 ) {
					setEntityOptions( saved );
					setShowingSavedOnly( true );
					setLoadingEntities( false );
					return;
				}

				fetchAllEntities( connection );
			} )
			.catch( () => fetchAllEntities( connection ) );
	}, [ connection, fetchAllEntities ] );

	// Nowe połączenie = nowa (albo brak) listy metryk, więc wracamy do trybu
	// listy i czyścimy filtr domeny — inaczej przełączenie np. z presetu na
	// tryb zaawansowany zostawiłoby użytkownika w ukrytym trybie ręcznym bez
	// wyjścia, albo z filtrem domeny, która nic już nie pasuje.
	useEffect( () => {
		setManualEntity( false );
		setDomainFilter( '' );
	}, [ connection ] );

	// Poprawka §5 (docs/archiwum/mvp-v2-0-push-mode-plan.md) — encja o wartości
	// logicznej (przekaźnik ON/OFF, binary_sensor...) w stylu gauge/chart/
	// sparkline dawała wcześniej pusty/mylący widget (arc/wykres liczony z
	// wartości znormalizowanej do 0/1, nie z realnej, ciągłej wielkości).
	// Auto-przełączenie na "list" zamiast tylko ostrzeżenia — użytkownik
	// mógł wybrać encję PO wybraniu stylu, więc samo ostrzeżenie zostawiłoby
	// zepsuty widget widoczny w podglądzie, dopóki nie zauważyłby komunikatu.
	useEffect( () => {
		const selectedEntityMeta = entityOptions.find( ( item ) => item.id === entity );

		if ( isLogicalEntity( selectedEntityMeta ) && STYLES_REQUIRING_A_CONTINUOUS_NUMBER.includes( style ) ) {
			setAttributes( { style: 'list' } );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps -- only re-run when the entity (or its known list) changes, not on every style change (that would fight the very switch this effect performs).
	}, [ entity, entityOptions ] );

	// Podgląd na żywo — to dokładnie to samo renderowanie co na froncie strony
	// (PHP: IOTDW_Shortcode::render()), więc nie duplikujemy logiki w JS.
	// Styl "grid" (v1.3 Tydzień 4) nie ma jednego "connection" dla całego
	// widgetu — warunek na wystarczające dane jest inny niż dla reszty stylów.
	useEffect( () => {
		// Override wyglądu (v1.5 Tydzień 6) trafia do podglądu tylko w trybie
		// "custom" — inaczej podgląd pokazywałby "override" nawet w trybie
		// domyślnym, mimo że zapisany shortcode (patrz save.js) w ogóle nie
		// wysłałby tych atrybutów.
		const appearanceOverride =
			'custom' === styleMode
				? {
						bg_color: bgColor,
						text_color: textColor,
						accent_color: accentColor,
						border_radius: borderRadius,
						font_size: fontSize,
				  }
				: {};

		const previewData =
			'grid' === style
				? ( items ? { style, items, states, ...appearanceOverride } : null )
				: connection && ( 'list' === style ? entities : entity )
					? { connection, style, entity, entities, label, on_label: onLabel, off_label: offLabel, states, min, max, range, points, ...appearanceOverride }
					: null;

		if ( ! previewData ) {
			setPreview( '' );
			return;
		}

		setLoadingPreview( true );

		const timeout = setTimeout( () => {
			apiFetch( {
				path: '/iot-data-widget/v1/preview',
				method: 'POST',
				data: previewData,
			} )
				.then( ( result ) => setPreview( result && result.html ? result.html : '' ) )
				.catch( () => setPreview( '' ) )
				.finally( () => setLoadingPreview( false ) );
		}, PREVIEW_DEBOUNCE_MS );

		return () => clearTimeout( timeout );
	}, [ connection, style, entity, entities, items, label, onLabel, offLabel, states, min, max, range, points, styleMode, bgColor, textColor, accentColor, borderRadius, fontSize ] );

	// Wykres opiera się na Chart.js + danych wstrzykniętych przez
	// wp_localize_script() na wp_footer (patrz IOTDW_Shortcode) — żadne z
	// tego nie odpala się w tym izolowanym żądaniu REST do podglądu, więc
	// zwrócony HTML to zawsze pusty <canvas>. Zamiast mylącego pustego pola
	// pokazujemy podpowiedź, ale tylko gdy podgląd faktycznie się powiódł —
	// błędy (np. "Connection not found") nadal muszą być widoczne.
	const isInertChartPreview = 'chart' === style && preview.includes( 'iot-dw-widget--chart' );

	// Poprawka 3 (docs/archiwum/iot-data-widget-do-poprawy.md) — value to id połączenia,
	// nie jego nazwa: zmiana nazwy połączenia nie psuje już zapisanych
	// widgetów. Etykieta (co widzi użytkownik w dropdownie) to nadal nazwa.
	const connectionOptions = [
		{ label: __( '— select connection —', 'iot-data-widget' ), value: '' },
		...connections.map( ( item ) => ( { label: item.name, value: item.id } ) ),
	];

	// item.domain istnieje tylko dla konektorów implementujących
	// IOTDW_Listable_Connector_Interface (HA) — przy setkach encji sama
	// wyszukiwarka tekstowa w ComboboxControl to za mało (v1.3 Tydzień 3).
	const availableDomains = [ ...new Set( entityOptions.map( ( item ) => item.domain ).filter( Boolean ) ) ].sort();

	const domainFilteredOptions = domainFilter
		? entityOptions.filter( ( item ) => item.domain === domainFilter )
		: entityOptions;

	const comboboxOptions = domainFilteredOptions.map( ( item ) => ( {
		value: item.id,
		label: `${ item.label } (${ item.id })`,
	} ) );

	const entityTokenSuggestions = entityOptions.map( ( item ) => item.id );
	const entityTokens = entities
		? entities.split( ',' ).map( ( token ) => token.trim() ).filter( Boolean )
		: [];

	// Poprawka §5 (docs/archiwum/mvp-v2-0-push-mode-plan.md) — encja logiczna nie
	// pokazuje style gauge/chart/sparkline w dropdownie w ogóle (nie tylko
	// ostrzeżenie obok), żeby nie dało się jej ponownie wybrać zaraz po
	// auto-przełączeniu w efekcie wyżej.
	const selectedEntityMeta = entityOptions.find( ( item ) => item.id === entity );
	const entityIsLogical = isLogicalEntity( selectedEntityMeta );
	const styleOptions = entityIsLogical
		? STYLE_OPTIONS.filter( ( option ) => ! STYLES_REQUIRING_A_CONTINUOUS_NUMBER.includes( option.value ) )
		: STYLE_OPTIONS;

	// Poprawka 2026-08-31 ("P1#9") — per-instancję id datalisty encji, żeby
	// dwa bloki IoT Data Widget na tej samej stronie (oba w trybie ręcznego
	// wpisu) nie dzieliły tego samego <datalist id="...">, co dawałoby
	// PODPOWIEDZI JEDNEGO POŁĄCZENIA drugiemu blokowi — clientId jest z
	// definicji unikalny per instancja bloku w edytorze.
	const entitySuggestionsId = `iot-dw-entity-suggestions-${ clientId }`;

	return (
		<>
			<InspectorControls>
				<PanelBody title={ __( 'Widget settings', 'iot-data-widget' ) }>
					<SelectControl
						label={ __( 'Style', 'iot-data-widget' ) }
						value={ style }
						options={ styleOptions }
						onChange={ ( value ) => setAttributes( { style: value } ) }
					/>

					{ entityIsLogical && (
						<Notice status="info" isDismissible={ false }>
							{ __(
								'This entity holds an on/off-style value, not a continuous number — Gauge, Chart, and Mini trend aren\'t available for it. Use Status list (or Card) instead.',
								'iot-data-widget'
							) }
						</Notice>
					) }

					{ ! connections.length && (
						<Notice status="warning" isDismissible={ false }>
							{ __(
								'No connections configured yet. Add one under IoT Data Widget → Connections.',
								'iot-data-widget'
							) }
						</Notice>
					) }

					{ 'grid' === style ? (
						<>
							{ gridRows.map( ( row, index ) => (
								<GridItemEditor
									key={ index }
									connectionOptions={ connectionOptions }
									connection={ row.connection }
									entity={ row.entity }
									onChange={ ( patch ) => updateGridRow( index, patch ) }
									onRemove={ () => removeGridRow( index ) }
								/>
							) ) }
							<Button variant="secondary" onClick={ addGridRow }>
								{ __( 'Add entity', 'iot-data-widget' ) }
							</Button>
							{ ! gridRows.length && (
								<p className="iot-dw-field-help">
									{ __(
										'Add at least one entity above — each row can come from a different connection.',
										'iot-data-widget'
									) }
								</p>
							) }
						</>
					) : (
						<>
							<SelectControl
								label={ __( 'Connection', 'iot-data-widget' ) }
								value={ connection }
								options={ connectionOptions }
								onChange={ ( value ) => setAttributes( { connection: value } ) }
							/>

							{ entitiesError && (
								<Notice status="error" isDismissible={ false }>
									{ entitiesError }
									{ ' ' }
									<a href={ window.iotdwEditorData?.connectionsUrl || '#' }>
										{ __( 'Check connection settings', 'iot-data-widget' ) }
									</a>
								</Notice>
							) }

							{ 'list' === style ? (
								<FormTokenField
									label={ __( 'Entities', 'iot-data-widget' ) }
									value={ entityTokens }
									suggestions={ entityTokenSuggestions }
									onChange={ ( tokens ) => setAttributes( { entities: tokens.join( ',' ) } ) }
								/>
							) : entityOptions.length > 0 && ! manualEntity ? (
								<>
									{ availableDomains.length > 1 && (
										<SelectControl
											label={ __( 'Filter by domain', 'iot-data-widget' ) }
											value={ domainFilter }
											options={ [
												{ label: __( 'All domains', 'iot-data-widget' ), value: '' },
												...availableDomains.map( ( domain ) => ( { label: domain, value: domain } ) ),
											] }
											onChange={ setDomainFilter }
										/>
									) }
									<ComboboxControl
										label={ showingSavedOnly ? __( 'Entity (saved)', 'iot-data-widget' ) : __( 'Entity', 'iot-data-widget' ) }
										value={ entity }
										options={ comboboxOptions }
										onChange={ ( value ) => setAttributes( { entity: value || '' } ) }
										allowReset
									/>
									{ showingSavedOnly && (
										<Button variant="link" onClick={ () => fetchAllEntities( connection ) }>
											{ __( 'Browse all entities', 'iot-data-widget' ) }
										</Button>
									) }
									<Button variant="link" onClick={ () => setManualEntity( true ) }>
										{ __( 'Enter manually instead', 'iot-data-widget' ) }
									</Button>
								</>
							) : (
								<>
									<TextControl
										label={ __( 'Entity', 'iot-data-widget' ) }
										value={ entity }
										onChange={ ( value ) => setAttributes( { entity: value } ) }
										// Poprawka 2026-08-31 ("P1#9") — datalist z ZNANYMI kluczami
										// encji (te same entityOptions co ComboboxControl wyżej),
										// tylko gdy jakieś istnieją: użytkownik mógł kliknąć "Wpisz
										// ręcznie zamiast tego" mimo dostępnej listy (np. chce
										// wkleić/poprawić wartość szybciej niż przez dropdown), więc
										// nie traci podpowiedzi całkowicie. Tryb zaawansowany REST
										// (JSONPath) / ESPHome entity_in_url mają entityOptions=[] —
										// tam faktycznie nie ma czego podpowiadać (ścieżki są
										// dowolne, per konfiguracja użytkownika), więc `list`
										// wskazuje wtedy na pusty/nieistniejący datalist, co
										// przeglądarka po prostu ignoruje — zero zmiany zachowania.
										list={ entityOptions.length > 0 ? entitySuggestionsId : undefined }
									/>
									{ entityOptions.length > 0 && (
										<datalist id={ entitySuggestionsId }>
											{ entityOptions.map( ( item ) => (
												<option key={ item.id } value={ item.id }>
													{ item.label }
												</option>
											) ) }
										</datalist>
									) }
									{ entityOptions.length > 0 && (
										<Button variant="link" onClick={ () => setManualEntity( false ) }>
											{ __( 'Choose from list instead', 'iot-data-widget' ) }
										</Button>
									) }
								</>
							) }

							<p className="iot-dw-field-help">
								{ __(
									'Home Assistant: entity ID (e.g. sensor.living_room) — pick from suggestions. Generic REST: path to the value in the JSON response (e.g. data.temperature).',
									'iot-data-widget'
								) }
							</p>

							{ loadingEntities && <Spinner /> }
						</>
					) }

					{ 'list' !== style && 'grid' !== style && (
						<TextControl
							label={ __( 'Label (optional)', 'iot-data-widget' ) }
							help={ __( 'Overrides the label from the connector — useful for Generic REST, where it otherwise shows the raw JSON path.', 'iot-data-widget' ) }
							value={ label }
							onChange={ ( value ) => setAttributes( { label: value } ) }
						/>
					) }

					{ ( entityIsLogical || 'list' === style ) && 'grid' !== style && (
						<>
							<TextControl
								label={ __( 'Label for "on" (optional)', 'iot-data-widget' ) }
								placeholder={ __( 'On', 'iot-data-widget' ) }
								help={ __(
									'Shown instead of the raw on/off-style value for on/off-style entities — e.g. "Open" for a contact sensor. Leave blank for the default.',
									'iot-data-widget'
								) }
								value={ onLabel }
								onChange={ ( value ) => setAttributes( { onLabel: value } ) }
							/>
							<TextControl
								label={ __( 'Label for "off" (optional)', 'iot-data-widget' ) }
								placeholder={ __( 'Off', 'iot-data-widget' ) }
								value={ offLabel }
								onChange={ ( value ) => setAttributes( { offLabel: value } ) }
							/>
						</>
					) }

					{ [ 'card', 'list', 'gauge', 'grid' ].includes( style ) && (
						<BaseControl
							id={ `iotdw-states-${ clientId }` }
							label={ __( 'Status rules', 'iot-data-widget' ) }
						>
							<StateRulesEditor
								value={ states }
								onChange={ ( value ) => setAttributes( { states: value } ) }
							/>
						</BaseControl>
					) }

					{ 'gauge' === style && (
						<>
							<NumberControl
								label={ __( 'Minimum', 'iot-data-widget' ) }
								value={ min }
								onChange={ ( value ) => setAttributes( { min: Number( value ) } ) }
							/>
							<NumberControl
								label={ __( 'Maximum', 'iot-data-widget' ) }
								value={ max }
								onChange={ ( value ) => setAttributes( { max: Number( value ) } ) }
							/>
						</>
					) }

					{ ( 'chart' === style || 'sparkline' === style ) && (
						<SelectControl
							label={ __( 'History range', 'iot-data-widget' ) }
							value={ range }
							options={ RANGE_OPTIONS }
							onChange={ ( value ) => setAttributes( { range: value } ) }
						/>
					) }

					{ 'sparkline' === style && (
						<NumberControl
							label={ __( 'Points shown', 'iot-data-widget' ) }
							help={ __( 'Caps the sparkline to the last N points within the selected range.', 'iot-data-widget' ) }
							min={ 2 }
							value={ points }
							onChange={ ( value ) => setAttributes( { points: Number( value ) } ) }
						/>
					) }
				</PanelBody>

				<PanelBody title={ __( 'Appearance', 'iot-data-widget' ) } initialOpen={ false }>
					<ToggleControl
						label={ __( 'Customize appearance for this widget', 'iot-data-widget' ) }
						help={
							'custom' === styleMode
								? __( 'This widget ignores the global appearance settings below.', 'iot-data-widget' )
								: __( 'This widget follows the global appearance settings (IoT Data Widget → Appearance).', 'iot-data-widget' )
						}
						checked={ 'custom' === styleMode }
						onChange={ ( checked ) => setAttributes( { styleMode: checked ? 'custom' : 'default' } ) }
					/>

					{ 'custom' === styleMode && (
						<>
							<BaseControl label={ __( 'Background color', 'iot-data-widget' ) } id="iot-dw-block-bg-color">
								<input
									type="color"
									id="iot-dw-block-bg-color"
									value={ bgColor }
									onChange={ ( event ) => setAttributes( { bgColor: event.target.value } ) }
								/>
							</BaseControl>
							<BaseControl label={ __( 'Text color', 'iot-data-widget' ) } id="iot-dw-block-text-color">
								<input
									type="color"
									id="iot-dw-block-text-color"
									value={ textColor }
									onChange={ ( event ) => setAttributes( { textColor: event.target.value } ) }
								/>
							</BaseControl>
							<BaseControl label={ __( 'Accent color', 'iot-data-widget' ) } id="iot-dw-block-accent-color">
								<input
									type="color"
									id="iot-dw-block-accent-color"
									value={ accentColor }
									onChange={ ( event ) => setAttributes( { accentColor: event.target.value } ) }
								/>
							</BaseControl>
							{ ( () => {
								// v1.7 Tydzień 8 (a11y) — te same progi/wzór co
								// docs/archiwum/audyt-a11y-v1-7.md: 4.5:1 tekst/tło (tekst),
								// 3:1 akcent/tło (element graficzny gauge/sparkline).
								const textRatio = contrastRatio( textColor, bgColor );
								const accentRatio = contrastRatio( accentColor, bgColor );
								const warnings = [];

								if ( null !== textRatio && textRatio < 4.5 ) {
									warnings.push(
										sprintf(
											/* translators: %s: computed contrast ratio, e.g. "3.20" */
											__( 'Low contrast: text vs. background is %s:1 — WCAG AA recommends at least 4.5:1 for readable text.', 'iot-data-widget' ),
											textRatio.toFixed( 2 )
										)
									);
								}

								if ( null !== accentRatio && accentRatio < 3.0 ) {
									warnings.push(
										sprintf(
											/* translators: %s: computed contrast ratio, e.g. "2.10" */
											__( 'Low contrast: accent vs. background is %s:1 — WCAG AA recommends at least 3:1 for the gauge/sparkline to stay visible.', 'iot-data-widget' ),
											accentRatio.toFixed( 2 )
										)
									);
								}

								return warnings.map( ( warning ) => (
									<Notice key={ warning } status="warning" isDismissible={ false }>
										{ warning }
									</Notice>
								) );
							} )() }
							<NumberControl
								label={ __( 'Corner radius', 'iot-data-widget' ) }
								min={ 0 }
								max={ 40 }
								value={ borderRadius }
								onChange={ ( value ) => setAttributes( { borderRadius: Number( value ) } ) }
							/>
							<NumberControl
								label={ __( 'Font size', 'iot-data-widget' ) }
								min={ 10 }
								max={ 32 }
								value={ fontSize }
								onChange={ ( value ) => setAttributes( { fontSize: Number( value ) } ) }
							/>
						</>
					) }
				</PanelBody>
			</InspectorControls>

			<div { ...blockProps }>
				{ loadingPreview && <Spinner /> }
				{ isInertChartPreview ? (
					<p className="iot-dw-block-placeholder">
						{ __(
							'Chart preview isn’t available in the editor — view the published page to see it render.',
							'iot-data-widget'
						) }
					</p>
				) : preview ? (
					// eslint-disable-next-line react/no-danger
					<div dangerouslySetInnerHTML={ { __html: preview } } />
				) : (
					<p className="iot-dw-block-placeholder">
						{ __(
							'IoT Data Widget — select a connection and entity in the sidebar.',
							'iot-data-widget'
						) }
					</p>
				) }
			</div>
		</>
	);
}
