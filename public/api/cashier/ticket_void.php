<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';

header('Content-Type: application/json; charset=utf-8');

function json_out(array $a, int $code=200): void {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_out(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
  }

  $storeId  = (int)($_POST['store_id'] ?? 0);
  $ticketId = (int)($_POST['ticket_id'] ?? 0);
  $reason   = trim((string)($_POST['reason'] ?? ''));

  if ($storeId <= 0 || $ticketId <= 0) {
    json_out(['ok'=>false,'error'=>'BAD_REQUEST'], 400);
  }

  // セッション開始と認可チェック
  if (function_exists('ensure_session')) ensure_session();
  $actorUserId = (int)($_SESSION['user_id'] ?? 0);
  if ($actorUserId <= 0) {
    json_out(['ok'=>false,'error'=>'UNAUTHORIZED'], 401);
  }

  // 権限チェック（admin/manager/staff/super_user のいずれか）
  $allowedRoles = ['admin','manager','staff','super_user'];
  $hasRole = false;
  foreach ($allowedRoles as $r) {
    if (function_exists('is_role') && is_role($r)) { $hasRole = true; break; }
  }
  if (!$hasRole) {
    json_out(['ok'=>false,'error'=>'FORBIDDEN'], 403);
  }

  $pdo = db();
  $pdo->beginTransaction();

  // チケット存在確認（store_id必須）+ ロック
  $st = $pdo->prepare("
    SELECT id, store_id, status
    FROM tickets
    WHERE id = :tid AND store_id = :sid
    FOR UPDATE
  ");
  $st->execute([':tid'=>$ticketId, ':sid'=>$storeId]);
  $t = $st->fetch(PDO::FETCH_ASSOC);

  if (!$t) {
    $pdo->rollBack();
    json_out(['ok'=>false,'error'=>'TICKET_NOT_FOUND'], 404);
  }

  $prevStatus = (string)$t['status'];
  if ($prevStatus === 'void') {
    $pdo->commit();
    json_out(['ok'=>true,'status'=>'void','message'=>'already_void']);
  }

  // 事故防止：入金（captured）があるなら無効化禁止（まず入金取消してから）
  $stPay = $pdo->prepare("
    SELECT COALESCE(SUM(amount),0) AS s
    FROM ticket_payments
    WHERE ticket_id = :tid
      AND status = 'captured'
      AND is_void = 0
  ");
  $stPay->execute([':tid'=>$ticketId]);
  $paidTotal = (int)($stPay->fetchColumn() ?? 0);

  if ($paidTotal > 0) {
    $pdo->rollBack();
    json_out([
      'ok'=>false,
      'error'=>'HAS_PAYMENTS',
      'message'=>'入金があるため無効化できません。先に入金を取消（void）してください。',
      'paid_total'=>$paidTotal
    ], 409);
  }

  // チケット無効化
  $stUp = $pdo->prepare("
    UPDATE tickets
    SET status='void',
        closed_at = COALESCE(closed_at, NOW()),
        updated_at = NOW()
    WHERE id=:tid AND store_id=:sid
    LIMIT 1
  ");
  $stUp->execute([':tid'=>$ticketId, ':sid'=>$storeId]);

  // 監査ログ（haruto_core.sql にある ticket_audits を利用）
  $detail = [
    'reason' => ($reason !== '' ? $reason : null),
    'from_status' => $prevStatus,
    'to_status' => 'void',
    'at' => date('Y-m-d H:i:s'),
  ];

  $stAud = $pdo->prepare("
    INSERT INTO ticket_audits
      (ticket_id, action, detail, actor_user_id, created_at)
    VALUES
      (:tid, 'ticket_void', :detail, :uid, NOW())
  ");
  $stAud->execute([
    ':tid'=>$ticketId,
    ':detail'=>json_encode($detail, JSON_UNESCAPED_UNICODE),
    ':uid'=>$actorUserId,
  ]);

  $pdo->commit();

  json_out(['ok'=>true,'status'=>'void']);

} catch (Throwable $e) {
  if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>'SERVER_ERROR','message'=>$e->getMessage()], 500);
}