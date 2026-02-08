<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();

header('Content-Type: application/json; charset=utf-8');

try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  // JSON or form どっちでも受ける（管理画面/右上メニューで便利）
  $theme = '';
  $ct = (string)($_SERVER['CONTENT_TYPE'] ?? '');

  if (stripos($ct, 'application/json') !== false) {
    $raw = file_get_contents('php://input') ?: '';
    $j = json_decode($raw, true);
    if (is_array($j)) $theme = (string)($j['theme'] ?? '');
  } else {
    $theme = (string)($_POST['theme'] ?? '');
  }

  $theme = trim($theme);

  $allowed = ['light','dark','soft','high_contrast','store_color'];
  if (!in_array($theme, $allowed, true)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid theme'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Unauthorized'], JSON_UNESCAPED_UNICODE);
    exit;
  }

  $pdo = db();
  $st = $pdo->prepare("UPDATE users SET ui_theme = ? WHERE id = ? AND is_active = 1");
  $st->execute([$theme, $uid]);

  // セッション反映（即時適用）
  $_SESSION['ui_theme'] = $theme;

  echo json_encode(['ok' => true, 'theme' => $theme], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(500);
  echo json_encode(['ok' => false, 'error' => 'Server error'], JSON_UNESCAPED_UNICODE);
}