<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

$redirectTo = $_GET['redirect_to'] ?? 'index.php';
// Απλή προφύλαξη ώστε το redirect να μένει μέσα στην εφαρμογή
if (!preg_match('#^[a-zA-Z0-9_./?=&%-]+$#', $redirectTo) || str_starts_with($redirectTo, '//')) {
    $redirectTo = 'index.php';
}

$error = null;
$passwordSet = isPasswordSet();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $redirectTo = $_POST['redirect_to'] ?? $redirectTo;
    if (!preg_match('#^[a-zA-Z0-9_./?=&%-]+$#', $redirectTo) || str_starts_with($redirectTo, '//')) {
        $redirectTo = 'index.php';
    }
    $password = $_POST['password'] ?? '';
    if (!$passwordSet) {
        // Πρώτη εγκατάσταση: δημιουργία κωδικού (μόνο όσο δεν υπάρχει κωδικός).
        $error = validateNewPassword($password, $_POST['password_confirm'] ?? '');
        if ($error === null) {
            setAdminPassword($password);
            session_regenerate_id(true);
            $_SESSION['authenticated'] = true;
            flash('success', t('setup.done'));
            header('Location: ' . $redirectTo);
            exit;
        }
    } elseif (verifyAdminPassword($password)) {
        session_regenerate_id(true);
        $_SESSION['authenticated'] = true;
        header('Location: ' . $redirectTo);
        exit;
    } else {
        $error = t('login.wrong');
    }
}

$pageTitle = t('page.login');
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-sm-8 col-md-5 col-lg-4">
    <div class="card shadow-sm mt-4">
      <div class="card-body p-4">
        <h4 class="mb-3 text-center"><i class="bi bi-lock"></i> <?= te($passwordSet ? 'login.heading' : 'setup.heading') ?></h4>
        <p class="text-muted small text-center"><?= te($passwordSet ? 'login.help' : 'setup.help', ['n' => MIN_PASSWORD_LENGTH]) ?></p>
        <?php if ($error): ?>
          <div class="alert alert-danger py-2"><?= htmlspecialchars($error) ?></div>
        <?php endif; ?>
        <form method="post">
          <?= csrfField() ?>
          <input type="hidden" name="redirect_to" value="<?= htmlspecialchars($redirectTo) ?>">
          <div class="mb-3">
            <label class="form-label"><?= te('login.label') ?></label>
            <input type="password" name="password" class="form-control" autofocus required
              <?= $passwordSet ? '' : 'minlength="' . MIN_PASSWORD_LENGTH . '" autocomplete="new-password"' ?>>
          </div>
          <?php if (!$passwordSet): ?>
          <div class="mb-3">
            <label class="form-label"><?= te('pw.confirm') ?></label>
            <input type="password" name="password_confirm" class="form-control" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
          </div>
          <?php endif; ?>
          <button type="submit" class="btn btn-primary w-100"><?= te($passwordSet ? 'login.submit' : 'setup.submit') ?></button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
