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
$passwordSet = hasUsers();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();
    $redirectTo = $_POST['redirect_to'] ?? $redirectTo;
    if (!preg_match('#^[a-zA-Z0-9_./?=&%-]+$#', $redirectTo) || str_starts_with($redirectTo, '//')) {
        $redirectTo = 'index.php';
    }
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    if (!$passwordSet) {
        // Πρώτη εγκατάσταση: δημιουργία του admin (μόνο όσο δεν υπάρχει κανένας χρήστης).
        $error = validateUsername($username) ?? validateNewPassword($password, $_POST['password_confirm'] ?? '');
        if ($error === null) {
            $id = createUser($username, $password, 'admin');
            // Συνδρομές που υπήρχαν πριν από τους χρήστες (χωρίς καταχωρητή) αποδίδονται στον πρώτο admin.
            getDb()->prepare('UPDATE subscriptions SET created_by = ?, created_by_name = ? WHERE created_by IS NULL AND created_by_name IS NULL')
                ->execute([$id, $username]);
            loginUser(findUserById($id));
            flash('success', t('setup.done'));
            header('Location: ' . $redirectTo);
            exit;
        }
    } elseif ($user = verifyLogin($username, $password)) {
        loginUser($user);
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
            <label class="form-label"><?= te('login.username') ?></label>
            <input type="text" name="username" class="form-control" autofocus required autocomplete="username"
              value="<?= htmlspecialchars($_POST['username'] ?? ($passwordSet ? '' : 'admin')) ?>">
          </div>
          <div class="mb-3">
            <label class="form-label"><?= te('login.label') ?></label>
            <input type="password" name="password" class="form-control" required
              <?= $passwordSet ? '' : 'minlength="' . MIN_PASSWORD_LENGTH . '" autocomplete="new-password"'  ?>>
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
