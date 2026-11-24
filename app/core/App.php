<?php

class App {
	public $controller = "";
	public $method = "";
	public $parameter = [];

	public function __construct(){

		$paramsToCheck = array_merge($_GET, $_POST);
		if (!empty($_SERVER['QUERY_STRING'])) {
			$paramsToCheck['__raw_query__'] = $_SERVER['QUERY_STRING'];
		}
		if (Security::detect_path_traversal($paramsToCheck)) {
			Security::log_incident('Path_Traversal_Vector', json_encode($paramsToCheck), 'Blocked');
			$this->triggerBadRequest("Request blocked due to unsafe path traversal pattern.");
			return;
		}
		if (Security::detect_sqli($paramsToCheck)) {
			Security::log_incident('SQLi_Pattern', json_encode($paramsToCheck), 'Blocked');
			$this->triggerBadRequest("Request blocked due to SQL query anomaly.");
			return;
		}
		if (Security::detect_rce_payload($paramsToCheck)) {
			Security::log_incident('RCE_Vector', json_encode($paramsToCheck), 'Blocked');
			$this->triggerBadRequest("Request blocked due to dangerous execution payload.");
			return;
		}
		if (Security::detect_xss($paramsToCheck)) {
			Security::log_incident('XSS_Vector', json_encode($paramsToCheck), 'Blocked');
			$this->triggerBadRequest("Request blocked due to XSS pattern.");
			return;
		}

		$this->initDefaultController("login", "index", []);

		$url = $this->parseURL();

		if (!empty($url)) {
			$rawController = $url[0];
			if (!preg_match('/^[a-zA-Z0-9_\-]+$/', $rawController)) {
				Security::log_incident('SUSPICIOUS_URL_CONTROLLER', $rawController, 'Blocked');
				$this->triggerNotFound("Invalid module route '" . htmlspecialchars($rawController, ENT_QUOTES, 'UTF-8') . "'.");
				return;
			}

			$controllerName = strtolower($rawController);
			$controllerPath = dirname(__DIR__) . '/controller/' . $controllerName . '.php';

			if (file_exists($controllerPath)) {
				$this->controller = $controllerName;
				unset($url[0]);
			} else {
				$this->triggerNotFound("Route '" . htmlspecialchars($rawController, ENT_QUOTES, 'UTF-8') . "' not found.");
				return;
			}
		}

		$controllerFile = dirname(__DIR__) . '/controller/' . $this->controller . '.php';
		if (file_exists($controllerFile)) {
			require_once $controllerFile;
		} else {
			$this->triggerNotFound("Controller '" . htmlspecialchars($this->controller, ENT_QUOTES, 'UTF-8') . "' unavailable.");
			return;
		}

		if (class_exists($this->controller)) {
			$this->controller = new $this->controller;
		} else {
			$this->triggerServerError("Unable to initialize controller.");
			return;
		}

		if (isset($url[1])) {
			$rawMethod = $url[1];
			if (!preg_match('/^[a-zA-Z0-9_]+$/', $rawMethod) || $this->starts_with($rawMethod, "_")) {
				$this->triggerNotFound("Action '" . htmlspecialchars($rawMethod, ENT_QUOTES, 'UTF-8') . "' is not permitted.");
				return;
			}

			if (method_exists($this->controller, $rawMethod)) {
				$this->method = $rawMethod;
				unset($url[1]);
			} else {
				$this->triggerNotFound("Action '" . htmlspecialchars($rawMethod, ENT_QUOTES, 'UTF-8') . "' not found.");
				return;
			}
		}

		if (!empty($url)) {
			$cleanParams = [];
			foreach (array_values($url) as $param) {
				if (is_string($param)) {
					if (Security::detect_path_traversal($param)) {
						Security::log_incident('URL_PARAM_PATH_TRAVERSAL', $param, 'Blocked');
						$this->triggerBadRequest("Unsafe URL parameter.");
						return;
					}
					if (Security::detect_sqli($param)) {
						Security::log_incident('URL_PARAM_SQLI', $param, 'Blocked');
						$this->triggerBadRequest("Unsafe URL parameter.");
						return;
					}
					if (Security::detect_rce_payload($param)) {
						Security::log_incident('URL_PARAM_RCE', $param, 'Blocked');
						$this->triggerBadRequest("Unsafe URL parameter.");
						return;
					}
					$cleanParam = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $param);
					$cleanParam = trim(substr($cleanParam, 0, 1024));
					$cleanParams[] = $cleanParam;
				} else {
					$cleanParams[] = $param;
				}
			}
			$this->parameter = $cleanParams;
		} else {
			$this->parameter = [];
		}

		try {
			call_user_func_array([$this->controller, $this->method], $this->parameter);
		} catch (Throwable $e) {
			error_log("Application Exception: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
			$this->triggerServerError("An internal server processing error occurred.");
		}
	}

	private function triggerBadRequest($message = ''){
		require_once dirname(__DIR__) . '/controller/errors.php';
		$err = new errors();
		$err->abort(400, $message);
	}

	private function triggerNotFound($message = ''){
		require_once dirname(__DIR__) . '/controller/errors.php';
		$err = new errors();
		$err->abort(404, $message);
	}

	private function triggerServerError($message = ''){
		require_once dirname(__DIR__) . '/controller/errors.php';
		$err = new errors();
		$err->abort(500, $message);
	}

	private function starts_with($str, $prefix){
		return strpos($str, $prefix) === 0;
	}

	private function initDefaultController($controller, $method, $param){
		$this->controller = $controller;
		$this->method = $method;
		$this->parameter = $param;
	}

	public function parseURL(){
		if (isset($_GET['url'])) {
			$rawUrl = (string)$_GET['url'];
			if (strpos($rawUrl, "\0") !== false || strpos($rawUrl, "%00") !== false) {
				Security::log_incident('NULL_BYTE_URL_POISONING', $rawUrl, 'Blocked');
				return ['errors', 'abort', 400];
			}
			if (Security::detect_path_traversal($rawUrl)) {
				Security::log_incident('URL_PARAM_PATH_TRAVERSAL', $rawUrl, 'Blocked');
				return ['errors', 'abort', 400];
			}
			if (Security::detect_sqli($rawUrl)) {
				Security::log_incident('URL_PARAM_SQLI', $rawUrl, 'Blocked');
				return ['errors', 'abort', 400];
			}
			if (Security::detect_rce_payload($rawUrl)) {
				Security::log_incident('URL_PARAM_RCE', $rawUrl, 'Blocked');
				return ['errors', 'abort', 400];
			}
			$url = rtrim($rawUrl, '/');
			$url = preg_replace('/[\x00-\x1F\x7F]/', '', $url);
			$url = filter_var($url, FILTER_SANITIZE_URL);
			return explode('/', $url);
		}
		return [];
	}
}
