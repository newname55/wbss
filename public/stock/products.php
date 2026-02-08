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

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = db();

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

$HAS_STORE_ID    = col_exists($pdo, 'stock_products', 'store_id');
$HAS_PTYPE       = col_exists($pdo, 'stock_products', 'product_type');
$HAS_CATEGORY_ID = col_exists($pdo, 'stock_products', 'category_id');
$HAS_IMAGE_URL   = col_exists($pdo, 'stock_products', 'image_url');
$HAS_UPDATED_AT  = col_exists($pdo, 'stock_products', 'updated_at');

$msg = '';
$err = '';

$edit_id = (int)($_GET['edit_id'] ?? 0);
$q = trim((string)($_GET['q'] ?? ''));

/** 入力（POST） */
$id          = (int)($_POST['id'] ?? 0);
$name        = trim((string)($_POST['name'] ?? ''));
$barcode     = trim((string)($_POST['barcode'] ?? ''));
$unit        = trim((string)($_POST['unit'] ?? '本'));
$is_active   = isset($_POST['is_active']) ? 1 : 0;
$product_type = trim((string)($_POST['product_type'] ?? 'mixer'));
$category_id = (int)($_POST['category_id'] ?? 0);

/** カテゴリ一覧（あれば） */
$categories = [];
try {
  $catSql = "SELECT id, name FROM stock_categories";
  $catWhere = [];
  $catParams = [];
  if (col_exists($pdo, 'stock_categories', 'store_id')) {
    $catWhere[] = "store_id = ?";
    $catParams[] = $store_id;
  }
  if ($catWhere) $catSql .= " WHERE " . implode(" AND ", $catWhere);
  $catSql .= " ORDER BY sort_order IS NULL, sort_order, name";
  $st = $pdo->prepare($catSql);
  $st->execute($catParams);
  $categories = $st->fetchAll();
} catch (Throwable $e) {
  $categories = [];
}

/** 画像アップロード（保存先/URL作成） */
function handle_upload_image(int $store_id, int $product_id): array {
  // return ['ok'=>bool, 'url'=>string|null, 'err'=>string|null]
  if (!isset($_FILES['image_file']) || !is_array($_FILES['image_file'])) {
    return ['ok'=>true, 'url'=>null, 'err'=>null];
  }
  $f = $_FILES['image_file'];
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
    return ['ok'=>true, 'url'=>null, 'err'=>null];
  }
  if (($f['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
    return ['ok'=>false, 'url'=>null, 'err'=>'画像アップロードに失敗しました（error='.$f['error'].'）'];
  }
  if (!is_uploaded_file($f['tmp_name'])) {
    return ['ok'=>false, 'url'=>null, 'err'=>'不正なアップロードです'];
  }
  $size = (int)($f['size'] ?? 0);
  if ($size <= 0 || $size > 3_500_000) {
    return ['ok'=>false, 'url'=>null, 'err'=>'画像サイズが大きすぎます（最大3.5MB）'];
  }

  $tmp = (string)$f['tmp_name'];
  $mime = @mime_content_type($tmp) ?: '';
  $ext = '';
  if ($mime === 'image/jpeg') $ext = 'jpg';
  elseif ($mime === 'image/png') $ext = 'png';
  elseif ($mime === 'image/webp') $ext = 'webp';
  else return ['ok'=>false, 'url'=>null, 'err'=>'対応形式は JPG/PNG/WEBP のみです'];

  // 保存先（public配下に置く）
  $baseDir = '/var/www/html/seika-app/public/uploads/products/' . $store_id;
  if (!is_dir($baseDir)) {
    @mkdir($baseDir, 0775, true);
  }
  if (!is_dir($baseDir) || !is_writable($baseDir)) {
    return ['ok'=>false, 'url'=>null, 'err'=>'保存先ディレクトリに書き込めません'];
  }

  $fname = 'p' . $product_id . '_' . date('Ymd_His') . '.' . $ext;
  $dest = $baseDir . '/' . $fname;

  if (!@move_uploaded_file($tmp, $dest)) {
    return ['ok'=>false, 'url'=>null, 'err'=>'画像の保存に失敗しました'];
  }
  @chmod($dest, 0664);

  $url = '/seika-app/public/uploads/products/' . $store_id . '/' . $fname;
  return ['ok'=>true, 'url'=>$url, 'err'=>null];
}

/** 保存（POST） */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($name === '') {
    $err = '商品名は必須です';
  } else {
    try {
      $pdo->beginTransaction();

      if ($id > 0) {
        $cols = [];
        $vals = [];

        $cols[] = "name = ?";      $vals[] = $name;
        $cols[] = "barcode = ?";   $vals[] = ($barcode !== '' ? $barcode : null);
        $cols[] = "unit = ?";      $vals[] = ($unit !== '' ? $unit : '本');
        $cols[] = "is_active = ?"; $vals[] = $is_active;

        if ($HAS_PTYPE) {
          $cols[] = "product_type = ?"; $vals[] = ($product_type !== '' ? $product_type : 'mixer');
        }
        if ($HAS_CATEGORY_ID) {
          $cols[] = "category_id = ?"; $vals[] = ($category_id > 0 ? $category_id : null);
        }
        if ($HAS_UPDATED_AT) {
          $cols[] = "updated_at = NOW()";
        }

        // store_id がある環境は、他店のを誤って編集しないように縛る
        $where = "id = ?";
        $vals[] = $id;
        if ($HAS_STORE_ID) {
          $where .= " AND store_id = ?";
          $vals[] = $store_id;
        }

        $sql = "UPDATE stock_products SET " . implode(", ", $cols) . " WHERE {$where}";
        $st = $pdo->prepare($sql);
        $st->execute($vals);

        $saved_id = $id;
        $msg = '商品を更新しました';
      } else {
        $cols = ['name','barcode','unit','is_active'];
        $vals = [$name, ($barcode !== '' ? $barcode : null), ($unit !== '' ? $unit : '本'), $is_active];

        if ($HAS_PTYPE) {
          $cols[] = 'product_type';
          $vals[] = ($product_type !== '' ? $product_type : 'mixer');
        }
        if ($HAS_CATEGORY_ID) {
          $cols[] = 'category_id';
          $vals[] = ($category_id > 0 ? $category_id : null);
        }
        if ($HAS_STORE_ID) {
          $cols[] = 'store_id';
          $vals[] = $store_id;
        }

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO stock_products (" . implode(',', $cols) . ") VALUES ({$ph})";
        $pdo->prepare($sql)->execute($vals);

        $saved_id = (int)$pdo->lastInsertId();
        $msg = '商品を追加しました';
      }

      // 画像アップロード（image_url列がある場合のみ反映）
      if ($HAS_IMAGE_URL) {
        $up = handle_upload_image($store_id, $saved_id);
        if (!$up['ok']) {
          throw new RuntimeException((string)$up['err']);
        }
        if ($up['url']) {
          $where = "id = ?";
          $vals2 = [$up['url'], $saved_id];
          if ($HAS_STORE_ID) {
            $where .= " AND store_id = ?";
            $vals2[] = $store_id;
          }
          $pdo->prepare("UPDATE stock_products SET image_url = ? WHERE {$where}")->execute($vals2);
          $msg .= '（画像更新）';
        }
      }

      $pdo->commit();

      // 追加/更新後：編集状態を解除（一覧に戻す）
      $edit_id = 0;
      $id = 0;
      $name = $barcode = '';
      $unit = '本';
      $is_active = 1;
      $product_type = 'mixer';
      $category_id = 0;

    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->getMessage();
    }
  }
}

/** 編集読み込み */
$edit = null;
if ($edit_id > 0) {
  $sql = "SELECT * FROM stock_products WHERE id = ?";
  $params = [$edit_id];
  if ($HAS_STORE_ID) {
    $sql .= " AND store_id = ?";
    $params[] = $store_id;
  }
  $sql .= " LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $edit = $st->fetch();
  if ($edit) {
    $id = (int)$edit['id'];
    $name = (string)$edit['name'];
    $barcode = (string)($edit['barcode'] ?? '');
    $unit = (string)($edit['unit'] ?? '本');
    $is_active = (int)($edit['is_active'] ?? 1);
    if ($HAS_PTYPE) $product_type = (string)($edit['product_type'] ?? 'mixer');
    if ($HAS_CATEGORY_ID) $category_id = (int)($edit['category_id'] ?? 0);
  } else {
    $edit_id = 0;
  }
}

/** 一覧取得 */
$where = [];
$params = [];
if ($HAS_STORE_ID) {
  $where[] = "p.store_id = ?";
  $params[] = $store_id;
}
if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

$select = "
  SELECT
    p.id, p.name, p.barcode, p.unit, p.is_active,
    " . ($HAS_PTYPE ? "p.product_type," : "NULL AS product_type,") . "
    " . ($HAS_CATEGORY_ID ? "p.category_id," : "NULL AS category_id,") . "
    " . ($HAS_IMAGE_URL ? "p.image_url," : "NULL AS image_url,") . "
    p.created_at
    " . ($HAS_CATEGORY_ID ? ", c.name AS category_name" : ", NULL AS category_name") . "
  FROM stock_products p
  " . ($HAS_CATEGORY_ID ? "LEFT JOIN stock_categories c ON c.id = p.category_id" : "") . "
";
$sql = $select;
if ($where) $sql .= " WHERE " . implode(" AND ", $where);
$sql .= " ORDER BY p.is_active DESC, p.name ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll();

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>';

render_page_start('商品マスタ');
render_header('商品マスタ', [
  'back_href' => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <div style="font-weight:1000; font-size:16px;">商品マスタ（<?= $HAS_STORE_ID ? '店舗別' : '共通' ?>）</div>
        <div class="muted">新人向けに画像を付けられます（<?= $HAS_IMAGE_URL ? '対応' : '※image_url列が無いので表示のみ' ?>）</div>
      </div>

      <form method="get" style="display:flex; gap:10px; flex-wrap:wrap;">
        <input class="btn" style="width:280px;" name="q" value="<?= h($q) ?>" placeholder="検索：商品名 / JAN">
        <button class="btn btn-primary" type="submit">検索</button>
        <?php if ($q !== ''): ?>
          <a class="btn" href="<?= h(strtok($_SERVER['REQUEST_URI'], '?')) ?>">解除</a>
        <?php endif; ?>
      </form>
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
      <div style="font-weight:1000;"><?= $edit ? '商品編集' : '商品追加' ?></div>
      <div class="muted">※ 追加/更新後は一覧に戻ります</div>
    </div>

    <form method="post" enctype="multipart/form-data" style="margin-top:12px;">
      <input type="hidden" name="id" value="<?= (int)$id ?>">

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:260px;">
          <label class="muted">商品名（必須）</label><br>
          <input class="btn" style="width:100%;" name="name" value="<?= h($name) ?>" placeholder="例) 角瓶 / 炭酸（割物）" required>
        </div>

        <div style="min-width:240px;">
          <label class="muted">バーコード（任意）</label><br>
          <input class="btn" style="width:100%;" name="barcode" value="<?= h($barcode) ?>" placeholder="JAN等">
        </div>

        <div style="min-width:140px;">
          <label class="muted">単位</label><br>
          <input class="btn" style="width:100%;" name="unit" value="<?= h($unit) ?>" placeholder="本/個/ml">
        </div>

        <?php if ($HAS_PTYPE): ?>
          <div style="min-width:200px;">
            <label class="muted">種別</label><br>
            <select class="btn" name="product_type" style="width:100%;">
              <option value="mixer" <?= $product_type==='mixer'?'selected':'' ?>>割物</option>
              <option value="bottle" <?= $product_type==='bottle'?'selected':'' ?>>酒（ボトル）</option>
              <option value="consumable" <?= $product_type==='consumable'?'selected':'' ?>>消耗品</option>
            </select>
          </div>
        <?php endif; ?>

        <?php if ($HAS_CATEGORY_ID): ?>
          <div style="min-width:240px;">
            <label class="muted">カテゴリ</label><br>
            <select class="btn" name="category_id" style="width:100%;">
              <option value="0">未設定</option>
              <?php foreach ($categories as $c): ?>
                <option value="<?= (int)$c['id'] ?>" <?= ((int)$c['id'] === (int)$category_id) ? 'selected' : '' ?>>
                  <?= h((string)$c['name']) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </div>
        <?php endif; ?>

        <div style="min-width:140px;">
          <label class="muted">状態</label><br>
          <label class="btn" style="gap:10px; justify-content:flex-start;">
            <input type="checkbox" name="is_active" value="1" <?= $is_active ? 'checked' : '' ?>>
            有効
          </label>
        </div>

        <div style="min-width:260px;">
          <label class="muted">商品画像（任意）</label><br>
          <input class="btn" style="width:100%;" type="file" name="image_file" accept="image/jpeg,image/png,image/webp">
          <div class="muted" style="margin-top:6px;">
            目安：JPG/PNG/WEBP、3.5MBまで
            <?php if (!$HAS_IMAGE_URL): ?>（※DBにimage_url列が無いので保存はしません）<?php endif; ?>
          </div>
        </div>

        <div>
          <label class="muted"><?= $edit ? '更新' : '追加' ?></label><br>
          <button class="btn btn-primary" type="submit"><?= $edit ? '更新する' : '追加する' ?></button>
        </div>

        <?php if ($edit): ?>
          <div>
            <label class="muted">解除</label><br>
            <a class="btn" href="?">編集をやめる</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if ($edit && $HAS_IMAGE_URL): ?>
        <div style="margin-top:12px;">
          <div class="muted">現在の画像</div>
          <?php $img = (string)($edit['image_url'] ?? ''); ?>
          <?php if ($img !== ''): ?>
            <img src="<?= h($img) ?>" style="margin-top:6px; width:240px; max-width:100%; border:1px solid var(--line); border-radius:14px; background:#fff; object-fit:contain;">
          <?php else: ?>
            <div class="muted" style="margin-top:6px;">画像なし</div>
          <?php endif; ?>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div style="font-weight:1000;">商品一覧（<?= count($rows) ?>件）</div>
      <div class="muted">画像は「長押し/ホバー」で確認しやすいように小さめ表示</div>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ID</th>
            <?php if ($HAS_IMAGE_URL): ?>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">画像</th>
            <?php endif; ?>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品名</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">JAN</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">単位</th>
            <?php if ($HAS_PTYPE): ?>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">種別</th>
            <?php endif; ?>
            <?php if ($HAS_CATEGORY_ID): ?>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">カテゴリ</th>
            <?php endif; ?>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">状態</th>
            <th style="padding:8px; border-bottom:1px solid var(--line);"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $off = !(int)$r['is_active'];
              $ptypeLabel = (string)($r['product_type'] ?? '');
              $ptypeView = $ptypeLabel !== '' ? $ptypeLabel : '-';
              $img = (string)($r['image_url'] ?? '');
            ?>
            <tr style="<?= $off ? 'opacity:.55;' : '' ?>">
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= (int)$r['id'] ?></td>

              <?php if ($HAS_IMAGE_URL): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line);">
                  <?php if ($img !== ''): ?>
                    <img
                      src="<?= h($img) ?>"
                      style="width:44px; height:44px; border-radius:10px; border:1px solid var(--line); background:#fff; object-fit:cover;"
                      title="長押し/ホバーで確認"
                      loading="lazy"
                    >
                  <?php else: ?>
                    <span class="muted">-</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>

              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$r['name']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['barcode'] ?? '')) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['unit'] ?? '')) ?></td>

              <?php if ($HAS_PTYPE): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h($ptypeView) ?></td>
              <?php endif; ?>

              <?php if ($HAS_CATEGORY_ID): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['category_name'] ?? '')) ?></td>
              <?php endif; ?>

              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <?= $off ? '<span class="muted">無効</span>' : '有効' ?>
              </td>

              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;">
                <a class="btn" href="?edit_id=<?= (int)$r['id'] ?><?= $q!=='' ? '&q='.urlencode($q) : '' ?>">編集</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>

  <div class="muted" style="margin-top:12px;">
    <?php if (!$HAS_IMAGE_URL): ?>
      ■ 画像保存を有効にするには：stock_products に <b>image_url</b>（VARCHAR）を追加してください。<br>
    <?php endif; ?>
    ■ 「最強（詳細モーダル）」は move.php 側で ℹ️ → product_detail API から image_url を返すだけで繋がります。
  </div>

</div>
<?php render_page_end(); ?>