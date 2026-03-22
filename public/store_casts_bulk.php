<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_store_casts.php';

require_login();
require_role(['admin', 'super_user']);

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = db();
$stores = $pdo->query("SELECT id, name FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];

$selectedStoreId = (int)($_GET['store_id'] ?? $_POST['selected_store_id'] ?? 0);
$selectedStoreName = '';
foreach ($stores as $store) {
  if ((int)($store['id'] ?? 0) === $selectedStoreId) {
    $selectedStoreName = (string)($store['name'] ?? '');
    break;
  }
}
if ($selectedStoreId > 0 && $selectedStoreName === '') {
  $selectedStoreId = 0;
}

$defaultRows = [];
for ($i = 0; $i < 12; $i++) {
  $defaultRows[] = [
    'staff_code' => '',
    'display_name' => '',
    'employment_type' => 'part',
  ];
}

$rows = $defaultRows;
$msg = '';
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  $inputRows = $_POST['rows'] ?? [];
  $rows = [];
  foreach ($inputRows as $row) {
    if (!is_array($row)) continue;
    $rows[] = [
      'staff_code' => trim((string)($row['staff_code'] ?? '')),
      'display_name' => trim((string)($row['display_name'] ?? '')),
      'employment_type' => (string)($row['employment_type'] ?? 'part'),
    ];
  }
  if ($rows === []) {
    $rows = $defaultRows;
  }

  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    if ($selectedStoreId <= 0) {
      throw new InvalidArgumentException('先に店舗を選択してください');
    }
    $payloadRows = array_map(static function(array $row) use ($selectedStoreId): array {
      $row['store_id'] = $selectedStoreId;
      return $row;
    }, $rows);
    $createdCount = service_add_store_casts_bulk($pdo, $payloadRows);
    $msg = $createdCount . '件のキャストを追加しました';
    $rows = $defaultRows;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

render_page_start('全店キャスト一括登録');
render_header('全店キャスト一括登録', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '<a class="btn" href="/wbss/public/store_casts.php">店別キャスト管理</a>',
]);
?>
<div class="page"><div class="admin-wrap bulk-shell">
  <?php if ($msg !== ''): ?>
    <div class="card notice-ok"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if ($err !== ''): ?>
    <div class="card notice-err"><?= h($err) ?></div>
  <?php endif; ?>

  <section class="hero">
    <div>
      <div class="eyebrow">Bulk Entry</div>
      <h1>全店舗のキャストをまとめて登録</h1>
      <p class="muted">最初に店舗を選んで、その店舗のキャストだけをまとめて登録します。登録時に、その店舗の `cast` ロールと `store_users` 所属を自動で付与するので、現場ではあとからLINE連携をしてもらう運用に繋げやすくしています。</p>
    </div>
    <div class="tips">
      <div class="tip">同じ店舗で店番が重複している行は保存前に弾きます。</div>
      <div class="tip">店番が2桁ならレギュラー、3桁ならバイトを自動補完します。必要なら手動変更もできます。</div>
      <div class="tip">ログインIDとパスワードは内部で自動生成されます。</div>
    </div>
  </section>

  <section class="card">
    <div class="store-picker-head">
      <div>
        <div class="card-title">店舗を選択</div>
        <div class="muted">先に店舗を固定してから入力すると、登録ミスを減らせます。</div>
      </div>
      <?php if ($selectedStoreId > 0): ?>
        <div class="selected-store-badge">選択中: <?= h($selectedStoreName) ?></div>
      <?php endif; ?>
    </div>
    <div class="store-picker">
      <?php foreach ($stores as $store): ?>
        <?php $storeId = (int)($store['id'] ?? 0); ?>
        <a
          class="store-pill<?= $storeId === $selectedStoreId ? ' is-active' : '' ?>"
          href="/wbss/public/store_casts_bulk.php?store_id=<?= $storeId ?>"
        ><?= h((string)($store['name'] ?? '')) ?></a>
      <?php endforeach; ?>
    </div>
  </section>

  <?php if ($selectedStoreId > 0): ?>
  <form method="post" class="card">
    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
    <input type="hidden" name="selected_store_id" value="<?= (int)$selectedStoreId ?>">

    <div class="form-head">
      <div>
        <div class="card-title">入力対象: <?= h($selectedStoreName) ?></div>
        <div class="muted">この画面で登録する行はすべて <?= h($selectedStoreName) ?> に追加されます。</div>
      </div>
      <a class="btn" href="/wbss/public/store_casts.php?store_id=<?= (int)$selectedStoreId ?>">この店舗の管理画面へ</a>
    </div>

    <div class="table-wrap">
      <table class="tbl" id="bulk-cast-table">
        <thead>
          <tr>
            <th>#</th>
            <th>店番</th>
            <th>名前</th>
            <th>雇用区分</th>
          </tr>
        </thead>
        <tbody id="bulk-cast-body">
          <?php foreach ($rows as $index => $row): ?>
            <tr>
              <td class="num js-row-no"><?= $index + 1 ?></td>
              <td><input class="input mono js-staff-code" name="rows[<?= $index ?>][staff_code]" value="<?= h((string)$row['staff_code']) ?>" maxlength="32" inputmode="numeric" placeholder="例 12"></td>
              <td><input class="input" name="rows[<?= $index ?>][display_name]" value="<?= h((string)$row['display_name']) ?>" maxlength="100" placeholder="例 りん"></td>
              <td>
                <select class="input js-employment-type" name="rows[<?= $index ?>][employment_type]">
                  <option value="regular" <?= (string)$row['employment_type'] === 'regular' ? 'selected' : '' ?>>レギュラー</option>
                  <option value="part" <?= (string)$row['employment_type'] !== 'regular' ? 'selected' : '' ?>>バイト</option>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="actions">
      <button type="button" class="btn" id="add-row-btn">行を追加</button>
      <button type="submit" class="btn btn-primary">まとめて登録</button>
    </div>
  </form>
  <?php endif; ?>
</div></div>

<template id="bulk-cast-row-template">
  <tr>
    <td class="num js-row-no"></td>
    <td><input class="input mono js-staff-code" maxlength="32" inputmode="numeric" placeholder="例 12"></td>
    <td><input class="input js-display-name" maxlength="100" placeholder="例 りん"></td>
    <td>
      <select class="input js-employment-type">
        <option value="regular">レギュラー</option>
        <option value="part" selected>バイト</option>
      </select>
    </td>
  </tr>
</template>

<style>
.bulk-shell{display:grid;gap:16px}
.hero,.card{border:1px solid var(--line);background:var(--cardA);border-radius:18px;box-shadow:var(--shadow)}
.hero{padding:18px;display:grid;gap:14px}
.eyebrow{font-size:12px;font-weight:900;color:var(--muted);letter-spacing:.08em;text-transform:uppercase}
.hero h1{margin:6px 0 8px;font-size:26px;line-height:1.2}
.muted{color:var(--muted);font-size:12px}
.tips{display:grid;gap:8px}
.tip{padding:10px 12px;border:1px solid var(--line);border-radius:14px;background:var(--cardB);font-size:12px;color:var(--muted)}
.notice-ok{border-color:rgba(34,197,94,.35)}
.notice-err{border-color:rgba(239,68,68,.45)}
.card{padding:14px}
.card-title{font-size:16px;font-weight:900}
.store-picker-head,.form-head{display:flex;gap:12px;justify-content:space-between;align-items:flex-start;flex-wrap:wrap}
.selected-store-badge{display:inline-flex;align-items:center;padding:8px 12px;border-radius:999px;border:1px solid rgba(250,204,21,.30);background:linear-gradient(135deg, rgba(250,204,21,.18), rgba(251,146,60,.14));font-size:12px;font-weight:900}
.store-picker{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
.store-pill{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:999px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;font-size:13px;font-weight:900}
.store-pill.is-active{border-color:rgba(250,204,21,.38);background:linear-gradient(135deg, rgba(250,204,21,.20), rgba(251,146,60,.18))}
.table-wrap{overflow:auto;border:1px solid rgba(255,255,255,.10);border-radius:12px}
.tbl{width:100%;border-collapse:collapse;min-width:720px}
.tbl th,.tbl td{padding:10px;border-bottom:1px solid rgba(255,255,255,.08);vertical-align:middle}
.tbl thead th{position:sticky;top:0;background:var(--cardA);z-index:1}
.input{width:100%;padding:10px 12px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit}
.mono{font-variant-numeric:tabular-nums}
.actions{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap;margin-top:14px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn-primary{border-color:rgba(250,204,21,.38);background:linear-gradient(135deg, rgba(250,204,21,.20), rgba(251,146,60,.18))}
.num{text-align:right;font-variant-numeric:tabular-nums}
@media (max-width: 640px){
  .actions{justify-content:stretch}
  .actions .btn{width:100%;justify-content:center}
}
</style>

<script>
(function(){
  const body = document.getElementById('bulk-cast-body');
  const template = document.getElementById('bulk-cast-row-template');
  const addBtn = document.getElementById('add-row-btn');
  if (!body || !template || !addBtn) return;

  function autoEmploymentFromStaffCode(row) {
    const staff = row.querySelector('.js-staff-code');
    const emp = row.querySelector('.js-employment-type');
    if (!staff || !emp) return;

    const digits = (staff.value || '').replace(/\D+/g, '');
    if (digits.length === 2) {
      emp.value = 'regular';
    } else if (digits.length === 3) {
      emp.value = 'part';
    }
  }

  function bindRow(row) {
    const staff = row.querySelector('.js-staff-code');
    if (!staff || staff.dataset.autoBound === '1') return;

    const handler = function() {
      autoEmploymentFromStaffCode(row);
    };

    staff.addEventListener('input', handler);
    staff.addEventListener('change', handler);
    staff.dataset.autoBound = '1';
  }

  function syncNames() {
    Array.from(body.querySelectorAll('tr')).forEach((row, index) => {
      const no = row.querySelector('.js-row-no');
      if (no) no.textContent = String(index + 1);

      const staff = row.querySelector('.js-staff-code');
      const name = row.querySelector('.js-display-name');
      const emp = row.querySelector('.js-employment-type');

      if (staff) staff.name = `rows[${index}][staff_code]`;
      if (name) name.name = `rows[${index}][display_name]`;
      if (emp) emp.name = `rows[${index}][employment_type]`;
      bindRow(row);
    });
  }

  addBtn.addEventListener('click', function(){
    const node = template.content.firstElementChild.cloneNode(true);
    body.appendChild(node);
    syncNames();
    autoEmploymentFromStaffCode(node);
  });

  Array.from(body.querySelectorAll('tr')).forEach(autoEmploymentFromStaffCode);
  syncNames();
})();
</script>
<?php render_page_end(); ?>
