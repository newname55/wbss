<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

/**
 * Cast self weekly planning screen.
 *
 * Canonical rules:
 * - plan source of truth: cast_shift_plans
 * - actual source of truth: attendances
 * - this screen does not write cast_week_plans
 */

require_login();
if (function_exists('require_role')) {
  require_role(['cast','admin','manager','super_user']); // cast本人が使う想定
}

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

/** store_id 解決（既存のストア選択がある前提） */
function resolve_store_id(PDO $pdo): int {
  if (function_exists('current_store_id')) {
    $sid = (int)current_store_id();
    if ($sid > 0) return $sid;
  }
  $sid = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? ($_SESSION['store_id'] ?? 0));
  if ($sid <= 0) {
    // store_select.php があるならそこへ（無ければ適宜変更）
    header('Location: /wbss/public/store_select.php?next=' . urlencode('/wbss/public/cast_week.php'));
    exit;
  }
  $_SESSION['store_id'] = $sid;
  return $sid;
}

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

function fetch_cast_week_plan_map(PDO $pdo, int $storeId, int $userId, array $weekDates): array {
  $plan = [];
  if ($weekDates === []) {
    return $plan;
  }

  $minD = $weekDates[0];
  $maxD = $weekDates[count($weekDates) - 1];

  $st = $pdo->prepare("
    SELECT business_date, start_time, is_off, note
    FROM cast_shift_plans
    WHERE store_id = ? AND user_id = ? AND business_date BETWEEN ? AND ?
      AND status = 'planned'
  ");
  $st->execute([$storeId, $userId, $minD, $maxD]);

  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
    $ymd = (string)$r['business_date'];
    $off = ((int)$r['is_off'] === 1);
    $t   = (!$off && $r['start_time'] !== null) ? substr((string)$r['start_time'], 0, 5) : '';
    $end = read_end_from_note($r['note'] ?? null);

    $plan[$ymd] = ['on' => !$off, 'time' => $t, 'end' => $end];
  }

  return $plan;
}

/** HH:MM -> HH:MM:00 */
function normalize_time_hm(?string $hm): ?string {
  $hm = trim((string)$hm);
  if ($hm === '') return null;
  if (!preg_match('/^\d{2}:\d{2}$/', $hm)) return null;
  [$hh, $mm] = array_map('intval', explode(':', $hm));
  if ($hh < 0 || $hh > 23 || $mm < 0 || $mm > 59) return null;
  return sprintf('%02d:%02d:00', $hh, $mm);
}

/** Store end-time metadata in cast_shift_plans.note. */
function note_from_end(string $endHm): string {
  $endHm = trim($endHm);
  if ($endHm === '' || strtoupper($endHm) === 'LAST') return '';
  if (!preg_match('/^\d{2}:\d{2}$/', $endHm)) return '';
  return '#end=' . $endHm;
}
function read_end_from_note(?string $note): string {
  $note = (string)($note ?? '');
  if (preg_match('/#end=(\d{2}:\d{2}|LAST)\b/u', $note, $m)) {
    return strtoupper((string)$m[1]);
  }
  return 'LAST';
}
// ===== 店舗ごとの定休日（曜日）＋ 日別例外（store_closures）で営業判定 =====

function store_closed_dows(PDO $pdo, int $storeId): array {
  static $cache = [];
  if (isset($cache[$storeId])) return $cache[$storeId];

  $dows = [];
  try {
    $st = $pdo->prepare("SELECT dow FROM store_weekly_closed_days WHERE store_id=?");
    $st->execute([$storeId]);
    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $v) {
      $d = (int)$v;
      if ($d >= 1 && $d <= 7) $dows[] = $d;
    }
  } catch (Throwable $e) {
    // テーブル未導入でも落とさない
  }

  return $cache[$storeId] = $dows;
}

function store_override_open(PDO $pdo, int $storeId, string $ymd): ?bool {
  // store_closures を「上書き」テーブルとして扱う（is_open=1:営業 / 0:休業）
  try {
    $st = $pdo->prepare("SELECT is_open FROM store_closures WHERE store_id=? AND closed_date=? LIMIT 1");
    $st->execute([$storeId, $ymd]);
    $v = $st->fetchColumn();
    if ($v === false) return null;
    return ((int)$v === 1);
  } catch (Throwable $e) {
    return null;
  }
}

function is_store_open(PDO $pdo, int $storeId, string $ymd): bool {
  // 1) 日別上書きが最優先（臨時営業/臨時休業）
  $ov = store_override_open($pdo, $storeId, $ymd);
  if ($ov !== null) return $ov;

  // 2) 曜日定休日ルール
  $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  $dow = (int)$d->format('N'); // 1..7
  $closed = store_closed_dows($pdo, $storeId);

  // closedが空なら「定休日なし」扱い
  return !in_array($dow, $closed, true);
}

$storeId = resolve_store_id($pdo);
$userId  = current_user_id_safe();
if ($userId <= 0) { http_response_code(401); exit('not logged in'); }

$week     = (string)($_GET['week'] ?? $_POST['week'] ?? now_jst_ymd());
$weekStart = week_start_ymd($week);
$weekDates = week_dates($weekStart); // 週の7日（読み込み範囲の基準）
$dates    = week_dates($weekStart);
// 営業日だけ表示（定休日は非表示、臨時営業日は表示）
$dates = array_values(array_filter($dates, function($ymd) use ($pdo, $storeId){
  return is_store_open($pdo, $storeId, $ymd);
}));
// 店名（任意）
$st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$storeName = (string)($st->fetchColumn() ?: ('#'.$storeId));

// ユーザー名
$st = $pdo->prepare("SELECT display_name, is_active FROM users WHERE id=? LIMIT 1");
$st->execute([$userId]);
$u = $st->fetch(PDO::FETCH_ASSOC);
$displayName = (string)($u['display_name'] ?? ('user#'.$userId));
$isActiveUser = (int)($u['is_active'] ?? 1);

// デフォ開始（cast_profiles があればそこから、無ければ 20:00）
$defaultStart = '20:00';
try {
  $st = $pdo->prepare("SELECT default_start_time FROM cast_profiles WHERE user_id=? AND store_id=? LIMIT 1");
  $st->execute([$userId, $storeId]);
  $v = $st->fetchColumn();
  if ($v !== false && $v !== null) $defaultStart = substr((string)$v, 0, 5);
} catch (Throwable $e) {}

// 前後週
$ws = new DateTime($weekStart, new DateTimeZone('Asia/Tokyo'));
$prev = (clone $ws)->modify('-7 day')->format('Y-m-d');
$next = (clone $ws)->modify('+7 day')->format('Y-m-d');

$msg = '';
$err = '';

/* =========================
   save to canonical plan table
========================= */
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  csrf_verify($_POST['csrf_token'] ?? null);

  if ($isActiveUser !== 1) {
    $err = '非在籍のため編集できません';
  } else {
    $beforePlan = fetch_cast_week_plan_map($pdo, $storeId, $userId, $weekDates);
    $pdo->beginTransaction();
    try {
      $up = $pdo->prepare("
        INSERT INTO cast_shift_plans
          (store_id, user_id, business_date, start_time, is_off, status, note, created_by_user_id)
        VALUES
          (?, ?, ?, ?, ?, 'planned', ?, ?)
        ON DUPLICATE KEY UPDATE
          start_time=VALUES(start_time),
          is_off=VALUES(is_off),
          status='planned',
          note=VALUES(note),
          created_by_user_id=VALUES(created_by_user_id),
          updated_at=NOW()
      ");
      $changedLogs = [];

      foreach ($dates as $ymd) {
        $onKey = 'on_' . $ymd;
        $tmKey = 'time_' . $ymd;
        $enKey = 'end_'  . $ymd;

        $isOn = isset($_POST[$onKey]) && (string)$_POST[$onKey] === '1';
        $tHm  = trim((string)($_POST[$tmKey] ?? ''));
        $eHm  = trim((string)($_POST[$enKey] ?? 'LAST'));
        $before = $beforePlan[$ymd] ?? ['on' => false, 'time' => '', 'end' => 'LAST'];

        if (!$isOn) {
          // OFF（レコードを残す：is_off=1）
          $up->execute([$storeId, $userId, $ymd, null, 1, '', $userId ?: null]);
          $after = ['on' => false, 'time' => '', 'end' => 'LAST'];
          if ($before !== $after) {
            $changedLogs[] = [
              'business_date' => $ymd,
              'before' => $before,
              'after' => $after,
              'change_type' => 'set_off',
            ];
          }
          continue;
        }

        $start = normalize_time_hm($tHm) ?? normalize_time_hm($defaultStart);
        $note  = note_from_end($eHm);

        $up->execute([$storeId, $userId, $ymd, $start, 0, $note, $userId ?: null]);
        $after = [
          'on' => true,
          'time' => substr((string)$start, 0, 5),
          'end' => ($eHm !== '' ? strtoupper($eHm) : 'LAST'),
        ];
        if ($before !== $after) {
          $changedLogs[] = [
            'business_date' => $ymd,
            'before' => $before,
            'after' => $after,
            'change_type' => !empty($before['on']) ? 'update_shift' : 'set_on',
          ];
        }
      }

      // ログ（テーブルが無い環境もあるので try）
      try {
        if ($changedLogs !== []) {
          $detailLog = $pdo->prepare("
            INSERT INTO cast_shift_logs (store_id, user_id, action, payload_json, created_by_user_id)
            VALUES (?, ?, 'cast.shift.plan_changed', ?, ?)
          ");
          foreach ($changedLogs as $row) {
            $detailLog->execute([
              $storeId,
              $userId,
              json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
              $userId ?: null,
            ]);
          }
        }

        $lg = $pdo->prepare("
          INSERT INTO cast_shift_logs (store_id, user_id, action, payload_json, created_by_user_id)
          VALUES (?, ?, 'cast.shift.week_save', ?, ?)
        ");
        $lg->execute([
          $storeId,
          $userId,
          json_encode([
            'weekStart' => $weekStart,
            'changed_days' => count($changedLogs),
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
          $userId ?: null,
        ]);
      } catch (Throwable $e) {}

      $pdo->commit();
      $msg = ($changedLogs === []) ? '変更はありませんでした' : ('保存しました（変更 ' . count($changedLogs) . ' 日）');
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = '保存失敗: ' . $e->getMessage();
    }
  }
}

/* =========================
   load from canonical plan table
========================= */
$plan = fetch_cast_week_plan_map($pdo, $storeId, $userId, $weekDates); // [ymd] => ['on'=>bool,'time'=>'HH:MM','end'=>'LAST|HH:MM']

$weekOnDays = [];
$weekOffDays = [];
foreach ($dates as $ymd) {
  $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  $dow = (int)$d->format('N');
  $dowName = ['','月','火','水','木','金','土','日'][$dow];
  $p = $plan[$ymd] ?? ['on'=>false,'time'=>'','end'=>'LAST'];
  if (!empty($p['on'])) {
    $weekOnDays[] = $dowName;
  } else {
    $weekOffDays[] = $dowName;
  }
}
$weekSummaryOn = $weekOnDays ? implode('・', $weekOnDays) : 'なし';
$weekSummaryOff = $weekOffDays ? implode('・', $weekOffDays) : 'なし';

// UI
render_page_start('出勤（キャスト）');
render_header('出勤（キャスト）', [
  'back_href' => '/wbss/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '',
  'show_user' => false,
]);

$dowJp = ['','月','火','水','木','金','土','日'];
?>
<div class="page">
  <div class="wrap">

    <div class="hero">
      <div class="heroTop">
        <div>
          <div class="h1">📅 出勤（週）</div>
          <div class="sub">店舗：<b><?= h($storeName) ?></b></div>
        </div>

        <div class="weekNav">
          <span class="pill">週: <b><?= h($weekStart) ?></b></span>
          <a class="btn ghost" href="?week=<?= h($prev) ?>">← 前週</a>
          <a class="btn ghost" href="?week=<?= h($next) ?>">次週 →</a>
        </div>
      </div>

      <div class="weekMiniSummary" aria-label="今週の予定サマリー">
        <div class="weekMiniSummary__row">
          <span class="weekMiniSummary__label">出勤予定</span>
          <span class="weekMiniSummary__value"><?= h($weekSummaryOn) ?></span>
        </div>
        <div class="weekMiniSummary__row is-off">
          <span class="weekMiniSummary__label">休み予定</span>
          <span class="weekMiniSummary__value"><?= h($weekSummaryOff) ?></span>
        </div>
      </div>

      <?php if ($err): ?><div class="notice ng"><?= h($err) ?></div><?php endif; ?>

      <div class="tools">
        <button class="toolBtn" type="button" onclick="allOn()">✅ 全部出勤</button>
        <button class="toolBtn" type="button" onclick="allOff()">🛌 全部休み</button>
      </div>

      <div class="muted" style="margin-top:8px;">
        まずは各日を「出勤 / 休み」で選び、時間を変えたい日だけ「時間を変更」を開いてください。保存先は <b>cast_shift_plans</b> です。
      </div>
      <div class="muted" style="margin-top:6px;">
        一度決めた予定はできるだけ維持する前提です。体調不良などやむを得ない変更時は、お店へ先に連絡してください。変更履歴は記録されます。
      </div>
    </div>

    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="week" value="<?= h($weekStart) ?>">

      <div class="list">
        <?php foreach ($dates as $ymd): ?>
          <?php
            $d = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
            $dow = (int)$d->format('N'); // 1..7
            $dowJp = ['','月','火','水','木','金','土','日'][$dow];
            $dowClass = ['','mon','tue','wed','thu','fri','sat','sun'][$dow];

            $p = $plan[$ymd] ?? ['on'=>false,'time'=>'','end'=>'LAST'];
            $isOn = (bool)$p['on'];
            $t = $p['time'] !== '' ? $p['time'] : $defaultStart;
            $end = $p['end'] ?: 'LAST';
            $endIsLast = (strtoupper($end) === 'LAST');
            $displayDate = substr($ymd, 5, 5);
          ?>
          <div class="day" data-ymd="<?= h($ymd) ?>">
            <div class="dayHead">
              <div class="dayTitle">
                <span class="date"><?= h($displayDate) ?></span>
                <span class="dow <?= h($dowClass) ?>"><?= h($dowJp) ?></span>
              </div>
              <div class="statusBadge js-statusbadge-<?= h($ymd) ?> <?= $isOn ? 'is-on' : 'is-off' ?>">
                <?= $isOn ? '出勤' : '休み' ?>
              </div>
            </div>

            <div class="dayBody">
              <input type="hidden"
                     id="on_<?= h($ymd) ?>"
                     name="on_<?= h($ymd) ?>"
                     value="<?= $isOn ? '1' : '0' ?>">

              <div class="statusSwitch" role="group" aria-label="<?= h($ymd) ?> の出勤設定">
                <button type="button"
                        class="stateBtn <?= $isOn ? 'is-work active' : 'is-off active' ?>"
                        id="statebtn_<?= h($ymd) ?>"
                        onclick="toggleDayState('<?= h($ymd) ?>')">
                  <?= $isOn ? '出勤' : '休み' ?>
                </button>
              </div>

              <div class="compactSummary js-summary-<?= h($ymd) ?>">
                <span class="summaryMain">
                  <?php if ($isOn): ?>
                    <span class="summaryChip">
                      <span class="summaryChip__label">入</span>
                      <span class="summaryChip__value"><?= h($t) ?></span>
                    </span>
                    <span class="summaryChip">
                      <span class="summaryChip__label">終</span>
                      <span class="summaryChip__value"><?= h($end) ?></span>
                    </span>
                  <?php else: ?>
                    <span class="summaryEmpty">この日は休みです</span>
                  <?php endif; ?>
                </span>
              </div>

              <div class="timeToggleWrap js-timewrap-<?= h($ymd) ?>" <?= $isOn ? '' : 'hidden' ?>>
                <button type="button"
                        class="timeToggle"
                        id="toggle_<?= h($ymd) ?>"
                        aria-expanded="false"
                        onclick="toggleEditor('<?= h($ymd) ?>')">
                  時間を変更
                </button>
              </div>

              <div class="editor js-editor-<?= h($ymd) ?>" hidden>
                <div class="fieldGrid">
                  <div class="field">
                    <div class="fieldTop">
                      <div class="lbl">開始</div>
                      <div class="hint">例: 20:00</div>
                    </div>
                    <input class="inp"
                           type="time"
                           id="time_<?= h($ymd) ?>"
                           name="time_<?= h($ymd) ?>"
                           value="<?= h($t) ?>"
                           step="60"
                           <?= $isOn ? '' : 'disabled' ?>>
                  </div>

                  <div class="field">
                    <div class="fieldTop">
                      <div class="lbl">終了</div>
                      <div class="hint">LAST / 時刻</div>
                    </div>

                    <div class="endRow">
                      <button type="button"
                              class="mini <?= $endIsLast ? 'active' : '' ?>"
                              id="endbtn_last_<?= h($ymd) ?>"
                              onclick="setEndMode('<?= h($ymd) ?>','LAST')">LAST</button>

                      <button type="button"
                              class="mini <?= !$endIsLast ? 'active' : '' ?>"
                              id="endbtn_time_<?= h($ymd) ?>"
                              onclick="setEndMode('<?= h($ymd) ?>','TIME')">時刻</button>

                      <input class="inp"
                             type="time"
                             id="endtime_<?= h($ymd) ?>"
                             value="<?= !$endIsLast && preg_match('/^\d{2}:\d{2}$/',$end) ? h($end) : '' ?>"
                             step="60"
                             <?= ($isOn && !$endIsLast) ? '' : 'disabled' ?>>
                    </div>

                    <input type="hidden"
                           id="endmode_<?= h($ymd) ?>"
                           name="end_<?= h($ymd) ?>"
                           value="<?= h($endIsLast ? 'LAST' : ($end !== '' ? $end : 'LAST')) ?>">
                  </div>
                </div>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="saveBar">
        <button class="saveBtn" type="submit">この内容で決定</button>
      </div>
    </form>

  </div>
</div>

<style>
:root{
  --bg1:#f7f8ff;
  --bg2:#fff6fb;
  --card:#ffffff;
  --ink:#1f2937;
  --muted:#6b7280;
  --line:rgba(15,23,42,.10);

  --pink:#ff5fa2;
  --purple:#7c5cff;
  --mint:#34d399;
  --sky:#60a5fa;
  --amber:#f59e0b;

  --shadow: 0 10px 30px rgba(17,24,39,.08);
  --shadow2: 0 6px 16px rgba(17,24,39,.06);
}

/* Dark theme adjustments for this page only (improve contrast/readability)
   These rules apply only when the document theme is 'dark' so other themes stay unchanged. */
html[data-theme="dark"], body[data-theme="dark"]{
  --bg1: #071025;
  --bg2: #081228;
  --card: #0b1224;
  --ink: #e8ecff;
  --muted: #aab3d6;
  --line: rgba(255,255,255,.06);
  --shadow: 0 10px 30px rgba(0,0,0,.6);
  --shadow2: 0 6px 16px rgba(0,0,0,.5);
}

/* Make key page components use the adjusted variables in dark mode */
html[data-theme="dark"] .page,
body[data-theme="dark"] .page{
  background:
    radial-gradient(1000px 600px at 10% 0%, rgba(124,92,255,.06), transparent 60%),
    radial-gradient(900px 600px at 90% 10%, rgba(255,95,162,.07), transparent 60%),
    linear-gradient(180deg, var(--bg1), var(--bg2));
}
html[data-theme="dark"] .day,
body[data-theme="dark"] .day,
html[data-theme="dark"] .field,
body[data-theme="dark"] .field,
html[data-theme="dark"] .dayHead,
body[data-theme="dark"] .dayHead,
html[data-theme="dark"] .pill,
body[data-theme="dark"] .pill,
html[data-theme="dark"] .btn,
body[data-theme="dark"] .btn,
html[data-theme="dark"] .toolBtn,
body[data-theme="dark"] .toolBtn,
html[data-theme="dark"] .notice,
body[data-theme="dark"] .notice,
html[data-theme="dark"] .inp,
body[data-theme="dark"] .inp,
html[data-theme="dark"] .endRow .mini,
body[data-theme="dark"] .endRow .mini{
  background: var(--card) !important;
  color: var(--ink) !important;
  border-color: var(--line) !important;
}

/* Inputs should have dark background and light text in dark theme */
html[data-theme="dark"] .inp,
body[data-theme="dark"] .inp{
  background: var(--card) !important;
  color: var(--ink) !important;
  border: 1px solid rgba(255,255,255,.06) !important;
}

/* Muted texts should become lighter but still muted */
html[data-theme="dark"] .muted,
body[data-theme="dark"] .muted{
  color: var(--muted) !important;
}

.page{
  background:
    radial-gradient(1000px 600px at 10% 0%, rgba(124,92,255,.12), transparent 60%),
    radial-gradient(900px 600px at 90% 10%, rgba(255,95,162,.14), transparent 60%),
    linear-gradient(180deg, var(--bg1), var(--bg2));
  min-height: 100vh;
}

.wrap{
  max-width: 920px;
  margin: 0 auto;
  padding: 12px 12px 36px;
}

.hero{
  background: linear-gradient(135deg, rgba(124,92,255,.15), rgba(255,95,162,.10));
  border:1px solid rgba(124,92,255,.18);
  border-radius: 18px;
  padding: 12px;
  box-shadow: var(--shadow2);
}

.heroTop{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
  flex-wrap:wrap;
}

.h1{
  font-weight:1000;
  font-size: 17px;
  color: var(--ink);
  display:flex;
  align-items:center;
  gap:8px;
}
.sub{
  margin-top:2px;
  font-size:12px;
  color: var(--muted);
}

.weekNav{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:6px;
  align-items:center;
  min-width: 220px;
}
.pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  justify-content:center;
  grid-column: 1 / -1;
  padding:8px 12px;
  border-radius: 14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.8);
  backdrop-filter: blur(8px);
  box-shadow: var(--shadow2);
}
.btn{
  appearance:none;
  border:1px solid var(--line);
  background: rgba(255,255,255,.95);
  color: var(--ink);
  padding: 8px 14px;
  border-radius: 14px;
  font-weight:800;
  cursor:pointer;
  text-decoration:none;
  box-shadow: var(--shadow2);
}
.btn:active{ transform: translateY(1px); }
.btn.primary{
  border-color: rgba(124,92,255,.25);
  background: linear-gradient(135deg, rgba(124,92,255,.18), rgba(255,95,162,.12));
}
.btn.ghost{
  background: rgba(255,255,255,.6);
}
.btn:disabled{ opacity:.55; cursor:not-allowed; }

.notice{
  margin-top:8px;
  padding:9px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.85);
}
.notice.ok{ border-color: rgba(52,211,153,.35); background: rgba(52,211,153,.10); }
.notice.ng{ border-color: rgba(239,68,68,.35); background: rgba(239,68,68,.10); }

.weekMiniSummary{
  margin-top:8px;
  display:flex;
  flex-wrap:wrap;
  gap:6px;
}

.weekMiniSummary__row{
  display:inline-flex;
  align-items:center;
  gap:8px;
  min-height:34px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid rgba(16,185,129,.18);
  background: rgba(255,255,255,.72);
  font-size:12px;
}

.weekMiniSummary__row.is-off{
  border-color: rgba(239,68,68,.16);
}

.weekMiniSummary__label{
  color: var(--muted);
  font-weight:800;
}

.weekMiniSummary__value{
  color: var(--ink);
  font-weight:900;
}

.tools{
  margin-top:8px;
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:6px;
}
.toolBtn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-height:42px;
  padding:9px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.85);
  box-shadow: var(--shadow2);
  cursor:pointer;
  font-weight:800;
}

.list{
  margin-top: 10px;
  display:grid;
  grid-template-columns: 1fr;
  gap:12px;
}

@media (min-width: 900px){
  .list{
    grid-template-columns: repeat(3, minmax(0, 1fr));
    align-items:start;
  }
  .day.is-editing{
    grid-column: 1 / -1;
  }
}

.day{
  border:1px solid var(--line);
  border-radius: 20px;
  background: rgba(255,255,255,.92);
  box-shadow: var(--shadow);
  overflow:hidden;
}

.dayHead{
  display:flex;
  align-items:flex-start;
  justify-content:space-between;
  gap:10px;
  padding: 14px 14px 10px;
  border-bottom: 1px solid rgba(15,23,42,.06);
  background: linear-gradient(180deg, rgba(255,255,255,.95), rgba(255,255,255,.85));
}

.dayTitle{
  display:flex;
  align-items:baseline;
  gap:10px;
  flex-wrap:wrap;
}
.dayTitle .date{
  font-weight:1000;
  font-size:16px;
}
.dayTitle .dow{
  font-weight:1000;
  font-size:14px;
  padding:3px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.8);
}
.dow.mon{ color:#2563eb; border-color:rgba(37,99,235,.18); background: rgba(37,99,235,.06); }
.dow.tue{ color:#7c3aed; border-color:rgba(124,58,237,.18); background: rgba(124,58,237,.06); }
.dow.wed{ color:#059669; border-color:rgba(5,150,105,.18); background: rgba(5,150,105,.06); }
.dow.thu{ color:#0ea5e9; border-color:rgba(14,165,233,.18); background: rgba(14,165,233,.06); }
.dow.fri{ color:#f97316; border-color:rgba(249,115,22,.18); background: rgba(249,115,22,.06); }
.dow.sat{ color:#ec4899; border-color:rgba(236,72,153,.18); background: rgba(236,72,153,.06); }
.dow.sun{ color:#ef4444; border-color:rgba(239,68,68,.18); background: rgba(239,68,68,.06); }

.statusBadge{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:72px;
  padding:8px 12px;
  border-radius: 999px;
  border:1px solid var(--line);
  background: rgba(255,255,255,.8);
  font-weight:1000;
}
.statusBadge.is-on{
  color:#047857;
  background: rgba(16,185,129,.10);
  border-color: rgba(16,185,129,.22);
}
.statusBadge.is-off{
  color:#dc2626;
  background: rgba(239,68,68,.10);
  border-color: rgba(239,68,68,.18);
}

.dayBody{
  padding: 12px 14px 14px;
  display:flex;
  flex-direction:column;
  gap:10px;
}

.statusSwitch{
  display:block;
}

.stateBtn{
  appearance:none;
  width:100%;
  min-height:54px;
  padding:12px 12px;
  border-radius: 16px;
  border:2px solid rgba(15,23,42,.08);
  background:#fff;
  color: var(--ink);
  font-size:18px;
  font-weight:1000;
  cursor:pointer;
  box-shadow: var(--shadow2);
}
.stateBtn.active{
  transform: translateY(1px);
}
.stateBtn.is-work{
  color:#047857;
  background: linear-gradient(135deg, rgba(16,185,129,.18), rgba(52,211,153,.10));
  border-color: rgba(16,185,129,.35);
}
.stateBtn.is-off{
  color:#dc2626;
  background: linear-gradient(135deg, rgba(239,68,68,.14), rgba(248,113,113,.08));
  border-color: rgba(239,68,68,.30);
}

.compactSummary{
  display:flex;
  align-items:center;
  gap:6px;
  padding:8px 10px;
  border-radius: 14px;
  background: rgba(15,23,42,.03);
  color: var(--ink);
  min-height:44px;
}

.summaryMain{
  display:flex;
  align-items:center;
  gap:6px;
  width:100%;
  flex-wrap:wrap;
}

.summaryChip{
  display:inline-flex;
  align-items:center;
  gap:6px;
  min-height:28px;
  padding:4px 8px;
  border-radius:999px;
  background:#fff;
  border:1px solid rgba(15,23,42,.08);
}

.summaryChip__label{
  color: var(--muted);
  font-size:11px;
  font-weight:900;
}

.summaryChip__value{
  color: var(--ink);
  font-size:15px;
  font-weight:1000;
  letter-spacing:.01em;
}

.summaryEmpty{
  font-size:14px;
  font-weight:800;
  color: var(--ink);
}

.timeToggleWrap{
  display:flex;
}

.timeToggle{
  appearance:none;
  width:100%;
  min-height:48px;
  padding:12px 14px;
  border-radius: 14px;
  border:1px dashed rgba(124,92,255,.30);
  background: rgba(124,92,255,.06);
  color: #5b47c8;
  font-weight:900;
  cursor:pointer;
}

.editor{
  border-top:1px dashed rgba(15,23,42,.10);
  padding-top:12px;
}

.day.is-editing{
  box-shadow: 0 16px 36px rgba(124,92,255,.16);
  border-color: rgba(124,92,255,.24);
}

.fieldGrid{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
}

@media (max-width: 640px){
  .fieldGrid{
    grid-template-columns: 1fr;
  }
}

.field{
  border:1px solid var(--line);
  background: rgba(255,255,255,.85);
  border-radius: 16px;
  padding: 10px 10px;
}

.fieldTop{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  margin-bottom: 8px;
}
.fieldTop .lbl{
  font-size:12px;
  color: var(--muted);
  font-weight:900;
  letter-spacing:.02em;
}
.fieldTop .hint{
  font-size:12px;
  color: var(--muted);
  opacity:.9;
}

.inp{
  width:100%;
  padding: 12px 12px;
  border-radius: 14px;
  border:1px solid rgba(15,23,42,.12);
  background: #fff;
  font-size: 16px;
  font-weight:900;
  color: var(--ink);
}

.endRow{
  display:flex;
  gap:8px;
  align-items:center;
}
.endRow .mini{
  flex:0 0 auto;
  padding:10px 10px;
  border-radius: 14px;
  border:1px solid rgba(15,23,42,.12);
  background:#fff;
  font-weight:900;
  cursor:pointer;
}
.endRow .mini.active{
  border-color: rgba(124,92,255,.35);
  background: rgba(124,92,255,.10);
}
.endRow .inp{ flex:1 1 auto; }

.saveBar{
  position: sticky;
  bottom: 10px;
  margin-top: 14px;
  display:flex;
  justify-content:flex-end;
  gap:10px;
}
.saveBtn{
  padding: 12px 16px;
  border-radius: 16px;
  border:1px solid #6d4dff;
  background: linear-gradient(135deg, #7c5cff, #ff5fa2);
  color:#fff;
  font-weight:1000;
  box-shadow: 0 14px 28px rgba(124,92,255,.28);
  min-height:56px;
}
.saveBtn:hover{
  filter: brightness(1.03);
}

html[data-theme="dark"] .saveBtn,
body[data-theme="dark"] .saveBtn{
  border-color:#9f8bff;
  box-shadow: 0 14px 28px rgba(0,0,0,.38);
}

.muted{ color: var(--muted); font-size:12px; }

html[data-theme="dark"] .statusBadge,
body[data-theme="dark"] .statusBadge,
html[data-theme="dark"] .compactSummary,
body[data-theme="dark"] .compactSummary,
html[data-theme="dark"] .stateBtn,
body[data-theme="dark"] .stateBtn,
html[data-theme="dark"] .timeToggle,
body[data-theme="dark"] .timeToggle{
  border-color: rgba(255,255,255,.08) !important;
}

html[data-theme="dark"] .compactSummary,
body[data-theme="dark"] .compactSummary{
  background: rgba(255,255,255,.04);
}

html[data-theme="dark"] .summaryChip,
body[data-theme="dark"] .summaryChip{
  background: rgba(255,255,255,.06);
  border-color: rgba(255,255,255,.08);
}

html[data-theme="dark"] .timeToggle,
body[data-theme="dark"] .timeToggle{
  background: rgba(124,92,255,.14);
  color: #d9d1ff;
}

html[data-theme="dark"] .stateBtn.is-work.active,
body[data-theme="dark"] .stateBtn.is-work.active{
  color:#bbf7d0;
}

html[data-theme="dark"] .stateBtn.is-off.active,
body[data-theme="dark"] .stateBtn.is-off.active{
  color:#ffd6d6;
}

html[data-theme="dark"] .weekMiniSummary__row,
body[data-theme="dark"] .weekMiniSummary__row{
  background: rgba(255,255,255,.05);
}

@media (max-width: 640px){
  .wrap{
    padding: 10px 10px 30px;
  }
  .hero{
    border-radius: 16px;
    padding: 10px;
  }
  .weekNav{
    width:100%;
    min-width: 0;
  }
  .tools{
    grid-template-columns: 1fr 1fr;
  }
  .toolBtn{
    min-height:42px;
    font-size:14px;
  }
  .list{
    grid-template-columns: 1fr 1fr;
    gap:10px;
  }
  .day{
    border-radius: 18px;
  }
  .day.is-editing{
    grid-column: 1 / -1;
  }
  .weekMiniSummary{
    gap:5px;
  }
  .weekMiniSummary__row{
    width:100%;
    justify-content:space-between;
    border-radius:14px;
  }
  .dayTitle{
    gap:8px;
  }
  .dayTitle .date{
    font-size:15px;
  }
  .statusBadge{
    min-width:64px;
    padding:7px 10px;
  }
  .statusSwitch{
    gap:0;
  }
  .stateBtn{
    min-height:48px;
    font-size:16px;
    border-radius: 14px;
  }
  .compactSummary{
    padding:7px 8px;
    min-height:40px;
  }
  .summaryChip{
    gap:5px;
    padding:4px 7px;
  }
  .summaryChip__value{
    font-size:14px;
  }
  .saveBar{
    bottom: 8px;
  }
  .saveBtn{
    width:100%;
  }
  .h1{
    font-size:16px;
  }
}

@media (max-width: 380px){
  .weekNav{
    grid-template-columns: 1fr;
  }
  .pill{
    grid-column: auto;
  }
  .list{
    grid-template-columns: 1fr;
  }
}

.toast{
  position: fixed;
  left: 50%;
  bottom: 22px;
  transform: translateX(-50%) translateY(20px);
  min-width: 180px;
  max-width: calc(100vw - 28px);
  padding: 12px 16px;
  border-radius: 16px;
  border:1px solid rgba(16,185,129,.22);
  background: rgba(17,24,39,.92);
  color: #fff;
  font-size: 14px;
  font-weight: 900;
  text-align:center;
  box-shadow: 0 12px 30px rgba(17,24,39,.28);
  opacity: 0;
  pointer-events: none;
  transition: opacity .22s ease, transform .22s ease;
  z-index: 1000;
}

.toast.show{
  opacity: 1;
  transform: translateX(-50%) translateY(0);
}
</style>
<script>
function updateSummary(ymd){
  const t = document.getElementById('time_'+ymd);
  const endMode = document.getElementById('endmode_'+ymd);
  const summary = document.querySelector('.js-summary-'+CSS.escape(ymd)+' .summaryMain');
  const onInput = document.getElementById('on_'+ymd);
  const isOn = onInput && onInput.value === '1';
  if (!summary) return;

  if (!isOn){
    summary.innerHTML = '<span class="summaryEmpty">この日は休みです</span>';
    return;
  }

  const start = (t && t.value) ? t.value : '<?= h($defaultStart) ?>';
  const end = (endMode && endMode.value) ? endMode.value : 'LAST';
  summary.innerHTML =
    '<span class="summaryChip"><span class="summaryChip__label">入</span><span class="summaryChip__value">' + start + '</span></span>' +
    '<span class="summaryChip"><span class="summaryChip__label">終</span><span class="summaryChip__value">' + end + '</span></span>';
}

function setDayState(ymd, isOn){
  const t = document.getElementById('time_'+ymd);
  const onInput = document.getElementById('on_'+ymd);
  const endMode = document.getElementById('endmode_'+ymd);
  const endTime = document.getElementById('endtime_'+ymd);
  const stateBtn = document.getElementById('statebtn_'+ymd);
  const badge = document.querySelector('.js-statusbadge-'+CSS.escape(ymd));
  const timeWrap = document.querySelector('.js-timewrap-'+CSS.escape(ymd));
  const editor = document.querySelector('.js-editor-'+CSS.escape(ymd));
  const toggleBtn = document.getElementById('toggle_'+ymd);
  const day = document.querySelector('.day[data-ymd="'+CSS.escape(ymd)+'"]');

  if (onInput) onInput.value = isOn ? '1' : '0';
  if (t) t.disabled = !isOn;

  const modeVal = endMode ? (endMode.value || 'LAST') : 'LAST';
  if (endTime) endTime.disabled = (!isOn || modeVal === 'LAST');

  if (stateBtn){
    stateBtn.textContent = isOn ? '出勤' : '休み';
    stateBtn.classList.toggle('is-work', isOn);
    stateBtn.classList.toggle('is-off', !isOn);
  }

  if (badge){
    badge.textContent = isOn ? '出勤' : '休み';
    badge.classList.toggle('is-on', isOn);
    badge.classList.toggle('is-off', !isOn);
  }

  if (timeWrap) timeWrap.hidden = !isOn;

  if (!isOn && editor){
    editor.hidden = true;
    if (day) day.classList.remove('is-editing');
    if (toggleBtn){
      toggleBtn.textContent = '時間を変更';
      toggleBtn.setAttribute('aria-expanded', 'false');
    }
  }

  updateSummary(ymd);
}

function toggleDayState(ymd){
  const onInput = document.getElementById('on_'+ymd);
  const isOn = onInput && onInput.value === '1';
  setDayState(ymd, !isOn);
}

function toggleEditor(ymd){
  const day = document.querySelector('.day[data-ymd="'+CSS.escape(ymd)+'"]');
  const editor = document.querySelector('.js-editor-'+CSS.escape(ymd));
  const toggleBtn = document.getElementById('toggle_'+ymd);
  if (!editor || !toggleBtn) return;

  const willOpen = editor.hidden;
  editor.hidden = !willOpen;
  toggleBtn.textContent = willOpen ? '時間を閉じる' : '時間を変更';
  toggleBtn.setAttribute('aria-expanded', willOpen ? 'true' : 'false');
  if (day) day.classList.toggle('is-editing', willOpen);

  if (willOpen) {
    window.setTimeout(() => {
      editor.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }, 80);
  }
}

function setEndMode(ymd, mode){
  const endMode = document.getElementById('endmode_'+ymd);
  const endTime = document.getElementById('endtime_'+ymd);
  const bLast = document.getElementById('endbtn_last_'+ymd);
  const bTime = document.getElementById('endbtn_time_'+ymd);

  if (!endMode) return;

  if (mode === 'LAST'){
    endMode.value = 'LAST';
    if (endTime){ endTime.value = ''; endTime.disabled = true; }
    if (bLast) bLast.classList.add('active');
    if (bTime) bTime.classList.remove('active');
  } else {
    // TIME
    if (endTime && endTime.value && /^\d{2}:\d{2}$/.test(endTime.value)){
      endMode.value = endTime.value;
    } else {
      // 入力が空ならとりあえず LAST 相当（保存時はnoteが空）
      endMode.value = 'LAST';
    }
    if (endTime){ endTime.disabled = false; endTime.focus(); }
    if (bLast) bLast.classList.remove('active');
    if (bTime) bTime.classList.add('active');
  }
  updateSummary(ymd);
}

function allOn(){
  document.querySelectorAll('div.day').forEach(day=>{
    const ymd = day.getAttribute('data-ymd');
    if (ymd) setDayState(ymd, true);
  });
}
function allOff(){
  document.querySelectorAll('div.day').forEach(day=>{
    const ymd = day.getAttribute('data-ymd');
    if (ymd) setDayState(ymd, false);
  });
}
document.querySelectorAll('input[id^="time_"]').forEach(inp=>{
  inp.addEventListener('change', ()=>{
    const ymd = inp.id.replace('time_','');
    updateSummary(ymd);
  });
});

document.querySelectorAll('input[id^="endtime_"]').forEach(inp=>{
  inp.addEventListener('change', ()=>{
    const ymd = inp.id.replace('endtime_','');
    const endMode = document.getElementById('endmode_'+ymd);
    const bLast = document.getElementById('endbtn_last_'+ymd);
    const bTime = document.getElementById('endbtn_time_'+ymd);
    if (inp.value && /^\d{2}:\d{2}$/.test(inp.value)){
      if (endMode) endMode.value = inp.value;
      if (bLast) bLast.classList.remove('active');
      if (bTime) bTime.classList.add('active');
    }
    updateSummary(ymd);
  });
});

document.querySelectorAll('div.day').forEach(day=>{
  const ymd = day.getAttribute('data-ymd');
  const onInput = document.getElementById('on_'+ymd);
  if (ymd && onInput) setDayState(ymd, onInput.value === '1');
});

<?php if ($msg): ?>
(function(){
  const toast = document.createElement('div');
  toast.className = 'toast';
  toast.textContent = <?= json_encode($msg, JSON_UNESCAPED_UNICODE) ?>;
  document.body.appendChild(toast);
  requestAnimationFrame(() => toast.classList.add('show'));
  window.setTimeout(() => {
    toast.classList.remove('show');
    window.setTimeout(() => toast.remove(), 260);
  }, 2200);
})();
<?php endif; ?>
</script>

<?php render_page_end(); ?>
