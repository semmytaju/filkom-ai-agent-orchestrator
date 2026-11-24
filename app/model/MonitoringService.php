<?php

class MonitoringService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    private function rangeToSqlCondition(string $range, string $col = 'created_at'): array {
        $r = strtolower(trim($range));
        if ($r === '1h') {
            return ["{$col} >= (NOW() - INTERVAL 1 HOUR)", '1h'];
        }
        if ($r === '24h' || $r === 'today') {
            return ["{$col} >= (NOW() - INTERVAL 24 HOUR)", '24h'];
        }
        if ($r === '7d') {
            return ["{$col} >= (NOW() - INTERVAL 7 DAY)", '7d'];
        }
        if ($r === '30d') {
            return ["{$col} >= (NOW() - INTERVAL 30 DAY)", '30d'];
        }
        return ['1=1', 'all'];
    }

    public function getOverview(array $userContext): array {
        if (!$this->db->isConnected()) {
            return [];
        }

        $userId = (int)($userContext['user_id'] ?? 0);
        $isAdmin = !empty($userContext['scope']['is_admin']);
        $isDean = !empty($userContext['scope']['is_dean']);

        $stAgents = $this->db->runPrepared(
            "SELECT COUNT(*) AS total_agents,
                    SUM(CASE WHEN status = 'active' AND approval_status = 'approved' THEN 1 ELSE 0 END) AS active_agents,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive_agents,
                    SUM(CASE WHEN approval_status = 'pending_approval' THEN 1 ELSE 0 END) AS pending_approval_agents
             FROM tbl_agents WHERE deleted_at IS NULL"
        );
        $agentStats = $stAgents ? ($stAgents->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $taskWhere = ($isAdmin || $isDean) ? '1=1' : 'user_id = ?';
        $taskBinds = ($isAdmin || $isDean) ? [] : [$userId];

        $stTasks = $this->db->runPrepared(
            "SELECT COUNT(*) AS total_tasks,
                    SUM(CASE WHEN status IN ('queued','routing','running') THEN 1 ELSE 0 END) AS running_tasks,
                    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) AS completed_tasks,
                    SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed_tasks,
                    ROUND(AVG(NULLIF(latency_ms, 0)), 0) AS avg_response_ms
             FROM tbl_agent_tasks WHERE {$taskWhere}",
            $taskBinds
        );
        $taskStats = $stTasks ? ($stTasks->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $stUsage = $this->db->runPrepared(
            "SELECT COALESCE(SUM(total_tokens), 0) AS total_tokens,
                    COALESCE(SUM(prompt_tokens), 0) AS prompt_tokens,
                    COALESCE(SUM(completion_tokens), 0) AS completion_tokens,
                    COALESCE(SUM(estimated_cost_usd), 0) AS estimated_cost_usd,
                    COUNT(*) AS api_calls
             FROM tbl_api_usage_logs WHERE {$taskWhere}",
            $taskBinds
        );
        $usageStats = $stUsage ? ($stUsage->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $timelineWhere = ($isAdmin || $isDean) ? '1=1' : 't.user_id = ?';
        $stTimeline = $this->db->runPrepared(
            "SELECT l.id, l.task_id, l.step_type, l.tool_code, l.status, l.summary, l.duration_ms, l.created_at,
                    t.task_uuid, t.role_code, u.full_name AS actor_name, a.agent_code, a.agent_name
             FROM tbl_agent_task_logs l
             INNER JOIN tbl_agent_tasks t ON t.id = l.task_id
             INNER JOIN tbl_users u ON u.id = t.user_id
             LEFT JOIN tbl_agents a ON a.id = l.agent_id
             WHERE {$timelineWhere}
             ORDER BY l.id DESC
             LIMIT 30",
            $taskBinds
        );
        $timeline = $stTimeline ? $stTimeline->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'agents' => [
                'total' => (int)($agentStats['total_agents'] ?? 0),
                'active' => (int)($agentStats['active_agents'] ?? 0),
                'inactive' => (int)($agentStats['inactive_agents'] ?? 0),
                'pending_approval' => (int)($agentStats['pending_approval_agents'] ?? 0)
            ],
            'tasks' => [
                'total' => (int)($taskStats['total_tasks'] ?? 0),
                'running' => (int)($taskStats['running_tasks'] ?? 0),
                'completed' => (int)($taskStats['completed_tasks'] ?? 0),
                'failed' => (int)($taskStats['failed_tasks'] ?? 0),
                'avg_response_ms' => (int)($taskStats['avg_response_ms'] ?? 0)
            ],
            'openai_usage' => [
                'api_calls' => (int)($usageStats['api_calls'] ?? 0),
                'prompt_tokens' => (int)($usageStats['prompt_tokens'] ?? 0),
                'completion_tokens' => (int)($usageStats['completion_tokens'] ?? 0),
                'total_tokens' => (int)($usageStats['total_tokens'] ?? 0),
                'estimated_cost_usd' => round((float)($usageStats['estimated_cost_usd'] ?? 0), 6)
            ],
            'timeline' => $timeline
        ];
    }

    public function getMonthlyBudgetUsd(): float {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_system_settings')) {
            return 50.0;
        }
        $st = $this->db->runPrepared("SELECT setting_value FROM tbl_system_settings WHERE setting_key = 'ai_monthly_budget_usd' LIMIT 1");
        $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        $val = $row ? (float)$row['setting_value'] : 50.0;
        return $val > 0 ? round($val, 2) : 50.0;
    }

    public function saveMonthlyBudgetUsd(float $budgetUsd): array {
        if (!$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Database unavailable.'];
        }
        $clean = max(1.0, min(100000.0, round($budgetUsd, 2)));
        $valStr = number_format($clean, 2, '.', '');
        $st = $this->db->runPrepared(
            "INSERT INTO tbl_system_settings (setting_key, setting_value) VALUES ('ai_monthly_budget_usd', ?)
             ON DUPLICATE KEY UPDATE setting_value = ?",
            [$valStr, $valStr]
        );
        return [
            'success' => (bool)$st,
            'monthly_budget_usd' => $clean,
            'message' => $st ? ('Monthly API budget cap updated to $' . $valStr . ' USD.') : 'Failed to save budget cap.'
        ];
    }

    public function toggleApiKeyStatus(int $id, int $isActive): array {
        if ($id <= 0 || !$this->db->isConnected() || !$this->db->hasTable('tbl_ai_provider_keys')) {
            return ['success' => false, 'message' => 'Invalid API Key ID.'];
        }
        $activeFlag = $isActive ? 1 : 0;
        $st = $this->db->runPrepared("UPDATE tbl_ai_provider_keys SET is_active = ? WHERE id = ?", [$activeFlag, $id]);
        return [
            'success' => (bool)$st,
            'message' => $st ? ($activeFlag ? 'API Key activated.' : 'API Key deactivated.') : 'Failed to update API Key.'
        ];
    }

    public function getApiCostAndAgentMonitor(string $range = '30d'): array {
        if (!$this->db->isConnected()) {
            return ['summary' => [], 'by_agent' => [], 'by_model' => [], 'recent_logs' => []];
        }
        [$execCond, $normRange] = $this->rangeToSqlCondition($range, 'h.created_at');
        [$apiCond] = $this->rangeToSqlCondition($range, 'l.created_at');

        $budgetUsd = $this->getMonthlyBudgetUsd();
        $idrRate = 16000.0;

        $stByAgent = $this->db->runPrepared(
            "SELECT a.id AS agent_id, a.agent_code, a.agent_name, a.zone_code, COALESCE(cfg.model_name, 'gpt-4.1-mini') AS model_name, a.status AS agent_status,
                    COALESCE(ex.exec_count, 0) AS requests,
                    COALESCE(ex.ok_count, 0) AS success_count,
                    COALESCE(ex.err_count, 0) AS error_count,
                    COALESCE(ex.avg_latency_ms, 0) AS avg_latency_ms,
                    COALESCE(ex.last_exec_at, api.last_api_at) AS last_request_at,
                    COALESCE(api.api_calls, 0) AS openai_calls,
                    GREATEST(COALESCE(api.prompt_tokens, 0), COALESCE(ex.prompt_tokens, 0)) AS prompt_tokens,
                    GREATEST(COALESCE(api.completion_tokens, 0), COALESCE(ex.completion_tokens, 0)) AS completion_tokens,
                    GREATEST(COALESCE(api.total_tokens, 0), COALESCE(ex.total_tokens, 0)) AS total_tokens,
                    GREATEST(COALESCE(api.cost_usd, 0), COALESCE(ex.cost_usd, 0)) AS cost_usd
             FROM tbl_agents a
             LEFT JOIN tbl_agent_configurations cfg ON cfg.agent_id = a.id
             LEFT JOIN (
                 SELECT h.agent_id,
                        COUNT(*) AS exec_count,
                        SUM(CASE WHEN h.status IN ('completed','fallback','success') THEN 1 ELSE 0 END) AS ok_count,
                        SUM(CASE WHEN h.status IN ('failed','error') THEN 1 ELSE 0 END) AS err_count,
                        ROUND(AVG(NULLIF(h.latency_ms, 0)), 0) AS avg_latency_ms,
                        COALESCE(SUM(h.prompt_tokens), 0) AS prompt_tokens,
                        COALESCE(SUM(h.completion_tokens), 0) AS completion_tokens,
                        COALESCE(SUM(h.total_tokens), 0) AS total_tokens,
                        COALESCE(SUM(h.estimated_cost_usd), 0) AS cost_usd,
                        MAX(h.created_at) AS last_exec_at
                 FROM tbl_agent_execution_history h
                 WHERE {$execCond}
                 GROUP BY h.agent_id
             ) ex ON ex.agent_id = a.id
             LEFT JOIN (
                 SELECT l.agent_id,
                        COUNT(*) AS api_calls,
                        COALESCE(SUM(l.prompt_tokens), 0) AS prompt_tokens,
                        COALESCE(SUM(l.completion_tokens), 0) AS completion_tokens,
                        COALESCE(SUM(l.total_tokens), 0) AS total_tokens,
                        COALESCE(SUM(l.estimated_cost_usd), 0) AS cost_usd,
                        MAX(l.created_at) AS last_api_at
                 FROM tbl_api_usage_logs l
                 WHERE {$apiCond}
                 GROUP BY l.agent_id
             ) api ON api.agent_id = a.id
             WHERE a.deleted_at IS NULL
             ORDER BY requests DESC, cost_usd DESC, a.agent_name ASC"
        );
        $byAgent = $stByAgent ? $stByAgent->fetchAll(PDO::FETCH_ASSOC) : [];

        $totReq = 0;
        $totOpenAi = 0;
        $totPrompt = 0;
        $totComp = 0;
        $totTok = 0;
        $totCostUsd = 0.0;
        $latSum = 0;
        $latCnt = 0;

        foreach ($byAgent as &$row) {
            $row['requests'] = (int)$row['requests'];
            $row['openai_calls'] = (int)$row['openai_calls'];
            $row['success_count'] = (int)$row['success_count'];
            $row['error_count'] = (int)$row['error_count'];
            $row['prompt_tokens'] = (int)$row['prompt_tokens'];
            $row['completion_tokens'] = (int)$row['completion_tokens'];
            $row['total_tokens'] = (int)$row['total_tokens'];
            $row['avg_latency_ms'] = (int)$row['avg_latency_ms'];
            $row['cost_usd'] = round((float)$row['cost_usd'], 6);
            $row['cost_idr'] = (int)round($row['cost_usd'] * $idrRate);

            $totReq += $row['requests'];
            $totOpenAi += $row['openai_calls'];
            $totPrompt += $row['prompt_tokens'];
            $totComp += $row['completion_tokens'];
            $totTok += $row['total_tokens'];
            $totCostUsd += $row['cost_usd'];
            if ($row['avg_latency_ms'] > 0 && $row['requests'] > 0) {
                $latSum += $row['avg_latency_ms'] * $row['requests'];
                $latCnt += $row['requests'];
            }
        }
        unset($row);

        $stByModel = $this->db->runPrepared(
            "SELECT h.model_used AS model_name,
                    COUNT(*) AS requests,
                    COALESCE(SUM(h.total_tokens), 0) AS total_tokens,
                    COALESCE(SUM(h.estimated_cost_usd), 0) AS cost_usd,
                    ROUND(AVG(NULLIF(h.latency_ms, 0)), 0) AS avg_latency_ms
             FROM tbl_agent_execution_history h
             WHERE {$execCond}
             GROUP BY h.model_used
             ORDER BY requests DESC"
        );
        $byModel = $stByModel ? $stByModel->fetchAll(PDO::FETCH_ASSOC) : [];

        $stLogs = $this->db->runPrepared(
            "SELECT h.id, h.created_at, COALESCE(a.agent_code, 'ORCH') AS agent_code, COALESCE(a.agent_name, 'Orchestrator') AS agent_name,
                    COALESCE(a.zone_code, 'COMMAND_CENTER') AS zone_code, COALESCE(u.username, '-') AS username,
                    h.model_used AS model_name, h.prompt_tokens, h.completion_tokens, h.total_tokens,
                    h.estimated_cost_usd, h.latency_ms, h.status
             FROM tbl_agent_execution_history h
             LEFT JOIN tbl_agents a ON a.id = h.agent_id
             LEFT JOIN tbl_users u ON u.id = h.user_id
             WHERE {$execCond}
             ORDER BY h.id DESC
             LIMIT 60"
        );
        $recentLogs = $stLogs ? $stLogs->fetchAll(PDO::FETCH_ASSOC) : [];

        $stKeys = $this->db->runPrepared(
            "SELECT COUNT(*) AS total_keys, SUM(CASE WHEN is_active = 1 THEN 1 ELSE 0 END) AS active_keys
             FROM tbl_ai_provider_keys"
        );
        $keyAgg = $stKeys ? ($stKeys->fetch(PDO::FETCH_ASSOC) ?: []) : [];
        $envKeyPresent = trim((string)(getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? ''))) !== '';

        $budgetUsedPct = $budgetUsd > 0 ? min(100.0, round(($totCostUsd / $budgetUsd) * 100.0, 2)) : 0.0;

        return [
            'range' => $normRange,
            'summary' => [
                'total_requests' => $totReq,
                'openai_calls' => $totOpenAi,
                'local_calls' => max(0, $totReq - $totOpenAi),
                'prompt_tokens' => $totPrompt,
                'completion_tokens' => $totComp,
                'total_tokens' => $totTok,
                'total_cost_usd' => round($totCostUsd, 6),
                'total_cost_idr' => (int)round($totCostUsd * $idrRate),
                'avg_latency_ms' => $latCnt > 0 ? (int)round($latSum / $latCnt) : 0,
                'monthly_budget_usd' => $budgetUsd,
                'budget_used_pct' => $budgetUsedPct,
                'remaining_usd' => max(0.0, round($budgetUsd - $totCostUsd, 4)),
                'active_keys' => (int)($keyAgg['active_keys'] ?? 0),
                'env_key_present' => $envKeyPresent
            ],
            'by_agent' => $byAgent,
            'by_model' => $byModel,
            'recent_logs' => $recentLogs
        ];
    }

    public function getRealTimeAgentTelemetry(string $range = '24h', string $zone = ''): array {
        if (!$this->db->isConnected()) {
            return ['summary' => [], 'timeline' => [], 'agents' => [], 'zones' => [], 'live_events' => []];
        }
        [$execCond, $normRange] = $this->rangeToSqlCondition($range, 'h.created_at');
        $zoneClean = strtoupper(trim($zone));
        $allowedZones = ['DEAN', 'KAPRODI', 'SECRETARY', 'UPMF', 'RESEARCH_HEAD', 'STUDENT', 'COMMAND_CENTER'];
        if (!in_array($zoneClean, $allowedZones, true)) {
            $zoneClean = '';
        }

        $zoneFilterSql = $zoneClean !== '' ? ' AND a.zone_code = ?' : '';
        $zoneBinds = $zoneClean !== '' ? [$zoneClean] : [];

        $stAgents = $this->db->runPrepared(
            "SELECT a.id AS agent_id, a.agent_code, a.agent_name, a.zone_code, COALESCE(cfg.model_name, 'gpt-4.1-mini') AS model_name, a.status AS registry_status,
                    COALESCE(ex.requests, 0) AS requests,
                    COALESCE(ex.success_count, 0) AS success_count,
                    COALESCE(ex.error_count, 0) AS error_count,
                    COALESCE(ex.avg_latency_ms, 0) AS avg_latency_ms,
                    COALESCE(ex.total_tokens, 0) AS total_tokens,
                    COALESCE(ex.cost_usd, 0) AS cost_usd,
                    ex.last_status,
                    ex.last_active_at
             FROM tbl_agents a
             LEFT JOIN tbl_agent_configurations cfg ON cfg.agent_id = a.id
             LEFT JOIN (
                 SELECT h.agent_id,
                        COUNT(*) AS requests,
                        SUM(CASE WHEN h.status IN ('completed','fallback','success') THEN 1 ELSE 0 END) AS success_count,
                        SUM(CASE WHEN h.status IN ('failed','error') THEN 1 ELSE 0 END) AS error_count,
                        ROUND(AVG(NULLIF(h.latency_ms, 0)), 0) AS avg_latency_ms,
                        COALESCE(SUM(h.total_tokens), 0) AS total_tokens,
                        COALESCE(SUM(h.estimated_cost_usd), 0) AS cost_usd,
                        SUBSTRING_INDEX(GROUP_CONCAT(h.status ORDER BY h.id DESC), ',', 1) AS last_status,
                        MAX(h.created_at) AS last_active_at
                 FROM tbl_agent_execution_history h
                 WHERE {$execCond}
                 GROUP BY h.agent_id
             ) ex ON ex.agent_id = a.id
             WHERE a.deleted_at IS NULL {$zoneFilterSql}
             ORDER BY requests DESC, a.agent_name ASC",
            $zoneBinds
        );
        $agents = $stAgents ? $stAgents->fetchAll(PDO::FETCH_ASSOC) : [];

        $bucketExpr = ($normRange === '1h')
            ? "DATE_FORMAT(h.created_at, '%H:%i')"
            : (($normRange === '24h') ? "DATE_FORMAT(h.created_at, '%m-%d %H:00')" : "DATE_FORMAT(h.created_at, '%Y-%m-%d')");

        $stSeries = $this->db->runPrepared(
            "SELECT {$bucketExpr} AS bucket_label,
                    COUNT(*) AS requests,
                    COALESCE(SUM(h.total_tokens), 0) AS total_tokens,
                    COALESCE(SUM(h.estimated_cost_usd), 0) AS cost_usd,
                    ROUND(AVG(NULLIF(h.latency_ms, 0)), 0) AS avg_latency_ms
             FROM tbl_agent_execution_history h
             LEFT JOIN tbl_agents a ON a.id = h.agent_id
             WHERE {$execCond} {$zoneFilterSql}
             GROUP BY bucket_label
             ORDER BY bucket_label ASC
             LIMIT 30",
            $zoneBinds
        );
        $seriesRows = $stSeries ? $stSeries->fetchAll(PDO::FETCH_ASSOC) : [];

        $stZones = $this->db->runPrepared(
            "SELECT COALESCE(a.zone_code, 'COMMAND_CENTER') AS zone_code,
                    COUNT(h.id) AS requests,
                    COALESCE(SUM(h.total_tokens), 0) AS total_tokens,
                    COALESCE(SUM(h.estimated_cost_usd), 0) AS cost_usd
             FROM tbl_agents a
             LEFT JOIN tbl_agent_execution_history h ON h.agent_id = a.id AND {$execCond}
             WHERE a.deleted_at IS NULL
             GROUP BY COALESCE(a.zone_code, 'COMMAND_CENTER')
             ORDER BY requests DESC"
        );
        $zones = $stZones ? $stZones->fetchAll(PDO::FETCH_ASSOC) : [];

        $stEvents = $this->db->runPrepared(
            "SELECT e.id, e.agent_code, e.status, e.task_label, e.progress, e.zone_code, e.created_at
             FROM tbl_agent_events e
             ORDER BY e.id DESC
             LIMIT 20"
        );
        $liveEvents = $stEvents ? $stEvents->fetchAll(PDO::FETCH_ASSOC) : [];

        $totReq = 0;
        $totOk = 0;
        $totTok = 0;
        $totCost = 0.0;
        $latSum = 0;
        $latCnt = 0;
        $activeAgentsCount = 0;

        foreach ($agents as &$ag) {
            $ag['requests'] = (int)$ag['requests'];
            $ag['success_count'] = (int)$ag['success_count'];
            $ag['error_count'] = (int)$ag['error_count'];
            $ag['avg_latency_ms'] = (int)$ag['avg_latency_ms'];
            $ag['total_tokens'] = (int)$ag['total_tokens'];
            $ag['cost_usd'] = round((float)$ag['cost_usd'], 6);
            if ($ag['registry_status'] === 'active') $activeAgentsCount++;
            $totReq += $ag['requests'];
            $totOk += $ag['success_count'];
            $totTok += $ag['total_tokens'];
            $totCost += $ag['cost_usd'];
            if ($ag['avg_latency_ms'] > 0 && $ag['requests'] > 0) {
                $latSum += $ag['avg_latency_ms'] * $ag['requests'];
                $latCnt += $ag['requests'];
            }
        }
        unset($ag);

        return [
            'range' => $normRange,
            'zone' => $zoneClean,
            'server_time' => date('Y-m-d H:i:s'),
            'summary' => [
                'active_agents' => $activeAgentsCount,
                'total_agents' => count($agents),
                'total_requests' => $totReq,
                'success_rate' => $totReq > 0 ? round(($totOk / $totReq) * 100.0, 1) : 100.0,
                'avg_latency_ms' => $latCnt > 0 ? (int)round($latSum / $latCnt) : 0,
                'total_tokens' => $totTok,
                'total_cost_usd' => round($totCost, 6)
            ],
            'timeline' => [
                'labels' => array_column($seriesRows, 'bucket_label'),
                'requests' => array_map('intval', array_column($seriesRows, 'requests')),
                'tokens' => array_map('intval', array_column($seriesRows, 'total_tokens')),
                'latency' => array_map('intval', array_column($seriesRows, 'avg_latency_ms')),
                'cost_usd' => array_map(static fn($v) => round((float)$v, 6), array_column($seriesRows, 'cost_usd'))
            ],
            'agents' => $agents,
            'zones' => $zones,
            'live_events' => $liveEvents
        ];
    }

    public function getLatestEventsForUser(int $userId, int $lastEventId = 0, int $limit = 30): array {
        if ($userId <= 0 || !$this->db->isConnected()) return [];
        $st = $this->db->runPrepared(
            "SELECT id, task_id, agent_code AS agent_id, status, task_label AS task, progress, animation, zone_code AS zone, payload_json, created_at
             FROM tbl_agent_events
             WHERE user_id = ? AND id > ?
             ORDER BY id ASC
             LIMIT ?",
            [$userId, $lastEventId, $limit]
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}
