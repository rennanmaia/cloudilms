<?php
/**
 * CloudiLMS – Admin AJAX: browse a media source
 *
 * GET  /api/media_browse.php?source=<type>&path=<path>
 *
 * source  gdrive | http | ftp | local
 * path    Folder reference appropriate for the source type:
 *           gdrive  → Google Drive folder URL or ID
 *           http    → HTTP URL (autoindex dir, JSON manifest, or single file)
 *           ftp     → Relative path from ftp_base_path, e.g. "courses/python/"
 *           local   → Relative path from local_base_path, e.g. "courses/python/"
 *
 * Returns JSON: {"ok":true, "files":[...]} | {"ok":false, "error":"..."}
 */

require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/mediasource.php';

header('Content-Type: application/json; charset=utf-8');

$auth = new Auth();
if (!$auth->isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Acesso restrito a administradores.']);
    exit;
}

$type = trim($_GET['source'] ?? '');
$path = trim($_GET['path']   ?? '');

$validTypes = ['gdrive', 'http', 'ftp', 'local'];
if (!in_array($type, $validTypes, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => "Tipo de fonte inválido: '{$type}'."]);
    exit;
}

try {
    $db     = Database::getConnection();
    $source = MediaSourceBase::make($type, $db);
    $files  = $source->listFiles($path);

    // Separate folders from files for easier UI rendering
    $folders  = array_values(array_filter($files, fn($f) => $f['isFolder']));
    $videos   = array_values(array_filter($files, fn($f) => !$f['isFolder']));

    echo json_encode([
        'ok'      => true,
        'source'  => $type,
        'path'    => $path,
        'folders' => $folders,
        'files'   => $videos,
        'total'   => count($videos),
    ]);
} catch (\InvalidArgumentException $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
}
