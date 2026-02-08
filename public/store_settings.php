<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['admin','super_user']);

$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/* -------------------------
   role helper
------------------------- */
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
$isSuper = has_role('super_user');

/* -------------------------
   CSRF (最小)
------------------------- */
function csrf_token(): string {
  if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
  }
  return (string)$_SESSION['csrf_token'];
}
function csrf_verify(): void {
  $t = (string)($_POST['csrf_token'] ?? '');
  $e = (string)($_SESSION['csrf_token'] ?? '');
  if ($t === '' || $e === '' || !hash_equals($e, $t)) {
    throw new RuntimeException('CSRF token mismatch');
  }
}

/* -------------------------
   admin の自店舗固定
------------------------- */
function current_admin_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.code = 'admin'
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  $sid = $st->fetchColumn();
  if (!$sid) throw new RuntimeException('この管理者は店舗に紐付いていません');
  return (int)$sid;
}

/* -------------------------
   stores 一覧（superのみ）
------------------------- */
$stores = [];
if ($isSuper) {
  $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")
    ->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* -------------------------
   対象 store_id 決定
------------------------- */
$storeId = 0;

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? 0);
  if ($storeId <= 0) {
    $st = $pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1");
    $storeId = (int)$st->fetchColumn();
  }
  if ($storeId <= 0) throw new RuntimeException('有効な店舗が存在しません');
} else {
  $storeId = current_admin_store_id($pdo, (int)current_user_id());
}

/* -------------------------
   GET: 現在値
------------------------- */
function fetch_store(PDO $pdo, int $storeId): array {
  $st = $pdo->prepare("
    SELECT
      id, code, name, is_active,
      business_day_start,
      open_time, close_time_weekday, close_time_weekend,
      close_is_next_day_weekday, close_is_next_day_weekend,
      weekend_dow_mask,
      lat, lon, radius_m
    FROM stores
    WHERE id=?
    LIMIT 1
  ");
  $st->execute([$storeId]);
  $row = $st->fetch(PDO::FETCH_ASSOC);
  return is_array($row) ? $row : [];
}

$err = '';
$msg = '';

/* -------------------------
   POST: 更新
------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify();

    // super_user だけ store_id をPOSTから受けてもOK（adminは固定）
    $storeIdP = $storeId;
    if ($isSuper) {
      $storeIdP = (int)($_POST['store_id'] ?? $storeId);
      if ($storeIdP <= 0) throw new RuntimeException('store_id invalid');
    }

    // 入力
    $businessDayStart = trim((string)($_POST['business_day_start'] ?? '06:00:00'));
    $openTime         = trim((string)($_POST['open_time'] ?? '20:00:00'));
    $closeWk          = trim((string)($_POST['close_time_weekday'] ?? '02:30:00'));
    $closeWe          = trim((string)($_POST['close_time_weekend'] ?? '05:00:00'));

    $nextWk = (int)($_POST['close_is_next_day_weekday'] ?? 1);
    $nextWe = (int)($_POST['close_is_next_day_weekend'] ?? 1);

    $mask   = (int)($_POST['weekend_dow_mask'] ?? 96);

    $lat = trim((string)($_POST['lat'] ?? ''));
    $lon = trim((string)($_POST['lon'] ?? ''));
    $radius = (int)($_POST['radius_m'] ?? 150);
    if ($radius <= 0) $radius = 150;

    $latVal = ($lat === '') ? null : (float)$lat;
    $lonVal = ($lon === '') ? null : (float)$lon;

    // 簡易バリデーション（形式チェックはゆるめ）
    if ($businessDayStart === '' || $openTime === '' || $closeWk === '' || $closeWe === '') {
      throw new RuntimeException('時刻が未入力です');
    }

    $st = $pdo->prepare("
      UPDATE stores
      SET
        business_day_start=?,
        open_time=?,
        close_time_weekday=?,
        close_time_weekend=?,
        close_is_next_day_weekday=?,
        close_is_next_day_weekend=?,
        weekend_dow_mask=?,
        lat=?,
        lon=?,
        radius_m=?
      WHERE id=?
      LIMIT 1
    ");
    $st->execute([
      $businessDayStart,
      $openTime,
      $closeWk,
      $closeWe,
      $nextWk,
      $nextWe,
      $mask,
      $latVal,
      $lonVal,
      $radius,
      $storeIdP
    ]);

    $msg = '更新しました';
    // 表示中のstoreIdも更新
    if ($isSuper) $storeId = $storeIdP;

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$store = fetch_store($pdo, $storeId);
if (!$store) {
  throw new RuntimeException('店舗が見つかりません');
}

/* -------------------------
   UI
------------------------- */
render_page_start('店舗設定');
render_header('店舗設定', [
  'back_href'  => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page">
  <div class="admin-wrap">

    <?php if ($err): ?>
      <div class="card" style="border-color:rgba(251,113,133,.45);">
        <?= h($err) ?>
      </div>
    <?php endif; ?>

    <?php if ($msg): ?>
      <div class="card" style="border-color:rgba(52,211,153,.35);">
        ✅ <?= h($msg) ?>
      </div>
    <?php endif; ?>

    <div class="card">
      <div class="admin-top">
        <div>
          <div style="font-weight:1000; font-size:18px;">🏬 店舗設定</div>
          <div class="muted" style="margin-top:4px;">
            営業開始/終了ルール・位置情報（LINE出勤）を設定
          </div>
        </div>

        <?php if ($isSuper): ?>
          <form method="get" class="searchRow" action="/seika-app/public/store_settings.php">
            <select class="btn" name="store_id">
              <?php foreach ($stores as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===(int)$storeId)?'selected':'' ?>>
                  <?= h((string)$s['name']) ?><?= ((int)$s['is_active']===1)?'':'（無効）' ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-primary" type="submit">表示</button>
          </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-top:14px;">
      <div style="font-weight:1000; margin-bottom:10px;">🕒 営業時間ルール</div>

      <form method="post" class="searchRow" style="align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <?php if ($isSuper): ?>
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <?php endif; ?>

        <div style="min-width:180px;">
          <label class="muted">営業日切替（business_day_start）</label><br>
          <input class="btn" name="business_day_start" value="<?= h((string)($store['business_day_start'] ?? '06:00:00')) ?>" placeholder="06:00:00" style="width:100%;">
        </div>

        <div style="min-width:160px;">
          <label class="muted">開店</label><br>
          <input class="btn" name="open_time" value="<?= h((string)($store['open_time'] ?? '20:00:00')) ?>" placeholder="20:00:00" style="width:100%;">
        </div>

        <div style="min-width:180px;">
          <label class="muted">閉店（平日）</label><br>
          <input class="btn" name="close_time_weekday" value="<?= h((string)($store['close_time_weekday'] ?? '02:30:00')) ?>" placeholder="02:30:00" style="width:100%;">
        </div>

        <div style="min-width:120px;">
          <label class="muted">翌日？（平日）</label><br>
          <select class="btn" name="close_is_next_day_weekday" style="width:100%;">
            <option value="1" <?= ((int)($store['close_is_next_day_weekday'] ?? 1)===1)?'selected':'' ?>>翌日</option>
            <option value="0" <?= ((int)($store['close_is_next_day_weekday'] ?? 1)===0)?'selected':'' ?>>当日</option>
          </select>
        </div>

        <div style="min-width:180px;">
          <label class="muted">閉店（週末）</label><br>
          <input class="btn" name="close_time_weekend" value="<?= h((string)($store['close_time_weekend'] ?? '05:00:00')) ?>" placeholder="05:00:00" style="width:100%;">
        </div>

        <div style="min-width:120px;">
          <label class="muted">翌日？（週末）</label><br>
          <select class="btn" name="close_is_next_day_weekend" style="width:100%;">
            <option value="1" <?= ((int)($store['close_is_next_day_weekend'] ?? 1)===1)?'selected':'' ?>>翌日</option>
            <option value="0" <?= ((int)($store['close_is_next_day_weekend'] ?? 1)===0)?'selected':'' ?>>当日</option>
          </select>
        </div>

        <div style="min-width:160px;">
          <label class="muted">週末DOWマスク（既定:金土=96）</label><br>
          <input class="btn" name="weekend_dow_mask" inputmode="numeric" value="<?= h((string)($store['weekend_dow_mask'] ?? 96)) ?>" style="width:100%;">
        </div>

        <button class="btn btn-primary" type="submit">更新</button>
      </form>

      <div class="muted" style="margin-top:10px; font-size:12px;">
        ※ 週末は通常「金土」を想定（mask=96）。将来、祝前日など拡張する時にここを使う。
      </div>
    </div>

    <div class="card" style="margin-top:14px;">
      <div style="font-weight:1000; margin-bottom:10px;">📍 店舗位置（LINE出勤の距離判定）</div>

      <form method="post" class="searchRow" style="align-items:flex-end; flex-wrap:wrap;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <?php if ($isSuper): ?>
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <?php endif; ?>

        <div style="min-width:200px;">
          <label class="muted">緯度 lat</label><br>
          <input class="btn" name="lat" value="<?= h((string)($store['lat'] ?? '')) ?>" placeholder="例: 35.681236" style="width:100%;">
        </div>
        <div style="min-width:200px;">
          <label class="muted">経度 lon</label><br>
          <input class="btn" name="lon" value="<?= h((string)($store['lon'] ?? '')) ?>" placeholder="例: 139.767125" style="width:100%;">
        </div>
        <div style="min-width:160px;">
          <label class="muted">許可半径(m)</label><br>
          <input class="btn" name="radius_m" inputmode="numeric" value="<?= h((string)($store['radius_m'] ?? 150)) ?>" style="width:100%;">
        </div>

        <button class="btn btn-primary" type="submit">更新</button>
      </form>

      <div class="muted" style="margin-top:10px; font-size:12px;">
        ※ lat/lon未設定だとLINE出勤は「店舗位置未設定」と返すようにするのが安全。
      </div>
    </div>

  </div>
</div>

<?php render_page_end(); ?>