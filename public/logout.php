<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
ensure_session();

$_SESSION = [];
if (ini_get("session.use_cookies")) {
  $p = session_get_cookie_params();
  setcookie(session_name(), '', time()-42000, $p["path"], $p["domain"], (bool)$p["secure"], (bool)$p["httponly"]);
}
session_destroy();

header('Location: /seika-app/public/login.php?reason=logout');
exit;