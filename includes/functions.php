<?php
/**
 * functions.php
 * Πυρήνας υπολογισμών: πόσες δόσεις έχουν πληρωθεί, πότε είναι η επόμενη,
 * πόσο κοστίζει κάθε δόση (λαμβάνοντας υπόψη το ιστορικό τιμών) και ποιες
 * περίοδοι πρέπει να εξαιρεθούν επειδή η συνδρομή ήταν παγωμένη.
 */

const FREQUENCY_KEYS = ['weekly', 'monthly', 'half-yearly', 'yearly', 'biennial', 'triennial'];
const STATUS_KEYS = ['active', 'trial', 'frozen', 'canceled', 'paid_off', 'expired'];

/** Πόσες ημέρες πριν τη λήξη εμφανίζεται ειδοποίηση. */
const EXPIRY_WARNING_DAYS = 30;

/** True για συνδρομές/πληρωμές που ΤΡΕΧΟΥΝ ακόμα (δεν είναι ακυρωμένες, εξοφλημένες ή ληγμένες). */
function isRunningStatus(string $status): bool
{
    return !in_array($status, ['canceled', 'paid_off', 'expired'], true);
}

/** @return array<string,string> κλειδί συχνότητας => μεταφρασμένη ετικέτα */
function frequencies(): array
{
    $out = [];
    foreach (FREQUENCY_KEYS as $k) {
        $out[$k] = t('freq.' . $k);
    }
    return $out;
}

/** @return array<string,string> κλειδί κατάστασης => μεταφρασμένη ετικέτα */
function statuses(): array
{
    $out = [];
    foreach (STATUS_KEYS as $k) {
        $out[$k] = t('status.' . $k);
    }
    return $out;
}

function frequencyIntervalSpec(string $frequency): string
{
    return match ($frequency) {
        'weekly'      => 'P7D',
        'monthly'     => 'P1M',
        'half-yearly' => 'P6M',
        'yearly'      => 'P1Y',
        'biennial'    => 'P2Y',
        'triennial'   => 'P3Y',
        default       => throw new InvalidArgumentException("Άγνωστη συχνότητα: $frequency"),
    };
}

function periodsPerYear(string $frequency): float
{
    return match ($frequency) {
        'weekly'      => 52.0,
        'monthly'     => 12.0,
        'half-yearly' => 2.0,
        'yearly'      => 1.0,
        'biennial'    => 0.5,
        'triennial'   => 1.0 / 3.0,
        default       => throw new InvalidArgumentException("Άγνωστη συχνότητα: $frequency"),
    };
}

/**
 * Πόσες δόσεις πέφτουν σε ένα έτος. Για πληρωμή με ΣΥΓΚΕΚΡΙΜΕΝΟ πλήθος δόσεων
 * (π.χ. αγορά σε 3 δόσεις) το ετήσιο κόστος δεν μπορεί να ξεπερνά το συνολικό
 * ποσό του πλάνου: 3 δόσεις = 3 φορές το ποσό, όχι 12.
 */
function installmentsPerYear(string $frequency, ?int $totalInstallments = null): float
{
    $perYear = periodsPerYear($frequency);
    return $totalInstallments !== null ? min($perYear, (float) $totalInstallments) : $perYear;
}

function monthlyEquivalent(float $cost, string $frequency, ?int $totalInstallments = null): float
{
    return $cost * installmentsPerYear($frequency, $totalInstallments) / 12.0;
}

function annualCost(float $cost, string $frequency, ?int $totalInstallments = null): float
{
    return $cost * installmentsPerYear($frequency, $totalInstallments);
}

/**
 * Επιστρέφει τις ημερομηνίες χρέωσης από $startDate (συμπεριλαμβάνεται)
 * μέχρι και $untilDate (συμπεριλαμβάνεται), με βήμα τη συχνότητα.
 * @return string[] ημερομηνίες σε μορφή Y-m-d, αύξουσα σειρά
 */
function generatePeriodDates(string $startDate, string $frequency, string $untilDate): array
{
    $dates = [];
    if ($startDate > $untilDate) {
        return $dates;
    }
    $current = new DateTime($startDate);
    $end = new DateTime($untilDate);
    $interval = new DateInterval(frequencyIntervalSpec($frequency));

    $safety = 0;
    while ($current <= $end && $safety < 5000) {
        $dates[] = $current->format('Y-m-d');
        $current->add($interval);
        $safety++;
    }
    return $dates;
}

/**
 * Η τιμή που ίσχυε σε μια συγκεκριμένη ημερομηνία, με βάση το ιστορικό τιμών.
 * $prices: array από ['cost'=>float,'effective_from'=>'Y-m-d'], ταξινομημένο αύξοντα.
 */
function priceAtDate(array $prices, string $date): float
{
    $applicable = 0.0;
    foreach ($prices as $p) {
        if ($p['effective_from'] <= $date) {
            $applicable = (float) $p['cost'];
        } else {
            break;
        }
    }
    return $applicable;
}

/**
 * Εκτίμηση ποσού για πληρωμές με ΜΕΤΑΒΛΗΤΟ ποσό (λογαριασμοί ρεύματος, κινητού κ.λπ.):
 * ο μέσος όρος των 3 τελευταίων ΕΠΙΒΕΒΑΙΩΜΕΝΩΝ λογαριασμών πριν από την
 * ημερομηνία. Αν δεν υπάρχει κανένας, η τιμή (εκτίμηση) που έδωσε ο χρήστης.
 * $payments: ['payment_date'=>..., 'amount'=>..., 'is_estimate'=>0|1]
 */
function estimateFromPayments(array $payments, array $prices, string $date): float
{
    $confirmed = [];
    foreach ($payments as $p) {
        if (empty($p['is_estimate']) && $p['payment_date'] < $date) {
            $confirmed[] = $p;
        }
    }
    usort($confirmed, fn($a, $b) => strcmp($b['payment_date'], $a['payment_date']));
    $last = array_slice($confirmed, 0, 3);
    if (empty($last)) {
        return priceAtDate($prices, $date);
    }
    $sum = 0.0;
    foreach ($last as $p) {
        $sum += (float) $p['amount'];
    }
    return round($sum / count($last), 2);
}

/** Ξαναϋπολογίζει τα ποσά των πληρωμών που είναι ακόμα εκτιμήσεις (μετά από επιβεβαίωση λογαριασμού ή αλλαγή τιμής). */
function refreshEstimates(PDO $pdo, int $subId): void
{
    $pricesStmt = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
    $pricesStmt->execute([$subId]);
    $prices = $pricesStmt->fetchAll();

    $payStmt = $pdo->prepare('SELECT id, payment_date, amount, is_estimate FROM subscription_payments WHERE subscription_id = ? ORDER BY payment_date ASC');
    $payStmt->execute([$subId]);
    $payments = $payStmt->fetchAll();

    $upd = $pdo->prepare('UPDATE subscription_payments SET amount = ? WHERE id = ?');
    foreach ($payments as $p) {
        if (!empty($p['is_estimate'])) {
            $upd->execute([estimateFromPayments($payments, $prices, $p['payment_date']), $p['id']]);
        }
    }
}

/**
 * True αν κάποια καταχωρημένη πληρωμή έχει υπολογιστεί με την τιμή στη θέση $index.
 * Μια τιμή καλύπτει τις πληρωμές από το effective_from της (συμπεριλαμβάνεται)
 * μέχρι το effective_from της επόμενης τιμής (δεν συμπεριλαμβάνεται).
 * $prices: ταξινομημένο αύξοντα (effective_from, id). $payments: ['payment_date'=>...].
 */
function priceIsUsedByPayments(array $prices, int $index, array $payments): bool
{
    $from = $prices[$index]['effective_from'];
    $to = $prices[$index + 1]['effective_from'] ?? null;
    foreach ($payments as $p) {
        if ($p['payment_date'] >= $from && ($to === null || $p['payment_date'] < $to)) {
            return true;
        }
    }
    return false;
}

/**
 * True αν η ημερομηνία πέφτει μέσα σε κάποιο διάστημα "παγώματος".
 * $freezes: array από ['frozen_from'=>'Y-m-d','frozen_until'=>?'Y-m-d']
 */
function isFrozenAt(array $freezes, string $date): bool
{
    foreach ($freezes as $f) {
        $from = $f['frozen_from'];
        $until = $f['frozen_until'];
        if ($date >= $from && ($until === null || $date <= $until)) {
            return true;
        }
    }
    return false;
}

/**
 * "Πιάνει" (καταχωρεί) στο βιβλίο πληρωμών (subscription_payments) όλες τις
 * δόσεις που λείπουν, από την τελευταία καταχωρημένη πληρωμή (ή από την
 * ημερομηνία έναρξης, αν δεν υπάρχει ακόμα καμία καταχωρημένη πληρωμή) μέχρι
 * και $upToDate. Αυτή είναι η συνάρτηση που κάνει δύο πράγματα ταυτόχρονα:
 *   - Σε μια ΝΕΑ συνδρομή με παλιότερη ημερομηνία έναρξης, γεμίζει αυτόματα
 *     όλο το ιστορικό μέχρι σήμερα την ώρα που καταχωρείται.
 *   - Σε μια ΥΠΑΡΧΟΥΣΑ συνδρομή, συνεχίζει από την τελευταία πληρωμή της.
 * Οι περίοδοι που πέφτουν μέσα σε πάγωμα ΔΕΝ καταχωρούνται ως πληρωμή, αλλά
 * καταναλώνουν κανονικά το "βήμα" του χρονολογίου (ώστε η επόμενη πληρωμή
 * μετά το ξεπάγωμα να πέσει στη σωστή μελλοντική ημερομηνία).
 *
 * Idempotent: μπορεί να κληθεί σε κάθε φόρτωση σελίδας χωρίς να δημιουργεί
 * διπλές εγγραφές (UNIQUE(subscription_id, payment_date) + INSERT OR IGNORE).
 *
 * @return int πλήθος νέων γραμμών που καταχωρήθηκαν
 */
function syncSubscriptionLedger(PDO $pdo, array $sub, array $prices, array $freezes, string $upToDate): int
{
    $inserted = syncSubscriptionLedgerRows($pdo, $sub, $prices, $freezes, $upToDate);
    reconcileInstallmentStatus($pdo, (int) $sub['id']);
    return $inserted;
}

/**
 * Πληρωμές με συγκεκριμένο πλήθος δόσεων (total_installments): όταν έχουν
 * καταχωρηθεί όλες οι δόσεις (δηλαδή πέρασε η ημερομηνία της τελευταίας), η
 * κατάσταση γίνεται 'paid_off' (Εξοφλήθη). Αν αργότερα αυξηθεί το πλήθος δόσεων
 * (ή αφαιρεθεί το όριο), επιστρέφει σε 'active'.
 */
function reconcileInstallmentStatus(PDO $pdo, int $subId): void
{
    $count = '(SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = subscriptions.id)';
    $pdo->prepare("UPDATE subscriptions SET status='paid_off', updated_at=datetime('now')
                   WHERE id = ? AND status IN ('active','trial') AND total_installments IS NOT NULL AND $count >= total_installments")
        ->execute([$subId]);
    $pdo->prepare("UPDATE subscriptions SET status='active', updated_at=datetime('now')
                   WHERE id = ? AND status = 'paid_off' AND (total_installments IS NULL OR $count < total_installments)")
        ->execute([$subId]);
}

function syncSubscriptionLedgerRows(PDO $pdo, array $sub, array $prices, array $freezes, string $upToDate): int
{
    $frequency = $sub['frequency'];

    $limitStmt = $pdo->prepare('SELECT total_installments, (SELECT COUNT(*) FROM subscription_payments WHERE subscription_id = subscriptions.id), variable_amount FROM subscriptions WHERE id = ?');
    $limitStmt->execute([$sub['id']]);
    [$totalInstallments, $recorded, $variableAmount] = $limitStmt->fetch(PDO::FETCH_NUM) ?: [null, 0, 0];
    $variableAmount = (int) $variableAmount === 1;
    $confirmedPayments = [];
    if ($variableAmount) {
        $cp = $pdo->prepare('SELECT payment_date, amount, is_estimate FROM subscription_payments WHERE subscription_id = ?');
        $cp->execute([$sub['id']]);
        $confirmedPayments = $cp->fetchAll();
    }
    $totalInstallments = $totalInstallments !== null ? (int) $totalInstallments : null;
    $recorded = (int) $recorded;
    $interval = new DateInterval(frequencyIntervalSpec($frequency));

    $stmt = $pdo->prepare('SELECT MAX(payment_date) FROM subscription_payments WHERE subscription_id = ?');
    $stmt->execute([$sub['id']]);
    $lastPaid = $stmt->fetchColumn();

    if ($lastPaid) {
        $cursor = new DateTime($lastPaid);
        $cursor->add($interval);
    } else {
        $cursor = new DateTime($sub['start_date']);
    }

    $end = new DateTime($upToDate);
    if ($cursor > $end) {
        return 0;
    }

    $ins = $pdo->prepare('INSERT OR IGNORE INTO subscription_payments (subscription_id, payment_date, amount, is_estimate) VALUES (?,?,?,?)');
    $inserted = 0;
    $safety = 0;
    while ($cursor <= $end && $safety < 5000) {
        if ($totalInstallments !== null && $recorded + $inserted >= $totalInstallments) {
            break; // όλες οι δόσεις έχουν ήδη καταχωρηθεί
        }
        $d = $cursor->format('Y-m-d');
        if (!isFrozenAt($freezes, $d)) {
            // Μεταβλητό ποσό: εκτίμηση (μέσος όρος τελευταίων 3 λογαριασμών) μέχρι να επιβεβαιωθεί ο λογαριασμός
            $amount = $variableAmount ? estimateFromPayments($confirmedPayments, $prices, $d) : priceAtDate($prices, $d);
            $ins->execute([$sub['id'], $d, $amount, $variableAmount ? 1 : 0]);
            $inserted += $ins->rowCount();
        }
        $cursor->add($interval);
        $safety++;
    }
    return $inserted;
}

/**
 * Τρέχει το sync για όλες τις μη-ακυρωμένες συνδρομές, μέχρι $today.
 * Καλείται στην αρχή κάθε φόρτωσης σελίδας ώστε το βιβλίο πληρωμών να είναι
 * πάντα ενημερωμένο μέχρι σήμερα.
 */
function syncAllLedgers(PDO $pdo, ?string $today = null): void
{
    $today = $today ?? date('Y-m-d');
    expireDueSubscriptions($pdo, $today);
    $subs = $pdo->query("SELECT * FROM subscriptions WHERE status NOT IN ('canceled', 'expired')")->fetchAll();
    if (empty($subs)) {
        return;
    }

    $pricesStmt = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
    $freezesStmt = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');

    foreach ($subs as $sub) {
        $pricesStmt->execute([$sub['id']]);
        $prices = $pricesStmt->fetchAll();
        $freezesStmt->execute([$sub['id']]);
        $freezes = $freezesStmt->fetchAll();
        syncSubscriptionLedger($pdo, $sub, $prices, $freezes, $today);
    }
}

/**
 * Διαχείριση λήξης: οι συνδρομές/πληρωμές με ημερομηνία λήξης (end_date) που
 * έχει περάσει ("σήμερα" > end_date) γίνονται αυτόματα 'expired' (Έληξε).
 * Πριν κλειδωθούν, καταχωρούνται οι δόσεις μέχρι και την ημερομηνία λήξης —
 * ποτέ μετά. Η ημερομηνία λήξης κρατιέται και στο canceled_date, ώστε μια
 * επανενεργοποίηση να μη μετρήσει το διάστημα που έληξε ως πληρωμένο.
 */
function expireDueSubscriptions(PDO $pdo, string $today): void
{
    $stmt = $pdo->prepare("SELECT * FROM subscriptions WHERE end_date IS NOT NULL AND end_date < ? AND status IN ('active','trial','frozen')");
    $stmt->execute([$today]);
    $due = $stmt->fetchAll();
    if (!$due) {
        return;
    }
    $pricesStmt = $pdo->prepare('SELECT cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
    $freezesStmt = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');
    foreach ($due as $sub) {
        $pricesStmt->execute([$sub['id']]);
        $freezesStmt->execute([$sub['id']]);
        syncSubscriptionLedgerRows($pdo, $sub, $pricesStmt->fetchAll(), $freezesStmt->fetchAll(), $sub['end_date']);
        $pdo->prepare("UPDATE subscriptions SET status = 'expired', canceled_date = end_date, updated_at = datetime('now') WHERE id = ?")->execute([$sub['id']]);
        logActivity($pdo, (int) $sub['id'], $sub['name'], 'expired', ['end_date' => $sub['end_date']], true);
    }
}

/**
 * Επανενεργοποίηση ακυρωμένης ή ληγμένης συνδρομής. Το διάστημα από την
 * ακύρωση/λήξη μέχρι σήμερα καταγράφεται σαν "πάγωμα" ώστε να μην μετρηθεί ως
 * πληρωμένο. Ανοιχτά παγώματα κλείνουν (η συνδρομή τρέχει ξανά).
 */
function reactivateSubscription(PDO $pdo, array $sub, string $today, bool $clearEndDate = true): void
{
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE subscription_freezes SET frozen_until = ? WHERE subscription_id = ? AND frozen_until IS NULL')
            ->execute([$today, $sub['id']]);
        if (!empty($sub['canceled_date']) && $sub['canceled_date'] < $today) {
            $pdo->prepare('INSERT INTO subscription_freezes (subscription_id, frozen_from, frozen_until) VALUES (?,?,?)')
                ->execute([$sub['id'], $sub['canceled_date'], $today]);
        }
        $pdo->prepare("UPDATE subscriptions SET status='active', canceled_date=NULL" . ($clearEndDate ? ', end_date=NULL' : '') . ", updated_at=datetime('now') WHERE id=?")
            ->execute([$sub['id']]);
        logActivity($pdo, (int) $sub['id'], $sub['name'], 'reactivated');
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
}

/**
 * Υπολογίζει τα στατιστικά μιας συνδρομής ΑΠΟ ΤΟ ΒΙΒΛΙΟ ΠΛΗΡΩΜΩΝ: δόσεις
 * πληρωμένες = πλήθος καταχωρημένων γραμμών, σύνολο = άθροισμά τους. Η
 * επόμενη δόση υπολογίζεται με αφετηρία την ΤΕΛΕΥΤΑΙΑ καταχωρημένη πληρωμή
 * (όχι την αρχική ημερομηνία έναρξης).
 *
 * @param array $sub      γραμμή από τον πίνακα subscriptions
 * @param array $prices   ιστορικό τιμών (ταξινομημένο αύξοντα κατά effective_from)
 * @param array $freezes  ιστορικό παγωμάτων
 * @param array $payments καταχωρημένες πληρωμές (ταξινομημένες αύξοντα κατά payment_date)
 * @param string|null $today override για tests (default: σήμερα)
 */
function computeSubscriptionStats(array $sub, array $prices, array $freezes, array $payments, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $frequency = $sub['frequency'];

    $installmentsPaid = count($payments);
    $totalPaid = 0.0;
    foreach ($payments as $p) {
        $totalPaid += (float) $p['amount'];
    }
    $lastPaymentDate = $installmentsPaid > 0 ? $payments[$installmentsPaid - 1]['payment_date'] : null;

    // Επόμενη δόση (μόνο για ενεργές/δοκιμαστικές συνδρομές — όχι παγωμένες/ακυρωμένες).
    $nextPaymentDate = null;
    $nextPaymentAmount = null;

    $variable = !empty($sub['variable_amount']);
    $estimatesPending = 0;
    foreach ($payments as $p) {
        if (!empty($p['is_estimate'])) {
            $estimatesPending++;
        }
    }
    $totalInstallments = isset($sub['total_installments']) && $sub['total_installments'] !== '' ? (int) $sub['total_installments'] : null;
    $allPaid = $totalInstallments !== null && $installmentsPaid >= $totalInstallments;

    if (in_array($sub['status'], ['active', 'trial'], true) && !$allPaid) {
        if ($lastPaymentDate !== null) {
            $cursor = new DateTime($lastPaymentDate);
            $cursor->add(new DateInterval(frequencyIntervalSpec($frequency)));
        } else {
            $cursor = new DateTime($sub['start_date']);
        }
        $safety = 0;
        while (isFrozenAt($freezes, $cursor->format('Y-m-d')) && $safety < 1000) {
            $cursor->add(new DateInterval(frequencyIntervalSpec($frequency)));
            $safety++;
        }
        $nextPaymentDate = $cursor->format('Y-m-d');
        $nextPaymentAmount = $variable ? estimateFromPayments($payments, $prices, $nextPaymentDate) : priceAtDate($prices, $nextPaymentDate);
        if (!empty($sub['end_date']) && $nextPaymentDate > $sub['end_date']) {
            $nextPaymentDate = null; // η συνδρομή λήγει πριν την επόμενη χρέωση
            $nextPaymentAmount = null;
        }
    }

    $currentPrice = $variable ? estimateFromPayments($payments, $prices, $today) : priceAtDate($prices, $today);
    // Αν δεν υπάρχει ακόμα καμία εγγραφή τιμής με effective_from <= today
    // (π.χ. μελλοντική συνδρομή), πάρε την πρώτη γνωστή τιμή.
    if ($currentPrice === 0.0 && !empty($prices)) {
        $currentPrice = (float) $prices[0]['cost'];
    }

    return [
        'installments_paid'   => $installmentsPaid,
        'total_paid'          => round($totalPaid, 2),
        'current_price'       => round($currentPrice, 2),
        'monthly_equivalent'  => round(monthlyEquivalent($currentPrice, $frequency, $totalInstallments), 2),
        'annual_cost'         => round(annualCost($currentPrice, $frequency, $totalInstallments), 2),
        'next_payment_date'   => $nextPaymentDate,
        'next_payment_amount' => $nextPaymentAmount !== null ? round($nextPaymentAmount, 2) : null,
        'is_frozen_now'       => $sub['status'] === 'frozen',
        'is_variable'            => $variable,
        'estimates_pending'      => $estimatesPending,
        'installments_total'     => $totalInstallments,
        'installments_remaining' => $totalInstallments !== null ? max(0, $totalInstallments - $installmentsPaid) : null,
    ];
}

/** Φορμάρισμα ποσού σε ευρώ, π.χ. "€ 55.50" */
function euro(?float $amount): string
{
    if ($amount === null) {
        return '—';
    }
    return '€ ' . number_format($amount, 2, '.', ',');
}

/** Φορμάρισμα ημερομηνίας Y-m-d -> μορφή της τρέχουσας γλώσσας (ή "—") */
function fdate(?string $ymd): string
{
    if (empty($ymd)) {
        return '—';
    }
    $dt = DateTime::createFromFormat('Y-m-d', $ymd);
    return $dt ? $dt->format(t('meta.date_format')) : $ymd;
}

/** Πόσες ημέρες μέχρι μια ημερομηνία (αρνητικό αν έχει περάσει) */
function daysUntil(?string $ymd, ?string $today = null): ?int
{
    if (empty($ymd)) {
        return null;
    }
    $today = $today ?? date('Y-m-d');
    $a = new DateTime($today);
    $b = new DateTime($ymd);
    return (int) $a->diff($b)->format('%r%a');
}

/**
 * Φέρνει όλες τις συνδρομές μαζί με το ιστορικό τιμών/παγωμάτων/πληρωμών τους.
 * Πρώτα συγχρονίζει (syncAllLedgers) ώστε το βιβλίο πληρωμών να είναι
 * ενημερωμένο μέχρι $today πριν διαβαστεί.
 * @return array<int, array{sub: array, prices: array, freezes: array, payments: array, stats: array}>
 */
function getAllSubscriptionsWithDetails(PDO $pdo, ?string $today = null, ?string $kind = null): array
{
    $today = $today ?? date('Y-m-d');
    syncAllLedgers($pdo, $today);

    if ($kind !== null) {
        $stmt = $pdo->prepare('SELECT * FROM subscriptions WHERE kind = ? ORDER BY name COLLATE NOCASE');
        $stmt->execute([$kind]);
        $subs = $stmt->fetchAll();
    } else {
        $subs = $pdo->query('SELECT * FROM subscriptions ORDER BY name COLLATE NOCASE')->fetchAll();
    }

    $pricesStmt = $pdo->prepare('SELECT id, cost, effective_from FROM subscription_prices WHERE subscription_id = ? ORDER BY effective_from ASC, id ASC');
    $freezesStmt = $pdo->prepare('SELECT frozen_from, frozen_until FROM subscription_freezes WHERE subscription_id = ? ORDER BY frozen_from ASC');
    $paymentsStmt = $pdo->prepare('SELECT payment_date, amount, is_estimate FROM subscription_payments WHERE subscription_id = ? ORDER BY payment_date ASC');

    $result = [];
    foreach ($subs as $sub) {
        $pricesStmt->execute([$sub['id']]);
        $prices = $pricesStmt->fetchAll();

        $freezesStmt->execute([$sub['id']]);
        $freezes = $freezesStmt->fetchAll();

        $paymentsStmt->execute([$sub['id']]);
        $payments = $paymentsStmt->fetchAll();

        $stats = computeSubscriptionStats($sub, $prices, $freezes, $payments, $today);
        foreach ($prices as $i => $_) {
            $prices[$i]['deletable'] = count($prices) > 1 && !priceIsUsedByPayments($prices, $i, $payments);
        }

        $result[] = [
            'sub'      => $sub,
            'prices'   => $prices,
            'freezes'  => $freezes,
            'payments' => $payments,
            'stats'    => $stats,
        ];
    }
    return $result;
}

/** Όλες οι διακριτές κατηγορίες που υπάρχουν ήδη (για datalist στη φόρμα) */
function getDistinctCategories(PDO $pdo, ?string $kind = null): array
{
    if ($kind !== null) {
        $stmt = $pdo->prepare('SELECT DISTINCT category FROM subscriptions WHERE kind = ? ORDER BY category COLLATE NOCASE');
        $stmt->execute([$kind]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
    return $pdo->query('SELECT DISTINCT category FROM subscriptions ORDER BY category COLLATE NOCASE')
        ->fetchAll(PDO::FETCH_COLUMN);
}

/** Ρυθμίζει τη γλώσσα μηνυμάτων ("συνδρομή" / "πάγια πληρωμή") ανάλογα με τον τύπο της εγγραφής. */
function useKindOf(PDO $pdo, int $subscriptionId): void
{
    $stmt = $pdo->prepare('SELECT kind FROM subscriptions WHERE id = ?');
    $stmt->execute([$subscriptionId]);
    setKind((string) ($stmt->fetchColumn() ?: 'subscription'));
}

/** Αποθηκεύει μήνυμα (success/danger/warning/info) για εμφάνιση στην επόμενη σελίδα */
function flash(string $type, string $msg): void
{
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

/** Τρόποι πληρωμής: μετρητά/κατάθεση ή κάρτα (η κάρτα επιλέγεται από τη λίστα καρτών). */
const PAYMENT_METHODS = ['cash', 'card'];

/**
 * Επικυρώνει τον τρόπο πληρωμής μιας φόρμας. Παλιές εγγραφές έχουν ελεύθερο
 * κείμενο ως τρόπο πληρωμής ($legacy): αν δεν αλλάξει, μένει όπως ήταν.
 * @return array{0: ?string, 1: ?int, 2: ?string} [payment_method, card_id, μήνυμα σφάλματος]
 */
function resolvePaymentMethod(PDO $pdo, string $method, $cardIdRaw, ?string $legacy = null): array
{
    $method = trim($method);
    if ($method === '') {
        return [null, null, null];
    }
    if ($method === 'cash') {
        return ['cash', null, null];
    }
    if ($method === 'card') {
        $cardId = (int) $cardIdRaw;
        $stmt = $pdo->prepare('SELECT 1 FROM cards WHERE id = ?');
        $stmt->execute([$cardId]);
        return $stmt->fetchColumn() ? ['card', $cardId, null] : [null, null, t('err.card_required')];
    }
    if ($legacy !== null && $method === $legacy) {
        return [$legacy, null, null];
    }
    return [null, null, t('err.invalid_payment_method')];
}

/** @return array<int, array{id:int,name:string,last4:string}> οι κάρτες, με κλειδί το id */
function getCardsById(PDO $pdo): array
{
    $out = [];
    foreach ($pdo->query('SELECT id, name, last4 FROM cards ORDER BY name COLLATE NOCASE, last4')->fetchAll() as $c) {
        $out[(int) $c['id']] = $c;
    }
    return $out;
}

function cardLabel(array $card): string
{
    return $card['name'] . ' ••••' . $card['last4'];
}

/** Ετικέτα τρόπου πληρωμής μιας εγγραφής (για λίστα και ιστορικό). */
function paymentMethodLabel(?string $method, $cardId, array $cardsById): string
{
    if ($method === null || $method === '') {
        return '';
    }
    if ($method === 'cash') {
        return t('pm.cash');
    }
    if ($method === 'card') {
        return isset($cardsById[(int) $cardId]) ? cardLabel($cardsById[(int) $cardId]) : t('pm.card');
    }
    return $method; // παλιά ελεύθερη τιμή
}

/** Όλοι οι διακριτοί τρόποι πληρωμής που υπάρχουν ήδη */
function getDistinctPaymentMethods(PDO $pdo): array
{
    return $pdo->query("SELECT DISTINCT payment_method FROM subscriptions WHERE payment_method IS NOT NULL AND payment_method != '' ORDER BY payment_method COLLATE NOCASE")
        ->fetchAll(PDO::FETCH_COLUMN);
}

// --- Ιστορικό ενεργειών (audit log) ------------------------------------------

/**
 * Καταγράφει ποιος έκανε τι σε μια συνδρομή. Δεν πετά ποτέ exception: αν η
 * καταγραφή αποτύχει, η ίδια η ενέργεια δεν πρέπει να χαλάσει.
 *
 * @param string $action created|edited|price_added|price_deleted|payment_confirmed|frozen|unfrozen|canceled|reactivated|expired|deleted
 */
function logActivity(PDO $pdo, int $subId, string $subName, string $action, array $details = [], bool $system = false): void
{
    try {
        $u = (!$system && function_exists('currentUser')) ? currentUser() : null;
        $pdo->prepare('INSERT INTO subscription_activity (subscription_id, subscription_name, user_id, user_name, action, details, created_at) VALUES (?,?,?,?,?,?,?)')
            ->execute([$subId, $subName, $u['id'] ?? null, $u['username'] ?? null, $action, $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
        // η καταγραφή είναι δευτερεύουσα
    }
}

/** Ανθρώπινο κείμενο για μια εγγραφή του ιστορικού, στη γλώσσα του επισκέπτη. */
function activityText(string $action, array $details): string
{
    switch ($action) {
        case 'edited':
            $parts = [];
            foreach ($details['changes'] ?? [] as $c) {
                $parts[] = t('field.' . $c['field']) . ': ' . activityValue($c['field'], $c['old']) . ' → ' . activityValue($c['field'], $c['new']);
            }
            return t('activity.edited') . ($parts ? ' — ' . implode('; ', $parts) : '');
        case 'price_added':
            return t('activity.price_added', ['cost' => euro((float) $details['cost']), 'date' => fdate($details['from'])]);
        case 'price_deleted':
            return t('activity.price_deleted', ['cost' => euro((float) $details['cost']), 'date' => fdate($details['from'])]);
        case 'payment_confirmed':
            return t('activity.payment_confirmed', ['amount' => euro((float) $details['amount']), 'date' => fdate($details['date'])]);
        case 'created':
            return !empty($details['copy_of']) ? t('activity.created_copy', ['name' => $details['copy_of']]) : t('activity.created');
        case 'frozen':
        case 'unfrozen':
        case 'canceled':
        case 'reactivated':
        case 'deleted':
            return t('activity.' . $action);
        case 'expired':
            return t('activity.expired', ['date' => fdate($details['end_date'] ?? null)]);
    }
    return $action;
}

function activityValue(string $field, $v): string
{
    if ($v === null || $v === '') {
        return '—';
    }
    if ($field === 'frequency') {
        return t('freq.' . $v);
    }
    if ($field === 'start_date' || $field === 'end_date') {
        return fdate((string) $v);
    }
    if ($field === 'variable_amount') {
        return $v ? t('activity.yes') : t('activity.no');
    }
    if ($field === 'estimate') {
        return euro((float) $v);
    }
    $v = (string) $v;
    return '"' . (mb_strlen($v) > 60 ? mb_substr($v, 0, 60) . '…' : $v) . '"';
}

/**
 * Ιστορικό ανά συνδρομή, έτοιμο για το JSON του παραθύρου λεπτομερειών.
 * @param int[] $subIds
 * @return array<int, array<int, array{who:string, at:string, text:string}>> νεότερα πρώτα
 */
function getActivityBySubscription(PDO $pdo, array $subIds): array
{
    if (!$subIds) {
        return [];
    }
    $in = implode(',', array_map('intval', $subIds));
    $out = [];
    foreach ($pdo->query("SELECT * FROM subscription_activity WHERE subscription_id IN ($in) ORDER BY id DESC")->fetchAll() as $r) {
        $out[(int) $r['subscription_id']][] = [
            'who'  => $r['user_name'] ?? t('activity.system'),
            'at'   => fdate(substr($r['created_at'], 0, 10)) . ' ' . substr($r['created_at'], 11, 5),
            'text' => activityText($r['action'], $r['details'] ? (json_decode($r['details'], true) ?: []) : []),
        ];
    }
    return $out;
}
