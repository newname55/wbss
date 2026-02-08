<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';

require_login();

render_page_start('登録完了');
render_header('登録完了', [
  'back_href' => '/seika-app/public/gate.php',
  'back_label' => '← 戻る',
]);
?>
<div class="page">
  <div class="card">
    <div style="font-weight:1000; font-size:18px;">✅ 登録が完了しました</div>
    <div class="muted" style="margin-top:8px;">
      このまま通常通りログインして使えます。
    </div>
  </div>
</div>
<?php render_page_end(); ?>