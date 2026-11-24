<?php

class login extends Controller {

    public function index() {
        $this->start_default_session_app();
        if (!empty($_SESSION['user-id']) && !empty($_SESSION['user-role'])) {
            header('Location: ' . APP_PATH . '/dashboard');
            exit();
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $this->auth();
            return;
        }

        $flashError = $_SESSION['login_error'] ?? '';
        $flashSuccess = $_SESSION['login_success'] ?? '';
        unset($_SESSION['login_error'], $_SESSION['login_success']);

        if (isset($_GET['timeout'])) {
            $flashError = 'Your session expired due to inactivity. Please sign in again.';
        } elseif (isset($_GET['revoked'])) {
            $flashError = 'Your session has been revoked by an administrator.';
        } elseif (isset($_GET['auth'])) {
            $flashError = 'Please sign in to access the FILKOM AI Agent Orchestrator.';
        } elseif (isset($_GET['logout'])) {
            $flashSuccess = 'You have signed out safely.';
        }

        $data = [
            'title' => 'Sign In — ' . APP_NAME,
            'app_name' => APP_NAME,
            'csrf_token' => $this->get_csrf_token(),
            'error_message' => $flashError,
            'success_message' => $flashSuccess
        ];

        $this->display('login/index', $data);
    }

    public function auth() {
        $this->start_default_session_app();
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                  || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false)
                  || (isset($_SERVER['CONTENT_TYPE']) && strpos($_SERVER['CONTENT_TYPE'], 'application/json') !== false);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => 'HTTP method not allowed.'], 405);
            }
            header('Location: ' . APP_PATH . '/login');
            exit();
        }

        $payload = $this->readPostOrJsonPayload($isAjax);
        $csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->verify_csrf_token($csrf)) {
            $msg = 'CSRF security validation failed. Reload the page and retry.';
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => $msg], 403);
            }
            $_SESSION['login_error'] = $msg;
            header('Location: ' . APP_PATH . '/login');
            exit();
        }

        $identifier = trim((string)($payload['identifier'] ?? ($payload['username'] ?? '')));
        $password = (string)($payload['password'] ?? '');
        $totpCode = isset($payload['mfa_code']) ? trim((string)$payload['mfa_code']) : null;
        $ip = Security::get_client_ip();
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255);

        if (!Security::check_login_rate_limit($identifier, $ip)) {
            Security::log_incident('LOGIN_BRUTEFORCE_LOCK', "Identifier: {$identifier} from IP {$ip}", 'Blocked 429');
            $msg = 'Too many failed login attempts. Please wait 15 minutes.';
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => $msg], 429);
            }
            $_SESSION['login_error'] = $msg;
            header('Location: ' . APP_PATH . '/login');
            exit();
        }

        $validation = $this->validate_input(
            ['identifier' => $identifier, 'password' => $password],
            ['identifier' => 'required|min:3|max:120', 'password' => 'required|min:4|max:128']
        );

        if (!$validation['valid']) {
            $msg = 'Username/email or password does not meet validation rules.';
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => $msg], 400);
            }
            $_SESSION['login_error'] = $msg;
            header('Location: ' . APP_PATH . '/login');
            exit();
        }

        $authSvc = $this->logic('AuthService');
        $res = $authSvc->authenticate($identifier, $password, $totpCode, $ip, $ua);

        if (empty($res['success'])) {
            $this->log_user_login(null, $identifier, !empty($res['mfa_required']) ? 'MFA_Required' : 'Failed', $res['message']);
            if ($isAjax) {
                $this->json_response([
                    'success' => false,
                    'mfa_required' => !empty($res['mfa_required']),
                    'message' => $res['message']
                ], !empty($res['mfa_required']) ? 200 : 401);
            }
            $_SESSION['login_error'] = $res['message'];
            header('Location: ' . APP_PATH . '/login');
            exit();
        }

        $user = $res['user'];
        @session_regenerate_id(true);
        $_SESSION['user-id'] = (int)$user['id'];
        $_SESSION['user-nip'] = (string)$user['id'];
        $_SESSION['user-username'] = (string)$user['username'];
        $_SESSION['user-email'] = (string)$user['email'];
        $_SESSION['user-name'] = (string)$user['full_name'];
        $_SESSION['user-role'] = (string)$user['role'];
        $_SESSION['user-roles'] = $user['roles'] ?? [$user['role']];
        $_SESSION['user-zone'] = (string)($user['zone_code'] ?? 'COMMAND_CENTER');
        $_SESSION['user-department'] = (string)($user['department'] ?? 'Fakultas Ilmu Komputer');
        $_SESSION['user-phone'] = (string)($user['phone'] ?? '');
        $_SESSION['user-color'] = (string)($user['avatar_color'] ?? '#2563eb');
        $_SESSION['LAST_ACTIVITY'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

        $this->track_user_session((string)$user['id'], (string)$user['email'], (string)$user['full_name'], (string)$user['role']);
        $this->log_user_login((string)$user['id'], (string)$user['username'], 'SUCCESS', null);
        $this->audit_log('USER_LOGIN', 'tbl_users', (string)$user['id'], "User {$user['username']} ({$user['role']}) signed in.");

        if ($isAjax) {
            $this->json_response([
                'success' => true,
                'role' => $user['role'],
                'message' => 'Authentication successful. Redirecting...',
                'redirect' => APP_PATH . '/dashboard'
            ]);
        }

        header('Location: ' . APP_PATH . '/dashboard');
        exit();
    }

    public function request_reset() {
        $this->start_default_session_app(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json_response(['success' => false, 'message' => 'POST method required.'], 405);
        }
        $payload = $this->readPostOrJsonPayload(true);
        $csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->verify_csrf_token($csrf)) {
            $this->json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        $ident = trim((string)($payload['identifier'] ?? ''));
        $authSvc = $this->logic('AuthService');
        $this->json_response($authSvc->initiatePasswordReset($ident));
    }

    public function confirm_reset() {
        $this->start_default_session_app(true);
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            $this->json_response(['success' => false, 'message' => 'POST method required.'], 405);
        }
        $payload = $this->readPostOrJsonPayload(true);
        $csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (!$this->verify_csrf_token($csrf)) {
            $this->json_response(['success' => false, 'message' => 'Invalid CSRF token.'], 403);
        }
        $token = trim((string)($payload['token'] ?? ''));
        $newPwd = (string)($payload['new_password'] ?? '');
        $authSvc = $this->logic('AuthService');
        $res = $authSvc->confirmPasswordReset($token, $newPwd);
        $this->json_response($res, !empty($res['success']) ? 200 : 400);
    }

    private function readPostOrJsonPayload(bool $isAjax): array {
        $rawInput = file_get_contents('php://input');
        $jsonBody = [];
        if (!empty($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                $jsonBody = $decoded;
            }
        }
        $payload = array_merge($_POST, $jsonBody);
        if (Security::detect_sqli($payload) || Security::detect_xss($payload) || Security::detect_rce_payload($payload) || Security::detect_path_traversal($payload)) {
            Security::log_incident('LOGIN_WAF_BLOCKED', substr(json_encode($payload), 0, 250), 'Blocked 400');
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => 'Request rejected by security filter.'], 400);
            }
            $_SESSION['login_error'] = 'Request rejected by security filter.';
            header('Location: ' . APP_PATH . '/login');
            exit();
        }
        return $payload;
    }
}
