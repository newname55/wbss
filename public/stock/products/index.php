<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
  http_response_code(403);
  exit('Forbidden');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));

$params = [];
$where = [];
if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

$sql = "
  SELECT
    p.id, p.name, p.product_type, p.unit, p.barcode,
    p.is_active, p.is_stock_managed, p.reorder_point, p.image_path,
    c.name AS category_name
  FROM stock_products p
  LEFT JOIN stock_categories c ON c.id = p.category_id
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY p.is_active DESC, p.name ASC, p.id DESC LIMIT 300";

$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>';

render_page_start('商品マスタ');
render_header('商品マスタ', [
  'back_href'  => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <div class="card">
    <div style="display:flex; gap:10px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap;">
      <form method="get" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap;">
        <div style="min-width:280px;">
          <label class="muted">検索（商品名 / JAN）</label><br>
          <input class="btn" style="width:100%;" name="q" value="<?= h($q) ?>" placeholder="例) 鏡月 / 490...">
        </div>
        <div style="display:flex; gap:10px;">
          <button class="btn btn-primary" type="submit">検索</button>
          <a class="btn" href="/seika-app/public/stock/products/index.php">クリア</a>
        </div>
      </form>

      <div style="display:flex; gap:10px;">
        <a class="btn btn-primary" href="/seika-app/public/stock/products/edit.php?id=0">＋ 新規作成</a>
      </div>
    </div>
    <div class="muted" style="margin-top:10px;">
      ✅ 行をタップすると編集が開きます（iPhoneでも安定）。
    </div>
  </div>

  <div class="card" style="margin-top:12px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
      <div style="font-weight:1000;">商品一覧（最大300件）</div>
      <div class="muted">タップ → 編集</div>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px; min-width:860px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:70px;">ID</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:84px;">画像</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:120px;">種別</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:160px;">カテゴリ</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:180px;">JAN</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:90px;">状態</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $r): ?>
            <?php
              $id = (int)$r['id'];
              $active = ((int)$r['is_active'] === 1);
              $img = (string)($r['image_path'] ?? '');
              $href = '/seika-app/public/stock/products/edit.php?id=' . $id;
            ?>
            <tr class="tapRow" data-href="<?= h($href) ?>">
              <td style="padding:10px 8px; border-bottom:1px solid var(--line); white-space:nowrap;">
                <?= $id ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line);">
                <?php if ($img !== ''): ?>
                  <img src="<?= h($img) ?>" alt=""
                       style="width:54px; height:54px; object-fit:cover; border-radius:12px; border:1px solid var(--line); background:#fff;">
                <?php else: ?>
                  <div class="muted" style="width:54px; height:54px; display:flex; align-items:center; justify-content:center; border-radius:12px; border:1px dashed var(--line);">
                    -
                  </div>
                <?php endif; ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line);">
                <div style="font-weight:1000; font-size:14px;"><?= h((string)$r['name']) ?></div>
                <div class="muted" style="margin-top:4px;">単位: <?= h((string)($r['unit'] ?? '')) ?></div>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line); white-space:nowrap;">
                <?= h((string)($r['product_type'] ?? '')) ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line);">
                <?= h((string)($r['category_name'] ?? '')) ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line);">
                <?= h((string)($r['barcode'] ?? '')) ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line); white-space:nowrap;">
                <span style="display:inline-block; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <?= $active ? '有効' : '無効' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$list): ?>
            <tr><td colspan="7" class="muted" style="padding:10px;">データなし</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<style>
  .tapRow{ cursor:pointer; }
  .tapRow:hover{ filter: brightness(1.03); }
  .tapRow:active{ transform: translateY(1px); }
</style>

<script>
  // ✅ iPhoneでも確実に「行タップ→編集」にする
  (function(){
    document.querySelectorAll('tr.tapRow[data-href]').forEach(tr => {
      tr.addEventListener('click', () => {
        const href = tr.getAttribute('data-href');
        if (href) location.href = href;
      }, {passive:true});
    });
  })();
</script>

<?php render_page_end(); ?>