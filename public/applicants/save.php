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
$actorUserId = (int)(current_user_id() ?? 0);
$personId = (int)($_POST['person_id'] ?? 0);

try {
  $savedId = service_applicants_save_person($pdo, $_POST, $_FILES['face_photo'] ?? null, $actorUserId);
  header('Location: /wbss/public/applicants/detail.php?id=' . $savedId . '&msg=' . rawurlencode($personId > 0 ? '基本情報を更新しました' : '面接者を登録しました'));
  exit;
} catch (Throwable $e) {
  $redirectId = $personId > 0 ? $personId : 0;
  $url = '/wbss/public/applicants/detail.php';
  if ($redirectId > 0) {
    $url .= '?id=' . $redirectId . '&err=' . rawurlencode($e->getMessage());
  } else {
    $url .= '?err=' . rawurlencode($e->getMessage());
  }
  header('Location: ' . $url);
  exit;
}
