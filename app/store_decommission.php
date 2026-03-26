<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/store_access.php';

function store_decommission_json_encode(array $value): string
{
  return (string)json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}

function store_decommission_table_exists(PDO $pdo, string $table): bool
{
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
  ");
  $st->execute([$table]);
  return ((int)$st->fetchColumn() > 0);
}

function store_decommission_column_exists(PDO $pdo, string $table, string $column): bool
{
  $st = $pdo->prepare("
    SELECT COUNT(*)
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
  ");
  $st->execute([$table, $column]);
  return ((int)$st->fetchColumn() > 0);
}

function store_decommission_now(): string
{
  return (new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
}

function store_decommission_allowed_role_codes(): array
{
  return ['super_user', 'admin', 'manager', 'owner', 'system_admin', 'hq_admin'];
}

function store_decommission_user_can_manage(): bool
{
  foreach (store_decommission_allowed_role_codes() as $roleCode) {
    if (is_role($roleCode)) {
      return true;
    }
  }
  return false;
}

function store_decommission_require_manage_role(): void
{
  require_login();
  if (!store_decommission_user_can_manage()) {
    http_response_code(403);
    exit('Forbidden');
  }
}

function store_decommission_manageable_stores(PDO $pdo): array
{
  return store_access_allowed_stores($pdo);
}

function store_decommission_resolve_store_id(PDO $pdo, ?int $requestedStoreId = null): int
{
  return store_access_resolve_manageable_store_id($pdo, $requestedStoreId);
}

function store_decommission_fetch_store(PDO $pdo, int $storeId): array
{
  $hasCode = store_decommission_column_exists($pdo, 'stores', 'code');
  $hasIsActive = store_decommission_column_exists($pdo, 'stores', 'is_active');
  $hasStatus = store_decommission_column_exists($pdo, 'stores', 'status');
  $hasLifecycle = store_decommission_column_exists($pdo, 'stores', 'lifecycle_status');
  $hasLastLogin = store_decommission_column_exists($pdo, 'stores', 'last_login_at');
  $hasUpdatedAt = store_decommission_column_exists($pdo, 'stores', 'updated_at');
  $hasRequestedAt = store_decommission_column_exists($pdo, 'stores', 'decommission_requested_at');
  $hasApprovedAt = store_decommission_column_exists($pdo, 'stores', 'decommission_approved_at');
  $hasScheduledAt = store_decommission_column_exists($pdo, 'stores', 'decommission_scheduled_at');
  $hasCompletedAt = store_decommission_column_exists($pdo, 'stores', 'decommission_completed_at');

  $sql = "
    SELECT
      id,
      " . ($hasCode ? "code" : "'' AS code") . ",
      name,
      " . ($hasIsActive ? "is_active" : "1 AS is_active") . ",
      " . ($hasStatus ? "status" : "'' AS status") . ",
      " . ($hasLifecycle ? "lifecycle_status" : "'active' AS lifecycle_status") . ",
      " . ($hasLastLogin ? "last_login_at" : "NULL AS last_login_at") . ",
      " . ($hasUpdatedAt ? "updated_at" : "NULL AS updated_at") . ",
      " . ($hasRequestedAt ? "decommission_requested_at" : "NULL AS decommission_requested_at") . ",
      " . ($hasApprovedAt ? "decommission_approved_at" : "NULL AS decommission_approved_at") . ",
      " . ($hasScheduledAt ? "decommission_scheduled_at" : "NULL AS decommission_scheduled_at") . ",
      " . ($hasCompletedAt ? "decommission_completed_at" : "NULL AS decommission_completed_at") . "
    FROM stores
    WHERE id = ?
    LIMIT 1
  ";

  $st = $pdo->prepare($sql);
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  if (!is_array($row)) {
    throw new RuntimeException('店舗が見つかりません');
  }
  return $row;
}

function store_decommission_verify_password(PDO $pdo, int $userId, string $password): bool
{
  $password = (string)$password;
  if ($userId <= 0 || $password === '') {
    return false;
  }

  $st = $pdo->prepare("SELECT password_hash FROM users WHERE id=? LIMIT 1");
  $st->execute([$userId]);
  $hash = (string)($st->fetchColumn() ?: '');
  if ($hash === '') {
    return false;
  }

  if (str_starts_with($hash, 'sha2:')) {
    return hash_equals(substr($hash, 5), hash('sha256', $password));
  }

  return password_verify($password, $hash);
}

function store_decommission_expected_confirm_text(string $action, int $storeId): string
{
  $action = strtolower(trim($action));
  return match ($action) {
    'suspend' => 'SUSPEND STORE ' . $storeId,
    'request' => 'DELETE STORE ' . $storeId,
    'approve' => 'APPROVE STORE ' . $storeId,
    'cancel' => 'CANCEL STORE ' . $storeId,
    default => 'STORE ' . $storeId,
  };
}

function store_decommission_assert_password_and_confirm(
  PDO $pdo,
  int $userId,
  int $storeId,
  string $password,
  string $confirmText,
  string $action
): void {
  if (!store_decommission_verify_password($pdo, $userId, $password)) {
    throw new RuntimeException('パスワード再入力が一致しません');
  }

  $expected = store_decommission_expected_confirm_text($action, $storeId);
  if (trim($confirmText) !== $expected) {
    throw new RuntimeException('確認文字列が一致しません');
  }
}

function store_decommission_log_step(
  PDO $pdo,
  int $jobId,
  int $storeId,
  string $stepKey,
  string $stepLabel,
  string $status,
  ?string $message = null,
  array $context = [],
  ?int $createdBy = null
): void {
  if (!store_decommission_table_exists($pdo, 'store_decommission_logs')) {
    return;
  }

  $st = $pdo->prepare("
    INSERT INTO store_decommission_logs (
      job_id,
      store_id,
      step_key,
      step_label,
      status,
      message,
      context_json,
      created_by,
      created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  $st->execute([
    $jobId,
    $storeId,
    $stepKey,
    $stepLabel,
    $status,
    $message,
    $context ? store_decommission_json_encode($context) : null,
    $createdBy,
  ]);
}

function store_decommission_table_kind(PDO $pdo, string $table): ?string
{
  $st = $pdo->prepare("
    SELECT TABLE_TYPE
    FROM information_schema.TABLES
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table]);
  $kind = $st->fetchColumn();
  return is_string($kind) ? $kind : null;
}

function store_decommission_delete_allowlist(): array
{
  return [
    // 会計/注文: 店舗業務の中核データのみ
    'order_item_cast_assignments',
    'ticket_items',
    'ticket_seat_moves',
    'tickets',
    'visit_ticket_links',
    'visits',
    'visit_nomination_events',

    // 顧客: 正本と補助ログに限定
    'customer_notes',
    'customers',

    // 勤怠/シフト: 正本と申請/計画ログに限定
    'attendance_audits',
    'attendances',
    'cast_shift_logs',
    'cast_shift_plans',
    'cast_shift_requests',
    'cast_week_plans',

    // 最小限の連携補助
    'line_geo_pending',

    // 店舗イベント/監査補助
    'store_event_audit_logs',

    // 応募/面接
    'applicants',
    'interviews',
    'wbss_applicant_interviews',
    'wbss_applicant_photos',
    'wbss_applicant_status_logs',
    'wbss_applicant_store_assignments',
  ];
}

function store_decommission_delete_excluded(): array
{
  return [
    // 親/監査/ジョブ
    'stores' => 'parent store row is retained for audit visibility',
    'store_decommission_batches' => 'decommission control tables must remain',
    'store_decommission_jobs' => 'decommission control tables must remain',
    'store_decommission_logs' => 'decommission control tables must remain',
    'store_decommission_snapshots' => 'decommission control tables must remain',
    'audit_impersonations' => 'global audit data should not be physically deleted in v0.1',
    'audit_logs' => 'global audit data should not be physically deleted in v0.1',

    // 共有ユーザー情報
    'store_users' => 'shared user membership needs store-specific rules before deletion',
    'user_roles' => 'shared role assignments need store-specific rules before deletion',
    'cast_profiles' => 'cast profiles may span stores and need dedicated cleanup rules',
    'cast_points' => 'points data needs business decision before deletion',
    'cast_points_deleted' => 'points data needs business decision before deletion',
    'cast_transport_profiles' => 'cast transport profiles may be shared across stores',
    'drivers' => 'driver master may be shared across stores',

    // ビュー/派生
    'v_store_casts_active' => 'views are never physical delete targets',
  ];
}

function store_decommission_build_delete_plan(PDO $pdo): array
{
  $plan = [];
  foreach (store_decommission_delete_allowlist() as $table) {
    if (!store_decommission_table_exists($pdo, $table)) {
      continue;
    }
    if (!store_decommission_column_exists($pdo, $table, 'store_id')) {
      continue;
    }
    if (store_decommission_table_kind($pdo, $table) !== 'BASE TABLE') {
      continue;
    }
    $plan[] = ['table' => $table, 'mode' => 'delete_by_store_id'];
  }
  return $plan;
}

function store_decommission_ignored_tables(PDO $pdo): array
{
  $ignored = [];
  foreach (store_decommission_delete_excluded() as $table => $reason) {
    if (!store_decommission_table_exists($pdo, $table)) {
      continue;
    }
    $ignored[] = [
      'table' => $table,
      'reason' => $reason,
      'kind' => store_decommission_table_kind($pdo, $table),
    ];
  }
  return $ignored;
}

function store_decommission_preview_definitions(): array
{
  return [
    'customers' => [
      ['table' => 'customers', 'aggregate' => 'COUNT(*)'],
    ],
    'tickets' => [
      ['table' => 'tickets', 'aggregate' => 'COUNT(*)'],
    ],
    'orders' => [
      ['table' => 'orders', 'aggregate' => 'COUNT(*)'],
    ],
    'attendances' => [
      ['table' => 'attendances', 'aggregate' => 'COUNT(*)'],
    ],
    'nominations' => [
      ['table' => 'visit_nomination_events', 'aggregate' => 'COUNT(*)'],
    ],
    'interviews' => [
      ['table' => 'wbss_applicant_interviews', 'aggregate' => 'COUNT(*)'],
      ['table' => 'interviews', 'aggregate' => 'COUNT(*)'],
    ],
    'attachments' => [
      ['table' => 'wbss_applicant_photos', 'aggregate' => 'COUNT(*)'],
    ],
    'attachments_bytes' => [
      ['table' => 'wbss_applicant_photos', 'aggregate' => 'COALESCE(SUM(file_size),0)'],
    ],
  ];
}

function store_decommission_preview(PDO $pdo, int $storeId): array
{
  $summary = [
    'customers' => 0,
    'tickets' => 0,
    'orders' => 0,
    'attendances' => 0,
    'nominations' => 0,
    'interviews' => 0,
    'attachments' => 0,
    'attachments_bytes' => 0,
  ];

  foreach (store_decommission_preview_definitions() as $key => $candidates) {
    foreach ($candidates as $candidate) {
      $table = (string)$candidate['table'];
      if (!store_decommission_table_exists($pdo, $table) || !store_decommission_column_exists($pdo, $table, 'store_id')) {
        continue;
      }

      $sql = "SELECT " . $candidate['aggregate'] . " AS value FROM `{$table}` WHERE store_id = ?";
      $st = $pdo->prepare($sql);
      $st->execute([$storeId]);
      $summary[$key] = (int)($st->fetchColumn() ?: 0);
      break;
    }
  }

  return $summary;
}

function store_decommission_create_snapshot(PDO $pdo, int $jobId, int $storeId, array $summary): void
{
  if (!store_decommission_table_exists($pdo, 'store_decommission_snapshots')) {
    return;
  }

  $pdo->prepare("DELETE FROM store_decommission_snapshots WHERE job_id=?")->execute([$jobId]);

  $st = $pdo->prepare("
    INSERT INTO store_decommission_snapshots (
      job_id,
      store_id,
      customers_count,
      tickets_count,
      orders_count,
      attendances_count,
      nominations_count,
      interviews_count,
      attachments_count,
      attachments_bytes,
      snapshot_json,
      created_at
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
  ");
  $st->execute([
    $jobId,
    $storeId,
    (int)($summary['customers'] ?? 0),
    (int)($summary['tickets'] ?? 0),
    (int)($summary['orders'] ?? 0),
    (int)($summary['attendances'] ?? 0),
    (int)($summary['nominations'] ?? 0),
    (int)($summary['interviews'] ?? 0),
    (int)($summary['attachments'] ?? 0),
    (int)($summary['attachments_bytes'] ?? 0),
    store_decommission_json_encode($summary),
  ]);
}

function store_decommission_normalize_store_ids(PDO $pdo, array $storeIds): array
{
  $resolved = [];
  foreach ($storeIds as $storeId) {
    $storeId = (int)$storeId;
    if ($storeId <= 0) {
      continue;
    }
    $resolved[] = store_decommission_resolve_store_id($pdo, $storeId);
  }

  $resolved = array_values(array_unique($resolved));
  sort($resolved, SORT_NUMERIC);
  if (!$resolved) {
    throw new RuntimeException('対象店舗を1件以上選択してください');
  }
  return $resolved;
}

function store_decommission_validate_schedule_at(?string $scheduledAt): ?string
{
  $scheduledAt = trim((string)$scheduledAt);
  if ($scheduledAt === '') {
    return null;
  }
  if (!preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $scheduledAt)) {
    throw new RuntimeException('scheduled_at は YYYY-MM-DD HH:MM:SS 形式で入力してください');
  }
  return $scheduledAt;
}

function store_decommission_fetch_batch(PDO $pdo, int $batchId): ?array
{
  if ($batchId <= 0 || !store_decommission_table_exists($pdo, 'store_decommission_batches')) {
    return null;
  }

  $st = $pdo->prepare("SELECT * FROM store_decommission_batches WHERE id = ? LIMIT 1");
  $st->execute([$batchId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function store_decommission_fetch_batch_jobs(PDO $pdo, int $batchId): array
{
  if ($batchId <= 0 || !store_decommission_table_exists($pdo, 'store_decommission_jobs')) {
    return [];
  }

  $sql = "
    SELECT
      j.*,
      s.name AS store_name
    FROM store_decommission_jobs j
    LEFT JOIN stores s ON s.id = j.store_id
    WHERE j.batch_id = ?
    ORDER BY j.id ASC
  ";
  $st = $pdo->prepare($sql);
  $st->execute([$batchId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function store_decommission_batch_progress_from_jobs(array $batch, array $jobs): array
{
  $counts = [
    'requested' => 0,
    'approved' => 0,
    'scheduled' => 0,
    'running' => 0,
    'completed' => 0,
    'dry_run_completed' => 0,
    'cancelled' => 0,
    'failed' => 0,
  ];
  $storeIds = [];

  foreach ($jobs as $job) {
    $status = (string)($job['status'] ?? '');
    if (!array_key_exists($status, $counts)) {
      $counts[$status] = 0;
    }
    $counts[$status]++;
    $storeIds[(int)($job['store_id'] ?? 0)] = true;
  }

  $totalJobs = count($jobs);
  $terminal = $counts['completed'] + $counts['dry_run_completed'] + $counts['cancelled'] + $counts['failed'];
  $successful = $counts['completed'] + $counts['dry_run_completed'];
  $hasPending = ($counts['requested'] + $counts['approved'] + $counts['scheduled'] + $counts['running']) > 0;
  $status = 'scheduled';

  if ($totalJobs === 0) {
    $status = 'failed';
  } elseif ($counts['running'] > 0) {
    $status = 'running';
  } elseif ($hasPending) {
    $status = (!empty($batch['started_at']) || $successful > 0 || $counts['failed'] > 0) ? 'running' : 'scheduled';
  } elseif ($counts['failed'] === $totalJobs) {
    $status = 'failed';
  } elseif ($counts['dry_run_completed'] === $totalJobs) {
    $status = 'dry_run_completed';
  } elseif ($counts['failed'] > 0 && $successful > 0) {
    $status = 'partial_failed';
  } elseif ($successful === $totalJobs) {
    $status = 'completed';
  } elseif ($terminal === $totalJobs && $counts['cancelled'] === $totalJobs) {
    $status = 'cancelled';
  } else {
    $status = 'partial_failed';
  }

  return [
    'status' => $status,
    'total_jobs' => $totalJobs,
    'store_count' => count($storeIds),
    'completed_jobs' => $counts['completed'],
    'dry_run_completed_jobs' => $counts['dry_run_completed'],
    'failed_jobs' => $counts['failed'],
    'running_jobs' => $counts['running'],
    'scheduled_jobs' => $counts['scheduled'],
    'requested_jobs' => $counts['requested'],
    'approved_jobs' => $counts['approved'],
    'cancelled_jobs' => $counts['cancelled'],
    'terminal_jobs' => $terminal,
    'progress_percent' => $totalJobs > 0 ? (int)floor(($terminal / $totalJobs) * 100) : 0,
    'counts' => $counts,
  ];
}

function store_decommission_batch_progress(PDO $pdo, int $batchId): array
{
  $batch = store_decommission_fetch_batch($pdo, $batchId);
  if (!$batch) {
    throw new RuntimeException('batch が見つかりません');
  }

  $jobs = store_decommission_fetch_batch_jobs($pdo, $batchId);
  $progress = store_decommission_batch_progress_from_jobs($batch, $jobs);

  return [
    'batch' => $batch,
    'jobs' => $jobs,
    'progress' => $progress,
  ];
}

function store_decommission_refresh_batch_status(PDO $pdo, int $batchId): ?array
{
  $payload = store_decommission_batch_progress($pdo, $batchId);
  $batch = $payload['batch'];
  $progress = $payload['progress'];
  $status = (string)$progress['status'];

  $sets = ['status = :status', 'updated_at = NOW()'];
  $params = [
    ':status' => $status,
    ':id' => $batchId,
  ];

  if ($status === 'running' && empty($batch['started_at'])) {
    $sets[] = 'started_at = NOW()';
  }
  if (in_array($status, ['completed', 'dry_run_completed', 'partial_failed', 'failed', 'cancelled'], true)) {
    $sets[] = 'completed_at = NOW()';
  }
  if (in_array($status, ['partial_failed', 'failed'], true) && empty($batch['failure_reason'])) {
    $sets[] = 'failure_reason = :failure_reason';
    $params[':failure_reason'] = 'one or more child jobs failed';
  }

  $sql = "UPDATE store_decommission_batches SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);

  return store_decommission_fetch_batch($pdo, $batchId);
}

function store_decommission_mark_batch_running(PDO $pdo, int $batchId): void
{
  if ($batchId <= 0 || !store_decommission_table_exists($pdo, 'store_decommission_batches')) {
    return;
  }

  $pdo->prepare("
    UPDATE store_decommission_batches
    SET status = 'running',
        started_at = COALESCE(started_at, NOW()),
        updated_at = NOW()
    WHERE id = ?
      AND status = 'scheduled'
    LIMIT 1
  ")->execute([$batchId]);
}

function store_decommission_list_batches(PDO $pdo, int $limit = 20): array
{
  $limit = max(1, min(100, $limit));
  if (!store_decommission_table_exists($pdo, 'store_decommission_batches')) {
    return [];
  }

  $st = $pdo->query("
    SELECT *
    FROM store_decommission_batches
    ORDER BY id DESC
    LIMIT {$limit}
  ");
  $batches = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  foreach ($batches as &$batch) {
    $jobs = store_decommission_fetch_batch_jobs($pdo, (int)($batch['id'] ?? 0));
    $batch['_progress'] = store_decommission_batch_progress_from_jobs($batch, $jobs);
    $batch['_jobs'] = $jobs;
  }
  unset($batch);

  return $batches;
}

function store_decommission_create_batch(
  PDO $pdo,
  array $storeIds,
  int $actorUserId,
  string $password,
  string $reason,
  ?string $scheduledAt,
  bool $dryRun = false
): array {
  if (!store_decommission_table_exists($pdo, 'store_decommission_batches')) {
    throw new RuntimeException('store_decommission_batches テーブルがありません');
  }
  if (!store_decommission_column_exists($pdo, 'store_decommission_jobs', 'batch_id')) {
    throw new RuntimeException('store_decommission_jobs.batch_id カラムがありません');
  }
  if (!store_decommission_verify_password($pdo, $actorUserId, $password)) {
    throw new RuntimeException('パスワード再入力が一致しません');
  }

  $storeIds = store_decommission_normalize_store_ids($pdo, $storeIds);
  $scheduledAt = store_decommission_validate_schedule_at($scheduledAt) ?? store_decommission_now();
  $requestedIp = $_SERVER['REMOTE_ADDR'] ?? null;

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("
      INSERT INTO store_decommission_batches (
        requested_by,
        status,
        reason,
        dry_run,
        scheduled_at,
        requested_ip,
        updated_at
      ) VALUES (?, 'scheduled', ?, ?, ?, ?, NOW())
    ");
    $st->execute([
      $actorUserId,
      $reason !== '' ? $reason : null,
      $dryRun ? 1 : 0,
      $scheduledAt,
      $requestedIp,
    ]);

    $batchId = (int)$pdo->lastInsertId();
    $jobIds = [];

    foreach ($storeIds as $storeId) {
      $store = store_decommission_fetch_store($pdo, $storeId);
      if ((string)($store['lifecycle_status'] ?? 'active') === 'decommissioned') {
        throw new RuntimeException('解約済み店舗は batch 対象にできません: store_id=' . $storeId);
      }

      $latestJob = store_decommission_fetch_latest_job($pdo, $storeId);
      if ($latestJob && in_array((string)$latestJob['status'], ['requested', 'approved', 'scheduled', 'running'], true)) {
        throw new RuntimeException('未完了の解約ジョブが存在します: store_id=' . $storeId);
      }

      $summary = store_decommission_preview($pdo, $storeId);
      $confirmToken = bin2hex(random_bytes(32));

      $jobSt = $pdo->prepare("
        INSERT INTO store_decommission_jobs (
          batch_id,
          store_id,
          requested_by,
          approved_by,
          status,
          reason,
          confirm_token,
          requested_at,
          approved_at,
          scheduled_at,
          requested_ip,
          approved_ip,
          updated_at
        ) VALUES (?, ?, ?, ?, 'scheduled', ?, ?, NOW(), NOW(), ?, ?, ?, NOW())
      ");
      $jobSt->execute([
        $batchId,
        $storeId,
        $actorUserId,
        $actorUserId,
        $reason !== '' ? $reason : null,
        $confirmToken,
        $scheduledAt,
        $requestedIp,
        $requestedIp,
      ]);

      $jobId = (int)$pdo->lastInsertId();
      $jobIds[] = $jobId;
      store_decommission_create_snapshot($pdo, $jobId, $storeId, $summary);
      store_decommission_update_store_lifecycle($pdo, $storeId, 'decommissioning', [
        'decommission_requested_at' => store_decommission_now(),
        'decommission_approved_at' => store_decommission_now(),
        'decommission_scheduled_at' => $scheduledAt,
        'decommission_completed_at' => null,
      ]);
      store_decommission_log_step($pdo, $jobId, $storeId, 'batch.request', 'batch 廃棄申請', 'completed', $reason, [
        'batch_id' => $batchId,
        'summary' => $summary,
        'dry_run' => $dryRun,
      ], $actorUserId);
      store_decommission_log_step($pdo, $jobId, $storeId, 'batch.schedule', 'batch 廃棄予約', 'completed', null, [
        'batch_id' => $batchId,
        'scheduled_at' => $scheduledAt,
        'dry_run' => $dryRun,
      ], $actorUserId);
    }

    $pdo->commit();

    return [
      'batch_id' => $batchId,
      'job_ids' => $jobIds,
      'store_ids' => $storeIds,
      'scheduled_at' => $scheduledAt,
      'dry_run' => $dryRun,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function store_decommission_fetch_latest_job(PDO $pdo, int $storeId): ?array
{
  if (!store_decommission_table_exists($pdo, 'store_decommission_jobs')) {
    return null;
  }

  $st = $pdo->prepare("
    SELECT *
    FROM store_decommission_jobs
    WHERE store_id = ?
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function store_decommission_fetch_logs(PDO $pdo, int $jobId): array
{
  if ($jobId <= 0 || !store_decommission_table_exists($pdo, 'store_decommission_logs')) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT *
    FROM store_decommission_logs
    WHERE job_id = ?
    ORDER BY id DESC
    LIMIT 200
  ");
  $st->execute([$jobId]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function store_decommission_status(PDO $pdo, int $storeId): array
{
  $store = store_decommission_fetch_store($pdo, $storeId);
  $job = store_decommission_fetch_latest_job($pdo, $storeId);
  $summary = store_decommission_preview($pdo, $storeId);
  $logs = $job ? store_decommission_fetch_logs($pdo, (int)$job['id']) : [];

  return [
    'store' => $store,
    'job' => $job,
    'summary' => $summary,
    'logs' => $logs,
  ];
}

function store_decommission_update_store_lifecycle(PDO $pdo, int $storeId, string $status, array $timestamps = []): void
{
  $sets = ['lifecycle_status = :lifecycle_status'];
  $params = [':lifecycle_status' => $status, ':id' => $storeId];

  foreach ([
    'decommission_requested_at',
    'decommission_approved_at',
    'decommission_scheduled_at',
    'decommission_completed_at',
  ] as $column) {
    if (array_key_exists($column, $timestamps)) {
      $sets[] = "{$column} = :{$column}";
      $params[":{$column}"] = $timestamps[$column];
    }
  }

  $sql = "UPDATE stores SET " . implode(', ', $sets) . " WHERE id = :id LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute($params);
}

function store_decommission_suspend(PDO $pdo, int $storeId, int $actorUserId, string $password, string $confirmText, string $reason): array
{
  store_decommission_assert_password_and_confirm($pdo, $actorUserId, $storeId, $password, $confirmText, 'suspend');

  $store = store_decommission_fetch_store($pdo, $storeId);
  if (($store['lifecycle_status'] ?? 'active') === 'decommissioned') {
    throw new RuntimeException('解約済み店舗は停止変更できません');
  }

  store_decommission_update_store_lifecycle($pdo, $storeId, 'suspended');

  $job = store_decommission_fetch_latest_job($pdo, $storeId);
  if ($job) {
    store_decommission_log_step($pdo, (int)$job['id'], $storeId, 'suspend', '店舗停止', 'completed', $reason, [], $actorUserId);
  }

  return [
    'store_id' => $storeId,
    'lifecycle_status' => 'suspended',
  ];
}

function store_decommission_unsuspend(PDO $pdo, int $storeId, int $actorUserId, string $password): array
{
  if (!store_decommission_verify_password($pdo, $actorUserId, $password)) {
    throw new RuntimeException('パスワード再入力が一致しません');
  }

  $store = store_decommission_fetch_store($pdo, $storeId);
  $status = (string)($store['lifecycle_status'] ?? 'active');
  if (!in_array($status, ['suspended', 'decommissioning'], true)) {
    throw new RuntimeException('停止解除できる状態ではありません');
  }

  store_decommission_update_store_lifecycle($pdo, $storeId, 'active', [
    'decommission_requested_at' => null,
    'decommission_approved_at' => null,
    'decommission_scheduled_at' => null,
  ]);

  return [
    'store_id' => $storeId,
    'lifecycle_status' => 'active',
  ];
}

function store_decommission_request(
  PDO $pdo,
  int $storeId,
  int $actorUserId,
  string $password,
  string $confirmText,
  string $reason,
  ?string $requestedScheduleAt = null
): array {
  store_decommission_assert_password_and_confirm($pdo, $actorUserId, $storeId, $password, $confirmText, 'request');
  $requestedScheduleAt = store_decommission_validate_schedule_at($requestedScheduleAt);

  $store = store_decommission_fetch_store($pdo, $storeId);
  $lifecycle = (string)($store['lifecycle_status'] ?? 'active');
  if (!in_array($lifecycle, ['suspended', 'decommissioning'], true)) {
    throw new RuntimeException('まず店舗を停止してください');
  }
  $latestJob = store_decommission_fetch_latest_job($pdo, $storeId);
  if ($latestJob && in_array((string)$latestJob['status'], ['requested', 'approved', 'scheduled', 'running'], true)) {
    throw new RuntimeException('未完了の解約ジョブがすでに存在します');
  }

  $summary = store_decommission_preview($pdo, $storeId);
  $confirmToken = bin2hex(random_bytes(32));
  $requestedAt = store_decommission_now();

  $st = $pdo->prepare("
    INSERT INTO store_decommission_jobs (
      store_id,
      requested_by,
      status,
      reason,
      confirm_token,
      requested_at,
      scheduled_at,
      requested_ip,
      updated_at
    ) VALUES (?, ?, 'requested', ?, ?, NOW(), ?, ?, NOW())
  ");
  $st->execute([
    $storeId,
    $actorUserId,
    $reason !== '' ? $reason : null,
    $confirmToken,
    $requestedScheduleAt,
    $_SERVER['REMOTE_ADDR'] ?? null,
  ]);

  $jobId = (int)$pdo->lastInsertId();
  store_decommission_create_snapshot($pdo, $jobId, $storeId, $summary);
  store_decommission_update_store_lifecycle($pdo, $storeId, 'decommissioning', [
    'decommission_requested_at' => $requestedAt,
    'decommission_scheduled_at' => $requestedScheduleAt,
  ]);
  store_decommission_log_step($pdo, $jobId, $storeId, 'request', '廃棄申請', 'completed', $reason, [
    'requested_schedule_at' => $requestedScheduleAt,
    'summary' => $summary,
  ], $actorUserId);

  return [
    'job_id' => $jobId,
    'status' => 'requested',
    'confirm_token' => substr($confirmToken, 0, 8) . '...',
  ];
}

function store_decommission_approve(PDO $pdo, int $jobId, int $actorUserId, string $password, bool $approve, string $comment): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }
  if (!store_decommission_verify_password($pdo, $actorUserId, $password)) {
    throw new RuntimeException('パスワード再入力が一致しません');
  }
  if (!$approve) {
    throw new RuntimeException('approve=true が必要です');
  }
  if (!in_array((string)$job['status'], ['requested', 'approved'], true)) {
    throw new RuntimeException('承認できる状態ではありません');
  }

  $approvedAt = store_decommission_now();
  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET approved_by = ?,
        status = 'approved',
        approved_at = NOW(),
        approved_ip = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$actorUserId, $_SERVER['REMOTE_ADDR'] ?? null, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'decommissioning', [
    'decommission_approved_at' => $approvedAt,
  ]);
  store_decommission_log_step($pdo, $jobId, (int)$job['store_id'], 'approve', '廃棄承認', 'completed', $comment, [], $actorUserId);
  if ((int)($job['batch_id'] ?? 0) > 0) {
    store_decommission_refresh_batch_status($pdo, (int)$job['batch_id']);
  }

  return ['job_id' => $jobId, 'status' => 'approved'];
}

function store_decommission_schedule(PDO $pdo, int $jobId, string $scheduledAt, int $actorUserId): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }
  if (!in_array((string)$job['status'], ['requested', 'approved', 'scheduled'], true)) {
    throw new RuntimeException('スケジュール設定できる状態ではありません');
  }
  $scheduledAt = store_decommission_validate_schedule_at($scheduledAt) ?? '';
  if ($scheduledAt === '') {
    throw new RuntimeException('scheduled_at は YYYY-MM-DD HH:MM:SS 形式で入力してください');
  }

  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'scheduled',
        scheduled_at = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$scheduledAt, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'decommissioning', [
    'decommission_scheduled_at' => $scheduledAt,
  ]);
  store_decommission_log_step($pdo, $jobId, (int)$job['store_id'], 'schedule', '廃棄予約', 'completed', null, [
    'scheduled_at' => $scheduledAt,
  ], $actorUserId);
  if ((int)($job['batch_id'] ?? 0) > 0) {
    store_decommission_refresh_batch_status($pdo, (int)$job['batch_id']);
  }

  return ['job_id' => $jobId, 'status' => 'scheduled', 'scheduled_at' => $scheduledAt];
}

function store_decommission_cancel(PDO $pdo, int $jobId, int $actorUserId, string $password, string $confirmText, string $reason): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  store_decommission_assert_password_and_confirm($pdo, $actorUserId, (int)$job['store_id'], $password, $confirmText, 'cancel');

  if (in_array((string)$job['status'], ['running', 'completed', 'dry_run_completed', 'cancelled'], true)) {
    throw new RuntimeException('キャンセルできる状態ではありません');
  }

  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'cancelled',
        cancelled_at = NOW(),
        failure_reason = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$reason !== '' ? $reason : null, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'suspended', [
    'decommission_scheduled_at' => null,
  ]);
  store_decommission_log_step($pdo, $jobId, (int)$job['store_id'], 'cancel', '廃棄キャンセル', 'completed', $reason, [], $actorUserId);
  if ((int)($job['batch_id'] ?? 0) > 0) {
    store_decommission_refresh_batch_status($pdo, (int)$job['batch_id']);
  }

  return ['job_id' => $jobId, 'status' => 'cancelled'];
}

function store_decommission_fetch_job(PDO $pdo, int $jobId): ?array
{
  if ($jobId <= 0 || !store_decommission_table_exists($pdo, 'store_decommission_jobs')) {
    return null;
  }

  $st = $pdo->prepare("SELECT * FROM store_decommission_jobs WHERE id=? LIMIT 1");
  $st->execute([$jobId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : null;
}

function store_decommission_export(PDO $pdo, int $jobId, int $actorUserId): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  $storeId = (int)$job['store_id'];
  $store = store_decommission_fetch_store($pdo, $storeId);
  $summary = store_decommission_preview($pdo, $storeId);

  $dir = dirname(__DIR__) . '/public/uploads/decommission_exports';
  if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
    throw new RuntimeException('エクスポート保存先を作成できません');
  }

  $fileName = sprintf('store_%d_job_%d_%s.json', $storeId, $jobId, date('Ymd_His'));
  $filePath = $dir . '/' . $fileName;
  $payload = [
    'store' => [
      'id' => $storeId,
      'name' => (string)($store['name'] ?? ''),
      'lifecycle_status' => (string)($store['lifecycle_status'] ?? ''),
    ],
    'job' => [
      'id' => $jobId,
      'status' => (string)($job['status'] ?? ''),
      'requested_at' => (string)($job['requested_at'] ?? ''),
      'scheduled_at' => (string)($job['scheduled_at'] ?? ''),
    ],
    'summary' => $summary,
    'exported_at' => store_decommission_now(),
  ];

  if (file_put_contents($filePath, store_decommission_json_encode($payload)) === false) {
    throw new RuntimeException('エクスポート書き込みに失敗しました');
  }

  $publicPath = '/wbss/public/uploads/decommission_exports/' . $fileName;
  $st = $pdo->prepare("
    UPDATE store_decommission_jobs
    SET export_path = ?, export_ready = 1, updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$publicPath, $jobId]);

  store_decommission_log_step($pdo, $jobId, $storeId, 'export', '最終エクスポート作成', 'completed', null, [
    'export_path' => $publicPath,
  ], $actorUserId);

  return ['job_id' => $jobId, 'export_path' => $publicPath, 'export_ready' => true];
}

function store_decommission_mark_job_running(PDO $pdo, int $jobId): void
{
  $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'running',
        started_at = NOW(),
        updated_at = NOW()
    WHERE id = ?
      AND status = 'scheduled'
    LIMIT 1
  ")->execute([$jobId]);
}

function store_decommission_mark_job_failed(PDO $pdo, int $jobId, string $reason): void
{
  $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'failed',
        failed_at = NOW(),
        failure_reason = ?,
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ")->execute([$reason, $jobId]);
}

function store_decommission_mark_job_dry_run_completed(PDO $pdo, int $jobId, int $actorUserId): void
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'dry_run_completed',
        executed_by = ?,
        completed_at = NOW(),
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ")->execute([$actorUserId > 0 ? $actorUserId : null, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'suspended', [
    'decommission_scheduled_at' => null,
    'decommission_completed_at' => null,
  ]);
}

function store_decommission_mark_job_completed(PDO $pdo, int $jobId, int $actorUserId): void
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  $pdo->prepare("
    UPDATE store_decommission_jobs
    SET status = 'completed',
        executed_by = ?,
        completed_at = NOW(),
        updated_at = NOW()
    WHERE id = ?
    LIMIT 1
  ")->execute([$actorUserId > 0 ? $actorUserId : null, $jobId]);

  store_decommission_update_store_lifecycle($pdo, (int)$job['store_id'], 'decommissioned', [
    'decommission_completed_at' => store_decommission_now(),
  ]);
}

function store_decommission_count_rows_for_store(PDO $pdo, string $table, int $storeId): int
{
  if (!store_decommission_column_exists($pdo, $table, 'store_id')) {
    throw new RuntimeException("store_id カラムがないため処理できません: {$table}");
  }
  $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE store_id = ?");
  $st->execute([$storeId]);
  return (int)$st->fetchColumn();
}

function store_decommission_delete_rows_for_store(PDO $pdo, string $table, int $storeId): void
{
  if (!store_decommission_column_exists($pdo, $table, 'store_id')) {
    throw new RuntimeException("store_id カラムがないため削除できません: {$table}");
  }
  $st = $pdo->prepare("DELETE FROM `{$table}` WHERE store_id = ?");
  $st->execute([$storeId]);
}

function store_decommission_collect_due_jobs(PDO $pdo, int $limit = 10): array
{
  $limit = max(1, min(100, $limit));
  $st = $pdo->query("
    SELECT *
    FROM store_decommission_jobs
    WHERE status = 'scheduled'
      AND scheduled_at IS NOT NULL
      AND scheduled_at <= NOW()
    ORDER BY scheduled_at ASC, id ASC
    LIMIT {$limit}
  ");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function store_decommission_execute_job(PDO $pdo, int $jobId, bool $dryRun = true, int $systemUserId = 0, bool $persistDryRun = false): array
{
  $job = store_decommission_fetch_job($pdo, $jobId);
  if (!$job) {
    throw new RuntimeException('ジョブが見つかりません');
  }

  $storeId = (int)$job['store_id'];
  $batchId = (int)($job['batch_id'] ?? 0);
  $batch = $batchId > 0 ? store_decommission_fetch_batch($pdo, $batchId) : null;
  $effectiveDryRun = $dryRun || ($batch && (int)($batch['dry_run'] ?? 0) === 1);
  $plan = store_decommission_build_delete_plan($pdo);
  $ignored = store_decommission_ignored_tables($pdo);
  $result = ['deleted' => [], 'skipped' => [], 'ignored' => []];

  store_decommission_mark_job_running($pdo, $jobId);
  if ($batchId > 0) {
    store_decommission_mark_batch_running($pdo, $batchId);
  }
  store_decommission_log_step($pdo, $jobId, $storeId, 'runner.start', '廃棄実行開始', 'started', $effectiveDryRun ? 'dry-run' : 'execute', [
    'dry_run' => $effectiveDryRun,
    'persist_dry_run' => $persistDryRun,
    'batch_id' => $batchId > 0 ? $batchId : null,
    'planned_tables' => array_column($plan, 'table'),
    'ignored_tables' => array_column($ignored, 'table'),
  ], $systemUserId > 0 ? $systemUserId : null);

  try {
    foreach ($ignored as $entry) {
      $table = (string)$entry['table'];
      $reason = (string)$entry['reason'];
      store_decommission_log_step($pdo, $jobId, $storeId, 'ignore.' . $table, 'Ignore ' . $table, 'completed', $reason, [
        'table_kind' => $entry['kind'],
      ], $systemUserId ?: null);
      $result['ignored'][$table] = $reason;
    }

    foreach ($plan as $entry) {
      $table = (string)$entry['table'];
      $label = 'Delete ' . $table;
      store_decommission_log_step($pdo, $jobId, $storeId, 'delete.' . $table, $label, 'started', null, [], $systemUserId ?: null);

      $count = store_decommission_count_rows_for_store($pdo, $table, $storeId);

      if ($count === 0) {
        store_decommission_log_step($pdo, $jobId, $storeId, 'delete.' . $table, $label, 'completed', '0 rows', [
          'rows' => 0,
        ], $systemUserId ?: null);
        $result['skipped'][$table] = 0;
        continue;
      }

      if (!$effectiveDryRun) {
        store_decommission_delete_rows_for_store($pdo, $table, $storeId);
      }

      store_decommission_log_step($pdo, $jobId, $storeId, 'delete.' . $table, $label, 'completed', $effectiveDryRun ? 'dry-run' : null, [
        'rows' => $count,
        'dry_run' => $effectiveDryRun,
      ], $systemUserId ?: null);
      $result['deleted'][$table] = $count;
    }

    if ($effectiveDryRun && !$persistDryRun) {
      $pdo->prepare("
        UPDATE store_decommission_jobs
        SET status = 'scheduled',
            started_at = NULL,
            updated_at = NOW()
        WHERE id = ?
        LIMIT 1
      ")->execute([$jobId]);
    } elseif ($effectiveDryRun) {
      store_decommission_mark_job_dry_run_completed($pdo, $jobId, $systemUserId);
    } else {
      store_decommission_mark_job_completed($pdo, $jobId, $systemUserId);
    }

    store_decommission_log_step($pdo, $jobId, $storeId, 'runner.finish', '廃棄実行完了', 'completed', null, [
      'dry_run' => $effectiveDryRun,
      'deleted_tables' => array_keys($result['deleted']),
      'ignored_tables' => $result['ignored'],
    ], $systemUserId ?: null);
    if ($batchId > 0) {
      store_decommission_refresh_batch_status($pdo, $batchId);
    }

    return $result;
  } catch (Throwable $e) {
    store_decommission_mark_job_failed($pdo, $jobId, $e->getMessage());
    store_decommission_log_step($pdo, $jobId, $storeId, 'runner.finish', '廃棄実行完了', 'failed', $e->getMessage(), [
      'dry_run' => $effectiveDryRun,
    ], $systemUserId ?: null);
    if ($batchId > 0) {
      store_decommission_refresh_batch_status($pdo, $batchId);
    }
    throw $e;
  }
}

function store_decommission_is_write_blocked(PDO $pdo, int $storeId): bool
{
  $store = store_decommission_fetch_store($pdo, $storeId);
  return in_array((string)($store['lifecycle_status'] ?? 'active'), ['suspended', 'decommissioning', 'decommissioned'], true);
}

function store_decommission_assert_store_writable(PDO $pdo, int $storeId, string $message = 'この店舗は停止中のため操作できません'): void
{
  if (!store_decommission_is_write_blocked($pdo, $storeId)) {
    return;
  }
  throw new RuntimeException($message);
}
