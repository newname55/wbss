<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/**
 * store系は色々なページから require_once されるので、
 * 二重定義で死なないよう function_exists ガード必須。
 */

if (!function_exists('current_store_id')) {
  function current_store_id(): ?int {
    $v = $_SESSION['store_id'] ?? null;
    if ($v === null || $v === '') return null;
    $id = (int)$v;
    return ($id > 0) ? $id : null;
  }
}

if (!function_exists('set_current_store_id')) {
  function set_current_store_id(int $store_id): void {
    if ($store_id <= 0) {
      unset($_SESSION['store_id']);
      return;
    }
    $_SESSION['store_id'] = $store_id;
  }
}

if (!function_exists('clear_current_store_id')) {
  function clear_current_store_id(): void {
    unset($_SESSION['store_id']);
  }
}

if (!function_exists('list_stores')) {
  /** @return array<int, array{id:int,name:string}> */
  function list_stores(): array {
    $pdo = db();
    $rows = $pdo->query("SELECT id, name FROM stores ORDER BY id ASC")->fetchAll();
    $out = [];
    foreach ($rows as $r) {
      $out[] = [
        'id' => (int)($r['id'] ?? 0),
        'name' => (string)($r['name'] ?? ''),
      ];
    }
    return $out;
  }
}

if (!function_exists('get_store')) {
  /** @return array{id:int,name:string}|null */
  function get_store(int $store_id): ?array {
    if ($store_id <= 0) return null;
    $pdo = db();
    $st = $pdo->prepare("SELECT id, name FROM stores WHERE id = ? LIMIT 1");
    $st->execute([$store_id]);
    $r = $st->fetch();
    if (!$r) return null;
    return [
      'id' => (int)$r['id'],
      'name' => (string)$r['name'],
    ];
  }
}