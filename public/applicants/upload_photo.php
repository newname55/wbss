<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/service_applicants.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  exit('Method Not Allowed');
}

csrf_verify($_POST['csrf_token'] ?? null);

$pdo = db();
$personId = (int)($_POST['person_id'] ?? 0);
$actorUserId = (int)(current_user_id() ?? 0);

try {
  service_applicants_upload_photo($pdo, $personId, $_FILES['face_photo'] ?? [], $actorUserId);
  header('Location: /wbss/public/applicants/detail.php?id=' . $personId . '&msg=' . rawurlencode('顔写真を更新しました'));
  exit;
} catch (Throwable $e) {
  header('Location: /wbss/public/applicants/detail.php?id=' . $personId . '&err=' . rawurlencode($e->getMessage()));
  exit;
}
