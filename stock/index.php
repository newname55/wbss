<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';

require_login();

// 権限：super/admin/manager
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
?>
<!doctype html>
<html lang="ja">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>在庫管理</title>
<body>
  <h1>在庫管理</h1>
  <p>店舗ID: <?= (int)$store_id ?></p>
  <p><a href="/seika-app/public/dashboard.php">← ダッシュボード</a></p>

  <hr>
  <p>TODO: ここに棚卸 / 入出庫 / 在庫一覧 / アラートを作っていく</p>
</body>
</html>