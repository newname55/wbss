<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';

function orders_repo_table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  $key = strtolower($table);
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.tables
    WHERE table_schema = DATABASE()
      AND table_name = ?
    LIMIT 1
  ");
  $st->execute([$table]);
  $cache[$key] = (bool)$st->fetchColumn();
  return $cache[$key];
}

function orders_repo_column_exists(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $key = strtolower($table . '.' . $column);
  if (array_key_exists($key, $cache)) {
    return $cache[$key];
  }

  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.columns
    WHERE table_schema = DATABASE()
      AND table_name = ?
      AND column_name = ?
    LIMIT 1
  ");
  $st->execute([$table, $column]);
  $cache[$key] = (bool)$st->fetchColumn();
  return $cache[$key];
}

function orders_repo_normalize_image_url(?string $url): string {
  $value = trim((string)$url);
  if ($value === '') {
    return '';
  }

  if (preg_match('#^https?://#i', $value)) {
    $parts = parse_url($value);
    $path = (string)($parts['path'] ?? '');
    if ($path !== '') {
      if (str_starts_with($path, '/seika-app/public/')) {
        return '/wbss/public/' . ltrim(substr($path, strlen('/seika-app/public/')), '/');
      }
      if (str_starts_with($path, '/wbss/public/')) {
        return $path;
      }
      if (str_starts_with($path, '/uploads/')) {
        return '/wbss/public' . $path;
      }
    }
    return $value;
  }

  if ($value[0] === '/') {
    if (str_starts_with($value, '/seika-app/public/')) {
      return '/wbss/public/' . ltrim(substr($value, strlen('/seika-app/public/')), '/');
    }
    return $value;
  }

  if (str_starts_with($value, 'wbss/public/')) {
    return '/' . $value;
  }
  if (preg_match('#^[^/]+/public/(.+)$#', $value, $m)) {
    return '/wbss/public/' . ltrim((string)$m[1], '/');
  }
  if (str_starts_with($value, 'uploads/')) {
    return '/wbss/public/' . $value;
  }

  return '/wbss/public/' . ltrim($value, '/');
}

function orders_repo_item_cast_assignments_ready(PDO $pdo): bool {
  return orders_repo_table_exists($pdo, 'order_item_cast_assignments');
}

function orders_repo_cast_no_column(PDO $pdo): string {
  foreach (['staff_code', 'cast_no', 'code'] as $column) {
    if (orders_repo_column_exists($pdo, 'store_users', $column)) {
      return $column;
    }
  }
  return '';
}

function orders_repo_fetch_store_cast_lookup(PDO $pdo, int $storeId): array {
  $column = orders_repo_cast_no_column($pdo);
  if ($storeId <= 0 || $column === '') {
    return ['by_no' => [], 'by_user' => []];
  }

  $sql = "
    SELECT
      u.id AS user_id,
      COALESCE(NULLIF(TRIM(u.display_name), ''), u.login_id, CONCAT('user#', u.id)) AS display_name,
      CAST(su.{$column} AS UNSIGNED) AS cast_no
    FROM store_users su
    JOIN users u ON u.id = su.user_id
    JOIN user_roles ur ON ur.user_id = su.user_id AND ur.store_id = su.store_id
    JOIN roles r ON r.id = ur.role_id AND r.code = 'cast'
    WHERE su.store_id = ?
      AND u.is_active = 1
  ";
  if (orders_repo_column_exists($pdo, 'store_users', 'status')) {
    $sql .= " AND su.status = 'active'";
  }
  if (orders_repo_column_exists($pdo, 'store_users', 'is_active')) {
    $sql .= " AND su.is_active = 1";
  }
  $sql .= " ORDER BY cast_no ASC, u.id ASC";

  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);

  $byNo = [];
  $byUser = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
    $userId = (int)($row['user_id'] ?? 0);
    $castNo = (int)($row['cast_no'] ?? 0);
    if ($userId <= 0 || $castNo <= 0) {
      continue;
    }

    $item = [
      'user_id' => $userId,
      'cast_no' => $castNo,
      'display_name' => (string)($row['display_name'] ?? ('user#' . $userId)),
    ];
    $byNo[(string)$castNo] = $item;
    $byUser[$userId] = $item;
  }

  return ['by_no' => $byNo, 'by_user' => $byUser];
}

function orders_repo_ticket_payload(PDO $pdo, int $storeId, int $ticketId): ?array {
  if ($storeId <= 0 || $ticketId <= 0) {
    return null;
  }

  $st = $pdo->prepare("
    SELECT totals_snapshot
    FROM tickets
    WHERE id = ? AND store_id = ?
    LIMIT 1
  ");
  $st->execute([$ticketId, $storeId]);
  $snapshot = (string)($st->fetchColumn() ?: '');
  if ($snapshot === '') {
    return null;
  }

  $decoded = json_decode($snapshot, true);
  if (!is_array($decoded)) {
    return null;
  }

  $payload = $decoded['payload'] ?? null;
  return is_array($payload) ? $payload : null;
}

function orders_repo_set_duration_minutes(string $kind): int {
  return match (trim($kind)) {
    'half25' => 25,
    'pack_douhan' => 90,
    default => 50,
  };
}

function orders_repo_set_end_hhmm(array $set, string $fallbackStart): string {
  $startedAt = trim((string)($set['started_at'] ?? $fallbackStart));
  if (preg_match('/^\d{1,2}:\d{2}$/', $startedAt) !== 1) {
    $startedAt = $fallbackStart;
  }

  $explicitEnd = trim((string)($set['ends_at'] ?? ''));
  if (preg_match('/^\d{1,2}:\d{2}$/', $explicitEnd) === 1) {
    return $explicitEnd;
  }

  [$hour, $minute] = array_map('intval', explode(':', $startedAt));
  $base = ($hour * 60) + $minute;
  $end = $base + orders_repo_set_duration_minutes((string)($set['kind'] ?? 'normal50'));
  $endHour = intdiv($end, 60) % 24;
  $endMinute = $end % 60;
  return sprintf('%02d:%02d', $endHour, $endMinute);
}

function orders_repo_current_set_index(array $payload): ?int {
  $sets = $payload['sets'] ?? null;
  if (!is_array($sets) || $sets === []) {
    return null;
  }

  $fallbackStart = trim((string)($payload['start_time'] ?? '20:00'));
  if (preg_match('/^\d{1,2}:\d{2}$/', $fallbackStart) !== 1) {
    $fallbackStart = '20:00';
  }

  $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
  $nowMinutes = ((int)$now->format('H') * 60) + (int)$now->format('i');
  $lastIndex = count($sets) - 1;

  foreach ($sets as $index => $set) {
    if (!is_array($set)) {
      continue;
    }

    $start = trim((string)($set['started_at'] ?? $fallbackStart));
    $end = orders_repo_set_end_hhmm($set, $fallbackStart);
    if (preg_match('/^\d{1,2}:\d{2}$/', $start) !== 1 || preg_match('/^\d{1,2}:\d{2}$/', $end) !== 1) {
      continue;
    }

    [$sh, $sm] = array_map('intval', explode(':', $start));
    [$eh, $em] = array_map('intval', explode(':', $end));
    $startMinutes = ($sh * 60) + $sm;
    $endMinutes = ($eh * 60) + $em;
    $currentMinutes = $nowMinutes;
    if ($endMinutes <= $startMinutes) {
      $endMinutes += 1440;
      if ($currentMinutes < $startMinutes) {
        $currentMinutes += 1440;
      }
    }

    if ($currentMinutes >= $startMinutes && $currentMinutes < $endMinutes) {
      return $index;
    }
  }

  return $lastIndex >= 0 ? $lastIndex : null;
}

function orders_repo_extract_current_seated_casts_from_payload(array $payload, array $castLookup): array {
  $resolveCast = static function (string $rawCastKey) use ($castLookup): ?array {
    if (isset($castLookup['by_no'][$rawCastKey]) && is_array($castLookup['by_no'][$rawCastKey])) {
      return $castLookup['by_no'][$rawCastKey];
    }
    $userId = (int)$rawCastKey;
    if ($userId > 0 && isset($castLookup['by_user'][$userId]) && is_array($castLookup['by_user'][$userId])) {
      return $castLookup['by_user'][$userId];
    }
    return null;
  };

  $appendCast = static function (array &$result, array $cast, string $context): void {
    $userId = (int)($cast['user_id'] ?? 0);
    if ($userId <= 0) {
      return;
    }
    if (!isset($result[$userId])) {
      $result[$userId] = [
        'user_id' => $userId,
        'cast_no' => (int)($cast['cast_no'] ?? 0),
        'display_name' => (string)($cast['display_name'] ?? ('user#' . $userId)),
        'contexts' => [],
      ];
    }
    if ($context !== '') {
      $result[$userId]['contexts'][] = $context;
    }
  };

  $index = orders_repo_current_set_index($payload);
  $sets = $payload['sets'] ?? null;
  $result = [];
  if ($index !== null && is_array($sets) && isset($sets[$index]) && is_array($sets[$index])) {
    $set = $sets[$index];
    $customers = $set['customers'] ?? null;
    if (is_array($customers)) {
      foreach ($customers as $customerNo => $customer) {
        if (!is_array($customer)) {
          continue;
        }

        $mode = (string)($customer['mode'] ?? 'free');
        if ($mode === 'shimei') {
          $shimei = $customer['shimei'] ?? null;
          if (!is_array($shimei)) {
            continue;
          }
          foreach ($shimei as $rawCastNo => $nominationType) {
            $cast = $resolveCast((string)$rawCastNo);
            if (!is_array($cast)) {
              continue;
            }
            $appendCast($result, $cast, '客' . (int)$customerNo . ' ' . (((string)$nominationType === 'hon') ? '本指名' : '場内'));
          }
          continue;
        }

        $free = $customer['free'] ?? null;
        if (!is_array($free)) {
          continue;
        }
        $phase = (string)($free['phase'] ?? 'first');
        if (!in_array($phase, ['first', 'second', 'third'], true)) {
          $phase = 'first';
        }
        $cast = $resolveCast((string)($free[$phase] ?? ''));
        if (!is_array($cast)) {
          continue;
        }
        $appendCast($result, $cast, '客' . (int)$customerNo . ' FREE ' . strtoupper($phase));
      }
    }
  }

  $topLevelShimei = $payload['shimei'] ?? null;
  if (is_array($topLevelShimei)) {
    foreach ($topLevelShimei as $row) {
      if (!is_array($row)) {
        continue;
      }
      $cast = $resolveCast((string)($row['cast_user_id'] ?? ''));
      if (!is_array($cast)) {
        continue;
      }
      $type = ((string)($row['shimei_type'] ?? 'normal') === 'jounai') ? '場内' : '本指名';
      $people = max(1, (int)($row['people'] ?? 1));
      $appendCast($result, $cast, 'トップレベル ' . $type . ' x' . $people);
    }
  }

  foreach ($result as &$row) {
    $row['contexts'] = array_values(array_unique($row['contexts']));
    $row['context_label'] = implode(' / ', $row['contexts']);
  }
  unset($row);

  usort($result, static function (array $a, array $b): int {
    $cmp = ((int)$a['cast_no']) <=> ((int)$b['cast_no']);
    if ($cmp !== 0) return $cmp;
    return ((int)$a['user_id']) <=> ((int)$b['user_id']);
  });

  return array_values($result);
}

function orders_repo_fetch_ticket_current_seated_casts(PDO $pdo, int $storeId, int $ticketId): array {
  $payload = orders_repo_ticket_payload($pdo, $storeId, $ticketId);
  if (!is_array($payload)) {
    return [];
  }
  $lookup = orders_repo_fetch_store_cast_lookup($pdo, $storeId);
  return orders_repo_extract_current_seated_casts_from_payload($payload, $lookup);
}

function orders_repo_fetch_ticket_assignment_candidates(PDO $pdo, int $storeId, int $ticketId): array {
  $lookup = orders_repo_fetch_store_cast_lookup($pdo, $storeId);
  $payload = orders_repo_ticket_payload($pdo, $storeId, $ticketId);
  $result = [];

  if (is_array($payload)) {
    $sets = $payload['sets'] ?? null;
    if (is_array($sets)) {
      foreach ($sets as $set) {
        if (!is_array($set)) {
          continue;
        }
        $customers = $set['customers'] ?? null;
        if (!is_array($customers)) {
          continue;
        }
        foreach ($customers as $customer) {
          if (!is_array($customer)) {
            continue;
          }
          $shimei = $customer['shimei'] ?? null;
          if (is_array($shimei)) {
            foreach (array_keys($shimei) as $rawCastNo) {
              $cast = $lookup['by_no'][(string)$rawCastNo] ?? null;
              if (is_array($cast)) {
                $result[(int)$cast['user_id']] = $cast;
              }
            }
          }
          $free = $customer['free'] ?? null;
          if (is_array($free)) {
            foreach (['first', 'second', 'third'] as $phase) {
              $cast = $lookup['by_no'][(string)($free[$phase] ?? '')] ?? null;
              if (is_array($cast)) {
                $result[(int)$cast['user_id']] = $cast;
              }
            }
          }
        }
      }
    }
  }

  if ($result === []) {
    foreach (orders_repo_fetch_ticket_current_seated_casts($pdo, $storeId, $ticketId) as $cast) {
      $result[(int)$cast['user_id']] = [
        'user_id' => (int)$cast['user_id'],
        'cast_no' => (int)$cast['cast_no'],
        'display_name' => (string)$cast['display_name'],
      ];
    }
  }

  if ($result === []) {
    $result = $lookup['by_user'];
  }

  usort($result, static function (array $a, array $b): int {
    $cmp = ((int)$a['cast_no']) <=> ((int)$b['cast_no']);
    if ($cmp !== 0) return $cmp;
    return ((int)$a['user_id']) <=> ((int)$b['user_id']);
  });

  return array_values($result);
}

function orders_repo_get_categories_with_menus(PDO $pdo, int $storeId): array {
  $sql = "
    SELECT
      m.id, m.name, m.price_ex, m.image_url, m.description, m.is_sold_out, m.sort_order,
      c.id AS category_id, c.name AS category_name, c.sort_order AS category_sort
    FROM order_menus m
    LEFT JOIN order_menu_categories c
      ON c.id = m.category_id AND c.store_id = m.store_id
    WHERE m.store_id = ?
      AND m.is_active = 1
    ORDER BY c.sort_order ASC, m.sort_order ASC, m.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $cats = [];
  foreach ($rows as $r) {
    $cid = (int)($r['category_id'] ?? 0);
    $cname = (string)($r['category_name'] ?? 'その他');
    $key = $cid > 0 ? (string)$cid : '0';

    if (!isset($cats[$key])) {
      $cats[$key] = [
        'category_id' => $cid,
        'category_name' => $cname,
        'items' => [],
      ];
    }

    $cats[$key]['items'][] = [
      'id' => (int)$r['id'],
      'name' => (string)$r['name'],
      'price_ex' => (int)$r['price_ex'],
      'image_url' => orders_repo_normalize_image_url((string)($r['image_url'] ?? '')),
      'description' => (string)($r['description'] ?? ''),
      'is_sold_out' => ((int)$r['is_sold_out'] === 1),
    ];
  }
  return array_values($cats);
}

function orders_repo_resolve_table_id(PDO $pdo, int $storeId, int $tableNo): int {
  // 安全策：卓番号の上限（店に合わせて調整）
  if ($tableNo < 1 || $tableNo > 50) return 0;

  // 既存を探す（store一致 + active）
  $st = $pdo->prepare("SELECT id FROM order_tables WHERE store_id=? AND table_no=? AND is_active=1 LIMIT 1");
  $st->execute([$storeId, $tableNo]);
  $id = (int)($st->fetchColumn() ?: 0);
  if ($id > 0) return $id;

  // 無ければ自動作成（※ order_tables のカラムが違うならここだけ調整）
  $name = '卓' . $tableNo;
  $st = $pdo->prepare("INSERT INTO order_tables (store_id, table_no, name, is_active) VALUES (?, ?, ?, 1)");
  $st->execute([$storeId, $tableNo, $name]);

  return (int)$pdo->lastInsertId();
}

function orders_repo_create_order(PDO $pdo, int $storeId, int $tableId, ?int $ticketId, string $note, array $items): int {
  // tableId は order_tables.id 前提（resolveしない）
  if ($tableId <= 0) throw new RuntimeException('table_id required');

  // テーブル存在確認（store一致）
  $st = $pdo->prepare("SELECT id FROM order_tables WHERE store_id=? AND id=? AND is_active=1 LIMIT 1");
  $st->execute([$storeId, $tableId]);
  if (!(int)($st->fetchColumn() ?: 0)) throw new RuntimeException('table not found');

  // items整形
  $menuIds = [];
  foreach ($items as $it) {
    $mid = (int)($it['menu_id'] ?? 0);
    if ($mid > 0) $menuIds[$mid] = true;
  }
  if (!$menuIds) throw new RuntimeException('invalid items');

  // menuチェック（store一致 + active + soldout）
  $in = implode(',', array_fill(0, count($menuIds), '?'));
  $params = array_merge([$storeId], array_keys($menuIds));
  $st = $pdo->prepare("SELECT id, is_sold_out, is_active FROM order_menus WHERE store_id=? AND id IN ($in)");
  $st->execute($params);

  $map = [];
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $map[(int)$m['id']] = $m;

  foreach (array_keys($menuIds) as $mid) {
    if (!isset($map[$mid]) || (int)$map[$mid]['is_active'] !== 1) throw new RuntimeException("menu not found: {$mid}");
    if ((int)$map[$mid]['is_sold_out'] === 1) throw new RuntimeException("sold out: {$mid}");
  }

  $seatedCastMap = [];
  if ($ticketId !== null && $ticketId > 0) {
    foreach (orders_repo_fetch_ticket_current_seated_casts($pdo, $storeId, $ticketId) as $cast) {
      $seatedCastMap[(int)$cast['user_id']] = true;
    }
  }

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      INSERT INTO order_orders (store_id, table_id, ticket_id, status, note)
      VALUES (?, ?, ?, 'new', ?)
    ");
    $st->execute([
      $storeId,
      $tableId,
      ($ticketId && $ticketId > 0) ? $ticketId : null,
      ($note !== '' ? $note : null),
    ]);
    $orderId = (int)$pdo->lastInsertId();

    $sti = $pdo->prepare("
      INSERT INTO order_order_items (store_id, order_id, menu_id, qty, item_status, note)
      VALUES (?, ?, ?, ?, 'new', ?)
    ");
    $stAssignment = null;
    if (orders_repo_item_cast_assignments_ready($pdo)) {
      $stAssignment = $pdo->prepare("
        INSERT INTO order_item_cast_assignments
          (store_id, order_id, order_item_id, ticket_id, menu_id, cast_user_id, consumed_qty, amount_yen, note, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
      ");
    }

    foreach ($items as $it) {
      $mid = (int)($it['menu_id'] ?? 0);
      $qty = max(1, (int)($it['qty'] ?? 1));
      $inote = trim((string)($it['note'] ?? ''));
      $sti->execute([$storeId, $orderId, $mid, $qty, ($inote !== '' ? $inote : null)]);
      $orderItemId = (int)$pdo->lastInsertId();

      $castUserId = (int)($it['cast_user_id'] ?? 0);
      if ($castUserId > 0) {
        if ($seatedCastMap !== [] && !isset($seatedCastMap[$castUserId])) {
          throw new RuntimeException('着席していないキャストは注文担当に選べません');
        }
        if ($stAssignment !== null) {
          $priceEx = (int)($map[$mid]['price_ex'] ?? 0);
          $stAssignment->execute([
            $storeId,
            $orderId,
            $orderItemId,
            ($ticketId && $ticketId > 0) ? $ticketId : null,
            $mid,
            $castUserId,
            (string)$qty,
            max(0, $priceEx * $qty),
            null,
          ]);
        }
      }
    }

    $pdo->commit();
    return $orderId;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function orders_repo_fetch_ticket_order_items_with_assignments(PDO $pdo, int $storeId, int $ticketId): array {
  if ($storeId <= 0 || $ticketId <= 0) {
    return [];
  }

  $sql = "
    SELECT
      o.id AS order_id,
      o.status AS order_status,
      o.created_at AS order_created_at,
      oi.id AS order_item_id,
      oi.menu_id,
      oi.qty,
      oi.item_status,
      oi.note AS item_note,
      COALESCE(m.name, CONCAT('menu#', oi.menu_id)) AS menu_name,
      COALESCE(m.price_ex, 0) AS price_ex
    FROM order_orders o
    JOIN order_order_items oi ON oi.order_id = o.id AND oi.store_id = o.store_id
    LEFT JOIN order_menus m ON m.id = oi.menu_id AND m.store_id = oi.store_id
    WHERE o.store_id = ?
      AND o.ticket_id = ?
    ORDER BY o.created_at DESC, o.id DESC, oi.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$storeId, $ticketId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $assignmentsByItem = [];
  if ($rows !== [] && orders_repo_item_cast_assignments_ready($pdo)) {
    $itemIds = array_values(array_unique(array_map(static fn(array $row): int => (int)$row['order_item_id'], $rows)));
    if ($itemIds !== []) {
      $in = implode(',', array_fill(0, count($itemIds), '?'));
      $stAssign = $pdo->prepare("
        SELECT
          a.id,
          a.order_item_id,
          a.cast_user_id,
          a.consumed_qty,
          a.amount_yen,
          a.note,
          COALESCE(NULLIF(TRIM(u.display_name), ''), u.login_id, CONCAT('user#', a.cast_user_id)) AS cast_name
        FROM order_item_cast_assignments a
        LEFT JOIN users u ON u.id = a.cast_user_id
        WHERE a.store_id = ?
          AND a.order_item_id IN ($in)
        ORDER BY a.id ASC
      ");
      $stAssign->execute(array_merge([$storeId], $itemIds));
      foreach ($stAssign->fetchAll(PDO::FETCH_ASSOC) ?: [] as $assignment) {
        $assignmentsByItem[(int)$assignment['order_item_id']][] = $assignment;
      }
    }
  }

  foreach ($rows as &$row) {
    $row['assignments'] = $assignmentsByItem[(int)$row['order_item_id']] ?? [];
  }
  unset($row);

  return $rows;
}

function orders_repo_fetch_ticket_cast_drink_summary(PDO $pdo, int $storeId, int $ticketId): array {
  if ($storeId <= 0 || $ticketId <= 0 || !orders_repo_item_cast_assignments_ready($pdo)) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT
      a.cast_user_id,
      COALESCE(NULLIF(TRIM(u.display_name), ''), u.login_id, CONCAT('user#', a.cast_user_id)) AS cast_name,
      SUM(a.amount_yen) AS total_amount_yen,
      SUM(a.consumed_qty) AS total_consumed_qty,
      COUNT(DISTINCT a.order_item_id) AS item_count
    FROM order_item_cast_assignments a
    LEFT JOIN users u ON u.id = a.cast_user_id
    WHERE a.store_id = ?
      AND a.ticket_id = ?
    GROUP BY a.cast_user_id, cast_name
    ORDER BY total_amount_yen DESC, total_consumed_qty DESC, cast_name ASC
  ");
  $st->execute([$storeId, $ticketId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function orders_repo_is_bottle_meta(?array $meta): bool {
  if (!is_array($meta)) {
    return false;
  }

  $type = strtolower(trim((string)($meta['product_type'] ?? $meta['type'] ?? '')));
  if (in_array($type, ['bottle', 'alcohol'], true)) {
    return true;
  }

  if (!empty($meta['is_bottle'])) {
    return true;
  }

  $category = trim((string)($meta['category'] ?? $meta['category_name'] ?? ''));
  if ($category !== '' && mb_stripos($category, 'ドリンク') !== false) {
    return true;
  }
  if ($category !== '' && mb_stripos($category, 'ボトル') !== false) {
    return true;
  }

  $name = trim((string)($meta['product_name'] ?? $meta['name'] ?? $meta['label'] ?? $meta['title'] ?? ''));
  if ($name !== '' && mb_stripos($name, 'ボトル') !== false) {
    return true;
  }
  if ($name !== '' && preg_match('/^\s*BS[\s\-_.　]*/iu', $name) === 1) {
    return true;
  }

  $haystacks = array_filter([
    $category,
    $name,
    trim((string)($meta['description'] ?? $meta['menu_description'] ?? '')),
  ], static fn(string $value): bool => $value !== '');

  $keywords = [
    'シャンパン',
    '焼酎',
    'ウイスキー',
    'ブランデー',
    'ワイン',
    '吉四六',
    '黒霧島',
    '鏡月',
    'いいちこ',
    'ボトルキープ',
  ];
  foreach ($haystacks as $text) {
    foreach ($keywords as $keyword) {
      if (mb_stripos($text, $keyword) !== false) {
        return true;
      }
    }
  }

  return false;
}

function orders_repo_fetch_cast_assignment_rows(PDO $pdo, int $storeId, string $from, string $to, ?int $ticketId = null): array {
  if ($storeId <= 0 || !orders_repo_item_cast_assignments_ready($pdo)) {
    return [];
  }

  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    throw new InvalidArgumentException('invalid date range');
  }

  $menuTypeExpr = orders_repo_column_exists($pdo, 'order_menus', 'product_type')
    ? 'm.product_type'
    : (orders_repo_column_exists($pdo, 'order_menus', 'type') ? 'm.type' : 'NULL');
  $menuIsBottleExpr = orders_repo_column_exists($pdo, 'order_menus', 'is_bottle') ? 'm.is_bottle' : '0';
  $menuDescriptionExpr = orders_repo_column_exists($pdo, 'order_menus', 'description') ? 'm.description' : 'NULL';
  $stockSellingExpr = orders_repo_column_exists($pdo, 'stock_products', 'selling_price_yen') ? 'sp.selling_price_yen' : 'NULL';
  $stockBackRateExpr = orders_repo_column_exists($pdo, 'stock_products', 'bottle_back_rate_pct') ? 'sp.bottle_back_rate_pct' : 'NULL';

  $sql = "
    SELECT
      a.id AS assignment_id,
      a.ticket_id,
      a.order_id,
      a.order_item_id,
      a.menu_id,
      a.cast_user_id,
      a.consumed_qty,
      a.amount_yen,
      a.note,
      COALESCE(NULLIF(TRIM(u.display_name), ''), u.login_id, CONCAT('user#', a.cast_user_id)) AS cast_name,
      COALESCE(NULLIF(TRIM(m.name), ''), CONCAT('menu#', a.menu_id)) AS menu_name,
      COALESCE(m.price_ex, 0) AS price_ex,
      c.name AS category_name,
      {$menuTypeExpr} AS product_type,
      {$menuIsBottleExpr} AS is_bottle,
      {$menuDescriptionExpr} AS menu_description,
      sp.id AS stock_product_id,
      {$stockSellingExpr} AS stock_selling_price_yen,
      {$stockBackRateExpr} AS stock_back_rate_pct,
      COALESCE(NULLIF(t.business_date, ''), DATE(o.created_at)) AS business_date,
      o.created_at AS order_created_at,
      o.status AS order_status,
      oi.qty AS ordered_qty,
      oi.item_status
    FROM order_item_cast_assignments a
    JOIN order_orders o ON o.id = a.order_id AND o.store_id = a.store_id
    JOIN order_order_items oi ON oi.id = a.order_item_id AND oi.store_id = a.store_id
    LEFT JOIN tickets t ON t.id = a.ticket_id AND t.store_id = a.store_id
    LEFT JOIN users u ON u.id = a.cast_user_id
    LEFT JOIN order_menus m ON m.id = a.menu_id AND m.store_id = a.store_id
    LEFT JOIN order_menu_categories c ON c.id = m.category_id AND c.store_id = m.store_id
    LEFT JOIN stock_products sp
      ON sp.store_id = a.store_id
     AND TRIM(sp.name) = TRIM(COALESCE(m.name, ''))
    WHERE a.store_id = ?
      AND COALESCE(NULLIF(t.business_date, ''), DATE(o.created_at)) >= ?
      AND COALESCE(NULLIF(t.business_date, ''), DATE(o.created_at)) <= ?
  ";
  $params = [$storeId, $from, $to];

  if (($ticketId ?? 0) > 0) {
    $sql .= " AND a.ticket_id = ?";
    $params[] = (int)$ticketId;
  }

  $sql .= " ORDER BY business_date DESC, a.ticket_id DESC, a.order_id DESC, a.order_item_id ASC, a.id ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);

  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($rows as &$row) {
    $row['is_bottle_item'] = orders_repo_is_bottle_meta([
      'product_type' => $row['product_type'] ?? null,
      'is_bottle' => $row['is_bottle'] ?? null,
      'category_name' => $row['category_name'] ?? null,
      'name' => $row['menu_name'] ?? null,
      'description' => $row['menu_description'] ?? null,
    ]);
    $sellingPriceYen = isset($row['stock_selling_price_yen']) && $row['stock_selling_price_yen'] !== null
      ? (int)$row['stock_selling_price_yen']
      : null;
    $backRatePct = isset($row['stock_back_rate_pct']) && $row['stock_back_rate_pct'] !== null
      ? (float)$row['stock_back_rate_pct']
      : null;
    $consumedQty = (float)($row['consumed_qty'] ?? 0);
    $row['back_base_amount_yen'] = ($sellingPriceYen !== null && $sellingPriceYen > 0)
      ? (int)round($sellingPriceYen * $consumedQty)
      : null;
    $row['back_amount_yen'] = ($sellingPriceYen !== null && $sellingPriceYen > 0 && $backRatePct !== null && $backRatePct > 0)
      ? (int)round($sellingPriceYen * $consumedQty * ($backRatePct / 100))
      : null;
  }
  unset($row);

  return $rows;
}

function orders_repo_aggregate_cast_assignment_summary(array $rows, bool $bottleOnly = false): array {
  $bucket = [];

  foreach ($rows as $row) {
    if ($bottleOnly && empty($row['is_bottle_item'])) {
      continue;
    }

    $castUserId = (int)($row['cast_user_id'] ?? 0);
    if ($castUserId <= 0) {
      continue;
    }

    if (!isset($bucket[$castUserId])) {
      $bucket[$castUserId] = [
        'cast_user_id' => $castUserId,
        'cast_name' => (string)($row['cast_name'] ?? ('user#' . $castUserId)),
        'item_count' => 0,
        'total_amount_yen' => 0,
        'total_consumed_qty' => 0.0,
        'back_base_amount_yen' => 0,
        'back_amount_yen' => 0,
        'bottle_item_count' => 0,
        'bottle_amount_yen' => 0,
        'bottle_consumed_qty' => 0.0,
        'bottle_back_base_amount_yen' => 0,
        'bottle_back_amount_yen' => 0,
        'bottle_breakdown' => [],
      ];
    }

    $bucket[$castUserId]['item_count']++;
    $bucket[$castUserId]['total_amount_yen'] += (int)($row['amount_yen'] ?? 0);
    $bucket[$castUserId]['total_consumed_qty'] += (float)($row['consumed_qty'] ?? 0);
    $bucket[$castUserId]['back_base_amount_yen'] += (int)($row['back_base_amount_yen'] ?? 0);
    $bucket[$castUserId]['back_amount_yen'] += (int)($row['back_amount_yen'] ?? 0);

    if (!empty($row['is_bottle_item'])) {
      $label = (string)($row['menu_name'] ?? ('menu#' . (int)($row['menu_id'] ?? 0)));
      $bucket[$castUserId]['bottle_item_count']++;
      $bucket[$castUserId]['bottle_amount_yen'] += (int)($row['amount_yen'] ?? 0);
      $bucket[$castUserId]['bottle_consumed_qty'] += (float)($row['consumed_qty'] ?? 0);
      $bucket[$castUserId]['bottle_back_base_amount_yen'] += (int)($row['back_base_amount_yen'] ?? 0);
      $bucket[$castUserId]['bottle_back_amount_yen'] += (int)($row['back_amount_yen'] ?? 0);
      $bucket[$castUserId]['bottle_breakdown'][$label] = ($bucket[$castUserId]['bottle_breakdown'][$label] ?? 0.0) + (float)($row['consumed_qty'] ?? 0);
    }
  }

  foreach ($bucket as &$row) {
    ksort($row['bottle_breakdown']);
  }
  unset($row);

  $result = array_values($bucket);
  usort($result, static function (array $a, array $b): int {
    $cmp = ((float)$b['bottle_consumed_qty']) <=> ((float)$a['bottle_consumed_qty']);
    if ($cmp !== 0) {
      return $cmp;
    }
    $cmp = ((int)$b['total_amount_yen']) <=> ((int)$a['total_amount_yen']);
    if ($cmp !== 0) {
      return $cmp;
    }
    return strcmp((string)($a['cast_name'] ?? ''), (string)($b['cast_name'] ?? ''));
  });

  return $result;
}

function orders_repo_aggregate_menu_assignment_summary(array $rows, bool $bottleOnly = true): array {
  $bucket = [];

  foreach ($rows as $row) {
    $isBottle = !empty($row['is_bottle_item']);
    if ($bottleOnly && !$isBottle) {
      continue;
    }

    $menuId = (int)($row['menu_id'] ?? 0);
    $key = $menuId > 0 ? (string)$menuId : (string)($row['menu_name'] ?? 'menu');
    if (!isset($bucket[$key])) {
      $bucket[$key] = [
        'menu_id' => $menuId,
        'menu_name' => (string)($row['menu_name'] ?? ('menu#' . $menuId)),
        'category_name' => (string)($row['category_name'] ?? ''),
        'assignment_count' => 0,
        'total_amount_yen' => 0,
        'total_consumed_qty' => 0.0,
        'back_base_amount_yen' => 0,
        'back_amount_yen' => 0,
        'cast_user_ids' => [],
      ];
    }

    $bucket[$key]['assignment_count']++;
    $bucket[$key]['total_amount_yen'] += (int)($row['amount_yen'] ?? 0);
    $bucket[$key]['total_consumed_qty'] += (float)($row['consumed_qty'] ?? 0);
    $bucket[$key]['back_base_amount_yen'] += (int)($row['back_base_amount_yen'] ?? 0);
    $bucket[$key]['back_amount_yen'] += (int)($row['back_amount_yen'] ?? 0);
    $castUserId = (int)($row['cast_user_id'] ?? 0);
    if ($castUserId > 0) {
      $bucket[$key]['cast_user_ids'][$castUserId] = true;
    }
  }

  foreach ($bucket as &$row) {
    $row['cast_count'] = count($row['cast_user_ids']);
    unset($row['cast_user_ids']);
  }
  unset($row);

  $result = array_values($bucket);
  usort($result, static function (array $a, array $b): int {
    $cmp = ((float)$b['total_consumed_qty']) <=> ((float)$a['total_consumed_qty']);
    if ($cmp !== 0) {
      return $cmp;
    }
    $cmp = ((int)$b['total_amount_yen']) <=> ((int)$a['total_amount_yen']);
    if ($cmp !== 0) {
      return $cmp;
    }
    return strcmp((string)($a['menu_name'] ?? ''), (string)($b['menu_name'] ?? ''));
  });

  return $result;
}

function orders_repo_aggregate_business_date_assignment_summary(array $rows, bool $bottleOnly = false): array {
  $bucket = [];

  foreach ($rows as $row) {
    if ($bottleOnly && empty($row['is_bottle_item'])) {
      continue;
    }

    $businessDate = trim((string)($row['business_date'] ?? ''));
    if ($businessDate === '') {
      continue;
    }

    if (!isset($bucket[$businessDate])) {
      $bucket[$businessDate] = [
        'business_date' => $businessDate,
        'ticket_ids' => [],
        'cast_user_ids' => [],
        'total_amount_yen' => 0,
        'total_consumed_qty' => 0.0,
        'bottle_amount_yen' => 0,
        'bottle_consumed_qty' => 0.0,
        'back_base_amount_yen' => 0,
        'back_amount_yen' => 0,
        'bottle_back_base_amount_yen' => 0,
        'bottle_back_amount_yen' => 0,
      ];
    }

    $bucket[$businessDate]['total_amount_yen'] += (int)($row['amount_yen'] ?? 0);
    $bucket[$businessDate]['total_consumed_qty'] += (float)($row['consumed_qty'] ?? 0);
    $bucket[$businessDate]['back_base_amount_yen'] += (int)($row['back_base_amount_yen'] ?? 0);
    $bucket[$businessDate]['back_amount_yen'] += (int)($row['back_amount_yen'] ?? 0);

    $ticketId = (int)($row['ticket_id'] ?? 0);
    if ($ticketId > 0) {
      $bucket[$businessDate]['ticket_ids'][$ticketId] = true;
    }
    $castUserId = (int)($row['cast_user_id'] ?? 0);
    if ($castUserId > 0) {
      $bucket[$businessDate]['cast_user_ids'][$castUserId] = true;
    }

    if (!empty($row['is_bottle_item'])) {
      $bucket[$businessDate]['bottle_amount_yen'] += (int)($row['amount_yen'] ?? 0);
      $bucket[$businessDate]['bottle_consumed_qty'] += (float)($row['consumed_qty'] ?? 0);
      $bucket[$businessDate]['bottle_back_base_amount_yen'] += (int)($row['back_base_amount_yen'] ?? 0);
      $bucket[$businessDate]['bottle_back_amount_yen'] += (int)($row['back_amount_yen'] ?? 0);
    }
  }

  foreach ($bucket as &$row) {
    $row['ticket_count'] = count($row['ticket_ids']);
    $row['cast_count'] = count($row['cast_user_ids']);
    unset($row['ticket_ids'], $row['cast_user_ids']);
  }
  unset($row);

  krsort($bucket);
  return array_values($bucket);
}

function orders_repo_replace_order_item_assignments(PDO $pdo, int $storeId, int $ticketId, int $orderItemId, array $assignments): void {
  if ($storeId <= 0 || $ticketId <= 0 || $orderItemId <= 0) {
    throw new InvalidArgumentException('invalid params');
  }
  if (!orders_repo_item_cast_assignments_ready($pdo)) {
    throw new RuntimeException('order_item_cast_assignments テーブルを先に作成してください');
  }

  $stItem = $pdo->prepare("
    SELECT
      oi.id,
      oi.order_id,
      oi.menu_id,
      oi.qty,
      COALESCE(m.price_ex, 0) AS price_ex
    FROM order_order_items oi
    JOIN order_orders o ON o.id = oi.order_id AND o.store_id = oi.store_id
    LEFT JOIN order_menus m ON m.id = oi.menu_id AND m.store_id = oi.store_id
    WHERE oi.store_id = ?
      AND oi.id = ?
      AND o.ticket_id = ?
    LIMIT 1
  ");
  $stItem->execute([$storeId, $orderItemId, $ticketId]);
  $item = $stItem->fetch(PDO::FETCH_ASSOC);
  if (!is_array($item)) {
    throw new RuntimeException('注文アイテムが見つかりません');
  }

  $allowedCastMap = [];
  foreach (orders_repo_fetch_ticket_assignment_candidates($pdo, $storeId, $ticketId) as $cast) {
    $allowedCastMap[(int)$cast['user_id']] = true;
  }

  $priceEx = (int)($item['price_ex'] ?? 0);
  $orderedQty = (float)($item['qty'] ?? 0);
  $normalized = [];
  $sum = 0.0;
  foreach ($assignments as $assignment) {
    if (!is_array($assignment)) {
      continue;
    }
    $castUserId = (int)($assignment['cast_user_id'] ?? 0);
    $qtyRaw = trim((string)($assignment['consumed_qty'] ?? ''));
    $note = trim((string)($assignment['note'] ?? ''));
    if ($castUserId <= 0 || $qtyRaw === '') {
      continue;
    }
    if (!preg_match('/^\d+(?:\.\d{1,2})?$/', $qtyRaw)) {
      throw new RuntimeException('消費数量は数値で入力してください');
    }
    $consumedQty = (float)$qtyRaw;
    if ($consumedQty <= 0) {
      continue;
    }
    if ($allowedCastMap !== [] && !isset($allowedCastMap[$castUserId])) {
      throw new RuntimeException('この伝票に紐づかないキャストが含まれています');
    }
    $sum += $consumedQty;
    $normalized[] = [
      'cast_user_id' => $castUserId,
      'consumed_qty' => $consumedQty,
      'note' => ($note !== '' ? $note : null),
    ];
  }

  if ($sum > $orderedQty + 0.0001) {
    throw new RuntimeException('割当数量の合計が注文数を超えています');
  }

  $pdo->beginTransaction();
  try {
    $pdo->prepare("DELETE FROM order_item_cast_assignments WHERE store_id = ? AND order_item_id = ?")
      ->execute([$storeId, $orderItemId]);

    if ($normalized !== []) {
      $stInsert = $pdo->prepare("
        INSERT INTO order_item_cast_assignments
          (store_id, order_id, order_item_id, ticket_id, menu_id, cast_user_id, consumed_qty, amount_yen, note, created_at, updated_at)
        VALUES
          (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW(), NOW())
      ");
      foreach ($normalized as $assignment) {
        $amountYen = (int)round($priceEx * (float)$assignment['consumed_qty']);
        $stInsert->execute([
          $storeId,
          (int)$item['order_id'],
          $orderItemId,
          $ticketId,
          (int)$item['menu_id'],
          (int)$assignment['cast_user_id'],
          number_format((float)$assignment['consumed_qty'], 2, '.', ''),
          $amountYen,
          $assignment['note'],
        ]);
      }
    }

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function orders_repo_kitchen_list(PDO $pdo, int $storeId): array {
  $sql = "
    SELECT
      o.id AS order_id, o.table_id, t.name AS table_name,
      o.status AS order_status, o.note AS order_note, o.created_at,
      i.id AS item_id, i.menu_id, i.qty, i.item_status, i.note AS item_note,
      m.name AS menu_name
    FROM order_orders o
    JOIN order_tables t ON t.id = o.table_id
    JOIN order_order_items i ON i.order_id = o.id
    JOIN order_menus m ON m.id = i.menu_id
    WHERE o.store_id = ?
      AND o.status IN ('new','accepted')
      AND i.item_status IN ('new','cooking')
    ORDER BY o.created_at DESC, o.id DESC, i.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  $orders = [];
  foreach ($rows as $r) {
    $oid = (int)$r['order_id'];
    if (!isset($orders[$oid])) {
      $orders[$oid] = [
        'order_id' => $oid,
        'table_id' => (int)$r['table_id'],
        'table_name' => (string)$r['table_name'],
        'order_status' => (string)$r['order_status'],
        'order_note' => (string)($r['order_note'] ?? ''),
        'created_at' => (string)$r['created_at'],
        'items' => [],
      ];
    }
    $orders[$oid]['items'][] = [
      'item_id' => (int)$r['item_id'],
      'menu_id' => (int)$r['menu_id'],
      'menu_name' => (string)$r['menu_name'],
      'qty' => (int)$r['qty'],
      'item_status' => (string)$r['item_status'],
      'item_note' => (string)($r['item_note'] ?? ''),
    ];
  }
  return array_values($orders);
}

function orders_repo_update_item_status(PDO $pdo, int $storeId, int $itemId, string $status): array {
  $allowed = ['cooking','served','canceled'];
  if (!in_array($status, $allowed, true)) throw new RuntimeException('invalid status');

  $pdo->beginTransaction();
  try {
    // item の order_id を取得（store一致）
    $st = $pdo->prepare("SELECT order_id FROM order_order_items WHERE id=? AND store_id=? LIMIT 1");
    $st->execute([$itemId, $storeId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('item not found');
    $orderId = (int)$row['order_id'];

    // item更新
    $st = $pdo->prepare("UPDATE order_order_items SET item_status=? WHERE id=? AND store_id=?");
    $st->execute([$status, $itemId, $storeId]);

    // 残り(new/cooking)が 0 なら done
    $st = $pdo->prepare("
      SELECT SUM(item_status IN ('new','cooking')) AS remain_cnt
      FROM order_order_items
      WHERE store_id=? AND order_id=?
    ");
    $st->execute([$storeId, $orderId]);
    $remain = (int)($st->fetch(PDO::FETCH_ASSOC)['remain_cnt'] ?? 0);

    $newOrderStatus = ($remain === 0) ? 'done' : 'accepted';
    $st = $pdo->prepare("UPDATE order_orders SET status=? WHERE id=? AND store_id=? AND status<>'canceled'");
    $st->execute([$newOrderStatus, $orderId, $storeId]);

    $pdo->commit();
    return ['order_id' => $orderId, 'order_status' => $newOrderStatus];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
