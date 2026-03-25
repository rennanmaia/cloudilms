<?php
/**
 * CloudiLMS - Instalador web
 * Acesse: http://localhost/cloudilms/install/
 * APAGUE esta pasta após instalar!
 */

$message = '';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $host   = trim($_POST['db_host'] ?? 'localhost');
    $dbname = trim($_POST['db_name'] ?? 'cloudilms');
    $user   = trim($_POST['db_user'] ?? 'root');
    $pass   = $_POST['db_pass'] ?? '';
    $adminName  = trim($_POST['admin_name'] ?? '');
    $adminEmail = trim($_POST['admin_email'] ?? '');
    $adminPass  = $_POST['admin_pass'] ?? '';
    $apiKey = trim($_POST['gdrive_api_key'] ?? '');
    $appUrl = rtrim(trim($_POST['app_url'] ?? ''), '/');

    // Validação básica
    if (!$adminName || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL) || strlen($adminPass) < 6) {
        $message = 'Preencha todos os campos corretamente. A senha deve ter ao menos 6 caracteres.';
    } else {
        try {
            $pdo = new PDO("mysql:host={$host};charset=utf8mb4", $user, $pass, [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
            ]);

            // Cria banco
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$dbname}`");

            // Executa schema
            $sql = file_get_contents(__DIR__ . '/schema.sql');
            // Remove comentários e executa statement por statement
            foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                if ($stmt) $pdo->exec($stmt);
            }

            // Cria admin
            $hash = password_hash($adminPass, PASSWORD_BCRYPT);
            $pdo->prepare('DELETE FROM users WHERE role = "admin"')->execute();
            $pdo->prepare('INSERT INTO users (name, email, password, role, active, created_at) VALUES (?, ?, ?, "admin", 1, NOW())')
                ->execute([$adminName, $adminEmail, $hash]);

            // Gera chave de criptografia para esta instalação
            $encryptKey = bin2hex(random_bytes(32));

            // Salva API key criptografada nas settings
            $iv     = random_bytes(16);
            $cipher = openssl_encrypt($apiKey, 'AES-256-CBC', hex2bin($encryptKey), OPENSSL_RAW_DATA, $iv);
            $encryptedApiKey = base64_encode($iv . $cipher);
            $pdo->prepare('INSERT INTO settings (key_name, value) VALUES ("gdrive_api_key", ?) ON DUPLICATE KEY UPDATE value = ?')
                ->execute([$encryptedApiKey, $encryptedApiKey]);
            $pdo->prepare('INSERT INTO settings (key_name, value) VALUES ("app_url", ?) ON DUPLICATE KEY UPDATE value = ?')
                ->execute([$appUrl, $appUrl]);

            // Gera config.php
            $configContent = "<?php
define('DB_HOST', " . var_export($host, true) . ");
define('DB_NAME', " . var_export($dbname, true) . ");
define('DB_USER', " . var_export($user, true) . ");
define('DB_PASS', " . var_export($pass, true) . ");
define('DB_CHARSET', 'utf8mb4');
define('APP_NAME', 'CloudiLMS');
define('APP_URL', " . var_export($appUrl, true) . ");
define('APP_VERSION', '1.0.0');
// Chave de criptografia AES-256 (32 bytes em hex). Nunca commitar em repositórios públicos.
define('ENCRYPT_KEY', '" . $encryptKey . "');
define('SESSION_LIFETIME', 3600 * 8);
define('HASH_ALGO', PASSWORD_BCRYPT);

function encryptValue(string \$plain): string {
    if (\$plain === '') return '';
    \$iv     = random_bytes(16);
    \$cipher = openssl_encrypt(\$plain, 'AES-256-CBC', hex2bin(ENCRYPT_KEY), OPENSSL_RAW_DATA, \$iv);
    return base64_encode(\$iv . \$cipher);
}

function decryptValue(string \$stored): string {
    if (\$stored === '') return '';
    \$data = base64_decode(\$stored, true);
    if (\$data === false || strlen(\$data) < 17) return '';
    \$plain = openssl_decrypt(substr(\$data, 16), 'AES-256-CBC', hex2bin(ENCRYPT_KEY), OPENSSL_RAW_DATA, substr(\$data, 0, 16));
    return \$plain !== false ? \$plain : '';
}

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    session_start();
}
";
            file_put_contents(__DIR__ . '/../includes/config.php', $configContent);

            $success = true;
            $message = 'Instalação concluída com sucesso!';
        } catch (Exception $e) {
            $message = 'Erro: ' . htmlspecialchars($e->getMessage());
        }
    }
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>CloudiLMS - Instalação</title>
<style>
  body{font-family:system-ui,sans-serif;background:#0f172a;color:#f1f5f9;display:flex;align-items:center;justify-content:center;min-height:100vh;margin:0}
  .box{background:#1e293b;padding:2rem;border-radius:1rem;width:100%;max-width:480px;box-shadow:0 25px 50px rgba(0,0,0,.5)}
  h1{margin:0 0 .5rem;font-size:1.5rem;color:#38bdf8}
  p.sub{color:#94a3b8;margin:0 0 1.5rem;font-size:.9rem}
  label{display:block;font-size:.85rem;color:#94a3b8;margin-bottom:.25rem;margin-top:1rem}
  input{width:100%;box-sizing:border-box;padding:.6rem .8rem;background:#0f172a;border:1px solid #334155;border-radius:.5rem;color:#f1f5f9;font-size:.95rem}
  input:focus{outline:2px solid #38bdf8;border-color:transparent}
  .btn{width:100%;padding:.75rem;background:#38bdf8;color:#0f172a;font-weight:700;border:none;border-radius:.5rem;cursor:pointer;font-size:1rem;margin-top:1.5rem}
  .btn:hover{background:#7dd3fc}
  .alert{padding:.75rem 1rem;border-radius:.5rem;margin-bottom:1rem;font-size:.9rem}
  .alert-err{background:#450a0a;color:#fca5a5;border:1px solid #7f1d1d}
  .alert-ok{background:#052e16;color:#86efac;border:1px solid #14532d}
  h2{font-size:1rem;color:#94a3b8;border-bottom:1px solid #334155;padding-bottom:.5rem;margin-top:1.5rem}
</style>
</head>
<body>
<div class="box">
  <h1>☁️ CloudiLMS</h1>
  <p class="sub">Assistente de instalação</p>

  <?php if ($message): ?>
  <div class="alert <?= $success ? 'alert-ok' : 'alert-err' ?>"><?= $message ?></div>
  <?php endif; ?>

  <?php if ($success): ?>
  <p style="color:#86efac">✅ Acesse o painel em: <a href="../admin/" style="color:#38bdf8"><?= htmlspecialchars($_POST['app_url'] ?? '') ?>/admin/</a></p>
  <p style="color:#fca5a5;font-size:.85rem">⚠️ Apague a pasta <strong>install/</strong> por segurança.</p>
  <?php else: ?>
  <form method="post">
    <h2>Banco de dados</h2>
    <label>Host</label>
    <input name="db_host" value="localhost" required>
    <label>Nome do banco</label>
    <input name="db_name" value="cloudilms" required>
    <label>Usuário</label>
    <input name="db_user" value="root" required>
    <label>Senha</label>
    <input name="db_pass" type="password" placeholder="(vazio para XAMPP padrão)">

    <h2>Conta de administrador</h2>
    <label>Nome</label>
    <input name="admin_name" required>
    <label>E-mail</label>
    <input name="admin_email" type="email" required>
    <label>Senha (mín. 6 caracteres)</label>
    <input name="admin_pass" type="password" required>

    <h2>Configurações</h2>
    <label>URL da aplicação (ex: http://localhost/cloudilms)</label>
    <input name="app_url" value="http://localhost/cloudilms" required>
    <label>Google Drive API Key <a href="https://console.cloud.google.com" target="_blank" style="color:#38bdf8;font-size:.8rem">(obter)</a></label>
    <input name="gdrive_api_key" placeholder="AIzaSy...">

    <button class="btn" type="submit">Instalar CloudiLMS</button>
  </form>
  <?php endif; ?>
</div>
</body>
</html>
