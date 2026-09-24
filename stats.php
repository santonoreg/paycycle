<?php
require __DIR__ . '/config.php';
require __DIR__ . '/includes/db.php';
require __DIR__ . '/includes/auth.php';
require __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/i18n.php';
require __DIR__ . '/includes/stats_helpers.php';

$pdo = getDb();
$today = date('Y-m-d');
$details = getAllSubscriptionsWithDetails($pdo, $today);

// --- Κάρτες συνόλων (πάντα για συνδρομές + επαναλαμβανόμενες πληρωμές μαζί) -----
$activeCount = 0;
$monthlySum = 0.0;
$annualSum = 0.0;
foreach ($details as $d) {
    if (isRunningStatus($d['sub']['status'])) {
        $activeCount++;
        $monthlySum += $d['stats']['monthly_equivalent'];
        $annualSum += $d['stats']['annual_cost'];
    }
}
$avgPerSub = $activeCount > 0 ? $monthlySum / $activeCount : 0.0;
$upcoming = computeUpcomingTotals($details, $today);

// --- Δεδομένα ανά "scope": all (σύνολο) / subscription / recurring -----------------
$scopes = ['all' => null, 'subscription' => 'subscription', 'recurring' => 'recurring'];
$palette = ['#1B4B66', '#B8842E', '#2F8F5B', '#3E7CB1', '#8B5FBF', '#C0483C', '#4C8FBD', '#6B8E23', '#A6763E', '#5B7A99'];

$scopeData = [];
$freqByScope = [];
foreach ($scopes as $name => $kind) {
    $det = $kind === null ? $details : array_values(array_filter($details, fn($d) => $d['sub']['kind'] === $kind));

    $cat = computeCategoryTotals($det);
    $monthlyHistory = computeMonthlySpendHistory($pdo, 12, $today, $kind);
    $forecast = computeForecastSpend($det, $today);
    $yearly = computeYearlySpendHistory($pdo, $today, $kind);
    $top = computeTopSubscriptions($det, 5);

    $timelineKeys = array_merge(array_keys($monthlyHistory), array_keys($forecast));
    $actual = [];
    $fore = [];
    foreach ($timelineKeys as $k) {
        $actual[] = array_key_exists($k, $monthlyHistory) ? $monthlyHistory[$k] : null;
        $fore[] = array_key_exists($k, $forecast) ? $forecast[$k] : null;
    }

    $catLabels = array_keys($cat);
    $scopeData[$name] = [
        'catLabels'   => $catLabels,
        'catColors'   => array_map(fn($i) => $palette[$i % count($palette)], array_keys($catLabels)),
        'catAnnual'   => array_values(array_map(fn($c) => $c['annual'], $cat)),
        'catMonthly'  => array_values(array_map(fn($c) => $c['monthly'], $cat)),
        'timeline'    => array_map('monthLabel', $timelineKeys),
        'actual'      => $actual,
        'forecast'    => $fore,
        'yearLabels'  => array_keys($yearly),
        'yearValues'  => array_values($yearly),
        'topNames'    => array_map(fn($t) => $t['name'], $top),
        'topValues'   => array_map(fn($t) => $t['annual'], $top),
    ];
    $freqByScope[$name] = computeFrequencyTotals($det);
}

$scopeLabels = ['all' => t('scope.all'), 'subscription' => t('scope.subscription'), 'recurring' => t('scope.recurring')];

/** Καρτέλες (tabs) επιλογής scope για μια κάρτα γραφήματος. */
function scopeTabs(array $labels): string
{
    $out = '<div class="btn-group btn-group-sm scope-tabs" role="group">';
    $first = true;
    foreach ($labels as $scope => $label) {
        $out .= '<button type="button" class="btn btn-outline-secondary' . ($first ? ' active' : '') . '" data-scope="' . $scope . '">'
            . htmlspecialchars($label) . '</button>';
        $first = false;
    }
    return $out . '</div>';
}

$pageTitle = t('page.stats');
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-1">
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="label"><?= te('stats.active_items') ?></div>
      <div class="value num"><?= $activeCount ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card accent-ok">
      <div class="label"><?= te('stats.monthly_total') ?></div>
      <div class="value num"><?= euro($monthlySum) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card accent-trial">
      <div class="label"><?= te('idx.annual_total') ?></div>
      <div class="value num"><?= euro($annualSum) ?></div>
    </div>
  </div>
  <div class="col-6 col-lg-3">
    <div class="stat-card accent-gold">
      <div class="label"><?= te('stats.avg_per_sub') ?></div>
      <div class="value num"><?= euro($avgPerSub) ?></div>
    </div>
  </div>
</div>
<div class="text-muted small mt-2 mb-4">
  <?= te('stats.rest_of_month') ?>: <strong class="num"><?= euro($upcoming['rest_of_this_month']) ?></strong> ·
  <?= te('stats.next_month') ?>: <strong class="num"><?= euro($upcoming['next_month']) ?></strong>
</div>

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card chart-card" data-card="donut">
      <div class="chart-head"><h6><?= te('stats.annual_by_cat') ?></h6><?= scopeTabs($scopeLabels) ?></div>
      <div class="chart-wrap">
        <canvas id="chartCategoryDonut" role="img" aria-label="<?= te('stats.aria.donut') ?>"></canvas>
        <div class="chart-empty d-none"><?= te('stats.no_data') ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card chart-card" data-card="catbar">
      <div class="chart-head"><h6><?= te('stats.monthly_by_cat') ?></h6><?= scopeTabs($scopeLabels) ?></div>
      <div class="chart-wrap">
        <canvas id="chartCategoryBar" role="img" aria-label="<?= te('stats.aria.bar_cat') ?>"></canvas>
        <div class="chart-empty d-none"><?= te('stats.no_data') ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12 col-lg-7">
    <div class="card chart-card" data-card="monthly">
      <div class="chart-head"><h6><?= te('stats.history_forecast') ?></h6><?= scopeTabs($scopeLabels) ?></div>
      <div class="chart-wrap">
        <canvas id="chartMonthly" role="img" aria-label="<?= te('stats.aria.monthly') ?>"></canvas>
        <div class="chart-empty d-none"><?= te('stats.no_data') ?></div>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card chart-card" data-card="yearly">
      <div class="chart-head"><h6><?= te('stats.per_year') ?></h6><?= scopeTabs($scopeLabels) ?></div>
      <div class="chart-wrap">
        <canvas id="chartYearly" role="img" aria-label="<?= te('stats.aria.yearly') ?>"></canvas>
        <div class="chart-empty d-none"><?= te('stats.no_data') ?></div>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12">
    <div class="card chart-card" data-card="top">
      <div class="chart-head"><h6><?= te('stats.top') ?></h6><?= scopeTabs($scopeLabels) ?></div>
      <div class="chart-wrap" style="height: 300px">
        <canvas id="chartTop" role="img" aria-label="<?= te('stats.aria.top') ?>"></canvas>
        <div class="chart-empty d-none"><?= te('stats.no_data') ?></div>
      </div>
    </div>
  </div>
</div>

<div class="card chart-card mt-4" data-card="freq">
  <div class="chart-head"><h6><?= te('stats.totals_by_freq') ?></h6><?= scopeTabs($scopeLabels) ?></div>
  <div class="table-responsive">
    <?php foreach ($freqByScope as $scope => $freqTotals): ?>
    <table class="table freq-table mb-0 <?= $scope === 'all' ? '' : 'd-none' ?>" data-scope="<?= $scope ?>">
      <thead>
        <tr><th><?= te('stats.type') ?></th><th class="text-end"><?= te('stats.count') ?></th><th class="text-end"><?= te('stats.monthly_eq') ?></th><th class="text-end"><?= te('stats.annual') ?></th></tr>
      </thead>
      <tbody>
        <?php $totCount = 0; $totMonthly = 0; $totAnnual = 0; ?>
        <?php foreach ($freqTotals as $f): $totCount += $f['count']; $totMonthly += $f['monthly']; $totAnnual += $f['annual']; ?>
          <tr>
            <td><?= htmlspecialchars($f['label']) ?></td>
            <td class="text-end num"><?= $f['count'] ?></td>
            <td class="text-end num"><?= euro($f['monthly']) ?></td>
            <td class="text-end num"><?= euro($f['annual']) ?></td>
          </tr>
        <?php endforeach; ?>
        <tr class="fw-bold">
          <td><?= te('stats.total') ?></td>
          <td class="text-end num"><?= $totCount ?></td>
          <td class="text-end num"><?= euro($totMonthly) ?></td>
          <td class="text-end num"><?= euro($totAnnual) ?></td>
        </tr>
      </tbody>
    </table>
    <?php endforeach; ?>
  </div>
</div>

<script src="assets/chart.min.js"></script>
<script>
const DATA = <?= json_encode($scopeData, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
const euroFmt = (v) => '€ ' + Number(v).toLocaleString(<?= json_encode(t('meta.locale')) ?>, {minimumFractionDigits: 2, maximumFractionDigits: 2});
Chart.defaults.font.family = "-apple-system, 'Segoe UI', Roboto, Arial, sans-serif";
const cssVar = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
Chart.defaults.color = cssVar('--text-muted');
Chart.defaults.borderColor = cssVar('--border');
const sliceBorder = cssVar('--surface');

const LBL = {
  monthlyEq: <?= json_encode(t('stats.dataset.monthly_eq'), JSON_UNESCAPED_UNICODE) ?>,
  actual: <?= json_encode(t('stats.dataset.actual'), JSON_UNESCAPED_UNICODE) ?>,
  forecast: <?= json_encode(t('stats.dataset.forecast'), JSON_UNESCAPED_UNICODE) ?>,
  spending: <?= json_encode(t('stats.dataset.spending'), JSON_UNESCAPED_UNICODE) ?>,
  annual: <?= json_encode(t('stats.dataset.annual'), JSON_UNESCAPED_UNICODE) ?>,
};

const SCOPE = <?= json_encode($scopeLabels, JSON_UNESCAPED_UNICODE) ?>;
const FORECAST_TXT = LBL.forecast;
// Χρώματα ανά είδος (συνδρομές / πάγιες πληρωμές) για τα στοιβαγμένα γραφήματα
const KIND_COLOR = { subscription: cssVar('--chart-brand'), recurring: cssVar('--ok') };
const faded = (hex) => hex + '88'; // ίδιο χρώμα με διαφάνεια = πρόβλεψη
// Ευθυγραμμίζει τιμές μιας ετικέτας-λίστας σε άλλη λίστα ετικετών (έτη)
const align = (labels, srcLabels, srcValues) => labels.map((l) => { const i = srcLabels.indexOf(l); return i < 0 ? 0 : srcValues[i]; });
const stackedTooltip = {
  mode: 'index', intersect: false,
  callbacks: {
    label: (ctx) => ctx.dataset.label + ': ' + euroFmt(ctx.parsed.y),
    footer: (items) => items.length > 1 ? '= ' + euroFmt(items.reduce((s, i) => s + (i.parsed.y || 0), 0)) : ''
  }
};
const plainTooltip = { mode: 'nearest', intersect: true, callbacks: { label: (ctx) => ctx.dataset.label + ': ' + euroFmt(ctx.parsed.y), footer: () => '' } };

const hasData = (arr) => arr.some((v) => Number(v) > 0);
const yScale = { y: { beginAtZero: true, ticks: { callback: (v) => euroFmt(v) } } };

// Κάθε κάρτα έχει ΕΝΑ γράφημα· οι καρτέλες αλλάζουν μόνο τα δεδομένα του.
// apply(chart, data) γεμίζει το γράφημα και επιστρέφει true αν δεν υπάρχουν δεδομένα.
const cards = {};
function register(cardKey, canvasId, config, apply) {
  const chart = new Chart(document.getElementById(canvasId), config);
  const card = document.querySelector('[data-card="' + cardKey + '"]');
  cards[cardKey] = function (scope) {
    const empty = apply(chart, DATA[scope], scope);
    chart.update();
    card.querySelector('.chart-empty').classList.toggle('d-none', !empty);
  };
  cards[cardKey]('all');
}

register('donut', 'chartCategoryDonut', {
  type: 'doughnut',
  data: { labels: [], datasets: [{ data: [], backgroundColor: [], borderColor: sliceBorder, borderWidth: 2 }] },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
      tooltip: { callbacks: { label: (ctx) => ctx.label + ': ' + euroFmt(ctx.parsed) } }
    }
  }
}, (chart, d) => {
  chart.data.labels = d.catLabels;
  chart.data.datasets[0].data = d.catAnnual;
  chart.data.datasets[0].backgroundColor = d.catColors;
  return !hasData(d.catAnnual);
});

register('catbar', 'chartCategoryBar', {
  type: 'bar',
  data: { labels: [], datasets: [{ label: LBL.monthlyEq, data: [], backgroundColor: [], borderRadius: 4, maxBarThickness: 34 }] },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => euroFmt(ctx.parsed.y) } } },
    scales: yScale
  }
}, (chart, d) => {
  chart.data.labels = d.catLabels;
  chart.data.datasets[0].data = d.catMonthly;
  chart.data.datasets[0].backgroundColor = d.catColors;
  return !hasData(d.catMonthly);
});

register('monthly', 'chartMonthly', {
  type: 'bar',
  data: {
    labels: [],
    datasets: [
      { label: LBL.actual, data: [], backgroundColor: cssVar('--chart-brand'), borderRadius: 4, maxBarThickness: 26 },
      { label: LBL.forecast, data: [], backgroundColor: cssVar('--gold'), borderRadius: 4, maxBarThickness: 26 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
      tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ' + euroFmt(ctx.parsed.y) } }
    },
    scales: { y: { ...yScale.y }, x: { ticks: { autoSkip: true, maxRotation: 60, minRotation: 45 } } }
  }
}, (chart, d, scope) => {
  chart.data.labels = d.timeline;
  const bar = (label, data, color) => ({ label, data, backgroundColor: color, borderRadius: 3, maxBarThickness: 26 });
  const stacked = scope === 'all';
  if (stacked) {
    // Σύνολο: στοιβαγμένες μπάρες συνδρομές + πάγιες (πραγματικά έξοδα και πρόβλεψη)
    const s = DATA.subscription, r = DATA.recurring;
    chart.data.datasets = [
      bar(SCOPE.subscription, s.actual, KIND_COLOR.subscription),
      bar(SCOPE.recurring, r.actual, KIND_COLOR.recurring),
      bar(SCOPE.subscription + ' – ' + FORECAST_TXT, s.forecast, faded(KIND_COLOR.subscription)),
      bar(SCOPE.recurring + ' – ' + FORECAST_TXT, r.forecast, faded(KIND_COLOR.recurring)),
    ];
  } else {
    chart.data.datasets = [
      bar(LBL.actual, d.actual, cssVar('--chart-brand')),
      bar(LBL.forecast, d.forecast, cssVar('--gold')),
    ];
  }
  chart.options.scales.x.stacked = stacked;
  chart.options.scales.y.stacked = stacked;
  chart.options.plugins.tooltip = stacked ? stackedTooltip : plainTooltip;
  return !hasData(d.actual) && !hasData(d.forecast);
});

register('yearly', 'chartYearly', {
  type: 'bar',
  data: { labels: [], datasets: [{ label: LBL.spending, data: [], backgroundColor: cssVar('--gold'), borderRadius: 4, maxBarThickness: 46 }] },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: {} },
    scales: { y: { beginAtZero: true, ticks: { callback: (v) => euroFmt(v) } }, x: {} }
  }
}, (chart, d, scope) => {
  chart.data.labels = d.yearLabels;
  const stacked = scope === 'all';
  if (stacked) {
    const s = DATA.subscription, r = DATA.recurring;
    const mk = (label, vals, color) => ({ label, data: vals, backgroundColor: color, borderRadius: 3, maxBarThickness: 46 });
    chart.data.datasets = [
      mk(SCOPE.subscription, align(d.yearLabels, s.yearLabels, s.yearValues), KIND_COLOR.subscription),
      mk(SCOPE.recurring, align(d.yearLabels, r.yearLabels, r.yearValues), KIND_COLOR.recurring),
    ];
  } else {
    chart.data.datasets = [{ label: LBL.spending, data: d.yearValues, backgroundColor: cssVar('--gold'), borderRadius: 4, maxBarThickness: 46 }];
  }
  chart.options.scales.x.stacked = stacked;
  chart.options.scales.y.stacked = stacked;
  chart.options.plugins.legend.display = stacked;
  chart.options.plugins.legend.position = 'bottom';
  chart.options.plugins.legend.labels = { boxWidth: 12, padding: 12 };
  chart.options.plugins.tooltip = stacked ? stackedTooltip : plainTooltip;
  return !hasData(d.yearValues);
});

register('top', 'chartTop', {
  type: 'bar',
  data: { labels: [], datasets: [{ label: LBL.annual, data: [], backgroundColor: cssVar('--trial'), borderRadius: 4 }] },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => euroFmt(ctx.parsed.x) } } },
    scales: { x: { beginAtZero: true, ticks: { callback: (v) => euroFmt(v) } } }
  }
}, (chart, d) => {
  chart.data.labels = d.topNames;
  chart.data.datasets[0].data = d.topValues;
  return !hasData(d.topValues);
});

// Πίνακας συχνοτήτων: εμφανίζεται ο πίνακας του επιλεγμένου scope.
cards.freq = function (scope) {
  document.querySelectorAll('[data-card="freq"] table[data-scope]').forEach((tbl) => {
    tbl.classList.toggle('d-none', tbl.dataset.scope !== scope);
  });
};

document.querySelectorAll('.scope-tabs').forEach((group) => {
  group.addEventListener('click', (e) => {
    const btn = e.target.closest('button[data-scope]');
    if (!btn) return;
    group.querySelectorAll('button').forEach((b) => b.classList.toggle('active', b === btn));
    cards[group.closest('[data-card]').dataset.card](btn.dataset.scope);
  });
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
