<?php

class StudentManagementService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public function listStudents(array $params = [], ?array $scope = null): array {
        if (!$this->db->isConnected()) {
            return ['items' => [], 'total' => 0, 'page' => 1, 'total_pages' => 1];
        }
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(5, min(100, (int)($params['per_page'] ?? 15)));
        $search = trim((string)($params['search'] ?? ''));
        $progFilter = (int)($params['study_program_id'] ?? ($params['filter1'] ?? 0));
        $statusFilter = strtolower(trim((string)($params['academic_status'] ?? ($params['filter2'] ?? ''))));

        $where = ['s.deleted_at IS NULL'];
        $binds = [];

        if ($scope) {
            if (!empty($scope['student_id']) && empty($scope['all_faculty']) && empty($scope['program_ids'])) {
                $where[] = 's.id = ?';
                $binds[] = (int)$scope['student_id'];
            } elseif (!empty($scope['program_ids']) && empty($scope['all_faculty'])) {
                $pIds = array_map('intval', $scope['program_ids']);
                $ph = implode(',', array_fill(0, count($pIds), '?'));
                $where[] = "s.study_program_id IN ({$ph})";
                $binds = array_merge($binds, $pIds);
            }
        }

        if ($search !== '') {
            $where[] = '(s.nim LIKE ? OR s.full_name LIKE ? OR u.username LIKE ? OR u.email LIKE ?)';
            $like = '%' . $search . '%';
            array_push($binds, $like, $like, $like, $like);
        }
        if ($progFilter > 0) {
            $where[] = 's.study_program_id = ?';
            $binds[] = $progFilter;
        }
        if ($statusFilter !== '' && in_array($statusFilter, ['active', 'probation', 'leave', 'graduated', 'inactive'], true)) {
            $where[] = 's.academic_status = ?';
            $binds[] = $statusFilter;
        }

        $whereSql = implode(' AND ', $where);
        $stCnt = $this->db->runPrepared(
            "SELECT COUNT(*) FROM tbl_students s LEFT JOIN tbl_users u ON u.id = s.user_id WHERE {$whereSql}",
            $binds
        );
        $total = $stCnt ? (int)$stCnt->fetchColumn() : 0;
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $qBinds = array_merge($binds, [$perPage, $offset]);
        $stRows = $this->db->runPrepared(
            "SELECT s.id, s.nim, s.user_id, s.study_program_id, s.full_name, s.cohort_year,
                    s.current_semester, s.gpa, s.total_credits, s.academic_status, s.advisor_lecturer_id,
                    sp.program_code, sp.program_name,
                    u.username AS login_username, u.email AS login_email, u.status AS login_status,
                    l.full_name AS advisor_name
             FROM tbl_students s
             INNER JOIN tbl_study_programs sp ON sp.id = s.study_program_id
             LEFT JOIN tbl_users u ON u.id = s.user_id AND u.deleted_at IS NULL
             LEFT JOIN tbl_lecturers l ON l.id = s.advisor_lecturer_id
             WHERE {$whereSql}
             ORDER BY s.cohort_year DESC, s.nim ASC
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

    public function saveStudent(array $data, string $actor = 'admin'): array {
        if (!$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Database unavailable.'];
        }
        $id = (int)($data['id'] ?? 0);
        $nim = preg_replace('/[^a-zA-Z0-9\-]/', '', trim((string)($data['nim'] ?? '')));
        $fullName = trim((string)($data['full_name'] ?? ''));
        $progId = (int)($data['study_program_id'] ?? 0);
        if ($progId <= 0 && !empty($data['program_code'])) {
            $this->db->prepare("SELECT id FROM tbl_study_programs WHERE program_code = :pc LIMIT 1");
            $this->db->bind(':pc', strtoupper(trim((string)$data['program_code'])));
            $pr = $this->db->single();
            if ($pr) $progId = (int)$pr['id'];
        }
        $cohort = max(2015, min(2035, (int)($data['cohort_year'] ?? (int)date('Y'))));
        $sem = max(1, min(14, (int)($data['current_semester'] ?? 1)));
        $gpa = max(0.0, min(4.0, (float)($data['gpa'] ?? 3.00)));
        $credits = max(0, min(200, (int)($data['total_credits'] ?? 0)));
        $acadStatus = in_array(($data['academic_status'] ?? 'active'), ['active', 'probation', 'leave', 'graduated', 'inactive'], true)
            ? $data['academic_status'] : 'active';
        $advisorId = !empty($data['advisor_lecturer_id']) ? (int)$data['advisor_lecturer_id'] : null;

        if (strlen($nim) < 5 || strlen($fullName) < 3 || $progId <= 0) {
            return ['success' => false, 'message' => 'NIM, Full Name, and Study Program are required.'];
        }

        $this->db->prepare("SELECT id FROM tbl_students WHERE nim = :nim AND id != :id AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':nim', $nim);
        $this->db->bind(':id', $id, PDO::PARAM_INT);
        if ($this->db->single()) {
            return ['success' => false, 'message' => "NIM {$nim} is already registered."];
        }

        try {
            $this->db->beginTransaction();

            $userId = isset($data['user_id']) && $data['user_id'] !== '' ? (int)$data['user_id'] : null;
            if (!empty($data['create_login']) && $userId === null) {
                $loginUsername = preg_replace('/[^a-zA-Z0-9_\.\-]/', '', trim((string)($data['login_username'] ?? ('stu_' . strtolower($nim)))));
                $loginEmail = strtolower(trim((string)($data['login_email'] ?? ($nim . '@student.unklab.ac.id'))));
                $loginPwd = (string)($data['login_password'] ?? 'Student123!');
                if (strlen($loginPwd) < 6) $loginPwd = 'Student123!';

                $userSvc = new UserManagementService($this->db);
                $uRes = $userSvc->saveUser([
                    'username' => $loginUsername,
                    'email' => $loginEmail,
                    'full_name' => $fullName,
                    'role' => 'STUDENT',
                    'study_program_id' => $progId,
                    'department' => 'FILKOM Student',
                    'password' => $loginPwd,
                    'status' => $acadStatus === 'inactive' ? 'inactive' : 'active'
                ], $actor);
                if (empty($uRes['success'])) {
                    $this->db->rollBack();
                    return $uRes;
                }
                $userId = (int)$uRes['user_id'];
            }

            if ($id > 0) {
                $this->db->prepare("UPDATE tbl_students
                    SET nim = :nim, study_program_id = :sp, full_name = :fn, cohort_year = :cy,
                        current_semester = :cs, gpa = :gpa, total_credits = :tc, academic_status = :as,
                        advisor_lecturer_id = :adv" . ($userId !== null ? ", user_id = :uid" : "") . "
                    WHERE id = :id");
                $this->db->bind(':nim', $nim);
                $this->db->bind(':sp', $progId, PDO::PARAM_INT);
                $this->db->bind(':fn', $fullName);
                $this->db->bind(':cy', $cohort, PDO::PARAM_INT);
                $this->db->bind(':cs', $sem, PDO::PARAM_INT);
                $this->db->bind(':gpa', number_format($gpa, 2, '.', ''));
                $this->db->bind(':tc', $credits, PDO::PARAM_INT);
                $this->db->bind(':as', $acadStatus);
                $this->db->bind(':adv', $advisorId);
                if ($userId !== null) {
                    $this->db->bind(':uid', $userId, PDO::PARAM_INT);
                }
                $this->db->bind(':id', $id, PDO::PARAM_INT);
                $this->db->execute();
                $studentId = $id;
            } else {
                $this->db->prepare("INSERT INTO tbl_students (nim, user_id, study_program_id, full_name, cohort_year, current_semester, gpa, total_credits, academic_status, advisor_lecturer_id)
                    VALUES (:nim, :uid, :sp, :fn, :cy, :cs, :gpa, :tc, :as, :adv)");
                $this->db->bind(':nim', $nim);
                $this->db->bind(':uid', $userId);
                $this->db->bind(':sp', $progId, PDO::PARAM_INT);
                $this->db->bind(':fn', $fullName);
                $this->db->bind(':cy', $cohort, PDO::PARAM_INT);
                $this->db->bind(':cs', $sem, PDO::PARAM_INT);
                $this->db->bind(':gpa', number_format($gpa, 2, '.', ''));
                $this->db->bind(':tc', $credits, PDO::PARAM_INT);
                $this->db->bind(':as', $acadStatus);
                $this->db->bind(':adv', $advisorId);
                $this->db->execute();
                $studentId = $this->db->lastInsertId();
            }

            $this->db->commit();
            if ($userId) RbacService::clearCache($userId);
            return [
                'success' => true,
                'student_id' => $studentId,
                'message' => $id > 0 ? 'Student academic record updated.' : 'Student record created.'
            ];
        } catch (Throwable $e) {
            $this->db->rollBack();
            return ['success' => false, 'message' => 'Failed to save student record: ' . $e->getMessage()];
        }
    }

    public function mapStudentAccount(int $studentId, ?int $userId): array {
        if ($studentId <= 0 || !$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Invalid student ID.'];
        }
        if ($userId !== null && $userId > 0) {
            $this->db->prepare("SELECT id FROM tbl_students WHERE user_id = :uid AND id != :sid AND deleted_at IS NULL LIMIT 1");
            $this->db->bind(':uid', $userId, PDO::PARAM_INT);
            $this->db->bind(':sid', $studentId, PDO::PARAM_INT);
            if ($this->db->single()) {
                return ['success' => false, 'message' => 'This user account is already mapped to another student record.'];
            }
        } else {
            $userId = null;
        }
        $this->db->prepare("UPDATE tbl_students SET user_id = :uid WHERE id = :sid");
        $this->db->bind(':uid', $userId);
        $this->db->bind(':sid', $studentId, PDO::PARAM_INT);
        $ok = $this->db->execute();
        RbacService::clearCache();
        return ['success' => $ok, 'message' => $ok ? 'Student account mapping updated.' : 'Failed to update mapping.'];
    }

    public function resetStudentPassword(int $studentId, string $newPassword): array {
        $this->db->prepare("SELECT user_id FROM tbl_students WHERE id = :sid AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':sid', $studentId, PDO::PARAM_INT);
        $row = $this->db->single();
        if (!$row || empty($row['user_id'])) {
            return ['success' => false, 'message' => 'Student record is not mapped to a login account.'];
        }
        $userSvc = new UserManagementService($this->db);
        return $userSvc->resetUserPassword((int)$row['user_id'], $newPassword);
    }

    public function importStudentsFromSpreadsheet(string $tmpPath, string $origName, string $actor = 'admin'): array {
        $parsed = DocumentParser::parseSpreadsheetRows($tmpPath, $origName);
        if (empty($parsed['success'])) {
            return $parsed;
        }
        $rows = $parsed['rows'];
        if (count($rows) < 2) {
            return ['success' => false, 'message' => 'Spreadsheet must contain a header row and at least one student row.'];
        }

        $header = array_map(static fn($h) => strtolower(preg_replace('/[^a-z0-9_]/', '_', trim((string)$h))), $rows[0]);
        $colIdx = array_flip($header);
        $imported = 0;
        $skipped = 0;
        $errors = [];

        for ($i = 1; $i < count($rows); $i++) {
            $r = $rows[$i];
            $nim = $r[$colIdx['nim'] ?? 0] ?? '';
            $fullName = $r[$colIdx['full_name'] ?? 1] ?? '';
            $progCode = strtoupper($r[$colIdx['program_code'] ?? ($colIdx['study_program'] ?? 2)] ?? 'IF');
            $cohort = (int)($r[$colIdx['cohort_year'] ?? 3] ?? date('Y'));
            $sem = (int)($r[$colIdx['semester'] ?? ($colIdx['current_semester'] ?? 4)] ?? 1);
            $gpa = (float)($r[$colIdx['gpa'] ?? 5] ?? 3.0);
            $credits = (int)($r[$colIdx['total_credits'] ?? 6] ?? 0);
            $email = $r[$colIdx['email'] ?? 7] ?? ($nim . '@student.unklab.ac.id');

            $res = $this->saveStudent([
                'nim' => $nim,
                'full_name' => $fullName,
                'program_code' => $progCode,
                'cohort_year' => $cohort,
                'current_semester' => $sem,
                'gpa' => $gpa,
                'total_credits' => $credits,
                'academic_status' => 'active',
                'create_login' => true,
                'login_username' => strtolower($nim),
                'login_email' => $email,
                'login_password' => 'Student123!'
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
            'message' => "Imported {$imported} student(s); skipped {$skipped}."
        ];
    }
}
