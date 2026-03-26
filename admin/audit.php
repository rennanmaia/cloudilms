<?php
/**
 * CloudiLMS - Auditoria de atividades
 * Global  → audit.php
 * Aluno   → audit.php?user=ID
 */
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();
$db = Database::getConnection();

// ── Helpers ──────────────────────────────────────────────────────────────────

function auditActionMeta(string $action): array {
    $map = [
        'login'           => ['Entrou',          'badge-success',   '🔓'],
        'login_failed'    => ['Falha de login',  'badge-danger',    '⚠️'],
        'logout'          => ['Saiu',            'badge-secondary', '🔒'],
        'page_view'       => ['Página visitada', 'badge-audit-dim', '👁'],
        'course_view'     => ['Curso aberto',    'badge-info',      '🎓'],
        'lesson_view'     => ['Aula iniciada',   'badge-warning',   '▶️'],
        'lesson_complete' => ['Aula concluída',  'badge-success',   '✅'],
        'course_enroll'      => ['Matriculou',          'badge-info',      '📝'],
        'certificate_issued' => ['Certificado emitido', 'badge-success',   '📜'],
    ];
    return $map[$action] ?? [$action, 'badge-secondary', '•'];
}

function auditTime(?int $secs): string {
    if (!$secs) return '—';
    if ($secs < 60)   return $secs . 's';
    if ($secs < 3600) return floor($secs / 60) . 'min ' . str_pad($secs % 60, 2, '0', STR_PAD_LEFT) . 's';
    return floor($secs / 3600) . 'h ' . floor(($secs % 3600) / 60) . 'min';
}

function auditPageUrl(?string $url): string {
    if (!$url) return '—';
    $path  = parse_url($url, PHP_URL_PATH) ?? '';
    $query = parse_url($url, PHP_URL_QUERY) ?? '';
    $short = $path . ($query ? '?' . $query : '');
    return htmlspecialchars($short);
}

function auditPagination(int $current, int $total, string $baseUrl): string {
    if ($total <= 1) return '';
    $from = max(1, $current - 3);
    $to   = min($total, $current + 3);
    $html = '<div class="audit-pagination">';
    if ($current > 1)   $html .= '<a href="' . $baseUrl . 'p=1" class="btn btn-sm btn-secondary">« Primeira</a>';
    for ($i = $from; $i <= $to; $i++) {
        $active = $i === $current ? 'btn-primary' : 'btn-secondary';
        $html  .= '<a href="' . $baseUrl . 'p=' . $i . '" class="btn btn-sm ' . $active . '">' . $i . '</a>';
    }
    if ($current < $total) $html .= '<a href="' . $baseUrl . 'p=' . $total . '" class="btn btn-sm btn-secondary">Última »</a>';
    $html .= '</div>';
    return $html;
}

$perPage = 50;
$page    = max(1, (int)($_GET['p'] ?? 1));
$offset  = ($page - 1) * $perPage;

// ══════════════════════════════════════ STUDENT AUDIT VIEW ════════════════════

$studentId = (int)($_GET['user'] ?? 0);

if ($studentId) {
    $student = $db->prepare('SELECT * FROM users WHERE id = ?');
    $student->execute([$studentId]);
    $student = $student->fetch();
    if (!$student) { header('Location: audit.php'); exit; }

    // Summary stats for this student
    $statsStmt = $db->prepare(
        'SELECT COUNT(*) AS events,
                COALESCE(SUM(time_on_page), 0) AS total_time,
                MAX(created_at) AS last_seen
         FROM activity_log WHERE user_id = ?'
    );
    $statsStmt->execute([$studentId]);
    $stats = $statsStmt->fetch();

    // Enrolled courses with progress
    $coursesStmt = $db->prepare(
        'SELECT c.id, c.title, c.slug, e.enrolled_at,
                COUNT(DISTINCT l.id)        AS total_lessons,
                COUNT(DISTINCT p.lesson_id) AS done_lessons,
                MAX(p.completed_at)         AS last_completion
         FROM enrollments e
         JOIN courses c        ON c.id = e.course_id
         LEFT JOIN lessons l   ON l.course_id = c.id
         LEFT JOIN progress p  ON p.lesson_id = l.id AND p.user_id = e.user_id AND p.completed = 1
         WHERE e.user_id = ?
         GROUP BY c.id, e.enrolled_at
         ORDER BY e.enrolled_at DESC'
    );
    $coursesStmt->execute([$studentId]);
    $studentCourses = $coursesStmt->fetchAll();

    // Time per lesson from JS-tracked page_view entries
    $lessonTimesStmt = $db->prepare(
        'SELECT entity_id, SUM(time_on_page) AS secs
         FROM activity_log
         WHERE user_id = ? AND entity_type = "lesson" AND action = "page_view" AND time_on_page IS NOT NULL
         GROUP BY entity_id'
    );
    $lessonTimesStmt->execute([$studentId]);
    $lessonTimeMap = array_column($lessonTimesStmt->fetchAll(), 'secs', 'entity_id');

    // Active tab
    $tab = in_array($_GET['tab'] ?? '', ['progress', 'history']) ? $_GET['tab'] : 'progress';

    // Activity history (paginated — only for history tab)
    $totalLogs  = 0;
    $totalPages = 1;
    $logs       = [];
    if ($tab === 'history') {
        $cntStmt = $db->prepare('SELECT COUNT(*) FROM activity_log WHERE user_id = ?');
        $cntStmt->execute([$studentId]);
        $totalLogs  = (int)$cntStmt->fetchColumn();
        $totalPages = max(1, (int)ceil($totalLogs / $perPage));
        $logsStmt   = $db->prepare(
            'SELECT * FROM activity_log WHERE user_id = ?
             ORDER BY created_at DESC LIMIT ? OFFSET ?'
        );
        $logsStmt->execute([$studentId, $perPage, $offset]);
        $logs = $logsStmt->fetchAll();
    }

    adminHeader('Auditoria: ' . $student['name'], 'audit');
    ?>
    <div style="margin-bottom:1.25rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
      <a href="audit.php" class="btn btn-sm btn-secondary">← Auditoria global</a>
      <a href="users.php?action=edit&id=<?= $studentId ?>" class="btn btn-sm btn-secondary">✏️ Editar usuário</a>
    </div>

    <!-- Student header card -->
    <div class="card mb-2">
      <div class="card-body audit-student-header">
        <div class="audit-avatar">👤</div>
        <div class="audit-student-info">
          <h2><?= htmlspecialchars($student['name']) ?></h2>
          <p>
            <?= htmlspecialchars($student['email']) ?> &nbsp;·&nbsp;
            <span class="badge <?= $student['role'] === 'admin' ? 'badge-info' : 'badge-secondary' ?>"><?= $student['role'] ?></span>
            &nbsp;·&nbsp; Cadastrado em <?= date('d/m/Y', strtotime($student['created_at'])) ?>
          </p>
        </div>
        <div class="audit-stats-row">
          <div class="audit-stat">
            <div class="audit-stat-val"><?= count($studentCourses) ?></div>
            <div class="audit-stat-lbl">Cursos</div>
          </div>
          <div class="audit-stat">
            <div class="audit-stat-val"><?= number_format($stats['events']) ?></div>
            <div class="audit-stat-lbl">Eventos</div>
          </div>
          <div class="audit-stat">
            <div class="audit-stat-val"><?= auditTime((int)$stats['total_time']) ?></div>
            <div class="audit-stat-lbl">Tempo total</div>
          </div>
          <div class="audit-stat">
            <div class="audit-stat-val"><?= $stats['last_seen'] ? date('d/m H:i', strtotime($stats['last_seen'])) : '—' ?></div>
            <div class="audit-stat-lbl">Último acesso</div>
          </div>
        </div>
      </div>
    </div>

    <!-- Tabs -->
    <div class="audit-tabs">
      <a href="audit.php?user=<?= $studentId ?>&tab=progress" class="audit-tab <?= $tab === 'progress' ? 'active' : '' ?>">📊 Progresso por Curso</a>
      <a href="audit.php?user=<?= $studentId ?>&tab=history"  class="audit-tab <?= $tab === 'history'  ? 'active' : '' ?>">📋 Histórico de Atividade</a>
    </div>

    <?php if ($tab === 'progress'): ?>
    <!-- ── TAB: PROGRESSO ─────────────────────────────────────────────── -->

    <?php if (empty($studentCourses)): ?>
    <div class="card"><div style="padding:2.5rem;text-align:center;color:#64748b">Aluno não matriculado em nenhum curso ainda.</div></div>

    <?php else: foreach ($studentCourses as $c):
        $pct = $c['total_lessons'] ? round($c['done_lessons'] / $c['total_lessons'] * 100) : 0;

        // Lessons with completion info
        $lessonsStmt = $db->prepare(
            'SELECT l.id, l.title, l.sort_order,
                    p.completed, p.completed_at,
                    t.title AS topic_title
             FROM lessons l
             LEFT JOIN progress p ON p.lesson_id = l.id AND p.user_id = ?
             LEFT JOIN topics  t ON t.id = l.topic_id
             WHERE l.course_id = ?
             ORDER BY COALESCE(t.sort_order, 9999), l.sort_order ASC, l.title ASC'
        );
        $lessonsStmt->execute([$studentId, $c['id']]);
        $courseLessons = $lessonsStmt->fetchAll();

        $courseTimeSecs = 0;
        foreach ($courseLessons as $cl) {
            $courseTimeSecs += (int)($lessonTimeMap[$cl['id']] ?? 0);
        }
        $completedCourses = (int)($c['done_lessons'] ?? 0);
    ?>
    <div class="card mb-2">
      <details <?= $pct > 0 ? 'open' : '' ?>>
        <summary class="course-progress-summary">
          <div class="cps-left">
            <strong class="cps-title"><?= htmlspecialchars($c['title']) ?></strong>
            <span class="cps-meta">
              Matrícula: <?= date('d/m/Y', strtotime($c['enrolled_at'])) ?>
              &nbsp;·&nbsp; ✅ <?= $c['done_lessons'] ?>/<?= $c['total_lessons'] ?> aulas
              &nbsp;·&nbsp; ⏱ <?= auditTime($courseTimeSecs) ?>
              <?php if ($c['last_completion']): ?>
              &nbsp;·&nbsp; Última ativ.: <?= date('d/m/Y H:i', strtotime($c['last_completion'])) ?>
              <?php endif; ?>
            </span>
          </div>
          <div class="cps-right">
            <div class="progress-mini-track" style="width:110px">
              <div class="progress-mini-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <span class="cps-pct" style="color:<?= $pct === 100 ? '#86efac' : '#94a3b8' ?>"><?= $pct ?>%</span>
          </div>
        </summary>
        <div class="course-details-wrap">
          <?php if ($courseLessons): ?>
          <table class="table audit-lesson-table">
            <thead>
              <tr><th>#</th><th>Aula</th><th>Tópico</th><th>Status</th><th>Concluída em</th><th>Tempo assistido</th></tr>
            </thead>
            <tbody>
              <?php foreach ($courseLessons as $i => $cl): ?>
              <tr class="<?= $cl['completed'] ? 'row-done' : '' ?>">
                <td style="color:#64748b"><?= $i + 1 ?></td>
                <td><?= htmlspecialchars($cl['title']) ?></td>
                <td style="color:#64748b"><?= $cl['topic_title'] ? htmlspecialchars($cl['topic_title']) : '—' ?></td>
                <td>
                  <?php if ($cl['completed']): ?>
                  <span class="badge badge-success">✅ Concluída</span>
                  <?php else: ?>
                  <span class="badge badge-secondary">Pendente</span>
                  <?php endif; ?>
                </td>
                <td style="font-size:.82rem"><?= $cl['completed_at'] ? date('d/m/Y H:i', strtotime($cl['completed_at'])) : '—' ?></td>
                <td><?= auditTime((int)($lessonTimeMap[$cl['id']] ?? 0)) ?></td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php else: ?>
          <p style="padding:1rem;color:#64748b">Nenhuma aula neste curso.</p>
          <?php endif; ?>
        </div>
      </details>
    </div>
    <?php endforeach; endif; ?>

    <?php else: ?>
    <!-- ── TAB: HISTÓRICO ────────────────────────────────────────────── -->
    <div class="card">
      <div class="card-header">
        <h2>Histórico — <?= number_format($totalLogs) ?> registros</h2>
        <span style="color:#94a3b8;font-size:.85rem">Página <?= $page ?>/<?= $totalPages ?></span>
      </div>
      <?php if ($logs): ?>
      <table class="table audit-table">
        <thead><tr><th>Data/Hora</th><th>Ação</th><th>Entidade</th><th>Página</th><th>Tempo</th><th>IP</th></tr></thead>
        <tbody>
          <?php foreach ($logs as $log):
            [$label, $cls, $ico] = auditActionMeta($log['action']); ?>
          <tr>
            <td class="audit-ts"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
            <td><span class="badge <?= $cls ?>"><?= $ico ?> <?= $label ?></span></td>
            <td class="audit-entity"><?= $log['entity_title'] ? htmlspecialchars($log['entity_title']) : '—' ?></td>
            <td class="audit-url" title="<?= htmlspecialchars($log['page_url'] ?? '') ?>"><?= auditPageUrl($log['page_url']) ?></td>
            <td><?= auditTime((int)$log['time_on_page']) ?></td>
            <td class="audit-ip"><?= htmlspecialchars($log['ip'] ?? '—') ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?= auditPagination($page, $totalPages, 'audit.php?user=' . $studentId . '&tab=history&') ?>
      <?php else: ?>
      <div style="padding:2rem;text-align:center;color:#64748b">Nenhum registro de atividade encontrado.</div>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php adminFooter(); exit;
}

// ══════════════════════════════════════ GLOBAL AUDIT VIEW ═════════════════════

$filterUser   = (int)($_GET['uid']  ?? 0);
$filterAction = $_GET['act']  ?? '';
$filterFrom   = $_GET['from'] ?? '';
$filterTo     = $_GET['to']   ?? '';
$filterSearch = trim($_GET['q'] ?? '');

$allowedActions = ['login','login_failed','logout','page_view','course_view','lesson_view','lesson_complete','course_enroll'];
if ($filterAction && !in_array($filterAction, $allowedActions, true)) $filterAction = '';

// Build parameterized WHERE clause
$where  = [];
$params = [];

if ($filterUser > 0) {
    $where[]  = 'al.user_id = ?';
    $params[] = $filterUser;
}
if ($filterAction) {
    $where[]  = 'al.action = ?';
    $params[] = $filterAction;
}
if ($filterFrom && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterFrom)) {
    $where[]  = 'al.created_at >= ?';
    $params[] = $filterFrom . ' 00:00:00';
}
if ($filterTo && preg_match('/^\d{4}-\d{2}-\d{2}$/', $filterTo)) {
    $where[]  = 'al.created_at <= ?';
    $params[] = $filterTo . ' 23:59:59';
}
if ($filterSearch !== '') {
    $like     = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $filterSearch) . '%';
    $where[]  = '(al.entity_title LIKE ? OR al.page_url LIKE ?)';
    $params[] = $like;
    $params[] = $like;
}

$whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

// Count
$cntStmt = $db->prepare("SELECT COUNT(*) FROM activity_log al $whereSQL");
$cntStmt->execute($params);
$totalLogs  = (int)$cntStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalLogs / $perPage));

// Data
$logsStmt = $db->prepare(
    "SELECT al.*, u.name AS user_name, u.email AS user_email, u.role AS user_role
     FROM activity_log al
     LEFT JOIN users u ON u.id = al.user_id
     $whereSQL
     ORDER BY al.created_at DESC
     LIMIT ? OFFSET ?"
);
$logsStmt->execute(array_merge($params, [$perPage, $offset]));
$logs = $logsStmt->fetchAll();

// Users for filter dropdown
$allUsers = $db->query('SELECT id, name, role FROM users ORDER BY name ASC')->fetchAll();

// Stats cards
$todayRow = $db->query(
    'SELECT COUNT(*) AS events,
            COUNT(DISTINCT user_id) AS users,
            COALESCE(SUM(time_on_page), 0) AS time_sum
     FROM activity_log WHERE DATE(created_at) = CURDATE()'
)->fetch();

$totalRow = $db->query(
    'SELECT COUNT(*) AS total,
            COALESCE(SUM(time_on_page), 0) AS total_time
     FROM activity_log'
)->fetch();

$qs      = http_build_query(array_filter(['uid' => $filterUser ?: null, 'act' => $filterAction, 'from' => $filterFrom, 'to' => $filterTo, 'q' => $filterSearch]));
$baseUrl = 'audit.php?' . ($qs ? $qs . '&' : '');

adminHeader('Auditoria', 'audit');
?>

<!-- Summary stats -->
<div class="stats-grid" style="margin-bottom:1.5rem">
  <div class="stat-card">
    <div class="stat-icon">📅</div>
    <div class="stat-info">
      <div class="stat-value"><?= number_format($todayRow['events']) ?></div>
      <div class="stat-label">Eventos hoje</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">👥</div>
    <div class="stat-info">
      <div class="stat-value"><?= $todayRow['users'] ?></div>
      <div class="stat-label">Usuários ativos hoje</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">⏱</div>
    <div class="stat-info">
      <div class="stat-value"><?= auditTime((int)$todayRow['time_sum']) ?></div>
      <div class="stat-label">Tempo rastreado hoje</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">📊</div>
    <div class="stat-info">
      <div class="stat-value"><?= number_format($totalRow['total']) ?></div>
      <div class="stat-label">Total de registros</div>
    </div>
  </div>
</div>

<!-- Filters -->
<div class="card mb-2">
  <div class="card-body" style="padding:.85rem 1.25rem">
    <form method="get" action="audit.php" style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end">
      <div>
        <label class="audit-filter-label">Usuário</label>
        <select name="uid" class="form-control" style="min-width:200px">
          <option value="">Todos</option>
          <?php foreach ($allUsers as $u): ?>
          <option value="<?= $u['id'] ?>" <?= $filterUser === (int)$u['id'] ? 'selected' : '' ?>>
            <?= htmlspecialchars($u['name']) ?> (<?= $u['role'] ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="audit-filter-label">Tipo de ação</label>
        <select name="act" class="form-control">
          <option value="">Todas</option>
          <?php foreach ($allowedActions as $a):
            [$lbl] = auditActionMeta($a); ?>
          <option value="<?= $a ?>" <?= $filterAction === $a ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="audit-filter-label">De</label>
        <input type="date" name="from" value="<?= htmlspecialchars($filterFrom) ?>" class="form-control" style="width:145px">
      </div>
      <div>
        <label class="audit-filter-label">Até</label>
        <input type="date" name="to" value="<?= htmlspecialchars($filterTo) ?>" class="form-control" style="width:145px">
      </div>

      <div style="flex:1;min-width:160px">
        <label class="audit-filter-label">Busca (entidade / URL)</label>
        <input type="text" name="q" value="<?= htmlspecialchars($filterSearch) ?>" class="form-control" placeholder="Ex: Python, watch.php…">
      </div>

      <div style="display:flex;gap:.5rem">
        <button type="submit" class="btn btn-primary">🔍 Filtrar</button>
        <a href="audit.php" class="btn">Limpar</a>
      </div>
    </form>
  </div>
</div>

<!-- Results table -->
<div class="card">
  <div class="card-header">
    <h2>Registros <?= $whereSQL ? '<span style="color:#38bdf8;font-size:.85rem">(filtrados)</span>' : '' ?> — <?= number_format($totalLogs) ?> no total</h2>
    <?php if ($totalPages > 1): ?>
    <span style="color:#94a3b8;font-size:.85rem">Pág. <?= $page ?>/<?= $totalPages ?></span>
    <?php endif; ?>
  </div>

  <?php if ($logs): ?>
  <div style="overflow-x:auto">
  <table class="table audit-table">
    <thead>
      <tr>
        <th>Data/Hora</th>
        <th>Usuário</th>
        <th>Ação</th>
        <th>Entidade</th>
        <th>Página</th>
        <th>Tempo</th>
        <th>IP</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($logs as $log):
        [$label, $cls, $ico] = auditActionMeta($log['action']); ?>
      <tr>
        <td class="audit-ts"><?= date('d/m/Y H:i:s', strtotime($log['created_at'])) ?></td>
        <td>
          <?php if ($log['user_name']): ?>
          <a href="audit.php?user=<?= $log['user_id'] ?>" class="audit-user-link">
            <?= htmlspecialchars($log['user_name']) ?>
            <?php if ($log['user_role'] === 'admin'): ?>
            <span class="badge badge-info" style="font-size:.65rem;vertical-align:middle">adm</span>
            <?php endif; ?>
          </a>
          <?php else: ?><span class="audit-anon">desconhecido</span><?php endif; ?>
        </td>
        <td><span class="badge <?= $cls ?>"><?= $ico ?> <?= $label ?></span></td>
        <td class="audit-entity" title="<?= htmlspecialchars($log['entity_title'] ?? '') ?>">
          <?= $log['entity_title'] ? htmlspecialchars($log['entity_title']) : '—' ?>
        </td>
        <td class="audit-url" title="<?= htmlspecialchars($log['page_url'] ?? '') ?>">
          <?= auditPageUrl($log['page_url']) ?>
        </td>
        <td style="white-space:nowrap"><?= auditTime((int)$log['time_on_page']) ?></td>
        <td class="audit-ip"><?= htmlspecialchars($log['ip'] ?? '—') ?></td>
        <td>
          <?php if ($log['user_id']): ?>
          <a href="audit.php?user=<?= $log['user_id'] ?>" class="btn btn-sm btn-secondary" title="Auditoria do usuário">🔎</a>
          <?php endif; ?>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  </div>
  <?= auditPagination($page, $totalPages, $baseUrl) ?>

  <?php else: ?>
  <div style="padding:2.5rem;text-align:center;color:#64748b">
    <div style="font-size:2rem;margin-bottom:.5rem">📋</div>
    <p>Nenhum registro encontrado<?= $whereSQL ? ' com os filtros aplicados' : '' ?>.</p>
    <?php if (!$whereSQL): ?><p style="font-size:.85rem;margin-top:.5rem">Os registros aparecerão aqui assim que os usuários navegarem pela plataforma.</p><?php endif; ?>
  </div>
  <?php endif; ?>
</div>

<?php adminFooter(); ?>
