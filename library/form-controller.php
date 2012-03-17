<?php

namespace Bundles\Controller;
use Exception;
use e;

/**
 * Form exception
 * @author Kelly Becker
 */
class FormException extends Exception {

	private $field;
	private $value;

	public function __construct($message, $field, $value = null, $code = null, $previous = null) {
		parent::__construct($message, $code, $previous);
		$this->field = $field;
		$this->value = $value;
	}

	public function getField() {
		return $this->field;
	}

	public function getValue() {
		return $this->value;
	}

}

/**
 * FormController
 */
abstract class FormController {

	/**
	 * The data variable that will allow you to save any data in the session.
	 *
	 * @var string
	 **/
	public $data = array();

	protected $class;

	public function __construct() {
		$this->class = strtolower(substr(get_class($this),strrpos(get_class($this),'\\')+1));
	}

	/**
	 * Load stored session data for this controller.
	 *
	 * @author Kelly Lauren Summer Becker
	 * @author David Boskovic
	 */
	final public function __initControllerPattern() {
		if(isset(e::$session->_data["form_controller"][$this->class]))
			$this->data = e::$session->_data["form_controller"][$this->class];
	}

	/**
	 * This will execute at the end of routing and save the data to the session.
	 *
	 * @author David Boskovic
	 **/
	final public function __shutdownControllerPattern() {
		//e::$session->_data["FormController"][$this->class] = 'test';
		e::$session->data('set',"form_controller", array($this->class => $this->data));
		e::$session->save();
	}

	final public function __redirect($url) {
		if(method_exists($this, '__shutdownController')) $this->__shutdownController();
		if(method_exists($this, '__shutdownControllerPattern')) $this->__shutdownControllerPattern();
		e\redirect($url);
	}


}