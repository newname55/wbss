<?php
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/service_push.php';
require_once __DIR__ . '/store.php';
require_once __DIR__ . '/store_access.php';

function message_table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

function message_tables_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }

  $ready = message_table_exists($pdo, 'messages') && message_table_exists($pdo, 'message_recipients');
  return $ready;
}

function message_has_role(string $role): bool {
  return function_exists('is_role') ? is_role($role) : in_array($role, (array)($_SESSION['roles'] ?? []), true);
}

function message_current_user_id(): int {
  return function_exists('current_user_id') ? (int)(current_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
}

function message_is_cast_only(): bool {
  return message_has_role('cast') && !message_has_role('admin') && !message_has_role('manager') && !message_has_role('super_user');
}

function message_dashboard_path(): string {
  return message_is_cast_only() ? '/wbss/public/dashboard_cast.php' : '/wbss/public/dashboard.php';
}

function message_resolve_cast_store_id(PDO $pdo, int $userId): int {
  if ($userId <= 0) {
    return 0;
  }

  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id AND r.code = 'cast'
    WHERE ur.user_id = ?
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  $storeId = (int)($st->fetchColumn() ?: 0);
  if ($storeId > 0) {
    $_SESSION['store_id'] = $storeId;
    set_current_store_id($storeId);
  }
  return $storeId;
}

function message_resolve_store_id(PDO $pdo, ?int $requestedStoreId = null): int {
  $userId = message_current_user_id();
  if ($userId <= 0) {
    return 0;
  }

  if (message_is_cast_only()) {
    return message_resolve_cast_store_id($pdo, $userId);
  }

  try {
    return store_access_resolve_manageable_store_id($pdo, $requestedStoreId);
  } catch (Throwable $e) {
    return 0;
  }
}

function message_store_name(PDO $pdo, int $storeId): string {
  if ($storeId <= 0) {
    return '';
  }

  try {
    $st = $pdo->prepare("SELECT name FROM stores WHERE id = ? LIMIT 1");
    $st->execute([$storeId]);
    return (string)($st->fetchColumn() ?: '');
  } catch (Throwable $e) {
    return '';
  }
}

function message_kind_label(string $kind): string {
  return $kind === 'thanks' ? 'ありがとう' : '業務連絡';
}

function message_recipient_exists(PDO $pdo, int $storeId, int $recipientUserId): bool {
  if ($storeId <= 0 || $recipientUserId <= 0) {
    return false;
  }

  $st = $pdo->prepare("
    SELECT 1
    FROM (
      SELECT su.user_id
      FROM store_users su
      JOIN users u ON u.id = su.user_id
      WHERE su.store_id = :store_id
        AND su.status = 'active'
        AND u.is_active = 1

      UNION

      SELECT ur.user_id
      FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id
      JOIN users u ON u.id = ur.user_id
      WHERE ur.store_id = :store_id2
        AND r.code IN ('admin', 'manager', 'super_user')
        AND u.is_active = 1
    ) members
    WHERE members.user_id = :user_id
    LIMIT 1
  ");
  $st->execute([
    ':store_id' => $storeId,
    ':store_id2' => $storeId,
    ':user_id' => $recipientUserId,
  ]);
  return (bool)$st->fetchColumn();
}

function message_fetch_recipients(PDO $pdo, int $storeId, int $currentUserId): array {
  if ($storeId <= 0) {
    return [];
  }

  $st = $pdo->prepare("
    SELECT
      members.user_id,
      members.display_name,
      MAX(members.staff_code) AS staff_code,
      GROUP_CONCAT(DISTINCT members.role_label ORDER BY members.role_label SEPARATOR ' / ') AS role_label
    FROM (
      SELECT
        su.user_id,
        u.display_name,
        COALESCE(NULLIF(TRIM(su.staff_code), ''), '') AS staff_code,
        'キャスト' AS role_label
      FROM store_users su
      JOIN users u ON u.id = su.user_id
      JOIN user_roles ur ON ur.user_id = su.user_id AND ur.store_id = su.store_id
      JOIN roles r ON r.id = ur.role_id AND r.code = 'cast'
      WHERE su.store_id = :store_id
        AND su.status = 'active'
        AND u.is_active = 1

      UNION

      SELECT
        ur.user_id,
        u.display_name,
        '' AS staff_code,
        CASE
          WHEN r.code = 'super_user' THEN '全体管理'
          WHEN r.code = 'admin' THEN '管理者'
          WHEN r.code = 'manager' THEN '店長'
          ELSE r.name
        END AS role_label
      FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id
      JOIN users u ON u.id = ur.user_id
      WHERE ur.store_id = :store_id2
        AND r.code IN ('admin', 'manager', 'super_user')
        AND u.is_active = 1
    ) members
    WHERE members.user_id <> :current_user_id
    GROUP BY members.user_id, members.display_name
    ORDER BY
      CASE WHEN MAX(members.staff_code) = '' THEN 1 ELSE 0 END ASC,
      CAST(CASE WHEN MAX(members.staff_code) REGEXP '^[0-9]+$' THEN MAX(members.staff_code) ELSE '999999' END AS UNSIGNED) ASC,
      members.display_name ASC,
      members.user_id ASC
  ");
  $st->execute([
    ':store_id' => $storeId,
    ':store_id2' => $storeId,
    ':current_user_id' => $currentUserId,
  ]);

  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function message_fetch_dashboard_summary(PDO $pdo, int $storeId, int $userId, int $thanksLimit = 3): array {
  $summary = [
    'unread_count' => 0,
    'recent_thanks' => [],
    'monthly_thanks_count' => 0,
    'total_unread_count' => 0,
  ];

  if ($storeId <= 0 || $userId <= 0 || !message_tables_ready($pdo)) {
    return $summary;
  }

  try {
    $stUnread = $pdo->prepare("
      SELECT COUNT(*)
      FROM message_recipients mr
      JOIN messages m ON m.id = mr.message_id
      WHERE mr.recipient_user_id = ?
        AND mr.is_read = 0
        AND m.kind = 'normal'
        AND m.store_id = ?
    ");
    $stUnread->execute([$userId, $storeId]);
    $summary['unread_count'] = (int)($stUnread->fetchColumn() ?: 0);
    $summary['total_unread_count'] = message_total_unread_count($pdo, $storeId, $userId);

    $limit = max(1, $thanksLimit);
    $stThanks = $pdo->prepare("
      SELECT
        m.id,
        m.title,
        m.body,
        m.created_at,
        COALESCE(NULLIF(TRIM(u.display_name), ''), u.login_id, CONCAT('user#', m.sender_user_id)) AS sender_name
      FROM message_recipients mr
      JOIN messages m ON m.id = mr.message_id
      JOIN users u ON u.id = m.sender_user_id
      WHERE mr.recipient_user_id = ?
        AND m.kind = 'thanks'
        AND m.store_id = ?
      ORDER BY m.created_at DESC, m.id DESC
      LIMIT {$limit}
    ");
    $stThanks->execute([$userId, $storeId]);
    $summary['recent_thanks'] = $stThanks->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $monthStart = (new DateTimeImmutable('first day of this month 00:00:00', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $nextMonthStart = (new DateTimeImmutable('first day of next month 00:00:00', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d H:i:s');
    $stMonthly = $pdo->prepare("
      SELECT COUNT(*)
      FROM message_recipients mr
      JOIN messages m ON m.id = mr.message_id
      WHERE mr.recipient_user_id = ?
        AND m.kind = 'thanks'
        AND m.store_id = ?
        AND m.created_at >= ?
        AND m.created_at < ?
    ");
    $stMonthly->execute([$userId, $storeId, $monthStart, $nextMonthStart]);
    $summary['monthly_thanks_count'] = (int)($stMonthly->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return $summary;
  }

  return $summary;
}

function message_total_unread_count(PDO $pdo, int $storeId, int $userId): int {
  if ($storeId <= 0 || $userId <= 0 || !message_tables_ready($pdo)) {
    return 0;
  }

  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM message_recipients mr
      JOIN messages m ON m.id = mr.message_id
      WHERE mr.recipient_user_id = ?
        AND mr.is_read = 0
        AND m.store_id = ?
    ");
    $st->execute([$userId, $storeId]);
    return (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function message_fetch_inbox(PDO $pdo, int $storeId, int $userId, string $kind, int $limit = 100): array {
  if ($storeId <= 0 || $userId <= 0 || !message_tables_ready($pdo)) {
    return [];
  }

  $kind = ($kind === 'thanks') ? 'thanks' : 'normal';
  $limit = max(1, min(200, $limit));

  $st = $pdo->prepare("
    SELECT
      m.id,
      m.store_id,
      m.sender_user_id,
      m.kind,
      m.title,
      m.body,
      m.created_at,
      m.updated_at,
      mr.id AS recipient_id,
      mr.is_read,
      mr.read_at,
      COALESCE(NULLIF(TRIM(u.display_name), ''), u.login_id, CONCAT('user#', m.sender_user_id)) AS sender_name
    FROM message_recipients mr
    JOIN messages m ON m.id = mr.message_id
    JOIN users u ON u.id = m.sender_user_id
    WHERE mr.recipient_user_id = :user_id
      AND m.store_id = :store_id
      AND m.kind = :kind
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT {$limit}
  ");
  $st->execute([
    ':user_id' => $userId,
    ':store_id' => $storeId,
    ':kind' => $kind,
  ]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function message_mark_kind_as_read(PDO $pdo, int $storeId, int $userId, string $kind): int {
  if ($storeId <= 0 || $userId <= 0 || !message_tables_ready($pdo)) {
    return 0;
  }

  $kind = ($kind === 'thanks') ? 'thanks' : 'normal';
  $st = $pdo->prepare("
    UPDATE message_recipients mr
    JOIN messages m ON m.id = mr.message_id
    SET mr.is_read = 1,
        mr.read_at = NOW()
    WHERE mr.recipient_user_id = ?
      AND mr.is_read = 0
      AND m.store_id = ?
      AND m.kind = ?
  ");
  $st->execute([$userId, $storeId, $kind]);
  return $st->rowCount();
}

function message_normalize_recipient_ids(array $recipientUserIds, int $senderUserId): array {
  $ids = [];
  foreach ($recipientUserIds as $recipientUserId) {
    $recipientUserId = (int)$recipientUserId;
    if ($recipientUserId <= 0 || $recipientUserId === $senderUserId) {
      continue;
    }
    $ids[$recipientUserId] = true;
  }
  return array_map('intval', array_keys($ids));
}

function message_fetch_sent_history(PDO $pdo, int $storeId, int $senderUserId, string $kind, int $limit = 50): array {
  if ($storeId <= 0 || $senderUserId <= 0 || !message_tables_ready($pdo)) {
    return [];
  }

  $kind = ($kind === 'thanks') ? 'thanks' : 'normal';
  $limit = max(1, min(200, $limit));

  $st = $pdo->prepare("
    SELECT
      m.id,
      m.kind,
      m.title,
      m.body,
      m.visibility_scope,
      m.created_at,
      COUNT(mr.id) AS recipient_count,
      GROUP_CONCAT(DISTINCT mr.recipient_user_id ORDER BY mr.recipient_user_id ASC SEPARATOR ',') AS recipient_user_ids,
      GROUP_CONCAT(
        DISTINCT COALESCE(NULLIF(TRIM(u.display_name), ''), u.login_id, CONCAT('user#', mr.recipient_user_id))
        ORDER BY u.display_name ASC, u.id ASC
        SEPARATOR ' / '
      ) AS recipient_names,
      SUM(CASE WHEN mr.is_read = 1 THEN 1 ELSE 0 END) AS read_count
    FROM messages m
    JOIN message_recipients mr ON mr.message_id = m.id
    JOIN users u ON u.id = mr.recipient_user_id
    WHERE m.store_id = :store_id
      AND m.sender_user_id = :sender_user_id
      AND m.kind = :kind
    GROUP BY m.id, m.kind, m.title, m.body, m.visibility_scope, m.created_at
    ORDER BY m.created_at DESC, m.id DESC
    LIMIT {$limit}
  ");
  $st->execute([
    ':store_id' => $storeId,
    ':sender_user_id' => $senderUserId,
    ':kind' => $kind,
  ]);

  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function message_create_many(PDO $pdo, int $storeId, int $senderUserId, array $recipientUserIds, string $kind, string $title, string $body): int {
  if (!message_tables_ready($pdo)) {
    throw new RuntimeException('messages テーブルが未作成です');
  }
  if ($storeId <= 0) {
    throw new InvalidArgumentException('店舗が未選択です');
  }
  if ($senderUserId <= 0) {
    throw new InvalidArgumentException('送信者が不正です');
  }

  $recipientUserIds = message_normalize_recipient_ids($recipientUserIds, $senderUserId);
  if (!$recipientUserIds) {
    throw new InvalidArgumentException('送信先を1人以上選択してください');
  }

  $kind = ($kind === 'thanks') ? 'thanks' : 'normal';
  $title = trim($title);
  $body = trim($body);

  if ($body === '') {
    throw new InvalidArgumentException('本文を入力してください');
  }
  if (mb_strlen($title) > 120) {
    throw new InvalidArgumentException('件名は120文字以内で入力してください');
  }
  if (mb_strlen($body) > 2000) {
    throw new InvalidArgumentException('本文は2000文字以内で入力してください');
  }
  foreach ($recipientUserIds as $recipientUserId) {
    if (!message_recipient_exists($pdo, $storeId, $recipientUserId)) {
      throw new RuntimeException('送信先がこの店舗に見つかりません');
    }
  }

  $pdo->beginTransaction();
  try {
    $stMessage = $pdo->prepare("
      INSERT INTO messages
        (store_id, sender_user_id, kind, title, body, visibility_scope, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, ?, NOW(), NOW())
    ");
    $visibilityScope = count($recipientUserIds) > 1 ? 'multi' : 'direct';
    $stMessage->execute([$storeId, $senderUserId, $kind, $title, $body, $visibilityScope]);
    $messageId = (int)$pdo->lastInsertId();

    $stRecipient = $pdo->prepare("
      INSERT INTO message_recipients
        (message_id, recipient_user_id, is_read, read_at, created_at)
      VALUES
        (?, ?, 0, NULL, NOW())
    ");
    foreach ($recipientUserIds as $recipientUserId) {
      $stRecipient->execute([$messageId, $recipientUserId]);
    }

    $pdo->commit();
    try {
      push_notify_unread_message($pdo, $storeId, $senderUserId, $recipientUserIds, $kind, $title, $body);
    } catch (Throwable $e) {
      // push is best effort and must not break message delivery
    }
    return $messageId;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) {
      $pdo->rollBack();
    }
    throw $e;
  }
}

function message_create(PDO $pdo, int $storeId, int $senderUserId, int $recipientUserId, string $kind, string $title, string $body): int {
  return message_create_many($pdo, $storeId, $senderUserId, [$recipientUserId], $kind, $title, $body);
}
