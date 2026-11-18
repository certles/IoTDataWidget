<?php
/**
 * Konektor kanału push (v2.0, docs/archiwum/mvp-v2-0-push-mode-plan.md) — w
 * przeciwieństwie do reszty konektorów NIGDY nie łączy się z niczym w
 * fetch_data(): urządzenie samo wysyła wartości do
 * IOTDW_Push_Ingest::handle_ingest(), ten konektor tylko czyta ostatnio
 * zapisany stan z IOTDW_Push_Channel_Manager. Ten sam ogólny wzorzec co
 * IOTDW_MQTT_Polling_Connector (dane przychodzą asynchronicznie, fetch_data()
 * czyta z magazynu, nie z sieci) — patrz komentarz tamtej klasy.
 *
 * @package IoT_Data_Widget
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class IOTDW_Push_Channel_Connector
 */
class IOTDW_Push_Channel_Connector extends IOTDW_Abstract_Connector {

	/**
	 * ID kanału (połączenia typu 'push').
	 *
	 * @var string
	 */
	private $connection_id;

	/**
	 * Whitelist encji + ostatnia wartość per kanał.
	 *
	 * @var IOTDW_Push_Channel_Manager
	 */
	private $channel_manager;

	/**
	 * Konstruktor.
	 *
	 * @param array<string, mixed> $config Konfiguracja połączenia (musi zawierać 'id').
	 */
	public function __construct( array $config ) {
		$this->connection_id   = (string) ( $config['id'] ?? '' );
		$this->channel_manager = new IOTDW_Push_Channel_Manager();
	}

	/**
	 * Czyta ostatnio odebraną wartość encji z tabeli stanu kanału. Nigdy nie
	 * wykonuje żadnego żądania sieciowego — patrz komentarz klasy.
	 *
	 * @param string $entity_id Nazwa encji (patrz IOTDW_Push_Ingest — ten sam ciąg, którym urządzenie identyfikuje odczyt).
	 * @return array{value: mixed, unit: string|null, label: string|null, last_updated: string|null, error: string|null, display_value: string|null}
	 */
	public function fetch_data( string $entity_id ): array {
		if ( '' === $this->connection_id ) {
			return $this->error_response(
				__( 'Push channel connector is missing its connection ID.', 'iot-data-widget' )
			);
		}

		$state = $this->channel_manager->get_entity_state( $this->connection_id, $entity_id );

		if ( null === $state ) {
			return $this->error_response(
				sprintf(
					/* translators: %s: entity name */
					__( '"%s" is not a declared entity for this push channel — check the channel\'s entity list.', 'iot-data-widget' ),
					$entity_id
				)
			);
		}

		if ( null === $state['last_recorded_at'] ) {
			return $this->error_response(
				__( 'No data received yet for this entity — make sure the device is configured and has sent at least one reading.', 'iot-data-widget' )
			);
		}

		return array(
			'value'         => $state['last_value_num'],
			'unit'          => $state['unit'],
			'label'         => $entity_id,
			'last_updated'  => $state['last_recorded_at'],
			'error'         => null,
			'display_value' => $state['last_value_txt'],
		);
	}

	/**
	 * Zwraca zadeklarowane encje kanału (whitelist) — do autouzupełniania w
	 * edytorze bloku, ten sam mechanizm co IOTDW_HA_Connector::list_entities()/
	 * IOTDW_REST_Connector::list_entities() dla presetów. W przeciwieństwie
	 * do HA (interfejs IOTDW_Listable_Connector_Interface, wymaga
	 * odświeżenia z API) whitelist jest już lokalna — bez żadnego żądania.
	 *
	 * 'value_type' (poprawka — blok Gutenberga nie blokował stylów
	 * gauge/chart/sparkline dla encji push o wartości tekstowej, bo ta metoda
	 * w ogóle nie wystawiała 'value_type', patrz isLogicalEntity() w edit.js):
	 * 'text', jeśli ostatnio odebrana wartość nie była liczbą (last_value_txt
	 * ustawione — patrz IOTDW_Push_Ingest::process_reading(), dotyczy zarówno
	 * rozpoznanych stanów on/off jak i dowolnego innego tekstu), w
	 * przeciwnym razie 'numeric' — łącznie z encją, która nie odebrała
	 * jeszcze żadnej wartości (last_recorded_at === null): nie ma podstaw,
	 * by cokolwiek blokować, dopóki nie przyjdzie pierwszy odczyt.
	 *
	 * @return array<int, array{id: string, label: string, value_type: string}>
	 */
	public function list_entities(): array {
		if ( '' === $this->connection_id ) {
			return array();
		}

		return array_map(
			static function ( $entity ) {
				return array(
					'id'         => $entity['entity_key'],
					'label'      => $entity['entity_key'],
					'value_type' => null !== $entity['last_value_txt'] ? 'text' : 'numeric',
				);
			},
			$this->channel_manager->get_entities_for_channel( $this->connection_id )
		);
	}
}
