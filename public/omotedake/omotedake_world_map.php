<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>オモテダケワールドMAP</title>
<style>
:root{
  --bg1:#120f1f;
  --bg2:#201437;
  --card:#ffffff14;
  --cardStrong:#ffffff1f;
  --line:#ffffff22;
  --text:#f8f5ff;
  --muted:#d6cde8;
  --gold:#ffd86b;
  --pink:#ff8bc2;
  --blue:#74c0ff;
  --green:#8ef0a8;
  --red:#ff8f8f;
  --purple:#c1a6ff;
  --shadow:0 18px 40px rgba(0,0,0,.35);
  --radius:22px;
  --max:1200px;
}

*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  font-family:
    "Hiragino Sans","Hiragino Kaku Gothic ProN","Yu Gothic",
    "Noto Sans JP",system-ui,-apple-system,sans-serif;
  color:var(--text);
  background:
    radial-gradient(circle at top, #3a245f 0%, transparent 30%),
    radial-gradient(circle at 80% 20%, #2f4e7a 0%, transparent 18%),
    linear-gradient(180deg, var(--bg2), var(--bg1));
  min-height:100vh;
}

a{color:inherit;text-decoration:none}

.wrap{
  width:min(calc(100% - 24px), var(--max));
  margin:0 auto;
  padding:20px 0 48px;
}

.hero{
  position:relative;
  overflow:hidden;
  border:1px solid var(--line);
  border-radius:28px;
  background:
    radial-gradient(circle at 20% 0%, #ffffff15 0, transparent 30%),
    linear-gradient(135deg, #2c1948 0%, #1b122c 48%, #111019 100%);
  box-shadow:var(--shadow);
  padding:24px;
}

.hero::after{
  content:"";
  position:absolute;
  inset:0;
  background:
    radial-gradient(circle, rgba(255,255,255,.20) 0 1px, transparent 1px 100%);
  background-size:24px 24px;
  opacity:.08;
  pointer-events:none;
}

.badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:8px 12px;
  border-radius:999px;
  border:1px solid var(--line);
  background:#ffffff10;
  color:var(--gold);
  font-size:12px;
  font-weight:800;
  letter-spacing:.08em;
}

.badgeLink{
  cursor:pointer;
  transition:transform .16s ease, background .16s ease, border-color .16s ease;
}

.badgeLink:hover{
  transform:translateY(-1px);
  background:#ffffff18;
  border-color:#ffffff38;
}

.hero h1{
  margin:14px 0 10px;
  font-size:clamp(28px, 5vw, 52px);
  line-height:1.05;
}

.hero p{
  margin:0;
  max-width:820px;
  color:var(--muted);
  line-height:1.9;
  font-size:15px;
}

.heroActions{
  display:flex;
  flex-wrap:wrap;
  gap:12px;
  margin-top:18px;
}

.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:46px;
  padding:0 16px;
  border-radius:14px;
  border:1px solid var(--line);
  background:#ffffff10;
  color:var(--text);
  font-weight:800;
  backdrop-filter: blur(6px);
}
.btn:hover{transform:translateY(-1px); background:#ffffff18}
.btn.primary{
  background:linear-gradient(135deg, #ffd86b, #ffb84d);
  color:#2b1d00;
  border-color:transparent;
}

.section{
  margin-top:22px;
}

.sectionTitle{
  display:flex;
  align-items:center;
  gap:10px;
  margin:0 0 14px;
  font-size:22px;
  font-weight:900;
}

.sectionTitle .mini{
  font-size:13px;
  color:var(--muted);
  font-weight:700;
}

.mapCard{
  border:1px solid var(--line);
  border-radius:28px;
  background:linear-gradient(180deg, #ffffff10, #ffffff08);
  box-shadow:var(--shadow);
  padding:18px;
}

.mapStage{
  position:relative;
  min-height:980px;
  border-radius:22px;
  border:1px solid var(--line);
  background:
    radial-gradient(circle at 50% 0%, #ffffff0f, transparent 24%),
    radial-gradient(circle at 50% 55%, #4c8a5522, transparent 18%),
    linear-gradient(180deg, #221631 0%, #14111f 100%);
  overflow:hidden;
}

.mapNode{
  position:absolute;
  width:280px;
  padding:16px;
  border-radius:20px;
  border:1px solid var(--line);
  background:linear-gradient(180deg, #ffffff18, #ffffff0c);
  box-shadow:var(--shadow);
  backdrop-filter: blur(10px);
}

.mapNode h3{
  margin:0 0 8px;
  font-size:19px;
  line-height:1.3;
}
.mapNode .sub{
  margin:0 0 8px;
  font-size:13px;
  font-weight:800;
  letter-spacing:.04em;
}
.mapNode p{
  margin:0;
  color:var(--muted);
  font-size:13px;
  line-height:1.8;
  overflow-wrap:anywhere;
}
.mapNode .chipRow{
  display:flex;
  flex-wrap:wrap;
  gap:8px;
  margin-top:12px;
}

.node-god{
  left:50%;
  top:24px;
  transform:translateX(-50%);
}

.node-money{
  left:32px;
  top:180px;
}

.node-love{
  right:32px;
  top:180px;
}

.node-center{
  left:50%;
  top:390px;
  transform:translateX(-50%);
}

.node-men{
  left:32px;
  top:670px;
  transform:none;
}

.node-yake{
  right:32px;
  top:670px;
}

.legend{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:12px;
  margin-top:16px;
}
.legendItem{
  border:1px solid var(--line);
  border-radius:16px;
  padding:14px;
  background:#ffffff10;
}
.legendItem h4{
  margin:0 0 6px;
  font-size:14px;
}
.legendItem p{
  margin:0;
  color:var(--muted);
  font-size:13px;
  line-height:1.7;
}

.bottomGrid{
  display:grid;
  grid-template-columns:1.15fr .85fr;
  gap:18px;
  margin-top:22px;
}

.panel{
  border:1px solid var(--line);
  border-radius:22px;
  background:linear-gradient(180deg,#ffffff10,#ffffff08);
  box-shadow:var(--shadow);
  padding:18px;
}

.panel h3{
  margin:0 0 10px;
  font-size:20px;
}
.panel p, .panel li{
  color:var(--muted);
  line-height:1.9;
  font-size:14px;
}
.panel ul{
  margin:0;
  padding-left:1.2em;
}
.note{
  margin-top:12px;
  padding:14px;
  border-radius:16px;
  border:1px dashed var(--line);
  background:#ffffff0c;
  color:var(--muted);
  font-size:13px;
  line-height:1.8;
}

.footerNav{
  display:flex;
  justify-content:center;
  gap:12px;
  flex-wrap:wrap;
  margin-top:22px;
}

@media (max-width:900px){
  .mapStage{
    min-height:auto;
    padding:14px;
  }
  .paths{display:none}
  .mapNode{
    position:relative;
    width:100%;
    left:auto !important;
    right:auto !important;
    top:auto !important;
    bottom:auto !important;
    transform:none !important;
    margin-bottom:14px;
  }
  .legend{
    grid-template-columns:1fr;
  }
  .bottomGrid{
    grid-template-columns:1fr;
  }
}
</style>
</head>
<body>
  <div class="wrap">

    <section class="hero">
      <a class="badge badgeLink" href="index.html">🍄 OMOTEDAKE WORLD</a>
      <h1>オモテダケワールドMAP</h1>
      <p>
        オモテダケワールドは、人間の運命が静かに編まれる不思議な世界。
        オモテダケを中心に、恋・男運・金運・不運・奇跡をつかさどるダケ族たちが、
        人知れず毎日の流れを整えています。
      </p>
      <div class="heroActions">
        <a class="btn primary" href="omotedake_story.php">📖 ストーリーを見る</a>
        <a class="btn" href="fortune.php">✨ 占いへ行く</a>
      </div>
    </section>

    <section class="section">
      <h2 class="sectionTitle">🗺 世界地図 <span class="mini">6つの領域で運命が動いている</span></h2>

      <div class="mapCard">
        <div class="mapStage">
          <div class="stars">
            <span class="star" style="left:8%; top:7%"></span>
            <span class="star" style="left:16%; top:12%"></span>
            <span class="star" style="left:25%; top:6%"></span>
            <span class="star" style="left:74%; top:10%"></span>
            <span class="star" style="left:85%; top:15%"></span>
            <span class="star" style="left:92%; top:8%"></span>
            <span class="star" style="left:10%; top:48%"></span>
            <span class="star" style="left:88%; top:54%"></span>
            <span class="star" style="left:18%; top:84%"></span>
            <span class="star" style="left:76%; top:88%"></span>
            <span class="star" style="left:50%; top:3%"></span>
          </div>

          <div class="paths">
            <div class="path v" style="left:50%; top:16%; height:170px; transform:translateX(-50%)"></div>
            <div class="path h" style="left:24%; top:43%; width:52%"></div>
            <div class="path v" style="left:50%; top:48%; height:170px; transform:translateX(-50%)"></div>
            <div class="path h" style="left:58%; top:72%; width:18%"></div>
          </div>

          <article class="mapNode node-god">
            <h3>🌟 神ダケ神殿</h3>
            <div class="sub">守護：神ダケ</div>
            <p>
              世界の最上層にある神域。ふだんは静寂に包まれており、
              ごくまれにだけ神ダケが降臨して、強烈な奇跡の流れを人間界へ落とす。
            </p>
            <div class="chipRow">
              <span class="chip">超大吉</span>
              <span class="chip">奇跡</span>
              <span class="chip">神レア</span>
            </div>
          </article>

          <article class="mapNode node-money">
            <h3>💰 カネ鉱山</h3>
            <div class="sub">守護：カネダケ</div>
            <p>
              幸運コインが眠る黄金地帯。仕事運・金運・チャンス運の源が集まり、
              カネダケが毎日せっせと“流れの良い日”を掘り当てている。
            </p>
            <div class="chipRow">
              <span class="chip">金運</span>
              <span class="chip">仕事運</span>
              <span class="chip">成功運</span>
            </div>
          </article>

          <article class="mapNode node-love">
            <h3>💖 恋の泉</h3>
            <div class="sub">守護：アナタダケ</div>
            <p>
              恋心が水面に映る聖なる泉。人と人の縁、想いの揺れ、相性のきらめきを
              アナタダケが見守っている。恋が叶う夜は泉の色が変わるという。
            </p>
            <div class="chipRow">
              <span class="chip">恋愛</span>
              <span class="chip">相性</span>
              <span class="chip">人間関係</span>
            </div>
          </article>

          <article class="mapNode node-center">
            <h3>🍄 オモテの森</h3>
            <div class="sub">守護：オモテダケ</div>
            <p>
              ダケ族の中心地であり、世界の案内所。人間界から届いた気配はまずここに集まり、
              オモテダケがどの運命の流れへ送るかを決めている。
            </p>
            <div class="chipRow">
              <span class="chip">入口</span>
              <span class="chip">案内人</span>
              <span class="chip">世界の中心</span>
            </div>
          </article>

          <article class="mapNode node-men">
            <h3>🕶 オス街</h3>
            <div class="sub">守護：オスダケ</div>
            <p>
              ネオンが揺れる夜の街。男運・モテ運・駆け引きの流れがここで生まれる。
              オスダケは出会いの空気を読んで、絶妙なタイミングを送り出している。
            </p>
            <div class="chipRow">
              <span class="chip">男運</span>
              <span class="chip">モテ運</span>
              <span class="chip">出会い</span>
            </div>
          </article>

          <article class="mapNode node-yake">
            <h3>🍷 ヤケ酒沼</h3>
            <div class="sub">守護：ヤケザケ</div>
            <p>
              失恋・やけっぱち・散財・ため息が流れ着く場所。危うい空気をまといながらも、
              ヤケザケは人間たちの不運を吸い取って浄化している影の功労者でもある。
            </p>
            <div class="chipRow">
              <span class="chip">不運</span>
              <span class="chip">浄化</span>
              <span class="chip">再起</span>
            </div>
          </article>
        </div>

        <div class="legend">
          <div class="legendItem">
            <h4>① 人間界との接点</h4>
            <p>
              占いを引いた瞬間、人間界の気配はまずオモテの森へ届きます。
              そこからその日の流れに合わせて、各エリアへ振り分けられます。
            </p>
          </div>
          <div class="legendItem">
            <h4>② 運命の分配</h4>
            <p>
              恋なら恋の泉、金運ならカネ鉱山、男運ならオス街、
              不運ならヤケ酒沼へ流れ、最後に結果が人間界へ返されます。
            </p>
          </div>
          <div class="legendItem">
            <h4>③ 神レアイベント</h4>
            <p>
              ごくまれに神ダケ神殿が開き、全領域を超えて奇跡の補正が入ります。
              その日は“世界そのものが味方する日”になります。
            </p>
          </div>
        </div>
      </div>
    </section>

    <section class="bottomGrid">
      <div class="panel">
        <h3>🍄 オモテダケワールドの地図ルール</h3>
        <ul>
          <li>世界の中心は <strong>オモテの森</strong>。</li>
          <li>恋愛・相性は <strong>恋の泉</strong>。</li>
          <li>金運・成功運は <strong>カネ鉱山</strong>。</li>
          <li>男運・出会いは <strong>オス街</strong>。</li>
          <li>不運・浄化・やけっぱちは <strong>ヤケ酒沼</strong>。</li>
          <li>奇跡・神レアは <strong>神ダケ神殿</strong>。</li>
        </ul>
        <div class="note">
          <!-- 今後ここに「キャラ画像」「エリア背景」「タップで詳細モーダル」「図鑑リンク」を入れると、
          一気にゲームっぽく強くなります。 -->
        </div>
      </div>

      <!-- <div class="panel">
        <h3>✨ 次に足すと強い要素</h3>
        <ul>
          <li>各エリアをタップすると詳細説明が開く</li>
          <li>神ダケ神殿だけ“？？？”表示にする</li>
          <li>占い結果に応じてMAPの該当エリアを光らせる</li>
          <li>図鑑解放済みキャラだけ色を付ける</li>
          <li>ヤケザケ出現時はヤケ酒沼を赤く揺らす</li>
        </ul>
      </div> -->
    </section>

    <div class="footerNav">
      <a class="btn primary" href="omotedake_story.php">📖 ストーリーへ</a>
      <a class="btn" href="fortune.php">🔮 占いトップへ</a>
    </div>

  </div>
</body>
</html>
