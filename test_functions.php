<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';
require __DIR__ . '/includes/stats_helpers.php';

function check(string $label, $actual, $expected): void
{
    $ok = ($actual == $expected);
    printf("[%s] %s -> got=%s expected=%s\n", $ok ? 'OK' : 'FAIL', $label, json_encode($actual), json_encode($expected));
}

function freshTestDb(): PDO
{
    $pdo = new PDO('sqlite::memory:');
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA foreign_keys = ON');
    initSchema($pdo);
    return $pdo;
}

function insertSub(PDO $pdo, array $row): int
{
    $stmt = $pdo->prepare('INSERT INTO subscriptions (name, category, frequency, payment_method, start_date, status, canceled_date) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$row['name'], $row['category'] ?? 'Test', $row['frequency'], $row['payment_method'] ?? null, $row['start_date'], $row['status'] ?? 'active', $row['canceled_date'] ?? null]);
    return (int) $pdo->lastInsertId();
}

function insertPrice(PDO $pdo, int $subId, float $cost, string $effectiveFrom): void
{
    $pdo->prepare('INSERT INTO subscription_prices (subscription_id, cost, effective_from) VALUES (?,?,?)')->execute([$subId, $cost, $effectiveFrom]);
}

// --- Pure helper tests (no DB) -------------------------------------------
check('monthlyEquivalent(120,yearly)', round(monthlyEquivalent(120, 'yearly'), 2), 10.0);
check('annualCost 3 monthly installments = 3x', round(annualCost(46.33, 'monthly', 3), 2), 138.99);
check('monthlyEquivalent 3 installments = total/12', round(monthlyEquivalent(46.33, 'monthly', 3), 2), 11.58);
check('annualCost 24 monthly installments capped at 12', round(annualCost(100, 'monthly', 24), 2), 1200.0);
check('annualCost yearly with 3 installments stays 1x/yr', round(annualCost(100, 'yearly', 3), 2), 100.0);
check('annualCost(2,weekly)', round(annualCost(2, 'weekly'), 2), 104.0);
check('priceAtDate mid-history', priceAtDate([['cost' => 5, 'effective_from' => '2026-01-01'], ['cost' => 10, 'effective_from' => '2026-04-01']], '2026-05-01'), 10.0);
check('isFrozenAt inside open freeze', isFrozenAt([['frozen_from' => '2026-05-01', 'frozen_until' => null]], '2026-06-01'), true);
check('isFrozenAt outside closed freeze', isFrozenAt([['frozen_from' => '2026-03-01', 'frozen_until' => '2026-03-15']], '2026-04-01'), false);

// --- Ledger sync: new subscription with a start date in the past ---------
// Simulates: today is 2026-09-09, user adds a subscription that started 2023-02-28
// monthly at 22.00 -> should back-fill 43 installments in one go.
$pdo = freshTestDb();
$subId = insertSub($pdo, ['name' => 'ChatGPT', 'frequency' => 'monthly', 'start_date' => '2023-02-28']);
insertPrice($pdo, $subId, 22.00, '2023-02-28');
$sub = ['id' => $subId, 'start_date' => '2023-02-28', 'frequency' => 'monthly', 'status' => 'active', 'canceled_date' => null];
$prices = [['cost' => 22.00, 'effective_from' => '2023-02-28']];
$inserted = syncSubscriptionLedger($pdo, $sub, $prices, [], '2026-09-09');
check('Backfill on add: inserted count', $inserted, 43);
$count = (int) $pdo->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subId")->fetchColumn();
$sum = (float) $pdo->query("SELECT SUM(amount) FROM subscription_payments WHERE subscription_id=$subId")->fetchColumn();
check('Backfill on add: ledger rows', $count, 43);
check('Backfill on add: ledger total', round($sum, 2), 946.00);

// Stats computed from that ledger should show next payment anchored on the
// LAST recorded payment (2026-08-28), not on the original start date.
$paymentsStmt = $pdo->prepare('SELECT payment_date, amount FROM subscription_payments WHERE subscription_id=? ORDER BY payment_date ASC');
$paymentsStmt->execute([$subId]);
$payments = $paymentsStmt->fetchAll();
$stats = computeSubscriptionStats($sub, $prices, [], $payments, '2026-09-09');
check('Backfill: installments_paid', $stats['installments_paid'], 43);
check('Backfill: next_payment_date anchored on last payment', $stats['next_payment_date'], '2026-09-28');

// --- Ledger sync: running subscription continues from last payment, not
//     from start_date, on a second sync call a month later --------------
$pdo2 = freshTestDb();
$subId2 = insertSub($pdo2, ['name' => 'Running', 'frequency' => 'monthly', 'start_date' => '2026-01-01']);
$sub2 = ['id' => $subId2, 'start_date' => '2026-01-01', 'frequency' => 'monthly', 'status' => 'active', 'canceled_date' => null];
$prices2 = [['cost' => 10.0, 'effective_from' => '2026-01-01']];
syncSubscriptionLedger($pdo2, $sub2, $prices2, [], '2026-03-01'); // catches Jan, Feb, Mar
$countAfterFirst = (int) $pdo2->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subId2")->fetchColumn();
check('Running sub: first sync count', $countAfterFirst, 3);
// A later page load re-syncs up to a newer "today" -> must only add the delta.
$insertedSecond = syncSubscriptionLedger($pdo2, $sub2, $prices2, [], '2026-05-01'); // adds Apr, May
check('Running sub: second sync only adds the delta', $insertedSecond, 2);
$countAfterSecond = (int) $pdo2->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subId2")->fetchColumn();
check('Running sub: total after second sync', $countAfterSecond, 5);
// Re-running sync with the SAME "today" must be a no-op (idempotent).
$insertedNoop = syncSubscriptionLedger($pdo2, $sub2, $prices2, [], '2026-05-01');
check('Running sub: idempotent re-sync inserts nothing', $insertedNoop, 0);

// --- Ledger sync respects freezes: frozen periods are not recorded -------
$pdo3 = freshTestDb();
$subId3 = insertSub($pdo3, ['name' => 'Freezable', 'frequency' => 'monthly', 'start_date' => '2026-01-01']);
$sub3 = ['id' => $subId3, 'start_date' => '2026-01-01', 'frequency' => 'monthly', 'status' => 'active', 'canceled_date' => null];
$prices3 = [['cost' => 10.0, 'effective_from' => '2026-01-01']];
$freezes3 = [['frozen_from' => '2026-03-15', 'frozen_until' => '2026-05-10']];
syncSubscriptionLedger($pdo3, $sub3, $prices3, $freezes3, '2026-06-01');
$countF = (int) $pdo3->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subId3")->fetchColumn();
check('Freeze-aware sync: paid count excludes frozen periods', $countF, 4); // Jan, Feb, Mar, Jun (Apr/May frozen)

// --- edit_price retroactive correction (simulated inline, same logic as action) ---
$pdo4 = freshTestDb();
$subId4 = insertSub($pdo4, ['name' => 'Notion Plus', 'frequency' => 'monthly', 'start_date' => '2026-01-15']);
insertPrice($pdo4, $subId4, 4.00, '2026-01-15');
$sub4 = ['id' => $subId4, 'start_date' => '2026-01-15', 'frequency' => 'monthly', 'status' => 'active', 'canceled_date' => null];
syncSubscriptionLedger($pdo4, $sub4, [['cost' => 4.00, 'effective_from' => '2026-01-15']], [], '2026-06-15');
// Now add a price increase effective 2026-04-15 (as if the user did this via edit_price.php).
insertPrice($pdo4, $subId4, 9.00, '2026-04-15');
$allPrices4 = $pdo4->query("SELECT cost, effective_from FROM subscription_prices WHERE subscription_id=$subId4 ORDER BY effective_from ASC")->fetchAll();
$rows = $pdo4->query("SELECT id, payment_date FROM subscription_payments WHERE subscription_id=$subId4 AND payment_date >= '2026-04-15'")->fetchAll();
$upd = $pdo4->prepare('UPDATE subscription_payments SET amount=? WHERE id=?');
foreach ($rows as $r) { $upd->execute([priceAtDate($allPrices4, $r['payment_date']), $r['id']]); }
$sumAfterCorrection = (float) $pdo4->query("SELECT SUM(amount) FROM subscription_payments WHERE subscription_id=$subId4")->fetchColumn();
// Jan15,Feb15,Mar15 @4.00 = 12.00 ; Apr15,May15,Jun15 @9.00 = 27.00 ; total 39.00
check('Retroactive price correction: ledger total', round($sumAfterCorrection, 2), 39.00);

// --- Future-dated subscription (trial not yet started): no payments yet --
$pdo5 = freshTestDb();
$subId5 = insertSub($pdo5, ['name' => 'Future Trial', 'frequency' => 'monthly', 'start_date' => '2026-12-08', 'status' => 'trial']);
$sub5 = ['id' => $subId5, 'start_date' => '2026-12-08', 'frequency' => 'monthly', 'status' => 'trial', 'canceled_date' => null];
$prices5 = [['cost' => 22.29, 'effective_from' => '2026-12-08']];
$inserted5 = syncSubscriptionLedger($pdo5, $sub5, $prices5, [], '2026-09-09');
check('Future-dated sub: no payments yet', $inserted5, 0);
$stats5 = computeSubscriptionStats($sub5, $prices5, [], [], '2026-09-09');
check('Future-dated sub: next_payment_date falls back to start_date', $stats5['next_payment_date'], '2026-12-08');

// --- getAllSubscriptionsWithDetails end-to-end (uses syncAllLedgers) -----
$pdo6 = freshTestDb();
$subId6 = insertSub($pdo6, ['name' => 'EndToEnd', 'frequency' => 'monthly', 'start_date' => '2026-06-09']);
insertPrice($pdo6, $subId6, 15.0, '2026-06-09');
$all = getAllSubscriptionsWithDetails($pdo6, '2026-09-09');
check('getAllSubscriptionsWithDetails: one subscription', count($all), 1);
check('getAllSubscriptionsWithDetails: auto-synced installments', $all[0]['stats']['installments_paid'], 4); // Jun,Jul,Aug,Sep
check('getAllSubscriptionsWithDetails: next payment', $all[0]['stats']['next_payment_date'], '2026-10-09');


// --- Εξόφληση δόσεων (prepayments) -------------------------------------------
// Πλάνο 6 μηνιαίων δόσεων από 2026-06-10. Στις 2026-08-20 έχουν πέσει 3 (Ιούν, Ιούλ, Αύγ).
// Εξοφλούμε 2 δόσεις νωρίτερα: μένει 1 προγραμματισμένη (10/9), οι ημερομηνίες δεν αλλάζουν.
$pdoP = freshTestDb();
$subIdP = insertSub($pdoP, ['name' => 'Prepay', 'frequency' => 'monthly', 'start_date' => '2026-06-10']);
$pdoP->exec("UPDATE subscriptions SET kind='recurring', total_installments=6 WHERE id=$subIdP");
insertPrice($pdoP, $subIdP, 50.0, '2026-06-10');
$allP = getAllSubscriptionsWithDetails($pdoP, '2026-08-20');
check('Prepay: before - paid', $allP[0]['stats']['installments_paid'], 3);
check('Prepay: before - next payment', $allP[0]['stats']['next_payment_date'], '2026-09-10');
check('Prepay: before - remaining', $allP[0]['stats']['installments_remaining'], 3);
$pdoP->exec("INSERT INTO subscription_prepayments (subscription_id, paid_on, installments, amount) VALUES ($subIdP, '2026-08-20', 2, 95.00)");
$allP = getAllSubscriptionsWithDetails($pdoP, '2026-08-20');
check('Prepay: paid counts prepaid installments', $allP[0]['stats']['installments_paid'], 5);
check('Prepay: remaining', $allP[0]['stats']['installments_remaining'], 1);
check('Prepay: next payment date unchanged', $allP[0]['stats']['next_payment_date'], '2026-09-10');
check('Prepay: total paid = 3 x 50 + 95', $allP[0]['stats']['total_paid'], 245.0);
// Sept 10 arrives: the one remaining installment is recorded and the plan completes; nothing more is scheduled.
$allP = getAllSubscriptionsWithDetails($pdoP, '2026-12-31');
check('Prepay: ledger stops at total - prepaid', (int) $pdoP->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subIdP")->fetchColumn(), 4);
check('Prepay: last scheduled date', $pdoP->query("SELECT MAX(payment_date) FROM subscription_payments WHERE subscription_id=$subIdP")->fetchColumn(), '2026-09-10');
check('Prepay: status completed', $allP[0]['sub']['status'], 'paid_off');
check('Prepay: no next payment', $allP[0]['stats']['next_payment_date'], null);

// Full payoff right away (nothing scheduled any more) -> completed
$pdoQ = freshTestDb();
$subIdQ = insertSub($pdoQ, ['name' => 'FullPayoff', 'frequency' => 'monthly', 'start_date' => '2026-06-10']);
$pdoQ->exec("UPDATE subscriptions SET kind='recurring', total_installments=6 WHERE id=$subIdQ");
insertPrice($pdoQ, $subIdQ, 50.0, '2026-06-10');
getAllSubscriptionsWithDetails($pdoQ, '2026-08-20'); // 3 recorded
$pdoQ->exec("INSERT INTO subscription_prepayments (subscription_id, paid_on, installments, amount) VALUES ($subIdQ, '2026-08-20', 3, 140.00)");
reconcileInstallmentStatus($pdoQ, $subIdQ);
$allQ = getAllSubscriptionsWithDetails($pdoQ, '2027-03-01');
check('Full payoff: status completed', $allQ[0]['sub']['status'], 'paid_off');
check('Full payoff: nothing scheduled after', (int) $pdoQ->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subIdQ")->fetchColumn(), 3);
check('Full payoff: installments paid = total', $allQ[0]['stats']['installments_paid'], 6);


// --- Εξόφληση-κλείσιμο επαναλαμβανόμενης πληρωμής χωρίς πλήθος δόσεων ----------
$pdoS = freshTestDb();
$subIdS = insertSub($pdoS, ['name' => 'OpenLoan', 'frequency' => 'monthly', 'start_date' => '2026-06-10']);
$pdoS->exec("UPDATE subscriptions SET kind='recurring' WHERE id=$subIdS");
insertPrice($pdoS, $subIdS, 80.0, '2026-06-10');
getAllSubscriptionsWithDetails($pdoS, '2026-08-20'); // Jun, Jul, Aug recorded
$pdoS->exec("INSERT INTO subscription_prepayments (subscription_id, paid_on, installments, amount, kind) VALUES ($subIdS, '2026-08-20', 1, 500.00, 'settlement')");
$pdoS->exec("UPDATE subscriptions SET status='paid_off' WHERE id=$subIdS");
$allS = getAllSubscriptionsWithDetails($pdoS, '2027-03-01');
check('Settlement: stays completed', $allS[0]['sub']['status'], 'paid_off');
check('Settlement: not charged any more', (int) $pdoS->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subIdS")->fetchColumn(), 3);
check('Settlement: installments paid excludes the settlement', $allS[0]['stats']['installments_paid'], 3);
check('Settlement: total paid includes it', $allS[0]['stats']['total_paid'], 740.0);
check('Settlement: no next payment', $allS[0]['stats']['next_payment_date'], null);
// reconcile must not bring it back to active
reconcileInstallmentStatus($pdoS, $subIdS);
check('Settlement: reconcile keeps completed', $pdoS->query("SELECT status FROM subscriptions WHERE id=$subIdS")->fetchColumn(), 'paid_off');
// once the settlement is removed it can run again
$pdoS->exec("DELETE FROM subscription_prepayments WHERE subscription_id=$subIdS");
reconcileInstallmentStatus($pdoS, $subIdS);
check('Settlement removed: active again', $pdoS->query("SELECT status FROM subscriptions WHERE id=$subIdS")->fetchColumn(), 'active');


// --- Πληρωμή της επόμενης δόσης νωρίτερα (ασφάλεια κάθε 6 μήνες) -------------------
$pdoE = freshTestDb();
$subIdE = insertSub($pdoE, ['name' => 'Insurance', 'frequency' => 'half-yearly', 'start_date' => '2026-03-15']);
$pdoE->exec("UPDATE subscriptions SET kind='recurring' WHERE id=$subIdE");
insertPrice($pdoE, $subIdE, 300.0, '2026-03-15');
$allE = getAllSubscriptionsWithDetails($pdoE, '2026-08-20');
check('Early: before - paid', $allE[0]['stats']['installments_paid'], 1);            // 15/03
check('Early: before - next', $allE[0]['stats']['next_payment_date'], '2026-09-15');
// pay the 15/09 installment early, on 2026-08-20
$pdoE->exec("INSERT INTO subscription_payments (subscription_id, payment_date, amount, is_estimate, paid_on) VALUES ($subIdE, '2026-09-15', 300.00, 0, '2026-08-20')");
$allE = getAllSubscriptionsWithDetails($pdoE, '2026-08-20');
check('Early: counts as paid', $allE[0]['stats']['installments_paid'], 2);
check('Early: next payment is the following half-year', $allE[0]['stats']['next_payment_date'], '2027-03-15');
check('Early: still active (not a payoff)', $allE[0]['sub']['status'], 'active');
// time passes: nothing is charged twice, the schedule continues
$allE = getAllSubscriptionsWithDetails($pdoE, '2027-03-20');
check('Early: later - installments recorded', $allE[0]['stats']['installments_paid'], 3);
check('Early: later - no duplicate for the paid slot', (int) $pdoE->query("SELECT COUNT(*) FROM subscription_payments WHERE subscription_id=$subIdE AND payment_date='2026-09-15'")->fetchColumn(), 1);
check('Early: later - next payment', $allE[0]['stats']['next_payment_date'], '2027-09-15');
// spend statistics count it in the month it was really paid (August), not the slot month
$m = computeMonthlySpendHistory($pdoE, 6, '2026-10-01', 'recurring');
check('Early: spend counted in the paid month', $m['2026-08'] ?? null, 300.0);
check('Early: spend not counted in the slot month', $m['2026-09'] ?? null, 0.0);

echo "\nΌλα τα tests έτρεξαν.\n";
