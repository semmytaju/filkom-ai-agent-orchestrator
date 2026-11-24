<?php
$v = '20261124v400';
$h = static fn($val): string => htmlspecialchars((string)($val ?? ''), ENT_QUOTES, 'UTF-8');

$user = $data['current_user'] ?? [];
$ctx = $data['user_context'] ?? [];
$scope = $ctx['scope'] ?? [];
$perms = $ctx['permissions'] ?? ($ctx['permission_codes'] ?? []);
$hasPerm = static fn(string $p): bool => !empty($scope['is_admin']) || in_array($p, $perms, true);

$roleCode = (string)($user['role'] ?? ($ctx['primary_role_code'] ?? 'STUDENT'));
$rolesList = $user['roles'] ?? [$roleCode];
$progsList = $data['study_programs'] ?? [];
$rolesCatalog = $data['roles_catalog'] ?? [];
$vaSettings = $data['va_settings'] ?? [];
$authorizedAgents = $data['authorized_agents'] ?? [];
$monitoring = $data['monitoring'] ?? [];
$csrfToken = (string)($data['csrf_token'] ?? '');

$isAdmin = !empty($scope['is_admin']);
$isDean = !empty($scope['is_dean']);
$isKaprodi = !empty($scope['is_kaprodi']);
$isSecretary = !empty($scope['is_secretary']);
$isUpmf = !empty($scope['is_upmf']);
$isResearchHead = !empty($scope['is_research_head']);
$isStudent = !empty($scope['is_student']) && !$isAdmin && !$isDean && !$isKaprodi;

$wsTabs = [];
if ($isAdmin || $isDean || $isKaprodi) {
    $wsTabs[] = ['id' => $isDean || $isAdmin ? 'executive' : 'program', 'label' => ($isDean || $isAdmin) ? 'Executive' : 'Program Academic', 'icon' => 'bi-bar-chart-line'];
    $wsTabs[] = ['id' => 'courses', 'label' => 'Courses & Schedule', 'icon' => 'bi-calendar3'];
}
if ($isAdmin || $isDean || $isResearchHead || $isKaprodi) {
    $wsTabs[] = ['id' => 'research', 'label' => 'Research & Publications', 'icon' => 'bi-flask'];
}
if ($isAdmin || $isDean || $isUpmf) {
    $wsTabs[] = ['id' => 'upmf', 'label' => 'UPMF Quality', 'icon' => 'bi-shield-check'];
}
if ($isAdmin || $isDean || $isSecretary) {
    $wsTabs[] = ['id' => 'secretary', 'label' => 'Administration', 'icon' => 'bi-folder2-open'];
}
if ($isStudent || (!empty($user['student']) && count($wsTabs) === 0)) {
    $wsTabs[] = ['id' => 'student_personal', 'label' => 'My Academic Record', 'icon' => 'bi-person-vcard'];
}

$bootPayload = [
    'appPath' => APP_PATH,
    'csrfToken' => $csrfToken,
    'user' => [
        'id' => (int)($user['id'] ?? 0),
        'username' => (string)($user['username'] ?? ''),
        'full_name' => (string)($user['full_name'] ?? ''),
        'email' => (string)($user['email'] ?? ''),
        'role' => $roleCode,
        'roles' => $rolesList,
        'zone_code' => (string)($user['zone_code'] ?? 'COMMAND_CENTER'),
        'program_codes' => $user['program_codes'] ?? [],
        'student' => $user['student'] ?? null,
        'mfa_enabled' => !empty($user['mfa_enabled']),
        'caps' => [
            'courses' => ($isAdmin || $isDean || $isKaprodi || $isSecretary),
            'lecturers' => ($isAdmin || $isDean || $isKaprodi),
            'research' => ($isAdmin || $isDean || $isResearchHead || $isKaprodi),
            'upmf' => ($isAdmin || $isDean || $isUpmf),
            'secretary' => ($isAdmin || $isDean || $isSecretary)
        ]
    ],
    'permissions' => $perms,
    'studyPrograms' => $progsList,
    'rolesCatalog' => $rolesCatalog,
    'authorizedAgents' => array_map(static function ($a) {
        return [
            'id' => (int)($a['id'] ?? 0),
            'agent_code' => (string)($a['agent_code'] ?? ''),
            'agent_name' => (string)($a['agent_name'] ?? ''),
            'description' => (string)($a['description'] ?? ''),
            'agent_category' => (string)($a['agent_category'] ?? ''),
            'zone_code' => (string)($a['zone_code'] ?? 'COMMAND_CENTER'),
            'avatar_model' => (string)($a['avatar_model'] ?? 'chibi_orchestrator'),
            'avatar_outfit' => (string)($a['avatar_outfit'] ?? 'classic_navy'),
            'animation_profile' => (string)($a['animation_profile'] ?? 'executive_briefing'),
            'model_name' => (string)($a['model_name'] ?? 'gpt-4.1-mini')
        ];
    }, $authorizedAgents)
];
$bootJson = json_encode($bootPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
?>
<!DOCTYPE html>
<html lang="en" data-bs-theme="light">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $h($data['title'] ?? APP_NAME); ?></title>
    <link rel="stylesheet" href="<?php echo APP_PATH; ?>/public/assets/vendor/bootstrap/css/bootstrap.min.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="<?php echo APP_PATH; ?>/public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css?v=<?php echo $v; ?>">
    <link rel="stylesheet" href="<?php echo APP_PATH; ?>/public/assets/css/orchestrator.css?v=<?php echo $v; ?>">
    <link rel="modulepreload" href="<?php echo APP_PATH; ?>/public/assets/js/imitation/OrchestratorPortalApp.js?v=<?php echo $v; ?>">
    <link rel="modulepreload" href="<?php echo APP_PATH; ?>/public/assets/js/imitation/OrchestratorCommandCenter3D.js?v=<?php echo $v; ?>">
    <link rel="modulepreload" href="<?php echo APP_PATH; ?>/public/assets/js/imitation/ChibiAvatarBuilder.js?v=<?php echo $v; ?>">
    <link rel="modulepreload" href="<?php echo APP_PATH; ?>/public/assets/js/imitation/HologramPanel3D.js?v=20261124v375">
    <link rel="modulepreload" href="<?php echo APP_PATH; ?>/public/assets/vendor/three/loaders/GLTFLoader.r150.jsm.js?v=<?php echo $v; ?>">
</head>
<body class="orch-body">
<div class="orch-shell">
    <aside class="orch-sidebar" id="orchSidebar">
        <div class="orch-brand">
            <div class="orch-brand-logo"><i class="bi bi-cpu-fill"></i></div>
            <div class="orch-brand-meta">
                <div class="orch-brand-title">ORCHESTRATOR</div>
                <div class="orch-brand-sub">AI Agent Ecosystem</div>
            </div>
            <button type="button" class="orch-side-collapse-btn d-none d-md-inline-flex" id="btnCollapseSidebar" title="Toggle Navigation Rail">
                <i class="bi bi-layout-sidebar"></i>
            </button>
        </div>
        <nav class="orch-nav-scroll">
            <div class="orch-nav-group">Orchestration</div>
            <button type="button" class="orch-nav-btn active" data-section="command_center" data-title="3D Agent Command Center">
                <i class="bi bi-boxes"></i> Command Center
                <span class="orch-nav-pill"><?php echo count($authorizedAgents); ?></span>
            </button>
            <button type="button" class="orch-nav-btn" data-section="workspace" data-title="Faculty Workspace">
                <i class="bi bi-grid-1x2"></i> Faculty Workspace
            </button>

            <?php if ($hasPerm('agents.manage') || $hasPerm('agents.build_ai') || $hasPerm('agents.approve') || $hasPerm('knowledge.read') || $hasPerm('tools.manage')): ?>
            <div class="orch-nav-group">Intelligence</div>
            <?php endif; ?>

            <?php if ($hasPerm('agents.manage') || $hasPerm('agents.build_ai') || $hasPerm('agents.approve')): ?>
            <button type="button" class="orch-nav-btn" data-section="agents" data-title="Agent Registry & Builder">
                <i class="bi bi-robot"></i> Agent Registry
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('knowledge.read') || $hasPerm('knowledge.manage') || $hasPerm('tools.manage')): ?>
            <button type="button" class="orch-nav-btn" data-section="knowledge_tools" data-title="Knowledge Base (RAG) & Tools">
                <i class="bi bi-journal-code"></i> Knowledge &amp; Tools
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('users.manage') || $hasPerm('roles.manage') || $hasPerm('positions.manage') || $hasPerm('students.manage') || $isDean || $isKaprodi): ?>
            <div class="orch-nav-group">Governance</div>
            <?php endif; ?>

            <?php if ($hasPerm('users.manage') || $hasPerm('roles.manage')): ?>
            <button type="button" class="orch-nav-btn" data-section="users_rbac" data-subtab="btnTabUsersMain" data-title="Users, Roles & Programs">
                <i class="bi bi-people"></i> Users &amp; RBAC
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('users.manage')): ?>
            <button type="button" class="orch-nav-btn" data-section="users_rbac" data-subtab="btnTabResetPwd" data-title="Admin Password Reset">
                <i class="bi bi-key-fill"></i> Reset Password
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('positions.manage')): ?>
            <button type="button" class="orch-nav-btn" data-section="positions" data-title="Position Assignment History">
                <i class="bi bi-arrow-left-right"></i> Position History
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('students.manage') || $isDean || $isKaprodi): ?>
            <button type="button" class="orch-nav-btn" data-section="students" data-title="Student Records">
                <i class="bi bi-mortarboard"></i> Students
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('api_keys.manage') || $hasPerm('monitoring.view') || $hasPerm('audit.view')): ?>
            <div class="orch-nav-group">Operations</div>
            <?php endif; ?>

            <?php if ($hasPerm('api_keys.manage')): ?>
            <button type="button" class="orch-nav-btn" data-section="ai_engine" data-title="API Keys &amp; Spend Monitor">
                <i class="bi bi-key"></i> API Keys &amp; Cost
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('monitoring.view') || $hasPerm('api_keys.manage')): ?>
            <button type="button" class="orch-nav-btn" data-section="agent_charts" data-title="Real-Time Agent Visual Monitor">
                <i class="bi bi-graph-up-arrow"></i> Live Agent Charts
            </button>
            <?php endif; ?>

            <?php if ($hasPerm('monitoring.view') || $hasPerm('audit.view')): ?>
            <button type="button" class="orch-nav-btn" data-section="monitoring" data-title="Telemetry &amp; Audit Trail">
                <i class="bi bi-activity"></i> Monitoring &amp; Audit
            </button>
            <?php endif; ?>

            <div class="orch-nav-group">Account</div>
            <button type="button" class="orch-nav-btn" data-section="profile" data-title="Profile & MFA Security">
                <i class="bi bi-person-lock"></i> Profile &amp; MFA
            </button>
        </nav>

        <div class="orch-sidebar-footer">
            <div class="small fw-semibold text-white text-truncate"><?php echo $h($user['full_name'] ?? 'User'); ?></div>
            <div class="d-flex justify-content-between align-items-center mt-1">
                <span class="badge bg-info text-dark"><?php echo $h($roleCode); ?></span>
                <a href="<?php echo APP_PATH; ?>/logout" class="btn btn-sm btn-outline-light py-0 px-2" title="Sign Out">
                    <i class="bi bi-box-arrow-right"></i>
                </a>
            </div>
        </div>
    </aside>

    <div class="orch-main">
        <main class="orch-content is-stage" id="orchMainContent">
            <div class="orch-inline-head" id="orchInlineHeader">
                <div class="orch-inline-head-left">
                    <button type="button" class="btn btn-sm btn-outline-info orch-mobile-toggle" data-mobile-nav>
                        <i class="bi bi-list"></i>
                    </button>
                    <h1 class="orch-page-title" id="orchPageTitle">3D Agent Command Center</h1>
                </div>
                <div class="orch-inline-head-right">
                    <button type="button" class="btn btn-sm btn-outline-info rounded-pill px-3 py-0" id="btnReturnStage">
                        <i class="bi bi-boxes me-1"></i>3D Stage
                    </button>
                </div>
            </div>

            <section id="section-command_center" class="orch-section active">
                <div class="orch-command-grid">
                    <div class="orch-stage-card" id="orchStageContainer">
                        <canvas id="orchCommandCanvas"></canvas>
                        <div class="orch-stage-hud-top">
                            <div class="d-flex align-items-center gap-2 flex-wrap">
                                <button type="button" class="btn btn-sm btn-outline-info orch-mobile-toggle rounded-pill px-2 py-0" id="btnMobileNav" data-mobile-nav>
                                    <i class="bi bi-list"></i>
                                </button>
                                <span class="orch-hud-glass orch-hud-title-pill d-none d-md-inline-flex">
                                    <i class="bi bi-cpu-fill text-info"></i> 3D Command Center
                                </span>
                                <span class="orch-hud-glass" id="orchHubStateBadge"><span class="orch-dot pulse"></span> IDLE</span>
                                <span class="orch-hud-glass" id="orchSelectedAgentBadge"><i class="bi bi-diagram-3"></i> Auto Orchestrator</span>
                            </div>
                            <div class="d-flex align-items-center gap-1 flex-wrap">
                                <div id="orchZoneFilterBar" class="d-flex gap-1 flex-wrap"></div>
                                <button type="button" id="btnResetCam" class="btn btn-sm btn-outline-info bg-dark bg-opacity-75 rounded-pill px-2 py-0" title="Reset Camera">
                                    <i class="bi bi-arrows-fullscreen"></i>
                                </button>
                                <button type="button" id="btnToggleConsole" class="orch-hud-console-btn" title="Open AI Orchestrator Console">
                                    <i class="bi bi-chat-square-dots-fill"></i> AI Console
                                </button>
                            </div>
                        </div>
                        <div class="orch-stage-hud-bottom">
                            <span class="orch-hud-glass text-truncate" style="max-width: 340px;" id="orchEventTicker">
                                <i class="bi bi-broadcast text-info"></i> Orchestrator Mesh Online
                            </span>
                        </div>
                    </div>

                    <div class="orch-console-card" id="orchConsoleDrawer">
                        <div class="orch-console-head">
                            <div class="d-flex align-items-center gap-2 flex-grow-1 min-w-0">
                                <select id="orchConvSelect" class="form-select form-select-sm"></select>
                            </div>
                            <button type="button" id="btnNewConv" class="btn btn-sm btn-outline-primary text-nowrap">
                                <i class="bi bi-plus-lg"></i> New
                            </button>
                            <button type="button" id="btnCloseConsole" class="btn btn-sm btn-outline-secondary" title="Hide Console">
                                <i class="bi bi-x-lg"></i>
                            </button>
                        </div>
                        <div id="orchAgentStrip" class="orch-agent-strip"></div>
                        <div id="orchChatFeed" class="orch-chat-feed"></div>
                        <div class="orch-composer">
                            <div id="orchQuickPrompts" class="orch-quick-prompts"></div>
                            <form id="orchChatForm" class="input-group input-group-sm">
                                <input type="text" id="orchChatInput" class="form-control" placeholder="Instruct FILKOM agents..." maxlength="4000" required>
                                <button type="submit" id="btnSendChat" class="btn btn-primary px-3">
                                    <i class="bi bi-send-fill"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </section>

            <section id="section-workspace" class="orch-section">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="btn-group btn-group-sm flex-wrap" id="wsTabBar">
                        <?php foreach ($wsTabs as $idx => $wt): ?>
                        <button type="button" class="btn btn-outline-primary <?php echo $idx === 0 ? 'active' : ''; ?>" data-ws-mod="<?php echo $h($wt['id']); ?>">
                            <i class="bi <?php echo $h($wt['icon']); ?> me-1"></i><?php echo $h($wt['label']); ?>
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php if ($isAdmin || $isDean || $isUpmf || $isSecretary || $isResearchHead): ?>
                    <select id="wsProgFilter" class="form-select form-select-sm w-auto">
                        <option value="">All Study Programs</option>
                        <?php foreach ($progsList as $sp): ?>
                        <option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code'] . ' — ' . $sp['program_name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
                <div id="wsBody"></div>
            </section>

            <?php if ($hasPerm('agents.manage') || $hasPerm('agents.build_ai') || $hasPerm('agents.approve')): ?>
            <section id="section-agents" class="orch-section">
                <div class="orch-card mb-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
                        <div class="d-flex flex-wrap gap-2">
                            <input type="text" id="agentSearch" class="form-control form-control-sm" style="width:210px" placeholder="Search agents...">
                            <select id="agentCatFilter" class="form-select form-select-sm w-auto">
                                <option value="">All Categories</option>
                                <option value="DEAN">DEAN</option>
                                <option value="KAPRODI">KAPRODI</option>
                                <option value="SECRETARY">SECRETARY</option>
                                <option value="UPMF">UPMF</option>
                                <option value="RESEARCH_HEAD">RESEARCH_HEAD</option>
                                <option value="STUDENT">STUDENT</option>
                                <option value="CUSTOM">CUSTOM</option>
                            </select>
                            <select id="agentStatusFilter" class="form-select form-select-sm w-auto">
                                <option value="">All Status</option>
                                <option value="active">Active</option>
                                <option value="inactive">Inactive</option>
                            </select>
                        </div>
                        <div class="d-flex flex-wrap gap-2">
                            <?php if ($hasPerm('agents.manage')): ?>
                            <button type="button" id="btnNewAgent" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Manual Builder</button>
                            <?php endif; ?>
                            <?php if ($hasPerm('agents.build_ai')): ?>
                            <button type="button" id="btnOpenAiBuilder" class="btn btn-sm btn-info text-white"><i class="bi bi-stars me-1"></i>AI Agent Builder</button>
                            <?php endif; ?>
                            <?php if ($hasPerm('agents.manage')): ?>
                            <button type="button" id="btnRunSelfLearn" class="btn btn-sm btn-outline-secondary"><i class="bi bi-arrow-repeat me-1"></i>Self-Learn Cycle</button>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <div class="orch-table-wrap">
                    <table class="table orch-table">
                        <thead><tr><th>Code</th><th>Agent</th><th>Category</th><th>Roles</th><th>Model</th><th>Ver</th><th>Status</th><th>Actions</th></tr></thead>
                        <tbody id="tblAgentsBody"></tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($hasPerm('users.manage') || $hasPerm('roles.manage')): ?>
            <section id="section-users_rbac" class="orch-section">
                <ul class="nav nav-tabs nav-tabs-sm mb-3" id="usersRbacTabs">
                    <li class="nav-item"><button class="nav-link active" id="btnTabUsersMain" data-bs-toggle="tab" data-bs-target="#tabUsersPane" type="button">Users</button></li>
                    <li class="nav-item"><button class="nav-link" id="btnTabResetPwd" data-bs-toggle="tab" data-bs-target="#tabResetPwdPane" type="button"><i class="bi bi-key-fill me-1"></i>Reset Password</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRolesPane" type="button">RBAC Matrix</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabProgsPane" type="button">Study Programs</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabSessionsPane" type="button">Active Sessions</button></li>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabUsersPane">
                        <div class="orch-card mb-3 d-flex flex-wrap justify-content-between gap-2">
                            <div class="d-flex flex-wrap gap-2">
                                <input type="text" id="userSearch" class="form-control form-control-sm" style="width:210px" placeholder="Search users...">
                                <select id="userRoleFilter" class="form-select form-select-sm w-auto">
                                    <option value="">All Roles</option>
                                    <?php foreach ($rolesCatalog as $rc): ?>
                                    <option value="<?php echo $h($rc['role_code']); ?>"><?php echo $h($rc['role_code']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="button" id="btnImportUsers" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel/CSV</button>
                                <button type="button" id="btnNewUser" class="btn btn-sm btn-primary"><i class="bi bi-person-plus me-1"></i>Add User</button>
                            </div>
                        </div>
                        <div class="orch-table-wrap">
                            <table class="table orch-table">
                                <thead><tr><th>Username</th><th>Full Name &amp; Email</th><th>Roles</th><th>Scope / Dept</th><th>Status</th><th>Actions</th></tr></thead>
                                <tbody id="tblUsersBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tabResetPwdPane">
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <div class="orch-card h-100">
                                    <h6 class="fw-bold mb-3"><i class="bi bi-shield-lock me-1 text-primary"></i>Admin Reset User Password</h6>
                                    <form id="adminResetPwdForm" class="row g-2">
                                        <div class="col-12">
                                            <label class="form-label small mb-0">Target User Account</label>
                                            <select id="adminResetUserId" class="form-select form-select-sm" required></select>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small mb-0">New Password (min 8 chars)</label>
                                            <div class="input-group input-group-sm">
                                                <input type="password" id="adminResetNewPwd" class="form-control" minlength="6" required placeholder="Enter new password">
                                                <button type="button" id="btnToggleAdminResetPwd" class="btn btn-outline-secondary" title="Show/Hide"><i class="bi bi-eye"></i></button>
                                                <button type="button" id="btnGenStrongPwd" class="btn btn-outline-primary" title="Generate Strong Password"><i class="bi bi-shuffle"></i></button>
                                                <button type="button" id="btnCopyResetPwd" class="btn btn-outline-info" title="Copy"><i class="bi bi-clipboard"></i></button>
                                            </div>
                                        </div>
                                        <div class="col-12">
                                            <label class="form-label small mb-0">Confirm Password</label>
                                            <input type="password" id="adminResetConfirmPwd" class="form-control form-control-sm" minlength="6" required placeholder="Re-enter password">
                                        </div>
                                        <div class="col-12 mt-2">
                                            <label class="form-check-label small">
                                                <input type="checkbox" id="adminResetRevokeSessions" class="form-check-input me-1" checked>
                                                Revoke active user sessions &amp; unlock login lockout
                                            </label>
                                        </div>
                                        <div class="col-12 text-end mt-2">
                                            <button type="submit" class="btn btn-sm btn-warning fw-semibold"><i class="bi bi-key-fill me-1"></i>Reset Password</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <div class="orch-card h-100">
                                    <h6 class="fw-bold mb-2">Recent Password Resets</h6>
                                    <div class="orch-table-wrap">
                                        <table class="table orch-table">
                                            <thead><tr><th>Time</th><th>Admin</th><th>Details</th><th>IP</th></tr></thead>
                                            <tbody id="tblResetPwdAudit"></tbody>
                                        </table>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tabRolesPane">
                        <div id="rbacMatrixWrap"></div>
                    </div>
                    <div class="tab-pane fade" id="tabProgsPane">
                        <div class="mb-2 text-end">
                            <button type="button" id="btnNewStudyProgram" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Program</button>
                        </div>
                        <div class="orch-table-wrap">
                            <table class="table orch-table">
                                <thead><tr><th>Code</th><th>Program Name</th><th>Degree</th><th>Accreditation</th><th>Students</th><th>Lecturers</th></tr></thead>
                                <tbody id="tblProgramsBody"></tbody>
                            </table>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tabSessionsPane">
                        <div class="orch-table-wrap">
                            <table class="table orch-table">
                                <thead><tr><th>Username</th><th>IP Address</th><th>Last Activity</th><th>Action</th></tr></thead>
                                <tbody id="tblSessionsBody"></tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($hasPerm('positions.manage')): ?>
            <section id="section-positions" class="orch-section">
                <div class="orch-card mb-3 d-flex flex-wrap justify-content-between gap-2">
                    <div class="d-flex flex-wrap gap-2">
                        <select id="posRoleFilter" class="form-select form-select-sm w-auto">
                            <option value="">All Leadership Roles</option>
                            <option value="DEAN">DEAN</option>
                            <option value="KAPRODI_IF">KAPRODI_IF</option>
                            <option value="KAPRODI_SI">KAPRODI_SI</option>
                            <option value="KAPRODI_TI">KAPRODI_TI</option>
                            <option value="KAPRODI_MSI">KAPRODI_MSI</option>
                            <option value="SECRETARY">SECRETARY</option>
                            <option value="UPMF">UPMF</option>
                            <option value="RESEARCH_HEAD">RESEARCH_HEAD</option>
                        </select>
                        <select id="posStatusFilter" class="form-select form-select-sm w-auto">
                            <option value="">All History</option>
                            <option value="active">Active</option>
                            <option value="ended">Ended</option>
                        </select>
                    </div>
                    <button type="button" id="btnReplacePosition" class="btn btn-sm btn-primary"><i class="bi bi-arrow-left-right me-1"></i>Replace Holder</button>
                </div>
                <div class="orch-table-wrap">
                    <table class="table orch-table">
                        <thead><tr><th>Role / Program</th><th>Holder</th><th>Previous Holder</th><th>Start Date</th><th>End Date</th><th>Status</th><th>Reason</th></tr></thead>
                        <tbody id="tblPositionsBody"></tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($hasPerm('students.manage') || $isDean || $isKaprodi): ?>
            <section id="section-students" class="orch-section">
                <div class="orch-card mb-3 d-flex flex-wrap justify-content-between gap-2">
                    <div class="d-flex flex-wrap gap-2">
                        <input type="text" id="stuSearch" class="form-control form-control-sm" style="width:200px" placeholder="Search NIM / Name...">
                        <select id="stuProgFilter" class="form-select form-select-sm w-auto">
                            <option value="">All Programs</option>
                            <?php foreach ($progsList as $sp): ?>
                            <option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <select id="stuStatusFilter" class="form-select form-select-sm w-auto">
                            <option value="">All Status</option>
                            <option value="active">Active</option>
                            <option value="probation">Probation</option>
                            <option value="leave">Leave</option>
                            <option value="graduated">Graduated</option>
                        </select>
                    </div>
                    <?php if ($hasPerm('students.manage')): ?>
                    <div class="d-flex gap-2">
                        <button type="button" id="btnImportStudents" class="btn btn-sm btn-outline-success"><i class="bi bi-file-earmark-spreadsheet me-1"></i>Import Excel/CSV</button>
                        <button type="button" id="btnNewStudent" class="btn btn-sm btn-primary"><i class="bi bi-plus-lg me-1"></i>Add Student</button>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="orch-table-wrap">
                    <table class="table orch-table">
                        <thead><tr><th>NIM</th><th>Full Name</th><th>Program</th><th>Semester</th><th>GPA</th><th>SKS</th><th>Status</th><th>Account</th><th>Actions</th></tr></thead>
                        <tbody id="tblStudentsBody"></tbody>
                    </table>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($hasPerm('knowledge.read') || $hasPerm('knowledge.manage') || $hasPerm('tools.manage')): ?>
            <section id="section-knowledge_tools" class="orch-section">
                <ul class="nav nav-tabs nav-tabs-sm mb-3">
                    <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabRagPane" type="button">Documents &amp; KBs</button></li>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabRagTestPane" type="button">RAG Search Test</button></li>
                    <?php if ($hasPerm('tools.manage')): ?>
                    <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabToolsPane" type="button">Tool Registry</button></li>
                    <?php endif; ?>
                </ul>
                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabRagPane">
                        <div class="row g-3 mb-3">
                            <div class="col-lg-5">
                                <div class="orch-card h-100">
                                    <div class="d-flex justify-content-between align-items-center mb-2">
                                        <h6 class="fw-bold mb-0">Knowledge Bases</h6>
                                        <?php if ($hasPerm('knowledge.manage')): ?>
                                        <button type="button" id="btnNewKb" class="btn btn-sm btn-outline-primary py-0">+ KB</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Code</th><th>Name</th><th>Cat</th><th>Docs</th></tr></thead><tbody id="tblKbBody"></tbody></table></div>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <div class="orch-card h-100">
                                    <div class="d-flex flex-wrap justify-content-between gap-2 mb-2">
                                        <div class="d-flex gap-2">
                                            <input type="text" id="ragSearch" class="form-control form-control-sm" placeholder="Filter documents...">
                                            <select id="ragKbFilter" class="form-select form-select-sm w-auto"></select>
                                        </div>
                                        <?php if ($hasPerm('knowledge.manage')): ?>
                                        <button type="button" id="btnNewRagDoc" class="btn btn-sm btn-primary"><i class="bi bi-cloud-upload me-1"></i>Upload Doc</button>
                                        <?php endif; ?>
                                    </div>
                                    <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Code</th><th>Document</th><th>KB</th><th>Type</th><th>Chunks</th><th>Hits</th></tr></thead><tbody id="tblRagDocsBody"></tbody></table></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="tab-pane fade" id="tabRagTestPane">
                        <div class="orch-card">
                            <div class="input-group input-group-sm mb-3">
                                <input type="text" id="ragTestQuery" class="form-control" placeholder="Test role-scoped semantic retrieval query...">
                                <button type="button" id="btnRunRagTest" class="btn btn-primary">Search</button>
                            </div>
                            <div id="ragTestResults"></div>
                        </div>
                    </div>
                    <?php if ($hasPerm('tools.manage')): ?>
                    <div class="tab-pane fade" id="tabToolsPane">
                        <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Code</th><th>Tool</th><th>Category</th><th>Scope</th><th>Status</th><th>Action</th></tr></thead><tbody id="tblToolsBody"></tbody></table></div>
                    </div>
                    <?php endif; ?>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($hasPerm('api_keys.manage')): ?>
            <section id="section-ai_engine" class="orch-section">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <ul class="nav nav-tabs nav-tabs-sm mb-0" id="aiCostTabs">
                        <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabAiCostAgents" type="button"><i class="bi bi-bar-chart-steps me-1"></i>Agent Requests &amp; Cost</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAiKeyVault" type="button"><i class="bi bi-key me-1"></i>API Key Vault</button></li>
                        <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabAiReqLogs" type="button"><i class="bi bi-list-ul me-1"></i>Request Logs</button></li>
                    </ul>
                    <div class="d-flex align-items-center gap-2">
                        <select id="aiCostRange" class="form-select form-select-sm w-auto">
                            <option value="24h">Last 24h</option>
                            <option value="7d">Last 7 Days</option>
                            <option value="30d" selected>Last 30 Days</option>
                            <option value="all">All Time</option>
                        </select>
                        <button type="button" id="btnRefreshAiCost" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>

                <div class="orch-kpi-grid mb-3" id="aiCostKpiRow"></div>

                <div class="orch-card mb-3 py-2 px-3">
                    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 small">
                        <div class="fw-semibold">Monthly Spend Budget (<span id="aiBudgetLabel">$0.00 / $50.00 USD</span>)</div>
                        <form id="aiBudgetForm" class="d-flex align-items-center gap-1">
                            <span class="text-muted">Cap USD:</span>
                            <input type="number" step="0.5" min="1" id="aiBudgetCapInput" class="form-control form-control-sm" style="width:92px" value="50">
                            <button type="submit" class="btn btn-sm btn-outline-primary py-0 px-2">Set</button>
                        </form>
                    </div>
                    <div class="progress mt-2" style="height:8px">
                        <div id="aiBudgetProgressBar" class="progress-bar bg-info" style="width:0%"></div>
                    </div>
                </div>

                <div class="tab-content">
                    <div class="tab-pane fade show active" id="tabAiCostAgents">
                        <div class="row g-3 mb-3">
                            <div class="col-lg-7">
                                <div class="orch-card" style="height:280px">
                                    <h6 class="fw-bold mb-2">Requests per Agent</h6>
                                    <div style="height:220px"><canvas id="aiCostAgentReqChart"></canvas></div>
                                </div>
                            </div>
                            <div class="col-lg-5">
                                <div class="orch-card" style="height:280px">
                                    <h6 class="fw-bold mb-2">Estimated Spend by Agent (USD)</h6>
                                    <div style="height:220px"><canvas id="aiCostSpendChart"></canvas></div>
                                </div>
                            </div>
                        </div>
                        <div class="orch-card">
                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
                                <h6 class="fw-bold mb-0">Per-Agent Request &amp; Expenditure Breakdown</h6>
                                <input type="text" id="aiAgentCostSearch" class="form-control form-control-sm" style="width:210px" placeholder="Filter agent...">
                            </div>
                            <div class="orch-table-wrap">
                                <table class="table orch-table">
                                    <thead><tr><th>Agent</th><th>Zone</th><th>Model</th><th>Requests</th><th>OpenAI Calls</th><th>Prompt / Out Tokens</th><th>Avg ms</th><th>Est. USD</th><th>Est. IDR</th><th>Last Request</th></tr></thead>
                                    <tbody id="tblAgentCostBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabAiKeyVault">
                        <div class="row g-3">
                            <div class="col-lg-5">
                                <div class="orch-card h-100">
                                    <h6 class="fw-bold mb-3">Encrypted AI Key Vault</h6>
                                    <form id="aiKeyForm" class="row g-2">
                                        <div class="col-6">
                                            <select id="aiKeyProvider" class="form-select form-select-sm">
                                                <option value="openai">OpenAI</option>
                                                <option value="gemini">Gemini</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <input type="text" id="aiKeyModel" class="form-control form-control-sm" value="gpt-4.1-mini" placeholder="Model">
                                        </div>
                                        <div class="col-12">
                                            <input type="text" id="aiKeyLabel" class="form-control form-control-sm" placeholder="Key Label (e.g. FILKOM OpenAI Prod)" required>
                                        </div>
                                        <div class="col-12">
                                            <input type="password" id="aiKeySecret" class="form-control form-control-sm" placeholder="sk-..." required>
                                        </div>
                                        <div class="col-12 d-flex justify-content-between align-items-center">
                                            <label class="form-check-label small"><input type="checkbox" id="aiKeyPrimary" class="form-check-input me-1" checked>Set Primary</label>
                                            <button type="submit" class="btn btn-sm btn-primary">Save Key</button>
                                        </div>
                                    </form>
                                    <hr>
                                    <h6 class="fw-bold mb-2">Orchestrator Defaults</h6>
                                    <form id="aiSettingsForm" class="row g-2 small">
                                        <div class="col-6">
                                            <select id="cfgProvider" class="form-select form-select-sm">
                                                <option value="openai" <?php echo ($vaSettings['ai_provider'] ?? '') === 'openai' ? 'selected' : ''; ?>>OpenAI</option>
                                                <option value="gemini" <?php echo ($vaSettings['ai_provider'] ?? '') === 'gemini' ? 'selected' : ''; ?>>Gemini</option>
                                            </select>
                                        </div>
                                        <div class="col-6">
                                            <input type="text" id="cfgOpenAiModel" class="form-control form-control-sm" value="<?php echo $h($vaSettings['openai_model'] ?? 'gpt-4.1-mini'); ?>">
                                        </div>
                                        <div class="col-12 d-flex flex-wrap gap-3 mt-2">
                                            <label><input type="checkbox" id="cfgRagEnabled" class="form-check-input me-1" <?php echo ($vaSettings['rag_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>RAG</label>
                                            <label><input type="checkbox" id="cfgFnEnabled" class="form-check-input me-1" <?php echo ($vaSettings['function_calling_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>Tools</label>
                                            <label><input type="checkbox" id="cfgProfanityEnabled" class="form-check-input me-1" <?php echo ($vaSettings['profanity_filter_enabled'] ?? '1') === '1' ? 'checked' : ''; ?>>Guardrail</label>
                                        </div>
                                        <div class="col-12 text-end">
                                            <button type="submit" class="btn btn-sm btn-outline-primary">Save Defaults</button>
                                        </div>
                                    </form>
                                </div>
                            </div>
                            <div class="col-lg-7">
                                <div class="orch-card h-100">
                                    <h6 class="fw-bold mb-2">Configured API Keys</h6>
                                    <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Provider</th><th>Label</th><th>Masked</th><th>Model</th><th>Requests</th><th>Status</th><th>Actions</th></tr></thead><tbody id="tblAiKeysBody"></tbody></table></div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="tab-pane fade" id="tabAiReqLogs">
                        <div class="orch-card">
                            <h6 class="fw-bold mb-2">Recent Agent Request &amp; Token Logs</h6>
                            <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Time</th><th>Agent</th><th>User</th><th>Model</th><th>Tokens</th><th>Est. Cost</th><th>Latency</th><th>Status</th></tr></thead><tbody id="tblApiUsageBody"></tbody></table></div>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($hasPerm('monitoring.view') || $hasPerm('api_keys.manage')): ?>
            <section id="section-agent_charts" class="orch-section">
                <div class="orch-card mb-3 py-2 px-3 d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div class="d-flex align-items-center gap-2 flex-wrap">
                        <button type="button" id="btnToggleLiveCharts" class="btn btn-sm btn-success rounded-pill px-3">
                            <span class="orch-dot pulse me-1"></span><span id="liveChartsBtnLbl">LIVE 5s ON</span>
                        </button>
                        <select id="liveChartsRange" class="form-select form-select-sm w-auto">
                            <option value="1h">Last 1 Hour</option>
                            <option value="24h" selected>Last 24 Hours</option>
                            <option value="7d">Last 7 Days</option>
                            <option value="30d">Last 30 Days</option>
                            <option value="all">All Time</option>
                        </select>
                        <select id="liveChartsZone" class="form-select form-select-sm w-auto">
                            <option value="">All Faculty Zones</option>
                            <option value="DEAN">DEAN</option>
                            <option value="KAPRODI">KAPRODI</option>
                            <option value="SECRETARY">SECRETARY</option>
                            <option value="UPMF">UPMF</option>
                            <option value="RESEARCH_HEAD">RESEARCH_HEAD</option>
                            <option value="STUDENT">STUDENT</option>
                            <option value="COMMAND_CENTER">COMMAND_CENTER</option>
                        </select>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <small class="text-muted" id="liveChartsUpdatedAt">Syncing...</small>
                        <button type="button" id="btnRefreshLiveCharts" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise"></i></button>
                    </div>
                </div>

                <div class="orch-kpi-grid mb-3" id="liveChartsKpiRow"></div>

                <div class="row g-3 mb-3">
                    <div class="col-lg-8">
                        <div class="orch-card" style="height:290px">
                            <h6 class="fw-bold mb-2">Real-Time Requests &amp; Token Activity</h6>
                            <div style="height:230px"><canvas id="liveChartTimeline"></canvas></div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="orch-card" style="height:290px">
                            <h6 class="fw-bold mb-2">Faculty Zone Workload Share</h6>
                            <div style="height:230px"><canvas id="liveChartZones"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3 mb-3">
                    <div class="col-lg-6">
                        <div class="orch-card" style="height:310px">
                            <h6 class="fw-bold mb-2">Requests per Agent (Live Ranking)</h6>
                            <div style="height:250px"><canvas id="liveChartByAgent"></canvas></div>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="orch-card" style="height:310px">
                            <h6 class="fw-bold mb-2">Avg Latency (ms) &amp; Token Load per Agent</h6>
                            <div style="height:250px"><canvas id="liveChartLatency"></canvas></div>
                        </div>
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-lg-8">
                        <div class="orch-card">
                            <h6 class="fw-bold mb-2">Live Per-Agent Telemetry Matrix</h6>
                            <div class="orch-table-wrap">
                                <table class="table orch-table">
                                    <thead><tr><th>Agent</th><th>Zone</th><th>State</th><th>Requests</th><th>Avg ms</th><th>Tokens</th><th>Est. USD</th><th>Last Seen</th></tr></thead>
                                    <tbody id="tblLiveAgentsBody"></tbody>
                                </table>
                            </div>
                        </div>
                    </div>
                    <div class="col-lg-4">
                        <div class="orch-card" style="max-height:380px;overflow-y:auto">
                            <h6 class="fw-bold mb-2">Live SSE Event Stream</h6>
                            <div id="liveChartsEventFeed"></div>
                        </div>
                    </div>
                </div>
            </section>
            <?php endif; ?>

            <?php if ($hasPerm('monitoring.view') || $hasPerm('audit.view')): ?>
            <section id="section-monitoring" class="orch-section">
                <div class="d-flex justify-content-end mb-2">
                    <button type="button" id="btnRefreshMon" class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-clockwise me-1"></i>Refresh</button>
                </div>
                <div id="monKpiRow"></div>
                <div class="row g-3 mb-3">
                    <div class="col-lg-4"><div class="orch-card" style="height:260px"><h6 class="fw-bold mb-2">Task Status</h6><div style="height:200px"><canvas id="monTaskChart"></canvas></div></div></div>
                    <div class="col-lg-4"><div class="orch-card" style="height:260px"><h6 class="fw-bold mb-2">Agent Fleet</h6><div style="height:200px"><canvas id="monTokenChart"></canvas></div></div></div>
                    <div class="col-lg-4"><div class="orch-card" style="height:260px;overflow-y:auto"><h6 class="fw-bold mb-2">Live Activity Timeline</h6><div id="monTimelineList"></div></div></div>
                </div>
                <?php if ($hasPerm('audit.view')): ?>
                <div class="row g-3">
                    <div class="col-lg-7"><div class="orch-card"><h6 class="fw-bold mb-2">Audit Log</h6><div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Time</th><th>Actor</th><th>Action</th><th>Details</th></tr></thead><tbody id="tblAuditLogs"></tbody></table></div></div></div>
                    <div class="col-lg-5"><div class="orch-card"><h6 class="fw-bold mb-2">Security Events</h6><div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Time</th><th>Vector</th><th>IP</th><th>Action</th></tr></thead><tbody id="tblSecurityLogs"></tbody></table></div></div></div>
                </div>
                <?php endif; ?>
            </section>
            <?php endif; ?>

            <section id="section-profile" class="orch-section">
                <div class="row g-3">
                    <div class="col-lg-6">
                        <div class="orch-card">
                            <h6 class="fw-bold mb-3">Account Profile</h6>
                            <form id="profileForm" class="row g-2">
                                <div class="col-12"><label class="form-label small mb-0">Full Name</label><input type="text" id="profFullName" class="form-control form-control-sm" value="<?php echo $h($user['full_name'] ?? ''); ?>" required></div>
                                <div class="col-6"><label class="form-label small mb-0">Email</label><input type="email" id="profEmail" class="form-control form-control-sm" value="<?php echo $h($user['email'] ?? ''); ?>" required></div>
                                <div class="col-6"><label class="form-label small mb-0">Phone</label><input type="text" id="profPhone" class="form-control form-control-sm" value="<?php echo $h($user['phone'] ?? ''); ?>"></div>
                                <div class="col-6"><label class="form-label small mb-0">Department</label><input type="text" id="profDept" class="form-control form-control-sm" value="<?php echo $h($user['department'] ?? ''); ?>"></div>
                                <div class="col-6"><label class="form-label small mb-0">New Password (optional)</label><input type="password" id="profPassword" class="form-control form-control-sm" placeholder="Min 6 chars"></div>
                                <div class="col-12 text-end mt-2"><button type="submit" class="btn btn-sm btn-primary">Save Profile</button></div>
                            </form>
                        </div>
                    </div>
                    <div class="col-lg-6">
                        <div class="orch-card">
                            <div class="d-flex justify-content-between align-items-center mb-3">
                                <h6 class="fw-bold mb-0">Multi-Factor Authentication (TOTP)</h6>
                                <span id="mfaStatusBadge" class="orch-badge <?php echo !empty($user['mfa_enabled']) ? 'orch-badge-ok' : 'orch-badge-muted'; ?>">
                                    <?php echo !empty($user['mfa_enabled']) ? 'MFA Enabled' : 'MFA Disabled'; ?>
                                </span>
                            </div>
                            <div class="d-flex gap-2 mb-3">
                                <button type="button" id="btnSetupMfa" class="btn btn-sm btn-outline-primary">Provision TOTP Secret</button>
                                <button type="button" id="btnDisableMfa" class="btn btn-sm btn-outline-danger">Disable MFA</button>
                            </div>
                            <div id="mfaSetupBox" class="d-none border rounded p-3 bg-light small">
                                <div>Secret Key: <code id="mfaSecretDisplay" class="fw-bold"></code></div>
                                <input type="text" id="mfaUriDisplay" class="form-control form-control-sm my-2" readonly>
                                <div class="input-group input-group-sm">
                                    <input type="text" id="mfaConfirmCode" class="form-control" placeholder="Enter 6-digit OTP code">
                                    <button type="button" id="btnEnableMfa" class="btn btn-success">Verify &amp; Enable</button>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </section>
        </main>
    </div>
</div>

<div class="modal fade" id="modalUser" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" id="userForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">User Account</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" id="usrId" value="0">
        <div class="col-md-4"><label class="form-label small mb-0">Username</label><input type="text" id="usrUsername" class="form-control form-control-sm" required></div>
        <div class="col-md-4"><label class="form-label small mb-0">Email</label><input type="email" id="usrEmail" class="form-control form-control-sm" required></div>
        <div class="col-md-4"><label class="form-label small mb-0">Full Name</label><input type="text" id="usrFullName" class="form-control form-control-sm" required></div>
        <div class="col-md-4"><label class="form-label small mb-0">Department</label><input type="text" id="usrDept" class="form-control form-control-sm" value="Fakultas Ilmu Komputer"></div>
        <div class="col-md-4"><label class="form-label small mb-0">Phone</label><input type="text" id="usrPhone" class="form-control form-control-sm"></div>
        <div class="col-md-4"><label class="form-label small mb-0">Study Program</label><select id="usrProg" class="form-select form-select-sm"><option value="">Faculty-Wide</option><?php foreach ($progsList as $sp): ?><option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code']); ?></option><?php endforeach; ?></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">Status</label><select id="usrStatus" class="form-select form-select-sm"><option value="active">Active</option><option value="inactive">Inactive</option><option value="suspended">Suspended</option></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">Password</label><input type="password" id="usrPassword" class="form-control form-control-sm" placeholder="Min 6 chars (blank keeps current)"></div>
        <div class="col-12 mt-2"><div class="small fw-semibold mb-1">Assigned Roles</div><div id="usrRoleBoxes" class="d-flex flex-wrap gap-2">
            <?php foreach ($rolesCatalog as $rc): ?>
            <label class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" value="<?php echo $h($rc['role_code']); ?>"><span class="form-check-label"><?php echo $h($rc['role_code']); ?></span></label>
            <?php endforeach; ?>
        </div></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save User</button></div>
</form></div></div>

<div class="modal fade" id="modalImportExcel" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="importExcelForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold" id="importModalTitle">Import Excel / CSV</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="file" id="importFile" class="form-control form-control-sm" accept=".xlsx,.csv" required>
        <div class="small text-muted mt-2">Supported headers: username, email, full_name, role_code, program_code / nim, cohort_year, gpa.</div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-success">Import</button></div>
</form></div></div>

<div class="modal fade" id="modalReplacePosition" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="replacePosForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Replace Leadership Position</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <div class="col-12"><label class="form-label small mb-0">Position Role</label><select id="posRoleCode" class="form-select form-select-sm"><option value="DEAN">DEAN</option><option value="KAPRODI_IF">KAPRODI_IF</option><option value="KAPRODI_SI">KAPRODI_SI</option><option value="KAPRODI_TI">KAPRODI_TI</option><option value="KAPRODI_MSI">KAPRODI_MSI</option><option value="SECRETARY">SECRETARY</option><option value="UPMF">UPMF</option><option value="RESEARCH_HEAD">RESEARCH_HEAD</option></select></div>
        <div class="col-12"><label class="form-label small mb-0">New Holder Account</label><select id="posNewUser" class="form-select form-select-sm" required></select></div>
        <div class="col-12"><label class="form-label small mb-0">Effective Date</label><input type="text" id="posEffectiveAt" class="form-control form-control-sm" value="<?php echo date('Y-m-d'); ?>"></div>
        <div class="col-12"><label class="form-label small mb-0">Decree / Reason</label><input type="text" id="posReason" class="form-control form-control-sm" required placeholder="SK Rektor Pelantikan"></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Confirm Transition</button></div>
</form></div></div>

<div class="modal fade" id="modalStudent" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="studentForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Student Record</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" id="stuId" value="0">
        <div class="col-6"><label class="form-label small mb-0">NIM</label><input type="text" id="stuNim" class="form-control form-control-sm" required></div>
        <div class="col-6"><label class="form-label small mb-0">Program</label><select id="stuProg" class="form-select form-select-sm"><?php foreach ($progsList as $sp): ?><option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code']); ?></option><?php endforeach; ?></select></div>
        <div class="col-12"><label class="form-label small mb-0">Full Name</label><input type="text" id="stuName" class="form-control form-control-sm" required></div>
        <div class="col-3"><label class="form-label small mb-0">Cohort</label><input type="number" id="stuCohort" class="form-control form-control-sm" value="2024"></div>
        <div class="col-3"><label class="form-label small mb-0">Sem</label><input type="number" id="stuSem" class="form-control form-control-sm" value="4"></div>
        <div class="col-3"><label class="form-label small mb-0">GPA</label><input type="text" id="stuGpa" class="form-control form-control-sm" value="3.50"></div>
        <div class="col-3"><label class="form-label small mb-0">Credits</label><input type="number" id="stuCredits" class="form-control form-control-sm" value="84"></div>
        <div class="col-6"><label class="form-label small mb-0">Status</label><select id="stuStatus" class="form-select form-select-sm"><option value="active">Active</option><option value="probation">Probation</option><option value="leave">Leave</option><option value="graduated">Graduated</option><option value="inactive">Inactive</option></select></div>
        <div class="col-6 d-flex align-items-end"><label class="form-check-label small"><input type="checkbox" id="stuCreateLogin" class="form-check-input me-1">Auto-create login</label></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Student</button></div>
</form></div></div>

<div class="modal fade" id="modalMapStudent" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="mapStudentForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Map Student Login Account</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" id="mapStuId" value="0">
        <select id="mapStuUserId" class="form-select form-select-sm"></select>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Mapping</button></div>
</form></div></div>

<div class="modal fade" id="modalAgent" tabindex="-1"><div class="modal-dialog modal-xl"><form class="modal-content" id="agentForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Agent Configuration</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" id="agId" value="0">
        <div class="col-md-3"><label class="form-label small mb-0">Agent Code</label><input type="text" id="agCode" class="form-control form-control-sm" required></div>
        <div class="col-md-5"><label class="form-label small mb-0">Agent Name</label><input type="text" id="agName" class="form-control form-control-sm" required></div>
        <div class="col-md-2"><label class="form-label small mb-0">Category</label><select id="agCategory" class="form-select form-select-sm"><option value="DEAN">DEAN</option><option value="KAPRODI">KAPRODI</option><option value="SECRETARY">SECRETARY</option><option value="UPMF">UPMF</option><option value="RESEARCH_HEAD">RESEARCH_HEAD</option><option value="STUDENT">STUDENT</option><option value="CUSTOM">CUSTOM</option></select></div>
        <div class="col-md-2"><label class="form-label small mb-0">Zone</label><select id="agZone" class="form-select form-select-sm"><option value="COMMAND_CENTER">COMMAND_CENTER</option><option value="DEAN">DEAN</option><option value="KAPRODI">KAPRODI</option><option value="SECRETARY">SECRETARY</option><option value="UPMF">UPMF</option><option value="RESEARCH_HEAD">RESEARCH_HEAD</option><option value="STUDENT">STUDENT</option></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">Description</label><input type="text" id="agDesc" class="form-control form-control-sm"></div>
        <div class="col-md-2"><label class="form-label small mb-0">OpenAI Model</label><select id="agModel" class="form-select form-select-sm"><option value="gpt-4.1-mini">gpt-4.1-mini</option><option value="gpt-4.1">gpt-4.1</option><option value="gpt-4o-mini">gpt-4o-mini</option><option value="gpt-4o">gpt-4o</option><option value="o4-mini">o4-mini</option></select></div>
        <div class="col-md-2"><label class="form-label small mb-0">Temperature</label><input type="text" id="agTemp" class="form-control form-control-sm" value="0.40"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Max Tokens</label><input type="number" id="agMaxTokens" class="form-control form-control-sm" value="900"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Avatar Profile</label><input type="text" id="agAvatarModel" class="form-control form-control-sm" value="chibi_orchestrator"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Animation Profile</label><input type="text" id="agAnimProfile" class="form-control form-control-sm" value="executive_briefing"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Priority</label><input type="number" id="agPriority" class="form-control form-control-sm" value="60"></div>
        <div class="col-md-2"><label class="form-label small mb-0">Status</label><select id="agStatus" class="form-select form-select-sm"><option value="active">Active</option><option value="inactive">Inactive</option></select></div>
        <div class="col-md-2"><label class="form-label small mb-0">Change Note</label><input type="text" id="agChangeNote" class="form-control form-control-sm" placeholder="Optional"></div>
        <div class="col-12"><label class="form-label small mb-0">System Prompt</label><textarea id="agPrompt" class="form-control form-control-sm" rows="4" required></textarea></div>
        <div class="col-md-6"><div class="small fw-semibold mb-1">Role Access &amp; Data Scope</div><div id="agentRolesConfig" class="border rounded p-2" style="max-height:190px;overflow-y:auto"></div></div>
        <div class="col-md-6">
            <div class="small fw-semibold mb-1">Authorized Tools</div><div id="agentToolsConfig" class="border rounded p-2 mb-2" style="max-height:92px;overflow-y:auto"></div>
            <div class="small fw-semibold mb-1">Knowledge Bases</div><div id="agentKbConfig" class="border rounded p-2" style="max-height:85px;overflow-y:auto"></div>
        </div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Version</button></div>
</form></div></div>

<div class="modal fade" id="modalAiAgentBuilder" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">AI-Assisted Agent Builder</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <textarea id="aiBuilderPrompt" class="form-control form-control-sm mb-2" rows="3" placeholder="Describe the agent purpose, target role, and FILKOM scope..."></textarea>
        <div class="text-end mb-2"><button type="button" id="btnGenerateAiAgent" class="btn btn-sm btn-info text-white"><i class="bi bi-stars me-1"></i>Generate Proposed Config</button></div>
        <div id="aiBuilderPreview"></div>
    </div>
    <div class="modal-footer py-2"><button type="button" id="btnSaveAiDraft" class="btn btn-sm btn-warning d-none">Save as Pending Approval</button></div>
</div></div></div>

<div class="modal fade" id="modalAgentVersions" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Versions, Rollback &amp; Sandbox Test</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
        <input type="hidden" id="sbxAgentId" value="0">
        <div class="small fw-semibold mb-1">Sandbox Test</div>
        <div class="input-group input-group-sm mb-2">
            <input type="text" id="sbxPrompt" class="form-control" placeholder="Enter test query for this agent...">
            <button type="button" id="btnRunSandboxTest" class="btn btn-primary">Run Sandbox</button>
        </div>
        <div id="sbxOutput" class="border rounded p-2 bg-light small mb-3">Ready to test.</div>
        <div class="small fw-semibold mb-1">Version Snapshots</div>
        <div id="agentVersionsList"></div>
    </div>
</div></div></div>

<div class="modal fade" id="modalKb" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="kbForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Knowledge Base</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" id="kbId" value="0">
        <div class="col-4"><input type="text" id="kbCode" class="form-control form-control-sm" placeholder="Code" required></div>
        <div class="col-8"><input type="text" id="kbName" class="form-control form-control-sm" placeholder="Knowledge Base Name" required></div>
        <div class="col-6"><input type="text" id="kbCategory" class="form-control form-control-sm" value="Academic"></div>
        <div class="col-6"><input type="text" id="kbDesc" class="form-control form-control-sm" placeholder="Description"></div>
        <div class="col-12 mt-2"><div class="small fw-semibold mb-1">Role Access</div><div id="kbRoleBoxes" class="d-flex flex-wrap gap-2">
            <?php foreach ($rolesCatalog as $rc): ?>
            <label class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" value="<?php echo (int)$rc['id']; ?>" checked><span class="form-check-label"><?php echo $h($rc['role_code']); ?></span></label>
            <?php endforeach; ?>
        </div></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save KB</button></div>
</form></div></div>

<div class="modal fade" id="modalRagDoc" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" id="ragDocForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Upload &amp; Index RAG Document</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" id="ragDocId" value="0">
        <div class="col-6"><select id="ragDocKbId" class="form-select form-select-sm"></select></div>
        <div class="col-6"><input type="text" id="ragDocCat" class="form-control form-control-sm" value="Academic"></div>
        <div class="col-12"><input type="text" id="ragDocTitle" class="form-control form-control-sm" placeholder="Document Title" required></div>
        <div class="col-9"><input type="file" id="ragDocFile" class="form-control form-control-sm" accept=".pdf,.docx,.xlsx,.txt"></div>
        <div class="col-3"><button type="button" id="btnPreviewRagFile" class="btn btn-sm btn-outline-info w-100">Extract Preview</button></div>
        <div class="col-12"><small id="ragExtractInfo" class="text-success"></small></div>
        <div class="col-12"><textarea id="ragDocText" class="form-control form-control-sm" rows="6" placeholder="Extracted or pasted content..."></textarea></div>
        <div class="col-12"><div class="small fw-semibold mb-1">Authorized Roles</div><div id="ragDocRoleBoxes" class="d-flex flex-wrap gap-2">
            <?php foreach ($rolesCatalog as $rc): ?>
            <label class="form-check form-check-inline small"><input class="form-check-input" type="checkbox" value="<?php echo (int)$rc['id']; ?>" checked><span class="form-check-label"><?php echo $h($rc['role_code']); ?></span></label>
            <?php endforeach; ?>
        </div></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Index Document</button></div>
</form></div></div>

<div class="modal fade" id="modalStudyProgram" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="studyProgramForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">Study Program</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <input type="hidden" id="spId" value="0">
        <div class="col-4"><input type="text" id="spCode" class="form-control form-control-sm" placeholder="Code (IF)" required></div>
        <div class="col-8"><input type="text" id="spName" class="form-control form-control-sm" placeholder="Program Name" required></div>
        <div class="col-4"><select id="spDegree" class="form-select form-select-sm"><option value="S1">S1</option><option value="S2">S2</option><option value="D3">D3</option></select></div>
        <div class="col-8"><input type="text" id="spAccred" class="form-control form-control-sm" value="Baik Sekali"></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Program</button></div>
</form></div></div>

<div class="modal fade" id="modalUserActivity" tabindex="-1"><div class="modal-dialog"><div class="modal-content">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold">User Activity</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="userActivityBody"></div>
</div></div></div>

<div class="modal fade" id="modalCourseSchedule" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" id="courseScheduleForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold"><i class="bi bi-calendar-plus me-1 text-primary"></i>Add Course &amp; Teaching Schedule</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <div class="col-md-4"><label class="form-label small mb-0">Course Code</label><input type="text" id="csCourseCode" class="form-control form-control-sm" placeholder="IF405" required></div>
        <div class="col-md-8"><label class="form-label small mb-0">Course Name</label><input type="text" id="csCourseName" class="form-control form-control-sm" placeholder="Reinforcement Learning" required></div>
        <div class="col-md-5"><label class="form-label small mb-0">Study Program</label><select id="csProgId" class="form-select form-select-sm" required>
            <?php foreach ($progsList as $sp): ?>
            <option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code'] . ' — ' . $sp['program_name']); ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="col-md-2"><label class="form-label small mb-0">SKS</label><input type="number" id="csCredits" class="form-control form-control-sm" min="1" max="6" value="3" required></div>
        <div class="col-md-2"><label class="form-label small mb-0">Semester</label><input type="number" id="csSemester" class="form-control form-control-sm" min="1" max="8" value="5"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Academic Term</label><input type="text" id="csSemLabel" class="form-control form-control-sm" value="2026_2027_1"></div>
        <div class="col-md-5"><label class="form-label small mb-0">Lecturer</label><select id="csLecturerId" class="form-select form-select-sm" required></select></div>
        <div class="col-md-3"><label class="form-label small mb-0">Day</label><select id="csDay" class="form-select form-select-sm">
            <option value="Monday">Monday</option><option value="Tuesday">Tuesday</option><option value="Wednesday">Wednesday</option>
            <option value="Thursday">Thursday</option><option value="Friday">Friday</option><option value="Saturday">Saturday</option>
        </select></div>
        <div class="col-md-2"><label class="form-label small mb-0">Start</label><input type="time" id="csStart" class="form-control form-control-sm" value="08:00" required></div>
        <div class="col-md-2"><label class="form-label small mb-0">End</label><input type="time" id="csEnd" class="form-control form-control-sm" value="10:30" required></div>
        <div class="col-md-6"><label class="form-label small mb-0">Room</label><input type="text" id="csRoom" class="form-control form-control-sm" placeholder="Lab AI-01 / GA-204" required></div>
        <div class="col-md-3"><label class="form-label small mb-0">Capacity</label><input type="number" id="csCapacity" class="form-control form-control-sm" min="10" max="200" value="40"></div>
        <div class="col-md-3"><label class="form-label small mb-0">Curriculum Year</label><input type="number" id="csCurrYear" class="form-control form-control-sm" value="2026"></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Course &amp; Schedule</button></div>
</form></div></div>

<div class="modal fade" id="modalLecturer" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="lecturerForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold"><i class="bi bi-person-plus me-1 text-primary"></i>Add Lecturer</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <div class="col-5"><label class="form-label small mb-0">NIDN</label><input type="text" id="lectNidn" class="form-control form-control-sm" placeholder="0912058801" required></div>
        <div class="col-7"><label class="form-label small mb-0">Full Name (No Degrees)</label><input type="text" id="lectName" class="form-control form-control-sm" placeholder="Full name without titles" required></div>
        <div class="col-6"><label class="form-label small mb-0">Study Program</label><select id="lectProgId" class="form-select form-select-sm" required>
            <?php foreach ($progsList as $sp): ?>
            <option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code'] . ' — ' . $sp['program_name']); ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="col-6"><label class="form-label small mb-0">Academic Rank</label><select id="lectRank" class="form-select form-select-sm">
            <option value="Asisten Ahli">Asisten Ahli</option><option value="Lektor" selected>Lektor</option>
            <option value="Lektor Kepala">Lektor Kepala</option><option value="Guru Besar">Guru Besar</option>
        </select></div>
        <div class="col-12"><label class="form-label small mb-0">Expertise</label><input type="text" id="lectExpertise" class="form-control form-control-sm" placeholder="Machine Learning, Cloud Architecture" required></div>
        <div class="col-6"><label class="form-label small mb-0">Teaching Score (0-5)</label><input type="number" step="0.05" min="0" max="5" id="lectTeach" class="form-control form-control-sm" value="4.60"></div>
        <div class="col-6"><label class="form-label small mb-0">Research Score (0-5)</label><input type="number" step="0.05" min="0" max="5" id="lectRes" class="form-control form-control-sm" value="4.40"></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Lecturer</button></div>
</form></div></div>

<div class="modal fade" id="modalResearchProj" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" id="researchProjForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold"><i class="bi bi-flask me-1 text-primary"></i>Add Research Project</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <div class="col-md-4"><label class="form-label small mb-0">Project Code</label><input type="text" id="rpCode" class="form-control form-control-sm" placeholder="RES-2026-09" required></div>
        <div class="col-md-8"><label class="form-label small mb-0">Project Title</label><input type="text" id="rpTitle" class="form-control form-control-sm" required></div>
        <div class="col-md-6"><label class="form-label small mb-0">Lead Lecturer</label><select id="rpLeadId" class="form-select form-select-sm" required></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">Study Program</label><select id="rpProgId" class="form-select form-select-sm" required>
            <?php foreach ($progsList as $sp): ?>
            <option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code'] . ' — ' . $sp['program_name']); ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="col-md-5"><label class="form-label small mb-0">Funding Source</label><input type="text" id="rpSource" class="form-control form-control-sm" placeholder="DRTPM / Internal Grant" required></div>
        <div class="col-md-3"><label class="form-label small mb-0">Funding (IDR)</label><input type="number" min="0" step="100000" id="rpAmount" class="form-control form-control-sm" value="50000000" required></div>
        <div class="col-md-2"><label class="form-label small mb-0">Year</label><input type="number" id="rpYear" class="form-control form-control-sm" value="2026" required></div>
        <div class="col-md-2"><label class="form-label small mb-0">Status</label><select id="rpStatus" class="form-select form-select-sm">
            <option value="proposed">Proposed</option><option value="active" selected>Active</option><option value="completed">Completed</option>
        </select></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Research Project</button></div>
</form></div></div>

<div class="modal fade" id="modalPublication" tabindex="-1"><div class="modal-dialog modal-lg"><form class="modal-content" id="publicationForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold"><i class="bi bi-journal-plus me-1 text-primary"></i>Add Publication</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <div class="col-md-6"><label class="form-label small mb-0">Author (Lecturer)</label><select id="pubLectId" class="form-select form-select-sm" required></select></div>
        <div class="col-md-6"><label class="form-label small mb-0">Index Tier</label><select id="pubTier" class="form-select form-select-sm">
            <option value="Scopus Q1">Scopus Q1</option><option value="Scopus Q2">Scopus Q2</option><option value="Scopus Q3">Scopus Q3</option>
            <option value="SINTA 1">SINTA 1</option><option value="SINTA 2" selected>SINTA 2</option><option value="IEEE Conference">IEEE Conference</option>
        </select></div>
        <div class="col-12"><label class="form-label small mb-0">Publication Title</label><input type="text" id="pubTitle" class="form-control form-control-sm" required></div>
        <div class="col-md-6"><label class="form-label small mb-0">Journal / Conference Venue</label><input type="text" id="pubVenue" class="form-control form-control-sm" required></div>
        <div class="col-md-3"><label class="form-label small mb-0">Year</label><input type="number" id="pubYear" class="form-control form-control-sm" value="2026" required></div>
        <div class="col-md-3"><label class="form-label small mb-0">Citations</label><input type="number" min="0" id="pubCitations" class="form-control form-control-sm" value="0"></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Publication</button></div>
</form></div></div>

<div class="modal fade" id="modalQualityInd" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="qualityIndForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold"><i class="bi bi-shield-plus me-1 text-primary"></i>Add Accreditation Indicator</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <div class="col-4"><label class="form-label small mb-0">Code</label><input type="text" id="qiCode" class="form-control form-control-sm" placeholder="KPI-09" required></div>
        <div class="col-8"><label class="form-label small mb-0">Program Scope</label><select id="qiProgId" class="form-select form-select-sm">
            <option value="">Faculty-Wide (FILKOM)</option>
            <?php foreach ($progsList as $sp): ?>
            <option value="<?php echo (int)$sp['id']; ?>"><?php echo $h($sp['program_code'] . ' — ' . $sp['program_name']); ?></option>
            <?php endforeach; ?>
        </select></div>
        <div class="col-12"><label class="form-label small mb-0">Standard / Indicator Name</label><input type="text" id="qiStandard" class="form-control form-control-sm" required></div>
        <div class="col-4"><label class="form-label small mb-0">Target</label><input type="number" step="0.01" id="qiTarget" class="form-control form-control-sm" value="90.00" required></div>
        <div class="col-4"><label class="form-label small mb-0">Actual</label><input type="number" step="0.01" id="qiActual" class="form-control form-control-sm" value="92.50" required></div>
        <div class="col-4"><label class="form-label small mb-0">Compliance</label><select id="qiStatus" class="form-select form-select-sm">
            <option value="met">Met</option><option value="watch">Watch</option><option value="critical">Critical</option>
        </select></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Indicator</button></div>
</form></div></div>

<div class="modal fade" id="modalMeeting" tabindex="-1"><div class="modal-dialog"><form class="modal-content" id="meetingForm">
    <div class="modal-header py-2"><h6 class="modal-title fw-bold"><i class="bi bi-calendar-event me-1 text-primary"></i>Schedule Faculty Meeting</h6><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body row g-2">
        <div class="col-12"><label class="form-label small mb-0">Meeting Title</label><input type="text" id="mtTitle" class="form-control form-control-sm" required></div>
        <div class="col-6"><label class="form-label small mb-0">Date &amp; Time</label><input type="datetime-local" id="mtDate" class="form-control form-control-sm" required></div>
        <div class="col-6"><label class="form-label small mb-0">Status</label><select id="mtStatus" class="form-select form-select-sm">
            <option value="scheduled">Scheduled</option><option value="completed">Completed</option><option value="cancelled">Cancelled</option>
        </select></div>
        <div class="col-6"><label class="form-label small mb-0">Location</label><input type="text" id="mtLocation" class="form-control form-control-sm" value="FILKOM Conference Room" required></div>
        <div class="col-6"><label class="form-label small mb-0">Chairperson</label><input type="text" id="mtChair" class="form-control form-control-sm" placeholder="Michael Carter" required></div>
        <div class="col-12"><label class="form-label small mb-0">Agenda Summary</label><textarea id="mtAgenda" class="form-control form-control-sm" rows="2"></textarea></div>
    </div>
    <div class="modal-footer py-2"><button type="submit" class="btn btn-sm btn-primary">Save Meeting</button></div>
</form></div></div>

<div class="orch-toast-box" id="orchToastBox"></div>

<script src="<?php echo APP_PATH; ?>/public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js?v=<?php echo $v; ?>" defer></script>
<script src="<?php echo APP_PATH; ?>/public/assets/vendor/chartjs/chart.umd.min.js?v=<?php echo $v; ?>" defer></script>
<script src="<?php echo APP_PATH; ?>/public/assets/vendor/three/three.min.js?v=<?php echo $v; ?>"></script>
<script>window.FILKOM_ORCH_BOOT = <?php echo $bootJson ?: '{}'; ?>;</script>
<script type="module" src="<?php echo APP_PATH; ?>/public/assets/js/imitation/OrchestratorPortalApp.js?v=<?php echo $v; ?>"></script>
</body>
</html>
