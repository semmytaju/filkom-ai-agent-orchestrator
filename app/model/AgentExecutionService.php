<?php

class AgentExecutionService {
    private Database $db;
    private ToolRegistryService $toolRegistry;
    private RagService $rag;
    private AgentPromptBuilder $promptBuilder;
    private FunctionCallingService $functionCaller;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
        $this->toolRegistry = new ToolRegistryService($this->db);
        $this->rag = new RagService($this->db);
        $this->promptBuilder = new AgentPromptBuilder();
        $this->functionCaller = new FunctionCallingService($this->db);
    }

    public function emitEvent(?int $taskId, int $userId, string $agentCode, string $status, string $taskLabel, int $progress, string $animation, string $zoneCode, array $payload = []): void {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_agent_events')) {
            return;
        }
        $this->db->runPrepared(
            "INSERT INTO tbl_agent_events (task_id, user_id, agent_code, status, task_label, progress, animation, zone_code, payload_json)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $taskId,
                $userId,
                substr($agentCode, 0, 64),
                substr($status, 0, 32),
                substr($taskLabel, 0, 250),
                max(0, min(100, $progress)),
                substr($animation, 0, 32),
                substr($zoneCode, 0, 32),
                !empty($payload) ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null
            ]
        );
    }

    public function logTaskStep(int $taskId, ?int $agentId, string $stepType, ?string $toolCode, string $status, string $summary, int $durationMs = 0): void {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_agent_task_logs')) {
            return;
        }
        $this->db->runPrepared(
            "INSERT INTO tbl_agent_task_logs (task_id, agent_id, step_type, tool_code, status, summary, duration_ms)
             VALUES (?, ?, ?, ?, ?, ?, ?)",
            [$taskId, $agentId, $stepType, $toolCode, $status, $summary, $durationMs]
        );
    }

    public function executeSingleAgent(int $taskId, array $agentConfig, array $userContext, string $subtaskPrompt, array $history = [], int $baseProgress = 25, int $endProgress = 85): array {
        $tStart = microtime(true);
        $agentId = (int)$agentConfig['id'];
        $agentCode = (string)$agentConfig['agent_code'];
        $agentName = (string)$agentConfig['agent_name'];
        $zoneCode = (string)($agentConfig['zone_code'] ?? 'COMMAND_CENTER');
        $userId = (int)$userContext['user_id'];

        $this->emitEvent($taskId, $userId, $agentCode, 'searching', "{$agentName}: Retrieving authorized knowledge", $baseProgress, 'searching', $zoneCode);
        $ragChunks = $this->rag->retrieveForAgent($subtaskPrompt, $agentId, $userContext['role_ids'] ?? [], 3, 0.12);

        $authorizedTools = $agentConfig['tools'] ?? $this->toolRegistry->getToolsForAgent($agentId);
        $deterministicToolData = $this->runRelevantLocalTools($subtaskPrompt, $authorizedTools, $userContext, $agentConfig, $taskId);

        $this->emitEvent($taskId, $userId, $agentCode, 'thinking', "{$agentName}: Analyzing scoped data", (int)round(($baseProgress + $endProgress) / 2), 'thinking', $zoneCode);

        $instructions = $this->promptBuilder->buildInstructions($agentConfig, $userContext, $ragChunks, $deterministicToolData);
        $inputMsgs = [];
        foreach (array_slice($history, -4) as $hm) {
            $inputMsgs[] = ['role' => $hm['sender_type'] === 'user' ? 'user' : 'assistant', 'content' => (string)$hm['content']];
        }
        $inputMsgs[] = ['role' => 'user', 'content' => $subtaskPrompt];

        $telemetry = ['task_id' => $taskId, 'agent_id' => $agentId, 'user_id' => $userId];
        $fcResult = $this->functionCaller->runConversationWithTools(
            $agentConfig,
            $userContext,
            $instructions,
            $inputMsgs,
            $authorizedTools,
            $telemetry,
            function (string $st, string $lbl, string $anim) use ($taskId, $userId, $agentCode, $endProgress, $zoneCode) {
                $this->emitEvent($taskId, $userId, $agentCode, $st, $lbl, max(10, $endProgress - 10), $anim, $zoneCode);
            }
        );

        $latencyMs = (int)round((microtime(true) - $tStart) * 1000);
        $citations = array_map(static fn($c) => [
            'doc_code' => $c['doc_code'],
            'title' => $c['title'],
            'section' => $c['section_title'],
            'score' => $c['score']
        ], $ragChunks);

        if (!empty($fcResult['ok']) && !empty($fcResult['text'])) {
            $this->emitEvent($taskId, $userId, $agentCode, 'working', "{$agentName}: Completed analysis", $endProgress, 'typing', $zoneCode);
            $usage = $fcResult['usage'] ?? [];
            $this->recordExecutionHistory($taskId, $agentId, $userId, (string)$agentConfig['model_name'], $usage, $latencyMs, 'completed', (string)$fcResult['text']);
            $this->logTaskStep($taskId, $agentId, 'agent_response', null, 'completed', "{$agentName} synthesized response via OpenAI Responses API.", $latencyMs);

            return [
                'ok' => true,
                'mode' => 'openai_responses',
                'agent_id' => $agentId,
                'agent_code' => $agentCode,
                'agent_name' => $agentName,
                'zone_code' => $zoneCode,
                'reply' => (string)$fcResult['text'],
                'citations' => $citations,
                'tool_traces' => $fcResult['tool_traces'] ?? [],
                'local_tools' => array_keys($deterministicToolData),
                'usage' => $usage,
                'latency_ms' => $latencyMs
            ];
        }

        $fallbackReply = $this->composeGroundedFallbackReply($agentConfig, $userContext, $subtaskPrompt, $ragChunks, $deterministicToolData, (string)($fcResult['error'] ?? ''));
        $this->emitEvent($taskId, $userId, $agentCode, 'working', "{$agentName}: Prepared scoped tool report", $endProgress, 'typing', $zoneCode);
        $this->recordExecutionHistory($taskId, $agentId, $userId, 'local-deterministic-adapter', ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'estimated_cost_usd' => 0], $latencyMs, 'fallback', $fallbackReply);
        $this->logTaskStep($taskId, $agentId, 'agent_fallback', null, 'fallback', "{$agentName} executed scoped PHP tools + RAG adapter (" . (!empty($fcResult['missing_key']) ? 'OPENAI_API_KEY not configured' : ($fcResult['error'] ?? 'fallback')) . ").", $latencyMs);

        $toolTraces = array_map(
            static fn($tc) => ['tool' => $tc, 'status' => 'ok', 'source' => 'scoped_php_tool'],
            array_keys($deterministicToolData)
        );
        return [
            'ok' => true,
            'mode' => !empty($fcResult['missing_key']) ? 'deterministic_adapter_no_key' : 'deterministic_adapter_fallback',
            'llm_notice' => !empty($fcResult['missing_key']) ? 'OPENAI_API_KEY not configured; executed verified PHP tools and authorized RAG retrieval.' : (string)($fcResult['error'] ?? ''),
            'agent_id' => $agentId,
            'agent_code' => $agentCode,
            'agent_name' => $agentName,
            'zone_code' => $zoneCode,
            'reply' => $fallbackReply,
            'citations' => $citations,
            'tool_traces' => $toolTraces,
            'local_tools' => array_keys($deterministicToolData),
            'usage' => ['prompt_tokens' => 0, 'completion_tokens' => 0, 'total_tokens' => 0, 'estimated_cost_usd' => 0],
            'latency_ms' => $latencyMs
        ];
    }

    private function runRelevantLocalTools(string $prompt, array $authorizedTools, array $userContext, array $agentConfig, int $taskId): array {
        $low = mb_strtolower($prompt, 'UTF-8');
        $selected = [];
        foreach ($authorizedTools as $tool) {
            $tCode = (string)$tool['tool_code'];
            if ($tCode === 'search_documents') continue;
            $kws = array_filter(array_map('trim', explode(',', mb_strtolower((string)($tool['trigger_keywords'] ?? ''), 'UTF-8'))));
            $hits = 0;
            foreach ($kws as $kw) {
                if ($kw !== '' && strpos($low, $kw) !== false) {
                    $hits++;
                }
            }
            if ($hits > 0) {
                $selected[$tCode] = $hits;
            }
        }
        arsort($selected);
        $topCodes = array_slice(array_keys($selected), 0, 2);
        if (empty($topCodes) && !empty($authorizedTools)) {
            foreach ($authorizedTools as $t) {
                if ($t['tool_code'] !== 'search_documents' && $t['tool_code'] !== 'generate_report') {
                    $topCodes[] = $t['tool_code'];
                    break;
                }
            }
        }

        $results = [];
        foreach ($topCodes as $tc) {
            $exec = $this->toolRegistry->executeTool($tc, ['query' => $prompt], $userContext, $agentConfig);
            if (!empty($exec['ok'])) {
                $results[$tc] = $exec['data'];
                $this->logTaskStep($taskId, (int)$agentConfig['id'], 'tool_call', $tc, 'ok', "Executed {$tc} in {$exec['duration_ms']}ms", (int)$exec['duration_ms']);
            }
        }
        return $results;
    }

    private function composeGroundedFallbackReply(array $agentConfig, array $userContext, string $prompt, array $ragChunks, array $toolData, string $errNote): string {
        $agentName = (string)$agentConfig['agent_name'];
        $role = (string)$userContext['primary_role_code'];
        $lines = ["**{$agentName}** ({$role})"];

        if (!empty($toolData)) {
            foreach ($toolData as $toolCode => $payload) {
                if (!empty($payload['programs'])) {
                    $lines[] = "**Study Program Performance:**";
                    foreach ($payload['programs'] as $p) {
                        $lines[] = "- **{$p['program_code']}** ({$p['program_name']}): {$p['total_students']} students ({$p['active_students']} active, {$p['probation_students']} probation), Avg GPA **{$p['avg_gpa']}**, Avg Credits {$p['avg_credits']}";
                    }
                    if (!empty($payload['lecturers'])) {
                        $la = $payload['lecturers'];
                        $lines[] = "- Lecturers: {$la['total_lecturers']} | Avg Teaching Score: **{$la['avg_teaching_score']}** | Avg Research Score: **{$la['avg_research_score']}**";
                    }
                    if (!empty($payload['theses'])) {
                        $ta = $payload['theses'];
                        $lines[] = "- Theses: {$ta['total_theses']} total ({$ta['risk_theses']} at-risk/delayed, {$ta['ready_theses']} defense-ready/done), Avg Progress **{$ta['avg_progress_pct']}%**";
                    }
                } elseif (!empty($payload['students'])) {
                    $lines[] = "**Student Academic Records ({$payload['scope_applied']}):**";
                    foreach (array_slice($payload['students'], 0, 8) as $st) {
                        $lines[] = "- `{$st['nim']}` **{$st['full_name']}** ({$st['program_code']}, Sem {$st['current_semester']}): GPA **{$st['gpa']}**, {$st['total_credits']} credits, status: *{$st['academic_status']}*";
                    }
                } elseif (!empty($payload['attendance'])) {
                    $lines[] = "**Attendance Summary (Min 80%):**";
                    foreach (array_slice($payload['attendance'], 0, 8) as $at) {
                        $lines[] = "- `{$at['course_code']}` {$at['course_name']}: **{$at['attendance_pct']}%** ({$at['attended_sessions']}/{$at['total_sessions']} sessions)";
                    }
                } elseif (!empty($payload['theses'])) {
                    $lines[] = "**Thesis Monitoring:**";
                    foreach (array_slice($payload['theses'], 0, 8) as $th) {
                        $lines[] = "- **{$th['student_name']}** ({$th['program_code']}): *{$th['title']}* — Stage: {$th['stage']} ({$th['progress_pct']}%), Similarity: {$th['similarity_pct']}%, Status: **{$th['status']}**";
                    }
                } elseif (!empty($payload['schedules'])) {
                    $lines[] = "**Course Schedules:**";
                    foreach (array_slice($payload['schedules'], 0, 8) as $cs) {
                        $lines[] = "- `{$cs['course_code']}` {$cs['course_name']} ({$cs['credits']} SKS) — {$cs['day_of_week']} {$cs['start_time']}-{$cs['end_time']} @ {$cs['room']}";
                    }
                } elseif (!empty($payload['projects']) || !empty($payload['publications'])) {
                    $sm = $payload['summary'] ?? [];
                    $lines[] = "**Research & Publications Summary:** {$sm['project_count']} projects (Total Funding: IDR " . number_format((int)($sm['total_funding_idr'] ?? 0)) . "), {$sm['publication_count']} publications ({$sm['total_citations']} citations).";
                    foreach (array_slice($payload['projects'] ?? [], 0, 5) as $pr) {
                        $lines[] = "- `[{$pr['project_code']}]` {$pr['title']} — {$pr['lead_name']} ({$pr['funding_source']})";
                    }
                } elseif (!empty($payload['indicators']) || !empty($payload['evaluations'])) {
                    $lines[] = "**Quality Assurance & Accreditation (UPMF):**";
                    foreach (array_slice($payload['indicators'] ?? [], 0, 6) as $ind) {
                        $lines[] = "- `[{$ind['indicator_code']}]` {$ind['standard_name']} ({$ind['program_code']}): Actual **{$ind['actual_value']}** vs Target {$ind['target_value']} ({$ind['compliance_status']})";
                    }
                } elseif (!empty($payload['meetings']) || !empty($payload['correspondence'])) {
                    $lines[] = "**Faculty Secretariat Records:**";
                    foreach (array_slice($payload['meetings'] ?? [], 0, 4) as $mt) {
                        $lines[] = "- Meeting: **{$mt['title']}** ({$mt['meeting_date']} @ {$mt['location']})";
                    }
                    foreach (array_slice($payload['correspondence'] ?? [], 0, 4) as $cr) {
                        $lines[] = "- Letter `[{$cr['ref_number']}]`: {$cr['subject']} ({$cr['direction']})";
                    }
                } elseif (!empty($payload['lecturers'])) {
                    $lines[] = "**Lecturer Performance:**";
                    foreach (array_slice($payload['lecturers'], 0, 8) as $lc) {
                        $lines[] = "- **{$lc['full_name']}** ({$lc['program_code']}, {$lc['academic_rank']}): Teaching **{$lc['teaching_score']}**, Research **{$lc['research_score']}**, Pubs: {$lc['publication_count']}";
                    }
                } elseif (!empty($payload['server_datetime'])) {
                    $lines[] = "Server Time: **{$payload['server_datetime']}** | Active Semester: **{$payload['active_semester']}**";
                }
            }
        }

        if (!empty($ragChunks)) {
            $lines[] = "**Referenced Knowledge:**";
            foreach ($ragChunks as $c) {
                $snippet = mb_substr(trim((string)$c['chunk_text']), 0, 220, 'UTF-8');
                $lines[] = "- `[{$c['doc_code']}]` *{$c['title']}*: {$snippet}";
            }
        }

        if (count($lines) <= 1) {
            $lines[] = "Processed within your verified {$role} data scope. Configure `OPENAI_API_KEY` in environment or Admin AI Keys Vault for full natural-language LLM reasoning.";
        }

        return implode("\n", $lines);
    }

    private function recordExecutionHistory(int $taskId, int $agentId, int $userId, string $modelUsed, array $usage, int $latencyMs, string $status, string $excerpt): void {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_agent_execution_history')) return;
        $this->db->runPrepared(
            "INSERT INTO tbl_agent_execution_history
             (task_id, agent_id, user_id, model_used, prompt_tokens, completion_tokens, total_tokens, estimated_cost_usd, latency_ms, status, output_excerpt)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $taskId,
                $agentId,
                $userId,
                substr($modelUsed, 0, 64),
                (int)($usage['prompt_tokens'] ?? 0),
                (int)($usage['completion_tokens'] ?? 0),
                (int)($usage['total_tokens'] ?? 0),
                number_format((float)($usage['estimated_cost_usd'] ?? 0), 6, '.', ''),
                $latencyMs,
                $status,
                mb_substr(trim($excerpt), 0, 500, 'UTF-8')
            ]
        );
    }
}
