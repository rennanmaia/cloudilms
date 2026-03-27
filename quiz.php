<?php
/**
 * CloudiLMS - Página de Questionário (Aluno)
 *
 * GET  ?quiz_id=X[&course_slug=Y]  → Exibe o questionário para responder
 * POST submit_answers=1            → Submete respostas, exibe resultado
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/course.php';
require_once __DIR__ . '/includes/quiz.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/layout.php';

$auth      = new Auth();
$auth->requireLogin();

$quizModel   = new QuizModel();
$courseModel = new CourseModel();

$userId     = (int)$_SESSION['user_id'];
$quizId     = (int)($_GET['quiz_id'] ?? ($_POST['quiz_id'] ?? 0));
$courseSlug = trim($_GET['course_slug'] ?? ($_POST['course_slug'] ?? ''));

if (!$quizId) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

$quiz = $quizModel->getQuizById($quizId);
if (!$quiz) {
    http_response_code(404);
    siteHeader('Questionário não encontrado');
    echo '<div class="empty-state"><h2>Questionário não encontrado.</h2></div>';
    siteFooter();
    exit;
}

$courseId = (int)$quiz['course_id'];
$course   = $courseModel->getCourseById($courseId);
if (!$course || !$courseModel->isEnrolled($userId, $courseId)) {
    header('Location: ' . APP_URL . '/index.php');
    exit;
}

if (!$courseSlug) $courseSlug = $course['slug'];

// URL para voltar ao curso
$courseUrl = APP_URL . '/course.php?slug=' . urlencode($courseSlug);

// ── Verificar se já passou ────────────────────────────────────────────────────
$bestAttempt = $quizModel->getBestAttempt($quizId, $userId);
$alreadyPassed = $bestAttempt && (int)$bestAttempt['passed'];

// ── POST: submeter respostas ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_answers'])) {
    $attemptId = (int)($_POST['attempt_id'] ?? 0);
    if (!$attemptId) {
        header('Location: quiz.php?quiz_id=' . $quizId . '&course_slug=' . urlencode($courseSlug) . '&err=invalid');
        exit;
    }

    // Collect answers: question_id => option_id
    $answers = [];
    foreach ($_POST as $key => $val) {
        if (str_starts_with($key, 'q_')) {
            $qId = (int)substr($key, 2);
            $answers[$qId] = (int)$val;
        }
    }

    $result = $quizModel->submitAttempt($attemptId, $userId, $answers);

    if (isset($result['error'])) {
        header('Location: quiz.php?quiz_id=' . $quizId . '&course_slug=' . urlencode($courseSlug) . '&err=' . $result['error']);
        exit;
    }

    $passed = $result['passed'];
    $action = $passed ? 'quiz_passed' : 'quiz_failed';
    ActivityLog::record($action, [
        'entity_type'  => 'quiz',
        'entity_id'    => $quizId,
        'entity_title' => $quiz['title'],
        'meta'         => [
            'score'      => $result['score'],
            'min_score'  => $result['min_score'],
            'course_id'  => $courseId,
        ],
    ]);

    // Mostra URL do certificado se agora passou em tudo
    $allPassed = $quizModel->allQuizzesPassed($userId, $courseId);
    $lessons   = $courseModel->getLessonsByCourse($courseId);
    $progress  = $courseModel->getProgress($userId, $courseId);
    $certUrl   = '';
    if ($allPassed && count($lessons) > 0 && count($progress) >= count($lessons)) {
        $certUrl = APP_URL . '/certificate.php?course=' . urlencode($courseSlug);
    }

    // Mostra resultado
    showResult($quiz, $result, $courseUrl, $certUrl, $courseSlug);
    exit;
}

// ── GET: exibir questionário ──────────────────────────────────────────────────
if (empty($quiz['questions'])) {
    siteHeader($quiz['title'] . ' — ' . $course['title']);
    echo '<div class="quiz-page"><div class="quiz-card"><p class="quiz-empty">Este questionário ainda não tem questões.</p>';
    echo '<a href="' . htmlspecialchars($courseUrl) . '" class="btn btn-primary">← Voltar ao curso</a></div></div>';
    siteFooter();
    exit;
}

// Inicia nova tentativa
$attemptId = $quizModel->startAttempt($quizId, $userId, $courseId);

$previousAttempts = $quizModel->getAttemptsByUser($quizId, $userId);
$attemptsCount    = count($previousAttempts);

siteHeader($quiz['title'] . ' — ' . $course['title']);
?>

<div class="quiz-page">
  <!-- Header da página -->
  <div class="quiz-header">
    <a href="<?= htmlspecialchars($courseUrl) ?>">← <?= htmlspecialchars($course['title']) ?></a>
    <h1><?= htmlspecialchars($quiz['title']) ?></h1>
    <?php if ($quiz['description']): ?>
    <p class="quiz-header-desc"><?= nl2br(htmlspecialchars($quiz['description'])) ?></p>
    <?php endif; ?>
    <div class="quiz-meta">
      <span>❓ <?= count($quiz['questions']) ?> questão(ões)</span>
      <span>🎯 Nota mínima: <strong><?= number_format((float)$quiz['min_score'], 0) ?>%</strong></span>
      <?php if ($attemptsCount > 0): ?>
      <span>🔁 Tentativa nº <?= $attemptsCount + 1 ?></span>
      <?php endif; ?>
    </div>
    <?php if ($alreadyPassed): ?>
    <div class="quiz-already-passed">
      ✅ Você já foi aprovado neste questionário! Pode respondê-lo novamente se quiser.
    </div>
    <?php endif; ?>
  </div>

  <!-- Barra de progresso -->
  <div class="quiz-progress-wrap">
    <div class="quiz-progress-bar">
      <div class="quiz-progress-fill" id="quizProgress" style="width:0%"></div>
    </div>
    <span class="quiz-progress-text" id="quizProgressText">0 / <?= count($quiz['questions']) ?> respondida(s)</span>
  </div>

  <!-- Formulário de respostas -->
  <form method="post" action="quiz.php" class="quiz-form" id="quizForm">
    <input type="hidden" name="quiz_id" value="<?= $quizId ?>">
    <input type="hidden" name="course_slug" value="<?= htmlspecialchars($courseSlug) ?>">
    <input type="hidden" name="attempt_id" value="<?= $attemptId ?>">
    <input type="hidden" name="submit_answers" value="1">

    <?php foreach ($quiz['questions'] as $qi => $q): ?>
    <div class="quiz-question-block" id="q-block-<?= $q['id'] ?>">
      <p class="quiz-question-text">
        <span class="quiz-q-num"><?= $qi + 1 ?></span>
        <?= nl2br(htmlspecialchars($q['question_text'])) ?>
      </p>
      <div class="quiz-options-group" role="radiogroup">
        <?php foreach ($q['options'] as $oi => $opt): ?>
        <label class="quiz-option-label">
          <input type="radio" name="q_<?= $q['id'] ?>" value="<?= $opt['id'] ?>" required>
          <span class="quiz-option-text"><?= htmlspecialchars($opt['option_text']) ?></span>
        </label>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <div class="quiz-submit-wrap">
      <a href="<?= htmlspecialchars($courseUrl) ?>" class="btn btn-secondary">← Voltar ao curso</a>
      <button type="submit" class="btn btn-primary btn-quiz-submit" id="submitBtn">
        ✅ Enviar respostas
      </button>
    </div>
  </form>
</div>

<script>
const TOTAL_QUESTIONS = <?= count($quiz['questions']) ?>;

function updateProgress() {
    const answered = document.querySelectorAll('input[type=radio]:checked').length;
    const pct = TOTAL_QUESTIONS > 0 ? Math.round(answered / TOTAL_QUESTIONS * 100) : 0;
    document.getElementById('quizProgress').style.width = pct + '%';
    document.getElementById('quizProgressText').textContent = answered + ' / ' + TOTAL_QUESTIONS + ' respondida(s)';
}

// Confirmação antes de enviar
document.getElementById('quizForm').addEventListener('submit', function(e) {
    const answered = document.querySelectorAll('input[type=radio]:checked').length;
    if (answered < TOTAL_QUESTIONS) {
        const missing = TOTAL_QUESTIONS - answered;
        if (!confirm('Você ainda não respondeu ' + missing + ' questão(ões). Enviar mesmo assim?')) {
            e.preventDefault();
        }
    }
});

// Destaca opção selecionada e atualiza progresso
document.querySelectorAll('.quiz-option-label input[type=radio]').forEach(radio => {
    radio.addEventListener('change', function() {
        const group = this.closest('.quiz-options-group');
        group.querySelectorAll('.quiz-option-label').forEach(l => l.classList.remove('selected'));
        this.closest('.quiz-option-label').classList.add('selected');
        updateProgress();
    });
});
</script>

<?php
siteFooter();
exit;

// ── Renderiza a tela de resultado ─────────────────────────────────────────────
function showResult(array $quiz, array $result, string $courseUrl, string $certUrl, string $courseSlug): void {
    $passed   = $result['passed'];
    $score    = $result['score'];
    $minScore = $result['min_score'];

    siteHeader($quiz['title'] . ' — Resultado');
    ?>
    <div class="quiz-page">
      <div class="quiz-result-card <?= $passed ? 'passed' : 'failed' ?>">
        <div class="quiz-result-icon"><?= $passed ? '✅' : '❌' ?></div>
        <h2 class="quiz-result-title">
          <?= $passed ? 'Aprovado!' : 'Reprovado' ?>
        </h2>
        <div class="quiz-result-score">
          <span class="quiz-score-value"><?= number_format($score, 1) ?>%</span>
          <span class="quiz-score-label">sua nota</span>
        </div>
        <p class="quiz-result-info">
          <?php if ($passed): ?>
            Parabéns! Você atingiu <?= number_format($score, 1) ?>% e superou a nota mínima de <?= number_format($minScore, 0) ?>%.
          <?php else: ?>
            Você atingiu <?= number_format($score, 1) ?>%, mas a nota mínima é <?= number_format($minScore, 0) ?>%.
            Você pode tentar novamente.
          <?php endif; ?>
        </p>
        <div class="quiz-result-actions">
          <?php if ($passed && $certUrl): ?>
          <a href="<?= htmlspecialchars($certUrl) ?>" class="btn btn-primary btn-lg">
            📜 Emitir Certificado
          </a>
          <?php endif; ?>
          <a href="<?= htmlspecialchars($courseUrl) ?>" class="btn <?= $passed ? 'btn-secondary' : 'btn-primary' ?>">
            <?= $passed ? '← Voltar ao curso' : '← Voltar ao curso' ?>
          </a>
          <?php if (!$passed): ?>
          <a href="quiz.php?quiz_id=<?= (int)$quiz['id'] ?>&amp;course_slug=<?= urlencode($courseSlug) ?>"
             class="btn btn-secondary">
            🔁 Tentar novamente
          </a>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php
    siteFooter();
}
