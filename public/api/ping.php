<?php
declare(strict_types=1);

/**
 * API 共通ユーティリティ
 * DB接続は seika-app 本体（app/db.php）の db() をそのまま使う
 */

// 既存アプリのDB接続・ヘルパーを読み込む
require_once __DIR__ . '/../../app/db.php';

/**
 * JSON出力
 */
function json_out(array $data, int $status = 200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

/**
 * JSON入力
 */
function read_json(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}