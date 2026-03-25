<?php
/**
 * CloudiLMS - Registro de atividades do usuário
 */
class ActivityLog {

    private static ?PDO $pdo = null;

    private static function db(): PDO {
        if (!self::$pdo) {
            self::$pdo = Database::getConnection();
        }
        return self::$pdo;
    }

    /**
     * Registra uma atividade. Retorna o ID inserido.
     *
     * Opções disponíveis em $opts:
     *   user_id, entity_type, entity_id, entity_title, page_url, meta (array)
     */
    public static function record(string $action, array $opts = []): int {
        $userId    = $opts['user_id'] ?? (isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null);
        $sessionId = session_id() ?: null;
        $ip        = self::getIp();
        $ua        = substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 350);
        $url       = $opts['page_url'] ?? (isset($_SERVER['REQUEST_URI']) ? substr($_SERVER['REQUEST_URI'], 0, 500) : null);
        $meta      = isset($opts['meta']) ? json_encode($opts['meta'], JSON_UNESCAPED_UNICODE) : null;

        $stmt = self::db()->prepare(
            'INSERT INTO activity_log
             (user_id, session_id, action, entity_type, entity_id, entity_title, page_url, ip, user_agent, meta, created_at)
             VALUES (?,?,?,?,?,?,?,?,?,?, NOW())'
        );
        $stmt->execute([
            $userId,
            $sessionId,
            $action,
            $opts['entity_type']  ?? null,
            $opts['entity_id']    ?? null,
            $opts['entity_title'] ?? null,
            $url,
            $ip,
            $ua,
            $meta,
        ]);
        return (int)self::db()->lastInsertId();
    }

    /**
     * Atualiza o tempo na página de um registro.
     * Verifica user_id por segurança para evitar que um usuário edite registros de outro.
     */
    public static function updateTimeOnPage(int $logId, int $userId, int $seconds): void {
        self::db()->prepare(
            'UPDATE activity_log SET time_on_page = ? WHERE id = ? AND user_id = ?'
        )->execute([$seconds, $logId, $userId]);
    }

    private static function getIp(): string {
        foreach (['HTTP_X_FORWARDED_FOR', 'HTTP_CLIENT_IP', 'REMOTE_ADDR'] as $key) {
            if (!empty($_SERVER[$key])) {
                $ip = trim(explode(',', $_SERVER[$key])[0]);
                if (filter_var($ip, FILTER_VALIDATE_IP)) return $ip;
            }
        }
        return '0.0.0.0';
    }
}
