<?php
/**
 * CloudiLMS - Dashboard do aluno (meus cursos)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/trail.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
$auth->requireLogin();

$model      = new CourseModel();
$trailModel = new TrailModel();
$userId     = (int)$_SESSION['user_id'];

// CSRF
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// Cancelar matrícula (aluno)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'cancel_enrollment') {
    if (($_POST['csrf'] ?? '') !== $csrf) { http_response_code(403); exit('Forbidden'); }
    $courseId = (int)($_POST['course_id'] ?? 0);
    if ($courseId && $model->isEnrolled($userId, $courseId)) {
        $course = $model->getCourseById($courseId);
        $model->cancelEnrollment($userId, $courseId);
        ActivityLog::record('course_unenroll', [
            'entity_type'  => 'course',
            'entity_id'    => $courseId,
            'entity_title' => $course['title'] ?? '',
        ]);
    }
    header('Location: dashboard.php?msg=unenrolled');
    exit;
}

$courses = $model->getEnrolledCourses($userId);
$trails  = $trailModel->getUserTrails($userId);
$msgOk   = ($_GET['msg'] ?? '') === 'unenrolled';

siteHeader('Meus Cursos');
?>

<h1 class="page-heading">Meus Cursos</h1>

<?php if ($msgOk): ?>
<div class="alert-front alert-front-success">✅ Matrícula cancelada. Seu histórico neste curso foi removido.</div>
<?php endif; ?>

<?php if ($trails): ?>
<div class="dashboard-trails-bar">
  <span class="dashboard-trails-label">🗺️ Minhas Trilhas:</span>
  <?php foreach ($trails as $t): ?>
  <a href="<?= APP_URL ?>/trails.php" class="dashboard-trail-chip <?= $t['status'] === 'locked' ? 'chip-locked' : 'chip-unlocked' ?>">
    <?= $t['status'] === 'locked' ? '🔴' : '🟢' ?> <?= htmlspecialchars($t['title']) ?>
  </a>
  <?php endforeach; ?>
  <a href="<?= APP_URL ?>/trails.php" class="dashboard-trails-more">Ver todas →</a>
</div>
<?php endif; ?>

<?php if ($courses): ?>
<div class="course-grid">
  <?php foreach ($courses as $c): ?>
  <?php
    $pct        = $c['lesson_count'] ? round($c['completed_count'] / $c['lesson_count'] * 100) : 0;
    $isExpired  = !empty($c['is_expired']);
    $expiresAt  = $c['expires_at'] ?? null;
    $daysLeft   = ($expiresAt && !$isExpired) ? (int)ceil((strtotime($expiresAt) - time()) / 86400) : null;
  ?>
  <div class="course-card-wrap <?= $isExpired ? 'course-card-expired' : '' ?>">
    <a href="course.php?slug=<?= urlencode($c['slug']) ?>" class="course-card">
    <?php if ($c['thumbnail']): ?>
    <div class="course-thumb" style="background-image:url('<?= htmlspecialchars($c['thumbnail']) ?>')"></div>
    <?php else: ?>
    <div class="course-thumb course-thumb-placeholder">🎓</div>
    <?php endif; ?>
    <div class="course-card-body">
      <h3 class="course-title"><?= htmlspecialchars($c['title']) ?></h3>
      <?php if ($isExpired): ?>
      <div class="course-expired-badge">⏰ Matrícula expirada</div>
      <?php elseif ($daysLeft !== null && $daysLeft <= 7): ?>
      <div class="course-expiry-warning">⚠️ Expira em <?= $daysLeft ?> dia(s)</div>
      <?php elseif ($expiresAt): ?>
      <div class="course-expiry-info">📅 Prazo: <?= date('d/m/Y', strtotime($expiresAt)) ?></div>
      <?php endif; ?>
      <div class="progress-mini-wrap">
        <div class="progress-mini-track"><div class="progress-mini-fill" style="width:<?= $pct ?>%"></div></div>
        <span><?= $pct ?>%</span>
      </div>
      <div class="course-meta">
        <span>✅ <?= $c['completed_count'] ?>/<?= $c['lesson_count'] ?> aulas</span>
        <?php if ($isExpired): ?>
        <span class="badge-expired-small">Expirado</span>
        <?php elseif ($c['cert_code']): ?>
        <span style="color:#c9a84c">📜 Certificado</span>
        <?php else: ?>
        <span class="btn-enroll">Continuar →</span>
        <?php endif; ?>
      </div>
    </div>
    </a>
    <?php if (!$isExpired): ?>
    <form method="post" action="dashboard.php" class="course-unenroll-form"
          onsubmit="return confirm('Cancelar matrícula em &quot;<?= htmlspecialchars(addslashes($c['title'])) ?>&quot;?\n\nSeu progresso e certificado (se houver) serão apagados.')">
      <input type="hidden" name="csrf" value="<?= $csrf ?>">
      <input type="hidden" name="_action" value="cancel_enrollment">
      <input type="hidden" name="course_id" value="<?= $c['id'] ?>">
      <button type="submit" class="btn-unenroll" title="Cancelar matrícula">✕ Cancelar matrícula</button>
    </form>
    <?php else: ?>
    <div class="course-expired-notice">Solicite ao administrador para reativar este acesso.</div>
    <?php endif; ?>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-state">
  <div class="empty-icon">📚</div>
  <h2>Você ainda não está matriculado em nenhum curso</h2>
  <a href="index.php" class="btn-hero">Ver cursos disponíveis →</a>
</div>
<?php endif; ?>

<?php siteFooter(); ?>
