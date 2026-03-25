<?php
/**
 * CloudiLMS - Dashboard do aluno (meus cursos)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
$auth->requireLogin();

$model   = new CourseModel();
$userId  = (int)$_SESSION['user_id'];
$courses = $model->getEnrolledCourses($userId);

siteHeader('Meus Cursos');
?>

<h1 class="page-heading">Meus Cursos</h1>

<?php if ($courses): ?>
<div class="course-grid">
  <?php foreach ($courses as $c): ?>
  <?php $pct = $c['lesson_count'] ? round($c['completed_count'] / $c['lesson_count'] * 100) : 0; ?>
  <a href="course.php?slug=<?= urlencode($c['slug']) ?>" class="course-card">
    <?php if ($c['thumbnail']): ?>
    <div class="course-thumb" style="background-image:url('<?= htmlspecialchars($c['thumbnail']) ?>')"></div>
    <?php else: ?>
    <div class="course-thumb course-thumb-placeholder">🎓</div>
    <?php endif; ?>
    <div class="course-card-body">
      <h3 class="course-title"><?= htmlspecialchars($c['title']) ?></h3>
      <div class="progress-mini-wrap">
        <div class="progress-mini-track"><div class="progress-mini-fill" style="width:<?= $pct ?>%"></div></div>
        <span><?= $pct ?>%</span>
      </div>
      <div class="course-meta">
        <span>✅ <?= $c['completed_count'] ?>/<?= $c['lesson_count'] ?> aulas</span>
        <span class="btn-enroll">Continuar →</span>
      </div>
    </div>
  </a>
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
