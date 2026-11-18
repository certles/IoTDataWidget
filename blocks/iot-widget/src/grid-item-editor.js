/**
 * Jeden wiersz repeatera stylu "grid" (v1.3 Tydzień 4) — wybór połączenia +
 * encji dla pojedynczej pary. To ten sam mechanizm co pojedynczy widget
 * (Tydzień 3: ComboboxControl z wyszukiwaniem, filtr po domenie, ręczny
 * wpis jako fallback, czytelny błąd zamiast cichego fail), tylko
 * powtórzony per wiersz — każdy wiersz ma WŁASNE połączenie, więc własną,
 * niezależną listę encji.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import { SelectControl, ComboboxControl, TextControl, Button, Spinner, Notice } from '@wordpress/components';
import apiFetch from '@wordpress/api-fetch';

export default function GridItemEditor( { connectionOptions, connection, entity, onChange, onRemove } ) {
	const [ entityOptions, setEntityOptions ] = useState( [] );
	const [ loadingEntities, setLoadingEntities ] = useState( false );
	const [ entitiesError, setEntitiesError ] = useState( '' );
	const [ domainFilter, setDomainFilter ] = useState( '' );
	const [ manualEntity, setManualEntity ] = useState( false );

	useEffect( () => {
		if ( ! connection ) {
			setEntityOptions( [] );
			setEntitiesError( '' );
			return;
		}

		setLoadingEntities( true );
		setEntitiesError( '' );

		apiFetch( { path: `/iot-data-widget/v1/connections/${ encodeURIComponent( connection ) }/entities` } )
			.then( ( result ) => setEntityOptions( Array.isArray( result ) ? result : [] ) )
			.catch( ( error ) => {
				setEntityOptions( [] );
				setEntitiesError( ( error && error.message ) || __( 'Could not load entities for this connection.', 'iot-data-widget' ) );
			} )
			.finally( () => setLoadingEntities( false ) );
	}, [ connection ] );

	useEffect( () => {
		setManualEntity( false );
		setDomainFilter( '' );
	}, [ connection ] );

	const availableDomains = [ ...new Set( entityOptions.map( ( item ) => item.domain ).filter( Boolean ) ) ].sort();
	const domainFilteredOptions = domainFilter
		? entityOptions.filter( ( item ) => item.domain === domainFilter )
		: entityOptions;
	const comboboxOptions = domainFilteredOptions.map( ( item ) => ( {
		value: item.id,
		label: `${ item.label } (${ item.id })`,
	} ) );

	return (
		<div className="iot-dw-grid-item-editor">
			<SelectControl
				label={ __( 'Connection', 'iot-data-widget' ) }
				value={ connection }
				options={ connectionOptions }
				onChange={ ( value ) => onChange( { connection: value, entity: '' } ) }
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

			{ connection && entityOptions.length > 0 && ! manualEntity ? (
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
						label={ __( 'Entity', 'iot-data-widget' ) }
						value={ entity }
						options={ comboboxOptions }
						onChange={ ( value ) => onChange( { connection, entity: value || '' } ) }
						allowReset
					/>
					<Button variant="link" onClick={ () => setManualEntity( true ) }>
						{ __( 'Enter manually instead', 'iot-data-widget' ) }
					</Button>
				</>
			) : (
				connection && (
					<>
						<TextControl
							label={ __( 'Entity', 'iot-data-widget' ) }
							value={ entity }
							onChange={ ( value ) => onChange( { connection, entity: value } ) }
						/>
						{ entityOptions.length > 0 && (
							<Button variant="link" onClick={ () => setManualEntity( false ) }>
								{ __( 'Choose from list instead', 'iot-data-widget' ) }
							</Button>
						) }
					</>
				)
			) }

			{ loadingEntities && <Spinner /> }

			<Button variant="secondary" isDestructive onClick={ onRemove }>
				{ __( 'Remove', 'iot-data-widget' ) }
			</Button>
		</div>
	);
}
