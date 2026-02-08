<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

function jout(array $a): void {
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = db();

$name = trim((string)($_POST['name'] ?? ''));
$unit = trim((string)($_POST['unit'] ?? '本'));
$barcode = trim((string)($_POST['barcode'] ?? ''));
$category_id = (int)($_POST['category_id'] ?? 0);
$product_type = trim((string)($_POST['product_type'] ?? ''));

if ($name === '') jout(['ok'=>false,'error'=>'商品名を入力してください']);
if ($barcode === '') jout(['ok'=>false,'error'=>'バーコードを入力してください']);
if (!preg_match('/^[0-9]{6,20}$/', $barcode)) jout(['ok'=>false,'error'=>'バーコード形式が不正です']);

// product_type列があるか
$st = $pdo->prepare("
  SELECT 1 FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME='stock_products'
    AND COLUMN_NAME='product_type'
  LIMIT 1
");
$st->execute();
$has_ptype = (bool)$st->fetchColumn();

$allowed_ptype = ['alcohol','mixer','consumable','other'];
if ($has_ptype) {
  if ($product_type === '') $product_type = 'other';
  if (!in_array($product_type, $allowed_ptype, true)) {
    jout(['ok'=>false,'error'=>'product_typeが不正です']);
  }
}

try {
  // barcode重複チェック
  $st = $pdo->prepare("SELECT id FROM stock_products WHERE barcode = ? LIMIT 1");
  $st->execute([$barcode]);
  if ($st->fetch()) jout(['ok'=>false,'error'=>'そのバーコードは既に登録されています']);

  // category_idがあれば存在確認（任意）
  if ($category_id > 0) {
    $st = $pdo->prepare("SELECT id FROM stock_categories WHERE id=? LIMIT 1");
    $st->execute([$category_id]);
    if (!$st->fetch()) $category_id = 0;
  }

  // search_text は name + barcode を入れておく（LIKE用）
  $search_text = trim($name . ' ' . $barcode);

  if ($has_ptype) {
    $st = $pdo->prepare("
      INSERT INTO stock_products
        (name, unit, barcode, category_id, product_type, search_text, is_active)
      VALUES
        (?, ?, ?, ?, ?, ?, 1)
    ");
    $st->execute([$name, $unit, $barcode, ($category_id>0?$category_id:null), $product_type, $search_text]);
  } else {
    $st = $pdo->prepare("
      INSERT INTO stock_products
        (name, unit, barcode, category_id, search_text, is_active)
      VALUES
        (?, ?, ?, ?, ?, 1)
    ");
    $st->execute([$name, $unit, $barcode, ($category_id>0?$category_id:null), $search_text]);
  }

  $new_id = (int)$pdo->lastInsertId();
  jout(['ok'=>true,'id'=>$new_id]);

} catch (Throwable $e) {
  jout(['ok'=>false,'error'=>$e->getMessage()]);
}