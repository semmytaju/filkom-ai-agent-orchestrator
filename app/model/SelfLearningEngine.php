<?php

class SelfLearningEngine {
    private Database $db;
    private static bool $schemaChecked = false;
    private static bool $autoMaintenanceRunning = false;
    private ?array $weightCache = null;
    
    private $llmSynthesizerFn = null;
    
    private $toolResolverFn = null;

    public function __construct(Database $db) {
        $this->db = $db;
    }

    public function setAutonomousLlmSynthesizer(callable $fn): void {
        $this->llmSynthesizerFn = $fn;
    }

    public function setAutonomousToolResolver(callable $fn): void {
        $this->toolResolverFn = $fn;
    }

    public function ensureSelfLearningSchema(?mysqli $conn = null): void {
        if (self::$schemaChecked) {
            return;
        }
        if (!$conn) {
            if (!$this->db->isConnected()) {
                return;
            }
            $conn = $this->db->getConnection();
        }
        if (!$conn) {
            return;
        }

        
        $verRes = @$conn->query("SELECT setting_value FROM `tbl_system_settings` WHERE setting_key = '_schema_sl_ver' LIMIT 1");
        if ($verRes && ($vr = $verRes->fetch_assoc()) && (string)$vr['setting_value'] === '353') {
            self::$schemaChecked = true;
            return;
        }

        if ($this->db->hasTable('tbl_va_conversations')) {
            $alterCols = [
                'user_satisfaction' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `follow_up_question`",
                'implicit_feedback' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `user_satisfaction`",
                'is_unanswered' => "TINYINT(1) NOT NULL DEFAULT 0 AFTER `implicit_feedback`",
                'failure_reason' => "VARCHAR(120) DEFAULT NULL AFTER `is_unanswered`",
                'intent_tag' => "VARCHAR(64) NOT NULL DEFAULT 'general' AFTER `failure_reason`",
                'flow_status' => "ENUM('resolved','continued','rephrased','stalled','abandoned') NOT NULL DEFAULT 'continued' AFTER `intent_tag`",
                'feedback_note' => "VARCHAR(255) DEFAULT NULL AFTER `flow_status`",
                'learned_item_id' => "INT(11) DEFAULT NULL AFTER `feedback_note`"
            ];
            foreach ($alterCols as $col => $def) {
                $chk = @$conn->query("SHOW COLUMNS FROM `tbl_va_conversations` LIKE '{$col}'");
                if ($chk && $chk->num_rows === 0) {
                    @$conn->query("ALTER TABLE `tbl_va_conversations` ADD COLUMN `{$col}` {$def}");
                }
            }
        }

        @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_learned_knowledge` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `pattern_code` VARCHAR(80) NOT NULL,
            `category` VARCHAR(64) NOT NULL DEFAULT 'Self-Learned',
            `trigger_phrases` TEXT NOT NULL,
            `keywords_json` TEXT DEFAULT NULL,
            `vector_json` TEXT DEFAULT NULL,
            `language` ENUM('id','en','both') NOT NULL DEFAULT 'id',
            `response_id` TEXT NOT NULL,
            `response_en` TEXT DEFAULT NULL,
            `source_type` ENUM('auto_synthesized','conversation_aligned','kb_alias_expanded','distilled_qa','correction_learned') NOT NULL DEFAULT 'auto_synthesized',
            `source_ref` VARCHAR(255) DEFAULT NULL,
            `confidence_score` DECIMAL(4,3) NOT NULL DEFAULT 0.720,
            `q_value` DECIMAL(5,4) NOT NULL DEFAULT 0.6800,
            `hit_count` INT(11) NOT NULL DEFAULT 0,
            `positive_count` INT(11) NOT NULL DEFAULT 0,
            `negative_count` INT(11) NOT NULL DEFAULT 0,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `auto_promoted_at` DATETIME DEFAULT NULL,
            `last_reinforced_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_pattern_code` (`pattern_code`),
            KEY `idx_active_q` (`is_active`, `q_value`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_knowledge_gaps` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `gap_signature` VARCHAR(64) NOT NULL,
            `sample_question` TEXT NOT NULL,
            `sample_variants_json` TEXT DEFAULT NULL,
            `language` VARCHAR(16) NOT NULL DEFAULT 'id',
            `intent_tag` VARCHAR(64) NOT NULL DEFAULT 'general',
            `failure_reason` VARCHAR(120) NOT NULL DEFAULT 'no_local_knowledge',
            `occurrence_count` INT(11) NOT NULL DEFAULT 1,
            `avg_eval_score` DECIMAL(4,2) NOT NULL DEFAULT 0.45,
            `negative_feedback_count` INT(11) NOT NULL DEFAULT 0,
            `rephrase_count` INT(11) NOT NULL DEFAULT 0,
            `stall_count` INT(11) NOT NULL DEFAULT 0,
            `status` ENUM('detected','auto_healed','reinforced','needs_rich_source') NOT NULL DEFAULT 'detected',
            `healing_strategy` VARCHAR(120) DEFAULT NULL,
            `resolved_learned_id` INT(11) DEFAULT NULL,
            `suggested_draft` TEXT DEFAULT NULL,
            `last_seen_at` DATETIME DEFAULT NULL,
            `healed_at` DATETIME DEFAULT NULL,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_gap_sig` (`gap_signature`),
            KEY `idx_gap_status` (`status`, `occurrence_count`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_conversation_flows` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `flow_key` VARCHAR(128) NOT NULL,
            `from_intent` VARCHAR(64) NOT NULL,
            `to_intent` VARCHAR(64) NOT NULL,
            `last_response_source` VARCHAR(64) NOT NULL DEFAULT 'Static KB',
            `total_transitions` INT(11) NOT NULL DEFAULT 1,
            `stall_count` INT(11) NOT NULL DEFAULT 0,
            `rephrase_loop_count` INT(11) NOT NULL DEFAULT 0,
            `abandon_count` INT(11) NOT NULL DEFAULT 0,
            `avg_satisfaction` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
            `auto_recovery_prompt_id` VARCHAR(255) DEFAULT NULL,
            `auto_recovery_prompt_en` VARCHAR(255) DEFAULT NULL,
            `last_updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_flow_key` (`flow_key`),
            KEY `idx_stall` (`stall_count`, `rephrase_loop_count`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_learning_weights` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `target_type` VARCHAR(32) NOT NULL,
            `target_key` VARCHAR(128) NOT NULL,
            `target_label` VARCHAR(180) DEFAULT NULL,
            `q_value` DECIMAL(5,4) NOT NULL DEFAULT 0.6500,
            `pull_count` INT(11) NOT NULL DEFAULT 1,
            `reward_sum` DECIMAL(8,3) NOT NULL DEFAULT 0.650,
            `positive_signals` INT(11) NOT NULL DEFAULT 0,
            `negative_signals` INT(11) NOT NULL DEFAULT 0,
            `last_reward` DECIMAL(5,3) NOT NULL DEFAULT 0.000,
            `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_type_key` (`target_type`, `target_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        @$conn->query("CREATE TABLE IF NOT EXISTS `tbl_self_eval_runs` (
            `id` INT(11) NOT NULL AUTO_INCREMENT,
            `trigger_mode` ENUM('auto_online','auto_cycle','manual_cycle') NOT NULL DEFAULT 'auto_cycle',
            `conversations_analyzed` INT(11) NOT NULL DEFAULT 0,
            `gaps_detected` INT(11) NOT NULL DEFAULT 0,
            `gaps_healed` INT(11) NOT NULL DEFAULT 0,
            `keywords_expanded` INT(11) NOT NULL DEFAULT 0,
            `flows_tuned` INT(11) NOT NULL DEFAULT 0,
            `avg_eval_before` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
            `avg_eval_after` DECIMAL(4,2) NOT NULL DEFAULT 0.00,
            `satisfaction_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `stall_rate` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
            `summary_note` TEXT DEFAULT NULL,
            `duration_ms` INT(11) NOT NULL DEFAULT 0,
            `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        if ($this->db->hasTable('tbl_learned_knowledge')) {
            @$conn->query("UPDATE `tbl_learned_knowledge` SET is_active = 0
                WHERE is_active = 1 AND source_type = 'conversation_aligned'
                  AND (source_ref LIKE '%via Static KB%' OR source_ref LIKE '%via Function Calling%'
                       OR trigger_phrases LIKE '%Katakan Hai%' OR trigger_phrases LIKE '%jam berapa saat ini%')");
        }

        if ($this->db->hasTable('tbl_system_settings')) {
            @$conn->query("INSERT IGNORE INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES
                ('auto_self_learning_enabled', '1'),
                ('auto_promote_knowledge', '1')");
            @$conn->query("INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('_schema_sl_ver', '353') ON DUPLICATE KEY UPDATE `setting_value` = '353'");
        }

        self::$schemaChecked = true;

        $runCheck = @$conn->query("SELECT COUNT(*) AS cnt FROM `tbl_self_eval_runs`");
        $convCheck = @$conn->query("SELECT COUNT(*) AS cnt FROM `tbl_va_conversations`");
        $hasRuns = ($runCheck && ($rr = $runCheck->fetch_assoc())) ? (int)$rr['cnt'] : 0;
        $hasConvs = ($convCheck && ($cr = $convCheck->fetch_assoc())) ? (int)$cr['cnt'] : 0;
        if ($hasRuns === 0 && $hasConvs > 0) {
            $this->runOptimizationCycle('auto_cycle');
        }
    }

    

    public function stemWord(string $word): string {
        $w = preg_replace('/[^a-z0-9]/', '', strtolower($word));
        if (strlen($w) <= 3) {
            return $w;
        }
        $exactKeep = [
            'skripsi', 'beasiswa', 'asrama', 'kuliah', 'unklab', 'filkom', 'krs', 'sks',
            'ips', 'ipk', 'wisuda', 'yudisium', 'chapel', 'moodle', 'siasat', 'alumni',
            'kantin', 'perpustakaan', 'pustaka', 'dosen', 'mahasiswa', 'fakultas',
            'jurusan', 'semester', 'uang', 'biaya', 'bayar', 'cicilan', 'chatbot',
            'reinforcement', 'learning', 'database', 'mysql', 'gemini', 'openai'
        ];
        if (in_array($w, $exactKeep, true)) {
            return $w;
        }

        $w = preg_replace('/(nya|ku|mu|lah|kah|tah)$/', '', $w);
        if (strlen($w) > 5) {
            $prefixes = ['meng', 'meny', 'memb', 'memp', 'mend', 'ment', 'mem', 'men', 'peng', 'peny', 'pem', 'pen', 'ber', 'bel', 'per', 'ter', 'ke', 'di', 'se', 'me'];
            foreach ($prefixes as $px) {
                if (strpos($w, $px) === 0 && (strlen($w) - strlen($px)) >= 3) {
                    $w = substr($w, strlen($px));
                    break;
                }
            }
        }
        if (strlen($w) > 5) {
            $suffixes = ['isasi', 'tion', 'ment', 'kan', 'ing', 'an', 'ed'];
            foreach ($suffixes as $sx) {
                $sLen = strlen($sx);
                if (substr($w, -$sLen) === $sx && (strlen($w) - $sLen) >= 3) {
                    $w = substr($w, 0, -$sLen);
                    break;
                }
            }
        }
        if (strlen($w) > 4 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') {
            if (!in_array($w, ['sks', 'ips', 'krs', 'uts', 'uas', 'lms', 'lpm'], true)) {
                $w = substr($w, 0, -1);
            }
        }
        return $w;
    }

    

    public function expandSynonyms(array $tokens): array {
        static $synMap = [
            'sks' => ['kredit', 'beban', 'studi', 'credit'],
            'ips' => ['indeks', 'prestasi', 'semester', 'gpa'],
            'ipk' => ['indeks', 'prestasi', 'kumulatif', 'cgpa', 'gpa'],
            'ukt' => ['uang', 'kuliah', 'biaya', 'bayar', 'spp', 'tuition'],
            'spp' => ['uang', 'kuliah', 'biaya', 'bayar', 'tuition'],
            'bayar' => ['pembayaran', 'uang', 'kuliah', 'keuangan', 'virtual', 'account', 'tuition', 'cicil'],
            'biaya' => ['uang', 'kuliah', 'pembayaran', 'keuangan', 'tarif', 'harga'],
            'tuition' => ['uang', 'kuliah', 'biaya', 'bayar'],
            'ta' => ['skripsi', 'tugas', 'akhir', 'thesis'],
            'skripsi' => ['tugas', 'akhir', 'sidang', 'proposal', 'thesis'],
            'thesis' => ['skripsi', 'tugas', 'akhir', 'proposal'],
            'perpus' => ['perpustakaan', 'pustaka', 'buku', 'library'],
            'perpustakaan' => ['perpus', 'pustaka', 'buku', 'library', 'opac'],
            'library' => ['perpustakaan', 'perpus', 'buku', 'pustaka'],
            'filkom' => ['komputer', 'informatika', 'sistem', 'informasi', 'ti'],
            'asrama' => ['dorm', 'dormitory', 'crystal', 'edelweiss', 'jasmine'],
            'dorm' => ['asrama', 'dormitory', 'kamar'],
            'beasiswa' => ['scholarship', 'kip', 'prestasi', 'bantuan', 'labor'],
            'scholarship' => ['beasiswa', 'kip', 'prestasi', 'bantuan'],
            'krs' => ['rencana', 'studi', 'ambil', 'matkul', 'enroll'],
            'absen' => ['kehadiran', 'hadir', 'bolos', 'attendance'],
            'hadir' => ['kehadiran', 'absen', 'attendance'],
            'wisuda' => ['lulus', 'kelulusan', 'yudisium', 'ijazah', 'graduation'],
            'lulus' => ['kelulusan', 'wisuda', 'yudisium', 'syarat', 'graduation'],
            'jam' => ['waktu', 'jadwal', 'buka', 'operasional', 'pukul', 'time', 'schedule'],
            'buka' => ['jam', 'operasional', 'layanan', 'jadwal', 'open'],
            'kapan' => ['jadwal', 'tanggal', 'waktu', 'when', 'schedule'],
            'dimana' => ['lokasi', 'alamat', 'gedung', 'tempat', 'where', 'location'],
            'lokasi' => ['dimana', 'alamat', 'gedung', 'peta', 'where'],
            'kontak' => ['nomor', 'telepon', 'email', 'hubungi', 'bantuan', 'layanan'],
            'daftar' => ['pendaftaran', 'admisi', 'registrasi', 'register', 'pmb'],
            'registrasi' => ['daftar', 'pendaftaran', 'admisi', 'pmb'],
            'rl' => ['reinforcement', 'learning', 'ppo', 'dqn', 'reward'],
            'sql' => ['query', 'database', 'mysql', 'tabel', 'select'],
            'query' => ['sql', 'database', 'mysql', 'cari'],
            'wifi' => ['internet', 'jaringan', 'network', 'hotspot'],
            'makan' => ['kafetaria', 'kantin', 'vegetarian', 'menu', 'makanan'],
            'kafetaria' => ['makan', 'kantin', 'vegetarian', 'menu'],
            'sakit' => ['klinik', 'medis', 'kesehatan', 'dokter', 'darurat'],
            'klinik' => ['medis', 'kesehatan', 'sakit', 'obat', 'darurat']
        ];

        $expanded = [];
        foreach ($tokens as $tok => $weight) {
            $expanded[$tok] = max($expanded[$tok] ?? 0.0, (float)$weight);
            if (isset($synMap[$tok])) {
                foreach ($synMap[$tok] as $syn) {
                    $expanded[$syn] = max($expanded[$syn] ?? 0.0, (float)$weight * 0.58);
                }
            }
        }
        return $expanded;
    }

    

    public function computeEnhancedVector(string $text, bool $withSynonyms = true): array {
        $lower = function_exists('mb_strtolower') ? mb_strtolower($text, 'UTF-8') : strtolower($text);
        $words = preg_split('/[^a-z0-9_]+/u', $lower, -1, PREG_SPLIT_NO_EMPTY);
        $stop = [
            'dan', 'yang', 'untuk', 'dengan', 'pada', 'dari', 'ini', 'itu', 'atau', 'dalam', 'adalah',
            'saya', 'aku', 'kamu', 'anda', 'kita', 'mereka', 'ada', 'bisa', 'mau', 'akan', 'sudah',
            'belum', 'juga', 'tapi', 'kalau', 'jika', 'karena', 'jadi', 'tolong', 'mohon', 'dong',
            'apa', 'apakah', 'bagaimana', 'gimana', 'mengapa', 'kenapa', 'siapa', 'sebutkan', 'jelaskan',
            'tentang', 'berapa', 'cara', 'nya', 'lah', 'kah', 'pun', 'ya', 'yah', 'nih', 'sih',
            'halo', 'hai', 'hello', 'hey', 'selamat', 'pagi', 'siang', 'sore', 'malam', 'terima', 'kasih', 'makasih',
            'the', 'and', 'for', 'with', 'from', 'this', 'that', 'are', 'was', 'were', 'what', 'who',
            'how', 'why', 'please', 'tell', 'about', 'can', 'could', 'would', 'should', 'explain', 'you', 'your', 'my'
        ];
        $rawFreq = [];
        foreach ($words as $w) {
            if (strlen($w) < 2 || in_array($w, $stop, true)) {
                continue;
            }
            $rawFreq[$w] = ($rawFreq[$w] ?? 0.0) + 1.0;
            $stem = $this->stemWord($w);
            if ($stem !== '' && strlen($stem) >= 2 && $stem !== $w && !in_array($stem, $stop, true)) {
                $rawFreq[$stem] = ($rawFreq[$stem] ?? 0.0) + 0.82;
            }
        }
        if (empty($rawFreq)) {
            foreach ($words as $w) {
                if (strlen($w) >= 2) {
                    $rawFreq[$w] = ($rawFreq[$w] ?? 0.0) + 0.5;
                }
            }
        }
        if ($withSynonyms && !empty($rawFreq)) {
            $rawFreq = $this->expandSynonyms($rawFreq);
        }
        arsort($rawFreq);
        $top = array_slice($rawFreq, 0, 40, true);
        $sumSq = 0.0;
        foreach ($top as $v) {
            $sumSq += $v * $v;
        }
        $norm = sqrt($sumSq) ?: 1.0;
        $vec = [];
        foreach ($top as $k => $v) {
            $vec[$k] = round($v / $norm, 4);
        }
        return $vec;
    }

    public function cosineVectors(array $a, array $b): float {
        if (empty($a) || empty($b)) {
            return 0.0;
        }
        $dot = 0.0;
        foreach ($a as $k => $v) {
            if (isset($b[$k])) {
                $dot += (float)$v * (float)$b[$k];
            }
        }
        return min(1.0, max(0.0, $dot));
    }

    

    public function extractDistinctKeywords(string $text, int $limit = 6): array {
        $vec = $this->computeEnhancedVector($text, false);
        $stop = [
            'unklabot', 'unklab', 'halo', 'hello', 'tanya', 'bertanya', 'maksud', 'soal', 'tolong', 'buat', 'buatkan',
            'apakah', 'kamu', 'anda', 'saya', 'aku', 'kami', 'kita', 'cara', 'agar', 'supaya', 'apa', 'bagaimana', 'gimana',
            'kenapa', 'mengapa', 'siapa', 'kapan', 'dimana', 'berapa', 'sekarang', 'saat', 'yang', 'dan', 'untuk',
            'prosedur', 'aturan', 'peraturan', 'informasi', 'ketentuan', 'pelaksanaan', 'implementasi', 'formulir',
            'pengisian', 'syarat', 'panduan', 'pedoman', 'dokumen', 'layanan', 'sistem', 'proses', 'lewat', 'melalui',
            'antara', 'terkait', 'seputar', 'tentang', 'universitas', 'klabat', 'mahasiswa'
        ];
        $out = [];
        foreach ($vec as $k => $_w) {
            if (strlen($k) >= 3 && !in_array($k, $stop, true) && !ctype_digit($k)) {
                $out[] = $k;
                if (count($out) >= $limit) {
                    break;
                }
            }
        }
        return $out;
    }

    public function fuzzyTokenSimilarity(string $query, string $target): float {
        $qTokens = $this->extractDistinctKeywords($query, 8);
        $tLower = strtolower($target);
        if (empty($qTokens) || $tLower === '') {
            return 0.0;
        }
        $tWords = preg_split('/[^a-z0-9_]+/u', $tLower, -1, PREG_SPLIT_NO_EMPTY);
        $tWordSet = array_values(array_unique(array_filter($tWords, static fn($w) => strlen($w) >= 3)));
        if (empty($tWordSet)) {
            return 0.0;
        }

        $matched = 0.0;
        foreach ($qTokens as $qt) {
            if (strpos($tLower, $qt) !== false) {
                $matched += 1.0;
                continue;
            }
            if (strlen($qt) >= 5) {
                foreach ($tWordSet as $tw) {
                    if (abs(strlen($qt) - strlen($tw)) <= 2 && levenshtein($qt, $tw) <= 1) {
                        $matched += 0.85;
                        break;
                    }
                }
            }
        }
        return min(1.0, $matched / count($qTokens));
    }

    public function classifyIntent(string $message, string $responseSource = ''): string {
        $m = function_exists('mb_strtolower') ? mb_strtolower($message, 'UTF-8') : strtolower($message);
        if ($responseSource === 'Profanity Guard') return 'profanity';
        if (preg_match('/\b(hitung|berapa hasil|kali|bagi|tambah|kurang|akar|persen|diskon|ppn|bmi|fpb|kpk|faktorial|calculate|convert|konversi)\b/u', $m)) return 'math_calc';
        if (preg_match('/\b(jam|tanggal|hari apa|besok|kemarin|waktu|semester aktif|kalender|date|time|yesterday|tomorrow)\b/u', $m)) return 'calendar_time';
        if (preg_match('/\b(sql|query|database|mysql|statistik|total chat|jumlah percakapan)\b/u', $m)) return 'sql_analytics';
        if (preg_match('/\b(siapa nama saya|nama saya siapa|tadi saya sebut|what did i say|apa yang barusan|ingat|memori|rangkum|ringkas|kesimpulan|remember|summarize)\b/u', $m)) return 'memory_recall';
        if (preg_match('/\b(siapa kamu|nama kamu|siapa namamu|tentang kamu|who are you|your name|fitur|bisa apa|kemampuan|siapa pembuatmu|pencipta kamu|siapa pencipta|apakah kamu manusia|kamu manusia|kamu robot|kamu ai|apakah kamu ai|are you human|are you a robot|are you an ai|di\s*mana\s+kamu\s+tinggal|kamu\s+tinggal\s+di\s*mana|di\s*mana\s+kamu\s+berada|di\s*mana\s+lokasi\s+kamu|lokasi\s+kamu|asal\s+kamu|where\s+do\s+you\s+live|where\s+are\s+you\s+located|where\s+are\s+you\s+based|who\s+created\s+you)\b/u', $m)) return 'identity';
        if (preg_match('/\b(biaya|uang kuliah|spp|ukt|bayar|pembayaran|cicilan|beasiswa|scholarship|kip|student labor|virtual account|keuangan)\b/u', $m)) return 'finance_scholarship';
        if (preg_match('/\b(sks|krs|ips|ipk|skripsi|tugas akhir|wisuda|yudisium|kuliah|absen|kehadiran|nilai|cum laude|dean|akademik|prodi|fakultas|filkom|matkul)\b/u', $m)) return 'academic_krs_sks';
        if (preg_match('/\b(asrama|dorm|crystal|edelweiss|jasmine|kafetaria|kantin|klinik|perpustakaan|perpus|library|wifi|parkir|chapel|gedung|lokasi|dimana|di mana)\b/u', $m)) return 'dorm_facility';
        if (preg_match('/\b(reinforcement learning|rl|dqn|ppo|rag|imitation|avatar|3d|webgl|tts|chatbot|llm|gemini|openai|ai)\b/u', $m)) return 'ai_research';
        $hasDirectiveTarget = (bool)preg_match('/\b(katakan|bilang|ucapkan|sampaikan|sebut|tulis|say|tell|greet)\b/u', $m)
            && (bool)preg_match('/\b(ke|kepada|sama|untuk|to|for)\s+(?:adik|kakak|teman|dia|mereka|brother|sister|friend)\b/u', $m);
        if (!$hasDirectiveTarget && str_word_count($m) <= 6 && preg_match('/\b(halo|hai|hello|hi|selamat pagi|selamat siang|selamat sore|selamat malam|apa kabar|terima kasih|makasih|thanks|bye|sampai jumpa)\b/u', $m)) return 'greeting';
        if (preg_match('/\b(lebih lanjut|jelaskan lagi|contohnya|maksudnya|bukan itu|salah|kurang|tell me more|what do you mean)\b/u', $m)) return 'clarification';
        if (strpos($responseSource, 'Fallback') !== false || $responseSource === 'Smart Contextual AI') return 'unanswered_fallback';
        return 'general_qa';
    }

    

    public function findLearnedMatch(string $query, string $lang = 'id', float $minScore = 0.25): ?array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_learned_knowledge')) {
            return null;
        }
        $intent = $this->classifyIntent($query);
        if (in_array($intent, ['greeting', 'identity', 'memory_recall', 'profanity', 'calendar_time', 'math_calc', 'sql_analytics'], true)) {
            return null;
        }
        $qClean = strtolower(trim($query));
        $qHasNegation = (bool)preg_match('/\b(?:tidak|tak|bukan|belum|jangan|tanpa|enggak|nggak|gak|not|no|never)\b/u', $qClean);
        $qVec = $this->computeEnhancedVector($query, true);
        if (empty($qVec)) {
            return null;
        }
        $this->db->prepare("SELECT * FROM tbl_learned_knowledge WHERE is_active = 1 ORDER BY q_value DESC, hit_count DESC LIMIT 250");
        $rows = $this->db->resultSet();
        if (empty($rows)) {
            return null;
        }

        $best = null;
        $bestScore = 0.0;
        $bestRawSim = 0.0;

        foreach ($rows as $row) {
            $qVal = (float)($row['q_value'] ?? 0.65);
            if ($qVal < 0.28 && (int)($row['negative_count'] ?? 0) >= 2) {
                continue;
            }
            $triggers = array_filter(array_map('trim', explode('|', strtolower((string)$row['trigger_phrases']))));
            if (empty($triggers)) {
                continue;
            }

            $fuzzy = 0.0;
            $cos = 0.0;
            $exactPhraseBoost = 0.0;
            $trigHasNegation = false;
            foreach ($triggers as $tp) {
                if (preg_match('/\b(?:tidak|tak|bukan|belum|jangan|tanpa|enggak|nggak|gak|not|no|never)\b/u', $tp)) {
                    $trigHasNegation = true;
                }
                $fuzzy = max($fuzzy, $this->fuzzyTokenSimilarity($qClean, $tp));
                $cos = max($cos, $this->cosineVectors($qVec, $this->computeEnhancedVector($tp, true)));
                if ($tp === $qClean) {
                    $exactPhraseBoost = 0.35;
                } elseif (strlen($tp) >= 6 && preg_match('/(?<![a-z0-9])' . preg_quote($tp, '/') . '(?![a-z0-9])/u', $qClean)) {
                    $exactPhraseBoost = max($exactPhraseBoost, 0.20);
                }
            }
            if ($qHasNegation && !$trigHasNegation) {
                continue;
            }

            $rawSim = min(1.0, ($cos * 0.68) + ($fuzzy * 0.32) + $exactPhraseBoost);
            $rlFactor = 0.72 + (0.48 * min(1.0, max(0.0, $qVal)));
            $finalScore = $rawSim * $rlFactor;

            $effMin = max(0.54, $minScore);
            if ($fuzzy >= 0.65 && $cos >= 0.35 && $rawSim >= $effMin && $finalScore > $bestScore) {
                $bestScore = $finalScore;
                $bestRawSim = $rawSim;
                $best = $row;
            }
        }

        if (!$best) {
            return null;
        }

        $replyText = ($lang === 'en' && trim((string)($best['response_en'] ?? '')) !== '')
            ? (string)$best['response_en']
            : (string)$best['response_id'];
        if (trim($replyText) === '') {
            return null;
        }

        $this->db->prepare("UPDATE tbl_learned_knowledge SET hit_count = hit_count + 1 WHERE id = :id");
        $this->db->bind(':id', (int)$best['id']);
        $this->db->execute();

        return [
            'id' => (int)$best['id'],
            'pattern_code' => (string)$best['pattern_code'],
            'category' => (string)$best['category'],
            'source_type' => (string)$best['source_type'],
            'source_ref' => (string)($best['source_ref'] ?? ''),
            'q_value' => round((float)$best['q_value'], 3),
            'score' => round($bestRawSim, 3),
            'weighted_score' => round($bestScore, 3),
            'reply' => $replyText
        ];
    }

    

    public function getTargetWeightMultiplier(string $targetType, string $targetKey): float {
        if ($this->weightCache === null) {
            $this->weightCache = [];
            if ($this->db->isConnected()) {
                $conn = $this->db->getConnection();
                $res = $conn ? @$conn->query("SELECT target_type, target_key, q_value FROM tbl_learning_weights") : null;
                if ($res) {
                    while ($row = $res->fetch_assoc()) {
                        $ck = (string)$row['target_type'] . '|' . (string)$row['target_key'];
                        $this->weightCache[$ck] = (float)($row['q_value'] ?? 0.65);
                    }
                }
            }
        }
        $k = $targetType . '|' . substr($targetKey, 0, 128);
        if (!isset($this->weightCache[$k])) {
            return 1.0;
        }
        $q = $this->weightCache[$k];
        return round(max(0.60, min(1.30, 0.72 + (0.45 * $q))), 3);
    }

    

    public function applyReinforcementUpdate(string $targetType, string $targetKey, string $label, float $reward, bool $isExplicitSignal = false): void {
        if (!$this->db->isConnected() || trim($targetKey) === '') {
            return;
        }
        $this->weightCache = null;
        $key = substr(trim($targetKey), 0, 128);
        $lbl = substr(trim($label ?: $targetKey), 0, 180);
        $r = max(0.0, min(1.0, ($reward + 1.0) / 2.0)); 

        $this->db->prepare("SELECT id, q_value, pull_count FROM tbl_learning_weights WHERE target_type = :tt AND target_key = :tk LIMIT 1");
        $this->db->bind(':tt', $targetType);
        $this->db->bind(':tk', $key);
        $row = $this->db->single();

        $posInc = ($reward > 0.05) ? 1 : 0;
        $negInc = ($reward < -0.05) ? 1 : 0;
        if ($row) {
            $pulls = (int)$row['pull_count'] + 1;
            $alpha = $isExplicitSignal ? max(0.18, min(0.35, 2.0 / ($pulls + 2))) : max(0.08, min(0.22, 1.5 / ($pulls + 3)));
            $oldQ = (float)$row['q_value'];
            $newQ = round(max(0.05, min(0.99, $oldQ + $alpha * ($r - $oldQ))), 4);
            $this->db->prepare("UPDATE tbl_learning_weights
                SET q_value = :qv, pull_count = pull_count + 1, reward_sum = reward_sum + :rs,
                    positive_signals = positive_signals + :ps, negative_signals = negative_signals + :ns, last_reward = :lr
                WHERE id = :id");
            $this->db->bind(':qv', $newQ);
            $this->db->bind(':rs', round($reward, 3));
            $this->db->bind(':ps', $posInc);
            $this->db->bind(':ns', $negInc);
            $this->db->bind(':lr', round($reward, 3));
            $this->db->bind(':id', (int)$row['id']);
            $this->db->execute();
        } else {
            $initQ = round(max(0.15, min(0.95, 0.65 + 0.25 * $reward)), 4);
            $this->db->prepare("INSERT INTO tbl_learning_weights
                (target_type, target_key, target_label, q_value, pull_count, reward_sum, positive_signals, negative_signals, last_reward)
                VALUES (:tt, :tk, :tl, :qv, 1, :rs, :ps, :ns, :lr)");
            $this->db->bind(':tt', $targetType);
            $this->db->bind(':tk', $key);
            $this->db->bind(':tl', $lbl);
            $this->db->bind(':qv', $initQ);
            $this->db->bind(':rs', round($reward, 3));
            $this->db->bind(':ps', $posInc);
            $this->db->bind(':ns', $negInc);
            $this->db->bind(':lr', round($reward, 3));
            $this->db->execute();
        }
    }

    

    public function evaluateTurn(array $ctx): array {
        $userMsg = trim((string)($ctx['user_message'] ?? ''));
        $reply = trim((string)($ctx['reply'] ?? ''));
        $source = (string)($ctx['response_source'] ?? 'Static KB');
        $ragScore = (float)($ctx['rag_score'] ?? 0.0);
        $learnedScore = (float)($ctx['learned_score'] ?? 0.0);
        $kbLen = (int)($ctx['kb_match_len'] ?? 0);
        $latencyMs = (int)($ctx['latency_ms'] ?? 20);
        $lang = (string)($ctx['language'] ?? 'id');

        $intent = $this->classifyIntent($userMsg, $source);
        $replyLower = function_exists('mb_strtolower') ? mb_strtolower($reply, 'UTF-8') : strtolower($reply);
        $msgLower = function_exists('mb_strtolower') ? mb_strtolower($userMsg, 'UTF-8') : strtolower($userMsg);

        $isUnanswered = false;
        $failureReason = null;

        if ($source === 'Smart Contextual AI' || strpos($source, 'Fallback') !== false) {
            $isUnanswered = true;
            $failureReason = 'no_local_knowledge';
        } elseif ($source === 'Context Awareness' && strlen($reply) < 160) {
            $isUnanswered = true;
            $failureReason = 'context_ambiguous';
        }

        $uncertaintyMarkers = [
            'tidak menemukan', 'belum menemukan', 'belum memiliki informasi', 'maaf saya belum',
            'pastikan api key', 'tidak dapat menjawab', 'belum tersedia di sistem', 'kurang jelas',
            'masih terbatas', 'cannot find', 'don\'t have information', 'verify your active ai api key',
            'unable to verify', 'not available in my knowledge'
        ];
        foreach ($uncertaintyMarkers as $um) {
            if (strpos($replyLower, $um) !== false) {
                $isUnanswered = true;
                $failureReason = $failureReason ?: (strpos($um, 'api key') !== false ? 'llm_key_unavailable' : 'uncertain_response');
                break;
            }
        }

        
        if (!$isUnanswered && ($source === 'Static KB' || strpos($source, 'Self-Learned') !== false)) {
            if (preg_match_all('/\b(?:tidak|tak|bukan|belum|jangan|tanpa|enggak|nggak|gak|not|no|never)\s+(?:[a-z0-9\'\-]+\s+){0,2}([a-z]{4,})/u', $msgLower, $negMatches)) {
                $replyHasAck = (bool)preg_match('/\b(tidak|bukan|belum|dicatat|catat|noted|ba[ik]+|not|no)\b/u', $replyLower);
                foreach ($negMatches[1] as $negTerm) {
                    if (preg_match('/\b' . preg_quote($negTerm, '/') . '\b/u', $replyLower) && !$replyHasAck) {
                        $isUnanswered = true;
                        $failureReason = 'negation_context_mismatch';
                        break;
                    }
                }
            }
        }

        
        $qVec = $this->computeEnhancedVector($userMsg, true);
        $aVec = $this->computeEnhancedVector($reply, true);
        $overlap = $this->cosineVectors($qVec, $aVec);

        $baseScore = 0.88;
        if ($source === 'Function Calling' || $source === 'SQL Assistant') {
            $baseScore = 0.96;
        } elseif (strpos($source, 'Self-Learned') !== false) {
            $baseScore = 0.78 + min(0.18, $learnedScore * 0.22);
        } elseif (strpos($source, 'LLM + RAG') !== false) {
            $baseScore = 0.84 + min(0.14, $ragScore * 0.25);
        } elseif ($source === 'RAG Document') {
            $baseScore = 0.72 + min(0.22, $ragScore * 0.38);
        } elseif (strpos($source, 'LLM') !== false) {
            $baseScore = 0.82;
        } elseif ($source === 'Static KB') {
            $baseScore = ($kbLen >= 6) ? 0.90 : 0.80;
        } elseif ($source === 'Long-Term Memory' || $source === 'Conversation Summary') {
            $baseScore = 0.91;
        } elseif ($source === 'Profanity Guard') {
            $baseScore = 0.92;
        } elseif ($isUnanswered) {
            $baseScore = 0.34;
        }

        $hasDirectiveWords = (bool)preg_match('/\b(katakan|bilang|ucapkan|sampaikan|adik|kakak|teman|say|tell)\b/u', $msgLower);
        $isTransactional = in_array($intent, ['greeting', 'identity', 'math_calc', 'calendar_time', 'profanity', 'memory_recall'], true)
            && !$hasDirectiveWords
            && !($intent === 'greeting' && str_word_count($msgLower) >= 4);
        $overlapAdj = 0.0;
        if (!$isTransactional && !$isUnanswered) {
            if ($overlap >= 0.30) {
                $overlapAdj = 0.06;
            } elseif ($overlap >= 0.14) {
                $overlapAdj = 0.02;
            } elseif (
                str_word_count($msgLower) >= 4
                && strpos($source, 'LLM') === false
                && $source !== 'Function Calling'
                && !($source === 'Static KB' && $kbLen >= 11)
            ) {
                $overlapAdj = -0.18;
                if ($overlap < 0.08 || ($ragScore < 0.22 && $kbLen < 6 && $learnedScore < 0.30)) {
                    $isUnanswered = true;
                    $failureReason = $failureReason ?: 'low_semantic_overlap';
                }
            }
        }

        $lengthPenalty = (strlen($reply) < 22 && !$isTransactional) ? -0.12 : 0.0;
        $latencyPenalty = ($latencyMs > 8500) ? -0.04 : (($latencyMs > 5000) ? -0.02 : 0.0);

        $evalScore = $baseScore + $overlapAdj + $lengthPenalty + $latencyPenalty;
        if ($isUnanswered) {
            $evalScore = min(0.49, max(0.22, $evalScore));
        } else {
            $evalScore = min(0.99, max(0.52, $evalScore));
        }

        $flowStatus = $isUnanswered ? 'stalled' : 'continued';

        return [
            'eval_score' => round($evalScore, 2),
            'is_unanswered' => $isUnanswered ? 1 : 0,
            'failure_reason' => $failureReason,
            'intent_tag' => $intent,
            'flow_status' => $flowStatus,
            'semantic_overlap' => round($overlap, 3)
        ];
    }

    

    public function getAdaptiveFollowUp(string $intentTag, string $userMsg, string $lang, bool $isUnanswered): string {
        if ($this->db->isConnected() && $this->db->hasTable('tbl_conversation_flows')) {
            $col = $lang === 'en' ? 'auto_recovery_prompt_en' : 'auto_recovery_prompt_id';
            $this->db->prepare("SELECT {$col} AS prompt_txt FROM tbl_conversation_flows WHERE from_intent = :fi AND {$col} IS NOT NULL AND {$col} != '' ORDER BY stall_count DESC, id DESC LIMIT 1");
            $this->db->bind(':fi', $intentTag);
            $row = $this->db->single();
            if ($row && trim((string)($row['prompt_txt'] ?? '')) !== '') {
                return trim((string)$row['prompt_txt']);
            }
        }

        if ($isUnanswered) {
            if ($lang === 'en') {
                return "Could you add a keyword (e.g., UNKLAB SKS, scholarship, dorm, or FILKOM AI)?";
            }
            return "Bisa sebutkan kata kunci spesifik (misalnya KRS/SKS, beasiswa, asrama, atau jadwal UNKLAB)?";
        }

        $defaultsId = [
            'academic_krs_sks' => 'Ingin saya cek syarat kelulusan, IPS/SKS, atau panduan KRS lainnya?',
            'finance_scholarship' => 'Apakah Anda ingin melihat syarat beasiswa prestasi, KIP-K, atau Student Labor?',
            'dorm_facility' => 'Mau tahu jadwal layanan kampus, asrama, atau lokasi gedung UNKLAB lainnya?',
            'ai_research' => 'Apakah Anda ingin mencoba simulasi algoritma RL, RAG dokumen, atau topik riset AI?',
            'math_calc' => 'Ada perhitungan angka atau konversi satuan lain yang perlu saya bantu?',
            'calendar_time' => 'Ingin memeriksa tanggal penting atau kalender akademik semester aktif?',
            'sql_analytics' => 'Ingin saya tampilkan statistik percakapan, RAG, atau contoh query SQL lainnya?',
            'greeting' => 'Ada informasi akademik UNKLAB atau materi riset AI yang ingin Anda tanyakan hari ini?'
        ];
        $defaultsEn = [
            'academic_krs_sks' => 'Would you like to check credit limits, attendance rules, or graduation criteria next?',
            'finance_scholarship' => 'Would you like details on academic scholarships, Student Labor, or tuition VA payment?',
            'dorm_facility' => 'Would you like to check library hours, dormitory policies, or campus services?',
            'ai_research' => 'Would you like to explore how DQN, PPO rewards, or hybrid RAG works here?',
            'math_calc' => 'Is there another calculation or unit conversion I can solve for you?',
            'calendar_time' => 'Would you like to check another date or current semester calendar?',
            'sql_analytics' => 'Would you like me to inspect other database metrics or safe SQL templates?',
            'greeting' => 'What UNKLAB academic guide or AI topic can I help you with today?'
        ];
        return $lang === 'en'
            ? ($defaultsEn[$intentTag] ?? 'Would you like to explore a related UNKLAB document or calculation?')
            : ($defaultsId[$intentTag] ?? 'Ada topik akademik UNKLAB atau dokumen lain yang ingin kita bahas selanjutnya?');
    }

    

    public function onTurnCompleted(array $turn): void {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_va_conversations')) {
            return;
        }
        $settings = $this->getSettingsMap();
        if (($settings['auto_self_learning_enabled'] ?? '1') !== '1') {
            return;
        }

        $convId = (int)($turn['conversation_id'] ?? 0);
        $sessionCode = (string)($turn['session_code'] ?? '');
        $userMsg = (string)($turn['user_message'] ?? '');
        $reply = (string)($turn['reply'] ?? '');
        $lang = (string)($turn['language'] ?? 'id');
        $source = (string)($turn['response_source'] ?? 'Static KB');
        $intent = (string)($turn['intent_tag'] ?? 'general_qa');
        $evalScore = (float)($turn['eval_score'] ?? 0.85);
        $isUnanswered = ((int)($turn['is_unanswered'] ?? 0)) === 1;
        $failureReason = $turn['failure_reason'] ?? null;
        $targetKey = (string)($turn['target_key'] ?? $source);
        $targetType = (string)($turn['target_type'] ?? 'source');
        $learnedId = (int)($turn['learned_item_id'] ?? 0);

        
        $prevRow = null;
        if ($convId > 0 && $sessionCode !== '') {
            $this->db->prepare("SELECT id, user_message, assistant_response, intent_tag, response_source, is_unanswered, ai_eval_score, learned_item_id, rag_reference
                FROM tbl_va_conversations
                WHERE session_code = :sc AND id < :cid AND created_at >= (NOW() - INTERVAL 25 MINUTE)
                ORDER BY id DESC LIMIT 1");
            $this->db->bind(':sc', $sessionCode);
            $this->db->bind(':cid', $convId);
            $prevRow = $this->db->single();
        }

        $fromIntent = $prevRow ? (string)($prevRow['intent_tag'] ?? 'session_start') : 'session_start';
        $isRephraseOfPrev = false;
        $isImplicitPositivePrev = false;

        if ($prevRow) {
            $prevQ = (string)$prevRow['user_message'];
            $currLower = strtolower($userMsg);
            $negCue = preg_match('/\b(bukan itu|salah|maksud saya|yang benar|tidak menjawab|ngaco|ulangi|jelaskan ulang|wrong|not what i|i meant|try again)\b/u', $currLower) === 1;
            $posCue = preg_match('/\b(terima kasih|makasih|oke jelas|paham|mantap|bagus|membantu|thank you|thanks|got it|helpful|clear)\b/u', $currLower) === 1;

            $simPrev = $this->cosineVectors(
                $this->computeEnhancedVector($prevQ, true),
                $this->computeEnhancedVector($userMsg, true)
            );

            if ($negCue || ($simPrev >= 0.44 && $prevRow['intent_tag'] !== 'greeting' && $prevRow['intent_tag'] !== 'math_calc' && $prevRow['intent_tag'] !== 'calendar_time')) {
                $isRephraseOfPrev = true;
                $this->db->prepare("UPDATE tbl_va_conversations SET implicit_feedback = -1, flow_status = 'rephrased' WHERE id = :pid");
                $this->db->bind(':pid', (int)$prevRow['id']);
                $this->db->execute();

                $this->applyReinforcementUpdate('source', (string)$prevRow['response_source'], (string)$prevRow['response_source'], -0.35, false);
                if (!empty($prevRow['learned_item_id'])) {
                    $this->updateLearnedItemReward((int)$prevRow['learned_item_id'], -0.35, false);
                }

                
                if (!$isUnanswered && $evalScore >= 0.82 && $this->isEligibleForPairwiseLearning($source, $intent)) {
                    $this->learnFromPairwiseRecovery(
                        (string)$prevRow['user_message'],
                        $userMsg,
                        $reply,
                        $lang,
                        $source . ($turn['rag_reference'] ? ' (' . $turn['rag_reference'] . ')' : '')
                    );
                }
            } elseif ($posCue) {
                $isImplicitPositivePrev = true;
                $this->db->prepare("UPDATE tbl_va_conversations SET implicit_feedback = 1, flow_status = 'resolved' WHERE id = :pid");
                $this->db->bind(':pid', (int)$prevRow['id']);
                $this->db->execute();

                $this->applyReinforcementUpdate('source', (string)$prevRow['response_source'], (string)$prevRow['response_source'], 0.35, false);
                if (!empty($prevRow['learned_item_id'])) {
                    $this->updateLearnedItemReward((int)$prevRow['learned_item_id'], 0.35, false);
                }
            } elseif ((int)$prevRow['is_unanswered'] === 0 && (float)$prevRow['ai_eval_score'] >= 0.82) {
                $this->db->prepare("UPDATE tbl_va_conversations SET implicit_feedback = CASE WHEN implicit_feedback = 0 THEN 1 ELSE implicit_feedback END, flow_status = 'resolved' WHERE id = :pid");
                $this->db->bind(':pid', (int)$prevRow['id']);
                $this->db->execute();
                $this->applyReinforcementUpdate('source', (string)$prevRow['response_source'], (string)$prevRow['response_source'], 0.14, false);
            }
        }

        
        $turnReward = $isUnanswered ? -0.45 : (($evalScore >= 0.88) ? 0.25 : 0.08);
        $this->applyReinforcementUpdate($targetType, $targetKey, $source, $turnReward, false);
        if ($targetType !== 'source') {
            $this->applyReinforcementUpdate('source', $source, $source, $turnReward, false);
        }
        if ($learnedId > 0) {
            $this->updateLearnedItemReward($learnedId, $turnReward, false);
        }

        
        $this->recordFlowTransition($fromIntent, $intent, $source, $isUnanswered, $isRephraseOfPrev, $isImplicitPositivePrev ? 1.0 : ($isUnanswered ? -1.0 : 0.2));

        
        if ($isUnanswered || $evalScore < 0.62) {
            $gapId = $this->registerKnowledgeGap($userMsg, $lang, $intent, (string)($failureReason ?: 'no_local_knowledge'), $evalScore, $isRephraseOfPrev ? 1 : 0);
            if ($gapId > 0 && ($settings['auto_promote_knowledge'] ?? '1') === '1') {
                $this->healSingleGap($gapId);
            }
        }

        
        if ($convId > 0 && ($convId % 3) === 0) {
            $this->runOptimizationCycle('auto_cycle');
        } else {
            $this->runAutonomousMaintenanceIfDue(45, 2);
        }
    }

    private function isEligibleForPairwiseLearning(string $source, string $intent): bool {
        if (in_array($intent, ['greeting', 'identity', 'memory_recall', 'profanity', 'calendar_time', 'math_calc', 'sql_analytics'], true)) {
            return false;
        }
        return !in_array($source, ['Static KB', 'Function Calling', 'SQL Assistant', 'Long-Term Memory', 'Profanity Guard', 'Conversation Summary'], true);
    }

    

    private function learnFromPairwiseRecovery(string $failedQuestion, string $succeededQuestion, string $winningReply, string $lang, string $sourceRef): ?int {
        if (!$this->db->hasTable('tbl_learned_knowledge')) {
            return null;
        }
        $cleanFailed = trim($failedQuestion);
        $cleanSucc = trim($succeededQuestion);
        if (strlen($cleanFailed) < 6 || strlen(trim($winningReply)) < 16) {
            return null;
        }
        $failIntent = $this->classifyIntent($cleanFailed);
        $succIntent = $this->classifyIntent($cleanSucc);
        if (in_array($failIntent, ['greeting', 'memory_recall', 'profanity', 'calendar_time', 'math_calc', 'sql_analytics'], true) ||
            in_array($succIntent, ['greeting', 'identity', 'memory_recall', 'profanity', 'calendar_time', 'math_calc', 'sql_analytics'], true) ||
            stripos($sourceRef, 'Static KB') !== false ||
            stripos($sourceRef, 'Function Calling') !== false ||
            stripos($sourceRef, 'SQL Assistant') !== false) {
            return null;
        }
        $sig = substr(md5(strtolower($cleanFailed)), 0, 14);
        $patternCode = 'ALIGN_' . $sig;
        $kw = $this->extractDistinctKeywords($cleanFailed . ' ' . $cleanSucc, 8);
        $vec = $this->computeEnhancedVector($cleanFailed . ' ' . $cleanSucc, true);

        $triggerParts = array_values(array_unique(array_filter([$cleanFailed, $cleanSucc])));
        $triggerPhrases = implode('|', $triggerParts);
        $respId = $winningReply;
        $respEn = ($lang === 'en') ? $winningReply : '';

        $this->db->prepare("SELECT id, trigger_phrases FROM tbl_learned_knowledge WHERE pattern_code = :pc LIMIT 1");
        $this->db->bind(':pc', $patternCode);
        $exists = $this->db->single();
        if ($exists) {
            $oldParts = array_filter(array_map('trim', explode('|', (string)$exists['trigger_phrases'])));
            $mergedList = array_values(array_unique(array_merge($oldParts, $triggerParts)));
            $mergedTriggers = substr(implode('|', $mergedList), 0, 900);
            $this->db->prepare("UPDATE tbl_learned_knowledge SET trigger_phrases = :tp, q_value = LEAST(0.96, q_value + 0.03), last_reinforced_at = NOW() WHERE id = :id");
            $this->db->bind(':tp', $mergedTriggers);
            $this->db->bind(':id', (int)$exists['id']);
            $this->db->execute();
            return (int)$exists['id'];
        }

        $this->db->prepare("INSERT INTO tbl_learned_knowledge
            (pattern_code, category, trigger_phrases, keywords_json, vector_json, language, response_id, response_en, source_type, source_ref, confidence_score, q_value, is_active, auto_promoted_at, last_reinforced_at)
            VALUES (:pc, 'Self-Aligned', :tp, :kj, :vj, :lg, :ri, :re, 'conversation_aligned', :sr, 0.840, 0.7400, 1, NOW(), NOW())");
        $this->db->bind(':pc', $patternCode);
        $this->db->bind(':tp', substr($triggerPhrases, 0, 900));
        $this->db->bind(':kj', json_encode($kw, JSON_UNESCAPED_UNICODE));
        $this->db->bind(':vj', json_encode($vec, JSON_UNESCAPED_UNICODE));
        $this->db->bind(':lg', $lang === 'en' ? 'en' : 'id');
        $this->db->bind(':ri', $respId);
        $this->db->bind(':re', $respEn);
        $this->db->bind(':sr', substr('Pairwise recovery dari "' . $succeededQuestion . '" via ' . $sourceRef, 0, 250));
        $this->db->execute();
        return (int)$this->db->lastInsertId() ?: null;
    }

    private function updateLearnedItemReward(int $learnedId, float $reward, bool $explicit): void {
        if ($learnedId <= 0 || !$this->db->hasTable('tbl_learned_knowledge')) {
            return;
        }
        $this->db->prepare("SELECT id, q_value, positive_count, negative_count FROM tbl_learned_knowledge WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $learnedId);
        $row = $this->db->single();
        if (!$row) {
            return;
        }
        $target = ($reward >= 0) ? 1.0 : 0.0;
        $alpha = $explicit ? 0.28 : 0.12;
        $newQ = round(max(0.05, min(0.99, (float)$row['q_value'] + $alpha * ($target - (float)$row['q_value']))), 4);
        $posInc = ($reward > 0.05) ? 1 : 0;
        $negInc = ($reward < -0.05) ? 1 : 0;
        $newNeg = (int)$row['negative_count'] + $negInc;
        $active = ($newQ < 0.26 && $newNeg >= 3) ? 0 : 1;

        $this->db->prepare("UPDATE tbl_learned_knowledge
            SET q_value = :qv, positive_count = positive_count + :pi, negative_count = negative_count + :ni, is_active = :ia, last_reinforced_at = NOW()
            WHERE id = :id");
        $this->db->bind(':qv', $newQ);
        $this->db->bind(':pi', $posInc);
        $this->db->bind(':ni', $negInc);
        $this->db->bind(':ia', $active);
        $this->db->bind(':id', $learnedId);
        $this->db->execute();
    }

    public function recordFlowTransition(string $fromIntent, string $toIntent, string $source, bool $isStall, bool $isRephrase, float $satSignal): void {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_conversation_flows')) {
            return;
        }
        $from = substr(preg_replace('/[^a-z0-9_]/', '_', strtolower($fromIntent)), 0, 60) ?: 'session_start';
        $to = substr(preg_replace('/[^a-z0-9_]/', '_', strtolower($toIntent)), 0, 60) ?: 'general_qa';
        $flowKey = substr("{$from}->{$to}", 0, 128);

        $this->db->prepare("SELECT id, total_transitions, avg_satisfaction FROM tbl_conversation_flows WHERE flow_key = :fk LIMIT 1");
        $this->db->bind(':fk', $flowKey);
        $row = $this->db->single();

        $stallInc = $isStall ? 1 : 0;
        $repInc = $isRephrase ? 1 : 0;
        if ($row) {
            $cnt = (int)$row['total_transitions'] + 1;
            $oldSat = (float)$row['avg_satisfaction'];
            $newSat = round((($oldSat * ($cnt - 1)) + $satSignal) / $cnt, 2);
            $this->db->prepare("UPDATE tbl_conversation_flows
                SET last_response_source = :src, total_transitions = total_transitions + 1,
                    stall_count = stall_count + :sc, rephrase_loop_count = rephrase_loop_count + :rc, avg_satisfaction = :sat
                WHERE id = :id");
            $this->db->bind(':src', substr($source, 0, 64));
            $this->db->bind(':sc', $stallInc);
            $this->db->bind(':rc', $repInc);
            $this->db->bind(':sat', $newSat);
            $this->db->bind(':id', (int)$row['id']);
            $this->db->execute();
        } else {
            $this->db->prepare("INSERT INTO tbl_conversation_flows
                (flow_key, from_intent, to_intent, last_response_source, total_transitions, stall_count, rephrase_loop_count, avg_satisfaction)
                VALUES (:fk, :fi, :ti, :src, 1, :sc, :rc, :sat)");
            $this->db->bind(':fk', $flowKey);
            $this->db->bind(':fi', $from);
            $this->db->bind(':ti', $to);
            $this->db->bind(':src', substr($source, 0, 64));
            $this->db->bind(':sc', $stallInc);
            $this->db->bind(':rc', $repInc);
            $this->db->bind(':sat', round($satSignal, 2));
            $this->db->execute();
        }
    }

    public function registerKnowledgeGap(string $question, string $lang, string $intent, string $reason, float $evalScore, int $rephraseInc = 0, int $negInc = 0, int $stallInc = 0): int {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_knowledge_gaps')) {
            return 0;
        }
        $cleanQ = trim(preg_replace('/\s+/', ' ', $question));
        if ($cleanQ === '' || strlen($cleanQ) < 3) {
            return 0;
        }
        $kw = $this->extractDistinctKeywords($cleanQ, 5);
        sort($kw);
        $sigBasis = !empty($kw) ? implode('_', $kw) : strtolower(substr($cleanQ, 0, 60));
        $sig = 'GAP_' . substr(md5($lang . '|' . $sigBasis), 0, 16);

        $this->db->prepare("SELECT * FROM tbl_knowledge_gaps WHERE gap_signature = :gs LIMIT 1");
        $this->db->bind(':gs', $sig);
        $row = $this->db->single();

        if ($row) {
            $variants = json_decode((string)($row['sample_variants_json'] ?? '[]'), true);
            if (!is_array($variants)) $variants = [];
            if (!in_array($cleanQ, $variants, true) && count($variants) < 8) {
                $variants[] = $cleanQ;
            }
            $occ = (int)$row['occurrence_count'] + 1;
            $newAvg = round((((float)$row['avg_eval_score'] * ($occ - 1)) + $evalScore) / $occ, 2);
            $this->db->prepare("UPDATE tbl_knowledge_gaps
                SET sample_variants_json = :sv, failure_reason = :fr, occurrence_count = occurrence_count + 1,
                    avg_eval_score = :av, negative_feedback_count = negative_feedback_count + :nf,
                    rephrase_count = rephrase_count + :rp, stall_count = stall_count + :st,
                    last_seen_at = NOW()
                WHERE id = :id");
            $this->db->bind(':sv', json_encode($variants, JSON_UNESCAPED_UNICODE));
            $this->db->bind(':fr', substr($reason, 0, 120));
            $this->db->bind(':av', $newAvg);
            $this->db->bind(':nf', $negInc);
            $this->db->bind(':rp', $rephraseInc);
            $this->db->bind(':st', $stallInc);
            $this->db->bind(':id', (int)$row['id']);
            $this->db->execute();
            return (int)$row['id'];
        }

        $variants = [$cleanQ];
        $this->db->prepare("INSERT INTO tbl_knowledge_gaps
            (gap_signature, sample_question, sample_variants_json, language, intent_tag, failure_reason, occurrence_count, avg_eval_score, negative_feedback_count, rephrase_count, stall_count, status, last_seen_at)
            VALUES (:gs, :sq, :sv, :lg, :it, :fr, 1, :av, :nf, :rp, :st, 'detected', NOW())");
        $this->db->bind(':gs', $sig);
        $this->db->bind(':sq', substr($cleanQ, 0, 500));
        $this->db->bind(':sv', json_encode($variants, JSON_UNESCAPED_UNICODE));
        $this->db->bind(':lg', $lang === 'en' ? 'en' : 'id');
        $this->db->bind(':it', substr($intent, 0, 64));
        $this->db->bind(':fr', substr($reason, 0, 120));
        $this->db->bind(':av', round($evalScore, 2));
        $this->db->bind(':nf', $negInc);
        $this->db->bind(':rp', $rephraseInc);
        $this->db->bind(':st', $stallInc);
        $this->db->execute();
        return (int)$this->db->lastInsertId();
    }

    

    public function recordUserFeedback(int $conversationId, string $sessionCode, int $rating, string $note = ''): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_va_conversations') || $conversationId <= 0) {
            return ['success' => false, 'message' => 'Conversation not found for rating.'];
        }
        $normRating = $rating >= 1 ? 1 : -1;
        $cleanNote = substr(trim($note), 0, 240);

        $this->db->prepare("SELECT id, session_code, user_message, assistant_response, language, response_source, rag_reference, intent_tag, ai_eval_score, learned_item_id, user_satisfaction
            FROM tbl_va_conversations WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $conversationId);
        $conv = $this->db->single();
        if (!$conv) {
            return ['success' => false, 'message' => 'Conversation log not found.'];
        }

        $nextFlowStatus = ($normRating === 1) ? 'resolved' : 'stalled';
        $this->db->prepare("UPDATE tbl_va_conversations
            SET user_satisfaction = :us, flow_status = :fs, feedback_note = :fn
            WHERE id = :id");
        $this->db->bind(':us', $normRating);
        $this->db->bind(':fs', $nextFlowStatus);
        $this->db->bind(':fn', $cleanNote !== '' ? $cleanNote : null);
        $this->db->bind(':id', $conversationId);
        $this->db->execute();

        $reward = $normRating === 1 ? 0.65 : -0.75;
        $src = (string)$conv['response_source'];
        $this->applyReinforcementUpdate('source', $src, $src, $reward, true);
        if (!empty($conv['rag_reference'])) {
            $docKey = explode(' (', (string)$conv['rag_reference'])[0];
            $this->applyReinforcementUpdate('rag_doc', $docKey, $docKey, $reward, true);
        }
        if (!empty($conv['learned_item_id'])) {
            $this->updateLearnedItemReward((int)$conv['learned_item_id'], $reward, true);
        }

        $healedMsg = '';
        if ($normRating === -1) {
            $reason = $cleanNote !== '' ? ('user_dissatisfied: ' . $cleanNote) : 'user_negative_feedback';
            $gapId = $this->registerKnowledgeGap(
                (string)$conv['user_message'],
                (string)($conv['language'] ?: 'id'),
                (string)($conv['intent_tag'] ?: 'general_qa'),
                $reason,
                min(0.45, (float)$conv['ai_eval_score']),
                0,
                1,
                1
            );
            if ($gapId > 0) {
                $healRes = $this->healSingleGap($gapId);
                if (!empty($healRes['healed'])) {
                    $healedMsg = ' System immediately synthesized an improved answer for the next interaction.';
                }
            }
            $this->recordFlowTransition('feedback_loop', (string)($conv['intent_tag'] ?: 'general_qa'), $src, true, false, -1.0);
        } else {
            
            if (strpos($src, 'LLM') !== false || strpos($src, 'RAG') !== false) {
                $this->promoteConfirmedQa((string)$conv['user_message'], (string)$conv['assistant_response'], (string)($conv['language'] ?: 'id'), $src);
            }
            $this->recordFlowTransition('feedback_loop', (string)($conv['intent_tag'] ?: 'general_qa'), $src, false, false, 1.0);
        }

        return [
            'success' => true,
            'rating' => $normRating,
            'message' => $normRating === 1
                ? 'Thank you! Positive feedback reinforces Unklabot answer weights.'
                : 'Feedback recorded. Unklabot flagged this topic for automatic improvement.' . $healedMsg
        ];
    }

    

    public function recordFlowBeacon(string $sessionCode, int $conversationId, string $eventType): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_va_conversations') || $conversationId <= 0) {
            return ['success' => false];
        }
        $this->db->prepare("SELECT id, intent_tag, response_source, is_unanswered, ai_eval_score, user_satisfaction FROM tbl_va_conversations WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $conversationId);
        $row = $this->db->single();
        if (!$row) {
            return ['success' => false];
        }
        $evt = strtolower(trim($eventType));
        if ($evt === 'aborted_early' || $evt === 'page_exit_stall') {
            $shouldMarkStall = ((int)$row['is_unanswered'] === 1) || ((float)$row['ai_eval_score'] < 0.72) || ((int)$row['user_satisfaction'] === -1);
            if ($evt === 'aborted_early' || $shouldMarkStall) {
                $status = ($evt === 'page_exit_stall') ? 'abandoned' : 'stalled';
                $this->db->prepare("UPDATE tbl_va_conversations SET implicit_feedback = -1, flow_status = :fs WHERE id = :id");
                $this->db->bind(':fs', $status);
                $this->db->bind(':id', $conversationId);
                $this->db->execute();

                $flowKey = substr("session_drop->{$row['intent_tag']}", 0, 128);
                $this->recordFlowTransition('session_drop', (string)$row['intent_tag'], (string)$row['response_source'], true, false, -0.6);
                if ($this->db->hasTable('tbl_conversation_flows')) {
                    $this->db->prepare("UPDATE tbl_conversation_flows SET abandon_count = abandon_count + 1 WHERE flow_key = :fk");
                    $this->db->bind(':fk', $flowKey);
                    $this->db->execute();
                }
            }
        }
        return ['success' => true];
    }

    

    public function healSingleGap(int $gapId): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_knowledge_gaps')) {
            return ['healed' => false, 'strategy' => 'db_unavailable'];
        }
        $this->db->prepare("SELECT * FROM tbl_knowledge_gaps WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $gapId);
        $gap = $this->db->single();
        if (!$gap) {
            return ['healed' => false, 'strategy' => 'not_found'];
        }

        $question = (string)$gap['sample_question'];
        $lang = ((string)$gap['language'] === 'en') ? 'en' : 'id';
        $intentTag = (string)($gap['intent_tag'] ?? 'general_qa');
        $qVec = $this->computeEnhancedVector($question, true);
        $keywords = $this->extractDistinctKeywords($question, 6);
        $hasCampusCue = (bool)preg_match('/\b(unklab|klabat|kampus|filkom|feb|fik|fakultas|prodi|jurusan|akademik|sks|krs|ips|ipk|skripsi|yudisium|wisuda|asrama|dorm|spp|ukt|beasiswa|kip|rektor|dekan|presensi|kuliah|semester|cuti|chapel|klinik|kafetaria|perpus|perpustakaan|kurikulum|matkul|nilai|kelulusan|peminatan)\b/iu', $question);
        $bestCandidate = null;

        
        if (is_callable($this->toolResolverFn)) {
            $toolResolved = ($this->toolResolverFn)($question, $lang);
            if (is_array($toolResolved) && !empty($toolResolved['reply'])) {
                $toolName = (string)($toolResolved['tool'] ?? 'agent_tool');
                $this->db->prepare("UPDATE tbl_knowledge_gaps
                    SET status = 'auto_healed', healing_strategy = :hs,
                        suggested_draft = :sd, healed_at = NOW()
                    WHERE id = :id");
                $this->db->bind(':hs', 'agent_tool_routing:' . $toolName);
                $this->db->bind(':sd', substr((string)$toolResolved['reply'], 0, 500));
                $this->db->bind(':id', $gapId);
                $this->db->execute();
                return ['healed' => true, 'strategy' => 'agent_tool_routing', 'learned_id' => 0, 'keywords_expanded' => 0];
            }
        }

        
        if ($this->db->hasTable('tbl_knowledge_chunks') && $this->db->hasTable('tbl_knowledge_documents')) {
            $this->db->prepare("SELECT c.id, c.section_title, c.chunk_text, d.title AS doc_title, d.file_name
                FROM tbl_knowledge_chunks c
                INNER JOIN tbl_knowledge_documents d ON d.id = c.document_id
                WHERE d.is_active = 1");
            $chunks = $this->db->resultSet();
            $topScore = 0.0;
            $topChunk = null;
            foreach ($chunks as $ch) {
                $chunkTxt = (string)$ch['chunk_text'];
                if (class_exists('DocumentParser') && !DocumentParser::isReadableDocumentText($chunkTxt)) {
                    continue;
                }
                $docText = (string)$ch['doc_title'] . ' ' . (string)$ch['section_title'] . ' ' . $chunkTxt;
                $fuzzy = $this->fuzzyTokenSimilarity($question, $docText);
                $minFuzzyRag = $hasCampusCue ? 0.65 : 0.78;
                if ($fuzzy < $minFuzzyRag) {
                    continue;
                }
                $dVec = $this->computeEnhancedVector($docText, true);
                $sim = $this->cosineVectors($qVec, $dVec);
                $combined = ($sim * 0.60) + ($fuzzy * 0.40);
                if (stripos((string)$ch['section_title'], 'Implementasi & Ketentuan Praktis') === 0) {
                    $combined *= 0.72;
                }
                if (!$hasCampusCue && preg_match('/^\[(?:FEB|FIK|FILKOM|ASMI)/i', trim($chunkTxt)) && $combined < 0.68) {
                    continue;
                }
                if ($combined > $topScore) {
                    $topScore = $combined;
                    $topChunk = $ch;
                }
            }
            if ($topChunk && $topScore >= ($hasCampusCue ? 0.50 : 0.58)) {
                $synthesized = $this->extractConciseTextRankSummary((string)$topChunk['chunk_text'], $qVec, 240);
                if ($synthesized !== '') {
                    $ref = $topChunk['file_name'] . ' — ' . $topChunk['section_title'];
                    $replyText = $synthesized . ($lang === 'en' ? " [Source: {$ref}]" : " [Sumber: {$ref}]");
                    $bestCandidate = [
                        'strategy' => 'deep_rag_textrank_synthesis',
                        'source_type' => 'auto_synthesized',
                        'confidence' => min(0.92, round(0.55 + ($topScore * 0.45), 3)),
                        'reply' => $replyText,
                        'ref' => $ref,
                        'category' => 'RAG Auto-Synthesized'
                    ];
                }
            }
        }

        
        if (!$bestCandidate && $this->db->hasTable('tbl_static_knowledge')) {
            $this->db->prepare("SELECT id, topic_code, category, keywords_id, keywords_en, response_id, response_en FROM tbl_static_knowledge WHERE is_active = 1");
            $kbRows = $this->db->resultSet();
            $topKbScore = 0.0;
            $topKb = null;
            foreach ($kbRows as $kb) {
                $kbText = (string)$kb['keywords_id'] . ' ' . (string)$kb['keywords_en'] . ' ' . (string)$kb['response_id'];
                $fuzzy = $this->fuzzyTokenSimilarity($question, $kbText);
                if ($fuzzy < 0.68) {
                    continue;
                }
                $kVec = $this->computeEnhancedVector($kbText, true);
                $sim = $this->cosineVectors($qVec, $kVec);
                $comb = ($sim * 0.60) + ($fuzzy * 0.40);
                if ($comb > $topKbScore) {
                    $topKbScore = $comb;
                    $topKb = $kb;
                }
            }
            if ($topKb && $topKbScore >= 0.54) {
                $expandedCount = $this->expandKbAliases((int)$topKb['id'], $lang, $keywords);
                $resp = ($lang === 'en' && !empty($topKb['response_en'])) ? (string)$topKb['response_en'] : (string)$topKb['response_id'];
                $bestCandidate = [
                    'strategy' => 'kb_semantic_alias_expansion',
                    'source_type' => 'kb_alias_expanded',
                    'confidence' => min(0.93, round(0.60 + ($topKbScore * 0.40), 3)),
                    'reply' => $resp,
                    'ref' => 'Static KB #' . $topKb['topic_code'] . " (+{$expandedCount} alias)",
                    'category' => (string)$topKb['category'],
                    'keywords_expanded' => $expandedCount
                ];
            }
        }

        
        if (!$bestCandidate && $this->db->hasTable('tbl_va_conversations') && !in_array($intentTag, ['memory_recall', 'calendar_time', 'math_calc', 'profanity'], true)) {
            $this->db->prepare("SELECT user_message, assistant_response, response_source, ai_eval_score, user_satisfaction
                FROM tbl_va_conversations
                WHERE is_unanswered = 0 AND ai_eval_score >= 0.85 AND LENGTH(assistant_response) >= 35
                  AND response_source NOT IN ('Profanity Guard','Long-Term Memory','Conversation Summary','RAG Document')
                  AND intent_tag NOT IN ('memory_recall','calendar_time','math_calc')
                ORDER BY user_satisfaction DESC, ai_eval_score DESC, id DESC LIMIT 80");
            $histRows = $this->db->resultSet();
            $topHistScore = 0.0;
            $topHist = null;
            foreach ($histRows as $hr) {
                $hVec = $this->computeEnhancedVector((string)$hr['user_message'], true);
                $sim = $this->cosineVectors($qVec, $hVec);
                if ($sim > $topHistScore) {
                    $topHistScore = $sim;
                    $topHist = $hr;
                }
            }
            if ($topHist && $topHistScore >= 0.48) {
                $bestCandidate = [
                    'strategy' => 'history_distillation',
                    'source_type' => 'distilled_qa',
                    'confidence' => min(0.90, round(0.58 + ($topHistScore * 0.40), 3)),
                    'reply' => (string)$topHist['assistant_response'],
                    'ref' => 'Distilasi riwayat sukses (' . $topHist['response_source'] . ')',
                    'category' => 'Conversation Distilled'
                ];
            }
        }

        
        if (!$bestCandidate && is_callable($this->llmSynthesizerFn)) {
            $syn = ($this->llmSynthesizerFn)($question, $lang, $intentTag);
            if (is_array($syn) && !empty($syn['reply']) && (float)($syn['score'] ?? 0) >= 0.84) {
                $srcTag = trim((string)($syn['source'] ?? 'Autonomous LLM'));
                $mdlTag = trim((string)($syn['model'] ?? ''));
                $bestCandidate = [
                    'strategy' => 'autonomous_llm_synthesis',
                    'source_type' => 'auto_synthesized',
                    'confidence' => min(0.95, round((float)$syn['score'], 3)),
                    'reply' => (string)$syn['reply'],
                    'ref' => 'Autonomous Synthesis (' . $srcTag . ($mdlTag !== '' ? " · {$mdlTag}" : '') . ')',
                    'category' => 'Autonomous AI Knowledge'
                ];
            }
        }

        if ($bestCandidate) {
            $patternCode = 'LEARN_' . substr(md5($gap['gap_signature']), 0, 14);
            $vec = $this->computeEnhancedVector($question, true);
            $respId = ($lang === 'id') ? $bestCandidate['reply'] : $bestCandidate['reply'];
            $respEn = ($lang === 'en') ? $bestCandidate['reply'] : '';

            $this->db->prepare("SELECT id FROM tbl_learned_knowledge WHERE pattern_code = :pc LIMIT 1");
            $this->db->bind(':pc', $patternCode);
            $ex = $this->db->single();
            if ($ex) {
                $learnedId = (int)$ex['id'];
                $this->db->prepare("UPDATE tbl_learned_knowledge
                    SET response_id = :ri, response_en = CASE WHEN :re != '' THEN :re2 ELSE response_en END,
                        confidence_score = :cs, is_active = 1, last_reinforced_at = NOW()
                    WHERE id = :id");
                $this->db->bind(':ri', $respId);
                $this->db->bind(':re', $respEn);
                $this->db->bind(':re2', $respEn);
                $this->db->bind(':cs', $bestCandidate['confidence']);
                $this->db->bind(':id', $learnedId);
                $this->db->execute();
            } else {
                $this->db->prepare("INSERT INTO tbl_learned_knowledge
                    (pattern_code, category, trigger_phrases, keywords_json, vector_json, language, response_id, response_en, source_type, source_ref, confidence_score, q_value, is_active, auto_promoted_at, last_reinforced_at)
                    VALUES (:pc, :cat, :tp, :kj, :vj, :lg, :ri, :re, :st, :sr, :cs, 0.7200, 1, NOW(), NOW())");
                $this->db->bind(':pc', $patternCode);
                $this->db->bind(':cat', $bestCandidate['category']);
                $this->db->bind(':tp', substr($question, 0, 480));
                $this->db->bind(':kj', json_encode($keywords, JSON_UNESCAPED_UNICODE));
                $this->db->bind(':vj', json_encode($vec, JSON_UNESCAPED_UNICODE));
                $this->db->bind(':lg', $lang);
                $this->db->bind(':ri', $respId);
                $this->db->bind(':re', $respEn);
                $this->db->bind(':st', $bestCandidate['source_type']);
                $this->db->bind(':sr', substr($bestCandidate['ref'], 0, 250));
                $this->db->bind(':cs', $bestCandidate['confidence']);
                $this->db->execute();
                $learnedId = (int)$this->db->lastInsertId();
            }

            $this->db->prepare("UPDATE tbl_knowledge_gaps
                SET status = 'auto_healed', healing_strategy = :hs, resolved_learned_id = :rl,
                    suggested_draft = :sd, healed_at = NOW()
                WHERE id = :id");
            $this->db->bind(':hs', $bestCandidate['strategy']);
            $this->db->bind(':rl', $learnedId);
            $this->db->bind(':sd', substr($bestCandidate['reply'], 0, 600));
            $this->db->bind(':id', $gapId);
            $this->db->execute();

            return [
                'healed' => true,
                'strategy' => $bestCandidate['strategy'],
                'learned_id' => $learnedId,
                'keywords_expanded' => (int)($bestCandidate['keywords_expanded'] ?? 0)
            ];
        }

        
        $kwLabel = !empty($keywords) ? implode(', ', $keywords) : 'topik terkait';
        $draft = $lang === 'en'
            ? "No verified local document mentions ({$kwLabel}). Recommended clarification: invite user to upload a RAG document or rephrase into UNKLAB academics/AI topics."
            : "Belum ada dokumen lokal yang memuat fakta spesifik ({$kwLabel}). Strategi anti-stall: tawarkan topik terdekat UNKLAB/AI atau unggah referensi baru ke RAG.";
        $this->db->prepare("UPDATE tbl_knowledge_gaps SET status = 'needs_rich_source', healing_strategy = 'local_clarification_bridge', suggested_draft = :sd WHERE id = :id");
        $this->db->bind(':sd', $draft);
        $this->db->bind(':id', $gapId);
        $this->db->execute();

        return ['healed' => false, 'strategy' => 'needs_rich_source'];
    }

    

    private function extractConciseTextRankSummary(string $chunkText, array $qVec, int $maxChars = 240): string {
        $clean = trim(preg_replace('/\s+/u', ' ', $chunkText));
        if ($clean === '') {
            return '';
        }
        $sentences = preg_split('/(?<=[.!?])\s+/u', $clean, -1, PREG_SPLIT_NO_EMPTY);
        if (!is_array($sentences) || count($sentences) <= 2) {
            return strlen($clean) > $maxChars ? substr($clean, 0, $maxChars - 3) . '...' : $clean;
        }
        $scored = [];
        foreach ($sentences as $idx => $s) {
            $sVec = $this->computeEnhancedVector($s, true);
            $qSim = $this->cosineVectors($qVec, $sVec);
            $posBonus = ($idx === 0) ? 0.12 : 0.0;
            $scored[] = ['idx' => $idx, 'text' => trim($s), 'score' => $qSim + $posBonus];
        }
        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        $topTwo = array_slice($scored, 0, 2);
        usort($topTwo, static fn($a, $b) => $a['idx'] <=> $b['idx']);
        $out = trim(implode(' ', array_column($topTwo, 'text')));
        if (strlen($out) > $maxChars) {
            $cut = substr($out, 0, $maxChars - 3);
            $sp = strrpos($cut, ' ');
            $out = ($sp !== false ? substr($cut, 0, $sp) : $cut) . '...';
        }
        return $out;
    }

    

    private function expandKbAliases(int $kbId, string $lang, array $newKeywords): int {
        if ($kbId <= 0 || empty($newKeywords) || !$this->db->hasTable('tbl_static_knowledge')) {
            return 0;
        }
        $col = $lang === 'en' ? 'keywords_en' : 'keywords_id';
        $this->db->prepare("SELECT {$col} AS kw_str FROM tbl_static_knowledge WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $kbId);
        $row = $this->db->single();
        if (!$row) {
            return 0;
        }
        $existing = array_filter(array_map('trim', explode(',', strtolower((string)$row['kw_str']))));
        $added = 0;
        foreach ($newKeywords as $nk) {
            $n = strtolower(trim($nk));
            if (strlen($n) >= 4 && !in_array($n, $existing, true) && count($existing) < 28) {
                $existing[] = $n;
                $added++;
                if ($added >= 2) break;
            }
        }
        if ($added > 0) {
            $this->db->prepare("UPDATE tbl_static_knowledge SET {$col} = :kw WHERE id = :id");
            $this->db->bind(':kw', implode(',', $existing));
            $this->db->bind(':id', $kbId);
            $this->db->execute();
        }
        return $added;
    }

    

    private function promoteConfirmedQa(string $question, string $answer, string $lang, string $src): void {
        $q = trim($question);
        $a = trim($answer);
        if (strlen($q) < 10 || strlen($a) < 28 || !$this->db->hasTable('tbl_learned_knowledge')) {
            return;
        }
        $intent = $this->classifyIntent($q, $src);
        if (in_array($intent, ['memory_recall', 'profanity', 'calendar_time', 'math_calc'], true)) {
            return;
        }
        $kw = $this->extractDistinctKeywords($q, 6);
        if (count($kw) < 2) {
            return;
        }
        $vec = $this->computeEnhancedVector($q, true);
        $sig = 'DIST_' . substr(md5($lang . '|' . implode('_', $kw)), 0, 14);
        $this->db->prepare("SELECT id FROM tbl_learned_knowledge WHERE pattern_code = :pc LIMIT 1");
        $this->db->bind(':pc', $sig);
        $ex = $this->db->single();
        if ($ex) {
            $this->updateLearnedItemReward((int)$ex['id'], 0.30, true);
            return;
        }

        $this->db->prepare("INSERT INTO tbl_learned_knowledge
            (pattern_code, category, trigger_phrases, keywords_json, vector_json, language, response_id, response_en, source_type, source_ref, confidence_score, q_value, positive_count, is_active, auto_promoted_at, last_reinforced_at)
            VALUES (:pc, 'Auto-Distilled', :tp, :kj, :vj, :lg, :ri, :re, 'distilled_qa', :sr, 0.860, 0.7800, 1, 1, NOW(), NOW())");
        $this->db->bind(':pc', $sig);
        $this->db->bind(':tp', substr($q, 0, 480));
        $this->db->bind(':kj', json_encode($kw, JSON_UNESCAPED_UNICODE));
        $this->db->bind(':vj', json_encode($vec, JSON_UNESCAPED_UNICODE));
        $this->db->bind(':lg', $lang === 'en' ? 'en' : 'id');
        $this->db->bind(':ri', $a);
        $this->db->bind(':re', $lang === 'en' ? $a : '');
        $this->db->bind(':sr', substr('Auto-distilled via ' . $src, 0, 250));
        $this->db->execute();
    }

    

    public function runOptimizationCycle(string $mode = 'manual_cycle', int $maxAutoGaps = 6): array {
        $t0 = microtime(true);
        if (!$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Database connection is not active.'];
        }
        $conn = $this->db->getConnection();
        if (!$conn) {
            return ['success' => false, 'message' => 'MySQL connection is unavailable.'];
        }

        $evalBeforeRow = @$conn->query("SELECT ROUND(AVG(ai_eval_score), 2) AS avg_sc FROM tbl_va_conversations")->fetch_assoc();
        $avgBefore = (float)($evalBeforeRow['avg_sc'] ?? 0.85);

        $analyzed = 0;
        $gapsDetected = 0;
        $gapsHealed = 0;
        $keywordsExpanded = 0;
        $flowsTuned = 0;

        
        $lastSyncedId = 0;
        if ($this->db->hasTable('tbl_system_settings')) {
            $wRes = @$conn->query("SELECT setting_value FROM `tbl_system_settings` WHERE setting_key = 'last_eval_synced_conv_id' LIMIT 1");
            if ($wRes && ($wr = $wRes->fetch_assoc())) {
                $lastSyncedId = (int)$wr['setting_value'];
            }
        }
        $maxSeenId = $lastSyncedId;

        
        $convRes = @$conn->query("SELECT * FROM (
                SELECT id, session_code, user_message, assistant_response, language, response_source, ai_eval_score, latency_ms, intent_tag, is_unanswered, flow_status, created_at
                FROM tbl_va_conversations ORDER BY id DESC LIMIT 300
            ) sub ORDER BY id ASC");
        $prevBySession = [];
        if ($convRes) {
            while ($c = $convRes->fetch_assoc()) {
                $cid = (int)$c['id'];
                if ($cid > $maxSeenId) {
                    $maxSeenId = $cid;
                }
                $isFresh = ($cid > $lastSyncedId);
                $sc = (string)$c['session_code'];
                if ($isFresh) {
                    $analyzed++;
                    $kbApproxLen = ($c['response_source'] === 'Static KB')
                        ? min(7, max(2, strlen(trim((string)$c['user_message']))))
                        : 0;
                    $ev = $this->evaluateTurn([
                        'user_message' => (string)$c['user_message'],
                        'reply' => (string)$c['assistant_response'],
                        'response_source' => (string)$c['response_source'],
                        'rag_score' => strpos((string)$c['response_source'], 'RAG') !== false ? 0.35 : 0.0,
                        'learned_score' => strpos((string)$c['response_source'], 'Self-Learned') !== false ? 0.45 : 0.0,
                        'kb_match_len' => $kbApproxLen,
                        'latency_ms' => (int)$c['latency_ms'],
                        'language' => (string)$c['language']
                    ]);

                    $stmtUpd = @$conn->prepare("UPDATE tbl_va_conversations SET intent_tag = ?, is_unanswered = ?, failure_reason = ?, ai_eval_score = ? WHERE id = ?");
                    if ($stmtUpd) {
                        $fr = $ev['failure_reason'];
                        $stmtUpd->bind_param('sisdi', $ev['intent_tag'], $ev['is_unanswered'], $fr, $ev['eval_score'], $cid);
                        @$stmtUpd->execute();
                        $stmtUpd->close();
                    }

                    if ($ev['is_unanswered'] === 1 || $ev['eval_score'] < 0.62) {
                        $gapsDetected++;
                        $this->registerKnowledgeGap(
                            (string)$c['user_message'],
                            (string)($c['language'] ?: 'id'),
                            $ev['intent_tag'],
                            (string)($ev['failure_reason'] ?: 'no_local_knowledge'),
                            $ev['eval_score']
                        );
                    }

                    
                    if (isset($prevBySession[$sc])) {
                        $prev = $prevBySession[$sc];
                        $sim = $this->cosineVectors(
                            $this->computeEnhancedVector((string)$prev['user_message'], true),
                            $this->computeEnhancedVector((string)$c['user_message'], true)
                        );
                        if ($sim >= 0.48 && !in_array($prev['intent_tag'], ['greeting', 'math_calc', 'calendar_time', 'memory_recall'], true)) {
                            @$conn->query("UPDATE tbl_va_conversations SET implicit_feedback = -1, flow_status = 'rephrased' WHERE id = " . (int)$prev['id']);
                            $this->recordFlowTransition((string)$prev['intent_tag'], $ev['intent_tag'], (string)$prev['response_source'], true, true, -0.5);
                            if ($ev['is_unanswered'] === 0 && $ev['eval_score'] >= 0.85 && $this->isEligibleForPairwiseLearning((string)$c['response_source'], (string)$ev['intent_tag'])) {
                                $this->learnFromPairwiseRecovery(
                                    (string)$prev['user_message'],
                                    (string)$c['user_message'],
                                    (string)$c['assistant_response'],
                                    (string)($c['language'] ?: 'id'),
                                    (string)$c['response_source']
                                );
                            }
                        }
                    }
                    $c['intent_tag'] = $ev['intent_tag'];
                } elseif (empty($c['intent_tag'])) {
                    $c['intent_tag'] = 'general_qa';
                }
                $prevBySession[$sc] = $c;
            }
        }
        if ($maxSeenId > $lastSyncedId && $this->db->hasTable('tbl_system_settings')) {
            @$conn->query("INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('last_eval_synced_conv_id', '{$maxSeenId}') ON DUPLICATE KEY UPDATE `setting_value` = '{$maxSeenId}'");
        }

        
        @$conn->query("UPDATE tbl_va_conversations c
            INNER JOIN (SELECT session_code, MAX(id) AS max_id FROM tbl_va_conversations GROUP BY session_code) t ON c.id = t.max_id
            SET c.flow_status = 'stalled'
            WHERE (c.is_unanswered = 1 OR c.user_satisfaction = -1 OR c.ai_eval_score < 0.65)
              AND c.created_at < (NOW() - INTERVAL 15 MINUTE)
              AND c.flow_status = 'continued'");

        
        $prunedCount = $this->auditAndRefineLearnedKnowledge();

        
        $autoGapLimit = max(1, min(20, $maxAutoGaps));
        $gapSql = ($mode === 'manual_cycle')
            ? "SELECT id FROM tbl_knowledge_gaps WHERE status IN ('detected','needs_rich_source') ORDER BY occurrence_count DESC, id DESC LIMIT 30"
            : "SELECT id FROM tbl_knowledge_gaps WHERE status IN ('detected','needs_rich_source') ORDER BY CASE WHEN status = 'detected' THEN 0 ELSE 1 END, occurrence_count DESC, id DESC LIMIT {$autoGapLimit}";
        $openGapsRes = @$conn->query($gapSql);
        if ($openGapsRes) {
            while ($gr = $openGapsRes->fetch_assoc()) {
                $resHeal = $this->healSingleGap((int)$gr['id']);
                if (!empty($resHeal['healed'])) {
                    $gapsHealed++;
                }
                $keywordsExpanded += (int)($resHeal['keywords_expanded'] ?? 0);
            }
        }

        
        $repQaRes = @$conn->query("SELECT MAX(user_message) AS user_message, MAX(assistant_response) AS assistant_response, language, MAX(response_source) AS response_source, COUNT(*) AS cnt
            FROM tbl_va_conversations
            WHERE is_unanswered = 0 AND ai_eval_score >= 0.90 AND response_source IN ('OpenAI LLM','Gemini LLM','OpenAI LLM + RAG','Gemini LLM + RAG','RAG Document')
            GROUP BY LEFT(user_message, 60), language
            HAVING cnt >= 2 OR MAX(user_satisfaction) = 1
            ORDER BY cnt DESC LIMIT 12");
        if ($repQaRes) {
            while ($rq = $repQaRes->fetch_assoc()) {
                $this->promoteConfirmedQa(
                    (string)$rq['user_message'],
                    (string)$rq['assistant_response'],
                    (string)($rq['language'] ?: 'id'),
                    (string)$rq['response_source']
                );
            }
        }

        
        $flowsRes = @$conn->query("SELECT id, from_intent, to_intent, stall_count, rephrase_loop_count, abandon_count
            FROM tbl_conversation_flows
            WHERE (stall_count > 0 OR rephrase_loop_count > 0 OR abandon_count > 0)
            ORDER BY (stall_count + rephrase_loop_count + abandon_count) DESC LIMIT 20");
        if ($flowsRes) {
            $recMapId = [
                'academic_krs_sks' => 'Supaya jelas, bagian akademik mana yang ingin Anda cek: batas SKS, IPS/IPK, syarat skripsi, atau absensi?',
                'finance_scholarship' => 'Apakah Anda ingin mengecek cara bayar VA uang kuliah, cicilan, atau syarat beasiswa UNKLAB?',
                'dorm_facility' => 'Ingin cek lokasi gedung kuliah, jam perpustakaan, klinik 24 jam, atau peraturan asrama?',
                'ai_research' => 'Bisa sebutkan topik AI spesifik yang Anda cari: Reinforcement Learning, RAG PDF, atau fitur Unklabot?',
                'calendar_time' => 'Sebutkan tanggal lengkap atau kata "hari ini / besok / kemarin" agar saya hitung kalendernya dengan tepat.',
                'sql_analytics' => 'Ingin membuat query SQL SELECT untuk tabel tertentu atau melihat statistik percakapan real-time?',
                'unanswered_fallback' => 'Coba tuliskan pertanyaan dengan 2–3 kata kunci spesifik (misalnya topik kuliah UNKLAB atau hitung angka).'
            ];
            $recMapEn = [
                'academic_krs_sks' => 'Would you like to specify: credit limit (SKS), GPA rules, thesis requirements, or attendance policy?',
                'finance_scholarship' => 'Are you asking about tuition VA payment, installments, academic scholarships, or Student Labor?',
                'dorm_facility' => 'Would you like library hours, clinic info, building locations, or dormitory rules?',
                'ai_research' => 'Which AI topic would you like to explore: Reinforcement Learning, Hybrid RAG, or 3D Avatar motion?',
                'calendar_time' => 'Please provide a specific date or relative day (today / tomorrow / yesterday) for exact calendar lookup.',
                'sql_analytics' => 'Would you like a safe SQL SELECT template for a table or live database analytics?',
                'unanswered_fallback' => 'Please include 2–3 specific keywords (such as UNKLAB academics, campus services, or math).'
            ];
            while ($fl = $flowsRes->fetch_assoc()) {
                $targetIntent = ($fl['to_intent'] !== 'general_qa' && isset($recMapId[$fl['to_intent']]))
                    ? (string)$fl['to_intent']
                    : (string)$fl['from_intent'];
                $pId = $recMapId[$targetIntent] ?? $recMapId['unanswered_fallback'];
                $pEn = $recMapEn[$targetIntent] ?? $recMapEn['unanswered_fallback'];
                $stFl = @$conn->prepare("UPDATE tbl_conversation_flows SET auto_recovery_prompt_id = ?, auto_recovery_prompt_en = ? WHERE id = ?");
                if ($stFl) {
                    $fid = (int)$fl['id'];
                    $stFl->bind_param('ssi', $pId, $pEn, $fid);
                    @$stFl->execute();
                    $stFl->close();
                    $flowsTuned++;
                }
            }
        }

        
        $prunedCount = $this->auditAndRefineLearnedKnowledge();
        if ($prunedCount > 0) {
            $reOpenRes = @$conn->query("SELECT id FROM tbl_knowledge_gaps WHERE status = 'detected' ORDER BY occurrence_count DESC, id DESC LIMIT 4");
            if ($reOpenRes) {
                while ($rg = $reOpenRes->fetch_assoc()) {
                    $reHeal = $this->healSingleGap((int)$rg['id']);
                    if (!empty($reHeal['healed'])) {
                        $gapsHealed++;
                    }
                }
            }
        }

        
        $analytics = $this->getSelfLearningAnalytics();
        $avgAfter = round((float)$analytics['avg_eval_score'], 2);
        $satRate = round((float)$analytics['satisfaction_rate'], 2);
        $stallRate = round((float)$analytics['stall_rate'], 2);
        $durMs = max(2, (int)round((microtime(true) - $t0) * 1000));
        $validMode = in_array($mode, ['auto_online', 'auto_cycle', 'manual_cycle'], true) ? $mode : 'manual_cycle';

        $pruneNote = $prunedCount > 0 ? ", {$prunedCount} stale rule(s) pruned" : '';
        $summaryNote = "Autonomous cycle complete ({$durMs} ms): {$analyzed} chats evaluated, {$gapsDetected} gaps detected, {$gapsHealed} gaps auto-healed, {$keywordsExpanded} KB synonyms expanded, {$flowsTuned} stalled flows tuned{$pruneNote}.";
        $stRun = @$conn->prepare("INSERT INTO tbl_self_eval_runs
            (trigger_mode, conversations_analyzed, gaps_detected, gaps_healed, keywords_expanded, flows_tuned, avg_eval_before, avg_eval_after, satisfaction_rate, stall_rate, summary_note, duration_ms)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        if ($stRun) {
            $stRun->bind_param('siiiiiddddsi', $validMode, $analyzed, $gapsDetected, $gapsHealed, $keywordsExpanded, $flowsTuned, $avgBefore, $avgAfter, $satRate, $stallRate, $summaryNote, $durMs);
            @$stRun->execute();
            $stRun->close();
        }
        if ($this->db->hasTable('tbl_system_settings')) {
            $nowTs = (string)time();
            @$conn->query("INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('last_auto_sl_cycle_at', '{$nowTs}') ON DUPLICATE KEY UPDATE `setting_value` = '{$nowTs}'");
        }

        return [
            'success' => true,
            'mode' => $validMode,
            'conversations_analyzed' => $analyzed,
            'gaps_detected' => $gapsDetected,
            'gaps_healed' => $gapsHealed,
            'pruned_skills' => $prunedCount,
            'keywords_expanded' => $keywordsExpanded,
            'flows_tuned' => $flowsTuned,
            'avg_eval_before' => $avgBefore,
            'avg_eval_after' => $avgAfter,
            'satisfaction_rate' => $satRate,
            'stall_rate' => $stallRate,
            'duration_ms' => $durMs,
            'message' => $summaryNote
        ];
    }

    

    public function auditAndRefineLearnedKnowledge(): int {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_learned_knowledge')) {
            return 0;
        }
        $conn = $this->db->getConnection();
        if (!$conn) {
            return 0;
        }

        $kbKeywords = [];
        if ($this->db->hasTable('tbl_static_knowledge')) {
            $kbRes = @$conn->query("SELECT keywords_id, keywords_en FROM tbl_static_knowledge WHERE is_active = 1");
            if ($kbRes) {
                while ($kr = $kbRes->fetch_assoc()) {
                    $allKw = array_filter(array_map('trim', explode(',', strtolower((string)$kr['keywords_id'] . ',' . (string)$kr['keywords_en']))));
                    foreach ($allKw as $kw) {
                        if (strlen($kw) >= 8) {
                            $kbKeywords[] = $kw;
                        }
                    }
                }
            }
        }

        $pruned = 0;
        $lkRes = @$conn->query("SELECT id, pattern_code, trigger_phrases, response_id, source_type, source_ref, q_value, positive_count, negative_count FROM tbl_learned_knowledge WHERE is_active = 1");
        if (!$lkRes) {
            return 0;
        }
        while ($row = $lkRes->fetch_assoc()) {
            $lid = (int)$row['id'];
            $trig = strtolower(trim((string)($row['trigger_phrases'] ?? '')));
            $trigClean = trim(preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $trig));
            $resp = trim((string)($row['response_id'] ?? ''));
            $srcType = (string)($row['source_type'] ?? '');
            $qVal = (float)($row['q_value'] ?? 0.70);
            $pos = (int)($row['positive_count'] ?? 0);
            $neg = (int)($row['negative_count'] ?? 0);

            $shouldDeactivate = false;
            $requeueGap = false;

            if ($qVal < 0.40 || ($neg >= 2 && $neg > $pos)) {
                $shouldDeactivate = true;
                $requeueGap = true;
            } elseif (class_exists('DocumentParser') && $resp !== '' && !DocumentParser::isReadableDocumentText($resp)) {
                $shouldDeactivate = true;
                $requeueGap = true;
            } elseif (
                $srcType === 'auto_synthesized'
                && stripos((string)($row['source_ref'] ?? ''), 'Autonomous Synthesis') === false
                && preg_match('/^\[(?:FEB|FIK|FILKOM|ASMI)/i', $resp)
                && !preg_match('/\b(unklab|klabat|kampus|filkom|feb|fik|fakultas|prodi|jurusan|akademik|keperawatan|ekonomi|sks|krs|wisuda|yudisium|asrama)\b/u', $trigClean)
            ) {
                $shouldDeactivate = true;
                $requeueGap = true;
            } elseif ($trigClean !== '' && !empty($kbKeywords)) {
                foreach ($kbKeywords as $kw) {
                    if ($trigClean === $kw || strpos($trigClean, $kw) !== false) {
                        $shouldDeactivate = true;
                        $requeueGap = false;
                        break;
                    }
                }
            }

            if ($shouldDeactivate) {
                @$conn->query("UPDATE tbl_learned_knowledge SET is_active = 0 WHERE id = {$lid}");
                if ($requeueGap && $this->db->hasTable('tbl_knowledge_gaps')) {
                    @$conn->query("UPDATE tbl_knowledge_gaps SET status = 'detected', resolved_learned_id = NULL WHERE resolved_learned_id = {$lid}");
                }
                $pruned++;
            }
        }
        return $pruned;
    }

    

    public function runAutonomousMaintenanceIfDue(int $cooldownSec = 35, int $maxGaps = 4): ?array {
        if (self::$autoMaintenanceRunning || !$this->db->isConnected()) {
            return null;
        }
        $settings = $this->getSettingsMap();
        if (($settings['auto_self_learning_enabled'] ?? '1') !== '1') {
            return null;
        }
        $conn = $this->db->getConnection();
        if (!$conn) {
            return null;
        }

        $nowTs = time();
        $lastAt = 0;
        $lastSyncedId = 0;
        if ($this->db->hasTable('tbl_system_settings')) {
            $sr = @$conn->query("SELECT setting_key, setting_value FROM `tbl_system_settings` WHERE setting_key IN ('last_auto_sl_cycle_at', 'last_eval_synced_conv_id')");
            if ($sr) {
                while ($r = $sr->fetch_assoc()) {
                    if ($r['setting_key'] === 'last_auto_sl_cycle_at') $lastAt = (int)$r['setting_value'];
                    if ($r['setting_key'] === 'last_eval_synced_conv_id') $lastSyncedId = (int)$r['setting_value'];
                }
            }
        }
        if ($lastAt > 0 && ($nowTs - $lastAt) < $cooldownSec) {
            return null;
        }

        $hasWork = false;
        if ($this->db->hasTable('tbl_va_conversations')) {
            $mxRow = @$conn->query("SELECT COALESCE(MAX(id), 0) AS mx FROM tbl_va_conversations")->fetch_assoc();
            if ((int)($mxRow['mx'] ?? 0) > $lastSyncedId) {
                $hasWork = true;
            }
        }
        if (!$hasWork && $this->db->hasTable('tbl_knowledge_gaps')) {
            $gpRow = @$conn->query("SELECT COUNT(*) AS cnt FROM tbl_knowledge_gaps WHERE status = 'detected'")->fetch_assoc();
            if ((int)($gpRow['cnt'] ?? 0) > 0) {
                $hasWork = true;
            }
        }
        if (!$hasWork && $this->db->hasTable('tbl_learned_knowledge')) {
            $badRow = @$conn->query("SELECT COUNT(*) AS cnt FROM tbl_learned_knowledge WHERE is_active = 1 AND q_value < 0.40")->fetch_assoc();
            if ((int)($badRow['cnt'] ?? 0) > 0) {
                $hasWork = true;
            }
        }
        if (!$hasWork) {
            if ($this->db->hasTable('tbl_system_settings')) {
                @$conn->query("INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('last_auto_sl_cycle_at', '{$nowTs}') ON DUPLICATE KEY UPDATE `setting_value` = '{$nowTs}'");
            }
            return null;
        }

        self::$autoMaintenanceRunning = true;
        try {
            if ($this->db->hasTable('tbl_system_settings')) {
                @$conn->query("INSERT INTO `tbl_system_settings` (`setting_key`, `setting_value`) VALUES ('last_auto_sl_cycle_at', '{$nowTs}') ON DUPLICATE KEY UPDATE `setting_value` = '{$nowTs}'");
            }
            return $this->runOptimizationCycle('auto_online', $maxGaps);
        } finally {
            self::$autoMaintenanceRunning = false;
        }
    }

    

    public function getSelfLearningAnalytics(): array {
        $out = [
            'avg_eval_score' => 0.85,
            'satisfaction_rate' => 100.0,
            'explicit_total' => 0,
            'explicit_positive' => 0,
            'explicit_negative' => 0,
            'implicit_positive' => 0,
            'implicit_negative' => 0,
            'unanswered_count' => 0,
            'total_gaps' => 0,
            'open_gaps' => 0,
            'healed_gaps' => 0,
            'needs_rich_gaps' => 0,
            'learned_skills_count' => 0,
            'stall_rate' => 0.0,
            'total_stalls' => 0,
            'total_rephrases' => 0,
            'learning_runs_count' => 0,
            'trend_runs' => [],
            'top_stalled_flows' => []
        ];
        if (!$this->db->isConnected()) {
            return $out;
        }
        $conn = $this->db->getConnection();
        if (!$conn) {
            return $out;
        }

        if ($this->db->hasTable('tbl_va_conversations')) {
            $agg = @$conn->query("SELECT
                COUNT(*) AS total_cnt,
                ROUND(COALESCE(AVG(ai_eval_score), 0.88), 2) AS avg_ev,
                SUM(CASE WHEN user_satisfaction = 1 THEN 1 ELSE 0 END) AS exp_pos,
                SUM(CASE WHEN user_satisfaction = -1 THEN 1 ELSE 0 END) AS exp_neg,
                SUM(CASE WHEN implicit_feedback = 1 THEN 1 ELSE 0 END) AS imp_pos,
                SUM(CASE WHEN implicit_feedback = -1 THEN 1 ELSE 0 END) AS imp_neg,
                SUM(CASE WHEN is_unanswered = 1 THEN 1 ELSE 0 END) AS unans,
                SUM(CASE WHEN flow_status IN ('stalled','abandoned','rephrased') THEN 1 ELSE 0 END) AS stall_cnt,
                SUM(CASE WHEN flow_status = 'rephrased' THEN 1 ELSE 0 END) AS rep_cnt
                FROM tbl_va_conversations")->fetch_assoc();
            if ($agg) {
                $total = max(1, (int)($agg['total_cnt'] ?? 0));
                $expPos = (int)($agg['exp_pos'] ?? 0);
                $expNeg = (int)($agg['exp_neg'] ?? 0);
                $impPos = (int)($agg['imp_pos'] ?? 0);
                $impNeg = (int)($agg['imp_neg'] ?? 0);
                $unans = (int)($agg['unans'] ?? 0);
                $stallCnt = (int)($agg['stall_cnt'] ?? 0);

                $out['avg_eval_score'] = (float)($agg['avg_ev'] ?? 0.88);
                $out['explicit_positive'] = $expPos;
                $out['explicit_negative'] = $expNeg;
                $out['explicit_total'] = $expPos + $expNeg;
                $out['implicit_positive'] = $impPos;
                $out['implicit_negative'] = $impNeg;
                $out['unanswered_count'] = $unans;
                $out['total_stalls'] = $stallCnt;
                $out['total_rephrases'] = (int)($agg['rep_cnt'] ?? 0);
                $out['stall_rate'] = round(($stallCnt / $total) * 100, 1);

                
                $posWeight = ($expPos * 2.0) + $impPos + max(0, ($total - $unans - $expNeg - $impNeg) * 0.65);
                $negWeight = ($expNeg * 2.0) + $impNeg + ($unans * 1.2);
                $denom = $posWeight + $negWeight;
                $out['satisfaction_rate'] = $denom > 0 ? round(($posWeight / $denom) * 100, 1) : 92.0;
            }
        }

        if ($this->db->hasTable('tbl_knowledge_gaps')) {
            $gAgg = @$conn->query("SELECT
                COUNT(*) AS tot,
                SUM(CASE WHEN status = 'detected' THEN 1 ELSE 0 END) AS det_cnt,
                SUM(CASE WHEN status IN ('auto_healed','reinforced') THEN 1 ELSE 0 END) AS heal_cnt,
                SUM(CASE WHEN status = 'needs_rich_source' THEN 1 ELSE 0 END) AS need_cnt
                FROM tbl_knowledge_gaps")->fetch_assoc();
            if ($gAgg) {
                $out['total_gaps'] = (int)($gAgg['tot'] ?? 0);
                $out['open_gaps'] = (int)($gAgg['det_cnt'] ?? 0) + (int)($gAgg['need_cnt'] ?? 0);
                $out['healed_gaps'] = (int)($gAgg['heal_cnt'] ?? 0);
                $out['needs_rich_gaps'] = (int)($gAgg['need_cnt'] ?? 0);
            }
        }

        if ($this->db->hasTable('tbl_learned_knowledge')) {
            $lAgg = @$conn->query("SELECT COUNT(*) AS cnt FROM tbl_learned_knowledge WHERE is_active = 1")->fetch_assoc();
            $out['learned_skills_count'] = (int)($lAgg['cnt'] ?? 0);
        }

        if ($this->db->hasTable('tbl_self_eval_runs')) {
            $rCnt = @$conn->query("SELECT COUNT(*) AS cnt FROM tbl_self_eval_runs")->fetch_assoc();
            $out['learning_runs_count'] = (int)($rCnt['cnt'] ?? 0);
            $trRes = @$conn->query("SELECT id, trigger_mode, gaps_healed, avg_eval_after, satisfaction_rate, stall_rate, created_at FROM tbl_self_eval_runs ORDER BY id DESC LIMIT 10");
            $runs = [];
            if ($trRes) {
                while ($r = $trRes->fetch_assoc()) {
                    $runs[] = $r;
                }
            }
            $out['trend_runs'] = array_reverse($runs);
        }

        if ($this->db->hasTable('tbl_conversation_flows')) {
            $flRes = @$conn->query("SELECT flow_key, from_intent, to_intent, total_transitions, stall_count, rephrase_loop_count, abandon_count, avg_satisfaction
                FROM tbl_conversation_flows
                ORDER BY (stall_count + rephrase_loop_count + abandon_count) DESC, total_transitions DESC LIMIT 8");
            $flows = [];
            if ($flRes) {
                while ($f = $flRes->fetch_assoc()) {
                    $flows[] = $f;
                }
            }
            $out['top_stalled_flows'] = $flows;
        }

        return $out;
    }

    public function toggleLearnedKnowledge(int $id, int $isActive): array {
        if ($id <= 0 || !$this->db->isConnected() || !$this->db->hasTable('tbl_learned_knowledge')) {
            return ['success' => false, 'message' => 'Autonomous knowledge entry not found.'];
        }
        $next = $isActive === 1 ? 1 : 0;
        $this->db->prepare("UPDATE tbl_learned_knowledge SET is_active = :ia WHERE id = :id");
        $this->db->bind(':ia', $next);
        $this->db->bind(':id', $id);
        $ok = $this->db->execute();
        return ['success' => $ok, 'message' => $ok ? 'Autonomous AI knowledge status updated.' : 'Failed to update status.'];
    }

    private function getSettingsMap(): array {
        $map = ['auto_self_learning_enabled' => '1', 'auto_promote_knowledge' => '1'];
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_system_settings')) {
            return $map;
        }
        $this->db->prepare("SELECT setting_key, setting_value FROM tbl_system_settings WHERE setting_key IN ('auto_self_learning_enabled', 'auto_promote_knowledge')");
        foreach ($this->db->resultSet() as $r) {
            $map[(string)$r['setting_key']] = (string)$r['setting_value'];
        }
        return $map;
    }
}
