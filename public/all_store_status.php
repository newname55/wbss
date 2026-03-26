<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/attendance.php';
require_once __DIR__ . '/../app/bootstrap.php';

require_login();
require_role(['admin', 'super_user']);

const ALL_STORE_HIGHLIGHT_SALES_YEN = 300000;

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function read_total_yen_from_snapshot(?string $json): int {
  $json = (string)$json;
  if ($json === '') return 0;

  $data = json_decode($json, true);
  if (!is_array($data)) return 0;

  if (isset($data['bill']) && is_array($data['bill']) && isset($data['bill']['total'])) {
    return (int)$data['bill']['total'];
  }
  if (isset($data['totals']) && is_array($data['totals']) && isset($data['totals']['total'])) {
    return (int)$data['totals']['total'];
  }
  if (isset($data['total'])) {
    return (int)$data['total'];
  }

  return 0;
}

function format_hm(?string $value): string {
  $value = trim((string)$value);
  if ($value === '') return '--:--';
  if (strlen($value) >= 16) return substr($value, 11, 5);
  if (strlen($value) >= 5) return substr($value, 0, 5);
  return $value;
}

function all_store_cards_attendance(PDO $pdo, int $storeId, string $bizDate): array {
  $rows = att_get_daily_rows($pdo, $storeId, $bizDate);

  $planStmt = $pdo->prepare("
    SELECT user_id, start_time
    FROM cast_shift_plans
    WHERE store_id = ?
      AND business_date = ?
      AND status = 'planned'
      AND is_off = 0
  ");
  $planStmt->execute([$storeId, $bizDate]);
  $planRows = $planStmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $plannedMap = [];
  foreach ($planRows as $plan) {
    $userId = (int)($plan['user_id'] ?? 0);
    if ($userId <= 0) continue;
    $plannedMap[$userId] = substr((string)($plan['start_time'] ?? ''), 0, 5);
  }

  $working = [];
  $finished = [];
  $waiting = [];
  $late = [];
  $presentCount = 0;

  $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
  foreach ($rows as $row) {
    $castId = (int)($row['cast_id'] ?? 0);
    $name = trim((string)($row['name'] ?? ''));
    $tag = trim((string)($row['shop_tag'] ?? ''));
    $inAt = (string)($row['in_at'] ?? '');
    $outAt = (string)($row['out_at'] ?? '');
    $planStart = (string)($plannedMap[$castId] ?? '');

    $label = $name !== '' ? $name : ('#' . $castId);
    if ($tag !== '') {
      $label = $tag . ' ' . $label;
    }

    if ($inAt !== '' || $outAt !== '') {
      $presentCount++;
    }

    if ($outAt !== '') {
      $finished[] = $label . ' ' . format_hm($outAt);
      continue;
    }

    if ($inAt !== '') {
      $working[] = $label . ' ' . format_hm($inAt);
      continue;
    }

    if ($planStart !== '') {
      $waiting[] = $label . ' ' . $planStart;

      $planAt = DateTimeImmutable::createFromFormat(
        'Y-m-d H:i',
        $bizDate . ' ' . $planStart,
        new DateTimeZone('Asia/Tokyo')
      );
      if ($planAt instanceof DateTimeImmutable && $now > $planAt) {
        $late[] = $label . ' ' . $planStart;
      }
    }
  }

  return [
    'active_casts' => count($rows),
    'planned' => count($plannedMap),
    'present' => $presentCount,
    'working' => count($working),
    'finished' => count($finished),
    'waiting' => count($waiting),
    'late' => count($late),
    'working_names' => array_slice($working, 0, 6),
    'late_names' => array_slice($late, 0, 6),
    'waiting_names' => array_slice($waiting, 0, 6),
  ];
}

function all_store_cards_sales(PDO $pdo, int $storeId, string $bizDate): array {
  $stmt = $pdo->prepare("
    SELECT id, status, totals_snapshot
    FROM tickets
    WHERE store_id = ?
      AND business_date = ?
      AND status <> 'void'
    ORDER BY id ASC
  ");
  $stmt->execute([$storeId, $bizDate]);
  $tickets = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $salesTotal = 0;
  $paidSales = 0;
  $openTickets = 0;
  $paidTickets = 0;

  foreach ($tickets as $ticket) {
    $total = read_total_yen_from_snapshot((string)($ticket['totals_snapshot'] ?? ''));
    $status = (string)($ticket['status'] ?? '');
    $salesTotal += $total;

    if ($status === 'paid') {
      $paidTickets++;
      $paidSales += $total;
    } else {
      $openTickets++;
    }
  }

  return [
    'sales_total' => $salesTotal,
    'paid_sales' => $paidSales,
    'ticket_count' => count($tickets),
    'paid_tickets' => $paidTickets,
    'open_tickets' => $openTickets,
  ];
}

$pdo = db();
$stores = $pdo->query("
  SELECT id, name, business_day_start
  FROM stores
  WHERE is_active = 1
  ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$cards = [];
$summary = [
  'store_count' => 0,
  'sales_total' => 0,
  'ticket_count' => 0,
  'paid_sales' => 0,
  'working' => 0,
  'finished' => 0,
  'waiting' => 0,
  'late' => 0,
  'planned' => 0,
  'present' => 0,
];

foreach ($stores as $store) {
  $storeId = (int)($store['id'] ?? 0);
  if ($storeId <= 0) continue;

  $bizDate = business_date_for_store($store, null);
  $sales = all_store_cards_sales($pdo, $storeId, $bizDate);
  $attendance = all_store_cards_attendance($pdo, $storeId, $bizDate);

  $cards[] = [
    'id' => $storeId,
    'name' => (string)($store['name'] ?? ('#' . $storeId)),
    'biz_date' => $bizDate,
    'sales' => $sales,
    'attendance' => $attendance,
    'is_hot' => ((int)$sales['sales_total'] >= ALL_STORE_HIGHLIGHT_SALES_YEN),
  ];

  $summary['store_count']++;
  $summary['sales_total'] += $sales['sales_total'];
  $summary['ticket_count'] += $sales['ticket_count'];
  $summary['paid_sales'] += $sales['paid_sales'];
  $summary['working'] += $attendance['working'];
  $summary['finished'] += $attendance['finished'];
  $summary['waiting'] += $attendance['waiting'];
  $summary['late'] += $attendance['late'];
  $summary['planned'] += $attendance['planned'];
  $summary['present'] += $attendance['present'];
}

usort($cards, static function(array $a, array $b): int {
  return ((int)$b['sales']['sales_total']) <=> ((int)$a['sales']['sales_total']);
});

render_page_start('全店統合ビュー');
render_header('全店統合ビュー', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '<a class="btn" href="/wbss/public/all_store_shift_plans.php">全店出勤予定</a> <a class="btn" href="/wbss/public/all_cast_kpi.php">全店キャストKPI</a> <a class="btn" href="/wbss/public/dashboard.php">ダッシュボード</a>',
]);
?>
<style>
.overview-shell{ display:grid; gap:16px; }
.overview-hero,
.overview-card{
  border:1px solid var(--line);
  background:var(--cardA);
  border-radius:18px;
  box-shadow:var(--shadow);
}
.overview-hero{
  padding:18px;
  display:grid;
  gap:14px;
}
.overview-title{
  display:flex;
  gap:12px;
  align-items:flex-start;
  justify-content:space-between;
  flex-wrap:wrap;
}
.overview-title h1{
  margin:0;
  font-size:24px;
  line-height:1.2;
}
.overview-lead{
  margin:6px 0 0;
  color:var(--muted);
}
.overview-kpis{
  display:grid;
  grid-template-columns:repeat(5, minmax(120px, 1fr));
  gap:10px;
}
.overview-kpi,
.mini-kpi{
  border:1px solid var(--line);
  background:var(--cardB);
  border-radius:16px;
  padding:12px 14px;
}
.overview-kpi .label,
.mini-kpi .label{
  font-size:12px;
  color:var(--muted);
}
.overview-kpi .value,
.mini-kpi .value{
  margin-top:4px;
  font-size:24px;
  font-weight:900;
}
.overview-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit, minmax(320px, 1fr));
  gap:14px;
}
.overview-card{
  padding:16px;
  display:grid;
  gap:14px;
}
.overview-card.is-hot{
  border-color:rgba(245,158,11,.55);
  background:
    linear-gradient(180deg, rgba(245,158,11,.10), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 18px 36px rgba(245,158,11,.14);
}
.card-head{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
}
.card-head-main{
  display:grid;
  gap:8px;
}
.card-head h2{
  margin:0;
  font-size:20px;
}
.biz-date{
  font-size:12px;
  color:var(--muted);
}
.top-badges{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
}
.card-actions{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  justify-content:flex-end;
}
.top-link{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:38px;
  padding:0 12px;
  border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardB);
  color:var(--txt);
  font-size:12px;
  font-weight:800;
  text-decoration:none;
}
.top-link:hover{
  border-color:rgba(59,130,246,.35);
  background:rgba(59,130,246,.10);
}
.top-link.strong{
  border-color:rgba(59,130,246,.35);
  background:rgba(59,130,246,.12);
}
.mini-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:10px;
}
.status-row{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.pill{
  display:inline-flex;
  gap:6px;
  align-items:center;
  border-radius:999px;
  padding:6px 10px;
  border:1px solid var(--line);
  background:var(--cardB);
  font-size:12px;
}
.pill.warn{
  border-color:rgba(245,158,11,.35);
  background:rgba(245,158,11,.14);
}
.pill.good{
  border-color:rgba(34,197,94,.35);
  background:rgba(34,197,94,.14);
}
.pill.hot{
  border-color:rgba(245,158,11,.45);
  background:rgba(245,158,11,.18);
  color:inherit;
}
.name-block{
  display:grid;
  gap:6px;
}
.name-block h3{
  margin:0;
  font-size:13px;
}
.name-list{
  margin:0;
  padding-left:18px;
  color:var(--muted);
  font-size:13px;
}
.name-list li + li{ margin-top:4px; }
.muted{
  color:var(--muted);
  font-size:12px;
}
@media (max-width: 900px){
  .overview-kpis{ grid-template-columns:repeat(2, minmax(120px, 1fr)); }
}
@media (max-width: 640px){
  .card-actions{
    justify-content:flex-start;
  }
  .mini-grid{
    grid-template-columns:1fr;
  }
}
</style>

<div class="page">
  <div class="admin-wrap overview-shell">
    <section class="overview-hero">
      <div class="overview-title">
        <div>
          <h1>全店舗の売上と出勤状況をひと目で確認</h1>
          <p class="overview-lead">売上は各店舗の当日 `tickets.totals_snapshot`、出勤は当日 `attendances` と `cast_shift_plans` をもとに集計しています。</p>
        </div>
        <div class="muted">対象 <?= number_format((int)$summary['store_count']) ?> 店舗</div>
      </div>

      <div class="overview-kpis">
        <div class="overview-kpi">
          <div class="label">全店売上</div>
          <div class="value">¥<?= number_format((int)$summary['sales_total']) ?></div>
        </div>
        <div class="overview-kpi">
          <div class="label">伝票件数</div>
          <div class="value"><?= number_format((int)$summary['ticket_count']) ?></div>
        </div>
        <div class="overview-kpi">
          <div class="label">出勤中</div>
          <div class="value"><?= number_format((int)$summary['working']) ?></div>
        </div>
        <div class="overview-kpi">
          <div class="label">退勤済</div>
          <div class="value"><?= number_format((int)$summary['finished']) ?></div>
        </div>
        <div class="overview-kpi">
          <div class="label">未打刻 / 遅刻目安</div>
          <div class="value"><?= number_format((int)$summary['waiting']) ?> / <?= number_format((int)$summary['late']) ?></div>
        </div>
      </div>
    </section>

    <section class="overview-grid">
      <?php foreach ($cards as $card): ?>
        <article class="overview-card<?= !empty($card['is_hot']) ? ' is-hot' : '' ?>">
          <div class="card-head">
            <div class="card-head-main">
              <div class="top-badges">
                <?php if (!empty($card['is_hot'])): ?>
                  <span class="pill hot">売上好調 ¥<?= number_format((int)$card['sales']['sales_total']) ?></span>
                <?php endif; ?>
                <?php if ((int)$card['attendance']['late'] > 0): ?>
                  <span class="pill warn">遅刻目安 <?= number_format((int)$card['attendance']['late']) ?></span>
                <?php endif; ?>
              </div>
              <h2><?= h((string)$card['name']) ?></h2>
              <div class="biz-date">営業日 <?= h((string)$card['biz_date']) ?> / store #<?= (int)$card['id'] ?></div>
            </div>
            <div class="card-actions">
              <a class="top-link strong" href="/wbss/public/attendance/index.php?store_id=<?= (int)$card['id'] ?>&date=<?= h((string)$card['biz_date']) ?>">出勤一覧へ</a>
              <a class="top-link strong" href="/wbss/public/cashier/index.php?store_id=<?= (int)$card['id'] ?>&business_date=<?= h((string)$card['biz_date']) ?>">会計へ</a>
              <a class="top-link" href="/wbss/public/dashboard.php?store_id=<?= (int)$card['id'] ?>">この店舗へ</a>
            </div>
          </div>

          <div class="mini-grid">
            <div class="mini-kpi">
              <div class="label">今の売上</div>
              <div class="value">¥<?= number_format((int)$card['sales']['sales_total']) ?></div>
              <div class="muted">会計済 ¥<?= number_format((int)$card['sales']['paid_sales']) ?></div>
            </div>
            <div class="mini-kpi">
              <div class="label">伝票</div>
              <div class="value"><?= number_format((int)$card['sales']['ticket_count']) ?></div>
              <div class="muted">未会計 <?= number_format((int)$card['sales']['open_tickets']) ?> / 会計済 <?= number_format((int)$card['sales']['paid_tickets']) ?></div>
            </div>
          </div>

          <div class="status-row">
            <span class="pill good">出勤中 <?= number_format((int)$card['attendance']['working']) ?></span>
            <span class="pill">退勤済 <?= number_format((int)$card['attendance']['finished']) ?></span>
            <span class="pill">予定 <?= number_format((int)$card['attendance']['planned']) ?></span>
            <span class="pill">出勤実績 <?= number_format((int)$card['attendance']['present']) ?></span>
            <span class="pill<?= (int)$card['attendance']['late'] > 0 ? ' warn' : '' ?>">遅刻目安 <?= number_format((int)$card['attendance']['late']) ?></span>
          </div>

          <div class="mini-grid">
            <div class="name-block">
              <h3>出勤中</h3>
              <?php if (!empty($card['attendance']['working_names'])): ?>
                <ul class="name-list">
                  <?php foreach ($card['attendance']['working_names'] as $name): ?>
                    <li><?= h((string)$name) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="muted">現在出勤中のキャストはいません。</div>
              <?php endif; ?>
            </div>

            <div class="name-block">
              <h3>未打刻 / 遅刻目安</h3>
              <?php if (!empty($card['attendance']['late_names'])): ?>
                <ul class="name-list">
                  <?php foreach ($card['attendance']['late_names'] as $name): ?>
                    <li><?= h((string)$name) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php elseif (!empty($card['attendance']['waiting_names'])): ?>
                <ul class="name-list">
                  <?php foreach ($card['attendance']['waiting_names'] as $name): ?>
                    <li><?= h((string)$name) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <div class="muted">未打刻の予定者はいません。</div>
              <?php endif; ?>
            </div>
          </div>
        </article>
      <?php endforeach; ?>
    </section>
  </div>
</div>
<?php render_page_end(); ?>
