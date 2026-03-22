<?php
declare(strict_types=1);

date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
if (function_exists('require_role')) {
  require_role(['cast','admin','manager','super_user']);
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

render_page_start('ヘルプ');
render_header('ヘルプ', [
  'back_href' => '/wbss/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
]);
?>
<div class="page">
  <div class="wrap">
    <div class="hero">
      <div class="heroTitle">❓ キャストページの使い方</div>
      <div class="heroSub">スマホで迷わず使えるように、よく使うページだけを短くまとめています。</div>
      <div class="heroChips">
        <span class="chip">出勤予定</span>
        <span class="chip">プロフィール</span>
        <span class="chip">今日の出勤</span>
      </div>
    </div>

    <div class="section">
      <div class="sectionTitle">1. 出勤予定の入れ方</div>
      <div class="card">
        <div class="stepTitle">📅 出勤予定</div>
        <ol class="steps">
          <li>ダッシュボードの <b>出勤予定</b> を開きます。</li>
          <li>各日を押して <b>出勤 / 休み</b> を切り替えます。</li>
          <li>時間を変える日だけ <b>時間を変更</b> を押します。</li>
          <li>開始時刻と終了を入れて、下の <b>この内容で決定</b> を押します。</li>
        </ol>
        <div class="note">ポイント: ふだんは「出勤 / 休み」だけ決めればOKです。時間変更は必要な日だけで大丈夫です。</div>
      </div>

      <div class="miniGrid">
        <div class="miniCard">
          <div class="miniTitle">前週・次週</div>
          <div class="miniBody">上の <b>前週</b> と <b>次週</b> で週を切り替えます。</div>
        </div>
        <div class="miniCard">
          <div class="miniTitle">全部出勤・全部休み</div>
          <div class="miniBody">一週間をまとめて入れたい時に使います。</div>
        </div>
      </div>
    </div>

    <div class="section">
      <div class="sectionTitle">2. プロフィールでできること</div>
      <div class="card">
        <div class="stepTitle">👤 プロフィール</div>
        <ol class="steps">
          <li><b>ポイント</b> で昨日と今期の数字を確認できます。</li>
          <li><b>直近7日</b> は閉じたまま合計と平均だけ見られます。</li>
          <li><b>出勤の基本設定</b> で基本開始時刻を変えられます。</li>
          <li>メモには、勤務メモや管理用の目標設定を書けます。</li>
        </ol>
        <div class="note">メモ: 雇用や店番は管理側の設定なので、自分では変更できません。</div>
      </div>
    </div>

    <div class="section">
      <div class="sectionTitle">3. 今日の出勤・退勤</div>
      <div class="card">
        <div class="stepTitle">📌 今日の状態</div>
        <ol class="steps">
          <li>ダッシュボードで今日の状態を確認します。</li>
          <li><b>出勤（LINEで位置情報）</b> を押すとLINEに案内が届きます。</li>
          <li>営業が終わったら <b>退勤（LINEで位置情報）</b> を押します。</li>
        </ol>
        <div class="note">出勤や退勤の記録が入らない時は、LINEの位置情報許可を確認してください。</div>
      </div>
    </div>

    <div class="section">
      <div class="sectionTitle">4. よくある使い方</div>
      <div class="faqList">
        <div class="faq">
          <div class="faqQ">Q. とりあえず一週間だけ早く入れたい</div>
          <div class="faqA">A. 出勤予定で各日をタップして出勤か休みを決めて、最後に <b>この内容で決定</b> を押せばOKです。</div>
        </div>
        <div class="faq">
          <div class="faqQ">Q. 時間は毎回入れないとダメ？</div>
          <div class="faqA">A. 必要な日だけ変更で大丈夫です。基本時刻はプロフィールで設定できます。</div>
        </div>
        <div class="faq">
          <div class="faqQ">Q. どこから何を開けばいいか迷う</div>
          <div class="faqA">A. 基本は <b>ダッシュボード → 出勤予定</b> と <b>ダッシュボード → プロフィール</b> の2つだけ覚えれば使えます。</div>
        </div>
      </div>
    </div>
  </div>
</div>

<style>
:root{
  --bg1:#f7f8ff;
  --bg2:#fff6fb;
  --card:#ffffff;
  --ink:#1f2937;
  --muted:#6b7280;
  --line:rgba(15,23,42,.10);
  --shadow:0 10px 30px rgba(17,24,39,.08);
  --shadow2:0 6px 16px rgba(17,24,39,.06);
}

.page{
  background:
    radial-gradient(1000px 600px at 10% 0%, rgba(124,92,255,.12), transparent 60%),
    radial-gradient(900px 600px at 90% 10%, rgba(255,95,162,.12), transparent 60%),
    linear-gradient(180deg, var(--bg1), var(--bg2));
  min-height:100vh;
}

.wrap{
  max-width:760px;
  margin:0 auto;
  padding:12px 12px 36px;
}

.hero,
.card,
.miniCard,
.faq{
  border:1px solid var(--line);
  background:rgba(255,255,255,.9);
  border-radius:18px;
  box-shadow:var(--shadow2);
}

.hero{
  padding:14px;
  background:linear-gradient(135deg, rgba(124,92,255,.15), rgba(255,95,162,.10));
}

.heroTitle{
  font-size:20px;
  font-weight:1000;
  color:var(--ink);
}

.heroSub{
  margin-top:6px;
  font-size:13px;
  color:var(--muted);
  line-height:1.6;
}

.heroChips{
  margin-top:10px;
  display:flex;
  flex-wrap:wrap;
  gap:8px;
}

.chip{
  display:inline-flex;
  align-items:center;
  min-height:30px;
  padding:0 10px;
  border-radius:999px;
  border:1px solid rgba(124,92,255,.20);
  background:rgba(255,255,255,.75);
  font-size:12px;
  font-weight:900;
}

.section{
  margin-top:14px;
}

.sectionTitle{
  margin:0 0 8px;
  font-size:15px;
  font-weight:1000;
  color:var(--ink);
}

.card{
  padding:14px;
}

.stepTitle{
  font-size:16px;
  font-weight:1000;
  color:var(--ink);
}

.steps{
  margin:10px 0 0;
  padding-left:18px;
  color:var(--ink);
  line-height:1.85;
}

.note{
  margin-top:10px;
  padding:10px 12px;
  border-radius:14px;
  background:rgba(15,23,42,.04);
  color:var(--muted);
  font-size:12px;
  line-height:1.7;
  font-weight:800;
}

.miniGrid{
  margin-top:10px;
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}

.miniCard{
  padding:12px;
}

.miniTitle{
  font-size:14px;
  font-weight:1000;
  color:var(--ink);
}

.miniBody{
  margin-top:6px;
  font-size:12px;
  color:var(--muted);
  line-height:1.7;
  font-weight:800;
}

.faqList{
  display:grid;
  gap:10px;
}

.faq{
  padding:12px;
}

.faqQ{
  font-size:14px;
  font-weight:1000;
  color:var(--ink);
}

.faqA{
  margin-top:6px;
  font-size:12px;
  color:var(--muted);
  line-height:1.8;
  font-weight:800;
}

@media (max-width: 640px){
  .wrap{
    padding:10px 10px 28px;
  }
  .hero,
  .card,
  .miniCard,
  .faq{
    border-radius:16px;
  }
  .hero{
    padding:12px;
  }
  .heroTitle{
    font-size:18px;
  }
  .card{
    padding:12px;
  }
  .miniGrid{
    grid-template-columns:1fr;
  }
}
</style>

<?php render_page_end(); ?>
