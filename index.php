<?php
/**
 * CloudiLMS - Página inicial (catálogo de cursos agrupados por trilha)
 */
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/trail.php';
require_once __DIR__ . '/includes/activity_log.php';
require_once __DIR__ . '/includes/layout.php';

$auth = new Auth();

// ── Inline login for guest landing ───────────────────────────────────────────
$loginError = '';
if (!$auth->isLoggedIn()) {
    if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $csrf = $_SESSION['csrf_token'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['_action'] ?? '') === 'login') {
        if (($_POST['csrf'] ?? '') !== $csrf) {
            $loginError = 'Token de segurança inválido.';
        } else {
            $email = trim($_POST['email'] ?? '');
            $pass  = $_POST['password'] ?? '';
            if ($auth->login($email, $pass)) {
                ActivityLog::record('login');
                if ($_SESSION['user_role'] === 'admin') {
                    header('Location: ' . APP_URL . '/admin/');
                } else {
                    header('Location: ' . APP_URL . '/dashboard.php');
                }
                exit;
            }
            $loginError = 'E-mail ou senha inválidos.';
            ActivityLog::record('login_failed', ['meta' => ['email' => $email]]);
        }
    }
}

$trailModel = new TrailModel();
$userId     = $auth->isLoggedIn() ? (int)$_SESSION['user_id'] : null;

if (!$userId) {
    // ── Guest landing ─────────────────────────────────────────────────────────
    siteHeader('Bem-vindo');
    ?>
    <div class="guest-landing">

      <div class="guest-hero">
        <div class="guest-hero-text">
          <h1>☁️ <?= htmlspecialchars(defined('APP_NAME') ? APP_NAME : 'CloudiLMS') ?></h1>
          <p class="guest-hero-sub">Plataforma de cursos online. Aprenda no seu ritmo, quando e onde quiser.</p>
          <ul class="guest-features">
            <li>🎓 Cursos em vídeo com progresso automático</li>
            <li>📜 Certificados digitais ao concluir</li>
            <li>🗺️ Trilhas de aprendizado organizadas</li>
            <li>📊 Acompanhe seu desempenho em tempo real</li>
          </ul>
        </div>

        <div class="guest-login-card">
          <h2 class="guest-login-title">Acessar plataforma</h2>
          <?php if ($loginError): ?>
          <div class="alert alert-danger"><?= htmlspecialchars($loginError) ?></div>
          <?php endif; ?>
          <form method="post" action="<?= htmlspecialchars(APP_URL) ?>/index.php">
            <input type="hidden" name="csrf"    value="<?= htmlspecialchars($csrf) ?>">
            <input type="hidden" name="_action" value="login">
            <div class="form-group">
              <label>E-mail</label>
              <input type="email" name="email" required class="form-control"
                     placeholder="seu@email.com" autofocus>
            </div>
            <div class="form-group">
              <label>Senha</label>
              <input type="password" name="password" required class="form-control"
                     placeholder="••••••••">
            </div>
            <button type="submit" class="btn btn-primary w-full">Entrar</button>
          </form>
          <p class="auth-link">Não tem conta? <a href="<?= htmlspecialchars(APP_URL) ?>/register.php">Cadastre-se gratuitamente</a></p>
        </div>
      </div>

    </div>
    <?php
    siteFooter();
    exit;
}

// ── Logged-in: course catalog ─────────────────────────────────────────────────
$trails     = $trailModel->getAllTrailsForIndex($userId);
$standalone = $trailModel->getStandalonePublishedCourses();

siteHeader('Catálogo de Cursos');
?>

<section class="hero">
  <h1>Aprenda no seu ritmo</h1>
  <p>Acesse os cursos disponíveis e assista quando quiser</p>
</section>

<?php if (!$trails && !$standalone): ?>
<div class="empty-state">
  <div class="empty-icon">📚</div>
  <h2>Nenhum curso disponível ainda</h2>
  <p>Volte em breve!</p>
</div>
<?php endif; ?>

<?php if ($trails): ?>
<div class="index-trails">
  <?php foreach ($trails as $t):
    $status   = $t['user_status']; // 'unlocked' | 'locked' | null
    $isLocked = ($status === 'locked');
    $isOpen   = ($status === 'unlocked' || $userId === null);
  ?>
  <details class="index-trail <?= $isLocked ? 'index-trail--locked' : '' ?>" <?= $isOpen ? 'open' : '' ?>>
    <summary class="index-trail-summary">
      <div class="index-trail-left">
        <span class="index-trail-icon">
          <?= $isLocked ? '🔴' : ($status === 'unlocked' ? '🟢' : '🗂️') ?>
        </span>
        <div>
          <div class="index-trail-name"><?= htmlspecialchars($t['title']) ?></div>
          <?php if ($t['description']): ?>
          <div class="index-trail-desc"><?= htmlspecialchars(mb_strimwidth($t['description'], 0, 120, '…')) ?></div>
          <?php endif; ?>
        </div>
      </div>
      <div class="index-trail-right">
        <?php if ($userId !== null): ?>
          <?php if ($status === 'unlocked'): ?>
          <span class="trail-status-pill trail-status-pill--unlocked">✅ Liberada</span>
          <?php elseif ($status === 'locked'): ?>
          <span class="trail-status-pill trail-status-pill--locked">🔒 Bloqueada</span>
          <?php else: ?>
          <span class="trail-status-pill trail-status-pill--neutral">📋 Não atribuída</span>
          <?php endif; ?>
        <?php endif; ?>
        <span class="index-trail-count">
          <?= (int)$t['published_course_count'] ?> curso<?= (int)$t['published_course_count'] !== 1 ? 's' : '' ?>
        </span>
        <span class="index-trail-chevron">▼</span>
      </div>
    </summary>

    <?php if ($t['courses']): ?>
    <div class="index-trail-courses">
      <?php foreach ($t['courses'] as $c): ?>
      <a href="course.php?slug=<?= urlencode($c['slug']) ?>" class="course-card">
        <?php if ($c['thumbnail']): ?>
        <div class="course-thumb" style="background-image:url('<?= htmlspecialchars($c['thumbnail']) ?>')"></div>
        <?php else: ?>
        <div class="course-thumb course-thumb-placeholder">🎓</div>
        <?php endif; ?>
        <div class="course-card-body">
          <h3 class="course-title"><?= htmlspecialchars($c['title']) ?></h3>
          <?php if ($c['description']): ?>
          <p class="course-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 90, '…')) ?></p>
          <?php endif; ?>
          <div class="course-meta">
            <span>▶ <?= $c['lesson_count'] ?> aulas</span>
            <span class="btn-enroll">Ver curso →</span>
          </div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
    <?php else: ?>
    <p class="index-trail-empty">Nenhum curso publicado nesta trilha ainda.</p>
    <?php endif; ?>
  </details>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<?php if ($standalone): ?>
<div class="index-standalone">
  <h2 class="index-standalone-header">📚 Cursos Avulsos</h2>
  <div class="index-trail-courses">
    <?php foreach ($standalone as $c): ?>
    <a href="course.php?slug=<?= urlencode($c['slug']) ?>" class="course-card">
      <?php if ($c['thumbnail']): ?>
      <div class="course-thumb" style="background-image:url('<?= htmlspecialchars($c['thumbnail']) ?>')"></div>
      <?php else: ?>
      <div class="course-thumb course-thumb-placeholder">🎓</div>
      <?php endif; ?>
      <div class="course-card-body">
        <h3 class="course-title"><?= htmlspecialchars($c['title']) ?></h3>
        <?php if ($c['description']): ?>
        <p class="course-desc"><?= htmlspecialchars(mb_strimwidth($c['description'], 0, 90, '…')) ?></p>
        <?php endif; ?>
        <div class="course-meta">
          <span>▶ <?= $c['lesson_count'] ?> aulas</span>
          <span class="btn-enroll">Ver curso →</span>
        </div>
      </div>
    </a>
    <?php endforeach; ?>
  </div>
</div>
<?php endif; ?>

<?php siteFooter(); ?>
