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
        $section = $_POST['section'] ?? 'general';
        $save = $db->prepare('INSERT INTO settings (key_name, value) VALUES (?,?) ON DUPLICATE KEY UPDATE value=?');

        if ($section === 'general') {
            $siteName    = trim($_POST['site_name'] ?? 'CloudiLMS');
            $apiKey      = trim($_POST['gdrive_api_key'] ?? '');
            $allowReg    = isset($_POST['allow_registration']) ? '1' : '0';
            $testFolder  = trim($_POST['test_folder'] ?? '');

            $save->execute(['site_name'         , $siteName             , $siteName]);
            $save->execute(['gdrive_api_key'    , encryptValue($apiKey) , encryptValue($apiKey)]);
            $save->execute(['allow_registration', $allowReg             , $allowReg]);

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
                $message = 'Configurações gerais salvas com sucesso.';
            }

        } elseif ($section === 'cert') {
            $certIssuer  = trim($_POST['cert_issuer']        ?? '');
            $certTitle   = trim($_POST['cert_title']         ?? 'Certificado de Conclusão');
            $certBody    = trim($_POST['cert_body']          ?? '');
            $certPrimary = trim($_POST['cert_primary_color'] ?? '#1e293b');
            $certAccent  = trim($_POST['cert_accent_color']  ?? '#c9a84c');
            $certFooter  = trim($_POST['cert_footer']        ?? '');
            $certBgImage = trim($_POST['cert_bg_image']      ?? '');
            if ($certBgImage && !preg_match('/^https?:\/\//i', $certBgImage)) $certBgImage = '';

            $save->execute(['cert_issuer',        $certIssuer,  $certIssuer]);
            $save->execute(['cert_title',         $certTitle,   $certTitle]);
            $save->execute(['cert_body',          $certBody,    $certBody]);
            $save->execute(['cert_primary_color', $certPrimary, $certPrimary]);
            $save->execute(['cert_accent_color',  $certAccent,  $certAccent]);
            $save->execute(['cert_footer',        $certFooter,  $certFooter]);
            $save->execute(['cert_bg_image',      $certBgImage, $certBgImage]);
            $message = 'Configurações do certificado salvas com sucesso.';

        } elseif ($section === 'smtp') {
            $smtpHost = trim($_POST['smtp_host']       ?? '');
            $smtpPort = (int)($_POST['smtp_port']      ?? 587);
            $smtpEnc  = $_POST['smtp_encryption']      ?? 'tls';
            $smtpUser = trim($_POST['smtp_user']       ?? '');
            $smtpPass = $_POST['smtp_pass']            ?? '';
            $smtpFrom = trim($_POST['smtp_from_email'] ?? '');
            $smtpName = trim($_POST['smtp_from_name']  ?? '');

            if (!in_array($smtpEnc, ['tls', 'ssl', 'none'], true)) $smtpEnc = 'tls';
            if ($smtpPort < 1 || $smtpPort > 65535) $smtpPort = 587;

            $save->execute(['smtp_host',       $smtpHost,         $smtpHost]);
            $save->execute(['smtp_port',       (string)$smtpPort, (string)$smtpPort]);
            $save->execute(['smtp_encryption', $smtpEnc,          $smtpEnc]);
            $save->execute(['smtp_user',       $smtpUser,         $smtpUser]);
            $save->execute(['smtp_from_email', $smtpFrom,         $smtpFrom]);
            $save->execute(['smtp_from_name',  $smtpName,         $smtpName]);

            // Atualiza a senha apenas se um novo valor foi digitado
            if ($smtpPass !== '' && $smtpPass !== '••••••••') {
                $enc = encryptValue($smtpPass);
                $save->execute(['smtp_pass', $enc, $enc]);
            }

            $testEmail = trim($_POST['smtp_test_email'] ?? '');
            if ($testEmail && filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
                require_once __DIR__ . '/../includes/mailer.php';
                // Re-lê configurações após salvar
                $settingsRaw2 = $db->query('SELECT key_name, value FROM settings')->fetchAll();
                $settings2 = [];
                foreach ($settingsRaw2 as $s2) $settings2[$s2['key_name']] = $s2['value'];
                $siteName2 = $settings2['site_name'] ?? 'CloudiLMS';
                try {
                    $mailer = new Mailer($db);
                    $sent   = $mailer->send(
                        $testEmail,
                        'Teste de e-mail — ' . $siteName2,
                        '<p>Se você recebeu este e-mail, as configurações SMTP estão corretas! ✅</p>'
                    );
                    $message = $sent
                        ? '✅ E-mail de teste enviado para ' . htmlspecialchars($testEmail) . '. Verifique a caixa de entrada.'
                        : '⚠️ Configurações salvas, mas não foi possível enviar o e-mail de teste. Verifique os dados SMTP.';
                } catch (Throwable $ex) {
                    $error = '❌ Erro ao enviar e-mail de teste: ' . htmlspecialchars($ex->getMessage());
                    if (!$error) $message = 'Configurações de e-mail salvas.';
                }
            } else {
                $message = 'Configurações de e-mail salvas com sucesso.';
            }

        } elseif ($section === 'ftp') {
            $ftpHost     = trim($_POST['ftp_host']      ?? '');
            $ftpPort     = max(1, min(65535, (int)($_POST['ftp_port'] ?? 21)));
            $ftpUser     = trim($_POST['ftp_user']      ?? '');
            $ftpPass     = $_POST['ftp_pass']           ?? '';
            $ftpBasePath = trim($_POST['ftp_base_path'] ?? '/');
            $ftpBaseUrl  = rtrim(trim($_POST['ftp_base_url'] ?? ''), '/');

            $save->execute(['ftp_host',      $ftpHost,               $ftpHost]);
            $save->execute(['ftp_port',      (string)$ftpPort,       (string)$ftpPort]);
            $save->execute(['ftp_user',      $ftpUser,               $ftpUser]);
            $save->execute(['ftp_base_path', $ftpBasePath,           $ftpBasePath]);
            $save->execute(['ftp_base_url',  $ftpBaseUrl,            $ftpBaseUrl]);

            if ($ftpPass !== '' && $ftpPass !== '••••••••') {
                $enc = encryptValue($ftpPass);
                $save->execute(['ftp_pass', $enc, $enc]);
            }
            $message = 'Configurações FTP salvas com sucesso.';

        } elseif ($section === 'http_src') {
            $httpBaseUrl = rtrim(trim($_POST['http_base_url'] ?? ''), '/');
            $save->execute(['http_base_url', $httpBaseUrl, $httpBaseUrl]);
            $message = 'Configurações HTTP salvas com sucesso.';

        } elseif ($section === 'local') {
            $localBasePath = rtrim(trim($_POST['local_base_path'] ?? ''), '/\\');
            $localBaseUrl  = rtrim(trim($_POST['local_base_url']  ?? ''), '/');
            $save->execute(['local_base_path', $localBasePath, $localBasePath]);
            $save->execute(['local_base_url',  $localBaseUrl,  $localBaseUrl]);
            $message = 'Configurações de arquivo local salvas com sucesso.';
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
      <input type="hidden" name="csrf"    value="<?= $csrf ?>">
      <input type="hidden" name="section" value="general">

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
               value="<?= htmlspecialchars(decryptValue($settings['gdrive_api_key'] ?? '')) ?>"  
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

<div class="card" style="margin-top:1.5rem">
  <div class="card-header"><h2>📜 Certificados</h2></div>
  <div class="card-body">
    <form method="post">
      <input type="hidden" name="csrf"    value="<?= $csrf ?>">
      <input type="hidden" name="section" value="cert">

      <div class="form-group">
        <label>Nome do emissor</label>
        <input type="text" name="cert_issuer"
               value="<?= htmlspecialchars($settings['cert_issuer'] ?? '') ?>"
               class="form-control" placeholder="Ex: Instituto CloudiLMS">
        <small class="help-text">Aparece como assinatura no certificado.</small>
      </div>

      <div class="form-group">
        <label>Título do certificado</label>
        <input type="text" name="cert_title"
               value="<?= htmlspecialchars($settings['cert_title'] ?? 'Certificado de Conclusão') ?>"
               class="form-control">
      </div>

      <div class="form-group">
        <label>Nota personalizada (opcional)</label>
        <textarea name="cert_body" rows="2" class="form-control"><?= htmlspecialchars($settings['cert_body'] ?? '') ?></textarea>
        <small class="help-text">Texto livre exibido no certificado. Deixe vazio para usar apenas o layout padrão.</small>
      </div>

      <div class="form-group">
        <label>Texto de rodapé (opcional)</label>
        <input type="text" name="cert_footer"
               value="<?= htmlspecialchars($settings['cert_footer'] ?? '') ?>"
               class="form-control" placeholder="Ex: Este certificado pode ser verificado em nosso site.">
      </div>

      <div style="display:flex;gap:1rem;flex-wrap:wrap">
        <div class="form-group" style="flex:1;min-width:160px">
          <label>Cor primária</label>
          <input type="color" name="cert_primary_color"
                 value="<?= htmlspecialchars($settings['cert_primary_color'] ?? '#1e293b') ?>"
                 class="form-control" style="height:2.5rem;padding:.25rem">
        </div>
        <div class="form-group" style="flex:1;min-width:160px">
          <label>Cor de destaque</label>
          <input type="color" name="cert_accent_color"
                 value="<?= htmlspecialchars($settings['cert_accent_color'] ?? '#c9a84c') ?>"
                 class="form-control" style="height:2.5rem;padding:.25rem">
        </div>
      </div>

      <div class="form-group">
        <label>Imagem de fundo (URL)</label>
        <input type="url" name="cert_bg_image"
               value="<?= htmlspecialchars($settings['cert_bg_image'] ?? '') ?>"
               class="form-control" placeholder="https://exemplo.com/fundo.jpg">
        <small class="help-text">URL pública de uma imagem (https://…). Uma camada semitransparente preserva a legibilidade do texto. Use imagens claras ou suaves.</small>
      </div>

      <button type="submit" class="btn btn-primary">💾 Salvar certificado</button>
    </form>
  </div>
</div>

<div class="card" style="margin-top:1.5rem">
  <div class="card-header"><h2>📧 Configurações de E-mail (SMTP)</h2></div>
  <div class="card-body">
    <div class="alert" style="background:#1a3040;border:1px solid #164e63;color:#7dd3fc;padding:1rem;border-radius:.5rem;margin-bottom:1.25rem">
      <strong>Dica:</strong> Configure o SMTP para habilitar a recuperação de senha por e-mail.<br>
      Para Gmail use <code>smtp.gmail.com</code>, porta <code>587</code>, criptografia <code>TLS</code> e uma <em>senha de app</em>.<br>
      Para outros provedores consulte as configurações SMTP dele. Deixe <em>Host</em> em branco para usar o
      <code>mail()</code> do PHP (apenas servidores com sendmail configurado).
    </div>
    <form method="post">
      <input type="hidden" name="csrf"    value="<?= $csrf ?>">
      <input type="hidden" name="section" value="smtp">

      <div style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:end">
        <div class="form-group" style="margin:0">
          <label>Host SMTP</label>
          <input type="text" name="smtp_host"
                 value="<?= htmlspecialchars($settings['smtp_host'] ?? '') ?>"
                 class="form-control" placeholder="smtp.gmail.com">
        </div>
        <div class="form-group" style="margin:0;width:110px">
          <label>Porta</label>
          <input type="number" name="smtp_port" min="1" max="65535"
                 value="<?= (int)($settings['smtp_port'] ?? 587) ?>"
                 class="form-control">
        </div>
      </div>

      <div class="form-group" style="margin-top:1rem">
        <label>Criptografia</label>
        <select name="smtp_encryption" class="form-control">
          <?php foreach (['tls' => 'TLS (STARTTLS — porta 587)', 'ssl' => 'SSL (porta 465)', 'none' => 'Nenhuma (porta 25)'] as $v => $lbl): ?>
          <option value="<?= $v ?>" <?= ($settings['smtp_encryption'] ?? 'tls') === $v ? 'selected' : '' ?>>
            <?= $lbl ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div class="form-group">
        <label>Usuário SMTP (e-mail)</label>
        <input type="text" name="smtp_user"
               value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>"
               class="form-control" placeholder="voce@gmail.com" autocomplete="off">
      </div>

      <div class="form-group">
        <label>Senha SMTP</label>
        <input type="password" name="smtp_pass"
               value="<?= ($settings['smtp_pass'] ?? '') !== '' ? '••••••••' : '' ?>"
               class="form-control" placeholder="Senha ou senha de app" autocomplete="new-password">
        <small class="help-text">Deixe em branco para manter a senha atual. Armazenada de forma criptografada.</small>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem">
        <div class="form-group">
          <label>E-mail remetente</label>
          <input type="email" name="smtp_from_email"
                 value="<?= htmlspecialchars($settings['smtp_from_email'] ?? '') ?>"
                 class="form-control" placeholder="noreply@seusite.com">
        </div>
        <div class="form-group">
          <label>Nome remetente</label>
          <input type="text" name="smtp_from_name"
                 value="<?= htmlspecialchars($settings['smtp_from_name'] ?? ($settings['site_name'] ?? 'CloudiLMS')) ?>"
                 class="form-control" placeholder="CloudiLMS">
        </div>
      </div>

      <hr style="border-color:#334155;margin:1.5rem 0">
      <h4 style="color:#94a3b8;margin-bottom:.75rem">Testar envio (opcional)</h4>
      <div style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:end">
        <div class="form-group" style="margin:0">
          <label>Enviar e-mail de teste para</label>
          <input type="email" name="smtp_test_email" class="form-control" placeholder="teste@exemplo.com">
        </div>
        <button type="submit" class="btn" style="background:#0ea5e9;color:#fff;padding:.55rem 1.2rem">
          📤 Salvar &amp; Testar
        </button>
      </div>
      <small class="help-text">Preencha o e-mail acima para enviar uma mensagem de teste ao salvar.</small>

      <div style="margin-top:1.25rem">
        <button type="submit" class="btn btn-primary">💾 Salvar configurações de e-mail</button>
      </div>
    </form>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     FTP
     ════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header"><h2>📡 Fonte de vídeos via FTP</h2></div>
  <div class="card-body">
    <p style="color:#94a3b8;margin-bottom:1.25rem">
      O servidor PHP lista os arquivos via FTP e os serve via HTTP usando a URL base configurada.
      Os alunos nunca acessam o FTP diretamente.
    </p>
    <form method="post" action="settings.php?action=save">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="section" value="ftp">

      <div style="display:grid;grid-template-columns:1fr auto;gap:1rem;align-items:end">
        <div class="form-group" style="margin:0">
          <label>Host FTP</label>
          <input type="text" name="ftp_host"
                 value="<?= htmlspecialchars($settings['ftp_host'] ?? '') ?>"
                 class="form-control" placeholder="ftp.seuservidor.com">
        </div>
        <div class="form-group" style="margin:0;width:110px">
          <label>Porta</label>
          <input type="number" name="ftp_port" min="1" max="65535"
                 value="<?= (int)($settings['ftp_port'] ?? 21) ?>"
                 class="form-control">
        </div>
      </div>

      <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-top:1rem">
        <div class="form-group">
          <label>Usuário FTP</label>
          <input type="text" name="ftp_user"
                 value="<?= htmlspecialchars($settings['ftp_user'] ?? '') ?>"
                 class="form-control" placeholder="usuario_ftp" autocomplete="off">
        </div>
        <div class="form-group">
          <label>Senha FTP</label>
          <input type="password" name="ftp_pass"
                 value="<?= ($settings['ftp_pass'] ?? '') !== '' ? '••••••••' : '' ?>"
                 class="form-control" placeholder="••••••••" autocomplete="new-password">
          <small class="help-text">Deixe em branco para manter. Armazenada criptografada.</small>
        </div>
      </div>

      <div class="form-group">
        <label>Caminho base FTP</label>
        <input type="text" name="ftp_base_path"
               value="<?= htmlspecialchars($settings['ftp_base_path'] ?? '/') ?>"
               class="form-control" placeholder="/public_html/videos">
        <small class="help-text">Diretório raiz no servidor FTP para os vídeos dos cursos.</small>
      </div>

      <div class="form-group">
        <label>URL base HTTP</label>
        <input type="text" name="ftp_base_url"
               value="<?= htmlspecialchars($settings['ftp_base_url'] ?? '') ?>"
               class="form-control" placeholder="https://cdn.seuservidor.com/videos">
        <small class="help-text">URL pública correspondente ao caminho base acima. Usada para reproduzir os vídeos no navegador.</small>
      </div>

      <button type="submit" class="btn btn-primary">💾 Salvar configurações FTP</button>
    </form>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     HTTP
     ════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header"><h2>🌐 Fonte de vídeos via HTTP</h2></div>
  <div class="card-body">
    <p style="color:#94a3b8;margin-bottom:1.25rem">
      Importa vídeos diretamente de um servidor HTTP: autoindex Apache/Nginx, manifesto JSON, ou URL única.
      Não requer credenciais; o servidor web deve permitir acesso público aos arquivos.
    </p>
    <form method="post" action="settings.php?action=save">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="section" value="http_src">

      <div class="form-group">
        <label>URL base HTTP (opcional)</label>
        <input type="text" name="http_base_url"
               value="<?= htmlspecialchars($settings['http_base_url'] ?? '') ?>"
               class="form-control" placeholder="https://cdn.seuservidor.com/videos">
        <small class="help-text">
          Prefixo opcional para construir URLs relativas. Deixe em branco para usar URLs absolutas em cada curso.
          <br>Formatos suportados por curso: <strong>autoindex</strong>, <strong>manifesto JSON</strong>
          <code>[{"url":"…","name":"…","folder":"Tópico"}]</code>, ou uma <strong>URL de vídeo única</strong>.
        </small>
      </div>

      <button type="submit" class="btn btn-primary">💾 Salvar configurações HTTP</button>
    </form>
  </div>
</div>

<!-- ════════════════════════════════════════════════════
     Local filesystem
     ════════════════════════════════════════════════════ -->
<div class="card">
  <div class="card-header"><h2>🖥️ Fonte de vídeos via sistema de arquivos local</h2></div>
  <div class="card-body">
    <p style="color:#94a3b8;margin-bottom:1.25rem">
      Importa vídeos de um diretório no servidor onde o PHP está rodando.
      Os arquivos são servidos pela URL base configurada (ex: via web server ou CDN local).
    </p>
    <form method="post" action="settings.php?action=save">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="section" value="local">

      <div class="form-group">
        <label>Caminho base no servidor</label>
        <input type="text" name="local_base_path"
               value="<?= htmlspecialchars($settings['local_base_path'] ?? '') ?>"
               class="form-control" placeholder="/var/www/videos ou C:\videos">
        <small class="help-text">Caminho absoluto no sistema de arquivos do servidor onde os vídeos estão armazenados.</small>
      </div>

      <div class="form-group">
        <label>URL base pública</label>
        <input type="text" name="local_base_url"
               value="<?= htmlspecialchars($settings['local_base_url'] ?? '') ?>"
               class="form-control" placeholder="https://seusite.com/videos">
        <small class="help-text">URL pública correspondente ao caminho base. Usada para reproduzir os vídeos no navegador.</small>
      </div>

      <button type="submit" class="btn btn-primary">💾 Salvar configurações de arquivo local</button>
    </form>
  </div>
</div>

<?php adminFooter(); ?>
