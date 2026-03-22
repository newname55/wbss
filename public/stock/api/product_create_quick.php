<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';

header('Content-Type: application/json; charset=utf-8');

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
  http_response_code(403);
  echo json_encode(['ok'=>false,'error'=>'Forbidden']);
  exit;
}

$store_id = current_store_id();
if ($store_id === null) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'store not selected']);
  exit;
}
$store_id = (int)$store_id;

$raw = file_get_contents('php://input');
$in = json_decode($raw, true);
if (!is_array($in)) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'invalid json']);
  exit;
}

$name = trim((string)($in['name'] ?? ''));
$unit = trim((string)($in['unit'] ?? ''));
$barcode = trim((string)($in['barcode'] ?? ''));

if ($name === '' || $unit === '') {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'name/unit required']);
  exit;
}

// barcode は任意（空でもOK）だが、入ってるなら数字/記号少し許容
if ($barcode !== '' && strlen($barcode) > 64) {
  http_response_code(400);
  echo json_encode(['ok'=>false,'error'=>'barcode too long']);
  exit;
}

$pdo = db();

try {
  // 既に同じbarcodeがあるならそれを返す（重複防止）
  if ($barcode !== '') {
    $st = $pdo->prepare("SELECT id FROM stock_products WHERE store_id = ? AND barcode = ? AND is_active=1 LIMIT 1");
    $st->execute([$store_id, $barcode]);
    if ($r = $st->fetch()) {
      echo json_encode(['ok'=>true,'id'=>(int)$r['id'],'note'=>'exists']);
      exit;
    }
  }

  $st = $pdo->prepare("
    INSERT INTO stock_products (store_id, name, unit, barcode, is_active)
    VALUES (?, ?, ?, ?, 1)
  ");
  $st->execute([$store_id, $name, $unit, ($barcode===''?null:$barcode)]);

  echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()]);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);
}
