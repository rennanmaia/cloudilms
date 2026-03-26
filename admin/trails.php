<?php
/**
 * CloudiLMS - Gerenciamento de Trilhas (Admin)
 * Ações: list | new | edit | save | delete | courses | add_course | remove_course | reorder_courses
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/trail.php';
require_once __DIR__ . '/../includes/course.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();

$model  = new TrailModel();
$cModel = new CourseModel();
$action = $_GET['action'] ?? 'list';
$id     = (int)($_GET['id'] ?? 0);
$message = $error = '';

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

// ── POST handlers ─────────────────────────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        // AJAX responses
        if (in_array($action, ['remove_course', 'reorder_courses', 'add_course'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'err' => 'csrf']);
            exit;
        }
        $error = 'Token inválido.';
    } else {
        switch ($action) {
            case 'save':
                $title = trim($_POST['title'] ?? '');
                $desc  = trim($_POST['description'] ?? '');
                if (!$title) { $error = 'Título é obrigatório.'; break; }
                if ($id) {
                    $model->updateTrail($id, ['title' => $title, 'description' => $desc]);
                    header('Location: trails.php?action=edit&id=' . $id . '&msg=' . urlencode('Trilha atualizada.'));
                } else {
                    $newId = $model->createTrail(['title' => $title, 'description' => $desc]);
                    header('Location: trails.php?action=courses&id=' . $newId . '&msg=' . urlencode('Trilha criada. Adicione os cursos abaixo.'));
                }
                exit;

            case 'delete':
                if ($id) $model->deleteTrail($id);
                header('Location: trails.php?msg=' . urlencode('Trilha excluída.'));
                exit;

            case 'add_course':
                $courseId = (int)($_POST['course_id'] ?? 0);
                if ($id && $courseId) $model->addCourseToTrail($id, $courseId);
                header('Location: trails.php?action=courses&id=' . $id . '&msg=' . urlencode('Curso adicionado.'));
                exit;

            case 'remove_course':
                $courseId = (int)($_POST['course_id'] ?? 0);
                if ($id && $courseId) $model->removeCourseFromTrail($id, $courseId);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;

            case 'reorder_courses':
                $ids = json_decode(file_get_contents('php://input'), true)['ids'] ?? [];
                if ($id && $ids) $model->reorderTrailCourses($id, $ids);
                header('Content-Type: application/json');
                echo json_encode(['ok' => true]);
                exit;
        }
    }
}

if (!$message && !empty($_GET['msg'])) $message = htmlspecialchars($_GET['msg']);

// ── LIST ──────────────────────────────────────────────────────────────────────

if ($action === 'list') {
    $trails = $model->getAllTrails();
    adminHeader('Trilhas de Aprendizagem', 'trails');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header">
        <h2>Trilhas (<?= count($trails) ?>)</h2>
        <a href="trails.php?action=new" class="btn btn-primary">+ Nova trilha</a>
      </div>
      <?php if ($trails): ?>
      <table class="table">
        <thead>
          <tr>
            <th>Nome</th>
            <th>Cursos</th>
            <th>Alunos</th>
            <th>Criada em</th>
            <th>Ações</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($trails as $t): ?>
          <tr>
            <td>
              <strong><?= htmlspecialchars($t['title']) ?></strong>
              <?php if ($t['description']): ?>
              <div style="font-size:.8rem;color:var(--text3);margin-top:.2rem"><?= htmlspecialchars(mb_strimwidth($t['description'], 0, 80, '…')) ?></div>
              <?php endif; ?>
            </td>
            <td><?= $t['course_count'] ?></td>
            <td><?= $t['user_count'] ?></td>
            <td style="font-size:.8rem;color:var(--text3)"><?= date('d/m/Y', strtotime($t['created_at'])) ?></td>
            <td class="actions">
              <a href="trails.php?action=edit&id=<?= $t['id'] ?>" class="btn btn-sm">✏️ Editar</a>
              <a href="trails.php?action=courses&id=<?= $t['id'] ?>" class="btn btn-sm btn-secondary">📚 Cursos</a>
              <form method="post" action="trails.php?action=delete&id=<?= $t['id'] ?>" style="display:inline"
                    onsubmit="return confirm('Excluir esta trilha? Os usuários perderão as atribuições.')">
                <input type="hidden" name="csrf" value="<?= $csrf ?>">
                <button class="btn btn-sm btn-danger">🗑</button>
              </form>
            </td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php else: ?>
      <div class="card-body" style="text-align:center;padding:3rem;color:var(--text3)">
        <div style="font-size:2.5rem;margin-bottom:.5rem">🗺️</div>
        <p>Nenhuma trilha criada ainda.</p>
        <a href="trails.php?action=new" class="btn btn-primary" style="margin-top:1rem">+ Criar primeira trilha</a>
      </div>
      <?php endif; ?>
    </div>
    <?php adminFooter(); exit;
}

// ── NEW / EDIT ────────────────────────────────────────────────────────────────

if ($action === 'new' || $action === 'edit') {
    $trail = $id ? $model->getTrailById($id) : null;
    adminHeader($id ? 'Editar Trilha' : 'Nova Trilha', 'trails');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
    <?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
    <div class="card">
      <div class="card-header">
        <h2><?= $id ? 'Editar Trilha' : 'Nova Trilha' ?></h2>
        <a href="trails.php" class="btn btn-sm">← Voltar</a>
      </div>
      <div class="card-body">
        <form method="post" action="trails.php?action=save<?= $id ? "&id={$id}" : '' ?>">
          <input type="hidden" name="csrf" value="<?= $csrf ?>">
          <div class="form-group">
            <label>Título *</label>
            <input type="text" name="title" value="<?= htmlspecialchars($trail['title'] ?? '') ?>"
                   required class="form-control" placeholder="Ex: Trilha de Desenvolvimento Web">
          </div>
          <div class="form-group">
            <label>Descrição</label>
            <textarea name="description" class="form-control" rows="4"
                      placeholder="Descreva o objetivo desta trilha de aprendizagem…"><?= htmlspecialchars($trail['description'] ?? '') ?></textarea>
          </div>
          <div style="display:flex;gap:.75rem;align-items:center">
            <button type="submit" class="btn btn-primary">💾 Salvar</button>
            <a href="trails.php" class="btn">Cancelar</a>
            <?php if ($id): ?>
            <a href="trails.php?action=courses&id=<?= $id ?>" class="btn btn-secondary">📚 Gerenciar cursos →</a>
            <?php endif; ?>
          </div>
        </form>
      </div>
    </div>
    <?php adminFooter(); exit;
}

// ── COURSES (gerenciar cursos da trilha) ──────────────────────────────────────

if ($action === 'courses') {
    $trail     = $id ? $model->getTrailById($id) : null;
    if (!$trail) { header('Location: trails.php'); exit; }

    $courses    = $model->getCoursesByTrail($id);
    $available  = $model->getCoursesNotInTrail($id);

    adminHeader('Trilha: ' . htmlspecialchars($trail['title']), 'trails');
    ?>
    <?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>

    <div style="display:flex;gap:1.5rem;align-items:center;margin-bottom:1.25rem">
      <a href="trails.php" class="btn btn-sm">← Trilhas</a>
      <h2 style="font-size:1.05rem;margin:0">📚 <?= htmlspecialchars($trail['title']) ?></h2>
    </div>

    <div class="trail-courses-layout">
      <!-- Lista de cursos -->
      <div class="card">
        <div class="card-header">
          <h2>Cursos na trilha (<?= count($courses) ?>)</h2>
          <span style="font-size:.8rem;color:var(--text3)">Arraste para reordenar</span>
        </div>
        <?php if ($courses): ?>
        <ul class="trail-course-list" id="trailCourseList" data-trail="<?= $id ?>">
          <?php foreach ($courses as $i => $c): ?>
          <li class="trail-course-item" data-id="<?= $c['id'] ?>">
            <span class="drag-handle">⠿</span>
            <span class="trail-course-num"><?= $i + 1 ?></span>
            <div class="trail-course-info">
              <span class="trail-course-title"><?= htmlspecialchars($c['title']) ?></span>
              <?php if ($c['description']): ?>
              <span class="trail-course-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 60, '…')) ?></span>
              <?php endif; ?>
            </div>
            <button class="btn btn-sm btn-danger trail-remove-course"
                    data-course="<?= $c['id'] ?>"
                    onclick="removeCourse(this)">✕ Remover</button>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php else: ?>
        <div class="card-body" style="text-align:center;color:var(--text3);padding:2rem">
          Nenhum curso ainda. Adicione abaixo.
        </div>
        <?php endif; ?>
      </div>

      <!-- Adicionar curso -->
      <div class="card" style="align-self:start">
        <div class="card-header"><h2>Adicionar curso</h2></div>
        <div class="card-body">
          <?php if ($available): ?>
          <form method="post" action="trails.php?action=add_course&id=<?= $id ?>">
            <input type="hidden" name="csrf" value="<?= $csrf ?>">
            <div class="form-group">
              <label>Selecione o curso</label>
              <select name="course_id" class="form-control topic-assign-select">
                <option value="">— escolha —</option>
                <?php foreach ($available as $c): ?>
                <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <button type="submit" class="btn btn-primary">+ Adicionar</button>
          </form>
          <?php else: ?>
          <p style="color:var(--text3);font-size:.9rem">Todos os cursos já estão nesta trilha.</p>
          <?php endif; ?>
        </div>
      </div>
    </div>

    <script>
    const CSRF       = <?= json_encode($csrf) ?>;
    const TRAIL_ID   = <?= $id ?>;

    // ── Remover curso (AJAX) ─────────────────────────────────────────────────
    function removeCourse(btn) {
        if (!confirm('Remover este curso da trilha?')) return;
        const courseId = btn.dataset.course;
        const form = new FormData();
        form.append('csrf', CSRF);
        form.append('course_id', courseId);
        fetch(`trails.php?action=remove_course&id=${TRAIL_ID}`, {method:'POST', body:form})
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    btn.closest('.trail-course-item').remove();
                    renumberItems();
                }
            });
    }

    function renumberItems() {
        document.querySelectorAll('#trailCourseList .trail-course-item').forEach((el, i) => {
            el.querySelector('.trail-course-num').textContent = i + 1;
        });
    }

    // ── Drag & drop reorder ──────────────────────────────────────────────────
    const list = document.getElementById('trailCourseList');
    if (list) {
        let dragging = null;
        list.querySelectorAll('.trail-course-item').forEach(item => {
            item.draggable = true;
            item.addEventListener('dragstart', () => { dragging = item; item.classList.add('dragging'); });
            item.addEventListener('dragend',   () => {
                item.classList.remove('dragging');
                dragging = null;
                saveOrder();
                renumberItems();
            });
            item.addEventListener('dragover', e => {
                e.preventDefault();
                const after = getDragAfter(list, e.clientY);
                if (after === null) list.appendChild(dragging);
                else list.insertBefore(dragging, after);
            });
        });

        function getDragAfter(container, y) {
            const items = [...container.querySelectorAll('.trail-course-item:not(.dragging)')];
            return items.reduce((closest, child) => {
                const box = child.getBoundingClientRect();
                const offset = y - box.top - box.height / 2;
                return (offset < 0 && offset > closest.offset) ? { offset, element: child } : closest;
            }, { offset: Number.NEGATIVE_INFINITY }).element ?? null;
        }

        function saveOrder() {
            const ids = [...list.querySelectorAll('.trail-course-item')].map(el => parseInt(el.dataset.id));
            fetch(`trails.php?action=reorder_courses&id=${TRAIL_ID}`, {
                method: 'POST',
                headers: {'Content-Type': 'application/json'},
                body: JSON.stringify({ids, csrf: CSRF})
            });
        }
    }
    </script>
    <?php adminFooter(); exit;
}

// Fallback
header('Location: trails.php');
exit;
