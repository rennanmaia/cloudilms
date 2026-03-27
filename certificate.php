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
require_once __DIR__ . '/includes/quiz.php';

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

// Verifica se todos os questionários do curso foram aprovados
$quizModel = new QuizModel();
if (!$quizModel->allQuizzesPassed($userId, $course['id'])) {
    header('Location: ' . APP_URL . '/course.php?slug=' . urlencode($slug) . '&notice=cert_quiz_pending');
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

// ── Helpers ──────────────────────────────────────────────────────────────────
function certFormatDate(string $dateStr): string {
    $months = [
        1=>'janeiro',  2=>'fevereiro', 3=>'março',    4=>'abril',
        5=>'maio',     6=>'junho',     7=>'julho',    8=>'agosto',
        9=>'setembro', 10=>'outubro', 11=>'novembro', 12=>'dezembro',
    ];
    $ts = strtotime($dateStr);
    if (!$ts) return $dateStr;
    return (int)date('d', $ts) . ' de ' . $months[(int)date('n', $ts)] . ' de ' . date('Y', $ts);
}

// ── Renderização do certificado ──────────────────────────────────────────────
function renderCertificate(?array $cert, array $settings, bool $notFound): void {

    $appName    = htmlspecialchars($settings['site_name']  ?? 'CloudiLMS');
    $certTitle  = htmlspecialchars($settings['cert_title'] ?? 'Certificado de Conclusão');
    $footerText = htmlspecialchars($settings['cert_footer'] ?? '');
    $certNote   = htmlspecialchars(trim($settings['cert_body'] ?? ''));

    $primaryColor = $settings['cert_primary_color'] ?? '#1a3a5c';
    $accentColor  = $settings['cert_accent_color']  ?? '#c9a84c';
    // Prevent CSS injection via color fields
    if (!preg_match('/^#[0-9a-f]{3,8}$/i', $primaryColor)) $primaryColor = '#1a3a5c';
    if (!preg_match('/^#[0-9a-f]{3,8}$/i', $accentColor))  $accentColor  = '#c9a84c';
    $pC = htmlspecialchars($primaryColor);
    $aC = htmlspecialchars($accentColor);

    // Validate background image URL — only http/https, strip CSS-breaking chars
    $bgRaw  = trim($settings['cert_bg_image'] ?? '');
    $bgSafe = '';
    if ($bgRaw && preg_match('/^https?:\/\//i', $bgRaw)) {
        $bgSafe = str_replace(["'", '"', '\\', "\n", "\r", ')'], ['%27','%22','%5C','','','%29'], $bgRaw);
    }

    $student = $course = $issuer = $workload = $issuedAt = $code = $verifyUrl = '';

    if ($cert) {
        $student   = htmlspecialchars($cert['snapshot_student_name']);
        $course    = htmlspecialchars($cert['snapshot_course_title']);
        $issuer    = htmlspecialchars($cert['snapshot_issuer']);
        $workload  = CertificateModel::formatWorkload((int) $cert['workload_minutes']);
        $issuedAt  = certFormatDate($cert['issued_at']);
        $code      = $cert['cert_code'];
        $verifyUrl = htmlspecialchars(APP_URL . '/certificate.php?code=' . $cert['cert_code']);
    }
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
  <meta charset="UTF-8">
  <title><?= $certTitle ?> — <?= $appName ?></title>
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <style>
    @page { size: A4 landscape; margin: 0; }
    *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

    body {
      font-family: Georgia, 'Times New Roman', serif;
      background: #c8d3de;
    }

    /* ── Action toolbar ───────────────────────────────────────────────────── */
    .cert-toolbar {
      display: flex; gap: .75rem; justify-content: center; align-items: center;
      padding: .65rem 1rem; background: #0f172a;
      position: sticky; top: 0; z-index: 50;
      border-bottom: 2px solid <?= $aC ?>;
    }
    .cert-toolbar a, .cert-toolbar button {
      padding: .4rem 1.15rem; border-radius: .3rem;
      font-family: system-ui, sans-serif; font-size: .84rem; font-weight: 600;
      border: none; cursor: pointer; text-decoration: none; line-height: 1.5;
      transition: opacity .15s;
    }
    .cert-toolbar a:hover, .cert-toolbar button:hover { opacity: .8; }
    .btn-back  { background: #334155; color: #f1f5f9; }
    .btn-print { background: <?= $aC ?>; color: #0f172a; }
    .btn-share { background: #0f766e; color: #f0fdfa; }

    /* ── Certificate page ─────────────────────────────────────────────────── */
    .cert-page {
      width: 297mm; min-height: 210mm;
      margin: 1.5rem auto 3rem;
      position: relative; overflow: hidden;
      display: flex; flex-direction: column;
      background-color: #fdf8ee;
      <?php if ($bgSafe): ?>background-image: url('<?= $bgSafe ?>'); background-size: cover; background-position: center;<?php endif; ?>
      box-shadow: 0 12px 60px rgba(0,0,0,.38);
    }
    <?php if ($bgSafe): ?>
    .cert-page::before {
      content: ''; position: absolute; inset: 0;
      background: rgba(253,248,238,.87); z-index: 0;
    }
    <?php endif; ?>

    /* ── Decorative frames + corner ornaments ─────────────────────────────── */
    .cert-frame-outer { position: absolute; inset: 8px;  border: 3px solid <?= $pC ?>; pointer-events: none; z-index: 5; }
    .cert-frame-inner { position: absolute; inset: 14px; border: 1px solid <?= $aC ?>; pointer-events: none; z-index: 5; }
    .cert-corner {
      position: absolute; z-index: 6; pointer-events: none;
      font-size: 1.25rem; color: <?= $aC ?>; line-height: 1;
    }
    .cert-corner--tl { top: 5px; left: 5px; }
    .cert-corner--tr { top: 5px; right: 5px; display: block; transform: scaleX(-1); }
    .cert-corner--bl { bottom: 5px; left: 5px; display: block; transform: scaleY(-1); }
    .cert-corner--br { bottom: 5px; right: 5px; display: block; transform: scale(-1,-1); }

    /* Content wrapper above bg/overlay */
    .cert-inner { position: relative; z-index: 2; flex: 1; display: flex; flex-direction: column; }

    /* ── Header ───────────────────────────────────────────────────────────── */
    .cert-header {
      text-align: center; padding: 1.75rem 3rem 1.1rem;
      border-bottom: 1px solid <?= $aC ?>;
    }
    .cert-platform {
      font-family: system-ui, sans-serif;
      font-size: .68rem; letter-spacing: .28em;
      text-transform: uppercase; color: <?= $aC ?>; margin-bottom: .5rem;
    }
    .cert-title-text {
      font-family: Georgia, serif;
      font-size: 1.95rem; font-weight: 700;
      color: <?= $pC ?>; letter-spacing: .055em; line-height: 1.15;
    }
    .cert-ornament { display: flex; align-items: center; justify-content: center; gap: .9rem; margin-top: .65rem; }
    .cert-orn-arm   { height: 1px; width: 52px; background: linear-gradient(to right, transparent, <?= $aC ?>); }
    .cert-orn-arm-r { background: linear-gradient(to left, transparent, <?= $aC ?>); }
    .cert-orn-sym   { color: <?= $aC ?>; font-size: 1.05rem; }

    /* ── Body ─────────────────────────────────────────────────────────────── */
    .cert-body {
      flex: 1; padding: 1.15rem 4rem .8rem;
      text-align: center; display: flex; flex-direction: column;
      align-items: center; justify-content: center; gap: 0;
    }
    .cert-we-certify {
      font-family: system-ui, sans-serif;
      font-size: .68rem; letter-spacing: .18em;
      text-transform: uppercase; color: #7e8fa2; margin-bottom: .3rem;
    }
    .cert-student-name {
      font-size: 2.5rem; font-style: italic; font-weight: bold;
      color: <?= $pC ?>; letter-spacing: .01em; line-height: 1.2; margin-top: .15rem;
    }
    .cert-name-underline {
      width: 320px; max-width: 74%; height: 2px;
      background: linear-gradient(to right, transparent 0%, <?= $aC ?> 30%, <?= $aC ?> 70%, transparent 100%);
      margin: .4rem auto .8rem;
    }
    .cert-completed-label {
      font-family: system-ui, sans-serif;
      font-size: .85rem; color: #4a5568; margin-bottom: .3rem;
    }
    .cert-course-name {
      font-size: 1.3rem; font-weight: 700; font-style: italic;
      color: <?= $pC ?>; line-height: 1.35;
      max-width: 72%; margin: 0 auto .85rem;
    }
    .cert-info-row { display: flex; gap: 1.25rem; justify-content: center; flex-wrap: wrap; margin-top: .25rem; }
    .cert-info-box { border: 1px solid <?= $aC ?>; border-radius: .3rem; padding: .38rem 1.1rem; text-align: center; min-width: 140px; }
    .cert-info-label {
      font-family: system-ui, sans-serif; font-size: .6rem; letter-spacing: .14em;
      text-transform: uppercase; color: #8a9bae; display: block; margin-bottom: .2rem;
    }
    .cert-info-value { font-family: system-ui, sans-serif; font-size: .9rem; font-weight: 700; color: <?= $pC ?>; }
    .cert-note { font-size: .78rem; color: #5a6a7a; font-style: italic; margin-top: .6rem; max-width: 65%; }

    /* ── Signature footer ─────────────────────────────────────────────────── */
    .cert-footer {
      padding: .5rem 4rem .65rem;
      border-top: 1px solid <?= $aC ?>;
      display: grid; grid-template-columns: 1fr 64px 1fr;
      align-items: end; gap: .75rem;
    }
    .cert-sig { text-align: center; }
    .cert-sig-line { border-top: 1.5px solid <?= $pC ?>; padding-top: .3rem; margin-top: 1.6rem; }
    .cert-sig-name { font-family: system-ui, sans-serif; font-weight: 700; font-size: .82rem; color: <?= $pC ?>; }
    .cert-sig-role { font-family: system-ui, sans-serif; font-size: .68rem; color: #7e8fa2; margin-top: .07rem; }
    .cert-stamp {
      display: flex; align-items: center; justify-content: center;
      width: 54px; height: 54px; border: 2px solid <?= $aC ?>;
      border-radius: 50%; font-size: 1.45rem; color: <?= $aC ?>; opacity: .55;
      justify-self: center; align-self: end;
    }

    /* ── Meta bar ─────────────────────────────────────────────────────────── */
    .cert-meta {
      border-top: 1px solid rgba(0,0,0,.07); padding: .27rem 1.6rem;
      display: flex; justify-content: space-between; align-items: center;
      font-family: system-ui, sans-serif; font-size: .57rem; color: #94a3b8;
      background: rgba(253,248,238,.65); position: relative; z-index: 3;
    }
    .cert-meta a { color: #94a3b8; }
    .cert-meta strong { color: #64748b; }

    /* ── Watermark ────────────────────────────────────────────────────────── */
    .cert-watermark {
      position: absolute; top: 50%; left: 50%;
      transform: translate(-50%, -50%) rotate(-22deg);
      font-family: system-ui, sans-serif; font-size: 5.5rem; font-weight: 900;
      letter-spacing: .1em; color: <?= $pC ?>; opacity: .032;
      white-space: nowrap; pointer-events: none; user-select: none; z-index: 1;
    }

    /* ── Not found ────────────────────────────────────────────────────────── */
    .cert-not-found {
      flex: 1; display: flex; flex-direction: column;
      justify-content: center; align-items: center; gap: .8rem;
      padding: 4rem; text-align: center;
      font-family: system-ui, sans-serif; color: #475569;
    }
    .cert-not-found h2 { font-size: 1.4rem; color: <?= $pC ?>; }

    /* ── Print ────────────────────────────────────────────────────────────── */
    @media print {
      body { background: white; }
      .cert-toolbar { display: none !important; }
      .cert-page { margin: 0; box-shadow: none; width: 100%; min-height: 100vh; }
    }

    /* ── Responsive ───────────────────────────────────────────────────────── */
    @media (max-width: 900px) {
      .cert-page   { width: 100%; min-height: unset; margin: 0; }
      .cert-body   { padding: 1rem 1.5rem .75rem; }
      .cert-footer { padding: .5rem 1.5rem .65rem; grid-template-columns: 1fr auto 1fr; }
      .cert-course-name  { max-width: 95%; }
      .cert-student-name { font-size: 2rem; }
    }
  </style>
</head>
<body>

<?php if (!$notFound && $cert): ?>
<div class="cert-toolbar">
  <a href="<?= htmlspecialchars(APP_URL) ?>/dashboard.php" class="btn-back">← Meus cursos</a>
  <button onclick="window.print()" class="btn-print">🖨 Imprimir / Salvar PDF</button>
  <a href="<?= $verifyUrl ?>" class="btn-share" target="_blank">🔗 Compartilhar</a>
</div>
<?php endif; ?>

<div class="cert-page">
  <div class="cert-frame-outer"></div>
  <div class="cert-frame-inner"></div>
  <span class="cert-corner cert-corner--tl">✦</span>
  <span class="cert-corner cert-corner--tr">✦</span>
  <span class="cert-corner cert-corner--bl">✦</span>
  <span class="cert-corner cert-corner--br">✦</span>

  <?php if ($notFound): ?>
  <div class="cert-not-found">
    <div style="font-size:3rem">🔍</div>
    <h2>Certificado não encontrado</h2>
    <p>O código informado não corresponde a nenhum certificado válido nesta plataforma.</p>
    <p style="margin-top:.75rem">
      <a href="<?= htmlspecialchars(APP_URL) ?>/index.php" style="color:<?= $pC ?>">← Ir para o início</a>
    </p>
  </div>

  <?php else: ?>
  <div class="cert-watermark"><?= $appName ?></div>
  <div class="cert-inner">

    <header class="cert-header">
      <div class="cert-platform">☁ <?= $appName ?></div>
      <div class="cert-title-text"><?= $certTitle ?></div>
      <div class="cert-ornament">
        <span class="cert-orn-arm"></span>
        <span class="cert-orn-sym">❧</span>
        <span class="cert-orn-arm cert-orn-arm-r"></span>
      </div>
    </header>

    <section class="cert-body">
      <p class="cert-we-certify">Certificamos que</p>
      <p class="cert-student-name"><?= $student ?></p>
      <div class="cert-name-underline"></div>
      <p class="cert-completed-label">concluiu com êxito o curso</p>
      <p class="cert-course-name"><?= $course ?></p>
      <div class="cert-info-row">
        <?php if ((int)$cert['workload_minutes'] > 0): ?>
        <div class="cert-info-box">
          <span class="cert-info-label">Carga horária</span>
          <span class="cert-info-value">⏱ <?= $workload ?></span>
        </div>
        <?php endif; ?>
        <div class="cert-info-box">
          <span class="cert-info-label">Data de emissão</span>
          <span class="cert-info-value">📅 <?= $issuedAt ?></span>
        </div>
      </div>
      <?php if ($certNote): ?><p class="cert-note"><?= $certNote ?></p><?php endif; ?>
    </section>

    <footer class="cert-footer">
      <div class="cert-sig">
        <div class="cert-sig-line">
          <div class="cert-sig-name"><?= $issuer ?></div>
          <div class="cert-sig-role">Emissor</div>
        </div>
      </div>
      <div class="cert-stamp">☁</div>
      <div class="cert-sig">
        <div class="cert-sig-line">
          <div class="cert-sig-name"><?= $issuedAt ?></div>
          <div class="cert-sig-role">Data de emissão</div>
        </div>
      </div>
    </footer>

    <div class="cert-meta">
      <span>Cód. verificação: <strong><?= htmlspecialchars(strtoupper(substr($code, 0, 8))) ?>…</strong></span>
      <?php if ($footerText): ?><span><?= $footerText ?></span><?php endif; ?>
      <span>Autenticidade: <a href="<?= $verifyUrl ?>"><?= $verifyUrl ?></a></span>
    </div>

  </div><!-- .cert-inner -->
  <?php endif; ?>

</div><!-- .cert-page -->

</body>
</html>
<?php
}
