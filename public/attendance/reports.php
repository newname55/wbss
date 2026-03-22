<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/attendance.php';
require_once __DIR__ . '/../../app/store_access.php';

require_login();
require_role(['manager','admin','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

$stores = store_access_allowed_stores($pdo);
if (!$stores) {
  http_response_code(400);
  exit('管理可能な店舗がありません');
}

$requestedStoreId = (int)($_GET['store_id'] ?? 0);
$storeId = store_access_resolve_manageable_store_id($pdo, $requestedStoreId > 0 ? $requestedStoreId : null);

$tz = new DateTimeZone('Asia/Tokyo');
$now = new DateTime('now', $tz);

$ym = (string)($_GET['ym'] ?? '');
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
  $ym = $now->format('Y-m');
}

$term = (string)($_GET['term'] ?? '');
if (!in_array($term, ['first', 'second'], true)) {
  $term = ((int)$now->format('j') >= 16) ? 'second' : 'first';
}

[$fromYmd, $toYmd] = att_term_range($ym, $term);

$rows = att_fetch_term_report($pdo, $storeId, $fromYmd, $toYmd);
$hasPlans = att_has_table($pdo, 'cast_shift_plans');

$storeName = store_access_find_store_name($stores, $storeId);
if ($storeName === '') {
  $storeName = '#' . $storeId;
}

$summary = [
  'cast_count' => count($rows),
  'planned_days' => 0,
  'attendance_days' => 0,
  'absent_days' => 0,
  'late_count' => 0,
  'worked_minutes' => 0,
  'incomplete_days' => 0,
];

foreach ($rows as &$row) {
  $row['planned_days'] = (int)($row['planned_days'] ?? 0);
  $row['attendance_days'] = (int)($row['attendance_days'] ?? 0);
  $row['absent_days'] = (int)($row['absent_days'] ?? 0);
  $row['late_count'] = (int)($row['late_count'] ?? 0);
  $row['worked_minutes'] = (int)($row['worked_minutes'] ?? 0);
  $row['incomplete_days'] = (int)($row['incomplete_days'] ?? 0);
  $row['note_days'] = (int)($row['note_days'] ?? 0);
  $row['avg_minutes'] = $row['attendance_days'] > 0
    ? (int)floor($row['worked_minutes'] / $row['attendance_days'])
    : 0;

  $summary['planned_days'] += $row['planned_days'];
  $summary['attendance_days'] += $row['attendance_days'];
  $summary['absent_days'] += $row['absent_days'];
  $summary['late_count'] += $row['late_count'];
  $summary['worked_minutes'] += $row['worked_minutes'];
  $summary['incomplete_days'] += $row['incomplete_days'];
}
unset($row);

usort($rows, static function (array $a, array $b): int {
  $aShop = trim((string)($a['shop_tag'] ?? ''));
  $bShop = trim((string)($b['shop_tag'] ?? ''));
  $aNum = ctype_digit($aShop) ? (int)$aShop : null;
  $bNum = ctype_digit($bShop) ? (int)$bShop : null;

  if ($aNum !== null || $bNum !== null) {
    if ($aNum === null) return 1;
    if ($bNum === null) return -1;
    if ($aNum !== $bNum) return $aNum <=> $bNum;
  }

  if ($aShop !== $bShop) {
    if ($aShop === '') return 1;
    if ($bShop === '') return -1;
    return strcmp($aShop, $bShop);
  }

  return strcmp((string)($a['display_name'] ?? ''), (string)($b['display_name'] ?? ''));
});

function att_minutes_label(int $minutes): string {
  $hours = intdiv(max($minutes, 0), 60);
  $mins = max($minutes, 0) % 60;
  return sprintf('%d:%02d', $hours, $mins);
}

function att_employment_label(string $employment): string {
  $normalized = strtolower(trim($employment));
  return match ($normalized) {
    'regular' => 'レギュラー',
    'part' => 'バイト',
    default => $employment,
  };
}

$rightHtml = ''
  . '<a class="btn" href="/wbss/public/attendance/index.php?store_id=' . (int)$storeId . '">日次出勤</a>'
  . '<a class="btn" href="/wbss/public/manager_today_schedule.php?store_id=' . (int)$storeId . '">本日の予定</a>';

render_page_start('勤怠レポート');
render_header('勤怠レポート', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => $rightHtml,
]);
?>
<div class="page">
  <div class="admin-wrap reportPage">
    <section class="hero card">
      <div>
        <div class="hero__title">勤怠 半期集計</div>
        <div class="muted">店舗：<b><?= h($storeName) ?></b> / 期間：<b><?= h($fromYmd) ?></b> 〜 <b><?= h($toYmd) ?></b></div>
        <div class="muted">労働時間、遅刻回数、欠勤候補、入力メモ日数を半期単位で確認できます。</div>
      </div>
      <form method="get" class="filters">
        <label>
          <span class="muted">店舗</span>
          <select name="store_id" class="inp">
            <?php foreach ($stores as $store): ?>
              <option value="<?= (int)$store['id'] ?>" <?= ((int)$store['id'] === $storeId) ? 'selected' : '' ?>>
                <?= h((string)$store['name']) ?> (#<?= (int)$store['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </label>
        <label>
          <span class="muted">月</span>
          <input class="inp" type="month" name="ym" value="<?= h($ym) ?>">
        </label>
        <label>
          <span class="muted">期</span>
          <select name="term" class="inp">
            <option value="first" <?= $term === 'first' ? 'selected' : '' ?>>前半（1-15）</option>
            <option value="second" <?= $term === 'second' ? 'selected' : '' ?>>後半（16-末）</option>
          </select>
        </label>
        <button class="btn btnPrimary" type="submit">表示</button>
      </form>
    </section>

    <section class="kpis">
      <div class="kpi card">
        <div class="kpi__label">対象キャスト</div>
        <div class="kpi__value"><?= number_format($summary['cast_count']) ?></div>
      </div>
      <div class="kpi card">
        <div class="kpi__label">総労働時間</div>
        <div class="kpi__value"><?= h(att_minutes_label($summary['worked_minutes'])) ?></div>
      </div>
      <div class="kpi card">
        <div class="kpi__label">遅刻回数</div>
        <div class="kpi__value"><?= number_format($summary['late_count']) ?></div>
      </div>
      <div class="kpi card">
        <div class="kpi__label">欠勤候補</div>
        <div class="kpi__value"><?= number_format($summary['absent_days']) ?></div>
      </div>
    </section>

    <section class="notes card">
      <div class="notes__title">集計ルール</div>
      <div class="notes__body">
        労働時間は `clock_in` と `clock_out` の差分で集計しています。欠勤候補は <?= $hasPlans ? '<b>cast_shift_plans の予定あり日</b> に対して勤怠実績が無い日です。' : '<b>cast_shift_plans テーブル未検出のため 0 固定</b> です。' ?>
      </div>
      <div class="notes__body">給与計算そのものは未実装ですが、この画面の総労働時間をベースに時給計算を載せられる状態です。</div>
    </section>

    <section class="tableCard card">
      <div class="tableCard__head">
        <div class="tableCard__title">キャスト別集計</div>
        <div class="muted">未完了打刻は IN/OUT が片方だけの日を数えています。</div>
      </div>

      <?php if (!$rows): ?>
        <div class="empty">この条件で表示できるキャストがありません。</div>
      <?php else: ?>
        <div class="tableWrap">
          <table class="reportTable">
            <thead>
              <tr>
                <th>店番</th>
                <th>キャスト</th>
                <th>雇用</th>
                <th>予定</th>
                <th>出勤</th>
                <th>欠勤候補</th>
                <th>遅刻</th>
                <th>未完了</th>
                <th>労働時間</th>
                <th>平均/日</th>
                <th>メモ日数</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $row): ?>
                <?php
                  $shopTag = trim((string)($row['shop_tag'] ?? ''));
                  $shopLabel = $shopTag !== '' ? $shopTag : (string)($row['user_id'] ?? '-');
                ?>
                <tr>
                  <td class="mono"><?= h($shopLabel) ?></td>
                  <td>
                    <div class="name"><?= h((string)($row['display_name'] ?? '')) ?></div>
                  </td>
                  <td><?= h(att_employment_label((string)($row['employment'] ?? ''))) ?></td>
                  <td class="num"><?= number_format((int)$row['planned_days']) ?></td>
                  <td class="num"><?= number_format((int)$row['attendance_days']) ?></td>
                  <td class="num danger"><?= number_format((int)$row['absent_days']) ?></td>
                  <td class="num warn"><?= number_format((int)$row['late_count']) ?></td>
                  <td class="num"><?= number_format((int)$row['incomplete_days']) ?></td>
                  <td class="mono strong"><?= h(att_minutes_label((int)$row['worked_minutes'])) ?></td>
                  <td class="mono"><?= h(att_minutes_label((int)$row['avg_minutes'])) ?></td>
                  <td class="num"><?= number_format((int)$row['note_days']) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>
  </div>
</div>

<style>
.reportPage{
  display:grid;
  gap:14px;
}
.card{
  border:1px solid var(--line);
  background:var(--cardA);
  border-radius:16px;
  padding:14px;
}
.hero{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:16px;
  flex-wrap:wrap;
}
.hero__title{
  font-size:20px;
  font-weight:1000;
}
.muted{
  opacity:.75;
  font-size:12px;
  margin-top:4px;
}
.filters{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  align-items:flex-end;
}
.filters label{
  display:grid;
  gap:4px;
}
.inp,
.btn{
  min-height:42px;
  padding:10px 12px;
  border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA);
  color:inherit;
  text-decoration:none;
}
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  cursor:pointer;
}
.btnPrimary{
  background:rgba(59,130,246,.16);
  border-color:rgba(59,130,246,.40);
}
.kpis{
  display:grid;
  grid-template-columns:repeat(4, minmax(140px, 1fr));
  gap:10px;
}
.kpi{
  background:var(--cardB);
}
.kpi__label{
  font-size:12px;
  opacity:.75;
}
.kpi__value{
  margin-top:8px;
  font-size:24px;
  font-weight:1000;
}
.notes__title,
.tableCard__title{
  font-weight:900;
}
.notes__body{
  margin-top:6px;
  font-size:13px;
  line-height:1.6;
}
.tableCard__head{
  display:flex;
  align-items:flex-end;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:10px;
}
.tableWrap{
  overflow:auto;
}
.reportTable{
  width:100%;
  border-collapse:collapse;
  min-width:980px;
}
.reportTable th,
.reportTable td{
  padding:10px 8px;
  border-top:1px solid var(--line);
  vertical-align:middle;
  white-space:nowrap;
}
.reportTable thead th{
  border-top:none;
  text-align:left;
  font-size:12px;
  opacity:.75;
}
.name{
  font-weight:800;
}
.num{
  text-align:right;
}
.mono{
  font-variant-numeric:tabular-nums;
}
.strong{
  font-weight:900;
}
.warn{
  color:#f59e0b;
}
.danger{
  color:#ef4444;
}
.empty{
  padding:18px 6px 6px;
  opacity:.75;
}
@media (max-width: 860px){
  .kpis{
    grid-template-columns:repeat(2, minmax(140px, 1fr));
  }
}
@media (max-width: 640px){
  .kpis{
    grid-template-columns:1fr;
  }
}
</style>

<?php render_page_end(); ?>
