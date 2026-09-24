<?php
/**
 * functions.php
 * Πυρήνας υπολογισμών: πόσες δόσεις έχουν πληρωθεί, πότε είναι η επόμενη,
 * πόσο κοστίζει κάθε δόση (λαμβάνοντας υπόψη το ιστορικό τιμών) και ποιες
 * περίοδοι πρέπει να εξαιρεθούν επειδή η συνδρομή ήταν παγωμένη.
 */

const FREQUENCY_KEYS = ['weekly', 'monthly', 'half-yearly', 'yearly', 'biennial', 'triennial'];
const STATUS_KEYS = ['active', 'trial', 'frozen', 'canceled'];

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

function monthlyEquivalent(float $cost, string $frequency): float
{
    return $cost * periodsPerYear($frequency) / 12.0;
}

function annualCost(float $cost, string $frequency): float
{
    return $cost * periodsPerYear($frequency);
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
    $frequency = $sub['frequency'];
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

    $ins = $pdo->prepare('INSERT OR IGNORE INTO subscription_payments (subscription_id, payment_date, amount) VALUES (?,?,?)');
    $inserted = 0;
    $safety = 0;
    while ($cursor <= $end && $safety < 5000) {
        $d = $cursor->format('Y-m-d');
        if (!isFrozenAt($freezes, $d)) {
            $ins->execute([$sub['id'], $d, priceAtDate($prices, $d)]);
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
    $subs = $pdo->query("SELECT * FROM subscriptions WHERE status != 'canceled'")->fetchAll();
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

    if (in_array($sub['status'], ['active', 'trial'], true)) {
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
        $nextPaymentAmount = priceAtDate($prices, $nextPaymentDate);
    }

    $currentPrice = priceAtDate($prices, $today);
    // Αν δεν υπάρχει ακόμα καμία εγγραφή τιμής με effective_from <= today
    // (π.χ. μελλοντική συνδρομή), πάρε την πρώτη γνωστή τιμή.
    if ($currentPrice === 0.0 && !empty($prices)) {
        $currentPrice = (float) $prices[0]['cost'];
    }

    return [
        'installments_paid'   => $installmentsPaid,
        'total_paid'          => round($totalPaid, 2),
        'current_price'       => round($currentPrice, 2),
        'monthly_equivalent'  => round(monthlyEquivalent($currentPrice, $frequency), 2),
        'annual_cost'         => round(annualCost($currentPrice, $frequency), 2),
        'next_payment_date'   => $nextPaymentDate,
        'next_payment_amount' => $nextPaymentAmount !== null ? round($nextPaymentAmount, 2) : null,
        'is_frozen_now'       => $sub['status'] === 'frozen',
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
    $paymentsStmt = $pdo->prepare('SELECT payment_date, amount FROM subscription_payments WHERE subscription_id = ? ORDER BY payment_date ASC');

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

/** Όλοι οι διακριτοί τρόποι πληρωμής που υπάρχουν ήδη */
function getDistinctPaymentMethods(PDO $pdo): array
{
    return $pdo->query("SELECT DISTINCT payment_method FROM subscriptions WHERE payment_method IS NOT NULL AND payment_method != '' ORDER BY payment_method COLLATE NOCASE")
        ->fetchAll(PDO::FETCH_COLUMN);
}
