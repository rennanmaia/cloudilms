<?php
/**
 * CloudiLMS – Download seguro de anexo de aula
 * Verifica matrícula (ou role admin) antes de servir o arquivo.
 * Suporta byte-range requests para que vídeo/áudio possam ser seek-ados.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';

$auth = new Auth();
$auth->requireLogin();

$attId = (int)($_GET['attachment'] ?? 0);
if (!$attId) { http_response_code(404); exit; }

$model = new CourseModel();
$att   = $model->getAttachmentById($attId);

if (!$att || empty($att['file_path'])) {
    http_response_code(404);
    exit;
}

// ── Controle de acesso ───────────────────────────────────────────────────────
if ($auth->isAdmin()) {
    // admins podem baixar qualquer arquivo
} else {
    $lesson = $model->getLessonById((int)$att['lesson_id']);
    if (!$lesson || !$model->isEnrolled((int)$_SESSION['user_id'], (int)$lesson['course_id'])) {
        http_response_code(403);
        exit('Acesso não autorizado.');
    }
}

// ── Caminho físico (basename previne path traversal) ─────────────────────────
$storagePath = __DIR__ . '/uploads/attachments/' . basename($att['file_path']);
if (!file_exists($storagePath) || !is_file($storagePath)) {
    http_response_code(404);
    exit;
}

$mime     = $att['mime_type'] ?: 'application/octet-stream';
$fileSize = filesize($storagePath);

// Inline vs attachment
$inlineMimes = ['application/pdf'];
$isInline    = in_array($mime, $inlineMimes)
              || str_starts_with($mime, 'video/')
              || str_starts_with($mime, 'audio/')
              || str_starts_with($mime, 'image/');
$disposition = $isInline ? 'inline' : 'attachment';

// ── Byte-range support (seek em vídeo/áudio) ─────────────────────────────────
$start = 0;
$end   = $fileSize - 1;

header('Accept-Ranges: bytes');
header('Content-Type: ' . $mime);
header('Content-Disposition: ' . $disposition . '; filename="' . addslashes($att['title']) . '"');

if (isset($_SERVER['HTTP_RANGE'])) {
    if (preg_match('/bytes=(\d+)-(\d*)/', $_SERVER['HTTP_RANGE'], $m)) {
        $start = (int)$m[1];
        $end   = ($m[2] !== '') ? (int)$m[2] : $fileSize - 1;
        $end   = min($end, $fileSize - 1);
    }
    $length = $end - $start + 1;
    http_response_code(206);
    header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
    header('Content-Length: ' . $length);
} else {
    header('Content-Length: ' . $fileSize);
}

$handle = fopen($storagePath, 'rb');
fseek($handle, $start);
$remaining = $end - $start + 1;
while ($remaining > 0 && !feof($handle)) {
    $chunk = min(8192, $remaining);
    echo fread($handle, $chunk);
    $remaining -= $chunk;
}
fclose($handle);
exit;
