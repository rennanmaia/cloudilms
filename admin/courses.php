<?php
/**
 * CloudiLMS - Gerenciamento de Cursos (Admin)
 * Ações: list | new | edit | save | delete | lessons | sync | reorder | delete_lesson
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/course.php';
require_once __DIR__ . '/../includes/googledrive.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();

$model   = new CourseModel();
$gdrive  = new GoogleDrive();
$action  = $_GET['action'] ?? 'list';
$id      = (int)($_GET['id'] ?? 0);
$message = '';
$error   = '';

// ── POST handlers ────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrfToken = $_POST['csrf'] ?? '';

    // CSRF simples via sessão
    if ($csrfToken !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Token de segurança inválido. Recarregue a página.';
    } else {
        switch ($action) {
            case 'save':
                $folderUrl = trim($_POST['gdrive_folder_url'] ?? '');
                $folderId  = GoogleDrive::extractFolderId($folderUrl);
                if (!$folderId) {
                    $error = 'URL/ID da pasta do Google Drive inválido.';
                    break;
                }
                $data = [
                    'title'               => trim($_POST['title'] ?? ''),
                    'description'         => trim($_POST['description'] ?? ''),
                    'thumbnail'           => trim($_POST['thumbnail'] ?? ''),
                    'gdrive_folder_id'    => $folderId,
                    'gdrive_folder_url'   => $folderUrl,
                    'published'           => isset($_POST['published']) ? 1 : 0,
                    'extra_hours_minutes' => max(0, (int)($_POST['extra_hours_minutes'] ?? 0)),
                ];
                if (!$data['title']) { $error = 'Título é obrigatório.'; break; }

                if ($id) {
                    $model->updateCourse($id, $data);
                    $message = 'Curso atualizado com sucesso.';
                } else {
                    $id = $model->createCourse($data);
                    $message = 'Curso criado. Agora sincronize as aulas ⬇️';
                    header("Location: courses.php?action=lessons&id={$id}&msg=" . urlencode($message));
                    exit;
                }
                $action = 'edit';
                break;

            case 'delete':
                if ($id) {
                    $model->deleteCourse($id);
                    header('Location: courses.php?action=list&msg=' . urlencode('Curso excluído.'));
                    exit;
                }
                break;

            case 'sync':
                $course = $model->getCourseById($id);
                if (!$course) { $error = 'Curso não encontrado.'; break; }

                $allFiles   = $gdrive->getFolderFiles($course['gdrive_folder_id']);
                $folders    = array_values(array_filter($allFiles, fn($f) => $f['mimeType'] === 'application/vnd.google-apps.folder'));
                $rootVideos = array_values(array_filter($allFiles, fn($f) => str_starts_with($f['mimeType'] ?? '', 'video/')));

                if (empty($allFiles)) {
                    $error = 'Nenhum arquivo encontrado. Verifique se a pasta é pública e a API Key está configurada.';
                    $action = 'lessons';
                    break;
                }

                if (!empty($folders)) {
                    // Subpastas detectadas → criar como tópicos
                    $subfolderData = [];
                    foreach ($folders as $folder) {
                        $subFiles = $gdrive->getFolderFiles($folder['id']);
                        $subfolderData[$folder['name']] = $subFiles;
                    }
                    $added = $model->syncLessonsWithTopics($id, $rootVideos, $subfolderData);
                    $tf = count($folders);
                    $message = "Sincronização concluída! {$added} nova(s) aula(s) importada(s). {$tf} subpasta(s) criadas como tópicos.";
                } else {
                    $added = $model->syncLessons($id, $allFiles);
                    $message = "Sincronização concluída! {$added} nova(s) aula(s) importada(s).";
                }
                $action = 'lessons';
                break;

            case 'reorder':
                $orders = $_POST['order'] ?? [];
                foreach ($orders as $lessonId => $order) {
                    $model->updateLessonOrder((int)$lessonId, (int)$order);
                }
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;

            case 'reorder_topic':
                $orders = $_POST['order'] ?? [];
                foreach ($orders as $topicId => $order) {
                    $model->updateTopicOrder((int)$topicId, (int)$order);
                }
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;

            case 'assign_topic':
                $lessonId = (int)($_POST['lesson_id'] ?? 0);
                $rawTopic = $_POST['topic_id'] ?? '';
                $topicId  = ($rawTopic === '' || $rawTopic === '0') ? null : (int)$rawTopic;
                if ($lessonId) $model->assignLessonToTopic($lessonId, $topicId);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;

            case 'save_topic':
                $topicTitle = trim($_POST['topic_title'] ?? '');
                $topicId    = (int)($_POST['topic_id'] ?? 0);
                if (!$topicTitle) { $error = 'Título do tópico é obrigatório.'; $action = 'lessons'; break; }
                if ($topicId) {
                    $model->updateTopic($topicId, $topicTitle);
                    $message = 'Tópico renomeado.';
                } else {
                    $existing = $model->getTopicsByCourse($id);
                    $model->createTopic($id, $topicTitle, count($existing) + 1);
                    $message = 'Tópico criado.';
                }
                header("Location: courses.php?action=lessons&id={$id}&msg=" . urlencode($message));
                exit;

            case 'delete_topic':
                $topicId = (int)($_POST['topic_id'] ?? 0);
                if ($topicId) $model->deleteTopic($topicId);
                header("Location: courses.php?action=lessons&id={$id}&msg=" . urlencode('Tópico excluído. Aulas mantidas sem tópico.'));
                exit;

            case 'lesson_settings':
                $lessonId        = (int)($_POST['lesson_id'] ?? 0);
                $preventSeek     = (int)(bool)($_POST['prevent_seek']     ?? 0);
                $forceSequential = (int)(bool)($_POST['force_sequential'] ?? 0);
                $requireWatch    = (int)(bool)($_POST['require_watch']    ?? 0);
                if ($lessonId) $model->updateLessonSettings($lessonId, $preventSeek, $forceSequential, $requireWatch);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;

            case 'lesson_estimated':
                $lessonId = (int)($_POST['lesson_id'] ?? 0);
                $minutes  = max(0, (int)($_POST['minutes'] ?? 0));
                $seconds  = $minutes > 0 ? $minutes * 60 : null;
                if ($lessonId) $model->updateLessonEstimated($lessonId, $seconds);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;

            case 'delete_lesson':
                $lessonId = (int)($_POST['lesson_id'] ?? 0);
                if ($lessonId) $model->deleteLesson($lessonId);
                header("Location: courses.php?action=lessons&id={$id}&msg=" . urlencode('Aula removida.'));
                exit;

            case 'bulk_delete_lessons':
                $ids = array_map('intval', (array)($_POST['lesson_ids'] ?? []));
                $deleted = 0;
                foreach (array_filter($ids) as $lid) {
                    $model->deleteLesson($lid);
                    $deleted++;
                }
                header("Location: courses.php?action=lessons&id={$id}&msg=" . urlencode("{$deleted} aula(s) excluída(s)."));
                exit;

            case 'save_lesson':
                $lessonId  = (int)($_POST['lesson_id'] ?? 0);
                $courseId  = $id ?: (int)($_POST['course_id'] ?? 0);
                $title     = trim($_POST['title'] ?? '');
                $videoUrl  = trim($_POST['gdrive_video_url'] ?? '');
                $bodyText  = trim($_POST['body_text'] ?? '');
                $topicRaw  = $_POST['topic_id'] ?? '';
                $topicId   = ($topicRaw === '' || $topicRaw === '0') ? null : (int)$topicRaw;

                if (!$title) { $error = 'Título é obrigatório.'; $action = $lessonId ? 'edit_lesson' : 'new_lesson'; break; }

                $gdriveFileId = null;
                $mimeType     = null;
                if ($videoUrl !== '') {
                    $extracted = GoogleDrive::extractFolderId($videoUrl);
                    if (!$extracted) { $error = 'URL/ID do vídeo no Google Drive inválido.'; $action = $lessonId ? 'edit_lesson' : 'new_lesson'; break; }
                    $gdriveFileId = $extracted;
                    $mimeType     = 'video/mp4';
                }

                if ($lessonId) {
                    $model->updateLesson($lessonId, $title, $gdriveFileId, $mimeType, $bodyText ?: null);
                    $model->assignLessonToTopic($lessonId, $topicId);
                    header("Location: courses.php?action=edit_lesson&id={$courseId}&lesson_id={$lessonId}&msg=" . urlencode('Aula atualizada.'));
                } else {
                    $newId = $model->createManualLesson($courseId, $topicId, $title, $gdriveFileId, $mimeType, $bodyText ?: null);
                    header("Location: courses.php?action=edit_lesson&id={$courseId}&lesson_id={$newId}&msg=" . urlencode('Aula criada.'));
                }
                exit;

            case 'add_attachment':
                $lessonId = (int)($_POST['lesson_id'] ?? 0);
                $courseId = $id ?: (int)($_POST['course_id'] ?? 0);
                if (!$lessonId) { $error = 'Aula não encontrada.'; $action = 'edit_lesson'; break; }

                $uploadDir = __DIR__ . '/../uploads/attachments/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                $files = $_FILES['att_files'] ?? null;
                if (!$files || empty($files['name'][0])) {
                    $error = 'Selecione pelo menos um arquivo.';
                    $action = 'edit_lesson';
                    break;
                }

                $dangerousExts = ['php','phtml','php3','php4','php5','phar','exe','bat','cmd','sh','py','rb','pl','cgi'];
                $added = 0;
                for ($i = 0; $i < count($files['name']); $i++) {
                    if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $origName = $files['name'][$i];
                    $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
                    if (in_array($ext, $dangerousExts)) continue;

                    $finfo = new finfo(FILEINFO_MIME_TYPE);
                    $mime  = $finfo->file($files['tmp_name'][$i]);

                    $newName = bin2hex(random_bytes(16)) . ($ext ? '.' . $ext : '');
                    if (move_uploaded_file($files['tmp_name'][$i], $uploadDir . $newName)) {
                        $title = pathinfo($origName, PATHINFO_FILENAME);
                        $model->addAttachment($lessonId, $title, null, $mime, $newName);
                        $added++;
                    }
                }
                $msg = $added > 0 ? "{$added} arquivo(s) adicionado(s)." : 'Nenhum arquivo válido foi enviado.';
                header("Location: courses.php?action=edit_lesson&id={$courseId}&lesson_id={$lessonId}&msg=" . urlencode($msg));
                exit;

            case 'delete_attachment':
                $lessonId   = (int)($_POST['lesson_id'] ?? 0);
                $courseId   = $id ?: (int)($_POST['course_id'] ?? 0);
                $attId      = (int)($_POST['att_id'] ?? 0);
                if ($attId) $model->deleteAttachment($attId);
                header("Location: courses.php?action=edit_lesson&id={$courseId}&lesson_id={$lessonId}&msg=" . urlencode('Anexo removido.'));
                exit;
        }
    }
}

// Regenera CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
}
$csrf = $_SESSION['csrf_token'];

// Mensagem via GET
if (!$message && !empty($_GET['msg'])) {
    $message = htmlspecialchars($_GET['msg']);
}

// ── Views ────────────────────────────────────────────────────────────────────

if ($action === 'list') {
    $courses = $model->getAllCourses();
    adminHeader('Gerenciar Cursos', 'courses');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header">
        <h2>Todos os cursos (<?= count($courses) ?>)</h2>
        <a href="courses.php?action=new" class="btn btn-primary">+ Novo curso</a>
      </div>
      <table class="table">
        <thead><tr><th>Título</th><th>Aulas</th><th>Alunos</th><th>Status</th><th>Ações</th></tr></thead>
        <tbody>
          <?php foreach ($courses as $c): ?>
          <tr>
            <td><strong><?= htmlspecialchars($c['title']) ?></strong><br>
                <small style="color:#64748b"><?= htmlspecialchars($c['gdrive_folder_id']) ?></small></td>
            <td><?= $c['lesson_count'] ?></td>
            <td><?= $c['student_count'] ?></td>
            <td><span class="badge <?= $c['published'] ? 'badge-success':'badge-warning' ?>"><?= $c['published'] ? 'Publicado':'Rascunho' ?></span></td>
            <td class="actions">
              <a href="courses.php?action=edit&id=<?= $c['id'] ?>" class="btn btn-sm">✏️ Editar</a>
              <a href="courses.php?action=lessons&id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">▶ Aulas</a>
              <a href="quizzes.php?course_id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">📝 Questionários</a>
              <form method="post" action="courses.php?action=delete&id=<?= $c['id'] ?>" style="display:inline" onsubmit="return confirm('Excluir este curso e todas as aulas?')">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button class="btn btn-sm btn-danger">🗑 Excluir</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
          <?php if (!$courses): ?>
          <tr><td colspan="5" style="text-align:center;color:#64748b">Nenhum curso. <a href="courses.php?action=new">Criar agora →</a></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <?php
    adminFooter();
    exit;
}

if ($action === 'new' || $action === 'edit') {
    $course = $id ? $model->getCourseById($id) : null;
    $pageTitle = $id ? 'Editar Curso' : 'Novo Curso';
    adminHeader($pageTitle, 'courses');
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= htmlspecialchars($message) ?></div><?php endif; ?>

    <div class="card">
      <div class="card-header"><h2><?= $pageTitle ?></h2></div>
      <div class="card-body">
        <form method="post" action="courses.php?action=save<?= $id ? "&id={$id}" : '' ?>">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <div class="form-group">
            <label>Título do curso *</label>
            <input type="text" name="title" value="<?= htmlspecialchars($course['title'] ?? '') ?>" required class="form-control" placeholder="Ex: Curso de Python do Zero">
          </div>

          <div class="form-group">
            <label>Descrição</label>
            <textarea name="description" rows="4" class="form-control" placeholder="Descreva o curso..."><?= htmlspecialchars($course['description'] ?? '') ?></textarea>
          </div>

          <div class="form-group">
            <label>URL da pasta no Google Drive *</label>
            <input type="text" name="gdrive_folder_url"
                   value="<?= htmlspecialchars($course['gdrive_folder_url'] ?? '') ?>"
                   required class="form-control"
                   placeholder="https://drive.google.com/drive/folders/XXXXX">
            <small class="help-text">Cole a URL da pasta pública do Google Drive. A pasta deve estar com permissão "Qualquer pessoa com o link pode ver".</small>
          </div>

          <div class="form-group">
            <label>URL de thumbnail (opcional)</label>
            <input type="url" name="thumbnail" value="<?= htmlspecialchars($course['thumbnail'] ?? '') ?>" class="form-control" placeholder="https://...">
          </div>

          <div class="form-group">
            <label class="checkbox-label">
              <input type="checkbox" name="published" value="1" <?= !empty($course['published']) ? 'checked' : '' ?>>
              Publicar curso (visível para alunos)
            </label>
          </div>

          <div class="form-group">
            <label>Minutos extras de carga horária</label>
            <input type="number" name="extra_hours_minutes" min="0" max="9999"
                   value="<?= (int)($course['extra_hours_minutes'] ?? 0) ?>"
                   class="form-control" style="max-width:140px">
            <small class="help-text">Minutos adicionais além da duração dos vídeos (ex: tempo estimado de avaliações). Usado no certificado.</small>
          </div>

            <button type="submit" class="btn btn-primary">💾 Salvar curso</button>
            <a href="courses.php" class="btn">Cancelar</a>
          </div>
        </form>
      </div>
    </div>
    <?php
    adminFooter();
    exit;
}

if ($action === 'new_lesson' || $action === 'edit_lesson') {
    $courseId = $id;
    $course   = $model->getCourseById($courseId);
    if (!$course) { header('Location: courses.php'); exit; }

    $lessonId = (int)($_GET['lesson_id'] ?? 0);
    $lesson   = $lessonId ? $model->getLessonById($lessonId) : null;
    if ($lessonId && !$lesson) { header("Location: courses.php?action=lessons&id={$courseId}"); exit; }

    $attachments = $lesson ? $model->getAttachmentsByLesson($lessonId) : [];
    $topics      = $model->getTopicsByCourse($courseId);

    $pageTitle = $lesson ? 'Editar Aula' : 'Nova Aula';
    adminHeader($pageTitle . ': ' . $course['title'], 'courses');

    if ($error): ?>
    <div class="alert alert-danger"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if (isset($_GET['msg'])): ?>
    <div class="alert alert-success"><?= htmlspecialchars($_GET['msg']) ?></div>
    <?php endif; ?>

    <div class="card mb-2">
      <div class="card-header">
        <h2><?= $pageTitle ?></h2>
        <a href="courses.php?action=lessons&id=<?= $courseId ?>" class="btn btn-sm">← Voltar para aulas</a>
      </div>
      <div class="card-body">
        <form method="post" id="lessonForm" action="courses.php?action=save_lesson&id=<?= $courseId ?>">
          <input type="hidden" name="csrf"      value="<?= $csrf ?>">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <input type="hidden" name="lesson_id" value="<?= $lessonId ?>">

          <div class="form-group">
            <label>Título da aula *</label>
            <input type="text" name="title" class="form-control" required
                   value="<?= htmlspecialchars($lesson['title'] ?? '') ?>"
                   placeholder="Ex: Introdução ao módulo">
          </div>

          <div class="form-group">
            <label>Tópico</label>
            <select name="topic_id" class="form-control">
              <option value="">— Sem tópico —</option>
              <?php foreach ($topics as $t): ?>
              <option value="<?= $t['id'] ?>"
                <?= (isset($lesson['topic_id']) && (int)$lesson['topic_id'] === (int)$t['id']) ? 'selected' : '' ?>>
                <?= htmlspecialchars($t['title']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>

          <div class="form-group">
            <label>Vídeo no Google Drive (opcional)</label>
            <input type="text" name="gdrive_video_url" class="form-control"
                   value="<?= htmlspecialchars(($lesson && $lesson['gdrive_file_id'] && !str_starts_with($lesson['gdrive_file_id'], 'manual_')) ? 'https://drive.google.com/file/d/' . $lesson['gdrive_file_id'] . '/view' : '') ?>"
                   placeholder="Cole a URL do vídeo no Google Drive (ou deixe em branco)">
            <small class="help-text">Ex: https://drive.google.com/file/d/XXXXX/view — Deixe em branco para aulas só com texto ou arquivos.</small>
          </div>

          <div class="form-group">
            <label>Texto / Conteúdo da aula</label>
            <div class="rte-wrapper">
              <div class="rte-toolbar" id="rteToolbar">
                <button type="button" class="rte-btn" data-cmd="bold" title="Negrito (Ctrl+B)"><b>B</b></button>
                <button type="button" class="rte-btn" data-cmd="italic" title="Itálico (Ctrl+I)"><i>I</i></button>
                <button type="button" class="rte-btn" data-cmd="underline" title="Sublinhado (Ctrl+U)"><u>U</u></button>
                <span class="rte-sep"></span>
                <button type="button" class="rte-btn" data-cmd="formatBlock" data-val="h2" title="Título 2">H2</button>
                <button type="button" class="rte-btn" data-cmd="formatBlock" data-val="h3" title="Título 3">H3</button>
                <button type="button" class="rte-btn" data-cmd="formatBlock" data-val="p" title="Parágrafo">¶</button>
                <span class="rte-sep"></span>
                <button type="button" class="rte-btn" data-cmd="insertUnorderedList" title="Lista com marcadores">• Lista</button>
                <button type="button" class="rte-btn" data-cmd="insertOrderedList" title="Lista numerada">1. Lista</button>
                <span class="rte-sep"></span>
                <button type="button" class="rte-btn" data-cmd="createLink" title="Inserir link">🔗</button>
                <button type="button" class="rte-btn" data-cmd="formatBlock" data-val="blockquote" title="Citação">❝</button>
                <span class="rte-sep"></span>
                <button type="button" class="rte-btn" data-cmd="removeFormat" title="Limpar formatação">⊘ Limpar</button>
              </div>
              <div class="rte-content" id="rteContent" contenteditable="true"><?= $lesson['body_text'] ?? '' ?></div>
            </div>
            <textarea name="body_text" id="bodyTextHidden" style="display:none"></textarea>
          </div>

          <button type="submit" class="btn btn-primary">💾 Salvar aula</button>
          <a href="courses.php?action=lessons&id=<?= $courseId ?>" class="btn">Cancelar</a>
        </form>
      </div>
    </div>

    <?php if ($lesson): ?>
    <!-- Anexos -->
    <div class="card">
      <div class="card-header">
        <h2>📎 Anexos (<?= count($attachments) ?>)</h2>
      </div>

      <?php if ($attachments): ?>
      <table class="table">
        <thead><tr><th>Título</th><th>Tipo</th><th>Arquivo</th><th></th></tr></thead>
        <tbody>
          <?php foreach ($attachments as $att): ?>
          <tr>
            <td><?= htmlspecialchars($att['title']) ?></td>
            <td><code><?= htmlspecialchars($att['mime_type'] ?? '—') ?></code></td>
            <td><code style="font-size:.8em;word-break:break-all"><?= htmlspecialchars(!empty($att['file_path']) ? $att['file_path'] : ($att['gdrive_file_id'] ?? '—')) ?></code></td>
            <td>
              <?php if (!empty($att['file_path'])): ?>
              <a href="<?= APP_URL ?>/download.php?attachment=<?= $att['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">🔗</a>
              <?php elseif (!empty($att['gdrive_file_id'])): ?>
              <a href="https://drive.google.com/file/d/<?= urlencode($att['gdrive_file_id']) ?>/view" target="_blank" class="btn btn-sm btn-secondary">🔗</a>
              <?php endif; ?>
              <form method="post" action="courses.php?action=delete_attachment&id=<?= $courseId ?>" style="display:inline"
                    onsubmit="return confirm('Remover este anexo?')">
                <input type="hidden" name="csrf"      value="<?= $csrf ?>">
                <input type="hidden" name="lesson_id" value="<?= $lessonId ?>">
                <input type="hidden" name="course_id" value="<?= $courseId ?>">
                <input type="hidden" name="att_id"    value="<?= $att['id'] ?>">
                <button class="btn btn-sm btn-danger">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>

      <div class="card-body" style="border-top:1px solid var(--bg3)">
        <h3 style="margin-bottom:1rem;font-size:1rem">Enviar arquivo(s)</h3>
        <form method="post" action="courses.php?action=add_attachment&id=<?= $courseId ?>"
              enctype="multipart/form-data">
          <input type="hidden" name="csrf"      value="<?= $csrf ?>">
          <input type="hidden" name="lesson_id" value="<?= $lessonId ?>">
          <input type="hidden" name="course_id" value="<?= $courseId ?>">
          <div style="display:flex;gap:.75rem;align-items:flex-end;flex-wrap:wrap">
            <div class="form-group" style="margin:0;flex:1;min-width:280px">
              <label>Arquivo(s) *</label>
              <input type="file" name="att_files[]" multiple required class="form-control"
                     accept=".pdf,.mp4,.mp3,.m4a,.ogg,.webm,.avi,.mov,.mkv,.jpg,.jpeg,.png,.gif,.webp,.zip,.rar,.7z,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv">
              <small class="help-text">Selecione um ou mais arquivos. Arquivos PHP/executáveis são bloqueados.</small>
            </div>
            <button type="submit" class="btn btn-primary" style="flex-shrink:0">⬆ Enviar</button>
          </div>
        </form>
      </div>
    </div>
    <?php else: ?>
    <p style="color:#64748b;margin-top:1rem">Salve a aula primeiro para poder adicionar anexos.</p>
    <?php endif; ?>

    <script>
    /* ── Rich Text Editor ──────────────────────────────────────────────── */
    (function () {
        const toolbar = document.getElementById('rteToolbar');
        const content = document.getElementById('rteContent');
        const hidden  = document.getElementById('bodyTextHidden');
        const form    = document.getElementById('lessonForm');
        if (!toolbar || !content || !hidden) return;

        // Inicializa campo oculto com conteúdo atual
        hidden.value = content.innerHTML;

        // Sincroniza antes do submit
        if (form) form.addEventListener('submit', () => { hidden.value = content.innerHTML; });

        // Clique nos botões da barra
        toolbar.querySelectorAll('.rte-btn[data-cmd]').forEach(btn => {
            btn.addEventListener('mousedown', e => {
                e.preventDefault();
                const cmd = btn.dataset.cmd;
                const val = btn.dataset.val || null;
                content.focus();
                if (cmd === 'createLink') {
                    const url = prompt('URL do link:', 'https://');
                    if (url) document.execCommand('createLink', false, url);
                } else if (val) {
                    document.execCommand(cmd, false, val);
                } else {
                    document.execCommand(cmd, false, null);
                }
                updateToolbarState();
            });
        });

        // Atualiza botões ativos
        function updateToolbarState() {
            toolbar.querySelectorAll('.rte-btn[data-cmd]').forEach(btn => {
                const cmd = btn.dataset.cmd;
                const val = btn.dataset.val;
                try {
                    const active = val
                        ? document.queryCommandValue(cmd) === val
                        : document.queryCommandState(cmd);
                    btn.classList.toggle('rte-active', !!active);
                } catch (_) {}
            });
        }
        content.addEventListener('keyup', updateToolbarState);
        content.addEventListener('mouseup', updateToolbarState);
    })();
    </script>
    <?php
    adminFooter();
    exit;
}

if ($action === 'lessons') {
    $course = $model->getCourseById($id);
    if (!$course) { header('Location: courses.php'); exit; }

    $grouped      = $model->getLessonsGroupedByTopic($id);
    $topics       = $model->getTopicsByCourse($id);
    $totalLessons = array_sum(array_map(fn($g) => count($g['lessons']), $grouped));

    require_once __DIR__ . '/../includes/quiz.php';
    $_qmL            = new QuizModel();
    $_allCQuizzes    = $_qmL->getQuizzesByCourse($id);
    $quizzesByLesson = [];
    $quizzesByTopic  = [];
    $endOfCourseQuizzes = [];
    foreach ($_allCQuizzes as $_cq) {
        if ($_cq['placement_type'] === 'after_lesson' && $_cq['placement_id']) {
            $quizzesByLesson[(int)$_cq['placement_id']][] = $_cq;
        } elseif ($_cq['placement_type'] === 'after_topic' && $_cq['placement_id']) {
            $quizzesByTopic[(int)$_cq['placement_id']][] = $_cq;
        } else {
            $endOfCourseQuizzes[] = $_cq;
        }
    }

    adminHeader('Aulas: ' . $course['title'], 'courses');
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

    <!-- Drive sync -->
    <div class="card mb-2">
      <div class="card-header">
        <div>
          <h2>Pasta Google Drive</h2>
          <small style="color:#94a3b8">ID: <?= htmlspecialchars($course['gdrive_folder_id']) ?> &mdash;
            Se a pasta tiver <strong>subpastas</strong>, elas serão criadas automaticamente como tópicos.</small>
        </div>
        <div style="display:flex;gap:.75rem;align-items:center">
          <a href="quizzes.php?course_id=<?= $id ?>" class="btn btn-sm btn-secondary">📝 Questionários</a>
          <a href="courses.php?action=new_lesson&id=<?= $id ?>" class="btn btn-sm btn-primary">+ Nova aula</a>
          <a href="<?= htmlspecialchars($course['gdrive_folder_url']) ?>" target="_blank" class="btn btn-sm btn-secondary">🔗 Abrir no Drive</a>
          <form method="post" action="courses.php?action=sync&id=<?= $id ?>">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <button class="btn btn-primary">🔄 Sincronizar aulas do Drive</button>
          </form>
        </div>
      </div>
    </div>

    <!-- Layout em duas colunas: tópicos | aulas -->
    <div class="lessons-layout">

      <!-- Painel de tópicos -->
      <div class="topics-panel">
        <div class="card">
          <div class="card-header"><h2>📂 Tópicos (<?= count($topics) ?>)</h2></div>

          <?php if ($topics): ?>
          <ul class="topic-manage-list" id="topicManageList">
            <?php foreach ($topics as $t): ?>
            <li class="topic-manage-item" data-id="<?= $t['id'] ?>">
              <span class="drag-handle">⠿</span>
              <span class="topic-manage-title" id="tmt-<?= $t['id'] ?>"><?= htmlspecialchars($t['title']) ?></span>
              <div class="topic-manage-actions">
                <button class="btn btn-sm btn-secondary" onclick="editTopic(<?= $t['id'] ?>, this)" title="Renomear">✏️</button>
                <form method="post" action="courses.php?action=delete_topic&id=<?= $id ?>" style="display:inline"
                      onsubmit="return confirm('Excluir tópico? As aulas serão mantidas sem tópico.')">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="topic_id" value="<?= $t['id'] ?>">
                  <button class="btn btn-sm btn-danger" title="Excluir">🗑</button>
                </form>
              </div>
            </li>
            <?php endforeach; ?>
          </ul>
          <?php else: ?>
          <div style="padding:1.25rem;color:#64748b;font-size:.875rem;text-align:center">
            Nenhum tópico criado ainda.<br>Use o formulário abaixo ou sincronize uma pasta com subpastas.
          </div>
          <?php endif; ?>

          <div style="padding:1rem;border-top:1px solid var(--bg3)">
            <form method="post" action="courses.php?action=save_topic&id=<?= $id ?>" id="newTopicForm">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="topic_id" value="0" id="editTopicId">
              <div style="display:flex;gap:.5rem">
                <input type="text" name="topic_title" id="topicTitleInput" class="form-control" placeholder="Nome do tópico…" required style="flex:1">
                <button type="submit" class="btn btn-primary" id="topicSaveBtn">+ Criar</button>
              </div>
              <button type="button" id="topicCancelBtn" class="btn btn-sm mt-1" style="display:none" onclick="cancelTopicEdit()">Cancelar edição</button>
            </form>
          </div>
        </div>
      </div>

      <!-- Painel de aulas -->
      <div class="lessons-panel">
        <div class="card">
          <div class="card-header">
            <div style="display:flex;align-items:center;gap:1rem;flex-wrap:wrap">
              <h2>▶ Aulas (<?= $totalLessons ?>)</h2>
              <?php if ($totalLessons > 0): ?>
              <label style="display:flex;align-items:center;gap:.4rem;font-size:.875rem;color:var(--text2);cursor:pointer;font-weight:normal">
                <input type="checkbox" id="selectAllLessons" onchange="toggleSelectAll(this)">
                Selecionar tudo
              </label>
              <?php endif; ?>
            </div>
            <div style="display:flex;align-items:center;gap:.75rem;flex-wrap:wrap">
              <?php if ($totalLessons > 0): ?>
              <button type="button" id="bulkDeleteBtn" class="btn btn-sm btn-danger" style="display:none" onclick="submitBulkDelete()">🗑 Excluir selecionadas</button>
              <?php endif; ?>
              <small style="color:#94a3b8">Arraste para reordenar dentro do tópico</small>
            </div>
          </div>
          <form method="post" action="courses.php?action=bulk_delete_lessons&id=<?= $id ?>" id="bulkDeleteForm">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">

          <?php if ($totalLessons > 0): ?>
          <div class="grouped-lessons">
            <?php foreach ($grouped as $group): ?>
            <?php $topicData = $group['topic']; $groupLessons = $group['lessons']; ?>

            <?php if ($topicData): ?>
            <div class="topic-group" <?= $topicData['id'] ? 'data-topic-id="' . $topicData['id'] . '"' : '' ?>>
              <div class="topic-group-header">
                <span class="topic-group-icon">📁</span>
                <span class="topic-group-title"><?= htmlspecialchars($topicData['title']) ?></span>
                <span class="topic-group-count"><?= count($groupLessons) ?> aula(s)</span>
              </div>
            <?php else: ?>
            <div class="topic-group" data-topic-id="0">
              <div class="topic-group-header topic-group-header--none">
                <span class="topic-group-icon">📄</span>
                <span class="topic-group-title">Sem tópico</span>
                <span class="topic-group-count"><?= count($groupLessons) ?> aula(s)</span>
              </div>
            <?php endif; ?>

              <ul class="lesson-list lesson-sublist" data-topic="<?= $topicData['id'] ?? '0' ?>">
                <?php foreach ($groupLessons as $i => $l): ?>
                <li class="lesson-item" data-id="<?= $l['id'] ?>">
                  <input type="checkbox" class="lesson-bulk-cb" name="lesson_ids[]" value="<?= $l['id'] ?>" onchange="onCbChange()" style="flex-shrink:0;margin-right:.25rem;accent-color:var(--danger,#ef4444);cursor:pointer">
                  <span class="drag-handle">⠿</span>
                  <span class="lesson-num"><?= $i + 1 ?></span>
                  <div class="lesson-info">
                    <span class="lesson-title"><?= htmlspecialchars($l['title']) ?></span>
                    <span class="lesson-dur-row">
                      <?php if ($l['duration_seconds']): ?>
                        <span class="lesson-dur-badge dur-auto" title="Duração detectada automaticamente">⏱ <?= gmdate($l['duration_seconds'] >= 3600 ? 'H:i:s' : 'i:s', $l['duration_seconds']) ?></span>
                      <?php elseif (!empty($l['estimated_seconds'])): ?>
                        <span class="lesson-dur-badge dur-est" title="Tempo estimado (definido manualmente)">⏱ ~<?= gmdate($l['estimated_seconds'] >= 3600 ? 'H:i:s' : 'i:s', $l['estimated_seconds']) ?></span>
                      <?php else: ?>
                        <span class="lesson-dur-badge dur-none">⏱ —</span>
                      <?php endif; ?>
                      <label class="lesson-est-wrap" title="Duração estimada em minutos (usado quando não há duração automática)">
                        <span class="est-lbl">Est.:</span>
                        <input class="lesson-est-input" type="number" min="0" max="9999" placeholder="—"
                               value="<?= !empty($l['estimated_seconds']) ? (int)round($l['estimated_seconds'] / 60) : '' ?>"
                               data-lesson="<?= $l['id'] ?>"
                               onblur="saveEstimated(this)"
                               onkeydown="if(event.key==='Enter'){this.blur();event.preventDefault();}">
                        <span class="est-unit">min</span>
                      </label>
                    </span>
                  </div>
                  <!-- Mover para tópico -->
                  <select class="topic-assign-select" data-lesson="<?= $l['id'] ?>" onchange="assignTopic(this)"
                          title="Mover para tópico">
                    <option value="">— Sem tópico —</option>
                    <?php foreach ($topics as $t): ?>
                    <option value="<?= $t['id'] ?>" <?= (int)$l['topic_id'] === (int)$t['id'] ? 'selected' : '' ?>>
                      <?= htmlspecialchars($t['title']) ?>
                    </option>
                    <?php endforeach; ?>
                  </select>
                  <div class="lesson-settings" data-id="<?= $l['id'] ?>">
                    <button class="btn btn-sm lesson-toggle-btn <?= $l['prevent_seek']     ? 'active' : '' ?>"
                            data-field="prevent_seek"
                            title="<?= $l['prevent_seek'] ? '✅ Avanço bloqueado — clique para desativar' : 'Bloquear avanço do vídeo (desativado)' ?>"
                            onclick="toggleSetting(this)">
                      ⏩ <span class="tgl-label">Seek</span>
                    </button>
                    <button class="btn btn-sm lesson-toggle-btn <?= $l['force_sequential'] ? 'active' : '' ?>"
                            data-field="force_sequential"
                            title="<?= $l['force_sequential'] ? '✅ Sequencial obrigatório — clique para desativar' : 'Exige conclusão da aula anterior (desativado)' ?>"
                            onclick="toggleSetting(this)">
                      🔒 <span class="tgl-label">Seq.</span>
                    </button>
                    <button class="btn btn-sm lesson-toggle-btn <?= !empty($l['require_watch']) ? 'active' : '' ?>"
                            data-field="require_watch"
                            title="<?= !empty($l['require_watch']) ? '✅ Exige 75% assistido — clique para permitir conclusão manual' : 'Conclusão manual permitida — clique para exigir 75%' ?>"
                            onclick="toggleSetting(this)">
                      🎯 <span class="tgl-label">75%</span>
                    </button>
                  </div>
                  <div class="lesson-actions">
                    <a href="<?= APP_URL ?>/watch.php?lesson=<?= $l['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">▶</a>
                    <a href="courses.php?action=edit_lesson&id=<?= $id ?>&lesson_id=<?= $l['id'] ?>" class="btn btn-sm" title="Editar aula">✏️</a>
                    <form method="post" action="courses.php?action=delete_lesson&id=<?= $id ?>" style="display:inline" onsubmit="return confirm('Remover esta aula?')">
                      <input type="hidden" name="csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="lesson_id" value="<?= $l['id'] ?>">
                      <button class="btn btn-sm btn-danger">🗑</button>
                    </form>
                  </div>
                </li>
                <?php if (isset($quizzesByLesson[(int)$l['id']])): ?>
                  <?php foreach ($quizzesByLesson[(int)$l['id']] as $_cq): ?>
                  <li class="lesson-quiz-row">
                    <span class="drag-handle" style="visibility:hidden">&#x2807;</span>
                    <span class="lesson-num lesson-quiz-badge">&#x1F4DD;</span>
                    <div class="lesson-info">
                      <span class="lesson-title"><?= htmlspecialchars($_cq['title']) ?></span>
                      <span class="lesson-dur-row">
                        <span class="lesson-dur-badge lesson-quiz-meta-badge">
                          mín. <?= number_format((float)$_cq['min_score'], 0) ?>%
                          &middot; <?= $_cq['scoring_method'] === 'weighted' ? '&#x2696;&#xFE0F; pond.' : '&#xF7; arit.' ?>
                        </span>
                      </span>
                    </div>
                    <div class="lesson-actions" style="margin-left:auto">
                      <a href="quizzes.php?action=questions&amp;id=<?= $_cq['id'] ?>" class="btn btn-sm btn-secondary" title="Questões">❓</a>
                      <a href="quizzes.php?action=edit&amp;id=<?= $_cq['id'] ?>&amp;course_id=<?= $id ?>" class="btn btn-sm" title="Editar">✏️</a>
                      <form method="post" action="quizzes.php?action=delete&amp;id=<?= $_cq['id'] ?>" style="display:inline"
                            onsubmit="return confirm('Excluir questionário?')">
                        <input type="hidden" name="csrf" value="<?= $csrf ?>">
                        <input type="hidden" name="quiz_id" value="<?= $_cq['id'] ?>">
                        <button class="btn btn-sm btn-danger" title="Excluir">🗑</button>
                      </form>
                    </div>
                  </li>
                  <?php endforeach; ?>
                <?php endif; ?>
                <?php endforeach; ?>
              </ul>
              <?php if (!empty($topicData['id']) && isset($quizzesByTopic[(int)$topicData['id']])): ?>
                <?php foreach ($quizzesByTopic[(int)$topicData['id']] as $_cq): ?>
                <div class="lesson-quiz-topic-row">
                  <span class="lesson-quiz-topic-icon">📝</span>
                  <div class="lesson-info" style="flex:1">
                    <span class="lesson-title"><?= htmlspecialchars($_cq['title']) ?></span>
                    <span class="lesson-dur-row">
                      <span class="lesson-dur-badge lesson-quiz-meta-badge">
                        mín. <?= number_format((float)$_cq['min_score'], 0) ?>% &middot; Após tópico
                      </span>
                    </span>
                  </div>
                  <div class="lesson-actions">
                    <a href="quizzes.php?action=questions&amp;id=<?= $_cq['id'] ?>" class="btn btn-sm btn-secondary" title="Questões">❓</a>
                    <a href="quizzes.php?action=edit&amp;id=<?= $_cq['id'] ?>&amp;course_id=<?= $id ?>" class="btn btn-sm" title="Editar">✏️</a>
                    <form method="post" action="quizzes.php?action=delete&amp;id=<?= $_cq['id'] ?>" style="display:inline"
                          onsubmit="return confirm('Excluir questionário?')">
                      <input type="hidden" name="csrf" value="<?= $csrf ?>">
                      <input type="hidden" name="quiz_id" value="<?= $_cq['id'] ?>">
                      <button class="btn btn-sm btn-danger" title="Excluir">🗑</button>
                    </form>
                  </div>
                </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div><!-- .topic-group -->
            <?php endforeach; ?>
          </div>
          <?php if (!empty($endOfCourseQuizzes)): ?>
          <div class="end-course-quiz-section">
            <div class="lesson-quiz-eoc-header">🏁 Questionário de finalização do curso</div>
            <?php foreach ($endOfCourseQuizzes as $_cq): ?>
            <div class="lesson-quiz-topic-row">
              <span class="lesson-quiz-topic-icon">📝</span>
              <div class="lesson-info" style="flex:1">
                <span class="lesson-title"><?= htmlspecialchars($_cq['title']) ?></span>
                <span class="lesson-dur-row">
                  <span class="lesson-dur-badge lesson-quiz-meta-badge">
                    mín. <?= number_format((float)$_cq['min_score'], 0) ?>% &middot; Final do curso
                  </span>
                </span>
              </div>
              <div class="lesson-actions">
                <a href="quizzes.php?action=questions&amp;id=<?= $_cq['id'] ?>" class="btn btn-sm btn-secondary" title="Questões">❓</a>
                <a href="quizzes.php?action=edit&amp;id=<?= $_cq['id'] ?>&amp;course_id=<?= $id ?>" class="btn btn-sm" title="Editar">✏️</a>
                <form method="post" action="quizzes.php?action=delete&amp;id=<?= $_cq['id'] ?>" style="display:inline"
                      onsubmit="return confirm('Excluir questionário?')">
                  <input type="hidden" name="csrf" value="<?= $csrf ?>">
                  <input type="hidden" name="quiz_id" value="<?= $_cq['id'] ?>">
                  <button class="btn btn-sm btn-danger" title="Excluir">🗑</button>
                </form>
              </div>
            </div>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>
          <?php else: ?>
          <div style="padding:3rem;text-align:center;color:#64748b">
            <div style="font-size:3rem;margin-bottom:1rem">📂</div>
            <p>Nenhuma aula ainda.</p>
            <p>Clique em <strong>"Sincronizar aulas do Drive"</strong> para importar automaticamente.</p>
          </div>
          <?php endif; ?>
          </form><!-- #bulkDeleteForm -->
        </div>
      </div>
    </div><!-- .lessons-layout -->

    <div style="margin-top:1rem;display:flex;gap:.75rem">
      <a href="courses.php?action=edit&id=<?= $id ?>" class="btn">← Editar curso</a>
      <a href="<?= APP_URL ?>/course.php?slug=<?= urlencode($course['slug']) ?>" target="_blank" class="btn btn-secondary">🌐 Ver página do curso</a>
    </div>

    <script>
    const COURSE_ID = <?= $id ?>;
    const CSRF      = '<?= $csrf ?>';

    // ── Seleção em massa ──────────────────────────────────────────────
    function toggleSelectAll(master) {
        document.querySelectorAll('.lesson-bulk-cb').forEach(cb => { cb.checked = master.checked; });
        onCbChange();
    }
    function onCbChange() {
        const total   = document.querySelectorAll('.lesson-bulk-cb').length;
        const checked = document.querySelectorAll('.lesson-bulk-cb:checked').length;
        const btn     = document.getElementById('bulkDeleteBtn');
        const master  = document.getElementById('selectAllLessons');
        if (btn)    btn.style.display = checked > 0 ? '' : 'none';
        if (master) master.indeterminate = checked > 0 && checked < total;
        if (master) master.checked = checked === total && total > 0;
    }
    function submitBulkDelete() {
        const checked = document.querySelectorAll('.lesson-bulk-cb:checked').length;
        if (!checked) return;
        if (!confirm(`Excluir ${checked} aula(s) selecionada(s)? Esta ação não pode ser desfeita.`)) return;
        document.getElementById('bulkDeleteForm').submit();
    }

    // ── Atribuir tópico via AJAX ──────────────────────────────────────────
    function assignTopic(sel) {
        const lessonId = sel.dataset.lesson;
        const topicId  = sel.value;
        const form = new FormData();
        form.append('csrf', CSRF);
        form.append('lesson_id', lessonId);
        form.append('topic_id', topicId);
        fetch(`courses.php?action=assign_topic&id=${COURSE_ID}`, {method:'POST', body:form})
            .then(r => r.json())
            .then(d => { if (d.ok) { sel.closest('.topic-group').querySelectorAll('.lesson-num'); location.reload(); }});
    }

    // ── Editar tópico inline ──────────────────────────────────────────────
    function editTopic(topicId, btn) {
        const span  = document.getElementById('tmt-' + topicId);
        const input = document.getElementById('topicTitleInput');
        const hidId = document.getElementById('editTopicId');
        const saveBtn = document.getElementById('topicSaveBtn');
        const cancelBtn = document.getElementById('topicCancelBtn');
        input.value      = span.textContent.trim();
        hidId.value      = topicId;
        saveBtn.textContent = '💾 Salvar';
        cancelBtn.style.display = 'inline-flex';
        input.focus();
        input.select();
    }
    function cancelTopicEdit() {
        document.getElementById('editTopicId').value   = '0';
        document.getElementById('topicTitleInput').value = '';
        document.getElementById('topicSaveBtn').textContent = '+ Criar';
        document.getElementById('topicCancelBtn').style.display = 'none';
    }

    // ── Toggles por aula (prevent_seek / force_sequential / require_watch) ──
    function toggleSetting(btn) {
        const wrap     = btn.closest('.lesson-settings');
        const lessonId = wrap.dataset.id;
        const field    = btn.dataset.field;

        // Lê estado atual dos três botões deste item
        const allBtns = wrap.querySelectorAll('.lesson-toggle-btn');
        let preventSeek     = 0;
        let forceSequential = 0;
        let requireWatch    = 0;
        allBtns.forEach(b => {
            const val = b === btn ? (b.classList.contains('active') ? 0 : 1) : (b.classList.contains('active') ? 1 : 0);
            if (b.dataset.field === 'prevent_seek')     preventSeek     = val;
            if (b.dataset.field === 'force_sequential') forceSequential = val;
            if (b.dataset.field === 'require_watch')    requireWatch    = val;
        });

        const form = new FormData();
        form.append('csrf', CSRF);
        form.append('lesson_id',        lessonId);
        form.append('prevent_seek',     preventSeek);
        form.append('force_sequential', forceSequential);
        form.append('require_watch',    requireWatch);

        const titles = {
            prevent_seek:     ['✅ Avanço bloqueado — clique para desativar',          'Bloquear avanço do vídeo (desativado)'],
            force_sequential: ['✅ Sequencial obrigatório — clique para desativar',    'Exige conclusão da aula anterior (desativado)'],
            require_watch:    ['✅ Exige 75% assistido — clique para permitir conclusão manual', 'Conclusão manual permitida — clique para exigir 75%'],
        };

        fetch(`courses.php?action=lesson_settings&id=${COURSE_ID}`, {method:'POST', body:form})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    btn.classList.toggle('active');
                    const isActive = btn.classList.contains('active');
                    btn.title = titles[field][isActive ? 0 : 1];
                }
            });
    }

    // ── Tempo estimado por aula ──────────────────────────────────────────
    function saveEstimated(input) {
        const lessonId = input.dataset.lesson;
        const minutes  = parseInt(input.value, 10) || 0;
        const form = new FormData();
        form.append('csrf', CSRF);
        form.append('lesson_id', lessonId);
        form.append('minutes',   minutes);
        fetch(`courses.php?action=lesson_estimated&id=${COURSE_ID}`, {method:'POST', body:form})
            .then(r => r.json())
            .then(d => {
                if (!d.ok) return;
                const badge = input.closest('.lesson-dur-row').querySelector('.lesson-dur-badge');
                if (!badge || badge.classList.contains('dur-auto')) return;
                if (minutes > 0) {
                    const secs = minutes * 60;
                    const h = Math.floor(secs / 3600);
                    const m = Math.floor((secs % 3600) / 60);
                    const s = secs % 60;
                    const fmt = h > 0
                        ? `${h}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`
                        : `${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
                    badge.textContent = `⏱ ~${fmt}`;
                    badge.className = 'lesson-dur-badge dur-est';
                    badge.title = 'Tempo estimado (definido manualmente)';
                } else {
                    badge.textContent = '⏱ —';
                    badge.className = 'lesson-dur-badge dur-none';
                    badge.title = '';
                }
            });
    }

    // ── Drag & drop dentro de cada tópico ────────────────────────────────
    document.querySelectorAll('.lesson-sublist').forEach(list => {
        let dragging = null;
        list.querySelectorAll('.lesson-item').forEach(item => {
            item.draggable = true;
            item.addEventListener('dragstart', () => { dragging = item; item.classList.add('dragging'); });
            item.addEventListener('dragend',   () => {
                item.classList.remove('dragging');
                dragging = null;
                saveOrder(list);
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                const after = getDragAfter(list, e.clientY);
                if (!after) list.appendChild(dragging);
                else list.insertBefore(dragging, after);
            });
        });
        function getDragAfter(container, y) {
            return [...container.querySelectorAll('.lesson-item:not(.dragging)')]
                .reduce((closest, child) => {
                    const box = child.getBoundingClientRect();
                    const offset = y - box.top - box.height / 2;
                    return (offset < 0 && offset > (closest.offset ?? -Infinity)) ? {offset, element: child} : closest;
                }, {}).element;
        }
        function saveOrder(list) {
            const items = list.querySelectorAll('.lesson-item');
            items.forEach((item, idx) => { item.querySelector('.lesson-num').textContent = idx + 1; });
            const data = new FormData();
            items.forEach((item, idx) => data.append(`order[${item.dataset.id}]`, idx + 1));
            data.append('csrf', CSRF);
            fetch(`courses.php?action=reorder&id=${COURSE_ID}`, {method:'POST', body:data});
        }
    });

    // ── Drag & drop dos tópicos ───────────────────────────────────────────
    const topicManageList = document.getElementById('topicManageList');
    if (topicManageList) {
        let dragging = null;
        topicManageList.querySelectorAll('.topic-manage-item').forEach(item => {
            item.draggable = true;
            item.addEventListener('dragstart', () => { dragging = item; item.classList.add('dragging'); });
            item.addEventListener('dragend',   () => {
                item.classList.remove('dragging');
                dragging = null;
                const data = new FormData();
                topicManageList.querySelectorAll('.topic-manage-item').forEach((it, idx) => {
                    data.append(`order[${it.dataset.id}]`, idx + 1);
                });
                data.append('csrf', CSRF);
                fetch(`courses.php?action=reorder_topic&id=${COURSE_ID}`, {method:'POST', body:data})
                    .then(() => location.reload());
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                const after = [...topicManageList.querySelectorAll('.topic-manage-item:not(.dragging)')]
                    .reduce((closest, child) => {
                        const box = child.getBoundingClientRect();
                        const offset = e.clientY - box.top - box.height / 2;
                        return (offset < 0 && offset > (closest.offset ?? -Infinity)) ? {offset, element: child} : closest;
                    }, {}).element;
                if (!after) topicManageList.appendChild(dragging);
                else topicManageList.insertBefore(dragging, after);
            });
        });
    }
    </script>
    <?php
    adminFooter();
    exit;
}
