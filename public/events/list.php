<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

/**
 * public/events/list.php
 * haruto_core.events を一覧・検索する（layout.php に統一）
 */

$root = dirname(__DIR__, 2); // /var/www/html/wbss

require_once $root . '/app/auth.php';
require_once $root . '/app/db.php';
require_once $root . '/app/layout.php';
require_once $root . '/app/store.php';

if (function_exists('require_login')) {
  require_login();
}

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && is_array($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$userId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');
$isCast    = has_role('cast');

// cast専用へ（dashboard.php と同じ方針）
if ($isCast && !$isAdmin && !$isManager && !$isSuper) {
  header('Location: /wbss/public/dashboard_cast.php');
  exit;
}

// ✅ super_user は「未選択なら店舗選択を挟む」
if (function_exists('require_store_selected_for_super')) {
  require_store_selected_for_super($isSuper, '/wbss/public/events/list.php');
}

/**
 * ✅ 店舗一覧の方針
 * - super_user: 全店
 * - admin: 全店
 * - manager: 自分に紐づく店だけ
 */
$stores = [];
if ($isSuper || $isAdmin) {
  $stores = $pdo->query("SELECT id,name FROM stores WHERE is_active=1 ORDER BY id ASC")
                ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} elseif ($isManager && function_exists('repo_allowed_stores')) {
  $stores = repo_allowed_stores($pdo, $userId, false);
}

// store_id 決定（GET優先 → セッション → 先頭）
$storeId = 0;
if ($stores) {
  $candidate = (int)($_GET['store_id'] ?? 0);
  if ($candidate <= 0 && function_exists('get_current_store_id')) $candidate = (int)get_current_store_id();
  if ($candidate <= 0) $candidate = (int)$stores[0]['id'];

  $allowedIds = array_map(fn($s)=>(int)$s['id'], $stores);
  if (!in_array($candidate, $allowedIds, true)) $candidate = (int)$stores[0]['id'];

  $storeId = $candidate;

  if (function_exists('set_current_store_id')) {
    set_current_store_id($storeId);
  } else {
    $_SESSION['store_id'] = $storeId;
  }
}

// 店舗名
$storeName = '';
foreach ($stores as $s) {
  if ((int)$s['id'] === $storeId) { $storeName = (string)$s['name']; break; }
}

// ---- 入力系ヘルパ ----
function ymd(?string $s): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $s)) return null;
  return $s;
}
function strq(?string $s, int $max=200): ?string {
  $s = trim((string)$s);
  if ($s === '') return null;
  if (mb_strlen($s) > $max) $s = mb_substr($s, 0, $max);
  return $s;
}

// ---- 検索パラメータ ----
$from  = ymd($_GET['from'] ?? '') ?? (new DateTimeImmutable('today'))->format('Y-m-d');
$to    = ymd($_GET['to'] ?? '')   ?? (new DateTimeImmutable('today'))->modify('+60 days')->format('Y-m-d');
$venue = strq($_GET['venue'] ?? '');
$org   = strq($_GET['org'] ?? '');
$q     = strq($_GET['q'] ?? '', 300);

$page  = max(1, (int)($_GET['page'] ?? 1));
$limit = max(10, min(200, (int)($_GET['limit'] ?? 50)));
$offset = ($page - 1) * $limit;

// ---- WHERE 組み立て ----
$where = [];
$params = [];



// 日付範囲（starts_atがあるものだけ）
$where[] = "e.starts_at IS NOT NULL";
$where[] = "e.starts_at >= :from_dt";
$where[] = "e.starts_at <  :to_dt";
$params['from_dt'] = $from . ' 00:00:00';
$params['to_dt']   = (new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';

// 部分一致（プレースホルダは全部ユニーク）
if ($venue !== null && $venue !== '') {
  $where[] = "(e.venue_name LIKE :venue_kw1 OR e.address LIKE :venue_kw2 OR e.city LIKE :venue_kw3 OR e.prefecture LIKE :venue_kw4)";
  $params['venue_kw1'] = '%' . $venue . '%';
  $params['venue_kw2'] = '%' . $venue . '%';
  $params['venue_kw3'] = '%' . $venue . '%';
  $params['venue_kw4'] = '%' . $venue . '%';
}
if ($org !== null && $org !== '') {
  $where[] = "(e.organizer_name LIKE :org_kw1 OR e.contact_name LIKE :org_kw2)";
  $params['org_kw1'] = '%' . $org . '%';
  $params['org_kw2'] = '%' . $org . '%';
}
if ($q !== null && $q !== '') {
  $where[] = "(e.title LIKE :q_kw1 OR e.description LIKE :q_kw2 OR e.source_url LIKE :q_kw3)";
  $params['q_kw1'] = '%' . $q . '%';
  $params['q_kw2'] = '%' . $q . '%';
  $params['q_kw3'] = '%' . $q . '%';
}

$sql_where = 'WHERE ' . implode(' AND ', $where);

// ---- 件数 ----
$sql_count = "SELECT COUNT(*) FROM events e $sql_where";
$st = $pdo->prepare($sql_count);
$st->execute($params);
$total = (int)$st->fetchColumn();
$pages = (int)ceil(max(1, $total) / $limit);
if ($page > $pages) $page = $pages;

// ---- 一覧 ----
$sql = "SELECT
          e.event_id, e.title, e.starts_at, e.ends_at, e.all_day, e.status,
          e.venue_name, e.address,
          e.organizer_name, e.contact_name,
          e.source_url,
          s.name AS source_name
        FROM events e
        JOIN event_sources s ON s.source_id = e.source_id
        $sql_where
        ORDER BY e.starts_at ASC, e.event_id ASC
        LIMIT $limit OFFSET $offset";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);
// 最終更新情報（管理用）
$sql_meta = "
  SELECT
    MAX(updated_at) AS last_updated,
    COUNT(*)        AS total_events
  FROM events
";
$meta = $pdo->query($sql_meta)->fetch(PDO::FETCH_ASSOC);

$last_updated = $meta['last_updated'] ?? null;
$total_events = (int)($meta['total_events'] ?? 0);

// ---- URL組み立て（ページャ用） ----
function build_qs(array $overrides = []): string {
  $q = $_GET;
  foreach ($overrides as $k => $v) {
    if ($v === null) unset($q[$k]);
    else $q[$k] = $v;
  }
  return http_build_query($q);
}

function format_event_datetime(?string $startAt, ?string $endAt, int $allDay): array {
  if (!$startAt) {
    return ['label' => '日時未設定', 'detail' => ''];
  }

  $start = new DateTimeImmutable($startAt);
  $label = $start->format('Y/m/d');

  if ($allDay === 1) {
    $label .= ' 終日';
  } else {
    $label .= ' ' . $start->format('H:i');
    if ($endAt) {
      $end = new DateTimeImmutable($endAt);
      $label .= ' - ' . $end->format('H:i');
    }
  }

  $detail = $start->format('D');
  return ['label' => $label, 'detail' => $detail];
}

function status_badge_label(?string $status): string {
  $status = trim((string)$status);
  if ($status === '') return '公開中';

  $map = [
    'published' => '公開中',
    'open' => '公開中',
    'draft' => '下書き',
    'cancelled' => '中止',
    'canceled' => '中止',
    'postponed' => '延期',
    'closed' => '終了',
  ];

  return $map[strtolower($status)] ?? $status;
}

$activeFilters = [];
if ($venue !== null && $venue !== '') $activeFilters[] = '開催場所: ' . $venue;
if ($org !== null && $org !== '') $activeFilters[] = '主催者: ' . $org;
if ($q !== null && $q !== '') $activeFilters[] = 'キーワード: ' . $q;
if ($limit !== 50) $activeFilters[] = '表示件数: ' . $limit . '件';
$hasExpandedFilters = !empty($activeFilters);

render_page_start('イベント一覧');

$right = '';
if ($last_updated) {
  $right .= '<span class="pill">最終更新(DB): ' . h((string)$last_updated) . '</span>';
}

render_header('岡山県のイベント一覧', [
  'active' => 'events',
  'back_href' => '/wbss/public/dashboard.php',
  'right_html' => $right,
]);

?>
<div class="container">
<div class="page">
  <style>
    .events-page{display:grid;gap:16px;}
    .events-hero{display:grid;gap:12px;}
    .events-hero__meta{display:flex;gap:10px;flex-wrap:wrap;align-items:center;}
    .events-summary{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;}
    .events-summary__card{
      padding:14px 16px;
      border:1px solid var(--line);
      border-radius:16px;
      background:rgba(255,255,255,.03);
    }
    .events-summary__label{font-size:12px;color:var(--muted);margin-bottom:6px;}
    .events-summary__value{font-size:24px;font-weight:800;line-height:1.1;}
    .events-filter{display:grid;gap:14px;}
    .events-filter__top{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-start;justify-content:space-between;}
    .events-filter__intro{display:grid;gap:8px;}
    .events-filter__active{display:flex;gap:8px;flex-wrap:wrap;}
    .events-filter__active-label{font-size:12px;color:var(--muted);padding-top:7px;}
    .events-filter__collapse{
      border:1px solid var(--line);
      border-radius:20px;
      background:rgba(255,255,255,.02);
      overflow:hidden;
    }
    .events-filter__collapse[open]{
      background:rgba(255,255,255,.03);
    }
    .events-filter__summary{
      list-style:none;
      cursor:pointer;
      padding:16px;
      display:flex;
      gap:12px;
      align-items:center;
      justify-content:space-between;
    }
    .events-filter__summary::-webkit-details-marker{display:none;}
    .events-filter__summary-main{display:grid;gap:8px;min-width:0;flex:1;}
    .events-filter__summary-title{display:flex;gap:10px;align-items:center;flex-wrap:wrap;}
    .events-filter__summary-title strong{font-size:17px;}
    .events-filter__summary-sub{display:flex;gap:8px;flex-wrap:wrap;min-width:0;}
    .events-filter__toggle{
      display:inline-flex;align-items:center;gap:8px;
      font-size:13px;color:var(--muted);white-space:nowrap;
    }
    .events-filter__toggle::before{
      content:"▼";
      font-size:10px;
      transition:transform .18s ease;
    }
    .events-filter__collapse[open] .events-filter__toggle::before{
      transform:rotate(180deg);
    }
    .events-filter__panel{
      display:grid;
      gap:14px;
      padding:16px;
      border-top:1px solid var(--line);
      background:rgba(255,255,255,.02);
    }
    .events-filter__primary{display:grid;grid-template-columns:minmax(0,2.1fr) repeat(3,minmax(140px,1fr));gap:10px;align-items:end;}
    .events-filter__secondary{display:grid;grid-template-columns:repeat(12,1fr);gap:10px;align-items:end;}
    .events-filter__grid{display:grid;grid-template-columns:repeat(12,1fr);gap:10px;align-items:end;}
    .events-filter__field{display:grid;gap:6px;}
    .events-filter__field--span-3{grid-column:span 3;}
    .events-filter__field--span-4{grid-column:span 4;}
    .events-filter__field--span-6{grid-column:span 6;}
    .events-filter__field--span-12{grid-column:span 12;}
    .events-filter__field--grow{min-width:0;}
    .events-filter__field--primary{grid-column:auto;}
    .events-filter__actions{display:flex;gap:10px;flex-wrap:wrap;}
    .events-filter__quick{display:flex;gap:8px;flex-wrap:wrap;}
    .events-filter__quick-link{
      display:inline-flex;align-items:center;justify-content:center;
      min-height:40px;padding:0 12px;border-radius:999px;
      border:1px solid var(--line);font-size:13px;
      color:var(--muted);background:rgba(255,255,255,.02);
    }
    .events-filter__quick-link.is-active{color:var(--txt);border-color:rgba(96,165,250,.55);}
    .events-filter__section-title{font-size:12px;color:var(--muted);font-weight:700;letter-spacing:.04em;text-transform:uppercase;}
    .events-table{width:100%;border-collapse:separate;border-spacing:0 8px;}
    .events-table th{
      text-align:left;
      font-size:12px;
      color:var(--muted);
      font-weight:700;
      padding:0 10px 5px;
    }
    .events-table td{
      padding:12px 10px;
      vertical-align:top;
      background:rgba(255,255,255,.03);
      border-top:1px solid var(--line);
      border-bottom:1px solid var(--line);
    }
    .events-table td:first-child{
      border-left:1px solid var(--line);
      border-radius:16px 0 0 16px;
      width:220px;
    }
    .events-table td:last-child{
      border-right:1px solid var(--line);
      border-radius:0 16px 16px 0;
      width:140px;
    }
    .events-date{display:grid;gap:2px;}
    .events-date__main{font-size:15px;font-weight:800;line-height:1.35;}
    .events-date__sub{font-size:12px;color:var(--muted);}
    .events-title{font-size:17px;font-weight:800;line-height:1.4;}
    .events-title a{color:var(--accent);}
    .events-meta{display:flex;gap:6px;flex-wrap:wrap;margin-top:8px;}
    .events-chip{
      display:inline-flex;align-items:center;gap:6px;
      padding:5px 9px;border-radius:999px;
      border:1px solid var(--line);font-size:12px;
      color:var(--muted);background:rgba(255,255,255,.04);
    }
    .events-info{display:grid;gap:6px;margin-top:10px;}
    .events-info__row{display:flex;gap:8px;align-items:flex-start;}
    .events-info__label{min-width:52px;font-size:12px;color:var(--muted);padding-top:2px;}
    .events-info__value{font-size:13px;line-height:1.5;word-break:break-word;}
    .events-source{display:grid;gap:8px;justify-items:start;}
    .events-source__name{font-weight:700;font-size:13px;line-height:1.4;}
    .events-empty{padding:24px 12px;text-align:center;color:var(--muted);}
    .events-pagination{display:flex;gap:8px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-top:8px;}

    @media (max-width: 860px){
      .events-filter__primary{grid-template-columns:1fr 1fr;}
      .events-filter__field--grow{grid-column:1 / -1;}
      .events-filter__field--span-3,
      .events-filter__field--span-4,
      .events-filter__field--span-6{grid-column:span 6;}
    }
    @media (max-width: 640px){
      .events-summary{grid-template-columns:1fr 1fr;}
      .events-filter__top{align-items:stretch;}
      .events-filter__summary{padding:14px;}
      .events-filter__summary-title strong{font-size:16px;}
      .events-filter__primary{grid-template-columns:1fr;}
      .events-filter__field--span-3,
      .events-filter__field--span-4,
      .events-filter__field--span-6{grid-column:span 12;}
      .events-table{border-spacing:0 10px;}
      .events-table tbody tr{display:block;}
      .events-table td,
      .events-table td:first-child,
      .events-table td:last-child{
        display:block;
        width:auto;
        border:1px solid var(--line);
        border-radius:0;
        background:rgba(255,255,255,.03);
        padding:10px 12px;
      }
      .events-table td:first-child{border-radius:16px 16px 0 0;border-bottom:none;}
      .events-table td:nth-child(2){border-top:none;border-bottom:none;}
      .events-table td:last-child{border-top:none;border-radius:0 0 16px 16px;}
      .events-date{gap:3px;}
      .events-date__main{font-size:14px;}
      .events-date__sub{font-size:11px;}
      .events-title{font-size:16px;line-height:1.45;}
      .events-meta{margin-top:7px;}
      .events-chip{padding:4px 8px;font-size:11px;}
      .events-info{gap:7px;margin-top:9px;}
      .events-info__row{display:grid;gap:2px;}
      .events-info__label{min-width:0;padding-top:0;font-size:11px;}
      .events-info__value{font-size:13px;line-height:1.45;}
      .events-source{
        justify-items:stretch;
        grid-template-columns:1fr;
        gap:8px;
      }
      .events-source__name{font-size:12px;color:var(--muted);}
      .events-source .btn{width:100%;}
    }
  </style>
  <div class="events-page">
    <section class="events-hero">
      <div class="events-hero__meta">
        <span class="pill"><?= h($storeName !== '' ? $storeName : '岡山県イベント') ?></span>
        <span class="pill">表示期間: <?= h($from) ?> - <?= h($to) ?></span>
        <span class="pill">表示件数: <?= h((string)$limit) ?>件</span>
      </div>
      <div class="events-summary">
        <div class="events-summary__card">
          <div class="events-summary__label">検索結果</div>
          <div class="events-summary__value"><?= h((string)$total) ?></div>
        </div>
        <div class="events-summary__card">
          <div class="events-summary__label">DB登録件数</div>
          <div class="events-summary__value"><?= h((string)$total_events) ?></div>
        </div>
        <div class="events-summary__card">
          <div class="events-summary__label">最終更新</div>
          <div class="events-summary__value" style="font-size:16px;line-height:1.5;">
            <?= $last_updated ? h($last_updated) : '未更新' ?>
          </div>
        </div>
      </div>
    </section>

    <section class="card events-filter">
      <div class="events-filter__top">
        <div class="events-filter__intro">
          <div>
          <div class="muted">検索条件</div>
          <div style="font-size:18px;font-weight:800;margin-top:4px;">見たいイベントを絞り込み</div>
          </div>
          <div class="events-filter__active">
            <span class="events-filter__active-label">現在の条件</span>
            <span class="events-chip"><?= h($from) ?> - <?= h($to) ?></span>
            <?php foreach ($activeFilters as $filterLabel): ?>
              <span class="events-chip"><?= h($filterLabel) ?></span>
            <?php endforeach; ?>
            <?php if (!$activeFilters): ?>
              <span class="events-chip">追加条件なし</span>
            <?php endif; ?>
          </div>
        </div>
        <div class="events-filter__actions">
          <a class="btn" href="list.php">リセット</a>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>1])) ?>">1ページ目へ戻る</a>
        </div>
      </div>

      <details class="events-filter__collapse"<?= $hasExpandedFilters ? ' open' : '' ?>>
        <summary class="events-filter__summary">
          <div class="events-filter__summary-main">
            <div class="events-filter__summary-title">
              <strong>検索条件を調整</strong>
              <span class="events-chip"><?= h($from) ?> - <?= h($to) ?></span>
            </div>
            <div class="events-filter__summary-sub">
              <?php if ($q !== null && $q !== ''): ?>
                <span class="events-chip">キーワード: <?= h($q) ?></span>
              <?php else: ?>
                <span class="events-chip">キーワードなし</span>
              <?php endif; ?>
              <?php if ($venue !== null && $venue !== ''): ?>
                <span class="events-chip">場所: <?= h($venue) ?></span>
              <?php endif; ?>
              <?php if ($org !== null && $org !== ''): ?>
                <span class="events-chip">主催: <?= h($org) ?></span>
              <?php endif; ?>
              <span class="events-chip"><?= h((string)$limit) ?>件表示</span>
            </div>
          </div>
          <span class="events-filter__toggle">詳細条件を表示</span>
        </summary>

        <form method="get">
          <div class="events-filter__panel">
            <div class="events-filter__section-title">すぐ使う条件</div>
            <div class="events-filter__primary">
              <div class="events-filter__field events-filter__field--grow">
                <label class="muted">キーワード</label>
                <input type="text" name="q" value="<?= h((string)$q) ?>" placeholder="イベント名・説明・URLで検索">
              </div>

              <div class="events-filter__field events-filter__field--primary">
                <label class="muted">開始日</label>
                <input type="date" name="from" value="<?= h($from) ?>">
              </div>

              <div class="events-filter__field events-filter__field--primary">
                <label class="muted">終了日</label>
                <input type="date" name="to" value="<?= h($to) ?>">
              </div>

              <div class="events-filter__field events-filter__field--primary">
                <label class="muted">表示件数</label>
                <select name="limit">
                  <?php foreach ([20,50,100,200] as $n): ?>
                    <option value="<?= $n ?>" <?= $limit===$n?'selected':'' ?>><?= $n ?>件</option>
                  <?php endforeach; ?>
                </select>
              </div>
            </div>

            <div>
              <div class="events-filter__section-title" style="margin-bottom:8px;">期間ショートカット</div>
              <div class="events-filter__quick">
                <?php
                  $today = (new DateTimeImmutable('today'))->format('Y-m-d');
                  $weekTo = (new DateTimeImmutable('today'))->modify('+7 days')->format('Y-m-d');
                  $monthTo = (new DateTimeImmutable('today'))->modify('+30 days')->format('Y-m-d');
                  $twoMonthsTo = (new DateTimeImmutable('today'))->modify('+60 days')->format('Y-m-d');
                  $quickLinks = [
                    ['label' => '今日だけ', 'from' => $today, 'to' => $today],
                    ['label' => '1週間', 'from' => $today, 'to' => $weekTo],
                    ['label' => '30日', 'from' => $today, 'to' => $monthTo],
                    ['label' => '60日', 'from' => $today, 'to' => $twoMonthsTo],
                  ];
                ?>
                <?php foreach ($quickLinks as $link): ?>
                  <?php $isActiveQuick = ($from === $link['from'] && $to === $link['to']); ?>
                  <a
                    class="events-filter__quick-link<?= $isActiveQuick ? ' is-active' : '' ?>"
                    href="list.php?<?= h(build_qs(['from' => $link['from'], 'to' => $link['to'], 'page' => 1])) ?>"
                  ><?= h($link['label']) ?></a>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="events-filter__section-title">詳細条件</div>
            <div class="events-filter__secondary">
              <div class="events-filter__field events-filter__field--span-6">
                <label class="muted">開催場所</label>
                <input type="text" name="venue" value="<?= h((string)$venue) ?>" placeholder="会場名・住所・市区町村">
              </div>

              <div class="events-filter__field events-filter__field--span-6">
                <label class="muted">主催者</label>
                <input type="text" name="org" value="<?= h((string)$org) ?>" placeholder="主催者名・担当者名">
              </div>

              <div class="events-filter__field events-filter__field--span-12">
                <div class="events-filter__actions">
                  <button class="btn btn-primary" type="submit">この条件で検索</button>
                  <a class="btn" href="list.php">条件をクリア</a>
                </div>
              </div>
            </div>
          </div>
        </form>
      </details>
    </section>

    <section class="card">
      <div style="overflow:auto;">
        <table class="events-table">
        <thead>
          <tr>
            <th>日時</th>
            <th>イベント詳細</th>
            <th>参照元</th>
          </tr>
        </thead>
        <tbody>
        <?php if (!$rows): ?>
          <tr><td colspan="3" class="events-empty">条件に合うイベントはありませんでした。</td></tr>
        <?php else: ?>
          <?php foreach ($rows as $r): ?>
            <?php
              $when = format_event_datetime(
                (string)($r['starts_at'] ?? ''),
                (string)($r['ends_at'] ?? ''),
                (int)($r['all_day'] ?? 0)
              );

              $venue_txt = trim((string)($r['venue_name'] ?? ''));
              $addr_txt  = trim((string)($r['address'] ?? ''));
              $place = $venue_txt !== '' ? $venue_txt : ($addr_txt !== '' ? $addr_txt : '会場情報なし');

              $org_txt = trim((string)($r['organizer_name'] ?? ''));
              $contact_txt = trim((string)($r['contact_name'] ?? ''));
              $orgline = $org_txt !== '' ? $org_txt : ($contact_txt !== '' ? $contact_txt : '主催者情報なし');

              $src = trim((string)($r['source_name'] ?? ''));
              $url = (string)($r['source_url'] ?? '');
            ?>
            <tr>
              <td>
                <div class="events-date">
                  <div class="events-date__main"><?= h($when['label']) ?></div>
                  <div class="events-date__sub"><?= h($when['detail']) ?></div>
                </div>
              </td>
              <td>
                <div class="events-title">
                  <?php if ($url !== ''): ?>
                    <a href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer"><?= h((string)$r['title']) ?></a>
                  <?php else: ?>
                    <?= h((string)$r['title']) ?>
                  <?php endif; ?>
                </div>

                <div class="events-meta">
                  <span class="events-chip"><?= h(status_badge_label((string)($r['status'] ?? ''))) ?></span>
                  <?php if ((int)($r['all_day'] ?? 0) === 1): ?>
                    <span class="events-chip">終日開催</span>
                  <?php endif; ?>
                  <?php if ($venue_txt !== ''): ?>
                    <span class="events-chip"><?= h($venue_txt) ?></span>
                  <?php endif; ?>
                </div>

                <div class="events-info">
                  <div class="events-info__row">
                    <div class="events-info__label">場所</div>
                    <div class="events-info__value"><?= h($place) ?></div>
                  </div>
                  <div class="events-info__row">
                    <div class="events-info__label">主催</div>
                    <div class="events-info__value"><?= h($orgline) ?></div>
                  </div>
                  <?php if ($addr_txt !== '' && $addr_txt !== $place): ?>
                    <div class="events-info__row">
                      <div class="events-info__label">住所</div>
                      <div class="events-info__value"><?= h($addr_txt) ?></div>
                    </div>
                  <?php endif; ?>
                </div>
              </td>
              <td>
                <div class="events-source">
                  <div class="events-source__name"><?= h($src !== '' ? $src : '参照元なし') ?></div>
                  <?php if ($url !== ''): ?>
                    <a class="btn" href="<?= h($url) ?>" target="_blank" rel="noopener noreferrer">詳細を開く</a>
                  <?php else: ?>
                    <span class="muted">URL未登録</span>
                  <?php endif; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
        </table>
      </div>

      <div class="events-pagination">
      <div class="muted">page <?= h((string)$page) ?> / <?= h((string)$pages) ?></div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <?php if ($page > 1): ?>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>1])) ?>">«</a>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>$page-1])) ?>">‹</a>
        <?php endif; ?>
        <?php if ($page < $pages): ?>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>$page+1])) ?>">›</a>
          <a class="btn" href="list.php?<?= h(build_qs(['page'=>$pages])) ?>">»</a>
        <?php endif; ?>
      </div>
      </div>
    </section>
  </div>
</div>
<?php render_page_end(); ?>
