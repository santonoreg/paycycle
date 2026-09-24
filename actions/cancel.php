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

$id = (int) ($_POST['id'] ?? 0);
$today = date('Y-m-d');
$pdo = getDb();
useKindOf($pdo, $id);

$stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id=?');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub || $sub['status'] === 'canceled') {
    flash('warning', t('msg.cancel_unavailable'));
    header('Location: ../' . backPage());
    exit;
}

// Πιάσε τυχόν εκκρεμείς δόσεις μέχρι σήμερα ΠΡΙΝ κλειδώσεις τη συνδρομή ως
// ακυρωμένη (μετά την ακύρωση το sync δεν την αγγίζει πια).
$pricesStmt = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id=? ORDER BY effective_from ASC, id ASC');
$pricesStmt->execute([$id]);
$prices = $pricesStmt->fetchAll();
$freezesStmt = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id=? ORDER BY frozen_from ASC');
$freezesStmt->execute([$id]);
$freezes = $freezesStmt->fetchAll();
syncSubscriptionLedger($pdo, $sub, $prices, $freezes, $today);

$stmt = $pdo->prepare("UPDATE subscriptions SET status='canceled', canceled_date=?, updated_at=datetime('now') WHERE id=?");
$stmt->execute([$today, $id]);

flash('success', t('msg.sub_canceled', ['name' => $sub['name']]));
header('Location: ../' . backPage());
exit;
