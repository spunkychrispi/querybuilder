<?php 

/*
 	Implements methods for query builder phrases that are common to all Elasticsearch document types.
 	ESQuerybuilder_Account implements phrase methods particular to the Account document type.

 	Refer to the Querybuilder class for documentation on Querybuilder itself. 
 */

class ESQuerybuilder extends Querybuilder {

	protected $_date_format = 'Y-m-d';

	public function __construct($initial_query=array(),$initial_state=array(),$initial_phrases=array()) {

		foreach (array('filter', 'query') as $key) {
			if (empty($initial_state[$key])) {
				$initial_state[$key] = array();
			}
		}

		parent::__construct($initial_query=array(), $initial_state, $initial_phrases);
	}

	protected function _fetchDate($formatted_date) {
		$return_date = !empty($this->_builder_stats['date_offset']) ? 
							date_format($this->_date_format, strtotime($this->_builder_state['date_offset'], strtotime($formatted_date))) :
							$formatted_date;

		return $return_date;
	}

	protected function _parseRangeParams($params) {

		if (isset($params['min'])) {
			$params['gte'] = $params['min'];
		}
		if (isset($params['max'])) {
			$params['lte'] = $params['max'];
		}

		$filter_array = array('gte' => 1,
					'gt' => 1,
					'lte' => 1,
					'lt');

		return array_intersect_key($params, $filter_array);
	}


	protected function _setFilter($name, $body, $config) {
		$config['name'] = $name;

		$id = !empty($config['id']) ? $config['id'] : $name;

		$props = ['name' => $name,
					'body' => $body,
					'config' => $config];

		return $this->_setComponent('filter', $id, $props);
	}


	protected function _getFilter($id) {
		return  $this->_getComponent('filter', $id);
	}

	// callbacks are class methods called after the other components have been applied
	protected function _setCallback($name) {
		$props = [];

		return $this->_setComponent('callback', $name, $props);
	}


	protected function _setComponent($type, $id, $props) {
		$this->_builder_state[$type][$id] = $props;
	}


	protected function _getComponent($type, $id) {
		if (!empty($this->_builder_state[$type][$id])) {
			return $this->_builder_state[$type][$id];
		} else {
			return false;
		}
	}


	/**
	 * sets the sort param of the query
	 * @param  array $config	contains params['field'] and params['dir'] - $field will be sent 
	 *                       	through the fieldMapping to get the actual field name
	 * @return bool $success
	 */
	protected function _phrase_sort($config) {
		$field = $config['params']['field'];
		if (!empty($this->_field_mapping[$field])) {
			$field = $this->_field_mapping[$field];
		}

		$direction = (!empty($config['params']['dir']) && $config['params']['dir']=='asc') ?
						'asc' : 'desc';

		$this->_query['sort'][$field] = $direction;
	}


	public function applyComponents() {

		// apply filters
		if (!empty($this->_builder_state['filter'])) {

			// for right now we are just ANDing everything together
			// need to update this to use bool filter when appropriate and include or/should/not/etc
			$filters = array();
			foreach ($this->_builder_state['filter'] as $filter_id => $filter_info) {
				$filters[] = $filter_info['body'];
			}
			$this->_query['query']['filtered']['filter']['and']['filters'] = $filters;
		}

		$this->_recordHistory();

		// apply callbacks
		if (!empty($this->_builder_state['callback'])) {

			foreach ($this->_builder_state['callback'] as $callback_id => $callback_info) {
				$callback_name = '_callback_'.$callback_id;
				if (method_exists($this, $callback_name)) {
					$this->$callback_name($callback_info);
				}

				$this->_recordHistory();
			}
		}
	}
}
