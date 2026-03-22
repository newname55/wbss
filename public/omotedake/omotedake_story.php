<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>オモテダケワールドの物語</title>
<style>
:root{
  --bg1:#0f0c18;
  --bg2:#1a1430;
  --card:#ffffff12;
  --line:#ffffff1f;
  --text:#faf7ff;
  --muted:#d7d0e7;
  --gold:#ffd86b;
  --pink:#ff8bc2;
  --blue:#7bc6ff;
  --green:#95efaf;
  --red:#ffa2a2;
  --purple:#c8afff;
  --shadow:0 18px 40px rgba(0,0,0,.36);
  --radius:24px;
  --max:1100px;
}

*{box-sizing:border-box}
html,body{margin:0;padding:0}
body{
  font-family:
    "Hiragino Sans","Hiragino Kaku Gothic ProN","Yu Gothic",
    "Noto Sans JP",system-ui,-apple-system,sans-serif;
  color:var(--text);
  background:
    radial-gradient(circle at 20% 0%, #3f2762 0%, transparent 24%),
    radial-gradient(circle at 80% 10%, #2a4f6d 0%, transparent 20%),
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
    radial-gradient(circle at top left, #ffffff15 0%, transparent 30%),
    linear-gradient(135deg, #2d1a4a, #13101e);
  box-shadow:var(--shadow);
  padding:26px;
}

.hero .eyebrow{
  display:inline-flex;
  align-items:center;
  gap:8px;
  min-height:34px;
  padding:0 12px;
  border-radius:999px;
  border:1px solid var(--line);
  background:#ffffff10;
  color:var(--gold);
  font-size:12px;
  font-weight:900;
  letter-spacing:.08em;
}

.hero h1{
  margin:14px 0 10px;
  font-size:clamp(30px, 5vw, 54px);
  line-height:1.05;
}

.hero p{
  margin:0;
  color:var(--muted);
  line-height:1.9;
  max-width:800px;
  font-size:15px;
}

.heroNav{
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
}
.btn:hover{transform:translateY(-1px); background:#ffffff18}
.btn.primary{
  background:linear-gradient(135deg, #ffd86b, #ffb84d);
  border-color:transparent;
  color:#2e2100;
}

.grid{
  display:grid;
  grid-template-columns:1fr;
  gap:18px;
  margin-top:22px;
}

.card{
  border:1px solid var(--line);
  border-radius:24px;
  background:linear-gradient(180deg,#ffffff12,#ffffff09);
  box-shadow:var(--shadow);
  padding:20px;
}

.card h2{
  margin:0 0 12px;
  font-size:24px;
}
.card p{
  margin:0;
  color:var(--muted);
  font-size:15px;
  line-height:2;
}

.storyBlock{
  display:grid;
  grid-template-columns:72px 1fr;
  gap:16px;
  align-items:start;
}
.storyNum{
  display:flex;
  align-items:center;
  justify-content:center;
  width:72px;
  height:72px;
  border-radius:22px;
  border:1px solid var(--line);
  background:linear-gradient(180deg,#ffffff18,#ffffff0f);
  font-size:26px;
  font-weight:900;
  color:var(--gold);
}

.worldCols{
  display:grid;
  grid-template-columns:repeat(3, 1fr);
  gap:14px;
}
.smallCard{
  border:1px solid var(--line);
  border-radius:18px;
  background:#ffffff0c;
  padding:16px;
}
.smallCard h3{
  margin:0 0 8px;
  font-size:18px;
}
.smallCard p{
  margin:0;
  color:var(--muted);
  font-size:14px;
  line-height:1.8;
}

.charGrid{
  display:grid;
  grid-template-columns:repeat(2, 1fr);
  gap:14px;
}
.charCard{
  border:1px solid var(--line);
  border-radius:18px;
  background:#ffffff0c;
  padding:16px;
}
.charCard h3{
  margin:0 0 6px;
  font-size:18px;
}
.charCard .role{
  margin:0 0 8px;
  font-size:13px;
  font-weight:800;
}
.charCard p{
  margin:0;
  color:var(--muted);
  font-size:14px;
  line-height:1.8;
}

.omote{border-color:#95efaf55}
.anata{border-color:#ff8bc255}
.osu{border-color:#7bc6ff55}
.kane{border-color:#ffd86b55}
.yake{border-color:#ffa2a255}
.kami{border-color:#c8afff66}

.quote{
  margin-top:14px;
  padding:16px 18px;
  border-left:4px solid var(--gold);
  border-radius:16px;
  background:#ffffff0d;
  color:#f5edc4;
  line-height:1.9;
  font-weight:700;
}

.footerNav{
  display:flex;
  justify-content:center;
  gap:12px;
  flex-wrap:wrap;
  margin-top:22px;
}

@media (max-width:860px){
  .storyBlock{
    grid-template-columns:1fr;
  }
  .storyNum{
    width:60px;
    height:60px;
    border-radius:18px;
  }
  .worldCols,
  .charGrid{
    grid-template-columns:1fr;
  }
}
</style>
</head>
<body>
  <div class="wrap">

    <section class="hero">
      <div class="eyebrow">📖 OMOTEDAKE STORY</div>
      <h1>オモテダケワールドの物語</h1>
      <p>
        この世界は、ただの占いの舞台ではありません。
        人間には見えない場所で、ダケ族たちが恋・金・男運・不運・奇跡の流れを整え、
        毎日の運命をそっと動かしている神話の世界です。
      </p>
      <div class="heroNav">
        <a class="btn primary" href="omotedake_world_map.php">🗺 MAPを見る</a>
        <a class="btn" href="fortune.php">🔮 占いへ行く</a>
      </div>
    </section>

    <section class="grid">

      <article class="card">
        <div class="storyBlock">
          <div class="storyNum">01</div>
          <div>
            <h2>はじまりの胞子</h2>
            <p>
              ずっと昔、人間たちがまだ自分の運命をうまく言葉にできなかったころ、
              世界には目に見えない「運命の胞子」が漂っていました。
              恋をした夜のため息、願いが届かなかった朝、急に舞い込んだ幸運、
              誰にも言えないやけっぱち――そうした感情の粒が、空気のなかに静かに蓄積されていったのです。
            </p>
            <p style="margin-top:12px;">
              その胞子が、ある夜ひとつの森に集まり、最初のダケ族を生みました。
              それがオモテダケワールドの始まりでした。
            </p>
          </div>
        </div>
      </article>

      <article class="card">
        <div class="storyBlock">
          <div class="storyNum">02</div>
          <div>
            <h2>オモテと人間界のあいだ</h2>
            <p>
              ダケ族は人間界とは別の場所に住んでいます。
              けれど完全に離れているわけではなく、世界と世界の境目、
              いわば「オモテ」と「その少し向こう」の境界に存在しています。
            </p>
            <div class="worldCols" style="margin-top:14px;">
              <div class="smallCard">
                <h3>人間界</h3>
                <p>
                  あなたが暮らしている現実の世界。日々の出来事や感情は、知らないうちに運命の胞子となって流れ出します。
                </p>
              </div>
              <div class="smallCard">
                <h3>ダケ界</h3>
                <p>
                  ダケ族が住む世界。恋、金、男運、不運などの流れがここで整理され、毎日の運勢として整えられます。
                </p>
              </div>
              <div class="smallCard">
                <h3>神域</h3>
                <p>
                  ごく高い場所にある特別領域。神ダケだけがふれることを許された、奇跡そのものの座です。
                </p>
              </div>
            </div>
          </div>
        </div>
      </article>

      <article class="card">
        <div class="storyBlock">
          <div class="storyNum">03</div>
          <div>
            <h2>6人のダケ族</h2>
            <p>
              オモテダケワールドでは、6人のダケ族がそれぞれの役割を担っています。
              占いを引くということは、彼らの誰かがその日のあなたに手を貸す、ということでもあります。
            </p>

            <div class="charGrid" style="margin-top:14px;">
              <div class="charCard omote">
                <h3>🍄 オモテダケ</h3>
                <div class="role">案内人 / 世界の中心</div>
                <p>
                  人間界から届いた気配を最初に受け取る存在。
                  どの運命の流れへつなぐべきかを判断し、ダケ族たちをまとめる。
                </p>
              </div>

              <div class="charCard anata">
                <h3>💖 アナタダケ</h3>
                <div class="role">恋愛 / 相性 / 人間関係</div>
                <p>
                  恋の泉を守るダケ。想いの揺れや、ふたりの温度差、
                  “今近づくべきかどうか”の気配を読むのが得意。
                </p>
              </div>

              <div class="charCard osu">
                <h3>🕶 オスダケ</h3>
                <div class="role">男運 / 出会い / モテ運</div>
                <p>
                  ネオンの街を見張るダケ。出会いのタイミング、惹かれ合う空気、
                  ちょっと危うい駆け引きの流れも担当する。
                </p>
              </div>

              <div class="charCard kane">
                <h3>💰 カネダケ</h3>
                <div class="role">金運 / 仕事運 / 成功運</div>
                <p>
                  カネ鉱山で幸運の鉱脈を掘るダケ。
                  金運だけでなく、成果が実りやすい日、動くべきタイミングも見ている。
                </p>
              </div>

              <div class="charCard yake">
                <h3>🍷 ヤケザケ</h3>
                <div class="role">不運 / やけっぱち / 浄化</div>
                <p>
                  失恋、散財、後悔、泣きたい夜。
                  そういう重たい気配を引き受ける存在。
                  危なっかしい見た目に反して、不運の掃除役でもある。
                </p>
              </div>

              <div class="charCard kami">
                <h3>🌟 神ダケ</h3>
                <div class="role">奇跡 / 超大吉 / 神レア</div>
                <p>
                  ふだんは神域にいて、ほとんど姿を見せない伝説の存在。
                  その気配が降りる日は、運命の流れそのものが一段階変わる。
                </p>
              </div>
            </div>
          </div>
        </div>
      </article>

      <article class="card">
        <div class="storyBlock">
          <div class="storyNum">04</div>
          <div>
            <h2>ヤケザケは悪役ではない</h2>
            <p>
              オモテダケワールドのなかで、いちばん誤解されやすいのがヤケザケです。
              失恋、散財、やけっぱち、飲みすぎ、泣きたい夜。
              そうしたマイナスの気配が流れ着くヤケ酒沼を守っているため、
              見た目も空気もどこか危なっかしく見えます。
            </p>
            <p style="margin-top:12px;">
              でも本当は、誰かのつらさを引き受けて、世界に広がりすぎないようにしている存在です。
              つまりヤケザケは、不運を集めて浄化する“影の守り手”です。
            </p>
            <div class="quote">
              泣きたい夜があるから、次の朝の光はちゃんと届く。  
              ヤケザケは、その夜を受け止めるためにいる。
            </div>
          </div>
        </div>
      </article>

      <article class="card">
        <div class="storyBlock">
          <div class="storyNum">05</div>
          <div>
            <h2>神ダケの伝説</h2>
            <p>
              神ダケについて、正確に知っているダケ族はほとんどいません。
              どこから生まれたのか、なぜ奇跡を起こせるのか、なぜめったに現れないのか――
              その多くは今も神話のままです。
            </p>
            <p style="margin-top:12px;">
              ただひとつ言われているのは、
              <strong>「世界の流れがどうしても動かないとき、神ダケが降りる」</strong>
              ということ。
              それはただのラッキーではなく、止まっていた流れが再び動き出す合図なのかもしれません。
            </p>
          </div>
        </div>
      </article>

      <article class="card">
        <div class="storyBlock">
          <div class="storyNum">06</div>
          <div>
            <h2>占いを引くということ</h2>
            <p>
              あなたが占いボタンを押すたび、オモテダケワールドでは小さな会議が始まります。
              オモテダケが気配を受け取り、アナタダケ、オスダケ、カネダケ、ヤケザケ、
              そしてときには神ダケの力を借りて、その日の運命の流れを人間界へ返します。
            </p>
            <p style="margin-top:12px;">
              だから占い結果は、ただの文章ではありません。
              それは、ダケ族たちがその日あなたのために決めた“今日の流れ”です。
            </p>
            <div class="quote">
              あなたがこの世界をのぞいた瞬間、  
              もうダケ族たちは、あなたのことを見つけている。
            </div>
          </div>
        </div>
      </article>

    </section>

    <div class="footerNav">
      <a class="btn primary" href="omotedake_world_map.php">🗺 世界地図へ</a>
      <a class="btn" href="fortune.php">🔮 占いへ戻る</a>
    </div>

  </div>
</body>
</html>