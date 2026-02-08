<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['cast']);

if (!function_exists('h')) { function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); } }

render_page_start('キャスト');
render_header('キャスト');
?>
<div class="page">
  <div class="admin-wrap" style="max-width:560px;">

    <div class="card">
      <div style="font-weight:1000; font-size:18px;">👤 キャストメニュー</div>
      <div class="muted" style="margin-top:4px;">スマホ1画面で完結</div>
    </div>

    <div class="card-grid cast-grid" style="margin-top:14px;">
      <a class="card big" href="/seika-app/public/cast_my_schedule.php">
        <div class="icon">🗓</div>
        <b>今週の予定</b>
      </a>

      <a class="card big" href="/seika-app/public/cast_week.php">
        <div class="icon">📅</div>
        <b>出勤予定（提出）</b>
      </a>

      <a class="card big" href="/seika-app/public/profile.php">
        <div class="icon">👤</div>
        <b>プロフィール</b>
      </a>

      <a class="card big" href="/seika-app/public/help.php">
        <div class="icon">❓</div>
        <b>ヘルプ</b>
      </a>
    </div>

    <div class="card" style="margin-top:14px;">
      <div style="font-weight:1000;">🟩 出勤/退勤（LINE推奨）</div>
      <div class="muted" style="margin-top:6px; font-size:12px;">
        LINEの「出勤」「退勤」ボタン → 位置情報送信で完了（店舗近くのみ）
      </div>
    </div>

  </div>
</div>

<style>
.card-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
.card{
  padding:14px;
  border:1px solid var(--line);
  border-radius:14px;
  background:var(--cardA);
  text-decoration:none;
  color:inherit;
}
.card:hover{ transform:translateY(-2px); box-shadow:0 10px 24px rgba(0,0,0,.18); }
.card .icon{ font-size:22px; margin-bottom:6px; }
.cast-grid .card.big{ padding:22px; text-align:center; }
@media (max-width:420px){
  .card-grid{ grid-template-columns:1fr; }
}
</style>
<?php render_page_end(); ?>