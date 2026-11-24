<?php
$viewData = isset($data) && is_array($data) ? $data : [];
$pageTitle = !empty($viewData['title']) ? trim((string) $viewData['title']) : 'Sign In — FILKOM AI Agent Orchestrator';
$csrfVal = (string) ($viewData['csrf_token'] ?? ($csrf_token ?? ($csrfToken ?? '')));
$alertMsg = '';
$alertType = 'info';
if (!empty($viewData['error_message'])) {
    $alertMsg = (string) $viewData['error_message'];
    $alertType = 'danger';
} elseif (!empty($viewData['success_message'])) {
    $alertMsg = (string) $viewData['success_message'];
    $alertType = 'success';
} elseif (isset($_GET['timeout'])) {
    $alertMsg = 'Your session expired due to inactivity. Please sign in again.';
    $alertType = 'warning';
} elseif (isset($_GET['revoked'])) {
    $alertMsg = 'Your session has been revoked by an administrator.';
    $alertType = 'danger';
} elseif (isset($_GET['auth'])) {
    $alertMsg = 'Please sign in to access the FILKOM AI Agent Orchestrator.';
    $alertType = 'warning';
} elseif (isset($_GET['logout'])) {
    $alertMsg = 'You have signed out safely.';
    $alertType = 'success';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="stylesheet" href="<?= APP_PATH ?>/public/assets/vendor/bootstrap/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= APP_PATH ?>/public/assets/vendor/bootstrap-icons/bootstrap-icons.min.css">
    <style>
        :root {
            --fo-navy: #0f172a;
            --fo-blue: #2563eb;
            --fo-cyan: #38bdf8;
            --fo-ink: #f8fafc;
            --fo-muted: rgba(203, 213, 225, 0.78);
            --fo-card: rgba(15, 23, 42, 0.84);
            --fo-border: rgba(56, 189, 248, 0.24);
        }
        * { box-sizing: border-box; }
        html, body {
            height: 100%;
            margin: 0;
            font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
            color: var(--fo-ink);
            background-color: #060d1f;
            overflow-x: hidden;
        }
        .fo-backdrop {
            position: fixed;
            inset: 0;
            background:
                radial-gradient(circle at 20% 20%, rgba(56, 189, 248, 0.22) 0%, transparent 45%),
                radial-gradient(circle at 80% 80%, rgba(37, 99, 235, 0.28) 0%, transparent 48%),
                linear-gradient(145deg, rgba(6, 13, 31, 0.56) 0%, rgba(15, 23, 42, 0.50) 50%, rgba(12, 36, 71, 0.68) 100%),
                url('<?= APP_PATH ?>/public/assets/img/bg.jpg') center center / cover no-repeat;
            z-index: 0;
        }
        .fo-shell {
            position: relative;
            z-index: 1;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 24px 16px;
        }
        .fo-card {
            width: 100%;
            max-width: 420px;
            background: var(--fo-card);
            backdrop-filter: blur(22px);
            -webkit-backdrop-filter: blur(22px);
            border: 1px solid var(--fo-border);
            border-top: 3px solid var(--fo-cyan);
            border-radius: 18px;
            padding: 30px 28px 24px;
            box-shadow: 0 28px 64px -18px rgba(2, 6, 23, 0.85);
        }
        .fo-brand {
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 20px;
        }
        .fo-emblem {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: linear-gradient(135deg, #38bdf8 0%, #2563eb 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            color: #ffffff;
            flex-shrink: 0;
        }
        .fo-title {
            margin: 0;
            font-size: 1.12rem;
            font-weight: 700;
            color: #ffffff;
            letter-spacing: -0.015em;
        }
        .fo-sub {
            margin: 2px 0 0;
            font-size: 0.76rem;
            color: var(--fo-muted);
        }
        .fo-alert {
            border-radius: 10px;
            padding: 9px 12px;
            font-size: 0.8rem;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 8px;
            border: 1px solid transparent;
        }
        .fo-alert.is-danger { background: rgba(239, 68, 68, 0.16); border-color: rgba(248, 113, 113, 0.35); color: #fecaca; }
        .fo-alert.is-warning { background: rgba(245, 158, 11, 0.16); border-color: rgba(251, 191, 36, 0.35); color: #fde68a; }
        .fo-alert.is-success { background: rgba(16, 185, 129, 0.16); border-color: rgba(52, 211, 153, 0.35); color: #a7f3d0; }
        .fo-alert.is-info { background: rgba(56, 189, 248, 0.15); border-color: rgba(56, 189, 248, 0.35); color: #bae6fd; }
        .fo-label {
            display: block;
            font-size: 0.76rem;
            font-weight: 600;
            color: #cbd5e1;
            margin-bottom: 6px;
        }
        .fo-input-wrap {
            position: relative;
            margin-bottom: 14px;
        }
        .fo-input-icon {
            position: absolute;
            left: 13px;
            top: 50%;
            transform: translateY(-50%);
            color: #94a3b8;
            font-size: 0.92rem;
            pointer-events: none;
        }
        .fo-input {
            width: 100%;
            height: 42px;
            background: rgba(15, 23, 42, 0.75);
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 10px;
            color: #ffffff;
            padding: 0 38px;
            font-size: 0.88rem;
        }
        .fo-input:focus {
            outline: none;
            border-color: var(--fo-cyan);
            box-shadow: 0 0 0 3px rgba(56, 189, 248, 0.18);
        }
        .fo-pwd-toggle {
            position: absolute;
            right: 8px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            padding: 4px 6px;
        }
        .fo-submit {
            width: 100%;
            height: 43px;
            border: none;
            border-radius: 10px;
            background: linear-gradient(135deg, #0ea5e9 0%, #2563eb 100%);
            color: #ffffff;
            font-size: 0.9rem;
            font-weight: 600;
            cursor: pointer;
            margin-top: 4px;
        }
        .fo-role-box {
            margin-top: 16px;
            padding-top: 14px;
            border-top: 1px solid rgba(255, 255, 255, 0.1);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .fo-select {
            flex: 1;
            height: 34px;
            background: rgba(15, 23, 42, 0.85);
            border: 1px solid rgba(148, 163, 184, 0.28);
            border-radius: 8px;
            color: #e2e8f0;
            font-size: 0.78rem;
            padding: 0 10px;
        }
        .fo-fill-btn {
            height: 34px;
            padding: 0 12px;
            border-radius: 8px;
            border: 1px solid rgba(56, 189, 248, 0.4);
            background: rgba(56, 189, 248, 0.14);
            color: #7dd3fc;
            font-size: 0.78rem;
            font-weight: 600;
            cursor: pointer;
        }
        .fo-footer {
            margin-top: 16px;
            padding-top: 12px;
            border-top: 1px solid rgba(255, 255, 255, 0.08);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.75rem;
        }
        .fo-link {
            color: #7dd3fc;
            text-decoration: none;
            background: none;
            border: none;
            padding: 0;
            cursor: pointer;
            font-size: 0.75rem;
        }
        .fo-link:hover { color: #ffffff; }
    </style>
</head>
<body>
    <div class="fo-backdrop" aria-hidden="true"></div>
    <main class="fo-shell">
        <section class="fo-card">
            <div class="fo-brand">
                <div class="fo-emblem"><i class="bi bi-cpu-fill"></i></div>
                <div>
                    <h1 class="fo-title">FILKOM AI Orchestrator</h1>
                    <p class="fo-sub">One Platform. Multiple Intelligent Agents.</p>
                </div>
            </div>

            <div class="fo-alert is-<?= htmlspecialchars($alertType, ENT_QUOTES, 'UTF-8') ?><?= $alertMsg === '' ? ' d-none' : '' ?>" id="loginAlertBox" role="alert">
                <i class="bi bi-shield-check flex-shrink-0 mt-1"></i>
                <span id="loginAlertText"><?= htmlspecialchars($alertMsg, ENT_QUOTES, 'UTF-8') ?></span>
            </div>

            <form id="vaLoginForm" novalidate autocomplete="off">
                <input type="hidden" id="csrfTokenInput" name="csrf_token" value="<?= htmlspecialchars($csrfVal, ENT_QUOTES, 'UTF-8') ?>">

                <label class="fo-label" for="identifierInput">Username or Email</label>
                <div class="fo-input-wrap">
                    <i class="bi bi-person fo-input-icon"></i>
                    <input type="text" class="fo-input" id="identifierInput" name="identifier" placeholder="username@filkom.unklab.ac.id" autocomplete="username" required>
                </div>

                <label class="fo-label" for="passwordInput">Password</label>
                <div class="fo-input-wrap">
                    <i class="bi bi-lock fo-input-icon"></i>
                    <input type="password" class="fo-input" id="passwordInput" name="password" placeholder="Enter password" autocomplete="current-password" required>
                    <button type="button" class="fo-pwd-toggle" id="btnTogglePwd" aria-label="Toggle password">
                        <i class="bi bi-eye" id="iconTogglePwd"></i>
                    </button>
                </div>

                <div class="fo-input-wrap d-none" id="mfaFieldWrap">
                    <label class="fo-label" for="mfaCodeInput">MFA Authenticator Code</label>
                    <div class="position-relative">
                        <i class="bi bi-key fo-input-icon"></i>
                        <input type="text" class="fo-input" id="mfaCodeInput" name="mfa_code" maxlength="6" placeholder="6-digit TOTP code">
                    </div>
                </div>

                <button type="submit" class="fo-submit" id="btnLoginSubmit">Sign In</button>
            </form>

            <div class="fo-role-box">
                <select class="fo-select" id="roleQuickSelect" aria-label="Quick Role Selection">
                    <option value="">Quick role fill...</option>
                    <option value="admin|Admin123!">ADMIN — Administrator</option>
                    <option value="dean_filkom|Filkom123!">DEAN — Dean of FILKOM</option>
                    <option value="kaprodi_if|Filkom123!">KAPRODI_IF — Informatics</option>
                    <option value="kaprodi_si|Filkom123!">KAPRODI_SI — Information Systems</option>
                    <option value="kaprodi_ti|Filkom123!">KAPRODI_TI — Information Technology</option>
                    <option value="kaprodi_msi|Filkom123!">KAPRODI_MSI — Master IS</option>
                    <option value="secretary_filkom|Filkom123!">SECRETARY — Faculty Secretary</option>
                    <option value="upmf_filkom|Filkom123!">UPMF — Quality Assurance</option>
                    <option value="research_filkom|Filkom123!">RESEARCH_HEAD — Research Chair</option>
                    <option value="student_if1|Student123!">STUDENT — Informatics Student</option>
                </select>
                <button type="button" class="fo-fill-btn" id="btnQuickFill">Fill</button>
            </div>

            <div class="fo-footer">
                <span class="text-muted">FILKOM — Universitas Klabat</span>
                <span class="text-info small opacity-75"><i class="bi bi-shield-lock me-1"></i>Admin-Managed Access</span>
            </div>
        </section>
    </main>

    <script src="<?= APP_PATH ?>/public/assets/vendor/bootstrap/js/bootstrap.bundle.min.js"></script>
    <script>
        (function () {
            const form = document.getElementById('vaLoginForm');
            const csrfEl = document.getElementById('csrfTokenInput');
            const idEl = document.getElementById('identifierInput');
            const pwdEl = document.getElementById('passwordInput');
            const mfaWrap = document.getElementById('mfaFieldWrap');
            const mfaEl = document.getElementById('mfaCodeInput');
            const submitBtn = document.getElementById('btnLoginSubmit');
            const alertBox = document.getElementById('loginAlertBox');
            const alertText = document.getElementById('loginAlertText');
            const toggleBtn = document.getElementById('btnTogglePwd');
            const toggleIcon = document.getElementById('iconTogglePwd');
            const roleSel = document.getElementById('roleQuickSelect');
            const fillBtn = document.getElementById('btnQuickFill');

            const showAlert = (msg, type) => {
                alertBox.className = 'fo-alert is-' + (type || 'danger');
                alertText.textContent = msg;
                alertBox.classList.remove('d-none');
            };

            if (toggleBtn && pwdEl && toggleIcon) {
                toggleBtn.addEventListener('click', () => {
                    const isHidden = pwdEl.type === 'password';
                    pwdEl.type = isHidden ? 'text' : 'password';
                    toggleIcon.className = isHidden ? 'bi bi-eye-slash' : 'bi bi-eye';
                });
            }

            if (fillBtn && roleSel) {
                fillBtn.addEventListener('click', () => {
                    const val = roleSel.value || '';
                    if (!val) return;
                    const parts = val.split('|');
                    idEl.value = parts[0] || '';
                    pwdEl.value = parts[1] || '';
                    alertBox.classList.add('d-none');
                });
            }

            if (form) {
                form.addEventListener('submit', async (ev) => {
                    ev.preventDefault();
                    alertBox.classList.add('d-none');
                    const identifier = (idEl.value || '').trim();
                    const password = pwdEl.value || '';
                    const mfa_code = mfaEl ? mfaEl.value.trim() : '';
                    const csrf_token = csrfEl ? csrfEl.value : '';

                    if (!identifier || !password) {
                        showAlert('Please enter username/email and password.', 'warning');
                        return;
                    }

                    submitBtn.disabled = true;
                    submitBtn.textContent = 'Signing in...';
                    try {
                        const res = await fetch('<?= APP_PATH ?>/login/auth', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json' },
                            body: JSON.stringify({ identifier, password, mfa_code, csrf_token })
                        });
                        const data = await res.json().catch(() => ({}));
                        if (data && data.mfa_required) {
                            mfaWrap.classList.remove('d-none');
                            showAlert(data.message || 'Enter your 6-digit MFA code.', 'info');
                            mfaEl.focus();
                            return;
                        }
                        if (res.ok && data && data.success) {
                            showAlert(data.message || 'Success. Redirecting...', 'success');
                            window.location.href = data.redirect || '<?= APP_PATH ?>/dashboard';
                            return;
                        }
                        showAlert((data && data.message) || 'Authentication failed.', 'danger');
                    } catch (err) {
                        showAlert('Unable to reach authentication server.', 'danger');
                    } finally {
                        submitBtn.disabled = false;
                        submitBtn.textContent = 'Sign In';
                    }
                });
            }
        })();
    </script>
</body>
</html>
