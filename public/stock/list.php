<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager') && !is_role('staff')) {
  http_response_code(403);
  exit('Forbidden');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

/** ====== ユーティリティ ====== */
function table_exists(PDO $pdo, string $table): bool {
  $t = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table);
  $sql = "SHOW TABLES LIKE " . $pdo->quote($t);
  return (bool)$pdo->query($sql)->fetchColumn();
}

function table_has_column(PDO $pdo, string $table, string $col): bool {
  $c = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $col);
  $sql = "SHOW COLUMNS FROM `" . str_replace('`','``',$table) . "` LIKE " . $pdo->quote($c);
  return (bool)$pdo->query($sql)->fetch();
}

function require_store_selected(): int {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $sid = (int)($_SESSION['store_id'] ?? 0);
  if ($sid <= 0) {
    header('Location: /seika-app/public/store_select.php');
    exit;
  }
  return $sid;
}

/** ✅ barcodeが無い商品でも move.php で検索できるようにする */
function move_q_for_row(array $r): string {
  $b = trim((string)($r['barcode'] ?? ''));
  if ($b !== '') return $b;
  $n = trim((string)($r['name'] ?? ''));
  return $n;
}

function ptype_label(string $ptype): string {
  return match ($ptype) {
    'mixer'      => '割物',
    'bottle'     => '酒',
    'consumable' => '消耗品',
    default      => ($ptype !== '' ? $ptype : '-'),
  };
}

$store_id = (int)require_store_selected();

/** ====== フィルタ ====== */
$q = trim((string)($_GET['q'] ?? ''));
$ptype = trim((string)($_GET['ptype'] ?? ''));   // mixer/bottle/consumable
$cat_id = (int)($_GET['cat'] ?? 0);

$only_low  = ((string)($_GET['low'] ?? '') === '1');  // reorder未満だけ
$only_zero = ((string)($_GET['zero'] ?? '') === '1'); // 0だけ

$sort = (string)($_GET['sort'] ?? 'name'); // name|qty|updated
$dir  = (string)($_GET['dir'] ?? 'asc');   // asc|desc

$allowedSort = ['name','qty','updated'];
if (!in_array($sort, $allowedSort, true)) $sort = 'name';
$dir = strtolower($dir) === 'desc' ? 'desc' : 'asc';

$orderSql = match ($sort) {
  'qty'     => "qty {$dir}, p.name asc",
  'updated' => "last_move_at {$dir}, p.name asc",
  default   => "p.name {$dir}",
};

/** ====== B案（店舗専用）の前提チェック ====== */
if (!table_exists($pdo, 'stock_products')) {
  http_response_code(500);
  exit('stock_products が存在しません');
}
if (!table_has_column($pdo, 'stock_products', 'store_id')) {
  http_response_code(500);
  exit('B案(店舗専用)には stock_products.store_id が必要です（列が見つかりません）');
}

/** ====== カテゴリ一覧 ====== */
$has_categories = table_exists($pdo, 'stock_categories');
$categories = [];

if ($has_categories) {
  try {
    $hasCatStore = table_has_column($pdo, 'stock_categories', 'store_id');
    if ($hasCatStore) {
      $st = $pdo->prepare("
        SELECT id, name
        FROM stock_categories
        WHERE is_active=1
          AND store_id = ?
        ORDER BY sort_order, name
      ");
      $st->execute([$store_id]);
    } else {
      $st = $pdo->prepare("
        SELECT id, name
        FROM stock_categories
        WHERE is_active=1
        ORDER BY sort_order, name
      ");
      $st->execute();
    }
    $categories = $st->fetchAll() ?: [];
  } catch (Throwable $e) {
    $categories = [];
  }
}

/** ====== 一覧取得 ====== */
$where = [];
$params = [];

$where[] = "p.is_active = 1";
$where[] = "p.store_id = ?"; // ★B案の核（店舗専用）

if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
  $params[] = '%'.$q.'%';
  $params[] = '%'.$q.'%';
}
if ($ptype !== '') {
  $where[] = "p.product_type = ?";
  $params[] = $ptype;
}
if ($cat_id > 0) {
  $where[] = "p.category_id = ?";
  $params[] = $cat_id;
}

$having = [];
if ($only_zero) $having[] = "qty = 0";
if ($only_low)  $having[] = "(reorder_point IS NOT NULL AND qty < reorder_point)";

$sql = "
  SELECT
    p.id,
    p.name,
    p.unit,
    p.barcode,
    p.product_type,
    p.category_id,
    p.reorder_point,
    COALESCE(i.qty, 0) AS qty,
    lm.last_move_at
  FROM stock_products p
  LEFT JOIN stock_items i
    ON i.product_id = p.id AND i.store_id = ?
  LEFT JOIN (
    SELECT product_id, MAX(created_at) AS last_move_at
    FROM stock_moves
    WHERE store_id = ?
    GROUP BY product_id
  ) lm ON lm.product_id = p.id
  " . (count($where) ? ("WHERE ".implode(" AND ", $where)) : "") . "
  " . (count($having) ? ("HAVING ".implode(" AND ", $having)) : "") . "
  ORDER BY {$orderSql}
";

$st = $pdo->prepare($sql);
$bind = array_merge([$store_id, $store_id, $store_id], $params);
$st->execute($bind);
$rows = $st->fetchAll() ?: [];

/** ====== 集計 ====== */
$total_items = count($rows);
$total_qty = 0;
$low_count = 0;
$zero_count = 0;

foreach ($rows as $r) {
  $qty2 = (int)$r['qty'];
  $total_qty += $qty2;
  if ($qty2 === 0) $zero_count++;
  $rp2 = $r['reorder_point'];
  if ($rp2 !== null && $qty2 < (int)$rp2) $low_count++;
}

/** ====== 画面 ====== */
$right = '
  <a class="btn" href="/seika-app/public/stock/move.php">入出庫</a>
  <a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>
';

render_page_start('在庫一覧');
render_header('在庫一覧', [
  'back_href' => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);

// category map
$catMap = [];
foreach ($categories as $c) $catMap[(int)$c['id']] = (string)$c['name'];

?>
<style>
/* ===== このページだけのUI（中学生でも使える） ===== */

.stock-top{
  display:grid;
  grid-template-columns: 1.2fr .8fr;
  gap:12px;
  align-items:start;
}
@media (max-width: 880px){
  .stock-top{ grid-template-columns: 1fr; }
}

.kpi{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:10px;
}
.kpi .chip{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.06);
  font-weight:900;
}
.kpi .dot{
  width:10px;height:10px;border-radius:999px;
}
.dot-ok{ background: var(--ok); }
.dot-warn{ background: var(--warn); }
.dot-ng{ background: var(--ng); }
.dot-att{ background: var(--accent); }

.filter-grid{
  display:grid;
  grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
  gap:10px;
  align-items:end;
}
.fg label{ display:block; font-size:12px; opacity:.8; margin-bottom:6px; }
.fg .in{ width:100%; min-height:42px; }
.fg .row2{ display:flex; gap:8px; }

.actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
  justify-content:flex-end;
}

/* テーブル（PC） */
.tbl{
  width:100%;
  border-collapse:collapse;
  font-size:14px;
}
.tbl th{
  text-align:left;
  padding:10px;
  border-bottom:2px solid var(--line);
  white-space:nowrap;
  opacity:.9;
}
.tbl td{
  padding:12px 10px;
  border-bottom:1px solid var(--line);
  vertical-align:middle;
}
.badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.06);
  white-space:nowrap;
}
.badge .dot{ width:8px;height:8px;border-radius:999px; }
.qty-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:6px 12px;
  border-radius:999px;
  border:1px solid var(--line);
  font-weight:1000;
}
.qty-ok{ background: rgba(52,211,153,.14); }
.qty-low{ background: rgba(245,158,11,.18); }
.qty-zero{ background: rgba(251,113,133,.18); }

/* スマホはカード表示（PCは現状維持） */
.pc-only{ display:block; }
.sp-only{ display:none; }
@media (max-width: 720px){
  .pc-only{ display:none; }
  .sp-only{ display:block; }

  .sp-cards{ display:grid; gap:10px; }
  .sp-card{
    border:1px solid var(--line);
    border-radius:18px;
    padding:12px;
    background:rgba(255,255,255,.04);
  }
  .sp-head{
    display:flex;
    gap:10px;
    align-items:flex-start;
    justify-content:space-between;
  }
  .sp-name{
    font-weight:1000;
    font-size:16px;
    line-height:1.2;
  }
  .sp-sub{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    margin-top:8px;
  }
  .sp-row{
    display:flex;
    justify-content:space-between;
    gap:10px;
    margin-top:10px;
    align-items:center;
  }
  .sp-row .muted{ font-size:12px; }
  .sp-actions{
    display:flex;
    gap:10px;
    flex-wrap:wrap;
    margin-top:12px;
  }

  /* バーコードはタップで展開（スマホでも邪魔にならない） */
  details.barcode-detail summary{
    cursor:pointer;
    user-select:none;
    font-weight:900;
  }
  details.barcode-detail summary::marker{ display:none; }
  details.barcode-detail summary:before{ content:"▶ "; }
  details.barcode-detail[open] summary:before{ content:"▼ "; }
  .barcode-value{
    margin-top:6px;
    word-break:break-all;
    font-size:13px;
  }
}
/* ==== PCテーブル：商品名の破綻（1文字改行）を防ぐ ==== */
@media (min-width: 800px){
  /* テーブルを固定レイアウトにして列幅の暴れを止める */
  .tbl{ table-layout: fixed; }

  /* 商品列（2列目）を広く確保 */
  .tbl th:nth-child(2),
  .tbl td:nth-child(2){
    width: 250px;        /* 好みで 300〜520 くらいで調整OK */
    min-width: 200px;
    white-space: nowrap; /* 1文字改行を止める */
    overflow: hidden;
    text-overflow: ellipsis; /* 長い商品名は … にする */
  }

  /* もし layout 側で break-all/anywhere が入っていても打ち消す */
  .tbl td:nth-child(2){
    word-break: normal !important;
    overflow-wrap: normal !important;
  }
}
/* 数量セル：中央に置く */
.td-qty{
  text-align:center;
  vertical-align:middle;
  white-space:nowrap;
}

/* pill：中身は「数字を真ん中」にする */
.qty-pill{
  position:relative;
  display:inline-flex;
  align-items:center;
  justify-content:center;

  height:28px;
  min-width:46px;
  padding:0 14px;
  padding-left:26px;          /* dot分の左余白 */
  line-height:1;
  font-weight:900;
}

/* dot：absoluteで配置して、センタリング計算から除外 */
.qty-pill .dot{
  position:absolute;
  left:12px;
  top:50%;
  transform:translateY(-50%);
  width:8px;
  height:8px;
  border-radius:999px;
}
.tbl td, .tbl th { vertical-align: middle; }
/* バーコード列は細く・省スペース */
.th-barcode,
.td-barcode{
  width:110px;          /* ← 好みで 120〜160px */
  max-width:120px;
  font-size:12px;
  color:#64748b;        /* 少し薄くして重要度を下げる */
}

/* 長い場合は省略表示 */
.td-barcode{
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
</style>

<div class="page">

  <div class="card">
    <div class="stock-top">
      <div>
        <div style="font-weight:1000; font-size:18px;">📦 在庫一覧</div>
        <div class="muted" style="margin-top:4px;">店舗の在庫を「見間違いゼロ」で見る画面（0在庫/低在庫を色で強調）</div>

        <div class="kpi">
          <div class="chip"><span class="dot dot-att"></span>件数 <?= (int)$total_items ?></div>
          <div class="chip"><span class="dot dot-ok"></span>合計数量 <?= (int)$total_qty ?></div>
          <div class="chip"><span class="dot dot-ng"></span>0在庫 <?= (int)$zero_count ?></div>
          <div class="chip"><span class="dot dot-warn"></span>低在庫 <?= (int)$low_count ?></div>
        </div>
      </div>

      <div class="actions">
        <a class="btn" href="/seika-app/public/stock/move.php<?= $ptype!==''?('?ptype='.urlencode($ptype)) : '' ?>">＋ 入出庫</a>
        <a class="btn" href="/seika-app/public/stock/list.php">条件クリア</a>
      </div>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <form method="get" class="filter-grid">
      <div class="fg">
        <label class="muted">検索（商品名 / バーコード）</label>
        <input class="btn in" name="q" value="<?= h($q) ?>" placeholder="例）角 / 鏡月 / 490...">
      </div>

      <div class="fg">
        <label class="muted">種別</label>
        <select class="btn in" name="ptype">
          <option value="">全部</option>
          <option value="bottle"     <?= $ptype==='bottle'?'selected':'' ?>>酒</option>
          <option value="mixer"      <?= $ptype==='mixer'?'selected':'' ?>>割物</option>
          <option value="consumable" <?= $ptype==='consumable'?'selected':'' ?>>消耗品</option>
        </select>
      </div>

      <?php if ($has_categories): ?>
      <div class="fg">
        <label class="muted">カテゴリ</label>
        <select class="btn in" name="cat">
          <option value="0">全部</option>
          <?php foreach ($categories as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === $cat_id) ? 'selected' : '' ?>>
              <?= h((string)$c['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <?php endif; ?>

      <div class="fg">
        <label class="muted">絞り込み</label>
        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <label class="muted" style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="zero" value="1" <?= $only_zero?'checked':'' ?>> 0だけ
          </label>
          <label class="muted" style="display:flex; gap:8px; align-items:center;">
            <input type="checkbox" name="low" value="1" <?= $only_low?'checked':'' ?>> 発注点未満
          </label>
        </div>
      </div>

      <div class="fg">
        <label class="muted">並び替え</label>
        <div class="row2">
          <select class="btn in" name="sort" style="flex:1;">
            <option value="name"    <?= $sort==='name'?'selected':'' ?>>名前</option>
            <option value="qty"     <?= $sort==='qty'?'selected':'' ?>>数量</option>
            <option value="updated" <?= $sort==='updated'?'selected':'' ?>>最終更新</option>
          </select>
          <select class="btn in" name="dir" style="min-width:120px;">
            <option value="asc"  <?= $dir==='asc'?'selected':'' ?>>昇順</option>
            <option value="desc" <?= $dir==='desc'?'selected':'' ?>>降順</option>
          </select>
        </div>
      </div>

      <div class="fg">
        <label class="muted">反映</label>
        <button class="btn btn-primary in" type="submit">検索</button>
      </div>
    </form>

    <div class="muted" style="margin-top:10px;">
      0在庫＝赤 / 低在庫＝黄 / OK＝緑（見間違い防止）
    </div>
  </div>

  <!-- =======================
       PC: いまのテーブル維持
  ======================= -->
  <div class="card pc-only" style="margin-top:14px;">
    <div style="overflow:auto;">
      <table class="tbl" style="min-width:980px;">
        <thead>
          <tr>
            <th style="width:50px;">ID</th>
            <th>商品</th>
            <th style="width:50px;">数量</th>
            <th style="width:50px;">単位</th>
            <th style="width:90px;">種別</th>
            <th style="width:140px;">カテゴリ</th>
            <th class="th-barcode">バーコード</th>
            <th style="width:50px; text-align:right;">発注点</th>
            <th style="width:100px;">最終更新</th>
            <th style="width:90px;">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="10" class="muted" style="padding:12px;">該当データがありません</td></tr>
          <?php endif; ?>

          <?php foreach ($rows as $r): ?>
            <?php
              $qty3 = (int)$r['qty'];
              $rp3  = $r['reorder_point'];
              $is_low = ($rp3 !== null && $qty3 < (int)$rp3);
              $is_zero = ($qty3 === 0);

              $dotColor = $is_zero ? 'var(--ng)' : ($is_low ? 'var(--warn)' : 'var(--ok)');
              $qtyClass = $is_zero ? 'qty-zero' : ($is_low ? 'qty-low' : 'qty-ok');

              $cid = (int)($r['category_id'] ?? 0);
              $cname = $cid > 0 ? ($catMap[$cid] ?? ('#'.$cid)) : '-';

              $last = $r['last_move_at'] ? (string)$r['last_move_at'] : '-';
              $moveq = move_q_for_row($r);
            ?>
            <tr>
              <td><?= (int)$r['id'] ?></td>
              <td style="font-weight:900;" title="<?= h((string)$r['name']) ?>"><?= h((string)$r['name']) ?></td>

              <td class="td-qty">
                <span class="qty-pill <?= h($qtyClass) ?>">
                  <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                  <?= (int)$qty3 ?>
                </span>
              </td>

              <td><?= h((string)($r['unit'] ?? '')) ?></td>

              <td>
                <span class="badge">
                  <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                  <?= h(ptype_label((string)($r['product_type'] ?? ''))) ?>
                </span>
              </td>

              <td><?= h($cname) ?></td>

              <td class="td-barcode" title="<?= h((string)($r['barcode'] ?? '')) ?>">
                <?= h((string)($r['barcode'] ?? '')) ?>
              </td>

              <td style="text-align:right;"><?= $rp3 === null ? '-' : (int)$rp3 ?></td>
              <td class="muted"><?= h($last) ?></td>

              <td>
                <a class="btn" style="min-height:auto; padding:6px 10px;"
                   href="/seika-app/public/stock/move.php?<?= h(http_build_query(['q'=>$moveq, 'ptype'=>$ptype ?: null])) ?>">
                  入出庫
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <!-- =======================
       SP: カードで見やすく
  ======================= -->
  <div class="card sp-only" style="margin-top:14px;">
    <?php if (!$rows): ?>
      <div class="muted">該当データがありません</div>
    <?php else: ?>
      <div class="sp-cards">
        <?php foreach ($rows as $r): ?>
          <?php
            $qty3 = (int)$r['qty'];
            $rp3  = $r['reorder_point'];
            $is_low = ($rp3 !== null && $qty3 < (int)$rp3);
            $is_zero = ($qty3 === 0);

            $dotColor = $is_zero ? 'var(--ng)' : ($is_low ? 'var(--warn)' : 'var(--ok)');
            $qtyClass = $is_zero ? 'qty-zero' : ($is_low ? 'qty-low' : 'qty-ok');

            $cid = (int)($r['category_id'] ?? 0);
            $cname = $cid > 0 ? ($catMap[$cid] ?? ('#'.$cid)) : '-';

            $last = $r['last_move_at'] ? (string)$r['last_move_at'] : '-';
            $moveq = move_q_for_row($r);
            $barcode = trim((string)($r['barcode'] ?? ''));
          ?>
          <div class="sp-card">
            <div class="sp-head">
              <div>
                <div class="sp-name"><?= h((string)$r['name']) ?></div>
                <div class="sp-sub">
                  <span class="badge">
                    <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                    <?= h(ptype_label((string)($r['product_type'] ?? ''))) ?>
                  </span>
                  <span class="badge">
                    <span class="dot dot-att"></span>
                    <?= h($cname) ?>
                  </span>
                </div>
              </div>

              <div style="text-align:right;">
                <div class="muted" style="font-size:12px;">在庫</div>
                <div style="margin-top:4px;">
                  <span class="qty-pill <?= h($qtyClass) ?>">
                    <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                    <?= (int)$qty3 ?>
                  </span>
                  <span class="muted" style="margin-left:6px; font-weight:900;"><?= h((string)($r['unit'] ?? '')) ?></span>
                </div>
              </div>
            </div>

            <div class="sp-row">
              <div class="muted">発注点</div>
              <div style="font-weight:900;"><?= $rp3 === null ? '—' : (int)$rp3 ?></div>
            </div>

            <div class="sp-row">
              <div class="muted">最終更新</div>
              <div class="muted" style="text-align:right;"><?= h($last) ?></div>
            </div>

            <div style="margin-top:10px;">
              <?php if ($barcode !== ''): ?>
                <details class="barcode-detail">
                  <summary class="muted">バーコード（タップで表示）</summary>
                  <div class="barcode-value"><?= h($barcode) ?></div>
                </details>
              <?php else: ?>
                <div class="muted">バーコード：—</div>
              <?php endif; ?>
            </div>

            <div class="sp-actions">
              <a class="btn btn-primary" style="flex:1; justify-content:center;"
                 href="/seika-app/public/stock/move.php?<?= h(http_build_query(['q'=>$moveq, 'ptype'=>$ptype ?: null])) ?>">
                この商品を入出庫
              </a>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>

<?php render_page_end(); ?>