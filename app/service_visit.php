<?php
declare(strict_types=1);

function wbss_table_exists(PDO $pdo, string $table): bool {
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

function wbss_visit_tables_ready(PDO $pdo): bool {
  return wbss_table_exists($pdo, 'visits') && wbss_table_exists($pdo, 'visit_ticket_links');
}

function wbss_event_entry_table_ready(PDO $pdo): bool {
  return wbss_table_exists($pdo, 'event_entries');
}

function wbss_create_visit(PDO $pdo, array $attrs): int {
  $st = $pdo->prepare("
    INSERT INTO visits (
      store_id,
      business_date,
      customer_id,
      store_event_instance_id,
      entry_id,
      primary_ticket_id,
      visit_status,
      visit_type,
      arrived_at,
      left_at,
      guest_count,
      charge_people_snapshot,
      first_free_stage,
      created_by_user_id,
      created_at,
      updated_at
    ) VALUES (
      :store_id,
      :business_date,
      :customer_id,
      :store_event_instance_id,
      :entry_id,
      :primary_ticket_id,
      :visit_status,
      :visit_type,
      :arrived_at,
      :left_at,
      :guest_count,
      :charge_people_snapshot,
      :first_free_stage,
      :created_by_user_id,
      :created_at,
      :updated_at
    )
  ");

  $st->execute([
    ':store_id' => (int)$attrs['store_id'],
    ':business_date' => (string)$attrs['business_date'],
    ':customer_id' => isset($attrs['customer_id']) && (int)$attrs['customer_id'] > 0 ? (int)$attrs['customer_id'] : null,
    ':store_event_instance_id' => isset($attrs['store_event_instance_id']) && (int)$attrs['store_event_instance_id'] > 0 ? (int)$attrs['store_event_instance_id'] : null,
    ':entry_id' => isset($attrs['entry_id']) && (int)$attrs['entry_id'] > 0 ? (int)$attrs['entry_id'] : null,
    ':primary_ticket_id' => isset($attrs['primary_ticket_id']) && (int)$attrs['primary_ticket_id'] > 0 ? (int)$attrs['primary_ticket_id'] : null,
    ':visit_status' => (string)($attrs['visit_status'] ?? 'arrived'),
    ':visit_type' => (string)($attrs['visit_type'] ?? 'unknown'),
    ':arrived_at' => (string)$attrs['arrived_at'],
    ':left_at' => $attrs['left_at'] ?? null,
    ':guest_count' => max(1, (int)($attrs['guest_count'] ?? 1)),
    ':charge_people_snapshot' => max(0, (int)($attrs['charge_people_snapshot'] ?? 0)),
    ':first_free_stage' => (string)($attrs['first_free_stage'] ?? 'first'),
    ':created_by_user_id' => isset($attrs['created_by_user_id']) && (int)$attrs['created_by_user_id'] > 0 ? (int)$attrs['created_by_user_id'] : null,
    ':created_at' => (string)$attrs['created_at'],
    ':updated_at' => (string)$attrs['updated_at'],
  ]);

  return (int)$pdo->lastInsertId();
}

function wbss_link_visit_ticket(PDO $pdo, array $attrs): void {
  $st = $pdo->prepare("
    INSERT INTO visit_ticket_links (
      store_id,
      visit_id,
      ticket_id,
      customer_id,
      link_type,
      payer_group,
      allocated_sales_yen,
      created_at,
      updated_at
    ) VALUES (
      :store_id,
      :visit_id,
      :ticket_id,
      :customer_id,
      :link_type,
      :payer_group,
      :allocated_sales_yen,
      :created_at,
      :updated_at
    )
    ON DUPLICATE KEY UPDATE
      customer_id = VALUES(customer_id),
      link_type = VALUES(link_type),
      payer_group = VALUES(payer_group),
      allocated_sales_yen = VALUES(allocated_sales_yen),
      updated_at = VALUES(updated_at)
  ");

  $st->execute([
    ':store_id' => (int)$attrs['store_id'],
    ':visit_id' => (int)$attrs['visit_id'],
    ':ticket_id' => (int)$attrs['ticket_id'],
    ':customer_id' => isset($attrs['customer_id']) && (int)$attrs['customer_id'] > 0 ? (int)$attrs['customer_id'] : null,
    ':link_type' => (string)($attrs['link_type'] ?? 'primary'),
    ':payer_group' => isset($attrs['payer_group']) && $attrs['payer_group'] !== '' ? (string)$attrs['payer_group'] : null,
    ':allocated_sales_yen' => isset($attrs['allocated_sales_yen']) ? (int)$attrs['allocated_sales_yen'] : null,
    ':created_at' => (string)$attrs['created_at'],
    ':updated_at' => (string)$attrs['updated_at'],
  ]);
}

function wbss_update_visit_entry_id(PDO $pdo, int $visitId, ?int $entryId, string $updatedAt): void {
  if ($visitId <= 0) {
    return;
  }

  $st = $pdo->prepare("
    UPDATE visits
    SET entry_id = :entry_id,
        updated_at = :updated_at
    WHERE id = :visit_id
    LIMIT 1
  ");
  $st->execute([
    ':entry_id' => ($entryId !== null && $entryId > 0) ? $entryId : null,
    ':updated_at' => $updatedAt,
    ':visit_id' => $visitId,
  ]);
}

function wbss_attach_event_entry_to_visit(PDO $pdo, array $attrs): ?int {
  if (!wbss_event_entry_table_ready($pdo)) {
    return null;
  }

  $storeId = (int)($attrs['store_id'] ?? 0);
  $eventInstanceId = (int)($attrs['store_event_instance_id'] ?? 0);
  $visitId = (int)($attrs['visit_id'] ?? 0);
  $customerId = (int)($attrs['customer_id'] ?? 0);
  $actorId = (int)($attrs['created_by_user_id'] ?? 0);
  $arrivedAt = (string)($attrs['arrived_at'] ?? '');
  $updatedAt = (string)($attrs['updated_at'] ?? $arrivedAt);
  $entryType = (string)($attrs['entry_type'] ?? 'event');

  if ($storeId <= 0 || $eventInstanceId <= 0 || $visitId <= 0 || $arrivedAt === '') {
    return null;
  }

  $entryId = null;

  if ($customerId > 0) {
    $st = $pdo->prepare("
      SELECT id
      FROM event_entries
      WHERE store_id = :store_id
        AND store_event_instance_id = :event_id
        AND customer_id = :customer_id
        AND visit_id IS NULL
      ORDER BY COALESCE(reserved_at, created_at) DESC, id DESC
      LIMIT 1
      FOR UPDATE
    ");
    $st->execute([
      ':store_id' => $storeId,
      ':event_id' => $eventInstanceId,
      ':customer_id' => $customerId,
    ]);
    $entryId = (int)($st->fetchColumn() ?: 0);
  }

  if ($entryId > 0) {
    $st = $pdo->prepare("
      UPDATE event_entries
      SET visit_id = :visit_id,
          status = 'arrived',
          arrived_at = COALESCE(arrived_at, :arrived_at),
          updated_at = :updated_at
      WHERE id = :id
      LIMIT 1
    ");
    $st->execute([
      ':visit_id' => $visitId,
      ':arrived_at' => $arrivedAt,
      ':updated_at' => $updatedAt,
      ':id' => $entryId,
    ]);
  } else {
    $st = $pdo->prepare("
      INSERT INTO event_entries (
        store_id,
        customer_id,
        store_event_instance_id,
        visit_id,
        entry_type,
        status,
        source_detail,
        reserved_at,
        arrived_at,
        note,
        created_by_user_id,
        created_at,
        updated_at
      ) VALUES (
        :store_id,
        :customer_id,
        :event_id,
        :visit_id,
        :entry_type,
        'arrived',
        'auto_from_cashier',
        NULL,
        :arrived_at,
        'cashier auto linked',
        :created_by_user_id,
        :created_at,
        :updated_at
      )
    ");
    $st->execute([
      ':store_id' => $storeId,
      ':customer_id' => $customerId > 0 ? $customerId : null,
      ':event_id' => $eventInstanceId,
      ':visit_id' => $visitId,
      ':entry_type' => $entryType,
      ':arrived_at' => $arrivedAt,
      ':created_by_user_id' => $actorId > 0 ? $actorId : null,
      ':created_at' => $arrivedAt,
      ':updated_at' => $updatedAt,
    ]);
    $entryId = (int)$pdo->lastInsertId();
  }

  if ($entryId > 0) {
    wbss_update_visit_entry_id($pdo, $visitId, $entryId, $updatedAt);
    return $entryId;
  }

  return null;
}

function wbss_fetch_ticket_visit_summary(PDO $pdo, int $ticketId): ?array {
  if ($ticketId <= 0 || !wbss_visit_tables_ready($pdo)) {
    return null;
  }

  $st = $pdo->prepare("
    SELECT
      v.id AS visit_id,
      v.customer_id,
      v.store_event_instance_id,
      v.visit_status,
      v.visit_type,
      v.guest_count,
      v.arrived_at,
      v.left_at
    FROM visit_ticket_links l
    INNER JOIN visits v
      ON v.id = l.visit_id
     AND v.store_id = l.store_id
    WHERE l.ticket_id = ?
    ORDER BY l.id ASC
    LIMIT 1
  ");
  $st->execute([$ticketId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function wbss_find_visit_id_by_ticket(PDO $pdo, int $ticketId): ?int {
  $summary = wbss_fetch_ticket_visit_summary($pdo, $ticketId);
  if (!is_array($summary)) {
    return null;
  }

  $visitId = (int)($summary['visit_id'] ?? 0);
  return $visitId > 0 ? $visitId : null;
}

function wbss_normalize_set_kind(string $kind): string {
  $kind = trim($kind);
  return match ($kind) {
    'full50', 'normal50' => 'normal50',
    'half25' => 'half25',
    'pack_douhan' => 'pack_douhan',
    default => 'normal50',
  };
}

function wbss_shimei_fee_per_unit(string $setKind): int {
  return match (wbss_normalize_set_kind($setKind)) {
    'half25' => 500,
    'pack_douhan' => 0,
    default => 1000,
  };
}

function wbss_normalize_event_started_at(string $rawStartedAt, string $fallbackStartedAt): string {
  $rawStartedAt = trim($rawStartedAt);
  $fallbackStartedAt = trim($fallbackStartedAt);

  if ($fallbackStartedAt === '') {
    $fallbackStartedAt = date('Y-m-d H:i:s');
  }

  $fallbackDate = preg_match('/^\d{4}-\d{2}-\d{2}/', $fallbackStartedAt)
    ? substr($fallbackStartedAt, 0, 10)
    : date('Y-m-d');

  if ($rawStartedAt === '') {
    return $fallbackStartedAt;
  }

  if (preg_match('/^\d{1,2}:\d{2}$/', $rawStartedAt)) {
    return $fallbackDate . ' ' . $rawStartedAt . ':00';
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}$/', $rawStartedAt)) {
    return str_replace('T', ' ', $rawStartedAt) . ':00';
  }

  if (preg_match('/^\d{4}-\d{2}-\d{2}[ T]\d{1,2}:\d{2}:\d{2}$/', $rawStartedAt)) {
    return str_replace('T', ' ', $rawStartedAt);
  }

  return $fallbackStartedAt;
}

function wbss_extract_nomination_events_from_payload(array $payload, string $fallbackStartedAt): array {
  $events = [];
  $sets = $payload['sets'] ?? [];
  if (!is_array($sets)) $sets = [];

  $payloadStart = (string)($payload['start_time'] ?? '');
  if ($payloadStart === '') {
    $payloadStart = substr($fallbackStartedAt, 11, 5);
  }

  foreach ($sets as $idx => $set) {
    if (!is_array($set)) continue;

    $setNo = $idx + 1;
    $setKind = wbss_normalize_set_kind((string)($set['kind'] ?? 'normal50'));
    $startedAt = wbss_normalize_event_started_at((string)($set['started_at'] ?? ''), $fallbackStartedAt);
    if ($startedAt === $fallbackStartedAt) {
      $startedAt = wbss_normalize_event_started_at((string)$payloadStart, $fallbackStartedAt);
    }

    $byCast = [];
    $customers = $set['customers'] ?? null;
    if (is_array($customers)) {
      foreach ($customers as $custNo => $cust) {
        if (!is_array($cust)) continue;
        if ((string)($cust['mode'] ?? '') !== 'shimei') continue;
        $shimeiMap = $cust['shimei'] ?? null;
        if (!is_array($shimeiMap)) continue;
        foreach ($shimeiMap as $castId => $kind) {
          $castUserId = (int)$castId;
          if ($castUserId <= 0) continue;
          $nominationType = ((string)$kind === 'hon') ? 'hon' : 'jounai';
          if (!isset($byCast[$castUserId])) {
            $byCast[$castUserId] = [
              'cast_user_id' => $castUserId,
              'nomination_type' => $nominationType,
              'count_unit' => 0.0,
            ];
          }
          if ($nominationType === 'hon') {
            $byCast[$castUserId]['nomination_type'] = 'hon';
          }
          $byCast[$castUserId]['count_unit'] += 1.0;
        }
      }
    }

    foreach ($byCast as $row) {
      $fee = wbss_shimei_fee_per_unit($setKind);
      $events[] = [
        'set_no' => $setNo,
        'cast_user_id' => (int)$row['cast_user_id'],
        'nomination_type' => (string)$row['nomination_type'],
        'count_unit' => (float)$row['count_unit'],
        'fee_ex_tax' => $fee > 0 ? (int)round($fee * (float)$row['count_unit']) : 0,
        'started_at' => $startedAt,
      ];
    }
  }

  $topLevel = $payload['shimei'] ?? null;
  if (is_array($topLevel) && $topLevel !== []) {
    foreach ($topLevel as $row) {
      if (!is_array($row)) continue;
      $castUserId = (int)($row['cast_user_id'] ?? 0);
      if ($castUserId <= 0) continue;
      $setKind = wbss_normalize_set_kind((string)($row['set_kind'] ?? 'normal50'));
      $people = max(1, (int)($row['people'] ?? 1));
      $nominationType = ((string)($row['shimei_type'] ?? 'normal') === 'jounai') ? 'jounai' : 'hon';
      $fee = wbss_shimei_fee_per_unit($setKind);
      $events[] = [
        'set_no' => 1,
        'cast_user_id' => $castUserId,
        'nomination_type' => $nominationType,
        'count_unit' => (float)$people,
        'fee_ex_tax' => $fee > 0 ? $fee * $people : 0,
        'started_at' => $fallbackStartedAt,
      ];
    }
  }

  return $events;
}

function wbss_replace_visit_nomination_events(PDO $pdo, array $attrs): void {
  if (!wbss_table_exists($pdo, 'visit_nomination_events')) {
    return;
  }

  $visitId = (int)($attrs['visit_id'] ?? 0);
  $storeId = (int)($attrs['store_id'] ?? 0);
  if ($visitId <= 0 || $storeId <= 0) {
    return;
  }

  $customerId = isset($attrs['customer_id']) && (int)$attrs['customer_id'] > 0 ? (int)$attrs['customer_id'] : null;
  $createdAt = (string)($attrs['created_at'] ?? date('Y-m-d H:i:s'));
  $updatedAt = (string)($attrs['updated_at'] ?? $createdAt);
  $events = $attrs['events'] ?? [];
  if (!is_array($events)) $events = [];

  $del = $pdo->prepare("DELETE FROM visit_nomination_events WHERE store_id = ? AND visit_id = ?");
  $del->execute([$storeId, $visitId]);

  if ($events === []) {
    return;
  }

  $ins = $pdo->prepare("
    INSERT INTO visit_nomination_events (
      store_id,
      visit_id,
      customer_id,
      cast_user_id,
      nomination_type,
      set_no,
      fee_ex_tax,
      cast_back_yen,
      count_unit,
      started_at,
      ended_at,
      created_at,
      updated_at
    ) VALUES (
      :store_id,
      :visit_id,
      :customer_id,
      :cast_user_id,
      :nomination_type,
      :set_no,
      :fee_ex_tax,
      :cast_back_yen,
      :count_unit,
      :started_at,
      NULL,
      :created_at,
      :updated_at
    )
  ");

  foreach ($events as $event) {
    if (!is_array($event)) continue;
    $castUserId = (int)($event['cast_user_id'] ?? 0);
    if ($castUserId <= 0) continue;
    $ins->execute([
      ':store_id' => $storeId,
      ':visit_id' => $visitId,
      ':customer_id' => $customerId,
      ':cast_user_id' => $castUserId,
      ':nomination_type' => (string)($event['nomination_type'] ?? 'hon'),
      ':set_no' => max(1, (int)($event['set_no'] ?? 1)),
      ':fee_ex_tax' => max(0, (int)($event['fee_ex_tax'] ?? 0)),
      ':cast_back_yen' => max(0, (int)($event['cast_back_yen'] ?? 0)),
      ':count_unit' => max(0.5, (float)($event['count_unit'] ?? 1)),
      ':started_at' => wbss_normalize_event_started_at((string)($event['started_at'] ?? ''), $createdAt),
      ':created_at' => $createdAt,
      ':updated_at' => $updatedAt,
    ]);
  }
}

if (!function_exists('seika_table_exists')) {
  function seika_table_exists(PDO $pdo, string $table): bool {
    return wbss_table_exists($pdo, $table);
  }
}

if (!function_exists('seika_visit_tables_ready')) {
  function seika_visit_tables_ready(PDO $pdo): bool {
    return wbss_visit_tables_ready($pdo);
  }
}

if (!function_exists('seika_event_entry_table_ready')) {
  function seika_event_entry_table_ready(PDO $pdo): bool {
    return wbss_event_entry_table_ready($pdo);
  }
}

if (!function_exists('seika_create_visit')) {
  function seika_create_visit(PDO $pdo, array $attrs): int {
    return wbss_create_visit($pdo, $attrs);
  }
}

if (!function_exists('seika_link_visit_ticket')) {
  function seika_link_visit_ticket(PDO $pdo, array $attrs): void {
    wbss_link_visit_ticket($pdo, $attrs);
  }
}

if (!function_exists('seika_update_visit_entry_id')) {
  function seika_update_visit_entry_id(PDO $pdo, int $visitId, ?int $entryId, string $updatedAt): void {
    wbss_update_visit_entry_id($pdo, $visitId, $entryId, $updatedAt);
  }
}

if (!function_exists('seika_attach_event_entry_to_visit')) {
  function seika_attach_event_entry_to_visit(PDO $pdo, array $attrs): ?int {
    return wbss_attach_event_entry_to_visit($pdo, $attrs);
  }
}

if (!function_exists('seika_fetch_ticket_visit_summary')) {
  function seika_fetch_ticket_visit_summary(PDO $pdo, int $ticketId): ?array {
    return wbss_fetch_ticket_visit_summary($pdo, $ticketId);
  }
}

if (!function_exists('seika_find_visit_id_by_ticket')) {
  function seika_find_visit_id_by_ticket(PDO $pdo, int $ticketId): ?int {
    return wbss_find_visit_id_by_ticket($pdo, $ticketId);
  }
}

if (!function_exists('seika_normalize_set_kind')) {
  function seika_normalize_set_kind(string $kind): string {
    return wbss_normalize_set_kind($kind);
  }
}

if (!function_exists('seika_shimei_fee_per_unit')) {
  function seika_shimei_fee_per_unit(string $setKind): int {
    return wbss_shimei_fee_per_unit($setKind);
  }
}

if (!function_exists('seika_normalize_event_started_at')) {
  function seika_normalize_event_started_at(string $rawStartedAt, string $fallbackStartedAt): string {
    return wbss_normalize_event_started_at($rawStartedAt, $fallbackStartedAt);
  }
}

if (!function_exists('seika_extract_nomination_events_from_payload')) {
  function seika_extract_nomination_events_from_payload(array $payload, string $fallbackStartedAt): array {
    return wbss_extract_nomination_events_from_payload($payload, $fallbackStartedAt);
  }
}

if (!function_exists('seika_replace_visit_nomination_events')) {
  function seika_replace_visit_nomination_events(PDO $pdo, array $attrs): void {
    wbss_replace_visit_nomination_events($pdo, $attrs);
  }
}
