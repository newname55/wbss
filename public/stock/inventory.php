<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager') && !is_role('staff')) {
  http_response_code(403);
  exit('Forbidden');
}

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

/**
 * 期待テーブル:
 * - stock_locations(store_id, name, ...)
 * - stock_item_locations(store_id, product_id, location_id, qty, ...)
 * - stock_inventory_sessions(store_id, location_id, inventory_date, status, ...)
 * - stock_inventory_lines(session_id, store_id, product_id, location_id, counted_qty, cur_qty, delta, ...)
 * - stock_products(id, name, unit, barcode, product_type, image_path, is_active)
 */

/* =========================
   image_path -> <img src> 正規化
   DBの保存値が
   - "/seika-app/public/uploads/..." (OK)
   - "seika-app/public/uploads/..." (先頭/なし)
   - "uploads/..." (相対)
   - "http(s)://..." (外部)
   など混在しても表示できるようにする
========================= */
function normalize_image_src(?string $path): string {
  $p = trim((string)$path);
  if ($p === '') return '';

  // 外部URL
  if (preg_match('#^https?://#i', $p)) return $p;

  // すでにサイトルート
  if ($p[0] === '/') return $p;

  // "seika-app/public/..." の形（先頭スラ無し）
  if (str_starts_with($p, 'seika-app/public/')) return '/' . $p;

  // "uploads/..." の形（public配下想定）
  if (str_starts_with($p, 'uploads/')) return '/seika-app/public/' . $p;

  // その他は public 配下に寄せる（最後の保険）
  return '/seika-app/public/' . ltrim($p, '/');
}

/* =========================
   GET パラメータ
========================= */
$location_id = (int)($_GET['location_id'] ?? 0);
$q           = trim((string)($_GET['q'] ?? ''));
$ptype       = trim((string)($_GET['ptype'] ?? '')); // mixer/bottle/consumable/''

/* =========================
   ロケーション取得（店ごとに違う）
========================= */
$locs = $pdo->prepare("
  SELECT id, name
  FROM stock_locations
  WHERE store_id = ? AND is_active = 1
  ORDER BY sort_order, id
");
$locs->execute([$store_id]);
$locations = $locs->fetchAll();

if (!$locations) {
  $err = '棚卸場所が未登録です。先に stock_locations に場所を作成してください（例: バックヤード/カウンター/冷蔵庫）。';
  $locations = [];
}
if ($location_id <= 0 && $locations) {
  $location_id = (int)$locations[0]['id'];
}

/* =========================
   棚卸セッション確保（今日×場所、open を再利用）
========================= */
$today = (new DateTimeImmutable('today'))->format('Y-m-d');
$session_id = null;

function get_or_create_inventory_session(PDO $pdo, int $store_id, int $location_id, string $date, ?int $created_by): int {
  $st = $pdo->prepare("
    SELECT id
    FROM stock_inventory_sessions
    WHERE store_id = ? AND location_id = ? AND inventory_date = ? AND status = 'open'
    ORDER BY id DESC
    LIMIT 1
  ");
  $st->execute([$store_id, $location_id, $date]);
  if ($r = $st->fetch()) return (int)$r['id'];

  $ins = $pdo->prepare("
    INSERT INTO stock_inventory_sessions (store_id, location_id, inventory_date, title, note, status, created_by)
    VALUES (?, ?, ?, ?, ?, 'open', ?)
  ");
  $title = '棚卸 ' . $date;
  $note  = null;
  $ins->execute([$store_id, $location_id, $date, $title, $note, $created_by]);
  return (int)$pdo->lastInsertId();
}

/* =========================
   POST: 行単位で「実数」反映（場所別）
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $mode = (string)($_POST['mode'] ?? '');

  $location_id = (int)($_POST['location_id'] ?? $location_id);

  if ($mode === 'adjust_one') {
    $product_id  = (int)($_POST['product_id'] ?? 0);
    $counted_raw = trim((string)($_POST['counted_qty'] ?? ''));
    $note        = trim((string)($_POST['note'] ?? ''));

    if ($location_id <= 0) {
      $err = '場所を選択してください';
    } elseif ($product_id <= 0) {
      $err = '商品が不正です';
    } elseif ($counted_raw === '' || !preg_match('/^\d+$/', $counted_raw)) {
      $err = '棚卸の実数は 0以上の整数で入力してください';
    } else {
      $counted_qty = (int)$counted_raw;

      try {
        $pdo->beginTransaction();

        $created_by = (int)($_SESSION['user_id'] ?? 0);
        $session_id = get_or_create_inventory_session($pdo, $store_id, $location_id, $today, $created_by > 0 ? $created_by : null);

        $st = $pdo->prepare("
          SELECT id, qty
          FROM stock_item_locations
          WHERE store_id = ? AND product_id = ? AND location_id = ?
          FOR UPDATE
        ");
        $st->execute([$store_id, $product_id, $location_id]);
        $row = $st->fetch();

        if (!$row) {
          $pdo->prepare("
            INSERT INTO stock_item_locations (store_id, product_id, location_id, qty)
            VALUES (?, ?, ?, 0)
          ")->execute([$store_id, $product_id, $location_id]);

          $st->execute([$store_id, $product_id, $location_id]);
          $row = $st->fetch();
        }

        $cur_qty = (int)$row['qty'];
        $delta   = $counted_qty - $cur_qty;

        $pdo->prepare("
          UPDATE stock_item_locations
          SET qty = ?
          WHERE store_id = ? AND product_id = ? AND location_id = ?
        ")->execute([$counted_qty, $store_id, $product_id, $location_id]);

        $pdo->prepare("
          INSERT INTO stock_inventory_lines
            (session_id, store_id, product_id, location_id, counted_qty, cur_qty, delta, note)
          VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ")->execute([
          $session_id,
          $store_id,
          $product_id,
          $location_id,
          $counted_qty,
          $cur_qty,
          $delta,
          ($note === '' ? null : $note),
        ]);

        $pdo->prepare("
          INSERT INTO stock_moves (store_id, product_id, move_type, delta, note, created_by)
          VALUES (?, ?, 'adjust', ?, ?, ?)
        ")->execute([
          $store_id,
          $product_id,
          $delta,
          ($note === '' ? ('棚卸 @loc#'.$location_id) : ($note.' / 棚卸 @loc#'.$location_id)),
          ($created_by > 0 ? $created_by : null),
        ]);

        $pdo->commit();

        $msg = "棚卸OK（場所#{$location_id}）: 実数 {$counted_qty} / 以前 {$cur_qty} / 差分 {$delta}";
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
      }
    }
  }

  $qs = ['location_id' => $location_id];
  if ($q !== '') $qs['q'] = $q;
  if ($ptype !== '') $qs['ptype'] = $ptype;

  header('Location: /seika-app/public/stock/inventory.php?' . http_build_query($qs));
  exit;
}

/* =========================
   一覧（場所別 現在庫 + 画像）
========================= */
$where = ["p.is_active = 1"];
$args  = [];

if ($ptype !== '') {
  $where[] = "p.product_type = ?";
  $args[] = $ptype;
}
if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
  $args[] = '%' . $q . '%';
  $args[] = '%' . $q . '%';
}

$sql = "
  SELECT
    p.id,
    p.name,
    p.unit,
    p.barcode,
    p.product_type,
    p.image_path,
    COALESCE(sil.qty, 0) AS qty
  FROM stock_products p
  LEFT JOIN stock_item_locations sil
    ON sil.store_id = ? AND sil.product_id = p.id AND sil.location_id = ?
  WHERE " . implode(" AND ", $where) . "
  ORDER BY p.name
  LIMIT 800
";

$st = $pdo->prepare($sql);
$st->execute(array_merge([$store_id, $location_id], $args));
$items = $st->fetchAll();

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>';

render_page_start('棚卸（場所別）');
render_header('棚卸（場所別）', [
  'back_href' => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);

$loc_name = '';
foreach ($locations as $L) {
  if ((int)$L['id'] === $location_id) { $loc_name = (string)$L['name']; break; }
}
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:flex-end; justify-content:space-between;">
      <div>
        <div style="font-weight:1000; font-size:16px;">場所別棚卸（画像で迷子ゼロ）</div>
        <div class="muted">場所を切替えると、その場所の在庫だけ表示。実数→反映で差分が記録されます。</div>
      </div>
      <div class="muted">今日: <?= h($today) ?> / 場所: <?= h($loc_name !== '' ? $loc_name : ('#'.$location_id)) ?></div>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <div style="min-width:240px;">
        <label class="muted">場所</label><br>
        <select class="btn" name="location_id" style="width:100%;">
          <?php foreach ($locations as $L): ?>
            <option value="<?= (int)$L['id'] ?>" <?= ((int)$L['id']===$location_id)?'selected':'' ?>><?= h((string)$L['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:240px;">
        <label class="muted">種別（任意）</label><br>
        <select class="btn" name="ptype" style="width:100%;">
          <option value="" <?= $ptype===''?'selected':'' ?>>すべて</option>
          <option value="mixer" <?= $ptype==='mixer'?'selected':'' ?>>割物</option>
          <option value="bottle" <?= $ptype==='bottle'?'selected':'' ?>>酒（ボトル）</option>
          <option value="consumable" <?= $ptype==='consumable'?'selected':'' ?>>消耗品</option>
        </select>
      </div>

      <div style="flex:1; min-width:260px;">
        <label class="muted">検索（商品名 / JAN）</label><br>
        <input class="btn" style="width:100%;" name="q" value="<?= h($q) ?>" placeholder="例) 角 / 鏡月 / 490..." autocomplete="off">
      </div>

      <div>
        <label class="muted">表示</label><br>
        <button class="btn btn-primary" type="submit">更新</button>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div style="font-weight:1000;">棚卸リスト（場所別）</div>
      <div class="muted">件数: <?= (int)count($items) ?>（多い場合は検索して絞って）</div>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:74px;">画像</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:110px;">種別</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line); width:110px;">現在</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:180px;">実数</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:240px;">メモ</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line); width:130px;">反映</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($items as $it): ?>
            <?php
              $pid   = (int)$it['id'];
              $name  = (string)$it['name'];
              $unit  = (string)$it['unit'];
              $qty   = (int)$it['qty'];
              $img_src = normalize_image_src($it['image_path'] ?? null);
              $ptype_row = (string)($it['product_type'] ?? '');
              $ptype_label = $ptype_row !== '' ? $ptype_row : '-';
              $form_id = 'inv_' . $pid;
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <?php if ($img_src !== ''): ?>
                  <img
                    src="<?= h($img_src) ?>"
                    alt=""
                    style="width:58px; height:58px; object-fit:cover; border-radius:14px; border:1px solid var(--line); background:#000;"
                    loading="lazy"
                  >
                <?php else: ?>
                  <div style="width:58px; height:58px; border-radius:14px; border:1px solid var(--line); background:rgba(255,255,255,.06); display:flex; align-items:center; justify-content:center; color:var(--muted); font-weight:900;">
                    NO
                  </div>
                <?php endif; ?>
              </td>

              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <div style="font-weight:900;"><?= h($name) ?></div>
                <div class="muted">単位: <?= h($unit) ?> / JAN: <?= h((string)($it['barcode'] ?? '')) ?></div>
              </td>

              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <span style="display:inline-block; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <?= h($ptype_label) ?>
                </span>
              </td>

              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right; font-weight:900;">
                <?= $qty ?> <span class="muted"><?= h($unit) ?></span>
              </td>

              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <input
                  class="btn"
                  style="width:120px; text-align:right;"
                  name="counted_qty"
                  inputmode="numeric"
                  placeholder="実数"
                  autocomplete="off"
                  form="<?= h($form_id) ?>"
                >
                <span class="muted"><?= h($unit) ?></span>
              </td>

              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <input
                  class="btn"
                  style="width:100%; min-width:180px;"
                  name="note"
                  placeholder="例) 棚卸/破損/移動"
                  autocomplete="off"
                  form="<?= h($form_id) ?>"
                >
              </td>

              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <form method="post" id="<?= h($form_id) ?>">
                  <input type="hidden" name="mode" value="adjust_one">
                  <input type="hidden" name="location_id" value="<?= (int)$location_id ?>">
                  <input type="hidden" name="product_id" value="<?= $pid ?>">
                  <button class="btn btn-primary" type="submit" style="min-width:110px;">反映</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$items): ?>
            <tr><td colspan="7" class="muted" style="padding:10px;">データなし</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="muted" style="margin-top:10px;">
      ※ ここは「場所別の実数」を入れるページ。反映すると差分が自動計算され、棚卸ライン＋moves(adjust)が残ります。
    </div>
  </div>

</div>

<script>
/* 実数欄は Enter で送信（スマホ/iPad想定） */
document.addEventListener('keydown', (e) => {
  const t = e.target;
  if (!t) return;
  if (t.matches('input[name="counted_qty"]') && e.key === 'Enter') {
    e.preventDefault();
    const formId = t.getAttribute('form');
    const form = formId ? document.getElementById(formId) : null;
    if (form) form.requestSubmit();
  }
});
</script>

<?php render_page_end(); ?>