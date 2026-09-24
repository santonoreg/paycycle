<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../index.php');
    exit;
}
verifyCsrf();

$subId = (int) ($_POST['id'] ?? 0);
$priceId = (int) ($_POST['price_id'] ?? 0);

$pdo = getDb();

$pricesStmt = $pdo->prepare('SELECT id, cost, effective_from FROM subscription_prices WHERE subscription_id=? ORDER BY effective_from ASC, id ASC');
$pricesStmt->execute([$subId]);
$prices = $pricesStmt->fetchAll();

$index = null;
foreach ($prices as $i => $p) {
    if ((int) $p['id'] === $priceId) {
        $index = $i;
        break;
    }
}

$paymentsStmt = $pdo->prepare('SELECT payment_date FROM subscription_payments WHERE subscription_id=? ORDER BY payment_date ASC');
$paymentsStmt->execute([$subId]);
$payments = $paymentsStmt->fetchAll();

if ($index === null) {
    flash('danger', t('msg.price_not_found'));
} elseif (count($prices) < 2) {
    flash('warning', t('msg.price_only_one'));
} elseif (priceIsUsedByPayments($prices, $index, $payments)) {
    flash('warning', t('msg.price_in_use'));
} else {
    $pdo->prepare('DELETE FROM subscription_prices WHERE id=? AND subscription_id=?')->execute([$priceId, $subId]);
    $pdo->prepare("UPDATE subscriptions SET updated_at=datetime('now') WHERE id=?")->execute([$subId]);
    flash('success', t('msg.price_deleted'));
}

header('Location: ../index.php');
exit;
