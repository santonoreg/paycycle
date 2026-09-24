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

$stmt = $pdo->prepare('SELECT name FROM subscriptions WHERE id=?');
$stmt->execute([$id]);
$sub = $stmt->fetch();

if (!$sub) {
    flash('warning', t('err.sub_not_found'));
    header('Location: ../' . backPage());
    exit;
}

$pdo->prepare('DELETE FROM subscriptions WHERE id=?')->execute([$id]);

flash('success', t('msg.sub_deleted', ['name' => $sub['name']]));
header('Location: ../' . backPage());
exit;
