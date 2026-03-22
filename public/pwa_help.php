<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';

require_login();

render_page_start('PWA手順');
render_header('PWA（ホームに追加）手順', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ランチャー',
]);
?>
<div class="page">
  <div class="card">
    <h2 style="margin:0 0 10px; font-size:18px;">iPadでカメラを安定させる手順</h2>
    <ol style="color:var(--muted); line-height:1.8; margin:0; padding-left:18px;">
      <li>Safariでこのサイトを開く（HTTPSのURL）</li>
      <li>下の <b>共有ボタン</b>（四角＋↑）を押す</li>
      <li><b>「ホーム画面に追加」</b> を選ぶ</li>
      <li>ホームにできた <b>WBSS</b> アイコンから起動する</li>
    </ol>

    <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
      <a class="btn btn-primary" href="/wbss/public/stock/move.php">スキャン画面へ</a>
      <a class="btn" href="/wbss/public/dashboard.php">ランチャーへ</a>
    </div>

    <div class="card" style="margin-top:14px;">
      <div style="font-weight:900;">メモ</div>
      <div class="muted" style="margin-top:6px;">
        iOSは「Safariタブで開く」より「ホーム追加」起動の方が、カメラ権限・復帰が安定しやすいです。
        さらに、同一ドメイン（ss...）で統一しておくと、権限まわりがブレにくいです。
      </div>
    </div>
  </div>
</div>
<?php render_page_end(); ?>
