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

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM subscription_payments WHERE id = ?')->execute([$payId]);
    $pdo->prepare("UPDATE subscriptions SET updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
    reconcileInstallmentStatus($pdo, $subId); // π.χ. Ολοκληρωμένη -> ξανά Ενεργή
    logActivity($pdo, $subId, $row['sub_name'], 'paid_early_removed', ['slot' => $row['payment_date'], 'amount' => (float) $row['amount'], 'paid_on' => $row['paid_on']]);
    $pdo->commit();
    flash('success', t('msg.early_removed', ['name' => $row['sub_name'], 'date' => fdate($row['payment_date'])]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
