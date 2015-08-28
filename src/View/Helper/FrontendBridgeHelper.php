<?php
namespace FrontendBridge\View\Helper;

use Cake\Core\App;
use Cake\Core\Configure;
use Cake\Core\Plugin;
use Cake\Filesystem\Folder;
use Cake\Utility\Hash;
use Cake\Utility\Inflector;
use Cake\View\Helper;
use Cake\View\View;

class FrontendBridgeHelper extends Helper {

/**
 * The helpers we need
 */
	public $helpers = array('Html');

/**
 * Holds the needed JS dependencies.
 *
 * @var array
 */
	protected $_dependencies = array(
		'/frontend_bridge/js/lib/basics.js',
		'/frontend_bridge/js/lib/jinheritance.js',
		'/frontend_bridge/js/lib/publish_subscribe_broker.js',
		'/frontend_bridge/js/lib/app.js',
		'/frontend_bridge/js/lib/controller.js',
		'/frontend_bridge/js/lib/component.js',
		'/frontend_bridge/js/lib/dialog.js',
		'/frontend_bridge/js/lib/router.js',
		'/frontend_bridge/js/lib/ui_blocker.js',
		'/frontend_bridge/js/vendor/jquery.blockUI.js'
	);

/**
 * Holds the frontendData array created by the Component
 *
 * @var array
 */
	protected $_frontendData = array(
		'jsonData' => array()
	);

/**
 * Array of plugin names of the plugin js controllers which are loaded.
 *
 * @var array
 */
	protected $_pluginJsNamespaces = [];

/**
 * Initialize the helper. Needs to be called before running it.
 *
 * @param array $frontendData Data to be passed to the frontend
 * @return void
 */
	public function init($frontendData) {
		$this->_frontendData = Hash::merge(
			$this->_frontendData, $frontendData
		);
		$this->_includeAppController();
		$this->_includeComponents();
	}

/**
 * Compiles an AssetCompress-compatible list of assets to be used in asset_compress.ini
 * files as a callback method
 *
 * @return array
 */
	public static function getAssetCompressFiles() {
		$helper = new FrontendBridgeHelper(new View());
		$dependencies = $helper->compileDependencies();
		$plugins = array_map('\Cake\Utility\Inflector::underscore', Plugin::loaded());

		foreach ($dependencies as $n => $dependency) {
			$parts = explode('/', $dependency);
			if (empty($parts[0]) && in_array($parts[1], $plugins)) {
				$prefix = '/' . $parts[1] . '/';
				$dependency = preg_replace('/^' . str_replace('/', '\/', $prefix) . '/', 'plugin:' . Inflector::camelize($parts[1]) . ':', $dependency);
			}
			if (substr($dependency, 0, 4) == '/js/') {
				$dependency = substr($dependency, 4);
			}
			$dependencies[$n] = $dependency;
		}
		return $dependencies;
	}

/**
 * Constructs the classes for the element that represents the frontend controller's DOM
 * reference.
 *
 * @return string
 */
	public function getMainContentClasses() {
		$classes = ['controller'];
		$classes[] = Inflector::underscore($this->_View->request->controller) . '-' . Inflector::underscore($this->_View->request->action);
		return implode(' ', $classes);
	}

/**
 * Returns a full list of the dependencies (used in console build task)
 *
 * @param array $defaultControllers which JS controllers to include before all others
 * @return array
 */
	public function compileDependencies($defaultControllers = array()) {
		$this->_includeAppController();
		$this->_includeComponents();
		$this->addController($defaultControllers);
		$this->addAllControllers();
		$this->_dependencies[] = '/frontend_bridge/js/bootstrap.js';
		return array_unique($this->_dependencies);
	}

/**
 * Includes the configured JS dependencies and appData - should
 * be called from the layout
 *
 * @return 	string	HTML
 */
	public function run() {
		$out = '';
		$this->_dependencies = array_unique($this->_dependencies);

		$out .= $this->getNamespaceDefinitions();
		$this->_addCurrentController();

		foreach ($this->_dependencies as $dependency) {
			if (strpos($dependency, DS) !== false) {
				$dependency = str_replace(DS, '/', $dependency);
			}
			$jsFile = $this->Html->script($dependency);
			$out .= $jsFile . "\n";
		}
		$out .= $this->getAppDataJs($this->_frontendData);
		$out .= $this->Html->script('/frontend_bridge/js/bootstrap.js');
		return $out;
	}

/**
 * Returns a script block containing namespace definitions for plugin controllers.
 *
 * @return string
 */
	public function getNamespaceDefinitions() {
		$script = 'var Frontend = {};';
		$script .= 'var App = { Controllers: {}, Components: {}, Lib: {} };';
		$tpl = 'App.Controllers.%s = {};';
		foreach ($this->_pluginJsNamespaces as $pluginName) {
			$script .= sprintf($tpl, $pluginName);
		}
		return $this->Html->scriptBlock($script);
	}

/**
 * Adds the currently visited controller/action, if existant.
 *
 * @return void
 */
	protected function _addCurrentController() {
		$this->addController(Inflector::camelize($this->_frontendData['request']['controller']) . '.' . Inflector::camelize($this->_frontendData['request']['action']));
		$this->addController(Inflector::camelize($this->_frontendData['request']['controller']));
	}

/**
 * Adds all controllers in app/controllers to the dependencies.
 * 
 * Please use only in development model.
 *
 * @return void
 */
	public function addAllControllers() {
		$controllers = [];

		// app/controllers/posts/*_controller.js
		$folder = new \Cake\Filesystem\Folder(WWW_ROOT . 'js/app/controllers');
		foreach ($folder->findRecursive('.*\.js') as $file) {
			$jsFile = '/' . str_replace(WWW_ROOT, '', $file);
			$controllers[] = $jsFile;
		}

		// Add All Plugin Controllers
		foreach (Plugin::loaded() as $pluginName) {
			$pluginJsControllersFolder = Plugin::path($pluginName) . '/webroot/js/app/controllers/';
			$pluginJsControllersFolder = str_replace('\\', '/', $pluginJsControllersFolder);

			if (is_dir($pluginJsControllersFolder)) {
				$this->_pluginJsNamespaces[] = $pluginName;
				$folder = new \Cake\Filesystem\Folder($pluginJsControllersFolder);
				$files = $folder->findRecursive('.*\.js');
				foreach ($files as $file) {
					$file = str_replace('\\', '/', $file);
					$file = str_replace($pluginJsControllersFolder, '', $file);
					$controllers[] = '/' . Inflector::underscore($pluginName) . '/js/app/controllers/' . $file;
				}
			}
		}

		// Move all controllers with base_ prefix to the top, so other controllers
		// can inherit from them
		foreach ($controllers as $n => $file) {
			if(substr(basename($file), 0, 5) == 'base_') {
				unset($controllers[$n]);
				array_unshift($controllers, $file);
			}
		}
		foreach ($controllers as $file) {
			$this->_addDependency($file);
		}
	}

/**
 * Include one or more JS controllers. Supports the 2 different file/folder structures.
 * 
 * - app/controllers/posts_edit_permissions_controller.js
 * - app/controllers/posts/edit_permissions_controller.js
 * - app/controllers/posts_controller.js
 * - app/controllers/posts/controller.js
 *
 * @param string|array $controllerName Dot-separated controller, TitleCased name.
 * 										Posts.EditPermissions
 * 										Posts.* (include all)
 * 										
 * @return bool
 */
	public function addController($controllerName) {
		if (is_array($controllerName)) {
			foreach ($controllerName as $cn) {
				$this->addController($cn);
			}
			return true;
		}

		$split = explode('.', $controllerName);
		$controller = $split[0];
		$action = null;
		if (isset($split[1])) {
			$action = $split[1];
		}

		// In the case of a plugin, we need to check the subfolder.

		if (empty($this->plugin)) {
			$absolutePath = WWW_ROOT . 'js/';
			$pluginPrefix = '';
		} else {
			$absolutePath = Plugin::path($this->plugin) . 'webroot/js/';
			$pluginPrefix = '/' . Inflector::underscore($this->plugin) . '/js/';
		}

		$paths = array();
		$path = 'app/controllers/';

		if ($controller && $action == '*') {
			// add the base controller
			$this->addController($controller);

			// app/controllers/posts/*_controller.js
			$subdirPath = $path . Inflector::underscore($controller) . '/';
			$folder = new Folder($absolutePath . $subdirPath);
			$files = $folder->find('.*\.js');

			if (!empty($files)) {
				foreach ($files as $file) {
					$this->_addDependency($pluginPrefix . $subdirPath . $file);
				}
			}

			$folder = new Folder($absolutePath . $path);
			// app/controllers/posts_*.js
			$files = $folder->find(Inflector::underscore($controller) . '_.*_controller\.js');
			if (!empty($files)) {
				foreach ($files as $file) {
					$this->_addDependency($pluginPrefix . $path . $file);
				}
			}
			return true;
		} elseif ($controller && $action) {
			// app/controllers/posts/edit_controller.js
			$paths[] = $path . Inflector::underscore($controller) . '/' . Inflector::underscore($action) . '_controller';
			// app/controllers/posts_edit_controller.js
			$paths[] = $path . Inflector::underscore($controller) . '_' . Inflector::underscore($action) . '_controller';
		} else {
			// app/controllers/posts/controller.js
			$paths[] = $path . Inflector::underscore($controller) . '/' . 'controller';
			// app/controllers/posts_controller.js
			$paths[] = $path . Inflector::underscore($controller) . '_controller';
		}

		foreach ($paths as $filePath) {
			if (file_exists($absolutePath . $filePath . '.js')) {
				$this->_addDependency($pluginPrefix . $filePath . '.js');
				return true;
			}
		}
		return false;
	}

/**
 * Include one or more JS components 
 *
 * @param string|array $componentName CamelCased component name	(e.g. SelectorAddressList)
 * @return bool
 */
	public function addComponent($componentName) {
		if (is_array($componentName)) {
			foreach ($componentName as $cn) {
				$this->addComponent($cn);
			}
			return true;
		}
		$componentFile = 'app/components/' . Inflector::underscore($componentName) . '.js';

		if (file_exists(JS . DS . $componentFile)) {
			$this->_addDependency($componentFile);
			return true;
		}
		return false;
	}


/**
 * Allows manipulating frontend data
 *
 * @param string|array $key Either the key or an array
 * @param mixed $value Value
 * @return void
 */
	public function setFrontendData($key, $value = null) {
		if (is_array($key)) {
			foreach ($key as $k => $v) {
				$this->setFrontendData($k, $v);
			}
			return;
		}
		$this->_frontendData['jsonData'][$key] = $value;
	}

/**
 * Constructs the JS for setting the appData
 *
 * @return string The rendered JS
 */
	public function getAppDataJs() {
		return $this->Html->scriptBlock('
			var appData = ' . json_encode($this->_frontendData) . ';
		');
	}

/**
 * Add a file to the frontend dependencies
 *
 * @param string $file path to be added
 * @return void
 */
	protected function _addDependency($file) {
		$file = str_replace('\\', '/', $file);
		if (!in_array($file, $this->_dependencies)) {
			$this->_dependencies[] = $file;
		}
	}

/**
 * Check if we have an AppController, if not, include a stub
 *
 * @return void
 */
	protected function _includeAppController() {
		$controller = null;
		if (file_exists(WWW_ROOT . 'js/app/app_controller.js')) {
			$controller = 'app/app_controller.js';
		} else {
			$controller = '/frontend_bridge/js/lib/app_controller.js';
		}
		$this->_dependencies[] = $controller;
	}

/**
 * Includes the needed components
 *
 * @return void
 */
	protected function _includeComponents() {
		// for now, we just include all components
		$appComponentFolder = WWW_ROOT . 'js/app/components/';
		$folder = new Folder($appComponentFolder);
		$files = $folder->find('.*\.js');
		if (!empty($files)) {
			foreach ($files as $file) {
				$this->_dependencies[] = 'app/components/' . $file;
			}
		}

		// Add Plugin Components
		foreach (Plugin::loaded() as $pluginName) {
			$pluginJsComponentsFolder = Plugin::path($pluginName) . '/webroot/js/app/components/';
			if (is_dir($pluginJsComponentsFolder)) {
				$folder = new Folder($pluginJsComponentsFolder);
				$files = $folder->find('.*\.js');
				foreach ($files as $file) {
					$this->_dependencies[] = '/' . Inflector::underscore($pluginName) . '/js/app/components/' . $file;
				}
			}
		}
	}
}
