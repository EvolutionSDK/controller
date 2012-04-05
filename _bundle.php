<?php

namespace Bundles\Controller;
use Exception;
use stack;
use e;

/**
 * Controller Bundle
 * @author Nate Ferrero
 */
class Bundle {

	private static $controllers = array();

	public function _on_framework_loaded() {
		
		/**
		 * Add LHTML Hook :controller(path/to/controller/file)
		 * @author Nate Ferrero
		 */
		e::configure('lhtml')->activeAddKey('hook', ':controller', function($controller) {
			return e::controller($controller);
		});

		/**
		 * Add LHTML Hook :api
		 * @author Nate Ferrero
		 */
		e::configure('lhtml')->activeAddKey('hook', ':api', function() {
			return e::portal('api')->controller->APIObject;
		});

		/**
		 * Add Portal Access Hook e::portal('api')->path->to->controller->method(arg1, arg2...);
		 * @author Nate Ferrero
		 */
		e::configure('portal')->activeAddKey('hook', 'controller', function($path, $class) {
			return new ControllerAccessor($path . '/controllers', $class . '\\Controllers');
		});
	}

	/**
	 * Allow direct access
	 */
	public function __callBundle($controller) {
		$controller = '/portals/'.implode('/controllers/', explode('/', $controller, 2));
		$class = str_replace('/', '\\', $controller);
		e\VerifyClass($class);
		return new $class;
	}

	/**
	 * Route through a portal
	 * @author Nate Ferrero
	 */
	public function _on_portal_route($path, $dir) {
		/**
		 * Add the current portal
		 */
		e::configure('controller')->activeAdd('locations', $dir);

		/**
		 * Route
		 */
		$this->route($path);
	}

	/**
	 * Route on main routing
	 * @author Nate Ferrero
	 */
	public function _on_router_route($path) {
		$this->route($path);
	}

	/**
	 * Route or load a controller method
	 * @author Nate Ferrero
	 */
	public function route($path, $dirs = null) {
		
		e::configure('controller')->activeAdd('locations', e\site);
		e::configure('autoload')->activeAddKey('special', 'Bundles\\Controller\\FormController', __DIR__ . '/library/form-controller.php');
		e::configure('autoload')->activeAddKey('special', 'Bundles\\Controller\\ApiController', __DIR__ . '/library/api-controller.php');
		e::configure('autoload')->activeAddKey('special', 'Bundles\\Controller\\ApiModel', __DIR__ . '/library/api-controller.php');
		e::configure('autoload')->activeAddKey('special', 'Bundles\\Controller\\ApiList', __DIR__ . '/library/api-controller.php');
		
		// If dirs are not specified, use defaults
		if(is_null($dirs))
			$dirs = e::configure('controller')->locations;

		// Get the controller name
		$name = implode('/',$path);

		/**
		 * Check all dirs for a matching controller
		 */
		if(is_array($dirs) || $dirs instanceof \Traversable) foreach($dirs as $dir) {

			/**
			 * Look in controllers folder
			 */
			if(basename($dir) !== 'controllers')
				$dir .= '/controllers';
			
			/**
			 * Skip if missing
			 */
			if(!is_dir($dir))
				continue;

			/**
			 * If router.php exists, use it!
			 * @author Nate Ferrero
			 */
			$file = "$dir/router.php";
			if(is_file($file)) {
				$class = '\\Portals\\' . e::$portal->currentPortalName() . '\\Controllers\\Router';
				require_once($file);
				$router = new $class;
				$router->route($path);
			}

			/**
			 * Find File
			 */
			$lname = $name;
			$args = array();
			$filea = explode('/', $lname);
			$total = count($filea);
			$i = 0;
			while($i <= $total) {
				
				/**
				 * Verify that name exists
				 */
				$last = count($filea) - 1;
				if($last === -1 || $filea[$last] === '')
					break;
				
				/**
				 * File name
				 */
				$file = ($dir.'/'.($lname = implode('/', $filea)).'.php');
				if(is_file($file))
					break;

				array_unshift($args, array_pop($filea));
				$i++;
			}

			/**
			 * Skip if incorrect file
			 */
			if(!is_file($file))
				continue;

			$fname = basename($file);

			/**
			 * Trace
			 */
			e\trace(__CLASS__, "Matched controller `$fname`");

			/**
			 * Load controller if not already loaded
			 */
			if(!isset(self::$controllers[$file])) {

				/**
				 * Define controller class
				 * @author Nate Ferrero
				 */
				$class = basename(dirname(dirname($dir))) . '\\' . basename(dirname($dir)) . '\\' . basename($dir) . '\\' . implode('\\', $filea);

				/**
				 * Load controller
				 */
				e\VerifyClass($class);
				self::$controllers[$file] = new $class;

				/**
				 * Cache arguments
				 * @author Nate Ferrero
				 * @rationale Init Controller needs to get the same args as Init Controller Pattern
				 */
				$oargs = $args;

				if(method_exists(self::$controllers[$file],'__initControllerPattern'))
					$newArgs = call_user_func_array(array(self::$controllers[$file], '__initControllerPattern'), $oargs);

				if(is_array($newArgs))
					$args = $newArgs;

				if(method_exists(self::$controllers[$file],'__initController'))
					$newArgs = call_user_func_array(array(self::$controllers[$file], '__initController'), $oargs);

				if(is_array($newArgs))
					$args = $newArgs;

			}

			/**
			 * Get the method name
			 */
			$method = !empty($args) ? array_shift($args) : 'index';

			/**
		 	 * Call the appropriate controller method with the remaining path elements as arguments
			 */
			if(method_exists(self::$controllers[$file], '__routeController')) {
				$result = call_user_func_array(
					array(self::$controllers[$file], '__routeController'),
					array($method, $args)
				);
			}
			
			else {
				/**
			 	 * make sure that our controller method exists before attempting to call it
				 */
				if(!method_exists(self::$controllers[$file], $method))
					throw new Exception("Controller `$lname` exists but the method `$method` is not defined in `$file`");

				$result = call_user_func_array(
					array(self::$controllers[$file], $method),
					$args
				);
			}

			/**
			 * If the controller has a shutdown methods, run them.
			 */
			if(method_exists(self::$controllers[$file],'__shutdownController'))
				self::$controllers[$file]->__shutdownController();
			if(method_exists(self::$controllers[$file],'__shutdownControllerPattern'))
				self::$controllers[$file]->__shutdownControllerPattern();

			/**
			 * Complete the page load
			 */
			e\complete($result);
		}
	}
}

/**
 * Controller Accessor
 */
class ControllerAccessor {

	private $segCount;
	private $class;
	private $path;
	private $api;
	
	/**
	 * Save the path and class
	 */
	public function __construct($path, $class) {
		$this->api = false;
		$this->path = $path;
		$this->segCount = 0;
		$this->class = $class;
	}

	/**
	 * Continue seeking controller paths
	 */
	public function __get($segment) {
		$this->segCount = $this->segCount + 1;
		if($segment == 'APIObject' && $this->segCount === 1) {
			$this->api = true;
			return $this;
		}
		
		$this->path .= '/' . $segment;

		$this->class .= '\\' . $segment;

		return $this;

	}

	/**
	 * Load the controller with a function call
	 */
	public function __call($method, $args) {
		$this->$method;

		if(!file_exists($this->path . '.php'))
			throw new Exception("Controller `$this->path.php` does not exist");
		
		e\VerifyClass($this->class);
		$controller = new $this->class;

		if(method_exists($controller,'__initControllerPattern'))
			call_user_func_array(array($controller, '__initControllerPattern'), $args);
		if(method_exists($controller,'__initController'))
			call_user_func_array(array($controller, '__initController'), $args);
		/*if(method_exists($controller,'__retObj') && $this->api)
			return call_user_func_array(array($controller, '__retObj'), array());*/

		if(!$this->api) return $controller;
		else return $controller->__phpAccess();

	}

	/**
	 * List all available controllers
	 * @author Nate Ferrero
	 */
	public function __list() {
		$out = array();
		foreach(glob($this->path . '/*.php') as $controller) {
			$out[] = pathinfo($controller, PATHINFO_FILENAME);
		}
		return $out;
	}

}