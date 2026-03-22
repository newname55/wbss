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
  header('Location: /wbss/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function split_note_and_location(?string $note): array {
  $raw = trim((string)$note);
  if ($raw === '') return ['', ''];
  if (preg_match('/(?:^| \/ )(@loc#\d+(?: [^\/]+)?)(?:$)/u', $raw, $m, PREG_OFFSET_CAPTURE)) {
    $loc = trim((string)$m[1][0]);
    $pos = (int)$m[1][1];
    $before = trim(substr($raw, 0, $pos));
    $after = trim(substr($raw, $pos + strlen($loc)));
    $memo = trim(trim($before . ' ' . $after), " /");
    return [$memo, $loc];
  }
  return [$raw, ''];
}
$pdo = db();

/** ===== filters ===== */
$q      = trim((string)($_GET['q'] ?? ''));          // 商品名/JAN/メモ
$ptype  = trim((string)($_GET['ptype'] ?? ''));      // mixer/bottle/consumable
$op     = trim((string)($_GET['op'] ?? ''));         // in/out/adjust
$location_id = (int)($_GET['location_id'] ?? 0);     // 場所
$user   = trim((string)($_GET['user'] ?? ''));       // display_name/login_id 部分一致
$days   = (int)($_GET['days'] ?? 7);                 // 期間（日）
$days   = max(1, min(180, $days));
$limit  = (int)($_GET['limit'] ?? 200);
$limit  = max(50, min(1000, $limit));

$sort = (string)($_GET['sort'] ?? 'new');            // new|old
$sort = ($sort === 'old') ? 'old' : 'new';
$orderSql = ($sort === 'old') ? 'm.id ASC' : 'm.id DESC';

$locSt = $pdo->prepare("
  SELECT id, name
  FROM stock_locations
  WHERE store_id = ? AND is_active = 1
  ORDER BY sort_order, id
");
$locSt->execute([$store_id]);
$locations = $locSt->fetchAll() ?: [];

/** ===== query ===== */
$where = [];
$params = [];

$where[] = "m.store_id = ?";
$params[] = $store_id;

// 期間
$where[] = "m.created_at >= (NOW() - INTERVAL ? DAY)";
$params[] = $days;

// 種別
if ($op !== '' && in_array($op, ['in','out','adjust'], true)) {
  $where[] = "m.move_type = ?";
  $params[] = $op;
}

// product_type
if ($ptype !== '') {
  $where[] = "p.product_type = ?";
  $params[] = $ptype;
}

// 検索（商品名/JAN/メモ）
if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.barcode LIKE ? OR m.note LIKE ?)";
  $params[] = '%'.$q.'%';
  $params[] = '%'.$q.'%';
  $params[] = '%'.$q.'%';
}

if ($location_id > 0) {
  $where[] = "m.note LIKE ?";
  $params[] = '%@loc#'.$location_id.'%';
}

// 操作者
if ($user !== '') {
  $where[] = "(u.display_name LIKE ? OR u.login_id LIKE ?)";
  $params[] = '%'.$user.'%';
  $params[] = '%'.$user.'%';
}

$sql = "
  SELECT
    m.id,
    m.created_at,
    m.move_type,
    m.delta,
    m.note,
    p.id AS product_id,
    p.name AS product_name,
    p.unit,
    p.barcode,
    p.product_type,
    u.display_name,
    u.login_id
  FROM stock_moves m
  JOIN stock_products p ON p.id = m.product_id
  LEFT JOIN users u ON u.id = m.created_by
  WHERE " . implode(" AND ", $where) . "
  ORDER BY {$orderSql}
  LIMIT {$limit}
";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll() ?: [];

/** ===== summary ===== */
$cnt = count($rows);
$sum_in = 0;   // delta>0 合計
$sum_out = 0;  // delta<0 絶対値合計
$sum_adj = 0;  // adjustのdelta合計（±）
foreach ($rows as $r) {
  $d = (int)$r['delta'];
  $t = (string)$r['move_type'];
  if ($t === 'adjust') {
    $sum_adj += $d;
  } else {
    if ($d >= 0) $sum_in += $d;
    else $sum_out += abs($d);
  }
}

function ptype_label(string $ptype): string {
  return match ($ptype) {
    'mixer'      => '割物',
    'bottle'     => '酒',
    'consumable' => '消耗品',
    default      => ($ptype !== '' ? $ptype : '-'),
  };
}
function op_label(string $op): string {
  return match ($op) {
    'in'     => '入庫',
    'out'    => '出庫',
    'adjust' => '棚卸',
    default  => $op,
  };
}

$right = '
  <a class="btn" href="/wbss/public/stock/move.php">入出庫</a>
  <a class="btn" href="/wbss/public/stock/list.php">在庫一覧</a>
  <a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>
';

render_page_start('入出庫履歴');
render_header('入出庫履歴', [
  'back_href' => '/wbss/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <div class="card">
    <div style="display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; justify-content:space-between;">
      <div>
        <div style="font-weight:1000; font-size:16px;">履歴（直近 <?= (int)$days ?>日 / 最大 <?= (int)$limit ?>件）</div>
        <div class="muted">
          件数 <?= (int)$cnt ?> /
          入庫合計 <?= (int)$sum_in ?> /
          出庫合計 <?= (int)$sum_out ?> /
          棚卸差分合計 <?= (int)$sum_adj ?>
        </div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <a class="btn" href="/wbss/public/stock/history.php">条件クリア</a>
      </div>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <div style="min-width:260px; flex:1;">
        <label class="muted">検索（商品名 / JAN / メモ）</label><br>
        <input class="btn" style="width:100%;" name="q" value="<?= h($q) ?>" placeholder="例) 角 / 490... / 破損">
      </div>

      <div style="min-width:180px;">
        <label class="muted">場所</label><br>
        <select class="btn" name="location_id" style="width:100%;">
          <option value="0">すべて</option>
          <?php foreach ($locations as $loc): ?>
            <option value="<?= (int)$loc['id'] ?>" <?= $location_id === (int)$loc['id'] ? 'selected' : '' ?>>
              <?= h((string)$loc['name']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:170px;">
        <label class="muted">種別（商品）</label><br>
        <select class="btn" name="ptype" style="width:100%;">
          <option value="">すべて</option>
          <option value="mixer" <?= $ptype==='mixer'?'selected':'' ?>>割物</option>
          <option value="bottle" <?= $ptype==='bottle'?'selected':'' ?>>酒</option>
          <option value="consumable" <?= $ptype==='consumable'?'selected':'' ?>>消耗品</option>
        </select>
      </div>

      <div style="min-width:170px;">
        <label class="muted">操作</label><br>
        <select class="btn" name="op" style="width:100%;">
          <option value="">すべて</option>
          <option value="in" <?= $op==='in'?'selected':'' ?>>入庫</option>
          <option value="out" <?= $op==='out'?'selected':'' ?>>出庫</option>
          <option value="adjust" <?= $op==='adjust'?'selected':'' ?>>棚卸</option>
        </select>
      </div>

      <div style="min-width:180px;">
        <label class="muted">操作者</label><br>
        <input class="btn" style="width:100%;" name="user" value="<?= h($user) ?>" placeholder="例) たかはし">
      </div>

      <div style="min-width:150px;">
        <label class="muted">期間（日）</label><br>
        <input class="btn" style="width:100%;" name="days" inputmode="numeric" value="<?= (int)$days ?>">
      </div>

      <div style="min-width:150px;">
        <label class="muted">表示件数</label><br>
        <input class="btn" style="width:100%;" name="limit" inputmode="numeric" value="<?= (int)$limit ?>">
      </div>

      <div style="min-width:150px;">
        <label class="muted">並び</label><br>
        <select class="btn" name="sort" style="width:100%;">
          <option value="new" <?= $sort==='new'?'selected':'' ?>>新→古</option>
          <option value="old" <?= $sort==='old'?'selected':'' ?>>古→新</option>
        </select>
      </div>

      <div>
        <label class="muted">反映</label><br>
        <button class="btn btn-primary" type="submit">検索</button>
      </div>
    </form>

    <div class="muted" style="margin-top:10px;">
      クリックでその商品を「入出庫画面」に持っていけるようにしてます（現場の迷子ゼロ）。
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="overflow:auto;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:10px; border-bottom:1px solid var(--line);">日時</th>
            <th style="text-align:left; padding:10px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:10px; border-bottom:1px solid var(--line);">種別</th>
            <th style="text-align:left; padding:10px; border-bottom:1px solid var(--line);">操作</th>
            <th style="text-align:right; padding:10px; border-bottom:1px solid var(--line);">変動</th>
            <th style="text-align:left; padding:10px; border-bottom:1px solid var(--line);">メモ</th>
            <th style="text-align:left; padding:10px; border-bottom:1px solid var(--line);">操作者</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="7" style="padding:12px; border-bottom:1px solid var(--line);" class="muted">
                該当データがありません
              </td>
            </tr>
          <?php endif; ?>

          <?php foreach ($rows as $r): ?>
            <?php
              $t = (string)$r['move_type'];
              $d = (int)$r['delta'];
              [$memoText, $locText] = split_note_and_location((string)($r['note'] ?? ''));

              $accent = ($t==='in') ? 'var(--ok)' : (($t==='out') ? 'var(--warn)' : 'var(--c-att)');
              $bg = ($t==='in') ? 'rgba(52,211,153,.12)' : (($t==='out') ? 'rgba(251,191,36,.12)' : 'rgba(96,165,250,.12)');

              $who = (string)($r['display_name'] ?? '');
              if ($who === '') $who = (string)($r['login_id'] ?? '');
              if ($who === '') $who = '-';

              $moveText = ($d > 0 ? '+'.$d : (string)$d);
              $prodLinkQ = (string)($r['barcode'] ?? '');
              if ($prodLinkQ === '') $prodLinkQ = (string)$r['product_name'];
            ?>
            <tr>
              <td style="padding:10px; border-bottom:1px solid var(--line); white-space:nowrap;">
                <?= h((string)$r['created_at']) ?>
              </td>

              <td style="padding:10px; border-bottom:1px solid var(--line);">
                <div style="font-weight:900;"><?= h((string)$r['product_name']) ?></div>
                <div class="muted" style="margin-top:4px;">
                  <a class="btn" style="min-height:auto; padding:6px 10px;"
                     href="/wbss/public/stock/move.php<?= h($ptype!==''?('?ptype='.$ptype.'&q='.urlencode($prodLinkQ)) : ('?q='.urlencode($prodLinkQ))) ?>">
                    この商品を入出庫
                  </a>
                </div>
              </td>

              <td style="padding:10px; border-bottom:1px solid var(--line); white-space:nowrap;" class="muted">
                <?= h(ptype_label((string)($r['product_type'] ?? ''))) ?>
              </td>

              <td style="padding:10px; border-bottom:1px solid var(--line);">
                <span style="display:inline-flex; align-items:center; gap:8px; padding:4px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06);">
                  <span style="width:8px; height:8px; border-radius:999px; background:<?= h($accent) ?>;"></span>
                  <span class="muted" style="color:var(--txt);"><?= h(op_label($t)) ?></span>
                </span>
              </td>

              <td style="padding:10px; border-bottom:1px solid var(--line); text-align:right;">
                <span style="display:inline-flex; align-items:center; justify-content:flex-end; gap:8px; padding:4px 10px; border-radius:999px; border:1px solid var(--line); background:<?= h($bg) ?>;">
                  <span style="width:8px; height:8px; border-radius:999px; background:<?= h($accent) ?>;"></span>
                  <span style="font-weight:1000;"><?= h($moveText) ?></span>
                  <span class="muted"><?= h((string)$r['unit']) ?></span>
                </span>
              </td>

              <td style="padding:10px; border-bottom:1px solid var(--line);">
                <?php if ($locText !== ''): ?>
                  <div style="margin-bottom:4px;">
                    <span style="display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(96,165,250,.12);">
                      <?= h($locText) ?>
                    </span>
                  </div>
                <?php endif; ?>
                <?= h($memoText) ?>
              </td>

              <td style="padding:10px; border-bottom:1px solid var(--line);">
                <?= h($who) ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="muted" style="margin-top:10px;">
      入庫＝緑 / 出庫＝黄 / 棚卸＝青（直感で誤操作防止）
    </div>
  </div>

</div>
<?php render_page_end(); ?>
