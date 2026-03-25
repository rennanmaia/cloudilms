<?php
/**
 * CloudiLMS - Página inicial (catálogo de cursos)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/layout.php';

$model   = new CourseModel();
$courses = $model->getAllCourses(true); // somente publicados

siteHeader('Catálogo de Cursos');
?>

<section class="hero">
  <h1>Aprenda no seu ritmo</h1>
  <p>Acesse os cursos disponíveis e assista quando quiser</p>
</section>

<section class="course-grid">
  <?php foreach ($courses as $c): ?>
  <a href="course.php?slug=<?= urlencode($c['slug']) ?>" class="course-card">
    <?php if ($c['thumbnail']): ?>
    <div class="course-thumb" style="background-image:url('<?= htmlspecialchars($c['thumbnail']) ?>')"></div>
    <?php else: ?>
    <div class="course-thumb course-thumb-placeholder">🎓</div>
    <?php endif; ?>
    <div class="course-card-body">
      <h3 class="course-title"><?= htmlspecialchars($c['title']) ?></h3>
      <?php if ($c['description']): ?>
      <p class="course-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 100, '…')) ?></p>
      <?php endif; ?>
      <div class="course-meta">
        <span>▶ <?= $c['lesson_count'] ?> aulas</span>
        <span class="btn-enroll">Ver curso →</span>
      </div>
    </div>
  </a>
  <?php endforeach; ?>

  <?php if (!$courses): ?>
  <div class="empty-state">
    <div class="empty-icon">📚</div>
    <h2>Nenhum curso disponível ainda</h2>
    <p>Volte em breve!</p>
  </div>
  <?php endif; ?>
</section>

<?php siteFooter(); ?>
