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

/* =========================
  Role helpers
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
  Store selection
========================= */
$userId = current_user_id_safe();

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? ($_POST['store_id'] ?? 0));
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

$store = null;
$st = $pdo->prepare("SELECT id, name, weekly_holiday_dow, open_time FROM stores WHERE id=? LIMIT 1");
$st->execute([$storeId]);
$store = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$store) exit('店舗が見つかりません');

$holidayDow = $store['weekly_holiday_dow'];
$holidayDow = ($holidayDow === null) ? null : (int)$holidayDow; // 0=Sun..6=Sat

/* =========================
  Week calc
========================= */
function jst_now(): DateTime { return new DateTime('now', new DateTimeZone('Asia/Tokyo')); }
function normalize_date(string $ymd, ?string $fallback = null): string {
  $ymd = trim($ymd);
  if ($ymd === '') $ymd = (string)$fallback;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $ymd)) return (new DateTime('today'))->format('Y-m-d');
  return $ymd;
}
function dow0(DateTime $d): int { return (int)$d->format('w'); } // 0=Sun..6=Sat
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

$weekStartDow0 = ($holidayDow === null) ? 1 : (($holidayDow + 1) % 7); // holiday翌日が週開始
$baseDate = normalize_date((string)($_GET['date'] ?? $_POST['date'] ?? ''), jst_now()->format('Y-m-d'));
$weekStart = week_start_date($baseDate, $weekStartDow0);
$dates = week_dates($weekStart);

/* =========================
  Masters
========================= */
$stores = [];
if ($isSuper) {
  $stores = $pdo->query("SELECT id,name,is_active FROM stores ORDER BY is_active DESC, id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$castRows = [];
$st = $pdo->prepare("
  SELECT
    u.id,
    u.display_name,
    u.is_active,
    COALESCE(cp.employment_type, 'part_time') AS employment_type,
    cp.default_start_time
  FROM user_roles ur
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  JOIN users u ON u.id=ur.user_id
  LEFT JOIN cast_profiles cp ON cp.user_id=u.id
  WHERE ur.store_id=?
  ORDER BY u.is_active DESC, u.id ASC
");
$st->execute([$storeId]);
$castRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

/* =========================
  Load existing plans
========================= */
$plans = []; // [user_id][ymd] => ['start_time'=>..., 'is_off'=>...]
$st = $pdo->prepare("
  SELECT user_id, business_date, start_time, is_off
  FROM cast_shift_plans
  WHERE store_id=?
    AND business_date BETWEEN ? AND ?
");
$st->execute([$storeId, $dates[0], $dates[6]]);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
  $uid = (int)$r['user_id'];
  $d   = (string)$r['business_date'];
  $plans[$uid][$d] = [
    'start_time' => $r['start_time'] !== null ? substr((string)$r['start_time'], 0, 5) : '',
    'is_off'     => ((int)$r['is_off'] === 1),
  ];
}

/* =========================
  POST Save
  - 予定保存
  - cast_profiles（勤務形態・基本開始）も同時更新
========================= */
$msg = '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($isSuper) $storeId = (int)($_POST['store_id'] ?? $storeId);

    $weekStart = normalize_date((string)($_POST['week_start'] ?? $weekStart), $weekStart);
    $dates = week_dates($weekStart);

    $pdo->beginTransaction();

    // cast_profiles 更新
    $upProf = $pdo->prepare("
      INSERT INTO cast_profiles (user_id, store_id, employment_type, default_start_time, updated_at)
      VALUES (?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        store_id=VALUES(store_id),
        employment_type=VALUES(employment_type),
        default_start_time=VALUES(default_start_time),
        updated_at=NOW()
    ");

    foreach ($castRows as $c) {
      $uid = (int)$c['id'];

      $etypeKey = "etype_{$uid}";
      $dstKey   = "dst_{$uid}";

      $etype = (string)($_POST[$etypeKey] ?? 'part_time');
      if (!in_array($etype, ['regular','part_time'], true)) $etype = 'part_time';

      $dst = trim((string)($_POST[$dstKey] ?? ''));
      $dstTime = null;
      if ($dst !== '') {
        // "21:00" -> "21:00:00"
        if (preg_match('/^\d{2}:\d{2}$/', $dst)) $dstTime = $dst . ':00';
      }

      $upProf->execute([$uid, $storeId, $etype, $dstTime]);
    }

    // cast_shift_plans 更新
    $upPlan = $pdo->prepare("
      INSERT INTO cast_shift_plans
        (store_id, user_id, business_date, start_time, is_off, status, created_by_user_id, created_at, updated_at)
      VALUES
        (?, ?, ?, ?, ?, 'planned', ?, NOW(), NOW())
      ON DUPLICATE KEY UPDATE
        start_time=VALUES(start_time),
        is_off=VALUES(is_off),
        status='planned',
        updated_at=NOW()
    ");

    foreach ($castRows as $c) {
      $uid = (int)$c['id'];
      foreach ($dates as $d) {
        $keyTime = "t_{$uid}_{$d}";
        $keyOff  = "off_{$uid}_{$d}";

        $time = trim((string)($_POST[$keyTime] ?? ''));
        $off  = isset($_POST[$keyOff]) ? 1 : 0;
        if ($off === 1) $time = '';

        // 未入力＆offでもない -> 保存しない（バイト運用）
        if ($time === '' && $off === 0) continue;

        $startTime = ($time !== '') ? ($time . ':00') : null;
        $upPlan->execute([$storeId, $uid, $d, $startTime, $off, $userId]);
      }
    }

    $pdo->commit();
    header('Location: /seika-app/public/cast_week_plans.php?store_id=' . $storeId . '&date=' . urlencode($weekStart) . '&ok=1');
    exit;

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

/* =========================
  UI helpers
========================= */
$jpW = ['日','月','火','水','木','金','土'];
function dow_label(string $ymd, array $jpW): string {
  $dt = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  return $jpW[(int)$dt->format('w')];
}
function is_holiday_col(string $ymd, ?int $holidayDow): bool {
  if ($holidayDow === null) return false;
  $dt = new DateTime($ymd, new DateTimeZone('Asia/Tokyo'));
  return ((int)$dt->format('w') === $holidayDow);
}

$timeOptions = ['19:00','20:00','21:00','22:00'];

render_page_start('週予定入力');
render_header('週予定入力', [
  'back_href'  => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);

$ok = (string)($_GET['ok'] ?? '') === '1';
?>
<div class="page">
  <div class="admin-wrap">

    <?php if ($ok): ?>
      <div class="card" style="border-color:rgba(52,211,153,.35);">✅ 保存しました</div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="card">
      <div style="display:flex; gap:12px; justify-content:space-between; align-items:flex-end; flex-wrap:wrap;">
        <div>
          <div style="font-weight:1000; font-size:18px;">🗓 週予定入力</div>
          <div class="muted" style="margin-top:4px;">
            店舗：<?= h((string)$store['name']) ?>
            <?php if ($holidayDow !== null): ?>
              / 店休日：<?= $jpW[$holidayDow] ?>
              / 週開始：<?= $jpW[$weekStartDow0] ?>
            <?php endif; ?>
          </div>
        </div>

        <form method="get" action="/seika-app/public/cast_week_plans.php" class="searchRow">
          <?php if ($isSuper): ?>
            <select class="btn" name="store_id">
              <?php foreach ($stores as $s): ?>
                <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===(int)$storeId)?'selected':'' ?>>
                  <?= h((string)$s['name']) ?><?= ((int)$s['is_active']===1)?'':'（無効）' ?>
                </option>
              <?php endforeach; ?>
            </select>
          <?php else: ?>
            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <?php endif; ?>

          <div>
            <div class="muted" style="font-size:12px;">基準日</div>
            <input class="btn" type="date" name="date" value="<?= h($baseDate) ?>">
          </div>

          <button class="btn btn-primary" type="submit">表示</button>
        </form>
      </div>

      <div style="display:flex; gap:10px; margin-top:12px; flex-wrap:wrap;">
        <button type="button" class="btn" onclick="fillRegularDefaults()">レギュラーに基本開始を反映</button>
        <button type="button" class="btn" onclick="holidayOffAuto()">店休日をOFFにする</button>
        <button type="button" class="btn" onclick="clearAll()">全消し（バイト向け）</button>
      </div>

      <div class="muted" style="margin-top:10px; font-size:12px;">
        ・店休日列は初期表示で「休み」に寄せます（既に入力がある場合は尊重）<br>
        ・レギュラーは「基本開始」を入れておくと、週作成が爆速になります
      </div>
    </div>

    <form method="post" action="/seika-app/public/cast_week_plans.php">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="week_start" value="<?= h($weekStart) ?>">

      <div class="card" style="margin-top:14px;">
        <div style="font-weight:1000; margin-bottom:10px;">👥 週予定（<?= h($weekStart) ?> 〜 <?= h($dates[6]) ?>）</div>

        <div style="overflow:auto;">
          <table class="tbl" style="min-width:1100px;">
            <thead>
              <tr>
                <th style="min-width:220px;">キャスト</th>
                <?php foreach ($dates as $d): ?>
                  <?php $isHol = is_holiday_col($d, $holidayDow); ?>
                  <th style="min-width:130px; <?= $isHol ? 'background:rgba(251,113,133,.08);' : '' ?>">
                    <?= h(substr($d,5)) ?>（<?= h(dow_label($d, $jpW)) ?>）
                    <?php if ($isHol): ?><div class="muted" style="font-size:11px;">店休日</div><?php endif; ?>
                  </th>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
            <?php if (!$castRows): ?>
              <tr><td colspan="8" class="muted" style="padding:12px;">キャストがいません</td></tr>
            <?php else: ?>
              <?php foreach ($castRows as $c): ?>
                <?php
                  $uid = (int)$c['id'];
                  $inactive = ((int)$c['is_active'] !== 1);
                  $etype = (string)($c['employment_type'] ?? 'part_time');
                  $dst = $c['default_start_time'] ? substr((string)$c['default_start_time'], 0, 5) : '';
                ?>
                <tr class="castRow" data-uid="<?= $uid ?>" data-etype="<?= h($etype) ?>" data-dst="<?= h($dst) ?>">
                  <td>
                    <div style="font-weight:1000;">
                      <?= h((string)$c['display_name']) ?>
                      <?php if ($inactive): ?>
                        <span class="badge" style="margin-left:6px; background:rgba(251,113,133,.16);">
                          <span class="dot" style="background:var(--ng)"></span>無効
                        </span>
                      <?php endif; ?>
                    </div>

                    <div style="display:flex; gap:8px; align-items:center; margin-top:8px; flex-wrap:wrap;">
                      <label class="muted" style="display:flex; gap:6px; align-items:center;">
                        形態
                        <select class="btn" name="etype_<?= $uid ?>" style="width:120px;">
                          <option value="part_time" <?= $etype==='part_time'?'selected':'' ?>>バイト</option>
                          <option value="regular" <?= $etype==='regular'?'selected':'' ?>>レギュラー</option>
                        </select>
                      </label>

                      <label class="muted" style="display:flex; gap:6px; align-items:center;">
                        基本開始
                        <select class="btn" name="dst_<?= $uid ?>" style="width:110px;">
                          <option value="">--</option>
                          <?php foreach ($timeOptions as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($opt===$dst)?'selected':'' ?>><?= h($opt) ?></option>
                          <?php endforeach; ?>
                        </select>
                      </label>
                    </div>

                    <div class="muted" style="font-size:12px; margin-top:6px;">#<?= $uid ?></div>
                  </td>

                  <?php foreach ($dates as $d): ?>
                    <?php
                      $p = $plans[$uid][$d] ?? ['start_time'=>'', 'is_off'=>false];
                      $time = (string)$p['start_time'];
                      $off  = (bool)$p['is_off'];
                      $isHol = is_holiday_col($d, $holidayDow);

                      // ★ 店休日は「初期表示でOFF」：ただし既に time があるなら尊重
                      $shouldOff = $isHol && ($time === '') && (!$off);

                      $nameT = "t_{$uid}_{$d}";
                      $nameO = "off_{$uid}_{$d}";

                      $finalOff = $off || $shouldOff;
                    ?>
                    <td style="<?= $isHol ? 'background:rgba(251,113,133,.04);' : '' ?>">
                      <div style="display:flex; gap:8px; align-items:center;">
                        <select class="btn timeSel" name="<?= h($nameT) ?>" data-uid="<?= $uid ?>" data-date="<?= h($d) ?>" style="width:82px;" <?= $finalOff?'disabled':'' ?>>
                          <option value="">--</option>
                          <?php foreach ($timeOptions as $opt): ?>
                            <option value="<?= h($opt) ?>" <?= ($opt===$time)?'selected':'' ?>><?= h($opt) ?></option>
                          <?php endforeach; ?>
                        </select>

                        <label class="muted" style="display:flex; gap:6px; align-items:center;">
                          <input type="checkbox" name="<?= h($nameO) ?>" class="offChk" data-uid="<?= $uid ?>" data-date="<?= h($d) ?>" <?= $finalOff?'checked':'' ?> data-holiday="<?= $isHol?1:0 ?>">
                          休
                        </label>
                      </div>
                    </td>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>

        <div style="display:flex; justify-content:flex-end; margin-top:12px;">
          <button class="btn btn-primary" type="submit">保存</button>
        </div>
      </div>
    </form>

  </div>
</div>

<script>
(function(){
  // 休みチェックで time select をdisable
  document.querySelectorAll('.offChk').forEach(chk => {
    chk.addEventListener('change', () => {
      const td = chk.closest('td');
      const sel = td ? td.querySelector('select.timeSel') : null;
      if (!sel) return;
      if (chk.checked) {
        sel.value = '';
        sel.disabled = true;
      } else {
        sel.disabled = false;
      }
    });
  });
})();

function fillRegularDefaults(){
  // レギュラーだけ：基本開始（dst）を「空のセルにだけ」入れる
  document.querySelectorAll('tr.castRow').forEach(row => {
    if (row.dataset.etype !== 'regular') return;

    const dstSel = row.querySelector('select[name^="dst_"]');
    const dst = dstSel ? dstSel.value : (row.dataset.dst || '');
    if (!dst) return;

    row.querySelectorAll('td select.timeSel').forEach(sel => {
      const td = sel.closest('td');
      const chk = td ? td.querySelector('input.offChk') : null;
      if (!chk) return;

      // 休みなら触らない
      if (chk.checked) return;

      // 空なら埋める
      if (!sel.value) sel.value = dst;
    });
  });
}

function holidayOffAuto(){
  // 店休日セルだけ：空ならOFFにする（入力済みは尊重）
  document.querySelectorAll('input.offChk[data-holiday="1"]').forEach(chk => {
    const td = chk.closest('td');
    const sel = td ? td.querySelector('select.timeSel') : null;
    if (!sel) return;

    if (!sel.value) {
      chk.checked = true;
      sel.value = '';
      sel.disabled = true;
    }
  });
}

function clearAll(){
  // 全クリア（保存されないので、バイト用：必要日だけ入れる）
  document.querySelectorAll('select.timeSel').forEach(sel => {
    sel.value = '';
    sel.disabled = false;
  });
  document.querySelectorAll('.offChk').forEach(chk => chk.checked = false);
}
</script>

<?php render_page_end(); ?>