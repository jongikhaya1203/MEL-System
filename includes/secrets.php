<?php
/**
 * Encryption at rest for stored credentials (SMTP password, AD bind
 * password). AES-256-GCM with a per-install key kept in config/app_key.php
 * (generated on first use, git-ignored, and a PHP file so a browser request
 * for it returns nothing). Back up that file with the database: without it,
 * stored credentials can't be decrypted and must be re-entered.
 */

const SECRET_PREFIX = 'enc:v1:';

function secret_key(): string
{
    static $key = null;
    if ($key !== null) {
        return $key;
    }
    $file = __DIR__ . '/../config/app_key.php';
    if (!is_file($file)) {
        $generated = base64_encode(random_bytes(32));
        $php = "<?php\n// Encryption key for stored credentials (SMTP / Active Directory passwords).\n"
             . "// Generated automatically. Keep it secret, back it up, never commit it.\nreturn '" . $generated . "';\n";
        if (file_put_contents($file, $php, LOCK_EX) === false) {
            throw new RuntimeException('Could not create config/app_key.php — check the config folder is writable.');
        }
    }
    $key = base64_decode((string)(require $file), true);
    if ($key === false || strlen($key) !== 32) {
        throw new RuntimeException('config/app_key.php does not contain a valid 32-byte key.');
    }
    return $key;
}

function encrypt_secret(?string $plain): ?string
{
    if ($plain === null || $plain === '') {
        return null;
    }
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return SECRET_PREFIX . base64_encode($iv . $tag . $cipher);
}

/** Decrypts a stored secret. Legacy plaintext (no prefix) is returned as-is; an undecryptable value returns ''. */
function decrypt_secret(?string $stored): string
{
    if ($stored === null || $stored === '') {
        return '';
    }
    if (strpos($stored, SECRET_PREFIX) !== 0) {
        return $stored;
    }
    $raw = base64_decode(substr($stored, strlen(SECRET_PREFIX)), true);
    if ($raw === false || strlen($raw) < 29) {
        return '';
    }
    $plain = openssl_decrypt(substr($raw, 28), 'aes-256-gcm', secret_key(), OPENSSL_RAW_DATA, substr($raw, 0, 12), substr($raw, 12, 16));
    return $plain === false ? '' : $plain;
}
