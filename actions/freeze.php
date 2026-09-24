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

if (!$sub || !in_array($sub['status'], ['active', 'trial'], true)) {
    flash('warning', t('msg.cannot_freeze'));
    header('Location: ../index.php');
    exit;
}

$pdo->beginTransaction();
try {
    $pdo->prepare('INSERT INTO subscription_freezes (subscription_id, frozen_from, frozen_until) VALUES (?,?,NULL)')->execute([$id, $today]);
    $pdo->prepare("UPDATE subscriptions SET status='frozen', updated_at=datetime('now') WHERE id=?")->execute([$id]);
    $pdo->commit();
    flash('success', t('msg.sub_frozen', ['name' => $sub['name']]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../index.php');
exit;
