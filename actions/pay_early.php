<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../' . backPage());
    exit;
}
verifyCsrf();

$pdo = getDb();
$today = date('Y-m-d');

// Πληρωμή της ΕΠΟΜΕΝΗΣ δόσης νωρίτερα. Δεν είναι εξόφληση: η δόση καταχωρείται ως πληρωμένη
// (στη δική της ημερομηνία-περίοδο, με ξεχωριστή ημερομηνία πραγματικής πληρωμής) και η
// πληρωμή συνεχίζει κανονικά — η δόση μετά από αυτήν μένει στην κανονική της ημερομηνία.
$id = (int) ($_POST['id'] ?? 0);
$amountRaw = str_replace(',', '.', trim($_POST['amount'] ?? ''));
$paidOn = $_POST['paid_on'] ?? '';
useKindOf($pdo, $id);

$stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id = ?');
$stmt->execute([$id]);
$sub = $stmt->fetch();
if (!$sub || !in_array($sub['status'], ['active', 'trial'], true)) {
    flash('warning', t('err.early_unavailable'));
    header('Location: ../' . backPage());
    exit;
}

// Πρώτα καταχωρούνται οι δόσεις που έχουν ήδη λήξει, ώστε η "επόμενη" να είναι σωστή.
$pr = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
$pr->execute([$id]);
$prices = $pr->fetchAll();
$fr = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');
$fr->execute([$id]);
$freezes = $fr->fetchAll();
syncSubscriptionLedger($pdo, $sub, $prices, $freezes, $today);
$stmt->execute([$id]);
$sub = $stmt->fetch();

$pay = $pdo->prepare('SELECT id, payment_date, amount, is_estimate FROM subscription_payments WHERE subscription_id = ? ORDER BY payment_date ASC');
$pay->execute([$id]);
$pp = $pdo->prepare('SELECT installments, amount, kind FROM subscription_prepayments WHERE subscription_id = ?');
$pp->execute([$id]);
$stats = computeSubscriptionStats($sub, $prices, $freezes, $pay->fetchAll(), $today, $pp->fetchAll());
$slot = $stats['next_payment_date'];

if (!in_array($sub['status'], ['active', 'trial'], true) || $slot === null || $slot <= $today) {
    flash('warning', t('err.early_unavailable'));
    header('Location: ../' . backPage());
    exit;
}

$errors = [];
if (!is_numeric($amountRaw) || (float) $amountRaw < 0) $errors[] = t('err.invalid_amount');
if (!DateTime::createFromFormat('Y-m-d', $paidOn) || $paidOn < $sub['start_date'] || $paidOn > $today) $errors[] = t('err.prepay_date');
if ($errors) {
    flash('danger', implode(' ', $errors));
    header('Location: ../' . backPage());
    exit;
}

$amount = round((float) $amountRaw, 2);
$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO subscription_payments (subscription_id, payment_date, amount, is_estimate, paid_on) VALUES (?,?,?,0,?)')->execute([$id, $slot, $amount, $paidOn]);
    $pdo->prepare("UPDATE subscriptions SET updated_at = datetime('now') WHERE id = ?")->execute([$id]);
    reconcileInstallmentStatus($pdo, $id); // αν ήταν η τελευταία δόση, η πληρωμή ολοκληρώνεται
    logActivity($pdo, $id, $sub['name'], 'paid_early', ['slot' => $slot, 'amount' => $amount, 'paid_on' => $paidOn]);
    $pdo->commit();
    flash('success', t('msg.paid_early', ['name' => $sub['name'], 'date' => fdate($slot), 'amount' => euro($amount)]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
