<?php
/**
 * CloudiLMS - Player de vídeo (aula)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/googledrive.php';
require_once __DIR__ . '/includes/layout.php';

$auth  = new Auth();
$auth->requireLogin();

$model    = new CourseModel();
$lessonId = (int)($_GET['lesson'] ?? 0);

if (!$lessonId) { header('Location: ' . APP_URL . '/index.php'); exit; }

$lesson = $model->getLessonById($lessonId);
if (!$lesson) { http_response_code(404); siteHeader('Aula não encontrada'); echo '<div class="empty-state"><h2>Aula não encontrada.</h2></div>'; siteFooter(); exit; }

$course = $model->getCourseById($lesson['course_id']);
if (!$course || !$course['published']) { header('Location: ' . APP_URL . '/index.php'); exit; }

$userId   = (int)$_SESSION['user_id'];
$enrolled = $model->isEnrolled($userId, $course['id']);
if (!$enrolled) {
    header('Location: ' . APP_URL . '/course.php?slug=' . urlencode($course['slug']));
    exit;
}

$lessons  = $model->getLessonsByCourse($course['id']);
$grouped  = $model->getLessonsGroupedByTopic($course['id']);
$hasTopics = count($grouped) > 1 || ($grouped[0]['topic'] !== null);
$progress = $model->getProgress($userId, $course['id']);

// Marca como concluído via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_complete'])) {
    if (in_array($_POST['lesson_id'] ?? '', array_column($lessons, 'id'))) {
        $model->markComplete($userId, $course['id'], (int)$_POST['lesson_id']);
        header('Content-Type: application/json');
        $total    = count($lessons);
        $done     = count($model->getProgress($userId, $course['id']));
        echo json_encode(['ok' => true, 'progress' => $total ? round($done / $total * 100) : 0, 'done' => $done, 'total' => $total]);
        exit;
    }
    exit;
}

// Índice da aula atual e anterior/próxima
$currentIndex = array_search($lessonId, array_column($lessons, 'id'));
$prevLesson   = $currentIndex > 0 ? $lessons[$currentIndex - 1] : null;
$nextLesson   = $currentIndex < count($lessons) - 1 ? $lessons[$currentIndex + 1] : null;

$embedUrl = GoogleDrive::getEmbedUrl($lesson['gdrive_file_id']);
$pct = count($lessons) ? round(count($progress) / count($lessons) * 100) : 0;

siteHeader($lesson['title'] . ' - ' . $course['title']);
?>

<div class="watch-layout">
  <!-- Sidebar com lista de aulas -->
  <aside class="watch-sidebar">
    <div class="watch-sidebar-header">
      <a href="course.php?slug=<?= urlencode($course['slug']) ?>">← <?= htmlspecialchars($course['title']) ?></a>
      <div class="progress-bar-wrap">
        <div class="progress-bar-track">
          <div class="progress-bar-fill" style="width:<?= $pct ?>%" id="progressBar"></div>
        </div>
        <span class="progress-label" id="progressLabel"><?= $pct ?>% concluído</span>
      </div>
    </div>
    <ol class="watch-lesson-list">
      <?php if ($hasTopics): ?>
        <?php $lessonNum = 0; ?>
        <?php foreach ($grouped as $group): ?>
          <?php if ($group['topic']): ?>
          <li class="watch-topic-header">
            <span>📁 <?= htmlspecialchars($group['topic']['title']) ?></span>
            <span class="wth-count"><?= count($group['lessons']) ?></span>
          </li>
          <?php endif; ?>
          <?php foreach ($group['lessons'] as $l): ?>
          <?php $lessonNum++; $done = in_array($l['id'], $progress); $active = $l['id'] == $lessonId; ?>
          <li class="watch-lesson-item <?= $active ? 'active' : '' ?> <?= $done ? 'done' : '' ?> <?= $group['topic'] ? 'indented' : '' ?>">
            <a href="watch.php?lesson=<?= $l['id'] ?>">
              <span class="wl-index"><?= $lessonNum ?></span>
              <span class="wl-title"><?= htmlspecialchars($l['title']) ?></span>
              <?php if ($done): ?><span class="wl-check">✓</span><?php endif; ?>
            </a>
          </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($lessons as $i => $l): ?>
        <?php $done = in_array($l['id'], $progress); $active = $l['id'] == $lessonId; ?>
        <li class="watch-lesson-item <?= $active ? 'active' : '' ?> <?= $done ? 'done' : '' ?>">
          <a href="watch.php?lesson=<?= $l['id'] ?>">
            <span class="wl-index"><?= $i + 1 ?></span>
            <span class="wl-title"><?= htmlspecialchars($l['title']) ?></span>
            <?php if ($done): ?><span class="wl-check">✓</span><?php endif; ?>
          </a>
        </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ol>
  </aside>

  <!-- Player principal -->
  <div class="watch-main">
    <div class="video-container">
      <iframe src="<?= htmlspecialchars($embedUrl) ?>"
              width="100%" height="100%"
              frameborder="0"
              allow="autoplay"
              allowfullscreen
              sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"
      ></iframe>
    </div>

    <div class="watch-controls">
      <div class="watch-lesson-title">
        <h2><?= htmlspecialchars($lesson['title']) ?></h2>
        <?php if ($lesson['duration_seconds']): ?>
        <span class="duration"><?= gmdate($lesson['duration_seconds'] >= 3600 ? 'H:i:s' : 'i:s', $lesson['duration_seconds']) ?></span>
        <?php endif; ?>
      </div>

      <div class="watch-actions">
        <?php if ($prevLesson): ?>
        <a href="watch.php?lesson=<?= $prevLesson['id'] ?>" class="btn btn-nav-prev">← Anterior</a>
        <?php endif; ?>

        <?php $isDone = in_array($lessonId, $progress); ?>
        <button id="markBtn" class="btn btn-complete <?= $isDone ? 'btn-done' : '' ?>"
                data-lesson="<?= $lessonId ?>" onclick="markComplete(this)">
          <?= $isDone ? '✅ Concluída' : '✓ Marcar como concluída' ?>
        </button>

        <?php if ($nextLesson): ?>
        <a href="watch.php?lesson=<?= $nextLesson['id'] ?>" class="btn btn-nav-next">Próxima →</a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function markComplete(btn) {
    const lessonId = btn.dataset.lesson;
    const form = new FormData();
    form.append('mark_complete', '1');
    form.append('lesson_id', lessonId);

    fetch('watch.php?lesson=' + lessonId, {method: 'POST', body: form})
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                btn.textContent = '✅ Concluída';
                btn.classList.add('btn-done');
                const bar = document.getElementById('progressBar');
                const lbl = document.getElementById('progressLabel');
                if (bar) bar.style.width = data.progress + '%';
                if (lbl) lbl.textContent = data.progress + '% concluído';
                // Marca item da lista
                const items = document.querySelectorAll('.watch-lesson-item.active');
                items.forEach(i => i.classList.add('done'));
            }
        });
}
</script>

<?php siteFooter(); ?>
