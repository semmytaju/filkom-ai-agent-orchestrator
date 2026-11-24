<?php

class OrchestratorEngine {
    private Database $db;
    private RbacService $rbac;
    private AgentRegistryService $registry;
    private AgentExecutionService $executor;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
        $this->rbac = new RbacService($this->db);
        $this->registry = new AgentRegistryService($this->db);
        $this->executor = new AgentExecutionService($this->db);
    }

    public function handleUserRequest(int $userId, string $message, ?int $conversationId = null, array $options = []): array {
        $tStart = microtime(true);
        $cleanMsg = trim($message);
        if ($userId <= 0 || $cleanMsg === '') {
            return ['success' => false, 'message' => 'User authentication and message text are required.'];
        }

        $userContext = $this->rbac->getUserContext($userId);
        if (!$userContext) {
            return ['success' => false, 'message' => 'User context unavailable.'];
        }

        $authorizedAgents = $this->registry->getAuthorizedAgentsForUser($userId);
        if (empty($authorizedAgents)) {
            return ['success' => false, 'message' => 'No active authorized AI agents are assigned to your role.'];
        }

        $convId = $this->ensureConversation($userId, (string)$userContext['primary_role_code'], $conversationId, $cleanMsg);
        $history = $this->getRecentMessages($convId, 8);
        $this->insertMessage($convId, 'user', null, $cleanMsg);

        $taskUuid = 'TSK-' . strtoupper(bin2hex(random_bytes(5)));
        $progId = !empty($userContext['program_ids']) ? (int)$userContext['program_ids'][0] : null;
        $this->db->runPrepared(
            "INSERT INTO tbl_agent_tasks (task_uuid, user_id, role_code, study_program_id, conversation_id, user_message, execution_mode, status, progress, total_agents)
             VALUES (?, ?, ?, ?, ?, ?, 'single', 'routing', 10, 1)",
            [$taskUuid, $userId, (string)$userContext['primary_role_code'], $progId, $convId, $cleanMsg]
        );
        $taskId = $this->db->lastInsertId();

        $userZone = (string)$userContext['zone_code'];
        $this->executor->emitEvent($taskId, $userId, 'ORCHESTRATOR_HUB', 'routing', 'Routing request to authorized agents', 12, 'thinking', 'COMMAND_CENTER');
        $this->executor->logTaskStep($taskId, null, 'routing', null, 'ok', "User {$userContext['username']} ({$userContext['primary_role_code']}) submitted request.", 0);

        $routing = $this->routeAgents($cleanMsg, $authorizedAgents, $options['preferred_agent_code'] ?? null);
        $selectedAgents = $routing['agents'];
        $mode = count($selectedAgents) > 1 ? 'multi' : 'single';

        $this->db->runPrepared(
            "UPDATE tbl_agent_tasks SET execution_mode = ?, status = 'running', total_agents = ?, progress = 20 WHERE id = ?",
            [$mode, count($selectedAgents), $taskId]
        );

        $agentNames = array_map(static fn($a) => $a['agent_name'], $selectedAgents);
        $this->executor->logTaskStep($taskId, (int)$selectedAgents[0]['id'], 'agent_selection', null, 'ok', "Orchestrator selected ({$mode}): " . implode(', ', $agentNames), 0);

        $agentOutputs = [];
        $allCitations = [];
        $allToolTraces = [];
        $totalTokens = 0;
        $totalCost = 0.0;
        $stepSpan = (int)floor(62 / max(1, count($selectedAgents)));

        foreach ($selectedAgents as $idx => $agent) {
            $baseP = 20 + ($idx * $stepSpan);
            $endP = min(85, $baseP + $stepSpan);

            if ($mode === 'multi') {
                $this->db->runPrepared(
                    "INSERT INTO tbl_agent_collaborations (task_id, parent_agent_id, child_agent_id, subtask_prompt, status, sequence_order)
                     VALUES (?, NULL, ?, ?, 'running', ?)",
                    [$taskId, (int)$agent['id'], $cleanMsg, $idx + 1]
                );
                $collabId = $this->db->lastInsertId();
            } else {
                $collabId = 0;
            }

            $out = $this->executor->executeSingleAgent($taskId, $agent, $userContext, $cleanMsg, $history, $baseP, $endP);
            $agentOutputs[] = $out;
            $allCitations = array_merge($allCitations, $out['citations'] ?? []);
            $allToolTraces = array_merge($allToolTraces, $out['tool_traces'] ?? []);
            $totalTokens += (int)($out['usage']['total_tokens'] ?? 0);
            $totalCost += (float)($out['usage']['estimated_cost_usd'] ?? 0);

            if ($collabId > 0) {
                $this->db->runPrepared(
                    "UPDATE tbl_agent_collaborations SET result_summary = ?, status = 'completed' WHERE id = ?",
                    [mb_substr((string)$out['reply'], 0, 500, 'UTF-8'), $collabId]
                );
            }
        }

        if ($mode === 'multi') {
            $this->executor->emitEvent($taskId, $userId, 'ORCHESTRATOR_HUB', 'collaborating', 'Synthesizing multi-agent executive report', 90, 'collaborating', 'COMMAND_CENTER');
            $finalReply = $this->synthesizeMultiAgentOutputs($cleanMsg, $userContext, $agentOutputs, $taskId);
        } else {
            $finalReply = (string)($agentOutputs[0]['reply'] ?? 'No response generated.');
        }

        $primaryAgent = $selectedAgents[0];
        $latencyMs = (int)round((microtime(true) - $tStart) * 1000);

        $this->db->runPrepared(
            "UPDATE tbl_agent_tasks SET status = 'completed', progress = 100, latency_ms = ?, completed_at = NOW() WHERE id = ?",
            [$latencyMs, $taskId]
        );
        $this->db->runPrepared(
            "UPDATE tbl_conversations SET last_agent_code = ?, updated_at = NOW() WHERE id = ?",
            [(string)$primaryAgent['agent_code'], $convId]
        );

        $msgMeta = [
            'task_id' => $taskId,
            'task_uuid' => $taskUuid,
            'mode' => $mode,
            'agents_used' => array_map(static fn($o) => ['agent_code' => $o['agent_code'], 'agent_name' => $o['agent_name'], 'zone_code' => $o['zone_code'], 'mode' => $o['mode']], $agentOutputs),
            'total_tokens' => $totalTokens,
            'estimated_cost_usd' => round($totalCost, 6),
            'latency_ms' => $latencyMs
        ];
        $assistantMsgId = $this->insertMessage($convId, $mode === 'multi' ? 'orchestrator' : 'agent', (int)$primaryAgent['id'], $finalReply, $msgMeta, $allCitations);

        $this->executor->logTaskStep($taskId, (int)$primaryAgent['id'], 'completed', null, 'completed', "Task {$taskUuid} completed in {$latencyMs}ms.", $latencyMs);
        $this->executor->emitEvent($taskId, $userId, (string)$primaryAgent['agent_code'], 'success', 'Task completed', 100, 'success', (string)$primaryAgent['zone_code'], ['conversation_id' => $convId]);

        return [
            'success' => true,
            'task_id' => $taskId,
            'task_uuid' => $taskUuid,
            'conversation_id' => $convId,
            'message_id' => $assistantMsgId,
            'execution_mode' => $mode,
            'primary_agent' => [
                'agent_code' => $primaryAgent['agent_code'],
                'agent_name' => $primaryAgent['agent_name'],
                'zone_code' => $primaryAgent['zone_code']
            ],
            'agents_used' => $msgMeta['agents_used'],
            'reply' => $finalReply,
            'citations' => $allCitations,
            'tool_traces' => $allToolTraces,
            'total_tokens' => $totalTokens,
            'estimated_cost_usd' => round($totalCost, 6),
            'latency_ms' => $latencyMs,
            'animation' => 'success',
            'zone' => $userZone
        ];
    }

    public function routeAgents(string $message, array $authorizedAgents, ?string $preferredAgentCode = null): array {
        if ($preferredAgentCode !== null && trim($preferredAgentCode) !== '') {
            foreach ($authorizedAgents as $ag) {
                if (strcasecmp((string)$ag['agent_code'], trim($preferredAgentCode)) === 0) {
                    return ['agents' => [$ag], 'reason' => 'explicit_selection'];
                }
            }
        }

        $low = mb_strtolower($message, 'UTF-8');
        $isBroadExecutive = (bool)preg_match('/\b(overall|overview|entire|across\s+filkom|faculty[\s\-]*wide|keseluruhan|menyeluruh|semua\s+aspek|comprehensive|compar(e|ing)|bandingkan|executive\s+summary|analisis\s+kinerja\s+filkom)\b/iu', $low);

        $scored = [];
        foreach ($authorizedAgents as $ag) {
            $baseScore = (float)$ag['priority'] * 0.05;
            $matchScore = 0.0;
            $haystack = mb_strtolower($ag['agent_name'] . ' ' . $ag['description'] . ' ' . $ag['agent_code'], 'UTF-8');
            $words = preg_split('/[^a-z0-9_]+/u', $low, -1, PREG_SPLIT_NO_EMPTY);
            foreach ($words as $w) {
                if (strlen($w) >= 3 && strpos($haystack, $w) !== false) {
                    $matchScore += 3.0;
                }
            }
            foreach (($ag['tools'] ?? []) as $t) {
                $kws = array_filter(array_map('trim', explode(',', mb_strtolower((string)($t['trigger_keywords'] ?? ''), 'UTF-8'))));
                foreach ($kws as $kw) {
                    if ($kw !== '' && strpos($low, $kw) !== false) {
                        $matchScore += 4.5;
                    }
                }
            }
            $scored[] = ['score' => $baseScore + $matchScore, 'match' => $matchScore, 'agent' => $ag];
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);

        $strongMatches = array_values(array_filter($scored, static fn($e) => $e['match'] >= 4.5));
        if (($isBroadExecutive && count($scored) >= 3) || count($strongMatches) >= 2) {
            $pool = $isBroadExecutive ? $scored : $strongMatches;
            $picked = [];
            $seenCodes = [];
            foreach ($pool as $entry) {
                if (count($picked) >= 4) break;
                $ag = $entry['agent'];
                $code = $ag['agent_code'];
                if (!isset($seenCodes[$code])) {
                    $picked[] = $ag;
                    $seenCodes[$code] = true;
                }
            }
            if (count($picked) >= 2) {
                return ['agents' => $picked, 'reason' => $isBroadExecutive ? 'multi_agent_executive' : 'multi_agent_collaborative'];
            }
        }

        return ['agents' => [$scored[0]['agent']], 'reason' => 'single_best_match'];
    }

    private function synthesizeMultiAgentOutputs(string $originalQuery, array $userContext, array $agentOutputs, int $taskId): string {
        $sections = [];
        foreach ($agentOutputs as $out) {
            $sections[] = "### {$out['agent_name']}\n{$out['reply']}";
        }
        $combinedContext = implode("\n\n", $sections);

        $respSvc = new ResponseService($this->db);
        if ($respSvc->hasConfiguredKey()) {
            $aiRes = $respSvc->createResponse([
                'model' => 'gpt-4.1-mini',
                'instructions' => 'Synthesize the multiple authorized agent analyses below into one unified, executive-grade report. Preserve all exact figures and document citations without inventing any facts.',
                'input' => "User Query: {$originalQuery}\n\nAgent Reports:\n{$combinedContext}",
                'max_output_tokens' => 1100,
                'temperature' => 0.30
            ], ['task_id' => $taskId, 'user_id' => (int)$userContext['user_id']]);
            if (!empty($aiRes['ok']) && !empty($aiRes['output_text'])) {
                return (string)$aiRes['output_text'];
            }
        }

        return "**FILKOM Multi-Agent Executive Synthesis**\n\n" . $combinedContext;
    }

    private function ensureConversation(int $userId, string $roleCode, ?int $conversationId, string $firstMsg): int {
        if ($conversationId !== null && $conversationId > 0) {
            $st = $this->db->runPrepared("SELECT id FROM tbl_conversations WHERE id = ? AND user_id = ? LIMIT 1", [$conversationId, $userId]);
            if ($st && $st->fetch()) {
                return $conversationId;
            }
        }
        $uuid = 'CONV-' . strtoupper(bin2hex(random_bytes(5)));
        $title = mb_substr(preg_replace('/\s+/', ' ', trim($firstMsg)), 0, 80, 'UTF-8') ?: 'Orchestration Session';
        $this->db->runPrepared(
            "INSERT INTO tbl_conversations (conversation_uuid, user_id, role_code, title) VALUES (?, ?, ?, ?)",
            [$uuid, $userId, $roleCode, $title]
        );
        return $this->db->lastInsertId();
    }

    private function getRecentMessages(int $convId, int $limit = 8): array {
        $st = $this->db->runPrepared(
            "SELECT sender_type, content FROM tbl_messages WHERE conversation_id = ? ORDER BY id DESC LIMIT ?",
            [$convId, $limit]
        );
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        return array_reverse($rows);
    }

    private function insertMessage(int $convId, string $senderType, ?int $agentId, string $content, array $meta = [], array $citations = []): int {
        $this->db->runPrepared(
            "INSERT INTO tbl_messages (conversation_id, sender_type, agent_id, content, metadata_json, citations_json)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $convId,
                $senderType,
                $agentId,
                $content,
                !empty($meta) ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
                !empty($citations) ? json_encode($citations, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
            ]
        );
        return $this->db->lastInsertId();
    }

    public function getUserConversations(int $userId, int $limit = 20): array {
        if ($userId <= 0 || !$this->db->isConnected()) return [];
        $st = $this->db->runPrepared(
            "SELECT id, conversation_uuid, role_code, title, last_agent_code, created_at, updated_at
             FROM tbl_conversations WHERE user_id = ? ORDER BY updated_at DESC LIMIT ?",
            [$userId, $limit]
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getConversationMessages(int $convId, int $userId): array {
        if ($convId <= 0 || $userId <= 0 || !$this->db->isConnected()) return [];
        $stCheck = $this->db->runPrepared("SELECT id FROM tbl_conversations WHERE id = ? AND user_id = ? LIMIT 1", [$convId, $userId]);
        if (!$stCheck || !$stCheck->fetch()) return [];
        $st = $this->db->runPrepared(
            "SELECT m.*, a.agent_code, a.agent_name
             FROM tbl_messages m
             LEFT JOIN tbl_agents a ON a.id = m.agent_id
             WHERE m.conversation_id = ?
             ORDER BY m.id ASC",
            [$convId]
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
