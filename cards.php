<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';

$pdo = getDb();
$today = date('Y-m-d');
$loggedIn = isLoggedIn();
$cards = getCardsById($pdo);

// Ποιες πληρωμές είναι συνδεδεμένες σε κάθε κάρτα (και πόσο κοστίζουν τον μήνα).
$linked = [];
foreach (getAllSubscriptionsWithDetails($pdo, $today) as $d) {
    $cid = (int) ($d['sub']['card_id'] ?? 0);
    if ($cid > 0) {
        $linked[$cid][] = $d;
    }
}

$pageTitle = t('page.cards');
require __DIR__ . '/includes/header.php';
?>

<div class="row justify-content-center">
  <div class="col-12 col-lg-9 col-xl-8">
    <h4 class="mb-1"><i class="bi bi-credit-card"></i> <?= te('cards.title') ?></h4>
    <p class="text-muted small mb-3"><?= te('cards.intro') ?></p>

    <?php if (empty($cards)): ?>
      <div class="card empty-state mb-3"><div class="card-body"><i class="bi bi-credit-card d-block mb-2"></i><?= te('cards.none') ?></div></div>
    <?php endif; ?>

    <?php foreach ($cards as $c):
        $items = $linked[$c['id']] ?? [];
        $running = array_filter($items, fn($d) => isRunningStatus($d['sub']['status']));
        $monthly = array_sum(array_map(fn($d) => $d['stats']['monthly_equivalent'], $running));
    ?>
    <div class="card mb-3">
      <div class="card-body">
        <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
          <div>
            <div class="fw-semibold"><i class="bi bi-credit-card"></i> <?= htmlspecialchars($c['name']) ?> <span class="text-muted num">••••<?= htmlspecialchars($c['last4']) ?></span></div>
            <div class="small text-muted mt-1">
              <?= te('cards.linked', ['n' => count($items), 'running' => count($running)]) ?>
              · <?= te('cards.monthly') ?>: <strong class="num"><?= euro($monthly) ?></strong>
              <?php if ($items): ?>
                · <a href="index.php?card=<?= (int) $c['id'] ?>"><?= te('cards.view_list') ?></a>
              <?php endif; ?>
            </div>
          </div>
          <?php if ($loggedIn): ?>
          <div class="d-flex gap-1 flex-wrap">
            <form method="post" action="actions/edit_card.php" class="d-flex gap-1">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <input type="text" name="name" value="<?= htmlspecialchars($c['name']) ?>" class="form-control form-control-sm" style="width:9rem" required maxlength="40">
              <input type="text" name="last4" value="<?= htmlspecialchars($c['last4']) ?>" class="form-control form-control-sm num" style="width:4.5rem" required pattern="[0-9]{4}" maxlength="4" inputmode="numeric" title="<?= te('cards.last4_help') ?>">
              <button type="submit" class="btn btn-sm btn-outline-secondary" title="<?= te('common.save') ?>"><i class="bi bi-check-lg"></i></button>
            </form>
            <form method="post" action="actions/delete_card.php" onsubmit="return confirm(<?= jsq(t('cards.confirm_delete', ['name' => $c['name']])) ?>);">
              <?= csrfField() ?>
              <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
              <button type="submit" class="btn btn-sm btn-outline-danger" title="<?= te('menu.delete') ?>"><i class="bi bi-trash"></i></button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php if ($items): ?>
          <ul class="list-unstyled small mb-0 mt-2">
            <?php foreach ($items as $d): setKind($d['sub']['kind']); ?>
              <li class="d-flex justify-content-between border-top py-1">
                <span><?= htmlspecialchars($d['sub']['name']) ?> <span class="status-badge status-<?= $d['sub']['status'] ?> ms-1"><?= te('status.' . $d['sub']['status']) ?></span></span>
                <span class="num"><?= euro($d['stats']['current_price']) ?> · <?= te('freq.' . $d['sub']['frequency']) ?></span>
              </li>
            <?php endforeach; setKind('subscription'); ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <?php if ($loggedIn): ?>
    <form method="post" action="actions/add_card.php" class="card p-3 p-md-4">
      <?= csrfField() ?>
      <div class="form-label fw-semibold"><i class="bi bi-plus-lg"></i> <?= te('cards.add') ?></div>
      <div class="row g-2 mb-2">
        <div class="col-12 col-sm-8">
          <label class="form-label small mb-1" for="card-name"><?= te('cards.name') ?></label>
          <input type="text" name="name" id="card-name" class="form-control" required maxlength="40" placeholder="<?= te('cards.name_ph') ?>">
        </div>
        <div class="col-12 col-sm-4">
          <label class="form-label small mb-1" for="card-last4"><?= te('cards.last4') ?></label>
          <input type="text" name="last4" id="card-last4" class="form-control num" required pattern="[0-9]{4}" maxlength="4" inputmode="numeric" autocomplete="off">
        </div>
      </div>
      <div class="form-text mb-2"><?= te('cards.last4_help') ?></div>
      <div><button type="submit" class="btn btn-primary"><i class="bi bi-check-lg"></i> <?= te('cards.add_btn') ?></button></div>
    </form>
    <?php else: ?>
      <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(loginUrl('cards.php')) ?>"><i class="bi bi-lock"></i> <?= te('btn.login_manage') ?></a>
    <?php endif; ?>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
