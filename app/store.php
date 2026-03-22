<?php
declare(strict_types=1);

/**
 * Store context helper (session-based)
 * - super_user/admin/manager: store can be selected (superは必須にしてもOK)
 * - cast: 基本この仕組みは使わない（cast側は自分のstore固定ロジックが別にある）
 *
 * 互換のため:
 * - current_store_id() を用意（stock側が呼んでても落ちない）
 * - require_store_selected_for_super() を提供
 */

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

const STORE_SESSION_KEY = '__store_id';

function normalize_internal_wbss_path(string $path, string $default = '/wbss/public/dashboard.php'): string {
  $value = trim($path);
  if ($value === '') {
    return $default;
  }

  if (preg_match('#^https?://#i', $value)) {
    $parts = parse_url($value);
    if (!is_array($parts)) {
      return $default;
    }
    $value = (string)($parts['path'] ?? '');
    if ($value === '') {
      return $default;
    }
    if (isset($parts['query']) && $parts['query'] !== '') {
      $value .= '?' . $parts['query'];
    }
    if (isset($parts['fragment']) && $parts['fragment'] !== '') {
      $value .= '#' . $parts['fragment'];
    }
  }

  if (preg_match('/^\/{2,}/', $value)) {
    return $default;
  }

  if ($value[0] !== '/') {
    $value = '/' . ltrim($value, '/');
  }

  if (str_starts_with($value, '/seika-app/public/')) {
    $value = '/wbss/public/' . ltrim(substr($value, strlen('/seika-app/public/')), '/');
  }

  if (!str_starts_with($value, '/wbss/public/')) {
    return $default;
  }

  return $value;
}

function set_current_store_id(int $storeId): void {
  if ($storeId <= 0) {
    unset($_SESSION[STORE_SESSION_KEY]);
    return;
  }
  $_SESSION[STORE_SESSION_KEY] = $storeId;
}

function get_current_store_id(): int {
  return (int)($_SESSION[STORE_SESSION_KEY] ?? 0);
}

/** 互換：古いコードが current_store_id() を呼ぶ想定がある */
function current_store_id(): int {
  return get_current_store_id();
}

/**
 * super_user が「店舗未選択のまま」ダッシュボード等へ来たら
 * store_select.php に飛ばして選択させる（return付き）
 */
function require_store_selected_for_super(bool $isSuper, string $returnTo): void {
  if (!$isSuper) return;
  if (get_current_store_id() > 0) return;

  $ret = normalize_internal_wbss_path($returnTo, '/wbss/public/dashboard.php');
  header('Location: /wbss/public/store_select.php?return=' . rawurlencode($ret));
  exit;
}

/**
 * 一般用途：ストア未選択なら store_select へ（return付き）
 * ※admin/manager でも「必須」にしたいページで呼ぶ用
 */
function require_store_selected(string $returnTo): int {
  $sid = get_current_store_id();
  if ($sid > 0) return $sid;

  $ret = normalize_internal_wbss_path($returnTo, '/wbss/public/dashboard.php');
  header('Location: /wbss/public/store_select.php?return=' . rawurlencode($ret));
  exit;
}
