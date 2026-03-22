<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/bootstrap.php';

if (!function_exists('att_h')) {
  function att_h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/**
 * CSRF（プロジェクト側がcsrf_token/csrf_verifyを持っていればそれを優先）
 */
function att_csrf_token(): string {
  if (function_exists('csrf_token')) return (string)csrf_token();
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  return (string)$_SESSION['csrf_token'];
}
function att_csrf_verify(?string $token): bool {
  if (function_exists('csrf_verify')) {
    try {
      $r = csrf_verify($token);
      return ($r === null) ? true : (bool)$r;
    } catch (Throwable $e) {
      // fallthrough
    }
  }
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $t = (string)($token ?? '');
  $s = (string)($_SESSION['csrf_token'] ?? '');
  return ($t !== '' && $s !== '' && hash_equals($s, $t));
}

/**
 * store_id を安全に決める（store.php の変更に巻き込まれない）
 */
function att_safe_store_id(): int {
  if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  $sid = 0;

  // プロジェクト側が current_store_id() を提供していれば優先
  if (function_exists('current_store_id')) {
    try { $sid = (int)current_store_id(); } catch (Throwable $e) { /* ignore */ }
  }
  if ($sid <= 0) $sid = (int)($_GET['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);

  if ($sid > 0) {
    // よく参照されがちなセッションキーを全埋め
    $_SESSION['store_id'] = $sid;
    $_SESSION['current_store_id'] = $sid;
    $_SESSION['store_selected'] = 1;
  }
  return $sid;
}

function att_fetch_store(PDO $pdo, int $store_id): array {
  if ($store_id <= 0) return [];
  $st = $pdo->prepare("SELECT id, name, business_day_start FROM stores WHERE id=? LIMIT 1");
  $st->execute([$store_id]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : [];
}

function att_business_date_for_store(string $businessDayStart, ?DateTimeImmutable $now=null): string {
  // 共通実装（app/bootstrap.php の business_date_for_store）を利用するラッパー
  // - 呼び出しシグネチャ（string, ?DateTimeImmutable）は維持
  // - デフォルト動作（Asia/Tokyo 現在時刻を基準にした営業日）は従来と同じ

  // business_day_start を bootstrap 形式の配列に変換
  $storeRow = ['business_day_start' => $businessDayStart];

  // now 未指定時は bootstrap 側に任せる（jst_now() を使用）
  if ($now === null) {
    return business_date_for_store($storeRow, null);
  }

  // 従来同様 Asia/Tokyo で判定したいので、DateTimeImmutable から JST の DateTime を生成
  $tz = new DateTimeZone('Asia/Tokyo');
  $dt = new DateTime($now->format('Y-m-d H:i:s'), $tz);

  return business_date_for_store($storeRow, $dt);
}
function att_has_is_late(PDO $pdo): bool {
  try {
    $st = $pdo->query("SHOW COLUMNS FROM attendances LIKE 'is_late'");
    return (bool)$st->fetch(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    return false;
  }
}

function att_has_column(PDO $pdo, string $table, string $column): bool {
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

function att_has_table(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("SHOW TABLES LIKE ?");
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function att_has_view_or_table(PDO $pdo, string $name): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $st->execute([$name]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

function att_term_range(string $ym, string $term): array {
  if (!preg_match('/^\d{4}-\d{2}$/', $ym)) {
    $ym = (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m');
  }

  $y = (int)substr($ym, 0, 4);
  $m = (int)substr($ym, 5, 2);
  $from = new DateTime(sprintf('%04d-%02d-01', $y, $m), new DateTimeZone('Asia/Tokyo'));
  $to = clone $from;

  if ($term === 'second') {
    $from->setDate($y, $m, 16);
    $to->modify('last day of this month');
  } else {
    $from->setDate($y, $m, 1);
    $to->setDate($y, $m, 15);
  }

  return [$from->format('Y-m-d'), $to->format('Y-m-d')];
}

function att_fetch_term_report(PDO $pdo, int $storeId, string $fromYmd, string $toYmd): array {
  if ($storeId <= 0) {
    return [];
  }

  $hasUserShopTag = att_has_column($pdo, 'users', 'shop_tag');
  $hasStoreUsersStaffCode = att_has_column($pdo, 'store_users', 'staff_code');
  $hasStoreUsersEmployment = att_has_column($pdo, 'store_users', 'employment_type');
  $hasCastProfilesShopTag = att_has_column($pdo, 'cast_profiles', 'shop_tag');
  $hasCastProfilesEmployment = att_has_column($pdo, 'cast_profiles', 'employment_type');
  $hasCastProfilesStoreId = att_has_column($pdo, 'cast_profiles', 'store_id');
  $hasCastShiftPlans = att_has_table($pdo, 'cast_shift_plans');

  $shopParts = [];
  if ($hasStoreUsersStaffCode) $shopParts[] = "NULLIF(TRIM(su.staff_code), '')";
  if ($hasCastProfilesShopTag) $shopParts[] = "NULLIF(TRIM(cp.shop_tag), '')";
  if ($hasUserShopTag) $shopParts[] = "NULLIF(TRIM(u.shop_tag), '')";
  $shopExpr = $shopParts ? "COALESCE(" . implode(', ', $shopParts) . ", '')" : "''";

  $employmentParts = [];
  if ($hasStoreUsersEmployment) $employmentParts[] = "NULLIF(su.employment_type, '')";
  if ($hasCastProfilesEmployment) $employmentParts[] = "NULLIF(cp.employment_type, '')";
  $employmentParts[] = "NULLIF(u.employment_type, '')";
  $employmentExpr = "COALESCE(" . implode(', ', $employmentParts) . ", '')";

  $storeUsersJoin = ($hasStoreUsersStaffCode || $hasStoreUsersEmployment)
    ? "LEFT JOIN store_users su ON su.user_id=u.id AND su.store_id=ur.store_id"
    : "";
  $castProfilesOn = $hasCastProfilesStoreId
    ? "cp.user_id=u.id AND (cp.store_id=ur.store_id OR cp.store_id IS NULL)"
    : "cp.user_id=u.id";
  $castProfilesJoin = ($hasCastProfilesShopTag || $hasCastProfilesEmployment)
    ? "LEFT JOIN cast_profiles cp ON {$castProfilesOn}"
    : "";

  $planAggSql = "
    SELECT
      pd.user_id,
      COUNT(*) AS planned_days,
      SUM(
        CASE
          WHEN ad.user_id IS NULL THEN 1
          WHEN ad.clock_in IS NULL AND ad.clock_out IS NULL THEN 1
          ELSE 0
        END
      ) AS absent_days
    FROM (
      SELECT
        user_id,
        business_date
      FROM cast_shift_plans
      WHERE store_id=?
        AND business_date BETWEEN ? AND ?
        AND status='planned'
        AND is_off=0
      GROUP BY user_id, business_date
    ) pd
    LEFT JOIN (
      SELECT
        user_id,
        business_date,
        MAX(clock_in) AS clock_in,
        MAX(clock_out) AS clock_out
      FROM attendances
      WHERE store_id=?
        AND business_date BETWEEN ? AND ?
      GROUP BY user_id, business_date
    ) ad
      ON ad.user_id=pd.user_id
     AND ad.business_date=pd.business_date
    GROUP BY pd.user_id
  ";

  $planAggJoin = $hasCastShiftPlans
    ? "LEFT JOIN ({$planAggSql}) pa ON pa.user_id=u.id"
    : "LEFT JOIN (SELECT NULL AS user_id, 0 AS planned_days, 0 AS absent_days) pa ON pa.user_id=u.id";

  $sql = "
    SELECT
      u.id AS user_id,
      u.display_name,
      u.is_active,
      {$employmentExpr} AS employment,
      {$shopExpr} AS shop_tag,
      COALESCE(aa.attendance_days, 0) AS attendance_days,
      COALESCE(aa.worked_minutes, 0) AS worked_minutes,
      COALESCE(aa.late_count, 0) AS late_count,
      COALESCE(aa.incomplete_days, 0) AS incomplete_days,
      COALESCE(aa.note_days, 0) AS note_days,
      COALESCE(pa.planned_days, 0) AS planned_days,
      COALESCE(pa.absent_days, 0) AS absent_days
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    JOIN users u ON u.id=ur.user_id AND u.is_active=1
    {$storeUsersJoin}
    {$castProfilesJoin}
    LEFT JOIN (
      SELECT
        ad.user_id,
        SUM(ad.present_flag) AS attendance_days,
        SUM(ad.worked_minutes) AS worked_minutes,
        SUM(ad.is_late) AS late_count,
        SUM(ad.incomplete_flag) AS incomplete_days,
        SUM(ad.has_note) AS note_days
      FROM (
        SELECT
          user_id,
          business_date,
          SUM(
            CASE
              WHEN clock_in IS NOT NULL AND clock_out IS NOT NULL
                THEN GREATEST(TIMESTAMPDIFF(MINUTE, clock_in, clock_out), 0)
              ELSE 0
            END
          ) AS worked_minutes,
          MAX(CASE WHEN clock_in IS NOT NULL OR clock_out IS NOT NULL THEN 1 ELSE 0 END) AS present_flag,
          MAX(COALESCE(is_late, 0)) AS is_late,
          MAX(
            CASE
              WHEN (clock_in IS NOT NULL AND clock_out IS NULL)
                OR (clock_in IS NULL AND clock_out IS NOT NULL)
                THEN 1
              ELSE 0
            END
          ) AS incomplete_flag,
          MAX(CASE WHEN COALESCE(NULLIF(TRIM(note), ''), '') <> '' THEN 1 ELSE 0 END) AS has_note
        FROM attendances
        WHERE store_id=?
          AND business_date BETWEEN ? AND ?
        GROUP BY user_id, business_date
      ) ad
      GROUP BY ad.user_id
    ) aa ON aa.user_id=u.id
    {$planAggJoin}
    WHERE ur.store_id=?
    GROUP BY
      u.id,
      u.display_name,
      u.is_active,
      employment,
      shop_tag,
      attendance_days,
      worked_minutes,
      late_count,
      incomplete_days,
      note_days,
      planned_days,
      absent_days
    ORDER BY
      u.is_active DESC,
      CASE WHEN shop_tag='' THEN 1 ELSE 0 END ASC,
      CASE WHEN CAST(shop_tag AS UNSIGNED) > 0 OR shop_tag='0' THEN CAST(shop_tag AS UNSIGNED) ELSE 999999 END ASC,
      shop_tag ASC,
      u.display_name ASC,
      u.id ASC
  ";

  $params = [$storeId, $fromYmd, $toYmd];
  if ($hasCastShiftPlans) {
    $params = array_merge($params, [$storeId, $fromYmd, $toYmd, $storeId, $fromYmd, $toYmd]);
  }
  $params[] = $storeId;

  $st = $pdo->prepare($sql);
  $st->execute($params);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}
/**
 * 一覧：その日の cast を並べて、attendance を left join
 * 返すキー（index.phpが期待している）
 *  - cast_id, name, employment, shop_tag, planned_start
 *  - in_at, out_at, attendance_status, is_late, memo
 */
function att_get_daily_rows(PDO $pdo, int $store_id, string $date): array {
  if ($store_id <= 0) return [];

  $castEmploymentFilterView = "COALESCE(c.shop_tag, '') <> ''";

  if (att_has_view_or_table($pdo, 'v_store_casts_active')) {
    try {
      $st = $pdo->prepare("
        SELECT
          c.user_id AS cast_id,
          c.display_name AS name,
          c.employment_type AS employment,
          COALESCE(c.shop_tag, '') AS shop_tag,
          ws.start_time AS planned_start,
          a.clock_in AS in_at,
          a.clock_out AS out_at,
          a.status AS attendance_status,
          COALESCE(a.is_late, 0) AS is_late,
          a.note AS memo
        FROM v_store_casts_active c
        LEFT JOIN attendances a
          ON a.user_id = c.user_id
         AND a.store_id = c.store_id
         AND a.business_date = ?
        LEFT JOIN cast_week ws
          ON ws.user_id = c.user_id
         AND ws.store_id = c.store_id
         AND ws.work_date = ?
        WHERE c.store_id = ?
          AND {$castEmploymentFilterView}
        ORDER BY
          CASE WHEN COALESCE(c.shop_tag, '') = '' THEN 1 ELSE 0 END ASC,
          CASE
            WHEN CAST(COALESCE(c.shop_tag, '') AS UNSIGNED) > 0 OR COALESCE(c.shop_tag, '') = '0'
              THEN LPAD(CAST(COALESCE(c.shop_tag, '') AS UNSIGNED), 6, '0')
            ELSE COALESCE(c.shop_tag, '')
          END ASC,
          c.display_name ASC,
          c.user_id ASC
      ");
      $st->execute([$date, $date, $store_id]);
      $rows = $st->fetchAll(PDO::FETCH_ASSOC);
      return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
      try {
        $st = $pdo->prepare("
          SELECT
            c.user_id AS cast_id,
            c.display_name AS name,
            c.employment_type AS employment,
            COALESCE(c.shop_tag, '') AS shop_tag,
            NULL AS planned_start,
            a.clock_in AS in_at,
            a.clock_out AS out_at,
            a.status AS attendance_status,
            COALESCE(a.is_late, 0) AS is_late,
            a.note AS memo
          FROM v_store_casts_active c
          LEFT JOIN attendances a
            ON a.user_id = c.user_id
           AND a.store_id = c.store_id
           AND a.business_date = ?
          WHERE c.store_id = ?
            AND {$castEmploymentFilterView}
          ORDER BY
            CASE WHEN COALESCE(c.shop_tag, '') = '' THEN 1 ELSE 0 END ASC,
            CASE
              WHEN CAST(COALESCE(c.shop_tag, '') AS UNSIGNED) > 0 OR COALESCE(c.shop_tag, '') = '0'
                THEN LPAD(CAST(COALESCE(c.shop_tag, '') AS UNSIGNED), 6, '0')
              ELSE COALESCE(c.shop_tag, '')
            END ASC,
            c.display_name ASC,
            c.user_id ASC
        ");
        $st->execute([$date, $store_id]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        return is_array($rows) ? $rows : [];
      } catch (Throwable $e2) {
        // fallback below
      }
    }
  }

  $hasUserShopTag = att_has_column($pdo, 'users', 'shop_tag');
  $hasStoreUsersStaffCode = att_has_column($pdo, 'store_users', 'staff_code');
  $hasStoreUsersEmployment = att_has_column($pdo, 'store_users', 'employment_type');
  $hasCastProfilesShopTag = att_has_column($pdo, 'cast_profiles', 'shop_tag');
  $hasCastProfilesEmployment = att_has_column($pdo, 'cast_profiles', 'employment_type');
  $hasCastProfilesStoreId = att_has_column($pdo, 'cast_profiles', 'store_id');

  $shopParts = [];
  if ($hasStoreUsersStaffCode) $shopParts[] = "NULLIF(TRIM(su.staff_code), '')";
  if ($hasCastProfilesShopTag) $shopParts[] = "NULLIF(TRIM(cp.shop_tag), '')";
  if ($hasUserShopTag) $shopParts[] = "NULLIF(TRIM(u.shop_tag), '')";
  $shopExpr = $shopParts ? "COALESCE(" . implode(', ', $shopParts) . ", '')" : "''";

  $employmentParts = [];
  if ($hasStoreUsersEmployment) $employmentParts[] = "NULLIF(su.employment_type, '')";
  if ($hasCastProfilesEmployment) $employmentParts[] = "NULLIF(cp.employment_type, '')";
  $employmentParts[] = "NULLIF(u.employment_type, '')";
  $employmentExpr = "COALESCE(" . implode(', ', $employmentParts) . ", '')";
  $storeUsersJoin = ($hasStoreUsersStaffCode || $hasStoreUsersEmployment)
    ? "LEFT JOIN store_users su ON su.user_id=u.id AND su.store_id=ur.store_id"
    : "";
  $castProfilesOn = $hasCastProfilesStoreId
    ? "cp.user_id=u.id AND (cp.store_id=ur.store_id OR cp.store_id IS NULL)"
    : "cp.user_id=u.id";
  $castProfilesJoin = ($hasCastProfilesShopTag || $hasCastProfilesEmployment)
    ? "LEFT JOIN cast_profiles cp ON {$castProfilesOn}"
    : "";

  $shopEmpty = "_utf8mb4'' COLLATE utf8mb4_bin";
  $shopZero = "_utf8mb4'0' COLLATE utf8mb4_bin";
  $shopSelect = "{$shopExpr} AS shop_tag";
  $castEmploymentFilter = "{$shopExpr} <> {$shopEmpty}";
  $shopOrder  = "
    CASE
      WHEN {$shopExpr} = {$shopEmpty} THEN 1
      WHEN CAST({$shopExpr} AS UNSIGNED) > 0 OR {$shopExpr} = {$shopZero} THEN 0
      ELSE 1
    END ASC,
    CASE
      WHEN CAST({$shopExpr} AS UNSIGNED) > 0 OR {$shopExpr} = {$shopZero}
        THEN LPAD(CAST({$shopExpr} AS UNSIGNED), 6, '0')
      ELSE {$shopExpr}
    END ASC,
  ";

  // cast_week が無い環境でも動くように try/catch で fallback
  $sql = "
    SELECT
      u.id AS cast_id,
      u.display_name AS name,
      {$employmentExpr} AS employment,
      {$shopSelect},
      ws.start_time AS planned_start,
      a.clock_in AS in_at,
      a.clock_out AS out_at,
      a.status AS attendance_status,
      COALESCE(a.is_late, 0) AS is_late,
      a.note AS memo
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id AND r.code='cast'
    JOIN users u ON u.id = ur.user_id AND u.is_active=1
    {$storeUsersJoin}
    {$castProfilesJoin}
    LEFT JOIN attendances a
      ON a.user_id=u.id AND a.store_id=ur.store_id AND a.business_date=?
    LEFT JOIN cast_week ws
      ON ws.user_id=u.id AND ws.store_id=ur.store_id AND ws.work_date=?
    WHERE ur.store_id=?
      AND {$castEmploymentFilter}
    ORDER BY
      {$shopOrder}
      u.display_name ASC
  ";

  try {
    $st = $pdo->prepare($sql);
    $st->execute([$date, $date, $store_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  } catch (Throwable $e) {
    // cast_week が無い場合の簡易版
    $sql2 = "
      SELECT
        u.id AS cast_id,
        u.display_name AS name,
        {$employmentExpr} AS employment,
        {$shopSelect},
        NULL AS planned_start,
        a.clock_in AS in_at,
        a.clock_out AS out_at,
        a.status AS attendance_status,
        COALESCE(a.is_late, 0) AS is_late,
        a.note AS memo
      FROM user_roles ur
      JOIN roles r ON r.id = ur.role_id AND r.code='cast'
      JOIN users u ON u.id = ur.user_id AND u.is_active=1
      {$storeUsersJoin}
      {$castProfilesJoin}
      LEFT JOIN attendances a
        ON a.user_id=u.id AND a.store_id=ur.store_id AND a.business_date=?
      WHERE ur.store_id=?
        AND {$castEmploymentFilter}
      ORDER BY
        {$shopOrder}
        u.display_name ASC
    ";
    $st = $pdo->prepare($sql2);
    $st->execute([$date, $store_id]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    return is_array($rows) ? $rows : [];
  }
}

/**
 * toggle IN/OUT/late + memo
 * 返す：index.phpが renderRow() で読む形式に合わせる
 */
function att_toggle_in(PDO $pdo, int $store_id, int $user_id, string $date): array {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, clock_in, status, source_in, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), 'working', 'admin', NOW(), NOW())
      ")->execute([$user_id, $store_id, $date]);
    } else {
      // もうIN済みでOUT無し → IN取り消し
      if (!empty($row['clock_in']) && empty($row['clock_out'])) {
        $pdo->prepare("
          UPDATE attendances
          SET clock_in=NULL, status='scheduled', source_in=NULL, updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      } else {
        // IN無し → IN付与（OUTがあっても、やり直し扱いでOUT消す）
        $pdo->prepare("
          UPDATE attendances
          SET clock_in=NOW(), clock_out=NULL, status='working', source_in='admin', source_out=NULL, updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      }
    }

    $hasLate = att_has_is_late($pdo);
    $selLate = $hasLate ? "COALESCE(is_late,0) AS is_late" : "0 AS is_late";

    $st = $pdo->prepare("
      SELECT clock_in AS in_at, clock_out AS out_at, note AS memo, {$selLate}
      FROM attendances
      WHERE store_id=? AND user_id=? AND business_date=?
      LIMIT 1
    ");
    $st->execute([$store_id, $user_id, $date]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: ['in_at'=>null,'out_at'=>null,'memo'=>null];

    $pdo->commit();
    return [
      'ok' => true,
      'in_at' => $a['in_at'] ?? null,
      'out_at' => $a['out_at'] ?? null,
      'is_late' => (int)($a['is_late'] ?? 0),
      'memo' => $a['memo'] ?? null,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function att_toggle_out(PDO $pdo, int $store_id, int $user_id, string $date): array {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      // OUTだけ押された → OUT登録（status finished）
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, clock_out, status, source_out, created_at, updated_at)
        VALUES (?, ?, ?, NOW(), 'finished', 'admin', NOW(), NOW())
      ")->execute([$user_id, $store_id, $date]);
    } else {
      if (!empty($row['clock_out'])) {
        // もうOUT済み → 取り消し
        $pdo->prepare("
          UPDATE attendances
          SET clock_out=NULL, status=IF(clock_in IS NULL,'scheduled','working'), source_out=NULL, updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      } else {
        // OUT付与（INが無い場合でも付ける）
        $pdo->prepare("
          UPDATE attendances
          SET clock_out=NOW(), status='finished', source_out='admin', updated_at=NOW()
          WHERE id=? LIMIT 1
        ")->execute([(int)$row['id']]);
      }
    }

    $hasLate = att_has_is_late($pdo);
    $selLate = $hasLate ? "COALESCE(is_late,0) AS is_late" : "0 AS is_late";

    $st = $pdo->prepare("
      SELECT clock_in AS in_at, clock_out AS out_at, note AS memo, {$selLate}
      FROM attendances
      WHERE store_id=? AND user_id=? AND business_date=?
      LIMIT 1
    ");
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: ['in_at'=>null,'out_at'=>null,'memo'=>null];

    $pdo->commit();
    return [
      'ok' => true,
      'in_at' => $a['in_at'] ?? null,
      'out_at' => $a['out_at'] ?? null,
      'is_late' => (int)($a['is_late'] ?? 0),
      'memo' => $a['memo'] ?? null,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function att_toggle_late(PDO $pdo, int $store_id, int $user_id, string $date): array {
  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT * FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $row = $st->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
      // レコードが無ければ作って is_late=1
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, status, is_late, created_at, updated_at)
        VALUES (?, ?, ?, 'scheduled', 1, NOW(), NOW())
      ")->execute([$user_id, $store_id, $date]);
      $isLate = 1;
    } else {
      $cur = (int)($row['is_late'] ?? 0);
      $isLate = $cur ? 0 : 1;
      $pdo->prepare("UPDATE attendances SET is_late=?, updated_at=NOW() WHERE id=? LIMIT 1")
          ->execute([$isLate, (int)$row['id']]);
    }

    $st = $pdo->prepare("
      SELECT clock_in AS in_at, clock_out AS out_at, note AS memo, is_late AS is_late
      FROM attendances
      WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1
    ");
    $st->execute([$store_id, $user_id, $date]);
    $a = $st->fetch(PDO::FETCH_ASSOC) ?: ['in_at'=>null,'out_at'=>null,'memo'=>null,'is_late'=>0];

    $pdo->commit();
    return [
      'ok' => true,
      'in_at' => $a['in_at'] ?? null,
      'out_at' => $a['out_at'] ?? null,
      'is_late' => (int)($a['is_late'] ?? 0),
      'memo' => $a['memo'] ?? null,
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function att_save_memo(PDO $pdo, int $store_id, int $user_id, string $date, string $memo): array {
  $memo = mb_substr($memo, 0, 255);

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare("SELECT id FROM attendances WHERE store_id=? AND user_id=? AND business_date=? LIMIT 1");
    $st->execute([$store_id, $user_id, $date]);
    $id = (int)($st->fetchColumn() ?: 0);

    if ($id <= 0) {
      $pdo->prepare("
        INSERT INTO attendances (user_id, store_id, business_date, status, note, created_at, updated_at)
        VALUES (?, ?, ?, 'scheduled', ?, NOW(), NOW())
      ")->execute([$user_id, $store_id, $date, $memo]);
    } else {
      $pdo->prepare("UPDATE attendances SET note=?, updated_at=NOW() WHERE id=? LIMIT 1")
          ->execute([$memo, $id]);
    }

    $pdo->commit();
    return ['ok'=>true];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}
