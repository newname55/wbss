<?php
declare(strict_types=1);

// ✅ seika-app本体のDB接続を利用（ここが正）
require_once __DIR__ . '/../../app/db.php';

function db(): PDO {
  // app/db.php が $pdo を作っている前提
  if (!isset($pdo) || !($pdo instanceof PDO)) {
    throw new RuntimeException('PDO not initialized in app/db.php');
  }
  return $pdo;
}

function json_out(array $data, int $status=200): void {
  http_response_code($status);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE);
  exit;
}

function read_json(): array {
  $raw = file_get_contents('php://input');
  if ($raw === false || trim($raw) === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}
