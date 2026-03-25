<?php
define('DB_HOST', 'localhost');
define('DB_NAME', 'cloudilms');
define('DB_USER', 'root');
define('DB_PASS', '');
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'CloudiLMS');
define('APP_URL', 'http://localhost/cloudilms');
define('APP_VERSION', '1.0.0');
define('GDRIVE_API_KEY', 'AIzaSyAf0xVTp8WDkzvAwwD_qGOhh59_f3Rb-gQ');
define('SESSION_LIFETIME', 3600 * 8);
define('HASH_ALGO', PASSWORD_BCRYPT);
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}
