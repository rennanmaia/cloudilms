<?php
/**
 * CloudiLMS – Redefinição de senha
 *
 * Recebe o token via GET, valida, e permite ao usuário definir uma nova senha.
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
if ($auth->isLoggedIn()) { header('Location: ' . APP_URL . '/index.php'); exit; }

$db = Database::getConnection();

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$token     = trim($_GET['token'] ?? ($_POST['token'] ?? ''));
$error     = '';
$success   = false;
$resetRow  = null;

// ── Valida token ──────────────────────────────────────────────────────────────
function findResetRow(PDO $db, string $token): array|false {
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return false;

    $tokenHash = hash('sha256', $token);
    $stmt = $db->prepare(
        'SELECT r.id, r.user_id, r.expires_at, r.used, u.email, u.name
         FROM password_resets r
         JOIN users u ON u.id = r.user_id
         WHERE r.token_hash = ?
         LIMIT 1'
    );
    $stmt->execute([$tokenHash]);
    return $stmt->fetch() ?: false;
}

if ($token) {
    $resetRow = findResetRow($db, $token);
}

// ── POST: salva nova senha ────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Token de segurança inválido. Tente novamente.';
    } else {
        $token    = trim($_POST['token'] ?? '');
        $pass1    = $_POST['password']  ?? '';
        $pass2    = $_POST['password2'] ?? '';

        $resetRow = $token ? findResetRow($db, $token) : false;

        if (!$resetRow) {
            $error = 'Link inválido ou expirado. Solicite um novo link de redefinição.';
        } elseif ((int)$resetRow['used']) {
            $error = 'Este link já foi utilizado. Solicite um novo se precisar.';
        } elseif (strtotime($resetRow['expires_at']) < time()) {
            $error = 'Este link de redefinição expirou. Solicite um novo.';
        } elseif (strlen($pass1) < 8) {
            $error = 'A senha deve ter pelo menos 8 caracteres.';
        } elseif ($pass1 !== $pass2) {
            $error = 'As senhas não coincidem.';
        } else {
            // Tudo válido: atualiza a senha
            $hash = password_hash($pass1, HASH_ALGO);
            $db->prepare('UPDATE users SET password = ? WHERE id = ?')
               ->execute([$hash, $resetRow['user_id']]);

            // Invalida todos os tokens deste usuário
            $db->prepare('UPDATE password_resets SET used = 1 WHERE user_id = ?')
               ->execute([$resetRow['user_id']]);

            $success = true;
        }
    }
}

// ── Verifica token para exibição do form ──────────────────────────────────────
$tokenValid   = false;
$tokenExpired = false;
$tokenUsed    = false;

if (!$success && $token) {
    $resetRow = findResetRow($db, $token);
    if (!$resetRow) {
        $tokenValid = false;
    } elseif ((int)$resetRow['used']) {
        $tokenUsed  = true;
    } elseif (strtotime($resetRow['expires_at']) < time()) {
        $tokenExpired = true;
    } else {
        $tokenValid = true;
    }
}

siteHeader('Redefinir senha');
?>

<div class="auth-wrap">
  <div class="auth-box">
    <h1 class="auth-title">🔑 Redefinir senha</h1>

    <?php if ($success): ?>
    <div class="alert alert-success">
      ✅ Senha redefinida com sucesso! Você já pode fazer login com a nova senha.
    </div>
    <a href="login.php" class="btn btn-primary w-full" style="text-align:center;display:block">
      → Fazer login
    </a>

    <?php elseif (!$token || (!$tokenValid && !$error)): ?>
    <!-- Token ausente ou inválido -->
    <div class="alert alert-danger">
      <?php if ($tokenUsed): ?>
        ⚠️ Este link já foi utilizado. Se precisar redefinir sua senha novamente, solicite um novo link.
      <?php elseif ($tokenExpired): ?>
        ⏰ Este link expirou. Os links são válidos por 1 hora.
      <?php else: ?>
        ❌ Link de redefinição inválido ou expirado.
      <?php endif; ?>
    </div>
    <p class="auth-link">
      <a href="forgot-password.php">← Solicitar novo link</a>
    </p>

    <?php else: ?>
    <!-- Formulário de nova senha -->
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <p style="font-size:.85rem;color:var(--text2);margin-bottom:1.25rem">
      Definindo nova senha para <strong><?= htmlspecialchars($resetRow['email'] ?? '') ?></strong>
    </p>

    <form method="post">
      <input type="hidden" name="csrf"  value="<?= $csrf ?>">
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">

      <div class="form-group">
        <label>Nova senha <small style="color:var(--text3)">(mínimo 8 caracteres)</small></label>
        <input type="password" name="password" required class="form-control"
               minlength="8" autofocus autocomplete="new-password">
      </div>
      <div class="form-group">
        <label>Confirmar nova senha</label>
        <input type="password" name="password2" required class="form-control"
               minlength="8" autocomplete="new-password">
      </div>

      <!-- Indicador de força da senha -->
      <div id="pass-strength" style="height:4px;border-radius:2px;background:var(--bg3);margin:-4px 0 1rem;transition:background .2s,width .2s;width:0"></div>

      <button type="submit" class="btn btn-primary w-full">💾 Salvar nova senha</button>
    </form>

    <p class="auth-link"><a href="login.php">← Voltar ao login</a></p>

    <script>
    document.querySelector('input[name=password]').addEventListener('input', function() {
        const v = this.value;
        let score = 0;
        if (v.length >= 8)  score++;
        if (v.length >= 12) score++;
        if (/[A-Z]/.test(v) && /[a-z]/.test(v)) score++;
        if (/\d/.test(v))   score++;
        if (/[^A-Za-z0-9]/.test(v)) score++;
        const colors = ['', '#ef4444', '#f97316', '#eab308', '#22c55e', '#10b981'];
        const widths  = ['0%', '20%', '40%', '60%', '80%', '100%'];
        const bar = document.getElementById('pass-strength');
        bar.style.background = colors[score] || '#ef4444';
        bar.style.width      = widths[score]  || '10%';
    });
    </script>
    <?php endif; ?>

  </div>
</div>

<?php siteFooter(); ?>
