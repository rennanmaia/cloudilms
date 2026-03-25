<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/googledrive.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getConnection();
$message = $error = '';
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Token inválido.';
    } else {
        $siteName    = trim($_POST['site_name'] ?? 'CloudiLMS');
        $apiKey      = trim($_POST['gdrive_api_key'] ?? '');
        $allowReg    = isset($_POST['allow_registration']) ? '1' : '0';
        $testFolder  = trim($_POST['test_folder'] ?? '');

        // Salva no banco
        $save = $db->prepare('INSERT INTO settings (key_name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?');
        $save->execute(['site_name'         , $siteName , $siteName]);
        $save->execute(['gdrive_api_key'    , $apiKey   , $apiKey]);
        $save->execute(['allow_registration', $allowReg , $allowReg]);

        // Atualiza config.php em memória (opcional - requer escrever no arquivo)
        $configFile = __DIR__ . '/../includes/config.php';
        $config = file_get_contents($configFile);
        $config = preg_replace("/define\('GDRIVE_API_KEY',\s*'[^']*'\)/", "define('GDRIVE_API_KEY', " . var_export($apiKey, true) . ")", $config);
        file_put_contents($configFile, $config);

        // Teste de conexão
        if ($testFolder && $apiKey) {
            $gd = new GoogleDrive();
            $fid = GoogleDrive::extractFolderId($testFolder);
            if ($fid) {
                $result = $gd->testConnection($fid);
                if ($result['success']) {
                    $message = '✅ API Key válida! Pasta encontrada: "' . htmlspecialchars($result['name']) . '"';
                } else {
                    $error = '❌ Erro ao testar: ' . htmlspecialchars($result['error']);
                }
            } else {
                $error = 'URL/ID da pasta inválido para teste.';
            }
        } else {
            $message = 'Configurações salvas com sucesso.';
        }
    }
}

// Lê configurações atuais
$settingsRaw = $db->query('SELECT key_name, value FROM settings')->fetchAll();
$settings = [];
foreach ($settingsRaw as $s) $settings[$s['key_name']] = $s['value'];

adminHeader('Configurações', 'settings');
?>
<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
<?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<div class="card">
  <div class="card-header"><h2>Configurações gerais</h2></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">

      <div class="form-group">
        <label>Nome do site</label>
        <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name'] ?? 'CloudiLMS') ?>" class="form-control">
      </div>

      <div class="form-group">
        <label class="checkbox-label">
          <input type="checkbox" name="allow_registration" value="1" <?= ($settings['allow_registration'] ?? '1') === '1' ? 'checked' : '' ?>>
          Permitir auto-cadastro de alunos
        </label>
      </div>

      <hr style="border-color:#334155;margin:1.5rem 0">

      <h3 style="color:#38bdf8;margin-bottom:1rem">🔑 Google Drive API</h3>

      <div class="alert" style="background:#1e3a5f;border:1px solid #1d4ed8;color:#93c5fd;padding:1rem;border-radius:.5rem;margin-bottom:1rem">
        <strong>Como obter a API Key:</strong><br>
        1. Acesse <a href="https://console.cloud.google.com" target="_blank" style="color:#60a5fa">console.cloud.google.com</a><br>
        2. Crie um projeto → Ative <strong>Google Drive API</strong><br>
        3. Credenciais → Criar credencial → <strong>Chave de API</strong><br>
        4. Restrinja a chave para apenas a <em>Google Drive API</em><br>
        5. As pastas do Drive devem ser públicas (<em>qualquer pessoa com o link</em>)
      </div>

      <div class="form-group">
        <label>Google Drive API Key</label>
        <input type="text" name="gdrive_api_key"
               value="<?= htmlspecialchars($settings['gdrive_api_key'] ?? '') ?>"
               class="form-control" placeholder="AIzaSy...">
      </div>

      <div class="form-group">
        <label>Testar com URL de pasta (opcional)</label>
        <input type="text" name="test_folder" class="form-control"
               placeholder="https://drive.google.com/drive/folders/...">
        <small class="help-text">Preencha e salve para testar se a API Key está funcionando.</small>
      </div>

      <button type="submit" class="btn btn-primary">💾 Salvar configurações</button>
    </form>
  </div>
</div>

<?php adminFooter(); ?>
