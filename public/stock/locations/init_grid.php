<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin')) {
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

$pdo = db();

$msg = '';
$err = '';

/** フィルタ */
$ptype = (string)($_GET['ptype'] ?? '');        // '', mixer, bottle, consumable
$loc_id = (int)($_GET['location_id'] ?? 0);     // 0=all

/** locations */
$st = $pdo->prepare("SELECT id, name FROM stock_locations WHERE store_id=? AND is_active=1 ORDER BY sort_order, id");
$st->execute([$store_id]);
$locations = $st->fetchAll();

/** products */
$sqlP = "SELECT id, name, product_type FROM stock_products WHERE is_active=1";
$paramsP = [];
if ($ptype !== '') {
  $sqlP .= " AND product_type = ?";
  $paramsP[] = $ptype;
}
$sqlP .= " ORDER BY product_type, name, id";
$st = $pdo->prepare($sqlP);
$st->execute($paramsP);
$products = $st->fetchAll();

/** 件数見積り（表示用） */
$locCount = ($loc_id > 0) ? 1 : count($locations);
$prodCount = count($products);
$targetComb = $locCount * $prodCount;

/**
 * 初期化：
 *  - safe_init : 「不足分だけ作る」(INSERT missing only)
 *  - reset_zero: 「全組み合わせを0で上書き」(危険)
 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $mode = (string)($_POST['mode'] ?? '');
  $ptypePost = (string)($_POST['ptype'] ?? '');
  $locPost = (int)($_POST['location_id'] ?? 0);

  try {
    $pdo->beginTransaction();

    // 1) stock_items（店×商品）も足りなければ作る（qty=0）
    //    move.php は stock_items を更新するので、こっちも揃えておくと安全
    $sqlInsItems = "
      INSERT INTO stock_items (store_id, product_id, qty, created_at)
      SELECT :store_id, p.id, 0, NOW()
      FROM stock_products p
      LEFT JOIN stock_items si
        ON si.store_id = :store_id AND si.product_id = p.id
      WHERE p.is_active=1
        " . ($ptypePost !== '' ? " AND p.product_type = :ptype " : "") . "
        AND si.id IS NULL
    ";
    $st = $pdo->prepare($sqlInsItems);
    $st->bindValue(':store_id', $store_id, PDO::PARAM_INT);
    if ($ptypePost !== '') $st->bindValue(':ptype', $ptypePost, PDO::PARAM_STR);
    $st->execute();
    $created_items = $st->rowCount();

    if ($mode === 'safe_init') {
      // 2) stock_item_locations（店×場所×商品）不足分だけ作る
      $sqlInit = "
        INSERT INTO stock_item_locations (store_id, location_id, product_id, qty, created_at, updated_at)
        SELECT :store_id, l.id, p.id, 0, NOW(), NOW()
        FROM stock_locations l
        JOIN stock_products p ON p.is_active=1
        LEFT JOIN stock_item_locations sil
          ON sil.store_id = :store_id
         AND sil.location_id = l.id
         AND sil.product_id = p.id
        WHERE l.store_id = :store_id AND l.is_active=1
          " . ($locPost > 0 ? " AND l.id = :loc_id " : "") . "
          " . ($ptypePost !== '' ? " AND p.product_type = :ptype " : "") . "
          AND sil.id IS NULL
      ";
      $st = $pdo->prepare($sqlInit);
      $st->bindValue(':store_id', $store_id, PDO::PARAM_INT);
      if ($locPost > 0)  $st->bindValue(':loc_id', $locPost, PDO::PARAM_INT);
      if ($ptypePost !== '') $st->bindValue(':ptype', $ptypePost, PDO::PARAM_STR);
      $st->execute();
      $created = $st->rowCount();

      $pdo->commit();
      $msg = "初期化OK（不足分のみ作成）: stock_items +{$created_items} / stock_item_locations +{$created}";
    }
    elseif ($mode === 'reset_zero') {
      // 2) 危険：対象の全組み合わせを 0 で上書き
      //    まず対象を確実に揃える（不足分作成）
      $sqlInit = "
        INSERT INTO stock_item_locations (store_id, location_id, product_id, qty, created_at, updated_at)
        SELECT :store_id, l.id, p.id, 0, NOW(), NOW()
        FROM stock_locations l
        JOIN stock_products p ON p.is_active=1
        LEFT JOIN stock_item_locations sil
          ON sil.store_id = :store_id
         AND sil.location_id = l.id
         AND sil.product_id = p.id
        WHERE l.store_id = :store_id AND l.is_active=1
          " . ($locPost > 0 ? " AND l.id = :loc_id " : "") . "
          " . ($ptypePost !== '' ? " AND p.product_type = :ptype " : "") . "
          AND sil.id IS NULL
      ";
      $st = $pdo->prepare($sqlInit);
      $st->bindValue(':store_id', $store_id, PDO::PARAM_INT);
      if ($locPost > 0)  $st->bindValue(':loc_id', $locPost, PDO::PARAM_INT);
      if ($ptypePost !== '') $st->bindValue(':ptype', $ptypePost, PDO::PARAM_STR);
      $st->execute();
      $created = $st->rowCount();

      // その上で0に更新
      $sqlUpd = "
        UPDATE stock_item_locations sil
        JOIN stock_locations l ON l.id = sil.location_id
        JOIN stock_products p ON p.id = sil.product_id
        SET sil.qty = 0, sil.updated_at = NOW()
        WHERE sil.store_id = :store_id
          AND l.store_id = :store_id
          AND l.is_active = 1
          AND p.is_active = 1
          " . ($locPost > 0 ? " AND l.id = :loc_id " : "") . "
          " . ($ptypePost !== '' ? " AND p.product_type = :ptype " : "") . "
      ";
      $st = $pdo->prepare($sqlUpd);
      $st->bindValue(':store_id', $store_id, PDO::PARAM_INT);
      if ($locPost > 0)  $st->bindValue(':loc_id', $locPost, PDO::PARAM_INT);
      if ($ptypePost !== '') $st->bindValue(':ptype', $ptypePost, PDO::PARAM_STR);
      $st->execute();
      $updated = $st->rowCount();

      $pdo->commit();
      $msg = "⚠️ リセットOK（0で上書き）: stock_items +{$created_items} / stock_item_locations +{$created}, 0上書き {$updated}行";
    }
    else {
      throw new RuntimeException('modeが不正です');
    }
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

render_page_start('在庫 初期化（場所×商品）');
render_header('在庫 初期化（場所×商品）', [
  'back_href'  => '/wbss/public/stock/locations/index.php',
  'back_label' => '← 場所マスタ',
  'right_html' => '<a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>',
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="font-weight:1000; font-size:16px;">場所 × 商品 在庫（stock_item_locations）を初期化</div>
    <div class="muted" style="margin-top:6px; line-height:1.6;">
      基本は <b>不足分だけ作成</b>（既存qtyは壊さない）。<br>
      例）場所追加／商品追加後に押す。<br>
      対象見込み：<b><?= (int)$locCount ?></b>場所 × <b><?= (int)$prodCount ?></b>商品 ＝ <b><?= (int)$targetComb ?></b> 組
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <form method="post" onsubmit="return confirmSubmit(this);">
      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div style="min-width:240px;">
          <label class="muted">場所</label><br>
          <select class="btn" name="location_id" style="min-width:240px;">
            <option value="0">すべての場所</option>
            <?php foreach ($locations as $l): ?>
              <option value="<?= (int)$l['id'] ?>" <?= $loc_id===(int)$l['id'] ? 'selected' : '' ?>>
                <?= h((string)$l['name']) ?> (#<?= (int)$l['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="min-width:240px;">
          <label class="muted">種別（product_type）</label><br>
          <select class="btn" name="ptype" style="min-width:240px;">
            <option value="" <?= $ptype===''?'selected':'' ?>>すべて</option>
            <option value="mixer" <?= $ptype==='mixer'?'selected':'' ?>>mixer（割物）</option>
            <option value="bottle" <?= $ptype==='bottle'?'selected':'' ?>>bottle（酒）</option>
            <option value="consumable" <?= $ptype==='consumable'?'selected':'' ?>>consumable（消耗品）</option>
          </select>
        </div>

        <div style="display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-primary" type="submit" name="mode" value="safe_init">
            ✅ 不足分だけ作成
          </button>
          <button class="btn" type="submit" name="mode" value="reset_zero" style="border-color:rgba(251,113,133,.45);">
            ⚠️ 0で上書き（危険）
          </button>
        </div>
      </div>

      <div class="muted" style="margin-top:10px;">
        ※「0で上書き」は棚卸前にやると事故るので、基本は使わない想定。
      </div>
    </form>
  </div>

</div>

<script>
function confirmSubmit(form){
  const mode = form.querySelector('button[type="submit"][name="mode"]:focus')?.value
            || form.querySelector('input[name="mode"]')?.value
            || '';
  if (mode === 'reset_zero') {
    return confirm('本当に 0で上書きしますか？（在庫が消えます）');
  }
  return true;
}
</script>

<?php render_page_end(); ?>