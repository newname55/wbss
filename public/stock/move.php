<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
require_role(['super_user','admin','manager','staff']);

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /seika-app/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

$msg = '';
$err = '';

$prefill_q = trim((string)($_GET['q'] ?? $_GET['barcode'] ?? ''));
/** product_type フィルタ（任意） */
$ptype = (string)($_GET['ptype'] ?? ''); // mixer/bottle/consumable
$is_mixer_mode = ($ptype === 'mixer');

/* ========= helpers ========= */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

/* =========================
   未登録バーコード → その場登録（POST）
   mode=register_product
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mode'] ?? '') === 'register_product') {
  $name    = trim((string)($_POST['name'] ?? ''));
  $unit    = trim((string)($_POST['unit'] ?? '本'));
  $barcode = trim((string)($_POST['barcode'] ?? ''));
  $ptype2  = trim((string)($_POST['product_type'] ?? ($is_mixer_mode ? 'mixer' : 'mixer')));

  if ($name === '') {
    $err = '商品名を入力してください';
  } elseif ($barcode === '') {
    $err = 'バーコードが空です';
  } else {
    try {
      $st = $pdo->prepare("SELECT id FROM stock_products WHERE barcode = ? AND is_active=1 LIMIT 1");
      $st->execute([$barcode]);
      $r = $st->fetch();
      if ($r) {
        $msg = 'すでに登録済みのバーコードです';
      } else {
        $cols = ['name','unit','barcode','is_active'];
        $vals = [$name, $unit, $barcode, 1];

        if (col_exists($pdo, 'stock_products', 'product_type')) {
          $cols[] = 'product_type';
          $vals[] = $ptype2;
        }
        if (col_exists($pdo, 'stock_products', 'store_id')) {
          $cols[] = 'store_id';
          $vals[] = $store_id;
        }

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO stock_products (".implode(',', $cols).") VALUES ($ph)";
        $pdo->prepare($sql)->execute($vals);

        $msg = "商品登録OK: {$name}";
      }
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

/* =========================
   入出庫/棚卸（POST）
   mode=move
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['mode'] ?? '') === 'move') {
  $op_mode    = (string)($_POST['op_mode'] ?? 'in'); // in|out|adjust
  $product_id = (int)($_POST['product_id'] ?? 0);
  $qty_input  = trim((string)($_POST['qty'] ?? ''));
  $note       = trim((string)($_POST['note'] ?? ''));
  $barcode    = trim((string)($_POST['barcode'] ?? ''));

  if (!in_array($op_mode, ['in','out','adjust'], true)) {
    $err = '操作種別が不正です';
  } else {
    if ($product_id <= 0 && $barcode !== '') {
      $st = $pdo->prepare("SELECT id FROM stock_products WHERE barcode = ? AND is_active=1 LIMIT 1");
      $st->execute([$barcode]);
      if ($r = $st->fetch()) $product_id = (int)$r['id'];
    }

    if ($product_id <= 0) {
      $err = '商品を選択してください（未登録なら登録）';
    } elseif ($qty_input === '' || !preg_match('/^-?\d+$/', $qty_input)) {
      $err = ($op_mode === 'adjust') ? '棚卸は実数（整数）' : '数量（整数）';
    } else {
      $qty = (int)$qty_input;
      if ($op_mode !== 'adjust' && $qty <= 0) {
        $err = '入出庫は 1以上';
      } elseif ($op_mode === 'adjust' && $qty < 0) {
        $err = '棚卸実数は 0以上';
      } else {
        try {
          $pdo->beginTransaction();

          $st = $pdo->prepare("
            SELECT id, qty
            FROM stock_items
            WHERE store_id = ? AND product_id = ?
            FOR UPDATE
          ");
          $st->execute([$store_id, $product_id]);
          $item = $st->fetch();

          if (!$item) {
            $pdo->prepare("INSERT INTO stock_items (store_id, product_id, qty) VALUES (?, ?, 0)")
              ->execute([$store_id, $product_id]);
            $st->execute([$store_id, $product_id]);
            $item = $st->fetch();
          }

          $item_id = (int)$item['id'];
          $cur_qty = (int)$item['qty'];

          if ($op_mode === 'in') {
            $delta = $qty;
          } elseif ($op_mode === 'out') {
            $delta = -$qty;
            if ($cur_qty + $delta < 0) throw new RuntimeException("在庫不足（現在 {$cur_qty}）");
          } else {
            $delta = $qty - $cur_qty;
          }

          $new_qty = $cur_qty + $delta;
          if ($new_qty < 0) throw new RuntimeException('在庫がマイナスになります');

          $pdo->prepare("UPDATE stock_items SET qty = ? WHERE id = ?")->execute([$new_qty, $item_id]);

          $created_by = (int)($_SESSION['user_id'] ?? 0);
          $pdo->prepare("
            INSERT INTO stock_moves (store_id, product_id, move_type, delta, note, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
          ")->execute([
            $store_id,
            $product_id,
            $op_mode,
            $delta,
            ($note === '' ? null : $note),
            ($created_by > 0 ? $created_by : null),
          ]);

          $pdo->commit();
          $msg = "OK: {$op_mode} / 変動 {$delta} → 在庫 {$new_qty}";
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          $err = $e->getMessage();
        }
      }
    }
  }
}

/* =========================
   直近履歴
========================= */
$hist = $pdo->prepare("
  SELECT m.created_at, p.name, m.move_type, m.delta, m.note, u.display_name
  FROM stock_moves m
  JOIN stock_products p ON p.id = m.product_id
  LEFT JOIN users u ON u.id = m.created_by
  WHERE m.store_id = ?
  ORDER BY m.id DESC
  LIMIT 30
");
$hist->execute([$store_id]);
$rows = $hist->fetchAll();

render_page_start('入出庫・棚卸');
render_header('入出庫・棚卸', [
  'back_href' => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; justify-content:space-between;">
      <div>
        <div style="font-weight:1000; font-size:16px;">片手運用（PWA推奨）</div>
        <div class="muted">📷スキャン → 商品自動選択 → 数量へフォーカス → Enterで即反映</div>
      </div>

      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if (!$is_mixer_mode): ?>
          <a class="btn" href="/seika-app/public/stock/move.php?ptype=mixer">割物モード</a>
        <?php else: ?>
          <a class="btn btn-primary" href="/seika-app/public/stock/move.php?ptype=mixer">割物モード中</a>
          <a class="btn" href="/seika-app/public/stock/move.php">通常</a>
        <?php endif; ?>
      </div>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <form method="post" id="moveForm" autocomplete="off">
      <input type="hidden" name="mode" value="move">

      <div class="row" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div>
          <label class="muted">操作</label><br>
          <select class="btn" name="op_mode" id="op_mode" style="min-width:220px;">
            <option value="in">入庫（＋）</option>
            <option value="out">出庫（－）</option>
            <option value="adjust">棚卸（実数）</option>
          </select>
        </div>

        <div style="min-width:260px;">
          <label class="muted">バーコード</label><br>
          <input class="btn" style="width:100%;" name="barcode" id="barcode"
       placeholder="JAN等（スキャンで自動入力）"
       value="<?= h($prefill_q) ?>">
        </div>

        <div style="min-width:260px;">
          <label class="muted">商品検索（AJAX）</label><br>
          <input class="btn" style="width:100%;" id="prod_q"
       placeholder="<?= $is_mixer_mode ? '割物のみ検索' : '商品名 / JAN' ?>"
       value="<?= h($prefill_q) ?>">
        </div>

        <div style="min-width:300px;">
          <label class="muted">商品候補（タップで選択 / ℹ️で詳細）</label>
          <div id="productList"></div>
          <input type="hidden" name="product_id" id="product_id">
        </div>

        <div>
          <label class="muted">📷</label><br>
          <button class="btn btn-primary" type="button" id="scanBtn">スキャン</button>
        </div>

        <div style="min-width:170px;">
          <label class="muted">数量</label><br>
          <input class="btn" style="width:100%;" name="qty" id="qty" inputmode="numeric" placeholder="例) 1">
        </div>

        <div style="flex:1; min-width:260px;">
          <label class="muted">メモ（任意）</label><br>
          <input class="btn" style="width:100%;" name="note" id="note" placeholder="例) 納品/提供/破損/棚卸">
        </div>

        <div>
          <label class="muted">⏎</label><br>
          <button class="btn" type="submit" id="submitBtn">反映</button>
        </div>
      </div>

      <div class="muted" style="margin-top:10px;">
        <?= $is_mixer_mode
          ? '割物モード：検索/候補を割物に寄せる。スキャン後は数量へ自動フォーカス。'
          : '通常：スキャン/検索どちらでもOK。棚卸は「実数」を入れるだけ（差分は自動計算）。'
        ?>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div style="font-weight:1000;">直近履歴（30件）</div>
      <a class="btn" href="/seika-app/public/stock/list.php">在庫一覧へ</a>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">日時</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">種別</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">変動</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">メモ</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $t = (string)$r['move_type'];
              $accent = ($t==='in') ? 'var(--ok)' : (($t==='out') ? 'var(--warn)' : 'var(--accent)');
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$r['created_at']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$r['name']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <span style="display:inline-block; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:<?= h($accent) ?>; margin-right:6px;"></span>
                  <?= h($t) ?>
                </span>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;"><?= (int)$r['delta'] ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['note'] ?? '')) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['display_name'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<!-- ===== カメラモーダル ===== -->
<div id="scanModal" style="display:none;">
  <div class="scan-backdrop"></div>
  <div class="scan-panel card">
    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px;">
      <div style="font-weight:1000;">バーコードスキャン</div>
      <button class="btn" type="button" id="closeScan">閉じる</button>
    </div>
    <div class="muted" style="margin-top:6px;">暗いと読み取りにくいので、画面を明るめに。</div>

    <div class="scan-viewport" style="margin-top:10px;">
      <video id="video" playsinline></video>
      <div class="scan-frame"></div>
    </div>

    <div class="muted" id="scanHint" style="margin-top:10px;">起動中...</div>
  </div>
</div>

<!-- ===== 商品詳細モーダル ===== -->
<div id="detailModal" class="modal" hidden>
  <div class="modal-bg" onclick="closeDetail()"></div>
  <div class="modal-card card">
    <button class="close" type="button" onclick="closeDetail()">×</button>
    <div id="detailBody">loading...</div>
  </div>
</div>

<style>
  /* scan */
  #scanModal{ position:fixed; inset:0; z-index:9999; }
  .scan-backdrop{ position:absolute; inset:0; background:rgba(0,0,0,.65); }
  .scan-panel{ position:relative; max-width:520px; margin:20px auto; padding:14px; }
  .scan-viewport{ position:relative; width:100%; aspect-ratio:3/4; border-radius:16px; overflow:hidden; border:1px solid var(--line); background:#000; }
  #video{ width:100%; height:100%; object-fit:cover; }
  .scan-frame{
    position:absolute; inset:12%;
    border-radius:16px; border:2px solid rgba(255,255,255,.45);
    box-shadow: 0 0 0 9999px rgba(0,0,0,.15);
    pointer-events:none;
  }

  /* detail modal */
  .modal[hidden]{ display:none !important; }
  .modal{ position:fixed; inset:0; z-index:10050; }
  .modal-bg{ position:absolute; inset:0; background:rgba(0,0,0,.55); }
  .modal-card{
    position:relative;
    max-width:560px;
    margin:18px auto;
    padding:14px;
  }
  .modal-card .close{
    position:absolute;
    top:10px; right:10px;
    min-width:52px; min-height:52px;
    border-radius:14px;
    border:1px solid var(--line);
    background:#fff;
    font-size:20px;
    font-weight:900;
    cursor:pointer;
  }
</style>

<!-- ZXing -->
<script src="https://unpkg.com/@zxing/library@0.21.0/umd/index.min.js"></script>

<script>
/* ===== 効果音（iOS対策：ユーザー操作後にarm） ===== */
const Sound = (() => {
  let ctx = null;
  function ensureCtx(){
    if (!ctx) ctx = new (window.AudioContext || window.webkitAudioContext)();
    if (ctx.state === 'suspended') ctx.resume().catch(()=>{});
    return ctx;
  }
  function beep({freq=1200, dur=0.08, type='sine', gain=0.12} = {}) {
    const c = ensureCtx();
    const o = c.createOscillator();
    const g = c.createGain();
    o.type = type; o.frequency.value = freq;
    g.gain.value = gain;
    o.connect(g).connect(c.destination);
    o.start();
    o.stop(c.currentTime + dur);
  }
  return {
    ok(){ beep({freq:1400, dur:0.06, type:'square', gain:0.10}); },
    ng(){ beep({freq:220, dur:0.18, type:'sawtooth', gain:0.16}); },
    arm(){ ensureCtx(); }
  };
})();

(() => {
  const ptype = <?= json_encode($ptype, JSON_UNESCAPED_UNICODE) ?>;

  const form = document.getElementById('moveForm');
  const q = document.getElementById('prod_q');
  const qty = document.getElementById('qty');
  const barcodeInput = document.getElementById('barcode');

  const list = document.getElementById('productList');
  const pid  = document.getElementById('product_id');

  // scan modal
  const scanModal = document.getElementById('scanModal');
  const scanBtn   = document.getElementById('scanBtn');
  const closeScan = document.getElementById('closeScan');
  const video     = document.getElementById('video');
  const scanHint  = document.getElementById('scanHint');

  let timer = null;

  // ===== Enter即反映（数量入力中） =====
  qty.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') {
      e.preventDefault();
      form.requestSubmit();
    }
  });

  // ===== 候補UI =====
  function clearProducts(){
    list.innerHTML = '';
    pid.value = '';
  }

  window.selectProduct = function selectProduct(id, name){
    pid.value = String(id);
    Sound.ok();
    qty.focus();
    qty.select();
  };

  window.openDetail = async function openDetail(id){
    const modal = document.getElementById('detailModal');
    const body  = document.getElementById('detailBody');
    modal.hidden = false;
    body.textContent = '読み込み中…';

    try{
      const res = await fetch(`/seika-app/public/stock/api/product_detail.php?id=${encodeURIComponent(id)}`, {
        credentials: 'same-origin'
      });
      const ct = (res.headers.get('content-type') || '');
      if (!ct.includes('application/json')) {
        body.textContent = '取得失敗（JSONではない）: ' + res.status;
        return;
      }
      const j = await res.json();
      if (!j.ok) { body.textContent = '取得失敗（ok=false）'; return; }

      const p = j.product || {};
      const locs = Array.isArray(j.locations) ? j.locations : [];

      body.innerHTML = `
        <div style="font-weight:1000; font-size:16px; margin-bottom:10px;">${escapeHtml(p.name ?? '')}</div>
        ${p.image_url ? `<img src="${escapeAttr(p.image_url)}" style="width:100%; max-height:260px; object-fit:contain; border:1px solid var(--line); border-radius:14px; background:#fff;">`
                      : `<div class="muted" style="padding:10px; border:1px dashed var(--line); border-radius:14px;">画像なし</div>`}
        <div class="muted" style="margin-top:10px; line-height:1.6;">
          カテゴリ：${escapeHtml(p.category ?? '-')}<br>
          種別：${escapeHtml(p.product_type ?? p.type ?? '-')}<br>
          単位：${escapeHtml(p.unit ?? '-')}
        </div>
        <hr style="border:none;border-top:1px solid var(--line);margin:12px 0;">
        <div style="font-weight:1000; margin-bottom:8px;">場所別在庫</div>
        ${locs.length
          ? locs.map(l => `<div style="padding:6px 0; border-bottom:1px solid var(--line);">${escapeHtml(l.location ?? '')}：<b>${Number(l.qty ?? 0)}</b> ${escapeHtml(p.unit ?? '')}</div>`).join('')
          : `<div class="muted">データなし</div>`
        }
      `;
    }catch(e){
      body.textContent = '取得失敗（通信/JSONパース）';
    }
  };

  window.closeDetail = function closeDetail(){
    document.getElementById('detailModal').hidden = true;
  };

  function escapeHtml(s){
    return String(s ?? '').replace(/[&<>"']/g, m => ({
      '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'
    }[m]));
  }
  function escapeAttr(s){ return escapeHtml(s); }

  async function renderProducts(items){
    clearProducts();

    if (!items || !items.length){
      list.innerHTML = `<div class="muted" style="padding:10px;">候補なし</div>`;
      return;
    }

    items.forEach(p => {
      const row = document.createElement('div');
      row.style.display = 'flex';
      row.style.gap = '8px';
      row.style.alignItems = 'center';
      row.style.marginBottom = '8px';

      const btn = document.createElement('button');
      btn.type = 'button';
      btn.className = 'btn';
      btn.style.flex = '1';
      btn.style.justifyContent = 'flex-start';
      btn.textContent = p.name ?? '';
      btn.addEventListener('click', () => window.selectProduct(p.id, p.name));

      const info = document.createElement('button');
      info.type = 'button';
      info.className = 'btn';
      info.textContent = 'ℹ️';
      info.style.minWidth = '58px';
      info.addEventListener('click', () => window.openDetail(p.id));

      row.appendChild(btn);
      row.appendChild(info);
      list.appendChild(row);
    });
  }

  async function search(v){
    if (!v) { clearProducts(); return {count:0}; }

    const url = new URL('/seika-app/public/stock/api/products_search.php', location.origin);
    url.searchParams.set('q', v);
    if (ptype) url.searchParams.set('ptype', ptype);

    const res = await fetch(url.toString(), { credentials: 'same-origin' });
    const j = await res.json().catch(()=>({}));

    if (!j.ok || !Array.isArray(j.items)) {
      clearProducts();
      Sound.ng();
      return {count:0};
    }

    await renderProducts(j.items);

    if (j.items.length === 1) {
      window.selectProduct(j.items[0].id, j.items[0].name);
    }

    return {count: j.items.length};
  }

  q.addEventListener('input', () => {
    clearTimeout(timer);
    timer = setTimeout(() => search(q.value.trim()), 250);
  });

  barcodeInput.addEventListener('input', () => {
    const v = (barcodeInput.value || '').trim();
    if (v.length >= 6) {
      q.value = v;
      q.dispatchEvent(new Event('input'));
    }
  });

  // ===== スキャン結果を search() に流す =====
  async function applyCode(code){
    const v = String(code || '').trim();
    if (!v) return;

    barcodeInput.value = v;
    q.value = v;

    const r = await search(v);
    if (r.count <= 0) {
      Sound.ng();
      return;
    }
    qty.focus(); qty.select();
  }
  // ===== 起動時：URLの q / barcode を自動適用 =====
  const params = new URLSearchParams(location.search);
  const pre = (params.get('q') || params.get('barcode') || '').trim();
  if (pre) {
    // すでにinputにvalueが入っていても、検索・自動選択を走らせる
    setTimeout(() => { applyCode(pre); }, 0);
  }
  // ===== スキャナ本体（BarcodeDetector → ダメならZXing） =====
  let stream = null;
  let rafId = null;
  let detector = null;
  let scanning = false;
  let zxingReader = null;
  let zxingActive = false;

  function openScanModal(){
    scanModal.style.display = 'block';
    document.body.style.overflow = 'hidden';
  }
  function closeScanModal(){
    scanModal.style.display = 'none';
    document.body.style.overflow = '';
  }

  async function stopAll(){
    scanning = false;
    if (rafId) cancelAnimationFrame(rafId);
    rafId = null;

    if (zxingReader) {
      try { zxingReader.reset(); } catch(e) {}
    }
    zxingActive = false;

    if (video) video.srcObject = null;
    if (stream) {
      try { stream.getTracks().forEach(t => t.stop()); } catch(e) {}
    }
    stream = null;
    detector = null;
  }

  async function startCameraOnly(){
    scanHint.textContent = 'カメラ許可待ち...';
    stream = await navigator.mediaDevices.getUserMedia({
      video: {
        facingMode: { ideal: 'environment' },
        width: { ideal: 1280 },
        height: { ideal: 720 }
      },
      audio: false
    });
    video.srcObject = stream;
    await video.play().catch(()=>{});
  }

  async function handleCode(code){
    const v = (code || '').trim();
    if (!v) return;
    Sound.ok();
    await stopAll();
    closeScanModal();
    await applyCode(v);
  }

  async function tickBarcodeDetector(){
    if (!scanning || !detector) return;
    try{
      const barcodes = await detector.detect(video);
      if (barcodes && barcodes.length > 0) {
        const code = (barcodes[0].rawValue || '').trim();
        if (code) { await handleCode(code); return; }
      }
    }catch(e){
      scanHint.textContent = 'BarcodeDetectorが不安定。ZXingへ切替...';
      await startZXing();
      return;
    }
    rafId = requestAnimationFrame(tickBarcodeDetector);
  }

  async function startBarcodeDetector(){
    detector = new BarcodeDetector({ formats: ['ean_13','ean_8','code_128','qr_code','upc_a','upc_e'] });
    scanning = true;
    scanHint.textContent = '枠内にバーコードを合わせてください（BarcodeDetector）';
    tickBarcodeDetector();
  }

  async function startZXing(){
    if (zxingActive) return;

    if (!window.ZXing || !window.ZXing.BrowserMultiFormatReader) {
      scanHint.textContent = 'ZXingの読み込みに失敗（ネット/キャッシュ）';
      Sound.ng();
      return;
    }

    scanning = false;
    if (rafId) cancelAnimationFrame(rafId);
    rafId = null;
    detector = null;

    scanHint.textContent = '枠内にバーコードを合わせてください（ZXing）';
    zxingReader = new window.ZXing.BrowserMultiFormatReader();
    zxingActive = true;

    try{
      const constraints = {
        audio: false,
        video: {
          facingMode: { ideal: 'environment' },
          width: { ideal: 1280 },
          height: { ideal: 720 }
        }
      };
      await zxingReader.decodeFromConstraints(constraints, video, (result, err) => {
        if (result && result.getText) {
          const code = result.getText();
          handleCode(code);
        }
      });
    }catch(e){
      zxingActive = false;
      scanHint.textContent = 'ZXing起動失敗（権限/HTTPS/他アプリ利用中）';
      Sound.ng();
    }
  }

  async function startScannerFlow(){
    Sound.arm();
    openScanModal();

    try{
      await startCameraOnly();
    }catch(e){
      scanHint.textContent = '❌ getUserMedia失敗（権限/HTTPS/設定）';
      Sound.ng();
      return;
    }

    try{
      if ('BarcodeDetector' in window) {
        scanHint.textContent = 'スキャナ初期化中（BarcodeDetector）...';
        await startBarcodeDetector();
      } else {
        scanHint.textContent = 'BarcodeDetector非対応。ZXingで起動します...';
        await startZXing();
      }
    }catch(e){
      scanHint.textContent = 'BarcodeDetector起動失敗。ZXingへ切替...';
      await startZXing();
    }
  }

  scanBtn.addEventListener('click', async () => {
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
      alert('この端末はカメラAPIに非対応です');
      return;
    }
    if (stream) return; // 連打防止
    await startScannerFlow();
  });

  closeScan.addEventListener('click', async () => {
    await stopAll();
    closeScanModal();
  });
  scanModal.querySelector('.scan-backdrop').addEventListener('click', async () => {
    await stopAll();
    closeScanModal();
  });

})();
</script>

<?php render_page_end(); ?>