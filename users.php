<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

requireAdmin();

$pdo = getDb();
$me = currentUser();
$users = $pdo->query("
    SELECT u.*, (SELECT COUNT(*) FROM subscriptions s WHERE s.created_by = u.id) AS sub_count
    FROM users u
    ORDER BY (u.role = 'admin') DESC, u.username COLLATE NOCASE
")->fetchAll();

$pageTitle = t('page.users');
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-9 col-xl-8">
    <h4 class="mb-1"><i class="bi bi-people"></i> <?= te('users.title') ?></h4>
    <p class="text-muted small mb-3"><?= te('users.intro') ?></p>

    <div class="card mb-3">
      <div class="table-responsive">
        <table class="table align-middle mb-0">
          <thead>
            <tr>
              <th><?= te('users.username') ?></th>
              <th><?= te('users.role') ?></th>
              <th class="text-end"><?= te('users.added_count') ?></th>
              <th><?= te('users.created') ?></th>
              <th></th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($users as $u): $isMe = (int) $u['id'] === (int) $me['id']; ?>
            <tr>
              <td>
                <i class="bi bi-person"></i> <?= htmlspecialchars($u['username']) ?>
                <?php if ($isMe): ?><span class="text-muted small">(<?= te('users.you') ?>)</span><?php endif; ?>
              </td>
              <td><?= $u['role'] === 'admin' ? '<span class="badge text-bg-warning">' . te('role.admin') . '</span>' : te('role.user') ?></td>
              <td class="text-end num"><?= (int) $u['sub_count'] ?></td>
              <td><?= fdate(substr($u['created_at'], 0, 10)) ?></td>
              <td class="text-end">
                <div class="d-flex gap-1 justify-content-end flex-wrap">
                  <form method="post" action="actions/reset_user_password.php" class="d-flex gap-1">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <input type="password" name="new_password" class="form-control form-control-sm" style="width:9rem"
                      placeholder="<?= te('users.new_password') ?>" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
                    <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= te('users.reset_password') ?>"><i class="bi bi-key"></i></button>
                  </form>
                  <?php if (!$isMe): ?>
                  <form method="post" action="actions/delete_user.php" onsubmit="return confirm(<?= jsq(t('users.confirm_delete', ['name' => $u['username']])) ?>);">
                    <?= csrfField() ?>
                    <input type="hidden" name="id" value="<?= (int) $u['id'] ?>">
                    <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= te('users.delete') ?>"><i class="bi bi-trash"></i></button>
                  </form>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

    <form method="post" action="actions/add_user.php" class="card p-3 p-md-4">
      <?= csrfField() ?>
      <div class="form-label fw-semibold"><i class="bi bi-person-plus"></i> <?= te('users.add') ?></div>
      <div class="row g-2 mb-3">
        <div class="col-12 col-sm-4">
          <label class="form-label small mb-1" for="nu-name"><?= te('users.username') ?></label>
          <input type="text" name="username" id="nu-name" class="form-control" required pattern="[A-Za-z0-9_.\-]{3,32}" autocomplete="off">
        </div>
        <div class="col-12 col-sm-4">
          <label class="form-label small mb-1" for="nu-pw"><?= te('pw.new') ?></label>
          <input type="password" name="password" id="nu-pw" class="form-control" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
        </div>
        <div class="col-12 col-sm-4">
          <label class="form-label small mb-1" for="nu-pw2"><?= te('pw.confirm') ?></label>
          <input type="password" name="password_confirm" id="nu-pw2" class="form-control" required minlength="<?= MIN_PASSWORD_LENGTH ?>" autocomplete="new-password">
        </div>
      </div>
      <div class="form-text mb-2"><?= te('users.add_help') ?></div>
      <div><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= te('users.add_btn') ?></button></div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
