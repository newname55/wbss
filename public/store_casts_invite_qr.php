<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/service_store_casts.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$pdo = db();

try {
  $storeId = store_access_resolve_manageable_store_id($pdo, (int)($_GET['store_id'] ?? 0));
  $token = service_get_or_create_cast_invite_token($pdo, $storeId, (int)current_user_id());
  header('Location: /wbss/public/print_invite_qr.php?invite=' . rawurlencode($token));
  exit;
} catch (Throwable $e) {
  http_response_code(400);
  echo h($e->getMessage());
}
