<?php
require __DIR__ . '/../config.php';
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/i18n.php';

requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ../cards.php');
    exit;
}
verifyCsrf();

$pdo = getDb();
$id = (int) ($_POST['id'] ?? 0);
$card = getCardsById($pdo)[$id] ?? null;

if ($card === null) {
    flash('danger', t('cards.not_found'));
} else {
    $used = $pdo->prepare('SELECT COUNT(*) FROM subscriptions WHERE card_id = ?');
    $used->execute([$id]);
    $usedCount = (int) $used->fetchColumn();
    if ($usedCount > 0) {
        // Δεν διαγράφεται κάρτα με συνδεδεμένες πληρωμές: πρώτα αλλάζεις τον τρόπο πληρωμής τους.
        flash('warning', t('cards.in_use', ['name' => $card['name'], 'n' => $usedCount]));
    } else {
        $pdo->prepare('DELETE FROM cards WHERE id = ?')->execute([$id]);
        flash('success', t('cards.deleted', ['name' => $card['name']]));
    }
}

header('Location: ../cards.php');
exit;
