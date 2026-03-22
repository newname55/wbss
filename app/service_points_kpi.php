<?php
declare(strict_types=1);

if (!function_exists('points_kpi_normalize_set_kind')) {
  function points_kpi_normalize_set_kind(string $kind): string {
    $kind = trim($kind);
    return match ($kind) {
      'full50', 'normal50' => 'normal50',
      'half25' => 'half25',
      'pack_douhan' => 'pack_douhan',
      default => 'normal50',
    };
  }
}

if (!function_exists('points_kpi_calc_points')) {
  function points_kpi_calc_points(string $effectiveKind, array $shMap): array {
    $douhan = [];
    $shimei = [];

    if ($effectiveKind === 'pack_douhan') {
      foreach ($shMap as $castUserId => $_type) {
        $castUserId = (int)$castUserId;
        if ($castUserId <= 0) continue;
        $douhan[$castUserId] = ($douhan[$castUserId] ?? 0.0) + 1.0;
        $shimei[$castUserId] = ($shimei[$castUserId] ?? 0.0) + 1.5;
      }
    } elseif ($effectiveKind === 'half25') {
      foreach ($shMap as $castUserId => $_type) {
        $castUserId = (int)$castUserId;
        if ($castUserId <= 0) continue;
        $shimei[$castUserId] = ($shimei[$castUserId] ?? 0.0) + 0.5;
      }
    } else {
      foreach ($shMap as $castUserId => $type) {
        $castUserId = (int)$castUserId;
        if ($castUserId <= 0) continue;
        $shimei[$castUserId] = ($shimei[$castUserId] ?? 0.0) + (((string)$type === 'hon') ? 1.0 : 0.5);
      }
    }

    return ['douhan' => $douhan, 'shimei' => $shimei];
  }
}

if (!function_exists('points_kpi_is_bottle_meta')) {
  function points_kpi_is_bottle_meta(?array $meta): bool {
    if (!is_array($meta)) return false;

    $type = strtolower(trim((string)($meta['product_type'] ?? $meta['type'] ?? '')));
    if (in_array($type, ['bottle', 'alcohol'], true)) return true;

    if (!empty($meta['is_bottle'])) return true;

    $category = trim((string)($meta['category'] ?? ''));
    if ($category !== '' && mb_stripos($category, 'ボトル') !== false) return true;

    $name = trim((string)($meta['product_name'] ?? $meta['name'] ?? $meta['label'] ?? $meta['title'] ?? ''));
    if ($name !== '' && mb_stripos($name, 'ボトル') !== false) return true;

    return false;
  }
}

if (!function_exists('points_kpi_meta_label')) {
  function points_kpi_meta_label(?array $meta, bool $isBottle): string {
    if (!is_array($meta)) {
      return $isBottle ? 'ボトル（名称未設定）' : 'ドリンク';
    }

    $label = trim((string)($meta['product_name'] ?? $meta['name'] ?? $meta['label'] ?? $meta['title'] ?? ''));
    if ($label !== '') return $label;

    return $isBottle ? 'ボトル（名称未設定）' : 'ドリンク';
  }
}

if (!function_exists('points_kpi_resolve_drink_cost_total')) {
  function points_kpi_resolve_drink_cost_total(array $drink): int {
    $qty = max(1, (int)($drink['qty'] ?? 1));

    foreach (['cost_total_yen', 'cost_total', 'raw_cost_total', 'purchase_total'] as $key) {
      if (isset($drink[$key]) && is_numeric($drink[$key])) {
        return max(0, (int)$drink[$key]);
      }
    }

    $meta = is_array($drink['meta'] ?? null) ? $drink['meta'] : [];
    foreach (['cost_total_yen', 'cost_total', 'raw_cost_total', 'purchase_total'] as $key) {
      if (isset($meta[$key]) && is_numeric($meta[$key])) {
        return max(0, (int)$meta[$key]);
      }
    }

    foreach (['cost_yen', 'unit_cost_yen', 'unit_cost', 'raw_cost', 'purchase_cost', 'purchase_price', 'cost', 'cost_ex'] as $key) {
      if (isset($drink[$key]) && is_numeric($drink[$key])) {
        return max(0, (int)$drink[$key]) * $qty;
      }
    }
    foreach (['cost_yen', 'unit_cost_yen', 'unit_cost', 'raw_cost', 'purchase_cost', 'purchase_price', 'cost', 'cost_ex'] as $key) {
      if (isset($meta[$key]) && is_numeric($meta[$key])) {
        return max(0, (int)$meta[$key]) * $qty;
      }
    }

    return 0;
  }
}

if (!function_exists('points_kpi_kind_label')) {
  function points_kpi_kind_label(string $kind): string {
    return match (points_kpi_normalize_set_kind($kind)) {
      'half25' => 'ハーフ',
      'pack_douhan' => '同伴',
      default => '通常',
    };
  }
}

if (!function_exists('points_kpi_nomination_label')) {
  function points_kpi_nomination_label(string $type): string {
    return ((string)$type === 'hon') ? '本指名' : '場内';
  }
}

if (!function_exists('points_kpi_extract_shimei_map_from_set')) {
  function points_kpi_extract_shimei_map_from_set(array $set, array $castNoMap): array {
    $map = [];
    $customers = $set['customers'] ?? null;
    if (!is_array($customers)) return $map;

    foreach ($customers as $customer) {
      if (!is_array($customer)) continue;
      if ((string)($customer['mode'] ?? '') !== 'shimei') continue;
      $shimei = $customer['shimei'] ?? null;
      if (!is_array($shimei)) continue;

      foreach ($shimei as $rawCastKey => $kind) {
        $castUserId = 0;
        if (isset($castNoMap[(string)$rawCastKey])) {
          $castUserId = (int)$castNoMap[(string)$rawCastKey];
        } else {
          $castUserId = (int)$rawCastKey;
        }
        if ($castUserId <= 0) continue;

        $type = ((string)$kind === 'hon') ? 'hon' : 'jounai';
        if (($map[$castUserId] ?? '') === 'hon') continue;
        $map[$castUserId] = $type;
      }
    }

    return $map;
  }
}

if (!function_exists('points_kpi_apply_top_level_shimei')) {
  function points_kpi_apply_top_level_shimei(array &$rowMap, array &$stats, array $payload, array $castNoMap): void {
    $rows = $payload['shimei'] ?? null;
    if (!is_array($rows)) return;

    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $rawCastId = (string)($row['cast_user_id'] ?? '');
      if ($rawCastId === '') continue;

      $castUserId = isset($castNoMap[$rawCastId]) ? (int)$castNoMap[$rawCastId] : (int)$rawCastId;
      if ($castUserId <= 0) continue;

      $people = max(1, (int)($row['people'] ?? 1));
      $setKind = points_kpi_normalize_set_kind((string)($row['set_kind'] ?? 'normal50'));
      $type = ((string)($row['shimei_type'] ?? 'normal') === 'jounai') ? 'jounai' : 'hon';

      if (!isset($rowMap[$castUserId])) {
        $rowMap[$castUserId] = [
          'user_id' => $castUserId,
          'display_name' => 'user#' . $castUserId,
          'shop_tag' => '',
          'shimei_point' => 0.0,
          'douhan_count' => 0.0,
          'drink_total' => 0,
          'drink_cost_total' => 0,
          'bottle_total' => 0,
          'bottle_cost_total' => 0,
          'bottle_breakdown' => [],
          'point_events' => [],
          'tickets' => [],
        ];
      }

      $tmp = points_kpi_calc_points($setKind === 'pack_douhan' ? 'pack_douhan' : $setKind, [$castUserId => $type]);
      $shimeiValue = (($tmp['shimei'][$castUserId] ?? 0.0) * $people);
      $douhanValue = (($tmp['douhan'][$castUserId] ?? 0.0) * $people);
      $rowMap[$castUserId]['shimei_point'] += $shimeiValue;
      $rowMap[$castUserId]['douhan_count'] += $douhanValue;
      $rowMap[$castUserId]['tickets'][$setKind . ':top'] = true;
      $rowMap[$castUserId]['point_events'][] = [
        'source_table' => 'tickets',
        'source_column' => 'totals_snapshot',
        'ticket_id' => 0,
        'set_no' => 1,
        'set_kind' => $setKind,
        'nomination_type' => $type,
        'shimei_point' => $shimeiValue,
        'douhan_point' => $douhanValue,
      ];
      $stats['shimei_total'] += $shimeiValue;
      $stats['douhan_total'] += $douhanValue;
    }
  }
}

if (!function_exists('points_kpi_resolve_drink_cast_user_id')) {
  function points_kpi_resolve_drink_cast_user_id(array $drink, array $set, array $castNoMap): int {
    $payerType = (string)($drink['payer_type'] ?? '');
    $payerId = (string)($drink['payer_id'] ?? '');

    if ($payerType === 'shimei') {
      return isset($castNoMap[$payerId]) ? (int)$castNoMap[$payerId] : (int)$payerId;
    }

    if ($payerType !== 'free') {
      return 0;
    }

    $parts = explode(':', $payerId, 2);
    $customerNo = (int)($parts[0] ?? 0);
    $role = (string)($parts[1] ?? '');
    if ($customerNo <= 0 || $role === '') return 0;

    $customer = $set['customers'][(string)$customerNo] ?? null;
    if (!is_array($customer)) return 0;

    $free = $customer['free'] ?? null;
    if (!is_array($free)) return 0;

    $rawCastNo = (string)($free[$role] ?? '');
    if ($rawCastNo === '') return 0;

    return isset($castNoMap[$rawCastNo]) ? (int)$castNoMap[$rawCastNo] : (int)$rawCastNo;
  }
}

if (!function_exists('points_kpi_bootstrap_rows')) {
  function points_kpi_bootstrap_rows(array $casts): array {
    $rows = [];
    foreach ($casts as $cast) {
      $userId = (int)($cast['user_id'] ?? 0);
      if ($userId <= 0) continue;
      $rows[$userId] = [
        'user_id' => $userId,
        'display_name' => (string)($cast['display_name'] ?? ('user#' . $userId)),
        'shop_tag' => trim((string)($cast['shop_tag'] ?? '')),
        'shimei_point' => 0.0,
        'douhan_count' => 0.0,
        'drink_total' => 0,
        'drink_cost_total' => 0,
        'bottle_total' => 0,
        'bottle_cost_total' => 0,
        'bottle_breakdown' => [],
        'point_events' => [],
        'tickets' => [],
      ];
    }
    return $rows;
  }
}

if (!function_exists('points_kpi_day_summary')) {
  function points_kpi_day_summary(PDO $pdo, int $storeId, string $businessDate, array $casts): array {
    $rows = points_kpi_bootstrap_rows($casts);

    $castNoMap = [];
    foreach ($casts as $cast) {
      $shopTag = trim((string)($cast['shop_tag'] ?? ''));
      $userId = (int)($cast['user_id'] ?? 0);
      if ($shopTag !== '' && $userId > 0) {
        $castNoMap[$shopTag] = $userId;
      }
    }

    $st = $pdo->prepare("
      SELECT id, status, totals_snapshot
      FROM tickets
      WHERE store_id = ?
        AND business_date = ?
        AND COALESCE(status, '') <> 'void'
      ORDER BY id ASC
    ");
    $st->execute([$storeId, $businessDate]);
    $tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = [
      'ticket_count' => 0,
      'source_ticket_count' => 0,
      'shimei_total' => 0.0,
      'douhan_total' => 0.0,
      'drink_total' => 0,
      'bottle_total' => 0,
    ];

    foreach ($tickets as $ticket) {
      $stats['ticket_count']++;

      $snapshotRaw = (string)($ticket['totals_snapshot'] ?? '');
      if ($snapshotRaw === '') continue;

      $snapshot = json_decode($snapshotRaw, true);
      if (!is_array($snapshot)) continue;

      $payload = $snapshot['payload'] ?? null;
      if (!is_array($payload)) continue;

      $stats['source_ticket_count']++;
      $ticketId = (int)($ticket['id'] ?? 0);

      $sets = $payload['sets'] ?? [];
      if (!is_array($sets)) $sets = [];

      foreach ($sets as $setIndex => $set) {
        if (!is_array($set)) continue;

        $shMap = points_kpi_extract_shimei_map_from_set($set, $castNoMap);
        $setKind = points_kpi_normalize_set_kind((string)($set['kind'] ?? 'normal50'));
        $effectiveKind = ($setKind === 'pack_douhan' && count($shMap) === 0) ? 'normal50' : $setKind;
        $pt = points_kpi_calc_points($effectiveKind, $shMap);

        foreach ($pt['shimei'] as $castUserId => $value) {
          if (!isset($rows[$castUserId])) {
          $rows[$castUserId] = [
            'user_id' => (int)$castUserId,
            'display_name' => 'user#' . (int)$castUserId,
            'shop_tag' => '',
            'shimei_point' => 0.0,
            'douhan_count' => 0.0,
            'drink_total' => 0,
            'drink_cost_total' => 0,
            'bottle_total' => 0,
            'bottle_cost_total' => 0,
            'bottle_breakdown' => [],
            'point_events' => [],
            'tickets' => [],
          ];
        }
        $rows[$castUserId]['shimei_point'] += (float)$value;
        $rows[$castUserId]['tickets'][$ticketId . ':' . $setIndex] = true;
        $stats['shimei_total'] += (float)$value;
      }

        foreach ($pt['douhan'] as $castUserId => $value) {
          if (($setIndex + 1) !== 1) continue;
          if (!isset($rows[$castUserId])) continue;
          $rows[$castUserId]['douhan_count'] += (float)$value;
          $rows[$castUserId]['tickets'][$ticketId . ':' . $setIndex] = true;
          $stats['douhan_total'] += (float)$value;
        }

        foreach ($shMap as $castUserId => $nominationType) {
          if (!isset($rows[$castUserId])) continue;

          $shimeiPoint = (float)($pt['shimei'][$castUserId] ?? 0.0);
          $douhanPoint = (($setIndex + 1) === 1) ? (float)($pt['douhan'][$castUserId] ?? 0.0) : 0.0;
          if ($shimeiPoint <= 0.0 && $douhanPoint <= 0.0) continue;

          $rows[$castUserId]['point_events'][] = [
            'source_table' => 'tickets',
            'source_column' => 'totals_snapshot',
            'ticket_id' => $ticketId,
            'set_no' => $setIndex + 1,
            'set_kind' => $effectiveKind,
            'nomination_type' => (string)$nominationType,
            'shimei_point' => $shimeiPoint,
            'douhan_point' => $douhanPoint,
          ];
        }

        $drinks = $set['drinks'] ?? null;
        if (!is_array($drinks)) continue;

        foreach ($drinks as $drink) {
          if (!is_array($drink)) continue;

          $amount = max(0, (int)($drink['amount'] ?? 0));
          if ($amount <= 0) {
            $price = max(0, (int)($drink['price_ex'] ?? $drink['price'] ?? 0));
            $qty = max(0, (int)($drink['qty'] ?? 1));
            $amount = $price * $qty;
          }
          if ($amount <= 0) continue;

          $castUserId = points_kpi_resolve_drink_cast_user_id($drink, $set, $castNoMap);
          if ($castUserId <= 0 || !isset($rows[$castUserId])) continue;

          $costAmount = points_kpi_resolve_drink_cost_total($drink);
          $rows[$castUserId]['drink_total'] += $amount;
          $rows[$castUserId]['drink_cost_total'] += $costAmount;
          $rows[$castUserId]['tickets'][$ticketId . ':' . $setIndex] = true;
          $stats['drink_total'] += $amount;

          $meta = is_array($drink['meta'] ?? null) ? $drink['meta'] : null;
          $isBottle = points_kpi_is_bottle_meta($meta);
          if ($isBottle) {
            $label = points_kpi_meta_label($meta, true);
            $rows[$castUserId]['bottle_total'] += $amount;
            $rows[$castUserId]['bottle_cost_total'] += $costAmount;
            $rows[$castUserId]['bottle_breakdown'][$label] = ($rows[$castUserId]['bottle_breakdown'][$label] ?? 0) + $amount;
            $stats['bottle_total'] += $amount;
          }
        }
      }

      points_kpi_apply_top_level_shimei($rows, $stats, $payload, $castNoMap);
    }

    foreach ($rows as &$row) {
      ksort($row['bottle_breakdown']);
      usort($row['point_events'], static function (array $a, array $b): int {
        $ticketCmp = ((int)$a['ticket_id']) <=> ((int)$b['ticket_id']);
        if ($ticketCmp !== 0) return $ticketCmp;
        return ((int)$a['set_no']) <=> ((int)$b['set_no']);
      });
      $row['ticket_count'] = count($row['tickets']);
      unset($row['tickets']);
    }
    unset($row);

    uasort($rows, static function (array $a, array $b): int {
      $aTag = trim((string)($a['shop_tag'] ?? ''));
      $bTag = trim((string)($b['shop_tag'] ?? ''));
      $aNum = $aTag !== '' ? (int)preg_replace('/\D+/', '', $aTag) : 999999;
      $bNum = $bTag !== '' ? (int)preg_replace('/\D+/', '', $bTag) : 999999;
      if ($aNum !== $bNum) return $aNum <=> $bNum;

      $nameCmp = strcmp((string)$a['display_name'], (string)$b['display_name']);
      if ($nameCmp !== 0) return $nameCmp;

      return (int)$a['user_id'] <=> (int)$b['user_id'];
    });

    return [
      'rows' => array_values($rows),
      'stats' => $stats,
    ];
  }
}

if (!function_exists('points_kpi_range_summary')) {
  function points_kpi_range_summary(PDO $pdo, int $storeId, string $fromDate, string $toDate, array $casts): array {
    $rows = points_kpi_bootstrap_rows($casts);

    $castNoMap = [];
    foreach ($casts as $cast) {
      $shopTag = trim((string)($cast['shop_tag'] ?? ''));
      $userId = (int)($cast['user_id'] ?? 0);
      if ($shopTag !== '' && $userId > 0) {
        $castNoMap[$shopTag] = $userId;
      }
    }

    $st = $pdo->prepare("
      SELECT id, status, totals_snapshot
      FROM tickets
      WHERE store_id = ?
        AND business_date BETWEEN ? AND ?
        AND COALESCE(status, '') <> 'void'
      ORDER BY business_date ASC, id ASC
    ");
    $st->execute([$storeId, $fromDate, $toDate]);
    $tickets = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $stats = [
      'ticket_count' => 0,
      'source_ticket_count' => 0,
      'shimei_total' => 0.0,
      'douhan_total' => 0.0,
      'drink_total' => 0,
      'bottle_total' => 0,
    ];

    foreach ($tickets as $ticket) {
      $stats['ticket_count']++;

      $snapshotRaw = (string)($ticket['totals_snapshot'] ?? '');
      if ($snapshotRaw === '') continue;

      $snapshot = json_decode($snapshotRaw, true);
      if (!is_array($snapshot)) continue;

      $payload = $snapshot['payload'] ?? null;
      if (!is_array($payload)) continue;

      $stats['source_ticket_count']++;
      $ticketId = (int)($ticket['id'] ?? 0);

      $sets = $payload['sets'] ?? [];
      if (!is_array($sets)) $sets = [];

      foreach ($sets as $setIndex => $set) {
        if (!is_array($set)) continue;

        $shMap = points_kpi_extract_shimei_map_from_set($set, $castNoMap);
        $setKind = points_kpi_normalize_set_kind((string)($set['kind'] ?? 'normal50'));
        $effectiveKind = ($setKind === 'pack_douhan' && count($shMap) === 0) ? 'normal50' : $setKind;
        $pt = points_kpi_calc_points($effectiveKind, $shMap);

        foreach ($pt['shimei'] as $castUserId => $value) {
          if (!isset($rows[$castUserId])) {
            $rows[$castUserId] = [
              'user_id' => (int)$castUserId,
              'display_name' => 'user#' . (int)$castUserId,
              'shop_tag' => '',
              'shimei_point' => 0.0,
              'douhan_count' => 0.0,
              'drink_total' => 0,
              'drink_cost_total' => 0,
              'bottle_total' => 0,
              'bottle_cost_total' => 0,
              'bottle_breakdown' => [],
              'point_events' => [],
              'tickets' => [],
            ];
          }
          $rows[$castUserId]['shimei_point'] += (float)$value;
          $rows[$castUserId]['tickets'][$ticketId . ':' . $setIndex] = true;
          $stats['shimei_total'] += (float)$value;
        }

        foreach ($pt['douhan'] as $castUserId => $value) {
          if (($setIndex + 1) !== 1) continue;
          if (!isset($rows[$castUserId])) continue;
          $rows[$castUserId]['douhan_count'] += (float)$value;
          $rows[$castUserId]['tickets'][$ticketId . ':' . $setIndex] = true;
          $stats['douhan_total'] += (float)$value;
        }

        foreach ($shMap as $castUserId => $nominationType) {
          if (!isset($rows[$castUserId])) continue;

          $shimeiPoint = (float)($pt['shimei'][$castUserId] ?? 0.0);
          $douhanPoint = (($setIndex + 1) === 1) ? (float)($pt['douhan'][$castUserId] ?? 0.0) : 0.0;
          if ($shimeiPoint <= 0.0 && $douhanPoint <= 0.0) continue;

          $rows[$castUserId]['point_events'][] = [
            'source_table' => 'tickets',
            'source_column' => 'totals_snapshot',
            'ticket_id' => $ticketId,
            'set_no' => $setIndex + 1,
            'set_kind' => $effectiveKind,
            'nomination_type' => (string)$nominationType,
            'shimei_point' => $shimeiPoint,
            'douhan_point' => $douhanPoint,
          ];
        }

        $drinks = $set['drinks'] ?? null;
        if (!is_array($drinks)) continue;

        foreach ($drinks as $drink) {
          if (!is_array($drink)) continue;

          $amount = max(0, (int)($drink['amount'] ?? 0));
          if ($amount <= 0) {
            $price = max(0, (int)($drink['price_ex'] ?? $drink['price'] ?? 0));
            $qty = max(0, (int)($drink['qty'] ?? 1));
            $amount = $price * $qty;
          }
          if ($amount <= 0) continue;

          $castUserId = points_kpi_resolve_drink_cast_user_id($drink, $set, $castNoMap);
          if ($castUserId <= 0 || !isset($rows[$castUserId])) continue;

          $costAmount = points_kpi_resolve_drink_cost_total($drink);
          $rows[$castUserId]['drink_total'] += $amount;
          $rows[$castUserId]['drink_cost_total'] += $costAmount;
          $rows[$castUserId]['tickets'][$ticketId . ':' . $setIndex] = true;
          $stats['drink_total'] += $amount;

          $meta = is_array($drink['meta'] ?? null) ? $drink['meta'] : null;
          $isBottle = points_kpi_is_bottle_meta($meta);
          if ($isBottle) {
            $label = points_kpi_meta_label($meta, true);
            $rows[$castUserId]['bottle_total'] += $amount;
            $rows[$castUserId]['bottle_cost_total'] += $costAmount;
            $rows[$castUserId]['bottle_breakdown'][$label] = ($rows[$castUserId]['bottle_breakdown'][$label] ?? 0) + $amount;
            $stats['bottle_total'] += $amount;
          }
        }
      }

      points_kpi_apply_top_level_shimei($rows, $stats, $payload, $castNoMap);
    }

    foreach ($rows as &$row) {
      ksort($row['bottle_breakdown']);
      usort($row['point_events'], static function (array $a, array $b): int {
        $ticketCmp = ((int)$a['ticket_id']) <=> ((int)$b['ticket_id']);
        if ($ticketCmp !== 0) return $ticketCmp;
        return ((int)$a['set_no']) <=> ((int)$b['set_no']);
      });
      $row['ticket_count'] = count($row['tickets']);
      unset($row['tickets']);
    }
    unset($row);

    uasort($rows, static function (array $a, array $b): int {
      $aTag = trim((string)($a['shop_tag'] ?? ''));
      $bTag = trim((string)($b['shop_tag'] ?? ''));
      $aNum = $aTag !== '' ? (int)preg_replace('/\D+/', '', $aTag) : 999999;
      $bNum = $bTag !== '' ? (int)preg_replace('/\D+/', '', $bTag) : 999999;
      if ($aNum !== $bNum) return $aNum <=> $bNum;

      $nameCmp = strcmp((string)$a['display_name'], (string)$b['display_name']);
      if ($nameCmp !== 0) return $nameCmp;

      return (int)$a['user_id'] <=> (int)$b['user_id'];
    });

    return [
      'rows' => array_values($rows),
      'stats' => $stats,
    ];
  }
}

if (!function_exists('points_kpi_group_point_events_by_ticket')) {
  function points_kpi_group_point_events_by_ticket(array $events): array {
    $grouped = [];

    foreach ($events as $event) {
      if (!is_array($event)) continue;
      $ticketId = (int)($event['ticket_id'] ?? 0);
      $groupKey = $ticketId > 0 ? ('ticket:' . $ticketId) : ('top:' . md5(json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)));

      if (!isset($grouped[$groupKey])) {
        $grouped[$groupKey] = [
          'ticket_id' => $ticketId,
          'source_table' => (string)($event['source_table'] ?? 'tickets'),
          'source_column' => (string)($event['source_column'] ?? 'totals_snapshot'),
          'shimei_point' => 0.0,
          'douhan_point' => 0.0,
          'details' => [],
        ];
      }

      $grouped[$groupKey]['shimei_point'] += (float)($event['shimei_point'] ?? 0.0);
      $grouped[$groupKey]['douhan_point'] += (float)($event['douhan_point'] ?? 0.0);
      $grouped[$groupKey]['details'][] = [
        'set_no' => (int)($event['set_no'] ?? 1),
        'set_kind' => (string)($event['set_kind'] ?? 'normal50'),
        'nomination_type' => (string)($event['nomination_type'] ?? 'jounai'),
        'shimei_point' => (float)($event['shimei_point'] ?? 0.0),
        'douhan_point' => (float)($event['douhan_point'] ?? 0.0),
      ];
    }

    foreach ($grouped as &$group) {
      usort($group['details'], static function (array $a, array $b): int {
        $setCmp = ((int)$a['set_no']) <=> ((int)$b['set_no']);
        if ($setCmp !== 0) return $setCmp;
        return strcmp((string)$a['nomination_type'], (string)$b['nomination_type']);
      });
    }
    unset($group);

    usort($grouped, static function (array $a, array $b): int {
      $aTicket = (int)($a['ticket_id'] ?? 0);
      $bTicket = (int)($b['ticket_id'] ?? 0);
      if ($aTicket === 0 && $bTicket === 0) return 0;
      if ($aTicket === 0) return 1;
      if ($bTicket === 0) return -1;
      return $aTicket <=> $bTicket;
    });

    return array_values($grouped);
  }
}

if (!function_exists('points_kpi_bottle_rows')) {
  function points_kpi_bottle_rows(PDO $pdo, int $storeId, string $businessDate, array $casts): array {
    $report = points_kpi_day_summary($pdo, $storeId, $businessDate, $casts);
    $rows = [];

    foreach (($report['rows'] ?? []) as $row) {
      $breakdown = $row['bottle_breakdown'] ?? [];
      if (!is_array($breakdown) || $breakdown === []) continue;

      $items = [];
      foreach ($breakdown as $label => $amount) {
        $items[] = [
          'label' => (string)$label,
          'amount' => (int)$amount,
        ];
      }

      $rows[] = [
        'user_id' => (int)($row['user_id'] ?? 0),
        'display_name' => (string)($row['display_name'] ?? ''),
        'shop_tag' => (string)($row['shop_tag'] ?? ''),
        'bottle_total' => (int)($row['bottle_total'] ?? 0),
        'items' => $items,
      ];
    }

    return $rows;
  }
}
