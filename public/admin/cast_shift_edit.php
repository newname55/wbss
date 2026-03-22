<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';

$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** CSRF */
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  return (string)$_SESSION['_csrf'];
}
function csrf_verify(?string $token): void {
  if (!$token || empty($_SESSION['_csrf']) || !hash_equals((string)$_SESSION['_csrf'], (string)$token)) {
    http_response_code(403);
    exit('csrf');
  }
}

/** store_id 解決（admin/cast_edit.php と同系統） */
function resolve_store_id(PDO $pdo): int {
  if (function_exists('current_store_id')) {
    $sid = (int)current_store_id();
    if ($sid > 0) return $sid;
  }
  if (function_exists('require_store_selected')) {
    try {
      $rf = new ReflectionFunction('require_store_selected');
      $need = $rf->getNumberOfRequiredParameters();
      $sid = ($need >= 1) ? (int)require_store_selected($pdo) : (int)require_store_selected();
      if ($sid > 0) return $sid;
    } catch (Throwable $e) {}
  }
  $sid = (int)($_GET['store_id'] ?? 0);
  if ($sid <= 0) $sid = (int)($_SESSION['store_id'] ?? 0);
  if ($sid <= 0) {
    header('Location: /wbss/public/store_select.php?next=' . urlencode('/wbss/public/admin/cast_edit.php'));
    exit;
  }
  $_SESSION['store_id'] = $sid;
  return $sid;
}

/** 週計算（月曜起点） */
function week_start_ymd(string $ymd): string {
  $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  $dow = (int)$d->format('N'); // 1=Mon..7=Sun
  $d->modify('-' . ($dow - 1) . ' days');
  return $d->format('Y-m-d');
}
function week_dates(string $weekStartYmd): array {
  $d = new DateTime($weekStartYmd, new DateTimeZone('Asia/Tokyo'));
  $out = [];
  for ($i=0; $i<7; $i++) {
    $out[] = $d->format('Y-m-d');
    $d->modify('+1 day');
  }
  return $out;
}
function now_jst_ymd(): string {
  return (new DateTime('now', new DateTimeZone('Asia/Tokyo')))->format('Y-m-d');
}

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

$store_id = resolve_store_id($pdo);
$user_id  = (int)($_GET['user_id'] ?? $_POST['user_id'] ?? 0);
$week     = (string)($_GET['week'] ?? $_POST['week'] ?? now_jst_ymd());
$weekStart = week_start_ymd($week);

if ($user_id <= 0) { http_response_code(400); exit('user_id required'); }

/** store info */
$st = $pdo->prepare("SELECT name, weekly_holiday_dow FROM stores WHERE id=? LIMIT 1");
$st->execute([$store_id]);
$storeRow = $st->fetch(PDO::FETCH_ASSOC) ?: [];
$storeName = (string)($storeRow['name'] ?? ('#'.$store_id));
$holidayDow = array_key_exists('weekly_holiday_dow', $storeRow) && $storeRow['weekly_holiday_dow'] !== null
  ? (int)$storeRow['weekly_holiday_dow']
  : null;

$dates = array_values(array_filter(
  week_dates($weekStart),
  static function (string $ymd) use ($holidayDow): bool {
    if ($holidayDow === null) return true;
    $dow0 = (int)(new DateTime($ymd, new DateTimeZone('Asia/Tokyo')))->format('w');
    return $dow0 !== $holidayDow;
  }
));

/** user + default_start_time を取る（cast_profiles は無くても落ちない） */
$display_name = '';
$default_start = '21:00';
try {
  $st = $pdo->prepare("SELECT display_name FROM users WHERE id=? LIMIT 1");
  $st->execute([$user_id]);
  $display_name = (string)($st->fetchColumn() ?: '');
} catch (Throwable $e) {}

try {
  $st = $pdo->prepare("SELECT default_start_time FROM cast_profiles WHERE user_id=? AND store_id=? LIMIT 1");
  $st->execute([$user_id, $store_id]);
  $v = $st->fetchColumn();
  if ($v !== false && $v !== null) $default_start = substr((string)$v, 0, 5);
} catch (Throwable $e) {}

$msg = '';
$err = '';

/** 保存（週7日まとめて） */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_verify($_POST['csrf_token'] ?? null);

  $pdo->beginTransaction();
  try {
    $up = $pdo->prepare("
      INSERT INTO cast_shift_plans
        (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
      VALUES
        (:store_id, :user_id, :business_date, :start_time, :is_off, 'planned', :note, :actor)
      ON DUPLICATE KEY UPDATE
        start_time = VALUES(start_time),
        is_off = VALUES(is_off),
        status = 'planned',
        note = VALUES(note),
        created_by_user_id = VALUES(created_by_user_id),
        updated_at = CURRENT_TIMESTAMP
    ");

    foreach ($dates as $ymd) {
      $onKey = 'on_' . $ymd;
      $tmKey = 'time_' . $ymd;

      $isOn = isset($_POST[$onKey]) && (string)$_POST[$onKey] === '1';
      $t = trim((string)($_POST[$tmKey] ?? ''));

      if ($isOn) {
        if (!preg_match('/^\d{2}:\d{2}$/', $t)) $t = $default_start;
        $up->execute([
          ':store_id' => $store_id,
          ':user_id' => $user_id,
          ':business_date' => $ymd,
          ':start_time' => $t . ':00',
          ':is_off' => 0,
          ':note' => null,
          ':actor' => current_user_id_safe() ?: null,
        ]);
      } else {
        // OFF：行を残す運用（履歴が残る）
        $up->execute([
          ':store_id' => $store_id,
          ':user_id' => $user_id,
          ':business_date' => $ymd,
          ':start_time' => null,
          ':is_off' => 1,
          ':note' => null,
          ':actor' => current_user_id_safe() ?: null,
        ]);
      }
    }

    // 軽ログ（テーブル無くても落ちないように try）
    try {
      $lg = $pdo->prepare("
        INSERT INTO cast_shift_logs (store_id, user_id, action, payload_json, created_by_user_id)
        VALUES (?, ?, 'shift.week_save', ?, ?)
      ");
      $lg->execute([$store_id, $user_id, json_encode(['weekStart'=>$weekStart], JSON_UNESCAPED_UNICODE), current_user_id_safe() ?: null]);
    } catch (Throwable $e) {}

    $pdo->commit();
    $msg = '保存しました';
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = '保存失敗: ' . $e->getMessage();
  }
}

/** 週の予定を読む */
$plan = []; // [ymd] => ['on'=>bool,'time'=>'HH:MM']
if ($dates) {
  $minD = $dates[0];
  $maxD = $dates[count($dates)-1];
  $st = $pdo->prepare("
    SELECT business_date, start_time, is_off
    FROM cast_shift_plans
    WHERE store_id=? AND user_id=? AND business_date BETWEEN ? AND ?
  ");
  $st->execute([$store_id, $user_id, $minD, $maxD]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $ymd = (string)$r['business_date'];
    $off = ((int)$r['is_off'] === 1);
    $t = $r['start_time'] !== null ? substr((string)$r['start_time'], 0, 5) : '';
    $plan[$ymd] = ['on' => !$off, 'time' => $t];
  }
}

/** prev/next */
$ws = new DateTime($weekStart, new DateTimeZone('Asia/Tokyo'));
$prev = (clone $ws)->modify('-7 day')->format('Y-m-d');
$next = (clone $ws)->modify('+7 day')->format('Y-m-d');

render_page_start('出勤編集');
render_header('出勤編集', [
  'back_href' => '/wbss/public/admin/cast_edit.php?store_id='.(int)$store_id,
  'back_label' => '← キャスト一覧',
  'right_html' => '
    <a class="btn" href="/wbss/public/cast_week_plans.php?store_id='.(int)$store_id.'&date='.h($weekStart).'">週表示へ</a>
  ',
]);

$dowJp = ['','月','火','水','木','金','土','日'];
?>
<div class="page">
  <div class="admin-wrap">

    <div class="heroCard">
      <div class="heroMain">
        <div>
          <div class="eyebrow">Individual Shift Editor</div>
          <div class="ttl">出勤編集</div>
          <div class="sub">店舗：<b><?= h($storeName) ?></b></div>
        </div>
        <div class="weekNav">
          <a class="btn" href="?store_id=<?= (int)$store_id ?>&user_id=<?= (int)$user_id ?>&week=<?= h($prev) ?>">← 前週</a>
          <span class="weekChip"><?= h($weekStart) ?> 週</span>
          <a class="btn" href="?store_id=<?= (int)$store_id ?>&user_id=<?= (int)$user_id ?>&week=<?= h($next) ?>">次週 →</a>
        </div>
      </div>

      <div class="heroMeta">
        <div class="metaCard">
          <span class="metaLabel">対象キャスト</span>
          <strong><?= h($display_name ?: ('user#'.$user_id)) ?></strong>
        </div>
        <div class="metaCard">
          <span class="metaLabel">基本開始</span>
          <strong class="mono"><?= h($default_start) ?></strong>
        </div>
        <div class="metaCard">
          <span class="metaLabel">保存先</span>
          <strong>`cast_shift_plans`</strong>
        </div>
      </div>
    </div>

    <?php if ($msg): ?><div class="notice ok"><?= h($msg) ?></div><?php endif; ?>
    <?php if ($err): ?><div class="notice ng"><?= h($err) ?></div><?php endif; ?>

    <div class="card softCard">
      <div class="sub" style="margin-top:0;">
        1週間分をこの場で個別調整できます。ONで開始時刻を入れ、OFFは休みとして保存します。
      </div>
    </div>

    <form method="post" style="margin-top:12px">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$store_id ?>">
        <input type="hidden" name="user_id" value="<?= (int)$user_id ?>">
        <input type="hidden" name="week" value="<?= h($weekStart) ?>">

        <div class="actionBar">
          <div class="actionGroup">
            <button type="button" class="btn" onclick="fillDefault()">基本開始で埋める</button>
            <button type="button" class="btn" onclick="allOn()">全部ON</button>
            <button type="button" class="btn" onclick="allOff()">全部OFF</button>
          </div>
          <button class="btn primary" type="submit">保存</button>
        </div>

        <div class="dayGrid">
          <?php foreach ($dates as $ymd): ?>
            <?php
              $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
              $dow = (int)$d->format('N');
              $p = $plan[$ymd] ?? ['on'=>false,'time'=>''];
              $isOn = (bool)$p['on'];
              $t = $p['time'] !== '' ? $p['time'] : $default_start;
            ?>
            <section class="dayCard <?= $isOn ? 'is-on' : 'is-off' ?>" id="card_<?= h($ymd) ?>">
              <div class="dayHead">
                <div>
                  <div class="dayDate mono"><?= h(substr($ymd, 5)) ?></div>
                  <div class="dayDow"><?= h($dowJp[$dow]) ?>曜日</div>
                </div>
                <label class="toggleWrap">
                  <input
                    type="checkbox"
                    name="on_<?= h($ymd) ?>"
                    value="1"
                    <?= $isOn ? 'checked' : '' ?>
                    onchange="toggleRow('<?= h($ymd) ?>', this.checked)"
                  >
                  <span class="toggleText <?= $isOn ? 'okTxt' : 'ngTxt' ?>" id="state_<?= h($ymd) ?>"><?= $isOn ? 'ON' : 'OFF' ?></span>
                </label>
              </div>

              <div class="dayBody">
                <label class="field">
                  <span class="fieldCap">開始時刻</span>
                  <input
                    class="in mono timeInput"
                    id="time_<?= h($ymd) ?>"
                    name="time_<?= h($ymd) ?>"
                    value="<?= h($t) ?>"
                    <?= $isOn ? '' : 'disabled' ?>
                    placeholder="21:00"
                  >
                </label>
              </div>
            </section>
          <?php endforeach; ?>
        </div>
    </form>

  </div>
</div>

<style>
.heroCard{
  padding:18px;
  border:1px solid var(--line);
  border-radius:18px;
  background:
    radial-gradient(circle at top right, rgba(59,130,246,.12), transparent 28%),
    linear-gradient(180deg, rgba(255,255,255,.03), rgba(255,255,255,.01)),
    var(--cardA);
}
.heroMain{display:flex;gap:16px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}
.eyebrow{font-size:11px;letter-spacing:.08em;text-transform:uppercase;opacity:.55}
.ttl{font-weight:1000;font-size:24px;line-height:1.1}
.sub{margin-top:6px;font-size:12px;opacity:.78}
.heroMeta{
  display:grid;
  grid-template-columns:repeat(3, minmax(0, 1fr));
  gap:12px;
  margin-top:16px;
}
.metaCard{
  border:1px solid var(--line);
  border-radius:14px;
  padding:12px 14px;
  background:rgba(255,255,255,.03);
}
.metaLabel{display:block;font-size:11px;opacity:.6;margin-bottom:6px}
.weekNav{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.weekChip{
  display:inline-flex;
  align-items:center;
  min-height:44px;
  padding:0 14px;
  border-radius:12px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  font-weight:700;
}
.card{padding:14px;border:1px solid var(--line);border-radius:14px;background:var(--cardA)}
.softCard{background:rgba(255,255,255,.03)}
.notice{margin-top:12px;padding:10px 12px;border-radius:12px;border:1px solid var(--line)}
.notice.ok{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
.notice.ng{border-color:rgba(239,68,68,.35);background:rgba(239,68,68,.10)}
.muted{opacity:.75;font-size:12px}
.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono","Courier New", monospace}
.in{padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;min-height:44px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn.primary{background:rgba(59,130,246,.18);border-color:rgba(59,130,246,.35)}
.okTxt{color:rgba(34,197,94,.95);font-weight:800}
.ngTxt{color:rgba(239,68,68,.95);font-weight:800}
.actionBar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  margin-bottom:12px;
}
.actionGroup{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
.dayGrid{
  display:grid;
  grid-template-columns:repeat(1, minmax(0, 1fr));
  gap:12px;
}
.dayCard{
  border:1px solid var(--line);
  border-radius:16px;
  padding:14px;
  background:var(--cardA);
  transition:background .18s ease, border-color .18s ease, opacity .18s ease;
}
.dayCard.is-on{
  border-color:rgba(59,130,246,.30);
  background:linear-gradient(180deg, rgba(59,130,246,.08), rgba(255,255,255,.02));
}
.dayCard.is-off{
  opacity:.82;
}
.dayHead{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:12px;
}
.dayDate{font-size:20px;font-weight:900;line-height:1}
.dayDow{margin-top:4px;font-size:12px;opacity:.72}
.toggleWrap{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 10px;
  border:1px solid var(--line);
  border-radius:999px;
  background:rgba(255,255,255,.04);
}
.dayBody{margin-top:14px}
.field{display:flex;flex-direction:column;gap:6px}
.fieldCap{font-size:11px;opacity:.65}
.timeInput{width:100%}

@media (min-width: 760px){
  .dayGrid{grid-template-columns:repeat(2, minmax(0, 1fr))}
}
@media (min-width: 1180px){
  .dayGrid{grid-template-columns:repeat(4, minmax(0, 1fr))}
}
@media (max-width: 760px){
  .heroMeta{grid-template-columns:1fr}
  .ttl{font-size:22px}
}
</style>

<script>
function toggleRow(ymd, isOn){
  const el = document.getElementById('time_'+ymd);
  const card = document.getElementById('card_'+ymd);
  const state = document.getElementById('state_'+ymd);
  if (!el) return;
  el.disabled = !isOn;
  if (card){
    card.classList.toggle('is-on', isOn);
    card.classList.toggle('is-off', !isOn);
  }
  if (state){
    state.textContent = isOn ? 'ON' : 'OFF';
    state.classList.toggle('okTxt', isOn);
    state.classList.toggle('ngTxt', !isOn);
  }
}
function allOn(){
  document.querySelectorAll('input[type="checkbox"][name^="on_"]').forEach(cb=>{
    cb.checked = true;
    toggleRow(cb.name.substring(3), true);
  });
}
function allOff(){
  document.querySelectorAll('input[type="checkbox"][name^="on_"]').forEach(cb=>{
    cb.checked = false;
    toggleRow(cb.name.substring(3), false);
  });
}
function fillDefault(){
  const base = <?= json_encode($default_start, JSON_UNESCAPED_UNICODE) ?> || '21:00';
  document.querySelectorAll('input[id^="time_"]').forEach(inp=>{ inp.value = base; });
}
</script>

<?php render_page_end(); ?>
