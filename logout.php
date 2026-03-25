<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/auth.php';
$auth = new Auth();
if ($auth->isLoggedIn()) ActivityLog::record('logout');
$auth->logout();
header('Location: ' . APP_URL . '/login.php');
exit;
