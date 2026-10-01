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
$pdo = getDb();
useKindOf($pdo, $id);

$stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id=?');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub || !in_array($sub['status'], ['canceled', 'expired'], true)) {
    flash('warning', t('msg.not_canceled'));
    header('Location: ../' . backPage());
    exit;
}

try {
    reactivateSubscription($pdo, $sub, date('Y-m-d'));
} catch (Throwable $e) {
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
    header('Location: ../' . backPage());
    exit;
}

flash('success', t('msg.sub_reactivated', ['name' => $sub['name']]));
header('Location: ../' . backPage());
exit;
