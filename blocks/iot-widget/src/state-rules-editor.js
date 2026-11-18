/**
 * Panel „Status rules” (mapowanie stanów, 1.0.4) — reguły „wartość albo
 * zakres → tekst + kolor statusu”, najwyżej 5. Zapisywane jako ten sam
 * tekst, który przyjmuje atrybut shortcode'u states= (patrz
 * IOTDW_State_Rules po stronie PHP): "warunek:tekst:kolor;…".
 */
import { __, sprintf } from '@wordpress/i18n';
import { Button, SelectControl, TextControl } from '@wordpress/components';

export const MAX_RULES = 5;

const COLOR_OPTIONS = [
	{ label: __( 'No colour', 'iot-data-widget' ), value: '' },
	{ label: __( 'Green', 'iot-data-widget' ), value: 'green' },
	{ label: __( 'Yellow', 'iot-data-widget' ), value: 'yellow' },
	{ label: __( 'Red', 'iot-data-widget' ), value: 'red' },
	{ label: __( 'Grey', 'iot-data-widget' ), value: 'grey' },
];

/**
 * Tekst atrybutu → wiersze edytora (bez walidacji — tę robi PHP przy
 * renderowaniu; edytor tylko podpowiada błędy, patrz isValidCondition()).
 *
 * @param {string} raw
 * @return {Array<{condition: string, text: string, color: string}>} Rule rows.
 */
export function parseRules( raw ) {
	return ( raw || '' )
		.split( ';' )
		.map( ( part ) => part.trim() )
		.filter( Boolean )
		.slice( 0, MAX_RULES )
		.map( ( part ) => {
			const [ condition = '', text = '', color = '' ] = part.split( ':' );
			return {
				condition: condition.trim(),
				text: text.trim(),
				color: color.trim(),
			};
		} );
}

/**
 * Wiersze → tekst atrybutu. Dwukropek i średnik w tekście zastępowane
 * (są separatorami formatu).
 *
 * @param {Array<{condition: string, text: string, color: string}>} rules
 * @return {string} Attribute text.
 */
export function serializeRules( rules ) {
	return rules
		.map( ( rule ) =>
			[
				rule.condition,
				rule.text.replace( /[:;]/g, ' ' ),
				rule.color,
			].join( ':' )
		)
		.join( ';' );
}

/**
 * Czy warunek ma poprawny zapis: liczba, "a..b", "..b", "a.." albo (1.0.6)
 * tekst stanu, np. "heat" — bez ":" i ";", które rozdzielają reguły.
 *
 * @param {string} condition
 * @return {boolean} Whether the condition is valid.
 */
export function isValidCondition( condition ) {
	const value = ( condition || '' ).replace( ',', '.' ).trim();
	const number = /^-?\d+(\.\d+)?$/;

	if ( -1 === value.indexOf( '..' ) ) {
		return (
			number.test( value ) || ( '' !== value && ! /[:;]/.test( value ) )
		);
	}

	const [ min, max ] = value.split( '..' ).map( ( part ) => part.trim() );

	if ( '' === min && '' === max ) {
		return false;
	}

	return (
		( '' === min || number.test( min ) ) &&
		( '' === max || number.test( max ) ) &&
		( '' === min || '' === max || parseFloat( min ) < parseFloat( max ) )
	);
}

export default function StateRulesEditor( { value, onChange } ) {
	const rules = parseRules( value );
	const update = ( index, changes ) => {
		const next = rules.map( ( rule, i ) =>
			i === index ? { ...rule, ...changes } : rule
		);
		onChange( serializeRules( next ) );
	};

	return (
		<div className="iotdw-state-rules">
			<p className="components-base-control__help">
				{ __(
					'Show a text and a status colour instead of the raw value. Condition: a number (e.g. 1), a range 18..24 (from 18, below 24), ..18 (below 18), 24.. (24 and above) or a state text such as heat or off. The first matching rule wins.',
					'iot-data-widget'
				) }
			</p>

			{ rules.map( ( rule, index ) => {
				const invalid =
					'' !== rule.condition &&
					! isValidCondition( rule.condition );

				return (
					<fieldset key={ index } className="iotdw-state-rules__rule">
						<legend>
							{ sprintf(
								/* translators: %d: rule number */
								__( 'Rule %d', 'iot-data-widget' ),
								index + 1
							) }
						</legend>
						<TextControl
							label={ __(
								'When the value is',
								'iot-data-widget'
							) }
							placeholder="18..24"
							value={ rule.condition }
							help={
								invalid
									? __(
											'Use a number, a range such as 18..24, ..18 or 24.., or a state text such as heat',
											'iot-data-widget'
									  )
									: undefined
							}
							onChange={ ( condition ) =>
								update( index, { condition } )
							}
						/>
						<TextControl
							label={ __(
								'Show text (optional)',
								'iot-data-widget'
							) }
							value={ rule.text }
							onChange={ ( text ) => update( index, { text } ) }
						/>
						<SelectControl
							label={ __( 'Status colour', 'iot-data-widget' ) }
							value={ rule.color }
							options={ COLOR_OPTIONS }
							onChange={ ( color ) => update( index, { color } ) }
						/>
						<Button
							variant="link"
							isDestructive
							onClick={ () =>
								onChange(
									serializeRules(
										rules.filter(
											( _rule, i ) => i !== index
										)
									)
								)
							}
						>
							{ __( 'Remove rule', 'iot-data-widget' ) }
						</Button>
					</fieldset>
				);
			} ) }

			{ rules.length < MAX_RULES && (
				<Button
					variant="secondary"
					onClick={ () =>
						onChange(
							serializeRules( [
								...rules,
								{ condition: '', text: '', color: 'green' },
							] )
						)
					}
				>
					{ __( 'Add rule', 'iot-data-widget' ) }
				</Button>
			) }
		</div>
	);
}
