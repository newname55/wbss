<?php
declare(strict_types=1);

require_once __DIR__ . '/_db.php';

function get_bearer_token(): ?string {
  // 1) まずは標準（環境によって入らない）
  $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

  // 2) Apache環境などでここに来るケースがある
  if (!$h && function_exists('getallheaders')) {
    $headers = getallheaders();
    $h = $headers['Authorization'] ?? ($headers['authorization'] ?? '');
  }

  // 3) 一部環境向けの代替
  if (!$h) {
    $h = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
  }

  if (!$h) return null;

  if (preg_match('/Bearer\s+(.+)/i', $h, $m)) return trim($m[1]);
  return null;
}

function sha256(string $s): string {
  return hash('sha256', $s);
}

function require_api_token(): array {
  $token = get_bearer_token();
  if (!$token) json_out(['ok'=>false,'error'=>'missing_token'], 401);

  $hash = sha256($token);

  $pdo = db();
  $st = $pdo->prepare("SELECT * FROM api_tokens WHERE token_hash=? AND is_active=1 LIMIT 1");
  $st->execute([$hash]);
  $row = $st->fetch();
  if (!$row) json_out(['ok'=>false,'error'=>'invalid_token'], 401);

  // last_used更新
  $pdo->prepare("UPDATE api_tokens SET last_used_at=NOW() WHERE id=?")->execute([(int)$row['id']]);

  return $row;
}