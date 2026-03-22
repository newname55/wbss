<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/service_store_casts.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

csrf_verify($_POST['csrf_token'] ?? null);

$pdo = db();

try {
  $storeId = store_access_resolve_manageable_store_id($pdo, (int)($_POST['store_id'] ?? 0));
  $token = service_create_cast_invite(
    $pdo,
    $storeId,
    (int)current_user_id(),
    (string)($_POST['expires_at'] ?? '')
  );
  header('Location: /wbss/public/store_casts_invites.php?store_id=' . $storeId . '&invite=' . rawurlencode($token));
  exit;
} catch (Throwable $e) {
  $storeId = (int)($_POST['store_id'] ?? 0);
  $qs = http_build_query([
    'store_id' => $storeId,
    'error' => $e->getMessage(),
  ]);
  header('Location: /wbss/public/store_casts_invites.php?' . $qs);
  exit;
}
