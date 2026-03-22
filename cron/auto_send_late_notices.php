<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("CLI only\n");
}

function line_api_post(string $accessToken, string $path, array $body): array {
  $url = 'https://api.line.me/v2/bot/' . ltrim($path, '/');
  $ch = curl_init($url);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 12,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err  = curl_error($ch);
  curl_close($ch);

  return [
    'code' => $code,
    'body' => is_string($res) ? $res : '',
    'curl_error' => $err,
  ];
}

function table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
    ");
    $st->execute([$table, $column]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

function resolve_store_sender_user_id(PDO $pdo, int $storeId): int {
  $st = $pdo->prepare("
    SELECT ur.user_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    JOIN users u ON u.id = ur.user_id
    WHERE ur.store_id = ?
      AND r.code IN ('manager', 'admin', 'super_user')
      AND u.is_active = 1
    ORDER BY
      CASE r.code
        WHEN 'manager' THEN 0
        WHEN 'admin' THEN 1
        WHEN 'super_user' THEN 2
        ELSE 9
      END,
      ur.user_id ASC
    LIMIT 1
  ");
  $st->execute([$storeId]);
  return (int)($st->fetchColumn() ?: 0);
}

function notice_due_at(string $bizDate, string $planTime, string $businessDayStart, int $delayMinutes): ?DateTime {
  $planTime = trim($planTime);
  if ($planTime === '') return null;

  $tz = new DateTimeZone('Asia/Tokyo');
  $base = new DateTime($bizDate . ' ' . substr($planTime, 0, 8), $tz);
  $cut  = substr(trim($businessDayStart), 0, 8);
  if ($cut === '') $cut = '06:00:00';

  // 深夜帯の予定は営業日翌日の実カレンダー日時として扱う。
  if (substr($planTime, 0, 8) < $cut) {
    $base->modify('+1 day');
  }

  if ($delayMinutes > 0) {
    $base->modify('+' . $delayMinutes . ' minutes');
  }
  return $base;
}

function parse_cli_options(array $argv): array {
  $opts = [
    'dry_run' => false,
    'delay_minutes' => 10,
  ];

  foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
      $opts['dry_run'] = true;
      continue;
    }
    if (strpos($arg, '--delay-minutes=') === 0) {
      $value = substr($arg, strlen('--delay-minutes='));
      if ($value !== '' && ctype_digit($value)) {
        $opts['delay_minutes'] = max(0, (int)$value);
      }
    }
  }

  return $opts;
}

$opts = parse_cli_options($argv);
$dryRun = (bool)$opts['dry_run'];
$delayMinutes = (int)$opts['delay_minutes'];
$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');

if (!$dryRun && $accessToken === '') {
  fwrite(STDERR, "[late_notice] LINE_MSG_CHANNEL_ACCESS_TOKEN missing\n");
  exit(1);
}

$pdo = db();
$now = jst_now();

$hasLateNoticeDelayColumn = table_has_column($pdo, 'stores', 'late_notice_delay_minutes');
$hasLateNoticeEnabledColumn = table_has_column($pdo, 'stores', 'late_notice_auto_enabled');
$lateNoticeDelayCol = $hasLateNoticeDelayColumn
  ? 'late_notice_delay_minutes'
  : (string)$delayMinutes . ' AS late_notice_delay_minutes';
$lateNoticeEnabledCol = $hasLateNoticeEnabledColumn
  ? 'late_notice_auto_enabled'
  : '1 AS late_notice_auto_enabled';

$stores = $pdo->query("
  SELECT id, name, business_day_start, {$lateNoticeDelayCol}, {$lateNoticeEnabledCol}
  FROM stores
  WHERE is_active = 1
  ORDER BY id ASC
")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$sentCount = 0;
$skipCount = 0;
$failCount = 0;

foreach ($stores as $store) {
  $storeId = (int)($store['id'] ?? 0);
  if ($storeId <= 0) continue;

  $bizDate = business_date_for_store($store, clone $now);
  $storeName = (string)($store['name'] ?? '');
  $autoEnabled = ((int)($store['late_notice_auto_enabled'] ?? 1) === 1);
  if (!$autoEnabled) {
    $skipCount++;
    fwrite(STDOUT, sprintf(
      "[late_notice] skip store=%d name=%s reason=auto_disabled\n",
      $storeId,
      $storeName
    ));
    continue;
  }

  $senderUserId = resolve_store_sender_user_id($pdo, $storeId);
  $storeDelayMinutes = (int)($store['late_notice_delay_minutes'] ?? $delayMinutes);
  if ($storeDelayMinutes < 0) $storeDelayMinutes = 0;
  if ($storeDelayMinutes > 180) $storeDelayMinutes = 180;
  if ($senderUserId <= 0) {
    $skipCount++;
    fwrite(STDOUT, sprintf(
      "[late_notice] skip store=%d name=%s reason=no_sender_user\n",
      $storeId,
      $storeName
    ));
    continue;
  }

  $st = $pdo->prepare("
    SELECT
      c.user_id,
      c.display_name,
      c.shop_tag,
      sp.start_time,
      a.clock_in,
      (
        SELECT ui.provider_user_id
        FROM user_identities ui
        WHERE ui.user_id = c.user_id
          AND ui.provider = 'line'
          AND ui.is_active = 1
        ORDER BY ui.id DESC
        LIMIT 1
      ) AS line_user_id
    FROM v_store_casts_active c
    JOIN cast_shift_plans sp
      ON sp.store_id = c.store_id
     AND sp.user_id = c.user_id
     AND sp.business_date = ?
     AND sp.status = 'planned'
     AND sp.is_off = 0
    LEFT JOIN attendances a
      ON a.store_id = c.store_id
     AND a.user_id = c.user_id
     AND a.business_date = ?
    WHERE c.store_id = ?
    ORDER BY
      CASE WHEN c.shop_tag='' THEN 999999 ELSE CAST(c.shop_tag AS UNSIGNED) END ASC,
      c.display_name ASC,
      c.user_id ASC
  ");
  $st->execute([$bizDate, $bizDate, $storeId]);
  $candidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($candidates as $row) {
    $castUserId = (int)($row['user_id'] ?? 0);
    $displayName = trim((string)($row['display_name'] ?? ''));
    $lineUserId = trim((string)($row['line_user_id'] ?? ''));

    if (!empty($row['clock_in'])) {
      $skipCount++;
      fwrite(STDOUT, sprintf(
        "[late_notice] skip store=%d cast=%d name=%s reason=already_clocked_in\n",
        $storeId,
        $castUserId,
        $displayName
      ));
      continue;
    }

    $sentCheck = $pdo->prepare("
      SELECT 1
      FROM line_notice_actions
      WHERE store_id = ?
        AND business_date = ?
        AND cast_user_id = ?
        AND kind = 'late'
      LIMIT 1
    ");
    $sentCheck->execute([$storeId, $bizDate, $castUserId]);
    if ($sentCheck->fetchColumn()) {
      $skipCount++;
      fwrite(STDOUT, sprintf(
        "[late_notice] skip store=%d cast=%d name=%s reason=already_sent\n",
        $storeId,
        $castUserId,
        $displayName
      ));
      continue;
    }

    $lineUserId = trim((string)($row['line_user_id'] ?? ''));
    if ($lineUserId === '') {
      $skipCount++;
      fwrite(STDOUT, sprintf(
        "[late_notice] skip store=%d cast=%d name=%s reason=line_unlinked\n",
        $storeId,
        $castUserId,
        $displayName
      ));
      continue;
    }

    $dueAt = notice_due_at(
      $bizDate,
      (string)($row['start_time'] ?? ''),
      (string)($store['business_day_start'] ?? '06:00:00'),
      $storeDelayMinutes
    );
    if (!$dueAt || $dueAt > $now) {
      $skipCount++;
      fwrite(STDOUT, sprintf(
        "[late_notice] skip store=%d cast=%d name=%s reason=not_due due_at=%s now=%s\n",
        $storeId,
        $castUserId,
        $displayName,
        $dueAt ? $dueAt->format('Y-m-d H:i:s') : 'null',
        $now->format('Y-m-d H:i:s')
      ));
      continue;
    }

    $message = $displayName . "さん\n遅刻の連絡をお願いします。\n到着予定時刻と理由を返信してください。";
    $token = bin2hex(random_bytes(12));

    if ($dryRun) {
      fwrite(STDOUT, sprintf(
        "[dry-run] store=%d cast=%d name=%s due_at=%s\n",
        $storeId,
        $castUserId,
        $displayName,
        $dueAt->format('Y-m-d H:i:s')
      ));
      $sentCount++;
      continue;
    }

    $pdo->beginTransaction();
    try {
      $ins = $pdo->prepare("
        INSERT INTO line_notice_actions
          (store_id, business_date, cast_user_id, kind, token, template_text, sent_text,
           sent_by_user_id, sent_at, status, error_message, created_at, updated_at)
        VALUES
          (?, ?, ?, 'late', ?, ?, ?, ?, NOW(), 'sent', NULL, NOW(), NOW())
      ");
      $ins->execute([
        $storeId,
        $bizDate,
        $castUserId,
        $token,
        $message,
        $message,
        $senderUserId > 0 ? $senderUserId : null,
      ]);

      $api = line_api_post($accessToken, 'message/push', [
        'to' => $lineUserId,
        'messages' => [
          ['type' => 'text', 'text' => $message],
        ],
      ]);

      if ($api['code'] >= 300) {
        $errMsg = 'HTTP ' . $api['code'];
        if ($api['curl_error'] !== '') $errMsg .= ' curl=' . $api['curl_error'];

        $upd = $pdo->prepare("
          UPDATE line_notice_actions
          SET status='failed', error_message=?, updated_at=NOW()
          WHERE token=? LIMIT 1
        ");
        $upd->execute([$errMsg, $token]);
        $pdo->commit();

        $failCount++;
        fwrite(STDERR, sprintf(
          "[late_notice] send failed store=%d cast=%d msg=%s\n",
          $storeId,
          $castUserId,
          $errMsg
        ));
        continue;
      }

      $pdo->commit();
      $sentCount++;
      fwrite(STDOUT, sprintf(
        "[late_notice] sent store=%d cast=%d name=%s\n",
        $storeId,
        $castUserId,
        $displayName
      ));
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $failCount++;
      fwrite(STDERR, sprintf(
        "[late_notice] exception store=%d cast=%d err=%s\n",
        $storeId,
        $castUserId,
        $e->getMessage()
      ));
    }
  }
}

fwrite(STDOUT, sprintf(
  "[late_notice] done sent=%d skipped=%d failed=%d mode=%s delay=%dmin\n",
  $sentCount,
  $skipCount,
  $failCount,
  $dryRun ? 'dry-run' : 'live',
  $delayMinutes
));

exit($failCount > 0 ? 1 : 0);
