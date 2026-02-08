<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['admin','manager','super_user']);

$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

function business_date_jst(string $start='05:00'): string {
  $tz = new DateTimeZone('Asia/Tokyo');
  $now = new DateTime('now', $tz);
  $d = $now->format('Y-m-d');
  $startDt = new DateTime($d.' '.$start.':00', $tz);
  if ($now < $startDt) $now->modify('-1 day');
  return $now->format('Y-m-d');
}

/** admin/manager の自店舗 */
function current_admin_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id
    WHERE ur.user_id=?
      AND r.code IN ('admin','manager')
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  $sid = $st->fetchColumn();
  if (!$sid) throw new RuntimeException('このユーザーは店舗に紐付いていません');
  return (int)$sid;
}

$isSuper = has_role('super_user');

$userId = current_user_id();
$bizDate = business_date_jst('05:00');

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? 0);
  if ($storeId <= 0) {
    $storeId = (int)$pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1")->fetchColumn();
  }
} else {
  $storeId = current_admin_store_id($pdo, $userId);
}

$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

$stores = [];
if ($isSuper) {
  $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$st = $pdo->prepare("
  SELECT
    u.id AS user_id,
    u.display_name,
    u.is_active,
    a.business_date,
    a.clock_in,
    a.clock_out,
    a.status,
    a.source_in,
    a.source_out,
    CASE WHEN ui.id IS NULL THEN 0 ELSE 1 END AS has_line
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id
  LEFT JOIN attendances a
    ON a.user_id=u.id
   AND a.store_id=ur.store_id
   AND a.business_date=?
  LEFT JOIN user_identities ui
    ON ui.user_id=u.id AND ui.provider='line' AND ui.is_active=1
  WHERE ur.store_id=?
  ORDER BY
    (a.clock_in IS NULL) ASC,
    a.clock_in ASC,
    u.id ASC
");
$st->execute([$bizDate, $storeId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

render_page_start('本日の出勤');
render_header('本日の出勤', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page">
  <div class="admin-wrap">

    <div class="card">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div>
          <div style="font-weight:1000; font-size:18px;">📋 本日の出勤一覧</div>
          <div class="muted" style="margin-top:4px;">店舗: <?= h($storeName) ?> / 営業日: <?= h($bizDate) ?></div>
        </div>

        <?php if ($isSuper): ?>
        <form method="get" style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
          <select class="btn" name="store_id">
            <?php foreach($stores as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
                <?= h((string)$s['name']) ?><?= ((int)$s['is_active']===1)?'':'（無効）' ?>
              </option>
            <?php endforeach; ?>
          </select>
          <button class="btn btn-primary">表示</button>
        </form>
        <?php endif; ?>
      </div>
    </div>

    <div class="card" style="margin-top:12px;">
      <table class="tbl">
        <thead>
          <tr>
            <th>キャスト</th>
            <th>LINE</th>
            <th>出勤</th>
            <th>退勤</th>
            <th>状態</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr><td colspan="5" class="muted" style="padding:12px;">該当なし</td></tr>
          <?php else: ?>
            <?php foreach($rows as $r): ?>
              <?php
                $hasLine = ((int)$r['has_line'] === 1);
                $stt = (string)($r['status'] ?? 'scheduled');
              ?>
              <tr>
                <td style="font-weight:1000;"><?= h((string)$r['display_name']) ?></td>
                <td>
                  <span class="badge" style="background:<?= $hasLine ? 'rgba(52,211,153,.14)' : 'rgba(239,68,68,.14)' ?>;">
                    <span class="dot" style="background:<?= $hasLine ? 'var(--ok)' : 'var(--ng)' ?>;"></span>
                    <?= $hasLine ? '連携済' : '未連携' ?>
                  </span>
                </td>
                <td><?= h((string)($r['clock_in'] ?? '—')) ?></td>
                <td><?= h((string)($r['clock_out'] ?? '—')) ?></td>
                <td><?= h($stt) ?></td>
              </tr>
            <?php endforeach; ?>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<style>
.admin-wrap{ width:min(1180px, calc(100% - 20px)); margin:0 auto; }
.card{ background:rgba(255,255,255,.06); border:1px solid rgba(255,255,255,.12); border-radius:16px; padding:14px; }
.tbl{ width:100%; border-collapse:separate; border-spacing:0; }
.tbl th,.tbl td{ padding:10px; border-bottom:1px solid rgba(255,255,255,.10); text-align:left; }
.badge{ display:inline-flex; align-items:center; gap:8px; padding:6px 10px; border-radius:999px; border:1px solid rgba(255,255,255,.12); }
.dot{ width:8px; height:8px; border-radius:50%; }
.btn{ padding:8px 10px; border-radius:12px; border:1px solid rgba(255,255,255,.14); background:rgba(255,255,255,.06); color:inherit; }
.btn-primary{ background:rgba(52,211,153,.14); border-color:rgba(52,211,153,.32); }
.muted{ opacity:.75; }
</style>

<?php render_page_end(); ?>