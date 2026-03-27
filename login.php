<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
if ($auth->isLoggedIn()) { header('Location: ' . APP_URL . '/index.php'); exit; }

$error = '';
$success = '';
if ($_GET['msg'] ?? '' === 'password_reset') {
    $success = 'Senha redefinida com sucesso! Faça login com a nova senha.';
}
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Token de segurança inválido.';
    } else {
        $email = trim($_POST['email'] ?? '');
        $pass  = $_POST['password'] ?? '';
        if ($auth->login($email, $pass)) {
            ActivityLog::record('login');
            $redirect = $_GET['redirect'] ?? '';
            // Valida redirect para evitar open redirect
            if ($redirect && str_starts_with($redirect, APP_URL)) {
                header('Location: ' . $redirect);
            } elseif ($_SESSION['user_role'] === 'admin') {
                header('Location: ' . APP_URL . '/admin/');
            } else {
                header('Location: ' . APP_URL . '/dashboard.php');
            }
            exit;
        }
        $error = 'E-mail ou senha inválidos.';
        ActivityLog::record('login_failed', ['meta' => ['email' => $email]]);
    }
}

siteHeader('Entrar');
?>

<div class="auth-wrap">
  <div class="auth-box">
    <h1 class="auth-title">☁️ Entrar</h1>
    <?php if ($success): ?><div class="alert alert-success"><?= htmlspecialchars($success) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <div class="form-group">
        <label>E-mail</label>
        <input type="email" name="email" required class="form-control" autofocus>
      </div>
      <div class="form-group">
        <label>Senha</label>
        <input type="password" name="password" required class="form-control">
      </div>
      <button type="submit" class="btn btn-primary w-full">Entrar</button>
    </form>
    <p class="auth-link"><a href="forgot-password.php">Esqueceu a senha?</a></p>
    <p class="auth-link">Não tem conta? <a href="register.php">Cadastre-se</a></p>
  </div>
</div>

<?php siteFooter(); ?>
