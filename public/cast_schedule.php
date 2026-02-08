<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();

$pdo = db();
$userId = (int)current_user_id();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/** castの所属store_id（1店舗前提） */
function resolve_cast_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id=ur.role_id AND r.code='cast'
    WHERE ur.user_id=?
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

$storeId = resolve_cast_store_id($pdo, $userId);
if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が未設定です。管理者に所属店舗を設定してもらってください。');
}

/** 週開始（月曜） */
$base = (string)($_GET['week'] ?? '');
$dt = $base !== '' ? new DateTime($base) : new DateTime('today', new DateTimeZone('Asia/Tokyo'));
$w = (int)$dt->format('N'); // 1..7
$weekStart = (clone $dt)->modify('-' . ($w - 1) . ' days');
$dates = [];
for ($i=0; $i<7; $i++) {
  $d = (clone $weekStart)->modify("+{$i} days");
  $dates[] = $d->format('Y-m-d');
}
$weekStartStr = $weekStart->format('Y-m-d');
$weekEndStr   = (clone $weekStart)->modify('+6 days')->format('Y-m-d');

/** 予定取得（attendances.status='scheduled' を予定として扱う） */
$st = $pdo->prepare("
  SELECT business_date, status, note
  FROM attendances
  WHERE user_id=?
    AND store_id=?
    AND business_date BETWEEN ? AND ?
");
$st->execute([$userId, $storeId, $weekStartStr, $weekEndStr]);

$map = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
  $map[(string)$r['business_date']] = $r;
}

render_page_start('出勤予定');
render_header('出勤予定', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label'=> '← 戻る',
]);
?>
<div class="page">
  <div class="admin-wrap">

    <div class="card">
      <div style="display:flex; gap:10px; align-items:center; justify-content:space-between; flex-wrap:wrap;">
        <div>
          <div style="font-weight:1000; font-size:18px;">🗓 出勤予定（<?=h($weekStartStr)?>〜<?=h($weekEndStr)?>）</div>
          <div class="muted" style="margin-top:4px;">予定ONにすると「店長側の本日の出勤予定」に出せます</div>
        </div>
        <div style="display:flex; gap:8px; flex-wrap:wrap;">
          <a class="btn" href="?week=<?=h((clone $weekStart)->modify('-7 days')->format('Y-m-d'))?>">← 前週</a>
          <a class="btn" href="?week=<?=h((new DateTime('today'))->format('Y-m-d'))?>">今週</a>
          <a class="btn" href="?week=<?=h((clone $weekStart)->modify('+7 days')->format('Y-m-d'))?>">次週 →</a>
        </div>
      </div>
    </div>

    <div class="card" style="margin-top:14px;">
      <form id="frm" method="post" action="/seika-app/public/api/cast_schedule_save.php">
        <input type="hidden" name="week_start" value="<?=h($weekStartStr)?>">
        <input type="hidden" name="store_id" value="<?=$storeId?>">

        <div class="sched-grid">
          <?php
            $jpW = ['日','月','火','水','木','金','土'];
            foreach ($dates as $d):
              $dt2 = new DateTime($d);
              $w2 = (int)$dt2->format('w'); // 0..6 (Sun..Sat)
              $row = $map[$d] ?? null;
              $on = $row && (string)$row['status'] === 'scheduled';
              $note = $row ? (string)($row['note'] ?? '') : '';
          ?>
          <div class="sched-card">
            <div class="top">
              <div class="date"><?=h($d)?> <span class="muted">(<?=h($jpW[$w2])?>)</span></div>

              <label class="toggle">
                <input type="checkbox" name="on[<?=h($d)?>]" value="1" <?= $on ? 'checked' : '' ?>>
                <span>予定</span>
              </label>
            </div>

            <div style="margin-top:8px;">
              <input class="btn" style="width:100%;" name="note[<?=h($d)?>]" value="<?=h($note)?>" placeholder="メモ（任意）例: 21:00〜 / 遅刻注意 など">
            </div>
          </div>
          <?php endforeach; ?>
        </div>

        <div style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
          <button class="btn btn-primary" type="submit">保存</button>
          <div class="muted" style="font-size:12px;">※ 出勤/退勤実績はボタン/LINEで別管理（予定を上書きしません）</div>
        </div>
      </form>
    </div>

  </div>
</div>

<style>
.sched-grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:10px;
}
.sched-card{
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  border-radius:14px;
  padding:12px;
  min-width:0;
}
.sched-card .top{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
}
.date{ font-weight:1000; }
.toggle{
  display:inline-flex;
  align-items:center;
  gap:8px;
  border:1px solid var(--line);
  border-radius:999px;
  padding:6px 10px;
  background:rgba(255,255,255,.06);
  white-space:nowrap;
}
.toggle input{ transform:scale(1.1); }
@media (max-width: 420px){
  .sched-grid{ grid-template-columns:1fr; }
}
</style>
<?php render_page_end(); ?>