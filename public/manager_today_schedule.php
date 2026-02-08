<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['manager','admin','super_user']);

$pdo = db();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/* =========================
   role 判定
========================= */
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');

function current_user_id_safe(): int {
  return function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);
}

function current_staff_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.code IN ('admin','manager')
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
    LIMIT 1
  ");
  $st->execute([$userId]);
  return (int)($st->fetchColumn() ?: 0);
}

/* =========================
   店舗決定（superは切替可）
========================= */
$userId = current_user_id_safe();

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? 0);
  if ($storeId <= 0) {
    $st = $pdo->query("SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1");
    $storeId = (int)$st->fetchColumn();
  }
} else {
  $storeId = current_staff_store_id($pdo, $userId);
}

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が特定できません（user_roles の admin/manager に store_id を設定してください）');
}

$st = $pdo->prepare("SELECT id,name,business_day_start,weekly_holiday_dow FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$store = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$store) exit('店舗が見つかりません');

/* =========================
   営業日（business_date）
========================= */
function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }

function business_date_for_store(array $storeRow, ?DateTime $now=null): string {
  $now = $now ?: jst_now();
  $cut = (string)($storeRow['business_day_start'] ?? '06:00:00');
  $cutDT = new DateTime($now->format('Y-m-d') . ' ' . $cut, new DateTimeZone('Asia/Tokyo'));
  if ($now < $cutDT) $now->modify('-1 day');
  return $now->format('Y-m-d');
}

$bizDate = business_date_for_store($store);

/* =========================
   今日（予定×実績）
   - cast_shift_plans と attendances を突き合わせ
========================= */
$st = $pdo->prepare("
  SELECT
    u.id AS user_id,
    u.display_name,
    COALESCE(cp.employment_type,'part_time') AS employment_type,

    sp.start_time AS plan_start_time,
    sp.is_off AS plan_is_off,

    a.clock_in,
    a.clock_out,
    a.status AS att_status,
    a.source_in,
    a.source_out

  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id
  LEFT JOIN cast_profiles cp ON cp.user_id=u.id

  LEFT JOIN cast_shift_plans sp
    ON sp.store_id=ur.store_id
   AND sp.user_id=u.id
   AND sp.business_date=?

  LEFT JOIN attendances a
    ON a.store_id=ur.store_id
   AND a.user_id=u.id
   AND a.business_date=?

  WHERE ur.store_id=?
  ORDER BY u.is_active DESC, u.id ASC
");
$st->execute([$bizDate, $bizDate, $storeId]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
   遅刻/欠勤 通知履歴（今日分）
   - 最新を user_id + kind で拾う
========================= */
$noticeMap = []; // [user_id][kind] => row
$st = $pdo->prepare("
  SELECT a.*,
         su.login_id AS sender_login
  FROM line_notice_actions a
  LEFT JOIN users su ON su.id=a.sent_by_user_id
  WHERE a.store_id=? AND a.business_date=?
  ORDER BY a.sent_at DESC
");
$st->execute([$storeId, $bizDate]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $a) {
  $uid = (int)$a['cast_user_id'];
  $k   = (string)$a['kind'];
  if (!isset($noticeMap[$uid][$k])) $noticeMap[$uid][$k] = $a; // 最新だけ
}

/* =========================
   集計（未出勤/出勤中/退勤済/休み/遅刻）
========================= */
$cnt = [
  'not_in' => 0,
  'working' => 0,
  'finished' => 0,
  'off' => 0,
  'late' => 0,
];

$now = jst_now();

foreach ($rows as $r) {
  $isOff = ((int)($r['plan_is_off'] ?? 0) === 1);
  if ($isOff) { $cnt['off']++; continue; }

  $clockIn = $r['clock_in'] ?? null;
  $clockOut = $r['clock_out'] ?? null;

  if ($clockOut) { $cnt['finished']++; continue; }
  if ($clockIn) { $cnt['working']++; continue; }

  // 未出勤
  $cnt['not_in']++;

  // 遅刻判定：予定 start_time があり、今がそれを過ぎている
  $pst = (string)($r['plan_start_time'] ?? '');
  if ($pst !== '') {
    $planDT = new DateTime($bizDate . ' ' . substr($pst,0,5) . ':00', new DateTimeZone('Asia/Tokyo'));
    if ($now > $planDT) $cnt['late']++;
  }
}

/* =========================
   画面
========================= */
render_page_start('本日の予定');
render_header('本日の予定');
?>

<div class="page">
  <div class="admin-wrap">

    <div class="rowTop">
      <a class="btn" href="/seika-app/public/dashboard.php">← ダッシュボード</a>
      <div class="title">🗓 本日の予定 × 実績</div>
    </div>

    <div class="muted" style="margin-top:6px;">
      店舗：<b><?= h((string)$store['name']) ?> (#<?= (int)$storeId ?>)</b>
      / 営業日：<b><?= h($bizDate) ?></b>
      <span class="muted">（現在 <?= h($now->format('Y-m-d H:i')) ?>）</span>
    </div>

    <?php if ($isSuper): ?>
      <?php
        $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      ?>
      <form method="get" class="searchRow">
        <select name="store_id" class="sel">
          <?php foreach ($stores as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
              <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
        <button class="btn">切替</button>
      </form>
    <?php endif; ?>

    <div class="kpi">
      <div class="k">未出勤<br><b><?= $cnt['not_in'] ?></b></div>
      <div class="k">出勤中<br><b><?= $cnt['working'] ?></b></div>
      <div class="k">退勤済<br><b><?= $cnt['finished'] ?></b></div>
      <div class="k">休み<br><b><?= $cnt['off'] ?></b></div>
      <div class="k">遅刻<br><b><?= $cnt['late'] ?></b></div>
    </div>

    <div class="card" style="margin-top:14px;">
      <div class="cardTitle">一覧（予定と実績を突き合わせ）</div>

      <table class="tbl">
        <thead>
          <tr>
            <th>状態</th>
            <th>名前</th>
            <th>予定</th>
            <th>実績</th>
            <th>遅刻</th>
            <th>連絡</th>
            <th>送信</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($rows as $r):
          $uid = (int)$r['user_id'];
          $name = (string)$r['display_name'];

          $planOff = ((int)($r['plan_is_off'] ?? 0) === 1);
          $planStart = (string)($r['plan_start_time'] ?? '');

          $clockIn = $r['clock_in'] ? substr((string)$r['clock_in'], 11, 5) : '';
          $clockOut = $r['clock_out'] ? substr((string)$r['clock_out'], 11, 5) : '';

          $statusLabel = '未出勤';
          if ($planOff) $statusLabel = '休み';
          else if ($r['clock_out']) $statusLabel = '退勤済';
          else if ($r['clock_in']) $statusLabel = '出勤中';

          $isLate = false;
          if (!$planOff && $planStart !== '' && !$r['clock_in']) {
            $planDT = new DateTime($bizDate . ' ' . substr($planStart,0,5) . ':00', new DateTimeZone('Asia/Tokyo'));
            if ($now > $planDT) $isLate = true;
          }

          $lateNotice = $noticeMap[$uid]['late'] ?? null;
          $absNotice  = $noticeMap[$uid]['absent'] ?? null;

          $replyText = '';
          $replyWhen = '';
          // 返信は「遅刻/欠勤どっちでも」最後に来たやつを見せる（現場用）
          $cand = [];
          if ($lateNotice && !empty($lateNotice['last_reply_text'])) $cand[] = $lateNotice;
          if ($absNotice && !empty($absNotice['last_reply_text']))  $cand[] = $absNotice;
          if ($cand) {
            usort($cand, fn($a,$b)=>strcmp((string)$b['responded_at'], (string)$a['responded_at']));
            $replyText = (string)($cand[0]['last_reply_text'] ?? '');
            $replyWhen = (string)($cand[0]['responded_at'] ?? '');
          }

          // デフォルトテンプレ
          $tplLate = "{$name}さん\n遅刻の連絡をお願いします。\n到着予定時刻と理由を返信してください。";
          $tplAbs  = "{$name}さん\n本日欠勤の場合は理由を返信してください。";
        ?>
          <tr>
            <td><?= h($statusLabel) ?></td>
            <td>
              <b><?= h($name) ?></b>
              <div class="muted"><?= h((string)$r['employment_type']) ?></div>
            </td>
            <td>
              <?= $planOff ? '<span class="muted">OFF</span>' : '<b>'.h(substr($planStart,0,5)).'</b>' ?>
            </td>
            <td>
              <?= h($clockIn ?: '--:--') ?> → <?= h($clockOut ?: '--:--') ?>
            </td>
            <td>
              <?= $isLate ? '<span class="badge-red">遅刻</span>' : '<span class="muted">-</span>' ?>
            </td>

            <!-- 返信（自動反映） -->
            <td style="max-width:360px;">
              <?php if ($replyText !== ''): ?>
                <div class="replyBox">
                  <div class="replyText"><?= nl2br(h(mb_strimwidth($replyText, 0, 160, '…', 'UTF-8'))) ?></div>
                  <div class="muted">返信: <?= h(substr($replyWhen, 11, 5)) ?></div>
                </div>
              <?php else: ?>
                <span class="muted">（返信なし）</span>
              <?php endif; ?>
            </td>

            <!-- 遅刻/欠勤 LINE -->
            <td>
              <div class="btnRow">
                <button
                  type="button"
                  class="btn ghost js-open-modal"
                  data-kind="late"
                  data-cast="<?= (int)$uid ?>"
                  data-name="<?= h($name) ?>"
                  data-text="<?= h($tplLate) ?>"
                >遅刻LINE</button>

                <button
                  type="button"
                  class="btn ghost js-open-modal"
                  data-kind="absent"
                  data-cast="<?= (int)$uid ?>"
                  data-name="<?= h($name) ?>"
                  data-text="<?= h($tplAbs) ?>"
                >欠勤LINE</button>
              </div>
            </td>

            <!-- 送信履歴 -->
            <td class="muted" style="white-space:nowrap;">
              <?php
                $lastSent = null;
                if ($lateNotice) $lastSent = $lateNotice;
                if ($absNotice && (!$lastSent || (string)$absNotice['sent_at'] > (string)$lastSent['sent_at'])) $lastSent = $absNotice;
              ?>
              <?php if ($lastSent): ?>
                <?= h(substr((string)$lastSent['sent_at'], 11, 5)) ?>
                <div class="muted">by <?= h((string)($lastSent['sender_login'] ?? '')) ?></div>
                <?php if (($lastSent['status'] ?? '') === 'failed'): ?>
                  <div class="badge-red">送信失敗</div>
                <?php endif; ?>
              <?php else: ?>
                -
              <?php endif; ?>
            </td>

          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

  </div>
</div>

<!-- 送信モーダル -->
<div class="modalBg" id="modalBg" hidden>
  <div class="modal">
    <div class="modalHead">
      <div class="modalTitle" id="modalTitle">LINE送信</div>
      <button type="button" class="btn ghost" id="modalClose">✕</button>
    </div>

    <div class="muted" id="modalSub" style="margin-top:4px;"></div>

    <form method="post" action="/seika-app/public/api/line_notice_send.php" id="modalForm">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="business_date" value="<?= h($bizDate) ?>">
      <input type="hidden" name="cast_user_id" id="m_cast_user_id" value="">
      <input type="hidden" name="kind" id="m_kind" value="">

      <textarea name="text" id="m_text" class="ta" rows="7" required></textarea>

      <div class="modalFoot">
        <button type="button" class="btn" id="modalCancel">キャンセル</button>
        <button type="submit" class="btn primary" id="modalSend">送信</button>
      </div>
      <div class="muted" id="modalMsg" style="margin-top:8px;"></div>
    </form>
  </div>
</div>

<style>
.rowTop{ display:flex; align-items:center; gap:12px; }
.title{ font-weight:1000; font-size:18px; }
.btn{ display:inline-flex; align-items:center; gap:6px; padding:10px 14px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; text-decoration:none; cursor:pointer; }
.btn.primary{ background:rgba(59,130,246,.18); border-color:rgba(59,130,246,.35); }
.btn.ghost{ background:transparent; }
.searchRow{ margin-top:10px; display:flex; gap:10px; align-items:center; }
.sel{ padding:10px 12px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); color:inherit; }
.kpi{ margin-top:10px; display:grid; grid-template-columns:repeat(auto-fit,minmax(120px,1fr)); gap:10px; }
.k{ padding:12px; border:1px solid var(--line); border-radius:14px; background:var(--cardA); text-align:center; }
.card{ padding:14px; border:1px solid var(--line); border-radius:14px; background:var(--cardA); }
.cardTitle{ font-weight:900; margin-bottom:10px; }
.tbl{ width:100%; border-collapse:collapse; }
.tbl th,.tbl td{ padding:10px; border-bottom:1px solid rgba(255,255,255,.08); vertical-align:top; }
.muted{ opacity:.75; font-size:12px; }
.badge-red{ display:inline-block; background:#ef4444; color:#fff; font-size:11px; padding:2px 8px; border-radius:999px; }
.btnRow{ display:flex; gap:8px; flex-wrap:wrap; }

.replyBox{ padding:8px 10px; border:1px solid rgba(255,255,255,.12); border-radius:12px; background:rgba(255,255,255,.04); }
.replyText{ font-size:13px; white-space:normal; line-height:1.35; }

.modalBg{
  position:fixed; inset:0;
  background:rgba(0,0,0,.55);
  display:flex; align-items:center; justify-content:center;
  padding:16px;
  z-index:1000;
}
.modal{
  width:min(720px, 96vw);
  border:1px solid rgba(255,255,255,.14);
  border-radius:16px;
  background:#0f1730;
  padding:14px;
}
.modalHead{ display:flex; justify-content:space-between; align-items:center; }
.modalTitle{ font-weight:1000; font-size:16px; }
.ta{
  width:100%;
  margin-top:10px;
  padding:12px;
  border-radius:12px;
  border:1px solid rgba(255,255,255,.16);
  background:rgba(255,255,255,.04);
  color:#e8ecff;
  resize:vertical;
}
.modalFoot{ display:flex; justify-content:flex-end; gap:10px; margin-top:10px; }
</style>

<script>
(() => {
  const bg = document.getElementById('modalBg');
  const closeBtn = document.getElementById('modalClose');
  const cancelBtn = document.getElementById('modalCancel');
  const title = document.getElementById('modalTitle');
  const sub = document.getElementById('modalSub');
  const msg = document.getElementById('modalMsg');

  const inCast = document.getElementById('m_cast_user_id');
  const inKind = document.getElementById('m_kind');
  const ta = document.getElementById('m_text');
  const form = document.getElementById('modalForm');
  const sendBtn = document.getElementById('modalSend');

  function openModal(kind, castId, name, text){
    msg.textContent = '';
    inCast.value = castId;
    inKind.value = kind;

    title.textContent = (kind === 'late') ? '遅刻LINE 送信' : '欠勤LINE 送信';
    sub.textContent = `宛先：${name}（user_id=${castId}）`;
    ta.value = text;

    bg.hidden = false;
    setTimeout(() => ta.focus(), 50);
  }
  function closeModal(){
    bg.hidden = true;
  }

  document.querySelectorAll('.js-open-modal').forEach(btn => {
    btn.addEventListener('click', () => {
      openModal(btn.dataset.kind, btn.dataset.cast, btn.dataset.name, btn.dataset.text);
    });
  });

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  bg.addEventListener('click', (e) => { if (e.target === bg) closeModal(); });

  form.addEventListener('submit', async (e) => {
    e.preventDefault();
    msg.textContent = '送信中…';
    sendBtn.disabled = true;

    try {
      const fd = new FormData(form);
      const res = await fetch(form.action, { method:'POST', body: fd });
      const json = await res.json().catch(() => null);

      if (!res.ok || !json || !json.ok) {
        msg.textContent = '送信失敗：' + (json && json.error ? json.error : ('HTTP ' + res.status));
        sendBtn.disabled = false;
        return;
      }

      msg.textContent = '✅ 送信しました（返信はこの画面に自動反映されます）';
      setTimeout(() => location.reload(), 600);

    } catch (err) {
      msg.textContent = '送信失敗：通信エラー';
      sendBtn.disabled = false;
    }
  });
})();
</script>

<?php render_page_end(); ?>