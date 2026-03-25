<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
if ($auth->isLoggedIn()) { header('Location: ' . APP_URL . '/index.php'); exit; }

// Verifica se cadastro está habilitado
$db = Database::getConnection();
$allowReg = $db->query('SELECT value FROM settings WHERE key_name = "allow_registration"')->fetchColumn();
if ($allowReg === '0') {
    siteHeader('Cadastro desabilitado');
    echo '<div class="empty-state"><h2>O cadastro de novos alunos está desabilitado.</h2></div>';
    siteFooter();
    exit;
}

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Token inválido.';
    } else {
        $name    = trim($_POST['name'] ?? '');
        $email   = trim($_POST['email'] ?? '');
        $pass    = $_POST['password'] ?? '';
        $pass2   = $_POST['password2'] ?? '';

        if (!$name || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Preencha nome e e-mail válidos.';
        } elseif (strlen($pass) < 6) {
            $error = 'A senha deve ter ao menos 6 caracteres.';
        } elseif ($pass !== $pass2) {
            $error = 'As senhas não coincidem.';
        } else {
            $check = $db->prepare('SELECT 1 FROM users WHERE email = ?');
            $check->execute([$email]);
            if ($check->fetch()) {
                $error = 'Este e-mail já está cadastrado.';
            } else {
                $db->prepare('INSERT INTO users (name,email,password,role,active,created_at) VALUES (?,?,?,"student",1,NOW())')
                   ->execute([$name, $email, password_hash($pass, PASSWORD_BCRYPT)]);
                // Login automático
                $auth->login($email, $pass);
                header('Location: ' . APP_URL . '/dashboard.php');
                exit;
            }
        }
    }
}

siteHeader('Cadastro');
?>

<div class="auth-wrap">
  <div class="auth-box">
    <h1 class="auth-title">☁️ Criar conta</h1>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <div class="form-group"><label>Nome completo</label><input type="text" name="name" required class="form-control" autofocus></div>
      <div class="form-group"><label>E-mail</label><input type="email" name="email" required class="form-control"></div>
      <div class="form-group"><label>Senha</label><input type="password" name="password" required class="form-control"></div>
      <div class="form-group"><label>Confirmar senha</label><input type="password" name="password2" required class="form-control"></div>
      <button type="submit" class="btn btn-primary w-full">Criar conta</button>
    </form>
    <p class="auth-link">Já tem conta? <a href="login.php">Entrar</a></p>
  </div>
</div>

<?php siteFooter(); ?>
