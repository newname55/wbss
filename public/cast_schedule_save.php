<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
$pdo = db();

$userId = (int)current_user_id();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** castの所属store_id（事故防止でサーバ側でも固定） */
function resolve_cast_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=?
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

$storeId = resolve_cast_store_id($pdo, $userId);
if ($storeId <= 0) {
  http_response_code(400);
  exit('store not set');
}

$weekStart = (string)($_POST['week_start'] ?? '');
if ($weekStart === '') {
  http_response_code(400);
  exit('bad request');
}

$on   = $_POST['on'] ?? [];
$note = $_POST['note'] ?? [];
if (!is_array($on)) $on = [];
if (!is_array($note)) $note = [];

$ws = new DateTime($weekStart);
$dates = [];
for ($i=0; $i<7; $i++) {
  $dates[] = (clone $ws)->modify("+{$i} days")->format('Y-m-d');
}

try {
  $pdo->beginTransaction();

  foreach ($dates as $d) {
    $isOn = isset($on[$d]) && (string)$on[$d] === '1';
    $memo = trim((string)($note[$d] ?? ''));
    $memo = ($memo === '') ? null : $memo;

    // 既存行（予定or実績）を確認
    $st = $pdo->prepare("
      SELECT id, status, clock_in, clock_out
      FROM attendances
      WHERE user_id=? AND store_id=? AND business_date=?
      LIMIT 1
    ");
    $st->execute([$userId, $storeId, $d]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      // 予定ONなら新規作成（scheduled）
      if ($isOn) {
        $pdo->prepare("
          INSERT INTO attendances
            (user_id, store_id, business_date, status, note, created_at, updated_at)
          VALUES
            (?, ?, ?, 'scheduled', ?, NOW(), NOW())
        ")->execute([$userId, $storeId, $d, $memo]);
      }
      continue;
    }

    $id = (int)$row['id'];
    $hasActual = !empty($row['clock_in']) || !empty($row['clock_out'])
      || in_array((string)$row['status'], ['working','finished'], true);

    // 実績がある日は「予定のON/OFF」で壊さない（メモだけ更新はOK）
    if ($hasActual) {
      $pdo->prepare("
        UPDATE attendances
        SET note=?, updated_at=NOW()
        WHERE id=?
        LIMIT 1
      ")->execute([$memo, $id]);
      continue;
    }

    // 実績が無い＝純予定
    if ($isOn) {
      $pdo->prepare("
        UPDATE attendances
        SET status='scheduled', note=?, updated_at=NOW()
        WHERE id=?
        LIMIT 1
      ")->execute([$memo, $id]);
    } else {
      // OFFにするなら、その日が純予定なら削除してOK（運用が楽）
      $pdo->prepare("DELETE FROM attendances WHERE id=? LIMIT 1")->execute([$id]);
    }
  }

  $pdo->commit();

  header('Location: /seika-app/public/cast_schedule.php?week=' . urlencode($weekStart) . '&ok=1');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  exit('save failed: ' . h($e->getMessage()));
}