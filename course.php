<?php
/**
 * CloudiLMS - Página do curso (lista de aulas)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/trail.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/certificate.php';
require_once __DIR__ . '/includes/quiz.php';
require_once __DIR__ . '/includes/layout.php';

$auth  = new Auth();
$model = new CourseModel();
$trailModel = new TrailModel();
$quizModel  = new QuizModel();

$slug = trim($_GET['slug'] ?? '');
if (!$slug) { header('Location: ' . APP_URL . '/index.php'); exit; }

$course = $model->getCourseBySlug($slug);
if (!$course) { http_response_code(404); siteHeader('Curso não encontrado'); echo '<div class="empty-state"><h2>Curso não encontrado</h2></div>'; siteFooter(); exit; }

// CSRF
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// Cancelar matrícula (aluno)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'cancel_enrollment') {
    if (($_POST['csrf'] ?? '') !== $csrf) { http_response_code(403); exit('Forbidden'); }
    $logged = $auth->isLoggedIn();
    $uid    = $logged ? (int)$_SESSION['user_id'] : 0;
    if ($uid && $model->isEnrolled($uid, $course['id'])) {
        $model->cancelEnrollment($uid, $course['id']);
        ActivityLog::record('course_unenroll', [
            'entity_type'  => 'course',
            'entity_id'    => $course['id'],
            'entity_title' => $course['title'],
        ]);
    }
    header('Location: course.php?slug=' . urlencode($slug) . '&notice=unenrolled');
    exit;
}

$course = $model->getCourseBySlug($slug);
if (!$course) { http_response_code(404); siteHeader('Curso não encontrado'); echo '<div class="empty-state"><h2>Curso não encontrado</h2></div>'; siteFooter(); exit; }

$lessons  = $model->getLessonsByCourse($course['id']);
$grouped  = $model->getLessonsGroupedByTopic($course['id']);
$hasTopics = count($grouped) > 1 || ($grouped[0]['topic'] !== null);
$logged   = $auth->isLoggedIn();
$userId   = $logged ? (int)$_SESSION['user_id'] : 0;
$enrolled = $logged && $model->isEnrolled($userId, $course['id']);
$progress = $enrolled ? $model->getProgress($userId, $course['id']) : [];
$enrollBlocked = $logged && !$enrolled && !$trailModel->canEnrollInCourse($userId, $course['id']);

// Matrícula automática ao clicar em "começar"
if ($logged && isset($_GET['enroll'])) {
    if ($enrollBlocked) {
        header('Location: course.php?slug=' . urlencode($slug) . '&notice=trail_locked');
        exit;
    }
    $model->enroll($userId, $course['id']);
    ActivityLog::record('course_enroll', [
        'entity_type'  => 'course',
        'entity_id'    => $course['id'],
        'entity_title' => $course['title'],
    ]);
    header('Location: course.php?slug=' . urlencode($slug));
    exit;
}

// Log de visita ao curso
if ($logged) {
    ActivityLog::record('course_view', [
        'entity_type'  => 'course',
        'entity_id'    => $course['id'],
        'entity_title' => $course['title'],
    ]);
}

// Carga horária do curso (vídeos + questionários + extra)
$_videoMins   = (int) ceil(array_sum(array_column($lessons, 'duration_seconds')) / 60);
$_allQuizzes  = $quizModel->getQuizzesByCourse($course['id']);
$_quizMins    = (int) array_sum(array_column($_allQuizzes, 'workload_minutes'));
$_extraMins   = (int) ($course['extra_hours_minutes'] ?? 0);
$workloadMins = $_videoMins + $_quizMins + $_extraMins;

siteHeader($course['title']);
?>

<?php if (($_GET['notice'] ?? '') === 'sequential'): ?>
<div class="alert alert-danger" style="margin:1rem auto;max-width:860px">
  🔒 Você precisa concluir as aulas anteriores antes de acessar esta aula.
</div>
<?php endif; ?>
<?php if (($_GET['notice'] ?? '') === 'trail_locked'): ?>
<div class="alert alert-danger" style="margin:1rem auto;max-width:860px">
  🔒 Este curso pertence a uma trilha que <strong>não está liberada</strong> para você. Solicite ao administrador que libere seu acesso.
</div>
<?php endif; ?>
<?php if (($_GET['notice'] ?? '') === 'unenrolled'): ?>
<div class="alert-front alert-front-success" style="margin:1rem auto;max-width:860px">
  ✅ Matrícula cancelada. Seu histórico neste curso foi removido.
</div>
<?php endif; ?>
<?php if (($_GET['notice'] ?? '') === 'cert_quiz_pending'): ?>
<div class="alert alert-danger" style="margin:1rem auto;max-width:860px">
  📝 Você precisa ser aprovado em todos os <strong>questionários do curso</strong> para emitir o certificado.
</div>
<?php endif; ?>

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
        <?php if ($workloadMins > 0): ?>
        <span>⏱ <?= CertificateModel::formatWorkload($workloadMins) ?></span>
        <?php endif; ?>
        <?php if ($enrolled): ?>
          <?php $pct = $lessons ? round(count($progress) / count($lessons) * 100) : 0; ?>
          <span>✅ <?= $pct ?>% concluído</span>
        <?php endif; ?>
      </div>
      <?php if (!$logged): ?>
        <a href="login.php?redirect=<?= urlencode('course.php?slug=' . $slug . '&enroll=1') ?>" class="btn-hero">🔐 Entrar para assistir</a>
      <?php elseif (!$enrolled && $enrollBlocked): ?>
        <div class="trail-blocked-notice">
          🔒 Este curso pertence a uma trilha que não está liberada para você.<br>
          <a href="<?= APP_URL ?>/trails.php" style="color:var(--primary);font-size:.9rem">Ver minhas trilhas</a>
        </div>
      <?php elseif (!$enrolled): ?>
        <a href="course.php?slug=<?= urlencode($slug) ?>&enroll=1" class="btn-hero">🎓 Matricular-se gratuitamente</a>
      <?php else: ?>
        <?php if ($lessons):
          $courseComplete   = count($progress) >= count($lessons);
          $firstIncomplete  = null;
          foreach ($lessons as $l) {
              if (!in_array($l['id'], $progress)) { $firstIncomplete = $l; break; }
          }
          $resumeId        = $firstIncomplete ? $firstIncomplete['id'] : $lessons[0]['id'];
          $certUrl         = APP_URL . '/certificate.php?course=' . urlencode($slug);
          $pendingQuizzes  = $courseComplete ? $quizModel->getPendingQuizzesByCourse($course['id'], $userId) : [];
          $allQuizPassed   = empty($pendingQuizzes);
        ?>
          <?php if ($courseComplete && $allQuizPassed): ?>
          <a href="<?= $certUrl ?>" class="btn-hero btn-cert">📜 Ver Certificado</a>
          <a href="watch.php?lesson=<?= $resumeId ?>" class="btn-hero btn-hero-alt" style="margin-top:.5rem">▶ Rever aulas</a>
          <?php elseif ($courseComplete && !$allQuizPassed): ?>
          <!-- Questionários pendentes -->
          <div class="quiz-pending-block">
            <p class="quiz-pending-title">📝 Questionários pendentes</p>
            <p class="quiz-pending-desc">Você precisa ser aprovado nos questionários abaixo para obter o certificado:</p>
            <div class="quiz-pending-list">
              <?php foreach ($pendingQuizzes as $pq): ?>
              <a href="<?= APP_URL ?>/quiz.php?quiz_id=<?= $pq['id'] ?>&amp;course_slug=<?= urlencode($slug) ?>"
                 class="quiz-pending-btn">
                ▶ <?= htmlspecialchars($pq['title']) ?>
                <small>(mín. <?= number_format((float)$pq['min_score'], 0) ?>%)</small>
              </a>
              <?php endforeach; ?>
            </div>
          </div>
          <a href="watch.php?lesson=<?= $resumeId ?>" class="btn-hero btn-hero-alt" style="margin-top:.5rem">▶ Rever aulas</a>
          <?php else: ?>
          <a href="watch.php?lesson=<?= $resumeId ?>" class="btn-hero">▶ Continuar assistindo</a>
          <?php endif; ?>
          <form method="post" action="course.php?slug=<?= urlencode($slug) ?>" style="margin-top:.85rem"
                onsubmit="return confirm('Cancelar sua matrícula neste curso?\n\nSeu progresso e certificado (se houver) serão apagados.')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="_action" value="cancel_enrollment">
            <button type="submit" class="btn-unenroll-course">❌ Cancelar matrícula</button>
          </form>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>

  <!-- Lista de aulas -->
  <div class="lesson-list-public">
    <h2>Conteúdo do curso</h2>

    <?php if ($hasTopics): ?>
    <!-- Vista agrupada por tópico -->
    <?php $globalIndex = 0; ?>
    <?php foreach ($grouped as $group): ?>
    <?php if ($group['topic']): ?>
    <div class="topic-section">
      <div class="topic-section-header">
        <div class="topic-section-title">
          <span class="topic-section-icon">📁</span>
          <?= htmlspecialchars($group['topic']['title']) ?>
        </div>
        <span class="topic-section-count"><?= count($group['lessons']) ?> aula(s)</span>
      </div>
    <?php else: ?>
    <div class="topic-section topic-section--flat">
    <?php endif; ?>
      <ol class="public-lessons">
        <?php foreach ($group['lessons'] as $l): ?>
        <?php $globalIndex++; $done = in_array($l['id'], $progress); ?>
        <li class="public-lesson-item <?= $done ? 'lesson-done' : '' ?>">
          <?php if ($enrolled): ?>
          <a href="watch.php?lesson=<?= $l['id'] ?>" class="lesson-link">
          <?php else: ?>
          <span class="lesson-link lesson-locked">
          <?php endif; ?>
            <span class="lesson-index"><?= $globalIndex ?></span>
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
    </div><!-- .topic-section -->
    <?php endforeach; ?>

    <?php else: ?>
    <!-- Vista plana (sem tópicos) -->
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
    <?php endif; ?>
  </div>
</div>

<?php siteFooter(); ?>
