<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
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
  $endpoint = trim((string)($_POST['endpoint'] ?? ''));
  if ($endpoint === '') {
    throw new RuntimeException('endpoint missing');
  }

  $pdo = db();
  push_deactivate_subscription($pdo, $endpoint);

  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
