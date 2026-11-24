<?php

class logout extends Controller {

    public function index() {
        $this->start_default_session_app();
        if (!empty($_SESSION['user-name'])) {
            $this->audit_log('USER_LOGOUT', 'tbl_users', (string)($_SESSION['user-id'] ?? ''), "User {$_SESSION['user-name']} signed out.");
            $this->destroy_user_session_db(session_id());
        }
        $this->destroy_session_cleanly();
        header('Location: ' . APP_PATH . '/login/?logout=1');
        exit();
    }
}
