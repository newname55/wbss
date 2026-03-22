<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/service_messages.php';

require_login();
require_role(['cast', 'admin', 'manager', 'super_user']);

header('Content-Type: application/json; charset=utf-8');

try {
  $pdo = db();
  $storeId = message_resolve_store_id($pdo, (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0));
  $userId = message_current_user_id();

  echo json_encode([
    'ok' => true,
    'store_id' => $storeId,
    'unread_count' => message_total_unread_count($pdo, $storeId, $userId),
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
