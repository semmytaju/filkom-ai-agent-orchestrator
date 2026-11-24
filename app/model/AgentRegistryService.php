<?php

class AgentRegistryService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public function getAuthorizedAgentsForUser(int $userId): array {
        if ($userId <= 0 || !$this->db->isConnected()) return [];
        $rbac = new RbacService($this->db);
        $ctx = $rbac->getUserContext($userId);
        if (!$ctx || empty($ctx['role_ids'])) return [];

        $roleIds = array_map('intval', $ctx['role_ids']);
        $ph = implode(',', array_fill(0, count($roleIds), '?'));
        $st = $this->db->runPrepared(
            "SELECT a.id, a.agent_code, a.agent_name, a.description, a.agent_category, a.zone_code,
                    a.avatar_model, a.avatar_outfit, a.animation_profile, a.status, a.approval_status, a.priority,
                    ap.system_prompt, ap.version_number AS prompt_version,
                    cfg.provider, cfg.model_name, cfg.temperature, cfg.max_output_tokens, cfg.reasoning_effort, cfg.timeout_seconds, cfg.max_retries,
                    ara.data_scope, ara.allowed_study_programs, ara.can_invoke, ara.can_collaborate
             FROM tbl_agents a
             INNER JOIN tbl_agent_role_access ara ON ara.agent_id = a.id AND ara.can_invoke = 1
             LEFT JOIN tbl_agent_prompts ap ON ap.agent_id = a.id AND ap.is_active = 1
             LEFT JOIN tbl_agent_configurations cfg ON cfg.agent_id = a.id
             WHERE a.deleted_at IS NULL
               AND a.status = 'active'
               AND a.approval_status = 'approved'
               AND ara.role_id IN ({$ph})
             ORDER BY a.priority DESC, a.id ASC",
            $roleIds
        );
        $rows = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
        if (empty($rows)) return [];

        $byId = [];
        foreach ($rows as $row) {
            $aid = (int)$row['id'];
            if (!isset($byId[$aid])) {
                $byId[$aid] = $row;
                $byId[$aid]['tools'] = [];
                $byId[$aid]['knowledge_bases'] = [];
            }
        }

        $agentIds = array_keys($byId);
        $phA = implode(',', array_fill(0, count($agentIds), '?'));

        $stTools = $this->db->runPrepared(
            "SELECT atp.agent_id, t.id, t.tool_code, t.tool_name, t.tool_category, t.trigger_keywords, t.description, t.handler_method, t.required_permission, t.scope_type, t.is_destructive
             FROM tbl_agent_tool_permissions atp
             INNER JOIN tbl_agent_tools t ON t.id = atp.tool_id
             WHERE atp.agent_id IN ({$phA}) AND t.is_active = 1
             ORDER BY t.id ASC",
            $agentIds
        );
        if ($stTools) {
            foreach ($stTools->fetchAll(PDO::FETCH_ASSOC) as $tr) {
                $aid = (int)$tr['agent_id'];
                if (isset($byId[$aid])) $byId[$aid]['tools'][] = $tr;
            }
        }

        $stKbs = $this->db->runPrepared(
            "SELECT ak.agent_id, kb.id, kb.kb_code, kb.kb_name, kb.category, ak.priority_order
             FROM tbl_agent_knowledge ak
             INNER JOIN tbl_knowledge_bases kb ON kb.id = ak.knowledge_base_id
             WHERE ak.agent_id IN ({$phA}) AND kb.is_active = 1
             ORDER BY ak.priority_order ASC",
            $agentIds
        );
        if ($stKbs) {
            foreach ($stKbs->fetchAll(PDO::FETCH_ASSOC) as $kr) {
                $aid = (int)$kr['agent_id'];
                if (isset($byId[$aid])) $byId[$aid]['knowledge_bases'][] = $kr;
            }
        }

        return array_values($byId);
    }

    public function listAllAgents(array $params = []): array {
        if (!$this->db->isConnected()) return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(5, min(100, (int)($params['per_page'] ?? 20)));
        $search = trim((string)($params['search'] ?? ''));
        $catFilter = strtoupper(trim((string)($params['category'] ?? ($params['filter1'] ?? ''))));
        $statusFilter = strtolower(trim((string)($params['status'] ?? ($params['filter2'] ?? ''))));

        $where = ['a.deleted_at IS NULL'];
        $binds = [];
        if ($search !== '') {
            $where[] = '(a.agent_code LIKE ? OR a.agent_name LIKE ? OR a.description LIKE ?)';
            $like = '%' . $search . '%';
            array_push($binds, $like, $like, $like);
        }
        if ($catFilter !== '') {
            $where[] = 'a.agent_category = ?';
            $binds[] = $catFilter;
        }
        if ($statusFilter === 'pending_approval') {
            $where[] = "a.approval_status = 'pending_approval'";
        } elseif (in_array($statusFilter, ['active', 'inactive'], true)) {
            $where[] = 'a.status = ?';
            $binds[] = $statusFilter;
        }

        $whereSql = implode(' AND ', $where);
        $stCnt = $this->db->runPrepared("SELECT COUNT(*) FROM tbl_agents a WHERE {$whereSql}", $binds);
        $total = $stCnt ? (int)$stCnt->fetchColumn() : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $qBinds = array_merge($binds, [$perPage, $offset]);
        $st = $this->db->runPrepared(
            "SELECT a.*, cfg.model_name, cfg.temperature, cfg.max_output_tokens,
                    ap.version_number AS prompt_version,
                    COUNT(DISTINCT ara.role_id) AS role_count,
                    COUNT(DISTINCT atp.tool_id) AS tool_count,
                    COUNT(DISTINCT ak.knowledge_base_id) AS kb_count
             FROM tbl_agents a
             LEFT JOIN tbl_agent_configurations cfg ON cfg.agent_id = a.id
             LEFT JOIN tbl_agent_prompts ap ON ap.agent_id = a.id AND ap.is_active = 1
             LEFT JOIN tbl_agent_role_access ara ON ara.agent_id = a.id
             LEFT JOIN tbl_agent_tool_permissions atp ON atp.agent_id = a.id
             LEFT JOIN tbl_agent_knowledge ak ON ak.agent_id = a.id
             WHERE {$whereSql}
             GROUP BY a.id
             ORDER BY FIELD(a.approval_status,'pending_approval','approved','rejected'), a.priority DESC, a.id ASC
             LIMIT ? OFFSET ?",
            $qBinds
        );
        $items = $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];

        if (!empty($items)) {
            $aIds = array_map(static fn($r) => (int)$r['id'], $items);
            $ph = implode(',', array_fill(0, count($aIds), '?'));
            $stRoles = $this->db->runPrepared(
                "SELECT ara.agent_id, r.role_code, ara.data_scope
                 FROM tbl_agent_role_access ara
                 INNER JOIN tbl_roles r ON r.id = ara.role_id
                 WHERE ara.agent_id IN ({$ph})",
                $aIds
            );
            $roleMap = [];
            if ($stRoles) {
                foreach ($stRoles->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                    $roleMap[(int)$rr['agent_id']][] = $rr;
                }
            }
            foreach ($items as &$item) {
                $item['assigned_roles'] = $roleMap[(int)$item['id']] ?? [];
            }
        }

        return [
            'items' => $items,
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }

    public function getAgentDetail(int $agentId): ?array {
        if ($agentId <= 0 || !$this->db->isConnected()) return null;
        $st = $this->db->runPrepared(
            "SELECT a.*, ap.system_prompt, ap.version_number AS prompt_version,
                    cfg.provider, cfg.model_name, cfg.temperature, cfg.max_output_tokens, cfg.reasoning_effort, cfg.timeout_seconds, cfg.max_retries
             FROM tbl_agents a
             LEFT JOIN tbl_agent_prompts ap ON ap.agent_id = a.id AND ap.is_active = 1
             LEFT JOIN tbl_agent_configurations cfg ON cfg.agent_id = a.id
             WHERE a.id = ? AND a.deleted_at IS NULL
             LIMIT 1",
            [$agentId]
        );
        $agent = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        if (!$agent) return null;

        $stR = $this->db->runPrepared(
            "SELECT ara.*, r.role_code, r.role_name
             FROM tbl_agent_role_access ara
             INNER JOIN tbl_roles r ON r.id = ara.role_id
             WHERE ara.agent_id = ?",
            [$agentId]
        );
        $agent['role_access'] = $stR ? $stR->fetchAll(PDO::FETCH_ASSOC) : [];

        $stT = $this->db->runPrepared(
            "SELECT t.id, t.tool_code, t.tool_name, t.tool_category
             FROM tbl_agent_tool_permissions atp
             INNER JOIN tbl_agent_tools t ON t.id = atp.tool_id
             WHERE atp.agent_id = ?",
            [$agentId]
        );
        $agent['tools'] = $stT ? $stT->fetchAll(PDO::FETCH_ASSOC) : [];

        $stK = $this->db->runPrepared(
            "SELECT kb.id, kb.kb_code, kb.kb_name, ak.priority_order
             FROM tbl_agent_knowledge ak
             INNER JOIN tbl_knowledge_bases kb ON kb.id = ak.knowledge_base_id
             WHERE ak.agent_id = ?
             ORDER BY ak.priority_order ASC",
            [$agentId]
        );
        $agent['knowledge_bases'] = $stK ? $stK->fetchAll(PDO::FETCH_ASSOC) : [];

        $stV = $this->db->runPrepared(
            "SELECT v.id, v.version_number, v.change_note, v.created_at, u.full_name AS author_name
             FROM tbl_agent_versions v
             LEFT JOIN tbl_users u ON u.id = v.created_by
             WHERE v.agent_id = ?
             ORDER BY v.version_number DESC
             LIMIT 20",
            [$agentId]
        );
        $agent['versions'] = $stV ? $stV->fetchAll(PDO::FETCH_ASSOC) : [];

        return $agent;
    }
}
