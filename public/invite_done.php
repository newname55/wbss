<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

$pdo = db();

$storeId = (int)($_GET['store_id'] ?? 0);

$storeName = '';
if ($storeId > 0) {
  $st = $pdo->prepare("SELECT name FROM stores WHERE id=? LIMIT 1");
  $st->execute([$storeId]);
  $storeName = (string)($st->fetchColumn() ?: '');
}

render_page_start('登録完了');
render_header('登録完了', ['back_href'=>'/seika-app/public/login.php', 'back_label'=>'← ログイン']);
?>
<div class="page">
  <div class="card" style="text-align:center; padding:22px;">
    <div style="font-weight:1000; font-size:18px;">✅ 登録が完了しました</div>
    <div class="muted" style="margin-top:10px;">
      <?= htmlspecialchars($storeName !== '' ? ($storeName . ' にキャスト登録しました') : 'キャスト登録しました', ENT_QUOTES, 'UTF-8') ?>
    </div>

    <div style="margin-top:14px;">
      <a class="btn btn-primary" href="/seika-app/public/login.php">ログインへ</a>
    </div>
  </div>
</div>
<?php render_page_end(); ?>