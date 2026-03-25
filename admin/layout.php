<?php
/**
 * CloudiLMS - Layout base do painel administrativo
 */
function adminHeader(string $title, string $activePage = ''): void {
    $appName = defined('APP_NAME') ? APP_NAME : 'CloudiLMS';
    $appUrl  = defined('APP_URL')  ? APP_URL  : '';
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?> | Admin - <?= htmlspecialchars($appName) ?></title>
<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/admin.css">
</head>
<body>
<div class="layout">
  <!-- Sidebar -->
  <aside class="sidebar">
    <div class="sidebar-brand">
      <span class="brand-icon">☁️</span>
      <span class="brand-name"><?= htmlspecialchars($appName) ?></span>
    </div>
    <nav class="sidebar-nav">
      <a href="<?= $appUrl ?>/admin/" class="nav-item <?= $activePage==='dashboard'?'active':'' ?>">
        <span class="nav-icon">📊</span> Dashboard
      </a>
      <a href="<?= $appUrl ?>/admin/courses.php" class="nav-item <?= $activePage==='courses'?'active':'' ?>">
        <span class="nav-icon">🎓</span> Cursos
      </a>
      <a href="<?= $appUrl ?>/admin/users.php" class="nav-item <?= $activePage==='users'?'active':'' ?>">
        <span class="nav-icon">👥</span> Alunos
      </a>
      <a href="<?= $appUrl ?>/admin/settings.php" class="nav-item <?= $activePage==='settings'?'active':'' ?>">
        <span class="nav-icon">⚙️</span> Configurações
      </a>
      <a href="<?= $appUrl ?>/admin/audit.php" class="nav-item <?= $activePage==='audit'?'active':'' ?>">
        <span class="nav-icon">🔎</span> Auditoria
      </a>
      <div class="nav-divider"></div>
      <a href="<?= $appUrl ?>/index.php" class="nav-item" target="_blank">
        <span class="nav-icon">🌐</span> Ver site
      </a>
      <a href="<?= $appUrl ?>/logout.php" class="nav-item nav-logout">
        <span class="nav-icon">🚪</span> Sair
      </a>
    </nav>
  </aside>

  <!-- Main -->
  <main class="main">
    <header class="topbar">
      <h1 class="page-title"><?= htmlspecialchars($title) ?></h1>
      <div class="topbar-user">
        <span>👤 <?= htmlspecialchars($_SESSION['user_name'] ?? 'Admin') ?></span>
      </div>
    </header>
    <div class="content">
    <?php
}

function adminFooter(): void {
    ?>
    </div><!-- .content -->
  </main>
</div><!-- .layout -->
<script src="<?= defined('APP_URL') ? APP_URL : '' ?>/assets/js/admin.js"></script>
<script>
(function () {
  var API = '<?= defined("APP_URL") ? APP_URL : "" ?>/api/log.php';
  var t0  = Date.now(), lid = null;
  fetch(API, {
    method: 'POST',
    headers: {'Content-Type': 'application/json'},
    body: JSON.stringify({action: 'page_view', url: location.href, title: document.title})
  }).then(function (r) { return r.json(); })
    .then(function (d) { if (d.ok) lid = d.id; })
    .catch(function () {});

  function beacon() {
    if (!lid) return;
    var s = Math.round((Date.now() - t0) / 1000);
    if (s < 1) return;
    navigator.sendBeacon(API, new Blob(
      [JSON.stringify({action: 'time_on_page', log_id: lid, seconds: s})],
      {type: 'application/json'}
    ));
    lid = null;
  }
  document.addEventListener('visibilitychange', function () { if (document.hidden) beacon(); });
  window.addEventListener('pagehide', beacon);
})();
</script>
</body>
</html>
    <?php
}
