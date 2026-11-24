<?php

class UserManagementService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public function listUsers(array $params = []): array {
        if (!$this->db->isConnected()) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        }
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(5, min(100, (int)($params['per_page'] ?? 15)));
        $search = trim((string)($params['search'] ?? ''));
        $roleFilter = strtoupper(trim((string)($params['role_code'] ?? ($params['filter1'] ?? ''))));
        $statusFilter = strtolower(trim((string)($params['status'] ?? ($params['filter2'] ?? ''))));

        $where = ['u.deleted_at IS NULL'];
        $binds = [];
        if ($search !== '') {
            $where[] = '(u.username LIKE ? OR u.email LIKE ? OR u.full_name LIKE ? OR u.department LIKE ?)';
            $like = '%' . $search . '%';
            array_push($binds, $like, $like, $like, $like);
        }
        if ($statusFilter !== '' && in_array($statusFilter, ['active', 'inactive', 'suspended'], true)) {
            $where[] = 'u.status = ?';
            $binds[] = $statusFilter;
        }
        if ($roleFilter !== '') {
            $where[] = 'EXISTS (SELECT 1 FROM tbl_user_roles ur2 INNER JOIN tbl_roles r2 ON r2.id = ur2.role_id WHERE ur2.user_id = u.id AND ur2.is_active = 1 AND r2.role_code = ?)';
            $binds[] = $roleFilter;
        }

        $whereSql = implode(' AND ', $where);
        $stCnt = $this->db->runPrepared("SELECT COUNT(*) FROM tbl_users u WHERE {$whereSql}", $binds);
        $total = $stCnt ? (int)$stCnt->fetchColumn() : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $qBinds = array_merge($binds, [$perPage, $offset]);
        $stRows = $this->db->runPrepared(
            "SELECT u.id, u.username, u.email, u.full_name, u.department, u.phone, u.avatar_color,
                    u.status, u.mfa_enabled, u.failed_attempts, u.locked_until, u.last_login_at, u.last_login_ip, u.created_by, u.created_at
             FROM tbl_users u
             WHERE {$whereSql}
             ORDER BY u.id ASC
             LIMIT ? OFFSET ?",
            $qBinds
        );
        $items = $stRows ? $stRows->fetchAll(PDO::FETCH_ASSOC) : [];

        if (!empty($items)) {
            $userIds = array_map(static fn($r) => (int)$r['id'], $items);
            $ph = implode(',', array_fill(0, count($userIds), '?'));
            $stRoles = $this->db->runPrepared(
                "SELECT ur.user_id, ur.role_id, ur.study_program_id, ur.is_primary, r.role_code, r.role_name, sp.program_code
                 FROM tbl_user_roles ur
                 INNER JOIN tbl_roles r ON r.id = ur.role_id
                 LEFT JOIN tbl_study_programs sp ON sp.id = ur.study_program_id
                 WHERE ur.user_id IN ({$ph}) AND ur.is_active = 1
                 ORDER BY ur.is_primary DESC, ur.id ASC",
                $userIds
            );
            $rolesByUser = [];
            if ($stRoles) {
                foreach ($stRoles->fetchAll(PDO::FETCH_ASSOC) as $rr) {
                    $uid = (int)$rr['user_id'];
                    if (!isset($rolesByUser[$uid])) $rolesByUser[$uid] = [];
                    $rolesByUser[$uid][] = $rr;
                }
            }
            foreach ($items as &$item) {
                $uid = (int)$item['id'];
                $uRoles = $rolesByUser[$uid] ?? [];
                $item['roles'] = $uRoles;
                $item['role'] = $uRoles[0]['role_code'] ?? 'UNASSIGNED';
                $item['role_codes'] = array_map(static fn($x) => $x['role_code'], $uRoles);
                $item['program_codes'] = array_values(array_filter(array_map(static fn($x) => $x['program_code'] ?? null, $uRoles)));
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

    public function saveUser(array $data, string $actor = 'admin'): array {
        if (!$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Database unavailable.'];
        }
        $id = (int)($data['id'] ?? 0);
        $username = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', trim((string)($data['username'] ?? '')));
        $email = strtolower(trim((string)($data['email'] ?? '')));
        $fullName = trim((string)($data['full_name'] ?? ''));
        $dept = trim((string)($data['department'] ?? 'Fakultas Ilmu Komputer'));
        $phone = trim((string)($data['phone'] ?? ''));
        $color = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($data['avatar_color'] ?? '')) ? $data['avatar_color'] : '#2563eb';
        $status = in_array(($data['status'] ?? 'active'), ['active', 'inactive', 'suspended'], true) ? $data['status'] : 'active';
        $password = (string)($data['password'] ?? '');

        $roleCodes = [];
        if (!empty($data['role_codes']) && is_array($data['role_codes'])) {
            $roleCodes = array_values(array_unique(array_map('strtoupper', array_map('trim', $data['role_codes']))));
        } elseif (!empty($data['role'])) {
            $roleCodes = [strtoupper(trim((string)$data['role']))];
        }
        if (empty($roleCodes)) {
            $roleCodes = ['STUDENT'];
        }

        $studyProgramId = !empty($data['study_program_id']) ? (int)$data['study_program_id'] : null;

        if (strlen($username) < 3 || !filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($fullName) < 3) {
            return ['success' => false, 'message' => 'Username (min 3 chars), valid Email, and Full Name are required.'];
        }

        $this->db->prepare("SELECT id FROM tbl_users WHERE (LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e)) AND id != :id AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':u', $username);
        $this->db->bind(':e', $email);
        $this->db->bind(':id', $id, PDO::PARAM_INT);
        if ($this->db->single()) {
            return ['success' => false, 'message' => 'Username or Email is already registered.'];
        }

        try {
            $this->db->beginTransaction();

            if ($id > 0) {
                if ($password !== '') {
                    if (strlen($password) < 6) {
                        $this->db->rollBack();
                        return ['success' => false, 'message' => 'New password must be at least 6 characters.'];
                    }
                    $hash = Security::hash_password($password);
                    $this->db->prepare("UPDATE tbl_users SET username = :u, email = :e, full_name = :fn, password_hash = :ph, department = :d, phone = :p, avatar_color = :c, status = :s, failed_attempts = 0, locked_until = NULL WHERE id = :id");
                    $this->db->bind(':ph', $hash);
                } else {
                    $this->db->prepare("UPDATE tbl_users SET username = :u, email = :e, full_name = :fn, department = :d, phone = :p, avatar_color = :c, status = :s, failed_attempts = 0, locked_until = NULL WHERE id = :id");
                }
                $this->db->bind(':u', $username);
                $this->db->bind(':e', $email);
                $this->db->bind(':fn', $fullName);
                $this->db->bind(':d', $dept);
                $this->db->bind(':p', $phone);
                $this->db->bind(':c', $color);
                $this->db->bind(':s', $status);
                $this->db->bind(':id', $id, PDO::PARAM_INT);
                $this->db->execute();
                $targetUserId = $id;
            } else {
                if (strlen($password) < 6) {
                    $this->db->rollBack();
                    return ['success' => false, 'message' => 'Password (minimum 6 characters) is required for new users.'];
                }
                $hash = Security::hash_password($password);
                $this->db->prepare("INSERT INTO tbl_users (username, email, full_name, password_hash, department, phone, avatar_color, status, created_by)
                    VALUES (:u, :e, :fn, :ph, :d, :p, :c, :s, :cb)");
                $this->db->bind(':u', $username);
                $this->db->bind(':e', $email);
                $this->db->bind(':fn', $fullName);
                $this->db->bind(':ph', $hash);
                $this->db->bind(':d', $dept);
                $this->db->bind(':p', $phone);
                $this->db->bind(':c', $color);
                $this->db->bind(':s', $status);
                $this->db->bind(':cb', $actor);
                $this->db->execute();
                $targetUserId = $this->db->lastInsertId();
            }

            $this->syncUserRoles($targetUserId, $roleCodes, $studyProgramId, $actor);

            $this->db->commit();
            RbacService::clearCache($targetUserId);
            return [
                'success' => true,
                'user_id' => $targetUserId,
                'message' => $id > 0 ? 'User updated successfully.' : 'User created successfully.'
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to save user: ' . $e->getMessage()];
        }
    }

    private function syncUserRoles(int $userId, array $roleCodes, ?int $explicitProgId, string $actor): void {
        $ph = implode(',', array_fill(0, count($roleCodes), '?'));
        $stR = $this->db->runPrepared("SELECT id, role_code FROM tbl_roles WHERE role_code IN ({$ph})", $roleCodes);
        $roleRows = $stR ? $stR->fetchAll(PDO::FETCH_ASSOC) : [];
        if (empty($roleRows)) {
            return;
        }

        $this->db->prepare("UPDATE tbl_user_roles SET is_active = 0 WHERE user_id = :uid");
        $this->db->bind(':uid', $userId, PDO::PARAM_INT);
        $this->db->execute();

        $progMap = ['KAPRODI_IF' => 'IF', 'KAPRODI_SI' => 'SI', 'KAPRODI_TI' => 'TI', 'KAPRODI_MSI' => 'MSI'];
        $progIdsByCode = [];
        $stP = $this->db->runPrepared("SELECT id, program_code FROM tbl_study_programs");
        if ($stP) {
            foreach ($stP->fetchAll(PDO::FETCH_ASSOC) as $p) {
                $progIdsByCode[strtoupper((string)$p['program_code'])] = (int)$p['id'];
            }
        }

        foreach ($roleRows as $idx => $rr) {
            $rid = (int)$rr['id'];
            $rc = strtoupper((string)$rr['role_code']);
            $spId = isset($progMap[$rc]) ? ($progIdsByCode[$progMap[$rc]] ?? $explicitProgId) : $explicitProgId;
            $isPrim = ($idx === 0) ? 1 : 0;

            $this->db->prepare("SELECT id FROM tbl_user_roles WHERE user_id = :uid AND role_id = :rid AND (study_program_id <=> :spid) LIMIT 1");
            $this->db->bind(':uid', $userId, PDO::PARAM_INT);
            $this->db->bind(':rid', $rid, PDO::PARAM_INT);
            $this->db->bind(':spid', $spId);
            $exist = $this->db->single();
            if ($exist) {
                $this->db->prepare("UPDATE tbl_user_roles SET is_active = 1, is_primary = :ip, assigned_by = :ab, assigned_at = NOW() WHERE id = :id");
                $this->db->bind(':ip', $isPrim, PDO::PARAM_INT);
                $this->db->bind(':ab', $actor);
                $this->db->bind(':id', (int)$exist['id'], PDO::PARAM_INT);
                $this->db->execute();
            } else {
                $this->db->prepare("INSERT INTO tbl_user_roles (user_id, role_id, study_program_id, is_primary, is_active, assigned_by, assigned_at)
                    VALUES (:uid, :rid, :spid, :ip, 1, :ab, NOW())");
                $this->db->bind(':uid', $userId, PDO::PARAM_INT);
                $this->db->bind(':rid', $rid, PDO::PARAM_INT);
                $this->db->bind(':spid', $spId);
                $this->db->bind(':ip', $isPrim, PDO::PARAM_INT);
                $this->db->bind(':ab', $actor);
                $this->db->execute();
            }
        }
    }

    public function setUserStatus(int $targetId, int $currentUserId, string $status): array {
        if ($targetId <= 0 || $targetId === $currentUserId) {
            return ['success' => false, 'message' => 'Cannot change status of your own active session account.'];
        }
        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            return ['success' => false, 'message' => 'Invalid status value.'];
        }
        $this->db->prepare("UPDATE tbl_users SET status = :st WHERE id = :id AND deleted_at IS NULL");
        $this->db->bind(':st', $status);
        $this->db->bind(':id', $targetId, PDO::PARAM_INT);
        $ok = $this->db->execute();
        RbacService::clearCache($targetId);
        return ['success' => $ok, 'message' => $ok ? "User status set to {$status}." : 'Failed to update status.'];
    }

    public function softDeleteUser(int $targetId, int $currentUserId): array {
        if ($targetId <= 0 || $targetId === $currentUserId) {
            return ['success' => false, 'message' => 'Cannot delete your currently logged-in account.'];
        }
        $this->db->prepare("SELECT username FROM tbl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':id', $targetId, PDO::PARAM_INT);
        $row = $this->db->single();
        if (!$row) {
            return ['success' => false, 'message' => 'User account not found.'];
        }
        if (strtolower((string)$row['username']) === 'admin') {
            return ['success' => false, 'message' => 'Primary system admin account is protected.'];
        }
        $this->db->prepare("UPDATE tbl_users SET status = 'inactive', deleted_at = NOW() WHERE id = :id");
        $this->db->bind(':id', $targetId, PDO::PARAM_INT);
        $ok = $this->db->execute();
        if ($ok) {
            $this->db->prepare("UPDATE tbl_user_roles SET is_active = 0 WHERE user_id = :id");
            $this->db->bind(':id', $targetId, PDO::PARAM_INT);
            $this->db->execute();
        }
        RbacService::clearCache($targetId);
        return ['success' => $ok, 'message' => $ok ? 'User deactivated and archived per retention policy.' : 'Delete failed.'];
    }

    public function resetUserPassword(int $targetId, string $newPassword, bool $revokeSessions = true, string $actor = 'admin'): array {
        if ($targetId <= 0 || strlen($newPassword) < 6) {
            return ['success' => false, 'message' => 'Valid user ID and password (min 6 chars) are required.'];
        }
        $this->db->prepare("SELECT id, username, full_name FROM tbl_users WHERE id = :id AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':id', $targetId, PDO::PARAM_INT);
        $target = $this->db->single();
        if (!$target) {
            return ['success' => false, 'message' => 'Target user account not found.'];
        }
        $hash = Security::hash_password($newPassword);
        $this->db->prepare("UPDATE tbl_users SET password_hash = :ph, failed_attempts = 0, locked_until = NULL WHERE id = :id AND deleted_at IS NULL");
        $this->db->bind(':ph', $hash);
        $this->db->bind(':id', $targetId, PDO::PARAM_INT);
        $ok = $this->db->execute();

        $revokedCount = 0;
        if ($ok && $revokeSessions && $this->db->hasTable('tbl_user_sessions')) {
            $stRev = $this->db->runPrepared(
                "UPDATE tbl_user_sessions SET is_revoked = 1 WHERE user_id = ? AND is_revoked = 0",
                [$targetId]
            );
            if ($stRev) {
                $revokedCount = $stRev->rowCount();
            }
        }
        RbacService::clearCache($targetId);
        return [
            'success' => $ok,
            'username' => (string)$target['username'],
            'full_name' => (string)$target['full_name'],
            'sessions_revoked' => $revokedCount,
            'message' => $ok
                ? ("Password for {$target['full_name']} (@{$target['username']}) reset successfully" . ($revokedCount > 0 ? " ({$revokedCount} active session(s) revoked)." : '.'))
                : 'Failed to reset password.'
        ];
    }

    public function getPasswordResetHistory(int $limit = 20): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_audit_logs')) return [];
        $st = $this->db->runPrepared(
            "SELECT id, user_name AS actor, record_id AS target_user_id, description, ip_address, created_at
             FROM tbl_audit_logs
             WHERE action = 'ADMIN_PASSWORD_RESET'
             ORDER BY id DESC
             LIMIT ?",
            [max(5, min(100, $limit))]
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function importUsersFromSpreadsheet(string $tmpPath, string $origName, string $actor = 'admin'): array {
        $parsed = DocumentParser::parseSpreadsheetRows($tmpPath, $origName);
        if (empty($parsed['success'])) {
            return $parsed;
        }
        $rows = $parsed['rows'];
        if (count($rows) < 2) {
            return ['success' => false, 'message' => 'Spreadsheet must contain a header row and at least one user row.'];
        }

        $header = array_map(static fn($h) => strtolower(preg_replace('/[^a-z0-9_]/', '_', trim((string)$h))), $rows[0]);
        $colIdx = array_flip($header);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $username = $r[$colIdx['username'] ?? 0] ?? '';
            $email = $r[$colIdx['email'] ?? 1] ?? '';
            $fullName = $r[$colIdx['full_name'] ?? 2] ?? '';
            $roleCode = strtoupper($r[$colIdx['role_code'] ?? ($colIdx['role'] ?? 3)] ?? 'STUDENT');
            $dept = $r[$colIdx['department'] ?? 4] ?? 'Fakultas Ilmu Komputer';
            $phone = $r[$colIdx['phone'] ?? 5] ?? '';
            $pwd = $r[$colIdx['password'] ?? 6] ?? 'Filkom2026!';

            $res = $this->saveUser([
                'username' => $username,
                'email' => $email,
                'full_name' => $fullName,
                'role' => $roleCode,
                'department' => $dept,
                'phone' => $phone,
                'password' => $pwd ?: 'Filkom2026!',
                'status' => 'active'
            ], $actor);
            if (!empty($res['success'])) {
                $imported++;
            } else {
                $skipped++;
                $errors[] = "Row " . ($i + 1) . ": " . ($res['message'] ?? 'Invalid');
            }
        }

        return [
            'success' => $imported > 0,
            'imported' => $imported,
            'skipped' => $skipped,
            'errors' => array_slice($errors, 0, 10),
            'message' => "Imported {$imported} user(s); skipped {$skipped}."
        ];
    }

    public function getUserActivity(int $userId): array {
        if ($userId <= 0 || !$this->db->isConnected()) return ['logins' => [], 'audits' => [], 'sessions' => []];
        $this->db->prepare("SELECT identifier_input, role_code, ip_address, user_agent, status, failure_reason, created_at
            FROM tbl_login_logs WHERE user_id = :uid ORDER BY id DESC LIMIT 15");
        $this->db->bind(':uid', (string)$userId);
        $logins = $this->db->resultSet();

        $this->db->prepare("SELECT action, target_table, record_id, description, ip_address, created_at
            FROM tbl_audit_logs WHERE user_id = :uid ORDER BY id DESC LIMIT 15");
        $this->db->bind(':uid', (string)$userId);
        $audits = $this->db->resultSet();

        $this->db->prepare("SELECT id, ip_address, device_type, last_activity, is_revoked, created_at
            FROM tbl_user_sessions WHERE user_id = :uid ORDER BY last_activity DESC LIMIT 10");
        $this->db->bind(':uid', (string)$userId);
        $sessions = $this->db->resultSet();

        return ['logins' => $logins, 'audits' => $audits, 'sessions' => $sessions];
    }
}
