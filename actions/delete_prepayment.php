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

$pdo = getDb();
$today = date('Y-m-d');
$prepId = (int) ($_POST['id'] ?? 0);

$stmt = $pdo->prepare('SELECT p.*, s.name AS sub_name, s.status AS sub_status, s.total_installments, s.start_date, s.kind AS sub_kind FROM subscription_prepayments p JOIN subscriptions s ON s.id = p.subscription_id WHERE p.id = ?');
$stmt->execute([$prepId]);
$prep = $stmt->fetch();
if (!$prep) {
    flash('warning', t('err.prepay_unavailable'));
    header('Location: ../' . backPage());
    exit;
}
$subId = (int) $prep['subscription_id'];
setKind($prep["sub_kind"]);

/** Μετά από αλλαγή εξόφλησης: ενημέρωσε την κατάσταση και συμπλήρωσε τις δόσεις που έχουν ήδη λήξει. */
function settleAfterPrepaymentChange(PDO $pdo, int $subId, string $today, ?string $settledOn = null): void
{
    $row = $pdo->query('SELECT * FROM subscriptions WHERE id = ' . $subId)->fetch();
    if ($row['total_installments'] === null) {
        // Ακυρώθηκε η εξόφληση-κλείσιμο: η πληρωμή τρέχει ξανά· το διάστημα που ήταν εξοφλημένη δεν χρεώνεται.
        if ($row['status'] === 'paid_off') {
            if ($settledOn !== null && $settledOn < $today) {
                $pdo->prepare('INSERT INTO subscription_freezes (subscription_id, frozen_from, frozen_until) VALUES (?,?,?)')->execute([$subId, $settledOn, $today]);
            }
            $pdo->prepare("UPDATE subscriptions SET status = 'active', updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
        }
        return;
    }
    $recorded = (int) $pdo->query('SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = ' . $subId)->fetchColumn();
    $remaining = (int) $row['total_installments'] - $recorded - prepaidInstallments($pdo, $subId);
    if ($remaining <= 0 && in_array($row['status'], ['active', 'trial', 'frozen'], true)) {
        $pdo->prepare("UPDATE subscriptions SET status = 'paid_off', updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
        return;
    }
    // Ολοκληρωμένη που ξαναγίνεται ενεργή (απομένουν δόσεις) -> και καταχώρηση όσων έχουν ήδη λήξει
    reconcileInstallmentStatus($pdo, $subId);
    $pr = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
    $pr->execute([$subId]);
    $fr = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');
    $fr->execute([$subId]);
    $row = $pdo->query('SELECT * FROM subscriptions WHERE id = ' . $subId)->fetch();
    if (!in_array($row['status'], ['canceled', 'expired'], true)) {
        syncSubscriptionLedger($pdo, $row, $pr->fetchAll(), $fr->fetchAll(), $today);
    }
}

$pdo->beginTransaction();
try {
    $pdo->prepare('DELETE FROM subscription_prepayments WHERE id = ?')->execute([$prepId]);
    $pdo->prepare("UPDATE subscriptions SET updated_at = datetime('now') WHERE id = ?")->execute([$subId]);
    $isSettlement = ($prep['kind'] ?? 'installments') === 'settlement';
    settleAfterPrepaymentChange($pdo, $subId, $today, $prep['paid_on']);
    logActivity($pdo, $subId, $prep['sub_name'], 'prepaid_removed', ['installments' => (int) $prep['installments'], 'amount' => (float) $prep['amount'], 'date' => $prep['paid_on']] + ($isSettlement ? ['settlement' => true] : []));
    $pdo->commit();
    flash('success', t('msg.prepay_removed', ['name' => $prep['sub_name']]));
} catch (Throwable $e) {
    $pdo->rollBack();
    flash('danger', t('msg.error', ['error' => $e->getMessage()]));
}

header('Location: ../' . backPage());
exit;
