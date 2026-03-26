<?php
/**
 * CloudiLMS - Página inicial (catálogo de cursos agrupados por trilha)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/trail.php';
require_once __DIR__ . '/includes/layout.php';

$auth       = new Auth();
$trailModel = new TrailModel();
$userId     = $auth->isLoggedIn() ? (int)$_SESSION['user_id'] : null;

$trails     = $trailModel->getAllTrailsForIndex($userId);
$standalone = $trailModel->getStandalonePublishedCourses();

siteHeader('Catálogo de Cursos');
?>

<section class="hero">
  <h1>Aprenda no seu ritmo</h1>
  <p>Acesse os cursos disponíveis e assista quando quiser</p>
</section>

<?php if (!$trails && !$standalone): ?>
<div class="empty-state">
  <div class="empty-icon">📚</div>
  <h2>Nenhum curso disponível ainda</h2>
  <p>Volte em breve!</p>
</div>
<?php endif; ?>

<?php if ($trails): ?>
<div class="index-trails">
  <?php foreach ($trails as $t):
    $status   = $t['user_status']; // 'unlocked' | 'locked' | null
    $isLocked = ($status === 'locked');
    $isOpen   = ($status === 'unlocked' || $userId === null);
  ?>
  <details class="index-trail <?= $isLocked ? 'index-trail--locked' : '' ?>" <?= $isOpen ? 'open' : '' ?>>
    <summary class="index-trail-summary">
      <div class="index-trail-left">
        <span class="index-trail-icon">
          <?= $isLocked ? '🔴' : ($status === 'unlocked' ? '🟢' : '🗂️') ?>
        </span>
        <div>
          <div class="index-trail-name"><?= htmlspecialchars($t['title']) ?></div>
          <?php if ($t['description']): ?>
          <div class="index-trail-desc"><?= htmlspecialchars(mb_strimwidth($t['description'], 0, 120, '…')) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="index-trail-right">
        <?php if ($userId !== null): ?>
          <?php if ($status === 'unlocked'): ?>
          <span class="trail-status-pill trail-status-pill--unlocked">✅ Liberada</span>
          <?php elseif ($status === 'locked'): ?>
          <span class="trail-status-pill trail-status-pill--locked">🔒 Bloqueada</span>
          <?php else: ?>
          <span class="trail-status-pill trail-status-pill--neutral">📋 Não atribuída</span>
          <?php endif; ?>
        <?php endif; ?>
        <span class="index-trail-count">
          <?= (int)$t['published_course_count'] ?> curso<?= (int)$t['published_course_count'] !== 1 ? 's' : '' ?>
        </span>
        <span class="index-trail-chevron">▼</span>
      </div>
    </summary>

    <?php if ($t['courses']): ?>
    <div class="index-trail-courses">
      <?php foreach ($t['courses'] as $c): ?>
      <a href="course.php?slug=<?= urlencode($c['slug']) ?>" class="course-card">
        <?php if ($c['thumbnail']): ?>
        <div class="course-thumb" style="background-image:url('<?= htmlspecialchars($c['thumbnail']) ?>')"></div>
        <?php else: ?>
        <div class="course-thumb course-thumb-placeholder">🎓</div>
        <?php endif; ?>
        <div class="course-card-body">
          <h3 class="course-title"><?= htmlspecialchars($c['title']) ?></h3>
          <?php if ($c['description']): ?>
          <p class="course-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 90, '…')) ?></p>
          <?php endif; ?>
          <div class="course-meta">
            <span>▶ <?= $c['lesson_count'] ?> aulas</span>
            <span class="btn-enroll">Ver curso →</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="index-trail-empty">Nenhum curso publicado nesta trilha ainda.</p>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($standalone): ?>
<div class="index-standalone">
  <h2 class="index-standalone-header">📚 Cursos Avulsos</h2>
  <div class="index-trail-courses">
    <?php foreach ($standalone as $c): ?>
    <a href="course.php?slug=<?= urlencode($c['slug']) ?>" class="course-card">
      <?php if ($c['thumbnail']): ?>
      <div class="course-thumb" style="background-image:url('<?= htmlspecialchars($c['thumbnail']) ?>')"></div>
      <?php else: ?>
      <div class="course-thumb course-thumb-placeholder">🎓</div>
      <?php endif; ?>
      <div class="course-card-body">
        <h3 class="course-title"><?= htmlspecialchars($c['title']) ?></h3>
        <?php if ($c['description']): ?>
        <p class="course-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 90, '…')) ?></p>
        <?php endif; ?>
        <div class="course-meta">
          <span>▶ <?= $c['lesson_count'] ?> aulas</span>
          <span class="btn-enroll">Ver curso →</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php siteFooter(); ?>
