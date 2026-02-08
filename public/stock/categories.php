<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager')) {
  http_response_code(403);
  exit('Forbidden');
}

$pdo = db();

function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

$msg = '';
$err = '';

// 編集対象
$edit_id = (int)($_GET['edit_id'] ?? 0);

// 入力
$id         = (int)($_POST['id'] ?? 0);
$name       = trim((string)($_POST['name'] ?? ''));
$sort_order = (int)($_POST['sort_order'] ?? 0);
$is_active  = isset($_POST['is_active']) ? 1 : 0;
$scope      = (string)($_POST['scope'] ?? 'global'); // global / store

$store_id = current_store_id(); // 店舗選択中
$store_id = ($scope === 'store') ? (int)$store_id : null;

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($name === '') {
    $err = 'カテゴリ名は必須です';
  } else {
    try {
      if ($id > 0) {
        // 更新
        $st = $pdo->prepare("
          UPDATE stock_categories
          SET name = ?, sort_order = ?, is_active = ?, store_id = ?
          WHERE id = ?
        ");
        $st->execute([$name, $sort_order, $is_active, $store_id, $id]);
        $msg = 'カテゴリを更新しました';
      } else {
        // 追加
        $st = $pdo->prepare("
          INSERT INTO stock_categories (name, sort_order, is_active, store_id)
          VALUES (?, ?, ?, ?)
        ");
        $st->execute([$name, $sort_order, $is_active, $store_id]);
        $msg = 'カテゴリを追加しました';
      }

      // リセット
      $edit_id = 0;
      $name = '';
      $sort_order = 0;
      $is_active = 1;
      $scope = 'global';

    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

// 編集読み込み
$edit = null;
if ($edit_id > 0) {
  $st = $pdo->prepare("SELECT * FROM stock_categories WHERE id = ?");
  $st->execute([$edit_id]);
  $edit = $st->fetch();
  if ($edit) {
    $name       = (string)$edit['name'];
    $sort_order = (int)$edit['sort_order'];
    $is_active  = (int)$edit['is_active'];
    $scope      = ($edit['store_id'] === null) ? 'global' : 'store';
  }
}

// 一覧（全店共通 + 現在店舗）
$st = $pdo->prepare("
  SELECT c.*, s.name AS store_name
  FROM stock_categories c
  LEFT JOIN stores s ON s.id = c.store_id
  WHERE c.store_id IS NULL OR c.store_id = ?
  ORDER BY c.sort_order, c.name
");
$st->execute([current_store_id()]);
$rows = $st->fetchAll();
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>カテゴリマスタ管理</title>
<style>
  body{ font-family: system-ui, -apple-system, "Noto Sans JP", sans-serif; margin:16px; }
  table{ border-collapse:collapse; width:100%; margin-top:14px; }
  th,td{ border:1px solid #ddd; padding:8px; font-size:13px; }
  th{ background:#f6f6f6; text-align:left; }
  input,select,button{ padding:8px; font-size:14px; }
  .row{ display:flex; gap:10px; flex-wrap:wrap; }
  .msg{ background:#e8fff0; border:1px solid #b7f0c9; padding:10px; margin:12px 0; }
  .err{ background:#fff1f1; border:1px solid #f2b5b5; padding:10px; margin:12px 0; }
  .off{ color:#999; }
  .tag{ font-size:12px; padding:2px 6px; border-radius:999px; background:#eee; }
</style>
</head>
<body>

<h1>カテゴリマスタ管理（酒種）</h1>
<p>
  <a href="/seika-app/public/stock/index.php">← 在庫メニュー</a> /
  <a href="/seika-app/public/dashboard.php">← ダッシュボード</a>
</p>

<?php if ($msg): ?><div class="msg"><?= h($msg) ?></div><?php endif; ?>
<?php if ($err): ?><div class="err"><?= h($err) ?></div><?php endif; ?>

<h2><?= $edit ? 'カテゴリ編集' : 'カテゴリ追加' ?></h2>

<form method="post">
  <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="row">
    <input name="name" value="<?= h($name) ?>" placeholder="例）ウイスキー" required>
    <input type="number" name="sort_order" value="<?= (int)$sort_order ?>" placeholder="並び順">
    <select name="scope">
      <option value="global" <?= $scope==='global'?'selected':'' ?>>全店舗共通</option>
      <option value="store"  <?= $scope==='store'?'selected':'' ?>>この店舗のみ</option>
    </select>
    <label>
      <input type="checkbox" name="is_active" value="1" <?= $is_active ? 'checked' : '' ?>>
      有効
    </label>
    <button type="submit"><?= $edit ? '更新' : '追加' ?></button>
  </div>
</form>

<h2>カテゴリ一覧</h2>
<table>
<thead>
<tr>
  <th>ID</th>
  <th>カテゴリ名</th>
  <th>範囲</th>
  <th>並び</th>
  <th>状態</th>
  <th></th>
</tr>
</thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr class="<?= !$r['is_active'] ? 'off' : '' ?>">
  <td><?= (int)$r['id'] ?></td>
  <td><?= h((string)$r['name']) ?></td>
  <td>
    <?php if ($r['store_id'] === null): ?>
      <span class="tag">全店舗</span>
    <?php else: ?>
      <span class="tag"><?= h((string)$r['store_name']) ?></span>
    <?php endif; ?>
  </td>
  <td><?= (int)$r['sort_order'] ?></td>
  <td><?= $r['is_active'] ? '有効' : '無効' ?></td>
  <td><a href="?edit_id=<?= (int)$r['id'] ?>">編集</a></td>
</tr>
<?php endforeach; ?>
</tbody>
</table>

</body>
</html>