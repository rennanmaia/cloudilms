<?php
/**
 * CloudiLMS – Gestão global de matrículas
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/../includes/course.php';
require_once __DIR__ . '/../includes/activity_log.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();

$db     = Database::getConnection();
$cModel = new CourseModel();

if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

$message = $error = '';

// ── POST actions ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['csrf'] ?? '') !== $csrf) {
        $error = 'Token inválido.';
    } else {
        $pAction = $_POST['action'] ?? '';

        if ($pAction === 'enroll') {
            $uId = (int)($_POST['user_id']   ?? 0);
            $cId = (int)($_POST['course_id'] ?? 0);
            if ($uId > 0 && $cId > 0) {
                if (!$cModel->isEnrolled($uId, $cId)) {
                    $cModel->enroll($uId, $cId);
                    $course = $cModel->getCourseById($cId);
                    ActivityLog::record('course_enroll', [
                        'user_id'      => $uId,
                        'entity_type'  => 'course',
                        'entity_id'    => $cId,
                        'entity_title' => $course['title'] ?? '',
                        'meta'         => ['enrolled_by_admin' => (int)$_SESSION['user_id']],
                    ]);
                    header('Location: enrollments.php?msg=' . urlencode('Matrícula realizada com sucesso.'));
                    exit;
                } else {
                    $error = 'Aluno já matriculado neste curso.';
                }
            } else {
                $error = 'Selecione o aluno e o curso.';
            }
        }

        if ($pAction === 'cancel') {
            $uId = (int)($_POST['user_id']   ?? 0);
            $cId = (int)($_POST['course_id'] ?? 0);
            if ($uId > 0 && $cId > 0) {
                $course = $cModel->getCourseById($cId);
                $cModel->cancelEnrollment($uId, $cId);
                ActivityLog::record('course_unenroll', [
                    'user_id'      => $uId,
                    'entity_type'  => 'course',
                    'entity_id'    => $cId,
                    'entity_title' => $course['title'] ?? '',
                    'meta'         => ['cancelled_by_admin' => (int)$_SESSION['user_id']],
                ]);
                $qs = array_filter([
                    'search'    => $_POST['_search']    ?? '',
                    'course_id' => $_POST['_course_id'] ?? '',
                    'user_id'   => $_POST['_user_id']   ?? '',
                    'p'         => $_POST['_p']         ?? '',
                    'msg'       => 'Matrícula cancelada.',
                ], fn($v) => $v !== '' && $v !== '0');
                header('Location: enrollments.php?' . http_build_query($qs));
                exit;
            } else {
                $error = 'Dados inválidos.';
            }
        }
    }
}

if (!$message && !empty($_GET['msg'])) $message = htmlspecialchars(trim($_GET['msg']));

// ── Filter inputs ─────────────────────────────────────────────────────────────
$search       = trim($_GET['search']    ?? '');
$filterCourse = (int)($_GET['course_id'] ?? 0);
$filterUser   = (int)($_GET['user_id']   ?? 0);
$perPage      = 50;
$page         = max(1, (int)($_GET['p'] ?? 1));

// ── Stats (overall, unfiltered) ───────────────────────────────────────────────
$stats = $db->query(
    'SELECT
       (SELECT COUNT(*)                 FROM enrollments) AS total,
       (SELECT COUNT(DISTINCT user_id)  FROM enrollments) AS students,
       (SELECT COUNT(DISTINCT course_id)FROM enrollments) AS courses,
       (SELECT COUNT(*)                 FROM certificates) AS certs'
)->fetch();

// ── Build WHERE ───────────────────────────────────────────────────────────────
$conds  = [];
$params = [];

if ($search !== '') {
    $conds[]           = '(u.name LIKE :search OR u.email LIKE :search OR c.title LIKE :search)';
    $params[':search'] = '%' . $search . '%';
}
if ($filterCourse > 0) {
    $conds[]    = 'c.id = :cid';
    $params[':cid'] = $filterCourse;
}
if ($filterUser > 0) {
    $conds[]    = 'u.id = :uid';
    $params[':uid'] = $filterUser;
}
$where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';

// Count for pagination
$cnt = $db->prepare(
    "SELECT COUNT(DISTINCT e.id)
     FROM enrollments e
     JOIN users u   ON u.id  = e.user_id
     JOIN courses c ON c.id  = e.course_id
     {$where}"
);
$cnt->execute($params);
$total      = (int)$cnt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

// Data
$dataStmt = $db->prepare(
    "SELECT e.id AS enrollment_id, e.user_id, e.course_id, e.enrolled_at,
            u.name  AS student_name,  u.email AS student_email,
            c.title AS course_title,  c.slug  AS course_slug,
            (SELECT COUNT(*) FROM lessons l
             WHERE l.course_id = c.id) AS total_lessons,
            (SELECT COUNT(*) FROM progress p
             WHERE p.user_id = e.user_id AND p.course_id = c.id AND p.completed = 1) AS done_lessons,
            (SELECT cert_code FROM certificates
             WHERE user_id = e.user_id AND course_id = e.course_id LIMIT 1) AS cert_code
     FROM enrollments e
     JOIN users u   ON u.id  = e.user_id
     JOIN courses c ON c.id  = e.course_id
     {$where}
     ORDER BY e.enrolled_at DESC
     LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $dataStmt->bindValue($k, $v);
$dataStmt->bindValue(':lim', $perPage, PDO::PARAM_INT);
$dataStmt->bindValue(':off', $offset,  PDO::PARAM_INT);
$dataStmt->execute();
$enrollments = $dataStmt->fetchAll();

// Data for form selects
$students   = $db->query("SELECT id, name, email FROM users WHERE role='student' AND active=1 ORDER BY name ASC")->fetchAll();
$allCourses = $db->query("SELECT id, title FROM courses ORDER BY title ASC")->fetchAll();

// Name of filtered user (if any)
$filterUserName = '';
if ($filterUser > 0) {
    $fu = $db->prepare('SELECT name FROM users WHERE id = ?');
    $fu->execute([$filterUser]);
    $filterUserName = (string)($fu->fetchColumn() ?: '');
}

// Pagination base URL (preserves active filters)
$fArr    = array_filter(['search' => $search, 'course_id' => $filterCourse ?: '', 'user_id' => $filterUser ?: ''], fn($v) => $v !== '' && $v !== '0');
$baseUrl = 'enrollments.php?' . ($fArr ? http_build_query($fArr) . '&' : '');

adminHeader('Matrículas', 'enrollments');
?>

<?php if ($message): ?><div class="alert alert-success"><?= $message ?></div><?php endif; ?>
<?php if ($error):   ?><div class="alert alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>

<!-- ── Stats ─────────────────────────────────────────────────────────────── -->
<div class="stats-grid" style="grid-template-columns:repeat(auto-fill,minmax(160px,1fr));margin-bottom:1.5rem">
  <div class="stat-card">
    <span class="stat-icon">📝</span>
    <div><div class="stat-value"><?= (int)$stats['total'] ?></div><div class="stat-label">Total de matrículas</div></div>
  </div>
  <div class="stat-card">
    <span class="stat-icon">👥</span>
    <div><div class="stat-value"><?= (int)$stats['students'] ?></div><div class="stat-label">Alunos matriculados</div></div>
  </div>
  <div class="stat-card">
    <span class="stat-icon">🎓</span>
    <div><div class="stat-value"><?= (int)$stats['courses'] ?></div><div class="stat-label">Cursos com alunos</div></div>
  </div>
  <div class="stat-card">
    <span class="stat-icon">📜</span>
    <div><div class="stat-value"><?= (int)$stats['certs'] ?></div><div class="stat-label">Certificados emitidos</div></div>
  </div>
</div>

<!-- ── Quick enroll ──────────────────────────────────────────────────────── -->
<div class="card mb-2">
  <div class="card-header">
    <h2>➕ Nova matrícula</h2>
    <button type="button" class="btn btn-sm"
            onclick="var b=document.getElementById('enroll-form-body');b.classList.toggle('enroll-hidden');this.textContent=b.classList.contains('enroll-hidden')?'Expandir':'Recolher'">
      Expandir
    </button>
  </div>
  <div class="card-body enroll-hidden" id="enroll-form-body">
    <form method="post" action="enrollments.php"
          style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
      <input type="hidden" name="csrf"   value="<?= $csrf ?>">
      <input type="hidden" name="action" value="enroll">
      <div class="form-group" style="margin:0;flex:1;min-width:220px">
        <label>Aluno</label>
        <select name="user_id" class="form-control" required>
          <option value="">— selecione —</option>
          <?php foreach ($students as $s): ?>
          <option value="<?= (int)$s['id'] ?>">
            <?= htmlspecialchars($s['name']) ?> &lt;<?= htmlspecialchars($s['email']) ?>&gt;
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:220px">
        <label>Curso</label>
        <select name="course_id" class="form-control" required>
          <option value="">— selecione —</option>
          <?php foreach ($allCourses as $c): ?>
          <option value="<?= (int)$c['id'] ?>"><?= htmlspecialchars($c['title']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit" class="btn btn-primary">Matricular</button>
    </form>
  </div>
</div>

<!-- ── Filters ───────────────────────────────────────────────────────────── -->
<div class="card mb-2">
  <div class="card-body" style="padding:.875rem 1.25rem">
    <form method="get" action="enrollments.php"
          style="display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end">
      <div class="form-group" style="margin:0;flex:2;min-width:180px">
        <label>Buscar</label>
        <input type="text" name="search" value="<?= htmlspecialchars($search) ?>"
               class="form-control" placeholder="Nome, e-mail ou curso…">
      </div>
      <div class="form-group" style="margin:0;flex:1;min-width:160px">
        <label>Filtrar por curso</label>
        <select name="course_id" class="form-control">
          <option value="">Todos os cursos</option>
          <?php foreach ($allCourses as $c): ?>
          <option value="<?= (int)$c['id'] ?>" <?= $filterCourse === (int)$c['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($c['title']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="display:flex;gap:.5rem;padding-bottom:1px">
        <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
        <a href="enrollments.php" class="btn">✕ Limpar</a>
      </div>
    </form>
  </div>
</div>

<!-- ── Enrollments table ─────────────────────────────────────────────────── -->
<div class="card">
  <div class="card-header">
    <h2>
      📋 Matrículas
      <?php if ($total === 0): ?>
        (0)
      <?php elseif ($search || $filterCourse || $filterUser): ?>
        (<?= $total ?> filtradas)
      <?php else: ?>
        (<?= $total ?>)
      <?php endif; ?>
      <?php if ($filterUserName !== ''): ?>
        <span class="badge badge-info" style="font-size:.75rem;margin-left:.5rem">
          👤 <?= htmlspecialchars($filterUserName) ?>
        </span>
      <?php endif; ?>
    </h2>
    <?php if ($filterUser || $filterCourse || $search): ?>
    <a href="enrollments.php" class="btn btn-sm btn-secondary">✕ Limpar filtros</a>
    <?php endif; ?>
  </div>

  <?php if ($enrollments): ?>
  <table class="table">
    <thead>
      <tr>
        <th>Aluno</th>
        <th>Curso</th>
        <th>Progresso</th>
        <th>Certificado</th>
        <th>Matriculado em</th>
        <th>Ações</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($enrollments as $e):
        $tl  = (int)$e['total_lessons'];
        $dl  = (int)$e['done_lessons'];
        $pct = $tl > 0 ? round($dl / $tl * 100) : 0;
      ?>
      <tr>
        <td>
          <div style="font-weight:500"><?= htmlspecialchars($e['student_name']) ?></div>
          <div style="font-size:.775rem;color:var(--text3)"><?= htmlspecialchars($e['student_email']) ?></div>
        </td>
        <td>
          <a href="<?= htmlspecialchars(APP_URL) ?>/course.php?slug=<?= urlencode($e['course_slug']) ?>"
             target="_blank" style="font-size:.875rem">
            <?= htmlspecialchars($e['course_title']) ?>
          </a>
        </td>
        <td>
          <div class="enroll-progress-wrap">
            <div class="enroll-progress-bar">
              <div class="enroll-progress-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <span class="enroll-progress-label"><?= $dl ?>/<?= $tl ?> (<?= $pct ?>%)</span>
          </div>
        </td>
        <td>
          <?php if ($e['cert_code']): ?>
          <a href="<?= htmlspecialchars(APP_URL) ?>/certificate.php?code=<?= htmlspecialchars($e['cert_code']) ?>"
             target="_blank">
            <span class="badge badge-success">📜 Emitido</span>
          </a>
          <?php else: ?>
          <span style="font-size:.8rem;color:var(--text3)">—</span>
          <?php endif; ?>
        </td>
        <td style="font-size:.8rem;color:var(--text3);white-space:nowrap">
          <?= date('d/m/Y', strtotime($e['enrolled_at'])) ?>
        </td>
        <td class="actions">
          <a href="users.php?action=edit&id=<?= (int)$e['user_id'] ?>"
             class="btn btn-sm" title="Perfil do aluno">👤</a>
          <a href="audit.php?user=<?= (int)$e['user_id'] ?>"
             class="btn btn-sm btn-secondary" title="Auditoria do aluno">🔎</a>
          <form method="post" action="enrollments.php" style="display:inline"
                onsubmit="return confirm('Cancelar matrícula de\n&quot;<?= htmlspecialchars(addslashes($e['student_name']), ENT_QUOTES) ?>&quot;\nem\n&quot;<?= htmlspecialchars(addslashes($e['course_title']), ENT_QUOTES) ?>&quot;?\n\nHistórico de progresso e certificado serão apagados.')">
            <input type="hidden" name="csrf"      value="<?= $csrf ?>">
            <input type="hidden" name="action"    value="cancel">
            <input type="hidden" name="user_id"   value="<?= (int)$e['user_id'] ?>">
            <input type="hidden" name="course_id" value="<?= (int)$e['course_id'] ?>">
            <!-- Preserve current filters for redirect -->
            <input type="hidden" name="_search"    value="<?= htmlspecialchars($search) ?>">
            <input type="hidden" name="_course_id" value="<?= $filterCourse ?>">
            <input type="hidden" name="_user_id"   value="<?= $filterUser ?>">
            <input type="hidden" name="_p"         value="<?= $page ?>">
            <button class="btn btn-sm btn-danger" title="Cancelar matrícula">✕</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <?php if ($totalPages > 1): ?>
  <div class="card-body" style="padding-top:.75rem;border-top:1px solid var(--bg3)">
    <div class="enroll-pagination">
      <?php if ($page > 1): ?>
        <a href="<?= $baseUrl ?>p=1" class="btn btn-sm btn-secondary">« Primeira</a>
      <?php endif; ?>
      <?php for ($i = max(1, $page - 3); $i <= min($totalPages, $page + 3); $i++): ?>
        <a href="<?= $baseUrl ?>p=<?= $i ?>"
           class="btn btn-sm <?= $i === $page ? 'btn-primary' : 'btn-secondary' ?>"><?= $i ?></a>
      <?php endfor; ?>
      <?php if ($page < $totalPages): ?>
        <a href="<?= $baseUrl ?>p=<?= $totalPages ?>" class="btn btn-sm btn-secondary">Última »</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php else: ?>
  <div class="card-body" style="color:var(--text3)">
    <?= ($search || $filterCourse || $filterUser)
        ? 'Nenhuma matrícula encontrada para os filtros aplicados.'
        : 'Nenhuma matrícula registrada ainda.' ?>
  </div>
  <?php endif; ?>
</div>

<?php adminFooter(); ?>
