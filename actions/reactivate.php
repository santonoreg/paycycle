<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require __DIR__ . '/../includes/i18n.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}
verifyCsrf();

$id = (int) ($_POST['id'] ?? 0);
$pdo = getDb();

$stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id=?');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub || $sub['status'] !== 'canceled') {
    flash('warning', t('msg.not_canceled'));
    header('Location: ../index.php');
    exit;
}

$today = date('Y-m-d');
$pdo->beginTransaction();
try {
    // Το διάστημα από την ακύρωση μέχρι σήμερα δεν είχε πραγματικές χρεώσεις —
    // το καταγράφουμε σαν "πάγωμα" ώστε το sync να μην το μετρήσει ως πληρωμένο.
    if (!empty($sub['canceled_date']) && $sub['canceled_date'] < $today) {
        $pdo->prepare('INSERT INTO subscription_freezes (subscription_id, frozen_from, frozen_until) VALUES (?,?,?)')
            ->execute([$id, $sub['canceled_date'], $today]);
    }
    $pdo->prepare("UPDATE subscriptions SET status='active', canceled_date=NULL, updated_at=datetime('now') WHERE id=?")->execute([$id]);
    $pdo->commit();
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
    header('Location: ../index.php');
    exit;
}

flash('success', t('msg.sub_reactivated', ['name' => $sub['name']]));
header('Location: ../index.php');
exit;
