<?php

class landing extends Controller {

    public function index() {
        $this->start_default_session_app();
        if (!empty($_SESSION['user-id']) && !empty($_SESSION['user-role'])) {
            header('Location: ' . APP_PATH . '/dashboard');
            exit();
        }
        header('Location: ' . APP_PATH . '/login');
        exit();
    }

    public function api($actionParam = '') {
        $this->require_authentication([], true);
        $this->json_response([
            'success' => false,
            'message' => 'Please use the authenticated Orchestrator Portal endpoints.'
        ], 410);
    }
}
