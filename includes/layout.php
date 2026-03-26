<?php
/**
 * CloudiLMS - Layout base do front-end (aluno)
 */
function siteHeader(string $title): void {
    $appName = defined('APP_NAME') ? APP_NAME : 'CloudiLMS';
    $appUrl  = defined('APP_URL')  ? APP_URL  : '';
    $auth    = new Auth();
    $logged  = $auth->isLoggedIn();
    $isAdmin = $auth->isAdmin();
    ?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($title) ?> | <?= htmlspecialchars($appName) ?></title>
<link rel="stylesheet" href="<?= $appUrl ?>/assets/css/app.css">
</head>
<body>
<header class="site-header">
  <div class="container header-inner">
    <a href="<?= $appUrl ?>/" class="site-logo">☁️ <?= htmlspecialchars($appName) ?></a>
    <nav class="site-nav">
      <?php if ($logged): ?>
        <a href="<?= $appUrl ?>/index.php">Cursos</a>
      <?php endif; ?>
      <?php if ($logged): ?>
        <a href="<?= $appUrl ?>/dashboard.php">Meus Cursos</a>
        <a href="<?= $appUrl ?>/trails.php">Minhas Trilhas</a>
        <a href="<?= $appUrl ?>/my-certificates.php">Meus Certificados</a>
        <?php if ($isAdmin): ?>
          <a href="<?= $appUrl ?>/admin/">Painel Admin</a>
        <?php endif; ?>
        <a href="<?= $appUrl ?>/logout.php" class="btn-nav">Sair</a>
      <?php else: ?>
        <a href="<?= $appUrl ?>/login.php" class="btn-nav">Entrar</a>
        <a href="<?= $appUrl ?>/register.php" class="btn-nav btn-nav-primary">Cadastrar</a>
      <?php endif; ?>
    </nav>
  </div>
</header>
<main class="site-main">
<div class="container">
<?php
}

function siteFooter(): void {
    $appName = defined('APP_NAME') ? APP_NAME : 'CloudiLMS';
    $appUrl  = defined('APP_URL')  ? APP_URL  : '';
    ?>
</div>
</main>
<footer class="site-footer">
  <div class="container">
    <p>© <?= date('Y') ?> <?= htmlspecialchars($appName) ?> &mdash; Plataforma de Cursos Online</p>
  </div>
</footer><?php if (!empty($_SESSION['user_id'])): ?>
<script>
(function () {
  var API = '<?= $appUrl ?>/api/log.php';
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
<?php endif; ?></body>
</html>
<?php
}
