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

$id = (int) ($_POST['id'] ?? 0);
$today = date('Y-m-d');
$pdo = getDb();

$stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id=?');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub || $sub['status'] !== 'frozen') {
    flash('warning', t('msg.not_frozen'));
    header('Location: ../index.php');
    exit;
}

$openFreeze = $pdo->prepare('SELECT id, frozen_from FROM subscription_freezes WHERE subscription_id=? AND frozen_until IS NULL ORDER BY frozen_from DESC LIMIT 1');
$openFreeze->execute([$id]);
$freeze = $openFreeze->fetch();

$pdo->beginTransaction();
try {
    if ($freeze) {
        if ($freeze['frozen_from'] === $today) {
            // Πάγωσε και ξεπάγωσε την ίδια μέρα -> σβήνουμε το πάγωμα εντελώς.
            $pdo->prepare('DELETE FROM subscription_freezes WHERE id=?')->execute([$freeze['id']]);
        } else {
            $pdo->prepare('UPDATE subscription_freezes SET frozen_until=? WHERE id=?')->execute([$today, $freeze['id']]);
        }
    }
    $pdo->prepare("UPDATE subscriptions SET status='active', updated_at=datetime('now') WHERE id=?")->execute([$id]);
    $pdo->commit();

    $pricesStmt = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id=? ORDER BY effective_from ASC, id ASC');
    $pricesStmt->execute([$id]);
    $prices = $pricesStmt->fetchAll();
    $freezesStmt = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id=? ORDER BY frozen_from ASC');
    $freezesStmt->execute([$id]);
    $freezes = $freezesStmt->fetchAll();
    $sub['status'] = 'active';
    syncSubscriptionLedger($pdo, $sub, $prices, $freezes, $today);

    flash('success', t('msg.sub_unfrozen', ['name' => $sub['name']]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../index.php');
exit;
