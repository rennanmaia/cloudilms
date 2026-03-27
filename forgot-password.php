<?php
/**
 * CloudiLMS – Esqueci minha senha
 *
 * Recebe o e-mail, gera token seguro, salva o hash e envia o link de redefinição.
 * Por segurança, exibe SEMPRE a mesma mensagem de sucesso,
 * independentemente de o e-mail existir ou não (evita enumeração de usuários).
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/mailer.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
if ($auth->isLoggedIn()) { header('Location: ' . APP_URL . '/index.php'); exit; }

$db = Database::getConnection();

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$sent  = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Token de segurança inválido. Recarregue a página e tente novamente.';
    } else {
        $email = strtolower(trim($_POST['email'] ?? ''));

        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um endereço de e-mail válido.';
        } else {
            // Busca usuário ativo
            $stmt = $db->prepare('SELECT id, name FROM users WHERE email = ? AND active = 1 LIMIT 1');
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if ($user) {
                // Invalida tokens anteriores deste usuário
                $db->prepare('DELETE FROM password_resets WHERE user_id = ?')
                   ->execute([$user['id']]);

                // Gera token seguro: 32 bytes aleatórios em hex (64 chars)
                $token     = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $token);
                $expiresAt = date('Y-m-d H:i:s', time() + 3600); // 1 hora

                $db->prepare(
                    'INSERT INTO password_resets (user_id, token_hash, expires_at, used, created_at)
                     VALUES (?, ?, ?, 0, NOW())'
                )->execute([$user['id'], $tokenHash, $expiresAt]);

                // Monta e-mail
                $resetUrl = APP_URL . '/reset-password.php?token=' . urlencode($token);
                $siteName = APP_NAME;
                $userName = htmlspecialchars($user['name']);
                $html     = <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head><meta charset="UTF-8"></head>
<body style="font-family:sans-serif;background:#0f172a;color:#e2e8f0;margin:0;padding:0">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:40px 0">
    <tr><td align="center">
      <table width="520" cellpadding="0" cellspacing="0"
             style="background:#1e293b;border-radius:12px;overflow:hidden;border:1px solid #334155">
        <tr><td style="background:linear-gradient(135deg,#4f46e5,#7c3aed);padding:28px 36px">
          <h1 style="margin:0;font-size:1.35rem;color:#fff">☁️ {$siteName}</h1>
        </td></tr>
        <tr><td style="padding:32px 36px">
          <p style="margin:0 0 12px">Olá, <strong>{$userName}</strong>,</p>
          <p style="margin:0 0 24px;color:#94a3b8;line-height:1.6">
            Recebemos uma solicitação para redefinir a senha da sua conta.<br>
            Clique no botão abaixo para criar uma nova senha. O link é válido por <strong>1 hora</strong>.
          </p>
          <p style="text-align:center">
            <a href="{$resetUrl}"
               style="display:inline-block;background:#4f46e5;color:#fff;text-decoration:none;
                      padding:12px 32px;border-radius:8px;font-weight:700;font-size:.95rem">
              🔑 Redefinir senha
            </a>
          </p>
          <p style="color:#64748b;font-size:.8rem;margin:24px 0 0;line-height:1.6">
            Se você não solicitou a redefinição, ignore este e-mail.<br>
            A sua senha atual permanece inalterada.<br><br>
            Ou copie e cole este link no navegador:<br>
            <span style="color:#7dd3fc;word-break:break-all">{$resetUrl}</span>
          </p>
        </td></tr>
        <tr><td style="background:#0f172a;padding:16px 36px;text-align:center;
                       font-size:.78rem;color:#475569">
          © {$siteName} – Este é um e-mail automático, não responda.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;

                $mailer = new Mailer();
                $mailer->send($email, 'Redefinição de senha – ' . $siteName, $html);
                // Não revelamos se o envio falhou (evita enumeração)
            }

            // Sempre mostra mensagem de sucesso (não revela se e-mail existe)
            $sent = true;
        }
    }
}

siteHeader('Esqueci minha senha');
?>

<div class="auth-wrap">
  <div class="auth-box">
    <h1 class="auth-title">🔑 Esqueci minha senha</h1>

    <?php if ($sent): ?>
    <div class="alert alert-success">
      ✅ Se este e-mail estiver cadastrado, você receberá um link para redefinir sua senha em breve.<br>
      <small style="opacity:.8">Verifique também a caixa de spam. O link expira em 1 hora.</small>
    </div>
    <p class="auth-link"><a href="login.php">← Voltar ao login</a></p>

    <?php else: ?>

    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <p style="font-size:.9rem;color:var(--text2);margin-bottom:1.25rem;line-height:1.6">
      Informe o e-mail da sua conta e enviaremos um link para você criar uma nova senha.
    </p>

    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <div class="form-group">
        <label>E-mail</label>
        <input type="email" name="email" required class="form-control" autofocus
               placeholder="seu@email.com"
               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
      </div>
      <button type="submit" class="btn btn-primary w-full">📧 Enviar link de redefinição</button>
    </form>

    <p class="auth-link"><a href="login.php">← Voltar ao login</a></p>
    <?php endif; ?>
  </div>
</div>

<?php siteFooter(); ?>
