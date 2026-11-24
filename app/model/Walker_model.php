<?php

require_once __DIR__ . '/AgentToolsRegistry.php';
require_once __DIR__ . '/SelfLearningEngine.php';
require_once __DIR__ . '/DocumentParser.php';

class Walker_model {
    private Database $db;
    private ?SelfLearningEngine $selfLearner = null;

    public function __construct() {
        $this->db = new Database();
        $this->ensureSchema();
    }

    public function getSelfLearner(): SelfLearningEngine {
        if ($this->selfLearner === null) {
            $this->selfLearner = new SelfLearningEngine($this->db);
            $this->selfLearner->setAutonomousToolResolver(fn(string $q, string $lang) => $this->resolveToolAliasForGap($q, $lang));
            $this->selfLearner->setAutonomousLlmSynthesizer(fn(string $q, string $lang, string $intent) => $this->synthesizeKnowledgeWithLlm($q, $lang, $intent));
        }
        return $this->selfLearner;
    }

    public function ensureSchema(): void {
        if (!$this->db->isConnected()) {
            return;
        }
        if (session_status() === PHP_SESSION_ACTIVE && !empty($_SESSION['_va_schema_v353_ready'])) {
            return;
        }
        $conn = $this->db->getConnection();
        if ($conn) {
            $svRes = @$conn->query("SELECT setting_value FROM `tbl_system_settings` WHERE setting_key = '_schema_v353_ready' LIMIT 1");
            if ($svRes && ($svr = $svRes->fetch_assoc()) && (string)$svr['setting_value'] === '1') {
                if (session_status() === PHP_SESSION_ACTIVE) {
                    $_SESSION['_va_schema_v353_ready'] = true;
                }
                return;
            }
        }
        if (
            $this->db->hasTable('tbl_users') &&
            $this->db->hasTable('tbl_system_settings') &&
            $this->db->hasTable('tbl_static_knowledge') &&
            $this->db->hasTable('tbl_va_conversations') &&
            $this->db->hasTable('tbl_knowledge_documents') &&
            $this->db->hasTable('tbl_long_term_memories') &&
            $this->db->hasTable('tbl_agent_tools') &&
            $this->db->hasTable('tbl_ai_provider_keys')
        ) {
            if ($conn) {
                $colCheck = @$conn->query("SHOW COLUMNS FROM `tbl_ai_provider_keys` LIKE 'provider'");
                if ($colCheck && $colCheck->num_rows === 0) {
                    @$conn->query("ALTER TABLE `tbl_ai_provider_keys` ADD COLUMN `provider` varchar(32) NOT NULL DEFAULT 'gemini' AFTER `id`");
                }
                @$conn->query("INSERT IGNORE INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('ai_provider', 'gemini'), ('openai_model', 'gpt-6.1-sol')");
                $primCheck = @$conn->query("SELECT COUNT(*) AS cnt FROM `tbl_ai_provider_keys` WHERE is_primary = 1");
                if ($primCheck && ($pr = $primCheck->fetch_assoc()) && (int)$pr['cnt'] > 1) {
                    $prefProv = 'gemini';
                    $pvRes = @$conn->query("SELECT setting_value FROM `tbl_system_settings` WHERE setting_key = 'ai_provider' LIMIT 1");
                    if ($pvRes && ($pvr = $pvRes->fetch_assoc())) {
                        $prefProv = self::normalizeProvider((string)$pvr['setting_value']);
                    }
                    $escProv = $conn->real_escape_string($prefProv);
                    $pickRes = @$conn->query("SELECT id FROM `tbl_ai_provider_keys` WHERE is_primary = 1 ORDER BY (provider = '{$escProv}') DESC, id DESC LIMIT 1");
                    if ($pickRes && ($pk = $pickRes->fetch_assoc())) {
                        $keepId = (int)$pk['id'];
                        @$conn->query("UPDATE `tbl_ai_provider_keys` SET is_primary = CASE WHEN id = {$keepId} THEN 1 ELSE 0 END");
                    }
                }
                $toolCntRes = @$conn->query("SELECT COUNT(*) AS cnt FROM `tbl_agent_tools`");
                if ($toolCntRes && ($tr = $toolCntRes->fetch_assoc()) && (int)$tr['cnt'] < 100) {
                    $this->syncAgentToolsCatalog($conn);
                }
                $this->syncKnowledgeBaseFixes($conn);
                $this->getSelfLearner()->ensureSelfLearningSchema($conn);
                @$conn->query("INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('_schema_v352_ready', '1'), ('_schema_v353_ready', '1') ON DUPLICATE KEY UPDATE `setting_value` = '1'");
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_va_schema_v353_ready'] = true;
            }
            return;
        }
        $sqlFile = BASEPATH . '/database/db_agents_orchestrator.sql';
        if (file_exists($sqlFile)) {
            $this->db->importSqlFile($sqlFile);
            if ($this->db->hasTable('tbl_agent_tools')) {
                $conn = $this->db->getConnection();
                if ($conn) {
                    $this->syncAgentToolsCatalog($conn);
                    $this->syncKnowledgeBaseFixes($conn);
                    $this->getSelfLearner()->ensureSelfLearningSchema($conn);
                    @$conn->query("INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('_schema_v352_ready', '1'), ('_schema_v353_ready', '1') ON DUPLICATE KEY UPDATE `setting_value` = '1'");
                }
            }
            if (session_status() === PHP_SESSION_ACTIVE) {
                $_SESSION['_va_schema_v353_ready'] = true;
            }
        }
    }

    private function syncKnowledgeBaseFixes(mysqli $conn): void {
        if (!$this->db->hasTable('tbl_static_knowledge')) {
            return;
        }
        @$conn->query("UPDATE `tbl_static_knowledge`
            SET keywords_id = REPLACE(keywords_id, 'tentang unklat', 'tentang unklab,alamat unklab,di mana unklab')
            WHERE topic_code = 'unklab_profile' AND keywords_id LIKE '%tentang unklat%'");
        @$conn->query("UPDATE `tbl_static_knowledge`
            SET keywords_id = CONCAT(keywords_id, ',siapa pencipta kamu,siapa penciptamu,pencipta kamu,siapa yang menciptakanmu'),
                keywords_en = CONCAT(keywords_en, ',who made you,who is your creator')
            WHERE topic_code = 'creator_team' AND keywords_id NOT LIKE '%pencipta%'");
        $stmt = @$conn->prepare("INSERT IGNORE INTO `tbl_static_knowledge`
            (`topic_code`, `category`, `keywords_id`, `keywords_en`, `response_id`, `response_en`, `expression`, `gesture`, `priority`, `is_active`, `hit_count`)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, 1)");
        if ($stmt) {
            $tc = 'assistant_location';
            $cat = 'Identitas';
            $kwId = 'di mana kamu tinggal,dimana kamu tinggal,kamu tinggal di mana,kamu tinggal dimana,tempat tinggal kamu,di mana kamu berada,dimana kamu berada,lokasi kamu dimana,di mana lokasi kamu,kamu berasal dari mana,asal kamu dari mana';
            $kwEn = 'where do you live,where are you located,where are you based,where is unklabot,where are you from';
            $respId = 'Saya asisten virtual 3D yang beroperasi secara digital di Kampus UNKLAB, Airmadidi, Sulawesi Utara.';
            $respEn = "I'm a 3D virtual assistant operating digitally at UNKLAB Campus in Airmadidi, North Sulawesi.";
            $expr = 'HAPPY';
            $gest = 'EXPLAIN';
            $prio = 96;
            $stmt->bind_param('ssssssssi', $tc, $cat, $kwId, $kwEn, $respId, $respEn, $expr, $gest, $prio);
            @$stmt->execute();
            $stmt->close();
        }
    }

    private function syncAgentToolsCatalog(mysqli $conn): void {
        $stmt = @$conn->prepare("INSERT IGNORE INTO `tbl_agent_tools` (`tool_code`, `tool_name`, `tool_category`, `trigger_keywords`, `description`, `handler_method`, `is_active`, `call_count`, `last_called_at`) VALUES (?, ?, ?, ?, ?, ?, 1, 1, NOW())");
        if (!$stmt) {
            return;
        }
        foreach (AgentToolsRegistry::getCatalog() as $toolRow) {
            [$tCode, $tName, $tCat, $tKw, $tDesc, $tMethod] = $toolRow;
            $stmt->bind_param('ssssss', $tCode, $tName, $tCat, $tKw, $tDesc, $tMethod);
            @$stmt->execute();
        }
        $stmt->close();
    }

    public function encryptSecret(string $plain): string {
        $key = defined('APP_ENC_KEY') ? APP_ENC_KEY : hash('sha256', 'UNKLABOT_DEFAULT_KEY', true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            return base64_encode($plain);
        }
        return base64_encode($iv . $cipher);
    }

    public function decryptSecret(string $encoded): string {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) {
            return '';
        }
        $key = defined('APP_ENC_KEY') ? APP_ENC_KEY : hash('sha256', 'UNKLABOT_DEFAULT_KEY', true);
        $iv = substr($raw, 0, 16);
        $cipher = substr($raw, 16);
        $plain = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $plain !== false ? $plain : '';
    }

    public function maskApiKey(string $key): string {
        $k = trim($key);
        $len = strlen($k);
        if ($len <= 10) {
            return str_repeat('*', max(4, $len));
        }
        return substr($k, 0, 6) . '...' . substr($k, -4);
    }

    public function authenticateUser(string $identifier, string $password, string $ip, string $userAgent, ?string $totpCode = null): array {
        $auth = new AuthService($this->db);
        return $auth->authenticate($identifier, $password, $totpCode, $ip, $userAgent);
    }

    public function getUserById(int $id): ?array {
        $rbac = new RbacService($this->db);
        $ctx = $rbac->getUserContext($id);
        if (!$ctx) return null;
        $u = $ctx['user'];
        $u['role'] = $ctx['primary_role_code'];
        $u['roles'] = $ctx['role_codes'];
        $u['zone_code'] = $ctx['zone_code'];
        $u['program_codes'] = $ctx['program_codes'];
        $u['student_id'] = $ctx['student_id'];
        return $u;
    }

    public static function getGemini3Catalog(): array {
        return [
            'gemini-3.8-flash' => 'Gemini 3.8 Flash (gemini-3.8-flash)',
            'gemini-3.8-live' => 'Gemini 3.8 Live (gemini-3.8-live)',
            'gemini-3.8-live-extended-thinking' => 'Gemini 3.8 Live Extended Thinking (gemini-3.8-live-extended-thinking)',
            'gemini-3.8-flash-tts' => 'Gemini 3.8 Flash TTS (gemini-3.8-flash-tts)',
            'gemini-3.8-flash-lite-tts' => 'Gemini 3.8 Flash-Lite TTS (gemini-3.8-flash-lite-tts)',
            'gemini-3.6-flash' => 'Gemini 3.6 Flash (gemini-3.6-flash)',
            'gemini-3.5-flash' => 'Gemini 3.5 Flash (gemini-3.5-flash)',
            'gemini-3.5-flash-lite' => 'Gemini 3.5 Flash-Lite (gemini-3.5-flash-lite)',
            'gemini-3.1-flash-lite' => 'Gemini 3.1 Flash-Lite (gemini-3.1-flash-lite)',
            'gemini-nano-banana-2.1' => 'Nano Banana 2.1 (gemini-nano-banana-2.1)',
            'gemini-3.1-flash-image' => 'Nano Banana 2 (gemini-3.1-flash-image)',
            'gemini-3.1-flash-lite-image' => 'Nano Banana 2 Lite (gemini-3.1-flash-lite-image)',
            'gemini-3-pro-image' => 'Nano Banana Pro (gemini-3-pro-image)',
            'gemini-3.5-transcribe' => 'Gemini 3.5 Transcribe (gemini-3.5-transcribe)',
            'gemini-3.5-transcribe-live' => 'Gemini 3.5 Transcribe Live (gemini-3.5-transcribe-live)',
            'gemini-omni-1.2-flash' => 'Gemini Omni 1.2 Flash (gemini-omni-1.2-flash)',
            'gemini-omni-1.1-flash' => 'Gemini Omni 1.1 Flash (gemini-omni-1.1-flash)',
            'gemini-3.1-pro-preview' => 'Gemini 3.1 Pro (gemini-3.1-pro-preview)',
            'gemini-3-flash-preview' => 'Gemini 3 Flash (gemini-3-flash-preview)',
            'gemini-3.5-live-translate-preview' => 'Gemini 3.5 Live Translate (gemini-3.5-live-translate-preview)',
            'gemini-3.1-flash-live-preview' => 'Gemini 3.1 Flash Live (gemini-3.1-flash-live-preview)',
            'gemini-3.1-flash-tts-preview' => 'Gemini 3.1 Flash TTS (gemini-3.1-flash-tts-preview)'
        ];
    }

    public static function normalizeGeminiModel(?string $model): string {
        $raw = strtolower(trim((string)$model));
        $deprecatedMap = [
            'gemini-2.5-flash' => 'gemini-3.5-flash',
            'gemini-2.5-flash-lite' => 'gemini-3.5-flash-lite',
            'gemini-2.0-flash' => 'gemini-3.5-flash',
            'gemini-1.5-flash' => 'gemini-3.5-flash-lite',
            'gemini-2.5-pro' => 'gemini-3.1-pro-preview',
            'gemini-1.5-pro' => 'gemini-3.1-pro-preview'
        ];
        if (isset($deprecatedMap[$raw])) {
            return $deprecatedMap[$raw];
        }
        $catalog = self::getGemini3Catalog();
        if (isset($catalog[$raw])) {
            return $raw;
        }
        if (preg_match('/^gemini-(?:3(?:\.[0-9]+)?|nano-banana|omni)[a-z0-9.\-]{1,48}$/', $raw)) {
            return $raw;
        }
        return 'gemini-3.8-flash';
    }

    public static function getOpenAiCatalog(): array {
        return [
            'gpt-6.1-sol' => 'GPT-6.1 Sol (gpt-6.1-sol)',
            'gpt-6-astra' => 'GPT-6 Astra (gpt-6-astra)',
            'gpt-6.1-terra' => 'GPT-6.1 Terra (gpt-6.1-terra)',
            'gpt-6-luna' => 'GPT-6 Luna (gpt-6-luna)',
            'gpt-6.2-astra' => 'GPT-6.2 Astra (gpt-6.2-astra)',
            'gpt-6.2-sol' => 'GPT-6.2 Sol (gpt-6.2-sol)',
            'gpt-6-sol' => 'GPT-6 Sol (gpt-6-sol)',
            'gpt-5.6-sol' => 'GPT-5.6 Sol (gpt-5.6-sol)',
            'gpt-5.6-luna' => 'GPT-5.6 Luna (gpt-5.6-luna)',
            'gpt-5.6-terra' => 'GPT-5.6 Terra (gpt-5.6-terra)',
            'gpt-5.5' => 'GPT-5.5 (gpt-5.5)',
            'gpt-5.5-pro' => 'GPT-5.5 Pro (gpt-5.5-pro)',
            'gpt-5.4' => 'GPT-5.4 (gpt-5.4)',
            'gpt-5.4-mini' => 'GPT-5.4 Mini (gpt-5.4-mini)',
            'gpt-5.4-nano' => 'GPT-5.4 Nano (gpt-5.4-nano)',
            'gpt-5' => 'GPT-5 (gpt-5)',
            'gpt-5-mini' => 'GPT-5 Mini (gpt-5-mini)',
            'gpt-5-nano' => 'GPT-5 Nano (gpt-5-nano)',
            'gpt-5-pro' => 'GPT-5 Pro (gpt-5-pro)',
            'o3' => 'OpenAI o3 (o3)',
            'gpt-4.1' => 'GPT-4.1 (gpt-4.1)',
            'gpt-4.1-mini' => 'GPT-4.1 Mini (gpt-4.1-mini)',
            'gpt-4o' => 'GPT-4o (gpt-4o)',
            'gpt-4o-mini' => 'GPT-4o Mini (gpt-4o-mini)',
            'gpt-image-2.6-sunburst' => 'GPT Image 2.6 Sunburst (gpt-image-2.6-sunburst)',
            'gpt-image-2.6-flare' => 'GPT Image 2.6 Flare (gpt-image-2.6-flare)',
            'gpt-image-2.5-sunburst' => 'GPT Image 2.5 Sunburst (gpt-image-2.5-sunburst)',
            'gpt-image-2.5-flare' => 'GPT Image 2.5 Flare (gpt-image-2.5-flare)',
            'gpt-live-1' => 'GPT Live 1 (gpt-live-1)',
            'gpt-realtime-2.1' => 'GPT Realtime 2.1 (gpt-realtime-2.1)',
            'gpt-realtime-1.5' => 'GPT Realtime 1.5 (gpt-realtime-1.5)',
            'gpt-audio-1.5' => 'GPT Audio 1.5 (gpt-audio-1.5)',
            'gpt-transcribe' => 'GPT Transcribe (gpt-transcribe)',
            'gpt-live-transcribe' => 'GPT Live Transcribe (gpt-live-transcribe)',
            'gpt-realtime-whisper' => 'GPT Realtime Whisper (gpt-realtime-whisper)',
            'text-embedding-3-large' => 'Text Embedding 3 Large (text-embedding-3-large)',
            'text-embedding-3-small' => 'Text Embedding 3 Small (text-embedding-3-small)',
            'omni-moderation-latest' => 'Omni Moderation Latest (omni-moderation-latest)',
            'gpt-oss-120b' => 'GPT OSS 120B (gpt-oss-120b)',
            'gpt-oss-20b' => 'GPT OSS 20B (gpt-oss-20b)'
        ];
    }

    public static function normalizeOpenAiModel(?string $model): string {
        $raw = strtolower(trim((string)$model));
        $catalog = self::getOpenAiCatalog();
        if (isset($catalog[$raw])) {
            return $raw;
        }
        if (preg_match('/^(?:gpt|o[1-9]|chatgpt|text-embedding|omni-moderation)[a-z0-9.\-]{1,56}$/', $raw)) {
            return $raw;
        }
        return 'gpt-4.1-mini';
    }

    public static function normalizeProvider(?string $provider, ?string $model = null, ?string $rawKey = null): string {
        $p = strtolower(trim((string)$provider));
        if ($p === 'openai' || $p === 'gemini') {
            return $p;
        }
        $k = trim((string)$rawKey);
        if (stripos($k, 'sk-') === 0) {
            return 'openai';
        }
        $m = strtolower(trim((string)$model));
        if (preg_match('/^(?:gpt|o[1-9]|chatgpt|text-embedding|omni-moderation)/', $m)) {
            return 'openai';
        }
        return 'gemini';
    }

    public function getAssistantSettings(): array {
        $defaults = [
            'language' => 'id',
            'voice_uri' => 'auto',
            'volume' => '0.90',
            'eye_blinking' => '1',
            'gaze_tracking' => '1',
            'auto_greeting' => '1',
            'voice_response' => '1',
            'webcam_tracking' => '0',
            'special_actions' => '0',
            'custom_outfit_enabled' => '0',
            'avatar_outfit' => 'classic_navy',
            'bg_effect_panel_enabled' => '0',
            'bg_effect' => 'snow',
            'eyewear_panel_enabled' => '0',
            'avatar_eyewear' => 'none',
            'env_bg_panel_enabled' => '0',
            'avatar_env_bg' => 'none',
            'voice_personality' => 'warm_lecturer',
            'conversation_style' => 'concise_friendly',
            'ai_provider' => 'gemini',
            'gemini_enabled' => '1',
            'gemini_model' => 'gemini-3.8-flash',
            'openai_model' => 'gpt-6.1-sol',
            'gemini_temperature' => '0.65',
            'gemini_max_tokens' => '260',
            'gemini_system_prompt' => 'Anda adalah Unklabot v.2.0.1, Asisten Dosen Virtual 3D dari Universitas Klabat (UNKLAB). Jawablah dalam bahasa yang digunakan pengguna (Indonesia atau Inggris) secara singkat, padat (maksimal 2-3 kalimat), sopan, cerdas, dan kontekstual.',
            'rag_enabled' => '1',
            'static_kb_enabled' => '1',
            'memory_enabled' => '1',
            'function_calling_enabled' => '1',
            'profanity_filter_enabled' => '1',
            'self_eval_enabled' => '1',
            'auto_self_learning_enabled' => '1',
            'auto_promote_knowledge' => '1'
        ];
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_system_settings')) {
            return $defaults;
        }
        $this->db->prepare("SELECT setting_key, setting_value FROM tbl_system_settings");
        $rows = $this->db->resultSet();
        foreach ($rows as $r) {
            $k = (string)($r['setting_key'] ?? '');
            if ($k !== '' && array_key_exists($k, $defaults)) {
                $defaults[$k] = (string)$r['setting_value'];
            }
        }
        $defaults['ai_provider'] = self::normalizeProvider($defaults['ai_provider']);
        $defaults['gemini_model'] = self::normalizeGeminiModel($defaults['gemini_model']);
        $defaults['openai_model'] = self::normalizeOpenAiModel($defaults['openai_model']);
        return $defaults;
    }

    public function saveAssistantSettings(array $payload, bool $adminPrivileged = false): array {
        $uiKeys = [
            'language', 'voice_uri', 'volume', 'eye_blinking', 'gaze_tracking', 'auto_greeting',
            'voice_response', 'webcam_tracking', 'special_actions', 'custom_outfit_enabled',
            'avatar_outfit', 'bg_effect_panel_enabled', 'bg_effect', 'eyewear_panel_enabled',
            'avatar_eyewear', 'env_bg_panel_enabled', 'avatar_env_bg', 'voice_personality'
        ];
        $adminKeys = [
            'conversation_style', 'ai_provider', 'gemini_enabled', 'gemini_model', 'openai_model', 'gemini_temperature',
            'gemini_max_tokens', 'gemini_system_prompt', 'rag_enabled', 'static_kb_enabled',
            'memory_enabled', 'function_calling_enabled', 'profanity_filter_enabled', 'self_eval_enabled',
            'auto_self_learning_enabled', 'auto_promote_knowledge'
        ];
        $allowed = $adminPrivileged ? array_merge($uiKeys, $adminKeys) : $uiKeys;
        $allowedOutfits = ['classic_navy', 'academic_tweed', 'emerald_dean', 'burgundy_scholar', 'charcoal_pin', 'ivory_batik'];
        $allowedEffects = ['snow', 'sakura', 'fireflies', 'autumn', 'starlight', 'rain', 'golden_ember'];
        $allowedEyewear = ['none', 'classic_gold_round', 'scholar_tortoise', 'crimson_cateye', 'silver_aviator', 'obsidian_square', 'rimless_titanium', 'browline_club', 'emerald_hexagon', 'rose_oversized', 'dark_sunglasses'];
        $allowedEnvBg = ['none', 'lecture_hall', 'library_archive', 'executive_office', 'minimal_studio', 'marble_foyer', 'sunset_terrace'];
        $allowedPersonalities = ['warm_lecturer', 'energetic_mentor', 'calm_scholar', 'crisp_anchor'];

        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_system_settings')) {
            return ['success' => false, 'settings' => $this->getAssistantSettings()];
        }
        foreach ($allowed as $key) {
            if (array_key_exists($key, $payload)) {
                $val = trim((string)$payload[$key]);
                if ($key === 'language' && !in_array($val, ['id', 'en'], true)) {
                    $val = 'id';
                }
                if ($key === 'avatar_outfit' && !in_array($val, $allowedOutfits, true)) {
                    $val = 'classic_navy';
                }
                if ($key === 'bg_effect' && !in_array($val, $allowedEffects, true)) {
                    $val = 'snow';
                }
                if ($key === 'avatar_eyewear' && !in_array($val, $allowedEyewear, true)) {
                    $val = 'none';
                }
                if ($key === 'avatar_env_bg' && !in_array($val, $allowedEnvBg, true)) {
                    $val = 'none';
                }
                if ($key === 'voice_personality' && !in_array($val, $allowedPersonalities, true)) {
                    $val = 'warm_lecturer';
                }
                if ($key === 'ai_provider') {
                    $val = self::normalizeProvider($val);
                }
                if ($key === 'gemini_model') {
                    $val = self::normalizeGeminiModel($val);
                }
                if ($key === 'openai_model') {
                    $val = self::normalizeOpenAiModel($val);
                }
                $this->db->prepare("INSERT INTO tbl_system_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
                $this->db->bind(':k', $key);
                $this->db->bind(':v', $val);
                $this->db->bind(':v2', $val);
                $this->db->execute();
            }
        }
        if ($adminPrivileged && array_key_exists('ai_provider', $payload) && $this->db->hasTable('tbl_ai_provider_keys')) {
            $chosenProv = self::normalizeProvider((string)$payload['ai_provider']);
            $this->db->prepare("SELECT id FROM tbl_ai_provider_keys WHERE is_active = 1 AND provider = :pv ORDER BY is_primary DESC, id DESC LIMIT 1");
            $this->db->bind(':pv', $chosenProv);
            $provKey = $this->db->single();
            if ($provKey && !empty($provKey['id'])) {
                $this->db->query("UPDATE tbl_ai_provider_keys SET is_primary = 0");
                $this->db->prepare("UPDATE tbl_ai_provider_keys SET is_primary = 1 WHERE id = :id");
                $this->db->bind(':id', (int)$provKey['id']);
                $this->db->execute();
            }
        }
        return [
            'success' => true,
            'settings' => $this->getAssistantSettings()
        ];
    }

    public function detectLanguage(string $text, string $fallback = 'id'): string {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $idWords = ['apa', 'apakah', 'siapa', 'bagaimana', 'gimana', 'berapa', 'dimana', 'kapan', 'mengapa', 'kenapa', 'tolong', 'saya', 'aku', 'kamu', 'anda', 'kuliah', 'kampus', 'sks', 'skripsi', 'dosen', 'mahasiswa', 'jelaskan', 'buatkan', 'hitung', 'jam', 'hari', 'selamat', 'pagi', 'siang', 'sore', 'malam', 'terima', 'kasih', 'tidak', 'bisa', 'yang', 'dan', 'untuk', 'dengan', 'dari'];
        $enWords = ['what', 'who', 'how', 'where', 'when', 'why', 'please', 'explain', 'tell', 'can', 'could', 'would', 'should', 'you', 'your', 'my', 'the', 'is', 'are', 'about', 'hello', 'good', 'morning', 'afternoon', 'evening', 'thank', 'thanks', 'summarize', 'calculate', 'database', 'university', 'course'];
        $tokens = preg_split('/[^a-z0-9]+/i', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $idScore = 0;
        $enScore = 0;
        foreach ($tokens as $tk) {
            if (in_array($tk, $idWords, true)) $idScore += 2;
            if (in_array($tk, $enWords, true)) $enScore += 2;
        }
        if ($idScore === 0 && $enScore === 0) {
            return in_array($fallback, ['id', 'en'], true) ? $fallback : 'id';
        }
        return $enScore > $idScore ? 'en' : 'id';
    }

    public function detectProfanity(string $text): array {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $lexicon = [
            'anjing' => 'High', 'anjir' => 'Low', 'anjay' => 'Low', 'bangsat' => 'High',
            'babi' => 'High', 'tolol' => 'Medium', 'goblok' => 'Medium', 'goblog' => 'Medium',
            'bodoh' => 'Medium', 'idiot' => 'Medium', 'dungu' => 'Medium', 'brengsek' => 'High',
            'kampret' => 'Medium', 'bajingan' => 'High', 'keparat' => 'High', 'sialan' => 'Low',
            'kontol' => 'High', 'memek' => 'High', 'jembut' => 'High', 'ngentot' => 'High',
            'pantek' => 'High', 'jancok' => 'High', 'jancuk' => 'High', 'asu' => 'High',
            'fuck' => 'High', 'shit' => 'Medium', 'bitch' => 'High', 'asshole' => 'High',
            'bastard' => 'High', 'stupid' => 'Low', 'moron' => 'Medium', 'dick' => 'High',
            'pussy' => 'High', 'cunt' => 'High', 'stfu' => 'Medium', 'wtf' => 'Low'
        ];
        $found = [];
        $maxSeverity = 'None';
        $rank = ['None' => 0, 'Low' => 1, 'Medium' => 2, 'High' => 3];

        foreach ($lexicon as $word => $sev) {
            if (preg_match('/\b' . preg_quote($word, '/') . '\b/u', $lower)) {
                $found[] = $word;
                if ($rank[$sev] > $rank[$maxSeverity]) {
                    $maxSeverity = $sev;
                }
            }
        }
        return [
            'is_profane' => !empty($found),
            'words' => array_values(array_unique($found)),
            'words_str' => !empty($found) ? implode(', ', array_unique($found)) : null,
            'severity' => $maxSeverity
        ];
    }

    public function detectEmotionAndGesture(string $text): array {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        if (preg_match('/\b(khawatir|stres|capek|lelah|galau|pusing|burnout|susah\s+tidur|insomnia|luka|sakit|demam|cemas|tertekan|worried|stressed|tired|exhausted|anxious|burn\s*out|sick|hurt)\b/u', $lower)) {
            return ['emotion' => 'empathy', 'expression' => 'EMPATHY', 'gesture' => 'NOD'];
        }
        if (preg_match('/\b(sedih|kecewa|putus\s+asa|menangis|sad|upset|depressed|heartbroken)\b/u', $lower)) {
            return ['emotion' => 'sad', 'expression' => 'EMPATHY', 'gesture' => 'NOD'];
        }
        if (preg_match('/\b(marah|kesal|benci|emosi|geram|angry|mad|furious|annoyed)\b/u', $lower)) {
            return ['emotion' => 'angry', 'expression' => 'CONFUSED', 'gesture' => 'NOD'];
        }
        if (preg_match('/\b(wah|hebat|keren|luar biasa|kaget|serius|wow|amazing|incredible|unbelievable|awesome)\b/u', $lower)) {
            return ['emotion' => 'surprised', 'expression' => 'SURPRISED', 'gesture' => 'APPLAUSE'];
        }
        if (preg_match('/\b(bingung|tidak mengerti|kurang paham|maksudnya|confused|unclear|dont understand)\b/u', $lower)) {
            return ['emotion' => 'confused', 'expression' => 'CONFUSED', 'gesture' => 'EXPLAIN'];
        }
        if (preg_match('/\b(terima kasih|makasih|thanks|thank you|syukur|senang|bahagia|happy|glad)\b/u', $lower)) {
            return ['emotion' => 'grateful', 'expression' => 'HAPPY', 'gesture' => 'BOW'];
        }
        if (preg_match('/\b(halo|hai|hello|hi|hey|pagi|siang|sore|malam|morning|afternoon|evening|bye|sampai jumpa)\b/u', $lower)) {
            return ['emotion' => 'friendly', 'expression' => 'HAPPY', 'gesture' => 'WAVE'];
        }
        if (preg_match('/\b(jelaskan|mengapa|bagaimana|analisis|hitung|apa itu|explain|why|how|analyze|compare)\b/u', $lower)) {
            return ['emotion' => 'curious', 'expression' => 'THINKING', 'gesture' => 'EXPLAIN'];
        }
        return ['emotion' => 'neutral', 'expression' => 'SMILING', 'gesture' => 'NOD'];
    }

    public function extractClientTelemetry(array $payload = []): array {
        $ip = Security::get_client_ip();
        $ua = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? 'Unknown'), 0, 255);
        $device = 'Desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/(iphone|ipod|blackberry|android|mobile|opera m(ob|in)i)/i', $ua)) {
            $device = 'Mobile';
        }

        $browser = 'Browser';
        if (stripos($ua, 'Edg/') !== false) $browser = 'Edge';
        elseif (stripos($ua, 'Chrome/') !== false) $browser = 'Chrome';
        elseif (stripos($ua, 'Firefox/') !== false) $browser = 'Firefox';
        elseif (stripos($ua, 'Safari/') !== false) $browser = 'Safari';

        $os = 'OS';
        if (stripos($ua, 'Windows NT 10') !== false) $os = 'Windows 10/11';
        elseif (stripos($ua, 'Windows') !== false) $os = 'Windows';
        elseif (stripos($ua, 'Android') !== false) $os = 'Android';
        elseif (stripos($ua, 'iPhone') !== false || stripos($ua, 'iPad') !== false) $os = 'iOS';
        elseif (stripos($ua, 'Mac OS X') !== false) $os = 'macOS';
        elseif (stripos($ua, 'Linux') !== false) $os = 'Linux';

        $clientLoc = trim((string)($payload['client_location'] ?? ($payload['timezone'] ?? '')));
        if ($clientLoc !== '') {
            $location = substr($clientLoc, 0, 110);
        } elseif ($ip === '127.0.0.1' || $ip === '::1' || strpos($ip, '192.168.') === 0 || strpos($ip, '10.') === 0) {
            $location = 'Airmadidi / Manado, ID (Local Campus Net)';
        } else {
            $location = 'Indonesia (' . $ip . ')';
        }

        return [
            'ip_address' => $ip,
            'device_type' => $device,
            'browser_os' => "$browser / $os",
            'user_agent' => $ua,
            'location_label' => $location
        ];
    }

    public function extractAndStoreMemories(string $sessionCode, string $userIdent, string $message): array {
        $extracted = [];
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_long_term_memories')) {
            return $extracted;
        }
        if (preg_match('/\b(siapa nama saya|nama saya siapa|apa proyek saya|apa yang kamu ingat|what is my name|what is my project|do you remember)\b/iu', $message)) {
            return $extracted;
        }

        $patterns = [
            'preferred_name' => [
                'type' => 'preference',
                'regex' => '/\b(?:nama saya(?:\s+adalah)?|namaku|panggil saya|my name is|call me)\s+([a-zA-Z\.\'\s]{2,35}?)(?=\s+\b(?:dan|and|saya|proyek|jurusan|dari|from|siapa|apa)\b|[,\.\!\?]|$)/iu'
            ],
            'user_major' => [
                'type' => 'fact',
                'regex' => '/\b(?:saya mahasiswa|jurusan saya|prodi saya|saya dari fakultas|i study|my major is|student of)\s+([a-zA-Z0-9\s]{3,50}?)(?=\s+\b(?:dan|and|saya|proyek|semester)\b|[,\.\!\?]|$)/iu'
            ],
            'current_project' => [
                'type' => 'project_context',
                'regex' => '/\b(?:proyek saya(?:\s+tentang)?|skripsi saya(?:\s+tentang)?|riset saya(?:\s+tentang)?|sedang mengerjakan|sedang meneliti|my project is|my thesis is|working on)\s+([^\.\!\?]{4,120})/iu'
            ],
            'user_note' => [
                'type' => 'fact',
                'regex' => '/\b(?:tolong ingat|catat bahwa|ingat ya|please remember|remember that)\s+([^\.\!\?]{4,120})/iu'
            ],
            'response_style' => [
                'type' => 'preference',
                'regex' => '/\b(?:saya suka penjelasan|gunakan gaya|jawab dengan gaya|i prefer)\s+([^\.\!\?]{3,60})/iu'
            ]
        ];

        foreach ($patterns as $key => $spec) {
            if (preg_match($spec['regex'], $message, $m, PREG_OFFSET_CAPTURE)) {
                $val = trim((string)$m[1][0]);
                $matchPos = (int)$m[0][1];
                $beforeMatch = strtolower(substr($message, max(0, $matchPos - 28), min(28, $matchPos)));
                if ($key === 'current_project' && preg_match('/\b(tidak|belum|bukan|nggak|gak|enggak|not|never)\b/u', $beforeMatch)) {
                    continue;
                }
                if ($key === 'preferred_name') {
                    if (preg_match('/^(?:bukan|not)\s+.+?\s+(?:tapi|melainkan|but)\s+(.+)$/iu', $val, $nm)) {
                        $val = trim($nm[1]);
                    }
                    if (preg_match('/\b(siapa|apa|tidak|bukan|belum)\b/iu', $val)) {
                        continue;
                    }
                }
                if (strlen($val) >= 2) {
                    $extracted[$key] = $val;
                    $this->saveMemoryItem($sessionCode, $userIdent, $spec['type'], $key, $val, 0.92);
                }
            }
        }

        if (preg_match('/\b(?:saya(?:\s+sekarang)?\s+(?:di\s+)?semester|aku(?:\s+sekarang)?\s+(?:di\s+)?semester|i\s+am\s+(?:in\s+)?semester|semester\s+saya)\s*(\d{1,2})\b/iu', $message, $sm)) {
            $sem = (int)$sm[1];
            if ($sem >= 1 && $sem <= 14) {
                $semVal = "Semester {$sem}";
                $extracted['user_semester'] = $semVal;
                $this->saveMemoryItem($sessionCode, $userIdent, 'fact', 'user_semester', $semVal, 0.92);
            }
        }

        if (preg_match('/\b(?:tidak|belum|bukan|nggak|gak|enggak|not)\s+(?:sedang\s+|lagi\s+|ambil\s+|mengambil\s+|mengerjakan\s+)?(?:skripsi|tugas\s+akhir|thesis)\b/iu', $message)) {
            $statusVal = 'Tidak sedang mengerjakan skripsi';
            $extracted['thesis_status'] = $statusVal;
            $this->saveMemoryItem($sessionCode, $userIdent, 'fact', 'thesis_status', $statusVal, 0.92);
            $this->db->prepare("DELETE FROM tbl_long_term_memories WHERE session_code = :sc AND memory_key = 'current_project' AND (LOWER(memory_value) LIKE '%skripsi%' OR LOWER(memory_value) LIKE '%thesis%')");
            $this->db->bind(':sc', $sessionCode);
            $this->db->execute();
        }

        if ($this->db->hasTable('tbl_user_profiles')) {
            $this->db->prepare("INSERT INTO tbl_user_profiles (session_code, preferred_name, project_context, total_interactions)
                VALUES (:sc, :pn, :pc, 1)
                ON DUPLICATE KEY UPDATE
                preferred_name = COALESCE(:pn2, preferred_name),
                project_context = COALESCE(:pc2, project_context),
                total_interactions = total_interactions + 1");
            $pName = $extracted['preferred_name'] ?? null;
            $pProj = $extracted['current_project'] ?? null;
            $this->db->bind(':sc', $sessionCode);
            $this->db->bind(':pn', $pName);
            $this->db->bind(':pc', $pProj);
            $this->db->bind(':pn2', $pName);
            $this->db->bind(':pc2', $pProj);
            $this->db->execute();
        }

        return $extracted;
    }

    public function saveMemoryItem(string $sessionCode, string $userIdent, string $type, string $key, string $value, float $importance = 0.85): bool {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_long_term_memories')) return false;
        $this->db->prepare("SELECT id FROM tbl_long_term_memories WHERE session_code = :sc AND memory_key = :mk LIMIT 1");
        $this->db->bind(':sc', $sessionCode);
        $this->db->bind(':mk', $key);
        $existing = $this->db->single();
        if ($existing) {
            $this->db->prepare("UPDATE tbl_long_term_memories SET memory_value = :mv, memory_type = :mt, importance = :imp, access_count = access_count + 1, last_accessed_at = NOW() WHERE id = :id");
            $this->db->bind(':mv', $value);
            $this->db->bind(':mt', $type);
            $this->db->bind(':imp', $importance);
            $this->db->bind(':id', (int)$existing['id']);
            return $this->db->execute();
        }
        $this->db->prepare("INSERT INTO tbl_long_term_memories (session_code, user_identifier, memory_type, memory_key, memory_value, importance, access_count, last_accessed_at)
            VALUES (:sc, :ui, :mt, :mk, :mv, :imp, 1, NOW())");
        $this->db->bind(':sc', $sessionCode);
        $this->db->bind(':ui', $userIdent);
        $this->db->bind(':mt', $type);
        $this->db->bind(':mk', $key);
        $this->db->bind(':mv', $value);
        $this->db->bind(':imp', $importance);
        return $this->db->execute();
    }

    public function getSessionMemories(string $sessionCode, int $limit = 8): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_long_term_memories') || trim($sessionCode) === '') return [];
        $this->db->prepare("SELECT * FROM tbl_long_term_memories WHERE session_code = :sc ORDER BY importance DESC, updated_at DESC LIMIT :lim");
        $this->db->bind(':sc', $sessionCode);
        $this->db->bind(':lim', $limit);
        return $this->db->resultSet();
    }

    public function getUserProfile(string $sessionCode): ?array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_user_profiles') || trim($sessionCode) === '') return null;
        $this->db->prepare("SELECT * FROM tbl_user_profiles WHERE session_code = :sc LIMIT 1");
        $this->db->bind(':sc', $sessionCode);
        $row = $this->db->single();
        return $row ?: null;
    }

    public function computeTfVector(string $text): array {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $words = preg_split('/[^a-z0-9_]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $stop = ['dan', 'yang', 'untuk', 'dengan', 'pada', 'dari', 'ini', 'itu', 'atau', 'dalam', 'adalah', 'the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'apa', 'what', 'how', 'bagaimana', 'jelaskan', 'tentang', 'mengapa', 'kenapa', 'sebutkan', 'tolong', 'bisa', 'penting', 'melatih', 'explain', 'why', 'please', 'tell', 'about'];
        $freq = [];
        foreach ($words as $w) {
            if (strlen($w) >= 3 && !in_array($w, $stop, true)) {
                $freq[$w] = ($freq[$w] ?? 0) + 1;
            }
        }
        arsort($freq);
        $top = array_slice($freq, 0, 35, true);
        $sumSq = 0.0;
        foreach ($top as $c) {
            $sumSq += $c * $c;
        }
        $norm = sqrt($sumSq) ?: 1.0;
        $vec = [];
        foreach ($top as $k => $c) {
            $vec[$k] = round($c / $norm, 4);
        }
        return $vec;
    }

    public function cosineSimilarity(array $vecA, array $vecB): float {
        if (empty($vecA) || empty($vecB)) return 0.0;
        $dot = 0.0;
        foreach ($vecA as $k => $v) {
            if (isset($vecB[$k])) {
                $dot += (float)$v * (float)$vecB[$k];
            }
        }
        return min(1.0, max(0.0, $dot));
    }

    private function condenseConciseReply(string $text, int $maxChars = 240, int $maxSentences = 2): string {
        $cite = '';
        $body = trim($text);
        if (preg_match('/\s*(\[(?:Sumber|Source):[^\]]+\])\s*$/u', $body, $cm)) {
            $cite = ' ' . trim($cm[1]);
            $body = trim(substr($body, 0, -strlen($cm[0])));
        }
        $body = preg_replace('/^#{1,6}\s+/m', '', $body);
        $body = preg_replace('/^\s*[-*•]\s+/m', '', $body);
        $body = preg_replace('/\*{1,3}([^\*]+)\*{1,3}/', '$1', $body);
        $body = preg_replace('/`{1,3}([^`]+)`{1,3}/', '$1', $body);
        $body = trim(preg_replace('/\s+/u', ' ', $body));
        if ($body === '') {
            return trim($cite);
        }

        $sentences = preg_split('/(?<=[.!?])\s+/u', $body, -1, PREG_SPLIT_NO_EMPTY);
        if (is_array($sentences) && count($sentences) > $maxSentences) {
            $picked = [];
            $len = 0;
            foreach ($sentences as $sent) {
                $sentTrim = trim($sent);
                if ($sentTrim === '') continue;
                $nextLen = $len + ($len > 0 ? 1 : 0) + strlen($sentTrim);
                if (!empty($picked) && ($nextLen > $maxChars || count($picked) >= $maxSentences)) {
                    break;
                }
                $picked[] = $sentTrim;
                $len = $nextLen;
                if (count($picked) >= $maxSentences) {
                    break;
                }
            }
            if (!empty($picked)) {
                $body = implode(' ', $picked);
            }
        }

        if (strlen($body) > $maxChars) {
            $cut = substr($body, 0, $maxChars);
            $lastSent = max((int)strrpos($cut, '. '), (int)strrpos($cut, '! '), (int)strrpos($cut, '? '));
            if ($lastSent > (int)($maxChars * 0.45)) {
                $body = trim(substr($cut, 0, $lastSent + 1));
            } else {
                $lastSpace = strrpos($cut, ' ');
                $body = ($lastSpace !== false && $lastSpace > 40 ? substr($cut, 0, $lastSpace) : $cut) . '...';
            }
        }

        return trim($body . $cite);
    }

    private function scoreRagChunks(string $query): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_knowledge_chunks') || !$this->db->hasTable('tbl_knowledge_documents')) {
            return [];
        }
        $qVec = $this->computeTfVector($query);
        $learner = $this->getSelfLearner();
        $qEnhVec = $learner->computeEnhancedVector($query, true);
        $coreTerms = $learner->extractDistinctKeywords($query, 8);
        if (empty($qVec) && empty($qEnhVec)) return [];

        $this->db->prepare("SELECT c.id, c.document_id, c.chunk_index, c.section_title, c.chunk_text, c.vector_json, d.title AS doc_title, d.file_name
            FROM tbl_knowledge_chunks c
            INNER JOIN tbl_knowledge_documents d ON d.id = c.document_id
            WHERE d.is_active = 1");
        $chunks = $this->db->resultSet();

        $coreSynGroups = [];
        foreach ($coreTerms as $ct) {
            $syns = array_keys($learner->expandSynonyms([$ct => 1.0]));
            $stem = $learner->stemWord($ct);
            if ($stem !== '' && strlen($stem) >= 3 && !in_array($stem, $syns, true)) {
                $syns[] = $stem;
            }
            $coreSynGroups[] = $syns;
        }
        $coreCount = count($coreSynGroups);

        $scored = [];
        $docBoostCache = [];
        $corePatternCache = [];
        foreach ($coreSynGroups as $synGroup) {
            $alts = [];
            foreach ($synGroup as $v) {
                if (strlen($v) >= 2) {
                    $alts[] = preg_quote($v, '/');
                }
            }
            $corePatternCache[] = !empty($alts) ? ('/\b(?:' . implode('|', $alts) . ')[a-z0-9]*/u') : '';
        }

        foreach ($chunks as $ch) {
            $cVec = json_decode((string)($ch['vector_json'] ?? '{}'), true);
            if (!is_array($cVec)) {
                $cVec = $this->computeTfVector((string)$ch['chunk_text']);
            }
            $secLower = strtolower((string)($ch['section_title'] ?? ''));
            $txtLower = strtolower((string)($ch['chunk_text'] ?? ''));
            $fullDocText = $secLower . ' ' . $txtLower;

            
            $matchedConcepts = 0;
            if ($coreCount > 0) {
                foreach ($corePatternCache as $pat) {
                    if ($pat !== '' && preg_match($pat, $fullDocText)) {
                        $matchedConcepts++;
                    }
                }
            }
            $cos = $this->cosineSimilarity($qVec, $cVec);
            if ($matchedConcepts === 0 && $cos < 0.05) {
                continue;
            }

            $enhCos = $learner->cosineVectors($qEnhVec, $learner->computeEnhancedVector($fullDocText, false));
            $sim = ($cos * 0.50) + ($enhCos * 0.50);

            $secBonus = 0.0;
            $txtBonus = 0.0;
            foreach ($coreTerms as $term) {
                if (strlen($term) >= 4 && preg_match('/\b' . preg_quote($term, '/') . '/u', $secLower)) {
                    $secBonus += 0.07;
                }
                if (strlen($term) >= 4 && preg_match('/\b' . preg_quote($term, '/') . '/u', $txtLower)) {
                    $txtBonus += 0.03;
                }
            }
            $sim += min(0.22, $secBonus) + min(0.14, $txtBonus);

            if ($coreCount > 0) {
                $coverage = $matchedConcepts / $coreCount;
                if ($coverage < 0.45) {
                    $sim *= max(0.12, $coverage * 0.65);
                } elseif ($coverage >= 0.70) {
                    $sim += 0.08;
                }
            }

            
            $hasCampusDomainCue = (bool)preg_match('/\b(unklab|klabat|kampus|filkom|feb|fik|fakultas|prodi|jurusan|akademik|sks|krs|ips|ipk|skripsi|yudisium|wisuda|asrama|dorm|spp|ukt|beasiswa|kip|rektor|dekan|presensi|kuliah|semester|cuti|chapel|klinik|kafetaria|perpus|perpustakaan|kurikulum|matkul|nilai|kelulusan|peminatan)\b/iu', $query);
            if ($matchedConcepts <= 1 && !$hasCampusDomainCue) {
                $sim *= 0.45;
            }

            if (strpos($secLower, 'implementasi & ketentuan praktis') === 0) {
                $sim *= 0.72;
            }

            $fKey = (string)($ch['file_name'] ?? '');
            if ($fKey !== '') {
                if (!isset($docBoostCache[$fKey])) {
                    $docBoostCache[$fKey] = $learner->getTargetWeightMultiplier('rag_doc', $fKey);
                }
                $sim = round($sim * $docBoostCache[$fKey], 4);
            }
            if ($sim > 0.08) {
                $ch['_sim'] = $sim;
                $scored[] = $ch;
            }
        }

        usort($scored, static function (array $a, array $b): int {
            return ($b['_sim'] <=> $a['_sim']);
        });
        return $scored;
    }

    public function searchRagKnowledgeMulti(string $query, string $lang = 'id', float $minScore = 0.18, int $limit = 3): ?array {
        $scored = $this->scoreRagChunks($query);
        if (empty($scored) || (float)$scored[0]['_sim'] < $minScore) {
            return null;
        }

        $top = $scored[0];
        $bestScore = (float)$top['_sim'];
        $this->db->prepare("UPDATE tbl_knowledge_documents SET retrieval_count = retrieval_count + 1 WHERE id = :id");
        $this->db->bind(':id', (int)$top['document_id']);
        $this->db->execute();

        $selected = [];
        $bundleParts = [];
        $bundleLen = 0;
        $threshold = max(0.14, $minScore * 0.78);
        foreach ($scored as $item) {
            if (count($selected) >= $limit) break;
            if ((float)$item['_sim'] < $threshold && !empty($selected)) break;
            $selected[] = $item;
            $chunkBody = trim((string)($item['chunk_text'] ?? ''));
            if (strlen($chunkBody) > 340) {
                $chunkBody = substr($chunkBody, 0, 336) . '...';
            }
            $entry = "[{$item['doc_title']} | {$item['section_title']}]: {$chunkBody}";
            if ($bundleLen + strlen($entry) > 980 && !empty($bundleParts)) {
                break;
            }
            $bundleParts[] = $entry;
            $bundleLen += strlen($entry);
        }

        $ref = $top['file_name'] . ' (' . $top['section_title'] . ', #' . $top['chunk_index'] . ')';
        $snippet = $this->condenseConciseReply(trim((string)$top['chunk_text']), 210, 2);
        $reply = $lang === 'en'
            ? "{$snippet} [Source: {$ref}]"
            : "{$snippet} [Sumber: {$ref}]";

        return [
            'score' => round($bestScore, 3),
            'reply' => $reply,
            'reference' => $ref,
            'document_title' => $top['doc_title'],
            'section_title' => $top['section_title'],
            'chunk_text' => $top['chunk_text'],
            'context_bundle' => implode("\n", $bundleParts)
        ];
    }

    public function searchRagKnowledge(string $query, string $lang = 'id', float $minScore = 0.22): ?array {
        return $this->searchRagKnowledgeMulti($query, $lang, $minScore, 1);
    }

    public function extractTextFromUploadedFile(string $tmpPath, string $originalName): array {
        return DocumentParser::parseUploadedFile($tmpPath, $originalName);
    }

    public function saveRagDocument(array $data, string $actor = 'admin'): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_knowledge_documents')) {
            return ['success' => false, 'message' => 'RAG database tables are not available yet.'];
        }
        $id = (int)($data['id'] ?? 0);
        $title = trim((string)($data['title'] ?? ''));
        $category = trim((string)($data['category'] ?? 'Akademik')) ?: 'Akademik';
        $fileName = trim((string)($data['file_name'] ?? 'Dokumen_Internal.txt')) ?: 'Dokumen_Internal.txt';
        $fileType = strtoupper(trim((string)($data['file_type'] ?? 'TXT')));
        if ($fileType === 'DOC') $fileType = 'DOCX';
        if (!in_array($fileType, ['PDF', 'DOCX', 'XLSX', 'TXT'], true)) $fileType = 'TXT';
        $contentText = DocumentParser::normalizeExtractedText((string)($data['content_text'] ?? ''));
        $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if ($title === '' || mb_strlen($contentText, 'UTF-8') < 10) {
            return ['success' => false, 'message' => 'Document title and text content (minimum 10 characters) are required.'];
        }
        if (!DocumentParser::isReadableDocumentText($contentText)) {
            return ['success' => false, 'message' => 'Document content rejected because it contains binary or unreadable garbled characters.'];
        }

        $rawParas = preg_split('/\n\s*\n|\r\n\s*\r\n/', $contentText, -1, PREG_SPLIT_NO_EMPTY);
        $chunks = [];
        $buf = '';
        foreach ($rawParas as $p) {
            $p = trim($p);
            if ($p === '') continue;
            if (strlen($buf . "\n" . $p) > 520 && $buf !== '') {
                $chunks[] = $buf;
                $buf = $p;
            } else {
                $buf = $buf === '' ? $p : ($buf . "\n" . $p);
            }
        }
        if ($buf !== '') $chunks[] = $buf;
        if (empty($chunks)) $chunks = [$contentText];

        if ($id > 0) {
            $this->db->prepare("UPDATE tbl_knowledge_documents SET title = :t, category = :c, file_name = :fn, file_type = :ft, file_size_bytes = :fs, content_text = :ct, chunk_count = :cc, is_active = :ia WHERE id = :id");
            $this->db->bind(':t', $title);
            $this->db->bind(':c', $category);
            $this->db->bind(':fn', $fileName);
            $this->db->bind(':ft', $fileType);
            $this->db->bind(':fs', strlen($contentText));
            $this->db->bind(':ct', $contentText);
            $this->db->bind(':cc', count($chunks));
            $this->db->bind(':ia', $isActive);
            $this->db->bind(':id', $id);
            $this->db->execute();

            $this->db->prepare("DELETE FROM tbl_knowledge_chunks WHERE document_id = :id");
            $this->db->bind(':id', $id);
            $this->db->execute();
            $docId = $id;
        } else {
            $docCode = 'DOC-' . strtoupper(substr(md5(uniqid((string)mt_rand(), true)), 0, 8));
            $this->db->prepare("INSERT INTO tbl_knowledge_documents (doc_code, title, category, file_name, file_type, file_size_bytes, content_text, chunk_count, is_active, retrieval_count, uploaded_by)
                VALUES (:dc, :t, :c, :fn, :ft, :fs, :ct, :cc, :ia, 0, :ub)");
            $this->db->bind(':dc', $docCode);
            $this->db->bind(':t', $title);
            $this->db->bind(':c', $category);
            $this->db->bind(':fn', $fileName);
            $this->db->bind(':ft', $fileType);
            $this->db->bind(':fs', strlen($contentText));
            $this->db->bind(':ct', $contentText);
            $this->db->bind(':cc', count($chunks));
            $this->db->bind(':ia', $isActive);
            $this->db->bind(':ub', $actor);
            $this->db->execute();
            $docId = (int)$this->db->lastInsertId();
        }

        foreach ($chunks as $idx => $chText) {
            $secTitle = $title . ' - Bagian #' . ($idx + 1);
            if (preg_match('/^\[([^\]]+)\]\s*(.*)$/s', $chText, $m)) {
                $secTitle = trim($m[1]);
                $chText = trim($m[2]) ?: $chText;
            }
            $vec = $this->computeTfVector($secTitle . ' ' . $chText);
            $kw = implode(', ', array_slice(array_keys($vec), 0, 16));
            $this->db->prepare("INSERT INTO tbl_knowledge_chunks (document_id, chunk_index, section_title, chunk_text, keywords_text, vector_json)
                VALUES (:did, :ci, :st, :ct, :kw, :vj)");
            $this->db->bind(':did', $docId);
            $this->db->bind(':ci', $idx + 1);
            $this->db->bind(':st', $secTitle);
            $this->db->bind(':ct', $chText);
            $this->db->bind(':kw', $kw);
            $this->db->bind(':vj', json_encode($vec));
            $this->db->execute();
        }

        
        if ($isActive === 1 && $this->db->hasTable('tbl_knowledge_gaps')) {
            $conn = $this->db->getConnection();
            if ($conn) {
                @$conn->query("UPDATE tbl_knowledge_gaps SET status = 'detected' WHERE status = 'needs_rich_source'");
            }
        }

        return [
            'success' => true,
            'document_id' => $docId,
            'chunk_count' => count($chunks),
            'message' => 'RAG document saved and indexed into ' . count($chunks) . ' semantic segments.'
        ];
    }

    private function parseCalendarTargetDate(string $lower, DateTimeImmutable $now, DateTimeZone $tz, string $lang): array {
        $monthMap = [
            'januari' => 1, 'january' => 1, 'jan' => 1,
            'februari' => 2, 'pebruari' => 2, 'february' => 2, 'feb' => 2,
            'maret' => 3, 'march' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'mei' => 5, 'may' => 5,
            'juni' => 6, 'june' => 6, 'jun' => 6,
            'juli' => 7, 'july' => 7, 'jul' => 7,
            'agustus' => 8, 'agus' => 8, 'ags' => 8, 'agu' => 8, 'august' => 8, 'aug' => 8,
            'september' => 9, 'sept' => 9, 'sep' => 9,
            'oktober' => 10, 'october' => 10, 'okt' => 10, 'oct' => 10,
            'november' => 11, 'nopember' => 11, 'nov' => 11, 'nop' => 11,
            'desember' => 12, 'december' => 12, 'des' => 12, 'dec' => 12
        ];
        $monthsId = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];
        $monthsEn = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
        $monthPattern = implode('|', array_keys($monthMap));

        $y = null;
        $m = null;
        $d = null;

        if (preg_match('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $lower, $dm)) {
            $y = (int)$dm[1];
            $m = (int)$dm[2];
            $d = (int)$dm[3];
        } elseif (preg_match('/\b(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})\b/', $lower, $dm)) {
            $d = (int)$dm[1];
            $m = (int)$dm[2];
            $y = (int)$dm[3];
        } elseif (preg_match('/\b(\d{1,2})\s+(' . $monthPattern . ')(?:\s+(\d{4}))?\b/u', $lower, $dm)) {
            $d = (int)$dm[1];
            $m = $monthMap[$dm[2]] ?? 0;
            $y = isset($dm[3]) && $dm[3] !== '' ? (int)$dm[3] : (int)$now->format('Y');
        } elseif (preg_match('/\b(' . $monthPattern . ')\s+(\d{1,2})(?:st|nd|rd|th)?(?:\s*,?\s*(\d{4}))?\b/u', $lower, $dm)) {
            $m = $monthMap[$dm[1]] ?? 0;
            $d = (int)$dm[2];
            $y = isset($dm[3]) && $dm[3] !== '' ? (int)$dm[3] : (int)$now->format('Y');
        } elseif (preg_match('/\b(kemarin\s+lusa|day\s+before\s+yesterday|kemarin|yesterday|lusa|day\s+after\s+tomorrow|besok|esok|tomorrow)\b/u', $lower, $rm)) {
            $kw = preg_replace('/\s+/', ' ', trim($rm[1]));
            $offset = 0;
            if ($kw === 'kemarin lusa' || $kw === 'day before yesterday') $offset = -2;
            elseif ($kw === 'kemarin' || $kw === 'yesterday') $offset = -1;
            elseif ($kw === 'besok' || $kw === 'esok' || $kw === 'tomorrow') $offset = 1;
            elseif ($kw === 'lusa' || $kw === 'day after tomorrow') $offset = 2;
            return ['matched' => true, 'date' => $now->modify(($offset >= 0 ? '+' : '') . $offset . ' days'), 'rel_label' => $kw];
        } elseif (preg_match('/\b(\d{1,3})\s+hari\s+(lagi|kedepan|ke\s+depan|mendatang|berikutnya)\b/u', $lower, $rm)) {
            $days = min(3650, max(1, (int)$rm[1]));
            return ['matched' => true, 'date' => $now->modify("+{$days} days"), 'rel_label' => "{$days} hari lagi"];
        } elseif (preg_match('/\b(\d{1,3})\s+hari\s+(?:yang\s+)?lalu\b/u', $lower, $rm)) {
            $days = min(3650, max(1, (int)$rm[1]));
            return ['matched' => true, 'date' => $now->modify("-{$days} days"), 'rel_label' => "{$days} hari lalu"];
        } elseif (preg_match('/\b(?:in\s+(\d{1,3})\s+days?|(\d{1,3})\s+days?\s+(?:from\s+now|later))\b/u', $lower, $rm)) {
            $days = min(3650, max(1, (int)(!empty($rm[1]) ? $rm[1] : $rm[2])));
            return ['matched' => true, 'date' => $now->modify("+{$days} days"), 'rel_label' => "in {$days} days"];
        } elseif (preg_match('/\b(\d{1,3})\s+days?\s+ago\b/u', $lower, $rm)) {
            $days = min(3650, max(1, (int)$rm[1]));
            return ['matched' => true, 'date' => $now->modify("-{$days} days"), 'rel_label' => "{$days} days ago"];
        } elseif (preg_match('/\b(\d{1,2})\s+minggu\s+(lagi|lalu)\b/u', $lower, $rm)) {
            $wks = min(520, max(1, (int)$rm[1]));
            $sign = $rm[2] === 'lalu' ? '-' : '+';
            return ['matched' => true, 'date' => $now->modify("{$sign}{$wks} weeks"), 'rel_label' => "{$wks} minggu {$rm[2]}"];
        } elseif (!preg_match('/\b(hari ini|sekarang|saat ini|today|now)\b/u', $lower) && preg_match('/\b(?:tanggal|tgl\.?|on\s+the)\s+(\d{1,2})(?:st|nd|rd|th)?\b/u', $lower, $dm)) {
            $d = (int)$dm[1];
            $m = (int)$now->format('n');
            $y = (int)$now->format('Y');
        }

        if ($d !== null && $m !== null && $y !== null) {
            if ($y < 1 || $y > 9999 || $m < 1 || $m > 12 || $d < 1 || !checkdate($m, $d, $y)) {
                $mLabel = $lang === 'en' ? ($monthsEn[$m] ?? "month {$m}") : ($monthsId[$m] ?? "bulan {$m}");
                $errTxt = $lang === 'en'
                    ? "The date {$mLabel} {$d}, {$y} is not a valid calendar date."
                    : "Tanggal {$d} {$mLabel} {$y} tidak valid dalam kalender.";
                return ['matched' => true, 'error' => $errTxt];
            }
            $dt = new DateTimeImmutable(sprintf('%04d-%02d-%02d 12:00:00', $y, $m, $d), $tz);
            return ['matched' => true, 'date' => $dt, 'rel_label' => null];
        }

        return ['matched' => false];
    }

    private function resolveTimezoneDisplay(string $timezoneName, string $lang = 'id'): array {
        $tzId = 'Asia/Makassar';
        if ($timezoneName !== '') {
            try {
                new DateTimeZone($timezoneName);
                $tzId = $timezoneName;
            } catch (Throwable $e) {
                $tzId = 'Asia/Makassar';
            }
        }
        $tz = new DateTimeZone($tzId);
        $now = new DateTimeImmutable('now', $tz);
        $upperTz = strtolower($tzId);
        if (strpos($upperTz, 'jakarta') !== false || strpos($upperTz, 'pontianak') !== false) {
            $zoneShort = 'WIB';
        } elseif (strpos($upperTz, 'makassar') !== false || strpos($upperTz, 'ujung_pandang') !== false) {
            $zoneShort = 'WITA';
        } elseif (strpos($upperTz, 'jayapura') !== false) {
            $zoneShort = 'WIT';
        } elseif (
            $now->getOffset() === 28800
            && ($lang === 'id' || in_array($tzId, ['Asia/Taipei', 'Asia/Shanghai', 'Asia/Hong_Kong', 'Asia/Singapore', 'Asia/Kuala_Lumpur', 'Asia/Manila', 'Asia/Brunei'], true))
        ) {
            $tzId = 'Asia/Makassar';
            $tz = new DateTimeZone($tzId);
            $now = $now->setTimezone($tz);
            $zoneShort = 'WITA';
        } else {
            $abbr = $now->format('T');
            $zoneShort = $abbr !== ''
                ? (preg_match('/^[+\-]/', $abbr) ? ('UTC' . $abbr) : $abbr)
                : 'WITA';
        }
        return ['tzId' => $tzId, 'tz' => $tz, 'now' => $now, 'zoneShort' => $zoneShort];
    }

    private function formatCalendarToolReply(string $lower, string $lang, string $timezoneName = 'Asia/Makassar'): string {
        $tzMeta = $this->resolveTimezoneDisplay($timezoneName, $lang);
        $tz = $tzMeta['tz'];
        $now = $tzMeta['now'];
        $tzLbl = $tzMeta['zoneShort'];
        $daysId = ['Sunday' => 'Minggu', 'Monday' => 'Senin', 'Tuesday' => 'Selasa', 'Wednesday' => 'Rabu', 'Thursday' => 'Kamis', 'Friday' => 'Jumat', 'Saturday' => 'Sabtu'];
        $monthsId = [1 => 'Januari', 'Februari', 'Maret', 'April', 'Mei', 'Juni', 'Juli', 'Agustus', 'September', 'Oktober', 'November', 'Desember'];

        $parsed = $this->parseCalendarTargetDate($lower, $now, $tz, $lang);
        if (!empty($parsed['matched'])) {
            if (!empty($parsed['error'])) {
                return (string)$parsed['error'];
            }
            $target = $parsed['date'];
            $dayEn = $target->format('l');
            $dayId = $daysId[$dayEn] ?? $dayEn;
            $tDay = (int)$target->format('j');
            $tMonthNum = (int)$target->format('n');
            $tYear = $target->format('Y');
            $tDateId = "{$tDay} " . ($monthsId[$tMonthNum] ?? $target->format('M')) . " {$tYear}";
            $tDateEn = $target->format('F j, Y');
            $rel = trim((string)($parsed['rel_label'] ?? ''));

            if ($target->format('Y-m-d') === $now->format('Y-m-d') && preg_match('/\b(jam berapa|waktu sekarang|what time|current time|semester aktif)\b/u', $lower) === 0) {
                return $lang === 'en'
                    ? "{$tDateEn} is today, {$dayEn}."
                    : "Tanggal {$tDateId} adalah hari ini, hari {$dayId}.";
            }
            if ($lang === 'en') {
                $isPast = $target->format('Y-m-d') < $now->format('Y-m-d');
                $verb = $isPast ? 'was' : 'is';
                $prefix = $rel !== '' ? (ucfirst($rel) . " ({$tDateEn}) {$verb} ") : "{$tDateEn} {$verb} ";
                return "{$prefix}{$dayEn}.";
            }
            $prefix = $rel !== '' ? (ucfirst($rel) . " adalah hari {$dayId}, tanggal {$tDateId}.") : "Tanggal {$tDateId} jatuh pada hari {$dayId}.";
            return $prefix;
        }

        $dayEn = $now->format('l');
        $dayId = $daysId[$dayEn] ?? $dayEn;
        $dayNum = (int)$now->format('j');
        $monthNum = (int)$now->format('n');
        $yearStr = $now->format('Y');
        $fullDateId = "{$dayNum} " . ($monthsId[$monthNum] ?? $now->format('M')) . " {$yearStr}";
        $fullDateEn = $now->format('F j, Y');
        $timeStr = $now->format('H:i');
        $sem = Controller::get_active_academic_semester();
        $semLbl = $lang === 'en' ? ($sem['semester_label_en'] ?? 'Odd Semester') : ($sem['semester_label'] ?? 'Semester Ganjil');

        $isDateOnlyQuery = (bool)preg_match('/\b(tanggal\s+berapa|sekarang\s+tanggal|hari\s+apa|what\s+date|what\s+day)\b/u', $lower)
            && !(bool)preg_match('/\b(jam\s+berapa|waktu\s+sekarang|what\s+time|current\s+time)\b/u', $lower);
        if ($isDateOnlyQuery) {
            return $lang === 'en'
                ? "Today is {$dayEn}, {$fullDateEn} ({$timeStr} {$tzLbl} · {$semLbl})."
                : "Hari ini hari {$dayId}, tanggal {$fullDateId} (pukul {$timeStr} {$tzLbl} · {$semLbl}).";
        }

        return $lang === 'en'
            ? "It is currently {$timeStr} {$tzLbl} on {$dayEn}, {$fullDateEn} (Active term: {$semLbl})."
            : "Saat ini pukul {$timeStr} {$tzLbl}, hari {$dayId}, {$fullDateId} (Periode aktif: {$semLbl}).";
    }

    public function tryExecuteAgentTool(string $message, string $lower, string $lang, string $sessionCode, string $timezoneName = 'Asia/Makassar'): ?array {
        return AgentToolsRegistry::dispatch(
            $message,
            $lower,
            $lang,
            $sessionCode,
            $timezoneName,
            $this->db,
            fn(string $tc) => $this->incrementToolCall($tc),
            fn(string $l, string $lg, string $tz) => $this->formatCalendarToolReply($l, $lg, $tz),
            fn(string $q, string $lg, float $min) => $this->searchRagKnowledge($q, $lg, $min)
        );
    }

    private function incrementToolCall(string $toolCode): void {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_agent_tools')) return;
        $this->db->prepare("UPDATE tbl_agent_tools SET call_count = call_count + 1, last_called_at = NOW() WHERE tool_code = :tc");
        $this->db->bind(':tc', $toolCode);
        $this->db->execute();
    }

    private function buildLlmPromptContext(string $userMessage, string $lang, string $sessionCode, ?array $ragContext, array $settings, ?string $kbHint = null): array {
        $temp = max(0.0, min(1.5, (float)($settings['gemini_temperature'] ?? 0.55)));
        $maxTokens = max(120, min(300, (int)($settings['gemini_max_tokens'] ?? 220)));
        $basePrompt = trim((string)($settings['gemini_system_prompt'] ?? ''));

        $identityRule = $lang === 'en'
            ? "Campus Identity: Unklabot v.2.0.1 was developed by the AI & Robotics Laboratory, Faculty of Computer Science (FILKOM), Klabat University (UNKLAB), located at Jl. Arnold Mononutu, Airmadidi, North Minahasa, North Sulawesi, and operates via UNKLAB's digital platform. Never invent user study details not in User Memory; acknowledge user status corrections naturally."
            : "Identitas Kampus: Unklabot v.2.0.1 dikembangkan oleh Laboratorium Kecerdasan Buatan & Robotika FILKOM Universitas Klabat (UNKLAB), berlokasi di Jl. Arnold Mononutu, Airmadidi, Minahasa Utara, Sulawesi Utara, dan beroperasi melalui sistem digital kampus UNKLAB. Jangan mengarang fakta studi pengguna yang tidak ada di User Memory; jika pengguna mengoreksi status studinya, akui dengan tepat.";
        $conciseRule = $lang === 'en'
            ? "Always reply concisely, densely, and clearly in at most 2 short sentences (under 40 words) suitable for a 3D speaking assistant. Use plain natural text with no markdown or bullet lists."
            : "Selalu jawab secara singkat, padat, dan jelas dalam maksimal 2 kalimat pendek (sekitar 20-40 kata) yang nyaman diucapkan asisten virtual 3D. Gunakan teks natural tanpa markdown atau daftar poin.";
        if ($ragContext) {
            $ragRule = $lang === 'en'
                ? "Verify the RAG document excerpts against the user's question first: summarize only directly relevant facts. If the excerpts do not address the question, answer directly without mentioning RAG documents."
                : "Verifikasi kesesuaian kutipan dokumen RAG dengan pertanyaan pengguna terlebih dahulu: rangkum hanya fakta yang relevan. Jika kutipan tidak sesuai pertanyaan, jawab langsung tanpa menyebut dokumen RAG.";
            $sysPrompt = trim($basePrompt . "\n" . $identityRule . "\n" . $ragRule . "\n" . $conciseRule);
        } else {
            $sysPrompt = trim($basePrompt . "\n" . $identityRule . "\n" . $conciseRule);
        }

        $memories = $this->getSessionMemories($sessionCode, 5);
        $memLines = [];
        foreach ($memories as $mem) {
            $memLines[] = $mem['memory_key'] . ': ' . $mem['memory_value'];
        }
        $memBlock = !empty($memLines) ? ("User Memory: " . implode(' | ', $memLines)) : '';
        $ragRawText = $ragContext ? (string)($ragContext['context_bundle'] ?? $ragContext['chunk_text'] ?? '') : '';
        $ragBlock = $ragRawText !== '' ? ("RAG Context ({$ragContext['reference']}):\n" . $ragRawText) : '';
        $kbBlock = ($kbHint !== null && trim($kbHint) !== '') ? ("Static KB Reference: " . trim($kbHint)) : '';

        $this->db->prepare("SELECT user_message, assistant_response FROM tbl_va_conversations WHERE session_code = :sc ORDER BY id DESC LIMIT 2");
        $this->db->bind(':sc', $sessionCode);
        $rawHist = array_reverse($this->db->resultSet());
        $historyRows = [];
        foreach ($rawHist as $hr) {
            $uMsg = substr(trim((string)$hr['user_message']), 0, 140);
            $aMsg = substr(trim((string)$hr['assistant_response']), 0, 160);
            if ($uMsg !== '' && $aMsg !== '') {
                $historyRows[] = ['user_message' => $uMsg, 'assistant_response' => $aMsg];
            }
        }

        $blocks = array_filter([$memBlock, $kbBlock, $ragBlock]);
        $augmentedUserText = (!empty($blocks) ? (implode("\n", $blocks) . "\n\n") : '') . "Question: " . $userMessage;

        return [
            'temperature' => $temp,
            'maxTokens' => $maxTokens,
            'systemPrompt' => $sysPrompt,
            'history' => $historyRows,
            'augmentedUserText' => $augmentedUserText
        ];
    }

    private function recordLlmKeyError(int $keyId, array $resHttp): void {
        $httpCode = (int)($resHttp['http_code'] ?? 0);
        $errCodeTxt = (string)($resHttp['decoded']['error']['status'] ?? ($resHttp['decoded']['error']['code'] ?? ($resHttp['curl_error'] ?? 'Check Key')));
        $errStatus = substr("HTTP {$httpCode} ({$errCodeTxt})", 0, 62);
        $this->db->prepare("UPDATE tbl_ai_provider_keys SET last_status = :st, last_used_at = NOW() WHERE id = :id");
        $this->db->bind(':st', $errStatus);
        $this->db->bind(':id', $keyId);
        $this->db->execute();
    }

    private function recordLlmKeySuccess(int $keyId, int $tokensUsed): void {
        $this->db->prepare("UPDATE tbl_ai_provider_keys SET total_requests = total_requests + 1, total_tokens = total_tokens + :tk, last_status = 'Connected (200 OK)', last_used_at = NOW() WHERE id = :id");
        $this->db->bind(':tk', $tokensUsed);
        $this->db->bind(':id', $keyId);
        $this->db->execute();
    }

    public function callActiveLlm(string $userMessage, string $lang, string $sessionCode, ?array $ragContext = null, ?string $kbHint = null): ?array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_ai_provider_keys')) {
            return null;
        }
        $settings = $this->getAssistantSettings();
        if (($settings['gemini_enabled'] ?? '1') !== '1') {
            return null;
        }
        $provider = self::normalizeProvider($settings['ai_provider'] ?? 'gemini');
        if ($provider === 'openai') {
            $primaryOut = $this->callOpenAiApi($userMessage, $lang, $sessionCode, $ragContext, $settings, $kbHint);
            if ($primaryOut !== null) return $primaryOut;
            return $this->callGeminiApi($userMessage, $lang, $sessionCode, $ragContext, $settings, $kbHint);
        }
        $primaryOut = $this->callGeminiApi($userMessage, $lang, $sessionCode, $ragContext, $settings, $kbHint);
        if ($primaryOut !== null) return $primaryOut;
        return $this->callOpenAiApi($userMessage, $lang, $sessionCode, $ragContext, $settings, $kbHint);
    }

    public function callOpenAiApi(string $userMessage, string $lang, string $sessionCode, ?array $ragContext = null, ?array $settings = null, ?string $kbHint = null): ?array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_ai_provider_keys')) {
            return null;
        }
        $settings = $settings ?? $this->getAssistantSettings();
        if (($settings['gemini_enabled'] ?? '1') !== '1') {
            return null;
        }
        $this->db->prepare("SELECT * FROM tbl_ai_provider_keys WHERE is_active = 1 AND provider = 'openai' ORDER BY is_primary DESC, id ASC LIMIT 5");
        $keyRows = $this->db->resultSet();
        if (empty($keyRows)) {
            return null;
        }

        $ctx = $this->buildLlmPromptContext($userMessage, $lang, $sessionCode, $ragContext, $settings, $kbHint);
        $inputMessages = [];
        foreach ($ctx['history'] as $hr) {
            $inputMessages[] = ['role' => 'user', 'content' => (string)$hr['user_message']];
            $inputMessages[] = ['role' => 'assistant', 'content' => (string)$hr['assistant_response']];
        }
        $inputMessages[] = ['role' => 'user', 'content' => $ctx['augmentedUserText']];

        foreach ($keyRows as $keyRow) {
            $apiKey = trim($this->decryptSecret((string)($keyRow['api_key_encrypted'] ?? '')), " \t\n\r\0\x0B\"'");
            if ($apiKey === '' || strlen($apiKey) < 12) {
                continue;
            }
            $model = self::normalizeOpenAiModel((string)(($keyRow['model_name'] ?? '') ?: ($settings['openai_model'] ?? 'gpt-6.1-sol')));
            $maxTok = min(320, max($ctx['maxTokens'], 220));
            $resHttp = $this->executeOpenAiHttp($model, $apiKey, $ctx['systemPrompt'], $inputMessages, $maxTok, $ctx['temperature'], 14);
            if (!$resHttp['ok']) {
                $this->recordLlmKeyError((int)$keyRow['id'], $resHttp);
                continue;
            }
            $decoded = $resHttp['decoded'];
            $textOut = $this->extractOpenAiReplyText($decoded);
            if ($textOut === '') {
                continue;
            }
            $tokensUsed = (int)($decoded['usage']['total_tokens'] ?? 85);
            $this->recordLlmKeySuccess((int)$keyRow['id'], $tokensUsed);
            return [
                'reply' => $textOut,
                'model' => $model,
                'tokens' => $tokensUsed,
                'source' => 'OpenAI LLM'
            ];
        }
        return null;
    }

    public function callGeminiApi(string $userMessage, string $lang, string $sessionCode, ?array $ragContext = null, ?array $settings = null, ?string $kbHint = null): ?array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_ai_provider_keys')) {
            return null;
        }
        $settings = $settings ?? $this->getAssistantSettings();
        if (($settings['gemini_enabled'] ?? '1') !== '1') {
            return null;
        }

        $this->db->prepare("SELECT * FROM tbl_ai_provider_keys WHERE is_active = 1 AND provider = 'gemini' ORDER BY is_primary DESC, id ASC LIMIT 5");
        $keyRows = $this->db->resultSet();
        if (empty($keyRows)) {
            return null;
        }

        $ctx = $this->buildLlmPromptContext($userMessage, $lang, $sessionCode, $ragContext, $settings, $kbHint);
        $contents = [];
        foreach ($ctx['history'] as $hr) {
            $contents[] = ['role' => 'user', 'parts' => [['text' => (string)$hr['user_message']]]];
            $contents[] = ['role' => 'model', 'parts' => [['text' => (string)$hr['assistant_response']]]];
        }
        $contents[] = ['role' => 'user', 'parts' => [['text' => $ctx['augmentedUserText']]]];

        foreach ($keyRows as $keyRow) {
            $apiKey = trim($this->decryptSecret((string)($keyRow['api_key_encrypted'] ?? '')), " \t\n\r\0\x0B\"'");
            if ($apiKey === '' || strlen($apiKey) < 12) {
                continue;
            }
            $model = self::normalizeGeminiModel((string)(($keyRow['model_name'] ?? '') ?: ($settings['gemini_model'] ?? 'gemini-3.8-flash')));
            $genCfg = [
                'temperature' => $ctx['temperature'],
                'maxOutputTokens' => min(260, max($ctx['maxTokens'], 180))
            ];
            if (strpos($model, 'tts') === false && strpos($model, 'image') === false && strpos($model, 'transcribe') === false) {
                $genCfg['thinkingConfig'] = ['thinkingBudget' => 0];
            }
            $body = [
                'systemInstruction' => [
                    'parts' => [['text' => $ctx['systemPrompt']]]
                ],
                'contents' => $contents,
                'generationConfig' => $genCfg
            ];
            $resHttp = $this->executeGeminiHttp($model, $apiKey, $body, 12);
            if (!$resHttp['ok']) {
                $this->recordLlmKeyError((int)$keyRow['id'], $resHttp);
                continue;
            }
            $decoded = $resHttp['decoded'];
            $textOut = $this->extractGeminiReplyText($decoded);
            if ($textOut === '') {
                continue;
            }

            $tokensUsed = (int)($decoded['usageMetadata']['totalTokenCount'] ?? 85);
            $this->recordLlmKeySuccess((int)$keyRow['id'], $tokensUsed);

            return [
                'reply' => $textOut,
                'model' => $model,
                'tokens' => $tokensUsed,
                'source' => 'Gemini LLM'
            ];
        }
        return null;
    }

    public function evaluateAndRefineResponse(string $reply, string $userMsg, string $lang, string $source, array $evalCtx = []): array {
        $maxChars = ($source === 'SQL Assistant') ? 340 : 300;
        $maxSentences = ($source === 'SQL Assistant') ? 3 : 2;
        $clean = $this->condenseConciseReply($reply, $maxChars, $maxSentences);

        $learner = $this->getSelfLearner();
        $ev = $learner->evaluateTurn(array_merge([
            'user_message' => $userMsg,
            'reply' => $clean,
            'response_source' => $source,
            'language' => $lang
        ], $evalCtx));

        $followUp = $learner->getAdaptiveFollowUp(
            (string)$ev['intent_tag'],
            $userMsg,
            $lang,
            ((int)$ev['is_unanswered']) === 1
        );

        return [
            'reply' => $clean,
            'score' => $ev['eval_score'],
            'is_unanswered' => $ev['is_unanswered'],
            'failure_reason' => $ev['failure_reason'],
            'intent_tag' => $ev['intent_tag'],
            'flow_status' => $ev['flow_status'],
            'follow_up' => $followUp
        ];
    }

    private function isStandaloneConversationalHit(string $lower, string $kw, bool $hasQuestionCue, bool $hasDirectiveOrThirdParty): bool {
        if ($hasDirectiveOrThirdParty || $hasQuestionCue) {
            return false;
        }
        $stripped = preg_replace('/(?<![a-z0-9])' . preg_quote($kw, '/') . '(?![a-z0-9])/u', ' ', $lower);
        $stripped = preg_replace('/\b(unklabot|bot|asisten|assistant|bu|pak|ibu|bapak|dosen|lecturer|dunia|world|semua|semuanya|everyone|teman|ya|yah|dong|deh|nih|sih|banget|sekali|banyak|very|much|please|pls|kak|bro|sis)\b/u', ' ', $stripped);
        $stripped = trim(preg_replace('/[^a-z0-9\s]+/u', ' ', $stripped));
        if ($stripped === '') {
            return true;
        }
        $leftovers = preg_split('/\s+/u', $stripped, -1, PREG_SPLIT_NO_EMPTY);
        if (count($leftovers) > 1) {
            return false;
        }
        return strlen($leftovers[0]) < 3;
    }

    

    private function matchStaticKnowledgeBase(string $message, string $lower, string $lang, SelfLearningEngine $learner): array {
        $empty = [
            'kb' => null, 'len' => 0, 'score' => 0.0,
            'matched' => false, 'is_pure_conversational' => false,
            'has_negated_or_personal_intent' => false
        ];
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_static_knowledge')) {
            return $empty;
        }

        $hasQuestionMark = strpos($message, '?') !== false;
        $hasQueryCue = (bool)preg_match('/\b(apa|apakah|bagaimana|gimana|kenapa|mengapa|siapa|kapan|dimana|di\s+mana|berapa|syarat|cara|aturan|ketentuan|prosedur|alur|jelaskan|sebutkan|jelasin|ceritakan|beritahu|kasih\s+tahu|tanya|bertanya|ingin\s+tahu|mau\s+tahu|minta|cari|info|informasi|daftar|jadwal|tips|perbedaan|arti|definisi|contoh|tentang|mengenai|mau\s+dengar|bikin|buatkan|what|how|why|who|when|where|which|explain|tell|describe|list|requirements|rules|schedule|tips|define|meaning|about)\b/u', $lower);
        $hasNegation = (bool)preg_match('/\b(?:tidak|tak|bukan|belum|jangan|tanpa|enggak|nggak|gak|not|no|never|don\'?t|doesn\'?t|didn\'?t|isn\'?t|aren\'?t|without)\b/u', $lower);
        $isPersonalStatement = !$hasQuestionMark && !$hasQueryCue && (bool)preg_match('/^\s*(?:saya|aku|kami|kita|gua|gue|i\s+am|i\'m|im|my|actually|sebenarnya)\b/u', $lower);
        $hasDirectiveOrThirdParty = (bool)preg_match('/\b(katakan|bilang|ucapkan|sampaikan|sebut|sebutkan|tulis|tuliskan|ketik|kirim|titip\s+salam|say|tell|greet|speak\s+to)\b/u', $lower)
            || (bool)preg_match('/\b(ke|kepada|sama|buat|untuk|to|for)\s+(?:adik|kakak|teman|sahabat|ibu|ayah|mama|papa|dosen|dia|mereka|pacar|keluarga|orang|brother|sister|friend|him|her|them)\b/u', $lower);

        $this->db->prepare("SELECT id, topic_code, category, keywords_id, keywords_en, response_id, response_en, expression, gesture FROM tbl_static_knowledge WHERE is_active = 1 ORDER BY priority DESC, id ASC");
        $kbRows = $this->db->resultSet();

        $bestKb = null;
        $bestLen = 0;
        $bestKbScore = 0.0;

        foreach ($kbRows as $kb) {
            $topicCode = (string)($kb['topic_code'] ?? '');
            $kbCat = (string)($kb['category'] ?? '');
            $kwField = $lang === 'en' ? ($kb['keywords_en'] . ',' . $kb['keywords_id']) : ($kb['keywords_id'] . ',' . $kb['keywords_en']);
            $keywords = array_filter(array_map('trim', explode(',', strtolower($kwField))));
            $mult = $learner->getTargetWeightMultiplier('kb', $topicCode !== '' ? $topicCode : (string)$kb['id']);

            if ($isPersonalStatement && !in_array($kbCat, ['Sapaan', 'Etika', 'Identitas', 'Emosi & Empati'], true)) {
                continue;
            }

            foreach ($keywords as $kw) {
                if ($kw === '') {
                    continue;
                }
                $kwQuoted = preg_quote($kw, '/');
                if (!preg_match('/(?<![a-z0-9])' . $kwQuoted . '(?![a-z0-9])/u', $lower)) {
                    continue;
                }
                $negPat = '/\b(?:tidak|tak|bukan|belum|jangan|tanpa|enggak|nggak|gak|anti|stop|not|no|never|don\'?t|doesn\'?t|didn\'?t|isn\'?t|aren\'?t|without)\b(?:\s+[a-z0-9\'\-]+){0,3}\s+' . $kwQuoted . '(?![a-z0-9])/u';
                if (preg_match($negPat, $lower)) {
                    continue;
                }
                if (in_array($kbCat, ['Sapaan', 'Etika'], true) && !in_array($topicCode, ['how_are_you', 'compliment_appearance', 'profanity_policy'], true)) {
                    if (!$this->isStandaloneConversationalHit($lower, $kw, $hasQuestionMark || $hasQueryCue, $hasDirectiveOrThirdParty)) {
                        continue;
                    }
                }
                if ($topicCode === 'how_are_you' && $hasDirectiveOrThirdParty) {
                    continue;
                }

                $kwLen = strlen($kw);
                $wScore = $kwLen * $mult;
                if ($wScore > $bestKbScore) {
                    $bestKbScore = $wScore;
                    $bestLen = $kwLen;
                    $bestKb = $kb;
                }
            }
        }

        $kbMatched = ($bestKb !== null && ($bestLen >= 5 || strlen($lower) <= 42));
        $kbCat = $kbMatched ? (string)($bestKb['category'] ?? '') : '';
        $isPureConversational = $kbMatched && in_array($kbCat, ['Sapaan', 'Etika', 'Identitas'], true) && str_word_count($message) <= 6;

        return [
            'kb' => $bestKb,
            'len' => $bestLen,
            'score' => $bestKbScore,
            'matched' => $kbMatched,
            'is_pure_conversational' => $isPureConversational,
            'is_personal_statement' => $isPersonalStatement,
            'has_negation' => $hasNegation
        ];
    }

    

    private function buildVisualCardData(
        string $message,
        string $lower,
        string $reply,
        string $lang,
        string $source,
        ?string $toolCalled,
        ?array $bestKb,
        string $timezone,
        string $emotion,
        float $evalScore = 0.90
    ): array {
        $card = $this->resolveVisualCardPayload(
            $message,
            $lower,
            $reply,
            $lang,
            $source,
            $toolCalled,
            $bestKb,
            $timezone,
            $emotion
        );

        $evalPct = max(0, min(100, (int)round($evalScore * 100)));
        $srcLabel = trim($source) !== '' ? $source : 'Smart Contextual AI';
        $feedbackLabel = "{$srcLabel} · Eval {$evalPct}%";

        $card['replyText'] = $reply;
        $card['source'] = $srcLabel;
        $card['evalScore'] = round($evalScore, 4);
        $card['evalPct'] = $evalPct;
        $card['feedbackLabel'] = $feedbackLabel;
        $card['footer'] = $feedbackLabel;
        if (empty($card['subtitle'])) {
            $card['subtitle'] = $reply;
        }
        return $card;
    }

    

    private function resolveVisualCardPayload(
        string $message,
        string $lower,
        string $reply,
        string $lang,
        string $source,
        ?string $toolCalled,
        ?array $bestKb,
        string $timezone,
        string $emotion
    ): array {
        $topicCode = (string)($bestKb['topic_code'] ?? '');
        $tc = (string)($toolCalled ?? '');

        
        if (
            in_array($tc, ['get_current_datetime_info', 'convert_world_timezone', 'check_day_holiday_status'], true)
            || $topicCode === 'daily_timezones_indonesia'
            || ($tc === '' && preg_match('/\b(jam\s+berapa|waktu\s+sekarang|tanggal\s+berapa|what\s+time|current\s+time)\b/u', $lower))
        ) {
            $tzMeta = $this->resolveTimezoneDisplay($timezone, $lang);
            $resolvedTzId = $tzMeta['tzId'];
            $zoneShort = $tzMeta['zoneShort'];
            $isRelCal = (bool)preg_match('/\b(besok|lusa|kemarin|tomorrow|yesterday|tanggal\s+\d)\b/u', $lower);
            $isDateOnly = (bool)preg_match('/\b(tanggal\s+berapa|sekarang\s+tanggal|hari\s+apa|what\s+date|what\s+day)\b/u', $lower)
                && !(bool)preg_match('/\b(jam\s+berapa|waktu\s+sekarang|what\s+time|current\s+time)\b/u', $lower);
            $chips = $topicCode === 'daily_timezones_indonesia'
                ? ['WIB UTC+7', 'WITA UTC+8', 'WIT UTC+9']
                : [$zoneShort . ' · ' . $resolvedTzId, $lang === 'en' ? 'Real-Time Sync' : 'Sinkron Real-Time'];
            return [
                'type' => 'clock',
                'title' => $topicCode === 'daily_timezones_indonesia'
                    ? ($lang === 'en' ? 'INDONESIA TIME ZONES' : 'ZONA WAKTU INDONESIA')
                    : ($isDateOnly
                        ? ($lang === 'en' ? "TODAY'S CALENDAR" : 'TANGGAL HARI INI')
                        : ($lang === 'en' ? 'CURRENT TIME & DATE' : 'WAKTU & TANGGAL')),
                'badge' => $isRelCal
                    ? ($lang === 'en' ? 'CALENDAR' : 'KALENDER')
                    : ($isDateOnly ? ($lang === 'en' ? 'LIVE DATE' : 'KALENDER LIVE') : 'LIVE CLOCK'),
                'timezone' => $resolvedTzId,
                'zoneShort' => $zoneShort,
                'liveClock' => !$isRelCal,
                'dateText' => $isRelCal ? mb_substr($reply, 0, 56, 'UTF-8') : '',
                'timeText' => preg_match('/\b(\d{1,2}[:\.]\d{2})\b/', $reply, $tm) ? $tm[1] : '',
                'chips' => $chips,
                'lang' => $lang,
                'accent' => '#38bdf8',
                'footer' => $lang === 'en' ? 'Live 3D Time Display' : 'Panel Waktu Real-Time 3D'
            ];
        }

        
        if ($tc === 'external_weather_airmadidi' || $topicCode === 'weather_campus' || $topicCode === 'daily_rainy_weather_prep') {
            $tempVal = preg_match('/(\d+(?:[\.,]\d+)?(?:\s*[–\-]\s*\d+(?:[\.,]\d+)?)?\s*°\s*C)/ui', $reply, $wm)
                ? str_replace(' ', '', $wm[1])
                : '27°C';
            $windVal = preg_match('/(\d+(?:[\.,]\d+)?\s*km\/jam)/ui', $reply, $wn) ? $wn[1] : null;
            return [
                'type' => 'weather',
                'title' => $lang === 'en' ? 'CAMPUS WEATHER' : 'CUACA KAMPUS UNKLAB',
                'badge' => $tc === 'external_weather_airmadidi' ? 'OPEN-METEO LIVE' : 'AIRMADIDI',
                'primaryValue' => $tempVal,
                'subtitle' => $reply,
                'chips' => array_values(array_filter(['Airmadidi', $windVal ? "Angin {$windVal}" : 'Tropis Sejuk'])),
                'accent' => '#38bdf8',
                'secondaryAccent' => '#fbbf24'
            ];
        }

        
        if (
            in_array($tc, ['calc_discount_price', 'calc_tax_ppn', 'calc_percentage_value', 'calc_compound_interest', 'calc_loan_installment', 'calc_split_bill_group', 'calc_fuel_trip_cost', 'convert_currency_estimate'], true)
            || in_array($topicCode, ['daily_budgeting_rule', 'daily_emergency_fund', 'daily_electricity_saving'], true)
        ) {
            if ($topicCode === 'daily_budgeting_rule') {
                return [
                    'type' => 'finance_chart',
                    'title' => $lang === 'en' ? '50/30/20 BUDGET PLAN' : 'ALOKASI ANGGARAN 50/30/20',
                    'badge' => 'FINANCE CHART',
                    'centerLabel' => '50/30/20',
                    'donut' => [
                        ['label' => $lang === 'en' ? 'Needs' : 'Kebutuhan', 'value' => 50, 'unit' => '%', 'color' => '#38bdf8', 'hint' => $lang === 'en' ? 'Housing, food, bills' : 'Pokok, makan, tagihan'],
                        ['label' => $lang === 'en' ? 'Wants' : 'Keinginan', 'value' => 30, 'unit' => '%', 'color' => '#fbbf24', 'hint' => $lang === 'en' ? 'Lifestyle & leisure' : 'Hiburan & gaya hidup'],
                        ['label' => $lang === 'en' ? 'Savings' : 'Tabungan', 'value' => 20, 'unit' => '%', 'color' => '#34d399', 'hint' => $lang === 'en' ? 'Emergency & goals' : 'Dana darurat & target']
                    ],
                    'accent' => '#34d399',
                    'secondaryAccent' => '#fbbf24'
                ];
            }
            $primary = preg_match('/((?:Rp\s*|USD\s*|\$)\s*[\d\.,]+(?:\s*(?:juta|ribu|k|M|B))?)/iu', $reply, $fm)
                ? trim($fm[1])
                : (preg_match('/(\d+(?:[\.,]\d+)?\s*(?:°C|%|kali|bulan))/iu', $reply, $pm) ? trim($pm[1]) : 'FINANCE');
            return [
                'type' => 'finance_chart',
                'title' => $lang === 'en' ? 'FINANCIAL INSIGHT' : 'RINGKASAN FINANSIAL',
                'badge' => $tc !== '' ? 'CALCULATED' : 'SMART TIPS',
                'primaryValue' => $primary,
                'subtitle' => $reply,
                'chips' => $topicCode === 'daily_electricity_saving'
                    ? ['AC 24–25°C', 'Hemat Daya']
                    : ($topicCode === 'daily_emergency_fund' ? ['3–6x Bulanan', 'Rekening Terpisah'] : ['Terhitung Akurat']),
                'accent' => '#fbbf24',
                'secondaryAccent' => '#38bdf8'
            ];
        }

        
        if (
            in_array($tc, ['calc_bmi_health', 'calc_calorie_water_intake'], true)
            || in_array($topicCode, ['daily_sleep_ideal', 'daily_hydration_water', 'daily_exercise_routine', 'daily_caffeine_coffee', 'daily_boiling_freezing'], true)
        ) {
            $healthPresets = [
                'daily_sleep_ideal' => ['val' => '7–9 Jam', 'unit' => 'Per Malam', 'ratio' => 0.80, 'mTitle' => 'Durasi Tidur Ideal', 'chips' => ['Dewasa 7–9 Jam', 'Stop Layar 30m']],
                'daily_hydration_water' => ['val' => '2 Liter', 'unit' => '8 Gelas/Hari', 'ratio' => 0.85, 'mTitle' => 'Hidrasi Harian', 'chips' => ['8 Gelas Air', 'Jaga Fokus']],
                'daily_exercise_routine' => ['val' => '30 Menit', 'unit' => 'Setiap Hari', 'ratio' => 0.76, 'mTitle' => 'Aktivitas Fisik', 'chips' => ['Jalan Cepat 30m', 'Jantung Sehat']],
                'daily_caffeine_coffee' => ['val' => '2–3 Cup', 'unit' => 'Batas Aman', 'ratio' => 0.65, 'mTitle' => 'Batas Aman Kafein', 'chips' => ['Max 2–3 Cangkir', 'Hindari Malam']],
                'daily_boiling_freezing' => ['val' => '36.8°C', 'unit' => 'Suhu Tubuh', 'ratio' => 0.74, 'mTitle' => 'Titik Air & Tubuh', 'chips' => ['Beku 0°C', 'Didih 100°C']]
            ];
            $p = $healthPresets[$topicCode] ?? null;
            $val = $p['val'] ?? (preg_match('/(\d+(?:[\.,]\d+)?\s*(?:liter|ml|kkal|kg\/m2)?)/ui', $reply, $hm) ? trim($hm[1]) : 'HEALTH');
            if ($tc === 'calc_calorie_water_intake' && preg_match('/(\d+(?:[\.,]\d+)?)\s*liter/i', $reply, $wm)) {
                $val = $wm[1] . ' L';
            } elseif ($tc === 'calc_bmi_health' && preg_match('/BMI(?:\s*Anda)?\s*(?:adalah\s*)?(\d+(?:[\.,]\d+)?)/i', $reply, $bm)) {
                $val = 'BMI ' . $bm[1];
            }
            return [
                'type' => 'health_gauge',
                'title' => $lang === 'en' ? 'HEALTH METRIC' : 'INDIKATOR KESEHATAN',
                'badge' => 'WELLNESS',
                'primaryValue' => $val,
                'unitLabel' => $p['unit'] ?? ($tc === 'calc_calorie_water_intake' ? 'Air / Hari' : 'Skor'),
                'metricTitle' => $p['mTitle'] ?? ($tc === 'calc_bmi_health' ? 'Indeks Massa Tubuh' : 'Target Harian'),
                'ratio' => $p['ratio'] ?? 0.74,
                'subtitle' => $reply,
                'chips' => $p['chips'] ?? ($tc === 'calc_calorie_water_intake' ? ['Hidrasi Harian', 'Energi Basal'] : ['Data Medis']),
                'accent' => '#34d399',
                'secondaryAccent' => '#fbbf24'
            ];
        }

        
        if ($topicCode === 'daily_emergency_numbers_id' || $tc === 'check_campus_emergency_contacts') {
            return [
                'type' => 'emergency_cards',
                'title' => $lang === 'en' ? 'EMERGENCY HOTLINES' : 'NOMOR DARURAT',
                'badge' => 'URGENT',
                'accent' => '#f87171',
                'secondaryAccent' => '#fbbf24',
                'items' => $topicCode === 'daily_emergency_numbers_id'
                    ? [
                        ['num' => '112', 'label' => $lang === 'en' ? 'Unified Emergency' : 'Darurat Terpadu'],
                        ['num' => '110', 'label' => $lang === 'en' ? 'Police' : 'Kepolisian'],
                        ['num' => '118/119', 'label' => $lang === 'en' ? 'Ambulance / Medical' : 'Ambulans / Medis'],
                        ['num' => '113', 'label' => $lang === 'en' ? 'Fire Rescue' : 'Pemadam Kebakaran']
                    ]
                    : [
                        ['num' => 'BAAK', 'label' => 'KRS & Transkrip'],
                        ['num' => 'KLINIK', 'label' => 'Kesehatan Kampus'],
                        ['num' => 'SECURITY', 'label' => 'Keamanan 24 Jam'],
                        ['num' => '112', 'label' => 'Darurat Nasional']
                    ]
            ];
        }

        
        if (
            in_array($tc, ['calc_gpa_grade_converter', 'calc_target_ipk_planner', 'calc_attendance_rule', 'calc_pomodoro_study_plan', 'run_academic_workflow', 'check_nim_year_info'], true)
            || in_array($topicCode, ['grading_system', 'attendance_rule', 'dean_list_honor', 'thesis_skripsi', 'graduation_syarat'], true)
        ) {
            $primary = preg_match('/(\d+(?:[\.,]\d+)?\s*(?:SKS|%|jam|menit|IPK|IPS)?)/ui', $reply, $am) ? trim($am[1]) : 'UNKLAB';
            $bars = null;
            if ($topicCode === 'attendance_rule' || $tc === 'calc_attendance_rule') {
                $bars = [
                    ['label' => 'Minimal Kehadiran', 'pct' => 0.80, 'valueText' => '80%', 'color' => '#38bdf8'],
                    ['label' => 'Batas Maksimal Absen', 'pct' => 0.20, 'valueText' => '20%', 'color' => '#fbbf24']
                ];
                $primary = 'Min. 80%';
            } elseif ($topicCode === 'dean_list_honor') {
                $bars = [
                    ['label' => "Dean's List (IPS)", 'pct' => 0.875, 'valueText' => '>= 3.50', 'color' => '#38bdf8'],
                    ['label' => 'Cum Laude (IPK Akhir)', 'pct' => 0.88, 'valueText' => '>= 3.51', 'color' => '#fbbf24']
                ];
                $primary = 'IPK >= 3.50';
            }
            return [
                'type' => 'academic_metric',
                'title' => $lang === 'en' ? 'ACADEMIC DATA' : 'DATA AKADEMIK UNKLAB',
                'badge' => 'ACADEMIC',
                'primaryValue' => $primary,
                'subtitle' => $reply,
                'bars' => $bars,
                'chips' => ['Standar Akademik', 'UNKLAB'],
                'accent' => '#38bdf8',
                'secondaryAccent' => '#fbbf24'
            ];
        }

        
        if (in_array($tc, ['calculate_math_expression', 'calc_average_median', 'calc_factorial_permutation', 'calc_square_cube_root', 'calc_prime_checker', 'calc_gcd_lcm', 'calc_circle_geometry', 'calc_triangle_rectangle', 'calc_age_exact', 'calc_countdown_days', 'convert_temperature', 'convert_unit_measure', 'convert_number_base', 'text_word_char_stats'], true)) {
            $exprTxt = trim(mb_substr($message, 0, 40, 'UTF-8'));
            $resVal = preg_match('/(?:adalah|is|=)\s*([^\.,;\n]+(?:[\.,]\d+)?)/iu', $reply, $mm)
                ? trim($mm[1])
                : (preg_match('/(-?\d+(?:[\.,]\d+)?(?:\s*(?:°[CFK]|hari|tahun|kata|km|m|kg|g|ml|L))?)/ui', $reply, $nm) ? trim($nm[1]) : 'DONE');
            return [
                'type' => 'math_convert',
                'title' => $lang === 'en' ? 'CALCULATION RESULT' : 'HASIL PERHITUNGAN',
                'badge' => 'PHP COMPUTE',
                'expressionText' => $exprTxt,
                'primaryValue' => $resVal,
                'subtitle' => $reply,
                'chips' => [$tc, $lang === 'en' ? 'Verified' : 'Akurat'],
                'accent' => '#38bdf8',
                'secondaryAccent' => '#fbbf24'
            ];
        }

        
        $hasQuantData = (bool)preg_match('/\b\d+(?:[\.,]\d+)?\s*(?:%|°C|°F|SKS|IPK|IPS|tahun|bulan|hari|jam|menit|detik|liter|ml|karakter|halaman|salinan|UTC\+\d|km|cangkir|gelas|ribu|juta|Rp)\b/iu', $reply);
        if ($hasQuantData || in_array($topicCode, ['daily_food_expiry_label', 'daily_sky_blue_why', 'daily_rainbow_why'], true)) {
            preg_match_all('/\b\d+(?:[–\-]\d+)?(?:[\.,]\d+)?\s*(?:%|°C|SKS|jam|menit|detik|liter|karakter|hari|tahun|UTC\+\d|cangkir|gelas|halaman|km)?\b/iu', $reply, $mAll);
            $chips = [];
            foreach ($mAll[0] as $tk) {
                $tk = trim($tk);
                if (strlen($tk) >= 2 && !in_array($tk, $chips, true)) {
                    $chips[] = $tk;
                }
                if (count($chips) >= 3) break;
            }
            if ($topicCode === 'daily_food_expiry_label') {
                $chips = ['Use By: Keamanan', 'Best Before: Mutu Rasa'];
            }
            return [
                'type' => 'data_highlight',
                'title' => strtoupper(str_replace('_', ' ', $topicCode ?: ($source ?: 'DATA INSIGHT'))),
                'badge' => strtoupper($source ?: 'INFO'),
                'primaryValue' => $chips[0] ?? '',
                'subtitle' => $reply,
                'chips' => $chips,
                'accent' => '#38bdf8',
                'secondaryAccent' => '#fbbf24'
            ];
        }

        
        $cleanTopicTitle = $topicCode !== ''
            ? strtoupper(str_replace('_', ' ', $topicCode))
            : ($lang === 'en' ? 'UNKLABOT RESPONSE' : 'RESPON UNKLABOT');
        if ($emotion === 'friendly' || strpos($topicCode, 'greeting_') === 0 || $topicCode === 'how_are_you') {
            $cleanTopicTitle = $lang === 'en' ? 'WARM GREETING' : 'SAPAAN RAMAH';
        } elseif ($emotion === 'grateful' || $topicCode === 'gratitude') {
            $cleanTopicTitle = $lang === 'en' ? 'APPRECIATION' : 'TERIMA KASIH';
        } elseif (in_array($emotion, ['sad', 'stressed', 'confused'], true)) {
            $cleanTopicTitle = $lang === 'en' ? 'ASSISTANT SUPPORT' : 'DUKUNGAN ASISTEN';
        } elseif (in_array($source, ['Static KB', 'RAG Knowledge', 'Self-Learned AI'], true)) {
            $cleanTopicTitle = $lang === 'en' ? 'KNOWLEDGE BASE' : 'BASIS PENGETAHUAN';
        } elseif (stripos($source, 'LLM') !== false) {
            $cleanTopicTitle = $lang === 'en' ? 'AI INTELLIGENCE' : 'KECERDASAN AI';
        }

        $accentMap = [
            'friendly' => '#34d399',
            'grateful' => '#34d399',
            'sad' => '#60a5fa',
            'stressed' => '#60a5fa',
            'curious' => '#a78bfa'
        ];
        $accentHex = $accentMap[$emotion] ?? '#38bdf8';

        return [
            'type' => 'ai_response',
            'title' => $cleanTopicTitle,
            'badge' => strtoupper($source ?: 'UNKLABOT'),
            'emotion' => $emotion ?: 'neutral',
            'subtitle' => $reply,
            'accent' => $accentHex,
            'secondaryAccent' => '#fbbf24'
        ];
    }

    public function processAssistantMessage(array $payload): array {
        $t0 = microtime(true);
        $uiLang = strtolower(trim((string)($payload['language'] ?? 'id')));
        if (!in_array($uiLang, ['id', 'en'], true)) {
            $uiLang = 'id';
        }
        $message = trim((string)($payload['message'] ?? ''));
        $sessionCode = trim((string)($payload['session_code'] ?? (session_id() ?: 'SES-DEFAULT')));
        $userIdent = trim((string)($_SESSION['user-name'] ?? ($payload['user_identifier'] ?? 'Guest')));

        if ($message === '') {
            return [
                'success' => true,
                'language' => $uiLang,
                'detected_language' => $uiLang,
                'expression' => 'SMILING',
                'gesture' => 'WAVE',
                'emotion' => 'friendly',
                'source' => 'Static KB',
                'ai_eval_score' => 0.96,
                'reply' => $uiLang === 'en'
                    ? "I am listening. How may I assist you today?"
                    : "Saya mendengarkan. Ada yang bisa saya bantu hari ini?"
            ];
        }

        $detectedLang = $this->detectLanguage($message, $uiLang);
        $lang = $detectedLang;
        $reqTimezone = trim((string)($payload['timezone'] ?? 'Asia/Makassar'));
        if ($reqTimezone === '') {
            $reqTimezone = 'Asia/Makassar';
        }
        $lower = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        $telemetry = $this->extractClientTelemetry($payload);
        $profanity = $this->detectProfanity($message);
        $emo = $this->detectEmotionAndGesture($message);
        $settings = $this->getAssistantSettings();
        $learner = $this->getSelfLearner();

        $reply = null;
        $expression = $emo['expression'];
        $gesture = $emo['gesture'];
        $source = 'Static KB';
        $ragRef = null;
        $toolCalled = null;
        $learnedItemId = null;
        $learnedScore = 0.0;
        $targetType = 'source';
        $targetKey = 'Static KB';

        if ($profanity['is_profane'] && ($settings['profanity_filter_enabled'] ?? '1') === '1') {
            $expression = 'SAD';
            $gesture = 'NOD';
            $source = 'Profanity Guard';
            $targetKey = 'Profanity Guard';
            $reply = $lang === 'en'
                ? "Please use polite and respectful academic language. Our conversation is logged for quality analysis, and I am happy to help constructively."
                : "Mohon gunakan bahasa yang sopan sesuai etika akademik kampus. Percakapan ini tercatat dalam sistem evaluasi, dan saya siap membantu Anda secara positif.";
        }

        $newlyExtractedMemories = [];
        if (!$profanity['is_profane'] && ($settings['memory_enabled'] ?? '1') === '1') {
            $newlyExtractedMemories = $this->extractAndStoreMemories($sessionCode, $userIdent, $message);
        }

        $hasQuestionCue = (strpos($message, '?') !== false)
            || (bool)preg_match('/\b(apa|apakah|bagaimana|gimana|kenapa|mengapa|siapa|kapan|dimana|di\s+mana|berapa|syarat|cara|aturan|ketentuan|prosedur|alur|jelaskan|sebutkan|jelasin|ceritakan|beritahu|kasih\s+tahu|tanya|bertanya|ingin\s+tahu|mau\s+tahu|minta|cari|info|informasi|daftar|jadwal|tips|perbedaan|arti|definisi|contoh|tentang|mengenai|mau\s+dengar|bikin|buatkan|tolong|bisa|what|how|why|who|when|where|which|explain|tell|describe|list|requirements|rules|schedule|tips|define|meaning|about|can\s+you|could\s+you)\b/u', $lower);

        if ($reply === null && preg_match('/\b(siapa nama saya|nama saya siapa|apa proyek saya|apa yang kamu ingat tentang saya|what is my name|what is my project|do you remember me)\b/u', $lower)) {
            $mems = $this->getSessionMemories($sessionCode, 6);
            if (!empty($mems)) {
                $facts = [];
                foreach ($mems as $mItem) {
                    $facts[] = $mItem['memory_value'];
                }
                $summaryStr = implode('; ', array_slice($facts, 0, 5));
                $source = 'Long-Term Memory';
                $targetKey = 'Long-Term Memory';
                $expression = 'HAPPY';
                $gesture = 'NOD';
                $reply = $lang === 'en'
                    ? "From my long-term memory, I remember: {$summaryStr}."
                    : "Dari memori jangka panjang saya, saya mengingat informasi Anda: {$summaryStr}.";
            }
        }

        if ($reply === null && !empty($newlyExtractedMemories) && !$hasQuestionCue) {
            $explicitIntroParts = [];
            if (isset($newlyExtractedMemories['preferred_name'])) {
                $explicitIntroParts[] = $lang === 'en' ? "nice to meet you, {$newlyExtractedMemories['preferred_name']}" : "senang mengenal Anda, {$newlyExtractedMemories['preferred_name']}";
            }
            if (isset($newlyExtractedMemories['user_major'])) {
                $explicitIntroParts[] = $lang === 'en' ? "from {$newlyExtractedMemories['user_major']}" : "dari {$newlyExtractedMemories['user_major']}";
            }
            if (isset($newlyExtractedMemories['current_project'])) {
                $explicitIntroParts[] = $lang === 'en' ? "working on {$newlyExtractedMemories['current_project']}" : "yang sedang mengerjakan {$newlyExtractedMemories['current_project']}";
            }
            if (isset($newlyExtractedMemories['user_note'])) {
                $explicitIntroParts[] = $lang === 'en' ? "your note '{$newlyExtractedMemories['user_note']}'" : "catatan '{$newlyExtractedMemories['user_note']}'";
            }
            if (!empty($explicitIntroParts) && strlen($message) < 95) {
                $source = 'Long-Term Memory';
                $targetKey = 'Long-Term Memory';
                $expression = 'HAPPY';
                $gesture = 'NOD';
                $joined = implode(', ', $explicitIntroParts);
                $reply = $lang === 'en'
                    ? "Got it! I have saved that in my long-term memory ({$joined}). How can I help you next?"
                    : "Baik! Informasi tersebut telah saya simpan di memori jangka panjang saya ({$joined}). Ada yang ingin kita diskusikan selanjutnya?";
            }
        }

        if ($reply === null && preg_match('/\b(kesimpulan|rangkum|ringkas percakapan|apa yang kita bahas|summarize our|conversation summary|what did we discuss)\b/u', $lower)) {
            $summaryText = $this->buildSessionSummary($sessionCode, $lang);
            $source = 'Conversation Summary';
            $targetKey = 'Conversation Summary';
            $expression = 'THINKING';
            $gesture = 'EXPLAIN';
            $reply = $summaryText;
        }

        
        if ($reply === null && ($settings['function_calling_enabled'] ?? '1') === '1') {
            $toolOut = $this->tryExecuteAgentTool($message, $lower, $lang, $sessionCode, trim((string)($payload['timezone'] ?? 'Asia/Makassar')));
            if ($toolOut) {
                $reply = $toolOut['reply'];
                $expression = $toolOut['expression'];
                $gesture = $toolOut['gesture'];
                $source = $toolOut['source'];
                $toolCalled = $toolOut['tool'];
                $targetType = 'tool';
                $targetKey = (string)$toolCalled;
            }
        }

        
        $kbRes = ($reply === null && ($settings['static_kb_enabled'] ?? '1') === '1')
            ? $this->matchStaticKnowledgeBase($message, $lower, $lang, $learner)
            : ['kb' => null, 'len' => 0, 'score' => 0.0, 'matched' => false, 'is_pure_conversational' => false, 'is_personal_statement' => false, 'has_negation' => false];
        $bestKb = $kbRes['kb'];
        $bestLen = $kbRes['len'];
        $kbMatched = $kbRes['matched'];
        $kbCat = $kbMatched ? (string)($bestKb['category'] ?? '') : '';
        $isPureConversationalKb = $kbRes['is_pure_conversational'];
        $isPersonalStatement = $kbRes['is_personal_statement'];
        $skipLearnedAutoMatch = $isPersonalStatement || $kbRes['has_negation'];
        $hasCampusCue = (bool)preg_match('/\b(unklab|klabat|kampus|filkom|feb|fik|fakultas|prodi|jurusan|akademik|sks|krs|ips|ipk|skripsi|yudisium|wisuda|asrama|dorm|spp|ukt|beasiswa|kip|rektor|dekan|presensi|kuliah|semester|cuti|chapel|klinik|kafetaria|perpus|perpustakaan|kurikulum|matkul|nilai|kelulusan)\b/u', $lower);
        $skipRagForKbDomain = $isPureConversationalKb || ($kbMatched && in_array($kbCat, ['Identitas', 'Kehidupan Sehari-hari'], true) && !$hasCampusCue);

        $ragResult = null;
        if ($reply === null && !$skipRagForKbDomain && !$isPersonalStatement && ($settings['rag_enabled'] ?? '1') === '1') {
            $ragResult = $this->searchRagKnowledgeMulti($message, $lang, 0.30, 3);
        }

        $learnedMatch = null;
        if ($reply === null && !$isPureConversationalKb && !$skipLearnedAutoMatch && ($settings['auto_self_learning_enabled'] ?? '1') === '1') {
            $learnedMatch = $learner->findLearnedMatch($message, $lang, 0.54);
            if ($learnedMatch) {
                $learnedScore = (float)$learnedMatch['score'];
            }
        }

        if ($reply === null && preg_match('/\b(lebih lanjut|jelaskan lagi|contohnya|maksudnya tadi|yang tadi|tell me more|give an example|elaborate on that)\b/u', $lower)) {
            $this->db->prepare("SELECT user_message, assistant_response, rag_reference FROM tbl_va_conversations WHERE session_code = :sc ORDER BY id DESC LIMIT 1");
            $this->db->bind(':sc', $sessionCode);
            $lastTurn = $this->db->single();
            if ($lastTurn) {
                $prevTopic = substr((string)$lastTurn['user_message'], 0, 80);
                $prevAns = substr((string)$lastTurn['assistant_response'], 0, 180);
                $followHint = "Previous question: {$prevTopic} | Previous reply: {$prevAns}";
                $llmFollow = $this->callActiveLlm($message, $lang, $sessionCode, $ragResult, $followHint);
                if ($llmFollow) {
                    $reply = $llmFollow['reply'];
                    $source = ($llmFollow['source'] ?? 'LLM') . ($ragResult ? ' + RAG' : '');
                    $targetKey = $source;
                    $expression = 'HAPPY';
                    $gesture = 'EXPLAIN';
                    if ($ragResult) {
                        $ragRef = $ragResult['reference'];
                    }
                } else {
                    $source = 'Context Awareness';
                    $targetKey = 'Context Awareness';
                    $expression = 'HAPPY';
                    $gesture = 'EXPLAIN';
                    $reply = $lang === 'en'
                        ? "Following up on \"{$prevTopic}\": please specify which detail or example you would like me to explain next."
                        : "Melanjutkan topik \"{$prevTopic}\": bagian atau contoh spesifik apa yang ingin saya jelaskan lebih ringkas?";
                }
            }
        }

        $kbHint = $kbMatched ? ($lang === 'en' ? (string)$bestKb['response_en'] : (string)$bestKb['response_id']) : null;
        if ($learnedMatch && (!$kbHint || $learnedMatch['score'] >= 0.45)) {
            $kbHint = $learnedMatch['reply'] . ($kbHint ? (' | ' . $kbHint) : '');
        }
        $useRagLlm = ($ragResult !== null) && (!$kbMatched || (!$skipRagForKbDomain && ($ragResult['score'] >= 0.36 || ($hasCampusCue && $ragResult['score'] >= 0.30))));

        if ($reply === null && $learnedMatch && $learnedMatch['score'] >= 0.44 && (!$ragResult || $learnedMatch['weighted_score'] >= (float)$ragResult['score']) && !$kbMatched) {
            $reply = $learnedMatch['reply'];
            $source = 'Self-Learned AI';
            $learnedItemId = (int)$learnedMatch['id'];
            $targetType = 'learned';
            $targetKey = (string)$learnedMatch['pattern_code'];
            $expression = 'HAPPY';
            $gesture = 'EXPLAIN';
            if (!empty($learnedMatch['source_ref'])) {
                $ragRef = $learnedMatch['source_ref'];
            }
        }

        if ($reply === null && $useRagLlm) {
            $ragRef = $ragResult['reference'];
            $llmRagOut = $this->callActiveLlm($message, $lang, $sessionCode, $ragResult, $kbHint);
            if ($llmRagOut) {
                $reply = $llmRagOut['reply'];
                $source = ($llmRagOut['source'] ?? 'Gemini LLM') . ' + RAG';
                $targetKey = $source;
                $expression = $emo['expression'] ?: 'SMILING';
                $gesture = 'EXPLAIN';
            } elseif ((float)($ragResult['score'] ?? 0) >= 0.32 && !$kbMatched) {
                $reply = $ragResult['reply'];
                $source = 'RAG Document';
                $targetType = 'rag_doc';
                $targetKey = explode(' (', (string)$ragRef)[0];
                $expression = 'HAPPY';
                $gesture = 'EXPLAIN';
            } else {
                $ragRef = null;
            }
        }

        if ($reply === null && $kbMatched) {
            $this->db->prepare("UPDATE tbl_static_knowledge SET hit_count = hit_count + 1 WHERE id = :id");
            $this->db->bind(':id', (int)$bestKb['id']);
            $this->db->execute();
            $targetType = 'kb';
            $targetKey = (string)($bestKb['topic_code'] ?? $bestKb['id']);
            $needsNuanceLlm = !$isPureConversationalKb
                && str_word_count($message) >= 7
                && ($bestLen < 10 || preg_match('/\b(saya|kalau|jika|apakah|tapi|tetapi|namun|kenapa|mengapa|bandingkan)\b/u', $lower))
                && ($settings['gemini_enabled'] ?? '1') === '1';
            if ($needsNuanceLlm) {
                $llmKbOut = $this->callActiveLlm($message, $lang, $sessionCode, null, $kbHint);
                if ($llmKbOut) {
                    $reply = $llmKbOut['reply'];
                    $source = $llmKbOut['source'] ?? 'Gemini LLM';
                    $targetKey = $source;
                    $expression = (string)($bestKb['expression'] ?: 'HAPPY');
                    $gesture = 'EXPLAIN';
                }
            }
            if ($reply === null) {
                $reply = ($lang === 'en' ? (string)$bestKb['response_en'] : (string)$bestKb['response_id']);
                $expression = (string)($bestKb['expression'] ?: 'HAPPY');
                $gesture = (string)($bestKb['gesture'] ?: 'NOD');
                $source = 'Static KB';
            }
        }

        if ($reply === null && $learnedMatch && $learnedMatch['score'] >= 0.54) {
            $reply = $learnedMatch['reply'];
            $source = 'Self-Learned AI';
            $learnedItemId = (int)$learnedMatch['id'];
            $targetType = 'learned';
            $targetKey = (string)$learnedMatch['pattern_code'];
            $expression = 'HAPPY';
            $gesture = 'EXPLAIN';
            if (!empty($learnedMatch['source_ref'])) {
                $ragRef = $learnedMatch['source_ref'];
            }
        }

        if ($reply === null) {
            $llmOut = $this->callActiveLlm($message, $lang, $sessionCode, $ragResult, $kbHint);
            if ($llmOut) {
                $reply = $llmOut['reply'];
                $source = ($llmOut['source'] ?? 'Gemini LLM') . ($ragResult ? ' + RAG' : '');
                $targetKey = $source;
                $expression = $emo['expression'] ?: 'SMILING';
                $gesture = 'EXPLAIN';
                if ($ragResult && strpos($reply, 'Sumber:') === false && strpos($reply, 'Source:') === false) {
                    $ragRef = $ragResult['reference'];
                }
            }
        }

        if ($reply === null && !empty($newlyExtractedMemories)) {
            $memSummary = implode(', ', array_values($newlyExtractedMemories));
            $source = 'Long-Term Memory';
            $targetKey = 'Long-Term Memory';
            $expression = 'HAPPY';
            $gesture = 'NOD';
            $reply = $lang === 'en'
                ? "Got it, I have updated your context ({$memSummary}). How can I assist you next?"
                : "Baik, saya catat pembaruan informasi Anda ({$memSummary}). Ada topik kuliah atau hal lain yang ingin dibahas?";
        }

        if ($reply === null) {
            $profile = $this->getUserProfile($sessionCode);
            $nameGreeting = !empty($profile['preferred_name']) ? (", " . $profile['preferred_name']) : "";
            $expression = 'CONFUSED';
            $gesture = $emo['gesture'] ?: 'EXPLAIN';
            $source = 'Smart Contextual AI';
            $targetKey = 'Smart Contextual AI';
            $reply = $lang === 'en'
                ? "I have not found specific details on that yet{$nameGreeting}. Could you clarify with UNKLAB academic, scholarship, or AI keywords?"
                : "Maaf, saya belum menemukan informasi spesifik untuk pertanyaan itu{$nameGreeting}. Bisa sebutkan kata kunci yang lebih jelas seputar UNKLAB atau AI?";
        }

        $latencyMs = max(2, (int)round((microtime(true) - $t0) * 1000));
        $evalOut = $this->evaluateAndRefineResponse($reply, $message, $lang, $source, [
            'rag_score' => $ragResult['score'] ?? 0.0,
            'learned_score' => $learnedScore,
            'kb_match_len' => $bestLen,
            'latency_ms' => $latencyMs
        ]);
        $finalReply = $evalOut['reply'];
        $evalScore = $evalOut['score'];
        $followUp = $evalOut['follow_up'];
        $intentTag = (string)$evalOut['intent_tag'];
        $isUnanswered = (int)$evalOut['is_unanswered'];
        $failureReason = $evalOut['failure_reason'];
        $flowStatus = (string)$evalOut['flow_status'];

        $visualCard = $this->buildVisualCardData(
            $message,
            $lower,
            $finalReply,
            $lang,
            $source,
            $toolCalled,
            $bestKb,
            $reqTimezone,
            (string)$emo['emotion'],
            (float)$evalScore
        );
        if (!in_array($gesture, ['WAVE', 'BOW'], true)) {
            $gesture = 'PRESENT';
        }
        if ($expression === 'SMILING' && preg_match('/^\s*(maaf|turut\s+prihatin|jangan\s+khawatir|sorry|i\'m\s+sorry)/iu', $finalReply)) {
            $expression = 'EMPATHY';
        }

        $conversationId = 0;
        if ($this->db->isConnected() && $this->db->hasTable('tbl_va_conversations')) {
            $this->db->prepare("INSERT INTO tbl_va_conversations
                (session_code, user_identifier, language, detected_language, user_message, assistant_response, expression, gesture, emotion_detected, avatar_state, response_source, rag_reference, tool_called, ai_eval_score, follow_up_question, is_unanswered, failure_reason, intent_tag, flow_status, learned_item_id, is_profane, profanity_words, profanity_severity, ip_address, location_label, device_type, browser_os, user_agent, latency_ms)
                VALUES (:sc, :ui, :lg, :dl, :um, :ar, :ex, :gs, :em, 'SPEAKING', :rs, :rr, :tc, :es, :fu, :iu, :fr, :it, :fs, :li, :ipr, :pw, :ps, :ip, :loc, :dev, :bo, :ua, :lat)");
            $this->db->bind(':sc', $sessionCode);
            $this->db->bind(':ui', $userIdent);
            $this->db->bind(':lg', $lang);
            $this->db->bind(':dl', $detectedLang);
            $this->db->bind(':um', $message);
            $this->db->bind(':ar', $finalReply);
            $this->db->bind(':ex', $expression);
            $this->db->bind(':gs', $gesture);
            $this->db->bind(':em', $emo['emotion']);
            $this->db->bind(':rs', $source);
            $this->db->bind(':rr', $ragRef);
            $this->db->bind(':tc', $toolCalled);
            $this->db->bind(':es', $evalScore);
            $this->db->bind(':fu', $followUp);
            $this->db->bind(':iu', $isUnanswered);
            $this->db->bind(':fr', $failureReason);
            $this->db->bind(':it', $intentTag);
            $this->db->bind(':fs', $flowStatus);
            $this->db->bind(':li', $learnedItemId);
            $this->db->bind(':ipr', $profanity['is_profane'] ? 1 : 0);
            $this->db->bind(':pw', $profanity['words_str']);
            $this->db->bind(':ps', $profanity['severity']);
            $this->db->bind(':ip', $telemetry['ip_address']);
            $this->db->bind(':loc', $telemetry['location_label']);
            $this->db->bind(':dev', $telemetry['device_type']);
            $this->db->bind(':bo', $telemetry['browser_os']);
            $this->db->bind(':ua', $telemetry['user_agent']);
            $this->db->bind(':lat', $latencyMs);
            $this->db->execute();
            $conversationId = (int)$this->db->lastInsertId();

            $learner->onTurnCompleted([
                'conversation_id' => $conversationId,
                'session_code' => $sessionCode,
                'user_message' => $message,
                'reply' => $finalReply,
                'language' => $lang,
                'response_source' => $source,
                'rag_reference' => $ragRef,
                'intent_tag' => $intentTag,
                'eval_score' => $evalScore,
                'is_unanswered' => $isUnanswered,
                'failure_reason' => $failureReason,
                'target_type' => $targetType,
                'target_key' => $targetKey,
                'learned_item_id' => $learnedItemId
            ]);
        }

        return [
            'success' => true,
            'conversation_id' => $conversationId,
            'session_code' => $sessionCode,
            'language' => $lang,
            'detected_language' => $detectedLang,
            'expression' => $expression,
            'gesture' => $gesture,
            'emotion' => $emo['emotion'],
            'source' => $source,
            'rag_reference' => $ragRef,
            'tool_called' => $toolCalled,
            'ai_eval_score' => $evalScore,
            'intent_tag' => $intentTag,
            'is_unanswered' => $isUnanswered === 1,
            'learned_id' => $learnedItemId,
            'follow_up' => $followUp,
            'is_profane' => $profanity['is_profane'],
            'visual_card' => $visualCard,
            'latency_ms' => $latencyMs,
            'reply' => $finalReply
        ];
    }

    public function buildSessionSummary(string $sessionCode, string $lang = 'id'): string {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_va_conversations')) {
            return $lang === 'en' ? "No conversation history recorded yet." : "Belum ada riwayat percakapan yang tercatat.";
        }
        $this->db->prepare("SELECT user_message, assistant_response, response_source FROM tbl_va_conversations WHERE session_code = :sc ORDER BY id DESC LIMIT 6");
        $this->db->bind(':sc', $sessionCode);
        $rows = array_reverse($this->db->resultSet());
        if (empty($rows)) {
            $this->db->prepare("SELECT user_message, assistant_response, response_source FROM tbl_va_conversations ORDER BY id DESC LIMIT 4");
            $rows = array_reverse($this->db->resultSet());
        }
        $topics = [];
        foreach ($rows as $r) {
            $topics[] = '"' . substr(trim((string)$r['user_message']), 0, 48) . '" (' . $r['response_source'] . ')';
        }
        $cnt = count($rows);
        $joined = implode(', ', $topics);
        $summary = $lang === 'en'
            ? "Conversation Summary ({$cnt} recent turns): We discussed {$joined}."
            : "Ringkasan Percakapan ({$cnt} interaksi terakhir): Kita telah membahas {$joined}.";

        $this->saveMemoryItem($sessionCode, 'System', 'conversation_summary', 'latest_summary', $summary, 0.90);
        return $summary;
    }

    public function getDashboardAnalytics(): array {
        $stats = [
            'total_conversations' => $this->db->getTableRowCount('tbl_va_conversations'),
            'total_static_kb' => $this->db->getTableRowCount('tbl_static_knowledge'),
            'total_rag_docs' => $this->db->getTableRowCount('tbl_knowledge_documents'),
            'total_rag_chunks' => $this->db->getTableRowCount('tbl_knowledge_chunks'),
            'total_memories' => $this->db->getTableRowCount('tbl_long_term_memories'),
            'total_users' => $this->db->getTableRowCount('tbl_users'),
            'total_tools' => $this->db->getTableRowCount('tbl_agent_tools'),
            'total_incidents' => $this->db->getTableRowCount('tbl_security_incidents'),
            'profane_chats' => 0,
            'avg_eval_score' => 0.96,
            'avg_latency_ms' => 15,
            'gemini_keys_count' => $this->db->getTableRowCount('tbl_ai_provider_keys'),
            'by_source' => [],
            'by_emotion' => [],
            'by_device' => [],
            'by_language' => ['id' => 0, 'en' => 0],
            'recent_profane' => []
        ];

        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_va_conversations')) {
            return $stats;
        }

        $this->db->prepare("SELECT
            SUM(CASE WHEN is_profane = 1 THEN 1 ELSE 0 END) AS prof_cnt,
            ROUND(AVG(ai_eval_score), 2) AS avg_score,
            ROUND(AVG(latency_ms), 1) AS avg_lat,
            SUM(CASE WHEN detected_language = 'en' THEN 1 ELSE 0 END) AS en_cnt,
            SUM(CASE WHEN detected_language != 'en' THEN 1 ELSE 0 END) AS id_cnt
            FROM tbl_va_conversations");
        $agg = $this->db->single();
        if ($agg) {
            $stats['profane_chats'] = (int)($agg['prof_cnt'] ?? 0);
            $stats['avg_eval_score'] = (float)($agg['avg_score'] ?? 0.96);
            $stats['avg_latency_ms'] = (float)($agg['avg_lat'] ?? 15);
            $stats['by_language'] = [
                'id' => (int)($agg['id_cnt'] ?? 0),
                'en' => (int)($agg['en_cnt'] ?? 0)
            ];
        }

        $this->db->prepare("SELECT response_source, COUNT(*) AS total FROM tbl_va_conversations GROUP BY response_source ORDER BY total DESC");
        $stats['by_source'] = $this->db->resultSet();

        $this->db->prepare("SELECT emotion_detected, COUNT(*) AS total FROM tbl_va_conversations GROUP BY emotion_detected ORDER BY total DESC");
        $stats['by_emotion'] = $this->db->resultSet();

        $this->db->prepare("SELECT device_type, COUNT(*) AS total FROM tbl_va_conversations GROUP BY device_type ORDER BY total DESC");
        $stats['by_device'] = $this->db->resultSet();

        $this->db->prepare("SELECT id, user_identifier, user_message, profanity_words, profanity_severity, ip_address, location_label, device_type, created_at FROM tbl_va_conversations WHERE is_profane = 1 ORDER BY id DESC LIMIT 5");
        $stats['recent_profane'] = $this->db->resultSet();

        $learner = $this->getSelfLearner();
        $learner->runAutonomousMaintenanceIfDue(35, 3);
        $selfStats = $learner->getSelfLearningAnalytics();
        $stats['self_learning'] = $selfStats;
        $stats['satisfaction_rate'] = $selfStats['satisfaction_rate'];
        $stats['stall_rate'] = $selfStats['stall_rate'];
        $stats['open_gaps'] = $selfStats['open_gaps'];
        $stats['healed_gaps'] = $selfStats['healed_gaps'];
        $stats['learned_skills_count'] = $selfStats['learned_skills_count'];
        $stats['unanswered_count'] = $selfStats['unanswered_count'];
        if (!empty($selfStats['avg_eval_score'])) {
            $stats['avg_eval_score'] = $selfStats['avg_eval_score'];
        }

        return $stats;
    }

    public function getPaginatedTable(string $tableType, array $params = []): array {
        $page = max(1, (int)($params['page'] ?? 1));
        $perPage = max(5, min(100, (int)($params['per_page'] ?? 10)));
        $search = trim((string)($params['search'] ?? ''));
        $filter1 = trim((string)($params['filter1'] ?? ''));
        $filter2 = trim((string)($params['filter2'] ?? ''));
        $filter3 = trim((string)($params['filter3'] ?? ''));

        $conn = $this->db->getConnection();
        if (!$conn) {
            return ['items' => [], 'total' => 0, 'page' => $page, 'per_page' => $perPage, 'total_pages' => 1];
        }

        $esc = static fn($s) => $conn->real_escape_string((string)$s);
        $where = ["1=1"];
        $table = "";
        $select = "*";
        $orderBy = "id DESC";

        switch ($tableType) {
            case 'conversations':
                $table = "tbl_va_conversations";
                $convFilter = $this->buildConversationFilterWhere($conn, $params);
                $where[] = $convFilter['whereSql'];
                break;

            case 'static_kb':
                $table = "tbl_static_knowledge";
                $orderBy = "priority DESC, id DESC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(topic_code LIKE '%{$s}%' OR category LIKE '%{$s}%' OR keywords_id LIKE '%{$s}%' OR keywords_en LIKE '%{$s}%' OR response_id LIKE '%{$s}%' OR response_en LIKE '%{$s}%')";
                }
                if ($filter1 !== '') {
                    $where[] = "category = '" . $esc($filter1) . "'";
                }
                if ($filter2 !== '') {
                    $where[] = "expression = '" . $esc($filter2) . "'";
                }
                break;

            case 'rag_docs':
                $table = "tbl_knowledge_documents";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(doc_code LIKE '%{$s}%' OR title LIKE '%{$s}%' OR file_name LIKE '%{$s}%' OR content_text LIKE '%{$s}%')";
                }
                if ($filter1 !== '') {
                    $where[] = "file_type = '" . $esc($filter1) . "'";
                }
                if ($filter2 !== '') {
                    $where[] = "category = '" . $esc($filter2) . "'";
                }
                break;

            case 'memories':
                $table = "tbl_long_term_memories";
                $orderBy = "importance DESC, id DESC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(session_code LIKE '%{$s}%' OR user_identifier LIKE '%{$s}%' OR memory_key LIKE '%{$s}%' OR memory_value LIKE '%{$s}%')";
                }
                if ($filter1 !== '') {
                    $where[] = "memory_type = '" . $esc($filter1) . "'";
                }
                break;

            case 'gemini_keys':
                $table = "tbl_ai_provider_keys";
                $select = "id, provider, key_label, api_key_masked, model_name, is_active, is_primary, total_requests, total_tokens, last_status, last_used_at, created_by, created_at";
                $orderBy = "is_primary DESC, id DESC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(provider LIKE '%{$s}%' OR key_label LIKE '%{$s}%' OR model_name LIKE '%{$s}%' OR last_status LIKE '%{$s}%')";
                }
                if ($filter1 !== '' && in_array($filter1, ['gemini', 'openai'], true)) {
                    $where[] = "provider = '" . $esc($filter1) . "'";
                }
                break;

            case 'agent_tools':
                $table = "tbl_agent_tools";
                $orderBy = "call_count DESC, id ASC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(tool_code LIKE '%{$s}%' OR tool_name LIKE '%{$s}%' OR description LIKE '%{$s}%' OR trigger_keywords LIKE '%{$s}%')";
                }
                if ($filter1 !== '') {
                    $where[] = "tool_category = '" . $esc($filter1) . "'";
                }
                break;

            case 'users':
                $userSvc = new UserManagementService($this->db);
                return $userSvc->listUsers($params);

            case 'security':
                $table = "tbl_security_incidents";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(incident_type LIKE '%{$s}%' OR source_ip LIKE '%{$s}%' OR request_uri LIKE '%{$s}%' OR payload_snippet LIKE '%{$s}%')";
                }
                if ($filter1 !== '') {
                    $where[] = "incident_type = '" . $esc($filter1) . "'";
                }
                break;

            case 'tbl_audit_logs':
                $table = "tbl_audit_logs";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(action LIKE '%{$s}%' OR description LIKE '%{$s}%' OR user_name LIKE '%{$s}%' OR ip_address LIKE '%{$s}%')";
                }
                break;

            case 'knowledge_gaps':
                $table = "tbl_knowledge_gaps";
                $orderBy = "FIELD(status,'detected','needs_rich_source','auto_healed','reinforced'), occurrence_count DESC, id DESC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(sample_question LIKE '%{$s}%' OR failure_reason LIKE '%{$s}%' OR intent_tag LIKE '%{$s}%' OR healing_strategy LIKE '%{$s}%')";
                }
                if ($filter1 !== '') {
                    $where[] = "status = '" . $esc($filter1) . "'";
                }
                if ($filter2 !== '') {
                    $where[] = "intent_tag = '" . $esc($filter2) . "'";
                }
                break;

            case 'learned_knowledge':
                $table = "tbl_learned_knowledge";
                $orderBy = "is_active DESC, q_value DESC, hit_count DESC, id DESC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(pattern_code LIKE '%{$s}%' OR trigger_phrases LIKE '%{$s}%' OR response_id LIKE '%{$s}%' OR source_ref LIKE '%{$s}%')";
                }
                if ($filter1 !== '') {
                    $where[] = "source_type = '" . $esc($filter1) . "'";
                }
                if ($filter2 !== '') {
                    $where[] = "is_active = " . ($filter2 === '1' ? '1' : '0');
                }
                break;

            case 'stalled_flows':
                $table = "tbl_conversation_flows";
                $orderBy = "(stall_count + rephrase_loop_count + abandon_count) DESC, total_transitions DESC, id DESC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(flow_key LIKE '%{$s}%' OR from_intent LIKE '%{$s}%' OR to_intent LIKE '%{$s}%' OR auto_recovery_prompt_id LIKE '%{$s}%')";
                }
                break;

            case 'self_eval_runs':
                $table = "tbl_self_eval_runs";
                $orderBy = "id DESC";
                if ($search !== '') {
                    $s = $esc($search);
                    $where[] = "(trigger_mode LIKE '%{$s}%' OR summary_note LIKE '%{$s}%')";
                }
                break;

            default:
                return ['items' => [], 'total' => 0, 'page' => 1, 'per_page' => $perPage, 'total_pages' => 1];
        }

        $whereSql = implode(' AND ', $where);
        $cntRes = $conn->query("SELECT COUNT(*) AS cnt FROM `{$table}` WHERE {$whereSql}");
        $total = 0;
        if ($cntRes && ($r = $cntRes->fetch_assoc())) {
            $total = (int)$r['cnt'];
        }
        $totalPages = max(1, (int)ceil($total / $perPage));
        if ($page > $totalPages) $page = $totalPages;
        $offset = ($page - 1) * $perPage;

        $qRes = $conn->query("SELECT {$select} FROM `{$table}` WHERE {$whereSql} ORDER BY {$orderBy} LIMIT {$perPage} OFFSET {$offset}");
        $items = [];
        if ($qRes) {
            while ($row = $qRes->fetch_assoc()) {
                $items[] = $row;
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

    public function saveStaticKnowledge(array $data): array {
        $id = (int)($data['id'] ?? 0);
        $topicCode = preg_replace('/[^a-zA-Z0-9_\-]/', '_', strtolower(trim((string)($data['topic_code'] ?? ''))));
        $category = trim((string)($data['category'] ?? 'General')) ?: 'General';
        $kwId = trim((string)($data['keywords_id'] ?? ''));
        $kwEn = trim((string)($data['keywords_en'] ?? ''));
        $resId = trim((string)($data['response_id'] ?? ''));
        $resEn = trim((string)($data['response_en'] ?? ''));
        $expr = strtoupper(trim((string)($data['expression'] ?? 'HAPPY')));
        $gest = strtoupper(trim((string)($data['gesture'] ?? 'NOD')));
        $prio = max(1, min(100, (int)($data['priority'] ?? 50)));
        $active = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if ($topicCode === '' || $kwId === '' || $resId === '') {
            return ['success' => false, 'message' => 'Topic code, Indonesian keywords, and Indonesian response are required.'];
        }
        if ($kwEn === '') $kwEn = $kwId;
        if ($resEn === '') $resEn = $resId;

        if ($id > 0) {
            $this->db->prepare("UPDATE tbl_static_knowledge SET topic_code = :tc, category = :cat, keywords_id = :ki, keywords_en = :ke, response_id = :ri, response_en = :re, expression = :ex, gesture = :gs, priority = :pr, is_active = :ia WHERE id = :id");
            $this->db->bind(':id', $id);
        } else {
            $this->db->prepare("INSERT INTO tbl_static_knowledge (topic_code, category, keywords_id, keywords_en, response_id, response_en, expression, gesture, priority, is_active, hit_count)
                VALUES (:tc, :cat, :ki, :ke, :ri, :re, :ex, :gs, :pr, :ia, 0)
                ON DUPLICATE KEY UPDATE category = :cat2, keywords_id = :ki2, keywords_en = :ke2, response_id = :ri2, response_en = :re2, expression = :ex2, gesture = :gs2, priority = :pr2, is_active = :ia2");
            $this->db->bind(':cat2', $category);
            $this->db->bind(':ki2', $kwId);
            $this->db->bind(':ke2', $kwEn);
            $this->db->bind(':ri2', $resId);
            $this->db->bind(':re2', $resEn);
            $this->db->bind(':ex2', $expr);
            $this->db->bind(':gs2', $gest);
            $this->db->bind(':pr2', $prio);
            $this->db->bind(':ia2', $active);
        }
        $this->db->bind(':tc', $topicCode);
        $this->db->bind(':cat', $category);
        $this->db->bind(':ki', $kwId);
        $this->db->bind(':ke', $kwEn);
        $this->db->bind(':ri', $resId);
        $this->db->bind(':re', $resEn);
        $this->db->bind(':ex', $expr);
        $this->db->bind(':gs', $gest);
        $this->db->bind(':pr', $prio);
        $this->db->bind(':ia', $active);
        $ok = $this->db->execute();

        return ['success' => $ok, 'message' => $ok ? 'Static Knowledge Base topic saved successfully.' : 'Failed to save Static Knowledge topic.'];
    }

    private function buildConversationFilterWhere(mysqli $conn, array $params): array {
        $esc = static fn($s) => $conn->real_escape_string((string)$s);
        $search = trim((string)($params['search'] ?? ''));
        $filter1 = trim((string)($params['filter1'] ?? ''));
        $filter2 = trim((string)($params['filter2'] ?? ''));
        $filter3 = trim((string)($params['filter3'] ?? ''));

        $clauses = [];
        if ($search !== '') {
            $s = $esc($search);
            $clauses[] = "(user_message LIKE '%{$s}%' OR assistant_response LIKE '%{$s}%' OR user_identifier LIKE '%{$s}%' OR ip_address LIKE '%{$s}%' OR location_label LIKE '%{$s}%' OR profanity_words LIKE '%{$s}%')";
        }
        if ($filter1 === 'profane') {
            $clauses[] = "is_profane = 1";
        } elseif ($filter1 === 'clean') {
            $clauses[] = "is_profane = 0";
        }
        if ($filter2 !== '' && in_array($filter2, ['id', 'en'], true)) {
            $clauses[] = "detected_language = '" . $esc($filter2) . "'";
        }
        if ($filter3 !== '') {
            $clauses[] = "(response_source = '" . $esc($filter3) . "' OR device_type = '" . $esc($filter3) . "')";
        }

        return [
            'whereSql' => !empty($clauses) ? implode(' AND ', $clauses) : '1=1',
            'hasFilter' => !empty($clauses)
        ];
    }

    public function deleteConversationsByCategory(array $params): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_va_conversations')) {
            return ['success' => false, 'deleted' => 0, 'message' => 'Conversation log table is not available.'];
        }
        $conn = $this->db->getConnection();
        if (!$conn) {
            return ['success' => false, 'deleted' => 0, 'message' => 'Database connection is not active.'];
        }
        $filterInfo = $this->buildConversationFilterWhere($conn, $params);
        if (!$filterInfo['hasFilter']) {
            return ['success' => false, 'no_filter' => true, 'deleted' => 0, 'message' => 'Please select at least one filter category (Ethics, Language, Source/Device, or search keyword) first.'];
        }
        $whereSql = $filterInfo['whereSql'];
        $cntRes = $conn->query("SELECT COUNT(*) AS cnt FROM tbl_va_conversations WHERE {$whereSql}");
        $matchCount = ($cntRes && ($r = $cntRes->fetch_assoc())) ? (int)$r['cnt'] : 0;
        if ($matchCount <= 0) {
            return ['success' => false, 'deleted' => 0, 'message' => 'No conversation logs match the selected filter criteria.'];
        }
        $ok = $conn->query("DELETE FROM tbl_va_conversations WHERE {$whereSql}");
        $deleted = $ok ? (int)$conn->affected_rows : 0;
        return [
            'success' => (bool)$ok,
            'deleted' => $deleted,
            'message' => $ok
                ? "Successfully deleted {$deleted} conversation log(s) matching the selected filters."
                : 'Failed to delete conversation logs from database.'
        ];
    }

    public function deleteAllSecurityTable(string $tableType): array {
        $map = [
            'security' => ['table' => 'tbl_security_incidents', 'label' => 'WAF Security Incident Logs'],
            'tbl_audit_logs' => ['table' => 'tbl_audit_logs', 'label' => 'System Activity Audit Trail']
        ];
        if (!isset($map[$tableType])) {
            return ['success' => false, 'invalid' => true, 'deleted' => 0, 'message' => 'Invalid security table target.'];
        }
        $tbl = $map[$tableType]['table'];
        $lbl = $map[$tableType]['label'];
        if (!$this->db->isConnected() || !$this->db->hasTable($tbl)) {
            return ['success' => false, 'deleted' => 0, 'message' => "{$lbl} table is not available."];
        }
        $conn = $this->db->getConnection();
        if (!$conn) {
            return ['success' => false, 'deleted' => 0, 'message' => 'Database connection is not active.'];
        }
        $cntRes = $conn->query("SELECT COUNT(*) AS cnt FROM `{$tbl}`");
        $total = ($cntRes && ($r = $cntRes->fetch_assoc())) ? (int)$r['cnt'] : 0;
        if ($total <= 0) {
            return ['success' => false, 'deleted' => 0, 'message' => "{$lbl} is already empty."];
        }
        $ok = $conn->query("DELETE FROM `{$tbl}`");
        $deleted = $ok ? (int)$conn->affected_rows : 0;
        return [
            'success' => (bool)$ok,
            'deleted' => $deleted,
            'message' => $ok
                ? "Successfully cleared all {$deleted} records from {$lbl}."
                : "Failed to clear {$lbl} from database."
        ];
    }

    public function deleteRecord(string $tableType, int $id): array {
        $map = [
            'conversations' => 'tbl_va_conversations',
            'static_kb' => 'tbl_static_knowledge',
            'rag_docs' => 'tbl_knowledge_documents',
            'memories' => 'tbl_long_term_memories',
            'gemini_keys' => 'tbl_ai_provider_keys',
            'security' => 'tbl_security_incidents',
            'knowledge_gaps' => 'tbl_knowledge_gaps',
            'learned_knowledge' => 'tbl_learned_knowledge',
            'stalled_flows' => 'tbl_conversation_flows'
        ];
        if (!isset($map[$tableType]) || $id <= 0) {
            return ['success' => false, 'message' => 'Invalid deletion target.'];
        }
        $tbl = $map[$tableType];
        if ($tableType === 'rag_docs') {
            $this->db->prepare("DELETE FROM tbl_knowledge_chunks WHERE document_id = :id");
            $this->db->bind(':id', $id);
            $this->db->execute();
        }
        $this->db->prepare("DELETE FROM `{$tbl}` WHERE id = :id");
        $this->db->bind(':id', $id);
        $ok = $this->db->execute();
        if ($ok && $tableType === 'gemini_keys') {
            $this->db->prepare("SELECT COUNT(*) AS cnt FROM tbl_ai_provider_keys WHERE is_primary = 1");
            $cntRow = $this->db->single();
            if (!$cntRow || (int)($cntRow['cnt'] ?? 0) === 0) {
                $this->db->prepare("SELECT id FROM tbl_ai_provider_keys WHERE is_active = 1 ORDER BY id DESC LIMIT 1");
                $fallbackKey = $this->db->single();
                if ($fallbackKey && !empty($fallbackKey['id'])) {
                    $this->db->prepare("UPDATE tbl_ai_provider_keys SET is_primary = 1 WHERE id = :id");
                    $this->db->bind(':id', (int)$fallbackKey['id']);
                    $this->db->execute();
                }
            }
        }
        return ['success' => $ok, 'message' => $ok ? 'Record deleted successfully.' : 'Failed to delete record.'];
    }

    public function setPrimaryApiKey(int $id): array {
        if ($id <= 0 || !$this->db->isConnected() || !$this->db->hasTable('tbl_ai_provider_keys')) {
            return ['success' => false, 'message' => 'Invalid API Key ID.'];
        }
        $this->db->prepare("SELECT id, provider, key_label, model_name FROM tbl_ai_provider_keys WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $id);
        $row = $this->db->single();
        if (!$row) {
            return ['success' => false, 'message' => 'API Key record not found.'];
        }
        $provider = self::normalizeProvider((string)($row['provider'] ?? ''), (string)($row['model_name'] ?? ''));
        $model = (string)($row['model_name'] ?? '');

        $this->db->query("UPDATE tbl_ai_provider_keys SET is_primary = 0");
        $this->db->prepare("UPDATE tbl_ai_provider_keys SET is_primary = 1, is_active = 1 WHERE id = :id");
        $this->db->bind(':id', $id);
        $ok = $this->db->execute();

        if ($ok && $this->db->hasTable('tbl_system_settings')) {
            $this->db->prepare("INSERT INTO tbl_system_settings (setting_key, setting_value) VALUES ('ai_provider', :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
            $this->db->bind(':v', $provider);
            $this->db->bind(':v2', $provider);
            $this->db->execute();
        }
        $provLbl = $provider === 'openai' ? 'OpenAI API' : 'Google Gemini API';
        return [
            'success' => $ok,
            'id' => $id,
            'provider' => $provider,
            'model_name' => $model,
            'message' => $ok ? ("Active Primary Key switched to '{$row['key_label']}' ({$provLbl}).") : 'Failed to set Primary Key.'
        ];
    }

    public function saveGeminiKey(array $data, string $actor = 'admin'): array {
        $id = (int)($data['id'] ?? 0);
        $rawKey = trim((string)($data['api_key'] ?? ''), " \t\n\r\0\x0B\"'");
        $rawModel = trim((string)($data['model_name'] ?? ''));
        $provider = self::normalizeProvider($data['provider'] ?? '', $rawModel, $rawKey);
        $defaultLbl = $provider === 'openai' ? 'OpenAI API Key' : 'Gemini API Key';
        $label = trim((string)($data['key_label'] ?? $defaultLbl)) ?: $defaultLbl;
        $model = $provider === 'openai'
            ? self::normalizeOpenAiModel($rawModel !== '' ? $rawModel : 'gpt-4.1-mini')
            : self::normalizeGeminiModel($rawModel !== '' ? $rawModel : 'gemini-2.5-flash');
        $isPrimary = !empty($data['is_primary']) ? 1 : 0;
        $isActive = isset($data['is_active']) ? ((int)$data['is_active'] ? 1 : 0) : 1;

        if ($id > 0) {
            $this->db->prepare("SELECT id, provider FROM tbl_ai_provider_keys WHERE id = :id LIMIT 1");
            $this->db->bind(':id', $id);
            $existing = $this->db->single();
            if ($existing) {
                if (empty($data['provider']) && $rawKey === '' && $rawModel === '' && !empty($existing['provider'])) {
                    $provider = self::normalizeProvider((string)$existing['provider']);
                }
                if ($isPrimary === 1) {
                    $this->db->query("UPDATE tbl_ai_provider_keys SET is_primary = 0");
                }
                if ($rawKey !== '') {
                    if (strlen($rawKey) < 12) {
                        return ['success' => false, 'message' => 'Invalid API Key format (minimum 12 characters).'];
                    }
                    $enc = $this->encryptSecret($rawKey);
                    $masked = $this->maskApiKey($rawKey);
                    $this->db->prepare("UPDATE tbl_ai_provider_keys SET provider = :pv, key_label = :l, api_key_encrypted = :e, api_key_masked = :m, model_name = :md, is_active = :ia, is_primary = :ip WHERE id = :id");
                    $this->db->bind(':e', $enc);
                    $this->db->bind(':m', $masked);
                } else {
                    $this->db->prepare("UPDATE tbl_ai_provider_keys SET provider = :pv, key_label = :l, model_name = :md, is_active = :ia, is_primary = :ip WHERE id = :id");
                }
                $this->db->bind(':pv', $provider);
                $this->db->bind(':l', $label);
                $this->db->bind(':md', $model);
                $this->db->bind(':ia', $isActive);
                $this->db->bind(':ip', $isPrimary);
                $this->db->bind(':id', $id);
                $ok = $this->db->execute();
                if ($ok && $isPrimary === 1 && $this->db->hasTable('tbl_system_settings')) {
                    $this->db->prepare("INSERT INTO tbl_system_settings (setting_key, setting_value) VALUES ('ai_provider', :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
                    $this->db->bind(':v', $provider);
                    $this->db->bind(':v2', $provider);
                    $this->db->execute();
                }
                return [
                    'success' => $ok,
                    'provider' => $provider,
                    'is_primary' => $isPrimary,
                    'message' => $ok ? 'API Key configuration updated successfully.' : 'Failed to update API Key in database.'
                ];
            }
            $id = 0;
        }

        if (strlen($rawKey) < 12) {
            return ['success' => false, 'message' => 'Invalid API Key format (minimum 12 characters).'];
        }
        $this->db->prepare("SELECT COUNT(*) AS cnt FROM tbl_ai_provider_keys WHERE is_primary = 1");
        $primRow = $this->db->single();
        if (!$primRow || (int)($primRow['cnt'] ?? 0) === 0) {
            $isPrimary = 1;
        }
        if ($isPrimary === 1) {
            $this->db->query("UPDATE tbl_ai_provider_keys SET is_primary = 0");
        }
        $enc = $this->encryptSecret($rawKey);
        $masked = $this->maskApiKey($rawKey);
        $this->db->prepare("INSERT INTO tbl_ai_provider_keys (provider, key_label, api_key_encrypted, api_key_masked, model_name, is_active, is_primary, last_status, created_by)
            VALUES (:pv, :l, :e, :m, :md, :ia, :ip, 'Configured (Ready)', :cb)");
        $this->db->bind(':pv', $provider);
        $this->db->bind(':l', $label);
        $this->db->bind(':e', $enc);
        $this->db->bind(':m', $masked);
        $this->db->bind(':md', $model);
        $this->db->bind(':ia', $isActive);
        $this->db->bind(':ip', $isPrimary);
        $this->db->bind(':cb', $actor);
        $ok = $this->db->execute();
        if ($ok && $isPrimary === 1 && $this->db->hasTable('tbl_system_settings')) {
            $this->db->prepare("INSERT INTO tbl_system_settings (setting_key, setting_value) VALUES ('ai_provider', :v) ON DUPLICATE KEY UPDATE setting_value = :v2");
            $this->db->bind(':v', $provider);
            $this->db->bind(':v2', $provider);
            $this->db->execute();
        }
        $provLbl = $provider === 'openai' ? 'OpenAI' : 'Gemini';
        return [
            'success' => $ok,
            'id' => $ok ? (int)$this->db->lastInsertId() : 0,
            'provider' => $provider,
            'is_primary' => $isPrimary,
            'message' => $ok ? "New {$provLbl} API Key encrypted (AES-256) and saved." : 'Failed to save API Key to database.'
        ];
    }

    private function extractGeminiReplyText(array $decoded): string {
        $parts = $decoded['candidates'][0]['content']['parts'] ?? [];
        if (!is_array($parts)) {
            return '';
        }
        $answerChunks = [];
        $fallbackChunks = [];
        foreach ($parts as $part) {
            if (!is_array($part) || !isset($part['text'])) {
                continue;
            }
            $t = trim((string)$part['text']);
            if ($t === '') {
                continue;
            }
            if (empty($part['thought'])) {
                $answerChunks[] = $t;
            } else {
                $fallbackChunks[] = $t;
            }
        }
        if (!empty($answerChunks)) {
            return trim(implode("\n", $answerChunks));
        }
        return !empty($fallbackChunks) ? trim(implode("\n", $fallbackChunks)) : '';
    }

    private function resolveCaBundleFile(): string {
        $caPaths = array_filter([
            (string)ini_get('curl.cainfo'),
            (string)ini_get('openssl.cafile'),
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
            'C:/xampp/php/extras/ssl/cacert.pem'
        ]);
        foreach ($caPaths as $cand) {
            if ($cand !== '' && file_exists($cand)) {
                return $cand;
            }
        }
        return '';
    }

    private function parseGeminiResponseBody(string $rawRes): array {
        $trimmed = ltrim($rawRes);
        if ($trimmed === '') {
            return [];
        }
        if ($trimmed[0] === '{' || $trimmed[0] === '[') {
            $direct = json_decode($trimmed, true);
            if (is_array($direct)) {
                return $direct;
            }
        }
        $lines = preg_split('/\r?\n/', $rawRes) ?: [];
        $answerText = '';
        $thoughtText = '';
        $finishReason = '';
        $promptFeedback = [];
        $usageMetadata = [];
        $errObj = null;
        $hasEvent = false;
        foreach ($lines as $line) {
            $lineTrim = trim($line);
            if ($lineTrim === '' || strpos($lineTrim, 'data:') !== 0) {
                continue;
            }
            $jsonStr = trim(substr($lineTrim, 5));
            if ($jsonStr === '' || $jsonStr === '[DONE]') {
                continue;
            }
            $chunk = json_decode($jsonStr, true);
            if (!is_array($chunk)) {
                continue;
            }
            $hasEvent = true;
            if (isset($chunk['error']) && is_array($chunk['error'])) {
                $errObj = $chunk['error'];
            }
            if (isset($chunk['promptFeedback']) && is_array($chunk['promptFeedback'])) {
                $promptFeedback = $chunk['promptFeedback'];
            }
            if (isset($chunk['usageMetadata']) && is_array($chunk['usageMetadata'])) {
                $usageMetadata = $chunk['usageMetadata'];
            }
            if (!empty($chunk['candidates'][0]['finishReason'])) {
                $finishReason = (string)$chunk['candidates'][0]['finishReason'];
            }
            $parts = $chunk['candidates'][0]['content']['parts'] ?? [];
            if (is_array($parts)) {
                foreach ($parts as $part) {
                    if (!is_array($part) || !isset($part['text'])) {
                        continue;
                    }
                    $t = (string)$part['text'];
                    if (empty($part['thought'])) {
                        $answerText .= $t;
                    } else {
                        $thoughtText .= $t;
                    }
                }
            }
        }
        if (!$hasEvent) {
            return [];
        }
        if ($errObj !== null) {
            return ['error' => $errObj];
        }
        $mergedParts = [];
        if (trim($answerText) !== '') {
            $mergedParts[] = ['text' => trim($answerText)];
        } elseif (trim($thoughtText) !== '') {
            $mergedParts[] = ['thought' => true, 'text' => trim($thoughtText)];
        }
        return [
            'candidates' => [[
                'content' => ['parts' => $mergedParts],
                'finishReason' => $finishReason
            ]],
            'promptFeedback' => $promptFeedback,
            'usageMetadata' => $usageMetadata
        ];
    }

    private function sendHttpJsonRequest(string $url, array $headers, string $jsonPayload, string $caFile, int $timeoutSec, string $method = 'POST'): array {
        $rawRes = '';
        $httpCode = 0;
        $curlErrNo = 0;
        $curlErrMsg = '';
        $isPost = strtoupper($method) === 'POST';
        if (function_exists('curl_init')) {
            $opts = [
                CURLOPT_RETURNTRANSFER => false,
                CURLOPT_ENCODING => '',
                CURLOPT_HTTPHEADER => array_merge(['Expect:'], $headers),
                CURLOPT_TIMEOUT => $timeoutSec,
                CURLOPT_CONNECTTIMEOUT => min(8, $timeoutSec),
                CURLOPT_LOW_SPEED_LIMIT => 1,
                CURLOPT_LOW_SPEED_TIME => min(12, $timeoutSec),
                CURLOPT_TCP_KEEPALIVE => 1,
                CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
                CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                CURLOPT_WRITEFUNCTION => function($ch, $chunk) use (&$rawRes) {
                    $rawRes .= $chunk;
                    return strlen($chunk);
                }
            ];
            if ($isPost) {
                $opts[CURLOPT_POST] = true;
                $opts[CURLOPT_POSTFIELDS] = $jsonPayload;
            }
            if ($caFile !== '') {
                $opts[CURLOPT_SSL_VERIFYPEER] = true;
                $opts[CURLOPT_SSL_VERIFYHOST] = 2;
                $opts[CURLOPT_CAINFO] = $caFile;
            } else {
                $opts[CURLOPT_SSL_VERIFYPEER] = false;
            }
            $ch = curl_init($url);
            curl_setopt_array($ch, $opts);
            $execOk = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrNo = curl_errno($ch);
            $curlErrMsg = trim((string)curl_error($ch));
            curl_close($ch);
            if ($execOk === false && $rawRes === '') {
                $rawRes = false;
            }
        } elseif (ini_get('allow_url_fopen')) {
            $sslOpts = ['verify_peer' => $caFile !== '', 'verify_peer_name' => $caFile !== ''];
            if ($caFile !== '') {
                $sslOpts['cafile'] = $caFile;
            }
            $httpOpts = [
                'method' => $isPost ? 'POST' : 'GET',
                'header' => implode("\r\n", $headers) . "\r\n",
                'timeout' => $timeoutSec,
                'ignore_errors' => true
            ];
            if ($isPost) {
                $httpOpts['content'] = $jsonPayload;
            }
            $ctx = @stream_context_create([
                'http' => $httpOpts,
                'ssl' => $sslOpts
            ]);
            if ($ctx) {
                $streamRes = @file_get_contents($url, false, $ctx);
                if (is_string($streamRes) && isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', (string)$http_response_header[0], $hm)) {
                    $rawRes = $streamRes;
                    $httpCode = (int)$hm[1];
                }
            }
        }
        return [
            'raw' => $rawRes,
            'http_code' => $httpCode,
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlErrMsg
        ];
    }

    private function executeGeminiHttp(string $model, string $apiKey, array $body, int $timeoutSec = 12): array {
        if (!preg_match('/^[a-zA-Z0-9\.\-_]{3,64}$/', $model)) {
            return ['ok' => false, 'http_code' => 400, 'decoded' => ['error' => ['status' => 'INVALID_MODEL', 'message' => 'Invalid Gemini model name format.']], 'curl_errno' => 0, 'curl_error' => ''];
        }
        $baseEndpoint = "https://generativelanguage.googleapis.com/v1beta/models/" . rawurlencode($model);
        $jsonPayload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $caFile = $this->resolveCaBundleFile();
        $headers = ['Content-Type: application/json', 'x-goog-api-key: ' . $apiKey];

        $sseAttempt = $this->sendHttpJsonRequest($baseEndpoint . ":streamGenerateContent?alt=sse", $headers, $jsonPayload, $caFile, $timeoutSec);
        $rawRes = $sseAttempt['raw'];
        $httpCode = (int)$sseAttempt['http_code'];
        $curlErrNo = (int)$sseAttempt['curl_errno'];
        $curlErrMsg = (string)$sseAttempt['curl_error'];
        $decoded = is_string($rawRes) ? $this->parseGeminiResponseBody($rawRes) : [];

        if ($httpCode === 400 && isset($body['generationConfig']['thinkingConfig']) && stripos((string)($decoded['error']['message'] ?? ''), 'thinking') !== false) {
            unset($body['generationConfig']['thinkingConfig']);
            $jsonPayload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $sseAttempt = $this->sendHttpJsonRequest($baseEndpoint . ":streamGenerateContent?alt=sse", $headers, $jsonPayload, $caFile, $timeoutSec);
            $rawRes = $sseAttempt['raw'];
            $httpCode = (int)$sseAttempt['http_code'];
            $curlErrNo = (int)$sseAttempt['curl_errno'];
            $curlErrMsg = (string)$sseAttempt['curl_error'];
            $decoded = is_string($rawRes) ? $this->parseGeminiResponseBody($rawRes) : [];
        }

        $shouldFallbackUnary = ($httpCode === 0 && $curlErrNo !== 28)
            || ($httpCode === 400 && strpos(strtolower((string)($decoded['error']['message'] ?? '')), 'stream') !== false)
            || ($httpCode === 404)
            || ($httpCode >= 200 && $httpCode < 300 && empty($decoded['candidates']));

        if ($shouldFallbackUnary) {
            $unaryTimeout = min(10, $timeoutSec);
            $unaryAttempt = $this->sendHttpJsonRequest($baseEndpoint . ":generateContent", $headers, $jsonPayload, $caFile, $unaryTimeout);
            $uCode = (int)$unaryAttempt['http_code'];
            $uRaw = $unaryAttempt['raw'];
            $uDecoded = is_string($uRaw) ? $this->parseGeminiResponseBody($uRaw) : [];
            if ($uCode > 0 && (!empty($uDecoded['candidates']) || $httpCode === 0 || !empty($uDecoded['error']))) {
                $rawRes = $uRaw;
                $httpCode = $uCode;
                $curlErrNo = (int)$unaryAttempt['curl_errno'];
                $curlErrMsg = (string)$unaryAttempt['curl_error'];
                $decoded = $uDecoded;
            }
        }

        return [
            'ok' => ($rawRes !== false && $httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'decoded' => $decoded,
            'curl_errno' => $curlErrNo,
            'curl_error' => $curlErrMsg
        ];
    }

    private function extractOpenAiReplyText(array $decoded): string {
        if (!empty($decoded['output_text']) && is_string($decoded['output_text'])) {
            return trim($decoded['output_text']);
        }
        $chunks = [];
        foreach (($decoded['output'] ?? []) as $item) {
            if (!is_array($item)) {
                continue;
            }
            $contents = $item['content'] ?? [];
            if (!is_array($contents)) {
                continue;
            }
            foreach ($contents as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (isset($part['text']) && is_string($part['text'])) {
                    $t = trim($part['text']);
                    if ($t !== '') {
                        $chunks[] = $t;
                    }
                }
            }
        }
        if (!empty($chunks)) {
            return trim(implode("\n", $chunks));
        }
        $chatMsg = $decoded['choices'][0]['message']['content'] ?? '';
        return is_string($chatMsg) ? trim($chatMsg) : '';
    }

    private function executeOpenAiHttp(string $model, string $apiKey, string $instructions, $input, int $maxOutputTokens = 240, ?float $temperature = null, int $timeoutSec = 14): array {
        if (!preg_match('/^[a-zA-Z0-9\.\-_]{2,64}$/', $model)) {
            return ['ok' => false, 'http_code' => 400, 'decoded' => ['error' => ['code' => 'INVALID_MODEL', 'message' => 'Invalid OpenAI model name format.']], 'curl_errno' => 0, 'curl_error' => ''];
        }
        $caFile = $this->resolveCaBundleFile();
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey];
        $payload = [
            'model' => $model,
            'input' => $input,
            'max_output_tokens' => max(160, $maxOutputTokens)
        ];
        if (trim($instructions) !== '') {
            $payload['instructions'] = $instructions;
        }
        $isReasoningFamily = (bool)preg_match('/^(?:gpt-5|gpt-6|o[1-9])/i', $model);
        if ($isReasoningFamily) {
            $payload['reasoning'] = ['effort' => 'low'];
            $payload['text'] = ['verbosity' => 'low'];
        } elseif ($temperature !== null) {
            $payload['temperature'] = $temperature;
        }

        $url = 'https://api.openai.com/v1/responses';
        $attempt = $this->sendHttpJsonRequest($url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $caFile, $timeoutSec);
        $rawRes = $attempt['raw'];
        $httpCode = (int)$attempt['http_code'];
        $decoded = is_string($rawRes) ? (json_decode($rawRes, true) ?: []) : [];

        if ($httpCode === 400) {
            $errMsg = strtolower((string)($decoded['error']['message'] ?? ''));
            $retried = false;
            if (isset($payload['temperature']) && strpos($errMsg, 'temperature') !== false) {
                unset($payload['temperature']);
                $retried = true;
            }
            if (isset($payload['reasoning']) && strpos($errMsg, 'reasoning') !== false) {
                unset($payload['reasoning']);
                $retried = true;
            }
            if (isset($payload['text']) && (strpos($errMsg, 'verbosity') !== false || strpos($errMsg, 'text') !== false)) {
                unset($payload['text']);
                $retried = true;
            }
            if ($retried) {
                $attempt = $this->sendHttpJsonRequest($url, $headers, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $caFile, $timeoutSec);
                $rawRes = $attempt['raw'];
                $httpCode = (int)$attempt['http_code'];
                $decoded = is_string($rawRes) ? (json_decode($rawRes, true) ?: []) : [];
            }
        }

        return [
            'ok' => ($rawRes !== false && $httpCode >= 200 && $httpCode < 300),
            'http_code' => $httpCode,
            'decoded' => $decoded,
            'curl_errno' => (int)$attempt['curl_errno'],
            'curl_error' => (string)$attempt['curl_error']
        ];
    }

    private function testOpenAiModelKey(int $id, string $rawKey, string $model): array {
        $caFile = $this->resolveCaBundleFile();
        $headers = ['Content-Type: application/json', 'Authorization: Bearer ' . $rawKey];
        $reply = '';
        $resHttp = [];

        if (strpos($model, 'text-embedding-') === 0) {
            $resHttp = $this->sendHttpJsonRequest(
                'https://api.openai.com/v1/embeddings',
                $headers,
                json_encode(['model' => $model, 'input' => 'Unklabot OpenAI embedding test']),
                $caFile,
                20
            );
            $decoded = is_string($resHttp['raw']) ? (json_decode($resHttp['raw'], true) ?: []) : [];
            $vec = $decoded['data'][0]['embedding'] ?? [];
            $ok = ($resHttp['http_code'] >= 200 && $resHttp['http_code'] < 300 && is_array($vec) && count($vec) > 0);
            $reply = $ok ? ('Embedding siap (' . count($vec) . ' dimensi)') : '';
            $resHttp['decoded'] = $decoded;
            $resHttp['ok'] = $ok;
        } elseif (strpos($model, 'omni-moderation') === 0) {
            $resHttp = $this->sendHttpJsonRequest(
                'https://api.openai.com/v1/moderations',
                $headers,
                json_encode(['model' => $model, 'input' => 'Unklabot moderation test']),
                $caFile,
                20
            );
            $decoded = is_string($resHttp['raw']) ? (json_decode($resHttp['raw'], true) ?: []) : [];
            $ok = ($resHttp['http_code'] >= 200 && $resHttp['http_code'] < 300 && isset($decoded['results'][0]));
            $reply = $ok ? 'Moderation API terhubung' : '';
            $resHttp['decoded'] = $decoded;
            $resHttp['ok'] = $ok;
        } elseif (preg_match('/^(?:gpt-image|gpt-realtime|gpt-live|gpt-audio|gpt-transcribe)/i', $model)) {
            $resHttp = $this->sendHttpJsonRequest(
                'https://api.openai.com/v1/models/' . rawurlencode($model),
                $headers,
                '',
                $caFile,
                15,
                'GET'
            );
            $decoded = is_string($resHttp['raw']) ? (json_decode($resHttp['raw'], true) ?: []) : [];
            $ok = ($resHttp['http_code'] >= 200 && $resHttp['http_code'] < 300 && !empty($decoded['id']));
            $reply = $ok ? ('Model spesialis aktif (' . $decoded['id'] . ')') : '';
            $resHttp['decoded'] = $decoded;
            $resHttp['ok'] = $ok;
        } else {
            $resHttp = $this->executeOpenAiHttp($model, $rawKey, 'Jawab singkat tepat satu kalimat.', 'Balas singkat tepat satu kalimat: Unklabot OpenAI API terhubung.', 180, null, 15);
            $reply = $this->extractOpenAiReplyText($resHttp['decoded'] ?? []);
            $resHttp['ok'] = ($resHttp['ok'] && $reply !== '');
        }

        $httpCode = (int)($resHttp['http_code'] ?? 0);
        $decoded = $resHttp['decoded'] ?? [];
        $ok = (bool)($resHttp['ok'] ?? false);
        $apiErrCode = (string)($decoded['error']['code'] ?? ($decoded['error']['type'] ?? ''));
        $apiErrMsg = trim((string)($decoded['error']['message'] ?? ''));
        $curlErr = trim((string)($resHttp['curl_error'] ?? ''));
        $incReason = (string)($decoded['incomplete_details']['reason'] ?? ($decoded['status'] ?? ''));

        if ($ok) {
            $statusTxt = 'Connected (200 OK)';
        } elseif ($httpCode === 0) {
            $shortErr = $curlErr !== '' ? substr($curlErr, 0, 32) : 'Network Timeout';
            $statusTxt = "HTTP 0 ({$shortErr})";
        } elseif ($httpCode >= 200 && $httpCode < 300 && $reply === '') {
            $lbl = $incReason !== '' ? $incReason : 'Empty Reply';
            $statusTxt = "HTTP {$httpCode} ({$lbl})";
        } else {
            $errCodeLbl = $apiErrCode !== '' ? $apiErrCode : 'Check Key';
            $statusTxt = "HTTP {$httpCode} ({$errCodeLbl})";
        }

        if ($id > 0) {
            $dbStatus = substr($statusTxt, 0, 62);
            $this->db->prepare("UPDATE tbl_ai_provider_keys SET last_status = :st, last_used_at = NOW() WHERE id = :id");
            $this->db->bind(':st', $dbStatus);
            $this->db->bind(':id', $id);
            $this->db->execute();
        }

        $detailMsg = $ok
            ? "OpenAI API ({$model}) connected successfully: {$reply}"
            : "OpenAI model {$model} connection test returned status: {$statusTxt}."
              . ($apiErrMsg !== '' ? " OpenAI API detail: {$apiErrMsg}" : ($curlErr !== '' ? " Network detail: {$curlErr}" : " Ensure the OpenAI API Key is active and has quota."));

        return [
            'success' => $ok,
            'provider' => 'openai',
            'model' => $model,
            'http_code' => $httpCode,
            'status' => $statusTxt,
            'reply' => $reply,
            'message' => $detailMsg
        ];
    }

    public function testGeminiKey(array $data): array {
        $id = (int)($data['id'] ?? 0);
        $rawKey = trim((string)($data['api_key'] ?? ''), " \t\n\r\0\x0B\"'");
        $hasModelParam = isset($data['model_name']) && trim((string)$data['model_name']) !== '';
        $hasProviderParam = isset($data['provider']) && trim((string)$data['provider']) !== '';
        $model = trim((string)($data['model_name'] ?? ''));
        $provider = $hasProviderParam ? self::normalizeProvider((string)$data['provider'], $model, $rawKey) : '';

        if ($id > 0 && $rawKey === '') {
            $this->db->prepare("SELECT * FROM tbl_ai_provider_keys WHERE id = :id LIMIT 1");
            $this->db->bind(':id', $id);
            $row = $this->db->single();
            if (!$row) {
                return ['success' => false, 'message' => 'API Key record not found in database. Please re-save the API Key.'];
            }
            $rawKey = trim($this->decryptSecret((string)$row['api_key_encrypted']), " \t\n\r\0\x0B\"'");
            if (!$hasModelParam && !empty($row['model_name'])) {
                $model = (string)$row['model_name'];
            }
            if ($provider === '' && !empty($row['provider'])) {
                $provider = self::normalizeProvider((string)$row['provider'], $model, $rawKey);
            }
        }
        $provider = self::normalizeProvider($provider, $model, $rawKey);
        if (strlen($rawKey) < 12) {
            return ['success' => false, 'message' => 'API Key is missing, failed decryption, or is too short.'];
        }
        if (!function_exists('curl_init') && !ini_get('allow_url_fopen')) {
            return ['success' => false, 'message' => 'PHP cURL extension and HTTPS streams are unavailable on this server.'];
        }

        if ($provider === 'openai') {
            $openAiModel = self::normalizeOpenAiModel($model !== '' ? $model : 'gpt-6.1-sol');
            return $this->testOpenAiModelKey($id, $rawKey, $openAiModel);
        }

        $geminiModel = self::normalizeGeminiModel($model !== '' ? $model : 'gemini-3.8-flash');
        $genCfgTest = ['maxOutputTokens' => 160, 'temperature' => 0.2];
        if (strpos($geminiModel, 'tts') === false && strpos($geminiModel, 'image') === false && strpos($geminiModel, 'transcribe') === false) {
            $genCfgTest['thinkingConfig'] = ['thinkingBudget' => 0];
        }
        $payload = [
            'contents' => [['role' => 'user', 'parts' => [['text' => 'Balas singkat tepat satu kalimat: Unklabot Gemini API terhubung.']]]],
            'generationConfig' => $genCfgTest
        ];
        $resHttp = $this->executeGeminiHttp($geminiModel, $rawKey, $payload, 12);
        $httpCode = (int)$resHttp['http_code'];
        $decoded = $resHttp['decoded'];
        $reply = $this->extractGeminiReplyText($decoded);
        $ok = ($resHttp['ok'] && $reply !== '');

        $finishReason = (string)($decoded['candidates'][0]['finishReason'] ?? ($decoded['promptFeedback']['blockReason'] ?? ''));
        $apiErrStatus = (string)($decoded['error']['status'] ?? '');
        $apiErrMsg = trim((string)($decoded['error']['message'] ?? ''));
        $curlErr = trim((string)($resHttp['curl_error'] ?? ''));

        if ($ok) {
            $statusTxt = 'Connected (200 OK)';
        } elseif ($httpCode === 0) {
            $shortErr = $curlErr !== '' ? substr($curlErr, 0, 32) : 'Network Timeout';
            $statusTxt = "HTTP 0 ({$shortErr})";
        } elseif ($resHttp['ok'] && $reply === '') {
            $reasonLbl = $finishReason !== '' ? $finishReason : 'Empty Reply';
            $statusTxt = "HTTP {$httpCode} ({$reasonLbl})";
        } else {
            $errCodeLbl = $apiErrStatus !== '' ? $apiErrStatus : 'Check Key';
            $statusTxt = "HTTP {$httpCode} ({$errCodeLbl})";
        }

        if ($id > 0) {
            $dbStatus = substr($statusTxt, 0, 62);
            $this->db->prepare("UPDATE tbl_ai_provider_keys SET last_status = :st, last_used_at = NOW() WHERE id = :id");
            $this->db->bind(':st', $dbStatus);
            $this->db->bind(':id', $id);
            $this->db->execute();
        }

        $detailMsg = $ok
            ? "Gemini API ({$geminiModel}) connected successfully: {$reply}"
            : "Gemini model {$geminiModel} connection test returned status: {$statusTxt}."
              . ($apiErrMsg !== '' ? " Google API detail: {$apiErrMsg}" : ($curlErr !== '' ? " Network detail: {$curlErr}" : " Ensure the API Key is active and the model supports generateContent."));

        return [
            'success' => $ok,
            'provider' => 'gemini',
            'model' => $geminiModel,
            'http_code' => $httpCode,
            'status' => $statusTxt,
            'reply' => $reply,
            'message' => $detailMsg
        ];
    }

    public function toggleAgentTool(int $id, int $isActive): array {
        $this->db->prepare("UPDATE tbl_agent_tools SET is_active = :ia WHERE id = :id");
        $this->db->bind(':ia', $isActive ? 1 : 0);
        $this->db->bind(':id', $id);
        $ok = $this->db->execute();
        return ['success' => $ok, 'message' => $ok ? 'AI Agent tool status updated.' : 'Failed to update tool status.'];
    }

    public function recordUserFeedback(int $conversationId, string $sessionCode, int $rating, string $note = ''): array {
        return $this->getSelfLearner()->recordUserFeedback($conversationId, $sessionCode, $rating, $note);
    }

    public function recordFlowBeacon(string $sessionCode, int $conversationId, string $eventType): array {
        return $this->getSelfLearner()->recordFlowBeacon($sessionCode, $conversationId, $eventType);
    }

    private function resolveToolAliasForGap(string $question, string $lang): ?array {
        $lower = function_exists('mb_strtolower') ? mb_strtolower(trim($question), 'UTF-8') : strtolower(trim($question));
        $toolOut = $this->tryExecuteAgentTool($question, $lower, $lang, '__self_learn_tool', 'Asia/Makassar');
        if ($toolOut && !empty($toolOut['reply'])) {
            return [
                'tool' => (string)($toolOut['tool'] ?? ''),
                'source' => (string)($toolOut['source'] ?? 'Function Calling'),
                'reply' => (string)$toolOut['reply']
            ];
        }
        return null;
    }

    private function synthesizeKnowledgeWithLlm(string $question, string $lang, string $intentTag = 'general_qa'): ?array {
        if (in_array($intentTag, ['memory_recall', 'calendar_time', 'math_calc', 'profanity', 'sql_analytics'], true)) {
            return null;
        }
        $cleanQ = trim($question);
        if (mb_strlen($cleanQ, 'UTF-8') < 6) {
            return null;
        }
        $synthPrompt = $lang === 'en'
            ? "Autonomous Knowledge Acquisition Task: Answer concisely and factually in at most 2 short sentences (under 220 characters) using plain natural text. If the question depends on private real-time institutional facts you cannot verify (e.g. current personal names of dormitory heads/staff, personal grades, or private schedules), reply ONLY with exact token: __UNCERTAIN__. Question: {$cleanQ}"
            : "Tugas Akuisisi Pengetahuan Otonom: Jawab secara faktual, netral, dan ringkas dalam maksimal 2 kalimat pendek (sekitar 20-40 kata) menggunakan teks biasa tanpa markdown. Jika pertanyaan bergantung pada fakta privat/real-time internal yang tidak dapat diverifikasi pasti (misalnya nama kepala asrama saat ini, nilai pribadi, atau jadwal personal), balas HANYA kata: __UNCERTAIN__. Pertanyaan: {$cleanQ}";

        $llmOut = $this->callActiveLlm($synthPrompt, $lang, '__self_learn_auto', null, null);
        if (!$llmOut || empty($llmOut['reply'])) {
            return null;
        }
        $rawReply = trim((string)$llmOut['reply']);
        if ($rawReply === '' || stripos($rawReply, '__UNCERTAIN__') !== false) {
            return null;
        }
        $ev = $this->evaluateAndRefineResponse($rawReply, $cleanQ, $lang, (string)($llmOut['source'] ?? 'OpenAI LLM'), ['latency_ms' => 400]);
        if (!empty($ev['is_unanswered']) || (float)($ev['score'] ?? 0) < 0.84) {
            return null;
        }
        return [
            'reply' => (string)$ev['reply'],
            'score' => (float)$ev['score'],
            'source' => (string)($llmOut['source'] ?? 'Autonomous LLM'),
            'model' => (string)($llmOut['model'] ?? '')
        ];
    }

    public function runSelfLearningCycle(string $mode = 'manual_cycle'): array {
        return $this->getSelfLearner()->runOptimizationCycle($mode);
    }

    public function healKnowledgeGap(int $gapId): array {
        $res = $this->getSelfLearner()->healSingleGap($gapId);
        return [
            'success' => true,
            'healed' => !empty($res['healed']),
            'strategy' => $res['strategy'] ?? '',
            'message' => !empty($res['healed'])
                ? 'Knowledge gap successfully healed and promoted to autonomous AI knowledge.'
                : 'Insufficient local facts found in RAG documents or Static KB to heal this gap yet.'
        ];
    }

    public function toggleLearnedKnowledge(int $id, int $isActive): array {
        return $this->getSelfLearner()->toggleLearnedKnowledge($id, $isActive);
    }

    public function saveUserAccount(array $data, string $actor = 'admin'): array {
        $userSvc = new UserManagementService($this->db);
        return $userSvc->saveUser($data, $actor);
    }

    public function deleteUserAccount(int $targetId, int $currentUserId): array {
        $userSvc = new UserManagementService($this->db);
        return $userSvc->softDeleteUser($targetId, $currentUserId);
    }

    public function updateProfileAccount(int $userId, array $data): array {
        $this->db->prepare("SELECT * FROM tbl_users WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $userId);
        $user = $this->db->single();
        if (!$user) {
            return ['success' => false, 'message' => 'Account not found.'];
        }
        $fullName = trim((string)($data['full_name'] ?? $user['full_name']));
        $email = strtolower(trim((string)($data['email'] ?? $user['email'])));
        $dept = trim((string)($data['department'] ?? $user['department']));
        $phone = trim((string)($data['phone'] ?? $user['phone']));
        $color = trim((string)($data['avatar_color'] ?? $user['avatar_color'])) ?: '#f59e0b';
        $newPass = (string)($data['new_password'] ?? '');
        $curPass = (string)($data['current_password'] ?? '');

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($fullName) < 3) {
            return ['success' => false, 'message' => 'Full name and a valid email format are required.'];
        }

        if ($newPass !== '') {
            if (!Security::verify_password($curPass, (string)$user['password_hash'])) {
                return ['success' => false, 'message' => 'Current password does not match.'];
            }
            if (strlen($newPass) < 6) {
                return ['success' => false, 'message' => 'New password must be at least 6 characters.'];
            }
            $hash = Security::hash_password($newPass);
            $this->db->prepare("UPDATE tbl_users SET full_name = :fn, email = :e, department = :d, phone = :p, avatar_color = :c, password_hash = :ph WHERE id = :id");
            $this->db->bind(':ph', $hash);
        } else {
            $this->db->prepare("UPDATE tbl_users SET full_name = :fn, email = :e, department = :d, phone = :p, avatar_color = :c WHERE id = :id");
        }
        $this->db->bind(':fn', $fullName);
        $this->db->bind(':e', $email);
        $this->db->bind(':d', $dept);
        $this->db->bind(':p', $phone);
        $this->db->bind(':c', $color);
        $this->db->bind(':id', $userId);
        $ok = $this->db->execute();
        return [
            'success' => $ok,
            'message' => $ok ? 'Your profile has been updated successfully.' : 'Failed to update profile.',
            'user' => $this->getUserById($userId)
        ];
    }

    public function getDiagnostics(): array {
        return $this->db->getDiagnostics();
    }

    public function getPresets(): array {
        return [];
    }

    public function getActiveSession(): ?array {
        return null;
    }

    public function getAllSessions(int $limit = 15): array {
        return [];
    }

    public function getSessionEpisodes(int $sessionId, int $limit = 150): array {
        return [];
    }

    public function getSavedModels(): array {
        return [];
    }

    public function getLeaderboard(int $limit = 20): array {
        return [];
    }
}
