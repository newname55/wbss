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

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /wbss/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function fmt_yen($v): string {
  if ($v === null || $v === '') return '-';
  return '¥' . number_format((int)$v);
}
function fmt_signed_yen($v): string {
  if ($v === null || $v === '') return '-';
  $n = (int)$v;
  return ($n > 0 ? '+' : '') . '¥' . number_format($n);
}

$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$sort = (string)($_GET['sort'] ?? 'name_asc');

$sortMap = [
  'name_asc' => 'p.name ASC, p.id DESC',
  'name_desc' => 'p.name DESC, p.id DESC',
  'selling_desc' => 'p.selling_price_yen DESC, p.name ASC, p.id DESC',
  'selling_asc' => 'p.selling_price_yen ASC, p.name ASC, p.id DESC',
  'gross_desc' => '(COALESCE(p.selling_price_yen, -99999999) - COALESCE(p.purchase_price_yen, -99999999)) DESC, p.name ASC, p.id DESC',
  'gross_asc' => '(COALESCE(p.selling_price_yen, 99999999) - COALESCE(p.purchase_price_yen, 99999999)) ASC, p.name ASC, p.id DESC',
  'gross_rate_desc' => 'CASE WHEN p.selling_price_yen IS NULL OR p.selling_price_yen <= 0 OR p.purchase_price_yen IS NULL THEN -99999999 ELSE ((p.selling_price_yen - p.purchase_price_yen) / p.selling_price_yen) END DESC, p.name ASC, p.id DESC',
  'gross_rate_asc' => 'CASE WHEN p.selling_price_yen IS NULL OR p.selling_price_yen <= 0 OR p.purchase_price_yen IS NULL THEN 99999999 ELSE ((p.selling_price_yen - p.purchase_price_yen) / p.selling_price_yen) END ASC, p.name ASC, p.id DESC',
];
if (!isset($sortMap[$sort])) $sort = 'name_asc';

$params = [$store_id];
$where = ['p.store_id = ?'];
if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

$sql = "
  SELECT
    p.id, p.name, p.product_type, p.unit, p.barcode,
    p.is_active, p.is_stock_managed, p.reorder_point, p.image_path,
    p.purchase_price_yen, p.selling_price_yen,
    c.name AS category_name
  FROM stock_products p
  LEFT JOIN stock_categories c ON c.id = p.category_id
";
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY p.is_active DESC, " . $sortMap[$sort] . " LIMIT 300";

$st = $pdo->prepare($sql);
$st->execute($params);
$list = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$right = '<a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>';

render_page_start('商品マスタ');
render_header('商品マスタ', [
  'back_href'  => '/wbss/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <div class="card">
    <div style="display:flex; gap:10px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap;">
      <form method="get" style="display:flex; gap:10px; align-items:flex-end; flex-wrap:wrap; width:100%;">
        <div style="min-width:280px;">
          <label class="muted">検索（商品名 / JAN）</label><br>
          <input class="btn" style="width:100%;" name="q" value="<?= h($q) ?>" placeholder="例) 鏡月 / 490...">
        </div>
        <div style="min-width:220px;">
          <label class="muted">並び替え</label><br>
          <select class="btn" style="width:100%;" name="sort">
            <option value="name_asc" <?= $sort === 'name_asc' ? 'selected' : '' ?>>名前順</option>
            <option value="name_desc" <?= $sort === 'name_desc' ? 'selected' : '' ?>>名前逆順</option>
            <option value="selling_desc" <?= $sort === 'selling_desc' ? 'selected' : '' ?>>売価が高い順</option>
            <option value="selling_asc" <?= $sort === 'selling_asc' ? 'selected' : '' ?>>売価が低い順</option>
            <option value="gross_desc" <?= $sort === 'gross_desc' ? 'selected' : '' ?>>粗利が高い順</option>
            <option value="gross_asc" <?= $sort === 'gross_asc' ? 'selected' : '' ?>>粗利が低い順</option>
            <option value="gross_rate_desc" <?= $sort === 'gross_rate_desc' ? 'selected' : '' ?>>粗利率が高い順</option>
            <option value="gross_rate_asc" <?= $sort === 'gross_rate_asc' ? 'selected' : '' ?>>粗利率が低い順</option>
          </select>
        </div>
        <div style="display:flex; gap:10px;">
          <button class="btn btn-primary" type="submit">検索</button>
          <a class="btn" href="/wbss/public/stock/products/index.php">クリア</a>
        </div>
        <div style="display:flex; gap:10px; margin-left:auto;">
          <a class="btn btn-primary" href="/wbss/public/stock/products/edit.php?id=0">＋ 新規作成</a>
        </div>
      </form>
    </div>
    <div class="muted" style="margin-top:10px;">
      ✅ 一覧は並び替え対応。PCは表、スマホはカードで見やすくしています。
    </div>
  </div>

  <div class="card" style="margin-top:12px;">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap;">
      <div style="font-weight:1000;">商品一覧（最大300件）</div>
      <div class="muted">タップ → 編集</div>
    </div>

    <div class="products-table-wrap" style="margin-top:10px;">
      <table class="products-table" style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:70px;">ID</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:84px;">画像</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:180px;">区分</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line); width:120px;">仕入れ値</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line); width:120px;">売価</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line); width:120px;">粗利/個</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line); width:100px;">粗利率</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:90px;">状態</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($list as $r): ?>
            <?php
              $id = (int)$r['id'];
              $active = ((int)$r['is_active'] === 1);
              $img = (string)($r['image_path'] ?? '');
              $href = '/wbss/public/stock/products/edit.php?id=' . $id;
              $purchase = ($r['purchase_price_yen'] ?? null) === null ? null : (int)$r['purchase_price_yen'];
              $selling = ($r['selling_price_yen'] ?? null) === null ? null : (int)$r['selling_price_yen'];
              $gross = ($purchase !== null && $selling !== null) ? ($selling - $purchase) : null;
              $grossRate = ($gross !== null && $selling > 0) ? (($gross / $selling) * 100) : null;
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
                <?php if ((string)($r['barcode'] ?? '') !== ''): ?>
                  <div class="muted" style="margin-top:4px;">JAN: <?= h((string)$r['barcode']) ?></div>
                <?php endif; ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line);">
                <div><?= h((string)($r['product_type'] ?? '')) ?></div>
                <div class="muted" style="margin-top:4px;"><?= h((string)($r['category_name'] ?? '未分類')) ?></div>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap;">
                <?= h(fmt_yen($purchase)) ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap;">
                <?= h(fmt_yen($selling)) ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap; color:<?= ($gross !== null && $gross < 0) ? '#fda4af' : '#86efac' ?>;">
                <?= h($gross === null ? '-' : (($gross > 0 ? '+' : '') . fmt_yen($gross))) ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap;">
                <?= h($grossRate === null ? '-' : number_format($grossRate, 1) . '%') ?>
              </td>

              <td style="padding:10px 8px; border-bottom:1px solid var(--line); white-space:nowrap;">
                <span style="display:inline-block; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <?= $active ? '有効' : '無効' ?>
                </span>
              </td>
            </tr>
          <?php endforeach; ?>

          <?php if (!$list): ?>
            <tr><td colspan="8" class="muted" style="padding:10px;">データなし</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="products-cards">
      <?php foreach ($list as $r): ?>
        <?php
          $id = (int)$r['id'];
          $active = ((int)$r['is_active'] === 1);
          $img = (string)($r['image_path'] ?? '');
          $href = '/wbss/public/stock/products/edit.php?id=' . $id;
          $purchase = ($r['purchase_price_yen'] ?? null) === null ? null : (int)$r['purchase_price_yen'];
          $selling = ($r['selling_price_yen'] ?? null) === null ? null : (int)$r['selling_price_yen'];
          $gross = ($purchase !== null && $selling !== null) ? ($selling - $purchase) : null;
          $grossRate = ($gross !== null && $selling > 0) ? (($gross / $selling) * 100) : null;
        ?>
        <a class="product-card" href="<?= h($href) ?>">
          <div class="product-card__top">
            <?php if ($img !== ''): ?>
              <img src="<?= h($img) ?>" alt="" class="product-card__img">
            <?php else: ?>
              <div class="product-card__img product-card__img--empty">-</div>
            <?php endif; ?>
            <div class="product-card__main">
              <div class="product-card__name"><?= h((string)$r['name']) ?></div>
              <div class="muted"><?= h((string)($r['product_type'] ?? '')) ?> / <?= h((string)($r['category_name'] ?? '未分類')) ?></div>
              <div class="muted">単位: <?= h((string)($r['unit'] ?? '')) ?></div>
              <?php if ((string)($r['barcode'] ?? '') !== ''): ?>
                <div class="muted">JAN: <?= h((string)$r['barcode']) ?></div>
              <?php endif; ?>
            </div>
            <span class="product-card__state"><?= $active ? '有効' : '無効' ?></span>
          </div>
          <div class="product-card__grid">
            <div><span class="muted">仕入れ</span><strong><?= h(fmt_yen($purchase)) ?></strong></div>
            <div><span class="muted">売価</span><strong><?= h(fmt_yen($selling)) ?></strong></div>
            <div><span class="muted">粗利</span><strong style="color:<?= ($gross !== null && $gross < 0) ? '#fda4af' : '#86efac' ?>;"><?= h(fmt_signed_yen($gross)) ?></strong></div>
            <div><span class="muted">粗利率</span><strong><?= h($grossRate === null ? '-' : number_format($grossRate, 1) . '%') ?></strong></div>
          </div>
        </a>
      <?php endforeach; ?>
      <?php if (!$list): ?>
        <div class="muted" style="padding:10px 0;">データなし</div>
      <?php endif; ?>
    </div>
  </div>
</div>

<style>
  .tapRow{ cursor:pointer; }
  .tapRow:hover{ filter: brightness(1.03); }
  .tapRow:active{ transform: translateY(1px); }
  .products-cards{ display:none; }
  .products-table{
    table-layout:fixed;
  }
  .products-table td,
  .products-table th{
    word-break:break-word;
  }
  .product-card{
    display:block;
    padding:14px;
    border-radius:18px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.04);
    color:inherit;
    text-decoration:none;
    margin-top:10px;
  }
  .product-card__top{
    display:grid;
    grid-template-columns:64px 1fr auto;
    gap:12px;
    align-items:start;
  }
  .product-card__img{
    width:64px;
    height:64px;
    object-fit:cover;
    border-radius:14px;
    border:1px solid var(--line);
    background:#fff;
  }
  .product-card__img--empty{
    display:flex;
    align-items:center;
    justify-content:center;
    color:var(--muted);
    border-style:dashed;
  }
  .product-card__name{
    font-size:15px;
    font-weight:1000;
    margin-bottom:4px;
  }
  .product-card__state{
    display:inline-flex;
    align-items:center;
    padding:4px 10px;
    border-radius:999px;
    border:1px solid var(--line);
    background:rgba(255,255,255,.06);
    color:var(--muted);
    white-space:nowrap;
  }
  .product-card__grid{
    display:grid;
    grid-template-columns:repeat(2, minmax(0, 1fr));
    gap:10px;
    margin-top:12px;
  }
  .product-card__grid div{
    padding:10px 12px;
    border-radius:12px;
    background:rgba(255,255,255,.03);
    display:grid;
    gap:4px;
  }
  @media (max-width: 860px){
    .products-table-wrap{ display:none; }
    .products-cards{ display:block; }
  }
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
