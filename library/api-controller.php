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

	final public function __phpAccess() {
		if($this->_reqsFailed instanceof Exception)
			throw $this->_reqsFailed;

		return $this;
	}

	final public function __routeController($method, $args) {

		/**
		 * API Authentication
		 * Returns: accessLevel for token used
		 */
		$this->accessLevel = e::APIAuth("Momentum API");

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
			$post = e::$input->post;
			if($this->_postMethod !== true && !empty($post)) {
				if(!is_object($result) || !method_exists($result, '__save'))
					throw new Exception("Item is not something that can be saved", 412);
				$success = $result->__save($post);
				if(!$success)
					throw new Exception("Item could not be saved", 400);
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
		$post = e::$input->post;

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

		if(!is_numeric($id)) $id = false;

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
		 * Set the Model
		 */
		$this->model = e::${$this->bundle}->{'get'.$this->method}($id);
		if(!$this->model) $this->model = e::${$this->bundle}->{'new'.$this->method}($id);
		if(!($this->model instanceof \Bundles\SQL\Model))
			throw new Exception('`$this->bundle` and `$this->method` must return a instance of `Bundles\SQL\Model` in `' . get_class($this) . '`');

		/**
		 * Model prerequisites
		 * Use for things such as verifing webapp
		 */
		if(($preq = $this->__modelPreq()) instanceof Exception)
			$this->_reqsFailed = $preq;

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
			if(strpos($val, '@') !== 0) continue;
			$result[$key] = $this->{str_replace('@', '_', $val)}();
		}

		$array = $result;
	}

	/*final public function __dumpFilter() {
		return e\ToArray($this);
	}*/

	public function __toArray() {
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
		return $this->bundle.'.'.$this->method.':'.$this->model->id;
	}

}

abstract class ApiList extends ApiController implements Iterator, Countable {
		
	protected $list = null;
	protected $input = false;

	protected $bundle = null;
	protected $method = null;
	protected $model = null;

	protected $listConditions = array();

	protected $cachedData = null;
	protected $position = 0;

	protected $searchFields = array();

	final public function __initControllerPattern() {
		$this->listConditions = e::$input->get;

		/**
		 * Grab the function arguments and the Model ID
		 */
		$args = func_get_args();

		/**
		 * If the Bundle and Method are not set throw an exception
		 */
		if(is_null($this->bundle))
			throw new Exception('You need to set `$this->bundle` in' . get_class($this));
		if(is_null($this->method))
			throw new Exception('You need to set `$this->method` in' . get_class($this));

		$this->bundle = strtolower($this->bundle);
		$this->method = strtolower($this->method);

		/**
		 * Set the List
		 */
		$this->list = e::${$this->bundle}->{'get'.$this->method}($id);
		if(!($this->list instanceof \Bundles\SQL\ListObj))
			throw new Exception('`$this->bundle` and `$this->method` must requrn a instance of `Bundles\SQL\ListObj` in `' . get_class($this) . '`');

		$this->_filterList(false, $this->searchFields);
		
		/**
		 * Return the real method to run
		 */
		return array_shift($args);
	}

	final protected function _filterList($args = false, $searchFields = false, $input = false) {
		if(!$input && !$this->input) $input = e::$input->get;
		if(isset($input['search-fields'])) $searchFields = json_decode($input['search-fields'], true);

		if(isset($input['page']) && isset($input['page-length']))
			$this->list->page($input['page'], $input['page-length']);
		if(isset($input['date-start']) && isset($input['date-end']))
			$this->list->manual_condition("`created_timestamp` BETWEEN '".$input['date-start']."' AND '".$input['date-end']."'");
		if(is_array($args)) foreach($args as $key => $val) {
			if(is_numeric($key))
				$this->list->manual_condition($val);
			else $this->list->condition($key, $val);
		}
		if(isset($input['search']) && is_array($searchFields)) {
			foreach($searchFields as $field)
				$query .= (!empty($query) ? ' OR ' : '')."`$field` LIKE '%$input[search]%'";
			$this->list->manual_condition("($query)");
		}
		if(isset($input['has-tag']))
			$this->list->_->taxonomy->hasTag($input['has-tag']);
	}

	public function page($page = 1, $length = 10) {
		$this->list->page($page, $length);
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
			$this->cachedData = $this->list->all();
		$this->position = 0;
	}
	
	final public function keys() {
		if(is_null($this->cachedData))
			$this->cachedData = $this->list->all();

		return array_keys($this->cachedData);
	}

	final public function current() {
		$model = $this->cachedData[$this->position];
		if(is_null($this->model))
			throw new Exception("Model not specified on list " . get_class($this));
		if(is_object($model))
			return e::portal('api')->controller->{$this->model}($model->id);
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
			$this->cachedData = $this->list->all();

		return count($this->cachedData);
	}

	public function paging() {
		return $this->list->paging();
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