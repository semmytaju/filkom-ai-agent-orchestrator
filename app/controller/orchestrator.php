<?php

class orchestrator extends Controller {

    public function index() {
        $this->require_authentication();
        header('Location: ' . APP_PATH . '/dashboard#command_center');
        exit();
    }

    private function checkOrchestratorRateLimit(int $maxPerMin = 90): void {
        $now = time();
        if (!isset($_SESSION['_orch_rate']) || !is_array($_SESSION['_orch_rate'])) {
            $_SESSION['_orch_rate'] = [];
        }
        $ts = array_values(array_filter($_SESSION['_orch_rate'], static fn($t) => ($now - (int)$t) < 60));
        if (count($ts) >= $maxPerMin) {
            Security::log_incident('ORCHESTRATOR_RATE_LIMIT', 'Exceeded orchestrator rate limit', 'Blocked 429');
            $this->json_response(['success' => false, 'message' => 'Rate limit exceeded. Please wait briefly.'], 429);
        }
        $ts[] = $now;
        $_SESSION['_orch_rate'] = $ts;
    }

    public function api($actionParam = '') {
        $this->require_authentication([], true);
        $userId = (int)($_SESSION['user-id'] ?? 0);

        $rawInput = file_get_contents('php://input');
        $jsonBody = [];
        if (!empty($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                if (Security::detect_rce_payload($decoded) || Security::detect_path_traversal($decoded) || Security::detect_xss($decoded)) {
                    Security::log_incident('ORCHESTRATOR_WAF_BLOCK', substr($rawInput, 0, 250), 'Blocked 400');
                    $this->json_response(['success' => false, 'message' => 'Payload rejected by security policy.'], 400);
                }
                $jsonBody = $decoded;
            }
        }
        $payload = array_merge($_GET, $_POST, $jsonBody);
        $action = strtolower(trim((string)($actionParam ?: ($payload['action'] ?? 'bootstrap'))));

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (!$this->verify_csrf_token($csrf)) {
                $this->json_response(['success' => false, 'message' => 'Invalid CSRF security token.'], 403);
            }
        }

        $rbac = $this->logic('RbacService');
        $userContext = $rbac->getUserContext($userId);
        if (!$userContext) {
            $this->json_response(['success' => false, 'message' => 'Active user context unavailable.'], 401);
        }

        $registry = $this->logic('AgentRegistryService');
        $engine = $this->logic('OrchestratorEngine');
        $monitor = $this->logic('MonitoringService');

        try {
            switch ($action) {
                case 'bootstrap':
                    $agents = $registry->getAuthorizedAgentsForUser($userId);
                    $cleanAgents = array_map(static function ($ag) {
                        return [
                            'id' => (int)$ag['id'],
                            'agent_code' => $ag['agent_code'],
                            'agent_name' => $ag['agent_name'],
                            'description' => $ag['description'],
                            'agent_category' => $ag['agent_category'],
                            'zone_code' => $ag['zone_code'],
                            'avatar_model' => $ag['avatar_model'],
                            'avatar_outfit' => $ag['avatar_outfit'],
                            'animation_profile' => $ag['animation_profile'],
                            'data_scope' => $ag['data_scope'],
                            'model_name' => $ag['model_name'],
                            'tool_count' => count($ag['tools'] ?? []),
                            'kb_count' => count($ag['knowledge_bases'] ?? [])
                        ];
                    }, $agents);

                    $events = $monitor->getLatestEventsForUser($userId, max(0, (int)($payload['after_id'] ?? 0)), 10);
                    $lastEvId = !empty($events) ? (int)end($events)['id'] : 0;

                    $this->json_response([
                        'success' => true,
                        'user' => [
                            'user_id' => $userId,
                            'full_name' => $userContext['full_name'],
                            'role_code' => $userContext['primary_role_code'],
                            'role_name' => $userContext['primary_role_name'],
                            'roles' => $userContext['role_codes'],
                            'zone_code' => $userContext['zone_code'],
                            'program_codes' => $userContext['program_codes'],
                            'student' => $userContext['student']
                        ],
                        'authorized_agents' => $cleanAgents,
                        'conversations' => $engine->getUserConversations($userId, 15),
                        'monitoring' => $monitor->getOverview($userContext),
                        'last_event_id' => $lastEvId
                    ]);
                    break;

                case 'execute':
                    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                        $this->json_response(['success' => false, 'message' => 'POST method required.'], 405);
                    }
                    $this->checkOrchestratorRateLimit(60);
                    if (!$rbac->userHasPermission($userId, 'agents.use')) {
                        $this->json_response(['success' => false, 'message' => 'Permission agents.use required.'], 403);
                    }
                    $message = trim((string)($payload['message'] ?? ''));
                    if ($message === '' || mb_strlen($message, 'UTF-8') > 4000) {
                        $this->json_response(['success' => false, 'message' => 'Message must be between 1 and 4000 characters.'], 400);
                    }
                    $convId = !empty($payload['conversation_id']) ? (int)$payload['conversation_id'] : null;
                    $prefAgent = !empty($payload['preferred_agent_code']) ? trim((string)$payload['preferred_agent_code']) : null;

                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_write_close();
                    }

                    $res = $engine->handleUserRequest($userId, $message, $convId, ['preferred_agent_code' => $prefAgent]);
                    if (!empty($res['success'])) {
                        $this->audit_log('ORCHESTRATOR_EXECUTE', 'tbl_agent_tasks', (string)$res['task_id'], "Executed {$res['execution_mode']} orchestration via {$res['primary_agent']['agent_code']}");
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'conversations':
                    $convId = (int)($payload['conversation_id'] ?? 0);
                    if ($convId > 0) {
                        $this->json_response([
                            'success' => true,
                            'messages' => $engine->getConversationMessages($convId, $userId)
                        ]);
                    }
                    $this->json_response([
                        'success' => true,
                        'conversations' => $engine->getUserConversations($userId, 20)
                    ]);
                    break;

                case 'events':
                    $afterId = max(0, (int)($payload['after_id'] ?? 0));
                    $events = $monitor->getLatestEventsForUser($userId, $afterId, 30);
                    $this->json_response([
                        'success' => true,
                        'events' => $events
                    ]);
                    break;

                case 'feedback':
                    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
                        $this->json_response(['success' => false, 'message' => 'POST required.'], 405);
                    }
                    $msgId = (int)($payload['message_id'] ?? 0);
                    $rating = (int)($payload['rating'] ?? 0);
                    if ($msgId <= 0 || !in_array($rating, [-1, 1], true)) {
                        $this->json_response(['success' => false, 'message' => 'Valid message_id and rating (-1 or 1) required.'], 400);
                    }
                    $db = new Database();
                    $db->runPrepared(
                        "UPDATE tbl_messages m
                         INNER JOIN tbl_conversations c ON c.id = m.conversation_id
                         SET m.satisfaction_rating = ?
                         WHERE m.id = ? AND c.user_id = ?",
                        [$rating, $msgId, $userId]
                    );
                    $this->json_response(['success' => true, 'message' => 'Feedback recorded.']);
                    break;

                default:
                    $this->json_response(['success' => false, 'message' => 'Unknown orchestrator API action.'], 404);
            }
        } catch (Throwable $e) {
            error_log('Orchestrator API error: ' . $e->getMessage());
            $this->json_response(['success' => false, 'message' => 'Orchestrator processing error.'], 500);
        }
    }

    public function stream() {
        $this->require_authentication([], true);
        $userId = (int)($_SESSION['user-id'] ?? 0);
        $lastId = max(0, (int)($_GET['last_id'] ?? ($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0)));

        if (session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }

        @ini_set('zlib.output_compression', '0');
        while (ob_get_level() > 0) {
            @ob_end_flush();
        }
        header('Content-Type: text/event-stream; charset=utf-8');
        header('Cache-Control: no-cache, no-store, must-revalidate');
        header('Connection: keep-alive');
        header('X-Accel-Buffering: no');

        $monitor = $this->logic('MonitoringService');
        if ($lastId === 0) {
            $recent = $monitor->getLatestEventsForUser($userId, 0, 5);
            if (!empty($recent)) {
                $lastId = (int)end($recent)['id'];
            }
        }

        echo "retry: 2000\n";
        echo "event: connected\n";
        echo "data: " . json_encode(['last_id' => $lastId, 'user_id' => $userId]) . "\n\n";
        @flush();

        $started = time();
        $lastPing = $started;
        $maxSeconds = isset($_GET['poll_window']) ? max(1, min(18, (int)$_GET['poll_window'])) : 18;

        while ((time() - $started) < $maxSeconds) {
            if (connection_aborted()) {
                break;
            }
            $events = $monitor->getLatestEventsForUser($userId, $lastId, 20);
            if (!empty($events)) {
                foreach ($events as $ev) {
                    $lastId = (int)$ev['id'];
                    $out = [
                        'event_id' => $lastId,
                        'task_id' => (int)$ev['task_id'],
                        'agent_id' => $ev['agent_id'],
                        'status' => $ev['status'],
                        'task' => $ev['task'],
                        'progress' => (int)$ev['progress'],
                        'animation' => $ev['animation'],
                        'zone' => $ev['zone'],
                        'created_at' => $ev['created_at']
                    ];
                    echo "id: {$lastId}\n";
                    echo "event: agent_status\n";
                    echo "data: " . json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n\n";
                }
                @flush();
                $lastPing = time();
            } elseif ((time() - $lastPing) >= 5) {
                echo "event: ping\n";
                echo "data: " . json_encode(['ts' => time()]) . "\n\n";
                @flush();
                $lastPing = time();
            }
            usleep(350000);
        }
        exit();
    }
}
