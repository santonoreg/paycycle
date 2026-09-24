<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/functions.php';

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

echo "\nΌλα τα tests έτρεξαν.\n";
