<?php
/**
 * CloudiLMS - Gerenciamento de Questionários (Admin)
 * Ações: list | edit | save | delete | questions | save_question | delete_question
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/course.php';
require_once __DIR__ . '/../includes/quiz.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/layout.php';

$auth       = new Auth();
$auth->requireAdmin();

$quizModel   = new QuizModel();
$courseModel = new CourseModel();

$action   = $_GET['action'] ?? 'list';
$id       = (int)($_GET['id']        ?? 0);  // quiz ID
$courseId = (int)($_GET['course_id'] ?? 0);  // course ID (usado em list/edit)
$message  = '';
$error    = '';

// ── POST handlers ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Token de segurança inválido.';
    } else {
        switch ($action) {

            case 'save':
                $data = [
                    'course_id'         => $courseId ?: (int)($_POST['course_id'] ?? 0),
                    'title'             => trim($_POST['title'] ?? ''),
                    'description'       => trim($_POST['description'] ?? ''),
                    'placement_type'    => $_POST['placement_type'] ?? 'end_of_course',
                    'placement_id'      => null, // resolvido abaixo conforme placement_type
                    'scoring_method'    => $_POST['scoring_method'] ?? 'arithmetic',
                    'min_score'         => min(100, max(0, (float)($_POST['min_score'] ?? 60))),
                    'workload_minutes'  => max(0, (int)($_POST['workload_minutes'] ?? 0)),
                ];
                // Resolve placement_id usando o campo correto conforme o tipo selecionado
                if ($data['placement_type'] === 'after_lesson') {
                    $data['placement_id'] = (int)($_POST['placement_id_lesson'] ?? 0) ?: null;
                } elseif ($data['placement_type'] === 'after_topic') {
                    $data['placement_id'] = (int)($_POST['placement_id_topic'] ?? 0) ?: null;
                }
                $courseId = (int)$data['course_id'];
                if (!$data['title'])    { $error = 'Título é obrigatório.'; $action = 'edit'; break; }
                if (!$courseId)         { $error = 'Curso inválido.';       $action = 'edit'; break; }
                if (!$courseModel->getCourseById($courseId)) { $error = 'Curso não encontrado.'; $action = 'edit'; break; }

                if ($id) {
                    // Edit: garantir que o quiz pertence ao curso
                    $existing = $quizModel->getQuizById($id);
                    if (!$existing || (int)$existing['course_id'] !== $courseId) {
                        $error = 'Questionário não encontrado.'; $action = 'edit'; break;
                    }
                    $quizModel->updateQuiz($id, $data);
                    $message = 'Questionário atualizado.';
                } else {
                    $id = $quizModel->createQuiz($data);
                    $message = 'Questionário criado. Agora adicione as questões. ⬇️';
                    header('Location: quizzes.php?action=questions&id=' . $id . '&msg=' . urlencode($message));
                    exit;
                }
                header('Location: quizzes.php?course_id=' . $courseId . '&msg=' . urlencode($message));
                exit;

            case 'delete':
                $quizId = (int)($_POST['quiz_id'] ?? $id);
                $quiz   = $quizModel->getQuizById($quizId);
                if ($quiz) {
                    $cid = (int)$quiz['course_id'];
                    $quizModel->deleteQuiz($quizId);
                    header('Location: quizzes.php?course_id=' . $cid . '&msg=' . urlencode('Questionário excluído.'));
                    exit;
                }
                header('Location: quizzes.php');
                exit;

            case 'save_question':
                $quizId      = (int)($_POST['quiz_id'] ?? 0);
                $questionId  = (int)($_POST['question_id'] ?? 0);
                $quiz        = $quizModel->getQuizById($quizId);
                if (!$quiz) { $error = 'Questionário não encontrado.'; $action = 'questions'; break; }
                $courseId    = (int)$quiz['course_id'];

                $qText   = trim($_POST['question_text'] ?? '');
                $weight  = max(0.01, (float)($_POST['weight'] ?? 1.0));
                if (!$qText) { $error = 'Texto da questão é obrigatório.'; $action = 'questions'; $id = $quizId; break; }

                // Coleta opções
                $optTexts   = $_POST['option_text']   ?? [];
                $correctIdx = (int)($_POST['correct_option'] ?? -1);
                $options    = [];
                foreach ($optTexts as $i => $oText) {
                    $oText = trim($oText);
                    if ($oText === '') continue;
                    $options[] = [
                        'text'       => $oText,
                        'is_correct' => ($i === $correctIdx),
                    ];
                }
                if (count($options) < 2) {
                    $error = 'Informe pelo menos 2 opções de resposta.'; $action = 'questions'; $id = $quizId; break;
                }
                $hasCorrect = array_filter($options, fn($o) => $o['is_correct']);
                if (empty($hasCorrect)) {
                    $error = 'Marque a opção correta.'; $action = 'questions'; $id = $quizId; break;
                }

                if ($questionId) {
                    if (!$quizModel->questionBelongsToQuiz($questionId, $quizId)) {
                        $error = 'Questão inválida.'; $action = 'questions'; $id = $quizId; break;
                    }
                    $quizModel->updateQuestion($questionId, $qText, $weight);
                    $quizModel->saveOptions($questionId, $options);
                    $msg = 'Questão atualizada.';
                } else {
                    $newQId = $quizModel->createQuestion($quizId, $qText, $weight);
                    $quizModel->saveOptions($newQId, $options);
                    $msg = 'Questão adicionada.';
                }
                header('Location: quizzes.php?action=questions&id=' . $quizId . '&msg=' . urlencode($msg));
                exit;

            case 'delete_question':
                $quizId     = (int)($_POST['quiz_id']     ?? 0);
                $questionId = (int)($_POST['question_id'] ?? 0);
                if ($questionId && $quizModel->questionBelongsToQuiz($questionId, $quizId)) {
                    $quizModel->deleteQuestion($questionId);
                }
                header('Location: quizzes.php?action=questions&id=' . $quizId . '&msg=' . urlencode('Questão removida.'));
                exit;
        }
    }
}

// Mensagem via GET
if (!$message && !empty($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// Regenera CSRF
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// ══════════════════════════════════════════════════════════════════════════════
// LIST — quizzes.php?course_id=X
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'list' || (!$id && !$courseId)) {
    if (!$courseId) {
        // Sem course_id: redireciona para lista de cursos
        header('Location: courses.php');
        exit;
    }
    $course  = $courseModel->getCourseById($courseId);
    if (!$course) { header('Location: courses.php'); exit; }
    $quizzes = $quizModel->getQuizzesByCourse($courseId);

    adminHeader('Questionários: ' . $course['title'], 'courses');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <div>
          <h2>📝 Questionários</h2>
          <small style="color:#94a3b8">Curso: <strong><?= htmlspecialchars($course['title']) ?></strong></small>
        </div>
        <div style="display:flex;gap:.5rem">
          <a href="courses.php?action=lessons&id=<?= $courseId ?>" class="btn btn-sm btn-secondary">← Voltar às aulas</a>
          <a href="quizzes.php?action=edit&course_id=<?= $courseId ?>" class="btn btn-primary">+ Novo questionário</a>
        </div>
      </div>

      <?php if ($quizzes): ?>
      <table class="table">
        <thead>
          <tr>
            <th>Título</th>
            <th>Posição</th>
            <th>Método</th>
            <th>Nota mín.</th>
            <th>Questões</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($quizzes as $q): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($q['title']) ?></strong>
              <?php if ($q['description']): ?>
              <br><small style="color:#64748b"><?= htmlspecialchars(mb_strimwidth($q['description'], 0, 80, '…')) ?></small>
              <?php endif; ?>
            </td>
            <td>
              <span class="badge badge-info"><?= QuizModel::placementLabel($q['placement_type']) ?></span>
              <?php if ($q['placement_title']): ?>
              <br><small style="color:#64748b"><?= htmlspecialchars($q['placement_title']) ?></small>
              <?php endif; ?>
            </td>
            <td><?= $q['scoring_method'] === 'weighted' ? '⚖️ Ponderada' : '➗ Aritmética' ?></td>
            <td><strong><?= number_format((float)$q['min_score'], 0) ?>%</strong></td>
            <td>
              <?php
              $qCount = (int)$q['question_count'];
              $badge  = $qCount > 0 ? 'badge-success' : 'badge-danger';
              ?>
              <span class="badge <?= $badge ?>"><?= $qCount ?></span>
            </td>
            <td class="actions">
              <a href="quizzes.php?action=questions&id=<?= $q['id'] ?>" class="btn btn-sm btn-secondary">❓ Questões</a>
              <a href="quizzes.php?action=edit&id=<?= $q['id'] ?>&course_id=<?= $courseId ?>" class="btn btn-sm">✏️ Editar</a>
              <form method="post" action="quizzes.php?action=delete&id=<?= $q['id'] ?>" style="display:inline"
                    onsubmit="return confirm('Excluir este questionário e todas as suas questões?')">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <input type="hidden" name="quiz_id" value="<?= $q['id'] ?>">
                <button class="btn btn-sm btn-danger">🗑 Excluir</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div style="padding:2rem;text-align:center;color:#64748b">
        <p>Nenhum questionário criado para este curso.</p>
        <a href="quizzes.php?action=edit&course_id=<?= $courseId ?>" class="btn btn-primary" style="margin-top:.75rem">+ Criar primeiro questionário</a>
      </div>
      <?php endif; ?>
    </div>
    <?php
    adminFooter();
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// EDIT / NEW — quizzes.php?action=edit&[id=X|course_id=X]
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'edit') {
    $quiz     = $id ? $quizModel->getQuizById($id) : null;
    $cid      = $quiz ? (int)$quiz['course_id'] : $courseId;
    $course   = $cid ? $courseModel->getCourseById($cid) : null;
    if (!$course) { header('Location: courses.php'); exit; }

    $lessons = $courseModel->getLessonsByCourse($cid);
    $topics  = $courseModel->getTopicsByCourse($cid);
    $pageTitle = $quiz ? 'Editar Questionário' : 'Novo Questionário';

    // Valores do form (mantém se houver erro de POST)
    $fTitle    = $_POST['title']          ?? ($quiz['title']          ?? '');
    $fDesc     = $_POST['description']    ?? ($quiz['description']    ?? '');
    $fPlType     = $_POST['placement_type'] ?? ($quiz['placement_type'] ?? 'end_of_course');
    $fPlIdLesson = (int)($_POST['placement_id_lesson'] ?? ($fPlType === 'after_lesson' ? ($quiz['placement_id'] ?? 0) : 0));
    $fPlIdTopic  = (int)($_POST['placement_id_topic']  ?? ($fPlType === 'after_topic'  ? ($quiz['placement_id'] ?? 0) : 0));
    $fScoring    = $_POST['scoring_method'] ?? ($quiz['scoring_method'] ?? 'arithmetic');
    $fMinScore = $_POST['min_score']      ?? ($quiz['min_score']      ?? '60');
    $fWorkload = $_POST['workload_minutes'] ?? ($quiz['workload_minutes'] ?? '0');

    adminHeader($pageTitle, 'courses');
    ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header">
        <h2><?= $pageTitle ?></h2>
        <a href="quizzes.php?course_id=<?= $cid ?>" class="btn btn-sm btn-secondary">← Voltar</a>
      </div>
      <div class="card-body" style="padding:1.5rem">
        <form method="post" action="quizzes.php?action=save&id=<?= $id ?>&course_id=<?= $cid ?>">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="course_id" value="<?= $cid ?>">

          <div class="form-group">
            <label class="form-label">Título *</label>
            <input type="text" name="title" class="form-control" value="<?= htmlspecialchars($fTitle) ?>" required>
          </div>

          <div class="form-group">
            <label class="form-label">Descrição <small style="color:#94a3b8">(opcional)</small></label>
            <textarea name="description" class="form-control" rows="3"><?= htmlspecialchars($fDesc) ?></textarea>
          </div>

          <!-- Posição do questionário -->
          <div class="form-group">
            <label class="form-label">Posição no curso</label>
            <div class="quiz-placement-wrap">
              <label class="quiz-placement-opt <?= $fPlType === 'end_of_course' ? 'active' : '' ?>">
                <input type="radio" name="placement_type" value="end_of_course"
                       <?= $fPlType === 'end_of_course' ? 'checked' : '' ?>
                       onchange="updatePlacement(this)">
                🏁 <strong>Final do curso</strong><br>
                <small>Exibido quando todas as aulas forem concluídas</small>
              </label>
              <label class="quiz-placement-opt <?= $fPlType === 'after_lesson' ? 'active' : '' ?>">
                <input type="radio" name="placement_type" value="after_lesson"
                       <?= $fPlType === 'after_lesson' ? 'checked' : '' ?>
                       onchange="updatePlacement(this)">
                ▶ <strong>Após aula</strong><br>
                <small>Exibido quando o aluno conclui uma aula específica</small>
              </label>
              <label class="quiz-placement-opt <?= $fPlType === 'after_topic' ? 'active' : '' ?>">
                <input type="radio" name="placement_type" value="after_topic"
                       <?= $fPlType === 'after_topic' ? 'checked' : '' ?>
                       onchange="updatePlacement(this)">
                📁 <strong>Após tópico</strong><br>
                <small>Exibido quando o aluno conclui todas as aulas de um tópico</small>
              </label>
            </div>
          </div>

          <!-- Seletor de aula/tópico -->
          <div id="placement-lesson-wrap" class="form-group" <?= $fPlType !== 'after_lesson' ? 'style="display:none"' : '' ?>>
            <label class="form-label">Aula de referência</label>
            <select name="placement_id_lesson" class="form-control" id="placement_lesson_id">
              <option value="">— Selecione uma aula —</option>
              <?php foreach ($lessons as $l): ?>
              <option value="<?= $l['id'] ?>" <?= $fPlIdLesson === (int)$l['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($l['title']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div id="placement-topic-wrap" class="form-group" <?= $fPlType !== 'after_topic' ? 'style="display:none"' : '' ?>>
            <label class="form-label">Tópico de referência</label>
            <select name="placement_id_topic" class="form-control" id="placement_topic_id">
              <option value="">— Selecione um tópico —</option>
              <?php foreach ($topics as $t): ?>
              <option value="<?= $t['id'] ?>" <?= $fPlIdTopic === (int)$t['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['title']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <!-- Pontuação -->
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:1rem">
            <div class="form-group">
              <label class="form-label">Método de pontuação</label>
              <div style="display:flex;flex-direction:column;gap:.5rem;margin-top:.25rem">
                <label style="cursor:pointer">
                  <input type="radio" name="scoring_method" value="arithmetic"
                         <?= $fScoring === 'arithmetic' ? 'checked' : '' ?>>
                  ➗ Média aritmética
                </label>
                <label style="cursor:pointer">
                  <input type="radio" name="scoring_method" value="weighted"
                         <?= $fScoring === 'weighted' ? 'checked' : '' ?>>
                  ⚖️ Média ponderada
                </label>
              </div>
            </div>
            <div class="form-group">
              <label class="form-label">Nota mínima para aprovação (%)</label>
              <input type="number" name="min_score" class="form-control" min="0" max="100" step="0.01"
                     value="<?= htmlspecialchars($fMinScore) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Carga horária (minutos)</label>
              <input type="number" name="workload_minutes" class="form-control" min="0" max="9999"
                     value="<?= htmlspecialchars($fWorkload) ?>">
              <small style="color:#94a3b8">Somado à carga horária do certificado</small>
            </div>
          </div>

          <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem">
            <a href="quizzes.php?course_id=<?= $cid ?>" class="btn btn-secondary">Cancelar</a>
            <button type="submit" class="btn btn-primary">💾 Salvar questionário</button>
          </div>
        </form>
      </div>
    </div>

    <script>
    function updatePlacement(radio) {
        document.querySelectorAll('.quiz-placement-opt').forEach(el => el.classList.remove('active'));
        radio.closest('.quiz-placement-opt').classList.add('active');
        document.getElementById('placement-lesson-wrap').style.display = radio.value === 'after_lesson' ? '' : 'none';
        document.getElementById('placement-topic-wrap').style.display  = radio.value === 'after_topic'  ? '' : 'none';
    }
    </script>
    <?php
    adminFooter();
    exit;
}

// ══════════════════════════════════════════════════════════════════════════════
// QUESTIONS — quizzes.php?action=questions&id=QUIZ_ID
// ══════════════════════════════════════════════════════════════════════════════
if ($action === 'questions') {
    if (!$id) { header('Location: courses.php'); exit; }
    $quiz = $quizModel->getQuizById($id);
    if (!$quiz) { header('Location: courses.php'); exit; }
    $course = $courseModel->getCourseById((int)$quiz['course_id']);

    $isWeighted = $quiz['scoring_method'] === 'weighted';

    // Questão sendo editada (via GET ?edit_q=ID)
    $editQId   = (int)($_GET['edit_q'] ?? 0);
    $editQ     = $editQId ? $quizModel->getQuestionById($editQId) : null;
    if ($editQ && (int)$editQ['quiz_id'] !== $id) $editQ = null;

    adminHeader('Questões: ' . $quiz['title'], 'courses');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

    <!-- Cabeçalho do questionário -->
    <div class="card mb-2">
      <div class="card-header">
        <div>
          <h2>❓ Questões</h2>
          <small style="color:#94a3b8">
            Questionário: <strong><?= htmlspecialchars($quiz['title']) ?></strong>
            &mdash; Nota mín.: <strong><?= number_format((float)$quiz['min_score'], 0) ?>%</strong>
            &mdash; <?= $isWeighted ? '⚖️ Ponderada' : '➗ Aritmética' ?>
            &mdash; <?= QuizModel::placementLabel($quiz['placement_type']) ?>
          </small>
        </div>
        <a href="quizzes.php?course_id=<?= $quiz['course_id'] ?>" class="btn btn-sm btn-secondary">← Voltar</a>
      </div>
    </div>

    <!-- Lista de questões existentes -->
    <?php foreach ($quiz['questions'] as $qi => $q): ?>
    <div class="card mb-2 quiz-question-card" id="qcard-<?= $q['id'] ?>">
      <div class="card-header" style="cursor:default">
        <div style="display:flex;align-items:center;gap:.75rem;flex:1">
          <span class="quiz-q-num"><?= $qi + 1 ?></span>
          <span class="quiz-q-text"><?= htmlspecialchars($q['question_text']) ?></span>
          <?php if ($isWeighted): ?>
          <span class="quiz-weight-badge">peso <?= number_format((float)$q['weight'], 2) ?></span>
          <?php endif; ?>
        </div>
        <div style="display:flex;gap:.5rem">
          <a href="quizzes.php?action=questions&id=<?= $id ?>&edit_q=<?= $q['id'] ?>#edit-form"
             class="btn btn-sm">✏️ Editar</a>
          <form method="post" action="quizzes.php?action=delete_question&id=<?= $id ?>" style="display:inline"
                onsubmit="return confirm('Remover esta questão?')">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <input type="hidden" name="quiz_id" value="<?= $id ?>">
            <input type="hidden" name="question_id" value="<?= $q['id'] ?>">
            <button class="btn btn-sm btn-danger">🗑</button>
          </form>
        </div>
      </div>
      <div style="padding:.75rem 1.25rem">
        <ol class="quiz-options-list">
          <?php foreach ($q['options'] as $opt): ?>
          <li class="quiz-option-item <?= $opt['is_correct'] ? 'correct' : '' ?>">
            <?= $opt['is_correct'] ? '✅' : '○' ?>
            <?= htmlspecialchars($opt['option_text']) ?>
          </li>
          <?php endforeach; ?>
        </ol>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if (empty($quiz['questions'])): ?>
    <div style="text-align:center;color:#64748b;padding:1.5rem">
      Nenhuma questão ainda. Adicione abaixo.
    </div>
    <?php endif; ?>

    <!-- Formulário Adicionar/Editar questão -->
    <div class="card" id="edit-form">
      <div class="card-header">
        <h2><?= $editQ ? '✏️ Editar questão' : '+ Nova questão' ?></h2>
        <?php if ($editQ): ?>
        <a href="quizzes.php?action=questions&id=<?= $id ?>" class="btn btn-sm btn-secondary">✕ Cancelar edição</a>
        <?php endif; ?>
      </div>
      <div class="card-body" style="padding:1.5rem">
        <form method="post" action="quizzes.php?action=save_question&id=<?= $id ?>">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <input type="hidden" name="quiz_id" value="<?= $id ?>">
          <input type="hidden" name="question_id" value="<?= $editQ ? $editQ['id'] : 0 ?>">

          <div class="form-group">
            <label class="form-label">Enunciado da questão *</label>
            <textarea name="question_text" class="form-control" rows="3" required
                      placeholder="Digite o texto da pergunta…"><?= htmlspecialchars($editQ ? $editQ['question_text'] : ($_POST['question_text'] ?? '')) ?></textarea>
          </div>

          <?php if ($isWeighted): ?>
          <div class="form-group" style="max-width:200px">
            <label class="form-label">Peso da questão</label>
            <input type="number" name="weight" class="form-control" min="0.01" max="100" step="0.01"
                   value="<?= htmlspecialchars($editQ ? $editQ['weight'] : ($_POST['weight'] ?? '1.00')) ?>">
          </div>
          <?php else: ?>
          <input type="hidden" name="weight" value="1.00">
          <?php endif; ?>

          <!-- Opções de resposta -->
          <div class="form-group">
            <label class="form-label">Opções de resposta
              <small style="color:#94a3b8">(marque a opção correta com o botão à esquerda)</small>
            </label>
            <div id="quiz-options-wrap">
              <?php
              $existingOpts = $editQ ? $editQ['options'] : [];
              // POST error? recupera do POST
              if ($error && isset($_POST['option_text'])) {
                  $existingOpts = [];
                  foreach ($_POST['option_text'] as $i => $ot) {
                      $existingOpts[] = [
                          'option_text' => $ot,
                          'is_correct'  => ($i === (int)($_POST['correct_option'] ?? -1)),
                      ];
                  }
              }
              $defaultOpts = 4;
              $optCount    = max($defaultOpts, count($existingOpts));
              for ($oi = 0; $oi < $optCount; $oi++):
                  $opt       = $existingOpts[$oi] ?? ['option_text' => '', 'is_correct' => false];
                  $isCorrect = (bool)($opt['is_correct'] ?? false);
              ?>
              <div class="quiz-option-row" data-idx="<?= $oi ?>">
                <input type="radio" name="correct_option" value="<?= $oi ?>"
                       <?= $isCorrect ? 'checked' : '' ?>
                       title="Marcar como correta">
                <input type="text" name="option_text[]" class="form-control"
                       placeholder="Opção <?= $oi + 1 ?>…"
                       value="<?= htmlspecialchars($opt['option_text']) ?>">
                <?php if ($oi >= $defaultOpts): // só mostra × nos extras ?>
                <button type="button" class="btn btn-sm btn-danger" onclick="removeOption(this)" title="Remover opção">×</button>
                <?php endif; ?>
              </div>
              <?php endfor; ?>
            </div>
            <button type="button" class="btn btn-sm btn-secondary" style="margin-top:.5rem" onclick="addOption()">
              + Adicionar opção
            </button>
          </div>

          <div style="display:flex;gap:.75rem;justify-content:flex-end;margin-top:1rem">
            <button type="submit" class="btn btn-primary">
              <?= $editQ ? '💾 Atualizar questão' : '+ Adicionar questão' ?>
            </button>
          </div>
        </form>
      </div>
    </div>

    <script>
    let optIndex = <?= $optCount ?>;

    function addOption() {
        const wrap = document.getElementById('quiz-options-wrap');
        const div  = document.createElement('div');
        div.className = 'quiz-option-row';
        div.dataset.idx = optIndex;
        div.innerHTML = `
            <input type="radio" name="correct_option" value="${optIndex}" title="Marcar como correta">
            <input type="text" name="option_text[]" class="form-control" placeholder="Opção ${optIndex + 1}…">
            <button type="button" class="btn btn-sm btn-danger" onclick="removeOption(this)" title="Remover opção">×</button>
        `;
        wrap.appendChild(div);
        optIndex++;
    }

    function removeOption(btn) {
        btn.closest('.quiz-option-row').remove();
        // re-numerar radio values para manter coerência
        document.querySelectorAll('#quiz-options-wrap .quiz-option-row').forEach((row, i) => {
            const radio = row.querySelector('input[type=radio]');
            if (radio) radio.value = i;
            row.dataset.idx = i;
        });
        optIndex = document.querySelectorAll('#quiz-options-wrap .quiz-option-row').length;
    }
    </script>
    <?php
    adminFooter();
    exit;
}

// Fallback
header('Location: courses.php');
exit;
