<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  echo json_encode(['ok'=>false, 'error'=>'invalid id']);
  exit;
}

$pdo = db();

/* 商品基本 */
$p = $pdo->prepare("
  SELECT
    p.id, p.name, p.unit, p.product_type,
    p.image_path,
    c.name AS category
  FROM stock_products p
  LEFT JOIN stock_categories c ON c.id = p.category_id
  WHERE p.id = ? AND p.is_active = 1
  LIMIT 1
");
$p->execute([$id]);
$product = $p->fetch();
if (!$product) {
  echo json_encode(['ok'=>false, 'error'=>'not found']);
  exit;
}

/* 場所別在庫 */
$loc = $pdo->prepare("
  SELECT
    l.name AS location,
    il.qty
  FROM stock_item_locations il
  JOIN stock_locations l ON l.id = il.location_id
  WHERE il.product_id = ?
  ORDER BY l.sort_order, l.id
");
$loc->execute([$id]);
$locations = $loc->fetchAll();

/* レスポンス */
echo json_encode([
  'ok' => true,
  'product' => [
    'id' => (int)$product['id'],
    'name' => $product['name'],
    'unit' => $product['unit'],
    'type' => $product['product_type'],
    'category' => $product['category'],
    'image_url' => $product['image_path']
      ? '/seika-app/public/uploads/products/'.$product['image_path']
      : null,
  ],
  'locations' => $locations,
], JSON_UNESCAPED_UNICODE);