<?php

/**
 * Querybuilder is ultimately an abstracted way to define and execute named transformations on keyed arrays.
 *
 * To use Querybuilder, a child class should inherit from Querybuilder and implement each phrase method
 * needed for that particular class. For example, ESQuerybuilder implements phrases that are common to ElastiSearch  
 * queries on any document type, and ESQuerybuilder_Account implements phrases particular to 
 * Account document.
 *
 * A simple example of using querybuilder. This example applies the active phrase to a blank query, basically
 * returning a query that filters for all active accounts for a customer.
 * 
 * 	$query = Model_ESQuerybuilder_Account::instance()->buildQuery([{"name":"active"}]);
 * 	$results = Model_Index_Accounts::instance()->query($query);
 *
 *  NOTE: the customer is implicitly set within querybuilder by calling Model_Customers->discoverCustomer();
 *
 *
 * There are three types of things in the query builder: 
 *
 * The query itself - A keyed array, representing the query DSL that is being built or transformed.
 * 
 * Phrases - Instructions to the builder on how to build the query. The builder takes a list of phrases and
 * 		applies them in order to either a blank or pre-existing query. Each type of phrase has a corresponding
 * 		_phrase_**NAME** class method, which gets called whenever that phrase is applied to the query. 
 * 		For instance, if these phrases are sent to the query builder: 
 * 		
 * 			[['name'=>'active', 'phrase' => [$active_params]], 
 * 				['name'=>'highRisk', 'phrase' => [$high_risk_params]]
 *
 * 		These class methods would be called with the accompanying parameters:
 * 		_phrase_active($active_params);
 * 		_phrase_highRisk($high_risk_params);
 *
 * 		The body of each class method would either modify the query directly, or create a component to delay 
 * 		modifying the query until a later state in the query building process. 
 * 		
 *
 * Components - 
 * 
 * 		Currently there are only two types of components: filters and callbacks. 
 * 		
 * 		Filters: Instead of transforming the query directly, a phrase may instead create either a filter or callback 
 * 			component, which will then be applied to the query after all the phrases have been applied. 
 *
 * 			For instance, instead of adding filters directly to the query each time a phrase is executed, 
 * 		 	phrase methods construct the filter DSL and then add it to the list of filter components. 
 * 		  	These filter components will then all be added to the query once all the other phrases have 
 * 		   	completed. This is so that the query builder may more smarty add the filters, as the application
 * 		    of many of them changes, depending on which other filters are also being applied. 
 *
 * 			For instance, if we are adding both highRisk and mediumRisk filters. We can't just add both a high risk
 * 		 	and a medium risk filter to the query, we must add one filter that groups both high risk and low
 * 		  	risk together in its own subfilter, and we can't do that until we know what all the other 
 * 		   	phrases are that have been applied to the query.
 *
 * 		Callbacks: Callbacks register another class method to be called once all the phrases have been
 * 			applied. This is just another way of storing state and delaying application of instructions
 * 			to the query until after all the other phrases have been applied.
 *
 *
 * There is only one public method in this class: buildQuery, which you call with the starting query
 * and list of phrases to be applied to it.
 *
 */
class Querybuilder {

	/**
	 * a session variable to store any persistent state data needed by the phrase transformations,
	 * such as builder modes, global data, etc.
	 * @var array
	 */
	protected $_builder_state;

	/**
	 * the initial value for $_builder_state
	 * $_builder_state gets reset to $_initial_state whenever reset() is called
	 * @var array
	 */
	protected $_initial_state;

	/**
	 * stores the intitial input query
	 * $_query gets reset to $_initial_query whenever reset() is called
	 * @var array
	 */
	protected $_initial_query;

	/**
	 * records the state of the builder before and after each phrase is applied
	 * mainly for debugging purposes
	 * @var array
	 */
	protected $_history;

	/**
	 * the query itself as it is modified by the phrases
	 * @var array
	 */
	protected $_query;

	/**
	 * the list of phrase transforms to apply to the query
	 * @var array
	 */
	protected $_phrases;

	private static $instance;

	/**
	 * Singleton pattern
	 *
	 * @return caller instance
	 */
	public static function instance() {
		$class = get_called_class();
		if (empty(self::$instance[$class])) {
			// Create a new session instance
			self::$instance[$class] = new $class();
		}

		return self::$instance[$class];
	}

	public function __construct($initial_query=array(), $initial_state=array(), $initial_phrases=array()) {
		$this->_initial_state = $initial_state;
		$this->_initial_query = $initial_query;
		$this->_initial_phrases = $initial_phrases;

		$this->_reset();
	}

	public function buildDescription($phrases=array()){
		$description = [];

		foreach ($phrases as $phrase) {

			$name = $phrase['name'];
			$params = !empty($phrase['params']) ? $phrase['params'] : [];

			$method_name = '_description_'.$name;

			if (method_exists($this, $method_name)) {
				$description[] = $this->$method_name($params);
			} else {
				throw new Exception($name . ' filter does not have associated description in the query builder');
			}
		}
		
		return $description;
	}


	public function buildQuery($phrases=array()) {
		$this->applyPhrases($phrases, $reset=true);
		$this->applyComponents();
		$query = $this->getQuery();

		return $query;
	}

	public function applyPhrase($name, $params=array(), $reset=false) {

		$phrases = array(
						array('name'=>$name, 'params'=>$params)
					);

		return $this->applyPhrases($phrases, $reset);
	}


	/**
	 * this is the main function
	 * @param  array $phrase 	should be a list of $phrase_name=>$phrase_params pairs, in which
	 * 							$phrase_params is an associative array containing the parameters to be
	 * 							sent to the phrase method.
	 * @param  array $query 	the initial query array to be transformed
	 * @param  array $reset
	 * @return array
	 */
	public function applyPhrases($phrases, $reset=false) {

		if (!empty($reset)) {
			$this->_reset();
		}

		$this->_phrases = $phrases;
		
		while (!empty($this->_phrases)) {
			
			$phrase = array_shift($this->_phrases);
			if (empty($phrase['params'])) $phrase['params'] = array();

			if (method_exists($this, '_phrase_'.$phrase['name'])) {
				$this->_query = $this->_callPhrase($phrase['name'], $phrase);
			}
		}

		return $this->_query;
	}


	/**
	 * this is just a placeholder for inheriting classes to implement
	 * the idea for components is to store and organize the effects of phrase transformations
	 * to be applied at a later time after all the phrases have been executed
	 * for instance, for the elasticsearch class the inherits from this one, 
	 * filters and subqueries are components that we don't want to apply to the query right
	 * away but instead organize them in the builder state as the phrases apply them so 
	 * that we can organize them and add them to the query in the proper fashion once all 
	 * the phrases have been applied.
	 *
	 * we might want to flesh out this more and have an applyComponent*type* for each type of 
	 * component there is, but for right now just have a generic method that does it all
	 * @return [type] [description]
	 */
	public function applyComponents() {
	}


	public function getQuery() {
		return $this->_query;
	}

	public function getHistory() {
		return $this->_history;
	}


	protected function _reset() {
		$this->history = array();
		$this->_query = $this->_initial_query;
		$this->_builder_state = $this->_initial_state;
		$this->applyPhrases($this->_initial_phrases);

	}


	protected function _unshiftPhrases($new_phrases) {
		$this->_phrases = !empty($this->_phrases) ?
							array_merge($this->_phrases, $new_phrases) :
							$new_phrases;
	}


	protected function _pushPhrases($new_phrases) {
		$this->_phrases = !empty($this->_phrases) ?
							array_merge($new_phrases, $this->_phrases) :
							$new_phrases;
	}


	/**
	 * call the phrase method that matches $phrase_name
	 * the phrase method should take the $query and modify it according to the supplied $phrase_params
	 * any functionality that should happen for all phrase calls should go here
	 * @param  [type] $query
	 * @param  [type] $phrase_name
	 * @param  [type] $phrase_params
	 * @return [type]
	 */
	protected function _callPhrase($phrase_name, $phrase_params) {

		$input_query = $this->_query;

		$method_name = '_phrase_'.$phrase_name;
		// print"<pre>";print_r($method_name);
		$success =  $this->$method_name($phrase_params);

		// @TODO - do something if phrase transform was not successful

		$additional_history = ['phrase_name' => $phrase_name,
									'phrase_params' => $phrase_params,
									'input_query' => $input_query];
		$this->_recordHistory($additional_history);

		return $this->_query;
	}


	protected function _recordHistory($additional_history=array()) {
		$history = 	['query' => $this->_query,
						'builder_state' => $this->_builder_state];
		$this->_history[] = array_merge($history, $additional_history);
	}

}