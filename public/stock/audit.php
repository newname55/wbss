<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
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

/** GET filters */
$days = (int)($_GET['days'] ?? 7);
if ($days < 1) $days = 1;
if ($days > 90) $days = 90;

$limit = (int)($_GET['limit'] ?? 80);
if ($limit < 20) $limit = 20;
if ($limit > 300) $limit = 300;

$since = (new DateTimeImmutable('now'))->modify("-{$days} days")->format('Y-m-d H:i:s');

/** schema helper */
function has_col(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

$has_items_store = has_col($pdo, 'stock_items', 'store_id');
$has_moves_store = has_col($pdo, 'stock_moves', 'store_id');

$msg = '';
$err = '';

/* =========================
   ① Negative stock (現物と矛盾)
========================= */
try {
  if ($has_items_store) {
    $st = $pdo->prepare("
      SELECT i.id, i.product_id, i.qty, p.name, p.unit, p.product_type, p.category_id
      FROM stock_items i
      JOIN stock_products p ON p.id = i.product_id
      WHERE i.store_id = ?
        AND i.qty < 0
      ORDER BY i.qty ASC, i.id DESC
      LIMIT 200
    ");
    $st->execute([$store_id]);
  } else {
    $st = $pdo->query("
      SELECT i.id, i.product_id, i.qty, p.name, p.unit, p.product_type, p.category_id
      FROM stock_items i
      JOIN stock_products p ON p.id = i.product_id
      WHERE i.qty < 0
      ORDER BY i.qty ASC, i.id DESC
      LIMIT 200
    ");
  }
  $neg = $st->fetchAll();
} catch (Throwable $e) {
  $neg = [];
  $err = $e->getMessage();
}

/* =========================
   ② Orphan moves (参照切れ)
========================= */
try {
  if ($has_moves_store) {
    $st = $pdo->prepare("
      SELECT m.id, m.created_at, m.product_id, m.move_type, m.delta, m.note,
             u.display_name,
             p.name AS product_name
      FROM stock_moves m
      LEFT JOIN stock_products p ON p.id = m.product_id
      LEFT JOIN users u ON u.id = m.created_by
      WHERE m.store_id = ?
        AND m.created_at >= ?
        AND p.id IS NULL
      ORDER BY m.id DESC
      LIMIT ?
    ");
    $st->execute([$store_id, $since, $limit]);
  } else {
    $st = $pdo->prepare("
      SELECT m.id, m.created_at, m.product_id, m.move_type, m.delta, m.note,
             u.display_name,
             p.name AS product_name
      FROM stock_moves m
      LEFT JOIN stock_products p ON p.id = m.product_id
      LEFT JOIN users u ON u.id = m.created_by
      WHERE m.created_at >= ?
        AND p.id IS NULL
      ORDER BY m.id DESC
      LIMIT ?
    ");
    $st->execute([$since, $limit]);
  }
  $orph = $st->fetchAll();
} catch (Throwable $e) {
  $orph = [];
  if ($err === '') $err = $e->getMessage();
}

/* =========================
   ③ Duplicate barcodes (重複JAN)
   ※ uq_barcode があるなら基本起きないが、NULL/空の扱いで確認する
========================= */
try {
  $st = $pdo->query("
    SELECT barcode, COUNT(*) AS cnt
    FROM stock_products
    WHERE barcode IS NOT NULL AND barcode <> ''
    GROUP BY barcode
    HAVING cnt >= 2
    ORDER BY cnt DESC, barcode
    LIMIT 200
  ");
  $dup_bar = $st->fetchAll();
} catch (Throwable $e) {
  $dup_bar = [];
  if ($err === '') $err = $e->getMessage();
}

/* =========================
   ④ Recent suspicious moves (最近の怪しい動き)
   - adjust が多い/大きい
   - delta が大きい（入出庫の異常）
========================= */
try {
  if ($has_moves_store) {
    $st = $pdo->prepare("
      SELECT m.id, m.created_at, p.name, p.unit, m.move_type, m.delta, m.note,
             u.display_name
      FROM stock_moves m
      JOIN stock_products p ON p.id = m.product_id
      LEFT JOIN users u ON u.id = m.created_by
      WHERE m.store_id = ?
        AND m.created_at >= ?
      ORDER BY m.id DESC
      LIMIT ?
    ");
    $st->execute([$store_id, $since, $limit]);
  } else {
    $st = $pdo->prepare("
      SELECT m.id, m.created_at, p.name, p.unit, m.move_type, m.delta, m.note,
             u.display_name
      FROM stock_moves m
      JOIN stock_products p ON p.id = m.product_id
      LEFT JOIN users u ON u.id = m.created_by
      WHERE m.created_at >= ?
      ORDER BY m.id DESC
      LIMIT ?
    ");
    $st->execute([$since, $limit]);
  }
  $recent = $st->fetchAll();

  // 画面表示用に「怪しいフラグ」を付与
  $sus = [];
  foreach ($recent as $r) {
    $t = (string)$r['move_type'];
    $d = (int)$r['delta'];
    $abs = abs($d);

    $flag = '';
    if ($t === 'adjust') {
      if ($abs >= 50) $flag = '棚卸（大）';
      else $flag = '棚卸';
    } else {
      if ($abs >= 24) $flag = '大量';
    }
    if ($flag !== '') {
      $r['flag'] = $flag;
      $sus[] = $r;
    }
  }
} catch (Throwable $e) {
  $sus = [];
  if ($err === '') $err = $e->getMessage();
}

/* =========================
   ⑤ Products missing required fields (必須欠け)
   - category_id が NULL
   - product_type が空
========================= */
try {
  $st = $pdo->prepare("
    SELECT id, name, barcode, product_type, category_id, unit, is_active
    FROM stock_products
    WHERE (category_id IS NULL OR product_type IS NULL OR product_type = '')
    ORDER BY id DESC
    LIMIT 200
  ");
  $st->execute();
  $bad_master = $st->fetchAll();
} catch (Throwable $e) {
  $bad_master = [];
  if ($err === '') $err = $e->getMessage();
}

render_page_start('監査（在庫チェック）');
render_header('監査（在庫チェック）', [
  'back_href' => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => '<a class="btn" href="/seika-app/public/stock/moves/history.php">入出庫履歴</a>',
]);

?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:16px;">🛡 監査（異常がないかチェック）</div>
        <div class="muted" style="margin-top:6px; line-height:1.6;">
          ここは「ミスや不整合を見つける」画面。<br>
          <b>マイナス在庫</b>や<b>棚卸の大量調整</b>があると要確認。
        </div>
      </div>

      <form method="get" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
        <div>
          <div class="muted">期間</div>
          <select class="btn" name="days">
            <?php foreach ([1,3,7,14,30,60,90] as $d): ?>
              <option value="<?= (int)$d ?>" <?= $days===$d?'selected':'' ?>>過去 <?= (int)$d ?> 日</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="muted">表示件数</div>
          <select class="btn" name="limit">
            <?php foreach ([50,80,120,200,300] as $n): ?>
              <option value="<?= (int)$n ?>" <?= $limit===$n?'selected':'' ?>><?= (int)$n ?>件</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <div class="muted">更新</div>
          <button class="btn btn-primary" type="submit">再表示</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ===== Summary cards ===== -->
  <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(220px,1fr)); gap:14px; margin-top:14px;">
    <div class="card">
      <div class="muted">マイナス在庫</div>
      <div style="font-weight:1000; font-size:26px; margin-top:6px; color: var(--ng);"><?= (int)count($neg) ?></div>
      <div class="muted" style="margin-top:6px;">0が理想</div>
    </div>
    <div class="card">
      <div class="muted">参照切れ（moves→products）</div>
      <div style="font-weight:1000; font-size:26px; margin-top:6px; color: var(--warn);"><?= (int)count($orph) ?></div>
      <div class="muted" style="margin-top:6px;">商品削除/DB差分の疑い</div>
    </div>
    <div class="card">
      <div class="muted">怪しい操作（棚卸/大量）</div>
      <div style="font-weight:1000; font-size:26px; margin-top:6px; color: var(--warn);"><?= (int)count($sus) ?></div>
      <div class="muted" style="margin-top:6px;">最近 <?= (int)$days ?> 日</div>
    </div>
    <div class="card">
      <div class="muted">必須欠け（商品マスタ）</div>
      <div style="font-weight:1000; font-size:26px; margin-top:6px; color: var(--warn);"><?= (int)count($bad_master) ?></div>
      <div class="muted" style="margin-top:6px;">カテゴリ/種別</div>
    </div>
  </div>

  <!-- ===== ① Negative ===== -->
  <div class="card" style="margin-top:16px;">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
      <div style="font-weight:1000;">① マイナス在庫（最優先で直す）</div>
      <a class="btn" href="/seika-app/public/stock/move.php">入出庫で直す</a>
    </div>
    <div class="muted" style="margin-top:6px;">出庫が先に入った/棚卸がズレた/二重計上など。</div>

    <?php if (!$neg): ?>
      <div class="muted" style="margin-top:12px;">✅ なし</div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:10px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">種別</th>
              <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">在庫</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">対処</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($neg as $r): ?>
              <tr>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$r['name']) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['product_type'] ?? '')) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right; color:var(--ng); font-weight:1000;">
                  <?= (int)$r['qty'] ?><?= h((string)($r['unit'] ?? '')) ?>
                </td>
                <td style="padding:8px; border-bottom:1px solid var(--line);">
                  <a class="btn" style="min-height:42px;" href="/seika-app/public/stock/move.php">入出庫/棚卸で修正</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===== ④ Suspicious ===== -->
  <div class="card" style="margin-top:16px;">
    <div style="font-weight:1000;">② 最近の怪しい操作（棚卸/大量）</div>
    <div class="muted" style="margin-top:6px;">
      棚卸（adjust）や、入出庫の大量（|delta|が大きい）を抽出。
    </div>

    <?php if (!$sus): ?>
      <div class="muted" style="margin-top:12px;">✅ なし</div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:10px;">
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
            <?php foreach ($sus as $r): ?>
              <?php
                $t = (string)$r['move_type'];
                $accent = ($t==='in') ? 'var(--ok)' : (($t==='out') ? 'var(--ng)' : 'var(--warn)');
              ?>
              <tr>
                <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$r['created_at']) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$r['name']) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);">
                  <span style="display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                    <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:<?= h($accent) ?>;"></span>
                    <?= h($t) ?> / <?= h((string)($r['flag'] ?? '')) ?>
                  </span>
                </td>
                <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right; font-weight:1000;">
                  <?= (int)$r['delta'] ?><?= h((string)($r['unit'] ?? '')) ?>
                </td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['note'] ?? '')) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['display_name'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===== ⑤ Bad master ===== -->
  <div class="card" style="margin-top:16px;">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:center;">
      <div style="font-weight:1000;">③ 商品マスタ：必須欠け（カテゴリ/種別）</div>
      <a class="btn" href="/seika-app/public/stock/products/index.php">商品マスタへ</a>
    </div>
    <div class="muted" style="margin-top:6px;">
      ここが欠けると「登録できない」「検索が弱い」「集計が崩れる」原因になる。
    </div>

    <?php if (!$bad_master): ?>
      <div class="muted" style="margin-top:12px;">✅ なし</div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:10px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ID</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">barcode</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">product_type</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">category_id</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">修正</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($bad_master as $r): ?>
              <tr>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= (int)$r['id'] ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$r['name']) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['barcode'] ?? '')) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['product_type'] ?? '')) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= $r['category_id'] === null ? '<span style="color:var(--ng); font-weight:1000;">NULL</span>' : (int)$r['category_id'] ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);">
                  <a class="btn" style="min-height:42px;" href="/seika-app/public/stock/products/index.php?edit_id=<?= (int)$r['id'] ?>">編集</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===== ② Orphan ===== -->
  <div class="card" style="margin-top:16px;">
    <div style="font-weight:1000;">④ 参照切れ（moves → products）</div>
    <div class="muted" style="margin-top:6px;">
      通常は起きない。DB統合/手動削除/環境差分があると出る。
    </div>

    <?php if (!$orph): ?>
      <div class="muted" style="margin-top:12px;">✅ なし</div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:10px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">日時</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">product_id</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">種別</th>
              <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">変動</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">メモ</th>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">操作</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($orph as $r): ?>
              <tr>
                <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$r['created_at']) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= (int)$r['product_id'] ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$r['move_type']) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;"><?= (int)$r['delta'] ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['note'] ?? '')) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['display_name'] ?? '')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

  <!-- ===== ③ dup barcode ===== -->
  <div class="card" style="margin-top:16px;">
    <div style="font-weight:1000;">⑤ バーコード重複</div>
    <div class="muted" style="margin-top:6px;">同一JANが複数商品に入ると、スキャンが事故る。</div>

    <?php if (!$dup_bar): ?>
      <div class="muted" style="margin-top:12px;">✅ なし</div>
    <?php else: ?>
      <div style="overflow:auto; margin-top:10px;">
        <table style="width:100%; border-collapse:collapse; font-size:13px;">
          <thead>
            <tr>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">barcode</th>
              <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">件数</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($dup_bar as $r): ?>
              <tr>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$r['barcode']) ?></td>
                <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right; font-weight:1000; color:var(--warn);"><?= (int)$r['cnt'] ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>

</div>
<?php render_page_end(); ?>