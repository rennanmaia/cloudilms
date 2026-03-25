<?php
require_once __DIR__ . '/../includes/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/database.php';
require_once __DIR__ . '/layout.php';

$auth = new Auth();
$auth->requireAdmin();

$db = Database::getConnection();

// Estatísticas
$stats = [
    'courses'  => (int)$db->query('SELECT COUNT(*) FROM courses')->fetchColumn(),
    'lessons'  => (int)$db->query('SELECT COUNT(*) FROM lessons')->fetchColumn(),
    'students' => (int)$db->query('SELECT COUNT(*) FROM users WHERE role = "student"')->fetchColumn(),
    'published'=> (int)$db->query('SELECT COUNT(*) FROM courses WHERE published = 1')->fetchColumn(),
];

$recentCourses = $db->query(
    'SELECT c.*, (SELECT COUNT(*) FROM lessons l WHERE l.course_id = c.id) AS lesson_count
     FROM courses c ORDER BY c.created_at DESC LIMIT 5'
)->fetchAll();

adminHeader('Dashboard', 'dashboard');
?>

<div class="stats-grid">
  <div class="stat-card">
    <div class="stat-icon">🎓</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['courses'] ?></div>
      <div class="stat-label">Cursos criados</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">▶️</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['lessons'] ?></div>
      <div class="stat-label">Aulas no total</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">👥</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['students'] ?></div>
      <div class="stat-label">Alunos cadastrados</div>
    </div>
  </div>
  <div class="stat-card">
    <div class="stat-icon">✅</div>
    <div class="stat-info">
      <div class="stat-value"><?= $stats['published'] ?></div>
      <div class="stat-label">Cursos publicados</div>
    </div>
  </div>
</div>

<div class="card mt-2">
  <div class="card-header">
    <h2>Cursos recentes</h2>
    <a href="courses.php?action=new" class="btn btn-primary">+ Novo curso</a>
  </div>
  <table class="table">
    <thead>
      <tr><th>Título</th><th>Aulas</th><th>Status</th><th>Criado em</th><th>Ações</th></tr>
    </thead>
    <tbody>
      <?php foreach ($recentCourses as $c): ?>
      <tr>
        <td><strong><?= htmlspecialchars($c['title']) ?></strong></td>
        <td><?= $c['lesson_count'] ?></td>
        <td><span class="badge <?= $c['published'] ? 'badge-success' : 'badge-warning' ?>">
          <?= $c['published'] ? 'Publicado' : 'Rascunho' ?></span></td>
        <td><?= date('d/m/Y', strtotime($c['created_at'])) ?></td>
        <td>
          <a href="courses.php?action=edit&id=<?= $c['id'] ?>" class="btn btn-sm">Editar</a>
          <a href="courses.php?action=lessons&id=<?= $c['id'] ?>" class="btn btn-sm btn-secondary">Aulas</a>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$recentCourses): ?>
      <tr><td colspan="5" style="text-align:center;color:#64748b">Nenhum curso ainda. <a href="courses.php?action=new">Criar primeiro curso</a></td></tr>
      <?php endif; ?>
    </tbody>
  </table>
</div>

<?php adminFooter(); ?>
