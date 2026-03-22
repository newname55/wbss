<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>オモテダケ占い｜ワンタップ占い</title>

<style>
:root{
  --bg1:#1f1d5a;
  --bg2:#5b3ea8;
  --bg3:#ff9ed6;
  --txt:#ffffff;
  --card:rgba(255,255,255,.14);
  --line:rgba(255,255,255,.18);
}

*{ box-sizing:border-box; }

body{
  margin:0;
  min-height:100vh;
  font-family:"Hiragino Maru Gothic ProN","Yu Gothic","Meiryo",sans-serif;
  color:var(--txt);
  background:
    radial-gradient(circle at 10% 20%, rgba(255,255,255,.45) 0 2px, transparent 3px),
    radial-gradient(circle at 80% 30%, rgba(255,255,255,.35) 0 2px, transparent 3px),
    radial-gradient(circle at 30% 70%, rgba(255,255,255,.25) 0 2px, transparent 3px),
    linear-gradient(180deg,var(--bg1),var(--bg2),var(--bg3));
  background-size:200px 200px,180px 180px,220px 220px,cover;
}

.wrap{
  max-width:1120px;
  margin:0 auto;
  padding:20px 16px 40px;
}

.header{
  text-align:center;
  margin-bottom:12px;
}

.header img{
  width:min(320px,70%);
}

.lead{
  text-align:center;
  font-size:18px;
  font-weight:800;
  margin:10px 0 22px;
}

.layout{
  display:grid;
  grid-template-columns:320px 1fr;
  gap:18px;
}

.panel,
.main{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:24px;
  box-shadow:0 12px 28px rgba(0,0,0,.18);
  backdrop-filter:blur(8px);
}

.panel{
  padding:20px;
  text-align:center;
}

.charImg{
  width:210px;
  max-width:100%;
  display:block;
  margin:0 auto 12px;
  animation:float 4s ease-in-out infinite;
  filter:drop-shadow(0 12px 20px rgba(0,0,0,.28));
}

@keyframes float{
  0%{ transform:translateY(0); }
  50%{ transform:translateY(-10px); }
  100%{ transform:translateY(0); }
}

.panelTitle{
  font-size:24px;
  font-weight:900;
  margin-bottom:8px;
}

.panelText{
  font-size:14px;
  line-height:1.8;
  opacity:.95;
}

.badges{
  display:grid;
  gap:8px;
  margin-top:14px;
}

.badges div{
  font-size:12px;
  background:rgba(255,255,255,.12);
  border-radius:12px;
  padding:8px 10px;
  line-height:1.5;
}

.backLink{
  display:inline-block;
  margin-top:16px;
  color:#fff;
  text-decoration:none;
  font-size:14px;
  opacity:.9;
}

.main{
  padding:16px;
  display:flex;
  flex-direction:column;
  gap:16px;
}

.topBar{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:6px 6px 14px;
  border-bottom:1px solid rgba(255,255,255,.12);
}

.topTitle{
  font-size:22px;
  font-weight:900;
}

.topSub{
  font-size:12px;
  opacity:.82;
}

.todayChip{
  background:rgba(255,255,255,.16);
  padding:8px 12px;
  border-radius:999px;
  font-size:12px;
  font-weight:900;
  white-space:nowrap;
}

.menuGrid{
  display:grid;
  grid-template-columns:repeat(5,1fr);
  gap:10px;
}

.menuBtn{
  border:none;
  cursor:pointer;
  border-radius:18px;
  padding:14px 10px;
  color:#fff;
  font-weight:900;
  font-size:14px;
  line-height:1.4;
  background:rgba(255,255,255,.16);
  transition:.18s;
  min-height:72px;
}

.menuBtn:hover{
  transform:translateY(-2px);
  filter:brightness(1.06);
}

.menuBtn:active{
  transform:scale(.98);
}

.resultCard{
  background:rgba(255,255,255,.92);
  color:#333;
  border-radius:24px;
  box-shadow:0 10px 24px rgba(0,0,0,.14);
  padding:22px;
  overflow:hidden;
  position:relative;
}

.resultCard.card-anata{
  background:linear-gradient(180deg,#fff6fb,#ffe6f4);
}

.resultCard.card-osu{
  background:linear-gradient(180deg,#fff7f3,#f2e3db);
}

.resultCard.card-kanedake{
  background:linear-gradient(180deg,#f6f7ff,#e7ecff);
}

.resultCard.card-yake{
  background:linear-gradient(180deg,#faf4ff,#eeddf7);
}

.resultCard.card-omote{
  background:linear-gradient(180deg,#fffdf7,#fff1d8);
}

.resultHead{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:16px;
  margin-bottom:12px;
}

.resultLeft{
  flex:1;
}

.resultType{
  font-size:14px;
  font-weight:900;
  opacity:.8;
  margin-bottom:6px;
}

.resultRank{
  font-size:34px;
  font-weight:900;
  line-height:1.2;
  margin-bottom:8px;
}

.resultStars{
  font-size:24px;
  letter-spacing:2px;
  margin-bottom:10px;
}

.resultText{
  font-size:16px;
  line-height:1.9;
  margin-bottom:14px;
}

.resultChar{
  width:180px;
  max-width:34%;
  object-fit:contain;
  filter:drop-shadow(0 10px 16px rgba(0,0,0,.18));
}

.resultMeta{
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:12px;
  margin-top:8px;
}

.metaItem{
  background:rgba(255,255,255,.7);
  border-radius:18px;
  padding:12px 14px;
  min-height:82px;
}

.metaLabel{
  font-size:12px;
  font-weight:900;
  opacity:.72;
  margin-bottom:6px;
}

.metaValue{
  font-size:15px;
  font-weight:800;
  line-height:1.6;
}

.extraRow{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:14px;
}

.miniCard{
  background:rgba(255,255,255,.14);
  border:1px solid rgba(255,255,255,.12);
  border-radius:18px;
  padding:14px;
}

.miniTitle{
  font-size:14px;
  font-weight:900;
  margin-bottom:8px;
}

.miniText{
  font-size:13px;
  line-height:1.8;
  opacity:.95;
}

.sparkle{
  position:fixed;
  pointer-events:none;
  z-index:9999;
  font-size:22px;
  animation:sparkleFall 1600ms linear forwards;
}

@keyframes sparkleFall{
  0%{ transform:translateY(0) scale(.8); opacity:0; }
  15%{ opacity:1; }
  100%{ transform:translateY(160px) scale(1.2); opacity:0; }
}

@media (max-width: 980px){
  .layout{
    grid-template-columns:1fr;
  }
  .menuGrid{
    grid-template-columns:repeat(2,1fr);
  }
}

@media (max-width: 720px){
  .resultHead{
    flex-direction:column-reverse;
    align-items:flex-start;
  }

  .resultChar{
    width:160px;
    max-width:100%;
    align-self:center;
  }

  .resultMeta{
    grid-template-columns:1fr;
  }

  .extraRow{
    grid-template-columns:1fr;
  }

  .menuGrid{
    grid-template-columns:1fr;
  }

  .topBar{
    flex-direction:column;
    align-items:flex-start;
  }

  .resultRank{
    font-size:28px;
  }
}
</style>
</head>
<body>

<div class="wrap">

  <div class="header">
    <a href="index.html">
      <img src="images/header-main.png" alt="オモテダケ占い">
    </a>
    <div class="lead">ワンタップで、ぴったりのキャラが占うダケ🔮</div>
  </div>

  <div class="layout">

    <aside class="panel">
      <img id="sideImage" class="charImg" src="images/omotedake-main.png" alt="キャラクター">
      <div id="sideTitle" class="panelTitle">オモテダケ案内所</div>
      <div id="sideText" class="panelText">
        今日の運勢も、恋愛も、仕事も、お金も、人間関係も。<br>
        気になるテーマを押すだけで結果が出るダケ。
      </div>

      <div class="badges">
        <div>💖 今日の運勢・恋愛運 → アナタダケ</div>
        <div>📈 仕事運 → オスダケ</div>
        <div>💰 金運 → カネダケ</div>
        <div>🫧 人間関係 → ヤケザケ</div>
        <div>🍄 案内役 → オモテダケ</div>
      </div>

      <a class="backLink" href="omoteaichat.php">← AI相談室へ</a>
    </aside>

    <section class="main">

      <div class="topBar">
        <div>
          <div class="topTitle">🍄 ワンタップ占い</div>
          <div class="topSub">テーマを選ぶと、その担当キャラが占うよ</div>
        </div>
        <div class="todayChip" id="todayChip">今日の運勢は日替わりダケ</div>
      </div>

      <div class="menuGrid">
        <button class="menuBtn" data-type="today">🔮<br>今日の運勢</button>
        <button class="menuBtn" data-type="love">💖<br>恋愛運</button>
        <button class="menuBtn" data-type="work">📈<br>仕事運</button>
        <button class="menuBtn" data-type="money">💰<br>金運</button>
        <button class="menuBtn" data-type="human">🫧<br>人間関係</button>
      </div>

      <div id="resultCard" class="resultCard card-omote">
        <div class="resultHead">
          <div class="resultLeft">
            <div id="fortuneTypeLabel" class="resultType">ようこそダケ</div>
            <div id="fortuneRank" class="resultRank">占ってみるダケ！</div>
            <div id="fortuneStars" class="resultStars">☆☆☆☆☆</div>
            <div id="fortuneText" class="resultText">
              気になるテーマを押すと、その担当キャラが運命をのぞいてくれるダケ。
            </div>
          </div>
          <img id="fortuneImage" class="resultChar" src="images/omotedake-main.png" alt="キャラクター">
        </div>

        <div class="resultMeta">
          <div class="metaItem">
            <div class="metaLabel">🎨 ラッキーカラー</div>
            <div id="fortuneColor" class="metaValue">--</div>
          </div>
          <div class="metaItem">
            <div class="metaLabel">🍀 ラッキーアイテム</div>
            <div id="fortuneItem" class="metaValue">--</div>
          </div>
          <div class="metaItem">
            <div class="metaLabel">⏰ 運気アップ時間</div>
            <div id="fortuneTime" class="metaValue">--</div>
          </div>
        </div>
      </div>

      <div class="extraRow">
        <div class="miniCard">
          <div class="miniTitle">🌙 今日のひとこと</div>
          <div id="fortuneWord" class="miniText">ボタンを押すとメッセージが出るダケ。</div>
        </div>
        <div class="miniCard">
          <div class="miniTitle">✨ ワンポイント</div>
          <div id="fortuneTip" class="miniText">まずは「今日の運勢」から試すのがおすすめダケ。</div>
        </div>
      </div>

    </section>

  </div>
</div>

<script>
const sideImage = document.getElementById("sideImage");
const sideTitle = document.getElementById("sideTitle");
const sideText = document.getElementById("sideText");
const todayChip = document.getElementById("todayChip");

const typeLabel = document.getElementById("fortuneTypeLabel");
const rankEl = document.getElementById("fortuneRank");
const starsEl = document.getElementById("fortuneStars");
const textEl = document.getElementById("fortuneText");
const colorEl = document.getElementById("fortuneColor");
const itemEl = document.getElementById("fortuneItem");
const timeEl = document.getElementById("fortuneTime");
const wordEl = document.getElementById("fortuneWord");
const tipEl = document.getElementById("fortuneTip");
const imageEl = document.getElementById("fortuneImage");
const resultCard = document.getElementById("resultCard");

function starString(n){
  return "★".repeat(n) + "☆".repeat(5 - n);
}

function hashString(str){
  let h = 0;
  for(let i=0;i<str.length;i++){
    h = (h * 31 + str.charCodeAt(i)) >>> 0;
  }
  return h;
}

function seededPick(arr, seed){
  return arr[seed % arr.length];
}

function randomPick(arr){
  return arr[Math.floor(Math.random() * arr.length)];
}

const fortunes = {
  today: {
    character: "anatadake",
    title: "今日の運勢",
    image: "images/anatadake-main.png",
    cardClass: "card-anata",
    sideTitle: "アナタダケ案内所",
    sideText: "今日の運勢はアナタダケが担当。アナタの一日を近くで見つめて占うよ…💖",
    ranks: ["小吉だよ…","吉だよ…","中吉だよ…","大吉だよ…"],
    stars: [2,3,4,5],
    texts: [
      "今日は小さな嬉しさを見つける日だよ。",
      "焦らなくても、アナタの流れはちゃんと来てるよ。",
      "やさしい選択が運気を上げてくれる日かも。",
      "今日はアナタが主役になれる日だよ。"
    ],
    colors: ["ローズピンク","ラベンダー","ミルキーホワイト","コーラルピンク"],
    items: ["ハートチャーム","リボン小物","香りハンカチ","小さな鏡"],
    times: ["09:00","11:00","15:00","21:00"],
    words: [
      "アナタの魅力、ちゃんと見えてるよ。",
      "今日はアナタらしくいるのがいちばん。",
      "無理しなくても、ちゃんと届く日だよ。"
    ],
    tips: [
      "最初の一歩を軽くすると流れが変わるよ。",
      "笑顔をひとつ増やすだけで追い風になるよ。",
      "迷ったら“好き”を基準に選ぶといいよ。"
    ]
  },

  love: {
    character: "anatadake",
    title: "恋愛運",
    image: "images/anatadake-main.png",
    cardClass: "card-anata",
    sideTitle: "アナタダケ恋愛相談室",
    sideText: "恋愛運はアナタダケが担当。近すぎる目線で恋の流れを見つめるよ…💗",
    data: [
      {
        rank:"恋の追い風だよ…",
        stars:5,
        text:"今日は気持ちが伝わりやすい日。やさしい言葉が恋を引き寄せるよ。",
        color:"ローズピンク",
        item:"ハートアクセ",
        time:"20:00",
        word:"アナタの魅力、ちゃんと届いてるよ。",
        tip:"笑顔をひとつ多めに見せると空気が変わるよ。"
      },
      {
        rank:"少し近づける日だよ…",
        stars:4,
        text:"焦らなくて大丈夫。会話のテンポを合わせると距離が縮まりそう。",
        color:"ラベンダー",
        item:"香りハンカチ",
        time:"18:00",
        word:"無理に追わなくても流れは動くよ。",
        tip:"相手の話を一つ深く聞いてみるといいよ。"
      },
      {
        rank:"様子見がきれいだよ…",
        stars:3,
        text:"今日は押しすぎないほうが恋の形が整いやすい日。",
        color:"ミルキーホワイト",
        item:"小さな鏡",
        time:"22:00",
        word:"恋は焦らないほうがきれいに咲くよ。",
        tip:"連絡は短くやさしくがちょうどいいよ。"
      }
    ]
  },

  work: {
    character: "osudake",
    title: "仕事運",
    image: "images/osudake-main.png",
    cardClass: "card-osu",
    sideTitle: "オスダケ仕事相談室",
    sideText: "仕事運はオスダケが担当。段取りと現実を整える、大人の助言をくれる。",
    data: [
      {
        rank:"攻めどきだな",
        stars:5,
        text:"今日は判断力が冴える。先に動いたほうがいい流れをつかめる日だ。",
        color:"ネイビー",
        item:"腕時計",
        time:"09:00",
        word:"迷うなら動け。今日はそれでいい。",
        tip:"午前中に重要な判断を入れると強い。"
      },
      {
        rank:"手堅くいける日だ",
        stars:4,
        text:"大きく崩れない。丁寧さを優先すると評価につながりやすい。",
        color:"ブラウン",
        item:"革小物",
        time:"14:00",
        word:"一つずつ片付ければ十分だ。",
        tip:"後回しを一つ潰すだけで流れが変わる。"
      },
      {
        rank:"整える日だな",
        stars:3,
        text:"今日は前進よりも土台づくり。段取りと確認を丁寧にやるといい。",
        color:"ワインレッド",
        item:"黒いペン",
        time:"19:00",
        word:"急がなくていい。形を整えろ。",
        tip:"机まわりを整えると集中しやすくなる。"
      }
    ]
  },

  money: {
    character: "kanedake",
    title: "金運",
    image: "images/kanedake-main.png",
    cardClass: "card-kanedake",
    sideTitle: "カネダケ金運相談室",
    sideText: "金運はカネダケが担当。チャンスと流れを、華やかに見つけてくれる。",
    data: [
      {
        rank:"きらめく金運ね",
        stars:5,
        text:"今日は小さなお得や良い巡り合わせが起きやすい日。情報にツキがあるわ。",
        color:"ゴールド",
        item:"財布",
        time:"12:00",
        word:"チャンスは意外と近くにあるの。",
        tip:"気になっていた情報を一つ確認してみて。"
      },
      {
        rank:"堅実に伸びる日よ",
        stars:4,
        text:"派手さはないけれど、節約や見直しでいい流れを作れる日。",
        color:"パールホワイト",
        item:"小銭入れ",
        time:"08:00",
        word:"整えるほど、お金の空気はきれいになるわ。",
        tip:"固定費やサブスクの見直しが吉。"
      },
      {
        rank:"守りの金運ね",
        stars:3,
        text:"今日は大きな買い物より、無駄を防ぐ意識を持つほうがいいわ。",
        color:"アクアブルー",
        item:"通帳ケース",
        time:"20:00",
        word:"手元を整えると運も整うわ。",
        tip:"衝動買いは一晩寝かせると正解。"
      }
    ]
  },

  human: {
    character: "yakezake",
    title: "人間関係",
    image: "images/yakezake-main.png",
    cardClass: "card-yake",
    sideTitle: "ヤケザケ人間関係相談室",
    sideText: "人間関係はヤケザケが担当。表じゃ見えない温度差や本音を見抜いてくれる。",
    data: [
      {
        rank:"本音が見える夜ね",
        stars:4,
        text:"今日は空気を読むより、誰と距離を詰めるか選ぶほうが大事。",
        color:"ボルドー",
        item:"香水",
        time:"22:00",
        word:"みんなに好かれようとしなくていいのよ。",
        tip:"違和感がある相手とは少し距離を置いて。"
      },
      {
        rank:"静かに整える日ね",
        stars:3,
        text:"人付き合いを広げるより、疲れる関係を減らすと気持ちが楽になる日。",
        color:"スモーキーピンク",
        item:"深色のノート",
        time:"17:00",
        word:"優しさにも境界線は必要よ。",
        tip:"返事を急がなくていい関係もあるわ。"
      },
      {
        rank:"見抜く力があるわ",
        stars:5,
        text:"今日は相手の本音や温度差を察しやすい日。無理な合わせ方をやめるといい。",
        color:"パープル",
        item:"小さなキャンドル",
        time:"23:00",
        word:"違和感は、たいてい当たるのよ。",
        tip:"心がざわつく相手とは一歩引いて正解。"
      }
    ]
  }
};

function setSide(character){
  const map = {
    omotedake: {
      image:"images/omotedake-main.png",
      title:"オモテダケ案内所",
      text:"今日の運勢も、恋愛も、仕事も、お金も、人間関係も。<br>気になるテーマを押すだけで結果が出るダケ。"
    },
    anatadake: {
      image:"images/anatadake-main.png",
      title:"アナタダケ相談室",
      text:"アナタの今日と恋の流れを、近くで見つめて占うよ…💖"
    },
    osudake: {
      image:"images/osudake-main.png",
      title:"オスダケ相談室",
      text:"仕事や現実の悩みは任せな。大人の助言で整えてやる。"
    },
    kanedake: {
      image:"images/kanedake-main.png",
      title:"カネダケ相談室",
      text:"お金の流れやチャンスを、華やかに見つけるダケ。"
    },
    yakezake: {
      image:"images/yakezake-main.png",
      title:"ヤケザケ相談室",
      text:"人間関係の濁りも温度差も、あたしが少し深く見るわ。"
    }
  };

  const d = map[character] || map.omotedake;
  sideImage.src = d.image;
  sideTitle.textContent = d.title;
  sideText.innerHTML = d.text;
}

function sparkles(count=12){
  const chars = ["✨","⭐","🌟"];
  for(let i=0;i<count;i++){
    const el = document.createElement("div");
    el.className = "sparkle";
    el.textContent = chars[Math.floor(Math.random()*chars.length)];
    el.style.left = (window.innerWidth * Math.random()) + "px";
    el.style.top = (window.innerHeight * 0.16 + Math.random()*120) + "px";
    document.body.appendChild(el);
    setTimeout(()=>el.remove(),1600);
  }
}

function renderTodayFortune(){
  const today = new Date().toISOString().slice(0,10);
  const base = fortunes.today;
  const seed = hashString(today + "anatadake");

  const rank = seededPick(base.ranks, seed + 1);
  const stars = seededPick(base.stars, seed + 2);
  const text = seededPick(base.texts, seed + 3);
  const color = seededPick(base.colors, seed + 4);
  const item = seededPick(base.items, seed + 5);
  const time = seededPick(base.times, seed + 6);
  const word = seededPick(base.words, seed + 7);
  const tip = seededPick(base.tips, seed + 8);

  resultCard.className = "resultCard " + base.cardClass;
  typeLabel.textContent = base.title;
  rankEl.textContent = rank;
  starsEl.textContent = starString(stars);
  textEl.textContent = text;
  colorEl.textContent = color;
  itemEl.textContent = item;
  timeEl.textContent = time;
  wordEl.textContent = word;
  tipEl.textContent = tip;
  imageEl.src = base.image;

  setSide(base.character);
  todayChip.textContent = "今日の運勢は日替わり固定ダケ";
  sparkles(10);
}

function renderFortune(type){
  if(type === "today"){
    renderTodayFortune();
    return;
  }

  const base = fortunes[type];
  const item = randomPick(base.data);

  resultCard.className = "resultCard " + base.cardClass;
  typeLabel.textContent = base.title;
  rankEl.textContent = item.rank;
  starsEl.textContent = starString(item.stars);
  textEl.textContent = item.text;
  colorEl.textContent = item.color;
  itemEl.textContent = item.item;
  timeEl.textContent = item.time;
  wordEl.textContent = item.word;
  tipEl.textContent = item.tip;
  imageEl.src = base.image;

  setSide(base.character);
  todayChip.textContent = "テーマごとに占い中ダケ";

  if(item.stars >= 5){
    sparkles(18);
  }else{
    sparkles(8);
  }
}

document.querySelectorAll(".menuBtn").forEach(btn=>{
  btn.addEventListener("click",()=>{
    renderFortune(btn.dataset.type);
  });
});

setSide("omotedake");
</script>

</body>
</html>