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
$cost = $_POST['cost'] ?? '';
$effectiveFrom = $_POST['effective_from'] ?? '';

$pdo = getDb();

$errors = [];
if ($id <= 0) $errors[] = t('err.unknown_sub');
if (!is_numeric($cost) || (float) $cost < 0) $errors[] = t('err.invalid_cost');
if (!DateTime::createFromFormat('Y-m-d', $effectiveFrom)) $errors[] = t('err.invalid_date');

if (!$errors) {
    $check = $pdo->prepare('SELECT id FROM subscriptions WHERE id=?');
    $check->execute([$id]);
    if (!$check->fetch()) $errors[] = t('err.sub_not_found');
}

if ($errors) {
    flash('danger', implode(' ', $errors));
    header('Location: ../index.php');
    exit;
}

$stmt = $pdo->prepare('INSERT INTO subscription_prices (subscription_id, cost, effective_from) VALUES (?,?,?)');
$stmt->execute([$id, (float) $cost, $effectiveFrom]);
$pdo->prepare("UPDATE subscriptions SET updated_at=datetime('now') WHERE id=?")->execute([$id]);

// Η νέα τιμή μπορεί να αφορά ημερομηνία στο παρελθόν (π.χ. "τους πρώτους μήνες
// είχε χαμηλότερο κόστος") — διόρθωσε αναδρομικά το ποσό των ήδη
// καταχωρημένων πληρωμών από εκείνη την ημερομηνία και μετά.
$pricesStmt = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id=? ORDER BY effective_from ASC, id ASC');
$pricesStmt->execute([$id]);
$allPrices = $pricesStmt->fetchAll();

$affected = $pdo->prepare('SELECT id, payment_date FROM subscription_payments WHERE subscription_id=? AND payment_date >= ?');
$affected->execute([$id, $effectiveFrom]);
$rows = $affected->fetchAll();

$upd = $pdo->prepare('UPDATE subscription_payments SET amount=? WHERE id=?');
foreach ($rows as $r) {
    $upd->execute([priceAtDate($allPrices, $r['payment_date']), $r['id']]);
}

$msg = t('msg.price_added');
if (!empty($rows)) {
    $msg .= t('msg.price_retro', ['n' => count($rows)]);
}
flash('success', $msg);
header('Location: ../index.php');
exit;
