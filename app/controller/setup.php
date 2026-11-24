<?php

class setup extends Controller {

    public function index() {
        header('Location: ' . APP_PATH . '/');
        exit();
    }

    public function init() {
        $this->start_default_session_app();
        $isAjax = (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest')
                  || (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false);

        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => 'HTTP method not allowed.'], 405);
            }
            header('Location: ' . APP_PATH . '/');
            exit();
        }

        $db = new Database();
        $isAlreadyProvisioned = $db->isConnected() && $db->hasTable('tbl_users') && $db->getTableRowCount('tbl_users') > 0;
        if ($isAlreadyProvisioned) {
            if (empty($_SESSION['user-name']) || strtolower((string)($_SESSION['user-role'] ?? '')) !== 'admin') {
                Security::log_incident('UNAUTHENTICATED_SETUP_ATTEMPT', 'Attempt to re-initialize provisioned database', 'Blocked 403');
                if ($isAjax) {
                    $this->json_response(['success' => false, 'message' => 'Unauthorized: Admin authentication required.'], 403);
                }
                $this->abort(403, 'Akses ditolak. Inisialisasi ulang database hanya diperbolehkan untuk Administrator.');
            }
            $csrf = $_POST['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (!$this->verify_csrf_token($csrf)) {
                if ($isAjax) {
                    $this->json_response(['success' => false, 'message' => 'CSRF token validation failed.'], 403);
                }
                $this->abort(403, 'Token CSRF tidak valid.');
            }
        }

        $sqlPath = BASEPATH . '/database/db_agents_orchestrator.sql';
        if (!file_exists($sqlPath)) {
            $msg = 'Database schema file database/db_agents_orchestrator.sql was not found.';
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => $msg], 404);
            }
            header('Location: ' . APP_PATH . '/');
            exit();
        }

        $result = $db->importSqlFile($sqlPath);
        if ($result['success']) {
            $msg = 'Database db_agents_orchestrator initialization completed successfully!';
            if ($isAjax) {
                $this->json_response(['success' => true, 'message' => $msg]);
            }
        } else {
            $msg = 'Database initialization failed.';
            if ($isAjax) {
                $this->json_response(['success' => false, 'message' => $msg], 500);
            }
        }

        header('Location: ' . APP_PATH . '/');
        exit();
    }
}
