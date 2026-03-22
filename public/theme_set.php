<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

ensure_session();
require_login();

if (!function_exists('csrf_token')) {
  // layout.php 側にある想定だが、無い環境のための保険
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['_csrf'];
  }
}
function verify_csrf(): void {
  $t = (string)($_POST['csrf_token'] ?? '');
  if ($t === '' || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $t)) {
    http_response_code(400);
    exit('Bad Request (csrf)');
  }
}

verify_csrf();

$theme = (string)($_POST['theme'] ?? 'dark');
$allow = ['dark','light','soft','high_contrast','store_color'];
if (!in_array($theme, $allow, true)) $theme = 'dark';

$_SESSION['ui_theme'] = $theme;

$uid = (int)($_SESSION['user_id'] ?? 0);
if ($uid > 0) {
  try {
    $pdo = db();
    $pdo->prepare("UPDATE users SET ui_theme=?, updated_at=NOW() WHERE id=?")->execute([$theme, $uid]);
  } catch (Throwable $e) {
    // セッションだけは反映させる
  }
}

$back = (string)($_POST['back'] ?? '/wbss/public/dashboard.php');
if ($back === '') $back = '/wbss/public/dashboard.php';
header('Location: ' . $back);
exit;