<?php
declare(strict_types=1);

function db(): PDO {
  static $pdo;
  if ($pdo instanceof PDO) return $pdo;

  // 既存のdb.phpに合わせて書き換えOK
  $dsn  = 'mysql:host=localhost;dbname=haruto_core;charset=utf8mb4';
  $user = 'YOUR_DB_USER';
  $pass = 'YOUR_DB_PASS';

  $pdo = new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
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