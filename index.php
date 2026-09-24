<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';
require __DIR__ . '/includes/stats_helpers.php';

// recurring.php ορίζει $kind = 'recurring' και φορτώνει αυτό το αρχείο.
$kind = $kind ?? 'subscription';
setKind($kind);

$pdo = getDb();
$today = date('Y-m-d');

$allDetails = getAllSubscriptionsWithDetails($pdo, $today, $kind);
$categories = getDistinctCategories($pdo, $kind);
$paymentMethods = getDistinctPaymentMethods($pdo);

// --- Συγκεντρωτικά (πάντα υπολογισμένα στο σύνολο, ανεξάρτητα από φίλτρα) ----
$activeCount = 0;
$monthlySum = 0.0;
$annualSum = 0.0;
foreach ($allDetails as $d) {
    if (isRunningStatus($d['sub']['status'])) {
        $activeCount++;
        $monthlySum += $d['stats']['monthly_equivalent'];
        $annualSum += $d['stats']['annual_cost'];
    }
}
$upcoming = computeUpcomingTotals($allDetails, $today);
$pendingBills = 0;
foreach ($allDetails as $d) {
    if ($d['sub']['status'] !== 'canceled') {
        $pendingBills += $d['stats']['estimates_pending'];
    }
}

// --- Φίλτρα (GET) ------------------------------------------------------
// Αν δεν έχει επιλεγεί φίλτρο (πρώτο άνοιγμα), ισχύει το προεπιλεγμένο από τις Ρυθμίσεις.
$fStatus = $_GET['status'] ?? getSetting('default_status');
$fCategory = $_GET['category'] ?? getSetting('default_category');
if (!isset($_GET['category']) && $fCategory !== '' && !in_array($fCategory, $categories, true)) {
    $fCategory = ''; // η προεπιλεγμένη κατηγορία δεν υπάρχει πια
}
$fQuery = trim($_GET['q'] ?? '');

$details = array_values(array_filter($allDetails, function ($d) use ($fStatus, $fCategory, $fQuery) {
    if ($fStatus !== '' && $d['sub']['status'] !== $fStatus) return false;
    if ($fCategory !== '' && $d['sub']['category'] !== $fCategory) return false;
    if ($fQuery !== '' && stripos($d['sub']['name'], $fQuery) === false) return false;
    return true;
}));

// --- Ταξινόμηση: ενεργές/δοκιμαστικές πρώτα (κατά επόμενη πληρωμή), μετά
//     παγωμένες, μετά ακυρωμένες — μέσα σε κάθε ομάδα, αλφαβητικά ---------
$statusPriority = ['active' => 0, 'trial' => 0, 'frozen' => 1, 'canceled' => 2, 'paid_off' => 2];
usort($details, function ($a, $b) use ($statusPriority) {
    $pa = $statusPriority[$a['sub']['status']];
    $pb = $statusPriority[$b['sub']['status']];
    if ($pa !== $pb) return $pa <=> $pb;
    if ($pa === 0) {
        $da = $a['stats']['next_payment_date'] ?? '9999-99-99';
        $db_ = $b['stats']['next_payment_date'] ?? '9999-99-99';
        if ($da !== $db_) return $da <=> $db_;
    }
    return strcasecmp($a['sub']['name'], $b['sub']['name']);
});

$pageTitle = t('page.subscriptions');
$loggedIn = isLoggedIn();
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-1">
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="label"><?= te('idx.active_subs') ?></div>
      <div class="value num"><?= $activeCount ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card accent-ok">
      <div class="label"><?= te('idx.monthly_total') ?></div>
      <div class="value num"><?= euro($monthlySum) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card accent-trial">
      <div class="label"><?= te('idx.annual_total') ?></div>
      <div class="value num"><?= euro($annualSum) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card accent-gold">
      <div class="label"><?= te('idx.next_month') ?></div>
      <div class="value num"><?= euro($upcoming['next_month']) ?></div>
    </div>
  </div>
</div>
<div class="text-muted small mt-2 mb-4">
  <?= te('idx.rest_of_month') ?>: <strong class="num"><?= euro($upcoming['rest_of_this_month']) ?></strong>
</div>

<?php if ($pendingBills > 0): ?>
  <div class="alert alert-warning py-2 mb-3"><i class="bi bi-hourglass-split"></i> <?= te('var.pending_total', ['n' => $pendingBills]) ?></div>
<?php endif; ?>

<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <form class="d-flex flex-wrap gap-2" method="get">
    <select name="status" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value=""><?= te('filter.all_statuses') ?></option>
      <?php foreach (statuses() as $k => $label): if ($k === 'paid_off' && $kind !== 'recurring') continue; ?>
        <option value="<?= $k ?>" <?= $fStatus === $k ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="category" class="form-select form-select-sm" style="width:auto" onchange="this.form.submit()">
      <option value=""><?= te('filter.all_categories') ?></option>
      <?php foreach ($categories as $c): ?>
        <option value="<?= htmlspecialchars($c) ?>" <?= $fCategory === $c ? 'selected' : '' ?>><?= htmlspecialchars($c) ?></option>
      <?php endforeach; ?>
    </select>
    <input type="search" name="q" value="<?= htmlspecialchars($fQuery) ?>" class="form-control form-control-sm" style="width:auto" placeholder="<?= te('filter.search') ?>">
    <button class="btn btn-sm btn-outline-secondary" type="submit"><i class="bi bi-search"></i></button>
    <?php if ($fStatus || $fCategory || $fQuery): ?>
      <a href="<?= basename($_SERVER['SCRIPT_NAME']) ?>?status=&amp;category=" class="btn btn-sm btn-link"><?= te('common.clear') ?></a>
    <?php endif; ?>
  </form>

  <?php if ($loggedIn): ?>
    <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#addModal">
      <i class="bi bi-plus-lg"></i> <?= te('btn.new_sub') ?>
    </button>
  <?php else: ?>
    <a class="btn btn-outline-primary btn-sm" href="<?= htmlspecialchars(loginUrl(basename($_SERVER['SCRIPT_NAME']))) ?>">
      <i class="bi bi-plus-lg"></i> <?= te('btn.new_sub') ?>
    </a>
  <?php endif; ?>
</div>

<?php if (empty($details)): ?>
  <div class="card empty-state">
    <div class="card-body">
      <i class="bi bi-inboxes d-block mb-2"></i>
      <?= empty($allDetails) ? te('empty.none') : te('empty.no_match') ?>
    </div>
  </div>
<?php else: ?>
<div class="card">
  <div class="table-responsive">
    <table class="table subs-table mb-0">
      <thead>
        <tr>
          <th><?= te('th.subscription') ?></th>
          <th><?= te('th.cost') ?></th>
          <th><?= te('th.monthly_eq') ?></th>
          <th><?= te('th.from') ?></th>
          <th><?= te('th.next_payment') ?></th>
          <th class="text-end"><?= te('th.installments') ?></th>
          <th class="text-end"><?= te('th.total_paid') ?></th>
          <th><?= te('th.status') ?></th>
          <th></th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($details as $d):
        $sub = $d['sub']; $stats = $d['stats'];
        $days = daysUntil($stats['next_payment_date'], $today);
        $rowClass = !isRunningStatus($sub['status']) ? 'row-canceled' : '';
        $pricesJson = htmlspecialchars(json_encode(array_map(fn($p) => [
            'id' => (int) $p['id'], 'cost' => (float) $p['cost'], 'effective_from' => $p['effective_from'],
            'deletable' => $p['deletable'],
        ], $d['prices'])), ENT_QUOTES);
        // Τρέχουσα (σταθερή) εκτίμηση = η πιο πρόσφατη τιμή που ισχύει σήμερα
        $fixedEstimate = null;
        foreach ($d['prices'] as $pr) {
            if ($pr['effective_from'] <= $today || $fixedEstimate === null) {
                $fixedEstimate = (float) $pr['cost'];
            }
        }
        $freezesJson = htmlspecialchars(json_encode($d['freezes']), ENT_QUOTES);
        $paymentsJson = htmlspecialchars(json_encode(array_map(fn($p) => [
            'amount' => (float) $p['amount'], 'payment_date' => $p['payment_date'], 'is_estimate' => (int) $p['is_estimate'],
        ], $d['payments'])), ENT_QUOTES);
      ?>
        <tr class="<?= $rowClass ?>">
          <td>
            <div class="sub-name"><?= htmlspecialchars($sub['name']) ?></div>
            <div class="sub-category"><?= htmlspecialchars($sub['category']) ?></div>
          </td>
          <td class="num"><?= $stats['is_variable'] ? '≈ ' : '' ?><?= euro($stats['current_price']) ?><div class="sub-category"><?= te('freq.' . $sub['frequency']) ?><?= $stats['is_variable'] ? ' · ' . te('list.variable') : '' ?></div></td>
          <td class="num"><?= euro($stats['monthly_equivalent']) ?></td>
          <td><?= fdate($sub['start_date']) ?></td>
          <td class="num">
            <?php if ($stats['next_payment_date']): ?>
              <span class="<?= ($days !== null && $days <= 7) ? 'next-soon' : '' ?>"><?= fdate($stats['next_payment_date']) ?></span>
              <div class="sub-category"><?= $stats['is_variable'] ? '≈ ' : '' ?><?= euro($stats['next_payment_amount']) ?></div>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td class="text-end num">
            <?php if ($stats['installments_total'] !== null): ?>
              <?= $stats['installments_paid'] ?> / <?= $stats['installments_total'] ?>
              <div class="sub-category"><?= te('inst.remaining', ['n' => $stats['installments_remaining']]) ?></div>
            <?php else: ?>
              <?= $stats['installments_paid'] ?>
            <?php endif; ?>
          </td>
          <td class="text-end num"><?= euro($stats['total_paid']) ?></td>
          <td>
            <span class="status-badge status-<?= $sub['status'] ?>"><?= te('status.' . $sub['status']) ?></span>
            <?php if ($stats['estimates_pending'] > 0 && $sub['status'] !== 'canceled'): ?>
              <div><span class="awaiting-badge" title="<?= te('var.badge_hint') ?>"><i class="bi bi-hourglass-split"></i> <?= te('var.pending_short', ['n' => $stats['estimates_pending']]) ?></span></div>
            <?php endif; ?>
          </td>
          <td class="text-end">
            <div class="d-flex gap-1 justify-content-end">
              <button type="button" class="btn btn-sm btn-outline-primary action-icon-btn" data-bs-toggle="modal" data-bs-target="#detailsModal"
                data-id="<?= $sub['id'] ?>"
                data-name="<?= htmlspecialchars($sub['name']) ?>"
                data-category="<?= htmlspecialchars($sub['category']) ?>"
                data-frequency="<?= $sub['frequency'] ?>"
                data-payment-method="<?= htmlspecialchars($sub['payment_method'] ?? '') ?>"
                data-start-date="<?= $sub['start_date'] ?>"
                data-notes="<?= htmlspecialchars($sub['notes'] ?? '') ?>"
                data-estimate="<?= $fixedEstimate !== null ? number_format($fixedEstimate, 2, '.', '') : '' ?>"
                data-variable="<?= !empty($sub['variable_amount']) ? 1 : 0 ?>"
                data-installments="<?= (int) ($sub['total_installments'] ?? 0) ?: '' ?>"
                data-status="<?= $sub['status'] ?>"
                data-current-price="<?= $stats['current_price'] ?>"
                data-prices='<?= $pricesJson ?>'
                data-freezes='<?= $freezesJson ?>'
                data-payments='<?= $paymentsJson ?>'
                title="<?= $loggedIn ? te('title.edit') : te('title.details') ?>">
                <i class="bi bi-<?= $loggedIn ? 'pencil' : 'info-circle' ?>"></i>
              </button>
              <?php if ($loggedIn): ?>
              <div class="dropdown">
                <button class="btn btn-sm btn-outline-secondary action-icon-btn" data-bs-toggle="dropdown"><i class="bi bi-three-dots-vertical"></i></button>
                <ul class="dropdown-menu dropdown-menu-end">
                  <?php if (in_array($sub['status'], ['active', 'trial'], true)): ?>
                    <li>
                      <form method="post" action="actions/freeze.php">
                        <?= csrfField() ?><input type="hidden" name="id" value="<?= $sub['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-snow2 me-2"></i><?= te('menu.freeze') ?></button>
                      </form>
                    </li>
                  <?php elseif ($sub['status'] === 'frozen'): ?>
                    <li>
                      <form method="post" action="actions/unfreeze.php">
                        <?= csrfField() ?><input type="hidden" name="id" value="<?= $sub['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-play-circle me-2"></i><?= te('menu.unfreeze') ?></button>
                      </form>
                    </li>
                  <?php endif; ?>

                  <?php if (isRunningStatus($sub['status'])): ?>
                    <li>
                      <form method="post" action="actions/cancel.php" onsubmit="return confirm(<?= jsq(t('confirm.cancel', ['name' => $sub['name']])) ?>);">
                        <?= csrfField() ?><input type="hidden" name="id" value="<?= $sub['id'] ?>">
                        <button type="submit" class="dropdown-item text-danger"><i class="bi bi-x-circle me-2"></i><?= te('menu.cancel') ?></button>
                      </form>
                    </li>
                  <?php elseif ($sub['status'] === 'canceled'): ?>
                    <li>
                      <form method="post" action="actions/reactivate.php">
                        <?= csrfField() ?><input type="hidden" name="id" value="<?= $sub['id'] ?>">
                        <button type="submit" class="dropdown-item"><i class="bi bi-arrow-counterclockwise me-2"></i><?= te('menu.reactivate') ?></button>
                      </form>
                    </li>
                  <?php endif; ?>
                  <li><hr class="dropdown-divider"></li>
                  <li>
                    <form method="post" action="actions/delete_subscription.php" onsubmit="return confirm(<?= jsq(t('confirm.delete', ['name' => $sub['name']])) ?>);">
                      <?= csrfField() ?><input type="hidden" name="id" value="<?= $sub['id'] ?>">
                      <button type="submit" class="dropdown-item text-danger"><i class="bi bi-trash me-2"></i><?= te('menu.delete') ?></button>
                    </form>
                  </li>
                </ul>
              </div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($loggedIn): ?>
<!-- ===================== Modal: Νέα Συνδρομή ===================== -->
<div class="modal fade" id="addModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <form method="post" action="actions/add_subscription.php">
        <?= csrfField() ?>
        <input type="hidden" name="kind" value="<?= $kind ?>">
        <div class="modal-header">
          <h5 class="modal-title"><i class="bi bi-plus-lg"></i> <?= te('modal.new_sub') ?></h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
        </div>
        <div class="modal-body">
          <div class="mb-3">
            <label class="form-label"><?= te('field.name') ?></label>
            <input type="text" name="name" class="form-control" required autofocus>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label"><?= te('field.category') ?></label>
              <input type="text" name="category" class="form-control" list="categories-list" required>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label"><?= te('field.payment_method') ?></label>
              <input type="text" name="payment_method" class="form-control" list="payment-methods-list">
            </div>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label"><?= te('field.frequency') ?></label>
              <select name="frequency" class="form-select" required>
                <?php foreach (frequencies() as $k => $label): ?>
                  <option value="<?= $k ?>" <?= $k === 'monthly' ? 'selected' : '' ?>><?= htmlspecialchars($label) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label"><?= te('field.cost') ?></label>
              <input type="number" step="0.01" min="0" name="cost" class="form-control" required>
            </div>
          </div>
          <div class="row">
            <div class="col-6 mb-3">
              <label class="form-label"><?= te('field.start_date') ?></label>
              <input type="date" name="start_date" class="form-control" value="<?= $today ?>" required>
            </div>
            <div class="col-6 mb-3">
              <label class="form-label"><?= te('field.status') ?></label>
              <select name="status" class="form-select">
                <option value="active"><?= te('status.active') ?></option>
                <option value="trial"><?= te('status.trial') ?></option>
              </select>
            </div>
          </div>
          <?php if ($kind === 'recurring'): ?>
          <div class="mb-3">
            <label class="form-label"><?= te('field.installments') ?></label>
            <input type="number" min="1" max="1200" step="1" name="installments" class="form-control" placeholder="<?= te('field.installments_ph') ?>">
            <div class="form-text"><?= te('field.installments_help') ?></div>
          </div>
          <div class="form-check mb-3">
            <input class="form-check-input" type="checkbox" name="variable_amount" value="1" id="add-variable">
            <label class="form-check-label" for="add-variable"><?= te('field.variable') ?></label>
            <div class="form-text"><?= te('field.variable_help') ?></div>
          </div>
          <?php endif; ?>
          <div class="mb-1">
            <label class="form-label"><?= te('field.notes') ?></label>
            <textarea name="notes" class="form-control" rows="2"></textarea>
          </div>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?= te('common.cancel') ?></button>
          <button type="submit" class="btn btn-primary"><?= te('common.save') ?></button>
        </div>
      </form>
    </div>
  </div>
</div>
<?php endif; ?>

<datalist id="categories-list">
  <?php foreach ($categories as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?php endforeach; ?>
</datalist>
<datalist id="payment-methods-list">
  <?php foreach ($paymentMethods as $p): ?><option value="<?= htmlspecialchars($p) ?>"><?php endforeach; ?>
</datalist>

<!-- ===================== Modal: Λεπτομέρειες / Επεξεργασία ===================== -->
<div class="modal fade" id="detailsModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header">
        <h5 class="modal-title" id="detailsModalTitle"><?= te('modal.default_title') ?></h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <ul class="nav nav-tabs mb-3" role="tablist">
          <li class="nav-item"><button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tab-info" type="button"><?= te('tab.info') ?></button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-payments" type="button"><?= te('tab.payments') ?></button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-prices" type="button"><?= te('tab.prices') ?></button></li>
          <li class="nav-item"><button class="nav-link" data-bs-toggle="tab" data-bs-target="#tab-freezes" type="button"><?= te('tab.freezes') ?></button></li>
        </ul>
        <div class="tab-content">
          <!-- Στοιχεία -->
          <div class="tab-pane fade show active" id="tab-info">
            <?php if ($loggedIn): ?>
              <form method="post" action="actions/edit_subscription.php" id="editInfoForm">
                <?= csrfField() ?>
                <input type="hidden" name="id" id="edit-id">
                <div class="mb-3">
                  <label class="form-label"><?= te('field.name') ?></label>
                  <input type="text" name="name" id="edit-name" class="form-control" required>
                </div>
                <div class="row">
                  <div class="col-6 mb-3">
                    <label class="form-label"><?= te('field.category') ?></label>
                    <input type="text" name="category" id="edit-category" class="form-control" list="categories-list" required>
                  </div>
                  <div class="col-6 mb-3">
                    <label class="form-label"><?= te('field.payment_method') ?></label>
                    <input type="text" name="payment_method" id="edit-payment-method" class="form-control" list="payment-methods-list">
                  </div>
                </div>
                <div class="row">
                  <div class="col-6 mb-3">
                    <label class="form-label"><?= te('field.frequency') ?></label>
                    <select name="frequency" id="edit-frequency" class="form-select">
                      <?php foreach (frequencies() as $k => $label): ?>
                        <option value="<?= $k ?>"><?= htmlspecialchars($label) ?></option>
                      <?php endforeach; ?>
                    </select>
                  </div>
                  <div class="col-6 mb-3">
                    <label class="form-label"><?= te('field.start_date') ?></label>
                    <input type="date" name="start_date" id="edit-start-date" class="form-control" required>
                  </div>
                </div>
                <?php if ($kind === 'recurring'): ?>
                <div class="mb-3">
                  <label class="form-label"><?= te('field.installments') ?></label>
                  <input type="number" min="1" max="1200" step="1" name="installments" id="edit-installments" class="form-control" placeholder="<?= te('field.installments_ph') ?>">
                  <div class="form-text"><?= te('field.installments_help') ?></div>
                </div>
                <div class="form-check mb-3">
                  <input class="form-check-input" type="checkbox" name="variable_amount" value="1" id="edit-variable">
                  <label class="form-check-label" for="edit-variable"><?= te('field.variable') ?></label>
                  <div class="form-text"><?= te('field.variable_help') ?></div>
                </div>
                <div class="mb-3" id="edit-estimate-wrap">
                  <label class="form-label" for="edit-estimate"><?= te('field.estimate') ?></label>
                  <input type="number" step="0.01" min="0" name="estimate" id="edit-estimate" class="form-control">
                  <div class="form-text"><?= te('field.estimate_help') ?></div>
                </div>
                <?php endif; ?>
                <div class="mb-3">
                  <label class="form-label"><?= te('field.notes') ?></label>
                  <textarea name="notes" id="edit-notes" class="form-control" rows="2"></textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-sm"><?= te('btn.save_info') ?></button>
              </form>
            <?php else: ?>
              <dl class="row mb-0">
                <dt class="col-4"><?= te('field.category') ?></dt><dd class="col-8" id="ro-category"></dd>
                <dt class="col-4"><?= te('field.frequency') ?></dt><dd class="col-8" id="ro-frequency"></dd>
                <dt class="col-4"><?= te('field.payment_method') ?></dt><dd class="col-8" id="ro-payment-method"></dd>
                <dt class="col-4"><?= te('field.start_date') ?></dt><dd class="col-8" id="ro-start-date"></dd>
                <?php if ($kind === 'recurring'): ?>
                <dt class="col-4"><?= te('field.installments') ?></dt><dd class="col-8" id="ro-installments"></dd>
                <?php endif; ?>
                <dt class="col-4"><?= te('field.notes') ?></dt><dd class="col-8" id="ro-notes"></dd>
              </dl>
              <a href="<?= htmlspecialchars(loginUrl(basename($_SERVER['SCRIPT_NAME']))) ?>" class="btn btn-outline-primary btn-sm mt-2">
                <i class="bi bi-lock"></i> <?= te('btn.login_manage') ?>
              </a>
            <?php endif; ?>
          </div>
          <!-- Πληρωμές (βιβλίο καταχωρημένων δόσεων) -->
          <div class="tab-pane fade" id="tab-payments">
            <div class="d-flex justify-content-between align-items-center mb-2">
              <span class="text-muted small"><?= te('payments.registered') ?></span>
              <span class="fw-semibold num" id="payments-total"></span>
            </div>
            <div class="history-list" id="payments-list"></div>
          </div>
          <!-- Ιστορικό τιμών -->
          <div class="tab-pane fade" id="tab-prices">
            <div class="history-list mb-3" id="prices-list"></div>
            <?php if ($loggedIn): ?>
              <hr>
              <form method="post" action="actions/edit_price.php" class="row g-2 align-items-end" id="editPriceForm">
                <?= csrfField() ?>
                <input type="hidden" name="id" id="price-sub-id">
                <div class="col-4">
                  <label class="form-label small mb-1"><?= te('prices.new_cost') ?></label>
                  <input type="number" step="0.01" min="0" name="cost" class="form-control form-control-sm" required>
                </div>
                <div class="col-4">
                  <label class="form-label small mb-1"><?= te('prices.effective_from') ?></label>
                  <input type="date" name="effective_from" class="form-control form-control-sm" value="<?= $today ?>" required>
                </div>
                <div class="col-4">
                  <button type="submit" class="btn btn-sm btn-primary w-100"><?= te('btn.add_price') ?></button>
                </div>
              </form>
            <?php endif; ?>
          </div>
          <!-- Παγώματα -->
          <div class="tab-pane fade" id="tab-freezes">
            <div class="history-list" id="freezes-list"></div>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
