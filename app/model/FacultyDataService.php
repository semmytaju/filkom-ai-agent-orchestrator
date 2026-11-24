<?php

class FacultyDataService {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    private function applyProgramFilter(array $scope, string $colAlias, array &$where, array &$binds, ?int $requestedProgId = null): void {
        if (!empty($scope['all_faculty'])) {
            if ($requestedProgId !== null && $requestedProgId > 0) {
                $where[] = "{$colAlias} = ?";
                $binds[] = $requestedProgId;
            }
            return;
        }
        $allowed = !empty($scope['program_ids']) ? array_map('intval', $scope['program_ids']) : [];
        if ($requestedProgId !== null && $requestedProgId > 0) {
            if (in_array($requestedProgId, $allowed, true)) {
                $where[] = "{$colAlias} = ?";
                $binds[] = $requestedProgId;
            } else {
                $where[] = "1 = 0";
            }
            return;
        }
        if (empty($allowed)) {
            $where[] = "1 = 0";
            return;
        }
        $ph = implode(',', array_fill(0, count($allowed), '?'));
        $where[] = "{$colAlias} IN ({$ph})";
        $binds = array_merge($binds, $allowed);
    }

    public function getStudyPrograms(): array {
        if (!$this->db->isConnected()) return [];
        $st = $this->db->runPrepared(
            "SELECT sp.id, sp.program_code, sp.program_name, sp.degree_level, sp.accreditation_grade, sp.is_active,
                    COUNT(DISTINCT CASE WHEN s.deleted_at IS NULL AND s.academic_status = 'active' THEN s.id END) AS active_students,
                    COUNT(DISTINCT l.id) AS total_lecturers
             FROM tbl_study_programs sp
             LEFT JOIN tbl_students s ON s.study_program_id = sp.id
             LEFT JOIN tbl_lecturers l ON l.study_program_id = sp.id AND l.status = 'active'
             GROUP BY sp.id
             ORDER BY sp.id ASC"
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function saveStudyProgram(array $data): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $id = (int)($data['id'] ?? 0);
        $code = strtoupper(preg_replace('/[^A-Z0-9_]/', '', (string)($data['program_code'] ?? '')));
        $name = trim((string)($data['program_name'] ?? ''));
        $degree = in_array(($data['degree_level'] ?? 'S1'), ['D3', 'S1', 'S2', 'S3'], true) ? $data['degree_level'] : 'S1';
        $accred = trim((string)($data['accreditation_grade'] ?? 'Baik Sekali'));
        $active = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if (strlen($code) < 2 || strlen($name) < 3) {
            return ['success' => false, 'message' => 'Program code and name are required.'];
        }

        if ($id > 0) {
            $this->db->prepare("UPDATE tbl_study_programs SET program_code = :pc, program_name = :pn, degree_level = :dl, accreditation_grade = :ag, is_active = :ia WHERE id = :id");
            $this->db->bind(':id', $id, PDO::PARAM_INT);
        } else {
            $this->db->prepare("INSERT INTO tbl_study_programs (program_code, program_name, degree_level, accreditation_grade, is_active) VALUES (:pc, :pn, :dl, :ag, :ia)");
        }
        $this->db->bind(':pc', $code);
        $this->db->bind(':pn', $name);
        $this->db->bind(':dl', $degree);
        $this->db->bind(':ag', $accred);
        $this->db->bind(':ia', $active, PDO::PARAM_INT);
        $ok = $this->db->execute();
        return ['success' => $ok, 'message' => $ok ? 'Study program saved.' : 'Failed to save study program.'];
    }

    public function getFacultyStatistics(array $scope, ?int $requestedProgId = null): array {
        if (!$this->db->isConnected()) return [];
        $whereStu = ['s.deleted_at IS NULL'];
        $bindsStu = [];
        $this->applyProgramFilter($scope, 's.study_program_id', $whereStu, $bindsStu, $requestedProgId);
        $wStuSql = implode(' AND ', $whereStu);

        $stStu = $this->db->runPrepared(
            "SELECT sp.program_code, sp.program_name,
                    COUNT(s.id) AS total_students,
                    SUM(CASE WHEN s.academic_status = 'active' THEN 1 ELSE 0 END) AS active_students,
                    SUM(CASE WHEN s.academic_status = 'probation' THEN 1 ELSE 0 END) AS probation_students,
                    ROUND(AVG(s.gpa), 2) AS avg_gpa,
                    ROUND(AVG(s.total_credits), 1) AS avg_credits
             FROM tbl_students s
             INNER JOIN tbl_study_programs sp ON sp.id = s.study_program_id
             WHERE {$wStuSql}
             GROUP BY sp.id, sp.program_code, sp.program_name
             ORDER BY sp.id ASC",
            $bindsStu
        );
        $byProgram = $stStu ? $stStu->fetchAll(PDO::FETCH_ASSOC) : [];

        $whereLect = ['1=1'];
        $bindsLect = [];
        $this->applyProgramFilter($scope, 'l.study_program_id', $whereLect, $bindsLect, $requestedProgId);
        $stLect = $this->db->runPrepared(
            "SELECT COUNT(*) AS total_lecturers, ROUND(AVG(l.teaching_score), 2) AS avg_teaching_score, ROUND(AVG(l.research_score), 2) AS avg_research_score
             FROM tbl_lecturers l WHERE " . implode(' AND ', $whereLect),
            $bindsLect
        );
        $lectAgg = $stLect ? ($stLect->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        $whereTh = ['1=1'];
        $bindsTh = [];
        $this->applyProgramFilter($scope, 't.study_program_id', $whereTh, $bindsTh, $requestedProgId);
        $stTh = $this->db->runPrepared(
            "SELECT COUNT(*) AS total_theses,
                    SUM(CASE WHEN t.status IN ('at_risk','delayed') THEN 1 ELSE 0 END) AS risk_theses,
                    SUM(CASE WHEN t.stage = 'defense_ready' OR t.status = 'completed' THEN 1 ELSE 0 END) AS ready_theses,
                    ROUND(AVG(t.progress_pct), 1) AS avg_progress_pct
             FROM tbl_theses t WHERE " . implode(' AND ', $whereTh),
            $bindsTh
        );
        $thAgg = $stTh ? ($stTh->fetch(PDO::FETCH_ASSOC) ?: []) : [];

        return [
            'programs' => $byProgram,
            'lecturers' => $lectAgg,
            'theses' => $thAgg
        ];
    }

    public function getStudentAcademicDetails(array $scope, ?string $nimFilter = null, ?int $requestedProgId = null): array {
        if (!$this->db->isConnected()) return [];
        $where = ['s.deleted_at IS NULL'];
        $binds = [];

        if (!empty($scope['is_student']) && empty($scope['all_faculty']) && empty($scope['is_kaprodi'])) {
            if (empty($scope['student_id'])) {
                return [];
            }
            $where[] = 's.id = ?';
            $binds[] = (int)$scope['student_id'];
        } else {
            $this->applyProgramFilter($scope, 's.study_program_id', $where, $binds, $requestedProgId);
            if ($nimFilter !== null && trim($nimFilter) !== '') {
                $where[] = '(s.nim = ? OR s.full_name LIKE ?)';
                $binds[] = trim($nimFilter);
                $binds[] = '%' . trim($nimFilter) . '%';
            }
        }

        $st = $this->db->runPrepared(
            "SELECT s.id, s.nim, s.full_name, s.cohort_year, s.current_semester, s.gpa, s.total_credits, s.academic_status,
                    sp.program_code, sp.program_name, l.full_name AS advisor_name
             FROM tbl_students s
             INNER JOIN tbl_study_programs sp ON sp.id = s.study_program_id
             LEFT JOIN tbl_lecturers l ON l.id = s.advisor_lecturer_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.gpa ASC, s.nim ASC
             LIMIT 30",
            $binds
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getStudentEnrollmentsAndAttendance(array $scope): array {
        if (!$this->db->isConnected()) return [];
        $where = ['s.deleted_at IS NULL'];
        $binds = [];
        if (!empty($scope['is_student']) && empty($scope['all_faculty']) && empty($scope['is_kaprodi'])) {
            if (empty($scope['student_id'])) return [];
            $where[] = 's.id = ?';
            $binds[] = (int)$scope['student_id'];
        } else {
            $this->applyProgramFilter($scope, 's.study_program_id', $where, $binds, null);
        }

        $st = $this->db->runPrepared(
            "SELECT s.nim, s.full_name AS student_name, sp.program_code,
                    c.course_code, c.course_name, c.credits,
                    se.semester_label, se.grade_letter, se.grade_points, se.status AS enrollment_status,
                    COALESCE(ar.attended_sessions, 0) AS attended_sessions,
                    COALESCE(ar.total_sessions, 16) AS total_sessions,
                    COALESCE(ar.attendance_pct, 0) AS attendance_pct
             FROM tbl_student_enrollments se
             INNER JOIN tbl_students s ON s.id = se.student_id
             INNER JOIN tbl_study_programs sp ON sp.id = s.study_program_id
             INNER JOIN tbl_courses c ON c.id = se.course_id
             LEFT JOIN tbl_attendance_records ar ON ar.enrollment_id = se.id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY s.nim ASC, c.course_code ASC
             LIMIT 50",
            $binds
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getCoursesAndSchedules(array $scope, ?int $requestedProgId = null): array {
        if (!$this->db->isConnected()) return [];
        $where = ['1=1'];
        $binds = [];
        $this->applyProgramFilter($scope, 'c.study_program_id', $where, $binds, $requestedProgId);
        $st = $this->db->runPrepared(
            "SELECT c.id, c.study_program_id, c.course_code, c.course_name, c.credits, c.semester_recommended, c.curriculum_year,
                    sp.program_code, sp.program_name,
                    cs.day_of_week, cs.start_time, cs.end_time, cs.room, cs.room AS room_code, cs.semester_label,
                    l.full_name AS lecturer_name
             FROM tbl_courses c
             INNER JOIN tbl_study_programs sp ON sp.id = c.study_program_id
             LEFT JOIN tbl_course_schedules cs ON cs.course_id = c.id
             LEFT JOIN tbl_lecturers l ON l.id = cs.lecturer_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY sp.id ASC, c.semester_recommended ASC, c.course_code ASC
             LIMIT 100",
            $binds
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getTheses(array $scope, ?int $requestedProgId = null): array {
        if (!$this->db->isConnected()) return [];
        $where = ['1=1'];
        $binds = [];
        if (!empty($scope['is_student']) && empty($scope['all_faculty']) && empty($scope['is_kaprodi'])) {
            if (empty($scope['student_id'])) return [];
            $where[] = 't.student_id = ?';
            $binds[] = (int)$scope['student_id'];
        } else {
            $this->applyProgramFilter($scope, 't.study_program_id', $where, $binds, $requestedProgId);
        }
        $st = $this->db->runPrepared(
            "SELECT t.id, t.title, t.stage, t.progress_pct, t.similarity_pct, t.status, t.updated_at,
                    s.nim, s.full_name AS student_name, sp.program_code, l.full_name AS supervisor_name
             FROM tbl_theses t
             INNER JOIN tbl_students s ON s.id = t.student_id
             INNER JOIN tbl_study_programs sp ON sp.id = t.study_program_id
             LEFT JOIN tbl_lecturers l ON l.id = t.supervisor_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY FIELD(t.status,'delayed','at_risk','on_track','completed'), t.progress_pct ASC
             LIMIT 40",
            $binds
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getLecturers(array $scope, ?int $requestedProgId = null): array {
        if (!$this->db->isConnected()) return [];
        $where = ['1=1'];
        $binds = [];
        if ($requestedProgId !== null && $requestedProgId > 0) {
            $this->applyProgramFilter($scope, 'l.study_program_id', $where, $binds, $requestedProgId);
        } elseif (empty($scope['all_faculty']) && empty($scope['is_research_head']) && empty($scope['is_upmf'])) {
            $this->applyProgramFilter($scope, 'l.study_program_id', $where, $binds, null);
        }
        $st = $this->db->runPrepared(
            "SELECT l.id, l.nidn, l.full_name, l.academic_rank, l.expertise, l.teaching_score, l.research_score, l.status,
                    sp.program_code, sp.program_name,
                    COUNT(DISTINCT p.id) AS publication_count,
                    COALESCE(SUM(p.citations), 0) AS total_citations
             FROM tbl_lecturers l
             INNER JOIN tbl_study_programs sp ON sp.id = l.study_program_id
             LEFT JOIN tbl_publications p ON p.lecturer_id = l.id
             WHERE " . implode(' AND ', $where) . "
             GROUP BY l.id
             ORDER BY l.teaching_score DESC",
            $binds
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getResearchAndPublications(array $scope, ?int $requestedProgId = null): array {
        if (!$this->db->isConnected()) return ['projects' => [], 'publications' => [], 'summary' => []];
        $where = ['1=1'];
        $binds = [];
        if ($requestedProgId !== null && $requestedProgId > 0) {
            $this->applyProgramFilter($scope, 'rp.study_program_id', $where, $binds, $requestedProgId);
        } elseif (empty($scope['all_faculty']) && empty($scope['is_research_head'])) {
            $this->applyProgramFilter($scope, 'rp.study_program_id', $where, $binds, null);
        }
        $stProj = $this->db->runPrepared(
            "SELECT rp.id, rp.project_code, rp.title, rp.funding_source, rp.funding_amount, rp.year, rp.status,
                    l.full_name AS lead_name, sp.program_code
             FROM tbl_research_projects rp
             INNER JOIN tbl_lecturers l ON l.id = rp.lead_lecturer_id
             INNER JOIN tbl_study_programs sp ON sp.id = rp.study_program_id
             WHERE " . implode(' AND ', $where) . "
             ORDER BY rp.year DESC, rp.funding_amount DESC
             LIMIT 40",
            $binds
        );
        $projects = $stProj ? $stProj->fetchAll(PDO::FETCH_ASSOC) : [];

        $wherePub = ['1=1'];
        $bindsPub = [];
        if ($requestedProgId !== null && $requestedProgId > 0) {
            $this->applyProgramFilter($scope, 'l.study_program_id', $wherePub, $bindsPub, $requestedProgId);
        }
        $stPub = $this->db->runPrepared(
            "SELECT p.id, p.title, p.venue, p.index_tier, p.year, p.citations,
                    l.full_name AS author_name, sp.program_code
             FROM tbl_publications p
             INNER JOIN tbl_lecturers l ON l.id = p.lecturer_id
             INNER JOIN tbl_study_programs sp ON sp.id = l.study_program_id
             WHERE " . implode(' AND ', $wherePub) . "
             ORDER BY p.year DESC, p.citations DESC
             LIMIT 40",
            $bindsPub
        );
        $pubs = $stPub ? $stPub->fetchAll(PDO::FETCH_ASSOC) : [];

        $totalFunding = 0;
        foreach ($projects as $pr) {
            $totalFunding += (int)$pr['funding_amount'];
        }
        $totalCitations = 0;
        foreach ($pubs as $pb) {
            $totalCitations += (int)$pb['citations'];
        }

        return [
            'projects' => $projects,
            'publications' => $pubs,
            'summary' => [
                'project_count' => count($projects),
                'total_funding_idr' => $totalFunding,
                'publication_count' => count($pubs),
                'total_citations' => $totalCitations
            ]
        ];
    }

    public function getQualityAndAccreditation(array $scope, ?int $requestedProgId = null): array {
        if (!$this->db->isConnected()) return ['indicators' => [], 'evaluations' => []];
        $whereInd = ['1=1'];
        $bindsInd = [];
        if ($requestedProgId !== null && $requestedProgId > 0) {
            $this->applyProgramFilter($scope, 'ai.study_program_id', $whereInd, $bindsInd, $requestedProgId);
        } elseif (empty($scope['all_faculty']) && empty($scope['is_upmf'])) {
            $this->applyProgramFilter($scope, 'ai.study_program_id', $whereInd, $bindsInd, null);
        }
        $stInd = $this->db->runPrepared(
            "SELECT ai.id, ai.indicator_code, ai.standard_name, ai.target_value, ai.actual_value, ai.compliance_status,
                    sp.program_code, sp.program_name
             FROM tbl_accreditation_indicators ai
             LEFT JOIN tbl_study_programs sp ON sp.id = ai.study_program_id
             WHERE " . implode(' AND ', $whereInd) . "
             ORDER BY FIELD(ai.compliance_status,'critical','watch','met'), ai.indicator_code ASC",
            $bindsInd
        );

        $stEval = $this->db->runPrepared(
            "SELECT qe.id, qe.audit_code, qe.cycle_label, qe.score, qe.findings, qe.status, qe.created_at,
                    sp.program_code, sp.program_name, u.full_name AS evaluator_name
             FROM tbl_quality_evaluations qe
             LEFT JOIN tbl_study_programs sp ON sp.id = qe.study_program_id
             LEFT JOIN tbl_users u ON u.id = qe.evaluator_user_id
             ORDER BY qe.id DESC
             LIMIT 30"
        );

        return [
            'indicators' => $stInd ? $stInd->fetchAll(PDO::FETCH_ASSOC) : [],
            'evaluations' => $stEval ? $stEval->fetchAll(PDO::FETCH_ASSOC) : []
        ];
    }

    public function getAdministrationRecords(): array {
        if (!$this->db->isConnected()) return ['meetings' => [], 'correspondence' => [], 'documents' => []];
        $stMeet = $this->db->runPrepared("SELECT id, title, meeting_date, location, chairperson, agenda_summary, status FROM tbl_meetings ORDER BY meeting_date DESC LIMIT 20");
        $stCorr = $this->db->runPrepared("SELECT id, ref_number, direction, subject, counterparty, letter_date, status FROM tbl_correspondence ORDER BY letter_date DESC LIMIT 20");
        $stDocs = $this->db->runPrepared(
            "SELECT fd.id, fd.doc_number, fd.category, fd.title, fd.summary, fd.created_at, sp.program_code
             FROM tbl_faculty_documents fd
             LEFT JOIN tbl_study_programs sp ON sp.id = fd.study_program_id
             ORDER BY fd.id DESC LIMIT 20"
        );
        return [
            'meetings' => $stMeet ? $stMeet->fetchAll(PDO::FETCH_ASSOC) : [],
            'correspondence' => $stCorr ? $stCorr->fetchAll(PDO::FETCH_ASSOC) : [],
            'documents' => $stDocs ? $stDocs->fetchAll(PDO::FETCH_ASSOC) : []
        ];
    }

    private function stripAcademicTitles(string $name): string {
        $clean = trim($name);
        $clean = preg_replace('/^(?:(?:Prof|Dr|Ir|Drs|Dra|Ns|Apt|H|Hj)\.?\s+)+/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/,\s*(?:S\.[A-Za-z\.]+|M\.[A-Za-z\.]+|Ph\.?D\.?|D\.?Sc\.?|B\.?Sc\.?|M\.?Sc\.?|MIT|MBA|MM|MT|MCs|MKom|SKom|SE|SH|SSi|ST|IPU|IPM|ASEAN\s+Eng\.?).*$/iu', '', $clean) ?? $clean;
        $clean = preg_replace('/\s{2,}/', ' ', trim($clean, " \t\n\r\0\x0B,")) ?? $clean;
        return $clean;
    }

    private function isProgramAuthorized(array $scope, int $progId): bool {
        if (!empty($scope['all_faculty'])) return true;
        $allowed = !empty($scope['program_ids']) ? array_map('intval', $scope['program_ids']) : [];
        return in_array($progId, $allowed, true);
    }

    public function saveCourseAndSchedule(array $data, array $scope): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $courseCode = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', (string)($data['course_code'] ?? '')));
        $courseName = trim((string)($data['course_name'] ?? ''));
        $progId = (int)($data['study_program_id'] ?? 0);
        $credits = max(1, min(6, (int)($data['credits'] ?? 3)));
        $semesterRec = max(1, min(8, (int)($data['semester_recommended'] ?? 3)));
        $currYear = max(2018, min(2035, (int)($data['curriculum_year'] ?? (int)date('Y'))));
        $lecturerId = (int)($data['lecturer_id'] ?? 0);
        $semesterLabel = trim((string)($data['semester_label'] ?? '2026_2027_1'));
        $dayOfWeek = trim((string)($data['day_of_week'] ?? 'Monday'));
        $startTime = trim((string)($data['start_time'] ?? '08:00'));
        $endTime = trim((string)($data['end_time'] ?? '10:30'));
        $room = trim((string)($data['room'] ?? ''));
        $capacity = max(10, min(200, (int)($data['capacity'] ?? 40)));

        if (strlen($courseCode) < 2 || strlen($courseName) < 3 || $progId <= 0) {
            return ['success' => false, 'message' => 'Course code, course name, and study program are required.'];
        }
        if (!$this->isProgramAuthorized($scope, $progId)) {
            return ['success' => false, 'message' => 'You are not authorized to modify courses for this study program.'];
        }
        if (!in_array($dayOfWeek, ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'], true)) {
            $dayOfWeek = 'Monday';
        }
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime) === 5) $endTime .= ':00';
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $startTime)) $startTime = '08:00:00';
        if (!preg_match('/^\d{2}:\d{2}:\d{2}$/', $endTime)) $endTime = '10:30:00';

        $stCheck = $this->db->runPrepared("SELECT id, study_program_id FROM tbl_courses WHERE course_code = ? LIMIT 1", [$courseCode]);
        $existing = $stCheck ? $stCheck->fetch(PDO::FETCH_ASSOC) : null;
        if ($existing) {
            if (!$this->isProgramAuthorized($scope, (int)$existing['study_program_id'])) {
                return ['success' => false, 'message' => 'Course code already belongs to another study program.'];
            }
            $courseId = (int)$existing['id'];
            $this->db->runPrepared(
                "UPDATE tbl_courses SET study_program_id = ?, course_name = ?, credits = ?, semester_recommended = ?, curriculum_year = ? WHERE id = ?",
                [$progId, $courseName, $credits, $semesterRec, $currYear, $courseId]
            );
        } else {
            $ok = $this->db->runPrepared(
                "INSERT INTO tbl_courses (course_code, study_program_id, course_name, credits, semester_recommended, curriculum_year, is_mandatory) VALUES (?, ?, ?, ?, ?, ?, 1)",
                [$courseCode, $progId, $courseName, $credits, $semesterRec, $currYear]
            );
            if (!$ok) return ['success' => false, 'message' => 'Failed to create course record.'];
            $courseId = (int)$this->db->lastInsertId();
        }

        if ($lecturerId > 0 && $room !== '') {
            $this->db->runPrepared(
                "INSERT INTO tbl_course_schedules (course_id, lecturer_id, semester_label, day_of_week, start_time, end_time, room, capacity) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                [$courseId, $lecturerId, $semesterLabel !== '' ? $semesterLabel : '2026_2027_1', $dayOfWeek, $startTime, $endTime, $room, $capacity]
            );
        }

        return ['success' => true, 'course_id' => $courseId, 'message' => "Course {$courseCode} & schedule saved."];
    }

    public function saveLecturer(array $data, array $scope): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $nidn = preg_replace('/[^A-Za-z0-9-]/', '', trim((string)($data['nidn'] ?? '')));
        $fullName = $this->stripAcademicTitles((string)($data['full_name'] ?? ''));
        $progId = (int)($data['study_program_id'] ?? 0);
        $rank = trim((string)($data['academic_rank'] ?? 'Lektor'));
        $expertise = trim((string)($data['expertise'] ?? 'Computer Science'));
        $teachScore = max(0.0, min(5.0, (float)($data['teaching_score'] ?? 4.50)));
        $resScore = max(0.0, min(5.0, (float)($data['research_score'] ?? 4.20)));
        $rawStatus = (string)($data['status'] ?? 'active');
        $status = in_array($rawStatus, ['active', 'study_leave', 'inactive'], true) ? $rawStatus : 'active';

        if (strlen($nidn) < 4 || strlen($fullName) < 3 || $progId <= 0) {
            return ['success' => false, 'message' => 'NIDN, full name, and study program are required.'];
        }
        if (!$this->isProgramAuthorized($scope, $progId)) {
            return ['success' => false, 'message' => 'You are not authorized to add lecturers to this study program.'];
        }

        $stCheck = $this->db->runPrepared("SELECT id FROM tbl_lecturers WHERE nidn = ? LIMIT 1", [$nidn]);
        $existing = $stCheck ? $stCheck->fetch(PDO::FETCH_ASSOC) : null;
        if ($existing) {
            $id = (int)$existing['id'];
            $ok = (bool)$this->db->runPrepared(
                "UPDATE tbl_lecturers SET study_program_id = ?, full_name = ?, academic_rank = ?, expertise = ?, teaching_score = ?, research_score = ?, status = ? WHERE id = ?",
                [$progId, $fullName, $rank, $expertise, $teachScore, $resScore, $status, $id]
            );
            return ['success' => $ok, 'lecturer_id' => $id, 'message' => $ok ? "Lecturer {$fullName} updated." : 'Failed to update lecturer.'];
        }

        $ok = (bool)$this->db->runPrepared(
            "INSERT INTO tbl_lecturers (nidn, study_program_id, full_name, academic_rank, expertise, teaching_score, research_score, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$nidn, $progId, $fullName, $rank, $expertise, $teachScore, $resScore, $status]
        );
        return ['success' => $ok, 'lecturer_id' => $ok ? (int)$this->db->lastInsertId() : 0, 'message' => $ok ? "Lecturer {$fullName} added." : 'Failed to add lecturer.'];
    }

    public function saveResearchProject(array $data, array $scope): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', (string)($data['project_code'] ?? '')));
        $title = trim((string)($data['title'] ?? ''));
        $leadId = (int)($data['lead_lecturer_id'] ?? 0);
        $progId = (int)($data['study_program_id'] ?? 0);
        $source = trim((string)($data['funding_source'] ?? 'Internal Grant'));
        $amount = max(0, (int)preg_replace('/[^0-9]/', '', (string)($data['funding_amount'] ?? '0')));
        $year = max(2015, min(2035, (int)($data['year'] ?? (int)date('Y'))));
        $rawStatus = (string)($data['status'] ?? 'active');
        $status = in_array($rawStatus, ['proposed', 'active', 'completed'], true) ? $rawStatus : 'active';

        if (strlen($code) < 3 || strlen($title) < 4 || $leadId <= 0 || $progId <= 0) {
            return ['success' => false, 'message' => 'Project code, title, lead lecturer, and study program are required.'];
        }
        if (empty($scope['all_faculty']) && empty($scope['is_research_head']) && !$this->isProgramAuthorized($scope, $progId)) {
            return ['success' => false, 'message' => 'Unauthorized study program for research project.'];
        }

        $stCheck = $this->db->runPrepared("SELECT id FROM tbl_research_projects WHERE project_code = ? LIMIT 1", [$code]);
        $existing = $stCheck ? $stCheck->fetch(PDO::FETCH_ASSOC) : null;
        if ($existing) {
            $id = (int)$existing['id'];
            $ok = (bool)$this->db->runPrepared(
                "UPDATE tbl_research_projects SET lead_lecturer_id = ?, study_program_id = ?, title = ?, funding_source = ?, funding_amount = ?, year = ?, status = ? WHERE id = ?",
                [$leadId, $progId, $title, $source, $amount, $year, $status, $id]
            );
            return ['success' => $ok, 'project_id' => $id, 'message' => $ok ? "Research project {$code} updated." : 'Failed to update project.'];
        }

        $ok = (bool)$this->db->runPrepared(
            "INSERT INTO tbl_research_projects (project_code, lead_lecturer_id, study_program_id, title, funding_source, funding_amount, year, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [$code, $leadId, $progId, $title, $source, $amount, $year, $status]
        );
        return ['success' => $ok, 'project_id' => $ok ? (int)$this->db->lastInsertId() : 0, 'message' => $ok ? "Research project {$code} added." : 'Failed to save research project.'];
    }

    public function savePublication(array $data, array $scope): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $lecturerId = (int)($data['lecturer_id'] ?? 0);
        $title = trim((string)($data['title'] ?? ''));
        $venue = trim((string)($data['venue'] ?? ''));
        $tier = (string)($data['index_tier'] ?? 'SINTA 2');
        $allowedTiers = ['Scopus Q1', 'Scopus Q2', 'Scopus Q3', 'SINTA 1', 'SINTA 2', 'IEEE Conference'];
        if (!in_array($tier, $allowedTiers, true)) $tier = 'SINTA 2';
        $year = max(2015, min(2035, (int)($data['year'] ?? (int)date('Y'))));
        $citations = max(0, min(100000, (int)($data['citations'] ?? 0)));

        if ($lecturerId <= 0 || strlen($title) < 4 || strlen($venue) < 2) {
            return ['success' => false, 'message' => 'Author lecturer, title, and publication venue are required.'];
        }

        $stL = $this->db->runPrepared("SELECT study_program_id FROM tbl_lecturers WHERE id = ? LIMIT 1", [$lecturerId]);
        $lect = $stL ? $stL->fetch(PDO::FETCH_ASSOC) : null;
        if (!$lect) {
            return ['success' => false, 'message' => 'Selected lecturer not found.'];
        }
        if (empty($scope['all_faculty']) && empty($scope['is_research_head']) && !$this->isProgramAuthorized($scope, (int)$lect['study_program_id'])) {
            return ['success' => false, 'message' => 'Unauthorized lecturer scope for publication.'];
        }

        $ok = (bool)$this->db->runPrepared(
            "INSERT INTO tbl_publications (lecturer_id, title, venue, index_tier, year, citations) VALUES (?, ?, ?, ?, ?, ?)",
            [$lecturerId, $title, $venue, $tier, $year, $citations]
        );
        return ['success' => $ok, 'publication_id' => $ok ? (int)$this->db->lastInsertId() : 0, 'message' => $ok ? 'Publication record added.' : 'Failed to add publication.'];
    }

    public function saveAccreditationIndicator(array $data): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/', '', (string)($data['indicator_code'] ?? '')));
        $progId = !empty($data['study_program_id']) ? (int)$data['study_program_id'] : null;
        $standard = trim((string)($data['standard_name'] ?? ''));
        $target = (float)($data['target_value'] ?? 0);
        $actual = (float)($data['actual_value'] ?? 0);
        $rawComp = (string)($data['compliance_status'] ?? 'met');
        $compliance = in_array($rawComp, ['met', 'watch', 'critical'], true) ? $rawComp : 'met';

        if (strlen($code) < 2 || strlen($standard) < 3) {
            return ['success' => false, 'message' => 'Indicator code and standard name are required.'];
        }

        if ($progId === null) {
            $stCheck = $this->db->runPrepared("SELECT id FROM tbl_accreditation_indicators WHERE indicator_code = ? AND study_program_id IS NULL LIMIT 1", [$code]);
        } else {
            $stCheck = $this->db->runPrepared("SELECT id FROM tbl_accreditation_indicators WHERE indicator_code = ? AND study_program_id = ? LIMIT 1", [$code, $progId]);
        }
        $existing = $stCheck ? $stCheck->fetch(PDO::FETCH_ASSOC) : null;
        if ($existing) {
            $id = (int)$existing['id'];
            $ok = (bool)$this->db->runPrepared(
                "UPDATE tbl_accreditation_indicators SET standard_name = ?, target_value = ?, actual_value = ?, compliance_status = ? WHERE id = ?",
                [$standard, $target, $actual, $compliance, $id]
            );
            return ['success' => $ok, 'indicator_id' => $id, 'message' => $ok ? "Indicator {$code} updated." : 'Failed to update indicator.'];
        }

        $ok = (bool)$this->db->runPrepared(
            "INSERT INTO tbl_accreditation_indicators (indicator_code, study_program_id, standard_name, target_value, actual_value, compliance_status) VALUES (?, ?, ?, ?, ?, ?)",
            [$code, $progId, $standard, $target, $actual, $compliance]
        );
        return ['success' => $ok, 'indicator_id' => $ok ? (int)$this->db->lastInsertId() : 0, 'message' => $ok ? "Indicator {$code} added." : 'Failed to save indicator.'];
    }

    public function saveMeeting(array $data): array {
        if (!$this->db->isConnected()) return ['success' => false, 'message' => 'Database unavailable.'];
        $title = trim((string)($data['title'] ?? ''));
        $rawDate = str_replace('T', ' ', trim((string)($data['meeting_date'] ?? '')));
        if (strlen($rawDate) === 16) $rawDate .= ':00';
        $location = trim((string)($data['location'] ?? 'FILKOM Meeting Room'));
        $chair = $this->stripAcademicTitles((string)($data['chairperson'] ?? ''));
        $agenda = trim((string)($data['agenda_summary'] ?? ''));
        $rawStatus = (string)($data['status'] ?? 'scheduled');
        $status = in_array($rawStatus, ['scheduled', 'completed', 'cancelled'], true) ? $rawStatus : 'scheduled';

        if (strlen($title) < 3 || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rawDate) || strlen($chair) < 2) {
            return ['success' => false, 'message' => 'Meeting title, valid date/time, and chairperson are required.'];
        }

        $ok = (bool)$this->db->runPrepared(
            "INSERT INTO tbl_meetings (title, meeting_date, location, chairperson, agenda_summary, status) VALUES (?, ?, ?, ?, ?, ?)",
            [$title, $rawDate, $location !== '' ? $location : 'FILKOM Meeting Room', $chair, $agenda !== '' ? $agenda : $title, $status]
        );
        return ['success' => $ok, 'meeting_id' => $ok ? (int)$this->db->lastInsertId() : 0, 'message' => $ok ? 'Faculty meeting scheduled.' : 'Failed to save meeting.'];
    }
}
