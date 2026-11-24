<?php

class Controller {

	public function __construct(){
		$this->send_security_headers();
		$this->sanitize_global_inputs();
		$this->check_security_payloads();
	}

	public function send_security_headers(){
		if (!headers_sent()) {
			@header_remove('X-Powered-By');
			header('X-Frame-Options: SAMEORIGIN');
			header('X-Content-Type-Options: nosniff');
			header('Referrer-Policy: strict-origin-when-cross-origin');
			header('Permissions-Policy: geolocation=(self), camera=(self), microphone=(self)');
			header('X-XSS-Protection: 1; mode=block');
			header('X-Permitted-Cross-Domain-Policies: none');
			header('Cross-Origin-Opener-Policy: same-origin-allow-popups');
			header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline' 'unsafe-eval' blob:; style-src 'self' 'unsafe-inline'; img-src 'self' data: blob:; font-src 'self' data:; media-src 'self' blob:; connect-src 'self' blob: data:; worker-src 'self' blob:; object-src 'none'; frame-ancestors 'self'; base-uri 'self'; form-action 'self';");
			header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
			header('Pragma: no-cache');
			if ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443) {
				header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
			}
		}
	}

	public function check_security_payloads(){
		$paramsToCheck = array_merge($_GET, $_POST, $_COOKIE);
		if (!empty($_SERVER['QUERY_STRING'])) {
			$paramsToCheck['__raw_query__'] = $_SERVER['QUERY_STRING'];
		}

		if (Security::detect_sqli($paramsToCheck)) {
			Security::log_incident('SQLi_Pattern', json_encode($paramsToCheck), 'Blocked');
			$this->abort(400, 'Request blocked by security policy due to unsafe query pattern.');
		}
		if (Security::detect_rce_payload($paramsToCheck)) {
			Security::log_incident('RCE_Vector', json_encode($paramsToCheck), 'Blocked');
			$this->abort(400, 'Request blocked due to dangerous execution payload.');
		}
		if (Security::detect_path_traversal($paramsToCheck)) {
			Security::log_incident('Path_Traversal_Vector', json_encode($paramsToCheck), 'Blocked');
			$this->abort(400, 'Request blocked due to unsafe path traversal pattern.');
		}
		if (Security::detect_xss($paramsToCheck)) {
			Security::log_incident('XSS_Vector', json_encode($paramsToCheck), 'Blocked');
			$this->abort(400, 'Request blocked due to script injection attempt.');
		}
	}

	public function abort($code = 404, $message = ''){
		http_response_code($code);
		$statusTexts = [
			400 => 'Bad Request',
			401 => 'Unauthorized',
			403 => 'Forbidden',
			404 => 'Not Found',
			405 => 'Method Not Allowed',
			500 => 'Internal Server Error',
			503 => 'Service Unavailable'
		];
		$defaultDescriptions = [
			400 => 'The request was rejected due to invalid syntax or security policy.',
			401 => 'Authentication is required or your session has expired.',
			403 => 'Access denied by role permissions or security validation.',
			404 => 'The requested resource or route could not be found.',
			405 => 'The HTTP method is not allowed on this endpoint.',
			500 => 'An internal server processing error occurred.',
			503 => 'The service is temporarily unavailable.'
		];
		$statusTitle = $statusTexts[$code] ?? "Status $code";
		$finalMessage = !empty($message) ? $message : ($defaultDescriptions[$code] ?? 'An error occurred.');
		$appName = defined('APP_NAME') ? APP_NAME : 'FILKOM AI Agent Orchestrator';
		$data = [
			'title' => "$code - $statusTitle - $appName",
			'code' => (int)$code,
			'status_title' => $statusTitle,
			'message' => $finalMessage,
			'uri' => $_SERVER['REQUEST_URI'] ?? '',
			'client_ip' => class_exists('Security') ? Security::get_client_ip() : ($_SERVER['REMOTE_ADDR'] ?? '127.0.0.1'),
			'timestamp' => date('d M Y H:i:s'),
			'reference_id' => 'ERR-' . strtoupper(substr(md5(($code) . ($_SERVER['REQUEST_URI'] ?? '') . time()), 0, 8)),
			'no_sidebar' => true
		];
		$this->display('errors/index', $data);
		exit();
	}

	public function display($view, $data = []){
		$this->send_security_headers();

		if (!isset($data['csrf_token'])) {
			$data['csrf_token'] = $this->get_csrf_token();
		}

		$semInfo = self::get_active_academic_semester();
		if (!isset($data['active_semester_label'])) {
			$data['active_semester_label'] = $semInfo['semester_label'];
		}
		if (!isset($data['active_semester'])) {
			$data['active_semester'] = $semInfo['semester_label'];
		}
		if (!isset($data['active_academic_year'])) {
			$data['active_academic_year'] = $semInfo['academic_year'];
		}
		if (!isset($data['active_semester_id'])) {
			$data['active_semester_id'] = $semInfo['semester_id'];
		}
		if (!isset($data['active_semester_type'])) {
			$data['active_semester_type'] = $semInfo['term_type'];
		}
		if (!isset($data['active_semester_info'])) {
			$data['active_semester_info'] = $semInfo;
		}
		if (!isset($data['semester'])) {
			$data['semester'] = $semInfo['semester_label'];
		}

		$primaryPath = dirname(__DIR__) . '/view/' . $view . '.php';
		if (file_exists($primaryPath)) {
			require $primaryPath;
		} elseif (file_exists('../app/view/' . $view . '.php')) {
			require '../app/view/' . $view . '.php';
		} elseif (file_exists('app/view/' . $view . '.php')) {
			require 'app/view/' . $view . '.php';
		} else {
			echo "View not found.";
		}
	}

	public function logic($model){
		$primaryPath = dirname(__DIR__) . '/model/' . $model . '.php';
		if (file_exists($primaryPath)) {
			require_once $primaryPath;
		} elseif (file_exists('../app/model/' . $model . '.php')) {
			require_once '../app/model/' . $model . '.php';
		} elseif (file_exists('app/model/' . $model . '.php')) {
			require_once 'app/model/' . $model . '.php';
		}

		if (class_exists($model)) {
			return new $model;
		}
		return null;
	}

	private static $cachedAcademicSemester = null;

	public static function get_active_academic_semester($forceRefresh = false){
		if (self::$cachedAcademicSemester !== null && !$forceRefresh) {
			return self::$cachedAcademicSemester;
		}

		$semesterId = '';
		try {
			$db = new Database();
			if ($db->isConnected() && $db->hasTable('tbl_system_settings')) {
				$db->prepare("SELECT setting_value FROM tbl_system_settings WHERE setting_key = :sk LIMIT 1");
				$db->bind(':sk', 'active_academic_semester');
				$row = $db->single();
				if ($row && !empty($row['setting_value'])) {
					$semesterId = trim((string)$row['setting_value']);
				}
			}
		} catch (Throwable $e) {}

		self::$cachedAcademicSemester = self::parse_semester_id($semesterId);
		return self::$cachedAcademicSemester;
	}

	public static function parse_semester_id($semesterId = ''){
		$semesterId = trim((string)$semesterId);
		$termNum = 1;
		$y1 = '';
		$y2 = '';

		if (!empty($semesterId)) {
			$parts = explode('_', $semesterId);
			if (count($parts) >= 3) {
				$y1 = $parts[0];
				$y2 = $parts[1];
				$termNum = (int)$parts[2];
			} elseif (count($parts) == 2 && is_numeric($parts[0])) {
				$y1 = $parts[0];
				$y2 = (string)((int)$parts[0] + 1);
				$termNum = (int)$parts[1];
			}
		}

		if (empty($y1) || empty($y2)) {
			$currYear = (int)date('Y');
			$currMonth = (int)date('n');
			if ($currMonth >= 7) {
				$y1 = (string)$currYear;
				$y2 = (string)($currYear + 1);
				$termNum = 1;
			} elseif ($currMonth <= 1) {
				$y1 = (string)($currYear - 1);
				$y2 = (string)$currYear;
				$termNum = 1;
			} else {
				$y1 = (string)($currYear - 1);
				$y2 = (string)$currYear;
				$termNum = 2;
			}
			$semesterId = "{$y1}_{$y2}_{$termNum}";
		}

		$academicYear = "$y1/$y2";
		if ($termNum === 1) {
			$termNameId = 'Semester Ganjil';
			$termNameEn = 'Odd Semester';
			$termShort = 'Ganjil';
		} elseif ($termNum === 2) {
			$termNameId = 'Semester Genap';
			$termNameEn = 'Even Semester';
			$termShort = 'Genap';
		} elseif ($termNum === 3) {
			$termNameId = 'Semester Padat';
			$termNameEn = 'Short Semester';
			$termShort = 'Padat';
		} else {
			$termNameId = "Semester $termNum";
			$termNameEn = "Semester $termNum";
			$termShort = "Sem $termNum";
		}

		return [
			'semester_id' => $semesterId,
			'semester_label' => "$termNameId $academicYear",
			'semester_label_en' => "$termNameEn $academicYear",
			'semester_short' => "$termShort $academicYear",
			'academic_year' => $academicYear,
			'term_name' => $termNameId,
			'term_name_en' => $termNameEn,
			'term_type' => $termShort,
			'term_number' => $termNum,
			'year_start' => $y1,
			'year_end' => $y2
		];
	}

	public function start_default_session_app($jsonMode = false){
		$this->send_security_headers();
		$acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
		$xhrHeader = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
		$wantsJson = (bool)$jsonMode || strpos($acceptHeader, 'application/json') !== false || $xhrHeader === 'xmlhttprequest';
		if (session_status() === PHP_SESSION_NONE) {
			$is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || ($_SERVER['SERVER_PORT'] ?? 80) == 443;
			@session_set_cookie_params([
				'lifetime' => 0,
				'path' => '/',
				'domain' => '',
				'secure' => $is_https,
				'httponly' => true,
				'samesite' => 'Lax'
			]);

			$sess_name = session_name();
			if (!empty($_COOKIE[$sess_name])) {
				$candidate_id = $_COOKIE[$sess_name];
				if (!preg_match('/^[a-zA-Z0-9,-]{16,128}$/', $candidate_id)) {
					unset($_COOKIE[$sess_name]);
					session_id(bin2hex(random_bytes(16)));
				}
			}

			@session_start();
		}

		$idle_timeout = 1800;
		if (isset($_SESSION['LAST_ACTIVITY'])) {
			if ((time() - (int)$_SESSION['LAST_ACTIVITY']) > $idle_timeout) {
				if (!empty($_SESSION['user-email']) || !empty($_SESSION['user-name'])) {
					$identifier = $_SESSION['user-email'] ?? ($_SESSION['user-username'] ?? 'User');
					$this->log_user_login($_SESSION['user-id'] ?? null, $identifier, 'Timeout', 'Session idle timeout');
					$this->destroy_user_session_db(session_id());
				}
				$this->destroy_session_cleanly();
				if ($wantsJson) {
					$this->json_response(['success' => false, 'session_expired' => true, 'message' => 'Session expired due to inactivity. Please sign in again.'], 401);
				}
				header('Location: ' . APP_PATH . '/login/?timeout=1');
				exit();
			}
		}

		$_SESSION['LAST_ACTIVITY'] = time();

		if (!empty($_SESSION['user-id']) || !empty($_SESSION['user-name']) || !empty($_SESSION['user-role'])) {
			try {
				$db = new Database();
				if ($db->isConnected() && $db->hasTable('tbl_user_sessions')) {
					$currentSessId = session_id();
					$db->prepare("SELECT is_revoked, last_activity FROM tbl_user_sessions WHERE session_id = :sid LIMIT 1");
					$db->bind(':sid', $currentSessId);
					$sessData = $db->single();

					if ($sessData) {
						if ((int)$sessData['is_revoked'] === 1) {
							$this->destroy_user_session_db($currentSessId);
							$this->destroy_session_cleanly();
							if ($wantsJson) {
								$this->json_response(['success' => false, 'session_expired' => true, 'message' => 'Your session has been revoked by an administrator.'], 401);
							}
							header('Location: ' . APP_PATH . '/login/?revoked=1');
							exit();
						}

						if ((time() - (int)$sessData['last_activity']) > $idle_timeout) {
							$this->destroy_user_session_db($currentSessId);
							$this->destroy_session_cleanly();
							if ($wantsJson) {
								$this->json_response(['success' => false, 'session_expired' => true, 'message' => 'Session expired. Please sign in again.'], 401);
							}
							header('Location: ' . APP_PATH . '/login/?timeout=1');
							exit();
						}

						$db->prepare("UPDATE tbl_user_sessions SET last_activity = :la WHERE session_id = :sid");
						$db->bind(':la', time(), PDO::PARAM_INT);
						$db->bind(':sid', $currentSessId);
						$db->execute();
					} else {
						$userId = $_SESSION['user-id'] ?? ($_SESSION['user-nip'] ?? null);
						$ident = $_SESSION['user-email'] ?? ($_SESSION['user-username'] ?? 'User');
						$name = $_SESSION['user-name'] ?? 'User';
						$role = $_SESSION['user-role'] ?? 'STUDENT';
						$this->track_user_session($userId, $ident, $name, $role);
					}

					if (random_int(1, 50) === 1) {
						$twoDaysAgo = time() - (2 * 86400);
						$oneHourAgo = time() - 3600;
						$db->prepare("DELETE FROM tbl_user_sessions WHERE last_activity < :t1 OR (is_revoked = 1 AND last_activity < :t2)");
						$db->bind(':t1', $twoDaysAgo, PDO::PARAM_INT);
						$db->bind(':t2', $oneHourAgo, PDO::PARAM_INT);
						$db->execute();
					}
				}
			} catch (Throwable $e) {}
		}

		if (empty($_SESSION['csrf_token'])) {
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		}
	}

	public function destroy_session_cleanly(){
		if (session_status() === PHP_SESSION_ACTIVE) {
			$_SESSION = [];
			if (ini_get("session.use_cookies")) {
				$params = session_get_cookie_params();
				setcookie(session_name(), '', time() - 42000,
					$params["path"], $params["domain"],
					$params["secure"], $params["httponly"]
				);
			}
			@session_destroy();
		}
	}

	public function detect_device_type($userAgent = null){
		$ua = $userAgent ?? ($_SERVER['HTTP_USER_AGENT'] ?? '');
		if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
			return 'Tablet';
		}
		if (preg_match('/(iphone|ipod|blackberry|android|mobile|bb\d+|meego|opera m(ob|in)i)/i', $ua)) {
			return 'Mobile';
		}
		return 'Desktop';
	}

	public function track_user_session($arg1, $arg2, $arg3 = null, $arg4 = null){
		try {
			$db = new Database();
			if (!$db->isConnected() || !$db->hasTable('tbl_user_sessions')) return false;

			if ($arg4 === null) {
				$userId = $_SESSION['user-id'] ?? ($_SESSION['user-nip'] ?? null);
				$identifier = $arg1;
				$role = $arg2;
				$fullName = $arg3 ?? ($identifier ?? 'User');
			} else {
				$userId = $arg1;
				$identifier = $arg2;
				$fullName = $arg3;
				$role = $arg4;
			}

			$sessId = session_id();
			if (empty($sessId)) {
				if (session_status() === PHP_SESSION_NONE) {
					@session_start();
				}
				$sessId = session_id();
			}
			if (empty($sessId)) return false;

			$ip = Security::get_client_ip();
			$ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255);
			$device = $this->detect_device_type($ua);
			$nowTs = time();

			$db->prepare("INSERT INTO tbl_user_sessions
				(session_id, user_id, identifier, full_name, role_code, ip_address, user_agent, device_type, last_activity, is_revoked)
				VALUES (:sid, :uid, :ident, :fn, :rc, :ip, :ua, :dt, :la, 0)
				ON DUPLICATE KEY UPDATE
				user_id = VALUES(user_id), identifier = VALUES(identifier), full_name = VALUES(full_name),
				role_code = VALUES(role_code), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent),
				device_type = VALUES(device_type), last_activity = VALUES(last_activity), is_revoked = 0");
			$db->bind(':sid', $sessId);
			$db->bind(':uid', $userId !== null ? (string)$userId : null);
			$db->bind(':ident', (string)$identifier);
			$db->bind(':fn', (string)$fullName);
			$db->bind(':rc', (string)$role);
			$db->bind(':ip', $ip);
			$db->bind(':ua', $ua);
			$db->bind(':dt', $device);
			$db->bind(':la', $nowTs, PDO::PARAM_INT);
			return $db->execute();
		} catch (Throwable $e) {}
		return false;
	}

	public function destroy_user_session_db($sessionId = null){
		try {
			if ($sessionId === null) {
				$sessionId = session_id();
			}
			if (empty($sessionId)) return false;

			$db = new Database();
			if (!$db->isConnected() || !$db->hasTable('tbl_user_sessions')) return false;

			$db->prepare("DELETE FROM tbl_user_sessions WHERE session_id = :sid");
			$db->bind(':sid', $sessionId);
			return $db->execute();
		} catch (Throwable $e) {}
		return false;
	}

	public function revoke_user_session_db($sessionIdOrId, $adminName = null){
		try {
			$db = new Database();
			if (!$db->isConnected() || !$db->hasTable('tbl_user_sessions')) return false;

			$revokedBy = $adminName ?: ($_SESSION['user-name'] ?? 'Admin');
			if (is_numeric($sessionIdOrId)) {
				$db->prepare("UPDATE tbl_user_sessions SET is_revoked = 1, revoked_by = :rb WHERE id = :id");
				$db->bind(':rb', $revokedBy);
				$db->bind(':id', (int)$sessionIdOrId, PDO::PARAM_INT);
				return $db->execute();
			} else {
				$db->prepare("UPDATE tbl_user_sessions SET is_revoked = 1, revoked_by = :rb WHERE session_id = :sid");
				$db->bind(':rb', $revokedBy);
				$db->bind(':sid', (string)$sessionIdOrId);
				return $db->execute();
			}
		} catch (Throwable $e) {}
		return false;
	}

	public function revoke_all_user_sessions_db($currentAdminSessionId = null, $adminName = null){
		try {
			$db = new Database();
			if (!$db->isConnected() || !$db->hasTable('tbl_user_sessions')) return false;

			$revokedBy = $adminName ?: ($_SESSION['user-name'] ?? 'Admin');
			if ($currentAdminSessionId) {
				$db->prepare("UPDATE tbl_user_sessions SET is_revoked = 1, revoked_by = :rb WHERE session_id != :sid AND is_revoked = 0");
				$db->bind(':rb', $revokedBy);
				$db->bind(':sid', $currentAdminSessionId);
				return $db->execute();
			} else {
				$db->prepare("UPDATE tbl_user_sessions SET is_revoked = 1, revoked_by = :rb WHERE is_revoked = 0");
				$db->bind(':rb', $revokedBy);
				return $db->execute();
			}
		} catch (Throwable $e) {}
		return false;
	}

	public function get_active_sessions_list(){
		try {
			$db = new Database();
			if (!$db->isConnected() || !$db->hasTable('tbl_user_sessions')) return [];

			$idleTimeout = 1800;
			$cutoff = time() - $idleTimeout;

			$db->prepare("SELECT id, session_id, user_id, identifier, full_name, role_code AS role, ip_address, user_agent, device_type, last_activity, is_revoked, revoked_by, created_at, updated_at
				FROM tbl_user_sessions
				WHERE is_revoked = 0 AND last_activity >= :cutoff
				ORDER BY last_activity DESC
				LIMIT 100");
			$db->bind(':cutoff', $cutoff, PDO::PARAM_INT);
			$rows = $db->resultSet();
			$sessions = [];
			foreach ($rows as $row) {
				$row['idle_seconds'] = time() - (int)$row['last_activity'];
				$row['idle_formatted'] = gmdate("i:s", max(0, $idleTimeout - $row['idle_seconds']));
				$sessions[] = $row;
			}
			return $sessions;
		} catch (Throwable $e) {
			return [];
		}
	}

	public function generate_csrf_token(){
		if (session_status() === PHP_SESSION_NONE) {
			$this->start_default_session_app();
		}
		if (empty($_SESSION['csrf_token'])) {
			$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
		}
		return $_SESSION['csrf_token'];
	}

	public function get_csrf_token(){
		return $_SESSION['csrf_token'] ?? $this->generate_csrf_token();
	}

	public function verify_csrf_token($token = null){
		if (session_status() === PHP_SESSION_NONE) {
			$this->start_default_session_app();
		}
		$token = $token ?? ($_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''));
		if (empty($token) || empty($_SESSION['csrf_token'])) {
			Security::log_incident('CSRF_TOKEN_MISSING', 'CSRF token missing in request payload', 'Blocked');
			return false;
		}
		$isValid = hash_equals($_SESSION['csrf_token'], (string)$token);
		if (!$isValid) {
			Security::log_incident('CSRF_TOKEN_FORGERY', 'CSRF token mismatch with active session', 'Blocked');
		}
		return $isValid;
	}

	public function sanitize_global_inputs(){
		$_GET = $this->clean_input_array($_GET);
		$_POST = $this->clean_input_array($_POST);
		$_COOKIE = $this->clean_input_array($_COOKIE);
		$_REQUEST = $this->clean_input_array($_REQUEST);
	}

	private function clean_input_array($data){
		if (is_array($data)) {
			$cleaned = [];
			foreach ($data as $k => $v) {
				$cleanK = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', (string)$k);
				$cleanK = trim($cleanK);
				$cleaned[$cleanK] = $this->clean_input_array($v);
			}
			return $cleaned;
		}
		if (is_string($data)) {
			$data = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $data);
			if (function_exists('mb_convert_encoding')) {
				$data = mb_convert_encoding($data, 'UTF-8', 'UTF-8');
			}
			return trim($data);
		}
		return $data;
	}

	public function audit_log($action, $arg2 = '', $arg3 = null, $arg4 = null){
		try {
			$db = new Database();
			if (!$db->isConnected() || !$db->hasTable('tbl_audit_logs')) return;

			if ($arg4 !== null) {
				$targetTable = (string)$arg2;
				$recordId = (string)$arg3;
				$description = (string)$arg4;
			} elseif ($arg3 !== null) {
				$description = (string)$arg2;
				$targetTable = (string)$arg3;
				$recordId = null;
			} else {
				$description = (string)$arg2;
				$targetTable = 'system';
				$recordId = null;
			}

			$uId = isset($_SESSION['user-id']) ? (string)$_SESSION['user-id'] : (isset($_SESSION['user-nip']) ? (string)$_SESSION['user-nip'] : null);
			$uName = $_SESSION['user-name'] ?? null;
			$role = $_SESSION['user-role'] ?? null;
			$ip = Security::get_client_ip();

			$db->prepare("INSERT INTO tbl_audit_logs (action, target_table, record_id, description, user_id, user_name, role_code, ip_address, created_at)
				VALUES (:act, :tbl, :rid, :desc, :uid, :uname, :role, :ip, NOW())");
			$db->bind(':act', substr((string)$action, 0, 80));
			$db->bind(':tbl', substr($targetTable, 0, 64));
			$db->bind(':rid', $recordId !== null ? substr($recordId, 0, 64) : null);
			$db->bind(':desc', substr($description, 0, 1000));
			$db->bind(':uid', $uId);
			$db->bind(':uname', $uName);
			$db->bind(':role', $role);
			$db->bind(':ip', $ip);
			$db->execute();
		} catch (Throwable $e) {}
	}

	public function log_user_login($arg1 = null, $arg2 = null, $arg3 = 'SUCCESS', $arg4 = null){
		try {
			$db = new Database();
			if (!$db->isConnected() || !$db->hasTable('tbl_login_logs')) return;

			if ($arg4 !== null) {
				$userId = $arg1;
				$identifier = $arg2;
				$status = $arg3;
				$failureReason = $arg4;
			} else {
				$userId = $_SESSION['user-id'] ?? ($_SESSION['user-nip'] ?? null);
				$identifier = $arg1;
				$status = $arg3 ?: 'SUCCESS';
				$failureReason = is_string($arg2) ? $arg2 : null;
			}

			$db->prepare("INSERT INTO tbl_login_logs (user_id, identifier_input, role_code, ip_address, user_agent, status, failure_reason, created_at)
				VALUES (:uid, :ident, :role, :ip, :ua, :st, :fr, NOW())");
			$db->bind(':uid', $userId !== null ? (string)$userId : null);
			$db->bind(':ident', substr((string)($identifier ?? 'Guest'), 0, 120));
			$db->bind(':role', $_SESSION['user-role'] ?? null);
			$db->bind(':ip', Security::get_client_ip());
			$db->bind(':ua', substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255));
			$db->bind(':st', substr((string)$status, 0, 32));
			$db->bind(':fr', $failureReason !== null ? substr((string)$failureReason, 0, 255) : null);
			$db->execute();
		} catch (Throwable $e) {}
	}

	public function sanitize($input){
		if (is_array($input)) {
			return array_map([$this, 'sanitize'], $input);
		}
		return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
	}

	public function json_response($data, $status = 200){
		$this->send_security_headers();
		http_response_code($status);
		header('Content-Type: application/json; charset=utf-8');
		$json = json_encode($data, JSON_INVALID_UTF8_SUBSTITUTE | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		if ($json === false) {
			$json = '{"success":false,"message":"JSON serialization error"}';
		}
		echo $json;
		exit();
	}

	public function require_authentication($allowedRoles = [], $jsonMode = false){
		$acceptHeader = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
		$xhrHeader = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
		$wantsJson = (bool)$jsonMode || strpos($acceptHeader, 'application/json') !== false || $xhrHeader === 'xmlhttprequest';
		$this->start_default_session_app($wantsJson);

		if (empty($_SESSION['user-id']) || empty($_SESSION['user-role'])) {
			if ($wantsJson) {
				$this->json_response(['success' => false, 'message' => 'Authentication required. Please sign in first.'], 401);
			}
			header('Location: ' . APP_PATH . '/login/?auth=1');
			exit();
		}

		if (!empty($allowedRoles)) {
			$rbac = $this->logic('RbacService');
			$userId = (int)$_SESSION['user-id'];
			$userRoles = $rbac ? $rbac->getUserRoleCodes($userId) : [strtoupper((string)$_SESSION['user-role'])];
			if (empty($userRoles)) {
				$userRoles = [strtoupper((string)$_SESSION['user-role'])];
			}
			$allowed = array_map('strtoupper', (array)$allowedRoles);
			$matched = false;
			foreach ($userRoles as $ur) {
				if (in_array(strtoupper($ur), $allowed, true)) {
					$matched = true;
					break;
				}
			}
			if (!$matched) {
				Security::log_incident('UNAUTHORIZED_ROLE_ACCESS', "Roles [" . implode(',', $userRoles) . "] denied access requiring: " . implode(',', $allowed), 'Blocked 403');
				if ($wantsJson) {
					$this->json_response(['success' => false, 'message' => 'Access denied for your account role.'], 403);
				}
				$this->abort(403, 'Access denied. Your role does not have permission for this module.');
			}
		}
	}

	public function require_permission(string $permissionCode, bool $jsonMode = true): void {
		$this->require_authentication([], $jsonMode);
		$rbac = $this->logic('RbacService');
		$userId = (int)($_SESSION['user-id'] ?? 0);
		if (!$rbac || !$rbac->userHasPermission($userId, $permissionCode)) {
			Security::log_incident('UNAUTHORIZED_PERMISSION', "User #{$userId} missing permission {$permissionCode}", 'Blocked 403');
			if ($jsonMode) {
				$this->json_response(['success' => false, 'message' => "Permission denied ({$permissionCode})."], 403);
			}
			$this->abort(403, "Permission denied ({$permissionCode}).");
		}
	}

	public function validate_input($data, $rules){
		$errors = [];
		$sanitized = [];

		foreach ($rules as $field => $ruleStr) {
			$val = $data[$field] ?? null;
			$ruleList = explode('|', $ruleStr);

			foreach ($ruleList as $rule) {
				$param = null;
				if (strpos($rule, ':') !== false) {
					list($rule, $param) = explode(':', $rule, 2);
				}

				if ($rule === 'required') {
					if ($val === null || $val === '') {
						$errors[$field][] = "Field '$field' is required.";
						break;
					}
				}

				if ($val !== null && $val !== '') {
					if ($rule === 'email' || $rule === 'student_email' || $rule === 'campus_email' || $rule === 'school_email' || $rule === 'unklab_email') {
						if (!filter_var($val, FILTER_VALIDATE_EMAIL)) {
							$errors[$field][] = "Field '$field' must be a valid email address.";
						}
					} elseif ($rule === 'numeric') {
						if (!is_numeric($val)) {
							$errors[$field][] = "Field '$field' must be numeric.";
						}
					} elseif ($rule === 'min' && $param !== null) {
						if (is_string($val) && strlen($val) < (int)$param) {
							$errors[$field][] = "Field '$field' must be at least $param characters.";
						}
					} elseif ($rule === 'max' && $param !== null) {
						if (is_string($val) && strlen($val) > (int)$param) {
							$errors[$field][] = "Field '$field' must be at most $param characters.";
						}
					} elseif ($rule === 'in' && $param !== null) {
						$allowedVals = explode(',', strtolower($param));
						if (!in_array(strtolower((string)$val), $allowedVals)) {
							$errors[$field][] = "Value for '$field' is not permitted.";
						}
					}
				}
			}

			if (is_string($val)) {
				$sanitized[$field] = $this->sanitize($val);
			} else {
				$sanitized[$field] = $val;
			}
		}

		return [
			'valid' => empty($errors),
			'errors' => $errors,
			'data' => $sanitized
		];
	}

	public function safe_get($key, $default = null, $type = 'string'){
		$val = $_GET[$key] ?? $default;
		return $this->cast_and_sanitize_param($val, $type);
	}

	public function safe_post($key, $default = null, $type = 'string'){
		$val = $_POST[$key] ?? $default;
		return $this->cast_and_sanitize_param($val, $type);
	}

	public function safe_request($key, $default = null, $type = 'string'){
		$val = $_POST[$key] ?? ($_GET[$key] ?? $default);
		return $this->cast_and_sanitize_param($val, $type);
	}

	private function cast_and_sanitize_param($val, $type){
		if ($val === null) return null;
		switch ($type) {
			case 'int':
			case 'integer':
				return is_numeric($val) ? (int)$val : 0;
			case 'float':
				return is_numeric($val) ? (float)$val : 0.0;
			case 'bool':
			case 'boolean':
				return filter_var($val, FILTER_VALIDATE_BOOLEAN);
			case 'email':
				$cleaned = filter_var(trim((string)$val), FILTER_SANITIZE_EMAIL);
				return filter_var($cleaned, FILTER_VALIDATE_EMAIL) ? strtolower($cleaned) : '';
			case 'alphanumeric':
				return preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)$val);
			case 'string':
			default:
				return $this->sanitize($val);
		}
	}
}
