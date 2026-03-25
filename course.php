<?php
/**
 * CloudiLMS - Página do curso (lista de aulas)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/layout.php';

$auth  = new Auth();
$model = new CourseModel();

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: ' . APP_URL . '/index.php'); exit; }

$course = $model->getCourseBySlug($slug);
if (!$course) { http_response_code(404); siteHeader('Curso não encontrado'); echo '<div class="empty-state"><h2>Curso não encontrado</h2></div>'; siteFooter(); exit; }

$lessons  = $model->getLessonsByCourse($course['id']);
$logged   = $auth->isLoggedIn();
$enrolled = $logged && $model->isEnrolled((int)$_SESSION['user_id'], $course['id']);
$progress = $enrolled ? $model->getProgress((int)$_SESSION['user_id'], $course['id']) : [];

// Matrícula automática ao clicar em "começar"
if ($logged && isset($_GET['enroll'])) {
    $model->enroll((int)$_SESSION['user_id'], $course['id']);
    header('Location: course.php?slug=' . urlencode($slug));
    exit;
}

siteHeader($course['title']);
?>

<div class="course-page">
  <!-- Header do curso -->
  <div class="course-hero">
    <?php if ($course['thumbnail']): ?>
    <div class="course-hero-thumb" style="background-image:url('<?= htmlspecialchars($course['thumbnail']) ?>')"></div>
    <?php endif; ?>
    <div class="course-hero-info">
      <h1><?= htmlspecialchars($course['title']) ?></h1>
      <?php if ($course['description']): ?>
      <p class="course-hero-desc"><?= nl2br(htmlspecialchars($course['description'])) ?></p>
      <?php endif; ?>
      <div class="course-hero-meta">
        <span>📹 <?= count($lessons) ?> aulas</span>
        <?php if ($enrolled): ?>
          <?php $pct = $lessons ? round(count($progress) / count($lessons) * 100) : 0; ?>
          <span>✅ <?= $pct ?>% concluído</span>
        <?php endif; ?>
      </div>
      <?php if (!$logged): ?>
        <a href="login.php?redirect=<?= urlencode('course.php?slug=' . $slug . '&enroll=1') ?>" class="btn-hero">🔐 Entrar para assistir</a>
      <?php elseif (!$enrolled): ?>
        <a href="course.php?slug=<?= urlencode($slug) ?>&enroll=1" class="btn-hero">🎓 Matricular-se gratuitamente</a>
      <?php else: ?>
        <?php if ($lessons): ?>
        <a href="watch.php?lesson=<?= $lessons[0]['id'] ?>" class="btn-hero">▶ Continuar assistindo</a>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lista de aulas -->
  <div class="lesson-list-public">
    <h2>Conteúdo do curso</h2>
    <ol class="public-lessons">
      <?php foreach ($lessons as $i => $l): ?>
      <?php $done = in_array($l['id'], $progress); ?>
      <li class="public-lesson-item <?= $done ? 'lesson-done' : '' ?>">
        <?php if ($enrolled): ?>
        <a href="watch.php?lesson=<?= $l['id'] ?>" class="lesson-link">
          <?php else: ?>
          <span class="lesson-link lesson-locked">
          <?php endif; ?>
          <span class="lesson-index"><?= $i + 1 ?></span>
          <span class="lesson-name"><?= htmlspecialchars($l['title']) ?></span>
          <?php if ($l['duration_seconds']): ?>
          <span class="lesson-dur"><?= gmdate($l['duration_seconds'] >= 3600 ? 'H:i:s' : 'i:s', $l['duration_seconds']) ?></span>
          <?php endif; ?>
          <?php if ($done): ?><span class="lesson-check">✓</span><?php endif; ?>
          <?php if (!$enrolled): ?><span class="lesson-lock">🔒</span><?php endif; ?>
          <?php if ($enrolled): ?></a><?php else: ?></span><?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
</div>

<?php siteFooter(); ?>
