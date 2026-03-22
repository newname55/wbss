<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/store.php';
if (is_file(__DIR__ . '/../../app/auth.php')) require_once __DIR__ . '/../../app/auth.php';
if (is_file(__DIR__ . '/../../app/layout.php')) require_once __DIR__ . '/../../app/layout.php';

if (function_exists('require_login')) require_login();
if (function_exists('require_role')) {
  require_role(['manager', 'admin', 'super_user']);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function format_datetime_local_value(?string $value): string {
  $raw = trim((string)$value);
  if ($raw === '') return '';
  try {
    return (new DateTimeImmutable($raw, new DateTimeZone('Asia/Tokyo')))->format('Y-m-d\TH:i');
  } catch (Throwable $e) {
    return '';
  }
}

function normalize_datetime_input(string $value): string {
  $raw = trim($value);
  if ($raw === '') return '';
  $raw = str_replace('T', ' ', $raw);
  if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $raw)) {
    $raw .= ':00';
  }
  return $raw;
}

function hm_from_time_value(?string $value, string $fallback): string {
  $value = trim((string)$value);
  if ($value === '') return $fallback;
  if (preg_match('/^\d{2}:\d{2}:\d{2}$/', $value)) return substr($value, 0, 5);
  if (preg_match('/^\d{2}:\d{2}$/', $value)) return $value;
  return $fallback;
}

function normalize_store_event_type(?string $value): string {
  $value = trim((string)$value);
  return in_array($value, ['', 'service_companion', 'forced_companion'], true) ? $value : '';
}

function store_event_type_label(string $type): string {
  return match ($type) {
    'service_companion' => 'サービス同伴',
    'forced_companion' => '強制同伴',
    default => '',
  };
}

function store_event_type_marker(string $type): string {
  return $type === '' ? '' : '[[event_type:' . $type . ']]';
}

function strip_store_event_type_marker(string $memo): string {
  $memo = preg_replace('/\[\[event_type:(service_companion|forced_companion)\]\]\s*/u', '', $memo);
  return trim((string)$memo);
}

function detect_store_event_type_from_memo(?string $memo): string {
  $memo = (string)$memo;
  if (preg_match('/\[\[event_type:(service_companion|forced_companion)\]\]/u', $memo, $m)) {
    return normalize_store_event_type($m[1] ?? '');
  }
  return '';
}

function apply_store_event_type_to_memo(string $memo, string $type): ?string {
  $clean = strip_store_event_type_marker($memo);
  $marker = store_event_type_marker($type);
  if ($marker !== '') {
    $clean = $marker . ($clean !== '' ? "\n" . $clean : '');
  }
  $clean = trim($clean);
  return $clean !== '' ? $clean : null;
}

function redirect_store_events_month(int $storeId, string $startsAt): never {
  $date = new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Tokyo'));
  $month = $date->format('Y-m');
  $selectedDate = $date->format('Y-m-d');
  header('Location: ./index.php?' . http_build_query([
    'store_id' => $storeId,
    'tab' => 'internal',
    'month' => $month,
    'selected_date' => $selectedDate,
  ]));
  exit;
}

$storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
if ($storeId <= 0) {
  $storeId = (int)current_store_id();
}
if ($storeId <= 0) { http_response_code(400); echo 'store_id required'; exit; }
set_current_store_id($storeId);

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$storeRule = [
  'business_day_start' => '06:00',
  'close_time_weekday' => '02:30',
  'close_time_weekend' => '05:00',
  'close_is_next_day_weekday' => 1,
  'close_is_next_day_weekend' => 1,
  'weekend_dow_mask' => 96,
];
try {
  $stStore = $pdo->prepare("
    SELECT business_day_start, close_time_weekday, close_time_weekend,
           close_is_next_day_weekday, close_is_next_day_weekend, weekend_dow_mask
      FROM stores
     WHERE id = ?
     LIMIT 1
  ");
  $stStore->execute([$storeId]);
  $storeRow = $stStore->fetch(PDO::FETCH_ASSOC) ?: [];
  $storeRule = [
    'business_day_start' => hm_from_time_value((string)($storeRow['business_day_start'] ?? ''), '06:00'),
    'close_time_weekday' => hm_from_time_value((string)($storeRow['close_time_weekday'] ?? ''), '02:30'),
    'close_time_weekend' => hm_from_time_value((string)($storeRow['close_time_weekend'] ?? ''), '05:00'),
    'close_is_next_day_weekday' => (int)($storeRow['close_is_next_day_weekday'] ?? 1),
    'close_is_next_day_weekend' => (int)($storeRow['close_is_next_day_weekend'] ?? 1),
    'weekend_dow_mask' => (int)($storeRow['weekend_dow_mask'] ?? 96),
  ];
} catch (Throwable $e) {
}

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (!isset($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
$csrf = $_SESSION['csrf_token'];

function verify_csrf(string $token): void {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $ok = isset($_SESSION['csrf_token']) && hash_equals((string)$_SESSION['csrf_token'], $token);
  if (!$ok) { http_response_code(400); echo 'CSRF token invalid'; exit; }
}

$fromExternalId = (int)($_GET['from_external_id'] ?? $_POST['from_external_id'] ?? 0);
$ext = null;
if ($fromExternalId > 0) {
  $st = $pdo->prepare('SELECT * FROM store_external_events WHERE id = :id AND store_id = :sid LIMIT 1');
  $st->execute(['id' => $fromExternalId, 'sid' => $storeId]);
  $ext = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$datePreset = trim((string)($_GET['date'] ?? $_POST['date'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $datePreset)) {
  $datePreset = '';
}

$title = '';
$startsAt = '';
$endsAt = '';
$budget = '';
$memo = '';
$status = 'draft';
$eventType = '';

if ($ext) {
  $title = '【連動】' . (string)$ext['title'];
  $startsAt = (string)($ext['starts_at'] ?? '');
  $endsAt = (string)($ext['ends_at'] ?? '');

  if ($startsAt !== '' && $endsAt === '') {
    $d = new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Tokyo'));
    $endsAt = $d->format('Y-m-d 23:59:59');
  }

  $venue = trim((string)($ext['venue_name'] ?? ''));
  $addr = trim((string)($ext['venue_addr'] ?? ''));
  $src = trim((string)($ext['source'] ?? ''));
  $url = trim((string)($ext['source_url'] ?? ''));

  $memoParts = [];
  $memoParts[] = '外部イベント連動: ' . $src;
  if ($venue !== '') $memoParts[] = '会場: ' . $venue;
  if ($addr !== '') $memoParts[] = '住所: ' . $addr;
  if ($url !== '') $memoParts[] = '元URL: ' . $url;
  $memo = implode("\n", $memoParts);
} else {
  $baseDate = $datePreset !== ''
    ? new DateTimeImmutable($datePreset . ' 21:00:00', new DateTimeZone('Asia/Tokyo'))
    : new DateTimeImmutable('today 21:00:00', new DateTimeZone('Asia/Tokyo'));
  $startsAt = $baseDate->format('Y-m-d H:i:s');
  $endsAt = $baseDate->modify('+2 hours')->format('Y-m-d H:i:s');
}

$createdId = 0;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf((string)($_POST['csrf_token'] ?? ''));

  $eventType = normalize_store_event_type((string)($_POST['event_type'] ?? ''));
  $title = trim((string)($_POST['title'] ?? ''));
  $startsAt = normalize_datetime_input((string)($_POST['starts_at'] ?? ''));
  $endsAt = normalize_datetime_input((string)($_POST['ends_at'] ?? ''));
  $budget = trim((string)($_POST['budget_yen'] ?? ''));
  $memo = trim((string)($_POST['memo'] ?? ''));
  $status = trim((string)($_POST['status'] ?? 'draft'));
  if (!in_array($status, ['draft', 'scheduled', 'confirmed', 'cancelled'], true)) {
    $status = 'draft';
  }
  if ($eventType !== '') {
    $title = store_event_type_label($eventType);
  }

  if ($title === '' || mb_strlen($title) > 120) $error = 'タイトルは1〜120文字で入力してください。';
  if ($error === '' && $startsAt === '') $error = '開始日時を入力してください。';
  if ($error === '' && $endsAt === '') $error = '終了日時を入力してください。';

  $sa = null;
  $ea = null;
  if ($error === '') {
    try { $sa = new DateTimeImmutable($startsAt, new DateTimeZone('Asia/Tokyo')); } catch (Throwable $e) { $error = '開始日時の形式が不正です。'; }
  }
  if ($error === '') {
    try { $ea = new DateTimeImmutable($endsAt, new DateTimeZone('Asia/Tokyo')); } catch (Throwable $e) { $error = '終了日時の形式が不正です。'; }
  }
  if ($error === '' && $sa && $ea && $ea < $sa) $error = '終了日時が開始日時より前です。';

  $budgetVal = null;
  if ($error === '' && $budget !== '') {
    $budgetVal = (int)preg_replace('/[^\d]/', '', $budget);
    if ($budgetVal < 0) $budgetVal = 0;
  }

  if ($error === '' && $sa && $ea) {
    $createdBy = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("
        INSERT INTO store_event_instances
          (store_id, template_id, title, status, starts_at, ends_at, budget_yen, owner_user_id, memo, created_by, updated_by, created_at, updated_at)
        VALUES
          (:sid, NULL, :title, :status, :sa, :ea, :budget, :owner, :memo, :cb, :ub, NOW(), NOW())
      ");
      $st->execute([
        'sid' => $storeId,
        'title' => $title,
        'status' => $status,
        'sa' => $sa->format('Y-m-d H:i:s'),
        'ea' => $ea->format('Y-m-d H:i:s'),
        'budget' => $budgetVal,
        'owner' => $createdBy > 0 ? $createdBy : null,
        'memo' => apply_store_event_type_to_memo($memo, $eventType),
        'cb' => $createdBy > 0 ? $createdBy : null,
        'ub' => $createdBy > 0 ? $createdBy : null,
      ]);

      $createdId = (int)$pdo->lastInsertId();

      try {
        $st2 = $pdo->prepare("
          INSERT INTO store_event_audit_logs
            (store_id, actor_user_id, action, entity_type, entity_id, summary, detail_json, created_at)
          VALUES
            (:sid, :uid, 'instance.create', 'instance', :eid, :sum, :json, NOW())
        ");
        $st2->execute([
          'sid' => $storeId,
          'uid' => $createdBy > 0 ? $createdBy : null,
          'eid' => $createdId,
          'sum' => 'created: ' . $title,
          'json' => json_encode([
            'from_external_id' => $fromExternalId ?: null,
            'starts_at' => $sa->format('Y-m-d H:i:s'),
            'ends_at' => $ea->format('Y-m-d H:i:s'),
            'status' => $status,
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
      } catch (Throwable $e) {
      }

      $pdo->commit();
      redirect_store_events_month($storeId, $sa->format('Y-m-d H:i:s'));
    } catch (Throwable $e) {
      $pdo->rollBack();
      $error = '作成に失敗しました: ' . $e->getMessage();
    }
  }
}

$pageTitle = '店内イベント入力';
if (function_exists('render_page_start')) {
  render_page_start($pageTitle);
}
if (function_exists('render_header')) {
  render_header($pageTitle);
} elseif (!function_exists('render_page_start')) {
  ?><!doctype html><html lang="ja"><head>
  <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?=h($pageTitle)?></title>
  </head><body><?php
}
?>
<style>
  .storeEventFormPage{
    max-width:980px;
    margin:0 auto;
    padding:20px 16px 40px;
    display:grid;
    gap:16px;
  }
  .storeEventFormHero,
  .storeEventFormCard{
    border-radius:22px;
    border:1px solid rgba(255,255,255,.12);
    background:linear-gradient(180deg, rgba(9,14,27,.96), rgba(13,19,37,.88));
    box-shadow:0 22px 50px rgba(0,0,0,.22);
  }
  .storeEventFormHero{
    padding:22px;
    display:flex;
    flex-wrap:wrap;
    gap:16px;
    justify-content:space-between;
    align-items:flex-end;
  }
  .storeEventFormHero h1{
    margin:0;
    font-size:32px;
  }
  .storeEventFormHero__sub{
    margin-top:8px;
    color:#93a0c1;
    font-size:14px;
  }
  .storeEventFormCard{
    padding:20px;
    display:grid;
    gap:16px;
  }
  .storeEventFormGrid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:14px;
  }
  .storeEventField{
    display:grid;
    gap:8px;
  }
  .storeEventField--full{
    grid-column:1 / -1;
  }
  .storeEventField label{
    color:#dce5fb;
    font-weight:700;
    font-size:14px;
  }
  .storeEventField input,
  .storeEventField textarea,
  .storeEventField select{
    width:100%;
    min-height:46px;
    padding:12px 14px;
    border-radius:14px;
    border:1px solid rgba(255,255,255,.18);
    background:rgba(255,255,255,.09);
    color:#f7faff;
    font-weight:600;
    line-height:1.5;
    box-sizing:border-box;
    caret-color:#47c2ff;
    -webkit-text-fill-color:#f7faff;
  }
  .storeEventField textarea{
    min-height:150px;
    resize:vertical;
  }
  .storeEventField input::placeholder,
  .storeEventField textarea::placeholder{
    color:rgba(229,237,255,.52);
    -webkit-text-fill-color:rgba(229,237,255,.52);
  }
  .storeEventField input:focus,
  .storeEventField textarea:focus,
  .storeEventField select:focus{
    outline:none;
    border-color:rgba(71,194,255,.72);
    background:rgba(255,255,255,.13);
    box-shadow:0 0 0 4px rgba(21,184,255,.14);
  }
  .storeEventField select{
    appearance:none;
    background-image:
      linear-gradient(45deg, transparent 50%, #dfe9ff 50%),
      linear-gradient(135deg, #dfe9ff 50%, transparent 50%);
    background-position:
      calc(100% - 20px) calc(50% - 2px),
      calc(100% - 14px) calc(50% - 2px);
    background-size:6px 6px, 6px 6px;
    background-repeat:no-repeat;
    padding-right:40px;
  }
  .storeEventField select option{
    color:#0b1120;
    background:#f4f7ff;
  }
  .storeEventField input[type="datetime-local"]::-webkit-calendar-picker-indicator{
    filter:invert(1) brightness(1.6);
    opacity:.9;
    cursor:pointer;
  }
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit,
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-fields-wrapper,
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-text,
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-month-field,
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-day-field,
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-year-field,
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-hour-field,
  .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-minute-field{
    color:#f7faff;
  }
  .storeEventHint{
    color:#b4c0dd;
    font-size:12px;
    line-height:1.5;
  }
  .storeEventQuickActions{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
    align-items:center;
  }
  .storeEventQuickBtn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:38px;
    padding:0 14px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.16);
    background:rgba(255,255,255,.05);
    color:#eaf2ff;
    cursor:pointer;
    font-weight:700;
  }
  .storeEventTypeButtons{
    display:flex;
    flex-wrap:wrap;
    gap:10px;
  }
  .storeEventTypeBtn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:42px;
    padding:0 16px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.16);
    background:rgba(255,255,255,.05);
    color:#eaf2ff;
    cursor:pointer;
    font-weight:700;
  }
  .storeEventTypeBtn.is-service{ background:rgba(255,173,72,.16); color:#ffd79c; border-color:rgba(255,173,72,.34); }
  .storeEventTypeBtn.is-forced{ background:rgba(255,88,121,.16); color:#ffc0cc; border-color:rgba(255,88,121,.34); }
  .storeEventTypeBtn.is-clear{ background:rgba(255,255,255,.05); }
  .storeEventTypeBtn.is-active{
    box-shadow:0 0 0 4px rgba(255,255,255,.08);
    transform:translateY(-1px);
  }
  .storeEventActions{
    display:flex;
    flex-wrap:wrap;
    justify-content:flex-end;
    gap:10px;
  }
  .storeEventBtn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    min-height:44px;
    padding:0 16px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.14);
    text-decoration:none;
    color:#eef3ff;
    background:rgba(255,255,255,.04);
  }
  .storeEventBtn--primary{
    background:linear-gradient(135deg, #15b8ff, #2970ff);
    border-color:transparent;
    color:#06111d;
    font-weight:700;
  }
  .storeEventError{
    padding:14px 16px;
    border-radius:16px;
    background:rgba(255,86,102,.15);
    border:1px solid rgba(255,86,102,.32);
    color:#ffd3d7;
  }
  body[data-theme="light"] .storeEventFormHero,
  body[data-theme="light"] .storeEventFormCard{
    border:1px solid rgba(25,35,58,.08);
    background:linear-gradient(180deg, #ffffff, #f6f8fd);
    box-shadow:0 20px 44px rgba(15,23,42,.12);
  }
  body[data-theme="light"] .storeEventFormHero h1,
  body[data-theme="light"] .storeEventField label{
    color:#162033;
  }
  body[data-theme="light"] .storeEventFormHero__sub{
    color:#4f5f7b;
  }
  body[data-theme="light"] .storeEventField input,
  body[data-theme="light"] .storeEventField textarea,
  body[data-theme="light"] .storeEventField select{
    border:1px solid #cfd8ea;
    background:#ffffff;
    color:#172133;
    -webkit-text-fill-color:#172133;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.75);
  }
  body[data-theme="light"] .storeEventField input::placeholder,
  body[data-theme="light"] .storeEventField textarea::placeholder{
    color:#7c8aa5;
    -webkit-text-fill-color:#7c8aa5;
  }
  body[data-theme="light"] .storeEventField input:focus,
  body[data-theme="light"] .storeEventField textarea:focus,
  body[data-theme="light"] .storeEventField select:focus{
    border-color:#2b9af3;
    background:#ffffff;
    box-shadow:0 0 0 4px rgba(43,154,243,.16);
  }
  body[data-theme="light"] .storeEventField select{
    background-image:
      linear-gradient(45deg, transparent 50%, #35507a 50%),
      linear-gradient(135deg, #35507a 50%, transparent 50%);
  }
  body[data-theme="light"] .storeEventField select option{
    color:#172133;
    background:#ffffff;
  }
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-calendar-picker-indicator{
    filter:none;
    opacity:.8;
  }
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit,
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-fields-wrapper,
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-text,
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-month-field,
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-day-field,
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-year-field,
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-hour-field,
  body[data-theme="light"] .storeEventField input[type="datetime-local"]::-webkit-datetime-edit-minute-field{
    color:#172133;
  }
  body[data-theme="light"] .storeEventHint{
    color:#5f6f8c;
  }
  body[data-theme="light"] .storeEventQuickBtn{
    color:#23405f;
    background:#ffffff;
    border-color:#d5dceb;
  }
  body[data-theme="light"] .storeEventTypeBtn{
    color:#23405f;
    background:#ffffff;
    border-color:#d5dceb;
  }
  body[data-theme="light"] .storeEventTypeBtn.is-service{
    background:#fff3e4;
    color:#b06100;
    border-color:#ffd19a;
  }
  body[data-theme="light"] .storeEventTypeBtn.is-forced{
    background:#ffe8ee;
    color:#bd3258;
    border-color:#ffb7c9;
  }
  body[data-theme="light"] .storeEventTypeBtn.is-active{
    box-shadow:0 0 0 4px rgba(43,154,243,.12);
  }
  body[data-theme="light"] .storeEventBtn{
    color:#25324a;
    background:#ffffff;
    border-color:#d5dceb;
  }
  body[data-theme="light"] .storeEventBtn--primary{
    color:#ffffff;
    background:linear-gradient(135deg, #1792f2, #2563eb);
    border-color:transparent;
  }
  body[data-theme="light"] .storeEventError{
    background:#fff1f3;
    border-color:#f3b9c2;
    color:#9f2438;
  }
  @media (max-width: 760px){
    .storeEventFormGrid{ grid-template-columns:1fr; }
  }
</style>

<div class="storeEventFormPage">
  <section class="storeEventFormHero">
    <div>
      <h1><?=h($pageTitle)?></h1>
      <div class="storeEventFormHero__sub">PCの月間カレンダーから遷移して、ここで日時と内容を入力します。</div>
    </div>
    <a class="storeEventBtn" href="./index.php?store_id=<?=$storeId?>&tab=internal<?= $datePreset !== '' ? '&month=' . h(substr($datePreset, 0, 7)) . '&selected_date=' . h($datePreset) : '' ?>">一覧へ戻る</a>
  </section>

  <?php if ($ext): ?>
    <section class="storeEventFormCard">
      <div>
        <strong>外部イベントからプリセット</strong>
        <div class="storeEventHint" style="margin-top:8px">
          <?=h((string)$ext['title'])?> / <?=h((string)$ext['source'])?>
          <?php if (!empty($ext['source_url'])): ?>
            / <a href="<?=h((string)$ext['source_url'])?>" target="_blank" rel="noopener">元ページ</a>
          <?php endif; ?>
        </div>
      </div>
    </section>
  <?php endif; ?>

  <?php if ($error !== ''): ?>
    <div class="storeEventError"><?=h($error)?></div>
  <?php endif; ?>

  <section class="storeEventFormCard">
    <form method="post">
      <input type="hidden" name="store_id" value="<?=$storeId?>">
      <input type="hidden" name="from_external_id" value="<?=$fromExternalId?>">
      <input type="hidden" name="date" value="<?=h($datePreset)?>">
      <input type="hidden" name="event_type" id="event_type" value="<?=h($eventType)?>">
      <input type="hidden" name="csrf_token" value="<?=h($csrf)?>">

      <div class="storeEventFormGrid">
        <div class="storeEventField storeEventField--full">
          <label>イベント種別</label>
          <div class="storeEventTypeButtons">
            <button type="button" class="storeEventTypeBtn is-clear" data-event-type="">通常イベント</button>
            <button type="button" class="storeEventTypeBtn is-service" data-event-type="service_companion">サービス同伴</button>
            <button type="button" class="storeEventTypeBtn is-forced" data-event-type="forced_companion">強制同伴</button>
          </div>
          <div class="storeEventHint">同伴系はボタン選択でタイトルを自動入力し、通常イベントは自由入力に戻せます。</div>
        </div>

        <div class="storeEventField storeEventField--full">
          <label for="title">タイトル</label>
          <input id="title" name="title" value="<?=h($title)?>" maxlength="120" required>
        </div>

        <div class="storeEventField">
          <label for="starts_at">開始日時</label>
          <input id="starts_at" type="datetime-local" name="starts_at" value="<?=h(format_datetime_local_value($startsAt))?>" required>
          <div class="storeEventHint">カレンダーの選択日が初期値に入ります。</div>
        </div>

        <div class="storeEventField">
          <label for="ends_at">終了日時</label>
          <input id="ends_at" type="datetime-local" name="ends_at" value="<?=h(format_datetime_local_value($endsAt))?>" required>
          <div class="storeEventHint">同日イベントなら終了時刻だけ調整すればOKです。</div>
        </div>

        <div class="storeEventField storeEventField--full">
          <label>営業日終端</label>
          <div class="storeEventQuickActions">
            <button
              type="button"
              class="storeEventQuickBtn"
              id="fillBusinessDayBtn"
              data-business-day-start="<?=h($storeRule['business_day_start'])?>"
              data-close-weekday="<?=h($storeRule['close_time_weekday'])?>"
              data-close-weekend="<?=h($storeRule['close_time_weekend'])?>"
              data-next-weekday="<?= (int)$storeRule['close_is_next_day_weekday'] ?>"
              data-next-weekend="<?= (int)$storeRule['close_is_next_day_weekend'] ?>"
              data-weekend-mask="<?= (int)$storeRule['weekend_dow_mask'] ?>"
            >営業日ずっとにする</button>
            <span class="storeEventHint">開始日時を基準に、その営業日の閉店時刻まで終了日時を自動入力します。</span>
          </div>
        </div>

        <div class="storeEventField">
          <label for="status">状態</label>
          <select id="status" name="status">
            <option value="draft" <?=$status === 'draft' ? 'selected' : ''?>>下書き</option>
            <option value="scheduled" <?=$status === 'scheduled' ? 'selected' : ''?>>予定</option>
            <option value="confirmed" <?=$status === 'confirmed' ? 'selected' : ''?>>確定</option>
            <option value="cancelled" <?=$status === 'cancelled' ? 'selected' : ''?>>中止</option>
          </select>
        </div>

        <div class="storeEventField">
          <label for="budget_yen">予算</label>
          <input id="budget_yen" name="budget_yen" value="<?=h($budget)?>" placeholder="例: 20000">
        </div>

        <div class="storeEventField storeEventField--full">
          <label for="memo">メモ</label>
          <textarea id="memo" name="memo"><?=h($memo)?></textarea>
          <div class="storeEventHint">運用メモ、依頼事項、外部イベントの補足などを残せます。</div>
        </div>
      </div>

      <div class="storeEventActions">
        <a class="storeEventBtn" href="./index.php?store_id=<?=$storeId?>&tab=internal<?= $datePreset !== '' ? '&month=' . h(substr($datePreset, 0, 7)) . '&selected_date=' . h($datePreset) : '' ?>">キャンセル</a>
        <button class="storeEventBtn storeEventBtn--primary" type="submit">イベントを保存</button>
      </div>
    </form>
  </section>
</div>

<script>
(() => {
  const eventTypeInput = document.getElementById('event_type');
  const titleInput = document.getElementById('title');
  const typeButtons = Array.from(document.querySelectorAll('.storeEventTypeBtn[data-event-type]'));
  const startInput = document.getElementById('starts_at');
  const endInput = document.getElementById('ends_at');
  const fillBtn = document.getElementById('fillBusinessDayBtn');
  if (!startInput || !endInput || !fillBtn || !eventTypeInput || !titleInput) return;

  const typeLabels = {
    service_companion: 'サービス同伴',
    forced_companion: '強制同伴',
  };

  const syncEventTypeUi = () => {
    const currentType = eventTypeInput.value;
    typeButtons.forEach((button) => {
      button.classList.toggle('is-active', button.dataset.eventType === currentType);
    });
    if (currentType && typeLabels[currentType]) {
      titleInput.value = typeLabels[currentType];
      titleInput.readOnly = true;
    } else {
      titleInput.readOnly = false;
    }
  };

  typeButtons.forEach((button) => {
    button.addEventListener('click', () => {
      eventTypeInput.value = button.dataset.eventType || '';
      syncEventTypeUi();
      titleInput.focus();
    });
  });
  syncEventTypeUi();

  const parseHm = (value, fallback) => {
    const raw = String(value || '').trim();
    if (/^\d{2}:\d{2}$/.test(raw)) return raw;
    return fallback;
  };

  const toDate = (value) => {
    if (!value) return null;
    const match = String(value).match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/);
    if (!match) return null;
    const y = Number(match[1]);
    const m = Number(match[2]);
    const d = Number(match[3]);
    const hh = Number(match[4]);
    const mm = Number(match[5]);
    const date = new Date(y, m - 1, d, hh, mm, 0, 0);
    return Number.isNaN(date.getTime()) ? null : date;
  };

  const formatLocal = (date) => {
    const pad = (n) => String(n).padStart(2, '0');
    return [
      date.getFullYear(),
      '-',
      pad(date.getMonth() + 1),
      '-',
      pad(date.getDate()),
      'T',
      pad(date.getHours()),
      ':',
      pad(date.getMinutes()),
    ].join('');
  };

  fillBtn.addEventListener('click', () => {
    const startDate = toDate(startInput.value);
    if (!startDate) return;

    const businessDayStart = parseHm(fillBtn.dataset.businessDayStart, '06:00');
    const closeWeekday = parseHm(fillBtn.dataset.closeWeekday, '02:30');
    const closeWeekend = parseHm(fillBtn.dataset.closeWeekend, '05:00');
    const nextWeekday = Number(fillBtn.dataset.nextWeekday || '1') === 1;
    const nextWeekend = Number(fillBtn.dataset.nextWeekend || '1') === 1;
    const weekendMask = Number(fillBtn.dataset.weekendMask || '96');

    const [bizHour, bizMinute] = businessDayStart.split(':').map(Number);
    const businessDate = new Date(startDate.getTime());
    if (startDate.getHours() < bizHour || (startDate.getHours() === bizHour && startDate.getMinutes() < bizMinute)) {
      businessDate.setDate(businessDate.getDate() - 1);
    }
    businessDate.setHours(0, 0, 0, 0);

    const dow = businessDate.getDay();
    const dowBit = 1 << dow;
    const isWeekend = (weekendMask & dowBit) === dowBit;
    const closeHm = isWeekend ? closeWeekend : closeWeekday;
    const closeNextDay = isWeekend ? nextWeekend : nextWeekday;
    const [closeHour, closeMinute] = closeHm.split(':').map(Number);

    const endDate = new Date(businessDate.getTime());
    if (closeNextDay) endDate.setDate(endDate.getDate() + 1);
    endDate.setHours(closeHour, closeMinute, 0, 0);
    if (endDate.getTime() < startDate.getTime()) {
      endDate.setDate(endDate.getDate() + 1);
    }

    endInput.value = formatLocal(endDate);
    endInput.dispatchEvent(new Event('change', { bubbles: true }));
  });
})();
</script>

<?php
if (function_exists('render_page_end')) {
  render_page_end();
} elseif (!function_exists('render_page_start')) {
  echo '</body></html>';
}
