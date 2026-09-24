<?php
// Επιβεβαίωση (ή διόρθωση) του πραγματικού ποσού ενός λογαριασμού με μεταβλητό ποσό.
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

$id = (int) ($_POST['id'] ?? 0);
$date = $_POST['payment_date'] ?? '';
$amount = str_replace(',', '.', trim($_POST['amount'] ?? ''));

$pdo = getDb();
useKindOf($pdo, $id);

if (!is_numeric($amount) || (float) $amount < 0 || !DateTime::createFromFormat('Y-m-d', $date)) {
    flash('danger', t('err.invalid_amount'));
} else {
    $stmt = $pdo->prepare('UPDATE subscription_payments SET amount = ?, is_estimate = 0
                           WHERE subscription_id = ? AND payment_date = ?
                             AND (SELECT variable_amount FROM subscriptions WHERE id = ?) = 1');
    $stmt->execute([round((float) $amount, 2), $id, $date, $id]);
    if ($stmt->rowCount() === 0) {
        flash('warning', t('err.sub_not_found'));
    } else {
        refreshEstimates($pdo, $id); // οι υπόλοιπες εκτιμήσεις προσαρμόζονται στον νέο μέσο όρο
        $pdo->prepare("UPDATE subscriptions SET updated_at=datetime('now') WHERE id=?")->execute([$id]);
        flash('success', t('msg.amount_saved'));
    }
}

header('Location: ../' . backPage());
exit;