<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/service_push.php';

require_login();
require_role(['cast', 'admin', 'manager', 'super_user']);

header('Content-Type: application/json; charset=utf-8');

try {
  $config = push_vapid_config();
  echo json_encode([
    'ok' => true,
    'public_key' => (string)$config['public_key'],
    'subject' => (string)$config['subject'],
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
