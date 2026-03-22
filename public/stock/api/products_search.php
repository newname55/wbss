<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
  http_response_code(403);
  echo json_encode(['ok' => false, 'error' => 'Forbidden'], JSON_UNESCAPED_UNICODE);
  exit;
}

$store_id = current_store_id();
if ($store_id === null) {
  http_response_code(400);
  echo json_encode(['ok' => false, 'error' => 'store not selected'], JSON_UNESCAPED_UNICODE);
  exit;
}
$store_id = (int)$store_id;

function out(array $a): void {
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

$pdo = db();

$q = trim((string)($_GET['q'] ?? ''));
$ptype = trim((string)($_GET['ptype'] ?? '')); // alcohol|mixer|consumable|other|''

if ($q === '' && $ptype === '') {
  out(['ok' => true, 'items' => []]);
}

// product_type があるか確認（無い環境でも落ちないように）
$st = $pdo->prepare("
  SELECT 1
  FROM information_schema.COLUMNS
  WHERE TABLE_SCHEMA = DATABASE()
    AND TABLE_NAME = 'stock_products'
    AND COLUMN_NAME = 'product_type'
  LIMIT 1
");
$st->execute();
$has_ptype = (bool)$st->fetchColumn();

$allowed_ptype = ['alcohol','mixer','consumable','other'];
if ($ptype !== '' && !in_array($ptype, $allowed_ptype, true)) {
  out(['ok' => false, 'error' => 'Invalid ptype']);
}

$where = ['p.is_active = 1', 'p.store_id = ?'];
$params = [$store_id];

if ($q !== '') {
  // 文字 or JAN どっちも拾う
  $like = '%' . $q . '%';
  $where[] = '(p.name LIKE ? OR p.barcode LIKE ? OR p.search_text LIKE ?)';
  array_push($params, $like, $like, $like);
}

if ($ptype !== '' && $has_ptype) {
  $where[] = 'p.product_type = ?';
  $params[] = $ptype;
}

$sql = "
  SELECT p.id, p.name, p.unit, p.barcode
  FROM stock_products p
  WHERE " . implode(' AND ', $where) . "
  ORDER BY
    CASE WHEN p.barcode = ? THEN 0 ELSE 1 END,
    p.name ASC,
    p.id ASC
  LIMIT 30
";

// barcode完全一致を最優先にしたいので、ORDER BY 用に q をもう一回渡す
$params2 = $params;
$params2[] = $q;

try {
  $st = $pdo->prepare($sql);
  $st->execute($params2);
  $items = $st->fetchAll();

  out(['ok' => true, 'items' => $items]);
} catch (Throwable $e) {
  out(['ok' => false, 'error' => $e->getMessage()]);
}
