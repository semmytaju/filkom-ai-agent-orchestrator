<?php

class ToolRegistryService {
    private Database $db;
    private FacultyDataService $facultyData;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
        $this->facultyData = new FacultyDataService($this->db);
    }

    public function listAllTools(): array {
        if (!$this->db->isConnected()) return [];
        $st = $this->db->runPrepared("SELECT * FROM tbl_agent_tools ORDER BY tool_category ASC, call_count DESC, id ASC");
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function getToolsForAgent(int $agentId): array {
        if ($agentId <= 0 || !$this->db->isConnected()) return [];
        $st = $this->db->runPrepared(
            "SELECT t.*
             FROM tbl_agent_tool_permissions atp
             INNER JOIN tbl_agent_tools t ON t.id = atp.tool_id
             WHERE atp.agent_id = ? AND t.is_active = 1
             ORDER BY t.id ASC",
            [$agentId]
        );
        return $st ? $st->fetchAll(PDO::FETCH_ASSOC) : [];
    }

    public function toggleTool(int $toolId, int $isActive): array {
        if ($toolId <= 0 || !$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Invalid tool ID.'];
        }
        $this->db->prepare("UPDATE tbl_agent_tools SET is_active = :ia WHERE id = :id");
        $this->db->bind(':ia', $isActive ? 1 : 0, PDO::PARAM_INT);
        $this->db->bind(':id', $toolId, PDO::PARAM_INT);
        $ok = $this->db->execute();
        return ['success' => $ok, 'message' => $ok ? 'Tool status updated.' : 'Update failed.'];
    }

    public function buildOpenAiToolSchemas(array $authorizedTools): array {
        $schemas = [];
        foreach ($authorizedTools as $tool) {
            $code = (string)$tool['tool_code'];
            $props = ['query' => ['type' => 'string', 'description' => 'Optional filter or search phrase']];
            if ($code === 'get_student_profile' || $code === 'get_student_academic_progress') {
                $props['nim_or_name'] = ['type' => 'string', 'description' => 'Optional NIM or student name filter within user scope'];
            } elseif ($code === 'get_program_statistics') {
                $props['program_code'] = ['type' => 'string', 'description' => 'Optional study program code (IF, SI, TI, MSI)'];
            }
            $schemas[] = [
                'type' => 'function',
                'name' => $code,
                'description' => (string)$tool['description'],
                'parameters' => [
                    'type' => 'object',
                    'properties' => (object)$props,
                    'required' => []
                ]
            ];
        }
        return $schemas;
    }

    public function executeTool(string $toolCode, array $args, array $userContext, array $agentConfig): array {
        $tStart = microtime(true);
        $agentId = (int)($agentConfig['id'] ?? 0);
        $userId = (int)($userContext['user_id'] ?? 0);
        $scope = $userContext['scope'] ?? [];

        if ($agentId <= 0 || $userId <= 0) {
            return ['ok' => false, 'error' => 'Unauthenticated tool execution denied.'];
        }

        $st = $this->db->runPrepared(
            "SELECT t.*
             FROM tbl_agent_tools t
             INNER JOIN tbl_agent_tool_permissions atp ON atp.tool_id = t.id
             WHERE t.tool_code = ? AND atp.agent_id = ? AND t.is_active = 1
             LIMIT 1",
            [$toolCode, $agentId]
        );
        $tool = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
        if (!$tool) {
            return ['ok' => false, 'error' => "Tool '{$toolCode}' is not permitted for agent #{$agentId}."];
        }

        $rbac = new RbacService($this->db);
        $reqPerm = (string)($tool['required_permission'] ?? 'agents.use');
        if ($reqPerm !== '' && !$rbac->userHasPermission($userId, $reqPerm)) {
            return ['ok' => false, 'error' => "User lacks permission '{$reqPerm}' for tool '{$toolCode}'."];
        }

        if ((int)($tool['is_destructive'] ?? 0) === 1 && empty($args['human_approved'])) {
            return ['ok' => false, 'error' => 'Destructive operations require explicit human approval.'];
        }

        $scopeType = (string)($tool['scope_type'] ?? 'GLOBAL');
        if ($scopeType === 'UPMF_SCOPED' && empty($scope['all_faculty']) && empty($scope['is_upmf'])) {
            return ['ok' => false, 'error' => 'Access restricted to Quality Assurance (UPMF) or Faculty Leadership.'];
        }
        if ($scopeType === 'RESEARCH_SCOPED' && empty($scope['all_faculty']) && empty($scope['is_research_head'])) {
            return ['ok' => false, 'error' => 'Access restricted to Research Chair or Faculty Leadership.'];
        }
        if ($scopeType === 'ADMIN_SCOPED' && empty($scope['all_faculty']) && empty($scope['is_secretary'])) {
            return ['ok' => false, 'error' => 'Access restricted to Faculty Secretariat or Faculty Leadership.'];
        }

        $handler = (string)$tool['handler_method'];
        $result = $this->dispatchHandler($handler, $args, $userContext, $agentConfig);

        $this->db->runPrepared(
            "UPDATE tbl_agent_tools SET call_count = call_count + 1, last_called_at = NOW() WHERE id = ?",
            [(int)$tool['id']]
        );

        return [
            'ok' => true,
            'tool_code' => $toolCode,
            'duration_ms' => (int)round((microtime(true) - $tStart) * 1000),
            'data' => $result
        ];
    }

    private function dispatchHandler(string $handler, array $args, array $userContext, array $agentConfig): array {
        $scope = $userContext['scope'] ?? [];
        $queryFilter = trim((string)($args['query'] ?? ($args['nim_or_name'] ?? '')));

        switch ($handler) {
            case 'toolGetStudentProfile':
            case 'toolGetStudentAcademicProgress':
                $rows = $this->facultyData->getStudentAcademicDetails($scope, $queryFilter !== '' ? $queryFilter : null);
                return ['students' => $rows, 'count' => count($rows), 'scope_applied' => $this->describeScope($scope)];

            case 'toolGetCourseSchedule':
                $rows = $this->facultyData->getCoursesAndSchedules($scope);
                return ['schedules' => $rows, 'count' => count($rows)];

            case 'toolGetCourseEnrollment':
                $enrollments = $this->facultyData->getStudentEnrollmentsAndAttendance($scope);
                $courses = $this->facultyData->getCoursesAndSchedules($scope);
                return ['enrollments' => $enrollments, 'courses' => $courses];

            case 'toolGetAttendanceSummary':
                $rows = $this->facultyData->getStudentEnrollmentsAndAttendance($scope);
                return ['attendance' => $rows, 'minimum_required_pct' => 80.0];

            case 'toolGetThesisStatus':
                $rows = $this->facultyData->getTheses($scope);
                return ['theses' => $rows, 'count' => count($rows)];

            case 'toolGetLecturerInformation':
                $rows = $this->facultyData->getLecturers($scope);
                return ['lecturers' => $rows, 'count' => count($rows)];

            case 'toolGetFacultyStatistics':
                return $this->facultyData->getFacultyStatistics($scope, null);

            case 'toolGetProgramStatistics':
                $progId = null;
                if (!empty($args['program_code'])) {
                    $this->db->prepare("SELECT id FROM tbl_study_programs WHERE program_code = :pc LIMIT 1");
                    $this->db->bind(':pc', strtoupper(trim((string)$args['program_code'])));
                    $r = $this->db->single();
                    if ($r) $progId = (int)$r['id'];
                }
                return $this->facultyData->getFacultyStatistics($scope, $progId);

            case 'toolGetFacultyDocuments':
            case 'toolGetMeetingSchedule':
                return $this->facultyData->getAdministrationRecords();

            case 'toolGetResearchProjects':
            case 'toolGetPublicationStatistics':
            case 'toolGetResearchPerformance':
                return $this->facultyData->getResearchAndPublications($scope);

            case 'toolGetAccreditationIndicators':
            case 'toolGetQualityEvaluation':
            case 'toolGetEvidenceDocuments':
                return $this->facultyData->getQualityAndAccreditation($scope);

            case 'toolSearchDocuments':
                $rag = new RagService($this->db);
                $chunks = $rag->retrieveForAgent(
                    $queryFilter !== '' ? $queryFilter : 'pedoman filkom',
                    (int)($agentConfig['id'] ?? 0),
                    $userContext['role_ids'] ?? [],
                    4
                );
                return ['documents' => $chunks, 'count' => count($chunks)];

            case 'toolGenerateReport':
                return [
                    'report_generated_at' => date('Y-m-d H:i:s'),
                    'scope' => $this->describeScope($scope),
                    'summary' => $this->facultyData->getFacultyStatistics($scope, null)
                ];

            case 'toolGetDateTimeInfo':
                $sem = Controller::get_active_academic_semester();
                return [
                    'server_datetime' => date('Y-m-d H:i:s T'),
                    'active_semester' => $sem['semester_label'],
                    'academic_year' => $sem['academic_year']
                ];

            default:
                return ['note' => 'Tool executed', 'scope' => $this->describeScope($scope)];
        }
    }

    private function describeScope(array $scope): string {
        if (!empty($scope['all_faculty'])) return 'ALL_FACULTY';
        if (!empty($scope['is_kaprodi'])) return 'STUDY_PROGRAM:' . implode(',', $scope['program_codes'] ?? []);
        if (!empty($scope['is_student'])) return 'OWN_STUDENT:' . (string)($scope['student_id'] ?? 'NONE');
        if (!empty($scope['is_upmf'])) return 'QUALITY_ASSURANCE';
        if (!empty($scope['is_research_head'])) return 'RESEARCH';
        if (!empty($scope['is_secretary'])) return 'ADMINISTRATION';
        return 'RESTRICTED';
    }
}
