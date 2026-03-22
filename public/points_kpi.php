<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_points.php';
require_once __DIR__ . '/../app/service_points_kpi.php';

require_login();
require_role(['admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('has_role')) {
  function has_role(string $role): bool {
    return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
  }
}
if (!function_exists('current_user_id_safe')) {
  function current_user_id_safe(): int {
    return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
  }
}
if (!function_exists('fmt_point')) {
  function fmt_point(float $value): string {
    $s = number_format($value, 1, '.', '');
    return rtrim(rtrim($s, '0'), '.');
  }
}

$userId = current_user_id_safe();
$isSuper = has_role('super_user');

$stores = repo_points_allowed_stores($pdo, $userId, $isSuper);
if (!$stores) {
  http_response_code(400);
  exit('店舗がありません');
}

$storeId = (int)($_GET['store_id'] ?? 0);
if ($storeId <= 0) $storeId = (int)$stores[0]['id'];

$allowedIds = array_map(static fn(array $s): int => (int)$s['id'], $stores);
if (!in_array($storeId, $allowedIds, true)) $storeId = (int)$stores[0]['id'];

$businessDate = (string)($_GET['business_date'] ?? '');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) {
  $businessDate = (new DateTimeImmutable('today', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#' . $storeId));

$casts = repo_points_casts_for_store($pdo, $storeId);
$report = points_kpi_day_summary($pdo, $storeId, $businessDate, $casts);
$rows = $report['rows'];
$stats = $report['stats'];

render_page_start('ポイント自動集計（KPI）');
render_header('ポイント自動集計（KPI）', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page"><div class="admin-wrap">
  <div class="topRow">
    <div>
      <div class="title">📈 ポイント自動集計（KPI）</div>
      <div class="muted">伝票データから日別で自動集計します。既存の手入力はレガシー画面としてそのまま残しています。</div>
    </div>
    <div class="muted">
      店舗：<b><?= h($storeName) ?></b> (#<?= (int)$storeId ?>)
      / 日付：<b><?= h($businessDate) ?></b>
    </div>
  </div>

  <form method="get" class="searchRow">
    <label class="muted">店舗</label>
    <select name="store_id" class="sel">
      <?php foreach ($stores as $store): ?>
        <option value="<?= (int)$store['id'] ?>" <?= ((int)$store['id'] === $storeId) ? 'selected' : '' ?>>
          <?= h((string)$store['name']) ?> (#<?= (int)$store['id'] ?>)
        </option>
      <?php endforeach; ?>
    </select>

    <label class="muted">日付</label>
    <input class="sel" type="date" name="business_date" value="<?= h($businessDate) ?>">

    <button class="btn">表示</button>
    <a class="btn ghost" href="/wbss/public/points_day.php?store_id=<?= (int)$storeId ?>&business_date=<?= h($businessDate) ?>">レガシー入力へ</a>
    <a class="btn ghost" href="/wbss/public/points_kpi_bottles.php?store_id=<?= (int)$storeId ?>&business_date=<?= h($businessDate) ?>">ボトル内訳へ</a>
    <a class="btn ghost" href="/wbss/public/points_terms.php?store_id=<?= (int)$storeId ?>&business_date=<?= h($businessDate) ?>">半月集計へ</a>
  </form>

  <div class="kpiGrid">
    <div class="kpiCard">
      <div class="kpiLabel">対象伝票</div>
      <div class="kpiValue"><?= number_format((int)$stats['source_ticket_count']) ?></div>
      <div class="muted">当日伝票 <?= number_format((int)$stats['ticket_count']) ?> 件中</div>
    </div>
    <div class="kpiCard">
      <div class="kpiLabel">指名ポイント合計</div>
      <div class="kpiValue"><?= h(fmt_point((float)$stats['shimei_total'])) ?></div>
    </div>
    <div class="kpiCard">
      <div class="kpiLabel">同伴本数合計</div>
      <div class="kpiValue"><?= h(fmt_point((float)$stats['douhan_total'])) ?></div>
    </div>
    <div class="kpiCard">
      <div class="kpiLabel">ドリンク売上</div>
      <div class="kpiValue">¥<?= number_format((int)$stats['drink_total']) ?></div>
    </div>
    <div class="kpiCard">
      <div class="kpiLabel">ボトル売上</div>
      <div class="kpiValue">¥<?= number_format((int)$stats['bottle_total']) ?></div>
    </div>
  </div>

  <div class="card" style="margin-top:12px;">
    <div class="cardTitle">キャスト別</div>
    <div class="tblWrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>店番</th>
            <th>名前</th>
            <th>対象伝票</th>
            <th>合計pt</th>
            <th>同伴本数</th>
            <th>指名pt</th>
            <th>ドリンク売上</th>
            <th>ボトル売上</th>
            <th>ポイント内訳</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $hasAny = ((float)$row['shimei_point'] > 0)
                || ((float)$row['douhan_count'] > 0)
                || ((int)$row['drink_total'] > 0)
                || ((int)$row['bottle_total'] > 0)
                || ((int)$row['ticket_count'] > 0);
            ?>
            <tr class="<?= $hasAny ? '' : 'is-empty' ?>">
              <?php $totalPoint = (float)$row['douhan_count'] + (float)$row['shimei_point']; ?>
              <td><?= h((string)($row['shop_tag'] !== '' ? $row['shop_tag'] : '—')) ?></td>
              <td><?= h((string)$row['display_name']) ?></td>
              <td style="text-align:right;"><?= number_format((int)$row['ticket_count']) ?></td>
              <td style="text-align:right;"><?= h(fmt_point($totalPoint)) ?></td>
              <td style="text-align:right;"><?= h(fmt_point((float)$row['douhan_count'])) ?></td>
              <td style="text-align:right;"><?= h(fmt_point((float)$row['shimei_point'])) ?></td>
              <td style="text-align:right;">¥<?= number_format((int)$row['drink_total']) ?></td>
              <td style="text-align:right;">¥<?= number_format((int)$row['bottle_total']) ?></td>
              <td>
                <?php if (!empty($row['point_events'])): ?>
                  <?php $ticketGroups = points_kpi_group_point_events_by_ticket($row['point_events']); ?>
                  <details class="detailBox">
                    <summary><?= count($ticketGroups) ?> 件</summary>
                    <div class="detailList">
                      <?php foreach ($ticketGroups as $group): ?>
                        <div class="detailItem">
                          <div><b><?= (int)$group['ticket_id'] > 0 ? ('伝票#' . (int)$group['ticket_id']) : 'トップレベル指名' ?></b></div>
                          <div>
                            指名 <?= h(fmt_point((float)$group['shimei_point'])) ?>
                            / 同伴 <?= h(fmt_point((float)$group['douhan_point'])) ?>
                          </div>
                          <?php if (!empty($group['details'])): ?>
                            <div class="detailSubList">
                              <?php foreach ($group['details'] as $detail): ?>
                                <div class="detailSubItem">
                                  セット<?= (int)$detail['set_no'] ?>
                                  / <?= h(points_kpi_kind_label((string)$detail['set_kind'])) ?>
                                  / <?= h(points_kpi_nomination_label((string)$detail['nomination_type'])) ?>
                                  : 指名 <?= h(fmt_point((float)$detail['shimei_point'])) ?>
                                  / 同伴 <?= h(fmt_point((float)$detail['douhan_point'])) ?>
                                </div>
                              <?php endforeach; ?>
                            </div>
                          <?php endif; ?>
                        </div>
                      <?php endforeach; ?>
                    </div>
                  </details>
                <?php else: ?>
                  <span class="muted">なし</span>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="muted" style="margin-top:10px;">
      注記: 伝票の `totals_snapshot` を元に集計しています。ボトル内訳は `drink.meta` に商品情報が入っているものだけ名称付きで出ます。
    </div>
  </div>
</div></div>

<style>
.topRow{display:flex;justify-content:space-between;gap:12px;align-items:flex-end;flex-wrap:wrap}
.title{font-weight:1000;font-size:20px}
.muted{opacity:.75;font-size:12px}
.searchRow{margin-top:10px;display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.sel{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn.ghost{background:transparent}
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.cardTitle{font-weight:900;margin-bottom:10px}
.kpiGrid{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:10px;margin-top:12px}
.kpiCard{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.kpiLabel{font-size:12px;opacity:.75}
.kpiValue{font-size:22px;font-weight:1000;margin-top:6px}
.tblWrap{overflow:auto;border:1px solid rgba(255,255,255,.10);border-radius:12px}
.tbl{width:100%;border-collapse:collapse;min-width:1040px}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:top;white-space:nowrap}
.tbl thead th{position:sticky;top:0;background:var(--cardA);z-index:1}
.tbl td:last-child,.tbl th:last-child{white-space:normal;min-width:340px}
.detailBox summary{cursor:pointer;font-weight:900}
.detailList{display:grid;gap:8px;margin-top:8px}
.detailItem{padding:8px 10px;border-radius:12px;border:1px solid var(--line);background:var(--cardB)}
.detailSubList{display:grid;gap:4px;margin-top:8px}
.detailSubItem{font-size:12px;color:var(--muted)}
.is-empty{opacity:.68}
@media (max-width: 1100px){
  .kpiGrid{grid-template-columns:repeat(2,minmax(0,1fr))}
}
@media (max-width: 640px){
  .kpiGrid{grid-template-columns:1fr}
}
</style>
<?php render_page_end(); ?>
