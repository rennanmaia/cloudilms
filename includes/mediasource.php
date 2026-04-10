<?php
/**
 * CloudiLMS – Multi-source media abstraction
 *
 * Supported source types:
 *   gdrive  Google Drive (public folder, API Key)
 *   http    Any HTTP/HTTPS server – direct URL, JSON manifest, or Apache/Nginx autoindex
 *   ftp     FTP server (browse via FTP, serve via configured HTTP base URL)
 *   local   Local filesystem path (serve via configured HTTP base URL)
 *
 * Each source returns a uniform file array:
 *   id          string   Unique identifier used to play/download.
 *                        For gdrive → Drive file ID.
 *                        For others → full HTTP URL of the video.
 *   name        string   Filename / display name.
 *   mimeType    string   e.g. "video/mp4" or "directory" for folders.
 *   isFolder    bool     True if the item is a sub-folder/sub-directory.
 *   size        int      File size in bytes (0 if unknown).
 *   duration    int|null Duration in seconds (null if unknown).
 *   subfolder   string|null  Folder name for grouping into topics (used by JSON manifest).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

// ── Abstract base ─────────────────────────────────────────────────────────────

abstract class MediaSourceBase {

    protected array $settings = [];
    protected PDO   $db;

    public function __construct(PDO $db) {
        $this->db = $db;
        $this->loadSettings();
    }

    protected function loadSettings(): void {
        $rows = $this->db->query('SELECT key_name, value FROM settings')->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            $this->settings[$r['key_name']] = $r['value'];
        }
    }

    protected function s(string $key, string $default = ''): string {
        return $this->settings[$key] ?? $default;
    }

    /**
     * List media files (and optionally subdirectories) at the given path.
     * @return array  Array of file objects (see header for schema).
     */
    abstract public function listFiles(string $path): array;

    /**
     * Return the URL/src used to play this file.
     * For gdrive: returns the Drive embed URL.
     * For others: returns the direct HTTP URL.
     */
    abstract public function getPlayerUrl(string $fileRef): string;

    /** Return the URL for downloading this file. */
    abstract public function getDownloadUrl(string $fileRef): string;

    /**
     * Test connectivity. Returns ['success'=>bool, 'name'=>...|'error'=>...].
     */
    abstract public function testConnection(string $path = ''): array;

    /**
     * Whether the player should use an <iframe> (true) or a <video> tag (false).
     */
    public function usesIframe(): bool { return false; }

    /**
     * Factory: create a source instance by type.
     */
    public static function make(string $type, PDO $db): self {
        return match ($type) {
            'gdrive' => new GoogleDriveSource($db),
            'http'   => new HttpSource($db),
            'ftp'    => new FtpSource($db),
            'local'  => new LocalSource($db),
            default  => throw new \InvalidArgumentException("Fonte desconhecida: {$type}"),
        };
    }

    public static function label(string $type): string {
        return match ($type) {
            'gdrive' => 'Google Drive',
            'http'   => 'HTTP / URL direta',
            'ftp'    => 'FTP',
            'local'  => 'Sistema de arquivos local',
            default  => $type,
        };
    }

    /** Strip video extension and leading numbering from a filename. */
    protected function cleanTitle(string $name): string {
        $name = preg_replace('/\.(mp4|mkv|avi|mov|webm|flv|wmv|m4v|ogv|3gp|mpeg|mpg)$/i', '', $name);
        $name = preg_replace('/^\d+[\s\.\-_]+/', '', $name);
        return trim($name);
    }

    protected function isVideoFile(string $filename): bool {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return in_array($ext, ['mp4','webm','mkv','avi','mov','flv','wmv','m4v','ogv','3gp','mpeg','mpg'], true);
    }

    protected function mimeFromExt(string $filename): string {
        $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        return match ($ext) {
            'mp4'        => 'video/mp4',
            'webm'       => 'video/webm',
            'mkv'        => 'video/x-matroska',
            'avi'        => 'video/x-msvideo',
            'mov'        => 'video/quicktime',
            'flv'        => 'video/x-flv',
            'wmv'        => 'video/x-ms-wmv',
            'm4v'        => 'video/mp4',
            'ogv'        => 'video/ogg',
            '3gp'        => 'video/3gpp',
            'mpeg','mpg' => 'video/mpeg',
            default      => 'video/mp4',
        };
    }

    protected function httpGet(string $url): ?string {
        if (!function_exists('curl_init')) return null;
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'CloudiLMS/1.0',
        ]);
        $result   = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($result !== false && $httpCode === 200) ? $result : null;
    }
}

// ── Google Drive source ───────────────────────────────────────────────────────

class GoogleDriveSource extends MediaSourceBase {

    private GoogleDrive $gdrive;

    public function __construct(PDO $db) {
        parent::__construct($db);
        require_once __DIR__ . '/googledrive.php';
        $this->gdrive = new GoogleDrive();
    }

    public function listFiles(string $path): array {
        $folderId = GoogleDrive::extractFolderId($path) ?? trim($path);
        if (!$folderId) return [];

        $raw = $this->gdrive->getFolderFiles($folderId);
        $out = [];
        foreach ($raw as $f) {
            $mime     = $f['mimeType'] ?? '';
            $isFolder = ($mime === 'application/vnd.google-apps.folder');
            if (!$isFolder && !str_starts_with($mime, 'video/')) continue;

            $dur = null;
            if (!empty($f['videoMediaMetadata']['durationMillis'])) {
                $dur = (int)round($f['videoMediaMetadata']['durationMillis'] / 1000);
            }
            $out[] = [
                'id'        => $f['id'],
                'name'      => $f['name'],
                'mimeType'  => $mime,
                'isFolder'  => $isFolder,
                'size'      => (int)($f['size'] ?? 0),
                'duration'  => $dur,
                'subfolder' => null,
            ];
        }
        return $out;
    }

    public function getPlayerUrl(string $fileRef): string {
        return GoogleDrive::getEmbedUrl($fileRef);
    }

    public function getDownloadUrl(string $fileRef): string {
        return GoogleDrive::getDirectUrl($fileRef);
    }

    public function testConnection(string $path = ''): array {
        $folderId = GoogleDrive::extractFolderId($path) ?? trim($path);
        return $this->gdrive->testConnection($folderId ?: '');
    }

    public function usesIframe(): bool { return true; }
}

// ── HTTP source ───────────────────────────────────────────────────────────────

class HttpSource extends MediaSourceBase {

    public function listFiles(string $path): array {
        if (empty($path)) return [];

        $urlPath = parse_url($path, PHP_URL_PATH) ?? '';

        // JSON manifest
        if (str_ends_with(strtolower($urlPath), '.json')) {
            return $this->listFromJson($path);
        }

        // Try as directory (Apache/Nginx autoindex)
        $html = $this->httpGet($path);
        if ($html !== null) {
            $files = $this->listFromAutoindex($path, $html);
            if (!empty($files)) return $files;
        }

        // Single video file URL
        $name = urldecode(basename($urlPath));
        if ($this->isVideoFile($name)) {
            return [[
                'id'        => $path,
                'name'      => $name,
                'mimeType'  => $this->mimeFromExt($name),
                'isFolder'  => false,
                'size'      => 0,
                'duration'  => null,
                'subfolder' => null,
            ]];
        }

        return [];
    }

    /**
     * JSON manifest format (flexible):
     * [{"url":"...", "name":"...", "mime":"...", "duration":120, "folder":"Topic A"}, ...]
     * OR: {"files":[...]} wrapper
     */
    private function listFromJson(string $url): array {
        $raw = $this->httpGet($url);
        if (!$raw) return [];

        $data = json_decode($raw, true);
        if (!is_array($data)) return [];

        // Support {"files":[...]} wrapper
        if (isset($data['files']) && is_array($data['files'])) {
            $data = $data['files'];
        }

        $files = [];
        foreach ($data as $item) {
            if (!is_array($item)) continue;
            $fileUrl  = trim($item['url']  ?? ($item['src'] ?? ''));
            $name     = trim($item['name'] ?? ($item['title'] ?? basename((string)parse_url($fileUrl, PHP_URL_PATH))));
            $mime     = $item['mime']     ?? ($item['mimeType'] ?? $this->mimeFromExt($name));
            $folder   = $item['folder']   ?? ($item['topic']   ?? null);
            $duration = isset($item['duration']) ? (int)$item['duration'] : null;
            if (!$fileUrl) continue;
            $files[] = [
                'id'        => $fileUrl,
                'name'      => $name ?: basename((string)parse_url($fileUrl, PHP_URL_PATH)),
                'mimeType'  => $mime,
                'isFolder'  => false,
                'size'      => (int)($item['size'] ?? 0),
                'duration'  => $duration,
                'subfolder' => $folder ? (string)$folder : null,
            ];
        }
        return $files;
    }

    /** Parse Apache/Nginx directory listing HTML. */
    private function listFromAutoindex(string $baseUrl, string $html): array {
        $base = rtrim($baseUrl, '/') . '/';
        preg_match_all('/<a\s[^>]*href="([^"#?][^"]*)"[^>]*>/i', $html, $m);
        $files = [];
        foreach ($m[1] as $href) {
            if ($href === '../' || $href === './' || str_starts_with($href, '?')) continue;
            $name = urldecode(rtrim(basename($href), '/'));
            $isDir = str_ends_with($href, '/');

            if ($isDir) {
                $fullUrl = str_starts_with($href, 'http') ? $href : $base . ltrim($href, '/');
                $files[] = [
                    'id'        => $fullUrl,
                    'name'      => $name,
                    'mimeType'  => 'directory',
                    'isFolder'  => true,
                    'size'      => 0,
                    'duration'  => null,
                    'subfolder' => null,
                ];
            } elseif ($this->isVideoFile($name)) {
                $fullUrl = str_starts_with($href, 'http') ? $href : $base . ltrim($href, '/');
                $files[] = [
                    'id'        => $fullUrl,
                    'name'      => $name,
                    'mimeType'  => $this->mimeFromExt($name),
                    'isFolder'  => false,
                    'size'      => 0,
                    'duration'  => null,
                    'subfolder' => null,
                ];
            }
        }
        return $files;
    }

    public function getPlayerUrl(string $fileRef): string { return $fileRef; }
    public function getDownloadUrl(string $fileRef): string { return $fileRef; }

    public function testConnection(string $path = ''): array {
        if (!$path || !filter_var($path, FILTER_VALIDATE_URL)) {
            return ['success' => false, 'error' => 'URL inválida.'];
        }
        $ch = curl_init($path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_NOBODY         => true,
        ]);
        curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err)              return ['success' => false, 'error' => $err];
        if ($code >= 200 && $code < 400) return ['success' => true, 'name' => parse_url($path, PHP_URL_HOST)];
        return ['success' => false, 'error' => "HTTP {$code}"];
    }
}

// ── FTP source ────────────────────────────────────────────────────────────────

class FtpSource extends MediaSourceBase {

    /** Connect to FTP and return the connection resource. Throws on failure. */
    private function connect(): mixed {
        if (!function_exists('ftp_connect')) {
            throw new \RuntimeException('A extensão PHP FTP não está habilitada (php_ftp).');
        }
        $host = $this->s('ftp_host');
        $port = max(1, (int)($this->s('ftp_port') ?: 21));
        if (!$host) throw new \RuntimeException('Host FTP não configurado.');

        $conn = @ftp_connect($host, $port, 10);
        if (!$conn) throw new \RuntimeException("Conexão FTP falhou: {$host}:{$port}");

        $user = $this->s('ftp_user');
        $pass = decryptValue($this->s('ftp_pass'));
        if (!@ftp_login($conn, $user, $pass)) {
            ftp_close($conn);
            throw new \RuntimeException("Autenticação FTP falhou (usuário: {$user})");
        }
        ftp_pasv($conn, true);
        return $conn;
    }

    /** Compute the fully-qualified FTP path for a given relative path. */
    private function ftpPath(string $path): string {
        $basePath = '/' . trim($this->s('ftp_base_path', '/'), '/');
        if ($path === '' || $path === '/') return $basePath;
        return $basePath . '/' . ltrim($path, '/');
    }

    /** Convert an FTP relative path to an HTTP URL using ftp_base_url. */
    private function toUrl(string $relPath): string {
        $base = rtrim($this->s('ftp_base_url'), '/');
        return $base . '/' . ltrim($relPath, '/');
    }

    public function listFiles(string $path): array {
        $basePath = '/' . trim($this->s('ftp_base_path', '/'), '/');
        $baseUrl  = rtrim($this->s('ftp_base_url'), '/');
        $ftpPath  = $this->ftpPath($path);

        try {
            $conn    = $this->connect();
            $rawList = ftp_rawlist($conn, $ftpPath);
            ftp_close($conn);
        } catch (\RuntimeException) {
            return [];
        }

        if (!is_array($rawList)) return [];

        $files = [];
        foreach ($rawList as $line) {
            // Unix-style listing: "-rw-r--r-- 1 user group 12345 Jan 1 00:00 filename"
            $parts = preg_split('/\s+/', trim($line), 9);
            if (count($parts) < 9) continue;

            $perms    = $parts[0];
            $size     = (int)$parts[4];
            $name     = $parts[8];
            if ($name === '.' || $name === '..') continue;

            $isDir    = ($perms[0] === 'd');
            $relDir   = ltrim(str_replace($basePath, '', $ftpPath), '/');

            if ($isDir) {
                $subPath = ($relDir ? $relDir . '/' : '') . $name;
                $files[] = [
                    'id'        => $subPath,        // relative path for recursive listFiles
                    'name'      => $name,
                    'mimeType'  => 'directory',
                    'isFolder'  => true,
                    'size'      => 0,
                    'duration'  => null,
                    'subfolder' => null,
                ];
            } elseif ($this->isVideoFile($name)) {
                $relFile = ($relDir ? $relDir . '/' : '') . $name;
                $files[] = [
                    'id'        => $baseUrl . '/' . $relFile,  // full HTTP URL
                    'name'      => $name,
                    'mimeType'  => $this->mimeFromExt($name),
                    'isFolder'  => false,
                    'size'      => $size,
                    'duration'  => null,
                    'subfolder' => null,
                ];
            }
        }
        usort($files, fn($a, $b) => ($b['isFolder'] - $a['isFolder']) ?: strnatcasecmp($a['name'], $b['name']));
        return $files;
    }

    public function getPlayerUrl(string $fileRef): string { return $fileRef; }
    public function getDownloadUrl(string $fileRef): string { return $fileRef; }

    public function testConnection(string $path = ''): array {
        try {
            $conn  = $this->connect();
            $fpath = $this->ftpPath($path ?: '/');
            $list  = ftp_nlist($conn, $fpath);
            ftp_close($conn);
            $count = is_array($list) ? count($list) : 0;
            return ['success' => true, 'name' => "Conectado ({$count} itens em '{$fpath}')"];
        } catch (\RuntimeException $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}

// ── Local filesystem source ───────────────────────────────────────────────────

class LocalSource extends MediaSourceBase {

    public function listFiles(string $path): array {
        $basePath = rtrim($this->s('local_base_path'), '/\\');
        $baseUrl  = rtrim($this->s('local_base_url'), '/');

        if (!$basePath) return [];

        // Sanitize: no path traversal
        $safePath = str_replace(['..', "\0"], '', $path);
        $absPath  = $basePath . DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $safePath), DIRECTORY_SEPARATOR);
        $absPath  = realpath($absPath);

        if (!$absPath || !str_starts_with($absPath, realpath($basePath) ?: '')) return [];
        if (!is_dir($absPath)) return [];

        $files = [];
        $it    = new \DirectoryIterator($absPath);
        foreach ($it as $entry) {
            if ($entry->isDot()) continue;
            $name = $entry->getFilename();

            // Compute relative path from basePath
            $relPath = ltrim(
                str_replace(DIRECTORY_SEPARATOR, '/', substr($entry->getPathname(), strlen($basePath))),
                '/'
            );

            if ($entry->isDir()) {
                $files[] = [
                    'id'        => $relPath,   // relative path for recursive listFiles
                    'name'      => $name,
                    'mimeType'  => 'directory',
                    'isFolder'  => true,
                    'size'      => 0,
                    'duration'  => null,
                    'subfolder' => null,
                ];
            } elseif ($entry->isFile() && $this->isVideoFile($name)) {
                $files[] = [
                    'id'        => $baseUrl . '/' . $relPath,  // full HTTP URL
                    'name'      => $name,
                    'mimeType'  => $this->mimeFromExt($name),
                    'isFolder'  => false,
                    'size'      => $entry->getSize(),
                    'duration'  => null,
                    'subfolder' => null,
                ];
            }
        }
        usort($files, fn($a, $b) => ($b['isFolder'] - $a['isFolder']) ?: strnatcasecmp($a['name'], $b['name']));
        return $files;
    }

    public function getPlayerUrl(string $fileRef): string { return $fileRef; }
    public function getDownloadUrl(string $fileRef): string { return $fileRef; }

    public function testConnection(string $path = ''): array {
        $basePath = $this->s('local_base_path');
        if (!$basePath) return ['success' => false, 'error' => 'Caminho base local não configurado.'];

        $abs = realpath($basePath);
        if (!$abs || !is_dir($abs)) {
            return ['success' => false, 'error' => "Diretório não encontrado: {$basePath}"];
        }
        $count = iterator_count(new \FilesystemIterator($abs, \FilesystemIterator::SKIP_DOTS));
        return ['success' => true, 'name' => "Acessível ({$count} itens em '{$abs}')"];
    }
}
