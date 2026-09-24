<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

$pdo = getDb();
$settings = getSettings();
$categories = getDistinctCategories($pdo);
$loggedIn = isLoggedIn();
$disabled = $loggedIn ? '' : 'disabled';

$pageTitle = t('page.settings');
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-8 col-xl-6">
    <h4 class="mb-1"><i class="bi bi-gear"></i> <?= te('settings.title') ?></h4>
    <p class="text-muted small mb-3"><?= te('settings.intro') ?></p>

    <?php if (!$loggedIn): ?>
      <div class="alert alert-info d-flex justify-content-between align-items-center flex-wrap gap-2">
        <span><i class="bi bi-lock"></i> <?= te('settings.login_needed') ?></span>
        <a class="btn btn-sm btn-primary" href="<?= htmlspecialchars(loginUrl('settings.php')) ?>"><?= te('nav.login') ?></a>
      </div>
    <?php endif; ?>

    <form method="post" action="actions/save_settings.php" class="card p-3 p-md-4">
      <?= csrfField() ?>

      <div class="mb-4">
        <label class="form-label fw-semibold" for="set-language"><i class="bi bi-translate"></i> <?= te('settings.language') ?></label>
        <select name="language" id="set-language" class="form-select" <?= $disabled ?>>
          <?php foreach (SUPPORTED_LANGUAGES as $code => $langName): ?>
            <option value="<?= $code ?>" <?= $settings['language'] === $code ? 'selected' : '' ?>><?= htmlspecialchars($langName) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="form-text"><?= te('settings.language_help') ?></div>
      </div>

      <div class="mb-4">
        <div class="form-label fw-semibold"><i class="bi bi-circle-half"></i> <?= te('settings.theme') ?></div>
        <?php foreach (['light' => 'sun', 'dark' => 'moon-stars', 'auto' => 'display'] as $th => $icon): ?>
          <div class="form-check">
            <input class="form-check-input" type="radio" name="theme" id="theme-<?= $th ?>" value="<?= $th ?>"
              <?= $settings['theme'] === $th ? 'checked' : '' ?> <?= $disabled ?>>
            <label class="form-check-label" for="theme-<?= $th ?>"><i class="bi bi-<?= $icon ?>"></i> <?= te('settings.theme.' . $th) ?></label>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="mb-4">
        <div class="form-label fw-semibold"><i class="bi bi-funnel"></i> <?= te('settings.filters') ?></div>
        <div class="form-text mb-2"><?= te('settings.filters_help') ?></div>
        <div class="row g-2">
          <div class="col-12 col-sm-6">
            <label class="form-label small mb-1" for="set-status"><?= te('settings.default_status') ?></label>
            <select name="default_status" id="set-status" class="form-select" <?= $disabled ?>>
              <option value=""><?= te('settings.any') ?></option>
              <?php foreach (statuses() as $k => $label): ?>
                <option value="<?= $k ?>" <?= $settings['default_status'] === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-12 col-sm-6">
            <label class="form-label small mb-1" for="set-category"><?= te('settings.default_category') ?></label>
            <select name="default_category" id="set-category" class="form-select" <?= $disabled ?>>
              <option value=""><?= te('settings.any') ?></option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= htmlspecialchars($c) ?>" <?= $settings['default_category'] === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>

      <div>
        <button type="submit" class="btn btn-primary" <?= $disabled ?>><i class="bi bi-check-lg"></i> <?= te('settings.save') ?></button>
      </div>
    </form>

    <?php if ($loggedIn): ?>
    <form method="post" action="actions/change_password.php" class="card p-3 p-md-4 mt-3">
      <?= csrfField() ?>
      <div class="form-label fw-semibold"><i class="bi bi-key"></i> <?= te('settings.password') ?></div>
      <div class="mb-2">
        <label class="form-label small mb-1" for="pw-current"><?= te('pw.current') ?></label>
        <input type="password" name="current_password" id="pw-current" class="form-control" required autocomplete="current-password">
      </div>
      <div class="row g-2 mb-3">
        <div class="col-12 col-sm-6">
          <label class="form-label small mb-1" for="pw-new"><?= te('pw.new') ?></label>
          <input type="password" name="new_password" id="pw-new" class="form-control" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
        </div>
        <div class="col-12 col-sm-6">
          <label class="form-label small mb-1" for="pw-confirm"><?= te('pw.confirm') ?></label>
          <input type="password" name="new_password_confirm" id="pw-confirm" class="form-control" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
        </div>
      </div>
      <div><button type="submit" class="btn btn-outline-primary"><i class="bi bi-check-lg"></i> <?= te('settings.change_password') ?></button></div>
    </form>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
