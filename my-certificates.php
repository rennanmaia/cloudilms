<?php
/**
 * CloudiLMS - Meus Certificados
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/database.php';
require_once __DIR__ . '/includes/certificate.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
$auth->requireLogin();

$userId    = (int)$_SESSION['user_id'];
$certModel = new CertificateModel();
$certs     = $certModel->getAllByUser($userId);

siteHeader('Meus Certificados');
?>

<div class="page-heading-row">
  <h1 class="page-heading">📜 Meus Certificados</h1>
  <a href="dashboard.php" class="btn-back-link">← Meus Cursos</a>
</div>

<?php if ($certs): ?>
<div class="cert-list-grid">
  <?php foreach ($certs as $cert): ?>
  <?php
    $workload  = CertificateModel::formatWorkload((int)$cert['workload_minutes']);
    $issuedAt  = date('d/m/Y', strtotime($cert['issued_at']));
    $viewUrl   = APP_URL . '/certificate.php?code=' . urlencode($cert['cert_code']);
    $shortCode = strtoupper(substr($cert['cert_code'], 0, 8));
  ?>
  <div class="cert-card">
    <div class="cert-card-seal">🏅</div>
    <div class="cert-card-body">
      <h2 class="cert-card-course"><?= htmlspecialchars($cert['snapshot_course_title']) ?></h2>
      <p class="cert-card-student"><?= htmlspecialchars($cert['snapshot_student_name']) ?></p>
      <div class="cert-card-meta">
        <?php if ($cert['workload_minutes'] > 0): ?>
        <span class="cert-meta-badge">⏱ <?= htmlspecialchars($workload) ?></span>
        <?php endif; ?>
        <span class="cert-meta-badge">📅 <?= $issuedAt ?></span>
        <span class="cert-meta-badge cert-code-badge" title="Código completo: <?= htmlspecialchars($cert['cert_code']) ?>">
          🔑 <?= $shortCode ?>…
        </span>
      </div>
    </div>
    <div class="cert-card-actions">
      <a href="<?= htmlspecialchars($viewUrl) ?>" class="btn-cert-view" target="_blank">
        👁 Visualizar
      </a>
      <a href="<?= htmlspecialchars($viewUrl) ?>" class="btn-cert-print" target="_blank" onclick="setTimeout(()=>{ var w=window.open('<?= htmlspecialchars($viewUrl) ?>'); w && w.addEventListener('load',()=>w.print()); }, 100); return false;">
        🖨 Imprimir
      </a>
      <button class="btn-cert-share"
              data-url="<?= htmlspecialchars($viewUrl) ?>"
              title="Copiar link de verificação">
        🔗 Compartilhar
      </button>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<p class="cert-list-info">
  Você possui <strong><?= count($certs) ?></strong> certificado<?= count($certs) !== 1 ? 's' : '' ?>.
  Qualquer pessoa pode verificar a autenticidade pelo link de compartilhamento.
</p>

<?php else: ?>
<div class="empty-state">
  <div class="empty-icon">📜</div>
  <h2>Nenhum certificado ainda</h2>
  <p>Conclua todas as aulas e questionários de um curso para receber seu certificado.</p>
  <a href="dashboard.php" class="btn-hero" style="margin-top:1.5rem">Ver meus cursos →</a>
</div>
<?php endif; ?>

<script>
document.querySelectorAll('.btn-cert-share').forEach(function(btn) {
  btn.addEventListener('click', function() {
    var url = this.dataset.url;
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(url).then(function() {
        btn.textContent = '✅ Copiado!';
        setTimeout(function() { btn.innerHTML = '🔗 Compartilhar'; }, 2000);
      });
    } else {
      prompt('Link de verificação:', url);
    }
  });
});
</script>

<?php siteFooter(); ?>
