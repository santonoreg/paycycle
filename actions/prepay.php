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

// Εξόφληση δόσεων: πλήρης (όλες όσες απομένουν) ή ενός πλήθους δόσεων. Οι δόσεις
// που εξοφλούνται αφαιρούνται από το ΤΕΛΟΣ του πλάνου· οι ημερομηνίες των υπόλοιπων
// δόσεων δεν αλλάζουν.
$id = (int) ($_POST['id'] ?? 0);
$mode = ($_POST['mode'] ?? 'full') === 'count' ? 'count' : 'full';
$amountRaw = str_replace(',', '.', trim($_POST['amount'] ?? ''));
$paidOn = $_POST['paid_on'] ?? '';
$today = date('Y-m-d');

$pdo = getDb();
useKindOf($pdo, $id);

$stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id = ?');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub || $sub['total_installments'] === null || !in_array($sub['status'], ['active', 'trial', 'frozen'], true)) {
    flash('warning', t('err.prepay_unavailable'));
    header('Location: ../' . backPage());
    exit;
}

// Πρώτα καταχωρούνται οι δόσεις που έχουν ήδη λήξει μέχρι σήμερα, ώστε το "πόσες απομένουν" να είναι σωστό.
$pricesStmt = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
$pricesStmt->execute([$id]);
$freezesStmt = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');
$freezesStmt->execute([$id]);
syncSubscriptionLedger($pdo, $sub, $pricesStmt->fetchAll(), $freezesStmt->fetchAll(), $today);

$stmt->execute([$id]);
$sub = $stmt->fetch();
$recorded = (int) $pdo->query('SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = ' . $id)->fetchColumn();
$remaining = (int) $sub['total_installments'] - $recorded - prepaidInstallments($pdo, $id);

if ($sub['status'] === 'paid_off' || $remaining < 1) {
    flash('warning', t('err.prepay_unavailable'));
    header('Location: ../' . backPage());
    exit;
}

$count = $mode === 'full' ? $remaining : (ctype_digit(trim($_POST['installments'] ?? '')) ? (int) trim($_POST['installments']) : 0);

$errors = [];
if ($count < 1 || $count > $remaining) $errors[] = t('err.prepay_count', ['n' => $remaining]);
if (!is_numeric($amountRaw) || (float) $amountRaw < 0) $errors[] = t('err.invalid_amount');
if (!DateTime::createFromFormat('Y-m-d', $paidOn) || $paidOn < $sub['start_date'] || $paidOn > $today) $errors[] = t('err.prepay_date');

if ($errors) {
    flash('danger', implode(' ', $errors));
    header('Location: ../' . backPage());
    exit;
}

$amount = round((float) $amountRaw, 2);
$isFull = $count === $remaining;

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO subscription_prepayments (subscription_id, paid_on, installments, amount, created_by_name) VALUES (?,?,?,?,?)')
        ->execute([$id, $paidOn, $count, $amount, currentUser()['username']]);
    if ($isFull) {
        $pdo->prepare("UPDATE subscriptions SET status = 'paid_off', updated_at = datetime('now') WHERE id = ?")->execute([$id]);
    } else {
        $pdo->prepare("UPDATE subscriptions SET updated_at = datetime('now') WHERE id = ?")->execute([$id]);
    }
    logActivity($pdo, $id, $sub['name'], 'prepaid', ['installments' => $count, 'amount' => $amount, 'date' => $paidOn, 'full' => $isFull]);
    $pdo->commit();
    flash('success', $isFull
        ? t('msg.prepaid_full', ['name' => $sub['name'], 'amount' => euro($amount)])
        : t('msg.prepaid', ['name' => $sub['name'], 'n' => $count, 'amount' => euro($amount)]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
