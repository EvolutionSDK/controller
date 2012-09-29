<?php

namespace Bundles\Controller;
use Exception;
use Countable;
use Iterator;
use e;

/**
 * FormController
 */
abstract class ApiController {
	
	/**
	 * Default method to run
	 * @todo Do we use this - Kelly
	 */
	protected $defaultMethod;

	/**
	 * Determine if the Controller has been initialized
	 * @todo Do we use this - Kelly
	 */
	public $__initialize = false;

	/**
	 * Has a post method been run
	 * Prevents default post method from running
	 */
	private $_postMethod = false;

	/**
	 * Access level the current user has
	 */
	private $_accessLevel = null;

	/**
	 * Response code/message to return
	 */
	protected $_reponseCode = null;
	protected $_reponseMessage = null;
	protected $_reqsFailed = false;
	protected $_viaRouting = false;

	final public function __phpAccess($args) {
		if($this->_reqsFailed instanceof Exception)
			throw $this->_reqsFailed;

		return $this;
	}

	final public function __routeController($method, $args) {

		/**
		 * API Authentication
		 * Returns: accessLevel for token used
		 */
		$this->_accessLevel = e::APIAuth("Momentum API");
		$this->_viaRouting = true;

		try {

			if($this->_reqsFailed instanceof Exception)
				throw $this->_reqsFailed;

			/**
			 * Convert dashes
			 */
			$method = str_replace('-', '_', $method);

			/**
			 * If the default method is requested
			 * Use the current object
			 */
			if($method === 'index' && !isset($result))
				$result = $this;
			
			/**
			 * If the method requested does not exists throw an error
			 */
			else if((!method_exists($this, $method) && !isset($result))
				|| ($this->model->id == null && $this instanceof APIModel)
				|| (is_null($this->list) && $this instanceof APIList))
				throw new Exception("Invalid API Method: `$method` does not exist.", 501);

			/**
			 * Call the method requested
			 */
			else if(!isset($result)) $result = call_user_func_array(array($this, $method), $args);

			/**
			 * Handle default the default post
			 */
			$post = e::$resource->post;
			if($this->_postMethod !== true && !empty($post)) {
				if(!is_object($result) || !method_exists($result, '__save'))
					throw new Exception("Item is not something that can be saved", 412);
				$success = $result->__save($post);
				if(!$success)
					throw new Exception("Item could not be saved", 400);

				/**
				 * Allow overrides
				 * @author Nate Ferrero
				 */
				if(is_array($success) && isset($success['result']))
					$result = $success['result'];
			}
			elseif($this->_postMethod == true && empty($post))
				throw new Exception("This method expects POST Data.", 412);
		}

		/**
		 * Handle any exceptions
		 */
		catch(Exception $e) {
			$result = $this->__responseData($e->getCode(), $e->getMessage());
		}

		/**
		 * If an empty model show proper codes
		 */
		if($this instanceof APIModel && $this->model->id == null) {
			$this->responseCode = 204;
			$this->responseMessage = "No model exists with this ID. Model was most likely deleted or not created.";
		}

		/**
		 * Format the returned data into the code / response format
		 */
		if(!is_array($result) || (!isset($result['status']) && !is_array($result['status']) && !isset($result['data'])))
			$result = $this->__responseData(
				is_null($this->responseCode) ? 200 : $this->responseCode,
				is_null($this->responseMessage) ? 'The operation suceeded' : $this->responseMessage,
				$result
			);

		/**
		 * Return the results
		 */
		header("Content-Type: application/json");

		echo json_encode(e\ToArray($result));
		e\Disable_Trace();
	}

	final protected function __postMethod() {
		$this->_postMethod = true;
		$post = e::$resource->post;

		if(empty($post))
			throw new Exception("This method expects POST Data.", 412);

		return $post;
	}

	final protected function __accessCheck($level = 0) {
		if($this->accessLevel < $level)
			throw new Exception("You are not authorized to access this method.", 401);
	}

	final protected function __responseData($code, $message, $data = null) {
		/**
		 * Handle error codes
		 */
		if($code === 0) $code = 500;
		$return['status']['code'] = $code;
		header("HTTP/1.0 $code");

		if($code < 200)
			$return['status']['type'] = 'info';
		if($code >= 200 && $code < 300)
			$return['status']['type'] = 'success';
		if($code >= 300 && $code < 400)
			$return['status']['type'] = 'deprecated';
		if($code >= 400 && $code < 500)
			$return['status']['type'] = 'error';
		if($code >= 500)
			$return['status']['type'] = 'fatal-error';

		/**
		 * Add message
		 */
		$return['status']['message'] = $message;

		/**
		 * Add data to be displayed
		 */
		$return['data'] = $data;

		return $return;
	}

}

abstract class ApiModel extends ApiController {
	
	protected $model = null;

	protected $bundle = null;
	protected $method = null;
	protected $flags = array();

	protected $fields = array();
	protected $searchFields = array();

	/**
	 * Set Flags
	 * @author Nate Ferrero
	 */
	final public function __setFlags($flags) {
		$this->flags = $flags;
	}

	final public function __initControllerPattern() {

		/**
		 * Grab the function arguments and the Model ID
		 */
		$args = func_get_args();
		$id = array_shift($args);

		/**
		 * If the Bundle and Method are not set throw an exception
		 */
		if(is_null($this->bundle))
			throw new Exception('You need to set `$this->bundle` in `' . get_class($this) . '`');
		if(is_null($this->method))
			throw new Exception('You need to set `$this->method` in `' . get_class($this) . '`');

		$this->bundle = strtolower($this->bundle);
		$this->method = strtolower($this->method);

		/**
		 * Id ID is a model
		 */
		if($id instanceof \Bundles\SQL\Model)
			$this->model = $id;

		/**
		 * Else get the model normally
		 */
		else {
			/**
			 * Set the Model
			 */
			try { $this->model = e::${$this->bundle}->{'get'.$this->method}($id); }
			catch(Exception $e) {}
			
			if(!$this->model) $this->model = e::${$this->bundle}->{'new'.$this->method}($id);
			if(!($this->model instanceof \Bundles\SQL\Model))
				throw new Exception('`$this->bundle` and `$this->method` must return a instance of `Bundles\SQL\Model` in `' . get_class($this) . '`');

			/**
			 * Model prerequisites
			 * Use for things such as verifing webapp
			 */
			if(($preq = $this->__modelPreq()) instanceof Exception)
				$this->_reqsFailed = $preq;
		}

		/**
		 * Return the real method to run
		 */
		
		return $args;
	}

	final protected function _filterArray(&$array = array(), $filter = false, $args = false) {
		if(is_array($args)) {
			$filter = $this->fields;
			foreach($args as $key => $arg) {
				if(!$arg) unset($filter[$key]);
				else $filter[$key] = $arg;
			}
		}

		if(!$filter) $filter = $this->fields;

		if(count($filter) === 1 && array_pop($filter) === '*')
			return;

		$result = array();

		/**
		 * Filter and Rename
		 */
		foreach($array as $key => $val) {
			if(in_array($key, $filter) || isset($filter[$key])) {
				if(isset($filter[$key]) && strpos($filter[$key], '@') === 0)
					continue;
				if(!is_null($filter[$key]))
					$result[$filter[$key]] = $val;
				else $result[$key] = $val;
			}
		}

		/**
		 * Run Functions
		 */
		foreach($filter as $key => $val) {
			if(strpos($val, '@?') === 0) {
				$val = $this->{str_replace('@?', '_', $val)}();
				if(!is_null($val)) $result[$key] = $val;
			}
			else if(strpos($val, '@') === 0)
				$result[$key] = $this->{str_replace('@', '_', $val)}();
		}

		$array = $result;
	}

	public function __toArray() {
		if(!is_object($this->model))
			return null;
		$result = $this->model->__toArray();
		$this->_filterArray($result);
		return $result;
	}

	final public function __isset($var) {
		$array = $this->__toArray();

		/*if(method_exists($this, 'information'))
			$info = $this->information();*/

		if(array_key_exists($var, $array))
			return true;
		else if(isset($info[$var]))
			return true;

		/**
		 * Check flags
		 * @author Nate Ferrero
		 */
		else if($var[0] == 'i' && $var[1] == 's') {
			$v = strtolower(substr($var, 2));
			if(isset($this->flags[$v]))
				return true;
		}

		else return false;
	}

	final public function __get($var) {
		$array = $this->__toArray();

		/*if(method_exists($this, 'information'))
			$info = $this->information();*/

		if(array_key_exists($var, $array))
			return $array[$var];
		if(isset($info[$var]))
			return $info[$var];

		/**
		 * Check flags
		 * @author Nate Ferrero
		 */
		if($var[0] == 'i' && $var[1] == 's') {
			$v = strtolower(substr($var, 2));
			if(isset($this->flags[$v]))
				return $this->flags[$v];
		}

		return null; 
	}

	public function __modelPreq() {}

	public function __map() {
		if(is_object($this->model) && $this->model->id > 0)
			return call_user_func_array(array($this->model, '__map'), func_get_args());
		return 'unsaved-model';
	}

	/**
	 * Passthru any is functions directly to the model
	 * @author Kelly Becker
	 */
	public function __call($func, $args) {
		if(strpos($func, 'is') === 0)
			return call_user_func_array(array($this->model, $func), $args);
	}

	protected function _flags() {
		$flags = $this->model->__getFlags();
		return empty($flags) ? null : $flags;
	}

}

abstract class ApiList extends ApiController implements Iterator, Countable {
		
	protected $list = null;
	protected $input = false;

	protected $bundle = null;
	protected $method = null;
	protected $model = null;

	protected $sort = false;

	protected $listConditions = array();

	protected $cachedData = null;
	public $position = 0;

	protected $searchFields = array();

	final public function __initControllerPattern() {
		$this->listConditions = e::$resource->get;

		/**
		 * Grab the function arguments and the Model ID
		 */
		$args = func_get_args();
		//if($this->model == 'donation') dump($args);
		if($args[0] instanceof \Bundles\SQL\ListObj)
			$this->list = $args[0];

		else {

			/**
			 * If the Bundle and Method are not set throw an exception
			 */
			if(is_null($this->bundle))
				throw new Exception('You need to set `$this->bundle` in' . get_class($this));
			if(is_null($this->method))
				throw new Exception('You need to set `$this->method` in' . get_class($this));

			$this->bundle = strtolower($this->bundle);
			//$this->method = strtolower($this->method);

			/**
			 * Set the List
			 */
			if(!isset(e::${$this->bundle}))
				throw new Exception("Bundle `$this->bundle` is not installed");
			$this->list = e::${$this->bundle}->{'get'.$this->method}($id);
			if(!($this->list instanceof \Bundles\SQL\ListObj))
				throw new Exception("`e::$$this->bundle->get$this->method()` must return a instance of `Bundles\SQL\ListObj` in `" . get_class($this) . '`');
		}

		if(is_array($this->sort)) {
			$this->list->order($this->sort[0], $this->sort[1]);
		}

		/**
		 * Allow input through PHP
		 * @author Nate Ferrero
		 */
		if(count($args) && is_array($args[0])) {
			$input = array_shift($args);
		} else {
			$input = false;
		}

		$this->_filterList(false, $this->searchFields, $input);
		
		/**
		 * Return the real method to run
		 */
		return array_shift($args);
	}

	public function __call($method, $args) {
		if(method_exists($this, '_filter_'.$method)) {
			$this->_filterList(false, $this->searchFields, array('filter' => array(array('field' => $method, 'value' => $args[0], 'operator' => $args[1] ? $args[1] : '='))));
		}
		return $this;
	}

	final protected function _filterList($args = false, $searchFields = false, $input = false) {
		if(!$input && !$this->input) $input = e::$resource->get;

		if(isset($input['search-fields'])) $searchFields = json_decode($input['search-fields'], true);

		if(isset($input['page']) && isset($input['page-length']))
			$this->list->page($input['page'], $input['page-length']);
		if(isset($input['date-start']) || isset($input['date-end'])) {
			$this->_filter_daterange($input['date-start'], $input['date-end'],$input['date-field'] ? $input['date-field'] : 'created_timestamp');
		}
		if(is_array($args)) foreach($args as $key => $val) {
			if(is_numeric($key))
				$this->list->manual_condition($val);
			else $this->list->condition($key, $val);
		}
		if(isset($input['search']) && is_array($searchFields) && count($searchFields) > 0) {
			$query = array();
			foreach($searchFields as $field)
				$query[] = "`$field` LIKE '%$input[search]%'";
			$this->list->manual_condition("(".implode(' OR ', $query).")");
		}
		if(isset($input['status'])) {
			$statuses = explode(',',$input['status']);
			call_user_func_array(array($this,'has_status'), $statuses);
		}
		if(isset($input['has-tag']))
			$this->list->_->taxonomy->hasTag($input['has-tag']);
		if(isset($input['filter']) && is_array($input['filter'])) {
			foreach($input['filter'] as $filter) {
				if(!$filter['value']) continue;
				if(is_string($filter['value']))
					$filter['value'] = addslashes($filter['value']);

				if(strtoupper($filter['operator']) == 'CONTAINS') {
					$filter['operator'] = 'LIKE';
					$filter['value'] = '%'.$filter['value'].'%';
				}

				if(strtoupper($filter['operator']) == 'LIKE') {
					$filter['value'] = str_replace('*', '%', $filter['value']);
				}

				if(method_exists($this, '_filter_'.$filter['field'])) {
					call_user_func(array($this, '_filter_'.$filter['field']), $filter['value'],$filter['operator']);
				}
				else{
					$this->filter($filter['field'].' '.$filter['operator'],$filter['value']);
				}
			}
		}
	}

	public function has_tag($tag) {
		$this->list->_->taxonomy->hasTag($tag);
	}

	/**
	 * Allow custom date filtering.
	 * @todo work off the timezone settings configured in the webapp
	 */
	protected function _filter_daterange($date_start = false, $date_end = false, $field = 'created_timestamp') {
	
		$start = $date_start ? date('Y-m-d H:i:s', strtotime($date_start,time()-(7*60*60))) : false;
		$end = $date_end ? date('Y-m-d H:i:s', strtotime($date_end,time()-(7*60*60))) : false;


		if(method_exists($this, '_filter_'.$field)) {
			if($start && $end) {
				call_user_func(array($this, '_filter_'.$field), $start,'>=');
				call_user_func(array($this, '_filter_'.$field), $end,'<');
			}

			elseif($start)
				call_user_func(array($this, '_filter_'.$field), $start,'>=');

			elseif($end)
				call_user_func(array($this, '_filter_'.$field), $end,'<');
		}
		else{
			if($start && $end)
				$this->list->manual_condition("`$field` >= '$start' AND  `$field` < '$end'");

			elseif($start)
				$this->list->manual_condition("`$field` >= '$start'");

			elseif($end)
				$this->list->manual_condition("`$field` < '$end'");
		}

	}


	public function page($page = 1, $length = 10) {
		$this->list->page($page, $length);
		return $this;
	}

	public function limit($start = 0, $length = false) {
		if($length)
			$this->list->limit($start, $length);
		else
			$this->list->limit($start);
		return $this;
	}

	public function json_filter($json = false) {
		if(!$json) return $this;
		if(strpos($json,'{') !== 0)
			$json = $_REQUEST[$json];
		if(!$json) return $this;

		if(strpos($json,'{') === 0)
			$filter = json_decode($json,true);
		else {
			$bjson = urldecode(base64_decode($json));
			if(strpos($bjson,'{') === 0)
				$filter = json_decode($bjson,true);
			else {
				$filter = array();
				parse_str(urldecode($json), $filter);
			}
		}
		if(!$filter) return $this;
		$this->_filterList(false, $this->searchFields, $filter);
		return $this;
	}

	public function filter($var, $val, $operator = false) {
		$this->cachedData = null;
		$val = addslashes($val);
		$operator = $this->_text_to_operator($operator);
		$var = "`$var` $operator";
		$this->list->condition($var, $val);
		if(isset($_GET['--api-list-filter']))
			eval(d);
		return $this;
	}

	public function _text_to_operator($text) {

		if(empty($text)) return '=';

		switch($text) {
			case 'lte':
				return '<=';
			break;
			case 'lt':
				return '<';
			break;
			case 'gt':
				return '>';
			break;
			case 'gte':
				return '>=';
			break;
			case '=':
			case '!=':
			case 'LIKE':
			case 'NOT LIKE':
			case '>':
			case '>=':
			case '<=':
			case '<':
			case 'CONTAINS':
			case 'IN':
			case 'NOT IN':
				return $text;
			break;
			default:
				throw new Exception("Invalid operator.");
			break;
		}
	}

	public function order_by_information($field, $direction = 'ASC', $reset = false) {
		return $this->sort_by_information($field, $direction, $reset);
	}
	public function sort_by_information($field, $direction = 'ASC', $reset = false) {
		$direction = trim($direction);
		$direction = strtolower($direction);
		$reset = !empty($reset);
		if($direction != 'asc' && $direction != 'desc')
			throw new Exception('Sort by ASC or DESC');
		$field = trim(addslashes($field));
		$this->list->order("(SELECT `value` from `\$information ".$this->list->__getTable()."` where `owner` = `".$this->list->__getTable()."`.`id` AND `field` = '$field')", $direction, $reset);
		return $this;
	}

	public function sort($field, $direction = 'ASC', $reset = false) {
		$direction = trim($direction);
		$direction = strtolower($direction);
		if(!$direction) return $this;
		$reset = !empty($reset);
		if($direction != 'asc' && $direction != 'desc')
			throw new Exception('Sort by ASC or DESC');
		$field = trim(addslashes($field));
		$this->list->order($field, $direction, $reset);
		return $this;
	}

	/*final public function __dumpFilter() {
		return e\ToArray($this);
	}*/

	/**
	 * BEGIN ITERATOR METHODS ----------------------------------------------------------------
	 */
	
	final public function rewind() {
		if(is_null($this->cachedData))
			$this->cachedData = $this->list->all(false, true);
		$this->position = 0;
	}
	
	final public function keys() {
		if(is_null($this->cachedData))
			$this->cachedData = $this->list->all(false, true);

		return array_keys($this->cachedData);
	}

	final public function current() {
		$model = $this->cachedData[$this->position];
		if(is_null($this->model))
			throw new Exception("Model not specified on list " . get_class($this));
		if(is_object($model)) {
			$apim = e::portal('api')->controller->{$this->model}($model);
			if(method_exists($this, '_modifyModel'))
				$this->_modifyModel($apim);
			return $apim;
		}
		return null;
	}

	final public function key() {
		return $this->position;
	}

	final public function next() {
		++$this->position;
	}

	final public function valid() {
		return isset($this->cachedData[$this->position]);
	}

	final public function count() {
		if(is_null($this->cachedData))
			$this->cachedData = $this->list->all(false, true);

		return count($this->cachedData);
	}

	public function paging() {
		return $this->list->paging();
	}

	public function paging_html($getvar = 'page') {
		return $this->list->paging_html(false, $getvar);
	}

	/**
	 * END ITERATOR METHODS ----------------------------------------------------------------
	 */

	public function all() {
		$array = array();
		foreach($this as $item) {
			$array[] = $item;
		}
		return $array;
	}

	public function first() {
		if(is_null($this->cachedData))
			$this->cachedData = $this->list->all(false, true);

		$model = $this->cachedData[0];

		if(is_null($this->model))
			throw new Exception("Model not specified on list " . get_class($this));
		if(is_object($model)) {
			$apim = e::portal('api')->controller->{$this->model}($model);
			if(method_exists($this, '_modifyModel'))
				$this->_modifyModel($apim);
			return $apim;
		}
	}

	/**
	 * Return sets of objects
	 *
	 * @author Robbie Trencheny
	 *
	 */
	public function setsOf($number) {
		$all = $this->all();
		$return = array();
		$index = 0;
		foreach($all as $item) {
			if($index % $number === 0) {
				if(isset($current)) {
					$return[] = $current;
				}
				$current = array();
			}
			$current[] = $item;
			$index++;
		}
		if(isset($current)) {
			$return[] = $current;
		}
		return $return;
	}

}

/**
 * Handle delayed API values for local access for optimization.
 */
class BufferedCallback {

	/**
	 * This should be an anonymous function.
	 */
	private $callback;

	/**
	 * An array of arguments to pass to the callback when executed.
	 */
	private $args;

	/**
	 * Set the callback.
	 */
	public function setCallback($callback) {
		$args = func_get_args();
		array_shift($args);
		$this->callback = $callback;
	}

}