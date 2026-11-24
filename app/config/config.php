<?php

if (!defined('BASEPATH')) {
    define('BASEPATH', dirname(__DIR__, 2));
}

$envFile = BASEPATH . '/.env';
if (is_file($envFile) && is_readable($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if (is_array($lines)) {
        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || $trimmed[0] === '#' || strpos($trimmed, '=') === false) {
                continue;
            }
            [$envName, $envVal] = explode('=', $trimmed, 2);
            $envName = trim($envName);
            $envVal = trim($envVal);
            if ($envName === '' || !preg_match('/^[A-Z0-9_]+$/i', $envName)) {
                continue;
            }
            if ((strlen($envVal) >= 2) && (($envVal[0] === '"' && substr($envVal, -1) === '"') || ($envVal[0] === "'" && substr($envVal, -1) === "'"))) {
                $envVal = substr($envVal, 1, -1);
            }
            if (getenv($envName) === false) {
                @putenv($envName . '=' . $envVal);
                $_ENV[$envName] = $envVal;
            }
        }
    }
}

if (!defined('APP_PATH')) {
    if (php_sapi_name() === 'cli' || empty($_SERVER['HTTP_HOST'])) {
        $folderName = basename(dirname(__DIR__, 2));
        define("APP_PATH", "http://localhost/" . $folderName);
    } else {
        $is_https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] == 443);
        $protocol = $is_https ? 'https://' : 'http://';
        $rawHost = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = preg_match('/^(localhost|127\.0\.0\.1|\[::1\]|[a-z0-9\-]+\.unklab\.ac\.id|unklab\.ac\.id)(:\d{1,5})?$/i', $rawHost) ? $rawHost : 'localhost';
        $scriptDir = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '')), '/');
        $cleanDir = preg_replace('#/public$#', '', $scriptDir);
        define("APP_PATH", $protocol . $host . $cleanDir);
    }
}

define("HOST_DB", getenv('DB_HOST') ?: "127.0.0.1");
define("NAME_DB", getenv('DB_NAME') ?: "db_agents_orchestrator");
define("USER_DB", getenv('DB_USER') ?: "root");
define("PASS_DB", getenv('DB_PASS') !== false ? (string)getenv('DB_PASS') : "");

define("APP_NAME", "FILKOM AI Agent Orchestrator");
define("APP_SHORT_NAME", "FILKOM Orchestrator");
define("APP_TAGLINE", "One Platform. Multiple Intelligent Agents. One Connected Faculty.");
define("APP_DESC", "FILKOM AI Agent Orchestrator — Multi-Agent Faculty Ecosystem");
define("APP_VERSION", "3.0.0");
define("APP_AUTHOR", "Fakultas Ilmu Komputer (FILKOM) — Universitas Klabat");

$rawEncKey = getenv('APP_ENC_KEY') ?: 'FILKOM_ORCHESTRATOR_AES256_KEY_2026';
$rawBidxKey = getenv('APP_BIDX_KEY') ?: 'FILKOM_ORCHESTRATOR_BIDX_KEY_2026';
define('APP_ENC_KEY', hash('sha256', $rawEncKey, true));
define('APP_BIDX_KEY', hash('sha256', $rawBidxKey, true));
define('OPENAI_BASE_URL', rtrim(getenv('OPENAI_BASE_URL') ?: 'https://api.openai.com/v1', '/'));
