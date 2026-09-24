<?php
/**
 * stats_helpers.php
 * Συναρτήσεις συγκεντρωτικών στατιστικών για το dashboard (stats.php).
 * Δουλεύουν πάνω στο αποτέλεσμα της getAllSubscriptionsWithDetails().
 */

function monthLabel(string $ym): string
{
    [$y, $m] = explode('-', $ym);
    $months = tList('months_short');
    return ($months[((int) $m) - 1] ?? $m) . " '" . substr($y, 2, 2);
}

/**
 * Μηνιαία ιστορικά έξοδα, τελευταίοι $monthsBack μήνες (συμπεριλαμβανομένου του τρέχοντος).
 * Διαβάζεται απευθείας από το βιβλίο πληρωμών (subscription_payments) — αυτό
 * είναι πλέον η μοναδική πηγή αλήθειας, οπότε αντανακλά και τυχόν αναδρομικές
 * διορθώσεις τιμών.
 *
 * @return array<string,float> κλειδί "Y-m" -> σύνολο ευρώ, σε χρονολογική σειρά
 */
function computeMonthlySpendHistory(PDO $pdo, int $monthsBack = 12, ?string $today = null, ?string $kind = null): array
{
    $today = $today ?? date('Y-m-d');
    $end = new DateTime($today);
    $start = (clone $end)->modify('first day of this month')->modify('-' . ($monthsBack - 1) . ' months');

    $buckets = [];
    $cursor = clone $start;
    for ($i = 0; $i < $monthsBack; $i++) {
        $buckets[$cursor->format('Y-m')] = 0.0;
        $cursor->modify('+1 month');
    }

    $sql = "SELECT substr(p.payment_date,1,7) AS ym, SUM(p.amount) AS total
            FROM subscription_payments p JOIN subscriptions s ON s.id = p.subscription_id
            WHERE p.payment_date >= ?" . ($kind !== null ? ' AND s.kind = ?' : '') . ' GROUP BY ym';
    $stmt = $pdo->prepare($sql);
    $stmt->execute($kind !== null ? [$start->format('Y-m-d'), $kind] : [$start->format('Y-m-d')]);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($buckets[$row['ym']])) {
            $buckets[$row['ym']] = round((float) $row['total'], 2);
        }
    }
    return $buckets;
}

/**
 * Έξοδα ανά έτος, από το παλαιότερο έτος με καταχωρημένη πληρωμή μέχρι φέτος.
 * Διαβάζεται απευθείας από το βιβλίο πληρωμών.
 * @return array<string,float> κλειδί έτος -> σύνολο ευρώ
 */
function computeYearlySpendHistory(PDO $pdo, ?string $today = null, ?string $kind = null): array
{
    $today = $today ?? date('Y-m-d');
    $currentYear = (int) substr($today, 0, 4);

    $kindSql = $kind !== null ? ' AND s.kind = ?' : '';
    $kindArgs = $kind !== null ? [$kind] : [];
    $minStmt = $pdo->prepare('SELECT MIN(substr(p.payment_date,1,4)) FROM subscription_payments p JOIN subscriptions s ON s.id = p.subscription_id WHERE 1=1' . $kindSql);
    $minStmt->execute($kindArgs);
    $minYearRow = $minStmt->fetchColumn();
    $minYear = $minYearRow ? (int) $minYearRow : $currentYear;

    $buckets = [];
    for ($y = $minYear; $y <= $currentYear; $y++) {
        $buckets[(string) $y] = 0.0;
    }

    $stmt = $pdo->prepare('SELECT substr(p.payment_date,1,4) AS y, SUM(p.amount) AS total FROM subscription_payments p JOIN subscriptions s ON s.id = p.subscription_id WHERE 1=1' . $kindSql . ' GROUP BY y');
    $stmt->execute($kindArgs);
    foreach ($stmt->fetchAll() as $row) {
        if (isset($buckets[$row['y']])) {
            $buckets[$row['y']] = round((float) $row['total'], 2);
        }
    }
    return $buckets;
}

/**
 * Πρόβλεψη μηνιαίων εξόδων από τον ΕΠΟΜΕΝΟ μήνα μέχρι το τέλος του ΕΠΟΜΕΝΟΥ
 * έτους, με βάση τις ενεργές/δοκιμαστικές συνδρομές. Για κάθε τέτοια
 * συνδρομή, ξεκινάει από την ήδη υπολογισμένη επόμενη πληρωμή της
 * (next_payment_date) και προχωράει κατά τη συχνότητά της μέχρι το τέλος του
 * παραθύρου πρόβλεψης, χρησιμοποιώντας την τιμή που θα ισχύει σε κάθε
 * ημερομηνία (τρέχουσα, ή ήδη προγραμματισμένη μελλοντική αν έχει καταχωρηθεί
 * στο ιστορικό τιμών). Παγωμένες/ακυρωμένες συνδρομές δεν έχουν
 * next_payment_date και εξαιρούνται αυτόματα.
 *
 * @return array<string,float> κλειδί "Y-m" -> προβλεπόμενο σύνολο ευρώ, από
 *         τον επόμενο μήνα μέχρι τον Δεκέμβριο του επόμενου έτους
 */
function computeForecastSpend(array $details, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $todayDt = new DateTime($today);

    $forecastStart = (clone $todayDt)->modify('first day of next month');
    $forecastEnd = new DateTime((((int) $todayDt->format('Y')) + 1) . '-12-31');
    $forecastStartStr = $forecastStart->format('Y-m-d');
    $forecastEndStr = $forecastEnd->format('Y-m-d');

    $buckets = [];
    $cursorMonth = clone $forecastStart;
    while ($cursorMonth <= $forecastEnd) {
        $buckets[$cursorMonth->format('Y-m')] = 0.0;
        $cursorMonth->modify('+1 month');
    }

    foreach ($details as $d) {
        $sub = $d['sub'];
        if (!in_array($sub['status'], ['active', 'trial'], true)) {
            continue;
        }
        $nextDate = $d['stats']['next_payment_date'];
        if ($nextDate === null) {
            continue;
        }
        $interval = new DateInterval(frequencyIntervalSpec($sub['frequency']));
        $cursor = new DateTime($nextDate);
        $safety = 0;
        $remaining = $d['stats']['installments_remaining'] ?? null; // null = χωρίς όριο δόσεων
        $counted = 0;
        while ($cursor <= $forecastEnd && $safety < 500) {
            if ($remaining !== null && $counted >= $remaining) {
                break; // δεν υπάρχουν άλλες δόσεις
            }
            $counted++;
            $dateStr = $cursor->format('Y-m-d');
            if ($dateStr >= $forecastStartStr && $dateStr <= $forecastEndStr) {
                $ym = substr($dateStr, 0, 7);
                if (isset($buckets[$ym])) {
                    $buckets[$ym] += !empty($sub['variable_amount'])
                        ? estimateFromPayments($d['payments'], $d['prices'], $dateStr)
                        : priceAtDate($d['prices'], $dateStr);
                }
            }
            $cursor->add($interval);
            $safety++;
        }
    }

    foreach ($buckets as $k => $v) {
        $buckets[$k] = round($v, 2);
    }
    return $buckets;
}

/**
 * Σύνολα ανά κατηγορία (ετήσιο κόστος & μηνιαίο ισοδύναμο) για μη-ακυρωμένες συνδρομές,
 * ταξινομημένα φθίνουσα κατά ετήσιο κόστος.
 * @return array<string, array{annual: float, monthly: float, count: int}>
 */
function computeCategoryTotals(array $details): array
{
    $out = [];
    foreach ($details as $d) {
        if (!isRunningStatus($d['sub']['status'])) {
            continue;
        }
        $cat = $d['sub']['category'];
        if (!isset($out[$cat])) {
            $out[$cat] = ['annual' => 0.0, 'monthly' => 0.0, 'count' => 0];
        }
        $out[$cat]['annual']  += $d['stats']['annual_cost'];
        $out[$cat]['monthly'] += $d['stats']['monthly_equivalent'];
        $out[$cat]['count']++;
    }
    foreach ($out as $k => $v) {
        $out[$k]['annual']  = round($v['annual'], 2);
        $out[$k]['monthly'] = round($v['monthly'], 2);
    }
    uasort($out, fn($a, $b) => $b['annual'] <=> $a['annual']);
    return $out;
}

/**
 * Σύνολα ανά συχνότητα (όπως ο πίνακας "Totals" του Excel), για μη-ακυρωμένες συνδρομές.
 * @return array<string, array{label:string, count:int, monthly:float, annual:float}>
 */
function computeFrequencyTotals(array $details): array
{
    $out = [];
    foreach (frequencies() as $key => $label) {
        $out[$key] = ['label' => $label, 'count' => 0, 'monthly' => 0.0, 'annual' => 0.0];
    }
    foreach ($details as $d) {
        if (!isRunningStatus($d['sub']['status'])) {
            continue;
        }
        $f = $d['sub']['frequency'];
        $out[$f]['count']++;
        $out[$f]['monthly'] += $d['stats']['monthly_equivalent'];
        $out[$f]['annual']  += $d['stats']['annual_cost'];
    }
    foreach ($out as $k => $v) {
        $out[$k]['monthly'] = round($v['monthly'], 2);
        $out[$k]['annual']  = round($v['annual'], 2);
    }
    return $out;
}

/** Πλήθος συνδρομών ανά κατάσταση */
function computeStatusCounts(array $details): array
{
    $out = array_fill_keys(STATUS_KEYS, 0);
    foreach ($details as $d) {
        $out[$d['sub']['status']]++;
    }
    return $out;
}

/**
 * Πόσο "πέφτει" να πληρωθεί το υπόλοιπο του τρέχοντος μήνα και πόσο τον επόμενο,
 * με βάση τις next_payment_date/next_payment_amount κάθε συνδρομής.
 */
function computeUpcomingTotals(array $details, ?string $today = null): array
{
    $today = $today ?? date('Y-m-d');
    $todayDt = new DateTime($today);

    $thisMonthEnd   = (clone $todayDt)->modify('last day of this month')->format('Y-m-d');
    $nextMonthStart = (clone $todayDt)->modify('first day of next month')->format('Y-m-d');
    $nextMonthEnd   = (clone $todayDt)->modify('last day of next month')->format('Y-m-d');

    $restOfThisMonth = 0.0;
    $nextMonth = 0.0;

    foreach ($details as $d) {
        $npd = $d['stats']['next_payment_date'];
        $npa = $d['stats']['next_payment_amount'];
        if ($npd === null || $npa === null) {
            continue;
        }
        if ($npd >= $today && $npd <= $thisMonthEnd) {
            $restOfThisMonth += $npa;
        } elseif ($npd >= $nextMonthStart && $npd <= $nextMonthEnd) {
            $nextMonth += $npa;
        }
    }

    return [
        'rest_of_this_month' => round($restOfThisMonth, 2),
        'next_month'         => round($nextMonth, 2),
    ];
}

/** Top-N συνδρομές κατά ετήσιο κόστος (μη ακυρωμένες) */
function computeTopSubscriptions(array $details, int $limit = 5): array
{
    $rows = [];
    foreach ($details as $d) {
        if (!isRunningStatus($d['sub']['status'])) {
            continue;
        }
        $rows[] = ['name' => $d['sub']['name'], 'annual' => $d['stats']['annual_cost']];
    }
    usort($rows, fn($a, $b) => $b['annual'] <=> $a['annual']);
    return array_slice($rows, 0, $limit);
}
