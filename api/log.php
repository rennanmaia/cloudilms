<?php
/**
 * CloudiLMS - API endpoint para rastreamento de atividade do usuário
 * Aceita requisições JSON (fetch e sendBeacon).
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/activity_log.php';

header('Content-Type: application/json');

// Apenas para usuários autenticados
if (empty($_SESSION['user_id'])) {
    echo json_encode(['ok' => false, 'err' => 'unauthenticated']);
    exit;
}

$raw  = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data)) {
    echo json_encode(['ok' => false, 'err' => 'invalid_json']);
    exit;
}

$action = $data['action'] ?? '';

// ── page_view: cria um registro e retorna o ID para o JS usar no beacon ──
if ($action === 'page_view') {
    $url   = filter_var($data['url'] ?? '', FILTER_SANITIZE_URL);
    $title = strip_tags(substr($data['title'] ?? '', 0, 255));

    // Detecta entidade pela URL para facilitar relatórios
    $entityType = null;
    $entityId   = null;
    if (preg_match('/watch\.php.*[?&]lesson=(\d+)/i', $url, $m)) {
        $entityType = 'lesson';
        $entityId   = (int)$m[1];
    }

    $logId = ActivityLog::record('page_view', [
        'entity_type'  => $entityType,
        'entity_id'    => $entityId,
        'entity_title' => $title ?: null,
        'page_url'     => $url ?: null,
    ]);
    echo json_encode(['ok' => true, 'id' => $logId]);
    exit;
}

// ── time_on_page: atualiza o tempo do registro criado pelo page_view ──
if ($action === 'time_on_page') {
    $logId   = (int)($data['log_id'] ?? 0);
    $seconds = (int)($data['seconds'] ?? 0);
    $userId  = (int)$_SESSION['user_id'];

    // Sanity: ignora valores absurdos (> 8h) e negativos
    if ($logId > 0 && $seconds > 0 && $seconds <= 28800) {
        ActivityLog::updateTimeOnPage($logId, $userId, $seconds);
    }
    echo json_encode(['ok' => true]);
    exit;
}

echo json_encode(['ok' => false, 'err' => 'unknown_action']);
