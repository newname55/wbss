<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_casts.php';
require_once __DIR__ . '/../app/service_points_kpi.php';
require_once __DIR__ . '/../app/attendance.php';

require_login();
require_role(['admin', 'super_user']);

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('fmt_point')) {
  function fmt_point(float $value): string {
    $s = number_format($value, 1, '.', '');
    return rtrim(rtrim($s, '0'), '.');
  }
}

function cast_kpi_minutes_label(int $minutes): string {
  $hours = intdiv(max(0, $minutes), 60);
  $mins = max(0, $minutes) % 60;
  return sprintf('%d:%02d', $hours, $mins);
}

function cast_kpi_has_column(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = $table . '.' . $column;
  if (array_key_exists($key, $cache)) return $cache[$key];

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    $cache[$key] = ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    $cache[$key] = false;
  }

  return $cache[$key];
}

function cast_kpi_hourly_wage_map(PDO $pdo, int $storeId): array {
  $candidates = ['hourly_wage_yen', 'hourly_wage', 'wage_hourly_yen', 'wage_yen'];

  $suCol = '';
  foreach ($candidates as $col) {
    if (cast_kpi_has_column($pdo, 'store_users', $col)) { $suCol = $col; break; }
  }
  $cpCol = '';
  foreach ($candidates as $col) {
    if (cast_kpi_has_column($pdo, 'cast_profiles', $col)) { $cpCol = $col; break; }
  }
  $uCol = '';
  foreach ($candidates as $col) {
    if (cast_kpi_has_column($pdo, 'users', $col)) { $uCol = $col; break; }
  }

  if ($suCol === '' && $cpCol === '' && $uCol === '') return [];

  $fields = ["su.user_id"];
  if ($suCol !== '') $fields[] = "su.`{$suCol}` AS su_wage";
  if ($cpCol !== '') $fields[] = "cp.`{$cpCol}` AS cp_wage";
  if ($uCol !== '') $fields[] = "u.`{$uCol}` AS u_wage";

  $sql = "
    SELECT " . implode(",\n      ", $fields) . "
    FROM store_users su
    JOIN users u ON u.id = su.user_id
    " . ($cpCol !== '' ? "LEFT JOIN cast_profiles cp ON cp.user_id=su.user_id AND (cp.store_id=su.store_id OR cp.store_id IS NULL)" : "") . "
    WHERE su.store_id = ?
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $map = [];
  foreach ($rows as $row) {
    $userId = (int)($row['user_id'] ?? 0);
    if ($userId <= 0) continue;

    $wage = 0;
    foreach (['su_wage', 'cp_wage', 'u_wage'] as $key) {
      if (isset($row[$key]) && is_numeric($row[$key])) {
        $wage = max(0, (int)$row[$key]);
        if ($wage > 0) break;
      }
    }
    $map[$userId] = $wage;
  }

  return $map;
}

function cast_kpi_fixed_cost_total(PDO $pdo, int $storeId, string $fromDate, string $toDate): int {
  $monthlyCandidates = [
    'monthly_fixed_cost_yen',
    'fixed_cost_monthly_yen',
    'monthly_fixed_cost',
    'fixed_cost_monthly',
    'rent_monthly_yen',
    'monthly_rent_yen',
    'utilities_monthly_yen',
    'monthly_utilities_yen',
    'other_fixed_cost_monthly_yen',
  ];
  $dailyCandidates = [
    'daily_fixed_cost_yen',
    'fixed_cost_daily_yen',
  ];

  $monthlyCols = [];
  foreach ($monthlyCandidates as $col) {
    if (cast_kpi_has_column($pdo, 'stores', $col)) $monthlyCols[] = $col;
  }
  $dailyCols = [];
  foreach ($dailyCandidates as $col) {
    if (cast_kpi_has_column($pdo, 'stores', $col)) $dailyCols[] = $col;
  }
  if ($monthlyCols === [] && $dailyCols === []) return 0;

  $selects = [];
  foreach (array_merge($monthlyCols, $dailyCols) as $col) {
    $selects[] = "`{$col}`";
  }

  $st = $pdo->prepare("SELECT " . implode(', ', $selects) . " FROM stores WHERE id=? LIMIT 1");
  $st->execute([$storeId]);
  $store = $st->fetch(PDO::FETCH_ASSOC);
  if (!$store) return 0;

  $from = new DateTimeImmutable($fromDate, new DateTimeZone('Asia/Tokyo'));
  $to = new DateTimeImmutable($toDate, new DateTimeZone('Asia/Tokyo'));
  $total = 0;

  foreach ($dailyCols as $col) {
    if (isset($store[$col]) && is_numeric($store[$col])) {
      $days = (int)$from->diff($to)->format('%a') + 1;
      $total += max(0, (int)$store[$col]) * max(0, $days);
      break;
    }
  }

  if ($monthlyCols !== []) {
    $cursor = $from;
    while ($cursor <= $to) {
      $monthEnd = $cursor->modify('last day of this month');
      $segmentEnd = $monthEnd < $to ? $monthEnd : $to;
      $segmentDays = (int)$cursor->diff($segmentEnd)->format('%a') + 1;
      $daysInMonth = (int)$cursor->format('t');

      $monthlyTotal = 0;
      foreach ($monthlyCols as $col) {
        if (isset($store[$col]) && is_numeric($store[$col])) {
          $monthlyTotal += max(0, (int)$store[$col]);
        }
      }

      if ($monthlyTotal > 0 && $daysInMonth > 0) {
        $total += (int)round($monthlyTotal * ($segmentDays / $daysInMonth));
      }

      $cursor = $segmentEnd->modify('+1 day');
    }
  }

  return $total;
}

function cast_kpi_rank_label(string $rankingBy): string {
  return match ($rankingBy) {
    'profit_contribution' => '利益寄与',
    'sales_total' => '売上寄与',
    'sales_per_hour' => '売上/時',
    'ticket_count' => '担当伝票',
    'shimei_point' => '指名pt',
    default => '指標',
  };
}

function cast_kpi_rank_value_text(string $rankingBy, array $row): string {
  $value = $row[$rankingBy] ?? 0;
  return match ($rankingBy) {
    'ticket_count' => number_format((int)$value),
    'shimei_point' => fmt_point((float)$value),
    default => '¥' . number_format((int)$value),
  };
}

$pdo = db();
$stores = $pdo->query("SELECT id, name FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
$storeOptions = [0 => '全店舗'];
foreach ($stores as $store) {
  $storeId = (int)($store['id'] ?? 0);
  if ($storeId > 0) $storeOptions[$storeId] = (string)($store['name'] ?? ('#' . $storeId));
}

$today = new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo'));
$defaultFrom = $today->modify('first day of this month')->format('Y-m-d');
$defaultTo = $today->format('Y-m-d');

$fromDate = (string)($_GET['from_date'] ?? $defaultFrom);
$toDate = (string)($_GET['to_date'] ?? $defaultTo);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fromDate)) $fromDate = $defaultFrom;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $toDate)) $toDate = $defaultTo;
if ($fromDate > $toDate) [$fromDate, $toDate] = [$toDate, $fromDate];

$selectedStoreId = (int)($_GET['store_id'] ?? 0);
if (!isset($storeOptions[$selectedStoreId])) $selectedStoreId = 0;

$rankingBy = (string)($_GET['ranking_by'] ?? 'profit_contribution');
$allowedRanking = ['profit_contribution', 'sales_total', 'sales_per_hour', 'ticket_count', 'shimei_point'];
if (!in_array($rankingBy, $allowedRanking, true)) $rankingBy = 'profit_contribution';

$rows = [];
$summary = [
  'cast_count' => 0,
  'sales_total' => 0,
  'ticket_count' => 0,
  'worked_minutes' => 0,
  'attendance_days' => 0,
  'shimei_total' => 0.0,
  'douhan_total' => 0.0,
  'beverage_cost_total' => 0,
  'labor_cost_total' => 0,
  'fixed_cost_total' => 0,
  'profit_contribution_total' => 0,
];

foreach ($stores as $store) {
  $storeId = (int)($store['id'] ?? 0);
  if ($storeId <= 0) continue;
  if ($selectedStoreId > 0 && $selectedStoreId !== $storeId) continue;

  $casts = repo_points_casts_for_store($pdo, $storeId);
  if (!$casts) continue;

  $pointReport = points_kpi_range_summary($pdo, $storeId, $fromDate, $toDate, $casts);
  $pointRows = [];
  foreach (($pointReport['rows'] ?? []) as $pointRow) {
    $pointRows[(int)($pointRow['user_id'] ?? 0)] = $pointRow;
  }

  $attendanceRows = att_fetch_term_report($pdo, $storeId, $fromDate, $toDate);
  $wageMap = cast_kpi_hourly_wage_map($pdo, $storeId);
  $storeFixedCostTotal = cast_kpi_fixed_cost_total($pdo, $storeId, $fromDate, $toDate);
  $storeWorkedMinutesTotal = 0;
  foreach ($attendanceRows as $attendanceRow) {
    $storeWorkedMinutesTotal += (int)($attendanceRow['worked_minutes'] ?? 0);
  }

  foreach ($attendanceRows as $attendanceRow) {
    $userId = (int)($attendanceRow['user_id'] ?? 0);
    if ($userId <= 0) continue;

    $pointRow = $pointRows[$userId] ?? [
      'user_id' => $userId,
      'display_name' => (string)($attendanceRow['display_name'] ?? ('user#' . $userId)),
      'shop_tag' => trim((string)($attendanceRow['shop_tag'] ?? '')),
      'shimei_point' => 0.0,
      'douhan_count' => 0.0,
      'drink_total' => 0,
      'drink_cost_total' => 0,
      'bottle_total' => 0,
      'bottle_cost_total' => 0,
      'ticket_count' => 0,
    ];

    $salesTotal = (int)($pointRow['drink_total'] ?? 0) + (int)($pointRow['bottle_total'] ?? 0);
    $beverageCostTotal = (int)($pointRow['drink_cost_total'] ?? 0) + (int)($pointRow['bottle_cost_total'] ?? 0);
    $attendanceDays = (int)($attendanceRow['attendance_days'] ?? 0);
    $workedMinutes = (int)($attendanceRow['worked_minutes'] ?? 0);
    $ticketCount = (int)($pointRow['ticket_count'] ?? 0);
    $salesPerDay = $attendanceDays > 0 ? (int)floor($salesTotal / $attendanceDays) : 0;
    $salesPerHour = $workedMinutes > 0 ? (int)floor($salesTotal / ($workedMinutes / 60)) : 0;
    $hourlyWage = max(0, (int)($wageMap[$userId] ?? 0));
    $laborCost = $workedMinutes > 0 ? (int)round($hourlyWage * ($workedMinutes / 60)) : 0;
    $allocatedFixedCost = ($storeFixedCostTotal > 0 && $storeWorkedMinutesTotal > 0 && $workedMinutes > 0)
      ? (int)round($storeFixedCostTotal * ($workedMinutes / $storeWorkedMinutesTotal))
      : 0;
    $profitContribution = $salesTotal - $beverageCostTotal - $laborCost - $allocatedFixedCost;

    $rows[] = [
      'store_id' => $storeId,
      'store_name' => (string)($store['name'] ?? ('#' . $storeId)),
      'user_id' => $userId,
      'shop_tag' => trim((string)($pointRow['shop_tag'] ?? $attendanceRow['shop_tag'] ?? '')),
      'display_name' => (string)($pointRow['display_name'] ?? $attendanceRow['display_name'] ?? ('user#' . $userId)),
      'attendance_days' => $attendanceDays,
      'worked_minutes' => $workedMinutes,
      'ticket_count' => $ticketCount,
      'drink_total' => (int)($pointRow['drink_total'] ?? 0),
      'drink_cost_total' => (int)($pointRow['drink_cost_total'] ?? 0),
      'bottle_total' => (int)($pointRow['bottle_total'] ?? 0),
      'bottle_cost_total' => (int)($pointRow['bottle_cost_total'] ?? 0),
      'sales_total' => $salesTotal,
      'beverage_cost_total' => $beverageCostTotal,
      'hourly_wage' => $hourlyWage,
      'labor_cost' => $laborCost,
      'allocated_fixed_cost' => $allocatedFixedCost,
      'profit_contribution' => $profitContribution,
      'sales_per_day' => $salesPerDay,
      'sales_per_hour' => $salesPerHour,
      'shimei_point' => (float)($pointRow['shimei_point'] ?? 0.0),
      'douhan_count' => (float)($pointRow['douhan_count'] ?? 0.0),
      'late_count' => (int)($attendanceRow['late_count'] ?? 0),
      'absent_days' => (int)($attendanceRow['absent_days'] ?? 0),
    ];
  }
}

usort($rows, static function(array $a, array $b): int {
  $profitCmp = ((int)$b['profit_contribution']) <=> ((int)$a['profit_contribution']);
  if ($profitCmp !== 0) return $profitCmp;
  $salesCmp = ((int)$b['sales_total']) <=> ((int)$a['sales_total']);
  if ($salesCmp !== 0) return $salesCmp;
  return strcmp((string)$a['display_name'], (string)$b['display_name']);
});

foreach ($rows as $row) {
  $summary['cast_count']++;
  $summary['sales_total'] += (int)$row['sales_total'];
  $summary['ticket_count'] += (int)$row['ticket_count'];
  $summary['worked_minutes'] += (int)$row['worked_minutes'];
  $summary['attendance_days'] += (int)$row['attendance_days'];
  $summary['shimei_total'] += (float)$row['shimei_point'];
  $summary['douhan_total'] += (float)$row['douhan_count'];
  $summary['beverage_cost_total'] += (int)$row['beverage_cost_total'];
  $summary['labor_cost_total'] += (int)$row['labor_cost'];
  $summary['fixed_cost_total'] += (int)$row['allocated_fixed_cost'];
  $summary['profit_contribution_total'] += (int)$row['profit_contribution'];
}

$rankedRows = $rows;
usort($rankedRows, static function(array $a, array $b) use ($rankingBy): int {
  $aValue = $a[$rankingBy] ?? 0;
  $bValue = $b[$rankingBy] ?? 0;
  $cmp = is_float($aValue) || is_float($bValue)
    ? ((float)$bValue <=> (float)$aValue)
    : ((int)$bValue <=> (int)$aValue);
  if ($cmp !== 0) return $cmp;
  return strcmp((string)$a['display_name'], (string)$b['display_name']);
});
$rankingRows = array_slice($rankedRows, 0, 5);

render_page_start('全店キャストKPI');
render_header('全店キャストKPI', [
  'back_href' => '/wbss/public/all_store_status.php',
  'back_label' => '← 全店統合ビュー',
  'right_html' => '<a class="btn" href="/wbss/public/all_store_status.php">全店統合ビュー</a>',
]);
?>
<div class="page"><div class="admin-wrap cast-kpi-shell">
  <section class="hero">
    <div>
      <div class="eyebrow">Cast KPI</div>
      <h1>全店のキャストKPIを一気に比較</h1>
      <p class="muted">勤務実績は `attendances`、売上寄与は伝票の `totals_snapshot` から集計しています。原価・時給・固定費の項目が未作成の環境では、その項目は 0 円として利益寄与を算出します。</p>
    </div>
    <form method="get" class="filters">
      <label>
        <span>店舗</span>
        <select class="sel" name="store_id">
          <?php foreach ($storeOptions as $storeId => $storeLabel): ?>
            <option value="<?= (int)$storeId ?>" <?= $selectedStoreId === (int)$storeId ? 'selected' : '' ?>><?= h($storeLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>
        <span>開始日</span>
        <input class="sel" type="date" name="from_date" value="<?= h($fromDate) ?>">
      </label>
      <label>
        <span>終了日</span>
        <input class="sel" type="date" name="to_date" value="<?= h($toDate) ?>">
      </label>
      <label>
        <span>ランキング</span>
        <select class="sel" name="ranking_by">
          <option value="profit_contribution" <?= $rankingBy === 'profit_contribution' ? 'selected' : '' ?>>利益寄与</option>
          <option value="sales_total" <?= $rankingBy === 'sales_total' ? 'selected' : '' ?>>売上寄与</option>
          <option value="sales_per_hour" <?= $rankingBy === 'sales_per_hour' ? 'selected' : '' ?>>売上/時</option>
          <option value="ticket_count" <?= $rankingBy === 'ticket_count' ? 'selected' : '' ?>>担当伝票</option>
          <option value="shimei_point" <?= $rankingBy === 'shimei_point' ? 'selected' : '' ?>>指名pt</option>
        </select>
      </label>
      <button class="btn">更新</button>
    </form>
  </section>

  <section class="kpi-grid">
    <div class="kpi-card"><div class="kpi-label">対象キャスト</div><div class="kpi-value"><?= number_format((int)$summary['cast_count']) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">売上寄与</div><div class="kpi-value">¥<?= number_format((int)$summary['sales_total']) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">飲料原価</div><div class="kpi-value">¥<?= number_format((int)$summary['beverage_cost_total']) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">人件費</div><div class="kpi-value">¥<?= number_format((int)$summary['labor_cost_total']) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">固定費按分</div><div class="kpi-value">¥<?= number_format((int)$summary['fixed_cost_total']) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">利益寄与</div><div class="kpi-value">¥<?= number_format((int)$summary['profit_contribution_total']) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">勤務時間</div><div class="kpi-value"><?= h(cast_kpi_minutes_label((int)$summary['worked_minutes'])) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">担当伝票</div><div class="kpi-value"><?= number_format((int)$summary['ticket_count']) ?></div></div>
    <div class="kpi-card"><div class="kpi-label">指名pt / 同伴</div><div class="kpi-value"><?= h(fmt_point((float)$summary['shimei_total'])) ?> / <?= h(fmt_point((float)$summary['douhan_total'])) ?></div></div>
  </section>

  <section class="ranking-grid">
    <?php foreach ($rankingRows as $index => $row): ?>
      <article class="rank-card">
        <div class="rank-no">#<?= $index + 1 ?></div>
        <div class="rank-store"><?= h((string)$row['store_name']) ?></div>
        <div class="rank-name"><?= h((string)$row['display_name']) ?><?= trim((string)$row['shop_tag']) !== '' ? ' / ' . h((string)$row['shop_tag']) : '' ?></div>
        <div class="rank-value"><?= h(cast_kpi_rank_value_text($rankingBy, $row)) ?></div>
        <div class="muted"><?= h(cast_kpi_rank_label($rankingBy)) ?> / 売上 ¥<?= number_format((int)$row['sales_total']) ?> / 利益寄与 ¥<?= number_format((int)$row['profit_contribution']) ?></div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="card">
    <div class="card-head">
      <div>
        <div class="card-title">キャスト別一覧</div>
        <div class="muted"><?= h($fromDate) ?> から <?= h($toDate) ?> まで / <?= h($storeOptions[$selectedStoreId] ?? '全店舗') ?></div>
      </div>
    </div>

    <div class="table-wrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>店舗</th>
            <th>店番</th>
            <th>名前</th>
            <th>利益寄与</th>
            <th>売上寄与</th>
            <th>飲料原価</th>
            <th>人件費</th>
            <th>固定費按分</th>
            <th>担当伝票</th>
            <th>出勤日数</th>
            <th>勤務時間</th>
            <th>売上/出勤</th>
            <th>売上/時</th>
            <th>指名pt</th>
            <th>同伴</th>
            <th>ドリンク</th>
            <th>ボトル</th>
            <th>遅刻</th>
            <th>欠勤</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= h((string)$row['store_name']) ?></td>
              <td><?= h((string)($row['shop_tag'] !== '' ? $row['shop_tag'] : '—')) ?></td>
              <td><?= h((string)$row['display_name']) ?></td>
              <td class="num strong<?= (int)$row['profit_contribution'] < 0 ? ' neg' : '' ?>">¥<?= number_format((int)$row['profit_contribution']) ?></td>
              <td class="num strong">¥<?= number_format((int)$row['sales_total']) ?></td>
              <td class="num">¥<?= number_format((int)$row['beverage_cost_total']) ?></td>
              <td class="num">¥<?= number_format((int)$row['labor_cost']) ?></td>
              <td class="num">¥<?= number_format((int)$row['allocated_fixed_cost']) ?></td>
              <td class="num"><?= number_format((int)$row['ticket_count']) ?></td>
              <td class="num"><?= number_format((int)$row['attendance_days']) ?></td>
              <td class="mono"><?= h(cast_kpi_minutes_label((int)$row['worked_minutes'])) ?></td>
              <td class="num">¥<?= number_format((int)$row['sales_per_day']) ?></td>
              <td class="num">¥<?= number_format((int)$row['sales_per_hour']) ?></td>
              <td class="num"><?= h(fmt_point((float)$row['shimei_point'])) ?></td>
              <td class="num"><?= h(fmt_point((float)$row['douhan_count'])) ?></td>
              <td class="num">¥<?= number_format((int)$row['drink_total']) ?></td>
              <td class="num">¥<?= number_format((int)$row['bottle_total']) ?></td>
              <td class="num"><?= number_format((int)$row['late_count']) ?></td>
              <td class="num"><?= number_format((int)$row['absent_days']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </section>
</div></div>

<style>
.cast-kpi-shell{display:grid;gap:16px}
.hero,.card,.kpi-card,.rank-card{border:1px solid var(--line);background:var(--cardA);border-radius:18px;box-shadow:var(--shadow)}
.hero{padding:18px;display:grid;gap:14px}
.eyebrow{font-size:12px;font-weight:900;color:var(--muted);letter-spacing:.08em;text-transform:uppercase}
.hero h1{margin:6px 0 8px;font-size:26px;line-height:1.2}
.muted{color:var(--muted);font-size:12px}
.filters{display:flex;gap:10px;flex-wrap:wrap;align-items:end}
.filters label{display:grid;gap:6px;font-size:12px;color:var(--muted)}
.sel{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.kpi-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}
.kpi-card{padding:14px}
.kpi-label{font-size:12px;color:var(--muted)}
.kpi-value{margin-top:6px;font-size:22px;font-weight:1000}
.ranking-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.rank-card{padding:14px;display:grid;gap:6px}
.rank-no{font-size:12px;font-weight:900;color:var(--muted)}
.rank-store{font-size:12px;color:var(--muted)}
.rank-name{font-size:16px;font-weight:900}
.rank-value{font-size:24px;font-weight:1000}
.card{padding:14px}
.card-title{font-size:16px;font-weight:900}
.table-wrap{margin-top:12px;overflow:auto;border:1px solid rgba(255,255,255,.10);border-radius:12px}
.tbl{width:100%;border-collapse:collapse;min-width:1600px}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:middle;white-space:nowrap}
.tbl thead th{position:sticky;top:0;background:var(--cardA);z-index:1}
.num{text-align:right}
.mono{font-variant-numeric:tabular-nums}
.strong{font-weight:900}
td.neg{color:#ef4444}
@media (max-width: 1100px){.kpi-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media (max-width: 640px){.kpi-grid{grid-template-columns:1fr}.filters{display:grid}}
</style>
<?php render_page_end(); ?>
