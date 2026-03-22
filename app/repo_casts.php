<?php
declare(strict_types=1);

/**
 * Cast repositories (DB access only)
 * - Do NOT define auth/session helper here to avoid redeclare.
 */

function repo_allowed_stores(PDO $pdo, int $userId, bool $isSuper): array {
  if ($isSuper) {
    $st = $pdo->query("SELECT id, name FROM stores WHERE is_active=1 ORDER BY id ASC");
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }

  // admin/manager が紐づいている店舗のみ
  $st = $pdo->prepare("
    SELECT DISTINCT s.id, s.name
    FROM user_roles ur
    JOIN roles r
      ON r.id = ur.role_id
     AND (
       CONVERT(r.code USING utf8mb4) COLLATE utf8mb4_unicode_ci = _utf8mb4'admin' COLLATE utf8mb4_unicode_ci
       OR CONVERT(r.code USING utf8mb4) COLLATE utf8mb4_unicode_ci = _utf8mb4'manager' COLLATE utf8mb4_unicode_ci
     )
    JOIN stores s ON s.id=ur.store_id AND s.is_active=1
    WHERE ur.user_id=? AND ur.store_id IS NOT NULL
    ORDER BY s.id ASC
  ");
  $st->execute([$userId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** cast_profiles に shop_tag 列があるか（無くても落ちないように） */
function repo_has_cast_profiles_shop_tag(PDO $pdo): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = 'cast_profiles'
        AND COLUMN_NAME = 'shop_tag'
    ");
    $st->execute();
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

function repo_fetch_casts(PDO $pdo, int $storeId, string $filter = 'all'): array {
  $filter = in_array($filter, ['all','line_unlinked','active_only','with_retired'], true) ? $filter : 'all';

  $hasShopTag = repo_has_cast_profiles_shop_tag($pdo);

  // shop_tag がある時だけ SELECT/ORDER に含める
  $selShopTag = $hasShopTag
    ? "COALESCE(NULLIF(TRIM(cp.shop_tag), ''), '') AS shop_tag,"
    : "'' AS shop_tag,";

  $orderShopTag = $hasShopTag
    ? "CASE WHEN NULLIF(TRIM(cp.shop_tag),'') IS NULL THEN 999999 ELSE CAST(cp.shop_tag AS UNSIGNED) END ASC,"
    : "";

  // ✅ 母集団は store_users を主軸にする
  $where = "su.store_id = ?";
  if ($filter !== 'with_retired') {
    $where .= " AND su.status='active'";
  }

  // 「active_only」は users.is_active も見る
  if ($filter === 'active_only') {
    $where .= " AND u.is_active=1";
  }

  // 「line_unlinked」は LINE未連携のみ（ついでに users active も推奨）
  if ($filter === 'line_unlinked') {
    $where .= " AND u.is_active=1 AND NOT EXISTS (
      SELECT 1 FROM user_identities ui2
      WHERE ui2.user_id=u.id AND ui2.provider='line' AND ui2.is_active=1
    )";
  }

  $sql = "
    SELECT
      su.id AS store_user_id,
      u.id,
      u.login_id,
      u.display_name,
      u.is_active,
      su.status,
      su.retired_at,
      su.retired_reason,
      COALESCE(NULLIF(su.employment_type,''), NULLIF(cp.employment_type,''), NULLIF(u.employment_type,''), 'part') AS employment_type,
      COALESCE(cp.default_start_time, NULL) AS default_start_time,
      {$selShopTag}
      MAX(CASE WHEN r.code='cast' THEN 1 ELSE 0 END) AS has_cast_role,
      MAX(CASE WHEN ui.provider='line' AND ui.is_active=1 THEN 1 ELSE 0 END) AS has_line
    FROM store_users su
    JOIN users u ON u.id=su.user_id

    LEFT JOIN user_roles ur ON ur.store_id=su.store_id AND ur.user_id=su.user_id
    LEFT JOIN roles r ON r.id=ur.role_id

    LEFT JOIN cast_profiles cp
      ON cp.user_id=u.id
     AND (cp.store_id=su.store_id OR cp.store_id IS NULL)

    LEFT JOIN user_identities ui
      ON ui.user_id=u.id
     AND ui.provider='line'
     AND ui.is_active=1

    WHERE {$where}
    GROUP BY
      su.id, u.id, u.login_id, u.display_name, u.is_active, su.status, su.retired_at, su.retired_reason,
      employment_type, default_start_time, shop_tag
    HAVING
      has_cast_role = 1
      AND shop_tag <> ''
    ORDER BY
      CASE WHEN su.status='active' THEN 0 ELSE 1 END ASC,
      u.is_active DESC,
      {$orderShopTag}
      u.display_name ASC,
      u.id ASC
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function repo_fetch_cast_notes(PDO $pdo, int $storeId, array $userIds): array {
  $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
  if (!$userIds) {
    return [];
  }

  try {
    $ph = implode(',', array_fill(0, count($userIds), '?'));
    $sql = "
      SELECT user_id, note
      FROM cast_profiles
      WHERE store_id=?
        AND user_id IN ({$ph})
    ";
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$storeId], $userIds));

    $notes = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $uid = (int)($row['user_id'] ?? 0);
      $note = trim((string)($row['note'] ?? ''));
      if ($uid > 0 && $note !== '') {
        $notes[$uid] = $note;
      }
    }
    return $notes;
  } catch (Throwable $e) {
    return [];
  }
}

function repo_points_casts_for_store(PDO $pdo, int $storeId): array {
  $sql = "
    SELECT
      u.id,
      u.display_name,
      COALESCE(NULLIF(cp.shop_tag,''), '-') AS shop_tag,
      COALESCE(NULLIF(su.employment_type,''), NULLIF(cp.employment_type,''), NULLIF(u.employment_type,''), 'part') AS employment_type,
      MAX(CASE WHEN ui.provider='line' AND ui.is_active=1 THEN 1 ELSE 0 END) AS has_line
    FROM store_users su
    JOIN users u ON u.id = su.user_id

    JOIN user_roles ur
      ON ur.store_id = su.store_id AND ur.user_id = su.user_id
    JOIN roles r
      ON r.id = ur.role_id
     AND CONVERT(r.code USING utf8mb4) COLLATE utf8mb4_unicode_ci = _utf8mb4'cast' COLLATE utf8mb4_unicode_ci

    LEFT JOIN cast_profiles cp
      ON cp.user_id = u.id
     AND (cp.store_id = su.store_id OR cp.store_id IS NULL)

    LEFT JOIN user_identities ui
      ON ui.user_id = u.id
     AND ui.provider='line'
     AND ui.is_active=1

    WHERE su.store_id = ?
      AND su.status = 'active'
      AND u.is_active = 1
    GROUP BY u.id, u.display_name, cp.shop_tag, cp.employment_type, su.employment_type
    ORDER BY cp.shop_tag+0 ASC, u.display_name ASC, u.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
