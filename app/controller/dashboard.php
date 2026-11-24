<?php

class dashboard extends Controller {

    public function index() {
        $this->require_authentication();
        $userId = (int)($_SESSION['user-id'] ?? 0);

        $rbac = $this->logic('RbacService');
        $userContext = $rbac->getUserContext($userId);
        if (!$userContext) {
            header('Location: ' . APP_PATH . '/logout');
            exit();
        }

        $walkerModel = $this->logic('Walker_model');
        $registry = $this->logic('AgentRegistryService');
        $monitor = $this->logic('MonitoringService');
        $facultyData = $this->logic('FacultyDataService');

        $currentUser = $userContext['user'];
        $currentUser['role'] = $userContext['primary_role_code'];
        $currentUser['roles'] = $userContext['role_codes'];
        $currentUser['zone_code'] = $userContext['zone_code'];
        $currentUser['program_codes'] = $userContext['program_codes'];
        $currentUser['student'] = $userContext['student'];

        $data = [
            'title' => APP_NAME . ' — ' . $userContext['primary_role_name'],
            'app_name' => APP_NAME,
            'app_version' => APP_VERSION,
            'current_user' => $currentUser,
            'user_context' => $userContext,
            'authorized_agents' => $registry->getAuthorizedAgentsForUser($userId),
            'monitoring' => $monitor->getOverview($userContext),
            'study_programs' => $facultyData->getStudyPrograms(),
            'roles_catalog' => $rbac->getAllRoles(),
            'analytics' => $walkerModel->getDashboardAnalytics(),
            'va_settings' => $walkerModel->getAssistantSettings(),
            'active_sessions' => $this->get_active_sessions_list(),
            'csrf_token' => $this->get_csrf_token()
        ];

        $this->display('dashboard/index', $data);
    }

    public function api($actionParam = '') {
        $this->require_authentication([], true);
        $userId = (int)($_SESSION['user-id'] ?? 0);
        $rbac = $this->logic('RbacService');
        $userContext = $rbac->getUserContext($userId);
        if (!$userContext) {
            $this->json_response(['success' => false, 'message' => 'Session expired.'], 401);
        }

        $walkerModel = $this->logic('Walker_model');
        $rawInput = file_get_contents('php://input');
        $jsonBody = [];
        if (!empty($rawInput)) {
            $decoded = json_decode($rawInput, true);
            if (is_array($decoded)) {
                if (Security::detect_rce_payload($decoded)) {
                    Security::log_incident('DASHBOARD_RCE_VECTOR', substr($rawInput, 0, 250), 'Blocked 400');
                    $this->json_response(['success' => false, 'message' => 'Payload rejected by RCE security filter.'], 400);
                }
                if (Security::detect_path_traversal($decoded)) {
                    Security::log_incident('DASHBOARD_TRAVERSAL_VECTOR', substr($rawInput, 0, 250), 'Blocked 400');
                    $this->json_response(['success' => false, 'message' => 'Payload rejected by path security filter.'], 400);
                }
                $jsonBody = $decoded;
            }
        }
        $payload = array_merge($_GET, $_POST, $jsonBody);
        $action = strtolower(trim((string)($actionParam ?: ($payload['api_action'] ?? ($payload['action'] ?? 'analytics')))));
        $actor = (string)$userContext['username'];
        $isAdmin = !empty($userContext['scope']['is_admin']);
        $scope = $userContext['scope'];

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $csrf = $payload['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
            if (!$this->verify_csrf_token($csrf)) {
                $this->json_response(['success' => false, 'message' => 'Invalid CSRF security token.'], 403);
            }
        }

        try {
            switch ($action) {
                case 'analytics':
                    $monitor = $this->logic('MonitoringService');
                    $this->json_response([
                        'success' => true,
                        'analytics' => $walkerModel->getDashboardAnalytics(),
                        'monitoring' => $monitor->getOverview($userContext),
                        'active_sessions' => $isAdmin ? $this->get_active_sessions_list() : [],
                        'settings' => $walkerModel->getAssistantSettings()
                    ]);
                    break;

                case 'monitoring_overview':
                    $monitor = $this->logic('MonitoringService');
                    $this->json_response([
                        'success' => true,
                        'monitoring' => $monitor->getOverview($userContext)
                    ]);
                    break;

                case 'faculty_module_data':
                    $faculty = $this->logic('FacultyDataService');
                    $module = strtolower(trim((string)($payload['module'] ?? 'overview')));
                    $progId = !empty($payload['study_program_id']) ? (int)$payload['study_program_id'] : null;
                    $out = ['success' => true, 'module' => $module, 'study_program_id' => $progId];
                    if ($module === 'overview' || $module === 'executive' || $module === 'program') {
                        $out['statistics'] = $faculty->getFacultyStatistics($scope, $progId);
                        $out['students'] = $faculty->getStudentAcademicDetails($scope, null, $progId);
                        $out['theses'] = $faculty->getTheses($scope, $progId);
                        $out['lecturers'] = $faculty->getLecturers($scope, $progId);
                    }
                    if ($module === 'student_personal') {
                        $out['student'] = $userContext['student'];
                        $out['enrollments'] = $faculty->getStudentEnrollmentsAndAttendance($scope);
                        $out['courses'] = $faculty->getCoursesAndSchedules($scope, $progId);
                        $out['theses'] = $faculty->getTheses($scope, $progId);
                    }
                    if ($module === 'courses') {
                        $out['courses'] = $faculty->getCoursesAndSchedules($scope, $progId);
                        $out['lecturers'] = $faculty->getLecturers($scope, null);
                    }
                    if ($module === 'research') {
                        $out['research'] = $faculty->getResearchAndPublications($scope, $progId);
                        $out['lecturers'] = $faculty->getLecturers($scope, null);
                    }
                    if ($module === 'upmf') {
                        $out['quality'] = $faculty->getQualityAndAccreditation($scope, $progId);
                    }
                    if ($module === 'secretary') {
                        $out['administration'] = $faculty->getAdministrationRecords();
                    }
                    $this->json_response($out);
                    break;

                case 'save_course_schedule':
                    if (!$isAdmin && empty($scope['is_dean']) && empty($scope['is_kaprodi']) && empty($scope['is_secretary'])) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $faculty = $this->logic('FacultyDataService');
                    $res = $faculty->saveCourseAndSchedule($payload, $scope);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_COURSE_SCHEDULE', 'tbl_courses', (string)($res['course_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'save_lecturer':
                    if (!$isAdmin && empty($scope['is_dean']) && empty($scope['is_kaprodi'])) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $faculty = $this->logic('FacultyDataService');
                    $res = $faculty->saveLecturer($payload, $scope);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_LECTURER', 'tbl_lecturers', (string)($res['lecturer_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'save_research_project':
                    if (!$isAdmin && empty($scope['is_dean']) && empty($scope['is_research_head']) && empty($scope['is_kaprodi'])) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $faculty = $this->logic('FacultyDataService');
                    $res = $faculty->saveResearchProject($payload, $scope);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_RESEARCH_PROJECT', 'tbl_research_projects', (string)($res['project_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'save_publication':
                    if (!$isAdmin && empty($scope['is_dean']) && empty($scope['is_research_head']) && empty($scope['is_kaprodi'])) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $faculty = $this->logic('FacultyDataService');
                    $res = $faculty->savePublication($payload, $scope);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_PUBLICATION', 'tbl_publications', (string)($res['publication_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'save_quality_indicator':
                    if (!$isAdmin && empty($scope['is_dean']) && empty($scope['is_upmf'])) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $faculty = $this->logic('FacultyDataService');
                    $res = $faculty->saveAccreditationIndicator($payload);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_QUALITY_INDICATOR', 'tbl_accreditation_indicators', (string)($res['indicator_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'save_faculty_meeting':
                    if (!$isAdmin && empty($scope['is_dean']) && empty($scope['is_secretary'])) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $faculty = $this->logic('FacultyDataService');
                    $res = $faculty->saveMeeting($payload);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_FACULTY_MEETING', 'tbl_meetings', (string)($res['meeting_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'users_list':
                    $this->require_permission('users.manage', true);
                    $uSvc = $this->logic('UserManagementService');
                    $res = $uSvc->listUsers($payload);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'save_user':
                    $this->require_permission('users.manage', true);
                    $uSvc = $this->logic('UserManagementService');
                    $res = $uSvc->saveUser($payload, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_USER', 'tbl_users', (string)($res['user_id'] ?? ''), 'Saved user ' . ($payload['username'] ?? ''));
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'toggle_user_status':
                    $this->require_permission('users.manage', true);
                    $uSvc = $this->logic('UserManagementService');
                    $res = $uSvc->setUserStatus((int)($payload['id'] ?? 0), $userId, (string)($payload['status'] ?? 'active'));
                    if (!empty($res['success'])) {
                        $this->audit_log('USER_STATUS', 'tbl_users', (string)($payload['id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'reset_user_password':
                    $this->require_permission('users.manage', true);
                    $uSvc = $this->logic('UserManagementService');
                    $revokeSess = !isset($payload['revoke_sessions']) || !empty($payload['revoke_sessions']);
                    $res = $uSvc->resetUserPassword((int)($payload['id'] ?? 0), (string)($payload['new_password'] ?? ''), $revokeSess, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('ADMIN_PASSWORD_RESET', 'tbl_users', (string)($payload['id'] ?? ''), 'Reset password for @' . ($res['username'] ?? '') . ' (' . ($res['full_name'] ?? '') . ')');
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'password_reset_history':
                    $this->require_permission('users.manage', true);
                    $uSvc = $this->logic('UserManagementService');
                    $this->json_response([
                        'success' => true,
                        'history' => $uSvc->getPasswordResetHistory(25)
                    ]);
                    break;

                case 'delete_user':
                    $this->require_permission('users.manage', true);
                    $uSvc = $this->logic('UserManagementService');
                    $res = $uSvc->softDeleteUser((int)($payload['id'] ?? 0), $userId);
                    if (!empty($res['success'])) {
                        $this->audit_log('ARCHIVE_USER', 'tbl_users', (string)($payload['id'] ?? ''), 'Soft-deleted user account per retention policy');
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'import_users_excel':
                    $this->require_permission('users.manage', true);
                    if (empty($_FILES['excel_file']['tmp_name']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
                        $this->json_response(['success' => false, 'message' => 'Please upload an XLSX or CSV file.'], 400);
                    }
                    $uSvc = $this->logic('UserManagementService');
                    $res = $uSvc->importUsersFromSpreadsheet($_FILES['excel_file']['tmp_name'], basename((string)$_FILES['excel_file']['name']), $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('IMPORT_USERS_EXCEL', 'tbl_users', 'batch', $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'user_activity':
                    $this->require_permission('users.manage', true);
                    $uSvc = $this->logic('UserManagementService');
                    $this->json_response([
                        'success' => true,
                        'activity' => $uSvc->getUserActivity((int)($payload['user_id'] ?? 0))
                    ]);
                    break;

                case 'roles_list':
                    $this->require_permission('roles.manage', true);
                    $this->json_response(['success' => true, 'matrix' => $rbac->getAllPermissionsMatrix()]);
                    break;

                case 'save_role_permissions':
                    $this->require_permission('roles.manage', true);
                    $res = $rbac->saveRolePermissions((int)($payload['role_id'] ?? 0), (array)($payload['permission_ids'] ?? []), $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_ROLE_PERMS', 'tbl_role_permissions', (string)($payload['role_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'study_programs_list':
                    $faculty = $this->logic('FacultyDataService');
                    $this->json_response(['success' => true, 'programs' => $faculty->getStudyPrograms()]);
                    break;

                case 'save_study_program':
                    $this->require_permission('programs.manage', true);
                    $faculty = $this->logic('FacultyDataService');
                    $res = $faculty->saveStudyProgram($payload);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_STUDY_PROGRAM', 'tbl_study_programs', (string)($payload['program_code'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'position_history_list':
                    $this->require_permission('positions.manage', true);
                    $posSvc = $this->logic('PositionHistoryService');
                    $res = $posSvc->listHistory($payload);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'replace_position_holder':
                    $this->require_permission('positions.manage', true);
                    $posSvc = $this->logic('PositionHistoryService');
                    $res = $posSvc->replacePositionHolder($payload, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('REPLACE_POSITION', 'tbl_user_role_history', (string)($payload['role_code'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'students_list':
                    if (!$rbac->userHasPermission($userId, 'students.manage') && empty($scope['all_faculty']) && empty($scope['is_kaprodi'])) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $stuSvc = $this->logic('StudentManagementService');
                    $res = $stuSvc->listStudents($payload, $rbac->userHasPermission($userId, 'students.manage') ? null : $scope);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'save_student':
                    $this->require_permission('students.manage', true);
                    $stuSvc = $this->logic('StudentManagementService');
                    $res = $stuSvc->saveStudent($payload, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_STUDENT', 'tbl_students', (string)($res['student_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'map_student_account':
                    $this->require_permission('students.manage', true);
                    $stuSvc = $this->logic('StudentManagementService');
                    $uidMap = isset($payload['user_id']) && $payload['user_id'] !== '' ? (int)$payload['user_id'] : null;
                    $res = $stuSvc->mapStudentAccount((int)($payload['student_id'] ?? 0), $uidMap);
                    if (!empty($res['success'])) {
                        $this->audit_log('MAP_STUDENT_ACCOUNT', 'tbl_students', (string)($payload['student_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'reset_student_password':
                    $this->require_permission('students.manage', true);
                    $stuSvc = $this->logic('StudentManagementService');
                    $res = $stuSvc->resetStudentPassword((int)($payload['student_id'] ?? 0), (string)($payload['new_password'] ?? ''));
                    if (!empty($res['success'])) {
                        $this->audit_log('RESET_STUDENT_PWD', 'tbl_students', (string)($payload['student_id'] ?? ''), 'Reset student password');
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'import_students_excel':
                    $this->require_permission('students.manage', true);
                    if (empty($_FILES['excel_file']['tmp_name']) || !is_uploaded_file($_FILES['excel_file']['tmp_name'])) {
                        $this->json_response(['success' => false, 'message' => 'Please upload an XLSX or CSV file.'], 400);
                    }
                    $stuSvc = $this->logic('StudentManagementService');
                    $res = $stuSvc->importStudentsFromSpreadsheet($_FILES['excel_file']['tmp_name'], basename((string)$_FILES['excel_file']['name']), $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('IMPORT_STUDENTS_EXCEL', 'tbl_students', 'batch', $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'agents_list':
                    $this->require_permission('agents.manage', true);
                    $reg = $this->logic('AgentRegistryService');
                    $res = $reg->listAllAgents($payload);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'agent_detail':
                    $this->require_permission('agents.manage', true);
                    $reg = $this->logic('AgentRegistryService');
                    $detail = $reg->getAgentDetail((int)($payload['agent_id'] ?? ($payload['id'] ?? 0)));
                    $this->json_response(['success' => (bool)$detail, 'agent' => $detail], $detail ? 200 : 404);
                    break;

                case 'save_agent':
                    $this->require_permission('agents.manage', true);
                    $builder = $this->logic('AgentBuilderService');
                    $res = $builder->saveManualAgent($payload, $userId, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_AGENT', 'tbl_agents', (string)($res['agent_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'ai_assist_build_agent':
                    $this->require_permission('agents.build_ai', true);
                    $builder = $this->logic('AgentBuilderService');
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_write_close();
                    }
                    $res = $builder->proposeAgentWithAi((string)($payload['description'] ?? ''), $userId);
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'approve_agent':
                    $this->require_permission('agents.approve', true);
                    $builder = $this->logic('AgentBuilderService');
                    $aid = (int)($payload['agent_id'] ?? ($payload['id'] ?? 0));
                    $res = $builder->approveAgent($aid, $userId);
                    if (!empty($res['success'])) {
                        $this->audit_log('APPROVE_AGENT', 'tbl_agents', (string)$aid, 'Administrator approved and activated AI agent');
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'rollback_agent_version':
                    $this->require_permission('agents.manage', true);
                    $builder = $this->logic('AgentBuilderService');
                    $aid = (int)($payload['agent_id'] ?? 0);
                    $vid = (int)($payload['version_id'] ?? 0);
                    $res = $builder->rollbackToVersion($aid, $vid, $userId);
                    if (!empty($res['success'])) {
                        $this->audit_log('ROLLBACK_AGENT', 'tbl_agents', (string)$aid, $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'duplicate_agent':
                    $this->require_permission('agents.manage', true);
                    $builder = $this->logic('AgentBuilderService');
                    $res = $builder->duplicateAgent((int)($payload['agent_id'] ?? ($payload['id'] ?? 0)), $userId, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('DUPLICATE_AGENT', 'tbl_agents', (string)($res['agent_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'toggle_agent_status':
                    $this->require_permission('agents.manage', true);
                    $builder = $this->logic('AgentBuilderService');
                    $aid = (int)($payload['agent_id'] ?? ($payload['id'] ?? 0));
                    $st = (string)($payload['status'] ?? 'active');
                    $res = $builder->toggleAgentStatus($aid, $st);
                    if (!empty($res['success'])) {
                        $this->audit_log('TOGGLE_AGENT', 'tbl_agents', (string)$aid, $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'test_agent_sandbox':
                    $this->require_permission('agents.manage', true);
                    $aid = (int)($payload['agent_id'] ?? 0);
                    $testPrompt = trim((string)($payload['prompt'] ?? ''));
                    if ($aid <= 0 || $testPrompt === '') {
                        $this->json_response(['success' => false, 'message' => 'Agent ID and test prompt required.'], 400);
                    }
                    $reg = $this->logic('AgentRegistryService');
                    $detail = $reg->getAgentDetail($aid);
                    if (!$detail) {
                        $this->json_response(['success' => false, 'message' => 'Agent not found.'], 404);
                    }
                    $execSvc = $this->logic('AgentExecutionService');
                    $db = new Database();
                    $db->runPrepared(
                        "INSERT INTO tbl_agent_tasks (task_uuid, user_id, role_code, user_message, execution_mode, status, progress, total_agents)
                         VALUES (?, ?, ?, ?, 'single', 'running', 10, 1)",
                        ['SBX-' . strtoupper(bin2hex(random_bytes(4))), $userId, $userContext['primary_role_code'], '[SANDBOX] ' . $testPrompt]
                    );
                    $sbxTaskId = $db->lastInsertId();
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_write_close();
                    }
                    $out = $execSvc->executeSingleAgent($sbxTaskId, $detail, $userContext, $testPrompt, [], 25, 95);
                    $db->runPrepared("UPDATE tbl_agent_tasks SET status = 'completed', progress = 100, completed_at = NOW() WHERE id = ?", [$sbxTaskId]);
                    $this->json_response(['success' => true, 'result' => $out]);
                    break;

                case 'tools_list':
                    $this->require_permission('tools.manage', true);
                    $tSvc = $this->logic('ToolRegistryService');
                    $this->json_response(['success' => true, 'tools' => $tSvc->listAllTools()]);
                    break;

                case 'toggle_tool':
                    $this->require_permission('tools.manage', true);
                    $tSvc = $this->logic('ToolRegistryService');
                    $res = $tSvc->toggleTool((int)($payload['id'] ?? 0), (int)($payload['is_active'] ?? 1));
                    $this->json_response($res);
                    break;

                case 'kb_list':
                    $ragSvc = $this->logic('RagService');
                    $this->json_response(['success' => true, 'knowledge_bases' => $ragSvc->listKnowledgeBases()]);
                    break;

                case 'save_kb':
                    $this->require_permission('knowledge.manage', true);
                    $ragSvc = $this->logic('RagService');
                    $res = $ragSvc->saveKnowledgeBase($payload, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_KB', 'tbl_knowledge_bases', (string)($res['knowledge_base_id'] ?? ''), $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'rag_docs':
                    $ragSvc = $this->logic('RagService');
                    $res = $ragSvc->listDocuments($payload, $userContext);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'extract_rag_preview':
                    $this->require_permission('knowledge.manage', true);
                    if (empty($_FILES['rag_file']['tmp_name']) || !is_uploaded_file($_FILES['rag_file']['tmp_name'])) {
                        $this->json_response(['success' => false, 'message' => 'No file uploaded.'], 400);
                    }
                    $origName = basename((string)$_FILES['rag_file']['name']);
                    $parsed = DocumentParser::parseUploadedFile($_FILES['rag_file']['tmp_name'], $origName);
                    if (empty($parsed['success'])) {
                        $this->json_response($parsed, 400);
                    }
                    $txt = (string)$parsed['text'];
                    $this->json_response([
                        'success' => true,
                        'file_name' => $origName,
                        'file_type' => $parsed['file_type'],
                        'suggested_title' => pathinfo($origName, PATHINFO_FILENAME),
                        'text' => $txt,
                        'char_count' => mb_strlen($txt, 'UTF-8'),
                        'segment_estimate' => max(1, (int)ceil(mb_strlen($txt, 'UTF-8') / 520)),
                        'message' => "Extracted " . mb_strlen($txt, 'UTF-8') . " characters from {$parsed['file_type']}."
                    ]);
                    break;

                case 'save_rag_doc':
                    $this->require_permission('knowledge.manage', true);
                    if (!empty($_FILES['rag_file']['tmp_name']) && is_uploaded_file($_FILES['rag_file']['tmp_name'])) {
                        $origName = basename((string)$_FILES['rag_file']['name']);
                        $parsed = DocumentParser::parseUploadedFile($_FILES['rag_file']['tmp_name'], $origName);
                        if (empty($parsed['success'])) {
                            $this->json_response($parsed, 400);
                        }
                        $payload['file_name'] = $origName;
                        $payload['file_type'] = $parsed['file_type'];
                        if (empty($payload['content_text'])) {
                            $payload['content_text'] = $parsed['text'];
                        }
                        if (empty($payload['title'])) {
                            $payload['title'] = pathinfo($origName, PATHINFO_FILENAME);
                        }
                    }
                    $ragSvc = $this->logic('RagService');
                    $res = $ragSvc->saveDocument($payload, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_RAG_DOC', 'tbl_knowledge_documents', (string)($res['document_id'] ?? ''), 'Indexed RAG document: ' . ($payload['title'] ?? ''));
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'test_rag_search':
                    $this->require_permission('knowledge.read', true);
                    $ragSvc = $this->logic('RagService');
                    $query = trim((string)($payload['query'] ?? ''));
                    $agentId = (int)($payload['agent_id'] ?? 0);
                    $chunks = $ragSvc->retrieveForAgent($query, $agentId, $userContext['role_ids'] ?? [], 5, 0.10);
                    $this->json_response([
                        'success' => true,
                        'found' => !empty($chunks),
                        'results' => $chunks
                    ]);
                    break;

                case 'api_usage_logs':
                    $this->require_permission('api_keys.manage', true);
                    $db = new Database();
                    $st = $db->runPrepared(
                        "SELECT l.id, l.provider, l.model_name, l.endpoint, l.prompt_tokens, l.completion_tokens, l.total_tokens,
                                l.estimated_cost_usd, l.http_status, l.latency_ms, l.status, l.error_message, l.created_at,
                                a.agent_code, u.username
                         FROM tbl_api_usage_logs l
                         LEFT JOIN tbl_agents a ON a.id = l.agent_id
                         LEFT JOIN tbl_users u ON u.id = l.user_id
                         ORDER BY l.id DESC LIMIT 50"
                    );
                    $this->json_response(['success' => true, 'logs' => $st ? $st->fetchAll(PDO::FETCH_ASSOC) : []]);
                    break;

                case 'api_cost_monitor':
                    $this->require_permission('api_keys.manage', true);
                    $monitor = $this->logic('MonitoringService');
                    $range = (string)($payload['range'] ?? '30d');
                    $res = $monitor->getApiCostAndAgentMonitor($range);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'toggle_ai_key':
                    $this->require_permission('api_keys.manage', true);
                    $monitor = $this->logic('MonitoringService');
                    $keyId = (int)($payload['id'] ?? 0);
                    $isActive = (int)($payload['is_active'] ?? 1);
                    $res = $monitor->toggleApiKeyStatus($keyId, $isActive);
                    if (!empty($res['success'])) {
                        $this->audit_log('TOGGLE_AI_KEY', 'tbl_ai_provider_keys', (string)$keyId, $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'delete_ai_key':
                    $this->require_permission('api_keys.manage', true);
                    $keyId = (int)($payload['id'] ?? 0);
                    $res = $walkerModel->deleteRecord('gemini_keys', $keyId);
                    if (!empty($res['success'])) {
                        $this->audit_log('DELETE_AI_KEY', 'tbl_ai_provider_keys', (string)$keyId, 'Deleted API Key #' . $keyId);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'save_budget_limit':
                    $this->require_permission('api_keys.manage', true);
                    $monitor = $this->logic('MonitoringService');
                    $res = $monitor->saveMonthlyBudgetUsd((float)($payload['monthly_budget_usd'] ?? 50));
                    if (!empty($res['success'])) {
                        $this->audit_log('UPDATE_AI_BUDGET', 'tbl_system_settings', 'ai_monthly_budget_usd', $res['message']);
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'agent_telemetry_charts':
                    if (!$rbac->userHasPermission($userId, 'monitoring.view') && !$rbac->userHasPermission($userId, 'api_keys.manage')) {
                        $this->json_response(['success' => false, 'message' => 'Permission denied.'], 403);
                    }
                    $monitor = $this->logic('MonitoringService');
                    $range = (string)($payload['range'] ?? '24h');
                    $zone = (string)($payload['zone'] ?? '');
                    $res = $monitor->getRealTimeAgentTelemetry($range, $zone);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'table':
                    $tableType = trim((string)($payload['table_type'] ?? 'conversations'));
                    if (in_array($tableType, ['users', 'security', 'audit_logs'], true)) {
                        $this->require_permission($tableType === 'users' ? 'users.manage' : 'audit.view', true);
                    } elseif ($tableType === 'gemini_keys') {
                        $this->require_permission('api_keys.manage', true);
                    }
                    $res = $walkerModel->getPaginatedTable($tableType, $payload);
                    $res['success'] = true;
                    $this->json_response($res);
                    break;

                case 'save_static_kb':
                    $this->require_permission('knowledge.manage', true);
                    $res = $walkerModel->saveStaticKnowledge($payload);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_STATIC_KB', 'tbl_static_knowledge', $payload['topic_code'] ?? '', 'Saved static knowledge entry');
                    }
                    $this->json_response($res);
                    break;

                case 'save_memory':
                    $this->require_permission('knowledge.manage', true);
                    $sc = trim((string)($payload['session_code'] ?? 'SES-DEMO01')) ?: 'SES-DEMO01';
                    $ui = trim((string)($payload['user_identifier'] ?? 'FILKOM')) ?: 'FILKOM';
                    $mt = trim((string)($payload['memory_type'] ?? 'fact'));
                    if (!in_array($mt, ['preference', 'fact', 'project_context', 'conversation_summary'], true)) $mt = 'fact';
                    $mk = trim((string)($payload['memory_key'] ?? 'custom_fact'));
                    $mv = trim((string)($payload['memory_value'] ?? ''));
                    $imp = max(0.1, min(1.0, (float)($payload['importance'] ?? 0.90)));
                    if ($mk === '' || $mv === '') {
                        $this->json_response(['success' => false, 'message' => 'Memory key and content required.'], 400);
                    }
                    $ok = $walkerModel->saveMemoryItem($sc, $ui, $mt, $mk, $mv, $imp);
                    $this->json_response(['success' => $ok, 'message' => $ok ? 'Memory saved.' : 'Failed to save memory.']);
                    break;

                case 'save_ai_key':
                case 'save_gemini_key':
                    $this->require_permission('api_keys.manage', true);
                    $res = $walkerModel->saveGeminiKey($payload, $actor);
                    if (!empty($res['success'])) {
                        $this->audit_log('SAVE_AI_KEY', 'tbl_ai_provider_keys', '', 'Updated encrypted AI provider key');
                    }
                    $this->json_response($res);
                    break;

                case 'test_ai_key':
                case 'test_gemini_key':
                    $this->require_permission('api_keys.manage', true);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_write_close();
                    }
                    $this->json_response($walkerModel->testGeminiKey($payload));
                    break;

                case 'set_primary_key':
                    $this->require_permission('api_keys.manage', true);
                    $keyId = (int)($payload['id'] ?? 0);
                    $res = $walkerModel->setPrimaryApiKey($keyId);
                    if (!empty($res['success'])) {
                        $this->audit_log('SET_PRIMARY_KEY', 'tbl_ai_provider_keys', (string)$keyId, 'Set primary API key #' . $keyId);
                    }
                    $this->json_response($res);
                    break;

                case 'save_ai_settings':
                    $this->require_permission('api_keys.manage', true);
                    $res = $walkerModel->saveAssistantSettings($payload, true);
                    $this->audit_log('UPDATE_AI_SETTINGS', 'tbl_system_settings', '', 'Updated orchestrator AI configuration');
                    $res['message'] = 'System configuration saved.';
                    $this->json_response($res);
                    break;

                case 'run_self_learning_cycle':
                    $this->require_permission('agents.manage', true);
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_write_close();
                    }
                    $res = $walkerModel->runSelfLearningCycle('manual_cycle');
                    if (!empty($res['success'])) {
                        $this->audit_log('RUN_SELF_LEARNING_CYCLE', 'tbl_self_eval_runs', '', $res['message'] ?? 'Ran self-learning cycle');
                        $res['analytics'] = $walkerModel->getDashboardAnalytics();
                    }
                    $this->json_response($res);
                    break;

                case 'auto_tick_self_learning':
                    if (session_status() === PHP_SESSION_ACTIVE) {
                        @session_write_close();
                    }
                    $cycleRes = $walkerModel->getSelfLearner()->runAutonomousMaintenanceIfDue(20, 4);
                    $this->json_response([
                        'success' => true,
                        'cycle_ran' => is_array($cycleRes),
                        'cycle' => $cycleRes,
                        'analytics' => $walkerModel->getDashboardAnalytics()
                    ]);
                    break;

                case 'heal_knowledge_gap':
                    $this->require_permission('agents.manage', true);
                    $gapId = (int)($payload['id'] ?? 0);
                    $res = $walkerModel->healKnowledgeGap($gapId);
                    $res['analytics'] = $walkerModel->getDashboardAnalytics();
                    $this->json_response($res);
                    break;

                case 'toggle_learned_knowledge':
                    $this->require_permission('agents.manage', true);
                    $this->json_response($walkerModel->toggleLearnedKnowledge((int)($payload['id'] ?? 0), (int)($payload['is_active'] ?? 1)));
                    break;

                case 'delete_record':
                    $this->require_permission('users.manage', true);
                    $tableType = trim((string)($payload['table_type'] ?? ''));
                    $id = (int)($payload['id'] ?? 0);
                    $res = $walkerModel->deleteRecord($tableType, $id);
                    if (!empty($res['success'])) {
                        $this->audit_log('DELETE_RECORD', $tableType, (string)$id, "Deleted record #{$id} from {$tableType}");
                    }
                    $this->json_response($res);
                    break;

                case 'bulk_delete_conversations':
                    $this->require_permission('audit.view', true);
                    if (!$isAdmin) $this->json_response(['success' => false, 'message' => 'Admin required.'], 403);
                    $res = $walkerModel->deleteConversationsByCategory($payload);
                    if (!empty($res['success'])) {
                        $this->audit_log('BULK_DELETE_CONVERSATIONS', 'tbl_va_conversations', 'filter', "Deleted {$res['deleted']} rows");
                    }
                    $this->json_response($res);
                    break;

                case 'delete_all_security_table':
                    $this->require_permission('audit.view', true);
                    if (!$isAdmin) $this->json_response(['success' => false, 'message' => 'Admin required.'], 403);
                    $res = $walkerModel->deleteAllSecurityTable(trim((string)($payload['table_type'] ?? '')));
                    $this->json_response($res);
                    break;

                case 'setup_mfa':
                    $authSvc = $this->logic('AuthService');
                    $this->json_response($authSvc->generateMfaProvision($userId));
                    break;

                case 'enable_mfa':
                    $authSvc = $this->logic('AuthService');
                    $res = $authSvc->enableMfa($userId, (string)($payload['code'] ?? ''));
                    if (!empty($res['success'])) {
                        $this->audit_log('MFA_ENABLED', 'tbl_users', (string)$userId, 'User enabled TOTP MFA');
                    }
                    $this->json_response($res, !empty($res['success']) ? 200 : 400);
                    break;

                case 'disable_mfa':
                    $authSvc = $this->logic('AuthService');
                    $res = $authSvc->disableMfa($userId);
                    if (!empty($res['success'])) {
                        $this->audit_log('MFA_DISABLED', 'tbl_users', (string)$userId, 'User disabled TOTP MFA');
                    }
                    $this->json_response($res);
                    break;

                case 'update_profile':
                    $res = $walkerModel->updateProfileAccount($userId, $payload);
                    if (!empty($res['success']) && !empty($res['user'])) {
                        $_SESSION['user-name'] = $res['user']['full_name'];
                        $_SESSION['user-email'] = $res['user']['email'];
                        $_SESSION['user-department'] = $res['user']['department'];
                        $_SESSION['user-phone'] = $res['user']['phone'];
                        $_SESSION['user-color'] = $res['user']['avatar_color'];
                        $this->audit_log('UPDATE_PROFILE', 'tbl_users', (string)$userId, 'Updated profile');
                    }
                    $this->json_response($res);
                    break;

                case 'revoke_session':
                    $this->require_permission('users.manage', true);
                    $sid = trim((string)($payload['session_id'] ?? ($payload['id'] ?? '')));
                    $ok = $this->revoke_user_session_db($sid, $_SESSION['user-name'] ?? 'Admin');
                    $this->json_response([
                        'success' => $ok,
                        'message' => $ok ? 'Session revoked.' : 'Failed to revoke session.',
                        'active_sessions' => $this->get_active_sessions_list()
                    ]);
                    break;

                default:
                    $this->json_response(['success' => false, 'message' => 'Unrecognized Dashboard API action.'], 400);
            }
        } catch (Throwable $e) {
            error_log('Dashboard API error: ' . $e->getMessage());
            $this->json_response(['success' => false, 'message' => 'Internal server processing error.'], 500);
        }
    }
}
