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
$rows = points_kpi_bottle_rows($pdo, $storeId, $businessDate, $casts);

render_page_start('ボトル内訳');
render_header('ボトル内訳', [
  'back_href' => '/wbss/public/points_kpi.php?store_id=' . $storeId . '&business_date=' . urlencode($businessDate),
  'back_label' => '← KPIへ',
]);
?>
<div class="page"><div class="admin-wrap">
  <div class="topRow">
    <div>
      <div class="title">🍾 ボトル内訳</div>
      <div class="muted">ポイントKPI一覧から分離した、ボトル売上の詳細ページです。</div>
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
    <a class="btn ghost" href="/wbss/public/points_kpi.php?store_id=<?= (int)$storeId ?>&business_date=<?= h($businessDate) ?>">KPIへ戻る</a>
  </form>

  <div class="card" style="margin-top:12px;">
    <div class="cardTitle">キャスト別ボトル内訳</div>
    <div class="tblWrap">
      <table class="tbl">
        <thead>
          <tr>
            <th>店番</th>
            <th>名前</th>
            <th>ボトル売上</th>
            <th>内訳</th>
          </tr>
        </thead>
        <tbody>
          <?php if ($rows === []): ?>
            <tr><td colspan="4" class="muted">ボトル内訳はありません。</td></tr>
          <?php endif; ?>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td><?= h((string)($row['shop_tag'] !== '' ? $row['shop_tag'] : '—')) ?></td>
              <td><?= h((string)$row['display_name']) ?></td>
              <td style="text-align:right;">¥<?= number_format((int)$row['bottle_total']) ?></td>
              <td>
                <div class="chips">
                  <?php foreach (($row['items'] ?? []) as $item): ?>
                    <span class="chip"><?= h((string)$item['label']) ?> ¥<?= number_format((int)$item['amount']) ?></span>
                  <?php endforeach; ?>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
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
.tblWrap{overflow:auto;border:1px solid rgba(255,255,255,.10);border-radius:12px}
.tbl{width:100%;border-collapse:collapse;min-width:760px}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:top;white-space:nowrap}
.tbl thead th{position:sticky;top:0;background:var(--cardA);z-index:1}
.tbl td:last-child,.tbl th:last-child{white-space:normal;min-width:360px}
.chips{display:flex;gap:6px;flex-wrap:wrap}
.chip{display:inline-flex;align-items:center;gap:4px;padding:5px 10px;border-radius:999px;border:1px solid var(--line);background:var(--cardB)}
</style>
<?php render_page_end(); ?>
