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

$activeCount = 0;
$monthlySum = 0.0;
$annualSum = 0.0;
foreach ($details as $d) {
    if ($d['sub']['status'] !== 'canceled') {
        $activeCount++;
        $monthlySum += $d['stats']['monthly_equivalent'];
        $annualSum += $d['stats']['annual_cost'];
    }
}
$avgPerSub = $activeCount > 0 ? $monthlySum / $activeCount : 0.0;

$categoryTotals = computeCategoryTotals($details);
$freqTotals = computeFrequencyTotals($details);
$statusCounts = computeStatusCounts($details);
$monthlyHistory = computeMonthlySpendHistory($pdo, 12, $today);
$yearlyHistory = computeYearlySpendHistory($pdo, $today);
$forecastHistory = computeForecastSpend($details, $today);
$topSubs = computeTopSubscriptions($details, 5);
$upcoming = computeUpcomingTotals($details, $today);

$palette = ['#1B4B66', '#B8842E', '#2F8F5B', '#3E7CB1', '#8B5FBF', '#C0483C', '#4C8FBD', '#6B8E23', '#A6763E', '#5B7A99'];
$catLabels = array_keys($categoryTotals);
$catAnnual = array_values(array_map(fn($c) => $c['annual'], $categoryTotals));
$catMonthly = array_values(array_map(fn($c) => $c['monthly'], $categoryTotals));
$catColors = [];
foreach ($catLabels as $i => $c) { $catColors[] = $palette[$i % count($palette)]; }

// Ενιαίο χρονολόγιο: ιστορικό (τελευταίοι 12 μήνες) + πρόβλεψη (μέχρι τέλος
// επόμενου έτους) σε ένα μόνο γράφημα, με δύο datasets (null όπου δεν ισχύει
// το καθένα) ώστε να "συνεχίζει" οπτικά με άλλο χρώμα.
$timelineKeys = array_merge(array_keys($monthlyHistory), array_keys($forecastHistory));
$timelineLabels = array_map('monthLabel', $timelineKeys);
$actualValues = [];
$forecastValues = [];
foreach ($timelineKeys as $k) {
    $actualValues[] = array_key_exists($k, $monthlyHistory) ? $monthlyHistory[$k] : null;
    $forecastValues[] = array_key_exists($k, $forecastHistory) ? $forecastHistory[$k] : null;
}

$yearLabels = array_keys($yearlyHistory);
$yearValues = array_values($yearlyHistory);

$topNames = array_map(fn($t) => $t['name'], $topSubs);
$topValues = array_map(fn($t) => $t['annual'], $topSubs);

$statusColors = ['active' => '#2F8F5B', 'trial' => '#3E7CB1', 'frozen' => '#4C8FBD', 'canceled' => '#B0483C'];

$pageTitle = t('page.stats');
require __DIR__ . '/includes/header.php';
?>

<div class="row g-3 mb-4">
  <div class="col-6 col-lg-3">
    <div class="stat-card">
      <div class="label"><?= te('idx.active_subs') ?></div>
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

<div class="row g-3">
  <div class="col-12 col-lg-6">
    <div class="card chart-card">
      <h6><?= te('stats.annual_by_cat') ?></h6>
      <div class="chart-wrap">
        <canvas id="chartCategoryDonut" role="img" aria-label="<?= te('stats.aria.donut') ?>">
          <?php foreach ($categoryTotals as $cat => $t): ?><?= htmlspecialchars($cat) ?>: <?= euro($t['annual']) ?>. <?php endforeach; ?>
        </canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-6">
    <div class="card chart-card">
      <h6><?= te('stats.monthly_by_cat') ?></h6>
      <div class="chart-wrap">
        <canvas id="chartCategoryBar" role="img" aria-label="<?= te('stats.aria.bar_cat') ?>">
          <?php foreach ($categoryTotals as $cat => $t): ?><?= htmlspecialchars($cat) ?>: <?= euro($t['monthly']) ?>. <?php endforeach; ?>
        </canvas>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12 col-lg-7">
    <div class="card chart-card">
      <h6><?= te('stats.history_forecast') ?></h6>
      <div class="chart-wrap">
        <canvas id="chartMonthly" role="img" aria-label="<?= te('stats.aria.monthly') ?>">
          <?php foreach ($monthlyHistory as $ym => $v): ?><?= $ym ?>: <?= euro($v) ?>. <?php endforeach; ?>
          <?php foreach ($forecastHistory as $ym => $v): ?><?= $ym ?> (<?= te('stats.forecast_suffix') ?>): <?= euro($v) ?>. <?php endforeach; ?>
        </canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card chart-card">
      <h6><?= te('stats.per_year') ?></h6>
      <div class="chart-wrap">
        <canvas id="chartYearly" role="img" aria-label="<?= te('stats.aria.yearly') ?>">
          <?php foreach ($yearlyHistory as $y => $v): ?><?= $y ?>: <?= euro($v) ?>. <?php endforeach; ?>
        </canvas>
      </div>
    </div>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-12 col-lg-7">
    <div class="card chart-card">
      <h6><?= te('stats.top') ?></h6>
      <div class="chart-wrap" style="height: <?= max(180, count($topSubs) * 45 + 60) ?>px">
        <canvas id="chartTop" role="img" aria-label="<?= te('stats.aria.top') ?>">
          <?php foreach ($topSubs as $t): ?><?= htmlspecialchars($t['name']) ?>: <?= euro($t['annual']) ?>. <?php endforeach; ?>
        </canvas>
      </div>
    </div>
  </div>
  <div class="col-12 col-lg-5">
    <div class="card chart-card">
      <h6><?= te('stats.status_chart') ?></h6>
      <div class="chart-wrap short">
        <canvas id="chartStatus" role="img" aria-label="<?= te('stats.aria.status') ?>">
          <?php foreach ($statusCounts as $s => $c): ?><?= te('status.' . $s) ?>: <?= $c ?>. <?php endforeach; ?>
        </canvas>
      </div>
      <div class="text-center small text-muted mt-2">
        <?= te('stats.rest_of_month') ?>: <strong class="num"><?= euro($upcoming['rest_of_this_month']) ?></strong> ·
        <?= te('stats.next_month') ?>: <strong class="num"><?= euro($upcoming['next_month']) ?></strong>
      </div>
    </div>
  </div>
</div>

<div class="section-title"><?= te('stats.totals_by_freq') ?></div>
<div class="card">
  <div class="table-responsive">
    <table class="table freq-table mb-0">
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
  </div>
</div>

<script src="assets/chart.min.js"></script>
<script>
const euroFmt = (v) => '€ ' + Number(v).toLocaleString(<?= json_encode(t('meta.locale')) ?>, {minimumFractionDigits: 2, maximumFractionDigits: 2});
Chart.defaults.font.family = "-apple-system, 'Segoe UI', Roboto, Arial, sans-serif";
const cssVar = (n) => getComputedStyle(document.documentElement).getPropertyValue(n).trim();
Chart.defaults.color = cssVar('--text-muted');
Chart.defaults.borderColor = cssVar('--border');
const sliceBorder = cssVar('--surface');

const catLabels = <?= json_encode($catLabels, JSON_UNESCAPED_UNICODE) ?>;
const catColors = <?= json_encode($catColors) ?>;

new Chart(document.getElementById('chartCategoryDonut'), {
  type: 'doughnut',
  data: {
    labels: catLabels,
    datasets: [{ data: <?= json_encode($catAnnual) ?>, backgroundColor: catColors, borderColor: sliceBorder, borderWidth: 2 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
      tooltip: { callbacks: { label: (ctx) => ctx.label + ': ' + euroFmt(ctx.parsed) } }
    }
  }
});

new Chart(document.getElementById('chartCategoryBar'), {
  type: 'bar',
  data: {
    labels: catLabels,
    datasets: [{ label: <?= json_encode(t('stats.dataset.monthly_eq'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($catMonthly) ?>, backgroundColor: catColors, borderRadius: 4, maxBarThickness: 34 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => euroFmt(ctx.parsed.y) } } },
    scales: { y: { beginAtZero: true, ticks: { callback: (v) => euroFmt(v) } } }
  }
});

new Chart(document.getElementById('chartMonthly'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($timelineLabels, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [
      { label: <?= json_encode(t('stats.dataset.actual'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($actualValues) ?>, backgroundColor: cssVar('--chart-brand'), borderRadius: 4, maxBarThickness: 26 },
      { label: <?= json_encode(t('stats.dataset.forecast'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($forecastValues) ?>, backgroundColor: cssVar('--gold'), borderRadius: 4, maxBarThickness: 26 }
    ]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: {
      legend: { display: true, position: 'bottom', labels: { boxWidth: 12, padding: 12 } },
      tooltip: { callbacks: { label: (ctx) => ctx.dataset.label + ': ' + euroFmt(ctx.parsed.y) } }
    },
    scales: { y: { beginAtZero: true, ticks: { callback: (v) => euroFmt(v) } }, x: { ticks: { autoSkip: true, maxRotation: 60, minRotation: 45 } } }
  }
});

new Chart(document.getElementById('chartYearly'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($yearLabels) ?>,
    datasets: [{ label: <?= json_encode(t('stats.dataset.spending'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($yearValues) ?>, backgroundColor: cssVar('--gold'), borderRadius: 4, maxBarThickness: 46 }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => euroFmt(ctx.parsed.y) } } },
    scales: { y: { beginAtZero: true, ticks: { callback: (v) => euroFmt(v) } } }
  }
});

new Chart(document.getElementById('chartTop'), {
  type: 'bar',
  data: {
    labels: <?= json_encode($topNames, JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{ label: <?= json_encode(t('stats.dataset.annual'), JSON_UNESCAPED_UNICODE) ?>, data: <?= json_encode($topValues) ?>, backgroundColor: cssVar('--trial'), borderRadius: 4 }]
  },
  options: {
    indexAxis: 'y',
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { display: false }, tooltip: { callbacks: { label: (ctx) => euroFmt(ctx.parsed.x) } } },
    scales: { x: { beginAtZero: true, ticks: { callback: (v) => euroFmt(v) } } }
  }
});

new Chart(document.getElementById('chartStatus'), {
  type: 'doughnut',
  data: {
    labels: <?= json_encode(array_map(fn($s) => t('status.' . $s), array_keys($statusCounts)), JSON_UNESCAPED_UNICODE) ?>,
    datasets: [{
      data: <?= json_encode(array_values($statusCounts)) ?>,
      backgroundColor: <?= json_encode(array_values($statusColors)) ?>,
      borderColor: sliceBorder, borderWidth: 2
    }]
  },
  options: {
    responsive: true, maintainAspectRatio: false,
    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, padding: 10 } } }
  }
});
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
