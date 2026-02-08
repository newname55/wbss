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

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /seika-app/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

$pdo = db();

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

$table = 'stock_categories';
$has_store_id = col_exists($pdo, $table, 'store_id');
$has_sort     = col_exists($pdo, $table, 'sort_order');
$has_active   = col_exists($pdo, $table, 'is_active');

$msg = '';
$err = '';

/* =========================
   POST actions
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      $sort = (int)($_POST['sort_order'] ?? 0);
      $active = (int)($_POST['is_active'] ?? 1);

      if ($name === '') throw new RuntimeException('カテゴリ名を入力してください');

      $cols = ['name'];
      $vals = [$name];

      if ($has_store_id) { $cols[] = 'store_id'; $vals[] = $store_id; }
      if ($has_sort)     { $cols[] = 'sort_order'; $vals[] = $sort; }
      if ($has_active)   { $cols[] = 'is_active'; $vals[] = ($active ? 1 : 0); }

      $ph  = implode(',', array_fill(0, count($cols), '?'));
      $sql = "INSERT INTO {$table} (" . implode(',', $cols) . ") VALUES ({$ph})";
      $pdo->prepare($sql)->execute($vals);

      $msg = 'OK: 追加しました';
      header('Location: /seika-app/public/stock/categories/index.php?ok=1');
      exit;

    } elseif ($action === 'update') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $sort = (int)($_POST['sort_order'] ?? 0);
      $active = (int)($_POST['is_active'] ?? 1);

      if ($id <= 0) throw new RuntimeException('IDが不正です');
      if ($name === '') throw new RuntimeException('カテゴリ名を入力してください');

      $sets = ['name = ?'];
      $vals = [$name];

      if ($has_sort)   { $sets[] = 'sort_order = ?'; $vals[] = $sort; }
      if ($has_active) { $sets[] = 'is_active = ?';  $vals[] = ($active ? 1 : 0); }

      $where = 'id = ?';
      $vals[] = $id;

      if ($has_store_id) { $where .= ' AND store_id = ?'; $vals[] = $store_id; }

      $sql = "UPDATE {$table} SET " . implode(', ', $sets) . " WHERE {$where} LIMIT 1";
      $pdo->prepare($sql)->execute($vals);

      header('Location: /seika-app/public/stock/categories/index.php?edit=' . $id . '&ok=1');
      exit;

    } elseif ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('IDが不正です');
      if (!$has_active) throw new RuntimeException('is_active 列がありません');

      $where = 'id = ?';
      $vals  = [$id];
      if ($has_store_id) { $where .= ' AND store_id = ?'; $vals[] = $store_id; }

      $st = $pdo->prepare("SELECT is_active FROM {$table} WHERE {$where} LIMIT 1");
      $st->execute($vals);
      $r = $st->fetch(PDO::FETCH_ASSOC);
      if (!$r) throw new RuntimeException('対象が見つかりません');

      $new = ((int)$r['is_active'] === 1) ? 0 : 1;

      $st2 = $pdo->prepare("UPDATE {$table} SET is_active=? WHERE {$where} LIMIT 1");
      $st2->execute([$new, ...$vals]);

      header('Location: /seika-app/public/stock/categories/index.php?ok=1');
      exit;

    } elseif ($action === 'reorder') {
      if (!$has_sort) throw new RuntimeException('sort_order 列がありません');

      $ids   = $_POST['id'] ?? [];
      $sorts = $_POST['sort_order'] ?? [];
      if (!is_array($ids) || !is_array($sorts)) throw new RuntimeException('入力が不正です');

      $pdo->beginTransaction();

      $sql = $has_store_id
        ? "UPDATE {$table} SET sort_order=? WHERE id=? AND store_id=? LIMIT 1"
        : "UPDATE {$table} SET sort_order=? WHERE id=? LIMIT 1";
      $st = $pdo->prepare($sql);

      $n = 0;
      foreach ($ids as $i => $idv) {
        $id = (int)$idv;
        $so = (int)($sorts[$i] ?? 0);
        if ($id <= 0) continue;
        if ($has_store_id) $st->execute([$so, $id, $store_id]);
        else $st->execute([$so, $id]);
        $n++;
      }

      $pdo->commit();
      header('Location: /seika-app/public/stock/categories/index.php?ok=1');
      exit;

    } else {
      throw new RuntimeException('不正な操作です');
    }

  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

/* =========================
   Fetch list
========================= */
$q = trim((string)($_GET['q'] ?? ''));

$where = [];
$params = [];

if ($has_store_id) { $where[] = 'store_id = ?'; $params[] = $store_id; }
if ($q !== '')     { $where[] = '(name LIKE ?)'; $params[] = '%' . $q . '%'; }

$sql = "SELECT * FROM {$table}";
if ($where) $sql .= " WHERE " . implode(' AND ', $where);

$order = [];
if ($has_sort) $order[] = 'sort_order ASC';
$order[] = 'name ASC';
$order[] = 'id ASC';
$sql .= " ORDER BY " . implode(', ', $order);

$st = $pdo->prepare($sql);
$st->execute($params);
$cats = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$edit_id = (int)($_GET['edit'] ?? 0);
$edit = null;
if ($edit_id > 0) {
  foreach ($cats as $c) {
    if ((int)$c['id'] === $edit_id) { $edit = $c; break; }
  }
}

$ok = (int)($_GET['ok'] ?? 0);
if ($ok === 1 && $msg === '' && $err === '') $msg = '保存しました';

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>';

render_page_start('カテゴリ');
render_header('カテゴリ', [
  'back_href'  => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <!-- 使い方 -->
  <div class="card">
    <div style="font-weight:1000; font-size:16px;">🗂 カテゴリ（わかりやすく）</div>
    <div class="muted" style="margin-top:6px; line-height:1.6;">
      ・右で「追加 / 編集」できます。<br>
      ・左の行をタップすると編集画面に切り替わります。<br>
      <?php if ($has_sort): ?>・並び順を変えたら「並び保存」を押してください。<br><?php endif; ?>
    </div>
  </div>

  <div style="display:grid; grid-template-columns: 1.2fr .8fr; gap:12px; margin-top:12px;">
    <!-- 左：一覧 -->
    <div class="card">
      <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <div>
          <div style="font-weight:1000; font-size:16px;">一覧</div>
          <div class="muted">タップ → 編集</div>
        </div>

        <form method="get" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
          <div style="min-width:240px;">
            <label class="muted">検索</label><br>
            <input class="btn" style="width:100%;" name="q" value="<?= h($q) ?>" placeholder="例) 焼酎 / ソフトドリンク">
          </div>
          <div>
            <button class="btn btn-primary" type="submit">検索</button>
            <a class="btn" href="/seika-app/public/stock/categories/index.php">クリア</a>
          </div>
        </form>
      </div>

      <?php if ($has_sort): ?>
        <div class="muted" style="margin-top:10px;">※ 並び順：小さいほど上</div>
      <?php endif; ?>

      <form method="post" style="margin-top:10px;">
        <input type="hidden" name="action" value="reorder">

        <div style="display:flex; flex-direction:column; gap:10px;">
          <?php foreach ($cats as $c): ?>
            <?php
              $id = (int)$c['id'];
              $name = (string)($c['name'] ?? '');
              $so = (int)($c['sort_order'] ?? 0);
              $active = $has_active ? ((int)($c['is_active'] ?? 1) === 1) : true;

              $isEditing = ($edit_id > 0 && $edit_id === $id);
              $badge = $active ? '有効' : '無効';
              $badgeBg = $active ? 'rgba(52,211,153,.10)' : 'rgba(251,113,133,.12)';
              $badgeBd = $active ? 'rgba(52,211,153,.35)' : 'rgba(251,113,133,.35)';
            ?>
            <div style="
              border:1px solid var(--line);
              border-radius: 16px;
              padding: 10px;
              background: <?= $isEditing ? 'color-mix(in srgb, var(--accent) 10%, transparent)' : 'transparent' ?>;
            ">
              <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:space-between;">
                <a href="/seika-app/public/stock/categories/index.php?edit=<?= $id ?><?= $q!=='' ? '&q='.urlencode($q) : '' ?>"
                   class="btn"
                   style="flex:1; justify-content:flex-start; gap:12px; min-width:260px;">
                  <span style="display:inline-flex; align-items:center; justify-content:center; width:44px; height:44px; border-radius:14px; border:1px solid var(--line); background:rgba(255,255,255,.04); font-weight:1000;">
                    <?= $id ?>
                  </span>
                  <span style="display:flex; flex-direction:column; gap:2px; line-height:1.2;">
                    <span style="font-weight:1000; font-size:15px;"><?= h($name) ?></span>
                    <span class="muted">タップで編集</span>
                  </span>
                </a>

                <?php if ($has_sort): ?>
                  <div style="min-width:120px;">
                    <div class="muted">並び</div>
                    <input class="btn" style="width:120px; text-align:right;" name="sort_order[]" inputmode="numeric" value="<?= (int)$so ?>">
                    <input type="hidden" name="id[]" value="<?= $id ?>">
                  </div>
                <?php else: ?>
                  <input type="hidden" name="id[]" value="<?= $id ?>">
                  <input type="hidden" name="sort_order[]" value="0">
                <?php endif; ?>

                <div style="display:flex; gap:10px; align-items:center;">
                  <span style="display:inline-flex; align-items:center; justify-content:center; min-width:70px; padding:8px 10px; border-radius:999px; border:1px solid <?= h($badgeBd) ?>; background:<?= h($badgeBg) ?>; font-weight:1000; font-size:12px;">
                    <?= h($badge) ?>
                  </span>

                  <?php if ($has_active): ?>
                    <form method="post" style="margin:0;">
                      <input type="hidden" name="action" value="toggle">
                      <input type="hidden" name="id" value="<?= $id ?>">
                      <button class="btn" type="submit"><?= $active ? '無効にする' : '有効にする' ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>

          <?php if (!$cats): ?>
            <div class="muted" style="padding:10px;">データなし</div>
          <?php endif; ?>
        </div>

        <?php if ($has_sort && $cats): ?>
          <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
            <button class="btn btn-primary" type="submit">並び保存</button>
            <a class="btn" href="/seika-app/public/stock/categories/index.php">再読込</a>
          </div>
        <?php endif; ?>
      </form>
    </div>

    <!-- 右：追加/編集 -->
    <div class="card">
      <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <div style="font-weight:1000; font-size:16px;"><?= $edit ? '編集' : '追加' ?></div>
        <?php if ($edit): ?>
          <a class="btn" href="/seika-app/public/stock/categories/index.php">＋ 新規</a>
        <?php endif; ?>
      </div>

      <?php
        $cur = $edit ?: [
          'id' => 0,
          'name' => '',
          'sort_order' => 0,
          'is_active' => 1,
        ];
        $curActive = $has_active ? ((int)($cur['is_active'] ?? 1) === 1) : true;
      ?>

      <form method="post" autocomplete="off" style="margin-top:10px;">
        <?php if ($edit): ?>
          <input type="hidden" name="action" value="update">
          <input type="hidden" name="id" value="<?= (int)$cur['id'] ?>">
        <?php else: ?>
          <input type="hidden" name="action" value="create">
        <?php endif; ?>

        <div>
          <label class="muted">カテゴリ名（必須）</label><br>
          <input class="btn" style="width:100%;" name="name" value="<?= h((string)$cur['name']) ?>" placeholder="例) 焼酎 / ビール / ソフトドリンク" required>
        </div>

        <?php if ($has_sort): ?>
          <div style="margin-top:10px;">
            <label class="muted">並び順（小さいほど上）</label><br>
            <input class="btn" style="width:100%; text-align:right;" name="sort_order" inputmode="numeric" value="<?= (int)($cur['sort_order'] ?? 0) ?>">
          </div>
        <?php endif; ?>

        <?php if ($has_active): ?>
          <div style="margin-top:10px;">
            <label class="muted">状態</label><br>
            <select class="btn" style="width:100%;" name="is_active">
              <option value="1" <?= $curActive ? 'selected' : '' ?>>有効</option>
              <option value="0" <?= !$curActive ? 'selected' : '' ?>>無効</option>
            </select>
          </div>
        <?php endif; ?>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-primary" type="submit"><?= $edit ? '更新する' : '追加する' ?></button>
          <?php if ($edit): ?>
            <a class="btn" href="/seika-app/public/stock/categories/index.php?edit=<?= (int)$cur['id'] ?>">再読込</a>
          <?php endif; ?>
        </div>

        <div class="muted" style="margin-top:10px; line-height:1.6;">
          ・「削除」はしません（運用は無効化）。<br>
          ・商品マスタのカテゴリ選択に反映されます。
        </div>
      </form>
    </div>
  </div>

  <style>
    @media (max-width: 900px){
      .page > div[style*="grid-template-columns"]{ grid-template-columns: 1fr !important; }
    }
  </style>

</div>

<?php render_page_end(); ?>