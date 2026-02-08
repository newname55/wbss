<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';

function get_active_stores(): array {
  $pdo = db();
  $st = $pdo->query("SELECT id, code, name FROM stores WHERE is_active = 1 ORDER BY id");
  return $st->fetchAll();
}

// super/admin は全店舗、その他は user_roles の store_id のみ（store_idがNULLなら全店舗扱いにしない運用）
function allowed_store_ids(): array {
  ensure_session();
  if (is_super_user() || is_role('admin')) return []; // []=全店舗OK
  return array_map('intval', $_SESSION['store_ids'] ?? []);
}

function can_access_store(int $store_id): bool {
  $allowed = allowed_store_ids();
  if ($allowed === []) return true;
  return in_array($store_id, $allowed, true);
}

function set_current_store(int $store_id): void {
  ensure_session();
  $_SESSION['current_store_id'] = $store_id;
}

function current_store_id(): ?int {
  ensure_session();
  return isset($_SESSION['current_store_id']) ? (int)$_SESSION['current_store_id'] : null;
}