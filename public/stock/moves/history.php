<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager') && !is_role('staff')) {
  http_response_code(403);
  exit('Forbidden');
}

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /seika-app/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 50) $limit = 50;
if ($limit > 1000) $limit = 1000;

$q = trim((string)($_GET['q'] ?? ''));

$params = [$store_id];
$where = "WHERE m.store_id = ?";
if ($q !== '') {
  $where .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR m.note LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}

$st = $pdo->prepare("
  SELECT
    m.id, m.created_at, m.move_type, m.delta, m.note,
    p.name AS product_name, p.barcode, p.unit,
    u.display_name
  FROM stock_moves m
  JOIN stock_products p ON p.id = m.product_id
  LEFT JOIN users u ON u.id = m.created_by
  {$where}
  ORDER BY m.id DESC
  LIMIT {$limit}
");
$st->execute($params);
$rows = $st->fetchAll();

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>';

render_page_start('入出庫履歴');
render_header('入出庫履歴', [
  'back_href'  => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">
  <div class="card">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:16px;">入出庫履歴</div>
        <div class="muted">検索：商品名 / JAN / メモ</div>
      </div>
      <a class="btn btn-primary" href="/seika-app/public/stock/move.php">入出庫・棚卸へ</a>
    </div>

    <hr style="border:none;border-top:1px solid var(--line);margin:12px 0;">

    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <div style="min-width:260px; flex:1;">
        <label class="muted">検索</label><br>
        <input class="btn" style="width:100%;" name="q" value="<?= h($q) ?>" placeholder="例) 角 / 490... / 納品">
      </div>
      <div style="min-width:160px;">
        <label class="muted">件数</label><br>
        <select class="btn" name="limit" style="width:100%;">
          <?php foreach ([100,200,300,500,1000] as $n): ?>
            <option value="<?= $n ?>" <?= $limit===$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="muted"> </label><br>
        <button class="btn" type="submit">絞り込み</button>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="overflow:auto;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">日時</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">種別</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">変動</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">メモ</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $t = (string)$r['move_type'];
              $accent = ($t==='in') ? 'var(--ok)' : (($t==='out') ? 'var(--warn)' : 'var(--accent)');
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$r['created_at']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <div style="font-weight:900;"><?= h((string)$r['product_name']) ?></div>
                <div class="muted"><?= h((string)($r['barcode'] ?? '')) ?></div>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:<?= h($accent) ?>;"></span>
                  <?= h($t) ?>
                </span>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;">
                <?= (int)$r['delta'] ?> <?= h((string)($r['unit'] ?? '')) ?>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['note'] ?? '')) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['display_name'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="muted" style="margin-top:10px;">表示件数: <?= count($rows) ?></div>
  </div>
</div>

<?php render_page_end(); ?>