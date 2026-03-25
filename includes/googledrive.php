<?php
/**
 * CloudiLMS - Integração com Google Drive
 * Usa apenas a Google Drive API v3 com API Key pública (sem OAuth)
 * Funciona para pastas públicas "qualquer pessoa com o link pode ver"
 */
require_once __DIR__ . '/config.php';

class GoogleDrive {

    private string $apiKey;
    private string $baseUrl = 'https://www.googleapis.com/drive/v3';

    public function __construct() {
        $this->apiKey = GDRIVE_API_KEY;
    }

    /**
     * Extrai o ID da pasta a partir de uma URL do Google Drive
     */
    public static function extractFolderId(string $url): ?string {
        // Formatos aceitos:
        // https://drive.google.com/drive/folders/FOLDER_ID
        // https://drive.google.com/drive/u/0/folders/FOLDER_ID
        // https://drive.google.com/open?id=FOLDER_ID
        if (preg_match('/\/folders\/([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        if (preg_match('/[?&]id=([a-zA-Z0-9_-]+)/', $url, $m)) {
            return $m[1];
        }
        // Aceita ID direto também
        if (preg_match('/^[a-zA-Z0-9_-]{20,}$/', trim($url))) {
            return trim($url);
        }
        return null;
    }

    /**
     * Busca todos os arquivos de vídeo dentro de uma pasta do Google Drive
     */
    public function getFolderFiles(string $folderId, string $pageToken = ''): array {
        $query = urlencode("'{$folderId}' in parents and trashed = false");
        $fields = urlencode('nextPageToken,files(id,name,mimeType,size,createdTime,thumbnailLink,videoMediaMetadata,description)');

        $url = "{$this->baseUrl}/files?q={$query}&fields={$fields}&key={$this->apiKey}&orderBy=name&pageSize=100";
        if ($pageToken) {
            $url .= '&pageToken=' . urlencode($pageToken);
        }

        $response = $this->httpGet($url);
        if (!$response) return [];

        $data = json_decode($response, true);
        if (!isset($data['files'])) return [];

        $files = [];
        foreach ($data['files'] as $file) {
            $mime = $file['mimeType'] ?? '';
            // Inclui vídeos e subpastas
            if (str_starts_with($mime, 'video/') || $mime === 'application/vnd.google-apps.folder') {
                $files[] = $file;
            }
        }

        // Paginar se necessário
        if (!empty($data['nextPageToken'])) {
            $more = $this->getFolderFiles($folderId, $data['nextPageToken']);
            $files = array_merge($files, $more);
        }

        return $files;
    }

    /**
     * Retorna a URL de embed/player para um arquivo de vídeo do Google Drive
     */
    public static function getEmbedUrl(string $fileId): string {
        return "https://drive.google.com/file/d/{$fileId}/preview";
    }

    /**
     * Retorna a URL de download direto
     */
    public static function getDirectUrl(string $fileId): string {
        return "https://drive.google.com/uc?export=download&id={$fileId}";
    }

    /**
     * Retorna a URL de thumbnail
     */
    public static function getThumbnailUrl(string $fileId): string {
        return "https://drive.google.com/thumbnail?id={$fileId}&sz=w320";
    }

    /**
     * Verifica se a API Key está configurada e funcional
     */
    public function testConnection(string $folderId): array {
        if (empty($this->apiKey)) {
            return ['success' => false, 'error' => 'API Key não configurada.'];
        }
        $url = "{$this->baseUrl}/files/{$folderId}?fields=id,name&key={$this->apiKey}";
        $response = $this->httpGet($url);
        if (!$response) {
            return ['success' => false, 'error' => 'Falha na conexão com a API do Google Drive.'];
        }
        $data = json_decode($response, true);
        if (isset($data['error'])) {
            return ['success' => false, 'error' => $data['error']['message'] ?? 'Erro desconhecido.'];
        }
        return ['success' => true, 'name' => $data['name'] ?? 'Pasta sem nome'];
    }

    /**
     * HTTP GET simples com cURL
     */
    private function httpGet(string $url): ?string {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'CloudiLMS/1.0',
        ]);
        $result = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        return ($result !== false && $httpCode === 200) ? $result : null;
    }
}
