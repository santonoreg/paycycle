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

$payId = (int) ($_POST['id'] ?? 0);
$stmt = $pdo->prepare('SELECT p.*, s.name AS sub_name, s.kind AS sub_kind, s.start_date FROM subscription_payments p JOIN subscriptions s ON s.id = p.subscription_id WHERE p.id = ?');
$stmt->execute([$payId]);
$row = $stmt->fetch();
// Διορθώνεται/ακυρώνεται μόνο μια δόση που πληρώθηκε νωρίτερα ΚΑΙ η ημερομηνία της δεν έχει φτάσει ακόμα.
if (!$row || empty($row['paid_on']) || $row['payment_date'] <= $today) {
    flash('warning', t('err.early_unavailable'));
    header('Location: ../' . backPage());
    exit;
}
$subId = (int) $row['subscription_id'];
setKind($row['sub_kind']);

$amountRaw = str_replace(',', '.', trim($_POST['amount'] ?? ''));
$paidOn = $_POST['paid_on'] ?? '';

$errors = [];
if (!is_numeric($amountRaw) || (float) $amountRaw < 0) $errors[] = t('err.invalid_amount');
if (!DateTime::createFromFormat('Y-m-d', $paidOn) || $paidOn < $row['start_date'] || $paidOn > $today) $errors[] = t('err.prepay_date');
if ($errors) {
    flash('danger', implode(' ', $errors));
    header('Location: ../' . backPage());
    exit;
}

$amount = round((float) $amountRaw, 2);
$pdo->beginTransaction();
try {
    $pdo->prepare('UPDATE subscription_payments SET amount = ?, paid_on = ? WHERE id = ?')->execute([$amount, $paidOn, $payId]);
    $pdo->prepare("UPDATE subscriptions SET updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
    logActivity($pdo, $subId, $row['sub_name'], 'paid_early_edited', ['slot' => $row['payment_date'], 'amount' => $amount, 'paid_on' => $paidOn]);
    $pdo->commit();
    flash('success', t('msg.early_updated', ['name' => $row['sub_name']]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
