<?php
/**
 * CloudiLMS - Classe de autenticação
 */
require_once __DIR__ . '/database.php';

class Auth {
    private PDO $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function login(string $email, string $password): bool {
        $stmt = $this->db->prepare('SELECT id, name, password, role FROM users WHERE email = ? AND active = 1 LIMIT 1');
        $stmt->execute([$email]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $_SESSION['user_id']   = $user['id'];
            $_SESSION['user_name'] = $user['name'];
            $_SESSION['user_role'] = $user['role'];
            $_SESSION['logged_at'] = time();
            session_regenerate_id(true);
            return true;
        }
        return false;
    }

    public function logout(): void {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
    }

    public function isLoggedIn(): bool {
        return !empty($_SESSION['user_id']) && (time() - ($_SESSION['logged_at'] ?? 0)) < SESSION_LIFETIME;
    }

    public function isAdmin(): bool {
        return $this->isLoggedIn() && $_SESSION['user_role'] === 'admin';
    }

    public function requireLogin(): void {
        if (!$this->isLoggedIn()) {
            header('Location: ' . APP_URL . '/login.php');
            exit;
        }
    }

    public function requireAdmin(): void {
        if (!$this->isAdmin()) {
            header('Location: ' . APP_URL . '/index.php');
            exit;
        }
    }

    public function getCurrentUser(): ?array {
        if (!$this->isLoggedIn()) return null;
        $stmt = $this->db->prepare('SELECT id, name, email, role FROM users WHERE id = ?');
        $stmt->execute([$_SESSION['user_id']]);
        return $stmt->fetch() ?: null;
    }
}
