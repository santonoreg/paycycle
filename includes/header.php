<?php
require_once __DIR__ . '/i18n.php';
/** @var string $pageTitle */
$pageTitle = $pageTitle ?? t('page.subscriptions');
$navKind = currentKind();
setKind('subscription'); // η πλοήγηση δεν αλλάζει διατύπωση

$currentScript = basename($_SERVER['SCRIPT_NAME']);
$themePref = getSetting('theme');
if (!in_array($themePref, THEMES, true)) {
    $themePref = 'light';
}
$initialTheme = $themePref === 'dark' ? 'dark' : 'light';
$backTo = $currentScript . (!empty($_SERVER['QUERY_STRING']) ? '?' . $_SERVER['QUERY_STRING'] : '');
?>
<!DOCTYPE html>
<html lang="<?= te('meta.html_lang') ?>" data-bs-theme="<?= $initialTheme ?>" data-theme-pref="<?= $themePref ?>">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= htmlspecialchars($pageTitle) ?> — <?= te('app.name') ?></title>
<script>
// Θέμα "Αυτόματο": ακολουθεί το θέμα της συσκευής (πριν το πρώτο painting, ώστε να μην "αναβοσβήνει").
(function () {
  var root = document.documentElement;
  if (root.getAttribute('data-theme-pref') !== 'auto') return;
  var mq = window.matchMedia('(prefers-color-scheme: dark)');
  function apply() { root.setAttribute('data-bs-theme', mq.matches ? 'dark' : 'light'); }
  apply();
  if (mq.addEventListener) mq.addEventListener('change', apply);
})();
</script>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="assets/style.css?v=<?= filemtime(__DIR__ . '/../assets/style.css') ?>" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark app-navbar mb-4">
  <div class="container-fluid">
    <a class="navbar-brand fw-semibold" href="index.php">
      <i class="bi bi-credit-card-2-front"></i> <?= te('app.name') ?>
    </a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item">
          <a class="nav-link <?= $currentScript === 'index.php' ? 'active' : '' ?>" href="index.php">
            <i class="bi bi-list-ul"></i> <?= te('nav.subscriptions') ?>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentScript === 'recurring.php' ? 'active' : '' ?>" href="recurring.php">
            <i class="bi bi-arrow-repeat"></i> <?= te('nav.recurring') ?>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentScript === 'stats.php' ? 'active' : '' ?>" href="stats.php">
            <i class="bi bi-bar-chart-line"></i> <?= te('nav.stats') ?>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentScript === 'settings.php' ? 'active' : '' ?>" href="settings.php">
            <i class="bi bi-gear"></i> <?= te('nav.settings') ?>
          </a>
        </li>
      </ul>
      <ul class="navbar-nav align-items-lg-center">
        <li class="nav-item dropdown me-lg-2">
          <a class="nav-link dropdown-toggle" href="#" data-bs-toggle="dropdown" title="<?= te('nav.language') ?>">
            <i class="bi bi-translate"></i> <?= htmlspecialchars(strtoupper(currentLang())) ?>
          </a>
          <ul class="dropdown-menu dropdown-menu-end">
            <?php foreach (SUPPORTED_LANGUAGES as $code => $langName): ?>
              <li>
                <form method="post" action="actions/set_language.php">
                  <?= csrfField() ?>
                  <input type="hidden" name="lang" value="<?= $code ?>">
                  <input type="hidden" name="back" value="<?= htmlspecialchars($backTo) ?>">
                  <button type="submit" class="dropdown-item <?= $code === currentLang() ? 'active' : '' ?>"><?= htmlspecialchars($langName) ?></button>
                </form>
              </li>
            <?php endforeach; ?>
          </ul>
        </li>
        <?php if (isLoggedIn()): ?>
          <li class="nav-item d-flex align-items-center">
            <span class="navbar-text text-white-50 me-3 small">
              <i class="bi bi-unlock"></i> <?= te('nav.logged_in') ?>
            </span>
          </li>
          <li class="nav-item">
            <a class="btn btn-outline-light btn-sm" href="logout.php"><i class="bi bi-box-arrow-right"></i> <?= te('nav.logout') ?></a>
          </li>
        <?php else: ?>
          <li class="nav-item">
            <a class="btn btn-outline-light btn-sm" href="login.php"><i class="bi bi-lock"></i> <?= te('nav.login') ?></a>
          </li>
        <?php endif; ?>
      </ul>
    </div>
  </div>
</nav>
<?php setKind($navKind); ?>
<div class="container-fluid px-3 px-md-4 pb-5">
<?php
if (!empty($_SESSION['flash'])) {
    foreach ($_SESSION['flash'] as $flash) {
        $type = htmlspecialchars($flash['type']);
        $msg = htmlspecialchars($flash['msg']);
        echo "<div class=\"alert alert-$type alert-dismissible fade show\" role=\"alert\">$msg
            <button type=\"button\" class=\"btn-close\" data-bs-dismiss=\"alert\"></button></div>";
    }
    unset($_SESSION['flash']);
}
?>
