<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/service_messages.php';
require_once __DIR__ . '/../../app/service_push.php';

require_login();
require_role(['cast', 'admin', 'manager', 'super_user']);

header('Content-Type: application/json; charset=utf-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo json_encode(['ok' => false, 'error' => 'method_not_allowed'], JSON_UNESCAPED_UNICODE);
  exit;
}

try {
  csrf_verify($_POST['csrf_token'] ?? null);
  $payloadRaw = (string)($_POST['subscription'] ?? '');
  $payload = json_decode($payloadRaw, true);
  if (!is_array($payload)) {
    throw new RuntimeException('subscription payload is invalid');
  }

  $pdo = db();
  $storeId = message_resolve_store_id($pdo, (int)($_POST['store_id'] ?? 0));
  $userId = message_current_user_id();
  $contentEncoding = trim((string)($_POST['content_encoding'] ?? 'aes128gcm'));

  push_save_subscription($pdo, $userId, $storeId, $payload, $contentEncoding !== '' ? $contentEncoding : 'aes128gcm');

  echo json_encode([
    'ok' => true,
    'store_id' => $storeId,
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
