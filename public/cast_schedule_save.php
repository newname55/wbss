<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

/**
 * Legacy cast schedule save endpoint.
 *
 * Canonical rules:
 * - plan source of truth: cast_shift_plans
 * - actual source of truth: attendances
 */

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

  $upPlan = $pdo->prepare("
    INSERT INTO cast_shift_plans
      (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
    VALUES
      (?, ?, ?, NULL, ?, 'planned', ?, ?)
    ON DUPLICATE KEY UPDATE
      start_time=VALUES(start_time),
      is_off=VALUES(is_off),
      status='planned',
      note=VALUES(note),
      created_by_user_id=VALUES(created_by_user_id),
      updated_at=NOW()
  ");

  foreach ($dates as $d) {
    $isOn = isset($on[$d]) && (string)$on[$d] === '1';
    $memo = trim((string)($note[$d] ?? ''));
    $memo = ($memo === '') ? null : $memo;

    if ($isOn) {
      $upPlan->execute([$storeId, $userId, $d, 0, $memo, $userId ?: null]);
      continue;
    }

    // OFFは canonical plan table に is_off=1 で残す。
    $upPlan->execute([$storeId, $userId, $d, 1, $memo, $userId ?: null]);
  }

  $pdo->commit();

  header('Location: /wbss/public/cast_schedule.php?week=' . urlencode($weekStart) . '&ok=1');
  exit;

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  http_response_code(500);
  exit('save failed: ' . h($e->getMessage()));
}
