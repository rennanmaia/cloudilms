<?php
/**
 * CloudiLMS — Gerador automático de manifesto de vídeos
 * ═══════════════════════════════════════════════════════
 *
 * ESTRUTURA ESPERADA DA PASTA DE VÍDEOS:
 *
 *   /opt/mk-auth/admin/cursos/         ← raiz (coloque este script aqui)
 *     generate_manifest.php
 *     watch_videos.sh
 *     index.json                        ← gerado automaticamente (lista de cursos)
 *
 *     Nome do Curso 1/                  ← pasta = um curso
 *       manifest.json                   ← gerado automaticamente
 *       01 - Introdução.mp4             ← vídeo sem seção (raiz do curso)
 *       Seção 1 - Fundamentos/          ← subpasta = tópico/seção
 *         01 - Variáveis.mp4
 *         02 - Tipos de dados.mp4
 *       Seção 2 - Avançado/
 *         01 - Funções.mp4
 *
 *     Nome do Curso 2/
 *       ...
 *
 * USO VIA CLI:
 *   php generate_manifest.php             → gera/atualiza todos os manifestos
 *   php generate_manifest.php --course="Nome do Curso"  → apenas um curso
 *
 * USO VIA HTTP (pelo admin do mk-auth):
 *   GET http://IP-DO-MK-AUTH/cursos/generate_manifest.php?secret=SEU_TOKEN
 *
 * CONFIGURAÇÃO NO CloudiLMS:
 *   Admin → Cursos → Editar → Tipo de fonte: HTTP
 *   URL: http://IP-DO-MK-AUTH/cursos/Nome%20do%20Curso/manifest.json
 */

// ════════════════════════════════════════════════════════════════════════════
//  CONFIGURAÇÃO — edite antes de usar
// ════════════════════════════════════════════════════════════════════════════

/** URL pública base desta pasta (sem barra no final)
 *  No mk-auth use o IP ou domínio do servidor + /cursos */
define('BASE_URL', 'http://IP-DO-MK-AUTH/cursos');

/** Token secreto para chamadas HTTP (troque por algo aleatório forte) */
define('SECRET_TOKEN', 'troque-por-um-token-seguro-aqui-32chars');

/** Detectar duração dos vídeos via ffprobe (recomendado, mas opcional) */
define('USE_FFPROBE', true);
define('FFPROBE_PATH', '/usr/bin/ffprobe');

/** Extensões de vídeo reconhecidas */
define('VIDEO_EXTENSIONS', ['mp4', 'webm', 'mov', 'mkv', 'avi', 'ogv', 'm4v']);

// ════════════════════════════════════════════════════════════════════════════
//  FIM DA CONFIGURAÇÃO
// ════════════════════════════════════════════════════════════════════════════

$rootDir = __DIR__;
$isCli   = (PHP_SAPI === 'cli');

// ── Segurança HTTP ───────────────────────────────────────────────────────────
if (!$isCli) {
    header('Content-Type: text/plain; charset=utf-8');
    $token = $_GET['secret'] ?? '';
    if (!hash_equals(SECRET_TOKEN, $token)) {
        http_response_code(403);
        exit("403 Acesso negado.\nForneça ?secret=TOKEN correto.");
    }
}

// ── Argumento --course (CLI) ─────────────────────────────────────────────────
$onlyCourse = null;
if ($isCli) {
    foreach ($argv ?? [] as $arg) {
        if (str_starts_with($arg, '--course=')) {
            $onlyCourse = substr($arg, 9);
        }
    }
}

// ── Geração ──────────────────────────────────────────────────────────────────
$results = generateAllManifests($rootDir, $onlyCourse);
echo implode("\n", $results) . "\n";
exit(0);


// ════════════════════════════════════════════════════════════════════════════
//  Funções
// ════════════════════════════════════════════════════════════════════════════

function generateAllManifests(string $rootDir, ?string $onlyCourse): array
{
    $log     = [];
    $courses = [];

    // Nomes de arquivos/pastas a ignorar na raiz
    $skip = [
        'generate_manifest.php', 'watch_videos.sh',
        'cloudilms-video-watcher.service', 'index.json',
        '.htaccess', '.gitignore',
    ];

    $items = scandir($rootDir);
    natsort($items);

    foreach ($items as $item) {
        if ($item[0] === '.') continue;
        if (in_array($item, $skip, true)) continue;

        $path = $rootDir . '/' . $item;
        if (!is_dir($path)) continue;

        if ($onlyCourse !== null && $item !== $onlyCourse) continue;

        $result   = generateCourseManifest($path, $item);
        $log[]    = $result['log'];
        $courses[] = [
            'name'          => $item,
            'manifest_url'  => BASE_URL . '/' . rawurlencode($item) . '/manifest.json',
            'video_count'   => $result['count'],
        ];
    }

    if ($onlyCourse === null) {
        // Atualiza index.json na raiz com lista de todos os cursos
        $indexPath = $rootDir . '/index.json';
        file_put_contents(
            $indexPath,
            json_encode($courses, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
        );
        $log[] = "📋 index.json atualizado (" . count($courses) . " curso(s))";
    }

    return $log;
}

function generateCourseManifest(string $courseDir, string $courseName): array
{
    $entries     = [];
    $courseSlug  = rawurlencode($courseName);

    // 1. Vídeos na raiz da pasta do curso (sem seção)
    foreach (listVideos($courseDir) as $file) {
        $url       = BASE_URL . '/' . $courseSlug . '/' . rawurlencode($file);
        $entries[] = makeEntry($courseDir . '/' . $file, $file, $url, null);
    }

    // 2. Subpastas (seções/tópicos) — ordenação natural
    $subs = [];
    foreach (scandir($courseDir) as $item) {
        if ($item[0] === '.') continue;
        if (is_dir($courseDir . '/' . $item)) {
            $subs[] = $item;
        }
    }
    natsort($subs);

    foreach ($subs as $section) {
        $sectionDir  = $courseDir . '/' . $section;
        $sectionSlug = rawurlencode($section);

        foreach (listVideos($sectionDir) as $file) {
            $url       = BASE_URL . '/' . $courseSlug . '/' . $sectionSlug . '/' . rawurlencode($file);
            $entries[] = makeEntry($sectionDir . '/' . $file, $file, $url, $section);
        }
    }

    $manifestPath = $courseDir . '/manifest.json';
    $written = file_put_contents(
        $manifestPath,
        json_encode($entries, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );

    $count = count($entries);
    $status = $written !== false ? '✅' : '❌';

    return [
        'log'   => "{$status} {$courseName}: {$count} vídeo(s) → manifest.json " . ($written !== false ? 'atualizado' : 'ERRO DE ESCRITA'),
        'count' => $count,
    ];
}

function makeEntry(string $filePath, string $fileName, string $url, ?string $folder): array
{
    // Remove prefixo numérico: "01 - ", "01_", "01." etc.
    $title = pathinfo($fileName, PATHINFO_FILENAME);
    $title = preg_replace('/^\d+[\s\-_.]+/', '', $title);
    $title = str_replace(['_', '-'], ' ', $title);
    $title = trim($title);

    $ext  = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
    $mime = mimeFromExt($ext);

    $entry = [
        'url'  => $url,
        'name' => $title !== '' ? $title : pathinfo($fileName, PATHINFO_FILENAME),
        'mime' => $mime,
    ];

    $duration = getVideoDuration($filePath);
    if ($duration !== null) $entry['duration'] = $duration;
    if ($folder   !== null) $entry['folder']   = $folder;

    return $entry;
}

function listVideos(string $dir): array
{
    if (!is_dir($dir)) return [];
    $videos = [];
    foreach (scandir($dir) as $f) {
        if ($f[0] === '.') continue;
        if (!is_file($dir . '/' . $f)) continue;
        $ext = strtolower(pathinfo($f, PATHINFO_EXTENSION));
        if (in_array($ext, VIDEO_EXTENSIONS, true)) {
            $videos[] = $f;
        }
    }
    natsort($videos);
    return array_values($videos);
}

function getVideoDuration(string $filePath): ?int
{
    if (!USE_FFPROBE || !is_executable(FFPROBE_PATH)) return null;
    $cmd    = FFPROBE_PATH
            . ' -v quiet -print_format json -show_format '
            . escapeshellarg($filePath)
            . ' 2>/dev/null';
    $output = shell_exec($cmd);
    if (!$output) return null;
    $data = json_decode($output, true);
    $secs = (float)($data['format']['duration'] ?? 0);
    return $secs > 0 ? (int)round($secs) : null;
}

function mimeFromExt(string $ext): string
{
    return match ($ext) {
        'mp4', 'm4v' => 'video/mp4',
        'webm'       => 'video/webm',
        'ogg', 'ogv' => 'video/ogg',
        'mov'        => 'video/quicktime',
        'mkv'        => 'video/x-matroska',
        'avi'        => 'video/x-msvideo',
        default      => 'video/mp4',
    };
}
