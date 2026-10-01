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
[$paymentMethod, $cardId, $pmError] = resolvePaymentMethod(getDb(), $_POST['payment_method'] ?? '', $_POST['card_id'] ?? '');
$startDate = $_POST['start_date'] ?? '';
$endDate = trim($_POST['end_date'] ?? '') ?: null; // προαιρετική ημερομηνία λήξης
$cost = $_POST['cost'] ?? '';
$status = $_POST['status'] ?? 'active';
$notes = trim($_POST['notes'] ?? '') ?: null;
$installmentsRaw = trim($_POST['installments'] ?? '');
$kind = in_array($_POST['kind'] ?? '', KINDS, true) ? $_POST['kind'] : 'subscription';
setKind($kind);
// Πλήθος δόσεων: προαιρετικό, μόνο για επαναλαμβανόμενες πληρωμές
$installments = null;
$variableAmount = $kind === 'recurring' && !empty($_POST['variable_amount']) ? 1 : 0;

$errors = [];
if ($name === '') $errors[] = t('err.name_required');
if ($category === '') $errors[] = t('err.category_required');
if (!in_array($frequency, FREQUENCY_KEYS, true)) $errors[] = t('err.invalid_frequency');
if (!DateTime::createFromFormat('Y-m-d', $startDate)) $errors[] = t('err.invalid_start');
if ($pmError !== null) $errors[] = $pmError;
if ($endDate !== null && (!DateTime::createFromFormat('Y-m-d', $endDate) || $endDate < $startDate)) $errors[] = t('err.invalid_end');
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
// Αν η ημερομηνία λήξης έχει ήδη περάσει, η εγγραφή καταχωρείται ως "Έληξε" και το
// ιστορικό πληρωμών γεμίζει μόνο μέχρι τη λήξη.
$canceledDate = null;
$fillUntil = $today;
if ($endDate !== null && $endDate < $today) {
    $status = 'expired';
    $canceledDate = $endDate;
    $fillUntil = $endDate;
}
$copyOf = null;
if (ctype_digit((string) ($_POST['copy_from'] ?? ''))) {
    $cp = $pdo->prepare('SELECT name FROM subscriptions WHERE id = ?');
    $cp->execute([(int) $_POST['copy_from']]);
    $copyOf = $cp->fetchColumn() ?: null;
}
$pdo->beginTransaction();
try {
    $stmt = $pdo->prepare('INSERT INTO subscriptions (name, category, frequency, payment_method, start_date, status, notes, kind, total_installments, variable_amount, created_by, created_by_name, card_id, end_date, canceled_date) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $stmt->execute([$name, $category, $frequency, $paymentMethod, $startDate, $status, $notes, $kind, $installments, $variableAmount, currentUser()['id'], currentUser()['username'], $cardId, $endDate, $canceledDate]);
    $subId = $pdo->lastInsertId();

    $stmt2 = $pdo->prepare('INSERT INTO subscription_prices (subscription_id, cost, effective_from) VALUES (?,?,?)');
    $stmt2->execute([$subId, (float) $cost, $startDate]);

    // Αν η ημερομηνία έναρξης είναι στο παρελθόν, "γέμισε" αμέσως το ιστορικό
    // πληρωμών μέχρι σήμερα και υπολόγισε την επόμενη δόση.
    $newSub = ['id' => $subId, 'start_date' => $startDate, 'frequency' => $frequency, 'status' => $status, 'canceled_date' => $canceledDate];
    $newPrices = [['cost' => (float) $cost, 'effective_from' => $startDate]];
    $inserted = syncSubscriptionLedger($pdo, $newSub, $newPrices, [], $fillUntil);

    logActivity($pdo, (int) $subId, $name, 'created', $copyOf !== null ? ['copy_of' => $copyOf] : []);
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
