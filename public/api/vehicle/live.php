<?php
declare(strict_types=1);

require_once __DIR__ . '/../_db.php';

/**
 * ここは「店側ログイン」前提にしたいなら、いつもの auth.php + require_admin() を挟んでOK
 * 今はMVPで誰でも見れないように、簡易キーを使う例（必要なら外す）
 */
$k = $_GET['k'] ?? '';
if ($k !== 'CHANGE_ME_VIEW_KEY') {
  http_response_code(403);
  echo 'forbidden';
  exit;
}

$storeId = (int)($_GET['store_id'] ?? 0);

$pdo = db();
$sql = "
SELECT
  v.id AS vehicle_id,
  v.name AS vehicle_name,
  v.plate,
  c.lat, c.lng, c.speed_mps, c.heading_deg, c.accuracy_m,
  c.captured_at, c.received_at,
  TIMESTAMPDIFF(SECOND, c.received_at, NOW()) AS seconds_since_update
FROM vehicles v
LEFT JOIN vehicle_current_locations c ON c.vehicle_id = v.id
WHERE v.is_active=1
";
$args = [];
if ($storeId > 0) { $sql .= " AND v.store_id=?"; $args[] = $storeId; }
$sql .= " ORDER BY v.id ASC";

$st = $pdo->prepare($sql);
$st->execute($args);
$rows = $st->fetchAll();

json_out(['ok'=>true,'vehicles'=>$rows]);