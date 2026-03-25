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
                    'title'             => trim($_POST['title'] ?? ''),
                    'description'       => trim($_POST['description'] ?? ''),
                    'thumbnail'         => trim($_POST['thumbnail'] ?? ''),
                    'gdrive_folder_id'  => $folderId,
                    'gdrive_folder_url' => $folderUrl,
                    'published'         => isset($_POST['published']) ? 1 : 0,
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

                $files = $gdrive->getFolderFiles($course['gdrive_folder_id']);
                if (empty($files)) {
                    $error = 'Nenhum vídeo encontrado. Verifique se a pasta é pública e a API Key está configurada.';
                    $action = 'lessons';
                    break;
                }
                $added = $model->syncLessons($id, $files);
                $message = "Sincronização concluída! {$added} nova(s) aula(s) adicionada(s).";
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

            case 'delete_lesson':
                $lessonId = (int)($_POST['lesson_id'] ?? 0);
                if ($lessonId) $model->deleteLesson($lessonId);
                header("Location: courses.php?action=lessons&id={$id}&msg=" . urlencode('Aula removida.'));
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

          <div style="display:flex;gap:1rem;margin-top:1.5rem">
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

if ($action === 'lessons') {
    $course = $model->getCourseById($id);
    if (!$course) { header('Location: courses.php'); exit; }

    $lessons = $model->getLessonsByCourse($id);
    adminHeader('Aulas: ' . $course['title'], 'courses');
    ?>
    <?php if ($error): ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

    <div class="card mb-2">
      <div class="card-header">
        <div>
          <h2>Pasta do Google Drive</h2>
          <small style="color:#94a3b8">ID: <?= htmlspecialchars($course['gdrive_folder_id']) ?></small>
        </div>
        <div style="display:flex;gap:.75rem">
          <a href="<?= htmlspecialchars($course['gdrive_folder_url']) ?>" target="_blank" class="btn btn-sm btn-secondary">🔗 Abrir no Drive</a>
          <form method="post" action="courses.php?action=sync&id=<?= $id ?>">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <button class="btn btn-primary">🔄 Sincronizar aulas do Drive</button>
          </form>
        </div>
      </div>
    </div>

    <div class="card">
      <div class="card-header">
        <h2>Aulas (<?= count($lessons) ?>)</h2>
        <small style="color:#94a3b8">Arraste para reordenar</small>
      </div>
      <?php if ($lessons): ?>
      <ul class="lesson-list" id="lessonList">
        <?php foreach ($lessons as $i => $l): ?>
        <li class="lesson-item" data-id="<?= $l['id'] ?>">
          <span class="drag-handle">⠿</span>
          <span class="lesson-num"><?= $i + 1 ?></span>
          <div class="lesson-info">
            <span class="lesson-title"><?= htmlspecialchars($l['title']) ?></span>
            <?php if ($l['duration_seconds']): ?>
            <span class="lesson-duration"><?= gmdate('H:i:s', $l['duration_seconds']) ?></span>
            <?php endif; ?>
          </div>
          <div class="lesson-actions">
            <a href="<?= APP_URL ?>/watch.php?lesson=<?= $l['id'] ?>" target="_blank" class="btn btn-sm btn-secondary">▶ Preview</a>
            <form method="post" action="courses.php?action=delete_lesson&id=<?= $id ?>" style="display:inline" onsubmit="return confirm('Remover esta aula?')">
              <input type="hidden" name="csrf" value="<?= $csrf ?>">
              <input type="hidden" name="lesson_id" value="<?= $l['id'] ?>">
              <button class="btn btn-sm btn-danger">🗑</button>
            </form>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php else: ?>
      <div style="padding:3rem;text-align:center;color:#64748b">
        <div style="font-size:3rem;margin-bottom:1rem">📂</div>
        <p>Nenhuma aula ainda.</p>
        <p>Clique em <strong>"Sincronizar aulas do Drive"</strong> para importar automaticamente os vídeos da pasta.</p>
      </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:1rem">
      <a href="courses.php?action=edit&id=<?= $id ?>" class="btn">← Editar curso</a>
      <a href="<?= APP_URL ?>/course.php?slug=<?= urlencode($course['slug']) ?>" target="_blank" class="btn btn-secondary">🌐 Ver página do curso</a>
    </div>

    <script>
    // Drag and drop para reordenar
    const list = document.getElementById('lessonList');
    if (list) {
        let dragging = null;
        list.querySelectorAll('.lesson-item').forEach(item => {
            item.draggable = true;
            item.addEventListener('dragstart', e => { dragging = item; item.classList.add('dragging'); });
            item.addEventListener('dragend', () => { dragging = null; item.classList.remove('dragging'); saveOrder(); });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                const after = getDragAfter(list, e.clientY);
                if (!after) list.appendChild(dragging);
                else list.insertBefore(dragging, after);
            });
        });
        function getDragAfter(container, y) {
            const items = [...container.querySelectorAll('.lesson-item:not(.dragging)')];
            return items.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                return (offset < 0 && offset > (closest.offset ?? -Infinity)) ? {offset, element: child} : closest;
            }, {}).element;
        }
        function saveOrder() {
            const items = list.querySelectorAll('.lesson-item');
            items.forEach((item, idx) => { item.querySelector('.lesson-num').textContent = idx + 1; });
            const data = new FormData();
            items.forEach((item, idx) => data.append(`order[${item.dataset.id}]`, idx + 1));
            data.append('csrf', '<?= $csrf ?>');
            fetch('courses.php?action=reorder&id=<?= $id ?>', {method:'POST', body:data});
        }
    }
    </script>
    <?php
    adminFooter();
    exit;
}
