# AI Agent Orchestrator

> **One Platform. Multiple Intelligent Agents. One Connected Faculty.**

An AI-powered Multi-Agent Orchestration Platform for the **faculty**, combining dynamic role-based AI agent orchestration, strict RBAC (role-based access control) and data scoping, Retrieval-Augmented Generation (RAG), real-time telemetry charts, and an interactive Three.js 3D Smart Tech Office Command Center.

---

## Tech Stack

| Layer | Technology |
|---|---|
| **Backend** | Native PHP 8+ MVC (`app/core`, `app/controller`, `app/model`, `app/view`) |
| **Database** | MySQL / MariaDB (`db_agents_orchestrator`, `utf8mb4`, InnoDB, PDO prepared statements) |
| **AI and RAG Engine** | OpenAI Responses API + Tool Calling + Role-Scoped RAG + SSE Real-Time Events |
| **3D Command Center** | Three.js r150 (`WebGLRenderer`, GLB sculpted assets, real-time animated monitors and avatars) |
| **Frontend and Charts** | Vanilla ES Modules, Bootstrap 5, Bootstrap Icons, Chart.js 4 |
| **Security** | BCRYPT, AES-256-CBC API Key Vault, CSRF tokens, RBAC, WAF/SSRF guards, TOTP MFA, Audit Logs |

---

## System Scale

- **36 Pre-Seeded Dynamic AI Agents** (database-driven, zero hardcoding; Admin can create/edit/approve/rollback agents dynamically):
  - **8 Dean Agents** (`DEAN`) — Executive Assistant, Faculty Performance, Academic Intelligence, Student Analytics, Lecturer Performance, Research Monitoring, Accreditation Monitoring, Strategic Decision Support
  - **7 Kaprodi Agents** (`KAPRODI_IF`, `KAPRODI_SI`, `KAPRODI_TI`, `KAPRODI_MSI` (Kaprodi Sistem Informasi)) — Curriculum, Course Scheduling, Student Progress, Academic Performance, Lecturer Teaching, Thesis Monitoring, Student Risk Detection
  - **5 Secretary Agents** (`SECRETARY`) — Faculty Administration, Meeting and Agenda, Document Management, Faculty Reporting, Correspondence
  - **5 UPMF Agents** (`UPMF`) — Quality Assurance, Accreditation Evidence, KPI Monitoring, Internal Audit, Quality Evaluation
  - **5 Research Head Agents** (`RESEARCH_HEAD`) — Research Monitoring, Publication Analytics, Research Funding, Lecturer Research Performance, Research Collaboration
  - **6 Student Agents** (`STUDENT`) — Academic Advisor, Course Information, Attendance Information, Thesis Assistant, Academic Regulation, Student Services
- **10 Official RBAC Roles:** `ADMIN`, `DEAN`, `KAPRODI_IF`, `KAPRODI_SI`, `KAPRODI_TI`, `KAPRODI_MSI`, `SECRETARY`, `UPMF`, `RESEARCH_HEAD`, `STUDENT`
- **20 Scoped Backend Tools** + **6 Role-Scoped Knowledge Bases (RAG)**
- **63 Database Tables** (all prefixed with `tbl_`) across **4 Study Programs** (`IF`, `SI`, `TI`, `MSI`)

---

## Core Features

- **3D Smart Office Command Center:** Interactive futuristic Three.js office featuring a central floating AI Orchestrator Robot, 6 uniquely styled autonomous walking/working 3D faculty avatars, live animated real-time data monitors, minimalist floating desk job holograms, and live SSE event pulses.
- **Dynamic Agent Registry and AI Agent Builder:** Create, duplicate, version, rollback, sandbox-test, and approve AI agents directly from the dashboard without touching source code.
- **API Key Vault and Per-Agent Spend Monitor:** Manage AES-256 encrypted API keys (add, test, primary, toggle, delete), set monthly USD budget caps, and monitor requests, tokens, latency, and estimated USD/IDR spend per agent.
- **Real-Time Live Agent Charts:** Admin telemetry submenu with auto-refreshing Chart.js graphs (request/token velocity, per-agent load ranking, latency and token comparison, zone workload share, and live SSE event feed).
- **Governance and Academic Modules:** Users and RBAC permission matrix, dedicated **Admin Reset User Password** submenu (with generator and session revocation), immutable Position Assignment History, Student Management + Excel/CSV import, and **Courses and Teaching Schedules** with Study Program (`Prodi`) filtering.

---

## Quick Installation

1. **Clone into XAMPP `htdocs`:**
   ```bash
   git clone <repository-url> filkom-ai-agent-orchestrator
   cd filkom-ai-agent-orchestrator
   ```
2. **Import Database (`db_agents_orchestrator`):**
   - Open phpMyAdmin or MySQL CLI and import [`database/db_agents_orchestrator.sql`](database/db_agents_orchestrator.sql).
   ```bash
   mysql -u root < database/db_agents_orchestrator.sql
   ```
3. **Configure Environment:**
   - Copy `.env.example` to `.env` and fill in your database credentials, encryption secrets (`APP_ENC_KEY`, `APP_BIDX_KEY`), and optionally `OPENAI_API_KEY` (or configure keys later inside **API Keys and Cost**).
4. **Launch:**
   - Start Apache and MySQL in XAMPP and open:
     `http://localhost/filkom-ai-agent-orchestrator/`

---
