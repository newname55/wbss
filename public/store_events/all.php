<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/repo_casts.php';
require_once __DIR__ . '/../../app/store.php';

require_login();
require_role(['manager', 'admin', 'super_user']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function has_dashboard_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

function hm_from_time_value(?string $value, string $fallback): string {
  $value = trim((string)$value);
  if ($value === '') return $fallback;
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) return substr($value, 0, 5);
  if (preg_match('/^\d{2}:\d{2}$/', $value)) return $value;
  return $fallback;
}

function business_date_from_datetime(DateTimeImmutable $dt, string $businessDayStartHm): string {
  [$hour, $minute] = array_map('intval', explode(':', $businessDayStartHm));
  $cutoff = $dt->setTime($hour, $minute, 0);
  if ($dt < $cutoff) {
    return $dt->modify('-1 day')->format('Y-m-d');
  }
  return $dt->format('Y-m-d');
}

function is_linked_external_event(array $row): bool {
  $title = trim((string)($row['title'] ?? ''));
  $memo = trim((string)($row['memo'] ?? ''));
  return str_starts_with($title, '【連動】') || str_contains($memo, '外部イベント連動:');
}

function internal_event_type(array $row): string {
  $memo = (string)($row['memo'] ?? '');
  if (preg_match('/\[\[event_type:(service_companion|forced_companion)\]\]/u', $memo, $m)) {
    return (string)$m[1];
  }
  return '';
}

function internal_event_type_label(string $type): string {
  return match ($type) {
    'service_companion' => 'サービス同伴',
    'forced_companion' => '強制同伴',
    default => '',
  };
}

function internal_event_type_class(string $type): string {
  return match ($type) {
    'service_companion' => 'is-service',
    'forced_companion' => 'is-forced',
    default => '',
  };
}

function store_event_status_label(string $status): string {
  $map = [
    'draft' => '下書き',
    'scheduled' => '予定',
    'confirmed' => '確定',
    'published' => '公開',
    'cancelled' => '中止',
    'canceled' => '中止',
    'done' => '完了',
  ];

  $key = strtolower(trim($status));
  return $map[$key] ?? ($status !== '' ? $status : '未設定');
}

function store_event_status_class(string $status): string {
  $key = strtolower(trim($status));
  return match ($key) {
    'confirmed', 'published' => 'is-confirmed',
    'cancelled', 'canceled' => 'is-cancelled',
    'done' => 'is-done',
    default => 'is-draft',
  };
}

function display_internal_event_title(array $row): string {
  $title = trim((string)($row['title'] ?? ''));
  if (is_linked_external_event($row) && str_starts_with($title, '【連動】')) {
    $title = trim(mb_substr($title, 4));
  }
  return $title;
}

function display_internal_event_body_title(array $row): string {
  if (internal_event_type($row) !== '') {
    return '';
  }
  return display_internal_event_title($row);
}

function format_event_period(?string $startAt, ?string $endAt): string {
  $sa = trim((string)$startAt);
  $ea = trim((string)$endAt);
  if ($sa === '' && $ea === '') return '日時未設定';
  if ($sa === '') return substr($ea, 0, 16);
  if ($ea === '' || $ea === $sa) return substr($sa, 0, 16);

  $startDate = substr($sa, 0, 10);
  $endDate = substr($ea, 0, 10);
  if ($startDate === $endDate) {
    return substr($sa, 0, 16) . ' - ' . substr($ea, 11, 5);
  }

  return substr($sa, 0, 16) . ' - ' . substr($ea, 0, 16);
}

function compare_all_store_events(array $a, array $b): int {
  $aStore = (int)($a['store_sort'] ?? 0);
  $bStore = (int)($b['store_sort'] ?? 0);
  if ($aStore !== $bStore) {
    return $aStore <=> $bStore;
  }

  $aStart = (string)($a['starts_at'] ?? '');
  $bStart = (string)($b['starts_at'] ?? '');
  if ($aStart !== $bStart) {
    return strcmp($aStart, $bStart);
  }

  return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
}

function store_palette(): array {
  return [
    '#1d9bf0',
    '#f97316',
    '#8b5cf6',
    '#10b981',
    '#ef4444',
    '#06b6d4',
    '#f59e0b',
    '#ec4899',
  ];
}

function hex_to_rgba(string $hex, float $alpha): string {
  $hex = ltrim(trim($hex), '#');
  if (strlen($hex) === 3) {
    $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
  }
  if (!preg_match('/^[0-9a-fA-F]{6}$/', $hex)) {
    return 'rgba(29,155,240,' . $alpha . ')';
  }
  $r = hexdec(substr($hex, 0, 2));
  $g = hexdec(substr($hex, 2, 2));
  $b = hexdec(substr($hex, 4, 2));
  return sprintf('rgba(%d,%d,%d,%.3f)', $r, $g, $b, $alpha);
}

function short_store_name(string $name): string {
  $name = trim($name);
  if ($name === '') return '店舗';
  if (mb_strlen($name) <= 6) return $name;
  return mb_substr($name, 0, 6);
}

function build_all_store_events_url(array $overrides = []): string {
  $params = array_merge($_GET, $overrides);
  foreach ($params as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    }
  }
  return './all.php' . ($params ? ('?' . http_build_query($params)) : '');
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
$isSuper = has_dashboard_role('super_user');
$isAdmin = has_dashboard_role('admin');
$isManager = has_dashboard_role('manager');

$allowedStores = [];
if ($isSuper || $isAdmin) {
  $stStores = $pdo->query("
    SELECT id, name, business_day_start
      FROM stores
     WHERE is_active = 1
     ORDER BY id ASC
  ");
  $allowedStores = $stStores->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($isManager) {
  $baseStores = repo_allowed_stores($pdo, $userId, false);
  $ids = array_values(array_filter(array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $baseStores)));
  if ($ids) {
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stStores = $pdo->prepare("
      SELECT id, name, business_day_start
        FROM stores
       WHERE is_active = 1
         AND id IN ({$ph})
       ORDER BY id ASC
    ");
    $stStores->execute($ids);
    $allowedStores = $stStores->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!$allowedStores) {
  render_page_start('全店イベントカレンダー');
  render_header('全店イベントカレンダー', ['back_href' => '/wbss/public/dashboard.php']);
  ?>
  <div class="page">
    <div class="admin-wrap" style="max-width:980px;margin:0 auto;padding:24px 16px 40px;">
      <div style="padding:28px;border:1px solid var(--line);border-radius:22px;background:var(--cardA);box-shadow:0 18px 40px rgba(0,0,0,.12);">
        表示できる店舗がありません。権限設定を確認してください。
      </div>
    </div>
  </div>
  <?php
  render_page_end();
  exit;
}

$storeMeta = [];
$storeIds = [];
$palette = store_palette();
foreach (array_values($allowedStores) as $index => $store) {
  $sid = (int)$store['id'];
  $accent = $palette[$index % count($palette)];
  $storeIds[] = $sid;
  $storeMeta[$sid] = [
    'id' => $sid,
    'name' => (string)$store['name'],
    'short_name' => short_store_name((string)$store['name']),
    'store_sort' => $index,
    'business_day_start' => hm_from_time_value((string)($store['business_day_start'] ?? ''), '06:00'),
    'accent' => $accent,
    'accent_soft' => hex_to_rgba($accent, 0.15),
    'accent_soft_strong' => hex_to_rgba($accent, 0.22),
  ];
}

$scope = (string)($_GET['scope'] ?? 'all');
$scope = $scope === 'store' ? 'store' : 'all';
$requestedStoreId = (int)($_GET['store_id'] ?? 0);
$currentContextStoreId = (int)current_store_id();
$selectedStoreId = isset($storeMeta[$requestedStoreId]) ? $requestedStoreId : 0;
if ($selectedStoreId <= 0 && isset($storeMeta[$currentContextStoreId])) {
  $selectedStoreId = $currentContextStoreId;
}
if ($selectedStoreId <= 0) {
  $selectedStoreId = $storeIds[0];
}
set_current_store_id($selectedStoreId);

$month = trim((string)($_GET['month'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $month)) {
  $month = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
}

$monthStart = new DateTimeImmutable($month . '-01 00:00:00', new DateTimeZone('Asia/Tokyo'));
$gridStart = $monthStart->modify('-' . (int)$monthStart->format('w') . ' days');
$gridEnd = $gridStart->modify('+41 days');
$monthEnd = $monthStart->modify('last day of this month 23:59:59');
$prevMonth = $monthStart->modify('-1 month')->format('Y-m');
$nextMonth = $monthStart->modify('+1 month')->format('Y-m');
$todayYmd = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');

$selectedDate = trim((string)($_GET['selected_date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $selectedDate)) {
  $selectedDate = $todayYmd;
}
if (substr($selectedDate, 0, 7) !== $month) {
  $selectedDate = $monthStart->format('Y-m-01');
}
$selectedDateObj = new DateTimeImmutable($selectedDate . ' 00:00:00', new DateTimeZone('Asia/Tokyo'));

$eventsByDate = [];
$selectedDateRows = [];
$upcomingRows = [];
$monthCountTotal = 0;
$monthCountByStore = array_fill_keys($storeIds, 0);

$ph = implode(',', array_fill(0, count($storeIds), '?'));
$stEvents = $pdo->prepare("
  SELECT id, store_id, title, status, starts_at, ends_at, budget_yen, memo
    FROM store_event_instances
   WHERE store_id IN ({$ph})
     AND COALESCE(ends_at, starts_at) >= ?
     AND starts_at <= ?
   ORDER BY starts_at ASC, id ASC
");
$stEvents->execute(array_merge(
  $storeIds,
  [
    $gridStart->format('Y-m-d 00:00:00'),
    $gridEnd->format('Y-m-d 23:59:59'),
  ]
));
$rows = $stEvents->fetchAll(PDO::FETCH_ASSOC) ?: [];

foreach ($rows as $row) {
  $storeId = (int)($row['store_id'] ?? 0);
  if (!isset($storeMeta[$storeId])) {
    continue;
  }
  if (is_linked_external_event($row)) {
    continue;
  }

  $startRaw = trim((string)($row['starts_at'] ?? ''));
  $endRaw = trim((string)($row['ends_at'] ?? ''));
  if ($startRaw === '') {
    continue;
  }

  $start = new DateTimeImmutable($startRaw, new DateTimeZone('Asia/Tokyo'));
  $end = $endRaw !== '' ? new DateTimeImmutable($endRaw, new DateTimeZone('Asia/Tokyo')) : $start;
  if ($end < $start) {
    $end = $start;
  }

  $normalized = $row;
  $normalized['store_name'] = $storeMeta[$storeId]['name'];
  $normalized['store_short_name'] = $storeMeta[$storeId]['short_name'];
  $normalized['store_sort'] = $storeMeta[$storeId]['store_sort'];
  $normalized['store_accent'] = $storeMeta[$storeId]['accent'];
  $normalized['store_accent_soft'] = $storeMeta[$storeId]['accent_soft'];
  $normalized['store_accent_soft_strong'] = $storeMeta[$storeId]['accent_soft_strong'];
  $normalized['event_type'] = internal_event_type($row);
  $normalized['display_title'] = display_internal_event_body_title($row);

  if ($start <= $monthEnd && $end >= $monthStart) {
    $monthCountTotal++;
    $monthCountByStore[$storeId] = ($monthCountByStore[$storeId] ?? 0) + 1;
  }

  $bizStartHm = $storeMeta[$storeId]['business_day_start'];
  $startBizDate = business_date_from_datetime($start, $bizStartHm);
  $endBizBase = $end > $start ? $end->modify('-1 second') : $end;
  $endBizDate = business_date_from_datetime($endBizBase, $bizStartHm);

  $cursor = new DateTimeImmutable($startBizDate . ' 00:00:00', new DateTimeZone('Asia/Tokyo'));
  $lastDay = new DateTimeImmutable($endBizDate . ' 00:00:00', new DateTimeZone('Asia/Tokyo'));
  while ($cursor <= $lastDay) {
    $eventsByDate[$cursor->format('Y-m-d')][] = $normalized;
    $cursor = $cursor->modify('+1 day');
  }

  if ($start >= $monthStart) {
    $upcomingRows[] = $normalized;
  }
}

foreach ($eventsByDate as &$dayRows) {
  usort($dayRows, 'compare_all_store_events');
}
unset($dayRows);
usort($upcomingRows, 'compare_all_store_events');

$selectedDateRows = $eventsByDate[$selectedDate] ?? [];
if ($scope === 'store') {
  $selectedDateRows = array_values(array_filter(
    $selectedDateRows,
    static fn(array $row): bool => (int)($row['store_id'] ?? 0) === $selectedStoreId
  ));
  $upcomingRows = array_values(array_filter(
    $upcomingRows,
    static fn(array $row): bool => (int)($row['store_id'] ?? 0) === $selectedStoreId
  ));
}
$upcomingRows = array_slice($upcomingRows, 0, 8);

$activeStoreName = $storeMeta[$selectedStoreId]['name'] ?? '';
$pageTitle = '全店イベントカレンダー';
render_page_start($pageTitle);
render_header($pageTitle, [
  'back_href' => '/wbss/public/dashboard.php?store_id=' . $selectedStoreId,
]);
?>
<style>
  .allStoreEventsPage{
    max-width:1420px;
    margin:0 auto;
    padding:20px 16px 44px;
    display:grid;
    gap:16px;
  }
  .allStoreEventsHero,
  .allStoreEventsCard,
  .allStoreEventsPanel{
    border:1px solid rgba(255,255,255,.12);
    border-radius:24px;
    background:linear-gradient(180deg, rgba(9,14,27,.96), rgba(13,19,37,.88));
    box-shadow:0 22px 52px rgba(0,0,0,.18);
  }
  .allStoreEventsHero{
    padding:22px 24px;
    display:flex;
    flex-wrap:wrap;
    justify-content:space-between;
    gap:18px;
    align-items:flex-end;
  }
  .allStoreEventsHero h1{
    margin:0;
    font-size:34px;
    line-height:1;
    letter-spacing:.02em;
  }
  .allStoreEventsLead{
    margin-top:10px;
    max-width:780px;
    color:#96a4c5;
    font-size:14px;
    line-height:1.65;
  }
  .allStoreEventsMeta,
  .allStoreEventsLegend,
  .allStoreEventsTabs,
  .allStoreEventsMonthNav,
  .allStoreEventsActions,
  .allStoreEventsListMeta{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
  }
  .allStoreEventsMeta{
    margin-top:14px;
  }
  .allStoreEventsPill,
  .allStoreEventsLegendItem,
  .allStoreEventsStoreBadge,
  .allStoreEventsStatus{
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:32px;
    padding:0 12px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.12);
    background:rgba(255,255,255,.04);
    color:#e9efff;
    font-size:13px;
  }
  .allStoreEventsActions a,
  .allStoreEventsTabs a,
  .allStoreEventsMonthNav a,
  .allStoreEventsInputBtn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:42px;
    padding:0 16px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(255,255,255,.04);
    color:#eef3ff;
    text-decoration:none;
  }
  .allStoreEventsActions .is-primary,
  .allStoreEventsTabs a.active,
  .allStoreEventsInputBtn.is-primary{
    background:linear-gradient(135deg, #15b8ff, #2970ff);
    border-color:transparent;
    color:#06111d;
    font-weight:800;
  }
  .allStoreEventsInputBtn.is-muted{
    opacity:.74;
    cursor:default;
  }
  .allStoreEventsCard{
    padding:18px;
  }
  .allStoreEventsTabsWrap{
    display:grid;
    gap:14px;
  }
  .allStoreEventsTabs{
    justify-content:space-between;
  }
  .allStoreEventsTabsItems{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
  }
  .allStoreEventsStats{
    color:#96a4c5;
    font-size:13px;
  }
  .allStoreEventsStats strong{
    color:#f5f8ff;
    font-size:20px;
  }
  .allStoreEventsLegend{
    gap:8px;
  }
  .allStoreEventsLegendItem::before,
  .allStoreEventsStoreBadge::before{
    content:"";
    width:10px;
    height:10px;
    border-radius:999px;
    background:var(--store-accent, #15b8ff);
    box-shadow:0 0 0 4px var(--store-soft, rgba(21,184,255,.18));
  }
  .allStoreEventsBoard{
    display:grid;
    grid-template-columns:minmax(0, 1fr) 340px;
    gap:16px;
    align-items:start;
  }
  .allStoreEventsCalendar{
    padding:20px;
    border-radius:24px;
    background:#04070d;
    border:1px solid rgba(36,148,255,.18);
  }
  .allStoreEventsMonthHead{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:16px;
    padding-bottom:14px;
    border-bottom:2px solid rgba(36,148,255,.52);
  }
  .allStoreEventsMonthTitle{
    display:flex;
    align-items:flex-end;
    gap:14px;
  }
  .allStoreEventsMonthNum{
    font-size:72px;
    line-height:.9;
    font-weight:900;
    color:#f5f8ff;
  }
  .allStoreEventsMonthLabel{
    font-size:28px;
    font-weight:800;
    color:#f5f8ff;
  }
  .allStoreEventsWeekdays,
  .allStoreEventsGrid{
    display:grid;
    grid-template-columns:repeat(7, minmax(0, 1fr));
  }
  .allStoreEventsWeekdays{
    padding:14px 0 8px;
    color:#5a6480;
    font-weight:800;
  }
  .allStoreEventsWeekdays div{
    padding-left:10px;
  }
  .allStoreEventsWeekdays .sun{ color:#ff6670; }
  .allStoreEventsWeekdays .sat{ color:#28a8ff; }
  .allStoreEventsDay{
    min-height:148px;
    padding:8px;
    border-top:1px solid rgba(255,255,255,.16);
    border-right:1px dotted rgba(255,255,255,.18);
    text-decoration:none;
    color:#eef3ff;
    display:flex;
    flex-direction:column;
    gap:8px;
  }
  .allStoreEventsDay:nth-child(7n+1){
    border-left:1px dotted rgba(255,255,255,.18);
  }
  .allStoreEventsDay.is-outside{
    color:#58627b;
    background:rgba(255,255,255,.01);
  }
  .allStoreEventsDay.is-today{
    background:linear-gradient(180deg, rgba(43,154,243,.12), rgba(43,154,243,.03));
  }
  .allStoreEventsDay.is-selected{
    box-shadow:inset 0 0 0 2px rgba(21,184,255,.86);
  }
  .allStoreEventsDayNum{
    font-size:20px;
    line-height:1;
    font-weight:900;
    color:#eef3ff;
  }
  .allStoreEventsDayNum.sun{ color:#ff6670; }
  .allStoreEventsDayNum.sat{ color:#28a8ff; }
  .allStoreEventsDayChips{
    display:grid;
    gap:6px;
    align-content:start;
  }
  .allStoreEventsChip{
    display:grid;
    gap:4px;
    padding:8px 9px;
    border-radius:13px;
    border:1px solid var(--store-soft-strong, rgba(21,184,255,.22));
    border-left:4px solid var(--store-accent, #15b8ff);
    background:linear-gradient(180deg, rgba(255,255,255,.08), rgba(255,255,255,.04));
    color:#eef3ff;
    overflow:hidden;
  }
  .allStoreEventsChipHead{
    display:flex;
    justify-content:space-between;
    gap:8px;
    align-items:center;
  }
  .allStoreEventsChipStore{
    color:#d5e6ff;
    font-size:10px;
    font-weight:800;
    letter-spacing:.04em;
    text-transform:uppercase;
  }
  .allStoreEventsChipTime{
    color:#8ed7ff;
    font-size:11px;
    font-weight:800;
  }
  .allStoreEventsChipTitle{
    color:#f5f8ff;
    font-size:12px;
    line-height:1.45;
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:2;
    overflow:hidden;
    word-break:break-word;
  }
  .allStoreEventsType{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:22px;
    padding:0 8px;
    border-radius:999px;
    font-size:10px;
    font-weight:800;
    border:1px solid rgba(255,255,255,.1);
    width:max-content;
  }
  .allStoreEventsType.is-service{
    background:rgba(255,173,72,.2);
    color:#ffd79c;
    border-color:rgba(255,173,72,.34);
  }
  .allStoreEventsType.is-forced{
    background:rgba(255,88,121,.2);
    color:#ffc1cd;
    border-color:rgba(255,88,121,.34);
  }
  .allStoreEventsMore{
    color:#96a4c5;
    font-size:11px;
    padding-left:2px;
  }
  .allStoreEventsSidebar{
    display:grid;
    gap:16px;
  }
  .allStoreEventsPanel{
    padding:18px;
    display:grid;
    gap:14px;
  }
  .allStoreEventsPanel h2,
  .allStoreEventsPanel h3{
    margin:0;
    font-size:18px;
  }
  .allStoreEventsPanelLead{
    color:#96a4c5;
    font-size:13px;
    line-height:1.6;
  }
  .allStoreEventsList{
    display:grid;
    gap:10px;
  }
  .allStoreEventsListItem{
    display:grid;
    gap:10px;
    padding:14px;
    border-radius:18px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
    color:#eef3ff;
    text-decoration:none;
  }
  .allStoreEventsListTitle{
    margin:0;
    font-size:18px;
    line-height:1.45;
  }
  .allStoreEventsListMeta{
    color:#96a4c5;
    font-size:12px;
  }
  .allStoreEventsStatus.is-draft{ background:rgba(255,255,255,.06); }
  .allStoreEventsStatus.is-confirmed{ background:rgba(21,184,255,.18); color:#9cecff; }
  .allStoreEventsStatus.is-cancelled{ background:rgba(255,86,102,.16); color:#ff9ca7; }
  .allStoreEventsStatus.is-done{ background:rgba(111,226,161,.14); color:#baf5cf; }
  .allStoreEventsEmpty{
    padding:16px;
    border-radius:18px;
    border:1px dashed rgba(255,255,255,.14);
    color:#96a4c5;
    text-align:center;
  }
  body[data-theme="light"] .allStoreEventsHero,
  body[data-theme="light"] .allStoreEventsCard,
  body[data-theme="light"] .allStoreEventsPanel{
    border-color:rgba(25,35,58,.08);
    background:linear-gradient(180deg, #ffffff, #f6f8fd);
    box-shadow:0 18px 42px rgba(15,23,42,.1);
  }
  body[data-theme="light"] .allStoreEventsHero h1,
  body[data-theme="light"] .allStoreEventsMonthNum,
  body[data-theme="light"] .allStoreEventsMonthLabel,
  body[data-theme="light"] .allStoreEventsPanel h2,
  body[data-theme="light"] .allStoreEventsPanel h3,
  body[data-theme="light"] .allStoreEventsStats strong,
  body[data-theme="light"] .allStoreEventsDayNum,
  body[data-theme="light"] .allStoreEventsListTitle,
  body[data-theme="light"] .allStoreEventsChipTitle{
    color:#162132;
  }
  body[data-theme="light"] .allStoreEventsLead,
  body[data-theme="light"] .allStoreEventsPanelLead,
  body[data-theme="light"] .allStoreEventsStats,
  body[data-theme="light"] .allStoreEventsListMeta,
  body[data-theme="light"] .allStoreEventsMore,
  body[data-theme="light"] .allStoreEventsEmpty{
    color:#5d6e89;
  }
  body[data-theme="light"] .allStoreEventsPill,
  body[data-theme="light"] .allStoreEventsLegendItem,
  body[data-theme="light"] .allStoreEventsStoreBadge,
  body[data-theme="light"] .allStoreEventsStatus,
  body[data-theme="light"] .allStoreEventsActions a,
  body[data-theme="light"] .allStoreEventsTabs a,
  body[data-theme="light"] .allStoreEventsMonthNav a,
  body[data-theme="light"] .allStoreEventsInputBtn,
  body[data-theme="light"] .allStoreEventsListItem{
    border-color:#d5dceb;
    background:#ffffff;
    color:#1f2c44;
  }
  body[data-theme="light"] .allStoreEventsActions .is-primary,
  body[data-theme="light"] .allStoreEventsTabs a.active,
  body[data-theme="light"] .allStoreEventsInputBtn.is-primary{
    color:#ffffff;
    background:linear-gradient(135deg, #1792f2, #2563eb);
    border-color:transparent;
  }
  body[data-theme="light"] .allStoreEventsCalendar{
    background:linear-gradient(180deg, #ffffff, #f7faff);
    border-color:rgba(43,154,243,.18);
    box-shadow:0 18px 42px rgba(15,23,42,.08);
  }
  body[data-theme="light"] .allStoreEventsMonthHead{
    border-bottom-color:rgba(43,154,243,.35);
  }
  body[data-theme="light"] .allStoreEventsWeekdays{
    color:#6f7e97;
  }
  body[data-theme="light"] .allStoreEventsDay{
    color:#1f2c44;
    border-top:1px solid #dce4f2;
    border-right:1px dotted #d7dfed;
    background:rgba(255,255,255,.62);
  }
  body[data-theme="light"] .allStoreEventsDay:nth-child(7n+1){
    border-left:1px dotted #d7dfed;
  }
  body[data-theme="light"] .allStoreEventsDay.is-outside{
    color:#98a7be;
    background:rgba(244,247,252,.88);
  }
  body[data-theme="light"] .allStoreEventsChip{
    background:linear-gradient(180deg, #ffffff, #f5f8ff);
    box-shadow:0 8px 18px rgba(15,23,42,.05);
  }
  body[data-theme="light"] .allStoreEventsChipStore{
    color:#58718e;
  }
  body[data-theme="light"] .allStoreEventsChipTime{
    color:#0d7dcc;
  }
  body[data-theme="light"] .allStoreEventsType.is-service{
    background:#fff3e4;
    color:#b06100;
    border-color:#ffd19a;
  }
  body[data-theme="light"] .allStoreEventsType.is-forced{
    background:#ffe8ee;
    color:#bd3258;
    border-color:#ffb7c9;
  }
  body[data-theme="light"] .allStoreEventsStatus.is-draft{ background:#f7f9fd; color:#44536d; }
  body[data-theme="light"] .allStoreEventsStatus.is-confirmed{ background:#e9f6ff; color:#0e7bc5; }
  body[data-theme="light"] .allStoreEventsStatus.is-cancelled{ background:#fff0f2; color:#b03c4f; }
  body[data-theme="light"] .allStoreEventsStatus.is-done{ background:#ecfbf2; color:#2b7a53; }
  body[data-theme="light"] .allStoreEventsEmpty{
    border-color:#d5dceb;
    background:#fbfcff;
  }
  @media (max-width: 1120px){
    .allStoreEventsBoard{ grid-template-columns:1fr; }
  }
  @media (max-width: 760px){
    .allStoreEventsPage{ padding:14px 10px 32px; }
    .allStoreEventsCalendar{ padding:14px; overflow-x:auto; }
    .allStoreEventsWeekdays,
    .allStoreEventsGrid{ min-width:780px; }
    .allStoreEventsMonthNum{ font-size:52px; }
    .allStoreEventsMonthLabel{ font-size:22px; }
    .allStoreEventsDay{ min-height:128px; }
  }
</style>

<div class="allStoreEventsPage">
  <section class="allStoreEventsHero">
    <div>
      <h1>全店イベントカレンダー</h1>
      <div class="allStoreEventsLead">5店舗分の予定をひとつの画面で見渡せる統合ページです。通常の店舗ページは入力用、こちらは比較と全体確認用として使えます。</div>
      <div class="allStoreEventsMeta">
        <span class="allStoreEventsPill">閲覧範囲 <?= count($storeIds) ?>店舗</span>
        <span class="allStoreEventsPill">対象月 <?= h($monthStart->format('Y年n月')) ?></span>
        <span class="allStoreEventsPill"><?= $scope === 'store' ? (h($activeStoreName) . ' を表示中') : '全店表示' ?></span>
      </div>
    </div>
    <div class="allStoreEventsActions">
      <a href="/wbss/public/dashboard.php?store_id=<?= (int)$selectedStoreId ?>">ダッシュボードへ</a>
      <?php if ($scope === 'store'): ?>
        <a class="is-primary" href="/wbss/public/store_events/new.php?store_id=<?= (int)$selectedStoreId ?>&date=<?= h($selectedDate) ?>">+ この店舗に入力</a>
      <?php else: ?>
        <span class="allStoreEventsInputBtn is-muted">入力は店舗タブから</span>
      <?php endif; ?>
    </div>
  </section>

  <section class="allStoreEventsCard allStoreEventsTabsWrap">
    <div class="allStoreEventsTabs">
      <div class="allStoreEventsTabsItems">
        <a class="<?= $scope === 'all' ? 'active' : '' ?>" href="<?= h(build_all_store_events_url(['scope' => 'all', 'store_id' => null])) ?>">全店</a>
        <?php foreach ($storeIds as $sid): ?>
          <?php $meta = $storeMeta[$sid]; ?>
          <a
            class="<?= $scope === 'store' && $selectedStoreId === $sid ? 'active' : '' ?>"
            href="<?= h(build_all_store_events_url(['scope' => 'store', 'store_id' => $sid])) ?>"
            style="--store-accent: <?= h($meta['accent']) ?>; --store-soft: <?= h($meta['accent_soft']) ?>;"
          ><?= h($meta['name']) ?></a>
        <?php endforeach; ?>
      </div>
      <div class="allStoreEventsStats">
        <span>対象月</span>
        <strong><?= (int)$monthStart->format('n') ?>月</strong>
        <span><?= (int)($scope === 'store' ? ($monthCountByStore[$selectedStoreId] ?? 0) : $monthCountTotal) ?>件</span>
      </div>
    </div>
    <div class="allStoreEventsLegend">
      <?php foreach ($storeIds as $sid): ?>
        <?php $meta = $storeMeta[$sid]; ?>
        <span
          class="allStoreEventsLegendItem"
          style="--store-accent: <?= h($meta['accent']) ?>; --store-soft: <?= h($meta['accent_soft']) ?>;"
        ><?= h($meta['name']) ?> <?= (int)($monthCountByStore[$sid] ?? 0) ?>件</span>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="allStoreEventsBoard">
    <div class="allStoreEventsCalendar">
      <div class="allStoreEventsMonthHead">
        <div class="allStoreEventsMonthTitle">
          <div class="allStoreEventsMonthNum"><?= (int)$monthStart->format('n') ?></div>
          <div class="allStoreEventsMonthLabel"><?= h($monthStart->format('F')) ?></div>
        </div>
        <div class="allStoreEventsMonthNav">
          <a href="<?= h(build_all_store_events_url(['month' => $prevMonth, 'selected_date' => $monthStart->modify('-1 month')->format('Y-m-01')])) ?>">前月</a>
          <a href="<?= h(build_all_store_events_url(['month' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m'), 'selected_date' => $todayYmd])) ?>">今月</a>
          <a href="<?= h(build_all_store_events_url(['month' => $nextMonth, 'selected_date' => $monthStart->modify('+1 month')->format('Y-m-01')])) ?>">次月</a>
        </div>
      </div>

      <div class="allStoreEventsWeekdays">
        <div class="sun">Sun</div>
        <div>Mon</div>
        <div>Tue</div>
        <div>Wed</div>
        <div>Thu</div>
        <div>Fri</div>
        <div class="sat">Sat</div>
      </div>

      <div class="allStoreEventsGrid">
        <?php for ($i = 0; $i < 42; $i++): ?>
          <?php
            $date = $gridStart->modify('+' . $i . ' days');
            $ymd = $date->format('Y-m-d');
            $dow = (int)$date->format('w');
            $dayRows = $eventsByDate[$ymd] ?? [];
            if ($scope === 'store') {
              $dayRows = array_values(array_filter(
                $dayRows,
                static fn(array $row): bool => (int)($row['store_id'] ?? 0) === $selectedStoreId
              ));
            }
            $classes = ['allStoreEventsDay'];
            if ($date->format('Y-m') !== $month) $classes[] = 'is-outside';
            if ($ymd === $todayYmd) $classes[] = 'is-today';
            if ($ymd === $selectedDate) $classes[] = 'is-selected';
            $numClass = 'allStoreEventsDayNum';
            if ($dow === 0) $numClass .= ' sun';
            if ($dow === 6) $numClass .= ' sat';
          ?>
          <a class="<?= h(implode(' ', $classes)) ?>" href="<?= h(build_all_store_events_url(['month' => $date->format('Y-m'), 'selected_date' => $ymd])) ?>">
            <div class="<?= h($numClass) ?>"><?= (int)$date->format('j') ?></div>
            <div class="allStoreEventsDayChips">
              <?php foreach (array_slice($dayRows, 0, 4) as $row): ?>
                <?php $eventType = (string)($row['event_type'] ?? ''); ?>
                <span
                  class="allStoreEventsChip"
                  style="--store-accent: <?= h((string)$row['store_accent']) ?>; --store-soft-strong: <?= h((string)$row['store_accent_soft_strong']) ?>;"
                >
                  <span class="allStoreEventsChipHead">
                    <span class="allStoreEventsChipStore"><?= h((string)$row['store_short_name']) ?></span>
                    <span class="allStoreEventsChipTime"><?= h(substr((string)$row['starts_at'], 11, 5)) ?></span>
                  </span>
                  <?php if ((string)$row['display_title'] !== ''): ?>
                    <span class="allStoreEventsChipTitle"><?= h((string)$row['display_title']) ?></span>
                  <?php endif; ?>
                  <?php if ($eventType !== ''): ?>
                    <span class="allStoreEventsType <?= h(internal_event_type_class($eventType)) ?>"><?= h(internal_event_type_label($eventType)) ?></span>
                  <?php endif; ?>
                </span>
              <?php endforeach; ?>
              <?php if (count($dayRows) > 4): ?>
                <span class="allStoreEventsMore">+<?= count($dayRows) - 4 ?>件</span>
              <?php endif; ?>
            </div>
          </a>
        <?php endfor; ?>
      </div>
    </div>

    <aside class="allStoreEventsSidebar">
      <section class="allStoreEventsPanel">
        <div>
          <h2><?= h($selectedDateObj->format('Y年n月j日')) ?></h2>
          <div class="allStoreEventsPanelLead"><?= $scope === 'store' ? '選択店舗のイベントだけを表示しています。' : 'この日の全店イベントをまとめて確認できます。' ?></div>
        </div>
        <div class="allStoreEventsList">
          <?php if ($selectedDateRows): ?>
            <?php foreach ($selectedDateRows as $row): ?>
              <?php $eventType = (string)($row['event_type'] ?? ''); ?>
              <a class="allStoreEventsListItem" href="/wbss/public/store_events/edit.php?store_id=<?= (int)$row['store_id'] ?>&id=<?= (int)$row['id'] ?>">
                <div class="allStoreEventsListMeta">
                  <span
                    class="allStoreEventsStoreBadge"
                    style="--store-accent: <?= h((string)$row['store_accent']) ?>; --store-soft: <?= h((string)$row['store_accent_soft']) ?>;"
                  ><?= h((string)$row['store_name']) ?></span>
                </div>
                <?php if ((string)$row['display_title'] !== ''): ?>
                  <h3 class="allStoreEventsListTitle"><?= h((string)$row['display_title']) ?></h3>
                <?php endif; ?>
                <?php if ($eventType !== ''): ?>
                  <span class="allStoreEventsType <?= h(internal_event_type_class($eventType)) ?>"><?= h(internal_event_type_label($eventType)) ?></span>
                <?php endif; ?>
                <div class="allStoreEventsListMeta">
                  <span><?= h(format_event_period((string)($row['starts_at'] ?? ''), (string)($row['ends_at'] ?? ''))) ?></span>
                </div>
                <div class="allStoreEventsListMeta">
                  <span class="allStoreEventsStatus <?= h(store_event_status_class((string)($row['status'] ?? ''))) ?>"><?= h(store_event_status_label((string)($row['status'] ?? ''))) ?></span>
                </div>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="allStoreEventsEmpty">この日のイベントはありません。</div>
          <?php endif; ?>
        </div>
      </section>

      <section class="allStoreEventsPanel">
        <div>
          <h3><?= $scope === 'store' ? h($activeStoreName . ' の直近予定') : '全店の直近予定' ?></h3>
          <div class="allStoreEventsPanelLead">次に近いイベントを見ながら、店舗間の重なりも確認できます。</div>
        </div>
        <div class="allStoreEventsList">
          <?php if ($upcomingRows): ?>
            <?php foreach ($upcomingRows as $row): ?>
              <?php $eventType = (string)($row['event_type'] ?? ''); ?>
              <a class="allStoreEventsListItem" href="/wbss/public/store_events/edit.php?store_id=<?= (int)$row['store_id'] ?>&id=<?= (int)$row['id'] ?>">
                <div class="allStoreEventsListMeta">
                  <span
                    class="allStoreEventsStoreBadge"
                    style="--store-accent: <?= h((string)$row['store_accent']) ?>; --store-soft: <?= h((string)$row['store_accent_soft']) ?>;"
                  ><?= h((string)$row['store_name']) ?></span>
                </div>
                <?php if ((string)$row['display_title'] !== ''): ?>
                  <h3 class="allStoreEventsListTitle"><?= h((string)$row['display_title']) ?></h3>
                <?php endif; ?>
                <?php if ($eventType !== ''): ?>
                  <span class="allStoreEventsType <?= h(internal_event_type_class($eventType)) ?>"><?= h(internal_event_type_label($eventType)) ?></span>
                <?php endif; ?>
                <div class="allStoreEventsListMeta"><?= h(format_event_period((string)($row['starts_at'] ?? ''), (string)($row['ends_at'] ?? ''))) ?></div>
              </a>
            <?php endforeach; ?>
          <?php else: ?>
            <div class="allStoreEventsEmpty">表示できる予定はまだありません。</div>
          <?php endif; ?>
        </div>
      </section>
    </aside>
  </section>
</div>

<?php render_page_end(); ?>
