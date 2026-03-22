(() => {
  const fortunes = {
    today: [
      {
        label: "今日の運勢",
        rank: "超大吉ダケ！",
        stars: 5,
        text: "今日はキラキラ最高潮ダケ！思いきって動くと、うれしい流れがどんどん来るよ。",
        color: "きらきらブルー",
        item: "星のチャーム",
        word: "どんどんチャレンジするダケ！",
        time: "19:00",
        image: "./images/omotedake-happy.png",
        state: "state-super"
      },
      {
        label: "今日の運勢",
        rank: "大吉ダケ！",
        stars: 5,
        text: "今日は最高の日ダケ！いいことを引き寄せやすいから、笑顔でいくダケ！",
        color: "サニーブルー",
        item: "キラキラペン",
        word: "自分から話しかけるダケ！",
        time: "20:00",
        image: "./images/omotedake-happy.png",
        state: "state-daikichi"
      },
      {
        label: "今日の運勢",
        rank: "中吉ダケ！",
        stars: 4,
        text: "ふんわり良い流れの日ダケ。丁寧に進めると、運気が味方してくれるよ。",
        color: "ミルクホワイト",
        item: "メモ帳",
        word: "ゆっくり確認するダケ！",
        time: "14:00",
        image: "./images/omotedake-main.png",
        state: ""
      },
      {
        label: "今日の運勢",
        rank: "吉ダケ！",
        stars: 3,
        text: "小さな幸運がありそうダケ。今日のラッキーを見逃さないでほしいダケ。",
        color: "やさしいピンク",
        item: "きのこシール",
        word: "笑顔を忘れないダケ！",
        time: "11:00",
        image: "./images/omotedake-thinking.png",
        state: ""
      },
      {
        label: "今日の運勢",
        rank: "小吉ダケ！",
        stars: 2,
        text: "今日はゆっくり過ごすダケ。休みながら進むと、ちゃんといい方向に向かうよ。",
        color: "うすむらさき",
        item: "ハンドクリーム",
        word: "休憩するダケ！",
        time: "21:00",
        image: "./images/omotedake-thinking.png",
        state: ""
      }
    ],

    love: [
      {
        label: "恋愛運",
        rank: "恋の大チャンスダケ！",
        stars: 5,
        text: "気持ちが伝わりやすい日ダケ。素直なひとことが恋をふわっと動かすかも。",
        color: "ローズピンク",
        item: "ハートアクセ",
        word: "照れても伝えるダケ！",
        time: "20:30",
        image: "./images/omotedake-love.png",
        state: "state-love"
      },
      {
        label: "恋愛運",
        rank: "ふんわり恋愛運ダケ！",
        stars: 4,
        text: "やさしい空気が魅力になる日ダケ。話し方をやわらかくすると良い流れだよ。",
        color: "ラベンダー",
        item: "香りハンカチ",
        word: "やさしく話すダケ！",
        time: "18:00",
        image: "./images/omotedake-love.png",
        state: "state-love"
      },
      {
        label: "恋愛運",
        rank: "恋は育てる日ダケ！",
        stars: 3,
        text: "急展開じゃなくても大丈夫ダケ。少しずつ距離を縮める日にぴったりだよ。",
        color: "サクラベージュ",
        item: "小さなお守り",
        word: "焦らないダケ！",
        time: "22:00",
        image: "./images/omotedake-thinking.png",
        state: "state-love"
      }
    ],

    work: [
      {
        label: "仕事運",
        rank: "仕事運アップダケ！",
        stars: 5,
        text: "集中力が高まるダケ。面倒なことほど先に進めるとスッキリするよ。",
        color: "ネイビー",
        item: "青いペン",
        word: "先手必勝ダケ！",
        time: "10:00",
        image: "./images/omotedake-main.png",
        state: "state-work"
      },
      {
        label: "仕事運",
        rank: "堅実に進めるダケ！",
        stars: 4,
        text: "丁寧な仕事が評価につながりやすい日ダケ。確認を一回増やすと◎。",
        color: "グレー",
        item: "付箋メモ",
        word: "落ち着いていくダケ！",
        time: "15:00",
        image: "./images/omotedake-thinking.png",
        state: "state-work"
      },
      {
        label: "仕事運",
        rank: "急がば回れダケ！",
        stars: 3,
        text: "今日は焦ると空回りしやすいダケ。順番どおりに進めるのがいちばんだよ。",
        color: "クリームイエロー",
        item: "温かい飲み物",
        word: "深呼吸するダケ！",
        time: "13:00",
        image: "./images/omotedake-thinking.png",
        state: "state-work"
      }
    ],

    money: [
      {
        label: "金運",
        rank: "金運ぴかぴかダケ！",
        stars: 5,
        text: "お得な巡り合わせがあるかもダケ。小さなチャンスを大事にしてみて。",
        color: "ゴールド",
        item: "小銭入れ",
        word: "無駄買いしないダケ！",
        time: "17:00",
        image: "./images/omotedake-happy.png",
        state: "state-money"
      },
      {
        label: "金運",
        rank: "節約上手ダケ！",
        stars: 4,
        text: "使い方を工夫すると満足度が高い日ダケ。必要なものを見きわめると◎。",
        color: "モスグリーン",
        item: "家計メモ",
        word: "ひと呼吸おいて買うダケ！",
        time: "12:00",
        image: "./images/omotedake-main.png",
        state: "state-money"
      },
      {
        label: "金運",
        rank: "守りの金運ダケ！",
        stars: 3,
        text: "今日は整える日に向いているダケ。財布の中を整理すると運気アップだよ。",
        color: "ブラウン",
        item: "カードケース",
        word: "きれいに整えるダケ！",
        time: "09:00",
        image: "./images/omotedake-thinking.png",
        state: "state-money"
      }
    ]
  };

  const pairMessages = [
    "ふたりは自然体でいるほど相性が深まるタイプダケ！",
    "会話のテンポが合いやすい相性ダケ。小さなやり取りを大事にしてみて。",
    "少し違うところがあるからこそ、お互いを補い合える相性ダケ！",
    "やさしさを言葉にすると、もっと仲良くなれるふたりダケ。",
    "タイミングを合わせると、ぐっと距離が縮まりやすい相性ダケ！"
  ];

  const resultCard = document.getElementById("resultCard");
  const typeLabel = document.getElementById("fortuneTypeLabel");
  const rankEl = document.getElementById("fortuneRank");
  const starsEl = document.getElementById("fortuneStars");
  const textEl = document.getElementById("fortuneText");
  const colorEl = document.getElementById("fortuneColor");
  const itemEl = document.getElementById("fortuneItem");
  const wordEl = document.getElementById("fortuneWord");
  const timeEl = document.getElementById("fortuneTime");
  const imageEl = document.getElementById("fortuneImage");

  const pairName1 = document.getElementById("pairName1");
  const pairName2 = document.getElementById("pairName2");
  const pairBtn = document.getElementById("pairBtn");
  const pairResult = document.getElementById("pairResult");
  const pairFocusBtn = document.querySelector(".js-focus-pair");

  function pick(list) {
    return list[Math.floor(Math.random() * list.length)];
  }

  function starString(n) {
    return "★".repeat(n) + "☆".repeat(5 - n);
  }

  function clearStates() {
    resultCard.classList.remove("state-super", "state-daikichi", "state-love", "state-work", "state-money");
  }

function renderFortune(type) {
  const item = pick(fortunes[type]);
  clearStates();
  if (item.state) resultCard.classList.add(item.state);

  typeLabel.textContent = item.label;
  rankEl.textContent = item.rank;
  starsEl.textContent = starString(item.stars);
  textEl.textContent = item.text;
  colorEl.textContent = item.color;
  itemEl.textContent = item.item;
  wordEl.textContent = item.word;
  timeEl.textContent = item.time;
  imageEl.src = item.image;

  // ⭐ 星演出は「今日の運勢」の星5のときのみ
  if (type === "today" && Number(item.stars) === 5) {
    setTimeout(() => starRain(24), 40);
  }

  // 💖 恋愛運（星5のときのみ）
  if (type === "love" && Number(item.stars) === 5) {
    setTimeout(() => heartRain(22), 0);
  }

  // 💰 金運（星5のときのみ）
  if (type === "money" && Number(item.stars) === 5) {
    setTimeout(() => coinRain(20), 0);
  }

  // 📈 仕事運（星5のときのみ）
  if (type === "work" && Number(item.stars) === 5) {
    setTimeout(() => lightningEffect(2), 0);
  }

  return item;
}

  function calcPairScore(a, b) {
    const text = `${a}❤${b}`;
    let sum = 0;
    for (let i = 0; i < text.length; i++) {
      sum += text.charCodeAt(i);
    }
    return (sum % 41) + 60; // 60-100
  }

  function runPair() {
    const a = pairName1.value.trim();
    const b = pairName2.value.trim();

    pairResult.textContent = "";
    const scoreEl = document.createElement("div");
    scoreEl.className = "pairScore";
    const messageEl = document.createElement("div");
    messageEl.className = "pairMessage";

    if (!a || !b) {
      scoreEl.textContent = "--%";
      messageEl.textContent = "ふたりの名前を入れてほしいダケ！";
      pairResult.append(scoreEl, messageEl);
      return;
    }

    const score = calcPairScore(a, b);
    const msg = pick(pairMessages);

    scoreEl.textContent = `${score}%`;
    messageEl.textContent = `${a} と ${b} の相性は ${score}% ダケ！ ${msg}`;
    pairResult.append(scoreEl, messageEl);
  }

  function magicEffectFromButton(btn) {
    const rect = btn.getBoundingClientRect();
    const x = rect.left + rect.width / 2;
    const y = rect.top + rect.height / 2;

    const stars = ["✨", "⭐", "🌟"];

    for (let i = 0; i < 10; i++) {
      const star = document.createElement("div");
      star.className = "magic-star";
      star.textContent = stars[Math.floor(Math.random() * stars.length)];

      const dx = (Math.random() * 120 - 60) + "px";
      const dy = (Math.random() * -120 - 20) + "px";

      star.style.left = x + "px";
      star.style.top = y + "px";
      star.style.setProperty("--dx", dx);
      star.style.setProperty("--dy", dy);

      document.body.appendChild(star);

      setTimeout(() => {
        star.remove();
      }, 900);
    }
  }

  document.querySelectorAll(".js-fortune-btn").forEach((btn) => {
    btn.addEventListener("click", () => {
      const type = btn.dataset.type;
      const result = renderFortune(type);
      if (Number(result.stars) === 5) {
        magicEffectFromButton(btn);
      }
      window.scrollTo({ top: resultCard.offsetTop - 20, behavior: "smooth" });
    });
  });

  if (pairBtn) {
    pairBtn.addEventListener("click", runPair);
  }

  if (pairFocusBtn) {
    pairFocusBtn.addEventListener("click", () => {
      document.getElementById("pairCard").scrollIntoView({ behavior: "smooth", block: "start" });
      setTimeout(() => pairName1.focus(), 400);
    });
  }

  renderFortune("today");
})();
function animateFallingItem(el, durationMs, rotateDeg) {
  if (typeof el.animate === "function") {
    el.animate(
      [
        { transform: "translateY(0) rotate(0deg)", opacity: 0 },
        { transform: "translateY(8vh) rotate(0deg)", opacity: 1, offset: 0.12 },
        { transform: `translateY(112vh) rotate(${rotateDeg}deg)`, opacity: 0 }
      ],
      { duration: durationMs, easing: "linear", fill: "forwards" }
    );
    return;
  }

  // Fallback for browsers without Web Animations API
  el.style.animation = `fall ${durationMs / 1000}s linear forwards`;
}

// 大吉のときだけ星が降る演出
function starRain(count = 24){
  const stars = ["⭐","🌟","✨"];

  for(let i = 0; i < count; i++){
    const star = document.createElement("div");
    star.className = "effect-item";
    star.textContent = stars[Math.floor(Math.random() * stars.length)];
    star.style.position = "fixed";
    star.style.left = Math.random() * 100 + "vw";
    star.style.top = "-10vh";
    star.style.zIndex = "9999";
    star.style.pointerEvents = "none";
    star.style.fontSize = (18 + Math.random() * 18) + "px";
    star.style.textShadow = "0 0 10px rgba(255,255,255,.9)";
    document.body.appendChild(star);

    const durationMs = (2500 + Math.random() * 1800);
    animateFallingItem(star, durationMs, 240 + Math.random() * 220);
    setTimeout(() => star.remove(), durationMs + 250);
  }
}
function heartBurst(x, y, count = 14) {
  const hearts = ["💖","💗","💘","💕"];

  for (let i = 0; i < count; i++) {
    const el = document.createElement("div");
    el.className = "effect-item flying-heart";
    el.textContent = hearts[Math.floor(Math.random() * hearts.length)];
    el.style.left = x + "px";
    el.style.top = y + "px";
    el.style.fontSize = (18 + Math.random() * 16) + "px";
    el.style.setProperty("--dx", (Math.random() * 180 - 90) + "px");
    el.style.setProperty("--dy", (Math.random() * -140 - 30) + "px");
    document.body.appendChild(el);

    setTimeout(() => el.remove(), 1000);
  }
}

// 上からハートが降ってくる演出（恋愛運用）
function heartRain(count = 18) {
  const hearts = ["💖","💗","💘","💕"];

  for (let i = 0; i < count; i++) {
    const el = document.createElement("div");
    el.className = "effect-item";
    el.textContent = hearts[Math.floor(Math.random() * hearts.length)];
    el.style.position = "fixed";
    el.style.left = Math.random() * 100 + "vw";
    el.style.top = "-10vh";
    el.style.fontSize = (18 + Math.random() * 18) + "px";
    el.style.zIndex = "9999";
    el.style.pointerEvents = "none";
    document.body.appendChild(el);

    const durationMs = (2100 + Math.random() * 1600);
    animateFallingItem(el, durationMs, 120 + Math.random() * 240);
    setTimeout(() => el.remove(), durationMs + 250);
  }
}

function coinRain(count = 18) {
  const coins = ["🪙","💰"];

  for (let i = 0; i < count; i++) {
    const el = document.createElement("div");
    el.className = "effect-item falling-coin";
    el.textContent = coins[Math.floor(Math.random() * coins.length)];
    el.style.left = Math.random() * 100 + "vw";
    el.style.animationDuration = (2.2 + Math.random() * 1.8) + "s";
    el.style.fontSize = (18 + Math.random() * 18) + "px";
    document.body.appendChild(el);

    setTimeout(() => el.remove(), 4500);
  }
}

function lightningEffect(count = 2) {
  const stage = document.querySelector(".siteWrap");

  for (let i = 0; i < count; i++) {
    setTimeout(() => {
      const flash = document.createElement("div");
      flash.style.position = "fixed";
      flash.style.inset = "0";
      flash.style.pointerEvents = "none";
      flash.style.zIndex = "10000";
      flash.style.background =
        "radial-gradient(circle at 30% 18%, rgba(255,255,255,.95), rgba(180,230,255,.35) 18%, rgba(255,255,255,0) 48%), rgba(255,255,255,.22)";
      flash.style.mixBlendMode = "screen";
      flash.style.opacity = "0";
      document.body.appendChild(flash);

      const bolt = document.createElement("div");
      bolt.style.position = "fixed";
      bolt.style.left = (22 + Math.random() * 56) + "vw";
      bolt.style.top = "-6vh";
      bolt.style.height = (60 + Math.random() * 26) + "vh";
      bolt.style.width = (4 + Math.random() * 3) + "px";
      bolt.style.pointerEvents = "none";
      bolt.style.zIndex = "10001";
      bolt.style.borderRadius = "999px";
      bolt.style.background = "linear-gradient(180deg, rgba(255,255,255,0), rgba(255,255,255,.98) 18%, rgba(170,225,255,.92) 68%, rgba(255,255,255,0))";
      bolt.style.filter = "drop-shadow(0 0 12px rgba(190,240,255,.95))";
      bolt.style.transform = `skewX(${Math.random() * 22 - 11}deg)`;
      bolt.style.opacity = "0";
      document.body.appendChild(bolt);

      if (typeof flash.animate === "function") {
        flash.animate(
          [
            { opacity: 0 },
            { opacity: 1, offset: 0.2 },
            { opacity: 0.65, offset: 0.48 },
            { opacity: 0 }
          ],
          { duration: 260, easing: "ease-out", fill: "forwards" }
        );
        bolt.animate(
          [
            { opacity: 0, transform: `${bolt.style.transform} translateY(-10px)` },
            { opacity: 1, offset: 0.2 },
            { opacity: 0.9, offset: 0.45 },
            { opacity: 0, transform: `${bolt.style.transform} translateY(8px)` }
          ],
          { duration: 240, easing: "ease-out", fill: "forwards" }
        );
      } else {
        flash.style.animation = "lightningFlash .26s ease-out forwards";
        bolt.style.animation = "lightningFlash .24s ease-out forwards";
      }

      if (stage && typeof stage.animate === "function") {
        stage.animate(
          [
            { transform: "translateX(0)" },
            { transform: "translateX(-4px)", offset: 0.2 },
            { transform: "translateX(3px)", offset: 0.45 },
            { transform: "translateX(-2px)", offset: 0.7 },
            { transform: "translateX(0)" }
          ],
          { duration: 180, easing: "ease-out" }
        );
      }

      setTimeout(() => {
        flash.remove();
        bolt.remove();
      }, 320);
    }, i * 170);
  }
}

function getElementCenter(el) {
  const rect = el.getBoundingClientRect();
  return {
    x: rect.left + rect.width / 2,
    y: rect.top + rect.height / 2
  };
}
