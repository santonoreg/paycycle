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
$prepId = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT p.*, s.name AS sub_name, s.status AS sub_status, s.total_installments, s.start_date, s.kind FROM subscription_prepayments p JOIN subscriptions s ON s.id = p.subscription_id WHERE p.id = ?');
$stmt->execute([$prepId]);
$prep = $stmt->fetch();
if (!$prep) {
    flash('warning', t('err.prepay_unavailable'));
    header('Location: ../' . backPage());
    exit;
}
$subId = (int) $prep['subscription_id'];
setKind($prep['kind']);

/** Μετά από αλλαγή εξόφλησης: ενημέρωσε την κατάσταση και συμπλήρωσε τις δόσεις που έχουν ήδη λήξει. */
function settleAfterPrepaymentChange(PDO $pdo, int $subId, string $today): void
{
    $row = $pdo->query('SELECT * FROM subscriptions WHERE id = ' . $subId)->fetch();
    $recorded = (int) $pdo->query('SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = ' . $subId)->fetchColumn();
    $remaining = (int) $row['total_installments'] - $recorded - prepaidInstallments($pdo, $subId);
    if ($remaining <= 0 && in_array($row['status'], ['active', 'trial', 'frozen'], true)) {
        $pdo->prepare("UPDATE subscriptions SET status = 'paid_off', updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
        return;
    }
    // Ολοκληρωμένη που ξαναγίνεται ενεργή (απομένουν δόσεις) -> και καταχώρηση όσων έχουν ήδη λήξει
    reconcileInstallmentStatus($pdo, $subId);
    $pr = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
    $pr->execute([$subId]);
    $fr = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');
    $fr->execute([$subId]);
    $row = $pdo->query('SELECT * FROM subscriptions WHERE id = ' . $subId)->fetch();
    if (!in_array($row['status'], ['canceled', 'expired'], true)) {
        syncSubscriptionLedger($pdo, $row, $pr->fetchAll(), $fr->fetchAll(), $today);
    }
}

$count = ctype_digit(trim($_POST['installments'] ?? '')) ? (int) trim($_POST['installments']) : 0;
$amountRaw = str_replace(',', '.', trim($_POST['amount'] ?? ''));
$paidOn = $_POST['paid_on'] ?? '';

// Πόσες δόσεις μπορεί να καλύπτει αυτή η εξόφληση: όσες δεν είναι ήδη καταχωρημένες ως προγραμματισμένες ή εξοφλημένες αλλού.
$recorded = (int) $pdo->query('SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = ' . $subId)->fetchColumn();
$others = prepaidInstallments($pdo, $subId) - (int) $prep['installments'];
$max = (int) $prep['total_installments'] - $recorded - $others;

$errors = [];
if ($count < 1 || $count > $max) $errors[] = t('err.prepay_count', ['n' => max(1, $max)]);
if (!is_numeric($amountRaw) || (float) $amountRaw < 0) $errors[] = t('err.invalid_amount');
if (!DateTime::createFromFormat('Y-m-d', $paidOn) || $paidOn < $prep['start_date'] || $paidOn > $today) $errors[] = t('err.prepay_date');

if ($errors) {
    flash('danger', implode(' ', $errors));
    header('Location: ../' . backPage());
    exit;
}

$amount = round((float) $amountRaw, 2);
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE subscription_prepayments SET installments = ?, amount = ?, paid_on = ? WHERE id = ?')->execute([$count, $amount, $paidOn, $prepId]);
    $pdo->prepare("UPDATE subscriptions SET updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
    settleAfterPrepaymentChange($pdo, $subId, $today);
    logActivity($pdo, $subId, $prep['sub_name'], 'prepaid_edited', ['installments' => $count, 'amount' => $amount, 'date' => $paidOn]);
    $pdo->commit();
    flash('success', t('msg.prepay_updated', ['name' => $prep['sub_name']]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
