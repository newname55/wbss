<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

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
function op_label(string $op): string {
  return match ($op) {
    'in'     => '入庫',
    'out'    => '出庫',
    'adjust' => '棚卸',
    default  => $op,
  };
}
function format_history_dt(?string $value): string {
  $raw = trim((string)$value);
  if ($raw === '') return '-';
  $ts = strtotime($raw);
  if ($ts === false) return $raw;
  return date('m-d H:i', $ts);
}
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

$limit = (int)($_GET['limit'] ?? 200);
if ($limit < 50) $limit = 50;
if ($limit > 1000) $limit = 1000;

$q = trim((string)($_GET['q'] ?? ''));
$location_id = (int)($_GET['location_id'] ?? 0);

$locSt = $pdo->prepare("
  SELECT id, name
  FROM stock_locations
  WHERE store_id = ? AND is_active = 1
  ORDER BY sort_order, id
");
$locSt->execute([$store_id]);
$locations = $locSt->fetchAll() ?: [];

$params = [$store_id];
$where = "WHERE m.store_id = ?";
if ($q !== '') {
  $where .= " AND (p.name LIKE ? OR p.barcode LIKE ? OR m.note LIKE ?)";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
  $params[] = "%{$q}%";
}
if ($location_id > 0) {
  $where .= " AND m.note LIKE ?";
  $params[] = "%@loc#{$location_id}%";
}

$st = $pdo->prepare("
  SELECT
    m.id, m.created_at, m.move_type, m.delta, m.note,
    p.name AS product_name, p.barcode, p.unit,
    u.display_name
  FROM stock_moves m
  JOIN stock_products p ON p.id = m.product_id
  LEFT JOIN users u ON u.id = m.created_by
  {$where}
  ORDER BY m.id DESC
  LIMIT {$limit}
");
$st->execute($params);
$rows = $st->fetchAll();

$right = '<a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>';

render_page_start('入出庫履歴');
render_header('入出庫履歴', [
  'back_href'  => '/wbss/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">
  <div class="card">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:16px;">入出庫履歴</div>
        <div class="muted">検索：商品名 / JAN / メモ / 場所</div>
      </div>
      <a class="btn btn-primary" href="/wbss/public/stock/move.php">入出庫・棚卸へ</a>
    </div>

    <hr style="border:none;border-top:1px solid var(--line);margin:12px 0;">

    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <div style="min-width:260px; flex:1;">
        <label class="muted">検索</label><br>
        <input class="btn" style="width:100%;" name="q" value="<?= h($q) ?>" placeholder="例) 角 / 490... / 納品">
      </div>
      <div style="min-width:160px;">
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
      <div style="min-width:160px;">
        <label class="muted">件数</label><br>
        <select class="btn" name="limit" style="width:100%;">
          <?php foreach ([100,200,300,500,1000] as $n): ?>
            <option value="<?= $n ?>" <?= $limit===$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="muted"> </label><br>
        <button class="btn" type="submit">絞り込み</button>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="overflow:auto;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">日時</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">場所</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">種別</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">変動</th>
            <th style="text-align:center; padding:8px; border-bottom:1px solid var(--line);">メモ</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $t = (string)$r['move_type'];
              $accent = ($t==='in') ? 'var(--ok)' : (($t==='out') ? 'var(--warn)' : 'var(--accent)');
              [$memoText, $locText] = split_note_and_location((string)($r['note'] ?? ''));
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h(format_history_dt((string)$r['created_at'])) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;">
                <?php if ($locText !== ''): ?>
                  <span style="display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(96,165,250,.12);">
                    <?= h(preg_replace('/^@loc#\d+\s*/u', '', $locText) ?: $locText) ?>
                  </span>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <div style="font-weight:900;"><?= h((string)$r['product_name']) ?></div>
                <div class="muted"><?= h((string)($r['barcode'] ?? '')) ?></div>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:<?= h($accent) ?>;"></span>
                  <?= h(op_label($t)) ?>
                </span>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;">
                <?= (int)$r['delta'] ?> <?= h((string)($r['unit'] ?? '')) ?>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:center;">
                <?php if ($memoText !== ''): ?>
                  <details style="display:inline-block; position:relative; text-align:left;">
                    <summary style="list-style:none; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; width:36px; height:36px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); font-size:18px;">
                      📝
                    </summary>
                    <div style="position:absolute; right:0; top:42px; z-index:5; min-width:220px; max-width:320px; padding:10px 12px; border-radius:12px; border:1px solid var(--line); background:var(--cardA); box-shadow:var(--shadow); white-space:normal;">
                      <?= nl2br(h($memoText)) ?>
                    </div>
                  </details>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['display_name'] ?? '')) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div class="muted" style="margin-top:10px;">表示件数: <?= count($rows) ?></div>
  </div>
</div>

<?php render_page_end(); ?>
