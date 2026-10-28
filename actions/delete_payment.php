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

// Διαγραφή μιας καταχωρημένης δόσης (π.χ. καταχωρήθηκαν λάθος δόσεις επειδή η συχνότητα ήταν λάθος).
$subId = (int) ($_POST['id'] ?? 0);
$date = $_POST['payment_date'] ?? '';
$today = date('Y-m-d');

$pdo = getDb();
useKindOf($pdo, $subId);

$stmt = $pdo->prepare('SELECT p.*, s.name AS sub_name FROM subscription_payments p JOIN subscriptions s ON s.id = p.subscription_id WHERE p.subscription_id = ? AND p.payment_date = ?');
$stmt->execute([$subId, $date]);
$row = $stmt->fetch();
if (!$row) {
    flash('warning', t('err.payment_not_found'));
    header('Location: ../' . backPage());
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM subscription_payments WHERE id = ?')->execute([$row['id']]);
    if ($date <= $today) {
        // Η δόση "παραλείπεται": χωρίς αυτό, το βιβλίο πληρωμών θα την ξαναδημιουργούσε στην επόμενη φόρτωση.
        $pdo->prepare('INSERT INTO subscription_freezes (subscription_id, frozen_from, frozen_until, is_skip) VALUES (?,?,?,1)')->execute([$subId, $date, $date]);
    }
    reconcileInstallmentStatus($pdo, $subId);
    refreshEstimates($pdo, $subId); // μεταβλητά ποσά: οι εκτιμήσεις ακολουθούν τους επιβεβαιωμένους λογαριασμούς
    $pdo->prepare("UPDATE subscriptions SET updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
    logActivity($pdo, $subId, $row['sub_name'], 'payment_deleted', ['date' => $date, 'amount' => (float) $row['amount']]);
    $pdo->commit();
    flash('success', t('msg.payment_deleted', ['name' => $row['sub_name'], 'date' => fdate($date)]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
