<?php
declare(strict_types=1);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>オモテダケ占い</title>
  <link rel="stylesheet" href="./css/style.css">
</head>
<body class="page-fortune">
  <div class="bg-stars"></div>

  <main class="siteWrap">
    <header class="topHero">
      <div class="heroLogo">
        <a href="./index.html">
          <img src="./images/omotedake-header-main.png" alt="オモテダケ占い">
        </a>
      </div>

      <div class="heroCatch">
        <p>
          キミの運命、のぞいてみるダケ！<br>
          今日はどんな日になるか、オモテダケが占うよ。
        </p>
      </div>

      <div class="heroMainButton">
        <button type="button" class="btn btn-main btn-xl js-fortune-btn" data-type="today">
          今日の運勢を占う
        </button>
      </div>
    </header>

    <section class="fortuneMenu">
      <button type="button" class="menuBtn js-fortune-btn" data-type="today">
        <span class="menuIcon">⭐</span>
        <span class="menuText">今日の運勢</span>
      </button>

      <button type="button" class="menuBtn js-fortune-btn" data-type="love">
        <span class="menuIcon">💖</span>
        <span class="menuText">恋愛運</span>
      </button>

      <button type="button" class="menuBtn js-fortune-btn" data-type="work">
        <span class="menuIcon">📈</span>
        <span class="menuText">仕事運</span>
      </button>

      <button type="button" class="menuBtn js-fortune-btn" data-type="money">
        <span class="menuIcon">💰</span>
        <span class="menuText">金運</span>
      </button>

      <button type="button" class="menuBtn js-focus-pair">
        <span class="menuIcon">✨</span>
        <span class="menuText">相性占い</span>
      </button>
    </section>

    <section class="resultCard" id="resultCard">
      <div class="resultLeft">
        <div class="resultBadge" id="fortuneTypeLabel">今日の運勢</div>

        <h2 class="resultRank" id="fortuneRank">占ってみるダケ！</h2>

        <div class="resultStars" id="fortuneStars">☆☆☆☆☆</div>

        <p class="resultText" id="fortuneText">
          上のボタンを押すとオモテダケが占うダケ！
        </p>

        <div class="resultInfoGrid">
          <div class="infoBox">
            <div class="infoLabel">ラッキーカラー</div>
            <div class="infoValue" id="fortuneColor">--</div>
          </div>

          <div class="infoBox">
            <div class="infoLabel">ラッキーアイテム</div>
            <div class="infoValue" id="fortuneItem">--</div>
          </div>

          <div class="infoBox">
            <div class="infoLabel">今日のひとこと</div>
            <div class="infoValue" id="fortuneWord">--</div>
          </div>

          <div class="infoBox">
            <div class="infoLabel">運気アップ時間</div>
            <div class="infoValue" id="fortuneTime">--</div>
          </div>
        </div>
      </div>

      <div class="resultRight">
        <img
          id="fortuneImage"
          class="fortuneImage"
          src="./images/omotedake-thinking.png"
          alt="オモテダケ"
        >
      </div>
    </section>

    <section class="pairCard" id="pairCard">
      <div class="pairHeader">
        <h2>相性占い</h2>
        <p>ふたりの名前を入れると、オモテダケが相性を占うダケ！</p>
      </div>

      <div class="pairBody">
        <div class="pairForm">
          <label class="pairField">
            <span>あなたの名前</span>
            <input type="text" id="pairName1" placeholder="例：はると">
          </label>

          <label class="pairField">
            <span>相手の名前</span>
            <input type="text" id="pairName2" placeholder="例：みく">
          </label>

          <button type="button" class="btn btn-main" id="pairBtn">占う</button>
        </div>

        <div class="pairVisual">
          <img
            id="pairImage"
            class="pairImage"
            src="./images/omotedake-love.png"
            alt="恋愛中のオモテダケ"
          >
        </div>
      </div>

      <div class="pairResult" id="pairResult">
        <div class="pairScore">--%</div>
        <div class="pairMessage">名前を入れて占ってみるダケ！</div>
      </div>
    </section>

    <footer class="siteFooter">
      © オモテダケ占い
    </footer>
  </main>
<style>
.magic-star{
  position: fixed;
  font-size: 20px;
  pointer-events: none;
  z-index: 9999;
  animation: magicPop 900ms ease-out forwards;
}

@keyframes magicPop{
  0%{
    transform: translate(0,0) scale(.3);
    opacity: 1;
  }
  70%{
    opacity: 1;
  }
  100%{
    transform: translate(var(--dx), var(--dy)) scale(1.8);
    opacity: 0;
  }
}
.falling-star{
  position: fixed;
  top: -30px;
  font-size: 22px;
  pointer-events: none;
  z-index: 9999;
  animation: fallStar linear forwards;
  text-shadow: 0 0 10px rgba(255,255,255,.9);
}

@keyframes fallStar{
  0%{
    transform: translateY(0) rotate(0deg);
    opacity: 0;
  }
  10%{
    opacity: 1;
  }
  100%{
    transform: translateY(110vh) rotate(240deg);
    opacity: 0;
  }
}
.effect-item{
  position: fixed;
  pointer-events: none;
  z-index: 9999;
  animation-fill-mode: forwards;
}

.falling-star{
  top: -30px;
  animation: fallStar linear forwards;
}

.falling-coin{
  top: -30px;
  animation: fallCoin linear forwards;
}

.flying-heart{
  animation: flyHeart 900ms ease-out forwards;
}

.lightning-flash{
  position: fixed;
  inset: 0;
  background: rgba(255,255,255,.75);
  pointer-events: none;
  z-index: 9998;
  animation: lightningFlash 320ms ease-out forwards;
}

@keyframes fallStar{
  0%{ transform: translateY(0) rotate(0deg); opacity: 0; }
  10%{ opacity: 1; }
  100%{ transform: translateY(110vh) rotate(240deg); opacity: 0; }
}

@keyframes fallCoin{
  0%{ transform: translateY(0) rotate(0deg) scale(.8); opacity: 0; }
  10%{ opacity: 1; }
  100%{ transform: translateY(110vh) rotate(540deg) scale(1.15); opacity: 0; }
}

@keyframes flyHeart{
  0%{ transform: translate(0,0) scale(.4); opacity: 0; }
  20%{ opacity: 1; }
  100%{ transform: translate(var(--dx), var(--dy)) scale(1.4); opacity: 0; }
}

@keyframes lightningFlash{
  0%{ opacity: 0; }
  20%{ opacity: 1; }
  100%{ opacity: 0; }
}
/* エフェクト用スタイル（星・ハート・コイン・稲妻） */
/* 共通 */
.effect-item, .magic-star, .falling-star, .falling-coin, .flying-heart {
  position: fixed;
  pointer-events: none;
  z-index: 9999;
  will-change: transform, opacity;
}

/* ボタンからのマジック用（小さな星が飛ぶ） */
.magic-star {
  font-size: 20px;
  animation: magicPop 0.9s forwards;
}
@keyframes magicPop {
  0% { transform: translate(0,0) scale(1); opacity:1; }
  100% { transform: translate(var(--dx,0), var(--dy, -60px)) scale(.3); opacity:0; }
}

/* 落ちてくる星・コイン */
.falling-star, .falling-coin {
  top: -8vh;
  animation-name: fall;
  animation-timing-function: linear;
  animation-fill-mode: forwards;
}
@keyframes fall {
  0% { transform: translateY(0) rotate(0deg); opacity:1; }
  100% { transform: translateY(110vh) rotate(360deg); opacity:0; }
}

/* ハートがふわっと飛ぶ */
.flying-heart {
  animation: heartFly 1s cubic-bezier(.2,.8,.2,1) forwards;
}
@keyframes heartFly {
  0% { transform: translate(0,0) scale(1); opacity:1; }
  100% { transform: translate(var(--dx,0), var(--dy, -120px)) scale(1.2); opacity:0; }
}

/* コインの見た目調整 */
.falling-coin { font-size: 20px; }
/* ハート（落下）の見た目 */
.falling-heart { font-size: 22px; }

/* 稲妻フラッシュ（画面全体の一瞬の光） */
.lightning-flash {
  position: fixed;
  inset: 0;
  background: radial-gradient(circle at 30% 20%, rgba(255,255,200,0.95), transparent 20%), rgba(255,255,200,0.06);
  mix-blend-mode: screen;
  pointer-events:none;
  z-index:10000;
  animation: lightningFlash .35s ease-in-out forwards;
}
@keyframes lightningFlash {
  0% { opacity:0; }
  30% { opacity:1; }
  100% { opacity:0; }
}
.fx-star {
  position: fixed;
  top: -40px;
  z-index: 9999;
  pointer-events: none;
  user-select: none;
  will-change: transform, opacity;
  animation-name: starFall;
  animation-timing-function: linear;
  animation-fill-mode: forwards;
}

@keyframes starFall {
  0% {
    transform: translateY(0) rotate(0deg);
    opacity: 0;
  }
  10% {
    opacity: 1;
  }
  100% {
    transform: translateY(110vh) rotate(360deg);
    opacity: 0;
  }
}
</style>
  <script src="./js/fortune.js"></script>
</body>
</html>
