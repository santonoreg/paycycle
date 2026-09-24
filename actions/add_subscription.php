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

$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? '');
$frequency = $_POST['frequency'] ?? '';
$paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
$startDate = $_POST['start_date'] ?? '';
$cost = $_POST['cost'] ?? '';
$status = $_POST['status'] ?? 'active';
$notes = trim($_POST['notes'] ?? '') ?: null;
$installmentsRaw = trim($_POST['installments'] ?? '');
$kind = in_array($_POST['kind'] ?? '', KINDS, true) ? $_POST['kind'] : 'subscription';
setKind($kind);
// Πλήθος δόσεων: προαιρετικό, μόνο για επαναλαμβανόμενες πληρωμές
$installments = null;

$errors = [];
if ($name === '') $errors[] = t('err.name_required');
if ($category === '') $errors[] = t('err.category_required');
if (!in_array($frequency, FREQUENCY_KEYS, true)) $errors[] = t('err.invalid_frequency');
if (!DateTime::createFromFormat('Y-m-d', $startDate)) $errors[] = t('err.invalid_start');
if (!is_numeric($cost) || (float) $cost < 0) $errors[] = t('err.invalid_cost');
if (!in_array($status, ['active', 'trial'], true)) $status = 'active';
if ($kind === 'recurring' && $installmentsRaw !== '') {
    if (!ctype_digit($installmentsRaw) || (int) $installmentsRaw < 1 || (int) $installmentsRaw > 1200) {
        $errors[] = t('err.invalid_installments');
    } else {
        $installments = (int) $installmentsRaw;
    }
}

if ($errors) {
    flash('danger', implode(' ', $errors));
    header('Location: ../' . backPage());
    exit;
}

$pdo = getDb();
$today = date('Y-m-d');
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO subscriptions (name, category, frequency, payment_method, start_date, status, notes, kind, total_installments) VALUES (?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$name, $category, $frequency, $paymentMethod, $startDate, $status, $notes, $kind, $installments]);
    $subId = $pdo->lastInsertId();

    $stmt2 = $pdo->prepare('INSERT INTO subscription_prices (subscription_id, cost, effective_from) VALUES (?,?,?)');
    $stmt2->execute([$subId, (float) $cost, $startDate]);

    // Αν η ημερομηνία έναρξης είναι στο παρελθόν, "γέμισε" αμέσως το ιστορικό
    // πληρωμών μέχρι σήμερα και υπολόγισε την επόμενη δόση.
    $newSub = ['id' => $subId, 'start_date' => $startDate, 'frequency' => $frequency, 'status' => $status, 'canceled_date' => null];
    $newPrices = [['cost' => (float) $cost, 'effective_from' => $startDate]];
    $inserted = syncSubscriptionLedger($pdo, $newSub, $newPrices, [], $today);

    $pdo->commit();
    $msg = t('msg.sub_added', ['name' => $name]);
    if ($inserted > 0) {
        $msg .= t('msg.sub_added_backfill', ['n' => $inserted]);
    }
    flash('success', $msg);
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.save_error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
