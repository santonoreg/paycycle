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
// Διάταξη (πλήρες πλάτος / περιορισμένο): προτίμηση του επισκέπτη, αποθηκεύεται σε cookie
$layout = ($_COOKIE['layout'] ?? 'full') === 'boxed' ? 'boxed' : 'full';
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
<body class="layout-<?= $layout ?>">
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
          <a class="nav-link <?= $currentScript === 'cards.php' ? 'active' : '' ?>" href="cards.php">
            <i class="bi bi-credit-card"></i> <?= te('nav.cards') ?>
          </a>
        </li>
        <li class="nav-item">
          <a class="nav-link <?= $currentScript === 'stats.php' ? 'active' : '' ?>" href="stats.php">
            <i class="bi bi-bar-chart-line"></i> <?= te('nav.stats') ?>
          </a>
        </li>
        <?php if (isAdmin()): ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentScript === 'users.php' ? 'active' : '' ?>" href="users.php">
            <i class="bi bi-people"></i> <?= te('nav.users') ?>
          </a>
        </li>
        <?php endif; ?>
        <li class="nav-item">
          <a class="nav-link <?= $currentScript === 'settings.php' ? 'active' : '' ?>" href="settings.php">
            <i class="bi bi-gear"></i> <?= te('nav.settings') ?>
          </a>
        </li>
      </ul>
      <ul class="navbar-nav align-items-lg-center">
        <li class="nav-item me-lg-2 my-2 my-lg-0">
          <div class="btn-group btn-group-sm layout-toggle" role="group" aria-label="<?= te('nav.layout') ?>" data-base="<?= htmlspecialchars(appBasePath()) ?>">
            <button type="button" class="btn btn-outline-light <?= $layout === 'full' ? 'active' : '' ?>" data-layout="full" title="<?= te('nav.layout_full') ?>" aria-pressed="<?= $layout === 'full' ? 'true' : 'false' ?>"><i class="bi bi-arrows-fullscreen"></i></button>
            <button type="button" class="btn btn-outline-light <?= $layout === 'boxed' ? 'active' : '' ?>" data-layout="boxed" title="<?= te('nav.layout_boxed') ?>" aria-pressed="<?= $layout === 'boxed' ? 'true' : 'false' ?>"><i class="bi bi-fullscreen-exit"></i></button>
          </div>
        </li>
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
              <i class="bi bi-person-check"></i> <?= htmlspecialchars(currentUser()['username']) ?><?= isAdmin() ? ' <span class="badge text-bg-warning">' . te('role.admin') . '</span>' : '' ?>
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
// Μηνύματα: toast που εξαφανίζεται μόνο του (επιτυχία/πληροφορία: λίγα δευτερόλεπτα,
// προειδοποίηση/σφάλμα: περισσότερο). Μπορεί να κλείσει και με το (x).
if (!empty($_SESSION['flash'])) {
    echo '<div class="toast-container position-fixed top-0 end-0 p-3" style="z-index:1090">';
    foreach ($_SESSION['flash'] as $flash) {
        $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
        $delay = in_array($type, ['danger', 'warning'], true) ? 9000 : min(6000, 3500 + 30 * mb_strlen($flash["msg"]));
        $closeCls = in_array($type, ['success', 'danger'], true) ? ' btn-close-white' : '';
        $msg = htmlspecialchars($flash['msg']);
        echo "<div class=\"toast align-items-center text-bg-$type border-0\" role=\"alert\" aria-live=\"assertive\" data-bs-delay=\"$delay\">"
            . "<div class=\"d-flex\"><div class=\"toast-body\">$msg</div>"
            . "<button type=\"button\" class=\"btn-close$closeCls me-2 m-auto\" data-bs-dismiss=\"toast\"></button></div></div>";
    }
    echo '</div>';
    unset($_SESSION['flash']);
}
?>
