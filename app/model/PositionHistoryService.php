<?php

class PositionHistoryService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public function listHistory(array $params = []): array {
        if (!$this->db->isConnected()) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        }
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(5, min(100, (int)($params['per_page'] ?? 15)));
        $roleFilter = strtoupper(trim((string)($params['role_code'] ?? '')));
        $statusFilter = strtolower(trim((string)($params['status'] ?? '')));
        $search = trim((string)($params['search'] ?? ''));

        $where = ['1=1'];
        $binds = [];
        if ($roleFilter !== '') {
            $where[] = 'r.role_code = ?';
            $binds[] = $roleFilter;
        }
        if (in_array($statusFilter, ['active', 'ended'], true)) {
            $where[] = 'h.assignment_status = ?';
            $binds[] = $statusFilter;
        }
        if ($search !== '') {
            $where[] = '(u.full_name LIKE ? OR u.username LIKE ? OR r.role_name LIKE ? OR h.replacement_reason LIKE ?)';
            $like = '%' . $search . '%';
            array_push($binds, $like, $like, $like, $like);
        }
        $whereSql = implode(' AND ', $where);

        $stCnt = $this->db->runPrepared(
            "SELECT COUNT(*) FROM tbl_user_role_history h
             INNER JOIN tbl_users u ON u.id = h.user_id
             INNER JOIN tbl_roles r ON r.id = h.role_id
             WHERE {$whereSql}",
            $binds
        );
        $total = $stCnt ? (int)$stCnt->fetchColumn() : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $qBinds = array_merge($binds, [$perPage, $offset]);
        $stRows = $this->db->runPrepared(
            "SELECT h.id, h.user_id, h.role_id, h.study_program_id, h.replaced_user_id,
                    h.assigned_at, h.ended_at, h.assignment_status, h.replacement_reason,
                    h.assigned_by, h.ended_by, h.created_at,
                    u.full_name AS holder_name, u.username AS holder_username, u.email AS holder_email,
                    pu.full_name AS replaced_holder_name, pu.username AS replaced_holder_username,
                    r.role_code, r.role_name,
                    sp.program_code, sp.program_name
             FROM tbl_user_role_history h
             INNER JOIN tbl_users u ON u.id = h.user_id
             LEFT JOIN tbl_users pu ON pu.id = h.replaced_user_id
             INNER JOIN tbl_roles r ON r.id = h.role_id
             LEFT JOIN tbl_study_programs sp ON sp.id = h.study_program_id
             WHERE {$whereSql}
             ORDER BY FIELD(h.assignment_status, 'active', 'ended'), h.assigned_at DESC, h.id DESC
             LIMIT ? OFFSET ?",
            $qBinds
        );

        return [
            'items' => $stRows ? $stRows->fetchAll(PDO::FETCH_ASSOC) : [],
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'total_pages' => $totalPages
        ];
    }

    public function replacePositionHolder(array $data, string $actor = 'admin'): array {
        if (!$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Database unavailable.'];
        }
        $roleCode = strtoupper(trim((string)($data['role_code'] ?? '')));
        $newUserId = (int)($data['new_user_id'] ?? 0);
        $reason = trim((string)($data['replacement_reason'] ?? 'Official leadership transition'));
        $effectiveAt = trim((string)($data['effective_at'] ?? date('Y-m-d H:i:s')));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}( \d{2}:\d{2}:\d{2})?$/', $effectiveAt)) {
            $effectiveAt = date('Y-m-d H:i:s');
        } elseif (strlen($effectiveAt) === 10) {
            $effectiveAt .= ' 08:00:00';
        }

        if ($roleCode === '' || $newUserId <= 0 || strlen($reason) < 3) {
            return ['success' => false, 'message' => 'Role code, new holder account, and replacement reason are required.'];
        }

        $leadershipRoles = ['DEAN', 'KAPRODI_IF', 'KAPRODI_SI', 'KAPRODI_TI', 'KAPRODI_MSI', 'SECRETARY', 'UPMF', 'RESEARCH_HEAD'];
        if (!in_array($roleCode, $leadershipRoles, true)) {
            return ['success' => false, 'message' => 'Selected role is not an exclusive leadership position.'];
        }

        $this->db->prepare("SELECT id, role_code, role_name FROM tbl_roles WHERE role_code = :rc LIMIT 1");
        $this->db->bind(':rc', $roleCode);
        $role = $this->db->single();
        if (!$role) {
            return ['success' => false, 'message' => 'Target role does not exist.'];
        }
        $roleId = (int)$role['id'];

        $this->db->prepare("SELECT id, username, full_name, status FROM tbl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':id', $newUserId, PDO::PARAM_INT);
        $newHolder = $this->db->single();
        if (!$newHolder || ($newHolder['status'] ?? '') !== 'active') {
            return ['success' => false, 'message' => 'New holder user must be an active account.'];
        }

        $programCodeMap = [
            'KAPRODI_IF' => 'IF',
            'KAPRODI_SI' => 'SI',
            'KAPRODI_TI' => 'TI',
            'KAPRODI_MSI' => 'MSI'
        ];
        $studyProgramId = !empty($data['study_program_id']) ? (int)$data['study_program_id'] : null;
        if (isset($programCodeMap[$roleCode])) {
            $this->db->prepare("SELECT id FROM tbl_study_programs WHERE program_code = :pc LIMIT 1");
            $this->db->bind(':pc', $programCodeMap[$roleCode]);
            $spRow = $this->db->single();
            if ($spRow) {
                $studyProgramId = (int)$spRow['id'];
            }
        }

        try {
            $this->db->beginTransaction();

            $this->db->prepare("SELECT id, user_id FROM tbl_user_role_history
                WHERE role_id = :rid AND assignment_status = 'active'
                  AND (study_program_id <=> :spid)
                ORDER BY assigned_at DESC LIMIT 1");
            $this->db->bind(':rid', $roleId, PDO::PARAM_INT);
            $this->db->bind(':spid', $studyProgramId);
            $prevHistory = $this->db->single();

            $prevUserId = $prevHistory ? (int)$prevHistory['user_id'] : null;
            if ($prevUserId === $newUserId) {
                $this->db->rollBack();
                return ['success' => false, 'message' => 'Selected user already holds this active position.'];
            }

            if ($prevHistory) {
                $this->db->prepare("UPDATE tbl_user_role_history
                    SET ended_at = :ea, assignment_status = 'ended', ended_by = :eb
                    WHERE id = :hid");
                $this->db->bind(':ea', $effectiveAt);
                $this->db->bind(':eb', $actor);
                $this->db->bind(':hid', (int)$prevHistory['id'], PDO::PARAM_INT);
                $this->db->execute();
            }

            $this->db->prepare("UPDATE tbl_user_roles SET is_active = 0 WHERE role_id = :rid AND (study_program_id <=> :spid) AND user_id != :nuid");
            $this->db->bind(':rid', $roleId, PDO::PARAM_INT);
            $this->db->bind(':spid', $studyProgramId);
            $this->db->bind(':nuid', $newUserId, PDO::PARAM_INT);
            $this->db->execute();

            $this->db->prepare("SELECT id FROM tbl_user_roles WHERE user_id = :uid AND role_id = :rid AND (study_program_id <=> :spid) LIMIT 1");
            $this->db->bind(':uid', $newUserId, PDO::PARAM_INT);
            $this->db->bind(':rid', $roleId, PDO::PARAM_INT);
            $this->db->bind(':spid', $studyProgramId);
            $existingUr = $this->db->single();

            if ($existingUr) {
                $this->db->prepare("UPDATE tbl_user_roles SET is_active = 1, is_primary = 1, assigned_by = :ab, assigned_at = :aa WHERE id = :id");
                $this->db->bind(':ab', $actor);
                $this->db->bind(':aa', $effectiveAt);
                $this->db->bind(':id', (int)$existingUr['id'], PDO::PARAM_INT);
                $this->db->execute();
            } else {
                $this->db->prepare("INSERT INTO tbl_user_roles (user_id, role_id, study_program_id, is_primary, is_active, assigned_by, assigned_at)
                    VALUES (:uid, :rid, :spid, 1, 1, :ab, :aa)");
                $this->db->bind(':uid', $newUserId, PDO::PARAM_INT);
                $this->db->bind(':rid', $roleId, PDO::PARAM_INT);
                $this->db->bind(':spid', $studyProgramId);
                $this->db->bind(':ab', $actor);
                $this->db->bind(':aa', $effectiveAt);
                $this->db->execute();
            }

            $this->db->prepare("INSERT INTO tbl_user_role_history
                (user_id, role_id, study_program_id, replaced_user_id, assigned_at, ended_at, assignment_status, replacement_reason, assigned_by)
                VALUES (:uid, :rid, :spid, :puid, :aa, NULL, 'active', :rsn, :ab)");
            $this->db->bind(':uid', $newUserId, PDO::PARAM_INT);
            $this->db->bind(':rid', $roleId, PDO::PARAM_INT);
            $this->db->bind(':spid', $studyProgramId);
            $this->db->bind(':puid', $prevUserId);
            $this->db->bind(':aa', $effectiveAt);
            $this->db->bind(':rsn', substr($reason, 0, 255));
            $this->db->bind(':ab', $actor);
            $this->db->execute();

            $this->db->commit();
            RbacService::clearCache();

            return [
                'success' => true,
                'message' => "Position {$role['role_name']} successfully transferred to {$newHolder['full_name']} with historical record preserved."
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Position replacement failed: ' . $e->getMessage()];
        }
    }
}
