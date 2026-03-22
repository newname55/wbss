<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';       // ✅ db() を確実にする
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
  http_response_code(403);
  exit('Forbidden');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

/** 商品編集ページ用の店舗必須保証 */
if (!function_exists('require_stock_product_edit_store_selected')) {
  function require_stock_product_edit_store_selected(): int {
    if (function_exists('require_store_selected')) {
      return (int)require_store_selected('/wbss/public/stock/products/edit.php');
    }
    if (session_status() !== PHP_SESSION_ACTIVE) {
      session_start();
    }
    $sid = (int)($_SESSION['store_id'] ?? 0);
    if ($sid <= 0) {
      header('Location: /wbss/public/store_select.php?return=' . rawurlencode('/wbss/public/stock/products/edit.php'));
      exit;
    }
    return $sid;
  }
}

$store_id = (int)require_stock_product_edit_store_selected();
$canEditPrices = can_edit_master();
$hasPurchasePrice = col_exists($pdo, 'stock_products', 'purchase_price_yen');
$hasSellingPrice = col_exists($pdo, 'stock_products', 'selling_price_yen');
$hasBottleBackRate = col_exists($pdo, 'stock_products', 'bottle_back_rate_pct');
$hasPriceHistory = col_exists($pdo, 'stock_product_price_history', 'id')
  && col_exists($pdo, 'stock_product_price_history', 'product_id')
  && col_exists($pdo, 'stock_product_price_history', 'new_price_yen');
$priceFeatureReady = $hasPurchasePrice && $hasSellingPrice && $hasPriceHistory;

$msg = '';
$err = '';

/**
 * uploads
 * FS : /var/www/html/wbss/public/uploads/products
 * URL: /wbss/public/uploads/products/xxxx.png
 */
$PUBLIC_FS = realpath(__DIR__ . '/../..'); // => .../public
if ($PUBLIC_FS === false) $PUBLIC_FS = __DIR__ . '/../..';
$UPLOAD_DIR_FS  = rtrim($PUBLIC_FS, '/') . '/uploads/products';
$UPLOAD_DIR_URL = '/wbss/public/uploads/products';

/* ===== helpers ===== */
function now_jst(): string {
  $dt = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
  return $dt->format('Y-m-d H:i:s');
}
function ensure_dir(string $dir): bool {
  if (is_dir($dir)) return is_writable($dir);
  @mkdir($dir, 0775, true);
  return is_dir($dir) && is_writable($dir);
}
function normalize_product_type(string $t): string {
  $t = trim($t);
  if ($t === '') return 'mixer';
  $allow = ['mixer','bottle','consumable','food','other'];
  return in_array($t, $allow, true) ? $t : 'mixer';
}
function int_or_null($v): ?int {
  $s = trim((string)$v);
  if ($s === '') return null;
  if (!preg_match('/^\d+$/', $s)) return null;
  return (int)$s;
}
function parse_price_or_null($v): ?int {
  $s = trim((string)$v);
  if ($s === '') return null;
  $s = str_replace([',', '¥', '￥', ' '], '', $s);
  if (!preg_match('/^\d+(?:\.\d+)?$/', $s)) {
    throw new InvalidArgumentException('価格は0以上の数字で入力してください');
  }
  return (int)round((float)$s);
}
function parse_percent_or_null($v): ?string {
  $s = trim((string)$v);
  if ($s === '') return null;
  $s = str_replace(['%', '％', ' '], '', $s);
  if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $s)) {
    throw new InvalidArgumentException('バック率は0以上の数値で入力してください');
  }
  $value = (float)$s;
  if ($value < 0 || $value > 100) {
    throw new InvalidArgumentException('バック率は0〜100で入力してください');
  }
  return number_format($value, 2, '.', '');
}
function fmt_yen($v): string {
  if ($v === null || $v === '') return '-';
  return '¥' . number_format((int)$v);
}

/** カラム存在チェック（B案 store_id フィルタに使う） */
function col_exists(PDO $pdo, string $table, string $col): bool {
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

function fetch_categories(PDO $pdo, int $store_id): array {
  $hasStore = col_exists($pdo, 'stock_categories', 'store_id');

  if ($hasStore) {
    // ✅B案：店別 + 有効のみ（NULL/0互換は捨てる）
    $st = $pdo->prepare("
      SELECT id, name
      FROM stock_categories
      WHERE is_active = 1
        AND store_id = ?
      ORDER BY sort_order, name, id
    ");
    $st->execute([$store_id]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // フォールバック（store_id列が無い場合）
  $st = $pdo->query("
    SELECT id, name
    FROM stock_categories
    WHERE is_active = 1
    ORDER BY sort_order, name, id
  ");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** ✅B案：stock_products も store_id で縛る（列がある場合） */
function fetch_product(PDO $pdo, int $id, int $store_id): ?array {
  $hasStore = col_exists($pdo, 'stock_products', 'store_id');

  if ($hasStore) {
    $st = $pdo->prepare("SELECT * FROM stock_products WHERE id=? AND store_id=? LIMIT 1");
    $st->execute([$id, $store_id]);
  } else {
    $st = $pdo->prepare("SELECT * FROM stock_products WHERE id=? LIMIT 1");
    $st->execute([$id]);
  }

  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

/** 画像保存（成功したら image_path(URL) を返す） */
function save_uploaded_image(string $fsDir, string $urlPrefix, array $file, int $productId): ?string {
  if (!isset($file['tmp_name']) || $file['tmp_name'] === '') return null;
  if (!is_uploaded_file($file['tmp_name'])) return null;

  if (!ensure_dir($fsDir)) {
    throw new RuntimeException('画像フォルダに書き込めません（権限/存在を確認）: ' . $fsDir);
  }

  if (!empty($file['error']) && (int)$file['error'] !== UPLOAD_ERR_OK) {
    throw new RuntimeException('アップロードエラー(code=' . (int)$file['error'] . ')');
  }

  $size = (int)($file['size'] ?? 0);
  if ($size <= 0) throw new RuntimeException('画像サイズが不正です');
  if ($size > 5 * 1024 * 1024) throw new RuntimeException('画像が大きすぎます（最大5MB）');

  $name = (string)($file['name'] ?? '');
  $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
  if ($ext === '') $ext = 'jpg';

  $allow = ['jpg','jpeg','png','webp'];
  if (!in_array($ext, $allow, true)) {
    throw new RuntimeException('対応していない画像形式です（jpg/png/webp）');
  }

  $fname   = sprintf('p%06d_%s.%s', $productId, date('Ymd_His'), $ext);
  $destFs  = rtrim($fsDir, '/') . '/' . $fname;
  $destUrl = rtrim($urlPrefix, '/') . '/' . $fname;

  if (!@move_uploaded_file($file['tmp_name'], $destFs)) {
    throw new RuntimeException('画像保存に失敗しました（権限/容量）');
  }
  @chmod($destFs, 0644);
  return $destUrl;
}

/* ===== state ===== */
$id = (int)($_GET['id'] ?? 0);
$categories = fetch_categories($pdo, $store_id);

if ($id > 0) {
  $p = fetch_product($pdo, $id, $store_id);
  if (!$p) {
    http_response_code(404);
    exit('Not found');
  }
} else {
  $p = [
    'id' => 0,
    'name' => '',
    'product_type' => 'mixer',
    'category_id' => null,
    'barcode' => null,
    'unit' => '本',
    'is_stock_managed' => 1,
    'reorder_point' => null,
    'purchase_price_yen' => null,
    'selling_price_yen' => null,
    'bottle_back_rate_pct' => null,
    'is_active' => 1,
    'image_path' => null,
  ];
}

$priceHistory = [];
if ((int)$p['id'] > 0 && $hasPriceHistory) {
  $st = $pdo->prepare("
    SELECT
      h.price_type, h.old_price_yen, h.new_price_yen, h.note, h.changed_at,
      u.display_name
    FROM stock_product_price_history h
    LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.store_id = ?
      AND h.product_id = ?
    ORDER BY h.changed_at DESC, h.id DESC
    LIMIT 12
  ");
  $st->execute([$store_id, (int)$p['id']]);
  $priceHistory = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ===== POST ===== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  if ($action === 'save') {
    $pid = (int)($_POST['id'] ?? 0);
    $cur = $pid > 0 ? fetch_product($pdo, $pid, $store_id) : null;

    $name = trim((string)($_POST['name'] ?? ($cur['name'] ?? '')));
    $product_type = normalize_product_type((string)($_POST['product_type'] ?? ($cur['product_type'] ?? 'mixer')));
    $category_id = int_or_null($_POST['category_id'] ?? ($cur['category_id'] ?? ''));
    $barcode = trim((string)($_POST['barcode'] ?? ($cur['barcode'] ?? '')));
    $unit = trim((string)($_POST['unit'] ?? ($cur['unit'] ?? '本')));
    if ($unit === '') $unit = '本';

    $is_stock_managed = (int)($_POST['is_stock_managed'] ?? ($cur['is_stock_managed'] ?? 1));
    $is_stock_managed = ($is_stock_managed === 0) ? 0 : 1;

    $reorder_point = int_or_null($_POST['reorder_point'] ?? ($cur['reorder_point'] ?? ''));
    $is_active = (int)($_POST['is_active'] ?? ($cur['is_active'] ?? 1));
    $is_active = ($is_active === 0) ? 0 : 1;
    $purchase_price = ($canEditPrices && $hasPurchasePrice)
      ? parse_price_or_null($_POST['purchase_price_yen'] ?? ($cur['purchase_price_yen'] ?? ''))
      : (($cur['purchase_price_yen'] ?? null) === null ? null : (int)$cur['purchase_price_yen']);
    $selling_price = ($canEditPrices && $hasSellingPrice)
      ? parse_price_or_null($_POST['selling_price_yen'] ?? ($cur['selling_price_yen'] ?? ''))
      : (($cur['selling_price_yen'] ?? null) === null ? null : (int)$cur['selling_price_yen']);
    $bottle_back_rate = ($canEditPrices && $hasBottleBackRate)
      ? parse_percent_or_null($_POST['bottle_back_rate_pct'] ?? ($cur['bottle_back_rate_pct'] ?? ''))
      : (($cur['bottle_back_rate_pct'] ?? null) === null ? null : number_format((float)$cur['bottle_back_rate_pct'], 2, '.', ''));
    $price_note = trim((string)($_POST['price_note'] ?? ''));
    if (mb_strlen($price_note) > 255) $price_note = mb_substr($price_note, 0, 255);

    if ($name === '') {
      $err = '商品名は必須です';
    } else {
      try {
        $pdo->beginTransaction();
        $now = now_jst();

        $prodHasStore = col_exists($pdo, 'stock_products', 'store_id');

        if ($pid <= 0) {
          if ($prodHasStore) {
            $st = $pdo->prepare("
              INSERT INTO stock_products
                (store_id, category_id, product_type, name, is_stock_managed, reorder_point, barcode, unit, purchase_price_yen, selling_price_yen, bottle_back_rate_pct, is_active, created_at, updated_at)
              VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([
              $store_id,
              $category_id,
              $product_type,
              $name,
              $is_stock_managed,
              $reorder_point,
              ($barcode === '' ? null : $barcode),
              $unit,
              $hasPurchasePrice ? $purchase_price : null,
              $hasSellingPrice ? $selling_price : null,
              $hasBottleBackRate ? $bottle_back_rate : null,
              $is_active,
              $now,
              $now,
            ]);
          } else {
            $st = $pdo->prepare("
              INSERT INTO stock_products
                (category_id, product_type, name, is_stock_managed, reorder_point, barcode, unit, purchase_price_yen, selling_price_yen, bottle_back_rate_pct, is_active, created_at, updated_at)
              VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([
              $category_id,
              $product_type,
              $name,
              $is_stock_managed,
              $reorder_point,
              ($barcode === '' ? null : $barcode),
              $unit,
              $hasPurchasePrice ? $purchase_price : null,
              $hasSellingPrice ? $selling_price : null,
              $hasBottleBackRate ? $bottle_back_rate : null,
              $is_active,
              $now,
              $now,
            ]);
          }
          $pid = (int)$pdo->lastInsertId();
        } else {
          if ($prodHasStore) {
            $st = $pdo->prepare("
              UPDATE stock_products
              SET category_id=?, product_type=?, name=?, is_stock_managed=?, reorder_point=?, barcode=?, unit=?, purchase_price_yen=?, selling_price_yen=?, bottle_back_rate_pct=?, is_active=?, updated_at=?
              WHERE id=? AND store_id=?
              LIMIT 1
            ");
            $st->execute([
              $category_id,
              $product_type,
              $name,
              $is_stock_managed,
              $reorder_point,
              ($barcode === '' ? null : $barcode),
              $unit,
              $hasPurchasePrice ? $purchase_price : ($cur['purchase_price_yen'] ?? null),
              $hasSellingPrice ? $selling_price : ($cur['selling_price_yen'] ?? null),
              $hasBottleBackRate ? $bottle_back_rate : ($cur['bottle_back_rate_pct'] ?? null),
              $is_active,
              $now,
              $pid,
              $store_id,
            ]);
          } else {
            $st = $pdo->prepare("
              UPDATE stock_products
              SET category_id=?, product_type=?, name=?, is_stock_managed=?, reorder_point=?, barcode=?, unit=?, purchase_price_yen=?, selling_price_yen=?, bottle_back_rate_pct=?, is_active=?, updated_at=?
              WHERE id=?
              LIMIT 1
            ");
            $st->execute([
              $category_id,
              $product_type,
              $name,
              $is_stock_managed,
              $reorder_point,
              ($barcode === '' ? null : $barcode),
              $unit,
              $hasPurchasePrice ? $purchase_price : ($cur['purchase_price_yen'] ?? null),
              $hasSellingPrice ? $selling_price : ($cur['selling_price_yen'] ?? null),
              $hasBottleBackRate ? $bottle_back_rate : ($cur['bottle_back_rate_pct'] ?? null),
              $is_active,
              $now,
              $pid,
            ]);
          }
        }

        if ($canEditPrices && $priceFeatureReady) {
          $oldPurchase = ($cur['purchase_price_yen'] ?? null) === null ? null : (int)$cur['purchase_price_yen'];
          $oldSelling = ($cur['selling_price_yen'] ?? null) === null ? null : (int)$cur['selling_price_yen'];
          $historyStmt = $pdo->prepare("
            INSERT INTO stock_product_price_history
              (store_id, product_id, price_type, old_price_yen, new_price_yen, note, changed_by, changed_at)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?)
          ");
          if ($oldPurchase !== $purchase_price) {
            $historyStmt->execute([
              $store_id,
              $pid,
              'purchase',
              $oldPurchase,
              $purchase_price,
              ($price_note !== '' ? $price_note : null),
              current_user_id(),
              $now,
            ]);
          }
          if ($oldSelling !== $selling_price) {
            $historyStmt->execute([
              $store_id,
              $pid,
              'selling',
              $oldSelling,
              $selling_price,
              ($price_note !== '' ? $price_note : null),
              current_user_id(),
              $now,
            ]);
          }
        }

        // 画像アップロード（任意）
        if (isset($_FILES['image']) && is_array($_FILES['image'])) {
          $newPath = save_uploaded_image($UPLOAD_DIR_FS, $UPLOAD_DIR_URL, $_FILES['image'], $pid);
          if ($newPath !== null) {
            if ($prodHasStore) {
              $pdo->prepare("UPDATE stock_products SET image_path=?, updated_at=? WHERE id=? AND store_id=? LIMIT 1")
                  ->execute([$newPath, $now, $pid, $store_id]);
            } else {
              $pdo->prepare("UPDATE stock_products SET image_path=?, updated_at=? WHERE id=? LIMIT 1")
                  ->execute([$newPath, $now, $pid]);
            }
          }
        }

        $pdo->commit();
        header('Location: /wbss/public/stock/products/edit.php?id=' . $pid . '&ok=1');
        exit;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
      }
    }
  }

  if ($action === 'delete_image') {
    $pid = (int)($_POST['id'] ?? 0);
    if ($pid > 0) {
      try {
        $prodHasStore = col_exists($pdo, 'stock_products', 'store_id');
        if ($prodHasStore) {
          $pdo->prepare("UPDATE stock_products SET image_path=NULL, updated_at=? WHERE id=? AND store_id=? LIMIT 1")
              ->execute([now_jst(), $pid, $store_id]);
        } else {
          $pdo->prepare("UPDATE stock_products SET image_path=NULL, updated_at=? WHERE id=? LIMIT 1")
              ->execute([now_jst(), $pid]);
        }
        header('Location: /wbss/public/stock/products/edit.php?id=' . $pid . '&ok=1');
        exit;
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    }
  }
}

if ((int)($_GET['ok'] ?? 0) === 1 && $msg === '' && $err === '') $msg = '保存しました';

$right = '<a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>';

render_page_start('商品編集');
render_header('商品編集', [
  'back_href'  => '/wbss/public/stock/products/index.php',
  'back_label' => '← 商品一覧',
  'right_html' => $right,
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="font-weight:1000; font-size:16px; margin-bottom:10px;">
      <?= ((int)$p['id'] > 0) ? '編集' : '新規作成' ?>
    </div>

    <form method="post" enctype="multipart/form-data" autocomplete="off">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
        <div style="grid-column:1/-1;">
          <label class="muted">商品名（必須）</label><br>
          <input class="btn" style="width:100%;" name="name" value="<?= h((string)$p['name']) ?>" required>
        </div>

        <div>
          <label class="muted">種別（必須）</label><br>
          <select class="btn" name="product_type" style="width:100%;">
            <?php
              $types = [
                'mixer' => '割物(mixer)',
                'bottle' => '酒(ボトル)',
                'consumable' => '消耗品',
                'food' => 'フード',
                'other' => 'その他',
              ];
              $curType = (string)($p['product_type'] ?? 'mixer');
              foreach ($types as $k => $label):
            ?>
              <option value="<?= h($k) ?>" <?= ($curType === $k) ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="muted">カテゴリ（未分類OK）</label><br>
          <select class="btn" name="category_id" style="width:100%;">
            <option value="">（未分類）</option>
            <?php foreach ($categories as $c): ?>
              <?php $sel = ((string)($p['category_id'] ?? '') === (string)$c['id']) ? 'selected' : ''; ?>
              <option value="<?= (int)$c['id'] ?>" <?= $sel ?>><?= h((string)$c['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="muted">JAN/バーコード（任意）</label><br>
          <input class="btn" style="width:100%;" name="barcode" value="<?= h((string)($p['barcode'] ?? '')) ?>">
        </div>

        <div>
          <label class="muted">単位</label><br>
          <input class="btn" style="width:100%;" name="unit" value="<?= h((string)($p['unit'] ?? '本')) ?>" placeholder="本/個/ml...">
        </div>

        <div>
          <label class="muted">在庫管理</label><br>
          <select class="btn" name="is_stock_managed" style="width:100%;">
            <option value="1" <?= ((int)$p['is_stock_managed']===1)?'selected':'' ?>>する</option>
            <option value="0" <?= ((int)$p['is_stock_managed']===0)?'selected':'' ?>>しない</option>
          </select>
        </div>

        <div>
          <label class="muted">発注点（任意）</label><br>
          <input class="btn" style="width:100%;" name="reorder_point" inputmode="numeric" value="<?= h((string)($p['reorder_point'] ?? '')) ?>">
        </div>

        <div>
          <label class="muted">有効</label><br>
          <select class="btn" name="is_active" style="width:100%;">
            <option value="1" <?= ((int)$p['is_active']===1)?'selected':'' ?>>有効</option>
            <option value="0" <?= ((int)$p['is_active']===0)?'selected':'' ?>>無効</option>
          </select>
        </div>

        <?php
          $purchase = ($p['purchase_price_yen'] ?? null) === null ? null : (int)$p['purchase_price_yen'];
          $selling = ($p['selling_price_yen'] ?? null) === null ? null : (int)$p['selling_price_yen'];
          $bottleBackRate = ($p['bottle_back_rate_pct'] ?? null) === null ? null : (float)$p['bottle_back_rate_pct'];
          $unitGross = ($purchase !== null && $selling !== null) ? ($selling - $purchase) : null;
          $grossRate = ($unitGross !== null && $selling > 0) ? (($unitGross / $selling) * 100) : null;
          $expectedBack = ($selling !== null && $bottleBackRate !== null) ? (int)round($selling * ($bottleBackRate / 100)) : null;
        ?>
        <div style="grid-column:1/-1; margin-top:4px; padding:12px; border:1px solid var(--line); border-radius:16px; background:rgba(255,255,255,.03);">
          <div style="display:flex; justify-content:space-between; gap:10px; align-items:flex-start; flex-wrap:wrap;">
            <div>
              <div style="font-weight:1000; font-size:15px;">価格と粗利</div>
              <div class="muted">商品マスタから価格も管理できます。価格変更はadmin以上のみです。</div>
            </div>
            <div class="muted">
              <?php if ($priceFeatureReady): ?>
                <?= $canEditPrices ? '更新可能' : '閲覧のみ' ?>
              <?php else: ?>
                DB設定待ち
              <?php endif; ?>
            </div>
          </div>

          <?php if (!$priceFeatureReady): ?>
            <div class="muted" style="margin-top:10px;">
              価格機能のDB設定が未完了です。<code>docs/add_stock_price_tracking.sql</code> を確認してください。
            </div>
          <?php endif; ?>

          <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:12px;">
            <div>
              <label class="muted">仕入れ価格</label><br>
              <?php if ($canEditPrices && $hasPurchasePrice): ?>
                <input class="btn" style="width:100%;" name="purchase_price_yen" inputmode="numeric" value="<?= h((string)($p['purchase_price_yen'] ?? '')) ?>" placeholder="例) 1800">
              <?php else: ?>
                <div class="btn" style="width:100%; display:flex; align-items:center; min-height:44px;"><?= h(fmt_yen($p['purchase_price_yen'] ?? null)) ?></div>
              <?php endif; ?>
            </div>

            <div>
              <label class="muted">販売価格</label><br>
              <?php if ($canEditPrices && $hasSellingPrice): ?>
                <input class="btn" style="width:100%;" name="selling_price_yen" inputmode="numeric" value="<?= h((string)($p['selling_price_yen'] ?? '')) ?>" placeholder="例) 3000">
              <?php else: ?>
                <div class="btn" style="width:100%; display:flex; align-items:center; min-height:44px;"><?= h(fmt_yen($p['selling_price_yen'] ?? null)) ?></div>
              <?php endif; ?>
            </div>

            <div>
              <label class="muted">ボトルバック率</label><br>
              <?php if ($canEditPrices && $hasBottleBackRate): ?>
                <input class="btn" style="width:100%;" name="bottle_back_rate_pct" inputmode="decimal" value="<?= h((string)($p['bottle_back_rate_pct'] ?? '')) ?>" placeholder="例) 10">
              <?php else: ?>
                <div class="btn" style="width:100%; display:flex; align-items:center; min-height:44px;"><?= h($bottleBackRate === null ? '-' : number_format($bottleBackRate, 2) . '%') ?></div>
              <?php endif; ?>
            </div>

            <div>
              <label class="muted">粗利/1<?= h((string)($p['unit'] ?? '個')) ?></label><br>
              <div class="btn" style="width:100%; display:flex; align-items:center; min-height:44px; color:<?= ($unitGross !== null && $unitGross < 0) ? '#fda4af' : '#86efac' ?>;">
                <?= h($unitGross === null ? '-' : (($unitGross > 0 ? '+' : '') . fmt_yen($unitGross))) ?>
              </div>
            </div>

            <div>
              <label class="muted">粗利率</label><br>
              <div class="btn" style="width:100%; display:flex; align-items:center; min-height:44px;">
                <?= h($grossRate === null ? '-' : number_format($grossRate, 1) . '%') ?>
              </div>
            </div>

            <div>
              <label class="muted">想定バック/1<?= h((string)($p['unit'] ?? '個')) ?></label><br>
              <div class="btn" style="width:100%; display:flex; align-items:center; min-height:44px;">
                <?= h($expectedBack === null ? '-' : fmt_yen($expectedBack)) ?>
              </div>
            </div>

            <?php if ($canEditPrices && $priceFeatureReady): ?>
              <div style="grid-column:1/-1;">
                <label class="muted">価格変更メモ（任意）</label><br>
                <input class="btn" style="width:100%;" name="price_note" value="" placeholder="例) 仕入先変更 / 値上げ対応">
              </div>
            <?php endif; ?>
          </div>

          <?php if ((int)$p['id'] > 0): ?>
            <div style="margin-top:12px;">
              <div class="muted" style="margin-bottom:6px;">価格変更履歴</div>
              <?php if ($priceHistory): ?>
                <div style="display:grid; gap:8px;">
                  <?php foreach ($priceHistory as $history): ?>
                    <div style="padding:10px 12px; border-radius:12px; border:1px solid var(--line); background:rgba(255,255,255,.04);">
                      <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
                        <strong><?= h(((string)$history['price_type'] === 'purchase') ? '仕入れ' : '販売') ?></strong>
                        <span class="muted"><?= h((string)$history['changed_at']) ?></span>
                        <span class="muted"><?= h((string)($history['display_name'] ?? '')) ?></span>
                      </div>
                      <div style="margin-top:4px;"><?= h(fmt_yen($history['old_price_yen'] ?? null)) ?> → <?= h(fmt_yen($history['new_price_yen'] ?? null)) ?></div>
                      <?php if ((string)($history['note'] ?? '') !== ''): ?>
                        <div class="muted" style="margin-top:4px;"><?= h((string)$history['note']) ?></div>
                      <?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <div class="muted">まだ価格変更履歴はありません。</div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        </div>

        <div style="grid-column:1/-1;">
          <label class="muted">商品画像（任意 / 5MBまで）</label><br>
          <input class="btn" style="width:100%;" type="file" name="image" accept="image/*">
          <?php if (!empty($p['image_path'])): ?>
            <div style="margin-top:10px; display:flex; gap:10px; align-items:center; flex-wrap:wrap;">
              <img src="<?= h((string)$p['image_path']) ?>" alt=""
                   style="max-height:130px; border-radius:14px; border:1px solid var(--line); background:#fff;">
              <form method="post" onsubmit="return confirm('画像を削除しますか？');">
                <input type="hidden" name="action" value="delete_image">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <button class="btn" type="submit">画像削除</button>
              </form>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
        <button class="btn btn-primary" type="submit">保存</button>
        <a class="btn" href="/wbss/public/stock/products/index.php">一覧へ戻る</a>
      </div>
    </form>
  </div>
</div>

<?php render_page_end(); ?>
