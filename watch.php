<?php
/**
 * CloudiLMS - Player de vídeo (aula)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/googledrive.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/quiz.php';
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
$requireWatch    = !empty($lesson['require_watch']);   // 1 = exige 75%; 0 = conclusão manual livre

// Carrega questionários (sidebar + cálculo de bloqueios)
$_qModel         = new QuizModel();
$_allQuizzes     = $_qModel->getQuizzesByCourse($course['id']);
$quizzesByLesson = [];
$quizzesByTopic  = [];
$quizEndOfCourse = [];
foreach ($_allQuizzes as $_q) {
    if ($_q['placement_type'] === 'after_lesson' && $_q['placement_id']) {
        $quizzesByLesson[(int)$_q['placement_id']][] = $_q;
    } elseif ($_q['placement_type'] === 'after_topic' && $_q['placement_id']) {
        $quizzesByTopic[(int)$_q['placement_id']][] = $_q;
    } else {
        $quizEndOfCourse[] = $_q;
    }
}
$quizBestAttempts = [];
foreach ($_allQuizzes as $_q) {
    $quizBestAttempts[$_q['id']] = $_qModel->getBestAttempt((int)$_q['id'], $userId);
}

// Pre-calcula quais aulas estão bloqueadas:
// (a) force_sequential e a aula anterior não foi concluída
// (b) questionário block_next=1 após a aula anterior não foi aprovado
// (c) questionário block_next=1 do tópico anterior não foi aprovado (fronteira de tópico)
$lessonIds = array_column($lessons, 'id');
$lockedIds = [];
foreach ($lessons as $i => $l) {
    if ($i === 0) continue;
    $prev = $lessons[$i - 1];

    // (a) force_sequential
    if (!empty($l['force_sequential']) && !in_array($prev['id'], $progress)) {
        $lockedIds[$l['id']] = true;
        continue;
    }

    // (b) blocking quiz after previous lesson
    foreach (($quizzesByLesson[(int)$prev['id']] ?? []) as $_bq) {
        if (!empty($_bq['block_next'])) {
            $_ba = $quizBestAttempts[$_bq['id']] ?? null;
            if (!$_ba || !(int)($_ba['passed'] ?? 0)) {
                $lockedIds[$l['id']] = true;
                continue 2;
            }
        }
    }

    // (c) blocking quiz at topic boundary
    $prevTopicId = (int)($prev['topic_id'] ?? 0);
    $currTopicId = (int)($l['topic_id'] ?? 0);
    if ($prevTopicId !== $currTopicId && $prevTopicId && isset($quizzesByTopic[$prevTopicId])) {
        foreach ($quizzesByTopic[$prevTopicId] as $_bq) {
            if (!empty($_bq['block_next'])) {
                $_ba = $quizBestAttempts[$_bq['id']] ?? null;
                if (!$_ba || !(int)($_ba['passed'] ?? 0)) {
                    $lockedIds[$l['id']] = true;
                    continue 2;
                }
            }
        }
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
        $duration          = (int)($lesson['duration_seconds'] ?? 0);
        $watched           = (int)($_POST['watched_seconds'] ?? 0);
        $minRequired       = ($duration > 0 && !empty($lesson['require_watch']))
                             ? (int)floor($duration * 0.75) : 0;

        if ($minRequired > 0 && $watched < $minRequired) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'err' => 'not_enough_watched']);
            exit;
        }

        $model->markComplete($userId, $course['id'], $completedLessonId);
        ActivityLog::record('lesson_complete', [
            'entity_type'  => 'lesson',
            'entity_id'    => $completedLessonId,
            'entity_title' => $lesson['title'],
        ]);
        header('Content-Type: application/json');
        $total          = count($lessons);
        $newProgress    = $model->getProgress($userId, $course['id']);
        $done           = count($newProgress);
        $courseComplete = $done >= $total;

        // ── Verifica questionários pendentes ──────────────────────────────
        require_once __DIR__ . '/includes/quiz.php';
        $quizModel = new QuizModel();
        $quizUrl   = '';

        // 1. Quiz após esta aula específica?
        $pendingQuiz = $quizModel->getPendingQuizAfterLesson($completedLessonId, $userId, $course['id']);

        // 2. Quiz após tópico? (se todas as aulas do tópico foram concluídas)
        if (!$pendingQuiz) {
            $topicId = $quizModel->getLessonTopicId($completedLessonId);
            if ($topicId && $quizModel->isTopicComplete($topicId, $userId, $course['id'])) {
                $pendingQuiz = $quizModel->getPendingQuizAfterTopic($topicId, $userId, $course['id']);
            }
        }

        // 3. Quiz de fim de curso?
        if (!$pendingQuiz && $courseComplete) {
            $pendingQuiz = $quizModel->getPendingEndOfCourseQuiz($course['id'], $userId);
        }

        if ($pendingQuiz) {
            $quizUrl = APP_URL . '/quiz.php?quiz_id=' . $pendingQuiz['id']
                     . '&course_slug=' . urlencode($course['slug']);
        }

        $certUrl = '';
        if ($courseComplete && !$pendingQuiz) {
            $allPassed = $quizModel->allQuizzesPassed($userId, $course['id']);
            if ($allPassed) {
                require_once __DIR__ . '/includes/certificate.php';
                $certUrl = APP_URL . '/certificate.php?course=' . urlencode($course['slug']);
            }
        }

        echo json_encode([
            'ok'             => true,
            'progress'       => $total ? round($done / $total * 100) : 0,
            'done'           => $done,
            'total'          => $total,
            'course_complete'=> $courseComplete,
            'cert_url'       => $certUrl,
            'quiz_url'       => $quizUrl,
        ]);
        exit;
    }
    exit;
}

// Índice da aula atual e anterior/próxima
$currentIndex = array_search($lessonId, array_column($lessons, 'id'));
$prevLesson   = $currentIndex > 0 ? $lessons[$currentIndex - 1] : null;
$nextLesson   = $currentIndex < count($lessons) - 1 ? $lessons[$currentIndex + 1] : null;

$hasVideo = !empty($lesson['gdrive_file_id']);
$embedUrl = $hasVideo ? GoogleDrive::getEmbedUrl($lesson['gdrive_file_id']) : '';
$attachments = $model->getAttachmentsByLesson($lessonId);
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
          <?php if (isset($quizzesByLesson[$l['id']])): ?>
            <?php foreach ($quizzesByLesson[$l['id']] as $_qv): ?>
            <?php $_qA = $quizBestAttempts[$_qv['id']] ?? null; $_qP = $_qA && (int)$_qA['passed']; ?>
            <li class="watch-lesson-item watch-quiz-item <?= $group['topic'] ? 'indented' : '' ?> <?= $_qP ? 'done' : '' ?>">
              <a href="<?= APP_URL ?>/quiz.php?quiz_id=<?= $_qv['id'] ?>&amp;course_slug=<?= urlencode($course['slug']) ?>">
                <span class="wl-index wl-quiz-icon">📝</span>
                <span class="wl-title"><?= htmlspecialchars($_qv['title']) ?></span>
                <?php if ($_qP): ?><span class="wl-check">✓</span>
                <?php elseif (!empty($_qv['block_next'])): ?><span class="wl-lock" title="Aprovação obrigatória para prosseguir">🔒</span><?php endif; ?>
              </a>
            </li>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php endforeach; ?>
          <?php if (!empty($group['topic']['id']) && isset($quizzesByTopic[(int)$group['topic']['id']])): ?>
            <?php foreach ($quizzesByTopic[(int)$group['topic']['id']] as $_qv): ?>
            <?php $_qA = $quizBestAttempts[$_qv['id']] ?? null; $_qP = $_qA && (int)$_qA['passed']; ?>
            <li class="watch-lesson-item watch-quiz-item <?= $_qP ? 'done' : '' ?>">
              <a href="<?= APP_URL ?>/quiz.php?quiz_id=<?= $_qv['id'] ?>&amp;course_slug=<?= urlencode($course['slug']) ?>">
                <span class="wl-index wl-quiz-icon">📝</span>
                <span class="wl-title"><?= htmlspecialchars($_qv['title']) ?></span>
                <?php if ($_qP): ?><span class="wl-check">✓</span>
                <?php elseif (!empty($_qv['block_next'])): ?><span class="wl-lock" title="Aprovação obrigatória para prosseguir">🔒</span><?php endif; ?>
              </a>
            </li>
            <?php endforeach; ?>
          <?php endif; ?>
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
        <?php if (isset($quizzesByLesson[$l['id']])): ?>
          <?php foreach ($quizzesByLesson[$l['id']] as $_qv): ?>
          <?php $_qA = $quizBestAttempts[$_qv['id']] ?? null; $_qP = $_qA && (int)$_qA['passed']; ?>
          <li class="watch-lesson-item watch-quiz-item <?= $_qP ? 'done' : '' ?>">
            <a href="<?= APP_URL ?>/quiz.php?quiz_id=<?= $_qv['id'] ?>&amp;course_slug=<?= urlencode($course['slug']) ?>">
              <span class="wl-index wl-quiz-icon">📝</span>
              <span class="wl-title"><?= htmlspecialchars($_qv['title']) ?></span>
              <?php if ($_qP): ?><span class="wl-check">✓</span>
              <?php elseif (!empty($_qv['block_next'])): ?><span class="wl-lock" title="Aprovação obrigatória para prosseguir">🔒</span><?php endif; ?>
            </a>
          </li>
          <?php endforeach; ?>
        <?php endif; ?>
        <?php endforeach; ?>
      <?php endif; ?>
      <?php foreach ($quizEndOfCourse as $_qv): ?>
        <?php $_qA = $quizBestAttempts[$_qv['id']] ?? null; $_qP = $_qA && (int)$_qA['passed']; ?>
        <li class="watch-lesson-item watch-quiz-item watch-quiz-eoc <?= $_qP ? 'done' : '' ?>">
          <a href="<?= APP_URL ?>/quiz.php?quiz_id=<?= $_qv['id'] ?>&amp;course_slug=<?= urlencode($course['slug']) ?>">
            <span class="wl-index wl-quiz-icon">📝</span>
            <span class="wl-title"><?= htmlspecialchars($_qv['title']) ?></span>
            <?php if ($_qP): ?><span class="wl-check">✓</span><?php endif; ?>
          </a>
        </li>
      <?php endforeach; ?>
    </ol>
  </aside>

  <!-- Player principal -->
  <div class="watch-main">
    <?php if ($hasVideo): ?>
    <div class="video-container" id="videoContainer">
      <iframe id="lessonIframe"
              src="<?= htmlspecialchars($embedUrl) ?>"
              width="100%" height="100%"
              frameborder="0"
              allow="autoplay"
              allowfullscreen
              sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"
      ></iframe>
      <!-- Cobre o botão de link externo do Google Drive (canto superior direito) -->
      <div class="gdrive-link-blocker"></div>
      <!-- Cobre toda a barra de controles inferior do Drive (progresso + play + volume) -->
      <div id="seekBlocker" class="gdrive-controls-blocker"></div>
    </div>
    <?php endif; ?>
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
        <?php $needsWatch = !$isDone && $requireWatch && $lesson['duration_seconds']; ?>
        <button id="markBtn" class="btn btn-complete <?= $isDone ? 'btn-done' : '' ?> <?= $needsWatch ? 'btn-locked' : '' ?>"
                <?= $needsWatch ? 'disabled' : '' ?>
                data-lesson="<?= $lessonId ?>" onclick="markComplete(this)">
          <?= $isDone ? '✅ Concluída' : ($needsWatch ? '⏳ Assista 75% para concluir' : '✓ Marcar como concluída') ?>
        </button>

        <?php if ($nextLesson): ?>
        <a href="watch.php?lesson=<?= $nextLesson['id'] ?>" class="btn btn-nav-next">Próxima →</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if (!empty($lesson['body_text'])): ?>
    <div class="lesson-body">
      <?= $lesson['body_text'] /* HTML armazenado pelo admin — sanitizado no input */ ?>
    </div>
    <?php endif; ?>

    <?php if ($attachments): ?>
    <div class="lesson-attachments">
      <h3 class="lesson-attachments-title">📎 Anexos</h3>
      <?php foreach ($attachments as $att):
            $attMime  = $att['mime_type'] ?? '';
            $isLocal  = !empty($att['file_path']);
            $isVideo  = str_starts_with($attMime, 'video/');
            $isAudio  = str_starts_with($attMime, 'audio/');
            $isPdf    = $attMime === 'application/pdf';
            $isImage  = str_starts_with($attMime, 'image/');
            if ($isLocal) {
                $attDownload = APP_URL . '/download.php?attachment=' . $att['id'];
                $attEmbed    = $attDownload;
            } else {
                $attDownload = GoogleDrive::getDirectUrl($att['gdrive_file_id'] ?? '');
                $attEmbed    = GoogleDrive::getEmbedUrl($att['gdrive_file_id'] ?? '');
            }
      ?>
      <div class="attachment-item">
        <div class="attachment-header">
          <span class="attachment-icon">
            <?php if ($isVideo): ?>🎬
            <?php elseif ($isAudio): ?>🎵
            <?php elseif ($isPdf): ?>📄
            <?php elseif ($isImage): ?>🖼️
            <?php else: ?>📎<?php endif; ?>
          </span>
          <span class="attachment-name"><?= htmlspecialchars($att['title']) ?></span>
          <a href="<?= htmlspecialchars($attDownload) ?>" target="_blank"
             class="btn btn-sm btn-secondary attachment-download">⬇ Baixar</a>
        </div>
        <?php if ($isLocal && $isVideo): ?>
        <div class="attachment-embed">
          <video controls preload="metadata" style="width:100%;height:100%;background:#000;display:block">
            <source src="<?= htmlspecialchars($attEmbed) ?>" type="<?= htmlspecialchars($attMime) ?>">
          </video>
        </div>
        <?php elseif ($isLocal && $isAudio): ?>
        <div class="attachment-embed-audio">
          <audio controls preload="metadata" style="width:100%">
            <source src="<?= htmlspecialchars($attEmbed) ?>" type="<?= htmlspecialchars($attMime) ?>">
          </audio>
        </div>
        <?php elseif ($isLocal && $isPdf): ?>
        <div class="attachment-embed attachment-embed--pdf">
          <iframe src="<?= htmlspecialchars($attEmbed) ?>" width="100%" height="100%" frameborder="0"></iframe>
        </div>
        <?php elseif (!$isLocal && ($isVideo || $isAudio)): ?>
        <div class="attachment-embed">
          <iframe src="<?= htmlspecialchars($attEmbed) ?>"
                  width="100%" height="100%"
                  frameborder="0" allow="autoplay" allowfullscreen
                  sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"
          ></iframe>
        </div>
        <?php elseif (!$isLocal && $isPdf): ?>
        <div class="attachment-embed attachment-embed--pdf">
          <iframe src="<?= htmlspecialchars($attEmbed) ?>"
                  width="100%" height="100%"
                  frameborder="0"
                  sandbox="allow-scripts allow-same-origin allow-popups allow-forms allow-presentation"
          ></iframe>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

  </div>
</div>

<script>
/* ── Configurações vindas do servidor ────────────────── */
const LESSON_ID       = <?= $lessonId ?>;
const LESSON_DURATION = <?= (int)($lesson['duration_seconds'] ?? 0) ?>;  // segundos
const PREVENT_SEEK    = <?= $preventSeek  ? 'true' : 'false' ?>;
const REQUIRE_WATCH   = <?= $requireWatch ? 'true' : 'false' ?>;
const ALREADY_DONE    = <?= in_array($lessonId, $progress) ? 'true' : 'false' ?>;
const MIN_WATCH_SECS  = (REQUIRE_WATCH && LESSON_DURATION > 0) ? Math.floor(LESSON_DURATION * 0.75) : 0;
const COURSE_SLUG     = '<?= addslashes($course['slug']) ?>';
const APP_BASE        = '<?= APP_URL ?>';

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
    if (markedComplete) return;

    // Desbloqueia o botão manual ao atingir 75%
    if (MIN_WATCH_SECS > 0 && markBtn && markBtn.disabled && activeSeconds >= MIN_WATCH_SECS) {
        markBtn.disabled = false;
        markBtn.classList.remove('btn-locked');
        markBtn.textContent = '\u2713 Marcar como conclu\u00edda';
    }

    // Auto-conclui ao atingir 75% (apenas quando seek está bloqueado)
    if (!PREVENT_SEEK) return;
    if (!MIN_WATCH_SECS) return;
    if (activeSeconds >= MIN_WATCH_SECS) {
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
    form.append('watched_seconds', activeSeconds);

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
                // Curso 100% completo → exibe banner do certificado ou do questionário
                if (data.quiz_url) {
                    showQuizBanner(data.quiz_url);
                } else if (data.course_complete && data.cert_url) {
                    showCertBanner(data.cert_url);
                }            }
        });
}
/* ── Banner de questionário pendente ────────────────── */
function showQuizBanner(quizUrl) {
    const banner = document.createElement('div');
    banner.id = 'quizBanner';
    banner.innerHTML = `
        <div style="
            position:fixed;inset:0;background:rgba(0,0,0,.65);
            display:flex;align-items:center;justify-content:center;
            z-index:9999;font-family:system-ui,sans-serif;
        ">
            <div style="
                background:#0f172a;border:2px solid #3b82f6;
                border-radius:.75rem;padding:2rem 2.5rem;
                text-align:center;max-width:420px;width:90%;
                box-shadow:0 20px 60px rgba(0,0,0,.5);
            ">
                <div style="font-size:3rem;margin-bottom:.5rem">📝</div>
                <h2 style="color:#f1f5f9;font-size:1.4rem;margin-bottom:.5rem">Questionário disponível!</h2>
                <p style="color:#94a3b8;margin-bottom:1.5rem;font-size:.95rem">
                    Há um questionário para responder antes de prosseguir.
                    Você precisa ser aprovado para obter o certificado.
                </p>
                <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
                    <a href="${quizUrl}" style="
                        background:#3b82f6;color:#fff;
                        padding:.55rem 1.4rem;border-radius:.35rem;
                        font-weight:700;text-decoration:none;font-size:.95rem;
                    ">📝 Responder questionário</a>
                    <button onclick="document.getElementById('quizBanner').remove()" style="
                        background:#334155;color:#f1f5f9;
                        padding:.55rem 1.1rem;border-radius:.35rem;
                        border:none;cursor:pointer;font-size:.95rem;
                    ">Depois</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(banner);
}

/* ── Banner de conclusão do curso ──────────────────── */
function showCertBanner(certUrl) {
    const banner = document.createElement('div');
    banner.id = 'certBanner';
    banner.innerHTML = `
        <div style="
            position:fixed;inset:0;background:rgba(0,0,0,.65);
            display:flex;align-items:center;justify-content:center;
            z-index:9999;font-family:system-ui,sans-serif;
        ">
            <div style="
                background:#0f172a;border:2px solid #c9a84c;
                border-radius:.75rem;padding:2rem 2.5rem;
                text-align:center;max-width:420px;width:90%;
                box-shadow:0 20px 60px rgba(0,0,0,.5);
            ">
                <div style="font-size:3rem;margin-bottom:.5rem">🎉</div>
                <h2 style="color:#f1f5f9;font-size:1.4rem;margin-bottom:.5rem">Curso conclu\u00eddo!</h2>
                <p style="color:#94a3b8;margin-bottom:1.5rem;font-size:.95rem">
                    Parab\u00e9ns! Voc\u00ea concluiu todas as aulas e j\u00e1 pode obter seu certificado.
                </p>
                <div style="display:flex;gap:.75rem;justify-content:center;flex-wrap:wrap">
                    <a href="${certUrl}" style="
                        background:#c9a84c;color:#1a0a00;
                        padding:.55rem 1.4rem;border-radius:.35rem;
                        font-weight:700;text-decoration:none;font-size:.95rem;
                    ">📜 Ver Certificado</a>
                    <button onclick="document.getElementById('certBanner').remove()" style="
                        background:#334155;color:#f1f5f9;
                        padding:.55rem 1.1rem;border-radius:.35rem;
                        border:none;cursor:pointer;font-size:.95rem;
                    ">Fechar</button>
                </div>
            </div>
        </div>`;
    document.body.appendChild(banner);
}
</script>

<?php siteFooter(); ?>
