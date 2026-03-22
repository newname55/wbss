<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
  http_response_code(403);
  exit('Forbidden');
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

/** テーブルに特定カラムがあるか（IDENTIFIERに?は使えないので SHOW COLUMNS で安全に） */
function col_exists(PDO $pdo, string $table, string $col): bool {
  // テーブル名は固定文字列だけを渡す運用で
  $sql = "SHOW COLUMNS FROM `{$table}` LIKE ?";
  $st = $pdo->prepare($sql);
  $st->execute([$col]);
  return (bool)$st->fetch();
}

$has_ip        = col_exists($pdo, 'stock_moves', 'ip_addr') || col_exists($pdo, 'stock_moves', 'ip');
$has_ua        = col_exists($pdo, 'stock_moves', 'user_agent');
$has_before    = col_exists($pdo, 'stock_moves', 'before_qty');
$has_after     = col_exists($pdo, 'stock_moves', 'after_qty');
$has_store_tbl = true; // stores テーブルがある前提（layout.phpでも参照）

/* =========================
   フィルタ（GET）
========================= */
$tz = new DateTimeZone('Asia/Tokyo');
$today = (new DateTime('now', $tz))->format('Y-m-d');

$from = (string)($_GET['from'] ?? $today);
$to   = (string)($_GET['to'] ?? $today);

$store_id = (int)($_GET['store_id'] ?? (current_store_id() ?? 0)); // 0=全店
$user_id  = (int)($_GET['user_id'] ?? 0);
$product_id = (int)($_GET['product_id'] ?? 0);

$ptype    = trim((string)($_GET['ptype'] ?? '')); // mixer/bottle/consumable
$move_type= trim((string)($_GET['move_type'] ?? '')); // in/out/adjust
$barcode  = trim((string)($_GET['barcode'] ?? ''));
$q        = trim((string)($_GET['q'] ?? '')); // 商品名/メモ/ユーザー名 など

$download = ((string)($_GET['download'] ?? '') === '1');

/* =========================
   期間の解釈（toは当日終わりまで含める）
========================= */
$from_dt = DateTime::createFromFormat('Y-m-d', $from, $tz) ?: new DateTime($today, $tz);
$to_dt   = DateTime::createFromFormat('Y-m-d', $to, $tz) ?: new DateTime($today, $tz);

$from_sql = $from_dt->format('Y-m-d') . ' 00:00:00';
$to_sql   = $to_dt->format('Y-m-d') . ' 23:59:59';

/* =========================
   ページング（CSV時は全件）
========================= */
$page = max(1, (int)($_GET['page'] ?? 1));
$per  = $download ? 50000 : max(50, min(500, (int)($_GET['per'] ?? 200)));
$off  = ($page - 1) * $per;

/* =========================
   WHERE 組み立て
========================= */
$where = [];
$params = [];

$where[] = "m.created_at BETWEEN ? AND ?";
$params[] = $from_sql;
$params[] = $to_sql;

if ($store_id > 0) { $where[] = "m.store_id = ?"; $params[] = $store_id; }
if ($user_id > 0)  { $where[] = "m.created_by = ?"; $params[] = $user_id; }
if ($product_id > 0){ $where[] = "m.product_id = ?"; $params[] = $product_id; }

if ($ptype !== '') {
  $where[] = "p.product_type = ?";
  $params[] = $ptype;
}
if ($move_type !== '') {
  $where[] = "m.move_type = ?";
  $params[] = $move_type;
}
if ($barcode !== '') {
  $where[] = "p.barcode = ?";
  $params[] = $barcode;
}
if ($q !== '') {
  // 商品名 / メモ / ユーザー表示名 / ログインID あたりを横断
  $where[] = "(p.name LIKE ? OR m.note LIKE ? OR u.display_name LIKE ? OR u.login_id LIKE ?)";
  $like = '%' . $q . '%';
  array_push($params, $like, $like, $like, $like);
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

/* =========================
   件数
========================= */
$cnt_st = $pdo->prepare("
  SELECT COUNT(*) AS c
  FROM stock_moves m
  JOIN stock_products p ON p.id = m.product_id
  LEFT JOIN users u ON u.id = m.created_by
  {$where_sql}
");
$cnt_st->execute($params);
$total = (int)($cnt_st->fetch()['c'] ?? 0);
$pages = max(1, (int)ceil($total / $per));

/* =========================
   データ取得
========================= */
$select_cols = [
  "m.id",
  "m.created_at",
  "m.store_id",
  "s.name AS store_name",
  "m.product_id",
  "p.name AS product_name",
  "p.unit",
  "p.barcode",
  "p.product_type",
  "m.move_type",
  "m.delta",
  "m.note",
  "m.created_by",
  "u.display_name",
  "u.login_id",
];

if ($has_before) $select_cols[] = "m.before_qty";
if ($has_after)  $select_cols[] = "m.after_qty";
if ($has_ip)     $select_cols[] = (col_exists($pdo,'stock_moves','ip_addr') ? "m.ip_addr" : "m.ip") . " AS ip_addr";
if ($has_ua)     $select_cols[] = "m.user_agent";

$sql = "
  SELECT
    " . implode(",\n    ", $select_cols) . "
  FROM stock_moves m
  JOIN stock_products p ON p.id = m.product_id
  LEFT JOIN users u ON u.id = m.created_by
  LEFT JOIN stores s ON s.id = m.store_id
  {$where_sql}
  ORDER BY m.id DESC
";

if (!$download) {
  $sql .= " LIMIT {$per} OFFSET {$off}";
}

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   CSV出力
========================= */
if ($download) {
  $fn = "stock_audit_" . $from_dt->format('Ymd') . "_" . $to_dt->format('Ymd') . ".csv";
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $fn . '"');
  // Excel対策（UTF-8 BOM）
  echo "\xEF\xBB\xBF";

  $out = fopen('php://output', 'w');

  $header = [
    'id','created_at','store','product','barcode','ptype','move_type','delta',
  ];
  if ($has_before) $header[] = 'before_qty';
  if ($has_after)  $header[] = 'after_qty';
  $header[] = 'note';
  $header[] = 'user';
  if ($has_ip) $header[] = 'ip';
  if ($has_ua) $header[] = 'user_agent';

  fputcsv($out, $header);

  foreach ($rows as $r) {
    $user = (string)($r['display_name'] ?? '');
    if ($user === '') $user = (string)($r['login_id'] ?? '');

    $line = [
      (int)$r['id'],
      (string)$r['created_at'],
      (string)($r['store_name'] ?? ('#'.(int)$r['store_id'])),
      (string)$r['product_name'],
      (string)($r['barcode'] ?? ''),
      (string)($r['product_type'] ?? ''),
      (string)$r['move_type'],
      (int)$r['delta'],
    ];
    if ($has_before) $line[] = (string)($r['before_qty'] ?? '');
    if ($has_after)  $line[] = (string)($r['after_qty'] ?? '');
    $line[] = (string)($r['note'] ?? '');
    $line[] = $user;
    if ($has_ip) $line[] = (string)($r['ip_addr'] ?? '');
    if ($has_ua) $line[] = (string)($r['user_agent'] ?? '');

    fputcsv($out, $line);
  }

  fclose($out);
  exit;
}

/* =========================
   フィルタ用の候補（軽量）
========================= */
$stores = $pdo->query("SELECT id, name FROM stores ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
$users  = $pdo->query("SELECT id, COALESCE(NULLIF(display_name,''), login_id) AS name FROM users ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// ptype候補
$ptype_items = [
  '' => 'すべて',
  'mixer' => '割物',
  'bottle' => '酒（ボトル）',
  'consumable' => '消耗品',
];

// move_type候補
$move_items = [
  '' => 'すべて',
  'in' => '入庫',
  'out' => '出庫',
  'adjust' => '棚卸',
];

/* =========================
   画面
========================= */
$right = '
  <a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>
  <a class="btn" href="/wbss/public/stock/moves/history.php">作業履歴</a>
';

render_page_start('入出庫監査');
render_header('入出庫監査（Audit）', [
  'back_href' => '/wbss/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <div style="font-weight:1000; font-size:16px;">監査ビュー（編集なし / 証跡確認）</div>
        <div class="muted">期間・店舗・ユーザー・種別で絞り込み → CSV出力（権限: 管理）</div>
      </div>
      <div class="muted">
        合計 <?= number_format($total) ?> 件
      </div>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <form method="get" style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <div>
        <label class="muted">From</label><br>
        <input class="btn" type="date" name="from" value="<?= h($from_dt->format('Y-m-d')) ?>">
      </div>
      <div>
        <label class="muted">To</label><br>
        <input class="btn" type="date" name="to" value="<?= h($to_dt->format('Y-m-d')) ?>">
      </div>

      <div style="min-width:220px;">
        <label class="muted">店舗</label><br>
        <select class="btn" name="store_id" style="width:100%;">
          <option value="0">全店</option>
          <?php foreach ($stores as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $store_id===(int)$s['id']?'selected':'' ?>>
              <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:220px;">
        <label class="muted">ユーザー</label><br>
        <select class="btn" name="user_id" style="width:100%;">
          <option value="0">すべて</option>
          <?php foreach ($users as $u): ?>
            <option value="<?= (int)$u['id'] ?>" <?= $user_id===(int)$u['id']?'selected':'' ?>>
              <?= h((string)$u['name']) ?> (#<?= (int)$u['id'] ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:170px;">
        <label class="muted">種別</label><br>
        <select class="btn" name="move_type" style="width:100%;">
          <?php foreach ($move_items as $k => $lab): ?>
            <option value="<?= h($k) ?>" <?= $move_type===$k?'selected':'' ?>><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:170px;">
        <label class="muted">ptype</label><br>
        <select class="btn" name="ptype" style="width:100%;">
          <?php foreach ($ptype_items as $k => $lab): ?>
            <option value="<?= h($k) ?>" <?= $ptype===$k?'selected':'' ?>><?= h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="min-width:220px;">
        <label class="muted">バーコード</label><br>
        <input class="btn" name="barcode" value="<?= h($barcode) ?>" placeholder="完全一致">
      </div>

      <div style="flex:1; min-width:260px;">
        <label class="muted">検索（商品名/メモ/ユーザー）</label><br>
        <input class="btn" name="q" value="<?= h($q) ?>" placeholder="例) 角 / 納品 / 破損 / 新名">
      </div>

      <div style="min-width:160px;">
        <label class="muted">表示件数</label><br>
        <select class="btn" name="per" style="width:100%;">
          <?php foreach ([50,100,200,300,500] as $n): ?>
            <option value="<?= $n ?>" <?= $per===$n?'selected':'' ?>><?= $n ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div style="display:flex; gap:10px;">
        <button class="btn btn-primary" type="submit">絞り込み</button>

        <?php
          // CSVリンク（現在のGETを維持して download=1 を付ける）
          $qs = $_GET;
          $qs['download'] = '1';
          $csv_href = '/wbss/public/stock/moves/audit.php?' . http_build_query($qs);
        ?>
        <a class="btn" href="<?= h($csv_href) ?>">CSV</a>
      </div>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="overflow:auto;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ID</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">日時</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">店舗</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">商品</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">barcode</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ptype</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">種別</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">Δ</th>
            <?php if ($has_before): ?>
              <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">before</th>
            <?php endif; ?>
            <?php if ($has_after): ?>
              <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">after</th>
            <?php endif; ?>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">メモ</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ユーザー</th>
            <?php if ($has_ip): ?>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">IP</th>
            <?php endif; ?>
            <?php if ($has_ua): ?>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">UA</th>
            <?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="20" style="padding:12px; color:var(--muted);">該当データがありません</td>
            </tr>
          <?php endif; ?>

          <?php foreach ($rows as $r): ?>
            <?php
              $t = (string)$r['move_type'];
              $accent = ($t==='in') ? 'var(--ok)' : (($t==='out') ? 'var(--warn)' : 'var(--c-att)');
              $storeLabel = (string)($r['store_name'] ?? '');
              if ($storeLabel === '') $storeLabel = '#'.(int)$r['store_id'];
              $userLabel = (string)($r['display_name'] ?? '');
              if ($userLabel === '') $userLabel = (string)($r['login_id'] ?? '');
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= (int)$r['id'] ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$r['created_at']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h($storeLabel) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <?= h((string)$r['product_name']) ?>
                <div class="muted" style="margin-top:2px;">#<?= (int)$r['product_id'] ?> / <?= h((string)($r['unit'] ?? '')) ?></div>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)($r['barcode'] ?? '')) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)($r['product_type'] ?? '')) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <span style="width:8px; height:8px; border-radius:999px; background:<?= h($accent) ?>;"></span>
                  <?= h($t) ?>
                </span>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right; font-weight:900;"><?= (int)$r['delta'] ?></td>

              <?php if ($has_before): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;"><?= h((string)($r['before_qty'] ?? '')) ?></td>
              <?php endif; ?>
              <?php if ($has_after): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;"><?= h((string)($r['after_qty'] ?? '')) ?></td>
              <?php endif; ?>

              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)($r['note'] ?? '')) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h($userLabel) ?></td>

              <?php if ($has_ip): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)($r['ip_addr'] ?? '')) ?></td>
              <?php endif; ?>

              <?php if ($has_ua): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line); max-width:360px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;" title="<?= h((string)($r['user_agent'] ?? '')) ?>">
                  <?= h((string)($r['user_agent'] ?? '')) ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <div style="display:flex; justify-content:space-between; align-items:center; gap:10px; flex-wrap:wrap; margin-top:12px;">
      <div class="muted">
        Page <?= $page ?> / <?= $pages ?>
      </div>
      <div style="display:flex; gap:8px; flex-wrap:wrap;">
        <?php
          $base = $_GET;
          $base['per'] = (string)$per;

          $mk = function(int $p) use ($base): string {
            $q = $base;
            $q['page'] = (string)$p;
            return '/wbss/public/stock/moves/audit.php?' . http_build_query($q);
          };
        ?>
        <a class="btn" href="<?= h($mk(1)) ?>">≪</a>
        <a class="btn" href="<?= h($mk(max(1, $page-1))) ?>">‹</a>
        <a class="btn" href="<?= h($mk(min($pages, $page+1))) ?>">›</a>
        <a class="btn" href="<?= h($mk($pages)) ?>">≫</a>
      </div>
    </div>
  </div>

  <div class="muted" style="margin-top:10px;">
    ※ “監査”は編集導線を置かないのが鉄則（証跡の一貫性を守る）。修正は move.php で追加の入出庫として打ち消す運用にする。
  </div>

</div>
<?php render_page_end(); ?>