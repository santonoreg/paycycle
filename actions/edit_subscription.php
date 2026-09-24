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
$name = trim($_POST['name'] ?? '');
$category = trim($_POST['category'] ?? '');
$frequency = $_POST['frequency'] ?? '';
$paymentMethod = trim($_POST['payment_method'] ?? '') ?: null;
$startDate = $_POST['start_date'] ?? '';
$notes = trim($_POST['notes'] ?? '') ?: null;

$errors = [];
if ($id <= 0) $errors[] = t('err.unknown_sub');
if ($name === '') $errors[] = t('err.name_required');
if ($category === '') $errors[] = t('err.category_required');
if (!in_array($frequency, FREQUENCY_KEYS, true)) $errors[] = t('err.invalid_frequency');
if (!DateTime::createFromFormat('Y-m-d', $startDate)) $errors[] = t('err.invalid_start');

// Πλήθος δόσεων (μόνο αν στάλθηκε το πεδίο, δηλαδή για επαναλαμβανόμενες πληρωμές)
$setInstallments = array_key_exists('installments', $_POST);
$installments = null;
if ($setInstallments) {
    $raw = trim($_POST['installments']);
    if ($raw !== '') {
        if (!ctype_digit($raw) || (int) $raw < 1 || (int) $raw > 1200) {
            $errors[] = t('err.invalid_installments');
        } else {
            $installments = (int) $raw;
        }
    }
}

if ($errors) {
    flash('danger', implode(' ', $errors));
    header('Location: ../' . backPage());
    exit;
}

$pdo = getDb();
useKindOf($pdo, $id);
$oldStmt = $pdo->prepare('SELECT * FROM subscriptions WHERE id = ?');
$oldStmt->execute([$id]);
$old = $oldStmt->fetch();

$stmt = $pdo->prepare("UPDATE subscriptions SET name=?, category=?, frequency=?, payment_method=?, start_date=?, notes=?, updated_at=datetime('now') WHERE id=?");
$stmt->execute([$name, $category, $frequency, $paymentMethod, $startDate, $notes, $id]);

if ($setInstallments) {
    $variableAmount = !empty($_POST['variable_amount']) ? 1 : 0;
    $pdo->prepare('UPDATE subscriptions SET total_installments=?, variable_amount=? WHERE id=?')->execute([$installments, $variableAmount, $id]);
    if (!$variableAmount) {
        // Δεν είναι πια μεταβλητό ποσό: ό,τι ήταν εκτίμηση μένει ως έχει (επιβεβαιωμένο)
        $pdo->prepare('UPDATE subscription_payments SET is_estimate=0 WHERE subscription_id=?')->execute([$id]);
    }
    // Αν άλλαξε το πλήθος δόσεων: ενημέρωσε την κατάσταση (Εξοφλήθη <-> Ενεργή)
    reconcileInstallmentStatus($pdo, $id);
}

// Αν άλλαξε η συχνότητα ή η ημερομηνία έναρξης, οι καταχωρημένες πληρωμές
// (που είχαν υπολογιστεί με το παλιό βήμα) δεν ισχύουν πια: ξαναχτίζονται.
$rebuilt = false;
if ($old && $old['status'] !== 'canceled' && ($old['frequency'] !== $frequency || $old['start_date'] !== $startDate)) {
    $pdo->beginTransaction();
    try {
        // Μεταβλητά ποσά: κράτα τους επιβεβαιωμένους λογαριασμούς ώστε να μη χαθούν στον επανυπολογισμό
        $keep = $pdo->prepare('SELECT payment_date, amount FROM subscription_payments WHERE subscription_id = ? AND is_estimate = 0 AND (SELECT variable_amount FROM subscriptions WHERE id = ?) = 1');
        $keep->execute([$id, $id]);
        $confirmedRows = $keep->fetchAll();
        $pdo->prepare('DELETE FROM subscription_payments WHERE subscription_id = ?')->execute([$id]);
        $pr = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
        $pr->execute([$id]);
        $fr = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');
        $fr->execute([$id]);
        $sub = ['id' => $id, 'start_date' => $startDate, 'frequency' => $frequency];
        syncSubscriptionLedger($pdo, $sub, $pr->fetchAll(), $fr->fetchAll(), date('Y-m-d'));
        $restore = $pdo->prepare('UPDATE subscription_payments SET amount = ?, is_estimate = 0 WHERE subscription_id = ? AND payment_date = ?');
        foreach ($confirmedRows as $c) {
            $restore->execute([$c['amount'], $id, $c['payment_date']]);
        }
        if ($confirmedRows) {
            refreshEstimates($pdo, $id);
        }
        $pdo->commit();
        $rebuilt = true;
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash('danger', t('msg.rebuild_error', ['error' => $e->getMessage()]));
        header('Location: ../' . backPage());
        exit;
    }
}

if ($stmt->rowCount() === 0) {
    flash('warning', t('msg.no_change'));
} else {
    flash('success', t('msg.info_updated') . ($rebuilt ? t('msg.info_rebuilt') : ''));
}

header('Location: ../' . backPage());
exit;
