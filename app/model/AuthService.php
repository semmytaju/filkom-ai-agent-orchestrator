<?php

class AuthService {
    private Database $db;
    private RbacService $rbac;

    public function __construct(?Database $db = null) {
        $this->db = $db ?: new Database();
        $this->rbac = new RbacService($this->db);
    }

    public function authenticate(string $identifier, string $password, ?string $totpCode, string $ip, string $userAgent): array {
        if (!$this->db->isConnected() || !$this->db->hasTable('tbl_users')) {
            return ['success' => false, 'message' => 'Authentication service is unavailable.'];
        }
        $ident = trim($identifier);
        if ($ident === '' || $password === '') {
            return ['success' => false, 'message' => 'Username/Email and password are required.'];
        }

        $this->db->prepare("SELECT * FROM tbl_users WHERE (LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e)) AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':u', $ident);
        $this->db->bind(':e', $ident);
        $user = $this->db->single();

        if (!$user) {
            return ['success' => false, 'message' => 'Invalid account credentials or incorrect password.'];
        }

        if (($user['status'] ?? 'active') !== 'active') {
            return ['success' => false, 'message' => 'Your account is currently inactive or suspended. Contact Administrator.'];
        }

        if (!empty($user['locked_until'])) {
            $lockTs = strtotime((string)$user['locked_until']);
            if ($lockTs && $lockTs > time()) {
                $waitMin = max(1, (int)ceil(($lockTs - time()) / 60));
                return ['success' => false, 'message' => "Account temporarily locked. Try again in {$waitMin} minute(s)."];
            }
        }

        if (!Security::verify_password($password, (string)$user['password_hash'])) {
            $fails = (int)($user['failed_attempts'] ?? 0) + 1;
            $lockSql = $fails >= 5 ? ", locked_until = DATE_ADD(NOW(), INTERVAL 15 MINUTE)" : "";
            $this->db->prepare("UPDATE tbl_users SET failed_attempts = :f {$lockSql} WHERE id = :id");
            $this->db->bind(':f', $fails, PDO::PARAM_INT);
            $this->db->bind(':id', (int)$user['id'], PDO::PARAM_INT);
            $this->db->execute();

            $rem = max(0, 5 - $fails);
            $msg = $rem > 0
                ? "Incorrect password. Remaining attempts before lockout: {$rem}."
                : "Account locked for 15 minutes after 5 failed sign-in attempts.";
            return ['success' => false, 'message' => $msg];
        }

        if ((int)($user['mfa_enabled'] ?? 0) === 1) {
            $code = preg_replace('/\s+/', '', (string)($totpCode ?? ''));
            if ($code === '') {
                return ['success' => false, 'mfa_required' => true, 'message' => 'Multi-Factor Authentication code is required.'];
            }
            $secret = $this->decryptSecret((string)($user['mfa_secret_encrypted'] ?? ''));
            if ($secret === '' || !$this->verifyTotp($secret, $code)) {
                return ['success' => false, 'mfa_required' => true, 'message' => 'Invalid MFA verification code.'];
            }
        }

        $this->db->prepare("UPDATE tbl_users SET failed_attempts = 0, locked_until = NULL, last_login_at = NOW(), last_login_ip = :ip WHERE id = :id");
        $this->db->bind(':ip', $ip);
        $this->db->bind(':id', (int)$user['id'], PDO::PARAM_INT);
        $this->db->execute();

        $ctx = $this->rbac->getUserContext((int)$user['id'], true);
        if (!$ctx || empty($ctx['role_codes'])) {
            return ['success' => false, 'message' => 'No active role is assigned to this account.'];
        }

        unset($user['password_hash'], $user['mfa_secret_encrypted'], $user['reset_token_hash']);
        $user['role'] = $ctx['primary_role_code'];
        $user['roles'] = $ctx['role_codes'];
        $user['zone_code'] = $ctx['zone_code'];

        return [
            'success' => true,
            'user' => $user,
            'context' => $ctx
        ];
    }

    public function initiatePasswordReset(string $identifier): array {
        $ident = trim($identifier);
        if ($ident === '' || !$this->db->isConnected()) {
            return ['success' => false, 'message' => 'Valid username or email is required.'];
        }
        $this->db->prepare("SELECT id, username, email, status FROM tbl_users WHERE (LOWER(username) = LOWER(:u) OR LOWER(email) = LOWER(:e)) AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':u', $ident);
        $this->db->bind(':e', $ident);
        $user = $this->db->single();

        if ($user && ($user['status'] ?? '') === 'active') {
            $token = bin2hex(random_bytes(24));
            $hash = hash('sha256', $token);
            $this->db->prepare("UPDATE tbl_users SET reset_token_hash = :th, reset_token_expires_at = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = :id");
            $this->db->bind(':th', $hash);
            $this->db->bind(':id', (int)$user['id'], PDO::PARAM_INT);
            $this->db->execute();

            $this->db->prepare("INSERT INTO tbl_audit_logs (action, target_table, record_id, description, user_id, user_name, role_code, ip_address)
                VALUES ('PASSWORD_RESET_REQUEST', 'tbl_users', :rid, :desc, :uid, :uname, 'SYSTEM', :ip)");
            $this->db->bind(':rid', (string)$user['id']);
            $this->db->bind(':desc', "Password reset requested for {$user['username']}.");
            $this->db->bind(':uid', (string)$user['id']);
            $this->db->bind(':uname', (string)$user['username']);
            $this->db->bind(':ip', Security::get_client_ip());
            $this->db->execute();
        }

        return [
            'success' => true,
            'message' => 'If an active matching account exists, a 30-minute reset request has been logged for verification or administrator dispatch.'
        ];
    }

    public function confirmPasswordReset(string $token, string $newPassword): array {
        $cleanToken = trim($token);
        if (strlen($cleanToken) < 16 || strlen($newPassword) < 8) {
            return ['success' => false, 'message' => 'Valid reset token and new password (minimum 8 characters) are required.'];
        }
        $hash = hash('sha256', $cleanToken);
        $this->db->prepare("SELECT id, username FROM tbl_users WHERE reset_token_hash = :th AND reset_token_expires_at >= NOW() AND deleted_at IS NULL LIMIT 1");
        $this->db->bind(':th', $hash);
        $user = $this->db->single();
        if (!$user) {
            return ['success' => false, 'message' => 'Reset token is invalid or has expired.'];
        }
        $pwdHash = Security::hash_password($newPassword);
        $this->db->prepare("UPDATE tbl_users SET password_hash = :ph, reset_token_hash = NULL, reset_token_expires_at = NULL, failed_attempts = 0, locked_until = NULL WHERE id = :id");
        $this->db->bind(':ph', $pwdHash);
        $this->db->bind(':id', (int)$user['id'], PDO::PARAM_INT);
        $ok = $this->db->execute();
        return [
            'success' => $ok,
            'message' => $ok ? 'Password updated successfully. You may now sign in.' : 'Unable to update password.'
        ];
    }

    public function generateMfaProvision(int $userId): array {
        $secret = $this->generateBase32Secret(16);
        $enc = $this->encryptSecret($secret);
        $this->db->prepare("UPDATE tbl_users SET mfa_secret_encrypted = :sec WHERE id = :id");
        $this->db->bind(':sec', $enc);
        $this->db->bind(':id', $userId, PDO::PARAM_INT);
        $this->db->execute();
        return ['success' => true, 'secret' => $secret];
    }

    public function enableMfa(int $userId, string $code): array {
        $this->db->prepare("SELECT mfa_secret_encrypted FROM tbl_users WHERE id = :id LIMIT 1");
        $this->db->bind(':id', $userId, PDO::PARAM_INT);
        $row = $this->db->single();
        if (!$row || empty($row['mfa_secret_encrypted'])) {
            return ['success' => false, 'message' => 'Generate an MFA secret first.'];
        }
        $secret = $this->decryptSecret((string)$row['mfa_secret_encrypted']);
        if (!$this->verifyTotp($secret, $code)) {
            return ['success' => false, 'message' => 'Verification code is invalid.'];
        }
        $this->db->prepare("UPDATE tbl_users SET mfa_enabled = 1 WHERE id = :id");
        $this->db->bind(':id', $userId, PDO::PARAM_INT);
        $this->db->execute();
        RbacService::clearCache($userId);
        return ['success' => true, 'message' => 'Multi-Factor Authentication enabled.'];
    }

    public function disableMfa(int $userId): array {
        $this->db->prepare("UPDATE tbl_users SET mfa_enabled = 0, mfa_secret_encrypted = NULL WHERE id = :id");
        $this->db->bind(':id', $userId, PDO::PARAM_INT);
        $this->db->execute();
        RbacService::clearCache($userId);
        return ['success' => true, 'message' => 'Multi-Factor Authentication disabled.'];
    }

    public function encryptSecret(string $plain): string {
        $key = defined('APP_ENC_KEY') ? APP_ENC_KEY : hash('sha256', 'FILKOM_ORCHESTRATOR_KEY', true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($plain, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        return $cipher === false ? '' : base64_encode($iv . $cipher);
    }

    public function decryptSecret(string $encoded): string {
        $raw = base64_decode($encoded, true);
        if ($raw === false || strlen($raw) < 17) return '';
        $key = defined('APP_ENC_KEY') ? APP_ENC_KEY : hash('sha256', 'FILKOM_ORCHESTRATOR_KEY', true);
        $plain = openssl_decrypt(substr($raw, 16), 'AES-256-CBC', $key, OPENSSL_RAW_DATA, substr($raw, 0, 16));
        return $plain !== false ? $plain : '';
    }

    private function generateBase32Secret(int $length = 16): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $secret = '';
        for ($i = 0; $i < $length; $i++) {
            $secret .= $alphabet[random_int(0, 31)];
        }
        return $secret;
    }

    public function verifyTotp(string $base32Secret, string $code, int $window = 1): bool {
        $cleanCode = preg_replace('/\D/', '', $code);
        if (strlen($cleanCode) !== 6) return false;
        $key = $this->base32Decode($base32Secret);
        if ($key === '') return false;
        $slice = (int)floor(time() / 30);
        for ($i = -$window; $i <= $window; $i++) {
            $expected = $this->computeTotp($key, $slice + $i);
            if (hash_equals($expected, $cleanCode)) {
                return true;
            }
        }
        return false;
    }

    private function computeTotp(string $key, int $timeSlice): string {
        $time = pack('N*', 0) . pack('N*', $timeSlice);
        $hm = hash_hmac('sha1', $time, $key, true);
        $offset = ord(substr($hm, -1)) & 0x0F;
        $hashPart = substr($hm, $offset, 4);
        $value = unpack('N', $hashPart)[1] & 0x7FFFFFFF;
        return str_pad((string)($value % 1000000), 6, '0', STR_PAD_LEFT);
    }

    private function base32Decode(string $b32): string {
        $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
        $b32 = strtoupper(preg_replace('/[^A-Z2-7]/', '', $b32));
        $binary = '';
        $len = strlen($b32);
        for ($i = 0; $i < $len; $i++) {
            $pos = strpos($alphabet, $b32[$i]);
            if ($pos === false) continue;
            $binary .= str_pad(decbin($pos), 5, '0', STR_PAD_LEFT);
        }
        $bytes = '';
        foreach (str_split($binary, 8) as $chunk) {
            if (strlen($chunk) === 8) {
                $bytes .= chr((int)bindec($chunk));
            }
        }
        return $bytes;
    }
}
