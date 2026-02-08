<?php
declare(strict_types=1);

require_once __DIR__ . '/../_auth.php';

$tok = require_api_token();
$vehicleId = (int)($tok['vehicle_id'] ?? 0);
if ($vehicleId <= 0) json_out(['ok'=>false,'error'=>'token_not_bound_vehicle'], 403);

$in = read_json();

$lat = isset($in['lat']) ? (float)$in['lat'] : null;
$lng = isset($in['lng']) ? (float)$in['lng'] : null;
if ($lat === null || $lng === null) json_out(['ok'=>false,'error'=>'missing_latlng'], 400);

$speed    = isset($in['speed_mps']) ? (float)$in['speed_mps'] : null;
$heading  = isset($in['heading_deg']) ? (int)$in['heading_deg'] : null;
$acc      = isset($in['accuracy_m']) ? (int)$in['accuracy_m'] : null;

$capturedAt = (string)($in['captured_at'] ?? '');
if ($capturedAt === '') json_out(['ok'=>false,'error'=>'missing_captured_at'], 400);

// ざっくり形式チェック (YYYY-mm-dd HH:ii:ss)
if (!preg_match('/^\d{4}-\d{2}-\d{2}\s+\d{2}:\d{2}:\d{2}$/', $capturedAt)) {
  json_out(['ok'=>false,'error'=>'bad_captured_at_format'], 400);
}

$pdo = db();
$pdo->beginTransaction();
try {
  // 履歴
  $st = $pdo->prepare("
    INSERT INTO vehicle_locations (vehicle_id, lat, lng, speed_mps, heading_deg, accuracy_m, captured_at)
    VALUES (?, ?, ?, ?, ?, ?, ?)
  ");
  $st->execute([$vehicleId, $lat, $lng, $speed, $heading, $acc, $capturedAt]);

  // 最新（UPSERT）
  $st2 = $pdo->prepare("
    INSERT INTO vehicle_current_locations (vehicle_id, lat, lng, speed_mps, heading_deg, accuracy_m, captured_at, received_at)
    VALUES (?, ?, ?, ?, ?, ?, ?, NOW())
    ON DUPLICATE KEY UPDATE
      lat=VALUES(lat), lng=VALUES(lng),
      speed_mps=VALUES(speed_mps),
      heading_deg=VALUES(heading_deg),
      accuracy_m=VALUES(accuracy_m),
      captured_at=VALUES(captured_at),
      received_at=NOW()
  ");
  $st2->execute([$vehicleId, $lat, $lng, $speed, $heading, $acc, $capturedAt]);

  $pdo->commit();
} catch (Throwable $e) {
  $pdo->rollBack();
  json_out(['ok'=>false,'error'=>'db_error','detail'=>$e->getMessage()], 500);
}

json_out(['ok'=>true]);