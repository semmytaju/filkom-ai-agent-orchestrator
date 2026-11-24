<?php

class AgentBuilderService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public function saveManualAgent(array $data, int $actorUserId, string $actorName): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];

        $id = (int)($data['id'] ?? 0);
        $agentCode = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', trim((string)($data['agent_code'] ?? ''))));
        $agentName = trim((string)($data['agent_name'] ?? ''));
        $description = trim((string)($data['description'] ?? ''));
        $category = strtoupper(trim((string)($data['agent_category'] ?? 'CUSTOM')));
        $zoneCode = strtoupper(trim((string)($data['zone_code'] ?? 'COMMAND_CENTER')));
        $avatarModel = trim((string)($data['avatar_model'] ?? 'chibi_orchestrator')) ?: 'chibi_orchestrator';
        $avatarOutfit = trim((string)($data['avatar_outfit'] ?? 'classic_navy')) ?: 'classic_navy';
        $animProfile = trim((string)($data['animation_profile'] ?? 'executive_briefing')) ?: 'executive_briefing';
        $priority = max(1, min(100, (int)($data['priority'] ?? 60)));
        $status = in_array(($data['status'] ?? 'active'), ['active', 'inactive'], true) ? $data['status'] : 'active';
        $isAiGen = !empty($data['is_ai_generated']) ? 1 : 0;
        $approvalStatus = $isAiGen ? 'pending_approval' : 'approved';
        if ($isAiGen) {
            $status = 'inactive';
        }

        $systemPrompt = trim((string)($data['system_prompt'] ?? ''));
        $modelName = preg_replace('/[^a-zA-Z0-9\.\-_]/', '', (string)($data['model_name'] ?? 'gpt-4.1-mini')) ?: 'gpt-4.1-mini';
        $temperature = isset($data['temperature']) && $data['temperature'] !== '' ? max(0.0, min(2.0, (float)$data['temperature'])) : 0.40;
        $maxTokens = max(160, min(4096, (int)($data['max_output_tokens'] ?? 900)));
        $changeNote = trim((string)($data['change_note'] ?? ($id > 0 ? 'Updated agent configuration' : 'Initial manual build')));

        if (strlen($agentCode) < 3 || strlen($agentName) < 3 || strlen($systemPrompt) < 12) {
            return ['success' => false, 'message' => 'Agent code, name, and system prompt (min 12 chars) are required.'];
        }

        $this->db->prepare("SELECT id FROM tbl_agents WHERE agent_code = :ac AND id != :id AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':ac', $agentCode);
        $this->db->bind(':id', $id, PDO::PARAM_INT);
        if ($this->db->single()) {
            return ['success' => false, 'message' => "Agent code '{$agentCode}' already exists."];
        }

        $roleAssignments = is_array($data['role_assignments'] ?? null) ? $data['role_assignments'] : [];
        $toolIds = is_array($data['tool_ids'] ?? null) ? array_values(array_unique(array_map('intval', $data['tool_ids']))) : [];
        $kbIds = is_array($data['kb_ids'] ?? null) ? array_values(array_unique(array_map('intval', $data['kb_ids']))) : [];

        try {
            $this->db->beginTransaction();

            if ($id > 0) {
                $this->db->prepare("UPDATE tbl_agents
                    SET agent_code = :ac, agent_name = :an, description = :desc, agent_category = :cat,
                        zone_code = :zc, avatar_model = :am, avatar_outfit = :ao, animation_profile = :ap,
                        status = :st, priority = :pr
                    WHERE id = :id");
                $this->db->bind(':id', $id, PDO::PARAM_INT);
                $agentId = $id;
            } else {
                $this->db->prepare("INSERT INTO tbl_agents
                    (agent_code, agent_name, description, agent_category, zone_code, avatar_model, avatar_outfit, animation_profile,
                     status, approval_status, is_ai_generated, priority, created_by, approved_by, approved_at)
                    VALUES (:ac, :an, :desc, :cat, :zc, :am, :ao, :ap, :st, :app, :aig, :pr, :cb, :ab, :aa)");
                $this->db->bind(':app', $approvalStatus);
                $this->db->bind(':aig', $isAiGen, PDO::PARAM_INT);
                $this->db->bind(':cb', $actorUserId, PDO::PARAM_INT);
                $this->db->bind(':ab', $isAiGen ? null : $actorUserId);
                $this->db->bind(':aa', $isAiGen ? null : date('Y-m-d H:i:s'));
            }
            $this->db->bind(':ac', $agentCode);
            $this->db->bind(':an', $agentName);
            $this->db->bind(':desc', $description);
            $this->db->bind(':cat', $category);
            $this->db->bind(':zc', $zoneCode);
            $this->db->bind(':am', $avatarModel);
            $this->db->bind(':ao', $avatarOutfit);
            $this->db->bind(':ap', $animProfile);
            $this->db->bind(':st', $status);
            $this->db->bind(':pr', $priority, PDO::PARAM_INT);
            $this->db->execute();

            if ($id <= 0) {
                $agentId = $this->db->lastInsertId();
            }

            $this->db->prepare("INSERT INTO tbl_agent_configurations (agent_id, provider, model_name, temperature, max_output_tokens)
                VALUES (:aid, 'openai', :mn, :tmp, :mt)
                ON DUPLICATE KEY UPDATE model_name = VALUES(model_name), temperature = VALUES(temperature), max_output_tokens = VALUES(max_output_tokens)");
            $this->db->bind(':aid', $agentId, PDO::PARAM_INT);
            $this->db->bind(':mn', $modelName);
            $this->db->bind(':tmp', number_format($temperature, 2, '.', ''));
            $this->db->bind(':mt', $maxTokens, PDO::PARAM_INT);
            $this->db->execute();

            $this->saveAgentBindings($agentId, $roleAssignments, $toolIds, $kbIds, $actorUserId);
            $newVer = $this->createNewPromptAndSnapshot($agentId, $systemPrompt, $data, $changeNote, $actorUserId);

            $this->db->commit();
            return [
                'success' => true,
                'agent_id' => $agentId,
                'version_number' => $newVer,
                'approval_status' => $approvalStatus,
                'message' => $isAiGen
                    ? "AI-proposed agent saved as pending approval (v{$newVer}). Administrator approval is required before activation."
                    : "Agent '{$agentName}' saved (version v{$newVer})."
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to save agent: ' . $e->getMessage()];
        }
    }

    private function saveAgentBindings(int $agentId, array $roleAssignments, array $toolIds, array $kbIds, int $actorUserId): void {
        $pdo = $this->db->getPdo();
        if (!$pdo) return;

        $this->db->runPrepared("DELETE FROM tbl_agent_role_access WHERE agent_id = ?", [$agentId]);
        $stRoleLookup = $pdo->prepare("SELECT id, role_code FROM tbl_roles WHERE role_code = ? OR id = ? LIMIT 1");
        $stInsRole = $pdo->prepare("INSERT INTO tbl_agent_role_access (agent_id, role_id, data_scope, allowed_study_programs, can_invoke, can_collaborate) VALUES (?, ?, ?, ?, 1, 1)");
        $validScopes = ['ALL_FACULTY', 'OWN_STUDY_PROGRAM', 'OWN_STUDENT', 'QUALITY_ASSURANCE', 'RESEARCH', 'ADMINISTRATION'];
        foreach ($roleAssignments as $ra) {
            if (!is_array($ra)) {
                $ra = ['role_id' => (int)$ra, 'data_scope' => 'OWN_STUDY_PROGRAM'];
            }
            $rc = strtoupper((string)($ra['role_code'] ?? ''));
            $rid = (int)($ra['role_id'] ?? 0);
            $stRoleLookup->execute([$rc, $rid]);
            $roleRow = $stRoleLookup->fetch(PDO::FETCH_ASSOC);
            if (!$roleRow) continue;

            $scope = strtoupper((string)($ra['data_scope'] ?? 'OWN_STUDY_PROGRAM'));
            if (!in_array($scope, $validScopes, true)) {
                $scope = 'OWN_STUDY_PROGRAM';
            }
            $allowedProgs = isset($ra['allowed_study_programs']) && trim((string)$ra['allowed_study_programs']) !== ''
                ? strtoupper(trim((string)$ra['allowed_study_programs']))
                : null;
            $stInsRole->execute([$agentId, (int)$roleRow['id'], $scope, $allowedProgs]);
        }

        $this->db->runPrepared("DELETE FROM tbl_agent_tool_permissions WHERE agent_id = ?", [$agentId]);
        $stInsTool = $pdo->prepare("INSERT IGNORE INTO tbl_agent_tool_permissions (agent_id, tool_id, granted_by) VALUES (?, ?, ?)");
        foreach ($toolIds as $tid) {
            if ($tid > 0) $stInsTool->execute([$agentId, $tid, $actorUserId]);
        }

        $this->db->runPrepared("DELETE FROM tbl_agent_knowledge WHERE agent_id = ?", [$agentId]);
        $stInsKb = $pdo->prepare("INSERT IGNORE INTO tbl_agent_knowledge (agent_id, knowledge_base_id, priority_order) VALUES (?, ?, ?)");
        foreach (array_values($kbIds) as $idx => $kbid) {
            if ($kbid > 0) $stInsKb->execute([$agentId, $kbid, $idx + 1]);
        }
    }

    private function createNewPromptAndSnapshot(int $agentId, string $systemPrompt, array $data, string $changeNote, int $actorUserId): int {
        $stMax = $this->db->runPrepared("SELECT COALESCE(MAX(version_number), 0) FROM tbl_agent_versions WHERE agent_id = ?", [$agentId]);
        $nextVer = ($stMax ? (int)$stMax->fetchColumn() : 0) + 1;

        $this->db->runPrepared("UPDATE tbl_agent_prompts SET is_active = 0 WHERE agent_id = ?", [$agentId]);
        $this->db->runPrepared(
            "INSERT INTO tbl_agent_prompts (agent_id, version_number, system_prompt, is_active, created_by) VALUES (?, ?, ?, 1, ?)",
            [$agentId, $nextVer, $systemPrompt, $actorUserId]
        );

        $snapshot = [
            'agent_code' => (string)($data['agent_code'] ?? ''),
            'agent_name' => (string)($data['agent_name'] ?? ''),
            'description' => (string)($data['description'] ?? ''),
            'agent_category' => (string)($data['agent_category'] ?? ''),
            'zone_code' => (string)($data['zone_code'] ?? 'COMMAND_CENTER'),
            'avatar_outfit' => (string)($data['avatar_outfit'] ?? 'classic_navy'),
            'animation_profile' => (string)($data['animation_profile'] ?? 'executive_briefing'),
            'system_prompt' => $systemPrompt,
            'model_name' => (string)($data['model_name'] ?? 'gpt-4.1-mini'),
            'temperature' => (float)($data['temperature'] ?? 0.40),
            'max_output_tokens' => (int)($data['max_output_tokens'] ?? 900),
            'role_assignments' => $data['role_assignments'] ?? [],
            'tool_ids' => $data['tool_ids'] ?? [],
            'kb_ids' => $data['kb_ids'] ?? []
        ];

        $this->db->runPrepared(
            "INSERT INTO tbl_agent_versions (agent_id, version_number, config_snapshot_json, change_note, created_by) VALUES (?, ?, ?, ?, ?)",
            [$agentId, $nextVer, json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), substr($changeNote, 0, 250), $actorUserId]
        );
        $verId = $this->db->lastInsertId();
        $this->db->runPrepared("UPDATE tbl_agents SET current_version_id = ? WHERE id = ?", [$verId, $agentId]);

        return $nextVer;
    }

    public function proposeAgentWithAi(string $naturalDescription, int $actorUserId): array {
        $desc = trim($naturalDescription);
        if (strlen($desc) < 10) {
            return ['success' => false, 'message' => 'Please describe the intended AI agent in at least 10 characters.'];
        }

        $toolRows = $this->db->runPrepared("SELECT id, tool_code, tool_name, scope_type FROM tbl_agent_tools WHERE is_active = 1");
        $tools = $toolRows ? $toolRows->fetchAll(PDO::FETCH_ASSOC) : [];
        $kbRows = $this->db->runPrepared("SELECT id, kb_code, kb_name FROM tbl_knowledge_bases WHERE is_active = 1");
        $kbs = $kbRows ? $kbRows->fetchAll(PDO::FETCH_ASSOC) : [];
        $roleRows = $this->db->runPrepared("SELECT id, role_code, role_name FROM tbl_roles");
        $roles = $roleRows ? $roleRows->fetchAll(PDO::FETCH_ASSOC) : [];

        $respSvc = new ResponseService($this->db);
        $proposal = null;
        $engine = 'deterministic_planner';

        if ($respSvc->hasConfiguredKey()) {
            $catalogSummary = json_encode([
                'roles' => array_column($roles, 'role_code'),
                'tools' => array_map(static fn($t) => $t['tool_code'], $tools),
                'knowledge_bases' => array_map(static fn($k) => $k['kb_code'], $kbs)
            ]);
            $aiRes = $respSvc->createResponse([
                'model' => 'gpt-4.1-mini',
                'instructions' => "You are an AI Architect for FILKOM UNKLAB. Given a natural language agent request, output strictly valid JSON with keys: agent_name, agent_code (lowercase snake_case), description, agent_category (DEAN|KAPRODI|SECRETARY|UPMF|RESEARCH_HEAD|STUDENT), zone_code (DEAN|KAPRODI|SECRETARY|UPMF|RESEARCH_HEAD|STUDENT), system_prompt, suggested_roles (array of {role_code, data_scope}), suggested_tool_codes (array), suggested_kb_codes (array), avatar_outfit (classic_navy|ivory_batik|emerald_dean|burgundy_scholar|charcoal_pin|academic_tweed), animation_profile (executive_briefing|analytics_scan|typing|searching). Never assign ALL_FACULTY unless role is DEAN. Catalog: {$catalogSummary}",
                'input' => $desc,
                'max_output_tokens' => 800,
                'temperature' => 0.25,
                'text' => ['format' => ['type' => 'json_object']]
            ], ['user_id' => $actorUserId]);

            if (!empty($aiRes['ok']) && !empty($aiRes['output_text'])) {
                $decoded = json_decode((string)$aiRes['output_text'], true);
                if (is_array($decoded) && !empty($decoded['agent_name'])) {
                    $proposal = $decoded;
                    $engine = 'openai_responses';
                }
            }
        }

        if ($proposal === null) {
            $proposal = $this->heuristicProposal($desc);
        }

        $roleByCode = [];
        foreach ($roles as $r) $roleByCode[strtoupper((string)$r['role_code'])] = (int)$r['id'];
        $toolByCode = [];
        foreach ($tools as $t) $toolByCode[strtolower((string)$t['tool_code'])] = $t;
        $kbByCode = [];
        foreach ($kbs as $k) $kbByCode[strtoupper((string)$k['kb_code'])] = (int)$k['id'];

        $mappedRoles = [];
        foreach (($proposal['suggested_roles'] ?? []) as $sr) {
            $rc = strtoupper((string)($sr['role_code'] ?? ''));
            if (!isset($roleByCode[$rc])) continue;
            $ds = strtoupper((string)($sr['data_scope'] ?? 'OWN_STUDY_PROGRAM'));
            if ($rc !== 'DEAN' && $rc !== 'ADMIN' && $ds === 'ALL_FACULTY') {
                $ds = strpos($rc, 'KAPRODI_') === 0 ? 'OWN_STUDY_PROGRAM' : 'ADMINISTRATION';
            }
            if ($rc === 'STUDENT') $ds = 'OWN_STUDENT';
            $mappedRoles[] = [
                'role_id' => $roleByCode[$rc],
                'role_code' => $rc,
                'data_scope' => $ds,
                'allowed_study_programs' => strpos($rc, 'KAPRODI_') === 0 ? substr($rc, 8) : null
            ];
        }
        if (empty($mappedRoles)) {
            $mappedRoles[] = ['role_id' => $roleByCode['KAPRODI_IF'] ?? 3, 'role_code' => 'KAPRODI_IF', 'data_scope' => 'OWN_STUDY_PROGRAM', 'allowed_study_programs' => 'IF'];
        }

        $mappedTools = [];
        foreach (($proposal['suggested_tool_codes'] ?? []) as $tc) {
            $low = strtolower((string)$tc);
            if (isset($toolByCode[$low]) && (int)$toolByCode[$low]['id'] > 0) {
                if (($toolByCode[$low]['scope_type'] ?? '') === 'GLOBAL' && ($proposal['agent_category'] ?? '') !== 'DEAN' && $low === 'get_faculty_statistics') {
                    continue;
                }
                $mappedTools[] = (int)$toolByCode[$low]['id'];
            }
        }
        if (empty($mappedTools) && isset($toolByCode['get_program_statistics'])) {
            $mappedTools[] = (int)$toolByCode['get_program_statistics']['id'];
        }

        $mappedKbs = [];
        foreach (($proposal['suggested_kb_codes'] ?? []) as $kc) {
            $up = strtoupper((string)$kc);
            if (isset($kbByCode[$up])) $mappedKbs[] = $kbByCode[$up];
        }
        if (empty($mappedKbs)) $mappedKbs[] = 2;

        return [
            'success' => true,
            'engine' => $engine,
            'requires_admin_approval' => true,
            'proposal' => [
                'agent_name' => (string)$proposal['agent_name'],
                'agent_code' => strtolower(preg_replace('/[^a-zA-Z0-9_]/', '_', (string)($proposal['agent_code'] ?? 'custom_agent_' . substr(md5($desc), 0, 5)))),
                'description' => (string)($proposal['description'] ?? $desc),
                'agent_category' => strtoupper((string)($proposal['agent_category'] ?? 'KAPRODI')),
                'zone_code' => strtoupper((string)($proposal['zone_code'] ?? 'KAPRODI')),
                'system_prompt' => (string)$proposal['system_prompt'],
                'model_name' => 'gpt-4.1-mini',
                'temperature' => 0.35,
                'max_output_tokens' => 900,
                'avatar_outfit' => (string)($proposal['avatar_outfit'] ?? 'classic_navy'),
                'animation_profile' => (string)($proposal['animation_profile'] ?? 'analytics_scan'),
                'role_assignments' => $mappedRoles,
                'tool_ids' => array_values(array_unique($mappedTools)),
                'kb_ids' => array_values(array_unique($mappedKbs)),
                'is_ai_generated' => 1
            ]
        ];
    }

    private function heuristicProposal(string $desc): array {
        $low = strtolower($desc);
        $cat = 'KAPRODI';
        $zone = 'KAPRODI';
        $roles = [];
        $tools = ['search_documents', 'generate_report'];
        $kbs = ['KB_CURRICULUM_AKADEMIK'];
        $outfit = 'classic_navy';
        $anim = 'analytics_scan';

        if (strpos($low, 'informatics') !== false || strpos($low, 'informatika') !== false) {
            $roles[] = ['role_code' => 'KAPRODI_IF', 'data_scope' => 'OWN_STUDY_PROGRAM'];
            $tools[] = 'get_student_academic_progress';
            $tools[] = 'get_program_statistics';
        } elseif (strpos($low, 'information system') !== false || strpos($low, 'sistem informasi') !== false) {
            $roles[] = ['role_code' => 'KAPRODI_SI', 'data_scope' => 'OWN_STUDY_PROGRAM'];
            $tools[] = 'get_student_academic_progress';
            $tools[] = 'get_program_statistics';
        } elseif (strpos($low, 'quality') !== false || strpos($low, 'accreditation') !== false || strpos($low, 'upmf') !== false) {
            $cat = 'UPMF';
            $zone = 'UPMF';
            $roles[] = ['role_code' => 'UPMF', 'data_scope' => 'QUALITY_ASSURANCE'];
            $tools[] = 'get_accreditation_indicators';
            $tools[] = 'get_quality_evaluation';
            $kbs = ['KB_UPMF_QA'];
            $outfit = 'burgundy_scholar';
        } elseif (strpos($low, 'research') !== false || strpos($low, 'publication') !== false) {
            $cat = 'RESEARCH_HEAD';
            $zone = 'RESEARCH_HEAD';
            $roles[] = ['role_code' => 'RESEARCH_HEAD', 'data_scope' => 'RESEARCH'];
            $tools[] = 'get_research_projects';
            $tools[] = 'get_publication_statistics';
            $kbs = ['KB_RESEARCH_INNOV'];
        } elseif (strpos($low, 'dean') !== false || strpos($low, 'faculty-wide') !== false) {
            $cat = 'DEAN';
            $zone = 'DEAN';
            $roles[] = ['role_code' => 'DEAN', 'data_scope' => 'ALL_FACULTY'];
            $tools[] = 'get_faculty_statistics';
            $kbs = ['KB_FACULTY_EXEC'];
            $outfit = 'ivory_batik';
        } elseif (strpos($low, 'student') !== false && strpos($low, 'risk') === false && strpos($low, 'kaprodi') === false) {
            $cat = 'STUDENT';
            $zone = 'STUDENT';
            $roles[] = ['role_code' => 'STUDENT', 'data_scope' => 'OWN_STUDENT'];
            $tools[] = 'get_student_profile';
            $kbs = ['KB_STUDENT_GUIDE'];
        } else {
            $roles[] = ['role_code' => 'KAPRODI_IF', 'data_scope' => 'OWN_STUDY_PROGRAM'];
            $tools[] = 'get_student_academic_progress';
            $tools[] = 'get_thesis_status';
            $tools[] = 'get_program_statistics';
        }

        if (strpos($low, 'risk') !== false || strpos($low, 'delay') !== false || strpos($low, 'graduation') !== false) {
            $tools[] = 'get_student_academic_progress';
            $tools[] = 'get_thesis_status';
            $tools[] = 'get_attendance_summary';
        }

        $slug = 'ai_agent_' . substr(md5($desc), 0, 6);
        $title = ucwords(trim(preg_replace('/[^a-z0-9\s]/i', '', substr($desc, 0, 48)))) ?: 'Custom FILKOM Agent';
        if (strlen($title) > 48) $title = substr($title, 0, 45) . ' Agent';

        return [
            'agent_name' => $title,
            'agent_code' => $slug,
            'description' => $desc,
            'agent_category' => $cat,
            'zone_code' => $zone,
            'system_prompt' => "You are {$title} in the FILKOM AI Agent Orchestrator. Task objective: {$desc}. Use only authorized read-only tools within the caller's verified scope and cite official knowledge sources.",
            'suggested_roles' => $roles,
            'suggested_tool_codes' => array_values(array_unique($tools)),
            'suggested_kb_codes' => $kbs,
            'avatar_outfit' => $outfit,
            'animation_profile' => $anim
        ];
    }

    public function approveAgent(int $agentId, int $adminUserId): array {
        if ($agentId <= 0 || !$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Invalid agent ID.'];
        }
        $rbac = new RbacService($this->db);
        if (!$rbac->userHasPermission($adminUserId, 'agents.approve')) {
            return ['success' => false, 'message' => 'Only authorized Administrators can approve AI-generated agents.'];
        }
        $this->db->runPrepared(
            "UPDATE tbl_agents SET approval_status = 'approved', status = 'active', approved_by = ?, approved_at = NOW() WHERE id = ? AND deleted_at IS NULL",
            [$adminUserId, $agentId]
        );
        return ['success' => true, 'message' => 'Agent approved and activated.'];
    }

    public function toggleAgentStatus(int $agentId, string $status): array {
        if ($agentId <= 0 || !in_array($status, ['active', 'inactive'], true)) {
            return ['success' => false, 'message' => 'Invalid agent or status.'];
        }
        if ($status === 'active') {
            $st = $this->db->runPrepared("SELECT approval_status FROM tbl_agents WHERE id = ? LIMIT 1", [$agentId]);
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
            if (!$row || ($row['approval_status'] ?? '') !== 'approved') {
                return ['success' => false, 'message' => 'Agent must be approved by an Administrator before activation.'];
            }
        }
        $this->db->runPrepared("UPDATE tbl_agents SET status = ? WHERE id = ?", [$status, $agentId]);
        return ['success' => true, 'message' => "Agent status set to {$status}."];
    }

    public function rollbackToVersion(int $agentId, int $versionId, int $actorUserId): array {
        if ($agentId <= 0 || $versionId <= 0 || !$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Invalid agent or version ID.'];
        }
        $st = $this->db->runPrepared("SELECT * FROM tbl_agent_versions WHERE id = ? AND agent_id = ? LIMIT 1", [$versionId, $agentId]);
        $ver = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        if (!$ver) {
            return ['success' => false, 'message' => 'Target version snapshot not found.'];
        }
        $snap = json_decode((string)$ver['config_snapshot_json'], true);
        if (!is_array($snap)) {
            return ['success' => false, 'message' => 'Corrupted version snapshot.'];
        }

        try {
            $this->db->beginTransaction();
            $this->db->runPrepared(
                "UPDATE tbl_agents SET agent_name = ?, description = ?, agent_category = ?, zone_code = ?, avatar_outfit = ?, animation_profile = ? WHERE id = ?",
                [
                    (string)($snap['agent_name'] ?? 'Agent'),
                    (string)($snap['description'] ?? ''),
                    (string)($snap['agent_category'] ?? 'CUSTOM'),
                    (string)($snap['zone_code'] ?? 'COMMAND_CENTER'),
                    (string)($snap['avatar_outfit'] ?? 'classic_navy'),
                    (string)($snap['animation_profile'] ?? 'executive_briefing'),
                    $agentId
                ]
            );
            $this->db->runPrepared(
                "UPDATE tbl_agent_configurations SET model_name = ?, temperature = ?, max_output_tokens = ? WHERE agent_id = ?",
                [
                    (string)($snap['model_name'] ?? 'gpt-4.1-mini'),
                    number_format((float)($snap['temperature'] ?? 0.40), 2, '.', ''),
                    (int)($snap['max_output_tokens'] ?? 900),
                    $agentId
                ]
            );
            $this->saveAgentBindings($agentId, $snap['role_assignments'] ?? [], $snap['tool_ids'] ?? [], $snap['kb_ids'] ?? [], $actorUserId);
            $newVer = $this->createNewPromptAndSnapshot($agentId, (string)($snap['system_prompt'] ?? ''), $snap, "Rollback to v{$ver['version_number']}", $actorUserId);
            $this->db->commit();
            return ['success' => true, 'version_number' => $newVer, 'message' => "Rolled back to v{$ver['version_number']} (recorded as v{$newVer})."];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Rollback failed: ' . $e->getMessage()];
        }
    }

    public function duplicateAgent(int $agentId, int $actorUserId, string $actorName): array {
        $reg = new AgentRegistryService($this->db);
        $detail = $reg->getAgentDetail($agentId);
        if (!$detail) return ['success' => false, 'message' => 'Source agent not found.'];

        $newCode = $detail['agent_code'] . '_copy_' . substr(bin2hex(random_bytes(2)), 0, 4);
        $roleAssignments = array_map(static fn($r) => [
            'role_id' => (int)$r['role_id'],
            'role_code' => $r['role_code'],
            'data_scope' => $r['data_scope'],
            'allowed_study_programs' => $r['allowed_study_programs']
        ], $detail['role_access'] ?? []);

        return $this->saveManualAgent([
            'agent_code' => $newCode,
            'agent_name' => $detail['agent_name'] . ' (Copy)',
            'description' => $detail['description'],
            'agent_category' => $detail['agent_category'],
            'zone_code' => $detail['zone_code'],
            'avatar_model' => $detail['avatar_model'],
            'avatar_outfit' => $detail['avatar_outfit'],
            'animation_profile' => $detail['animation_profile'],
            'priority' => $detail['priority'],
            'status' => 'inactive',
            'system_prompt' => $detail['system_prompt'],
            'model_name' => $detail['model_name'],
            'temperature' => $detail['temperature'],
            'max_output_tokens' => $detail['max_output_tokens'],
            'role_assignments' => $roleAssignments,
            'tool_ids' => array_map(static fn($t) => (int)$t['id'], $detail['tools'] ?? []),
            'kb_ids' => array_map(static fn($k) => (int)$k['id'], $detail['knowledge_bases'] ?? []),
            'change_note' => 'Duplicated from ' . $detail['agent_code']
        ], $actorUserId, $actorName);
    }
}
