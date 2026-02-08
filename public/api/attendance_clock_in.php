<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
$pdo = db();

$userId = (int)current_user_id();
$storeId = (int)($_POST['store_id'] ?? 0);

if ($storeId <= 0) {
  header('Location: /seika-app/public/dashboard_cast.php?err=' . urlencode('store_idが不正です'));
  exit;
}

$st = $pdo->prepare("SELECT id,business_day_start FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$store = $st->fetch(PDO::FETCH_ASSOC);
if (!$store) {
  header('Location: /seika-app/public/dashboard_cast.php?err=' . urlencode('店舗が見つかりません'));
  exit;
}

function business_date_for_store(array $storeRow): string {
  $now = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
  $cut = (string)($storeRow['business_day_start'] ?? '05:00:00');
  $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
  if ($now < $cutDT) $now->modify('-1 day');
  return $now->format('Y-m-d');
}

$bizDate = business_date_for_store($store);

try {
  $pdo->beginTransaction();

  $st = $pdo->prepare("
    SELECT id, clock_in, clock_out
    FROM attendances
    WHERE user_id=? AND store_id=? AND business_date=?
    LIMIT 1
  ");
  $st->execute([$userId, $storeId, $bizDate]);
  $row = $st->fetch(PDO::FETCH_ASSOC);

  if (!$row) {
    $st = $pdo->prepare("
      INSERT INTO attendances
        (user_id, store_id, business_date, clock_in, status, source_in, created_at, updated_at)
      VALUES
        (?, ?, ?, NOW(), 'working', 'button', NOW(), NOW())
    ");
    $st->execute([$userId, $storeId, $bizDate]);
  } else {
    if (!empty($row['clock_in'])) {
      $pdo->commit();
      header('Location: /seika-app/public/dashboard_cast.php?ok=1');
      exit;
    }
    $st = $pdo->prepare("
      UPDATE attendances
      SET clock_in=NOW(), status='working', source_in='button', updated_at=NOW()
      WHERE id=?
      LIMIT 1
    ");
    $st->execute([(int)$row['id']]);
  }

  $pdo->commit();
  header('Location: /seika-app/public/dashboard_cast.php?ok=1');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  header('Location: /seika-app/public/dashboard_cast.php?err=' . urlencode('出勤に失敗: ' . $e->getMessage()));
  exit;
}