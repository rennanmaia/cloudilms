<?php
/**
 * CloudiLMS - Player de vídeo (aula)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/googledrive.php';
require_once __DIR__ . '/includes/activity_log.php';
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

// Flags da aula atual (por aula)
$forceSequential = !empty($lesson['force_sequential']);
$preventSeek     = !empty($lesson['prevent_seek']);

// Pre-calcula quais aulas estão bloqueadas:
// Aula N está bloqueada se ela tem force_sequential=1 e a aula N-1 não foi concluída.
$lessonIds = array_column($lessons, 'id');
$lockedIds = [];
foreach ($lessons as $i => $l) {
    if (!empty($l['force_sequential']) && $i > 0 && !in_array($lessons[$i - 1]['id'], $progress)) {
        $lockedIds[$l['id']] = true;
    }
}

// Bloqueia acesso direto a aula bloqueada
if (isset($lockedIds[$lessonId])) {
    header('Location: ' . APP_URL . '/course.php?slug=' . urlencode($course['slug']) . '&notice=sequential');
    exit;
}

// Log de visualização da aula
ActivityLog::record('lesson_view', [
    'entity_type'  => 'lesson',
    'entity_id'    => $lessonId,
    'entity_title' => $lesson['title'],
]);

// Marca como concluído via AJAX
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_complete'])) {
    if (in_array($_POST['lesson_id'] ?? '', array_column($lessons, 'id'))) {
        $completedLessonId = (int)$_POST['lesson_id'];
        $model->markComplete($userId, $course['id'], $completedLessonId);
        ActivityLog::record('lesson_complete', [
            'entity_type'  => 'lesson',
            'entity_id'    => $completedLessonId,
            'entity_title' => $lesson['title'],
        ]);
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
          <?php $lessonNum++; $done = in_array($l['id'], $progress); $active = $l['id'] == $lessonId; $locked = isset($lockedIds[$l['id']]); ?>
          <li class="watch-lesson-item <?= $active ? 'active' : '' ?> <?= $done ? 'done' : '' ?> <?= $group['topic'] ? 'indented' : '' ?> <?= $locked ? 'locked' : '' ?>">
            <?php if ($locked): ?>
              <span class="wl-lock-wrap">
                <span class="wl-index"><?= $lessonNum ?></span>
                <span class="wl-title"><?= htmlspecialchars($l['title']) ?></span>
                <span class="wl-lock">🔒</span>
              </span>
            <?php else: ?>
            <a href="watch.php?lesson=<?= $l['id'] ?>">
              <span class="wl-index"><?= $lessonNum ?></span>
              <span class="wl-title"><?= htmlspecialchars($l['title']) ?></span>
              <?php if ($done): ?><span class="wl-check">✓</span><?php endif; ?>
            </a>
            <?php endif; ?>
          </li>
          <?php endforeach; ?>
        <?php endforeach; ?>
      <?php else: ?>
        <?php foreach ($lessons as $i => $l): ?>
        <?php $done = in_array($l['id'], $progress); $active = $l['id'] == $lessonId; $locked = isset($lockedIds[$l['id']]); ?>
        <li class="watch-lesson-item <?= $active ? 'active' : '' ?> <?= $done ? 'done' : '' ?> <?= $locked ? 'locked' : '' ?>">
          <?php if ($locked): ?>
            <span class="wl-lock-wrap">
              <span class="wl-index"><?= $i + 1 ?></span>
              <span class="wl-title"><?= htmlspecialchars($l['title']) ?></span>
              <span class="wl-lock">🔒</span>
            </span>
          <?php else: ?>
          <a href="watch.php?lesson=<?= $l['id'] ?>">
            <span class="wl-index"><?= $i + 1 ?></span>
            <span class="wl-title"><?= htmlspecialchars($l['title']) ?></span>
            <?php if ($done): ?><span class="wl-check">✓</span><?php endif; ?>
          </a>
          <?php endif; ?>
        </li>
        <?php endforeach; ?>
      <?php endif; ?>
    </ol>
  </aside>

  <!-- Player principal -->
  <div class="watch-main">
    <div class="video-container" id="videoContainer">
      <iframe id="lessonIframe"
              src="<?= htmlspecialchars($embedUrl) ?>"
              width="100%" height="100%"
              frameborder="0"
              allow="autoplay"
              allowfullscreen
              sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"
      ></iframe>
      <?php if ($preventSeek): ?>
      <!-- Bloqueia apenas a barra de progresso do player do Drive (região inferior) -->
      <div id="seekBlocker" class="seek-blocker" title="Avançar o vídeo está desabilitado"></div>
      <?php endif; ?>
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
/* ── Configurações vindas do servidor ────────────────── */
const LESSON_ID       = <?= $lessonId ?>;
const LESSON_DURATION = <?= (int)($lesson['duration_seconds'] ?? 0) ?>;  // segundos
const PREVENT_SEEK    = <?= $preventSeek     ? 'true' : 'false' ?>;
const ALREADY_DONE    = <?= in_array($lessonId, $progress) ? 'true' : 'false' ?>;

/* ── Referências DOM ─────────────────────────────────── */
const iframe    = document.getElementById('lessonIframe');
const markBtn   = document.getElementById('markBtn');
const blocker   = document.getElementById('seekBlocker'); // pode ser null

const iframeSrc = iframe ? iframe.src : '';

/* ── Estado do player ────────────────────────────────── */
let markedComplete = ALREADY_DONE;
let activeSeconds  = 0;
let ticker         = null;
let paused         = false;  // pausa forçada por visibilidade

/* ── Controle de tempo assistido ─────────────────────── */
function startTicker() {
    if (ticker || markedComplete) return;
    ticker = setInterval(() => {
        activeSeconds++;
        checkAutoComplete();
    }, 1000);
}

function stopTicker() {
    if (ticker) { clearInterval(ticker); ticker = null; }
}

function checkAutoComplete() {
    if (markedComplete || !PREVENT_SEEK) return;
    if (!LESSON_DURATION) return; // sem duração cadastrada → só o botão manual
    const threshold = Math.max(1, LESSON_DURATION - 10);
    if (activeSeconds >= threshold) {
        autoMarkComplete();
    }
}

function autoMarkComplete() {
    if (markedComplete) return;
    markedComplete = true;
    stopTicker();
    if (markBtn && !markBtn.classList.contains('btn-done')) {
        markComplete(markBtn);
    }
}

/* ── Pausar quando tela/aba ficar inativa ────────────── */
function pauseVideo() {
    if (paused || !iframe) return;
    paused = true;
    stopTicker();
    // Tenta pausar via postMessage (Google Drive player)
    try {
        iframe.contentWindow.postMessage('{"action":"pauseVideo"}', 'https://drive.google.com');
    } catch (_) {}
    // Fallback: remove src para garantir que o áudio também pare
    iframe.dataset.src = iframe.src;
    iframe.src = '';
}

function resumeVideo() {
    if (!paused || !iframe) return;
    paused = false;
    if (iframe.dataset.src) {
        iframe.src = iframe.dataset.src;
        delete iframe.dataset.src;
    }
    setTimeout(startTicker, 800); // aguarda iframe recarregar antes de contar
}

document.addEventListener('visibilitychange', () => {
    if (document.hidden) {
        pauseVideo();
    } else {
        resumeVideo();
    }
});

/* ── Inicializa o ticker ao carregar ─────────────────── */
if (!markedComplete) {
    startTicker();
}

/* ── Marcar como concluída (manual ou automática) ──────── */
function markComplete(btn) {
    const lid = btn ? btn.dataset.lesson : LESSON_ID;
    const form = new FormData();
    form.append('mark_complete', '1');
    form.append('lesson_id', lid);

    fetch('watch.php?lesson=' + lid, {method: 'POST', body: form})
        .then(r => r.json())
        .then(data => {
            if (data.ok) {
                markedComplete = true;
                stopTicker();
                if (btn) {
                    btn.textContent = '✅ Concluída';
                    btn.classList.add('btn-done');
                }
                const bar = document.getElementById('progressBar');
                const lbl = document.getElementById('progressLabel');
                if (bar) bar.style.width = data.progress + '%';
                if (lbl) lbl.textContent = data.progress + '% concluído';
                // Marca item da lista como concluído
                document.querySelectorAll('.watch-lesson-item.active')
                        .forEach(i => i.classList.add('done'));
            }
        });
}
</script>

<?php siteFooter(); ?>
