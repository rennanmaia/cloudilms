<?php
/**
 * CloudiLMS - Certificado de Conclusão
 *
 * GET ?code=XXXXXXXX     → Visualização/verificação pública (sem login)
 * GET ?course=SLUG       → Emite (ou recupera) e exibe o certificado do aluno logado
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/certificate.php';

$db        = Database::getConnection();
$auth      = new Auth();
$certModel = new CertificateModel();

// Carrega configurações do sistema
$settingsRaw = $db->query('SELECT key_name, value FROM settings')->fetchAll();
$settings    = [];
foreach ($settingsRaw as $s) $settings[$s['key_name']] = $s['value'];

// ── Modo 1: Verificação pública via código ──────────────────────────────────
$rawCode = $_GET['code'] ?? '';
if ($rawCode !== '') {
    $cert = $certModel->getByCode($rawCode);
    renderCertificate($cert, $settings, $cert === null);
    exit;
}

// ── Modo 2: Emissão/visualização por curso (requer login) ───────────────────
$auth->requireLogin();

$slug = trim($_GET['course'] ?? '');
if (!$slug) { header('Location: ' . APP_URL . '/index.php'); exit; }

$model  = new CourseModel();
$course = $model->getCourseBySlug($slug);
if (!$course) { header('Location: ' . APP_URL . '/index.php'); exit; }

$userId   = (int) $_SESSION['user_id'];
$enrolled = $model->isEnrolled($userId, $course['id']);
if (!$enrolled) {
    header('Location: ' . APP_URL . '/course.php?slug=' . urlencode($slug));
    exit;
}

$lessons  = $model->getLessonsByCourse($course['id']);
$progress = $model->getProgress($userId, $course['id']);

if (count($lessons) === 0 || count($progress) < count($lessons)) {
    header('Location: ' . APP_URL . '/course.php?slug=' . urlencode($slug) . '&notice=cert_incomplete');
    exit;
}

// Carrega dados do aluno
$userRow = $db->prepare('SELECT id, name, email FROM users WHERE id = ?');
$userRow->execute([$userId]);
$userRow = $userRow->fetch();

// Emite ou recupera o certificado
$cert = $certModel->issue($userId, $course['id'], $settings, $userRow, $course);

renderCertificate($cert, $settings, false);
exit;

// ── Renderização do certificado ──────────────────────────────────────────────
function renderCertificate(?array $cert, array $settings, bool $notFound): void {

    $siteTitle    = htmlspecialchars($settings['site_name']         ?? 'CloudiLMS');
    $certTitle    = htmlspecialchars($settings['cert_title']        ?? 'Certificado de Conclusão');
    $primaryColor = htmlspecialchars($settings['cert_primary_color'] ?? '#1e293b');
    $accentColor  = htmlspecialchars($settings['cert_accent_color']  ?? '#c9a84c');
    $footerText   = htmlspecialchars($settings['cert_footer']        ?? '');

    $student  = '';
    $course   = '';
    $issuer   = '';
    $workload = '';
    $wloadHtml = '';
    $issuedAt = '';
    $code     = '';
    $verifyUrl = '';
    $bodyText  = '';

    if ($cert) {
        $student   = htmlspecialchars($cert['snapshot_student_name']);
        $course    = htmlspecialchars($cert['snapshot_course_title']);
        $issuer    = htmlspecialchars($cert['snapshot_issuer']);
        $workload  = CertificateModel::formatWorkload((int) $cert['workload_minutes']);
        $issuedAt  = strftime('%d de %B de %Y', strtotime($cert['issued_at']));
        $code      = htmlspecialchars($cert['cert_code']);
        $verifyUrl = htmlspecialchars(APP_URL . '/certificate.php?code=' . $cert['cert_code']);

        $mins = (int) $cert['workload_minutes'];
        $wloadHtml = $mins >= 60
            ? '<strong>' . floor($mins / 60) . 'h' . ($mins % 60 ? str_pad($mins % 60, 2, '0', STR_PAD_LEFT) . 'min' : '') . '</strong>'
            : '<strong>' . $mins . ' min</strong>';

        $bodyTpl  = $settings['cert_body']
            ?? '{student_name} concluiu com êxito o curso {course_name}, com carga horária de {workload}.';
        $bodyText = str_replace(
            ['{student_name}', '{course_name}', '{workload}', '{issued_date}', '{issuer}'],
            [$student,         $course,          $workload,    $issuedAt,       $issuer],
            htmlspecialchars($bodyTpl)
        );
    }
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title><?= $certTitle ?> — <?= $siteTitle ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    @page { size: A4 landscape; margin: 0; }
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: Georgia, 'Times New Roman', serif;
      background: #eef2f7;
    }

    /* ── Barra de ações (tela) ── */
    .cert-toolbar {
      display: flex; gap: .75rem; justify-content: center; align-items: center;
      padding: .75rem 1rem; background: #1e293b;
      position: sticky; top: 0; z-index: 50;
    }
    .cert-toolbar a, .cert-toolbar button {
      padding: .4rem 1.1rem; border-radius: .35rem;
      font-family: system-ui, sans-serif; font-size: .875rem; font-weight: 600;
      border: none; cursor: pointer; text-decoration: none; line-height: 1.5;
    }
    .cert-toolbar .btn-print  { background: <?= $accentColor ?>; color: #0f172a; }
    .cert-toolbar .btn-back   { background: #334155; color: #f1f5f9; }
    .cert-toolbar .btn-share  { background: #0f766e; color: #f0fdfa; }

    /* ── Página do certificado ── */
    .cert-page {
      width: 297mm; min-height: 210mm;
      margin: 1.5rem auto;
      background: #fff;
      position: relative;
      overflow: hidden;
      box-shadow: 0 8px 40px rgba(0,0,0,.22);
      display: flex; flex-direction: column;
    }

    /* Moldura decorativa */
    .cert-frame-outer {
      position: absolute; inset: 9px;
      border: 3px solid <?= $primaryColor ?>;
      pointer-events: none; z-index: 2;
    }
    .cert-frame-inner {
      position: absolute; inset: 15px;
      border: 1px solid <?= $accentColor ?>;
      pointer-events: none; z-index: 2;
    }

    /* Faixa de cabeçalho */
    .cert-header {
      background: <?= $primaryColor ?>;
      padding: 1.6rem 2.5rem 1.3rem;
      text-align: center;
      position: relative; z-index: 3;
    }
    .cert-header-site {
      font-family: system-ui, sans-serif;
      font-size: .75rem; letter-spacing: .18em;
      text-transform: uppercase; color: <?= $accentColor ?>; opacity: .85;
    }
    .cert-header-title {
      font-size: 1.85rem; font-weight: 700;
      color: #fff; letter-spacing: .04em;
      margin-top: .35rem;
    }

    /* Corpo */
    .cert-body {
      flex: 1; padding: 1.6rem 3.5rem 1rem;
      text-align: center; position: relative; z-index: 3;
    }
    .cert-intro {
      font-family: system-ui, sans-serif;
      font-size: .8rem; letter-spacing: .12em;
      text-transform: uppercase; color: #64748b;
      margin-bottom: .6rem;
    }
    .cert-student {
      font-size: 2.3rem; color: <?= $primaryColor ?>;
      font-weight: bold; display: inline-block;
      border-bottom: 2px solid <?= $accentColor ?>;
      padding-bottom: .2rem; margin-bottom: .9rem;
    }
    .cert-body-text {
      font-size: 1rem; color: #334155; line-height: 1.75;
      max-width: 62%; margin: 0 auto .9rem;
    }
    .cert-course-highlight {
      font-style: italic; font-weight: bold; color: <?= $primaryColor ?>;
    }
    .cert-workload {
      display: inline-flex; align-items: center; gap: .7rem;
      background: #f8fafc; border: 1px solid #e2e8f0;
      border-radius: .4rem; padding: .45rem 1rem;
      font-family: system-ui, sans-serif;
    }
    .cert-workload span { font-size: .75rem; color: #64748b; }
    .cert-workload strong { font-size: 1.2rem; color: <?= $primaryColor ?>; }

    /* Marca d'água */
    .cert-watermark {
      position: absolute; bottom: 22%; left: 50%;
      transform: translateX(-50%) rotate(-28deg);
      font-size: 5.5rem; color: rgba(30,58,95,.035);
      font-weight: bold; pointer-events: none;
      white-space: nowrap; z-index: 1; user-select: none;
    }

    /* Rodapé com assinaturas */
    .cert-footer {
      display: grid; grid-template-columns: 1fr 1fr;
      padding: .75rem 4rem 1rem; gap: 1rem;
      position: relative; z-index: 3;
    }
    .cert-sig { text-align: center; }
    .cert-sig-line {
      border-top: 1px solid <?= $primaryColor ?>;
      padding-top: .4rem; margin-top: 2rem;
    }
    .cert-sig-name { font-weight: bold; color: <?= $primaryColor ?>; font-size: .9rem; }
    .cert-sig-role { font-family: system-ui, sans-serif; font-size: .75rem; color: #64748b; }

    /* Barra inferior */
    .cert-meta {
      border-top: 1px solid #e2e8f0;
      padding: .4rem 1.5rem;
      display: flex; justify-content: space-between; align-items: center;
      font-family: system-ui, sans-serif; font-size: .6rem; color: #94a3b8;
      position: relative; z-index: 3; background: #fafbfc;
    }
    .cert-meta a { color: #94a3b8; text-decoration: none; }
    .cert-meta strong { color: #64748b; }

    /* Estado "não encontrado" */
    .cert-not-found {
      text-align: center; padding: 5rem 2rem; color: #475569;
      font-family: system-ui, sans-serif;
    }
    .cert-not-found h2 { font-size: 1.4rem; margin-bottom: .75rem; }
    .cert-not-found a  { color: #1e3a5f; }

    @media print {
      body { background: #fff; }
      .cert-toolbar { display: none !important; }
      .cert-page    { margin: 0; box-shadow: none; width: 100%; }
    }
    @media (max-width: 900px) {
      .cert-page { width: 100%; min-height: unset; }
      .cert-body  { padding: 1.2rem 1.5rem .75rem; }
      .cert-body-text { max-width: 90%; }
      .cert-footer { padding: .75rem 1.5rem 1rem; }
    }
  </style>
</head>
<body>

<?php if (!$notFound): ?>
<div class="cert-toolbar">
  <a href="<?= APP_URL ?>/dashboard.php" class="btn-back">← Meus cursos</a>
  <button onclick="window.print()" class="btn-print">🖨 Imprimir / Salvar PDF</button>
  <a href="<?= $verifyUrl ?>" class="btn-share" target="_blank">🔗 Link de verificação</a>
</div>
<?php endif; ?>

<div class="cert-page">
<?php if ($notFound): ?>
  <div class="cert-not-found">
    <h2>Certificado não encontrado</h2>
    <p>O código informado não corresponde a nenhum certificado válido nesta plataforma.</p>
    <p style="margin-top:1rem"><a href="<?= APP_URL ?>/index.php">← Ir para o início</a></p>
  </div>

<?php else: ?>
  <div class="cert-frame-outer"></div>
  <div class="cert-frame-inner"></div>

  <div class="cert-header">
    <div class="cert-header-site"><?= $siteTitle ?></div>
    <div class="cert-header-title"><?= $certTitle ?></div>
  </div>

  <div class="cert-body">
    <div class="cert-intro">Certificamos que</div>
    <div class="cert-student"><?= $student ?></div>
    <div class="cert-body-text"><?= $bodyText ?></div>
    <?php if ($cert['workload_minutes']): ?>
    <div class="cert-workload">
      <span>Carga horária total</span>
      <?= $wloadHtml ?>
    </div>
    <?php endif; ?>
    <div class="cert-watermark"><?= $siteTitle ?></div>
  </div>

  <div class="cert-footer">
    <div class="cert-sig">
      <div class="cert-sig-line">
        <div class="cert-sig-name"><?= $issuer ?></div>
        <div class="cert-sig-role">Instituição / Emissor</div>
      </div>
    </div>
    <div class="cert-sig">
      <div class="cert-sig-line">
        <div class="cert-sig-name"><?= $issuedAt ?></div>
        <div class="cert-sig-role">Data de emissão</div>
      </div>
    </div>
  </div>

  <div class="cert-meta">
    <span>Cód. de verificação: <strong><?= $code ?></strong></span>
    <?php if ($footerText): ?><span><?= $footerText ?></span><?php endif; ?>
    <span>Verificar em: <a href="<?= $verifyUrl ?>"><?= $verifyUrl ?></a></span>
  </div>
<?php endif; ?>
</div>

</body>
</html>
<?php
}
