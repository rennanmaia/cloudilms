<?php
/**
 * CloudiLMS - Trilhas de Aprendizagem do Aluno
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/trail.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();
$auth->requireLogin();

$model  = new TrailModel();
$userId = (int)$_SESSION['user_id'];
$trails = $model->getUserTrailsWithProgress($userId);

siteHeader('Minhas Trilhas');
?>

<h1 class="page-heading">Minhas Trilhas</h1>

<?php if ($trails): ?>
<div class="trails-grid">
  <?php foreach ($trails as $t):
    $totalCourses    = count($t['courses']);
    $completedCourses = 0;
    foreach ($t['courses'] as $c) {
        if ($c['lesson_count'] > 0 && $c['completed_count'] >= $c['lesson_count']) $completedCourses++;
    }
    $trailPct  = $totalCourses ? round($completedCourses / $totalCourses * 100) : 0;
    $isLocked  = $t['status'] === 'locked';
  ?>
  <div class="trail-card <?= $isLocked ? 'trail-card--locked' : '' ?>">
    <div class="trail-card-header">
      <div class="trail-card-title">
        <span class="trail-card-icon"><?= $isLocked ? '🔴' : '🟢' ?></span>
        <div>
          <h2><?= htmlspecialchars($t['title']) ?></h2>
          <?php if ($t['description']): ?>
          <p class="trail-card-desc"><?= htmlspecialchars($t['description']) ?></p>
          <?php endif; ?>
        </div>
      </div>
      <span class="trail-status-pill <?= $isLocked ? 'trail-status-pill--locked' : 'trail-status-pill--unlocked' ?>">
        <?= $isLocked ? '🔒 Bloqueada' : '✅ Liberada' ?>
      </span>
    </div>

    <div class="trail-progress-bar-wrap">
      <div class="trail-progress-bar" style="width:<?= $trailPct ?>%"></div>
    </div>
    <p class="trail-progress-label"><?= $completedCourses ?>/<?= $totalCourses ?> cursos concluídos · <?= $trailPct ?>%</p>

    <?php if ($isLocked): ?>
    <div class="trail-locked-notice">
      🔒 Esta trilha está <strong>bloqueada para matrícula</strong>. Entre em contato com o administrador para liberar o acesso.
    </div>
    <?php endif; ?>

    <ol class="trail-course-sequence">
      <?php foreach ($t['courses'] as $i => $c):
        $cPct     = $c['lesson_count'] ? round($c['completed_count'] / $c['lesson_count'] * 100) : 0;
        $done     = $c['lesson_count'] > 0 && $c['completed_count'] >= $c['lesson_count'];
        $enrolled = $c['enrolled'];
      ?>
      <li class="trail-seq-item <?= $done ? 'trail-seq-item--done' : ($enrolled ? 'trail-seq-item--active' : '') ?> <?= $isLocked && !$enrolled ? 'trail-seq-item--blocked' : '' ?>">
        <span class="trail-seq-num"><?= $i + 1 ?></span>
        <div class="trail-seq-info">
          <span class="trail-seq-title"><?= htmlspecialchars($c['title']) ?></span>
          <?php if ($enrolled): ?>
          <div class="trail-seq-progress">
            <div class="trail-seq-bar"><div class="trail-seq-fill" style="width:<?= $cPct ?>%"></div></div>
            <span><?= $cPct ?>%</span>
          </div>
          <?php endif; ?>
        </div>
        <div class="trail-seq-action">
          <?php if ($done): ?>
            <a href="<?= APP_URL ?>/course.php?slug=<?= urlencode($c['slug']) ?>" class="btn-trail-action btn-trail-done">✅ Concluído</a>
          <?php elseif ($enrolled): ?>
            <a href="<?= APP_URL ?>/course.php?slug=<?= urlencode($c['slug']) ?>" class="btn-trail-action btn-trail-continue">▶ Continuar</a>
          <?php elseif (!$isLocked): ?>
            <a href="<?= APP_URL ?>/course.php?slug=<?= urlencode($c['slug']) ?>&enroll=1" class="btn-trail-action btn-trail-enroll">+ Matricular</a>
          <?php else: ?>
            <span class="btn-trail-action btn-trail-blocked">🔒 Bloqueado</span>
          <?php endif; ?>
        </div>
      </li>
      <?php endforeach; ?>
    </ol>
  </div>
  <?php endforeach; ?>
</div>
<?php else: ?>
<div class="empty-state">
  <div class="empty-icon">🗺️</div>
  <h2>Você não está associado a nenhuma trilha</h2>
  <p style="color:var(--text2);margin-top:.5rem">As trilhas de aprendizagem são atribuídas pelo administrador.</p>
  <a href="<?= APP_URL ?>/index.php" class="btn-hero" style="margin-top:1.5rem">Ver cursos disponíveis →</a>
</div>
<?php endif; ?>

<?php siteFooter(); ?>
