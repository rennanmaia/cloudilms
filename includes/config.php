<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cloudilms');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'CloudiLMS');
define('APP_URL', 'http://localhost/cloudilms');
define('APP_VERSION', '1.0.0');
// Chave de criptografia AES-256 (32 bytes em hex). Nunca commitar em repositórios públicos.
define('ENCRYPT_KEY', '388edc0cafbcab7966096da640f3378930fd81e41a7f2149a4a9f3069378e950');
define('SESSION_LIFETIME', 3600 * 8);
define('HASH_ALGO', PASSWORD_BCRYPT);

/**
 * Criptografa um valor com AES-256-CBC.
 * O IV aleatório é prefixado ao ciphertext e tudo é codificado em base64.
 */
function encryptValue(string $plain): string {
    if ($plain === '') return '';
    $iv     = random_bytes(16);
    $cipher = openssl_encrypt($plain, 'AES-256-CBC', hex2bin(ENCRYPT_KEY), OPENSSL_RAW_DATA, $iv);
    return base64_encode($iv . $cipher);
}

/**
 * Descriptografa um valor previamente criptografado por encryptValue().
 */
function decryptValue(string $stored): string {
    if ($stored === '') return '';
    $data = base64_decode($stored, true);
    if ($data === false || strlen($data) < 17) return '';
    $plain = openssl_decrypt(substr($data, 16), 'AES-256-CBC', hex2bin(ENCRYPT_KEY), OPENSSL_RAW_DATA, substr($data, 0, 16));
    return $plain !== false ? $plain : '';
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}
