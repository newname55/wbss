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

/** ✅ store_id 必須保証（store.php に無くてもこのファイルで保証する） */
if (!function_exists('require_store_selected')) {
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
}

$store_id = (int)require_store_selected();

$msg = '';
$err = '';

/**
 * uploads
 * FS : /var/www/html/seika-app/public/uploads/products
 * URL: /seika-app/public/uploads/products/xxxx.png
 */
$PUBLIC_FS = realpath(__DIR__ . '/../..'); // => .../public
if ($PUBLIC_FS === false) $PUBLIC_FS = __DIR__ . '/../..';
$UPLOAD_DIR_FS  = rtrim($PUBLIC_FS, '/') . '/uploads/products';
$UPLOAD_DIR_URL = '/seika-app/public/uploads/products';

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
    'is_active' => 1,
    'image_path' => null,
  ];
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
                (store_id, category_id, product_type, name, is_stock_managed, reorder_point, barcode, unit, is_active, created_at, updated_at)
              VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
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
              $is_active,
              $now,
              $now,
            ]);
          } else {
            $st = $pdo->prepare("
              INSERT INTO stock_products
                (category_id, product_type, name, is_stock_managed, reorder_point, barcode, unit, is_active, created_at, updated_at)
              VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $st->execute([
              $category_id,
              $product_type,
              $name,
              $is_stock_managed,
              $reorder_point,
              ($barcode === '' ? null : $barcode),
              $unit,
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
              SET category_id=?, product_type=?, name=?, is_stock_managed=?, reorder_point=?, barcode=?, unit=?, is_active=?, updated_at=?
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
              $is_active,
              $now,
              $pid,
              $store_id,
            ]);
          } else {
            $st = $pdo->prepare("
              UPDATE stock_products
              SET category_id=?, product_type=?, name=?, is_stock_managed=?, reorder_point=?, barcode=?, unit=?, is_active=?, updated_at=?
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
              $is_active,
              $now,
              $pid,
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
        header('Location: /seika-app/public/stock/products/edit.php?id=' . $pid . '&ok=1');
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
        header('Location: /seika-app/public/stock/products/edit.php?id=' . $pid . '&ok=1');
        exit;
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    }
  }
}

if ((int)($_GET['ok'] ?? 0) === 1 && $msg === '' && $err === '') $msg = '保存しました';

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>';

render_page_start('商品編集');
render_header('商品編集', [
  'back_href'  => '/seika-app/public/stock/products/index.php',
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
        <a class="btn" href="/seika-app/public/stock/products/index.php">一覧へ戻る</a>
      </div>
    </form>
  </div>
</div>

<?php render_page_end(); ?>