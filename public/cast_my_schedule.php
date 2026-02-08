<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['cast','admin','manager','super_user']); // castが主役だが管理者が見てもOK

$pdo = db();

if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }
function has_role(string $role): bool { return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true); }

$userId = (int)(function_exists('current_user_id') ? current_user_id() : ($_SESSION['user_id'] ?? 0));

function resolve_cast_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=? AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }
function dow0(DateTime $d): int { return (int)$d->format('w'); }
function week_start_date(string $anyDateYmd, int $weekStartDow0): string {
  $dt = new DateTime($anyDateYmd, new DateTimeZone('Asia/Tokyo'));
  $curDow = dow0($dt);
  $diff = ($curDow - $weekStartDow0 + 7) % 7;
  if ($diff > 0) $dt->modify("-{$diff} days");
  return $dt->format('Y-m-d');
}
function week_dates(string $weekStartYmd): array {
  $dt = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) { $out[] = $dt->format('Y-m-d'); $dt->modify('+1 day'); }
  return $out;
}

$storeId = resolve_cast_store_id($pdo, $userId);
if ($storeId <= 0) exit('所属店舗が未設定です（管理者に確認してください）');

$st = $pdo->prepare("SELECT id,name,weekly_holiday_dow FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$store = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$store) exit('店舗が見つかりません');

$holidayDow = $store['weekly_holiday_dow'];
$holidayDow = ($holidayDow === null) ? null : (int)$holidayDow;
$weekStartDow0 = ($holidayDow === null) ? 1 : (($holidayDow + 1) % 7);

$today = jst_now()->format('Y-m-d');
$weekStart = week_start_date($today, $weekStartDow0);
$dates = week_dates($weekStart);

// 予定
$st = $pdo->prepare("
  SELECT business_date, start_time, is_off
  FROM cast_shift_plans
  WHERE store_id=? AND user_id=?
    AND business_date BETWEEN ? AND ?
    AND status='planned'
");
$st->execute([$storeId, $userId, $dates[0], $dates[6]]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$map = [];
foreach ($rows as $r) {
  $d = (string)$r['business_date'];
  $map[$d] = [
    'is_off' => ((int)$r['is_off'] === 1),
    'start'  => $r['start_time'] ? substr((string)$r['start_time'],0,5) : '',
  ];
}

$jpW = ['日','月','火','水','木','金','土'];
function dow_label(string $ymd, array $jpW): string {
  $dt = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  return $jpW[(int)$dt->format('w')];
}

render_page_start('今週の予定');
render_header('今週の予定', [
  'back_href' => '/seika-app/public/dashboard_cast.php',
  'back_label'=> '← 戻る'
]);
?>
<div class="page">
  <div class="admin-wrap" style="max-width:560px;">
    <div class="card">
      <div style="font-weight:1000; font-size:18px;">🗓 今週の予定</div>
      <div class="muted" style="margin-top:4px;">
        <?= h((string)$store['name']) ?> / <?= h($weekStart) ?> 〜 <?= h($dates[6]) ?>
      </div>
    </div>

    <?php foreach ($dates as $d): ?>
      <?php
        $p = $map[$d] ?? ['is_off'=>false,'start'=>''];
        $label = dow_label($d, $jpW);
        $text = '--';
        if ($p['is_off']) $text = '休み';
        elseif ($p['start'] !== '') $text = $p['start'] . '〜';
      ?>
      <div class="card" style="display:flex; justify-content:space-between; align-items:center;">
        <div style="font-weight:900;"><?= h(substr($d,5)) ?>（<?= h($label) ?>）</div>
        <div class="<?= ($text==='休み')?'muted':'' ?>" style="font-weight:900;"><?= h($text) ?></div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<?php render_page_end(); ?>