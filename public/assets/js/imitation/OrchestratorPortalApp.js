import { OrchestratorCommandCenter3D } from './OrchestratorCommandCenter3D.js?v=20261124v400';

const BOOT = window.FILKOM_ORCH_BOOT || {};
const APP_PATH = BOOT.appPath || '';
let CSRF_TOKEN = BOOT.csrfToken || '';

const ROLE_QUICK_PROMPTS = {
    ADMIN: [
        'Summarize FILKOM academic performance, thesis risk, and accreditation indicators across all study programs.',
        'Compare lecturer research funding and Scopus publications across Informatics and Information Systems.',
        'Check latest quality assurance audit findings and administration meetings.'
    ],
    DEAN: [
        'Summarize FILKOM academic performance, thesis risk, and accreditation indicators across all study programs.',
        'Identify faculty strategic priorities based on research funding, probation students, and UPMF quality compliance.',
        'Show overall lecturer teaching and research KPI metrics.'
    ],
    KAPRODI_IF: [
        'Analyze student academic performance and GPA distribution in Informatics (IF).',
        'List Informatics students with delayed or at-risk thesis progress.',
        'Review Informatics courses, schedules, and lecturer teaching scores.'
    ],
    KAPRODI_SI: [
        'Analyze student academic performance and GPA distribution in Information Systems (SI).',
        'List Information Systems students with thesis risk and advisor status.',
        'Review Information Systems courses and classroom schedules.'
    ],
    KAPRODI_TI: [
        'Analyze student academic performance in Information Technology (TI).',
        'Check curriculum, course schedule, and lecturer assignments for Information Technology.'
    ],
    KAPRODI_MSI: [
        'Analyze Master Information Systems (MSI) graduate research and thesis progress.',
        'Show MSI student academic metrics and research alignment.'
    ],
    SECRETARY: [
        'Summarize upcoming faculty meetings, correspondence logs, and faculty decrees.',
        'Review faculty administration SOP and correspondence filing guidelines.'
    ],
    UPMF: [
        'Show all accreditation indicators with critical or watch compliance status.',
        'Summarize internal quality audit (AMI) evaluation scores across FILKOM programs.'
    ],
    RESEARCH_HEAD: [
        'Summarize FILKOM research grants, total funding IDR, and publication tiers.',
        'Rank top lecturers by publication citations and research scores.'
    ],
    STUDENT: [
        'Show my current GPA, total credits, enrolled courses, and attendance record.',
        'Check my thesis title, progress percentage, and academic advisor.',
        'Explain FILKOM academic regulations regarding leave, thesis defense prerequisites, and minimum attendance.'
    ]
};

function esc(v) {
    if (v === null || v === undefined) return '';
    return String(v)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function formatReplyHtml(text) {
    let s = esc(text || '');
    s = s.replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>');
    s = s.replace(/`([^`]+)`/g, '<code class="px-1 rounded bg-light text-primary">$1</code>');
    s = s.replace(/\n/g, '<br>');
    return s;
}

function hasPerm(code) {
    const perms = Array.isArray(BOOT.permissions) ? BOOT.permissions : [];
    return perms.includes(code);
}

function toast(msg, isError = false) {
    const box = document.getElementById('orchToastBox');
    if (!box) return;
    const el = document.createElement('div');
    el.className = 'orch-toast' + (isError ? ' err' : '');
    el.textContent = msg;
    box.appendChild(el);
    setTimeout(() => {
        el.remove();
    }, 4200);
}

async function callJson(url, method = 'GET', body = null, isFormData = false) {
    const opts = {
        method,
        credentials: 'same-origin',
        headers: {}
    };
    if (method === 'POST') {
        opts.headers['X-CSRF-Token'] = CSRF_TOKEN;
        if (isFormData) {
            if (body instanceof FormData && !body.has('csrf_token')) {
                body.append('csrf_token', CSRF_TOKEN);
            }
            opts.body = body;
        } else {
            opts.headers['Content-Type'] = 'application/json';
            opts.body = JSON.stringify({ ...(body || {}), csrf_token: CSRF_TOKEN });
        }
    }
    const res = await fetch(url, opts);
    const data = await res.json().catch(() => ({ success: false, message: 'Invalid JSON response' }));
    if (data && data.csrf_token) CSRF_TOKEN = data.csrf_token;
    if (!res.ok && data.success === undefined) {
        data.success = false;
    }
    return data;
}

function dashApi(action, method = 'GET', body = null, isFormData = false) {
    if (method === 'GET' && body && typeof body === 'object') {
        const qs = new URLSearchParams(body).toString();
        return callJson(`${APP_PATH}/dashboard/api/${action}${qs ? '?' + qs : ''}`, 'GET');
    }
    return callJson(`${APP_PATH}/dashboard/api/${action}`, method, body, isFormData);
}

function orchApi(action, method = 'GET', body = null) {
    if (method === 'GET' && body && typeof body === 'object') {
        const qs = new URLSearchParams(body).toString();
        return callJson(`${APP_PATH}/orchestrator/api/${action}${qs ? '?' + qs : ''}`, 'GET');
    }
    return callJson(`${APP_PATH}/orchestrator/api/${action}`, method, body);
}

class OrchestratorPortalApp {
    constructor() {
        this.state = {
            activeSection: 'command_center',
            authorizedAgents: Array.isArray(BOOT.authorizedAgents) ? BOOT.authorizedAgents : [],
            preferredAgentCode: '',
            currentConversationId: null,
            lastEventId: 0,
            isSending: false,
            activeWsModule: 'overview',
            agentsCache: [],
            toolsCache: [],
            kbCache: [],
            usersCache: [],
            lecturersCache: [],
            rolesMatrix: null,
            aiProposalDraft: null,
            ragPreviewCache: null,
            importTargetType: 'users',
            aiCostAgentsCache: [],
            liveAgentStatusMap: {},
            liveChartsAuto: true,
            liveChartsTimer: null,
            charts: {}
        };
        this.commandCenter3D = null;
        this.sse = null;
        this.init();
    }

    init() {
        this._bindNavigation();
        this._bindCommandCenter();
        this._bindWorkspace();
        this._bindAgentsModule();
        this._bindUsersModule();
        this._bindPositionsModule();
        this._bindStudentsModule();
        this._bindKnowledgeToolsModule();
        this._bindAiEngineModule();
        this._bindLiveAgentChartsModule();
        this._bindMonitoringModule();
        this._bindProfileModule();

        const hash = (window.location.hash || '').replace('#', '').trim();
        const initialSection = hash && document.getElementById(`section-${hash}`) ? hash : 'command_center';
        this.switchSection(initialSection);

        this._bootstrapOrchestrator();
    }

    _bindNavigation() {
        document.querySelectorAll('.orch-nav-btn[data-section]').forEach((btn) => {
            btn.addEventListener('click', () => {
                this.switchSection(btn.dataset.section, btn.dataset.subtab || '', btn);
                document.getElementById('orchSidebar')?.classList.remove('open');
            });
        });

        document.querySelectorAll('[data-mobile-nav]').forEach((btn) => {
            btn.addEventListener('click', () => {
                document.getElementById('orchSidebar')?.classList.toggle('open');
            });
        });

        document.getElementById('btnReturnStage')?.addEventListener('click', () => {
            this.switchSection('command_center');
        });

        document.getElementById('btnCollapseSidebar')?.addEventListener('click', () => {
            document.getElementById('orchSidebar')?.classList.toggle('collapsed');
            setTimeout(() => this.commandCenter3D?.resize(), 240);
        });
    }

    switchSection(section, subtabId = '', clickedBtn = null) {
        if (!document.getElementById(`section-${section}`)) {
            section = 'command_center';
        }
        this.state.activeSection = section;
        window.location.hash = section;

        document.getElementById('orchMainContent')?.classList.toggle('is-stage', section === 'command_center');

        const activeBtn = clickedBtn
            || (subtabId ? document.querySelector(`.orch-nav-btn[data-section="${section}"][data-subtab="${subtabId}"]`) : null)
            || document.querySelector(`.orch-nav-btn[data-section="${section}"]`);

        document.querySelectorAll('.orch-nav-btn[data-section]').forEach((btn) => {
            btn.classList.toggle('active', btn === activeBtn);
        });
        document.querySelectorAll('.orch-section').forEach((sec) => {
            sec.classList.toggle('active', sec.id === `section-${section}`);
        });

        const nextTitle = activeBtn ? (activeBtn.dataset.title || activeBtn.textContent.trim()) : '3D Agent Command Center';
        const titleEl = document.getElementById('orchPageTitle');
        if (titleEl) {
            titleEl.textContent = nextTitle;
        }
        const sideLabel = document.querySelector('#orchSideSectionLabel span');
        if (sideLabel) {
            sideLabel.textContent = nextTitle;
        }

        if (subtabId) {
            const tabTrigger = document.getElementById(subtabId);
            if (tabTrigger && window.bootstrap?.Tab) {
                window.bootstrap.Tab.getOrCreateInstance(tabTrigger).show();
            }
        }

        if (section !== 'agent_charts') {
            this.stopLiveChartsPolling();
        }

        if (section === 'command_center') {
            this._ensure3DCommandCenter();
            setTimeout(() => this.commandCenter3D?.resize(), 60);
        } else if (section === 'workspace') {
            this.loadWorkspaceData();
        } else if (section === 'agents') {
            this.loadAgentsList();
        } else if (section === 'users_rbac') {
            this.loadUsersList();
            this.loadPasswordResetHistory();
            this.loadRolesMatrix();
            this.loadStudyPrograms();
            this.loadProfileSessions();
        } else if (section === 'positions') {
            this.loadPositionHistory();
        } else if (section === 'students') {
            this.loadStudentsList();
        } else if (section === 'knowledge_tools') {
            this.loadKnowledgeBases();
            this.loadRagDocs();
            this.loadToolsList();
        } else if (section === 'ai_engine') {
            this.loadAiKeysAndUsage();
        } else if (section === 'agent_charts') {
            this.loadLiveAgentCharts();
            this.startLiveChartsPolling();
        } else if (section === 'monitoring') {
            this.loadMonitoringSection();
        } else if (section === 'profile') {
            this.loadProfileSessions();
        }
    }

    _ensure3DCommandCenter() {
        if (this.commandCenter3D) return;
        const canvas = document.getElementById('orchCommandCanvas');
        const container = document.getElementById('orchStageContainer');
        if (!canvas || !container || typeof window.THREE === 'undefined') return;

        const roleZone = BOOT.user?.zone_code || 'COMMAND_CENTER';
        this.commandCenter3D = new OrchestratorCommandCenter3D({
            canvas,
            container,
            roleZone,
            initialAgents: this.state.authorizedAgents,
            onAgentSelect: (agent) => {
                if (!agent?.agent_code) return;
                this.selectPreferredAgent(agent.agent_code);
                this.commandCenter3D.showHologramSummary(
                    agent.agent_name,
                    agent.agent_category || 'AGENT',
                    agent.description || 'Authorized FILKOM AI Agent ready for execution.'
                );
            },
            onStateUpdate: (ev) => {
                this._renderLiveEventHud(ev);
            }
        });
    }

    _bindCommandCenter() {
        const drawer = document.getElementById('orchConsoleDrawer');
        document.getElementById('btnToggleConsole')?.addEventListener('click', () => {
            drawer?.classList.toggle('open');
        });
        document.getElementById('btnCloseConsole')?.addEventListener('click', () => {
            drawer?.classList.remove('open');
        });

        document.getElementById('btnResetCam')?.addEventListener('click', () => {
            this.commandCenter3D?.resetCamera();
        });

        document.getElementById('orchZoneFilterBar')?.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-zone]');
            if (!btn) return;
            this.commandCenter3D?.focusZone(btn.dataset.zone);
        });

        document.getElementById('orchAgentStrip')?.addEventListener('click', (e) => {
            const pill = e.target.closest('[data-agent-code]');
            if (!pill) return;
            this.selectPreferredAgent(pill.dataset.agentCode);
        });

        document.getElementById('btnNewConv')?.addEventListener('click', () => {
            this.state.currentConversationId = null;
            const sel = document.getElementById('orchConvSelect');
            if (sel) sel.value = '';
            this._renderWelcomeChat();
        });

        document.getElementById('orchConvSelect')?.addEventListener('change', (e) => {
            const cid = parseInt(e.target.value || '0', 10);
            if (cid > 0) {
                this.loadConversationMessages(cid);
            } else {
                this.state.currentConversationId = null;
                this._renderWelcomeChat();
            }
        });

        document.getElementById('orchQuickPrompts')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-prompt]');
            if (!btn) return;
            const input = document.getElementById('orchChatInput');
            if (input) {
                input.value = btn.dataset.prompt;
                this.sendOrchestratorMessage();
            }
        });

        document.getElementById('orchChatForm')?.addEventListener('submit', (e) => {
            e.preventDefault();
            this.sendOrchestratorMessage();
        });

        document.getElementById('orchChatFeed')?.addEventListener('click', async (e) => {
            const fbBtn = e.target.closest('[data-fb-msg]');
            if (!fbBtn) return;
            const msgId = parseInt(fbBtn.dataset.fbMsg, 10);
            const rating = parseInt(fbBtn.dataset.fbVal, 10);
            const res = await orchApi('feedback', 'POST', { message_id: msgId, rating });
            if (res.success) {
                toast('Feedback saved');
                fbBtn.closest('.orch-msg-meta')?.querySelectorAll('[data-fb-msg]').forEach((b) => {
                    b.classList.toggle('text-primary', b === fbBtn);
                });
            }
        });
    }

    async _bootstrapOrchestrator() {
        const res = await orchApi('bootstrap', 'GET');
        if (!res.success) {
            this._renderQuickPrompts();
            this._renderWelcomeChat();
            return;
        }
        this.state.authorizedAgents = res.authorized_agents || [];
        this.state.lastEventId = res.last_event_id || 0;
        this._renderAgentStrip();
        this._renderZoneButtons();
        this._renderQuickPrompts();
        this._renderConversationsSelect(res.conversations || []);
        this._renderWelcomeChat();

        if (this.commandCenter3D) {
            this.commandCenter3D.setAuthorizedAgents(this.state.authorizedAgents, res.user?.zone_code || 'COMMAND_CENTER');
        }
        this._connectSseStream();
    }

    _renderZoneButtons() {
        const bar = document.getElementById('orchZoneFilterBar');
        if (!bar) return;
        const zones = [...new Set(this.state.authorizedAgents.map((a) => a.zone_code).filter(Boolean))];
        const html = [`<button type="button" class="btn btn-sm btn-outline-light bg-dark bg-opacity-75 rounded-pill px-2 py-0" data-zone="${esc(BOOT.user?.zone_code || 'COMMAND_CENTER')}">Hub</button>`];
        zones.forEach((z) => {
            html.push(`<button type="button" class="btn btn-sm btn-outline-light bg-dark bg-opacity-75 rounded-pill px-2 py-0" data-zone="${esc(z)}">${esc(z)}</button>`);
        });
        bar.innerHTML = html.join('');
    }

    _renderAgentStrip() {
        const strip = document.getElementById('orchAgentStrip');
        if (!strip) return;
        const items = [
            `<button type="button" class="orch-agent-pill ${this.state.preferredAgentCode === '' ? 'active' : ''}" data-agent-code="">
                <i class="bi bi-diagram-3-fill"></i> Auto-Route (${this.state.authorizedAgents.length})
            </button>`
        ];
        this.state.authorizedAgents.forEach((ag) => {
            const active = this.state.preferredAgentCode === ag.agent_code ? 'active' : '';
            items.push(
                `<button type="button" class="orch-agent-pill ${active}" data-agent-code="${esc(ag.agent_code)}" title="${esc(ag.description || '')}">
                    <i class="bi bi-robot"></i> ${esc(ag.agent_name)}
                </button>`
            );
        });
        strip.innerHTML = items.join('');
    }

    selectPreferredAgent(agentCode) {
        if (this.state.preferredAgentCode === agentCode && agentCode !== '') {
            this.state.preferredAgentCode = '';
        } else {
            this.state.preferredAgentCode = agentCode || '';
        }
        this._renderAgentStrip();
        const badge = document.getElementById('orchSelectedAgentBadge');
        if (badge) {
            const found = this.state.authorizedAgents.find((a) => a.agent_code === this.state.preferredAgentCode);
            badge.innerHTML = `<i class="bi bi-cpu"></i> ${esc(found ? found.agent_name : 'Auto Orchestrator')}`;
        }
    }

    _renderQuickPrompts() {
        const wrap = document.getElementById('orchQuickPrompts');
        if (!wrap) return;
        const role = BOOT.user?.role || 'STUDENT';
        const prompts = ROLE_QUICK_PROMPTS[role] || ROLE_QUICK_PROMPTS.STUDENT;
        wrap.innerHTML = prompts.map((p) => (
            `<button type="button" class="orch-qp-btn" data-prompt="${esc(p)}"><i class="bi bi-lightning-charge text-info"></i> ${esc(p.length > 52 ? p.slice(0, 52) + '...' : p)}</button>`
        )).join('');
    }

    _renderConversationsSelect(list) {
        const sel = document.getElementById('orchConvSelect');
        if (!sel) return;
        const opts = ['<option value="">New Orchestration Thread</option>'];
        list.forEach((c) => {
            opts.push(`<option value="${esc(c.id)}">${esc(c.title || ('Thread #' + c.id))}</option>`);
        });
        sel.innerHTML = opts.join('');
        if (this.state.currentConversationId) {
            sel.value = String(this.state.currentConversationId);
        }
    }

    _renderWelcomeChat() {
        const feed = document.getElementById('orchChatFeed');
        if (!feed) return;
        const roleName = BOOT.user?.role || 'USER';
        const progs = (BOOT.user?.program_codes || []).join(', ') || 'All Authorized Scope';
        feed.innerHTML = `
            <div class="orch-msg assistant">
                <div class="orch-msg-bubble">
                    <strong>FILKOM Orchestrator Online</strong><br>
                    Active Role: <span class="orch-badge orch-badge-info">${esc(roleName)}</span>
                    Scope: <span class="orch-badge orch-badge-muted">${esc(progs)}</span>
                    Authorized Agents: <strong>${this.state.authorizedAgents.length}</strong><br>
                    Select a pod in the 3D Command Center or submit a query below.
                </div>
            </div>
        `;
    }

    async loadConversationMessages(convId) {
        const res = await orchApi('conversations', 'GET', { conversation_id: convId });
        if (!res.success) return;
        this.state.currentConversationId = convId;
        const feed = document.getElementById('orchChatFeed');
        if (!feed) return;
        feed.innerHTML = '';
        (res.messages || []).forEach((m) => {
            this._appendMessageBubble(m.role === 'user' ? 'user' : 'assistant', m.content, {
                message_id: m.id,
                agent_name: m.agent_name,
                agent_code: m.agent_code,
                execution_mode: m.execution_mode,
                latency_ms: m.latency_ms,
                total_tokens: m.total_tokens,
                citations: m.citations || [],
                tool_traces: m.tool_traces || []
            });
        });
        feed.scrollTop = feed.scrollHeight;
    }

    _appendMessageBubble(role, text, meta = {}) {
        const feed = document.getElementById('orchChatFeed');
        if (!feed) return;
        const div = document.createElement('div');
        div.className = `orch-msg ${role}`;
        if (role === 'user') {
            div.innerHTML = `<div class="orch-msg-bubble">${esc(text)}</div>`;
        } else {
            const agentsList = Array.isArray(meta.agents_used) && meta.agents_used.length
                ? meta.agents_used.map((a) => `<span class="orch-trace-tag"><i class="bi bi-robot"></i> ${esc(a.agent_name || a.agent_code)}</span>`).join('')
                : (meta.agent_name ? `<span class="orch-trace-tag"><i class="bi bi-robot"></i> ${esc(meta.agent_name)}</span>` : '');
            const modeTag = (meta.execution_mode === 'multi' || meta.execution_mode === 'collaborative')
                ? `<span class="orch-badge orch-badge-warn">Multi-Agent</span>`
                : '';
            const toolTag = Array.isArray(meta.tool_traces) && meta.tool_traces.length
                ? `<span class="orch-badge orch-badge-ok"><i class="bi bi-wrench"></i> ${meta.tool_traces.length} tools</span>`
                : '';
            const citeHtml = Array.isArray(meta.citations) && meta.citations.length
                ? meta.citations.slice(0, 3).map((c) => `<span class="orch-cite-tag"><i class="bi bi-journal-bookmark"></i> ${esc(c.title || c.file_name || 'RAG')}</span>`).join('')
                : '';
            const perf = meta.latency_ms ? `<span>${meta.latency_ms}ms · ${meta.total_tokens || 0} tok</span>` : '';
            const fbHtml = meta.message_id
                ? `<button type="button" class="btn btn-sm p-0 border-0 text-muted" data-fb-msg="${esc(meta.message_id)}" data-fb-val="1"><i class="bi bi-hand-thumbs-up"></i></button>
                   <button type="button" class="btn btn-sm p-0 border-0 text-muted" data-fb-msg="${esc(meta.message_id)}" data-fb-val="-1"><i class="bi bi-hand-thumbs-down"></i></button>`
                : '';

            div.innerHTML = `
                <div class="orch-msg-bubble">${formatReplyHtml(text)}</div>
                <div class="orch-msg-meta">
                    ${modeTag} ${agentsList} ${toolTag} ${citeHtml} ${perf} ${fbHtml}
                </div>
            `;
        }
        feed.appendChild(div);
        feed.scrollTop = feed.scrollHeight;
    }

    async sendOrchestratorMessage() {
        if (this.state.isSending) return;
        const input = document.getElementById('orchChatInput');
        const btn = document.getElementById('btnSendChat');
        const message = (input?.value || '').trim();
        if (!message) return;

        this.state.isSending = true;
        if (input) input.value = '';
        if (btn) btn.disabled = true;

        this._appendMessageBubble('user', message);

        const typingEl = document.createElement('div');
        typingEl.className = 'orch-msg assistant';
        typingEl.innerHTML = `<div class="orch-msg-bubble text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Orchestrating authorized agents &amp; FILKOM data...</div>`;
        document.getElementById('orchChatFeed')?.appendChild(typingEl);

        this.commandCenter3D?.applyAgentEvent({
            agent_id: this.state.preferredAgentCode || 'ORCHESTRATOR_HUB',
            status: 'routing',
            animation: 'thinking',
            task: message.slice(0, 60)
        });

        const res = await orchApi('execute', 'POST', {
            message,
            conversation_id: this.state.currentConversationId,
            preferred_agent_code: this.state.preferredAgentCode || null
        });

        typingEl.remove();
        this.state.isSending = false;
        if (btn) btn.disabled = false;

        if (!res.success) {
            this._appendMessageBubble('assistant', res.message || 'Orchestration request could not be completed.');
            this.commandCenter3D?.applyAgentEvent({ agent_id: 'ORCHESTRATOR_HUB', status: 'error', animation: 'error' });
            toast(res.message || 'Execution failed', true);
            return;
        }

        this.state.currentConversationId = res.conversation_id || this.state.currentConversationId;
        this._appendMessageBubble('assistant', res.reply, {
            message_id: res.message_id,
            execution_mode: res.execution_mode,
            agent_name: res.primary_agent?.agent_name,
            agent_code: res.primary_agent?.agent_code,
            agents_used: res.agents_used || [],
            tool_traces: res.tool_traces || [],
            citations: res.citations || [],
            latency_ms: res.latency_ms,
            total_tokens: res.total_tokens
        });

        this.commandCenter3D?.applyAgentEvent({
            agent_id: res.primary_agent?.agent_code || 'ORCHESTRATOR_HUB',
            status: 'success',
            animation: res.animation || 'success',
            zone: res.zone || 'COMMAND_CENTER',
            task: `${res.execution_mode} · ${res.latency_ms}ms`
        });

        this.commandCenter3D?.showHologramSummary(
            res.primary_agent?.agent_name || 'FILKOM Orchestrator',
            (res.execution_mode === 'multi' || res.execution_mode === 'collaborative') ? 'COLLABORATIVE' : 'COMPLETED',
            (res.reply || '').slice(0, 260)
        );

        const convRes = await orchApi('conversations', 'GET');
        if (convRes.success) {
            this._renderConversationsSelect(convRes.conversations || []);
        }
    }

    _connectSseStream() {
        if (this.sse) {
            this.sse.close();
        }
        if (typeof EventSource === 'undefined') return;
        const url = `${APP_PATH}/orchestrator/stream?last_id=${this.state.lastEventId || 0}`;
        this.sse = new EventSource(url);
        this.sse.addEventListener('agent_status', (e) => {
            try {
                const ev = JSON.parse(e.data);
                if (ev.event_id) {
                    this.state.lastEventId = Math.max(this.state.lastEventId, Number(ev.event_id));
                }
                this.commandCenter3D?.applyAgentEvent(ev);
                this._renderLiveEventHud(ev);
                if (ev.agent_id) {
                    this.state.liveAgentStatusMap[ev.agent_id] = {
                        status: ev.status || 'idle',
                        task: ev.task || '',
                        at: new Date().toLocaleTimeString()
                    };
                }
                if (this.state.activeSection === 'agent_charts') {
                    this.loadLiveAgentCharts();
                }
            } catch (_) {}
        });
        this.sse.onerror = () => {
            const chip = document.getElementById('orchLiveChip');
            if (chip) chip.classList.remove('orch-chip-live');
        };
        this.sse.onopen = () => {
            const chip = document.getElementById('orchLiveChip');
            if (chip) chip.classList.add('orch-chip-live');
        };
    }

    _renderLiveEventHud(ev) {
        if (!ev) return;
        const ticker = document.getElementById('orchEventTicker');
        const hubBadge = document.getElementById('orchHubStateBadge');
        if (ticker) {
            const prog = ev.progress !== undefined ? ` (${ev.progress}%)` : '';
            ticker.innerHTML = `<i class="bi bi-broadcast text-info"></i> <span>${esc(ev.agent_id || 'HUB')}: ${esc(ev.task || ev.status)}${prog}</span>`;
        }
        if (hubBadge) {
            hubBadge.innerHTML = `<span class="orch-dot pulse"></span> ${esc((ev.status || 'IDLE').toUpperCase())}`;
        }
        const stripPill = document.querySelector(`.orch-agent-pill[data-agent-code="${CSS.escape(ev.agent_id || '')}"]`);
        if (stripPill) {
            const busy = !['idle', 'success', 'error'].includes((ev.status || '').toLowerCase());
            stripPill.classList.toggle('busy', busy);
        }
    }

    _bindWorkspace() {
        const firstActive = document.querySelector('#wsTabBar [data-ws-mod].active') || document.querySelector('#wsTabBar [data-ws-mod]');
        if (firstActive) {
            firstActive.classList.add('active');
            this.state.activeWsModule = firstActive.dataset.wsMod;
        }
        document.getElementById('wsTabBar')?.addEventListener('click', (e) => {
            const btn = e.target.closest('[data-ws-mod]');
            if (!btn) return;
            this.state.activeWsModule = btn.dataset.wsMod;
            document.querySelectorAll('#wsTabBar [data-ws-mod]').forEach((b) => {
                b.classList.toggle('active', b === btn);
            });
            this.loadWorkspaceData();
        });
        document.getElementById('wsProgFilter')?.addEventListener('change', () => this.loadWorkspaceData());
        document.getElementById('wsBody')?.addEventListener('change', (e) => {
            if (e.target && e.target.id === 'coursesProgFilter') {
                const topSel = document.getElementById('wsProgFilter');
                if (topSel) topSel.value = e.target.value;
                this.loadWorkspaceData(e.target.value);
            }
        });

        document.getElementById('wsBody')?.addEventListener('click', (e) => {
            const btn = e.target.closest('button[data-ws-action]');
            if (!btn) return;
            const act = btn.dataset.wsAction;
            const selProg = document.getElementById('wsProgFilter')?.value || '';
            if (act === 'add_course') {
                document.getElementById('courseScheduleForm')?.reset();
                this._populateLecturerSelects('csLecturerId');
                if (selProg && document.getElementById('csProgId')) document.getElementById('csProgId').value = selProg;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCourseSchedule')).show();
            } else if (act === 'add_lecturer') {
                document.getElementById('lecturerForm')?.reset();
                if (selProg && document.getElementById('lectProgId')) document.getElementById('lectProgId').value = selProg;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLecturer')).show();
            } else if (act === 'add_research') {
                document.getElementById('researchProjForm')?.reset();
                this._populateLecturerSelects('rpLeadId');
                if (selProg && document.getElementById('rpProgId')) document.getElementById('rpProgId').value = selProg;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalResearchProj')).show();
            } else if (act === 'add_publication') {
                document.getElementById('publicationForm')?.reset();
                this._populateLecturerSelects('pubLectId');
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPublication')).show();
            } else if (act === 'add_indicator') {
                document.getElementById('qualityIndForm')?.reset();
                if (selProg && document.getElementById('qiProgId')) document.getElementById('qiProgId').value = selProg;
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalQualityInd')).show();
            } else if (act === 'add_meeting') {
                document.getElementById('meetingForm')?.reset();
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMeeting')).show();
            }
        });

        document.getElementById('courseScheduleForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = {
                course_code: document.getElementById('csCourseCode').value.trim(),
                course_name: document.getElementById('csCourseName').value.trim(),
                study_program_id: parseInt(document.getElementById('csProgId').value || '0', 10),
                credits: parseInt(document.getElementById('csCredits').value || '3', 10),
                semester_recommended: parseInt(document.getElementById('csSemester').value || '5', 10),
                semester_label: document.getElementById('csSemLabel').value.trim(),
                lecturer_id: parseInt(document.getElementById('csLecturerId').value || '0', 10),
                day_of_week: document.getElementById('csDay').value,
                start_time: document.getElementById('csStart').value,
                end_time: document.getElementById('csEnd').value,
                room: document.getElementById('csRoom').value.trim(),
                capacity: parseInt(document.getElementById('csCapacity').value || '40', 10),
                curriculum_year: parseInt(document.getElementById('csCurrYear').value || '2026', 10)
            };
            const res = await dashApi('save_course_schedule', 'POST', payload);
            if (!res.success) return toast(res.message || 'Failed to save course', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalCourseSchedule')).hide();
            this.loadWorkspaceData();
        });

        document.getElementById('lecturerForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = {
                nidn: document.getElementById('lectNidn').value.trim(),
                full_name: document.getElementById('lectName').value.trim(),
                study_program_id: parseInt(document.getElementById('lectProgId').value || '0', 10),
                academic_rank: document.getElementById('lectRank').value,
                expertise: document.getElementById('lectExpertise').value.trim(),
                teaching_score: parseFloat(document.getElementById('lectTeach').value || '4.50'),
                research_score: parseFloat(document.getElementById('lectRes').value || '4.20')
            };
            const res = await dashApi('save_lecturer', 'POST', payload);
            if (!res.success) return toast(res.message || 'Failed to save lecturer', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalLecturer')).hide();
            this.loadWorkspaceData();
        });

        document.getElementById('researchProjForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = {
                project_code: document.getElementById('rpCode').value.trim(),
                title: document.getElementById('rpTitle').value.trim(),
                lead_lecturer_id: parseInt(document.getElementById('rpLeadId').value || '0', 10),
                study_program_id: parseInt(document.getElementById('rpProgId').value || '0', 10),
                funding_source: document.getElementById('rpSource').value.trim(),
                funding_amount: parseInt(document.getElementById('rpAmount').value || '0', 10),
                year: parseInt(document.getElementById('rpYear').value || '2026', 10),
                status: document.getElementById('rpStatus').value
            };
            const res = await dashApi('save_research_project', 'POST', payload);
            if (!res.success) return toast(res.message || 'Failed to save project', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalResearchProj')).hide();
            this.loadWorkspaceData();
        });

        document.getElementById('publicationForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = {
                lecturer_id: parseInt(document.getElementById('pubLectId').value || '0', 10),
                title: document.getElementById('pubTitle').value.trim(),
                venue: document.getElementById('pubVenue').value.trim(),
                index_tier: document.getElementById('pubTier').value,
                year: parseInt(document.getElementById('pubYear').value || '2026', 10),
                citations: parseInt(document.getElementById('pubCitations').value || '0', 10)
            };
            const res = await dashApi('save_publication', 'POST', payload);
            if (!res.success) return toast(res.message || 'Failed to save publication', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalPublication')).hide();
            this.loadWorkspaceData();
        });

        document.getElementById('qualityIndForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = {
                indicator_code: document.getElementById('qiCode').value.trim(),
                study_program_id: document.getElementById('qiProgId').value || null,
                standard_name: document.getElementById('qiStandard').value.trim(),
                target_value: parseFloat(document.getElementById('qiTarget').value || '0'),
                actual_value: parseFloat(document.getElementById('qiActual').value || '0'),
                compliance_status: document.getElementById('qiStatus').value
            };
            const res = await dashApi('save_quality_indicator', 'POST', payload);
            if (!res.success) return toast(res.message || 'Failed to save indicator', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalQualityInd')).hide();
            this.loadWorkspaceData();
        });

        document.getElementById('meetingForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const payload = {
                title: document.getElementById('mtTitle').value.trim(),
                meeting_date: document.getElementById('mtDate').value,
                location: document.getElementById('mtLocation').value.trim(),
                chairperson: document.getElementById('mtChair').value.trim(),
                agenda_summary: document.getElementById('mtAgenda').value.trim(),
                status: document.getElementById('mtStatus').value
            };
            const res = await dashApi('save_faculty_meeting', 'POST', payload);
            if (!res.success) return toast(res.message || 'Failed to save meeting', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMeeting')).hide();
            this.loadWorkspaceData();
        });
    }

    _populateLecturerSelects(selectId) {
        const el = document.getElementById(selectId);
        if (!el) return;
        const list = this.state.lecturersCache || [];
        el.innerHTML = list.length
            ? list.map((l) => `<option value="${esc(l.id)}">${esc(l.full_name)} (${esc(l.program_code || '')})</option>`).join('')
            : '<option value="">No lecturers available</option>';
    }

    async loadWorkspaceData(overrideProgId = null) {
        const mod = this.state.activeWsModule || 'overview';
        const topSel = document.getElementById('wsProgFilter');
        const progId = overrideProgId !== null ? overrideProgId : (topSel?.value || '');
        const body = document.getElementById('wsBody');
        if (!body) return;
        body.innerHTML = `<div class="p-4 text-center text-muted"><span class="spinner-border spinner-border-sm me-2"></span>Loading scoped FILKOM module...</div>`;
        const res = await dashApi('faculty_module_data', 'GET', { module: mod, study_program_id: progId });
        if (!res.success) {
            body.innerHTML = `<div class="alert alert-danger">${esc(res.message || 'Failed to load data.')}</div>`;
            return;
        }
        if (Array.isArray(res.lecturers) && res.lecturers.length > 0) {
            this.state.lecturersCache = res.lecturers;
        }
        body.innerHTML = this._renderWorkspaceHtml(mod, res, progId);
    }

    _renderWorkspaceHtml(mod, res, selectedProgId = '') {
        if (mod === 'student_personal') {
            const st = res.student || {};
            const enr = res.enrollments || [];
            const theses = res.theses || [];
            return `
                <div class="orch-kpi-grid">
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">NIM</div><div class="orch-kpi-val fs-5">${esc(st.nim || '-')}</div></div><div class="orch-kpi-icon"><i class="bi bi-person-badge"></i></div></div>
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Program</div><div class="orch-kpi-val fs-5">${esc(st.program_code || '-')}</div></div><div class="orch-kpi-icon"><i class="bi bi-mortarboard"></i></div></div>
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">GPA</div><div class="orch-kpi-val">${esc(st.gpa ?? '0.00')}</div></div><div class="orch-kpi-icon"><i class="bi bi-graph-up"></i></div></div>
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Credits</div><div class="orch-kpi-val">${esc(st.total_credits ?? 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-journal-check"></i></div></div>
                </div>
                <div class="orch-card mb-3">
                    <h6 class="fw-bold mb-2">Current Enrollments &amp; Attendance</h6>
                    <div class="orch-table-wrap"><table class="table orch-table">
                        <thead><tr><th>Code</th><th>Course</th><th>Credits</th><th>Semester</th><th>Grade</th><th>Attendance</th><th>Status</th></tr></thead>
                        <tbody>${enr.map((r) => `<tr>
                            <td><code>${esc(r.course_code)}</code></td><td>${esc(r.course_name)}</td><td>${esc(r.credits)}</td>
                            <td>${esc(r.semester_label)}</td><td><strong>${esc(r.grade_letter || '-')}</strong></td>
                            <td>${esc(r.attended_sessions)}/${esc(r.total_sessions)} (${esc(r.attendance_pct)}%)</td>
                            <td><span class="orch-badge orch-badge-info">${esc(r.enrollment_status)}</span></td>
                        </tr>`).join('') || '<tr><td colspan="7" class="text-center text-muted">No enrollments</td></tr>'}</tbody>
                    </table></div>
                </div>
                <div class="orch-card">
                    <h6 class="fw-bold mb-2">My Thesis Progress</h6>
                    <div class="orch-table-wrap"><table class="table orch-table">
                        <thead><tr><th>Title</th><th>Supervisor</th><th>Stage</th><th>Progress</th><th>Status</th></tr></thead>
                        <tbody>${theses.map((t) => `<tr>
                            <td>${esc(t.title)}</td><td>${esc(t.supervisor_name || '-')}</td>
                            <td>${esc(t.stage)}</td><td>${esc(t.progress_pct)}%</td>
                            <td><span class="orch-badge ${t.status === 'at_risk' || t.status === 'delayed' ? 'orch-badge-warn' : 'orch-badge-ok'}">${esc(t.status)}</span></td>
                        </tr>`).join('') || '<tr><td colspan="5" class="text-center text-muted">No thesis record yet</td></tr>'}</tbody>
                    </table></div>
                </div>
            `;
        }
        if (mod === 'courses') {
            const rows = res.courses || [];
            const progs = Array.isArray(BOOT.studyPrograms) ? BOOT.studyPrograms : [];
            const canPickProg = Boolean(document.getElementById('wsProgFilter'));
            const canAddCourse = Boolean(BOOT.user?.caps?.courses);
            const filterHtml = canPickProg
                ? `<select id="coursesProgFilter" class="form-select form-select-sm w-auto">
                        <option value="">All Study Programs</option>
                        ${progs.map((sp) => `<option value="${sp.id}" ${String(selectedProgId) === String(sp.id) ? 'selected' : ''}>${esc(sp.program_code)} — ${esc(sp.program_name)}</option>`).join('')}
                   </select>`
                : '';
            const addBtn = canAddCourse
                ? `<button type="button" class="btn btn-sm btn-primary" data-ws-action="add_course"><i class="bi bi-plus-lg me-1"></i>Add Course &amp; Schedule</button>`
                : '';
            return `<div class="orch-card">
                <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
                    <div class="d-flex align-items-center gap-2">
                        <h6 class="fw-bold mb-0">Courses &amp; Teaching Schedules</h6>
                        <span class="orch-badge orch-badge-info">${rows.length}</span>
                    </div>
                    <div class="d-flex flex-wrap align-items-center gap-2">${filterHtml}${addBtn}</div>
                </div>
                <div class="orch-table-wrap"><table class="table orch-table">
                    <thead><tr><th>Code</th><th>Course Name</th><th>Program</th><th>SKS</th><th>Lecturer</th><th>Day &amp; Time</th><th>Room</th></tr></thead>
                    <tbody>${rows.map((c) => `<tr>
                        <td><code>${esc(c.course_code)}</code></td><td>${esc(c.course_name)}</td>
                        <td><span class="orch-badge orch-badge-info">${esc(c.program_code)}</span></td><td>${esc(c.credits)}</td>
                        <td>${esc(c.lecturer_name || '-')}</td><td>${esc(c.day_of_week || '-')} ${esc(c.start_time || '')}${c.end_time ? '-' + esc(c.end_time) : ''}</td><td>${esc(c.room || c.room_code || '-')}</td>
                    </tr>`).join('') || '<tr><td colspan="7" class="text-center text-muted py-3">No courses found for selected study program</td></tr>'}</tbody>
                </table></div></div>`;
        }
        if (mod === 'research') {
            const sum = res.research?.summary || {};
            const projs = res.research?.projects || [];
            const pubs = res.research?.publications || [];
            const canAddRes = Boolean(BOOT.user?.caps?.research);
            return `
                <div class="orch-kpi-grid">
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Research Projects</div><div class="orch-kpi-val">${esc(sum.project_count || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-flask"></i></div></div>
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Total Funding (IDR)</div><div class="orch-kpi-val fs-6">Rp ${Number(sum.total_funding_idr || 0).toLocaleString()}</div></div><div class="orch-kpi-icon"><i class="bi bi-cash-coin"></i></div></div>
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Publications</div><div class="orch-kpi-val">${esc(sum.publication_count || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-journal-richtext"></i></div></div>
                    <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Citations</div><div class="orch-kpi-val">${esc(sum.total_citations || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-quote"></i></div></div>
                </div>
                <div class="row g-3">
                    <div class="col-lg-6"><div class="orch-card h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0">Research Projects</h6>
                            ${canAddRes ? `<button type="button" class="btn btn-sm btn-primary py-0 px-2" data-ws-action="add_research"><i class="bi bi-plus-lg me-1"></i>Add Project</button>` : ''}
                        </div>
                        <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Code</th><th>Title</th><th>Lead</th><th>Funding</th><th>Status</th></tr></thead>
                        <tbody>${projs.map((p) => `<tr><td><code>${esc(p.project_code)}</code></td><td>${esc(p.title)}</td><td>${esc(p.lead_name)}</td><td>Rp ${Number(p.funding_amount || 0).toLocaleString()}</td><td><span class="orch-badge orch-badge-info">${esc(p.status)}</span></td></tr>`).join('')}</tbody></table></div>
                    </div></div>
                    <div class="col-lg-6"><div class="orch-card h-100">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0">Publications</h6>
                            ${canAddRes ? `<button type="button" class="btn btn-sm btn-primary py-0 px-2" data-ws-action="add_publication"><i class="bi bi-plus-lg me-1"></i>Add Publication</button>` : ''}
                        </div>
                        <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Title</th><th>Author</th><th>Tier</th><th>Year</th><th>Citations</th></tr></thead>
                        <tbody>${pubs.map((p) => `<tr><td>${esc(p.title)}</td><td>${esc(p.author_name)}</td><td><span class="orch-badge orch-badge-ok">${esc(p.index_tier)}</span></td><td>${esc(p.year)}</td><td>${esc(p.citations)}</td></tr>`).join('')}</tbody></table></div>
                    </div></div>
                </div>`;
        }
        if (mod === 'upmf') {
            const inds = res.quality?.indicators || [];
            const evals = res.quality?.evaluations || [];
            const canAddInd = Boolean(BOOT.user?.caps?.upmf);
            return `
                <div class="orch-card mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <h6 class="fw-bold mb-0">Accreditation &amp; Quality KPI Indicators</h6>
                        ${canAddInd ? `<button type="button" class="btn btn-sm btn-primary py-0 px-2" data-ws-action="add_indicator"><i class="bi bi-plus-lg me-1"></i>Add Indicator</button>` : ''}
                    </div>
                    <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Code</th><th>Standard</th><th>Program</th><th>Target</th><th>Actual</th><th>Compliance</th></tr></thead>
                    <tbody>${inds.map((i) => `<tr>
                        <td><code>${esc(i.indicator_code)}</code></td><td>${esc(i.standard_name)}</td><td>${esc(i.program_code || 'FACULTY')}</td>
                        <td>${esc(i.target_value)}</td><td>${esc(i.actual_value)}</td>
                        <td><span class="orch-badge ${i.compliance_status === 'critical' ? 'orch-badge-err' : (i.compliance_status === 'watch' ? 'orch-badge-warn' : 'orch-badge-ok')}">${esc(i.compliance_status)}</span></td>
                    </tr>`).join('')}</tbody></table></div>
                </div>
                <div class="orch-card"><h6 class="fw-bold mb-2">Internal Quality Audits (AMI)</h6>
                    <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Audit Code</th><th>Cycle</th><th>Program</th><th>Score</th><th>Evaluator</th><th>Findings</th><th>Status</th></tr></thead>
                    <tbody>${evals.map((e) => `<tr>
                        <td><code>${esc(e.audit_code)}</code></td><td>${esc(e.cycle_label)}</td><td>${esc(e.program_code || 'FACULTY')}</td>
                        <td><strong>${esc(e.score)}</strong></td><td>${esc(e.evaluator_name || '-')}</td><td>${esc(e.findings)}</td><td><span class="orch-badge orch-badge-info">${esc(e.status)}</span></td>
                    </tr>`).join('')}</tbody></table></div>
                </div>`;
        }
        if (mod === 'secretary') {
            const meets = res.administration?.meetings || [];
            const corr = res.administration?.correspondence || [];
            const docs = res.administration?.documents || [];
            const canAddMeet = Boolean(BOOT.user?.caps?.secretary);
            return `
                <div class="row g-3">
                    <div class="col-lg-6"><div class="orch-card">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="fw-bold mb-0">Faculty Meetings &amp; Agendas</h6>
                            ${canAddMeet ? `<button type="button" class="btn btn-sm btn-primary py-0 px-2" data-ws-action="add_meeting"><i class="bi bi-plus-lg me-1"></i>Add Meeting</button>` : ''}
                        </div>
                        <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Title</th><th>Date</th><th>Chair</th><th>Status</th></tr></thead>
                        <tbody>${meets.map((m) => `<tr><td>${esc(m.title)}</td><td>${esc(m.meeting_date)}</td><td>${esc(m.chairperson)}</td><td><span class="orch-badge orch-badge-info">${esc(m.status)}</span></td></tr>`).join('')}</tbody></table></div>
                    </div></div>
                    <div class="col-lg-6"><div class="orch-card"><h6 class="fw-bold mb-2">Official Correspondence</h6>
                        <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Ref #</th><th>Dir</th><th>Subject</th><th>Counterparty</th><th>Status</th></tr></thead>
                        <tbody>${corr.map((c) => `<tr><td><code>${esc(c.ref_number)}</code></td><td>${esc(c.direction)}</td><td>${esc(c.subject)}</td><td>${esc(c.counterparty)}</td><td><span class="orch-badge orch-badge-ok">${esc(c.status)}</span></td></tr>`).join('')}</tbody></table></div>
                    </div></div>
                    <div class="col-12"><div class="orch-card"><h6 class="fw-bold mb-2">Faculty Decrees &amp; Reports</h6>
                        <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Doc #</th><th>Category</th><th>Program</th><th>Title</th><th>Summary</th></tr></thead>
                        <tbody>${docs.map((d) => `<tr><td><code>${esc(d.doc_number)}</code></td><td>${esc(d.category)}</td><td>${esc(d.program_code || 'FILKOM')}</td><td>${esc(d.title)}</td><td>${esc(d.summary)}</td></tr>`).join('')}</tbody></table></div>
                    </div></div>
                </div>`;
        }

        const progs = res.statistics?.programs || [];
        const lectAgg = res.statistics?.lecturers || {};
        const thAgg = res.statistics?.theses || {};
        const stus = res.students || [];
        const theses = res.theses || [];
        const lects = res.lecturers || [];
        const canAddLect = Boolean(BOOT.user?.caps?.lecturers);
        return `
            <div class="orch-kpi-grid">
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Programs in Scope</div><div class="orch-kpi-val">${progs.length}</div></div><div class="orch-kpi-icon"><i class="bi bi-building"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Active Lecturers</div><div class="orch-kpi-val">${esc(lectAgg.total_lecturers || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-person-workspace"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Avg Teaching Score</div><div class="orch-kpi-val">${esc(lectAgg.avg_teaching_score || '0')}</div></div><div class="orch-kpi-icon"><i class="bi bi-award"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Theses At Risk</div><div class="orch-kpi-val">${esc(thAgg.risk_theses || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-exclamation-triangle"></i></div></div>
            </div>
            <div class="orch-card mb-3"><h6 class="fw-bold mb-2">Program Performance Summary</h6>
                <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Code</th><th>Study Program</th><th>Total</th><th>Active</th><th>Probation</th><th>Avg GPA</th><th>Avg Credits</th></tr></thead>
                <tbody>${progs.map((p) => `<tr><td><code>${esc(p.program_code)}</code></td><td>${esc(p.program_name)}</td><td>${esc(p.total_students)}</td><td>${esc(p.active_students)}</td><td>${esc(p.probation_students)}</td><td><strong>${esc(p.avg_gpa)}</strong></td><td>${esc(p.avg_credits)}</td></tr>`).join('')}</tbody></table></div>
            </div>
            <div class="orch-card mb-3">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <h6 class="fw-bold mb-0">Faculty Lecturers</h6>
                    ${canAddLect ? `<button type="button" class="btn btn-sm btn-primary py-0 px-2" data-ws-action="add_lecturer"><i class="bi bi-plus-lg me-1"></i>Add Lecturer</button>` : ''}
                </div>
                <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>NIDN</th><th>Lecturer</th><th>Prodi</th><th>Rank</th><th>Expertise</th><th>Teaching</th><th>Research</th><th>Pubs</th></tr></thead>
                <tbody>${lects.map((l) => `<tr><td><code>${esc(l.nidn)}</code></td><td class="fw-semibold">${esc(l.full_name)}</td><td><span class="orch-badge orch-badge-info">${esc(l.program_code)}</span></td><td>${esc(l.academic_rank)}</td><td>${esc(l.expertise)}</td><td>${esc(l.teaching_score)}</td><td>${esc(l.research_score)}</td><td>${esc(l.publication_count || 0)}</td></tr>`).join('')}</tbody></table></div>
            </div>
            <div class="row g-3">
                <div class="col-lg-6"><div class="orch-card"><h6 class="fw-bold mb-2">Students in Scope</h6>
                    <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>NIM</th><th>Name</th><th>Prodi</th><th>Sem</th><th>GPA</th><th>Status</th></tr></thead>
                    <tbody>${stus.map((s) => `<tr><td><code>${esc(s.nim)}</code></td><td>${esc(s.full_name)}</td><td>${esc(s.program_code)}</td><td>${esc(s.current_semester)}</td><td><strong>${esc(s.gpa)}</strong></td><td><span class="orch-badge ${s.academic_status === 'probation' ? 'orch-badge-warn' : 'orch-badge-ok'}">${esc(s.academic_status)}</span></td></tr>`).join('')}</tbody></table></div>
                </div></div>
                <div class="col-lg-6"><div class="orch-card"><h6 class="fw-bold mb-2">Thesis Monitoring</h6>
                    <div class="orch-table-wrap"><table class="table orch-table"><thead><tr><th>Student</th><th>Title</th><th>Stage</th><th>Progress</th><th>Status</th></tr></thead>
                    <tbody>${theses.map((t) => `<tr><td>${esc(t.student_name)}</td><td>${esc(t.title)}</td><td>${esc(t.stage)}</td><td>${esc(t.progress_pct)}%</td><td><span class="orch-badge ${t.status === 'at_risk' || t.status === 'delayed' ? 'orch-badge-warn' : 'orch-badge-ok'}">${esc(t.status)}</span></td></tr>`).join('')}</tbody></table></div>
                </div></div>
            </div>`;
    }

    _bindAgentsModule() {
        document.getElementById('agentSearch')?.addEventListener('input', () => this.loadAgentsList());
        document.getElementById('agentCatFilter')?.addEventListener('change', () => this.loadAgentsList());
        document.getElementById('agentStatusFilter')?.addEventListener('change', () => this.loadAgentsList());

        document.getElementById('btnNewAgent')?.addEventListener('click', () => this.openAgentModal());
        document.getElementById('btnOpenAiBuilder')?.addEventListener('click', () => {
            this.state.aiProposalDraft = null;
            document.getElementById('aiBuilderPrompt').value = '';
            document.getElementById('aiBuilderPreview').innerHTML = '';
            document.getElementById('btnSaveAiDraft').classList.add('d-none');
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAiAgentBuilder')).show();
        });

        document.getElementById('btnGenerateAiAgent')?.addEventListener('click', async () => {
            const desc = document.getElementById('aiBuilderPrompt').value.trim();
            if (!desc) return toast('Enter an agent description first', true);
            const btn = document.getElementById('btnGenerateAiAgent');
            btn.disabled = true;
            btn.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Generating...`;
            const res = await dashApi('ai_assist_build_agent', 'POST', { description: desc });
            btn.disabled = false;
            btn.innerHTML = `<i class="bi bi-stars me-1"></i>Generate Proposed Config`;
            if (!res.success) return toast(res.message || 'AI proposal failed', true);
            this.state.aiProposalDraft = res.proposal;
            const p = res.proposal;
            document.getElementById('aiBuilderPreview').innerHTML = `
                <div class="alert alert-warning py-2 small mb-2"><strong>Safety Gate:</strong> Draft starts <em>inactive / pending_approval</em> and cannot execute until approved by an Administrator.</div>
                <div class="border rounded p-3 bg-light small">
                    <div><strong>Code:</strong> <code>${esc(p.agent_code)}</code> · <strong>Name:</strong> ${esc(p.agent_name)}</div>
                    <div><strong>Category:</strong> ${esc(p.agent_category)} · <strong>Zone:</strong> ${esc(p.zone_code)} · <strong>Model:</strong> ${esc(p.model_name)}</div>
                    <div class="mt-2"><strong>Description:</strong> ${esc(p.description)}</div>
                    <div class="mt-2"><strong>Roles:</strong> ${(p.role_assignments || []).map((r) => `${esc(r.role_code)} (${esc(r.data_scope)})`).join(', ')}</div>
                    <div class="mt-2"><strong>System Prompt:</strong><pre class="mb-0 mt-1 p-2 bg-white border rounded small">${esc(p.system_prompt)}</pre></div>
                </div>`;
            document.getElementById('btnSaveAiDraft').classList.remove('d-none');
        });

        document.getElementById('btnSaveAiDraft')?.addEventListener('click', async () => {
            if (!this.state.aiProposalDraft) return;
            const res = await dashApi('save_agent', 'POST', this.state.aiProposalDraft);
            if (!res.success) return toast(res.message || 'Save failed', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAiAgentBuilder')).hide();
            this.loadAgentsList();
        });

        document.getElementById('agentForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const roleAssignments = [];
            document.querySelectorAll('#agentRolesConfig input[type="checkbox"]:checked').forEach((chk) => {
                const rid = parseInt(chk.value, 10);
                const scopeSel = document.getElementById(`agRoleScope_${rid}`);
                roleAssignments.push({
                    role_id: rid,
                    role_code: chk.dataset.roleCode,
                    data_scope: scopeSel ? scopeSel.value : 'OWN_STUDY_PROGRAM'
                });
            });
            const toolIds = [...document.querySelectorAll('#agentToolsConfig input[type="checkbox"]:checked')].map((c) => parseInt(c.value, 10));
            const kbIds = [...document.querySelectorAll('#agentKbConfig input[type="checkbox"]:checked')].map((c) => parseInt(c.value, 10));

            const payload = {
                id: parseInt(document.getElementById('agId').value || '0', 10),
                agent_code: document.getElementById('agCode').value.trim(),
                agent_name: document.getElementById('agName').value.trim(),
                description: document.getElementById('agDesc').value.trim(),
                agent_category: document.getElementById('agCategory').value,
                zone_code: document.getElementById('agZone').value,
                model_name: document.getElementById('agModel').value,
                temperature: document.getElementById('agTemp').value,
                max_output_tokens: parseInt(document.getElementById('agMaxTokens').value || '900', 10),
                priority: parseInt(document.getElementById('agPriority').value || '60', 10),
                avatar_model: document.getElementById('agAvatarModel').value,
                animation_profile: document.getElementById('agAnimProfile').value,
                status: document.getElementById('agStatus').value,
                system_prompt: document.getElementById('agPrompt').value.trim(),
                change_note: document.getElementById('agChangeNote').value.trim(),
                role_assignments: roleAssignments,
                tool_ids: toolIds,
                kb_ids: kbIds
            };
            const res = await dashApi('save_agent', 'POST', payload);
            if (!res.success) return toast(res.message || 'Save failed', true);
            toast(res.message);
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAgent')).hide();
            this.loadAgentsList();
            this._bootstrapOrchestrator();
        });

        document.getElementById('btnRunSelfLearn')?.addEventListener('click', async () => {
            const res = await dashApi('run_self_learning_cycle', 'POST', {});
            toast(res.message || (res.success ? 'Cycle complete' : 'Cycle failed'), !res.success);
        });

        document.getElementById('tblAgentsBody')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-ag-act]');
            if (!btn) return;
            const id = parseInt(btn.dataset.agId, 10);
            const act = btn.dataset.agAct;
            if (act === 'edit') {
                this.openAgentModal(id);
            } else if (act === 'versions') {
                this.openAgentVersionsModal(id);
            } else if (act === 'approve') {
                const res = await dashApi('approve_agent', 'POST', { agent_id: id });
                toast(res.message, !res.success);
                if (res.success) { this.loadAgentsList(); this._bootstrapOrchestrator(); }
            } else if (act === 'clone') {
                const res = await dashApi('duplicate_agent', 'POST', { agent_id: id });
                toast(res.message, !res.success);
                if (res.success) this.loadAgentsList();
            } else if (act === 'toggle') {
                const next = btn.dataset.nextStatus || 'active';
                const res = await dashApi('toggle_agent_status', 'POST', { agent_id: id, status: next });
                toast(res.message, !res.success);
                if (res.success) { this.loadAgentsList(); this._bootstrapOrchestrator(); }
            }
        });

        document.getElementById('agentVersionsList')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-rollback-ver]');
            if (!btn) return;
            const res = await dashApi('rollback_agent_version', 'POST', {
                agent_id: parseInt(btn.dataset.agentId, 10),
                version_id: parseInt(btn.dataset.rollbackVer, 10)
            });
            toast(res.message, !res.success);
            if (res.success) {
                this.openAgentVersionsModal(parseInt(btn.dataset.agentId, 10));
                this.loadAgentsList();
            }
        });

        document.getElementById('btnRunSandboxTest')?.addEventListener('click', async () => {
            const aid = parseInt(document.getElementById('sbxAgentId').value || '0', 10);
            const prompt = document.getElementById('sbxPrompt').value.trim();
            const out = document.getElementById('sbxOutput');
            if (!aid || !prompt) return;
            out.innerHTML = `<span class="spinner-border spinner-border-sm me-1"></span>Executing sandbox run...`;
            const res = await dashApi('test_agent_sandbox', 'POST', { agent_id: aid, prompt });
            if (!res.success) {
                out.innerHTML = `<span class="text-danger">${esc(res.message || 'Failed')}</span>`;
                return;
            }
            const r = res.result || {};
            out.innerHTML = `<div>${formatReplyHtml(r.reply || '')}</div><div class="small text-muted mt-2">Tokens: ${esc(r.total_tokens)} · Tools: ${(r.tool_traces || []).length} · Latency: ${esc(r.latency_ms)}ms</div>`;
        });
    }

    async loadAgentsList() {
        const res = await dashApi('agents_list', 'GET', {
            search: document.getElementById('agentSearch')?.value || '',
            category: document.getElementById('agentCatFilter')?.value || '',
            status: document.getElementById('agentStatusFilter')?.value || ''
        });
        const tb = document.getElementById('tblAgentsBody');
        if (!tb) return;
        const items = res.items || [];
        this.state.agentsCache = items;
        tb.innerHTML = items.map((a) => {
            const pending = a.approval_status === 'pending_approval';
            const approveBtn = (pending && hasPerm('agents.approve'))
                ? `<button class="btn btn-sm btn-success py-0 px-2" data-ag-act="approve" data-ag-id="${a.id}" title="Approve & Activate"><i class="bi bi-check2-circle"></i> Approve</button>`
                : '';
            const nextSt = a.status === 'active' ? 'inactive' : 'active';
            return `<tr>
                <td><code>${esc(a.agent_code)}</code>${a.is_ai_generated ? ' <span class="orch-badge orch-badge-info">AI</span>' : ''}</td>
                <td><strong>${esc(a.agent_name)}</strong><div class="small text-muted text-truncate" style="max-width:260px">${esc(a.description)}</div></td>
                <td><span class="orch-badge orch-badge-muted">${esc(a.agent_category)}</span></td>
                <td><small>${esc(a.role_codes || '-')}</small></td>
                <td><code>${esc(a.model_name || 'gpt-4.1-mini')}</code></td>
                <td>v${esc(a.latest_version || 1)}</td>
                <td>
                    <span class="orch-badge ${a.status === 'active' ? 'orch-badge-ok' : 'orch-badge-muted'}">${esc(a.status)}</span>
                    ${pending ? '<span class="orch-badge orch-badge-warn ms-1">pending</span>' : ''}
                </td>
                <td class="text-nowrap">
                    ${approveBtn}
                    <button class="btn btn-sm btn-outline-primary py-0 px-1" data-ag-act="edit" data-ag-id="${a.id}" title="Edit"><i class="bi bi-pencil"></i></button>
                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-ag-act="versions" data-ag-id="${a.id}" title="Versions &amp; Sandbox"><i class="bi bi-clock-history"></i></button>
                    <button class="btn btn-sm btn-outline-info py-0 px-1" data-ag-act="clone" data-ag-id="${a.id}" title="Duplicate"><i class="bi bi-copy"></i></button>
                    <button class="btn btn-sm btn-outline-${a.status === 'active' ? 'warning' : 'success'} py-0 px-1" data-ag-act="toggle" data-ag-id="${a.id}" data-next-status="${nextSt}" title="Toggle Active"><i class="bi bi-power"></i></button>
                </td>
            </tr>`;
        }).join('') || '<tr><td colspan="8" class="text-center text-muted py-4">No agents matched filter</td></tr>';
    }

    async openAgentModal(agentId = 0) {
        if (!this.state.toolsCache.length) {
            const tRes = await dashApi('tools_list', 'GET');
            this.state.toolsCache = tRes.tools || [];
        }
        if (!this.state.kbCache.length) {
            const kRes = await dashApi('kb_list', 'GET');
            this.state.kbCache = kRes.knowledge_bases || [];
        }
        let detail = null;
        if (agentId > 0) {
            const res = await dashApi('agent_detail', 'GET', { agent_id: agentId });
            detail = res.agent || null;
        }

        document.getElementById('agId').value = detail ? detail.id : 0;
        document.getElementById('agCode').value = detail ? detail.agent_code : '';
        document.getElementById('agName').value = detail ? detail.agent_name : '';
        document.getElementById('agDesc').value = detail ? detail.description : '';
        document.getElementById('agCategory').value = detail ? detail.agent_category : 'CUSTOM';
        document.getElementById('agZone').value = detail ? detail.zone_code : 'COMMAND_CENTER';
        document.getElementById('agModel').value = detail ? (detail.model_name || 'gpt-4.1-mini') : 'gpt-4.1-mini';
        document.getElementById('agTemp').value = detail ? (detail.temperature ?? '0.40') : '0.40';
        document.getElementById('agMaxTokens').value = detail ? (detail.max_output_tokens ?? 900) : 900;
        document.getElementById('agPriority').value = detail ? (detail.priority ?? 60) : 60;
        document.getElementById('agAvatarModel').value = detail ? detail.avatar_model : 'chibi_orchestrator';
        document.getElementById('agAnimProfile').value = detail ? detail.animation_profile : 'executive_briefing';
        document.getElementById('agStatus').value = detail ? detail.status : 'active';
        document.getElementById('agPrompt').value = detail ? (detail.active_prompt || '') : '';
        document.getElementById('agChangeNote').value = '';

        const assignedRoles = {};
        (detail?.roles || []).forEach((r) => { assignedRoles[Number(r.role_id)] = r.data_scope; });
        const roleScopes = ['ALL_FACULTY', 'OWN_STUDY_PROGRAM', 'OWN_STUDENT', 'QUALITY_ASSURANCE', 'RESEARCH', 'ADMINISTRATION'];

        document.getElementById('agentRolesConfig').innerHTML = (BOOT.rolesCatalog || []).map((r) => {
            const rid = Number(r.id);
            const checked = Object.prototype.hasOwnProperty.call(assignedRoles, rid) ? 'checked' : '';
            const curScope = assignedRoles[rid] || 'OWN_STUDY_PROGRAM';
            return `<div class="d-flex align-items-center justify-content-between gap-2 py-1 border-bottom">
                <label class="form-check-label small mb-0">
                    <input class="form-check-input me-1" type="checkbox" value="${rid}" data-role-code="${esc(r.role_code)}" ${checked}>
                    <strong>${esc(r.role_code)}</strong>
                </label>
                <select id="agRoleScope_${rid}" class="form-select form-select-sm w-auto">${roleScopes.map((sc) => `<option value="${sc}" ${sc === curScope ? 'selected' : ''}>${sc}</option>`).join('')}</select>
            </div>`;
        }).join('');

        const assignedTools = new Set((detail?.tools || []).map((t) => Number(t.id)));
        document.getElementById('agentToolsConfig').innerHTML = this.state.toolsCache.map((t) => `
            <label class="form-check form-check-inline small me-3 mb-1">
                <input class="form-check-input" type="checkbox" value="${t.id}" ${assignedTools.has(Number(t.id)) ? 'checked' : ''}>
                <span class="form-check-label">${esc(t.tool_name)}</span>
            </label>`).join('');

        const assignedKbs = new Set((detail?.knowledge_bases || []).map((k) => Number(k.id)));
        document.getElementById('agentKbConfig').innerHTML = this.state.kbCache.map((k) => `
            <label class="form-check form-check-inline small me-3 mb-1">
                <input class="form-check-input" type="checkbox" value="${k.id}" ${assignedKbs.has(Number(k.id)) ? 'checked' : ''}>
                <span class="form-check-label">${esc(k.kb_name)}</span>
            </label>`).join('');

        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAgent')).show();
    }

    async openAgentVersionsModal(agentId) {
        const res = await dashApi('agent_detail', 'GET', { agent_id: agentId });
        if (!res.success || !res.agent) return toast('Agent detail unavailable', true);
        const ag = res.agent;
        document.getElementById('sbxAgentId').value = ag.id;
        document.getElementById('sbxOutput').innerHTML = 'Ready to test.';
        const vers = ag.versions || [];
        document.getElementById('agentVersionsList').innerHTML = vers.map((v) => `
            <div class="d-flex justify-content-between align-items-center border-bottom py-2 small">
                <div><strong>v${esc(v.version_number)}</strong> — ${esc(v.change_note || 'Snapshot')} <span class="text-muted">(${esc(v.created_at)})</span></div>
                <button class="btn btn-sm btn-outline-warning py-0" data-rollback-ver="${v.id}" data-agent-id="${ag.id}">Rollback</button>
            </div>`).join('') || '<div class="text-muted small">No snapshots</div>';
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalAgentVersions')).show();
    }

    _bindUsersModule() {
        document.getElementById('userSearch')?.addEventListener('input', () => this.loadUsersList());
        document.getElementById('userRoleFilter')?.addEventListener('change', () => this.loadUsersList());
        document.getElementById('btnNewUser')?.addEventListener('click', () => this.openUserModal());

        document.getElementById('btnImportUsers')?.addEventListener('click', () => {
            this.state.importTargetType = 'users';
            document.getElementById('importModalTitle').textContent = 'Import Users from Excel / CSV';
            document.getElementById('importFile').value = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalImportExcel')).show();
        });

        document.getElementById('importExcelForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const f = document.getElementById('importFile').files[0];
            if (!f) return toast('Choose XLSX or CSV file', true);
            const fd = new FormData();
            fd.append('excel_file', f);
            const endpoint = this.state.importTargetType === 'students' ? 'import_students_excel' : 'import_users_excel';
            const res = await dashApi(endpoint, 'POST', fd, true);
            toast(res.message || 'Import complete', !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalImportExcel')).hide();
                if (this.state.importTargetType === 'students') this.loadStudentsList();
                else this.loadUsersList();
            }
        });

        document.getElementById('userForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const roles = [...document.querySelectorAll('#usrRoleBoxes input[type="checkbox"]:checked')].map((c) => c.value);
            const res = await dashApi('save_user', 'POST', {
                id: parseInt(document.getElementById('usrId').value || '0', 10),
                username: document.getElementById('usrUsername').value.trim(),
                email: document.getElementById('usrEmail').value.trim(),
                full_name: document.getElementById('usrFullName').value.trim(),
                department: document.getElementById('usrDept').value.trim(),
                phone: document.getElementById('usrPhone').value.trim(),
                study_program_id: document.getElementById('usrProg').value || null,
                status: document.getElementById('usrStatus').value,
                password: document.getElementById('usrPassword').value,
                role_codes: roles
            });
            toast(res.message, !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUser')).hide();
                this.loadUsersList();
            }
        });

        document.getElementById('tblUsersBody')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-usr-act]');
            if (!btn) return;
            const id = parseInt(btn.dataset.usrId, 10);
            const act = btn.dataset.usrAct;
            if (act === 'edit') {
                const u = this.state.usersCache.find((x) => Number(x.id) === id);
                if (u) this.openUserModal(u);
            } else if (act === 'toggle') {
                const next = btn.dataset.nextStatus;
                const res = await dashApi('toggle_user_status', 'POST', { id, status: next });
                toast(res.message, !res.success);
                if (res.success) this.loadUsersList();
            } else if (act === 'reset_pwd') {
                const tabBtn = document.getElementById('btnTabResetPwd');
                const sel = document.getElementById('adminResetUserId');
                if (sel) sel.value = String(id);
                if (tabBtn && typeof window.bootstrap !== 'undefined') {
                    window.bootstrap.Tab.getOrCreateInstance(tabBtn).show();
                }
                document.getElementById('adminResetNewPwd')?.focus();
            } else if (act === 'activity') {
                const res = await dashApi('user_activity', 'GET', { user_id: id });
                const body = document.getElementById('userActivityBody');
                if (body && res.success) {
                    const logs = res.activity?.logins || [];
                    const audits = res.activity?.audits || [];
                    body.innerHTML = `<h6 class="fw-bold small">Login History</h6>` +
                        logs.map((l) => `<div class="small border-bottom py-1">${esc(l.status)} — ${esc(l.ip_address)} (${esc(l.created_at)})</div>`).join('') +
                        `<h6 class="fw-bold small mt-3">Recent Actions</h6>` +
                        audits.map((a) => `<div class="small border-bottom py-1">${esc(a.action)}: ${esc(a.details)} (${esc(a.created_at)})</div>`).join('');
                    bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUserActivity')).show();
                }
            } else if (act === 'delete') {
                if (!window.confirm('Archive this user account according to retention policy?')) return;
                const res = await dashApi('delete_user', 'POST', { id });
                toast(res.message, !res.success);
                if (res.success) this.loadUsersList();
            }
        });

        document.getElementById('btnNewStudyProgram')?.addEventListener('click', () => {
            document.getElementById('spId').value = 0;
            document.getElementById('spCode').value = '';
            document.getElementById('spName').value = '';
            document.getElementById('spDegree').value = 'S1';
            document.getElementById('spAccred').value = 'Baik Sekali';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalStudyProgram')).show();
        });

        document.getElementById('studyProgramForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await dashApi('save_study_program', 'POST', {
                id: parseInt(document.getElementById('spId').value || '0', 10),
                program_code: document.getElementById('spCode').value.trim(),
                program_name: document.getElementById('spName').value.trim(),
                degree_level: document.getElementById('spDegree').value,
                accreditation_grade: document.getElementById('spAccred').value.trim(),
                is_active: 1
            });
            toast(res.message, !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalStudyProgram')).hide();
                this.loadStudyPrograms();
            }
        });

        document.getElementById('rbacMatrixWrap')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-save-role-id]');
            if (!btn) return;
            const rid = parseInt(btn.dataset.saveRoleId, 10);
            const permIds = [...document.querySelectorAll(`input[data-role-perm-rid="${rid}"]:checked`)].map((c) => parseInt(c.value, 10));
            const res = await dashApi('save_role_permissions', 'POST', { role_id: rid, permission_ids: permIds });
            toast(res.message, !res.success);
        });

        document.getElementById('btnToggleAdminResetPwd')?.addEventListener('click', () => {
            const p1 = document.getElementById('adminResetNewPwd');
            const p2 = document.getElementById('adminResetConfirmPwd');
            if (!p1) return;
            const nextType = p1.type === 'password' ? 'text' : 'password';
            p1.type = nextType;
            if (p2) p2.type = nextType;
        });

        document.getElementById('btnGenStrongPwd')?.addEventListener('click', () => {
            const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
            const arr = new Uint32Array(12);
            window.crypto.getRandomValues(arr);
            const pwd = 'Fk' + Array.from(arr, (v) => chars[v % chars.length]).join('');
            const p1 = document.getElementById('adminResetNewPwd');
            const p2 = document.getElementById('adminResetConfirmPwd');
            if (p1) { p1.value = pwd; p1.type = 'text'; }
            if (p2) { p2.value = pwd; p2.type = 'text'; }
            toast('Strong password generated.');
        });

        document.getElementById('btnCopyResetPwd')?.addEventListener('click', async () => {
            const val = document.getElementById('adminResetNewPwd')?.value || '';
            if (!val) return toast('Generate or enter a password first.', true);
            try {
                await navigator.clipboard.writeText(val);
                toast('Password copied to clipboard.');
            } catch (_) {
                toast('Copy failed; select password manually.', true);
            }
        });

        document.getElementById('adminResetPwdForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const uid = parseInt(document.getElementById('adminResetUserId')?.value || '0', 10);
            const p1 = document.getElementById('adminResetNewPwd')?.value || '';
            const p2 = document.getElementById('adminResetConfirmPwd')?.value || '';
            const revoke = document.getElementById('adminResetRevokeSessions')?.checked ? 1 : 0;
            if (uid <= 0) return toast('Please select a target user.', true);
            if (p1.length < 8) return toast('Password must be at least 8 characters.', true);
            if (p1 !== p2) return toast('Password confirmation does not match.', true);
            const res = await dashApi('reset_user_password', 'POST', {
                id: uid,
                new_password: p1,
                revoke_sessions: revoke
            });
            toast(res.message, !res.success);
            if (res.success) {
                document.getElementById('adminResetNewPwd').value = '';
                document.getElementById('adminResetConfirmPwd').value = '';
                this.loadPasswordResetHistory();
                this.loadProfileSessions();
            }
        });
    }

    async loadPasswordResetHistory() {
        if (!hasPerm('users.manage')) return;
        const res = await dashApi('password_reset_history', 'GET');
        const tb = document.getElementById('tblResetPwdAudit');
        if (!tb) return;
        tb.innerHTML = (res.history || []).map((h) => `<tr>
            <td><small>${esc(h.created_at)}</small></td>
            <td><strong>${esc(h.actor || 'admin')}</strong></td>
            <td>${esc(h.description)}</td>
            <td><code>${esc(h.ip_address || '-')}</code></td>
        </tr>`).join('') || '<tr><td colspan="4" class="text-center text-muted py-3">No admin password resets recorded yet</td></tr>';
    }

    async loadUsersList() {
        if (!hasPerm('users.manage')) return;
        const res = await dashApi('users_list', 'GET', {
            search: document.getElementById('userSearch')?.value || '',
            role_code: document.getElementById('userRoleFilter')?.value || ''
        });
        const items = res.items || [];
        this.state.usersCache = items;
        const resetSel = document.getElementById('adminResetUserId');
        if (resetSel) {
            const prev = resetSel.value;
            resetSel.innerHTML = `<option value="">-- Select User Account --</option>` +
                items.map((u) => `<option value="${u.id}">@${esc(u.username)} — ${esc(u.full_name)} [${esc((u.role_codes || []).join(', '))}]</option>`).join('');
            if (prev) resetSel.value = prev;
        }
        const tb = document.getElementById('tblUsersBody');
        if (!tb) return;
        tb.innerHTML = items.map((u) => `<tr>
            <td><code>${esc(u.username)}</code></td>
            <td><strong>${esc(u.full_name)}</strong><div class="small text-muted">${esc(u.email)}</div></td>
            <td>${(u.role_codes || []).map((rc) => `<span class="orch-badge orch-badge-info me-1">${esc(rc)}</span>`).join('')}</td>
            <td>${esc((u.program_codes || []).join(', ') || u.department)}</td>
            <td><span class="orch-badge ${u.status === 'active' ? 'orch-badge-ok' : 'orch-badge-warn'}">${esc(u.status)}</span></td>
            <td class="text-nowrap">
                <button class="btn btn-sm btn-outline-primary py-0 px-1" data-usr-act="edit" data-usr-id="${u.id}" title="Edit"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-secondary py-0 px-1" data-usr-act="activity" data-usr-id="${u.id}" title="Activity"><i class="bi bi-activity"></i></button>
                <button class="btn btn-sm btn-outline-warning py-0 px-1" data-usr-act="reset_pwd" data-usr-id="${u.id}" title="Reset Password"><i class="bi bi-key"></i></button>
                <button class="btn btn-sm btn-outline-info py-0 px-1" data-usr-act="toggle" data-usr-id="${u.id}" data-next-status="${u.status === 'active' ? 'inactive' : 'active'}" title="Toggle Status"><i class="bi bi-power"></i></button>
                <button class="btn btn-sm btn-outline-danger py-0 px-1" data-usr-act="delete" data-usr-id="${u.id}" title="Archive"><i class="bi bi-trash"></i></button>
            </td>
        </tr>`).join('') || '<tr><td colspan="6" class="text-center text-muted py-3">No users found</td></tr>';
    }

    openUserModal(user = null) {
        document.getElementById('usrId').value = user ? user.id : 0;
        document.getElementById('usrUsername').value = user ? user.username : '';
        document.getElementById('usrEmail').value = user ? user.email : '';
        document.getElementById('usrFullName').value = user ? user.full_name : '';
        document.getElementById('usrDept').value = user ? (user.department || 'Fakultas Ilmu Komputer') : 'Fakultas Ilmu Komputer';
        document.getElementById('usrPhone').value = user ? (user.phone || '') : '';
        document.getElementById('usrStatus').value = user ? user.status : 'active';
        document.getElementById('usrPassword').value = '';
        const selected = new Set(user?.role_codes || ['STUDENT']);
        document.querySelectorAll('#usrRoleBoxes input[type="checkbox"]').forEach((c) => {
            c.checked = selected.has(c.value);
        });
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalUser')).show();
    }

    async loadRolesMatrix() {
        if (!hasPerm('roles.manage')) return;
        const wrap = document.getElementById('rbacMatrixWrap');
        if (!wrap) return;
        const res = await dashApi('roles_list', 'GET');
        if (!res.success) return;
        const roles = res.matrix?.roles || [];
        const perms = res.matrix?.permissions || [];
        const map = res.matrix?.role_permissions || {};
        wrap.innerHTML = roles.map((r) => {
            const rPerms = new Set((map[r.id] || []).map(Number));
            return `<div class="border rounded p-2 mb-2 bg-white">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <strong>${esc(r.role_code)} — ${esc(r.role_name)}</strong>
                    <button class="btn btn-sm btn-primary py-0" data-save-role-id="${r.id}">Save Permissions</button>
                </div>
                <div class="d-flex flex-wrap gap-2">${perms.map((p) => `
                    <label class="form-check form-check-inline small mb-0">
                        <input class="form-check-input" type="checkbox" data-role-perm-rid="${r.id}" value="${p.id}" ${rPerms.has(Number(p.id)) ? 'checked' : ''}>
                        <span class="form-check-label">${esc(p.permission_code)}</span>
                    </label>`).join('')}
                </div>
            </div>`;
        }).join('');
    }

    async loadStudyPrograms() {
        const res = await dashApi('study_programs_list', 'GET');
        const tb = document.getElementById('tblProgramsBody');
        if (!tb) return;
        tb.innerHTML = (res.programs || []).map((p) => `<tr>
            <td><code>${esc(p.program_code)}</code></td><td>${esc(p.program_name)}</td>
            <td>${esc(p.degree_level)}</td><td>${esc(p.accreditation_grade)}</td>
            <td>${esc(p.active_students)}</td><td>${esc(p.total_lecturers)}</td>
        </tr>`).join('');
    }

    _bindPositionsModule() {
        document.getElementById('posRoleFilter')?.addEventListener('change', () => this.loadPositionHistory());
        document.getElementById('posStatusFilter')?.addEventListener('change', () => this.loadPositionHistory());

        document.getElementById('btnReplacePosition')?.addEventListener('click', async () => {
            if (!this.state.usersCache.length) {
                const res = await dashApi('users_list', 'GET', { per_page: 100 });
                this.state.usersCache = res.items || [];
            }
            const sel = document.getElementById('posNewUser');
            if (sel) {
                sel.innerHTML = this.state.usersCache.map((u) => `<option value="${u.id}">${esc(u.full_name)} (${esc(u.username)})</option>`).join('');
            }
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalReplacePosition')).show();
        });

        document.getElementById('replacePosForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await dashApi('replace_position_holder', 'POST', {
                role_code: document.getElementById('posRoleCode').value,
                new_user_id: parseInt(document.getElementById('posNewUser').value || '0', 10),
                replacement_reason: document.getElementById('posReason').value.trim(),
                effective_at: document.getElementById('posEffectiveAt').value.trim()
            });
            toast(res.message, !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalReplacePosition')).hide();
                this.loadPositionHistory();
            }
        });
    }

    async loadPositionHistory() {
        if (!hasPerm('positions.manage')) return;
        const res = await dashApi('position_history_list', 'GET', {
            role_code: document.getElementById('posRoleFilter')?.value || '',
            status: document.getElementById('posStatusFilter')?.value || ''
        });
        const tb = document.getElementById('tblPositionsBody');
        if (!tb) return;
        tb.innerHTML = (res.items || []).map((h) => `<tr>
            <td><span class="orch-badge orch-badge-info">${esc(h.role_code)}</span> ${esc(h.program_code || '')}</td>
            <td><strong>${esc(h.holder_name)}</strong> <small class="text-muted">(@${esc(h.holder_username)})</small></td>
            <td>${esc(h.replaced_holder_name || '-')}</td>
            <td>${esc(h.assigned_at)}</td>
            <td>${esc(h.ended_at || 'Present')}</td>
            <td><span class="orch-badge ${h.assignment_status === 'active' ? 'orch-badge-ok' : 'orch-badge-muted'}">${esc(h.assignment_status)}</span></td>
            <td><small>${esc(h.replacement_reason)}</small></td>
        </tr>`).join('') || '<tr><td colspan="7" class="text-center text-muted py-3">No assignment records</td></tr>';
    }

    _bindStudentsModule() {
        document.getElementById('stuSearch')?.addEventListener('input', () => this.loadStudentsList());
        document.getElementById('stuProgFilter')?.addEventListener('change', () => this.loadStudentsList());
        document.getElementById('stuStatusFilter')?.addEventListener('change', () => this.loadStudentsList());

        document.getElementById('btnNewStudent')?.addEventListener('click', () => this.openStudentModal());
        document.getElementById('btnImportStudents')?.addEventListener('click', () => {
            this.state.importTargetType = 'students';
            document.getElementById('importModalTitle').textContent = 'Import Students from Excel / CSV';
            document.getElementById('importFile').value = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalImportExcel')).show();
        });

        document.getElementById('studentForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await dashApi('save_student', 'POST', {
                id: parseInt(document.getElementById('stuId').value || '0', 10),
                nim: document.getElementById('stuNim').value.trim(),
                full_name: document.getElementById('stuName').value.trim(),
                study_program_id: parseInt(document.getElementById('stuProg').value || '1', 10),
                cohort_year: parseInt(document.getElementById('stuCohort').value || '2024', 10),
                current_semester: parseInt(document.getElementById('stuSem').value || '1', 10),
                gpa: document.getElementById('stuGpa').value,
                total_credits: parseInt(document.getElementById('stuCredits').value || '0', 10),
                academic_status: document.getElementById('stuStatus').value,
                create_login: document.getElementById('stuCreateLogin').checked ? 1 : 0
            });
            toast(res.message, !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalStudent')).hide();
                this.loadStudentsList();
            }
        });

        document.getElementById('tblStudentsBody')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-stu-act]');
            if (!btn) return;
            const id = parseInt(btn.dataset.stuId, 10);
            const act = btn.dataset.stuAct;
            if (act === 'edit') {
                const res = await dashApi('students_list', 'GET', { per_page: 100 });
                const s = (res.items || []).find((x) => Number(x.id) === id);
                if (s) this.openStudentModal(s);
            } else if (act === 'map') {
                if (!this.state.usersCache.length) {
                    const uRes = await dashApi('users_list', 'GET', { per_page: 100 });
                    this.state.usersCache = uRes.items || [];
                }
                document.getElementById('mapStuId').value = id;
                const sel = document.getElementById('mapStuUserId');
                sel.innerHTML = `<option value="">-- Unlink Login Account --</option>` +
                    this.state.usersCache.map((u) => `<option value="${u.id}">${esc(u.username)} — ${esc(u.full_name)}</option>`).join('');
                sel.value = btn.dataset.uid || '';
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMapStudent')).show();
            } else if (act === 'reset_pwd') {
                const pwd = window.prompt('New student password (min 6 chars):', 'Student123!');
                if (!pwd) return;
                const res = await dashApi('reset_student_password', 'POST', { student_id: id, new_password: pwd });
                toast(res.message, !res.success);
            }
        });

        document.getElementById('mapStudentForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await dashApi('map_student_account', 'POST', {
                student_id: parseInt(document.getElementById('mapStuId').value || '0', 10),
                user_id: document.getElementById('mapStuUserId').value || null
            });
            toast(res.message, !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalMapStudent')).hide();
                this.loadStudentsList();
            }
        });
    }

    async loadStudentsList() {
        const res = await dashApi('students_list', 'GET', {
            search: document.getElementById('stuSearch')?.value || '',
            study_program_id: document.getElementById('stuProgFilter')?.value || '',
            academic_status: document.getElementById('stuStatusFilter')?.value || ''
        });
        const tb = document.getElementById('tblStudentsBody');
        if (!tb) return;
        const canManage = hasPerm('students.manage');
        tb.innerHTML = (res.items || []).map((s) => `<tr>
            <td><code>${esc(s.nim)}</code></td>
            <td><strong>${esc(s.full_name)}</strong></td>
            <td><span class="orch-badge orch-badge-info">${esc(s.program_code)}</span></td>
            <td>Sem ${esc(s.current_semester)} (${esc(s.cohort_year)})</td>
            <td><strong>${esc(s.gpa)}</strong></td>
            <td>${esc(s.total_credits)}</td>
            <td><span class="orch-badge ${s.academic_status === 'probation' ? 'orch-badge-warn' : 'orch-badge-ok'}">${esc(s.academic_status)}</span></td>
            <td>${s.login_username ? `<code>@${esc(s.login_username)}</code>` : '<span class="text-muted">Unlinked</span>'}</td>
            <td class="text-nowrap">${canManage ? `
                <button class="btn btn-sm btn-outline-primary py-0 px-1" data-stu-act="edit" data-stu-id="${s.id}" title="Edit"><i class="bi bi-pencil"></i></button>
                <button class="btn btn-sm btn-outline-info py-0 px-1" data-stu-act="map" data-stu-id="${s.id}" data-uid="${esc(s.user_id || '')}" title="Map Login"><i class="bi bi-link-45deg"></i></button>
                <button class="btn btn-sm btn-outline-warning py-0 px-1" data-stu-act="reset_pwd" data-stu-id="${s.id}" title="Reset Password"><i class="bi bi-key"></i></button>` : '-'}
            </td>
        </tr>`).join('') || '<tr><td colspan="9" class="text-center text-muted py-3">No student records</td></tr>';
    }

    openStudentModal(stu = null) {
        document.getElementById('stuId').value = stu ? stu.id : 0;
        document.getElementById('stuNim').value = stu ? stu.nim : '';
        document.getElementById('stuName').value = stu ? stu.full_name : '';
        document.getElementById('stuProg').value = stu ? stu.study_program_id : 1;
        document.getElementById('stuCohort').value = stu ? stu.cohort_year : new Date().getFullYear();
        document.getElementById('stuSem').value = stu ? stu.current_semester : 1;
        document.getElementById('stuGpa').value = stu ? stu.gpa : '3.25';
        document.getElementById('stuCredits').value = stu ? stu.total_credits : 20;
        document.getElementById('stuStatus').value = stu ? stu.academic_status : 'active';
        document.getElementById('stuCreateLogin').checked = !stu;
        bootstrap.Modal.getOrCreateInstance(document.getElementById('modalStudent')).show();
    }

    _bindKnowledgeToolsModule() {
        document.getElementById('ragSearch')?.addEventListener('input', () => this.loadRagDocs());
        document.getElementById('ragKbFilter')?.addEventListener('change', () => this.loadRagDocs());

        document.getElementById('btnNewKb')?.addEventListener('click', () => {
            document.getElementById('kbId').value = 0;
            document.getElementById('kbCode').value = '';
            document.getElementById('kbName').value = '';
            document.getElementById('kbCategory').value = 'Academic';
            document.getElementById('kbDesc').value = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKb')).show();
        });

        document.getElementById('kbForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const roleIds = [...document.querySelectorAll('#kbRoleBoxes input:checked')].map((c) => parseInt(c.value, 10));
            const res = await dashApi('save_kb', 'POST', {
                id: parseInt(document.getElementById('kbId').value || '0', 10),
                kb_code: document.getElementById('kbCode').value.trim(),
                kb_name: document.getElementById('kbName').value.trim(),
                category: document.getElementById('kbCategory').value.trim(),
                description: document.getElementById('kbDesc').value.trim(),
                role_ids: roleIds
            });
            toast(res.message, !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalKb')).hide();
                this.loadKnowledgeBases();
            }
        });

        document.getElementById('btnNewRagDoc')?.addEventListener('click', () => {
            document.getElementById('ragDocId').value = 0;
            document.getElementById('ragDocTitle').value = '';
            document.getElementById('ragDocText').value = '';
            document.getElementById('ragDocFile').value = '';
            document.getElementById('ragExtractInfo').textContent = '';
            bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRagDoc')).show();
        });

        document.getElementById('btnPreviewRagFile')?.addEventListener('click', async () => {
            const f = document.getElementById('ragDocFile').files[0];
            if (!f) return toast('Select PDF, DOCX, XLSX, or TXT file first', true);
            const fd = new FormData();
            fd.append('rag_file', f);
            const res = await dashApi('extract_rag_preview', 'POST', fd, true);
            if (!res.success) return toast(res.message || 'Extraction failed', true);
            if (!document.getElementById('ragDocTitle').value) {
                document.getElementById('ragDocTitle').value = res.suggested_title || '';
            }
            document.getElementById('ragDocText').value = res.text || '';
            document.getElementById('ragExtractInfo').textContent = res.message || '';
        });

        document.getElementById('ragDocForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData();
            const f = document.getElementById('ragDocFile').files[0];
            if (f) fd.append('rag_file', f);
            fd.append('id', document.getElementById('ragDocId').value || '0');
            fd.append('knowledge_base_id', document.getElementById('ragDocKbId').value || '1');
            fd.append('title', document.getElementById('ragDocTitle').value.trim());
            fd.append('category', document.getElementById('ragDocCat').value.trim());
            fd.append('content_text', document.getElementById('ragDocText').value.trim());
            [...document.querySelectorAll('#ragDocRoleBoxes input:checked')].forEach((c) => fd.append('role_ids[]', c.value));

            const res = await dashApi('save_rag_doc', 'POST', fd, true);
            toast(res.message, !res.success);
            if (res.success) {
                bootstrap.Modal.getOrCreateInstance(document.getElementById('modalRagDoc')).hide();
                this.loadRagDocs();
            }
        });

        document.getElementById('btnRunRagTest')?.addEventListener('click', async () => {
            const q = document.getElementById('ragTestQuery').value.trim();
            const wrap = document.getElementById('ragTestResults');
            if (!q || !wrap) return;
            const res = await dashApi('test_rag_search', 'POST', { query: q });
            const chunks = res.results || [];
            wrap.innerHTML = chunks.map((c) => `
                <div class="border rounded p-2 mb-2 bg-light small">
                    <div class="d-flex justify-content-between">
                        <strong>${esc(c.title)} (${esc(c.file_type)})</strong>
                        <span class="orch-badge orch-badge-info">Score ${Number(c.score || 0).toFixed(2)}</span>
                    </div>
                    <div class="text-muted mt-1">${esc((c.chunk_text || '').slice(0, 260))}</div>
                </div>`).join('') || '<div class="text-muted small">No matching document chunks found for your role.</div>';
        });

        document.getElementById('tblToolsBody')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-tool-toggle]');
            if (!btn) return;
            const res = await dashApi('toggle_tool', 'POST', {
                id: parseInt(btn.dataset.toolToggle, 10),
                is_active: parseInt(btn.dataset.nextActive, 10)
            });
            toast(res.message, !res.success);
            if (res.success) this.loadToolsList();
        });
    }

    async loadKnowledgeBases() {
        const res = await dashApi('kb_list', 'GET');
        const list = res.knowledge_bases || [];
        this.state.kbCache = list;
        const tb = document.getElementById('tblKbBody');
        if (tb) {
            tb.innerHTML = list.map((k) => `<tr>
                <td><code>${esc(k.kb_code)}</code></td><td><strong>${esc(k.kb_name)}</strong></td>
                <td>${esc(k.category)}</td><td>${esc(k.doc_count)}</td>
            </tr>`).join('');
        }
        const filterSel = document.getElementById('ragKbFilter');
        const modalSel = document.getElementById('ragDocKbId');
        if (filterSel) {
            filterSel.innerHTML = `<option value="">All Knowledge Bases</option>` +
                list.map((k) => `<option value="${k.id}">${esc(k.kb_name)}</option>`).join('');
        }
        if (modalSel) {
            modalSel.innerHTML = list.map((k) => `<option value="${k.id}">${esc(k.kb_name)}</option>`).join('');
        }
    }

    async loadRagDocs() {
        const res = await dashApi('rag_docs', 'GET', {
            search: document.getElementById('ragSearch')?.value || '',
            knowledge_base_id: document.getElementById('ragKbFilter')?.value || ''
        });
        const tb = document.getElementById('tblRagDocsBody');
        if (!tb) return;
        tb.innerHTML = (res.items || []).map((d) => `<tr>
            <td><code>${esc(d.doc_code)}</code></td>
            <td><strong>${esc(d.title)}</strong><div class="small text-muted">${esc(d.file_name)}</div></td>
            <td>${esc(d.kb_name || '-')}</td>
            <td><span class="orch-badge orch-badge-info">${esc(d.file_type)}</span></td>
            <td>${esc(d.chunk_count)}</td>
            <td>${esc(d.retrieval_count)}</td>
        </tr>`).join('') || '<tr><td colspan="6" class="text-center text-muted py-3">No authorized documents</td></tr>';
    }

    async loadToolsList() {
        if (!hasPerm('tools.manage')) return;
        const res = await dashApi('tools_list', 'GET');
        const tb = document.getElementById('tblToolsBody');
        if (!tb) return;
        const canEdit = hasPerm('tools.manage');
        tb.innerHTML = (res.tools || []).map((t) => `<tr>
            <td><code>${esc(t.tool_code)}</code></td>
            <td><strong>${esc(t.tool_name)}</strong><div class="small text-muted">${esc(t.description)}</div></td>
            <td><span class="orch-badge orch-badge-muted">${esc(t.category)}</span></td>
            <td><span class="orch-badge orch-badge-info">${esc(t.required_scope)}</span></td>
            <td><span class="orch-badge ${t.is_active ? 'orch-badge-ok' : 'orch-badge-muted'}">${t.is_active ? 'active' : 'inactive'}</span></td>
            <td>${canEdit ? `<button class="btn btn-sm btn-outline-secondary py-0" data-tool-toggle="${t.id}" data-next-active="${t.is_active ? 0 : 1}">${t.is_active ? 'Disable' : 'Enable'}</button>` : ''}</td>
        </tr>`).join('');
    }

    _bindAiEngineModule() {
        document.getElementById('aiCostRange')?.addEventListener('change', () => this.loadAiKeysAndUsage());
        document.getElementById('btnRefreshAiCost')?.addEventListener('click', () => this.loadAiKeysAndUsage());
        document.getElementById('aiAgentCostSearch')?.addEventListener('input', () => this._renderAgentCostTable());

        document.getElementById('aiBudgetForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const cap = parseFloat(document.getElementById('aiBudgetCapInput')?.value || '50');
            const res = await dashApi('save_budget_limit', 'POST', { monthly_budget_usd: cap });
            toast(res.message, !res.success);
            if (res.success) this.loadAiKeysAndUsage();
        });

        document.getElementById('aiKeyForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await dashApi('save_ai_key', 'POST', {
                provider: document.getElementById('aiKeyProvider').value,
                key_label: document.getElementById('aiKeyLabel').value.trim(),
                model_name: document.getElementById('aiKeyModel').value.trim(),
                api_key: document.getElementById('aiKeySecret').value.trim(),
                is_primary: document.getElementById('aiKeyPrimary').checked ? 1 : 0
            });
            toast(res.message, !res.success);
            if (res.success) {
                document.getElementById('aiKeySecret').value = '';
                this.loadAiKeysAndUsage();
            }
        });

        document.getElementById('aiSettingsForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await dashApi('save_ai_settings', 'POST', {
                ai_provider: document.getElementById('cfgProvider').value,
                openai_model: document.getElementById('cfgOpenAiModel').value,
                rag_enabled: document.getElementById('cfgRagEnabled').checked ? '1' : '0',
                function_calling_enabled: document.getElementById('cfgFnEnabled').checked ? '1' : '0',
                profanity_filter_enabled: document.getElementById('cfgProfanityEnabled').checked ? '1' : '0'
            });
            toast(res.message, !res.success);
        });

        document.getElementById('tblAiKeysBody')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-key-act]');
            if (!btn) return;
            const id = parseInt(btn.dataset.keyId, 10);
            const act = btn.dataset.keyAct;
            if (act === 'test') {
                toast('Testing API key connectivity...');
                const res = await dashApi('test_ai_key', 'POST', { id });
                toast(res.message, !res.success);
                this.loadAiKeysAndUsage();
            } else if (act === 'primary') {
                const res = await dashApi('set_primary_key', 'POST', { id });
                toast(res.message, !res.success);
                this.loadAiKeysAndUsage();
            } else if (act === 'toggle') {
                const nextActive = parseInt(btn.dataset.nextActive || '1', 10);
                const res = await dashApi('toggle_ai_key', 'POST', { id, is_active: nextActive });
                toast(res.message, !res.success);
                this.loadAiKeysAndUsage();
            } else if (act === 'delete') {
                if (!window.confirm('Delete this API key from the encrypted vault?')) return;
                const res = await dashApi('delete_ai_key', 'POST', { id });
                toast(res.message, !res.success);
                this.loadAiKeysAndUsage();
            }
        });
    }

    async loadAiKeysAndUsage() {
        if (!hasPerm('api_keys.manage')) return;
        const range = document.getElementById('aiCostRange')?.value || '30d';
        const [kRes, costRes] = await Promise.all([
            dashApi('table', 'GET', { table_type: 'gemini_keys', per_page: 25 }),
            dashApi('api_cost_monitor', 'GET', { range })
        ]);

        const sum = costRes.summary || {};
        const kpi = document.getElementById('aiCostKpiRow');
        if (kpi) {
            const keyStatusTxt = sum.active_keys > 0
                ? `${sum.active_keys} Vault Key(s)`
                : (sum.env_key_present ? '.env Active' : 'No Key Set');
            kpi.innerHTML = `
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Total Agent Requests</div><div class="orch-kpi-val">${Number(sum.total_requests || 0).toLocaleString()}</div></div><div class="orch-kpi-icon"><i class="bi bi-send-check"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Total Tokens</div><div class="orch-kpi-val">${Number(sum.total_tokens || 0).toLocaleString()}</div></div><div class="orch-kpi-icon"><i class="bi bi-cpu"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Est. Spend (USD / IDR)</div><div class="orch-kpi-val fs-6">$${Number(sum.total_cost_usd || 0).toFixed(4)} <small class="text-muted">(Rp ${Number(sum.total_cost_idr || 0).toLocaleString()})</small></div></div><div class="orch-kpi-icon"><i class="bi bi-cash-stack"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">API Vault Status</div><div class="orch-kpi-val fs-6">${esc(keyStatusTxt)}</div></div><div class="orch-kpi-icon"><i class="bi bi-key-fill"></i></div></div>`;
        }

        const budgetLbl = document.getElementById('aiBudgetLabel');
        const budgetIn = document.getElementById('aiBudgetCapInput');
        const budgetBar = document.getElementById('aiBudgetProgressBar');
        const usedPct = Math.min(100, Number(sum.budget_used_pct || 0));
        if (budgetLbl) {
            budgetLbl.textContent = `$${Number(sum.total_cost_usd || 0).toFixed(4)} / $${Number(sum.monthly_budget_usd || 50).toFixed(2)} USD (${usedPct.toFixed(1)}%)`;
        }
        if (budgetIn && document.activeElement !== budgetIn) {
            budgetIn.value = Number(sum.monthly_budget_usd || 50);
        }
        if (budgetBar) {
            budgetBar.style.width = `${usedPct}%`;
            budgetBar.className = 'progress-bar ' + (usedPct >= 85 ? 'bg-danger' : (usedPct >= 60 ? 'bg-warning' : 'bg-info'));
        }

        this.state.aiCostAgentsCache = costRes.by_agent || [];
        this._renderAgentCostTable();
        if (typeof window.Chart !== 'undefined') {
            this._renderAiCostCharts(this.state.aiCostAgentsCache);
        }

        const tbKeys = document.getElementById('tblAiKeysBody');
        if (tbKeys) {
            tbKeys.innerHTML = (kRes.items || []).map((k) => `<tr>
                <td><span class="orch-badge orch-badge-info">${esc(k.provider)}</span></td>
                <td><strong>${esc(k.key_label)}</strong> ${Number(k.is_primary) ? '<span class="orch-badge orch-badge-ok ms-1">Primary</span>' : ''}</td>
                <td><code>${esc(k.api_key_masked)}</code></td>
                <td><code>${esc(k.model_name)}</code></td>
                <td>${Number(k.total_requests || 0).toLocaleString()}</td>
                <td><span class="orch-badge ${Number(k.is_active) ? 'orch-badge-ok' : 'orch-badge-muted'}">${Number(k.is_active) ? 'active' : 'disabled'}</span><div class="small text-muted">${esc(k.last_status || '')}</div></td>
                <td class="text-nowrap">
                    <button class="btn btn-sm btn-outline-primary py-0 px-1" data-key-act="test" data-key-id="${k.id}" title="Test"><i class="bi bi-plug"></i></button>
                    <button class="btn btn-sm btn-outline-success py-0 px-1" data-key-act="primary" data-key-id="${k.id}" title="Set Primary"><i class="bi bi-star"></i></button>
                    <button class="btn btn-sm btn-outline-warning py-0 px-1" data-key-act="toggle" data-key-id="${k.id}" data-next-active="${Number(k.is_active) ? 0 : 1}" title="Enable/Disable"><i class="bi bi-power"></i></button>
                    <button class="btn btn-sm btn-outline-danger py-0 px-1" data-key-act="delete" data-key-id="${k.id}" title="Delete"><i class="bi bi-trash"></i></button>
                </td>
            </tr>`).join('') || '<tr><td colspan="7" class="text-center text-muted py-3">No vault keys saved (using .env if configured)</td></tr>';
        }

        const tbUsage = document.getElementById('tblApiUsageBody');
        if (tbUsage) {
            tbUsage.innerHTML = (costRes.recent_logs || []).map((l) => `<tr>
                <td><small>${esc(l.created_at)}</small></td>
                <td><strong>${esc(l.agent_name || l.agent_code || 'ORCH')}</strong></td>
                <td><code>${esc(l.username || '-')}</code></td>
                <td><code>${esc(l.model_name)}</code></td>
                <td>${Number(l.total_tokens || 0).toLocaleString()}</td>
                <td>$${Number(l.estimated_cost_usd || 0).toFixed(5)}</td>
                <td>${esc(l.latency_ms)}ms</td>
                <td><span class="orch-badge ${l.status === 'completed' || l.status === 'success' ? 'orch-badge-ok' : (l.status === 'fallback' ? 'orch-badge-info' : 'orch-badge-err')}">${esc(l.status)}</span></td>
            </tr>`).join('') || '<tr><td colspan="8" class="text-center text-muted py-3">No request logs in selected range</td></tr>';
        }
    }

    _renderAgentCostTable() {
        const tb = document.getElementById('tblAgentCostBody');
        if (!tb) return;
        const q = (document.getElementById('aiAgentCostSearch')?.value || '').trim().toLowerCase();
        const rows = (this.state.aiCostAgentsCache || []).filter((r) => {
            if (!q) return true;
            return String(r.agent_name || '').toLowerCase().includes(q)
                || String(r.agent_code || '').toLowerCase().includes(q)
                || String(r.zone_code || '').toLowerCase().includes(q);
        });
        tb.innerHTML = rows.map((r) => `<tr>
            <td><strong>${esc(r.agent_name)}</strong><div class="small text-muted"><code>${esc(r.agent_code)}</code></div></td>
            <td><span class="orch-badge orch-badge-info">${esc(r.zone_code)}</span></td>
            <td><code>${esc(r.model_name)}</code></td>
            <td><strong>${Number(r.requests || 0).toLocaleString()}</strong></td>
            <td>${Number(r.openai_calls || 0).toLocaleString()}</td>
            <td><small>${Number(r.prompt_tokens || 0).toLocaleString()} / ${Number(r.completion_tokens || 0).toLocaleString()}</small></td>
            <td>${esc(r.avg_latency_ms || 0)} ms</td>
            <td><strong>$${Number(r.cost_usd || 0).toFixed(5)}</strong></td>
            <td>Rp ${Number(r.cost_idr || 0).toLocaleString()}</td>
            <td><small class="text-muted">${esc(r.last_request_at || '-')}</small></td>
        </tr>`).join('') || '<tr><td colspan="10" class="text-center text-muted py-3">No matching agents</td></tr>';
    }

    _renderAiCostCharts(rows) {
        const top = (rows || []).slice(0, 10);
        const labels = top.map((r) => String(r.agent_name || r.agent_code).replace(/\s+Agent$/i, '').slice(0, 22));
        const reqData = top.map((r) => Number(r.requests || 0));
        const costData = top.map((r) => Number(r.cost_usd || 0));

        const cReq = document.getElementById('aiCostAgentReqChart');
        if (cReq) {
            if (this.state.charts.aiCostReq) this.state.charts.aiCostReq.destroy();
            this.state.charts.aiCostReq = new window.Chart(cReq, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Requests',
                        data: reqData,
                        backgroundColor: '#2563eb',
                        borderRadius: 6
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, indexAxis: 'y', plugins: { legend: { display: false } } }
            });
        }
        const cSpend = document.getElementById('aiCostSpendChart');
        if (cSpend) {
            if (this.state.charts.aiCostSpend) this.state.charts.aiCostSpend.destroy();
            this.state.charts.aiCostSpend = new window.Chart(cSpend, {
                type: 'bar',
                data: {
                    labels,
                    datasets: [{
                        label: 'Est. Cost (USD)',
                        data: costData,
                        backgroundColor: '#f59e0b',
                        borderRadius: 6
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
        }
    }

    _bindLiveAgentChartsModule() {
        document.getElementById('btnToggleLiveCharts')?.addEventListener('click', () => {
            this.state.liveChartsAuto = !this.state.liveChartsAuto;
            const btn = document.getElementById('btnToggleLiveCharts');
            const lbl = document.getElementById('liveChartsBtnLbl');
            if (btn) {
                btn.className = 'btn btn-sm rounded-pill px-3 ' + (this.state.liveChartsAuto ? 'btn-success' : 'btn-outline-secondary');
            }
            if (lbl) lbl.textContent = this.state.liveChartsAuto ? 'LIVE 5s ON' : 'LIVE PAUSED';
            if (this.state.liveChartsAuto) {
                this.loadLiveAgentCharts();
                this.startLiveChartsPolling();
            } else {
                this.stopLiveChartsPolling();
            }
        });
        document.getElementById('liveChartsRange')?.addEventListener('change', () => this.loadLiveAgentCharts());
        document.getElementById('liveChartsZone')?.addEventListener('change', () => this.loadLiveAgentCharts());
        document.getElementById('btnRefreshLiveCharts')?.addEventListener('click', () => this.loadLiveAgentCharts());
    }

    startLiveChartsPolling() {
        this.stopLiveChartsPolling();
        if (!this.state.liveChartsAuto) return;
        this.state.liveChartsTimer = setInterval(() => {
            if (this.state.activeSection === 'agent_charts' && this.state.liveChartsAuto) {
                this.loadLiveAgentCharts();
            }
        }, 5000);
    }

    stopLiveChartsPolling() {
        if (this.state.liveChartsTimer) {
            clearInterval(this.state.liveChartsTimer);
            this.state.liveChartsTimer = null;
        }
    }

    async loadLiveAgentCharts() {
        const range = document.getElementById('liveChartsRange')?.value || '24h';
        const zone = document.getElementById('liveChartsZone')?.value || '';
        const res = await dashApi('agent_telemetry_charts', 'GET', { range, zone });
        if (!res.success) return;

        const sum = res.summary || {};
        const upd = document.getElementById('liveChartsUpdatedAt');
        if (upd) upd.textContent = `Updated ${res.server_time || new Date().toLocaleTimeString()}`;

        const kpi = document.getElementById('liveChartsKpiRow');
        if (kpi) {
            kpi.innerHTML = `
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Active Agents</div><div class="orch-kpi-val">${esc(sum.active_agents || 0)} / ${esc(sum.total_agents || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-robot"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Executions</div><div class="orch-kpi-val">${Number(sum.total_requests || 0).toLocaleString()}</div></div><div class="orch-kpi-icon"><i class="bi bi-lightning-charge"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Avg Latency</div><div class="orch-kpi-val">${esc(sum.avg_latency_ms || 0)} ms</div></div><div class="orch-kpi-icon"><i class="bi bi-speedometer2"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Success Rate</div><div class="orch-kpi-val">${esc(sum.success_rate ?? 100)}%</div></div><div class="orch-kpi-icon"><i class="bi bi-check2-circle"></i></div></div>`;
        }

        const tb = document.getElementById('tblLiveAgentsBody');
        if (tb) {
            tb.innerHTML = (res.agents || []).map((a) => {
                const live = this.state.liveAgentStatusMap[a.agent_code];
                const stateTxt = (live?.status || a.last_status || 'idle').toUpperCase();
                const badgeCls = stateTxt === 'ERROR' || stateTxt === 'FAILED'
                    ? 'orch-badge-err'
                    : (['RUNNING', 'WORKING', 'THINKING', 'SEARCHING'].includes(stateTxt) ? 'orch-badge-warn' : 'orch-badge-ok');
                return `<tr>
                    <td><strong>${esc(a.agent_name)}</strong><div class="small text-muted"><code>${esc(a.agent_code)}</code></div></td>
                    <td><span class="orch-badge orch-badge-info">${esc(a.zone_code)}</span></td>
                    <td><span class="orch-badge ${badgeCls}">${esc(stateTxt)}</span></td>
                    <td><strong>${Number(a.requests || 0).toLocaleString()}</strong></td>
                    <td>${esc(a.avg_latency_ms || 0)} ms</td>
                    <td>${Number(a.total_tokens || 0).toLocaleString()}</td>
                    <td>$${Number(a.cost_usd || 0).toFixed(5)}</td>
                    <td><small class="text-muted">${esc(a.last_active_at || '-')}</small></td>
                </tr>`;
            }).join('') || '<tr><td colspan="8" class="text-center text-muted py-3">No agents in selected zone</td></tr>';
        }

        const feed = document.getElementById('liveChartsEventFeed');
        if (feed) {
            feed.innerHTML = (res.live_events || []).map((ev) => `
                <div class="orch-timeline-item">
                    <span class="orch-badge ${ev.status === 'error' ? 'orch-badge-err' : 'orch-badge-info'}">${esc(ev.status)}</span>
                    <div class="flex-grow-1 small">
                        <div><strong>${esc(ev.agent_code)}</strong> <span class="text-muted">(${esc(ev.zone_code)})</span></div>
                        <div class="text-muted">${esc(ev.task_label)}</div>
                    </div>
                    <small class="text-muted text-nowrap">${esc((ev.created_at || '').slice(11, 19))}</small>
                </div>`).join('') || '<div class="text-muted small">Waiting for live orchestration events...</div>';
        }

        if (typeof window.Chart !== 'undefined') {
            this._renderLiveTelemetryCharts(res);
        }
    }

    _renderLiveTelemetryCharts(res) {
        const tl = res.timeline || {};
        const tlLabels = tl.labels?.length ? tl.labels : ['Now'];
        const tlReqs = tl.requests?.length ? tl.requests : [0];
        const tlToks = tl.tokens?.length ? tl.tokens : [0];

        const c1 = document.getElementById('liveChartTimeline');
        if (c1) {
            if (this.state.charts.liveTimeline) this.state.charts.liveTimeline.destroy();
            this.state.charts.liveTimeline = new window.Chart(c1, {
                type: 'line',
                data: {
                    labels: tlLabels,
                    datasets: [
                        {
                            label: 'Requests',
                            data: tlReqs,
                            borderColor: '#0284c7',
                            backgroundColor: 'rgba(56, 189, 248, 0.22)',
                            fill: true,
                            tension: 0.35,
                            yAxisID: 'y'
                        },
                        {
                            label: 'Tokens',
                            data: tlToks,
                            borderColor: '#8b5cf6',
                            backgroundColor: 'rgba(139, 92, 246, 0.12)',
                            fill: false,
                            tension: 0.35,
                            yAxisID: 'y1'
                        }
                    ]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: { duration: 250 },
                    scales: {
                        y: { beginAtZero: true, position: 'left' },
                        y1: { beginAtZero: true, position: 'right', grid: { drawOnChartArea: false } }
                    }
                }
            });
        }

        const zPalette = {
            DEAN: '#f59e0b',
            KAPRODI: '#2563eb',
            SECRETARY: '#f43f5e',
            UPMF: '#8b5cf6',
            RESEARCH_HEAD: '#06b6d4',
            STUDENT: '#f97316',
            COMMAND_CENTER: '#0ea5e9'
        };
        const zones = res.zones || [];
        const c2 = document.getElementById('liveChartZones');
        if (c2) {
            if (this.state.charts.liveZones) this.state.charts.liveZones.destroy();
            this.state.charts.liveZones = new window.Chart(c2, {
                type: 'doughnut',
                data: {
                    labels: zones.map((z) => z.zone_code),
                    datasets: [{
                        data: zones.map((z) => Number(z.requests || 0)),
                        backgroundColor: zones.map((z) => zPalette[z.zone_code] || '#38bdf8')
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, animation: { duration: 250 }, plugins: { legend: { position: 'bottom' } } }
            });
        }

        const topAgents = (res.agents || []).slice(0, 12);
        const agLabels = topAgents.map((a) => String(a.agent_name || a.agent_code).replace(/\s+Agent$/i, '').slice(0, 22));

        const c3 = document.getElementById('liveChartByAgent');
        if (c3) {
            if (this.state.charts.liveByAgent) this.state.charts.liveByAgent.destroy();
            this.state.charts.liveByAgent = new window.Chart(c3, {
                type: 'bar',
                data: {
                    labels: agLabels,
                    datasets: [{
                        label: 'Requests',
                        data: topAgents.map((a) => Number(a.requests || 0)),
                        backgroundColor: topAgents.map((a) => zPalette[a.zone_code] || '#2563eb'),
                        borderRadius: 6
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, animation: { duration: 250 }, indexAxis: 'y', plugins: { legend: { display: false } } }
            });
        }

        const c4 = document.getElementById('liveChartLatency');
        if (c4) {
            if (this.state.charts.liveLatency) this.state.charts.liveLatency.destroy();
            this.state.charts.liveLatency = new window.Chart(c4, {
                type: 'bar',
                data: {
                    labels: agLabels,
                    datasets: [
                        {
                            label: 'Avg Latency (ms)',
                            data: topAgents.map((a) => Number(a.avg_latency_ms || 0)),
                            backgroundColor: '#06b6d4',
                            borderRadius: 5
                        },
                        {
                            label: 'Tokens (x10)',
                            data: topAgents.map((a) => Math.round(Number(a.total_tokens || 0) / 10)),
                            backgroundColor: '#a855f7',
                            borderRadius: 5
                        }
                    ]
                },
                options: { responsive: true, maintainAspectRatio: false, animation: { duration: 250 } }
            });
        }
    }

    _bindMonitoringModule() {
        document.getElementById('btnRefreshMon')?.addEventListener('click', () => this.loadMonitoringSection());
    }

    async loadMonitoringSection() {
        const res = await dashApi('analytics', 'GET');
        if (!res.success) return;
        const mon = res.monitoring || {};
        const agents = mon.agents || {};
        const tasks = mon.tasks || {};
        const usage = mon.openai_usage || {};

        const kpi = document.getElementById('monKpiRow');
        if (kpi) {
            kpi.innerHTML = `
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Active Agents</div><div class="orch-kpi-val">${esc(agents.active || 0)} / ${esc(agents.total || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-cpu"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Total Tasks</div><div class="orch-kpi-val">${esc(tasks.total || 0)}</div></div><div class="orch-kpi-icon"><i class="bi bi-diagram-3"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Avg Latency</div><div class="orch-kpi-val">${esc(tasks.avg_response_ms || 0)} ms</div></div><div class="orch-kpi-icon"><i class="bi bi-speedometer2"></i></div></div>
                <div class="orch-kpi-card"><div><div class="orch-kpi-lbl">Token Usage</div><div class="orch-kpi-val">${Number(usage.total_tokens || 0).toLocaleString()}</div></div><div class="orch-kpi-icon"><i class="bi bi-coin"></i></div></div>`;
        }

        const timeline = document.getElementById('monTimelineList');
        if (timeline) {
            timeline.innerHTML = (mon.timeline || []).map((t) => `
                <div class="orch-timeline-item">
                    <span class="orch-badge ${t.status === 'failed' ? 'orch-badge-err' : 'orch-badge-ok'}">${esc(t.step_type)}</span>
                    <div class="flex-grow-1">
                        <div><strong>${esc(t.agent_name || t.agent_code || 'Orchestrator')}</strong> · <small class="text-muted">${esc(t.actor_name)}</small></div>
                        <div class="text-muted">${esc(t.summary)}</div>
                    </div>
                    <small class="text-muted text-nowrap">${esc(t.duration_ms)}ms</small>
                </div>`).join('') || '<div class="text-muted small">No recent orchestration activity</div>';
        }

        if (typeof window.Chart !== 'undefined') {
            this._renderMonCharts(agents, tasks);
        }

        if (hasPerm('audit.view')) {
            const [audRes, secRes] = await Promise.all([
                dashApi('table', 'GET', { table_type: 'audit_logs', per_page: 20 }),
                dashApi('table', 'GET', { table_type: 'security', per_page: 20 })
            ]);
            const tbAud = document.getElementById('tblAuditLogs');
            if (tbAud) {
                tbAud.innerHTML = (audRes.items || []).map((a) => `<tr>
                    <td><small>${esc(a.created_at)}</small></td><td>${esc(a.actor)}</td>
                    <td><code>${esc(a.action)}</code></td><td>${esc(a.details)}</td>
                </tr>`).join('');
            }
            const tbSec = document.getElementById('tblSecurityLogs');
            if (tbSec) {
                tbSec.innerHTML = (secRes.items || []).map((s) => `<tr>
                    <td><small>${esc(s.created_at)}</small></td><td><span class="orch-badge orch-badge-err">${esc(s.incident_type || s.vector_type)}</span></td>
                    <td><code>${esc(s.source_ip || s.ip_address)}</code></td><td>${esc(s.action_taken)}</td>
                </tr>`).join('');
            }
        }
    }

    _renderMonCharts(agents, tasks) {
        const c1 = document.getElementById('monTaskChart');
        if (c1) {
            if (this.state.charts.tasks) this.state.charts.tasks.destroy();
            this.state.charts.tasks = new window.Chart(c1, {
                type: 'doughnut',
                data: {
                    labels: ['Completed', 'Running', 'Failed'],
                    datasets: [{
                        data: [tasks.completed || 0, tasks.running || 0, tasks.failed || 0],
                        backgroundColor: ['#10b981', '#38bdf8', '#ef4444']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'bottom' } } }
            });
        }
        const c2 = document.getElementById('monTokenChart');
        if (c2) {
            if (this.state.charts.agents) this.state.charts.agents.destroy();
            this.state.charts.agents = new window.Chart(c2, {
                type: 'bar',
                data: {
                    labels: ['Active', 'Inactive', 'Pending Approval'],
                    datasets: [{
                        label: 'Agents',
                        data: [agents.active || 0, agents.inactive || 0, agents.pending_approval || 0],
                        backgroundColor: ['#0284c7', '#94a3b8', '#f59e0b']
                    }]
                },
                options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { display: false } } }
            });
        }
    }

    _bindProfileModule() {
        document.getElementById('profileForm')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const res = await dashApi('update_profile', 'POST', {
                full_name: document.getElementById('profFullName').value.trim(),
                email: document.getElementById('profEmail').value.trim(),
                department: document.getElementById('profDept').value.trim(),
                phone: document.getElementById('profPhone').value.trim(),
                password: document.getElementById('profPassword').value
            });
            toast(res.message, !res.success);
            if (res.success) document.getElementById('profPassword').value = '';
        });

        document.getElementById('btnSetupMfa')?.addEventListener('click', async () => {
            const res = await dashApi('setup_mfa', 'POST', {});
            if (!res.success) return toast(res.message || 'MFA setup failed', true);
            document.getElementById('mfaSecretDisplay').textContent = res.secret || '';
            document.getElementById('mfaUriDisplay').value = res.otpauth_uri || '';
            document.getElementById('mfaSetupBox').classList.remove('d-none');
        });

        document.getElementById('btnEnableMfa')?.addEventListener('click', async () => {
            const code = document.getElementById('mfaConfirmCode').value.trim();
            const res = await dashApi('enable_mfa', 'POST', { code });
            toast(res.message, !res.success);
            if (res.success) {
                document.getElementById('mfaSetupBox').classList.add('d-none');
                document.getElementById('mfaStatusBadge').textContent = 'MFA Enabled';
                document.getElementById('mfaStatusBadge').className = 'orch-badge orch-badge-ok';
            }
        });

        document.getElementById('btnDisableMfa')?.addEventListener('click', async () => {
            const res = await dashApi('disable_mfa', 'POST', {});
            toast(res.message, !res.success);
            if (res.success) {
                document.getElementById('mfaStatusBadge').textContent = 'MFA Disabled';
                document.getElementById('mfaStatusBadge').className = 'orch-badge orch-badge-muted';
            }
        });

        document.getElementById('tblSessionsBody')?.addEventListener('click', async (e) => {
            const btn = e.target.closest('button[data-revoke-sid]');
            if (!btn) return;
            const res = await dashApi('revoke_session', 'POST', { session_id: btn.dataset.revokeSid });
            toast(res.message, !res.success);
            if (res.success) this.loadProfileSessions(res.active_sessions || []);
        });
    }

    async loadProfileSessions(overrideList = null) {
        if (overrideList === null) {
            const res = await dashApi('analytics', 'GET');
            overrideList = res.active_sessions || [];
        }
        const tb = document.getElementById('tblSessionsBody');
        if (!tb) return;
        tb.innerHTML = overrideList.map((s) => `<tr>
            <td>${esc(s.username || '-')}</td>
            <td><code>${esc(s.ip_address)}</code></td>
            <td><small>${esc(s.last_activity)}</small></td>
            <td><button class="btn btn-sm btn-outline-danger py-0" data-revoke-sid="${esc(s.session_id)}">Revoke</button></td>
        </tr>`).join('') || '<tr><td colspan="4" class="text-center text-muted">No sessions visible</td></tr>';
    }
}

document.addEventListener('DOMContentLoaded', () => {
    window.FILKOM_PORTAL_APP = new OrchestratorPortalApp();
});
