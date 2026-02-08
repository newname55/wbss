<?php
declare(strict_types=1);

$APP = realpath(__DIR__ . '/../app');
if ($APP === false) {
  http_response_code(500);
  exit('app directory not found');
}

require_once $APP . '/auth.php';
require_once $APP . '/store.php';
require_once $APP . '/layout.php';

require_login();

// 権限：super/admin/manager/staff
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
?>
<!doctype html>
<html lang="ja">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>イベント管理</title>
<body>
  <h1>イベント管理</h1>
  <p>店舗ID: <?= (int)$store_id ?></p>
  <p><a href="/seika-app/public/dashboard.php">← ダッシュボード</a></p>

  <hr>
  <p>TODO: ここにイベント作成 / カレンダー / 料金適用期間などを作っていく</p>
</body>
</html>