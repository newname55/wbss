<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/store.php';
if (is_file(__DIR__ . '/../../app/auth.php')) require_once __DIR__ . '/../../app/auth.php';
if (is_file(__DIR__ . '/../../app/layout.php')) require_once __DIR__ . '/../../app/layout.php';

if (function_exists('require_login')) require_login();
if (function_exists('require_role')) {
  require_role(['cast', 'staff', 'manager', 'admin', 'super_user']);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

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

function build_store_events_url(int $storeId, array $overrides = []): string {
  $params = array_merge($_GET, $overrides);
  $params['store_id'] = $storeId;
  foreach ($params as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    }
  }
  return './index.php?' . http_build_query($params);
}

function is_linked_external_event(array $row): bool {
  $title = trim((string)($row['title'] ?? ''));
  $memo = trim((string)($row['memo'] ?? ''));
  return str_starts_with($title, '【連動】') || str_contains($memo, '外部イベント連動:');
}

function compare_internal_events(array $a, array $b): int {
  $aLinked = is_linked_external_event($a) ? 1 : 0;
  $bLinked = is_linked_external_event($b) ? 1 : 0;
  if ($aLinked !== $bLinked) {
    return $aLinked <=> $bLinked;
  }

  $aStart = (string)($a['starts_at'] ?? '');
  $bStart = (string)($b['starts_at'] ?? '');
  if ($aStart !== $bStart) {
    return strcmp($aStart, $bStart);
  }

  return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
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

function is_cancelled_internal_event(array $row): bool {
  $status = strtolower(trim((string)($row['status'] ?? '')));
  return $status === 'cancelled' || $status === 'canceled';
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

function build_cashier_new_url_for_event(int $storeId, array $row): string {
  $businessDate = substr((string)($row['starts_at'] ?? ''), 0, 10);
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
    $businessDate = (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
  }

  return '/wbss/public/cashier/index.php?' . http_build_query([
    'store_id' => $storeId,
    'action' => 'new',
    'business_date' => $businessDate,
    'store_event_instance_id' => (int)($row['id'] ?? 0),
    'visit_type' => 'event',
  ]);
}

$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0) {
  $storeId = (int)current_store_id();
}
if ($storeId <= 0) {
  header('Location: /wbss/public/store_select.php?return=' . rawurlencode('/wbss/public/store_events/index.php'));
  exit;
}
set_current_store_id($storeId);

$tab = ($_GET['tab'] ?? 'internal');
$tab = in_array($tab, ['external', 'internal'], true) ? $tab : 'internal';
$showExternal = (int)($_GET['show_external'] ?? 0) === 1;
if (!$showExternal && $tab === 'external') {
  $tab = 'internal';
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$storeRule = [
  'business_day_start' => '06:00',
];
try {
  $stStore = $pdo->prepare("SELECT business_day_start FROM stores WHERE id = ? LIMIT 1");
  $stStore->execute([$storeId]);
  $storeRow = $stStore->fetch(PDO::FETCH_ASSOC) ?: [];
  $storeRule['business_day_start'] = hm_from_time_value((string)($storeRow['business_day_start'] ?? ''), '06:00');
} catch (Throwable $e) {
}

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

/* =========================
  EXTERNAL filters
========================= */
$q = trim((string)($_GET['q'] ?? ''));
$source = trim((string)($_GET['source'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to = trim((string)($_GET['to'] ?? ''));
$limit = max(20, min(300, (int)($_GET['limit'] ?? 120)));

if ($from === '' || $to === '') {
  $from = $from ?: $monthStart->format('Y-m-01');
  $to = $to ?: $monthStart->modify('+2 months')->format('Y-m-t');
}

$sources = [];
try {
  $st = $pdo->prepare("SELECT source, COUNT(*) c
                         FROM store_external_events
                        WHERE store_id = :sid
                        GROUP BY source
                        ORDER BY c DESC");
  $st->execute(['sid' => $storeId]);
  $sources = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
  $sources = [];
}

$externalRows = [];
$externalCount = 0;
if ($tab === 'external') {
  $where = ["e.store_id = :sid"];
  $params = ['sid' => $storeId];

  if ($source !== '') {
    $where[] = "e.source = :source";
    $params['source'] = $source;
  }
  if ($q !== '') {
    $where[] = "(e.title LIKE :q OR e.venue_name LIKE :q OR e.venue_addr LIKE :q)";
    $params['q'] = '%' . $q . '%';
  }
  if ($from !== '') {
    $where[] = "(e.ends_at IS NULL OR e.ends_at >= :from_dt)";
    $params['from_dt'] = $from . ' 00:00:00';
  }
  if ($to !== '') {
    $where[] = "(e.starts_at IS NULL OR e.starts_at <= :to_dt)";
    $params['to_dt'] = $to . ' 23:59:59';
  }

  $whereSql = implode(' AND ', $where);

  $stCnt = $pdo->prepare("SELECT COUNT(*) FROM store_external_events e WHERE {$whereSql}");
  $stCnt->execute($params);
  $externalCount = (int)$stCnt->fetchColumn();

  $sql = "SELECT e.*
            FROM store_external_events e
           WHERE {$whereSql}
           ORDER BY COALESCE(e.starts_at, '9999-12-31 00:00:00') ASC, e.id ASC
           LIMIT {$limit}";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $externalRows = $st->fetchAll(PDO::FETCH_ASSOC);
}

/* =========================
  Internal calendar
========================= */
$internalRows = [];
$eventsByDate = [];
$monthCount = 0;
$selectedDateRows = [];
$upcomingRows = [];

if ($tab === 'internal') {
  $st = $pdo->prepare("
    SELECT id, title, status, starts_at, ends_at, budget_yen, memo
      FROM store_event_instances
     WHERE store_id = :sid
       AND COALESCE(ends_at, starts_at) >= :grid_start
       AND starts_at <= :grid_end
     ORDER BY starts_at ASC, id ASC
  ");
  $st->execute([
    'sid' => $storeId,
    'grid_start' => $gridStart->format('Y-m-d 00:00:00'),
    'grid_end' => $gridEnd->format('Y-m-d 23:59:59'),
  ]);
  $internalRows = $st->fetchAll(PDO::FETCH_ASSOC);

  foreach ($internalRows as $row) {
    if (is_linked_external_event($row)) {
      continue;
    }
    $startRaw = trim((string)($row['starts_at'] ?? ''));
    $endRaw = trim((string)($row['ends_at'] ?? ''));
    if ($startRaw === '') {
      continue;
    }

    $start = new DateTimeImmutable($startRaw, new DateTimeZone('Asia/Tokyo'));
    $end = $endRaw !== ''
      ? new DateTimeImmutable($endRaw, new DateTimeZone('Asia/Tokyo'))
      : $start;

    if ($end < $start) {
      $end = $start;
    }

    if ($start <= $monthEnd && $end >= $monthStart) {
      $monthCount++;
    }

    $startBizDate = business_date_from_datetime($start, $storeRule['business_day_start']);
    $endBizBase = $end;
    if ($end > $start) {
      $endBizBase = $end->modify('-1 second');
    }
    $endBizDate = business_date_from_datetime($endBizBase, $storeRule['business_day_start']);

    $cursor = new DateTimeImmutable($startBizDate . ' 00:00:00', new DateTimeZone('Asia/Tokyo'));
    $lastDay = new DateTimeImmutable($endBizDate . ' 00:00:00', new DateTimeZone('Asia/Tokyo'));
    while ($cursor <= $lastDay) {
      $key = $cursor->format('Y-m-d');
      $eventsByDate[$key][] = $row;
      $cursor = $cursor->modify('+1 day');
    }
  }

  foreach ($eventsByDate as &$dayRows) {
    usort($dayRows, 'compare_internal_events');
  }
  unset($dayRows);

  $selectedDateRows = $eventsByDate[$selectedDate] ?? [];

  $stUpcoming = $pdo->prepare("
    SELECT id, title, status, starts_at, ends_at
      FROM store_event_instances
     WHERE store_id = :sid
       AND starts_at >= :from_dt
     ORDER BY starts_at ASC, id ASC
     LIMIT 8
  ");
  $stUpcoming->execute([
    'sid' => $storeId,
    'from_dt' => $monthStart->format('Y-m-01 00:00:00'),
  ]);
  $upcomingRows = $stUpcoming->fetchAll(PDO::FETCH_ASSOC);
  usort($selectedDateRows, 'compare_internal_events');
  usort($upcomingRows, 'compare_internal_events');
}

$title = '店舗イベント';
if (function_exists('render_page_start')) {
  render_page_start($title);
}
if (function_exists('render_header')) {
  render_header($title, [
    'back_href' => '/wbss/public/dashboard.php?store_id=' . $storeId,
  ]);
} elseif (!function_exists('render_page_start')) {
  ?><!doctype html><html lang="ja"><head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($title)?></title>
  </head><body><?php
}
?>
<style>
  .storeEventsPage{
    max-width:1360px;
    margin:0 auto;
    padding:20px 16px 40px;
    display:grid;
    gap:16px;
  }
  .storeEventsHero,
  .storeEventsCard{
    border:1px solid rgba(255,255,255,.12);
    border-radius:22px;
    background:linear-gradient(180deg, rgba(9,14,27,.96), rgba(13,19,37,.88));
    box-shadow:0 22px 50px rgba(0,0,0,.22);
  }
  .storeEventsHero{
    padding:22px;
    display:flex;
    flex-wrap:wrap;
    gap:16px;
    justify-content:space-between;
    align-items:flex-end;
  }
  .storeEventsHero__title{
    margin:0;
    font-size:34px;
    line-height:1;
    letter-spacing:.02em;
  }
  .storeEventsHero__sub{
    margin-top:8px;
    color:#93a0c1;
    font-size:14px;
  }
  .storeEventsHero__actions,
  .storeEventsTabs,
  .storeEventsMonthNav,
  .storeEventsStats,
  .storeEventsExternalFilter,
  .storeEventsListItem__meta,
  .storeEventsMetaRow{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
  }
  .storeEventsHero__sub{
    max-width:760px;
    line-height:1.6;
  }
  .storeEventsMetaRow{
    margin-top:12px;
    color:#93a0c1;
    font-size:13px;
  }
  .storeEventsMetaPill{
    display:inline-flex;
    align-items:center;
    gap:8px;
    min-height:32px;
    padding:0 12px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.1);
    background:rgba(255,255,255,.04);
  }
  .storeEventsBtn,
  .storeEventsTabs a,
  .storeEventsMonthNav a{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:8px;
    min-height:42px;
    padding:0 14px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.14);
    color:#eef3ff;
    text-decoration:none;
    background:rgba(255,255,255,.04);
  }
  .storeEventsBtn--primary,
  .storeEventsTabs a.active{
    background:linear-gradient(135deg, #15b8ff, #2970ff);
    border-color:transparent;
    color:#06111d;
    font-weight:700;
  }
  .storeEventsCard{
    padding:18px;
  }
  .storeEventsTabs{
    justify-content:space-between;
  }
  .storeEventsTabs__items{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
  }
  .storeEventsToggleLink{
    color:#6c7b97;
    font-size:12px;
    text-decoration:none;
    padding:0 2px;
    opacity:.88;
  }
  .storeEventsUtility{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    width:36px;
    height:36px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.12);
    background:rgba(255,255,255,.04);
    color:#dce6fb;
    text-decoration:none;
    font-size:16px;
    line-height:1;
    opacity:.92;
  }
  .storeEventsUtility:hover{
    opacity:1;
    transform:translateY(-1px);
  }
  .storeEventsUtilityLabel{
    color:#6c7b97;
    font-size:12px;
  }
  .storeEventsHero__utility{
    display:grid;
    justify-items:end;
    gap:6px;
  }
  .storeEventsStats{
    color:#93a0c1;
    font-size:13px;
  }
  .storeEventsStats strong{
    color:#eef3ff;
    font-size:20px;
  }
  .storeEventsBoard{
    display:grid;
    grid-template-columns:minmax(0, 1fr) 320px;
    gap:16px;
  }
  .storeEventsCalendar{
    padding:20px;
    background:#030507;
    border-radius:24px;
    border:1px solid rgba(25,184,255,.22);
  }
  .storeEventsMonthHead{
    display:flex;
    justify-content:space-between;
    align-items:flex-end;
    gap:16px;
    padding-bottom:14px;
    border-bottom:2px solid rgba(25,184,255,.7);
  }
  .storeEventsMonthHead__month{
    display:flex;
    align-items:flex-end;
    gap:14px;
  }
  .storeEventsMonthHead__num{
    font-size:72px;
    line-height:.9;
    font-weight:800;
    color:#f4f7ff;
  }
  .storeEventsMonthHead__label{
    font-size:28px;
    font-weight:700;
    color:#f4f7ff;
  }
  .storeEventsMonthHead__year{
    color:#7d89a9;
    font-size:18px;
    font-weight:700;
  }
  .storeEventsWeekdays,
  .storeEventsGrid{
    display:grid;
    grid-template-columns:repeat(7, minmax(0, 1fr));
  }
  .storeEventsWeekdays{
    padding:14px 0 8px;
    color:#515b73;
    font-weight:700;
  }
  .storeEventsWeekdays div{
    padding-left:10px;
  }
  .storeEventsWeekdays .sun{ color:#ff5666; }
  .storeEventsWeekdays .sat{ color:#27aeff; }
  .storeEventsDay{
    min-height:134px;
    padding:8px;
    border-top:1px solid rgba(255,255,255,.42);
    border-right:1px dotted rgba(255,255,255,.38);
    text-decoration:none;
    color:#eef3ff;
    display:flex;
    flex-direction:column;
    gap:8px;
    background:rgba(255,255,255,0);
  }
  .storeEventsDay:nth-child(7n+1){ border-left:1px dotted rgba(255,255,255,.38); }
  .storeEventsDay.is-outside{ color:#4f586d; }
  .storeEventsDay.is-today{ background:linear-gradient(180deg, rgba(21,184,255,.16), rgba(21,184,255,.04)); }
  .storeEventsDay.is-selected{ box-shadow:inset 0 0 0 2px rgba(21,184,255,.88); }
  .storeEventsDay__num{
    font-size:20px;
    font-weight:800;
    line-height:1;
  }
  .storeEventsDay__num.sun{ color:#ff5666; }
  .storeEventsDay__num.sat{ color:#27aeff; }
  .storeEventsDay__chips{
    display:grid;
    gap:6px;
    align-content:start;
  }
  .storeEventsChip{
    display:grid;
    gap:5px;
    padding:8px 9px;
    border-radius:12px;
    background:linear-gradient(180deg, rgba(255,255,255,.1), rgba(255,255,255,.06));
    border:1px solid rgba(255,255,255,.1);
    font-size:12px;
    line-height:1.45;
    overflow:hidden;
  }
  .storeEventsChip__time{
    color:#8ddcff;
    font-weight:700;
    font-size:11px;
    letter-spacing:.02em;
  }
  .storeEventsChip__title{
    color:#eef3ff;
    display:-webkit-box;
    -webkit-box-orient:vertical;
    -webkit-line-clamp:3;
    overflow:hidden;
    word-break:break-word;
  }
  .storeEventsChip.is-service{
    background:linear-gradient(180deg, rgba(255,173,72,.22), rgba(255,173,72,.1));
    border-color:rgba(255,173,72,.34);
  }
  .storeEventsChip.is-forced{
    background:linear-gradient(180deg, rgba(255,88,121,.22), rgba(255,88,121,.1));
    border-color:rgba(255,88,121,.34);
  }
  .storeEventsChip.is-linked{
    gap:0;
    align-content:center;
    justify-items:center;
    min-height:52px;
    padding:6px;
    background:linear-gradient(180deg, rgba(61,123,255,.1), rgba(61,123,255,.05));
    border-style:dashed;
    cursor:help;
  }
  .storeEventsChip__icon{
    font-size:18px;
    line-height:1;
  }
  .storeEventsChip__more{
    color:#93a0c1;
    font-size:11px;
  }
  .storeEventsSidebar{
    display:grid;
    gap:16px;
    align-content:start;
  }
  .storeEventsPanel{
    border-radius:20px;
    background:rgba(5,10,20,.9);
    border:1px solid rgba(255,255,255,.1);
    padding:18px;
    display:grid;
    gap:14px;
  }
  .storeEventsPanel h2,
  .storeEventsPanel h3{
    margin:0;
    font-size:18px;
  }
  .storeEventsPanel__lead{
    color:#93a0c1;
    font-size:13px;
  }
  .storeEventsList{
    display:grid;
    gap:10px;
  }
  .storeEventsListItem{
    display:block;
    padding:12px 14px;
    border-radius:16px;
    border:1px solid rgba(255,255,255,.08);
    background:rgba(255,255,255,.04);
    color:#eef3ff;
    text-decoration:none;
  }
  .storeEventsListItem__title{
    font-weight:700;
    margin-bottom:6px;
    line-height:1.5;
  }
  .storeEventsListItem.is-linked{
    border-style:dashed;
    background:rgba(255,255,255,.025);
  }
  .storeEventsListItem__linkedMark{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-bottom:8px;
    padding:4px 9px;
    border-radius:999px;
    border:1px solid rgba(97,154,255,.22);
    color:#8eb8ff;
    font-size:11px;
    font-weight:700;
  }
  .storeEventsListItem__meta{
    color:#93a0c1;
    font-size:12px;
  }
  .storeEventsTypeBadge{
    display:inline-flex;
    align-items:center;
    gap:6px;
    margin-bottom:8px;
    padding:4px 9px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.14);
    font-size:11px;
    font-weight:700;
  }
  .storeEventsTypeBadge.is-service{
    background:rgba(255,173,72,.18);
    color:#ffd79c;
    border-color:rgba(255,173,72,.32);
  }
  .storeEventsTypeBadge.is-forced{
    background:rgba(255,88,121,.18);
    color:#ffc0cc;
    border-color:rgba(255,88,121,.32);
  }
  .storeEventsListItem__actions{
    display:flex;
    flex-wrap:wrap;
    gap:8px;
    margin-top:10px;
  }
  .storeEventsBadge{
    display:inline-flex;
    align-items:center;
    min-height:24px;
    padding:0 10px;
    border-radius:999px;
    font-size:12px;
    border:1px solid rgba(255,255,255,.12);
    color:#d9e0f1;
  }
  .storeEventsBadge.is-draft{ background:rgba(255,255,255,.06); }
  .storeEventsBadge.is-confirmed{ background:rgba(21,184,255,.18); color:#99ecff; }
  .storeEventsBadge.is-cancelled{ background:rgba(255,86,102,.16); color:#ff9aa5; }
  .storeEventsBadge.is-done{ background:rgba(111,226,161,.14); color:#b5f5cb; }
  .storeEventsEmpty{
    padding:16px;
    border-radius:16px;
    border:1px dashed rgba(255,255,255,.14);
    color:#93a0c1;
    text-align:center;
  }
  .storeEventsExternalFilter input,
  .storeEventsExternalFilter select{
    min-height:40px;
    padding:0 12px;
    border-radius:12px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(255,255,255,.05);
    color:#eef3ff;
  }
  .storeEventsExternalTable{
    width:100%;
    border-collapse:collapse;
  }
  .storeEventsExternalTable th,
  .storeEventsExternalTable td{
    padding:14px 10px;
    border-bottom:1px solid rgba(255,255,255,.08);
    text-align:left;
    vertical-align:top;
  }
  .storeEventsExternalTable th{
    color:#93a0c1;
    font-size:12px;
  }
  body[data-theme="light"] .storeEventsHero,
  body[data-theme="light"] .storeEventsCard,
  body[data-theme="light"] .storeEventsPanel{
    border:1px solid rgba(25,35,58,.08);
    background:linear-gradient(180deg, #ffffff, #f6f8fd);
    box-shadow:0 20px 44px rgba(15,23,42,.12);
  }
  body[data-theme="light"] .storeEventsCalendar{
    background:linear-gradient(180deg, #ffffff, #f7faff);
    border:1px solid rgba(43,154,243,.18);
    box-shadow:0 20px 44px rgba(15,23,42,.1);
  }
  body[data-theme="light"] .storeEventsHero__title,
  body[data-theme="light"] .storeEventsMonthHead__num,
  body[data-theme="light"] .storeEventsMonthHead__label,
  body[data-theme="light"] .storeEventsPanel h2,
  body[data-theme="light"] .storeEventsPanel h3,
  body[data-theme="light"] .storeEventsStats strong,
  body[data-theme="light"] .storeEventsExternalTable td,
  body[data-theme="light"] .storeEventsExternalTable td a,
  body[data-theme="light"] .storeEventsListItem,
  body[data-theme="light"] .storeEventsChip__title{
    color:#172133;
  }
  body[data-theme="light"] .storeEventsHero__sub,
  body[data-theme="light"] .storeEventsMetaRow,
  body[data-theme="light"] .storeEventsStats,
  body[data-theme="light"] .storeEventsToggleLink,
  body[data-theme="light"] .storeEventsPanel__lead,
  body[data-theme="light"] .storeEventsListItem__meta,
  body[data-theme="light"] .storeEventsEmpty{
    color:#5f6f8c;
  }
  body[data-theme="light"] .storeEventsMetaPill,
  body[data-theme="light"] .storeEventsBtn,
  body[data-theme="light"] .storeEventsTabs a,
  body[data-theme="light"] .storeEventsMonthNav a,
  body[data-theme="light"] .storeEventsBadge,
  body[data-theme="light"] .storeEventsChip,
  body[data-theme="light"] .storeEventsUtility,
  body[data-theme="light"] .storeEventsListItem{
    border-color:#d5dceb;
    background:#ffffff;
  }
  body[data-theme="light"] .storeEventsBtn,
  body[data-theme="light"] .storeEventsTabs a,
  body[data-theme="light"] .storeEventsMonthNav a,
  body[data-theme="light"] .storeEventsUtility{
    color:#25324a;
  }
  body[data-theme="light"] .storeEventsBtn--primary,
  body[data-theme="light"] .storeEventsTabs a.active{
    color:#ffffff;
    background:linear-gradient(135deg, #1792f2, #2563eb);
    border-color:transparent;
  }
  body[data-theme="light"] .storeEventsMonthHead{
    border-bottom-color:rgba(43,154,243,.45);
  }
  body[data-theme="light"] .storeEventsWeekdays{
    color:#6d7c96;
  }
  body[data-theme="light"] .storeEventsDay{
    color:#1f2c44;
    border-top:1px solid #dce4f2;
    border-right:1px dotted #d6deed;
    background:rgba(255,255,255,.58);
  }
  body[data-theme="light"] .storeEventsDay:nth-child(7n+1){
    border-left:1px dotted #d6deed;
  }
  body[data-theme="light"] .storeEventsDay.is-outside{
    color:#9aa8bf;
    background:rgba(244,247,252,.85);
  }
  body[data-theme="light"] .storeEventsDay.is-today{
    background:linear-gradient(180deg, rgba(43,154,243,.12), rgba(43,154,243,.04));
  }
  body[data-theme="light"] .storeEventsDay__num{
    color:#1f2c44;
  }
  body[data-theme="light"] .storeEventsChip{
    background:linear-gradient(180deg, #ffffff, #f4f8ff);
    box-shadow:0 8px 18px rgba(15,23,42,.06);
  }
  body[data-theme="light"] .storeEventsChip.is-service{
    background:linear-gradient(180deg, #fff7ea, #ffefd7);
    border-color:#ffd19a;
  }
  body[data-theme="light"] .storeEventsChip.is-forced{
    background:linear-gradient(180deg, #fff0f4, #ffe2ea);
    border-color:#ffb7c9;
  }
  body[data-theme="light"] .storeEventsChip.is-linked{
    background:linear-gradient(180deg, #f7fbff, #edf5ff);
    border-color:#bfd6f6;
  }
  body[data-theme="light"] .storeEventsChip__time{
    color:#0d7dcc;
  }
  body[data-theme="light"] .storeEventsChip__more{
    color:#56708f;
  }
  body[data-theme="light"] .storeEventsBadge{
    color:#40506a;
  }
  body[data-theme="light"] .storeEventsTypeBadge.is-service{
    color:#b06100;
    border-color:#ffd19a;
    background:#fff3e4;
  }
  body[data-theme="light"] .storeEventsTypeBadge.is-forced{
    color:#bd3258;
    border-color:#ffb7c9;
    background:#ffe8ee;
  }
  body[data-theme="light"] .storeEventsBadge.is-draft{
    background:#f7f9fd;
  }
  body[data-theme="light"] .storeEventsBadge.is-confirmed{
    background:#e9f6ff;
    color:#0e7bc5;
  }
  body[data-theme="light"] .storeEventsBadge.is-cancelled{
    background:#fff0f2;
    color:#b03c4f;
  }
  body[data-theme="light"] .storeEventsBadge.is-done{
    background:#ecfbf2;
    color:#2b7a53;
  }
  body[data-theme="light"] .storeEventsExternalFilter label{
    color:#41516d;
    font-weight:700;
  }
  body[data-theme="light"] .storeEventsExternalFilter input,
  body[data-theme="light"] .storeEventsExternalFilter select{
    border:1px solid #cfd8ea;
    background:#ffffff;
    color:#172133;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.75);
  }
  body[data-theme="light"] .storeEventsExternalFilter input::placeholder{
    color:#7c8aa5;
  }
  body[data-theme="light"] .storeEventsExternalFilter input:focus,
  body[data-theme="light"] .storeEventsExternalFilter select:focus{
    outline:none;
    border-color:#2b9af3;
    box-shadow:0 0 0 4px rgba(43,154,243,.16);
  }
  body[data-theme="light"] .storeEventsExternalTable th{
    color:#5d6d88;
    border-bottom-color:#dbe3f0;
  }
  body[data-theme="light"] .storeEventsExternalTable td{
    border-bottom-color:#e5eaf4;
  }
  body[data-theme="light"] .storeEventsExternalTable tbody tr:hover{
    background:#f8fbff;
  }
  body[data-theme="light"] .storeEventsEmpty{
    border-color:#d5dceb;
    background:#fbfcff;
  }
  body[data-theme="light"] .storeEventsListItem.is-linked{
    background:#f8fbff;
    border-color:#d3dff1;
  }
  body[data-theme="light"] .storeEventsListItem__linkedMark{
    color:#4f78aa;
    border-color:#bfd6f6;
    background:#eef5ff;
  }
  @media (max-width: 1080px){
    .storeEventsBoard{ grid-template-columns:1fr; }
  }
  @media (max-width: 760px){
    .storeEventsPage{ padding:14px 10px 28px; }
    .storeEventsCalendar{ padding:14px; overflow-x:auto; }
    .storeEventsWeekdays,
    .storeEventsGrid{ min-width:760px; }
    .storeEventsMonthHead__num{ font-size:52px; }
    .storeEventsMonthHead__label{ font-size:22px; }
    .storeEventsDay{ min-height:118px; }
  }
</style>

<div class="storeEventsPage">
  <section class="storeEventsHero">
    <div>
      <h1 class="storeEventsHero__title">店舗イベント管理</h1>
      <div class="storeEventsHero__sub">店内イベントを月間カレンダーで確認し、入力と編集は別画面で行います。外部イベント一覧は通常は隠し、必要なときだけ表示します。</div>
      <div class="storeEventsMetaRow">
        <span class="storeEventsMetaPill">store_id=<?= (int)$storeId ?></span>
        <span class="storeEventsMetaPill">選択日 <?=h($selectedDateObj->format('Y/m/d'))?></span>
      </div>
    </div>
    <div class="storeEventsHero__actions">
      <?php if (!$showExternal): ?>
        <div class="storeEventsHero__utility">
          <a
            class="storeEventsUtility"
            href="<?=h(build_store_events_url($storeId, ['show_external' => 1, 'tab' => 'external']))?>"
            title="外部イベントを表示"
            aria-label="外部イベントを表示"
          >📝</a>
          <span class="storeEventsUtilityLabel">外部イベント</span>
        </div>
      <?php else: ?>
        <div class="storeEventsHero__utility">
          <a
            class="storeEventsUtility"
            href="<?=h(build_store_events_url($storeId, ['show_external' => null, 'tab' => 'internal']))?>"
            title="外部イベントを閉じる"
            aria-label="外部イベントを閉じる"
          >×</a>
          <span class="storeEventsUtilityLabel">閉じる</span>
        </div>
      <?php endif; ?>
      <a class="storeEventsBtn" href="/wbss/public/dashboard.php?store_id=<?=$storeId?>">ダッシュボードへ</a>
      <a class="storeEventsBtn storeEventsBtn--primary" href="./new.php?store_id=<?=$storeId?>&date=<?=$selectedDate?>">+ イベント入力</a>
    </div>
  </section>

  <section class="storeEventsCard storeEventsTabs">
    <div class="storeEventsTabs__items">
      <a class="<?= $tab === 'internal' ? 'active' : '' ?>" href="<?=h(build_store_events_url($storeId, ['tab' => 'internal']))?>">店内イベント</a>
      <?php if ($showExternal): ?>
        <a class="<?= $tab === 'external' ? 'active' : '' ?>" href="<?=h(build_store_events_url($storeId, ['tab' => 'external', 'show_external' => 1]))?>">外部イベント</a>
      <?php endif; ?>
    </div>
    <?php if ($tab === 'internal'): ?>
      <div class="storeEventsStats">
        <span>対象月</span>
        <strong><?= (int)$monthStart->format('n') ?>月</strong>
        <span><?= (int)$monthCount ?>件</span>
      </div>
    <?php else: ?>
      <div class="storeEventsStats">
        <span>外部イベント</span>
        <strong><?= (int)$externalCount ?></strong>
        <span>件</span>
        <a class="storeEventsToggleLink" href="<?=h(build_store_events_url($storeId, ['show_external' => null, 'tab' => 'internal']))?>">閉じる</a>
      </div>
    <?php endif; ?>
  </section>

  <?php if ($tab === 'internal'): ?>
    <section class="storeEventsBoard">
      <div class="storeEventsCalendar">
        <div class="storeEventsMonthHead">
          <div class="storeEventsMonthHead__month">
            <div class="storeEventsMonthHead__num"><?= (int)$monthStart->format('n') ?></div>
            <div class="storeEventsMonthHead__label"><?= h($monthStart->format('F')) ?></div>
          </div>
          <div class="storeEventsMonthNav">
            <a href="<?=h(build_store_events_url($storeId, ['month' => $prevMonth, 'tab' => 'internal', 'selected_date' => $monthStart->modify('-1 month')->format('Y-m-01')]))?>">前月</a>
            <a href="<?=h(build_store_events_url($storeId, ['month' => (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m'), 'tab' => 'internal', 'selected_date' => $todayYmd]))?>">今月</a>
            <a href="<?=h(build_store_events_url($storeId, ['month' => $nextMonth, 'tab' => 'internal', 'selected_date' => $monthStart->modify('+1 month')->format('Y-m-01')]))?>">次月</a>
          </div>
        </div>

        <div class="storeEventsWeekdays">
          <div class="sun">Sun</div>
          <div>Mon</div>
          <div>Tue</div>
          <div>Wed</div>
          <div>Thu</div>
          <div>Fri</div>
          <div class="sat">Sat</div>
        </div>

        <div class="storeEventsGrid">
          <?php for ($i = 0; $i < 42; $i++):
            $date = $gridStart->modify('+' . $i . ' days');
            $ymd = $date->format('Y-m-d');
            $dow = (int)$date->format('w');
            $dayRows = $eventsByDate[$ymd] ?? [];
            $classes = ['storeEventsDay'];
            if ($date->format('Y-m') !== $month) $classes[] = 'is-outside';
            if ($ymd === $todayYmd) $classes[] = 'is-today';
            if ($ymd === $selectedDate) $classes[] = 'is-selected';
            $numClass = 'storeEventsDay__num';
            if ($dow === 0) $numClass .= ' sun';
            if ($dow === 6) $numClass .= ' sat';
          ?>
            <a class="<?=h(implode(' ', $classes))?>" href="<?=h(build_store_events_url($storeId, ['tab' => 'internal', 'month' => $date->format('Y-m'), 'selected_date' => $ymd]))?>">
              <div class="<?=h($numClass)?>"><?= (int)$date->format('j') ?></div>
              <div class="storeEventsDay__chips">
                <?php foreach (array_slice($dayRows, 0, 3) as $row): ?>
                  <?php $isLinked = is_linked_external_event($row); ?>
                  <?php $eventType = internal_event_type($row); ?>
                  <?php if ($isLinked && is_cancelled_internal_event($row)) continue; ?>
                  <span class="storeEventsChip <?=h(trim(($isLinked ? 'is-linked ' : '') . internal_event_type_class($eventType)))?>">
                    <?php if ($isLinked): ?>
                      <span
                        class="storeEventsChip__icon"
                        aria-hidden="true"
                        title="<?=h(display_internal_event_title($row))?>"
                      >📝</span>
                    <?php else: ?>
                      <span class="storeEventsChip__time"><?=h(substr((string)$row['starts_at'], 11, 5))?></span>
                      <?php $bodyTitle = display_internal_event_body_title($row); ?>
                      <?php if ($bodyTitle !== ''): ?>
                        <span class="storeEventsChip__title"><?=h($bodyTitle)?></span>
                      <?php endif; ?>
                    <?php endif; ?>
                  </span>
                <?php endforeach; ?>
                <?php if (count($dayRows) > 3): ?>
                  <span class="storeEventsChip__more">+<?= count($dayRows) - 3 ?>件</span>
                <?php endif; ?>
              </div>
            </a>
          <?php endfor; ?>
        </div>
      </div>

      <aside class="storeEventsSidebar">
        <section class="storeEventsPanel">
          <div>
            <h2><?=h($selectedDateObj->format('Y年n月j日'))?></h2>
            <div class="storeEventsPanel__lead">選択日のイベント一覧です。入力は別画面に分けています。</div>
          </div>
          <a class="storeEventsBtn storeEventsBtn--primary" href="./new.php?store_id=<?=$storeId?>&date=<?=$selectedDate?>">この日に入力する</a>
          <div class="storeEventsList">
            <?php if ($selectedDateRows): ?>
              <?php foreach ($selectedDateRows as $row): ?>
                <?php $isLinked = is_linked_external_event($row); ?>
                <?php $eventType = internal_event_type($row); ?>
                <a class="storeEventsListItem <?= $isLinked ? 'is-linked' : '' ?>" href="./edit.php?store_id=<?=$storeId?>&id=<?= (int)$row['id'] ?>">
                  <?php if ($isLinked): ?>
                    <div class="storeEventsListItem__linkedMark">📝 連動イベント</div>
                  <?php endif; ?>
                  <?php if ($eventType !== ''): ?>
                    <div class="storeEventsTypeBadge <?=h(internal_event_type_class($eventType))?>"><?=h(internal_event_type_label($eventType))?></div>
                  <?php endif; ?>
                  <?php $bodyTitle = display_internal_event_body_title($row); ?>
                  <?php if ($bodyTitle !== ''): ?>
                    <div class="storeEventsListItem__title"><?=h($bodyTitle)?></div>
                  <?php endif; ?>
                  <div class="storeEventsListItem__meta">
                    <span><?=h(format_event_period((string)$row['starts_at'], (string)$row['ends_at']))?></span>
                    <span class="storeEventsBadge <?=h(store_event_status_class((string)$row['status']))?>"><?=h(store_event_status_label((string)$row['status']))?></span>
                  </div>
                </a>
                <div class="storeEventsListItem__actions">
                  <a class="storeEventsBtn" href="<?= h(build_cashier_new_url_for_event($storeId, $row)) ?>">このイベントで会計開始</a>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="storeEventsEmpty">この日のイベントはまだありません。</div>
            <?php endif; ?>
          </div>
        </section>

        <section class="storeEventsPanel">
          <div>
            <h3><?=h($monthStart->format('n月の予定候補'))?></h3>
            <div class="storeEventsPanel__lead">直近のイベントを確認しながら、月次予定を調整できます。</div>
          </div>
          <div class="storeEventsList">
            <?php if ($upcomingRows): ?>
              <?php foreach ($upcomingRows as $row): ?>
                <?php $isLinked = is_linked_external_event($row); ?>
                <?php $eventType = internal_event_type($row); ?>
                <a class="storeEventsListItem <?= $isLinked ? 'is-linked' : '' ?>" href="./edit.php?store_id=<?=$storeId?>&id=<?= (int)$row['id'] ?>">
                  <?php if ($isLinked): ?>
                    <div class="storeEventsListItem__linkedMark">📝 連動イベント</div>
                  <?php endif; ?>
                  <?php if ($eventType !== ''): ?>
                    <div class="storeEventsTypeBadge <?=h(internal_event_type_class($eventType))?>"><?=h(internal_event_type_label($eventType))?></div>
                  <?php endif; ?>
                  <?php $bodyTitle = display_internal_event_body_title($row); ?>
                  <?php if ($bodyTitle !== ''): ?>
                    <div class="storeEventsListItem__title"><?=h($bodyTitle)?></div>
                  <?php endif; ?>
                  <div class="storeEventsListItem__meta">
                    <span><?=h(format_event_period((string)$row['starts_at'], (string)$row['ends_at']))?></span>
                    <span class="storeEventsBadge <?=h(store_event_status_class((string)$row['status']))?>"><?=h(store_event_status_label((string)$row['status']))?></span>
                  </div>
                </a>
                <div class="storeEventsListItem__actions">
                  <a class="storeEventsBtn" href="<?= h(build_cashier_new_url_for_event($storeId, $row)) ?>">このイベントで会計開始</a>
                </div>
              <?php endforeach; ?>
            <?php else: ?>
              <div class="storeEventsEmpty">今後のイベントはまだありません。</div>
            <?php endif; ?>
          </div>
        </section>
      </aside>
    </section>

  <?php else: ?>
    <section class="storeEventsCard">
      <form method="get" class="storeEventsExternalFilter">
        <input type="hidden" name="store_id" value="<?=$storeId?>">
        <input type="hidden" name="tab" value="external">

        <label>
          <div>from</div>
          <input type="date" name="from" value="<?=h($from)?>">
        </label>
        <label>
          <div>to</div>
          <input type="date" name="to" value="<?=h($to)?>">
        </label>
        <label>
          <div>source</div>
          <select name="source">
            <option value="">全て</option>
            <?php foreach ($sources as $s): ?>
              <option value="<?=h((string)$s['source'])?>" <?=((string)$s['source'] === $source) ? 'selected' : ''?>>
                <?=h((string)$s['source'])?> (<?= (int)$s['c'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <div>検索</div>
          <input type="text" name="q" value="<?=h($q)?>" placeholder="タイトル / 会場 / 住所">
        </label>
        <label>
          <div>件数</div>
          <input type="number" name="limit" value="<?=$limit?>" min="20" max="300">
        </label>
        <button class="storeEventsBtn storeEventsBtn--primary" type="submit">絞り込み</button>
      </form>
    </section>

    <section class="storeEventsCard">
      <table class="storeEventsExternalTable">
        <thead>
          <tr>
            <th style="width:170px">期間</th>
            <th>外部イベント</th>
            <th style="width:150px">ソース</th>
            <th style="width:180px">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($externalRows as $r):
            $sa = (string)($r['starts_at'] ?? '');
            $ea = (string)($r['ends_at'] ?? '');
            $period = format_event_period($sa, $ea);
            $venue = trim((string)($r['venue_name'] ?? ''));
            $addr = trim((string)($r['venue_addr'] ?? ''));
            $srcUrl = (string)($r['source_url'] ?? '');
          ?>
            <tr>
              <td><?=h($period)?></td>
              <td>
                <div style="font-weight:700"><?=h((string)$r['title'])?></div>
                <?php if ($venue !== '' || $addr !== ''): ?>
                  <div style="color:#93a0c1;margin-top:6px"><?=h($venue)?><?= $addr !== '' ? ' / ' . h($addr) : '' ?></div>
                <?php endif; ?>
                <?php if ($srcUrl !== ''): ?>
                  <div style="margin-top:6px"><a href="<?=h($srcUrl)?>" target="_blank" rel="noopener">元ページ</a></div>
                <?php endif; ?>
              </td>
              <td>
                <span class="storeEventsBadge"><?=h((string)$r['source'])?></span>
                <div style="margin-top:6px;color:#93a0c1"><?=h((string)$r['source_id'])?></div>
              </td>
              <td><a class="storeEventsBtn storeEventsBtn--primary" href="./new.php?store_id=<?=$storeId?>&from_external_id=<?= (int)$r['id'] ?>">店内イベント化</a></td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$externalRows): ?>
            <tr><td colspan="4"><div class="storeEventsEmpty">該当する外部イベントはありません。</div></td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </section>
  <?php endif; ?>
</div>

<?php
if (function_exists('render_page_end')) {
  render_page_end();
} elseif (!function_exists('render_page_start')) {
  echo '</body></html>';
}
