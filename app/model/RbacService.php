<?php

class RbacService {
    private Database $db;
    private static array $contextCache = [];

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public static function clearCache(?int $userId = null): void {
        if ($userId === null) {
            self::$contextCache = [];
        } else {
            unset(self::$contextCache[$userId]);
        }
    }

    public function getUserContext(int $userId, bool $forceRefresh = false): ?array {
        if ($userId <= 0 || !$this->db->isConnected()) {
            return null;
        }
        if (!$forceRefresh && isset(self::$contextCache[$userId])) {
            return self::$contextCache[$userId];
        }

        $this->db->prepare("SELECT id, username, email, full_name, department, phone, avatar_color, status, mfa_enabled, last_login_at, last_login_ip, created_at
            FROM tbl_users
            WHERE id = :id AND deleted_at IS NULL
            LIMIT 1");
        $this->db->bind(':id', $userId, PDO::PARAM_INT);
        $user = $this->db->single();
        if (!$user) {
            return null;
        }

        $this->db->prepare("SELECT ur.id AS user_role_id, ur.role_id, ur.study_program_id, ur.is_primary,
                r.role_code, r.role_name, r.zone_code,
                sp.program_code, sp.program_name, sp.degree_level
            FROM tbl_user_roles ur
            INNER JOIN tbl_roles r ON r.id = ur.role_id
            LEFT JOIN tbl_study_programs sp ON sp.id = ur.study_program_id
            WHERE ur.user_id = :uid AND ur.is_active = 1
            ORDER BY ur.is_primary DESC, ur.id ASC");
        $this->db->bind(':uid', $userId, PDO::PARAM_INT);
        $roles = $this->db->resultSet();

        $roleIds = [];
        $roleCodes = [];
        $programIds = [];
        $programCodes = [];
        $primaryRole = null;

        foreach ($roles as $r) {
            $roleIds[] = (int)$r['role_id'];
            $code = strtoupper((string)$r['role_code']);
            $roleCodes[] = $code;
            if ($primaryRole === null) {
                $primaryRole = $r;
            }
            if (!empty($r['study_program_id'])) {
                $programIds[] = (int)$r['study_program_id'];
            }
            if (!empty($r['program_code'])) {
                $programCodes[] = strtoupper((string)$r['program_code']);
            }
        }

        $roleMapProgram = [
            'KAPRODI_IF' => 'IF',
            'KAPRODI_SI' => 'SI',
            'KAPRODI_TI' => 'TI',
            'KAPRODI_MSI' => 'MSI'
        ];
        foreach ($roleCodes as $rc) {
            if (isset($roleMapProgram[$rc]) && !in_array($roleMapProgram[$rc], $programCodes, true)) {
                $programCodes[] = $roleMapProgram[$rc];
            }
        }

        if (!empty($programCodes) && empty($programIds)) {
            $placeholders = implode(',', array_fill(0, count($programCodes), '?'));
            $st = $this->db->runPrepared("SELECT id FROM tbl_study_programs WHERE program_code IN ({$placeholders})", $programCodes);
            if ($st) {
                foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                    $programIds[] = (int)$pr['id'];
                }
            }
        }

        $permissions = [];
        if (!empty($roleIds)) {
            $uniqueRoleIds = array_values(array_unique($roleIds));
            $ph = implode(',', array_fill(0, count($uniqueRoleIds), '?'));
            $stPerm = $this->db->runPrepared(
                "SELECT DISTINCT p.permission_code
                 FROM tbl_role_permissions rp
                 INNER JOIN tbl_permissions p ON p.id = rp.permission_id
                 WHERE rp.role_id IN ({$ph})",
                $uniqueRoleIds
            );
            if ($stPerm) {
                foreach ($stPerm->fetchAll(PDO::FETCH_ASSOC) as $pr) {
                    $permissions[] = (string)$pr['permission_code'];
                }
            }
        }

        $this->db->prepare("SELECT s.id, s.nim, s.study_program_id, s.full_name, s.cohort_year, s.current_semester, s.gpa, s.total_credits, s.academic_status, sp.program_code, sp.program_name
            FROM tbl_students s
            INNER JOIN tbl_study_programs sp ON sp.id = s.study_program_id
            WHERE s.user_id = :uid AND s.deleted_at IS NULL
            LIMIT 1");
        $this->db->bind(':uid', $userId, PDO::PARAM_INT);
        $studentRecord = $this->db->single();

        $this->db->prepare("SELECT l.id, l.nidn, l.study_program_id, l.full_name, l.academic_rank, sp.program_code
            FROM tbl_lecturers l
            INNER JOIN tbl_study_programs sp ON sp.id = l.study_program_id
            WHERE l.user_id = :uid
            LIMIT 1");
        $this->db->bind(':uid', $userId, PDO::PARAM_INT);
        $lecturerRecord = $this->db->single();

        if ($studentRecord && empty($programIds)) {
            $programIds[] = (int)$studentRecord['study_program_id'];
            $programCodes[] = strtoupper((string)$studentRecord['program_code']);
        }

        $roleCodes = array_values(array_unique($roleCodes));
        $primaryRoleCode = $primaryRole ? strtoupper((string)$primaryRole['role_code']) : ($roleCodes[0] ?? 'STUDENT');
        $zoneCode = $primaryRole ? strtoupper((string)$primaryRole['zone_code']) : 'STUDENT';

        $isAdmin = in_array('ADMIN', $roleCodes, true);
        $isDean = in_array('DEAN', $roleCodes, true);
        $isSecretary = in_array('SECRETARY', $roleCodes, true);
        $isUpmf = in_array('UPMF', $roleCodes, true);
        $isResearch = in_array('RESEARCH_HEAD', $roleCodes, true);
        $isStudent = in_array('STUDENT', $roleCodes, true);
        $isKaprodi = !empty(array_intersect($roleCodes, ['KAPRODI_IF', 'KAPRODI_SI', 'KAPRODI_TI', 'KAPRODI_MSI']));

        $uniquePerms = array_values(array_unique($permissions));
        $ctx = [
            'user' => $user,
            'user_id' => (int)$user['id'],
            'username' => (string)$user['username'],
            'full_name' => (string)$user['full_name'],
            'primary_role_code' => $primaryRoleCode,
            'primary_role_name' => $primaryRole ? (string)$primaryRole['role_name'] : $primaryRoleCode,
            'zone_code' => $zoneCode,
            'role_ids' => array_values(array_unique($roleIds)),
            'role_codes' => $roleCodes,
            'roles' => $roles,
            'permissions' => $uniquePerms,
            'permission_codes' => $uniquePerms,
            'program_ids' => array_values(array_unique($programIds)),
            'program_codes' => array_values(array_unique($programCodes)),
            'student' => $studentRecord,
            'student_id' => $studentRecord ? (int)$studentRecord['id'] : null,
            'lecturer' => $lecturerRecord,
            'lecturer_id' => $lecturerRecord ? (int)$lecturerRecord['id'] : null,
            'scope' => [
                'is_admin' => $isAdmin,
                'is_dean' => $isDean,
                'is_kaprodi' => $isKaprodi,
                'is_secretary' => $isSecretary,
                'is_upmf' => $isUpmf,
                'is_research_head' => $isResearch,
                'is_student' => $isStudent,
                'all_faculty' => $isAdmin || $isDean,
                'program_ids' => ($isAdmin || $isDean) ? [] : array_values(array_unique($programIds)),
                'program_codes' => ($isAdmin || $isDean) ? [] : array_values(array_unique($programCodes)),
                'student_id' => $studentRecord ? (int)$studentRecord['id'] : null
            ]
        ];

        self::$contextCache[$userId] = $ctx;
        return $ctx;
    }

    public function getUserRoleCodes(int $userId): array {
        $ctx = $this->getUserContext($userId);
        return $ctx ? $ctx['role_codes'] : [];
    }

    public function userHasRole(int $userId, string $roleCode): bool {
        $codes = $this->getUserRoleCodes($userId);
        return in_array(strtoupper($roleCode), $codes, true);
    }

    public function userHasPermission(int $userId, string $permissionCode): bool {
        $ctx = $this->getUserContext($userId);
        if (!$ctx) return false;
        if (in_array('ADMIN', $ctx['role_codes'], true)) {
            return true;
        }
        return in_array($permissionCode, $ctx['permissions'], true);
    }

    public function getAllRoles(): array {
        if (!$this->db->isConnected()) return [];
        $this->db->prepare("SELECT r.id, r.role_code, r.role_name, r.description, r.zone_code, r.is_system,
                COUNT(DISTINCT ur.user_id) AS active_users
            FROM tbl_roles r
            LEFT JOIN tbl_user_roles ur ON ur.role_id = r.id AND ur.is_active = 1
            GROUP BY r.id
            ORDER BY r.id ASC");
        return $this->db->resultSet();
    }

    public function getAllPermissionsMatrix(): array {
        if (!$this->db->isConnected()) return ['roles' => [], 'permissions' => [], 'matrix' => []];
        $roles = $this->getAllRoles();
        $this->db->prepare("SELECT id, permission_code, permission_name, module, description FROM tbl_permissions ORDER BY module ASC, id ASC");
        $permissions = $this->db->resultSet();
        $this->db->prepare("SELECT role_id, permission_id FROM tbl_role_permissions");
        $pairs = $this->db->resultSet();
        $matrix = [];
        foreach ($pairs as $pair) {
            $rid = (int)$pair['role_id'];
            $pid = (int)$pair['permission_id'];
            if (!isset($matrix[$rid])) $matrix[$rid] = [];
            $matrix[$rid][] = $pid;
        }
        return [
            'roles' => $roles,
            'permissions' => $permissions,
            'matrix' => $matrix
        ];
    }

    public function saveRolePermissions(int $roleId, array $permissionIds, string $actor): array {
        if ($roleId <= 0 || !$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Invalid role selection.'];
        }
        $this->db->prepare("SELECT id, role_code FROM tbl_roles WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $roleId, PDO::PARAM_INT);
        $role = $this->db->single();
        if (!$role) {
            return ['success' => false, 'message' => 'Role not found.'];
        }
        $cleanIds = array_values(array_unique(array_filter(array_map('intval', $permissionIds), static fn($v) => $v > 0)));
        try {
            $this->db->beginTransaction();
            $this->db->prepare("DELETE FROM tbl_role_permissions WHERE role_id = :rid");
            $this->db->bind(':rid', $roleId, PDO::PARAM_INT);
            $this->db->execute();

            $pdo = $this->db->getPdo();
            if ($pdo && !empty($cleanIds)) {
                $st = $pdo->prepare("INSERT IGNORE INTO tbl_role_permissions (role_id, permission_id) VALUES (?, ?)");
                foreach ($cleanIds as $pid) {
                    $st->execute([$roleId, $pid]);
                }
            }
            $this->db->commit();
            self::clearCache();
            return ['success' => true, 'message' => "Permissions updated for role {$role['role_code']}."];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to update role permissions.'];
        }
    }
}
