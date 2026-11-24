<?php

class OpenAIClient {
    private Database $db;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
    }

    public function resolveApiKey(): array {
        $envKey = trim((string)(getenv('OPENAI_API_KEY') ?: ($_ENV['OPENAI_API_KEY'] ?? '')), " \t\n\r\0\x0B\"'");
        if ($envKey !== '' && strlen($envKey) >= 16) {
            return [
                'found' => true,
                'source' => 'env',
                'key_id' => 0,
                'api_key' => $envKey,
                'default_model' => getenv('OPENAI_MODEL') ?: 'gpt-4.1-mini'
            ];
        }

        if ($this->db->isConnected() && $this->db->hasTable('tbl_ai_provider_keys')) {
            $st = $this->db->runPrepared(
                "SELECT id, api_key_encrypted, model_name
                 FROM tbl_ai_provider_keys
                 WHERE is_active = 1 AND provider = 'openai'
                 ORDER BY is_primary DESC, id ASC
                 LIMIT 1"
            );
            $row = $st ? $st->fetch(PDO::FETCH_ASSOC) : null;
            if ($row && !empty($row['api_key_encrypted'])) {
                $auth = new AuthService($this->db);
                $dec = trim($auth->decryptSecret((string)$row['api_key_encrypted']), " \t\n\r\0\x0B\"'");
                if ($dec !== '' && strlen($dec) >= 16) {
                    return [
                        'found' => true,
                        'source' => 'vault',
                        'key_id' => (int)$row['id'],
                        'api_key' => $dec,
                        'default_model' => (string)($row['model_name'] ?: 'gpt-4.1-mini')
                    ];
                }
            }
        }

        return ['found' => false, 'source' => 'none', 'key_id' => 0, 'api_key' => '', 'default_model' => 'gpt-4.1-mini'];
    }

    public function postJson(string $endpointPath, array $payload, int $timeoutSec = 25, array $telemetry = []): array {
        $keyInfo = $this->resolveApiKey();
        if (empty($keyInfo['found'])) {
            return [
                'ok' => false,
                'missing_key' => true,
                'http_code' => 0,
                'decoded' => ['error' => ['code' => 'MISSING_API_KEY', 'message' => 'OPENAI_API_KEY is not configured in environment or key vault.']],
                'latency_ms' => 0
            ];
        }

        $baseUrl = defined('OPENAI_BASE_URL') ? OPENAI_BASE_URL : 'https://api.openai.com/v1';
        $url = rtrim($baseUrl, '/') . '/' . ltrim($endpointPath, '/');
        $headers = [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $keyInfo['api_key']
        ];
        $jsonBody = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $caFile = $this->resolveCaBundleFile();

        $tStart = microtime(true);
        $res = $this->sendCurl($url, $headers, (string)$jsonBody, $caFile, max(5, min(60, $timeoutSec)));
        $latencyMs = (int)round((microtime(true) - $tStart) * 1000);
        $httpCode = (int)$res['http_code'];
        $decoded = is_string($res['raw']) ? (json_decode($res['raw'], true) ?: []) : [];

        if ($httpCode === 400 && isset($payload['temperature'])) {
            $errMsg = strtolower((string)($decoded['error']['message'] ?? ''));
            if (strpos($errMsg, 'temperature') !== false || strpos($errMsg, 'unsupported') !== false) {
                unset($payload['temperature']);
                $jsonRetry = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                $res = $this->sendCurl($url, $headers, (string)$jsonRetry, $caFile, max(5, min(60, $timeoutSec)));
                $httpCode = (int)$res['http_code'];
                $decoded = is_string($res['raw']) ? (json_decode($res['raw'], true) ?: []) : [];
            }
        }

        $ok = ($res['raw'] !== false && $httpCode >= 200 && $httpCode < 300 && empty($decoded['error']));
        $usage = $decoded['usage'] ?? [];
        $promptTok = (int)($usage['input_tokens'] ?? ($usage['prompt_tokens'] ?? 0));
        $compTok = (int)($usage['output_tokens'] ?? ($usage['completion_tokens'] ?? 0));
        $totTok = (int)($usage['total_tokens'] ?? ($promptTok + $compTok));
        $modelName = (string)($payload['model'] ?? $keyInfo['default_model']);
        $costUsd = $this->estimateCostUsd($modelName, $promptTok, $compTok);

        $this->logApiUsage(
            $telemetry['task_id'] ?? null,
            $telemetry['agent_id'] ?? null,
            $telemetry['user_id'] ?? null,
            $modelName,
            $endpointPath,
            $promptTok,
            $compTok,
            $totTok,
            $costUsd,
            $httpCode,
            $latencyMs,
            $ok ? 'success' : 'error',
            $ok ? null : (string)($decoded['error']['message'] ?? $res['curl_error'])
        );

        if ((int)$keyInfo['key_id'] > 0) {
            $statusTxt = $ok ? 'Connected (200 OK)' : ('HTTP ' . $httpCode);
            $this->db->runPrepared(
                "UPDATE tbl_ai_provider_keys
                 SET total_requests = total_requests + 1, total_tokens = total_tokens + ?, last_status = ?, last_used_at = NOW()
                 WHERE id = ?",
                [$totTok, substr($statusTxt, 0, 62), (int)$keyInfo['key_id']]
            );
        }

        return [
            'ok' => $ok,
            'missing_key' => false,
            'http_code' => $httpCode,
            'decoded' => $decoded,
            'usage' => [
                'prompt_tokens' => $promptTok,
                'completion_tokens' => $compTok,
                'total_tokens' => $totTok,
                'estimated_cost_usd' => $costUsd
            ],
            'latency_ms' => $latencyMs
        ];
    }

    public function estimateCostUsd(string $model, int $inputTokens, int $outputTokens): float {
        $m = strtolower($model);
        $rates = [
            'gpt-4.1-nano' => [0.10, 0.40],
            'gpt-4.1-mini' => [0.40, 1.60],
            'gpt-4.1' => [2.00, 8.00],
            'gpt-4o-mini' => [0.15, 0.60],
            'gpt-4o' => [2.50, 10.00],
            'o4-mini' => [1.10, 4.40],
            'text-embedding-3-small' => [0.02, 0.0],
            'text-embedding-3-large' => [0.13, 0.0],
        ];
        $pair = [0.40, 1.60];
        foreach ($rates as $k => $v) {
            if (strpos($m, $k) !== false) {
                $pair = $v;
                break;
            }
        }
        return round((($inputTokens * $pair[0]) + ($outputTokens * $pair[1])) / 1000000.0, 6);
    }

    public function logApiUsage(?int $taskId, ?int $agentId, ?int $userId, string $modelName, string $endpoint, int $pTok, int $cTok, int $tTok, float $costUsd, int $httpCode, int $latencyMs, string $status, ?string $errMsg): void {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_api_usage_logs')) {
            return;
        }
        $this->db->runPrepared(
            "INSERT INTO tbl_api_usage_logs
             (task_id, agent_id, user_id, provider, model_name, endpoint, prompt_tokens, completion_tokens, total_tokens, estimated_cost_usd, http_status, latency_ms, status, error_message)
             VALUES (?, ?, ?, 'openai', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $taskId,
                $agentId,
                $userId,
                substr($modelName, 0, 64),
                substr($endpoint, 0, 64),
                $pTok,
                $cTok,
                $tTok,
                number_format($costUsd, 6, '.', ''),
                $httpCode,
                $latencyMs,
                $status,
                $errMsg !== null ? substr($errMsg, 0, 250) : null
            ]
        );
    }

    private function sendCurl(string $url, array $headers, string $jsonBody, string $caFile, int $timeoutSec): array {
        if (!function_exists('curl_init')) {
            return ['raw' => false, 'http_code' => 0, 'curl_errno' => -1, 'curl_error' => 'cURL extension is not available'];
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        if ($caFile !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $caFile);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        }
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $errNo = curl_errno($ch);
        $err = curl_error($ch);
        curl_close($ch);
        return ['raw' => $raw, 'http_code' => $code, 'curl_errno' => $errNo, 'curl_error' => $err];
    }

    private function resolveCaBundleFile(): string {
        $candidates = [
            ini_get('curl.cainfo') ?: '',
            ini_get('openssl.cafile') ?: '',
            'C:/xampp/apache/bin/curl-ca-bundle.crt',
            'C:/xampp/php/extras/ssl/cacert.pem'
        ];
        foreach ($candidates as $path) {
            if ($path !== '' && is_file($path)) {
                return $path;
            }
        }
        return '';
    }
}
