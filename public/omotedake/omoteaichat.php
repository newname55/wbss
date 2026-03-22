<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>オモテダケ占い｜AI相談室</title>
<style>
:root{
  --txt:#ffffff;
  --card:rgba(255,255,255,.14);
  --line:rgba(255,255,255,.18);
  --shadow:0 14px 30px rgba(0,0,0,.18);

  --omote:#6f49b6;
  --anata:#d85b9f;
  --osu:#8a5a43;
  --kane:#c59a18;
  --yake:#7b3f89;
  --kami:#f2d57b;
}

*{ box-sizing:border-box; }
html,body{ margin:0; min-height:100%; }

body{
  font-family:"Hiragino Maru Gothic ProN","Yu Gothic","Meiryo",sans-serif;
  color:var(--txt);
  background:
    radial-gradient(circle at 10% 20%, rgba(255,255,255,.45) 0 2px, transparent 3px),
    radial-gradient(circle at 80% 30%, rgba(255,255,255,.35) 0 2px, transparent 3px),
    radial-gradient(circle at 30% 70%, rgba(255,255,255,.25) 0 2px, transparent 3px),
    linear-gradient(180deg,#1f1d5a,#5b3ea8,#ff9ed6);
  background-size:200px 200px,180px 180px,220px 220px,cover;
  transition:background .45s ease, filter .3s ease;
  overflow-x:hidden;
}

body.theme-omotedake{
  background:
    radial-gradient(circle at 10% 20%, rgba(255,255,255,.45) 0 2px, transparent 3px),
    radial-gradient(circle at 80% 30%, rgba(255,255,255,.35) 0 2px, transparent 3px),
    radial-gradient(circle at 30% 70%, rgba(255,255,255,.25) 0 2px, transparent 3px),
    linear-gradient(180deg,#1f1d5a,#5b3ea8,#ff9ed6);
}
body.theme-anatadake{
  background:
    radial-gradient(circle at 12% 18%, rgba(255,255,255,.42) 0 2px, transparent 3px),
    radial-gradient(circle at 84% 30%, rgba(255,255,255,.28) 0 2px, transparent 3px),
    radial-gradient(circle at 25% 80%, rgba(255,255,255,.22) 0 2px, transparent 3px),
    linear-gradient(180deg,#7a2860,#d85b9f,#ffc5e6);
}
body.theme-osudake{
  background:
    radial-gradient(circle at 12% 18%, rgba(255,255,255,.28) 0 2px, transparent 3px),
    radial-gradient(circle at 84% 30%, rgba(255,255,255,.18) 0 2px, transparent 3px),
    radial-gradient(circle at 25% 80%, rgba(255,255,255,.15) 0 2px, transparent 3px),
    linear-gradient(180deg,#3e2b22,#8a5a43,#d8b094);
}
body.theme-kanedake{
  background:
    radial-gradient(circle at 12% 18%, rgba(255,255,255,.35) 0 2px, transparent 3px),
    radial-gradient(circle at 84% 30%, rgba(255,255,255,.22) 0 2px, transparent 3px),
    radial-gradient(circle at 25% 80%, rgba(255,255,255,.18) 0 2px, transparent 3px),
    linear-gradient(180deg,#5e4708,#c59a18,#ffe08b);
}
body.theme-yakezake{
  background:
    radial-gradient(circle at 12% 18%, rgba(255,255,255,.20) 0 2px, transparent 3px),
    radial-gradient(circle at 84% 30%, rgba(255,255,255,.14) 0 2px, transparent 3px),
    radial-gradient(circle at 25% 80%, rgba(255,255,255,.12) 0 2px, transparent 3px),
    linear-gradient(180deg,#2f1138,#7b3f89,#c17dd7);
}
body.theme-kamidake{
  background:
    radial-gradient(circle at 15% 20%, rgba(255,255,255,.55) 0 2px, transparent 3px),
    radial-gradient(circle at 75% 18%, rgba(255,230,160,.28) 0 2px, transparent 3px),
    radial-gradient(circle at 35% 78%, rgba(255,255,255,.18) 0 2px, transparent 3px),
    linear-gradient(180deg,#050505,#362400,#f6e4a8);
}
body.theme-uradake{
  background:
    radial-gradient(circle at 12% 18%, rgba(255,255,255,.16) 0 2px, transparent 3px),
    radial-gradient(circle at 84% 30%, rgba(180,120,255,.18) 0 2px, transparent 3px),
    radial-gradient(circle at 25% 80%, rgba(255,255,255,.10) 0 2px, transparent 3px),
    linear-gradient(180deg,#12051f,#3d1660,#7f4ad1);
}

.card-ura .fortuneTitle{
  color:#b88cff;
}
.wrap{
  max-width:1140px;
  margin:0 auto;
  padding:20px 16px 40px;
}

.header{
  text-align:center;
  margin-bottom:16px;
}
.header img{
  width:min(600px,74%);
  max-width:100%;
}
.lead{
  text-align:center;
  margin:8px 0 20px;
  font-size:18px;
  font-weight:800;
}

.layout{
  display:grid;
  grid-template-columns:330px 1fr;
  gap:18px;
}

.sideCard,.chatCard{
  background:var(--card);
  border:1px solid var(--line);
  border-radius:24px;
  box-shadow:var(--shadow);
  backdrop-filter:blur(8px);
}

.sideCard{
  padding:20px;
  text-align:center;
}

.omoteImg{
  width:210px;
  max-width:100%;
  display:block;
  margin:0 auto 12px;
  animation:float 4s ease-in-out infinite;
  filter:drop-shadow(0 12px 20px rgba(0,0,0,.28));
  transition:transform .28s ease, filter .28s ease;
}
.omoteImg.pop{
  animation:float 4s ease-in-out infinite, charPop .45s ease;
}
@keyframes float{
  0%{ transform:translateY(0); }
  50%{ transform:translateY(-10px); }
  100%{ transform:translateY(0); }
}
@keyframes charPop{
  0%{ transform:translateY(12px) scale(.92); opacity:.72; }
  60%{ transform:translateY(-4px) scale(1.04); opacity:1; }
  100%{ transform:translateY(0) scale(1); opacity:1; }
}

.sideTitle{
  font-size:22px;
  font-weight:900;
  margin-bottom:8px;
}
.sideText{
  line-height:1.8;
  opacity:.97;
  font-size:14px;
}

.quickBtns{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:10px;
  margin-top:16px;
}

.quickBtns button{
  border:none;
  border-radius:18px;
  padding:14px 14px;
  min-height:84px;
  background:rgba(255,255,255,.18);
  color:#fff;
  font-weight:900;
  cursor:pointer;
  transition:.18s;
  display:flex;
  flex-direction:column;
  align-items:flex-start;
  justify-content:center;
  text-align:left;
  line-height:1.35;
}

.quickBtns button:hover{
  filter:brightness(1.08);
  transform:translateY(-1px);
}

.quickBtns button:active{
  transform:scale(.98);
}

.quickBtns button.mainTopic{
  grid-column:1 / -1;
  min-height:108px;
}

.quickBtns .btnTitle{
  font-size:18px;
  font-weight:900;
}

.quickBtns button.mainTopic .btnTitle{
  font-size:22px;
}

.quickBtns .btnMeta{
  margin-top:6px;
  font-size:12px;
  opacity:.88;
  font-weight:800;
}

@media (max-width: 640px){
  .quickBtns button{
    min-height:76px;
    padding:12px;
  }

  .quickBtns button.mainTopic{
    min-height:92px;
  }

  .quickBtns .btnTitle{
    font-size:16px;
  }

  .quickBtns button.mainTopic .btnTitle{
    font-size:20px;
  }

  .quickBtns .btnMeta{
    font-size:11px;
  }
}

.kamiBook{
  margin-top:14px;
  padding:12px 14px;
  border-radius:16px;
  background:rgba(255,255,255,.12);
  text-align:left;
}
.kamiBook__title{
  font-size:13px;
  font-weight:900;
  margin-bottom:8px;
}
.kamiBook__count{
  font-size:24px;
  font-weight:900;
  line-height:1;
  color:#fff3b2;
}
.kamiBook__sub{
  margin-top:6px;
  font-size:12px;
  opacity:.9;
}

.backLink{
  display:inline-block;
  margin-top:14px;
  color:#fff;
  text-decoration:none;
  font-size:14px;
  opacity:.9;
}

.chatCard{
  padding:14px;
  display:flex;
  flex-direction:column;
  min-height:720px;
}

.chatHead{
  position:relative;
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  padding:8px 8px 14px;
  border-bottom:1px solid rgba(255,255,255,.12);
}

.chatTitle{
  font-size:20px;
  font-weight:900;
}
.chatSub{
  font-size:12px;
  opacity:.85;
}
.activeCharacter{
  font-size:12px;
  font-weight:900;
  background:rgba(255,255,255,.16);
  padding:8px 12px;
  border-radius:999px;
  white-space:nowrap;
}

.messages{
  flex:1;
  overflow:auto;
  padding:18px 8px 8px;
  display:flex;
  flex-direction:column;
  gap:14px;
  scroll-behavior:smooth;
}

.msgRow{
  display:flex;
  align-items:flex-start;
  gap:10px;
}
.msgRow.user{
  justify-content:flex-end;
}
.msgRow.user .msgAvatar{ display:none; }
.msgRow.user .msgMain{ align-items:flex-end; }

.msgAvatar{
  width:56px;
  height:56px;
  border-radius:50%;
  overflow:hidden;
  flex:0 0 56px;
  background:rgba(255,255,255,.88);
  box-shadow:0 8px 16px rgba(0,0,0,.14);
  border:2px solid rgba(255,255,255,.5);
}
.msgAvatar img{
  width:100%;
  height:100%;
  object-fit:cover;
  display:block;
}

.msgMain{
  max-width:min(78%, 640px);
  display:flex;
  flex-direction:column;
  align-items:flex-start;
}
.msgName{
  font-size:12px;
  font-weight:900;
  margin:0 0 6px 8px;
  text-shadow:0 1px 0 rgba(255,255,255,.15);
}

.bubble{
  position:relative;
  padding:12px 16px;
  border-radius:18px;
  line-height:1.8;
  font-size:15px;
  box-shadow:0 8px 18px rgba(0,0,0,.12);
  word-break:break-word;
}
.msgRow.user .bubble{
  background:linear-gradient(135deg,#ffd95c,#ff9ed6);
  color:#43203e;
  font-weight:800;
  border-top-right-radius:8px;
}
.msgRow.user .bubble::after{
  content:"";
  position:absolute;
  right:-9px;
  top:16px;
  border-left:11px solid #ff9ed6;
  border-top:10px solid transparent;
  border-bottom:10px solid transparent;
}
.msgRow.character .bubble{
  background:rgba(255,255,255,.96);
  color:#44354a;
  border-top-left-radius:8px;
}
.msgRow.character .bubble::before{
  content:"";
  position:absolute;
  left:-9px;
  top:16px;
  border-right:11px solid rgba(255,255,255,.96);
  border-top:10px solid transparent;
  border-bottom:10px solid transparent;
}

.msgRow.omotedake .msgName{ color:#efdfff; }
.msgRow.anatadake .msgName{ color:#ffd5ea; }
.msgRow.osudake .msgName{ color:#ffe0d0; }
.msgRow.kanedake .msgName{ color:#fff2b3; }
.msgRow.yakezake .msgName{ color:#f1d1ff; }
.msgRow.kamidake .msgName{ color:#fff0b5; }

.character-enter{
  animation:characterEnter .4s ease;
}
@keyframes characterEnter{
  0%{
    transform:translateY(30px) scale(.9);
    opacity:0;
  }
  100%{
    transform:translateY(0) scale(1);
    opacity:1;
  }
}

.typing{
  display:inline-flex;
  gap:6px;
  align-items:center;
}
.typing span{
  width:8px;
  height:8px;
  border-radius:50%;
  background:#d48ac8;
  display:inline-block;
  animation:blink 1.2s infinite;
}
.typing span:nth-child(2){ animation-delay:.2s; }
.typing span:nth-child(3){ animation-delay:.4s; }
@keyframes blink{
  0%,80%,100%{ opacity:.3; transform:translateY(0); }
  40%{ opacity:1; transform:translateY(-3px); }
}

.fortuneCard{
  margin:10px 8px 6px;
  padding:16px;
  border-radius:18px;
  background:rgba(255,255,255,.94);
  color:#333;
  box-shadow:0 8px 18px rgba(0,0,0,.12);
  font-size:14px;
  line-height:1.8;
  min-height:110px;
  animation:cardEnter .4s ease;
}
@keyframes cardEnter{
  0%{
    transform:translateY(20px);
    opacity:0;
  }
  100%{
    transform:translateY(0);
    opacity:1;
  }
}
.fortuneTitle{
  font-weight:900;
  margin-bottom:8px;
  font-size:15px;
}
.card-anata .fortuneTitle{ color:var(--anata); }
.card-osu .fortuneTitle{ color:var(--osu); }
.card-kanedake .fortuneTitle{ color:var(--kane); }
.card-yake .fortuneTitle{ color:var(--yake); }
.card-omote .fortuneTitle{ color:var(--omote); }
.card-kami .fortuneTitle{ color:var(--kami); }

.inputWrap{
  display:flex;
  gap:10px;
  padding:12px 6px 4px;
  border-top:1px solid rgba(255,255,255,.12);
}
.inputWrap input{
  flex:1;
  border:none;
  border-radius:16px;
  padding:14px 16px;
  font-size:15px;
  outline:none;
}
.sendBtn{
  min-width:92px;
  border:none;
  border-radius:16px;
  background:linear-gradient(135deg,#ffd95c,#ff9ed6);
  color:#472246;
  font-weight:900;
  cursor:pointer;
}
.sendBtn:hover{
  filter:brightness(1.05);
}
.sendBtn:disabled{
  opacity:.6;
  cursor:default;
}

/* BGM panel */
.kamiBgmPanel{
  margin:10px 8px 0;
  padding:12px 14px;
  border-radius:18px;
  background:linear-gradient(135deg, rgba(255,245,180,.22), rgba(255,255,255,.10));
  border:1px solid rgba(255,255,255,.20);
  display:none;
  align-items:center;
  justify-content:space-between;
  gap:12px;
  box-shadow:0 8px 18px rgba(0,0,0,.10);
}
.kamiBgmPanel.show{
  display:flex;
  animation:cardEnter .35s ease;
}
.kamiBgmInfo{
  min-width:0;
}
.kamiBgmTitle{
  font-size:14px;
  font-weight:900;
  color:#fff3be;
}
.kamiBgmSub{
  font-size:12px;
  opacity:.9;
}
.kamiBgmBtns{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.kamiBgmBtn{
  border:none;
  border-radius:999px;
  padding:10px 14px;
  font-weight:900;
  cursor:pointer;
  color:#5f4300;
  background:linear-gradient(135deg,#fff3a0,#ffd34f,#fff7d6);
}
.kamiBgmBtn.stop{
  background:rgba(255,255,255,.88);
  color:#5d4f2d;
}
.kamiBgmBtn:hover{
  filter:brightness(1.04);
}

/* effects */
.fxLayer{
  position:fixed;
  inset:0;
  pointer-events:none;
  overflow:hidden;
  z-index:9999;
}
.fxStar,.fxHeart,.fxCoin,.fxMist{
  position:absolute;
  user-select:none;
  pointer-events:none;
  will-change:transform, opacity;
}
.fxStar{
  color:#fff7a8;
  text-shadow:0 0 10px rgba(255,255,255,.8), 0 0 20px rgba(255,231,120,.65);
  animation:fallStar linear forwards;
}
.fxHeart{
  color:#ff86c8;
  text-shadow:0 0 10px rgba(255,130,200,.65);
  animation:fallHeart linear forwards;
}
.fxCoin{
  color:#ffd54a;
  text-shadow:0 0 10px rgba(255,210,70,.8), 0 0 18px rgba(255,185,0,.55);
  animation:fallCoin linear forwards;
}
.fxMist{
  width:120px;
  height:120px;
  border-radius:50%;
  background:radial-gradient(circle, rgba(179,114,255,.22) 0%, rgba(115,50,130,.10) 45%, rgba(0,0,0,0) 72%);
  filter:blur(4px);
  animation:mistFloat linear forwards;
}

@keyframes fallStar{
  0%{ transform:translateY(-40px) rotate(0deg) scale(.8); opacity:0; }
  10%{ opacity:1; }
  100%{ transform:translateY(110vh) rotate(180deg) scale(1.2); opacity:0; }
}
@keyframes fallHeart{
  0%{ transform:translateY(-40px) translateX(0) scale(.8); opacity:0; }
  10%{ opacity:1; }
  100%{ transform:translateY(110vh) translateX(40px) scale(1.15); opacity:0; }
}
@keyframes fallCoin{
  0%{ transform:translateY(-40px) rotateY(0deg) rotate(0deg) scale(.8); opacity:0; }
  10%{ opacity:1; }
  100%{ transform:translateY(110vh) rotateY(720deg) rotate(260deg) scale(1.05); opacity:0; }
}
@keyframes mistFloat{
  0%{ transform:translateY(20px) scale(.8); opacity:0; }
  15%{ opacity:1; }
  100%{ transform:translateY(-120px) translateX(30px) scale(1.5); opacity:0; }
}

.superLuckyBanner{
  position:fixed;
  left:50%;
  top:70px;
  transform:translateX(-50%) scale(.92);
  z-index:10000;
  pointer-events:none;
  background:linear-gradient(135deg,#fff3a0,#ffd34f,#fff7d6);
  color:#6b4600;
  border:2px solid rgba(255,255,255,.7);
  border-radius:999px;
  padding:14px 24px;
  box-shadow:0 16px 36px rgba(0,0,0,.18);
  font-weight:900;
  letter-spacing:.08em;
  opacity:0;
  animation:superLuckyPop 2.4s ease forwards;
}
@keyframes superLuckyPop{
  0%{ opacity:0; transform:translateX(-50%) translateY(-12px) scale(.84); }
  15%{ opacity:1; transform:translateX(-50%) translateY(0) scale(1.04); }
  80%{ opacity:1; transform:translateX(-50%) translateY(0) scale(1); }
  100%{ opacity:0; transform:translateX(-50%) translateY(-8px) scale(.95); }
}

.stamp{
  display:inline-block;
  margin-top:8px;
  padding:4px 10px;
  border-radius:999px;
  font-size:11px;
  font-weight:900;
  letter-spacing:.03em;
  background:rgba(255,255,255,.18);
  color:#fff;
}

/* 神ダケ降臨モーダル */
.kamiModal{
  position:fixed;
  inset:0;
  z-index:11000;
  display:flex;
  align-items:center;
  justify-content:center;
  background:radial-gradient(circle, rgba(255,255,255,.08), rgba(0,0,0,.72));
  backdrop-filter:blur(6px);
  animation:kamiFade .35s ease;
}
@keyframes kamiFade{
  from{ opacity:0; }
  to{ opacity:1; }
}
.kamiModal__card{
  width:min(92vw, 560px);
  border-radius:28px;
  padding:20px 18px 22px;
  text-align:center;
  background:linear-gradient(180deg, rgba(255,255,255,.16), rgba(255,245,200,.10));
  border:1px solid rgba(255,255,255,.24);
  box-shadow:0 25px 60px rgba(0,0,0,.35);
  animation:kamiPop .45s ease;
}
@keyframes kamiPop{
  0%{ transform:scale(.88) translateY(16px); opacity:0; }
  100%{ transform:scale(1) translateY(0); opacity:1; }
}
.kamiModal__title{
  font-size:28px;
  font-weight:900;
  color:#fff3be;
  margin-bottom:8px;
  text-shadow:0 0 10px rgba(255,240,170,.45);
}
.kamiModal__sub{
  font-size:13px;
  opacity:.95;
  margin-bottom:14px;
}
.kamiModal__img{
  width:min(280px, 56vw);
  max-width:100%;
  display:block;
  margin:0 auto 12px;
  filter:drop-shadow(0 0 22px rgba(255,245,180,.28));
}
.kamiModal__text{
  font-size:14px;
  line-height:1.9;
  margin-bottom:14px;
}
.kamiModal__btn{
  border:none;
  border-radius:999px;
  padding:12px 22px;
  background:linear-gradient(135deg,#fff3a0,#ffd34f,#fff7d6);
  color:#5f4300;
  font-weight:900;
  cursor:pointer;
}

/* flash */
.kami-flash{
  animation:kamiFlash .9s ease;
}
@keyframes kamiFlash{
  0%{ filter:brightness(1); }
  20%{ filter:brightness(1.8); }
  100%{ filter:brightness(1); }
}

@media (max-width: 900px){
  .layout{ grid-template-columns:1fr; }
  .chatCard{ min-height:620px; }
}
@media (max-width: 640px){
  .wrap{ padding:14px 12px 28px; }
  .chatHead{ align-items:flex-start; flex-direction:column; }
  .msgAvatar{ width:48px; height:48px; flex-basis:48px; }
  .msgMain{ max-width:calc(100% - 58px); }
  .msgRow.user .msgMain{ max-width:84%; }
  .bubble{ font-size:14px; }
  .inputWrap{ padding:12px 2px 2px; }
  .sendBtn{ min-width:78px; }
  .kamiBgmPanel.show{
    display:block;
  }
  .kamiBgmBtns{
    margin-top:10px;
  }
}
/* 神託フラッシュ */

.kamiFlash{
  animation:kamiFlashAnim .8s ease;
}

@keyframes kamiFlashAnim{

  0%{
    box-shadow:
      0 0 0px rgba(255,255,255,0),
      0 0 0px rgba(255,215,120,0);
  }

  40%{
    box-shadow:
      0 0 80px rgba(255,255,255,.9),
      0 0 160px rgba(255,215,120,.8);
    transform:scale(1.04);
  }

  100%{
    box-shadow:
      0 0 20px rgba(255,255,255,.4),
      0 0 60px rgba(255,215,120,.5);
    transform:scale(1);
  }
}

/* モーダルフェード */

.kamiFadeOut{
  animation:kamiFadeOutAnim .7s ease forwards;
}

@keyframes kamiFadeOutAnim{
  to{
    opacity:0;
    transform:scale(.9);
  }
}
/* 陰陽覚醒 */

body.yinYangMode{
  animation:yinYangFlash 0.6s ease;
}

@keyframes yinYangFlash{

  0%{
    filter:invert(0) hue-rotate(0deg) brightness(1);
  }

  40%{
    filter:invert(1) hue-rotate(180deg) brightness(1.3);
  }

  70%{
    filter:invert(.6) hue-rotate(90deg) brightness(1.1);
  }

  100%{
    filter:invert(0) hue-rotate(0deg) brightness(1);
  }

}
/* SSR召喚 */

.kamiSummon{
  position:fixed;
  inset:0;
  background:radial-gradient(circle at center,#fff3c0 0%,#000000 70%);
  display:flex;
  align-items:center;
  justify-content:center;
  z-index:99999;
  animation:kamiSummonFade 1.4s ease forwards;
}

@keyframes kamiSummonFade{

  0%{
    opacity:0;
    transform:scale(1.2);
  }

  30%{
    opacity:1;
  }

  100%{
    opacity:0;
    transform:scale(1);
  }

}

/* 光の柱 */

.kamiBeam{
  width:200px;
  height:120vh;
  background:linear-gradient(
    180deg,
    rgba(255,255,255,0),
    rgba(255,255,200,.9),
    rgba(255,255,255,0)
  );
  filter:blur(10px);
  animation:kamiBeamAnim 1.2s ease;
}

@keyframes kamiBeamAnim{

  0%{
    transform:translateY(-120%);
  }

  60%{
    transform:translateY(0);
  }

  100%{
    transform:translateY(120%);
  }

}
.kamiBgmPanel{

  position:relative; /* ヘッダ内で横並びに表示するため相対配置 */
  left:auto;
  bottom:auto;
  transform:none;

  background:rgba(0,0,0,.65);
  backdrop-filter:blur(6px);

  border-radius:12px;
  padding:8px 10px;

  display:flex;
  gap:8px;
  align-items:center;

  border:1px solid rgba(255,255,255,.14);

  z-index:1001;

  opacity:0;
  pointer-events:none;
  transition:.18s;
}

.kamiBgmPanel.show{
  opacity:1;
  pointer-events:auto;
}

@media (max-width: 900px){
  /* モバイルではヘッダが縦並びになるため、BGMはヘッダ下に表示 */
  .chatHead{ flex-direction:column; align-items:flex-start; }
  .chatHead > div[style]{ width:100%; display:flex; justify-content:space-between; align-items:center; }
  .kamiBgmPanel{ position:static; margin-top:8px; }
}

.kamiBgmTitle{
  font-weight:900;
}

.kamiBgmSub{
  font-size:11px;
  opacity:.7;
}

.kamiBgmBtns{
  display:flex;
  gap:6px;
}

.kamiBgmBtn{
  border:none;
  padding:8px 12px;
  border-radius:10px;
  font-weight:800;
  cursor:pointer;
}

.kamiBgmBtn.stop{
  background:#ff7a9e;
}
</style>
</head>
<body class="theme-omotedake">

<div class="wrap">
  <div class="header">
    <a href="index.html">
      <img src="images/header-main.png" alt="オモテダケ占い">
    </a>
    <div class="lead">5人＋神ダケに相談できるダケ🍄</div>
  </div>

  <div class="layout">
    <aside class="sideCard">
      <img id="omoteImage" class="omoteImg" src="images/omotedake-main.png" alt="キャラクター">
      <div class="sideTitle" id="sideTitle">オモテダケ相談室</div>
      <div class="sideText" id="sideText">
        今日は何を相談するダケ？<br>
        内容に合わせて、ぴったりのキャラが答えるよ。
      </div>

      <div class="quickBtns">
        <button type="button" class="mainTopic" data-q="今日の運勢を教えて" data-character="today">
          <span class="btnTitle">🔮 今日の運勢</span>
          <span class="btnMeta">5人＋1%神ダケ</span>
        </button>

        <button type="button" data-q="恋愛運をみて" data-character="anatadake">
          <span class="btnTitle">💖 恋愛運</span>
          <span class="btnMeta">アナタダケ</span>
        </button>

        <button type="button" data-q="仕事運をみて" data-character="osudake">
          <span class="btnTitle">📈 仕事運</span>
          <span class="btnMeta">オスダケ</span>
        </button>

        <button type="button" data-q="金運をみて" data-character="kanedake">
          <span class="btnTitle">💰 金運</span>
          <span class="btnMeta">カネダケ</span>
        </button>

        <button type="button" data-q="最近の人間関係をみて" data-character="yakezake">
          <span class="btnTitle">🫧 人間関係</span>
          <span class="btnMeta">ヤケザケ</span>
        </button>
      </div>

      <div class="kamiBook">
        <div class="kamiBook__title">🌟 神ダケ図鑑</div>
        <div class="kamiBook__count" id="kamiCount">0</div>
        <div class="kamiBook__sub">神ダケに出会った回数</div>
      </div>

      <a class="backLink" href="fortune.php">← ワンタップ占いに戻る</a>
    </aside>

    <section class="chatCard">
      <div class="chatHead">
        <div>
          <div class="chatTitle">🍄 AI占い相談室</div>
          <div class="chatSub">神UI version3 + 神ダケBGM</div>
        </div>
        <div style="display:flex;align-items:center;gap:10px;">
          <div class="activeCharacter" id="activeCharacter">案内役：オモテダケ</div>

          <div id="kamiBgmPanel" class="kamiBgmPanel">
            <div class="kamiBgmInfo">
              <div class="kamiBgmTitle">🌟 神ダケ専用BGM</div>
              <div class="kamiBgmSub">神ダケ出現中だけ再生できるダケ</div>
            </div>
            <div class="kamiBgmBtns">
              <button id="kamiBgmPlayBtn" class="kamiBgmBtn" type="button">▶ 再生</button>
              <button id="kamiBgmPauseBtn" class="kamiBgmBtn stop" type="button">⏸ 停止</button>
            </div>
          </div>
        </div>
      </div>

      <div class="messages" id="chatMessages"></div>

      <div id="fortuneCard" class="fortuneCard card-omote">
        <div class="fortuneTitle">🍄 オモテダケ占いカード</div>
        気になることを相談すると、ここに占いカードが出るダケ。
      </div>


      <div class="inputWrap">
        <input id="chatInput" type="text" placeholder="たとえば『今日の恋愛運どう？』">
        <button id="sendBtn" class="sendBtn" type="button">送信</button>
      </div>
    </section>
  </div>
</div>

<script>
const messagesEl = document.getElementById("chatMessages");
const inputEl = document.getElementById("chatInput");
const sendBtn = document.getElementById("sendBtn");
const omoteImage = document.getElementById("omoteImage");
const activeCharacterEl = document.getElementById("activeCharacter");
const sideTitleEl = document.getElementById("sideTitle");
const sideTextEl = document.getElementById("sideText");
const fortuneCardEl = document.getElementById("fortuneCard");
const kamiCountEl = document.getElementById("kamiCount");
const kamiBgmPanelEl = document.getElementById("kamiBgmPanel");
const kamiBgmPlayBtn = document.getElementById("kamiBgmPlayBtn");
const kamiBgmPauseBtn = document.getElementById("kamiBgmPauseBtn");

const KAMI_BGM_SRC = "audio/shingeki.mp3";

let currentTalkCharacter = "omotedake";
let kamiBgmAudio = null;
// 神ダケの演出を一度だけ実行するフラグ（降臨時のみ演出）
let kamiSummonedShown = false;

const characterMap = {
  omotedake: {
    name: "オモテダケ",
    image: "images/omotedake-main.png",
    label: "案内役：オモテダケ",
    title: "オモテダケ相談室",
    text: "今日は何を相談するダケ？<br>内容に合わせて、ぴったりのキャラが答えるよ。",
    titleCard: "🍄 オモテダケ占いカード",
    cardClass: "card-omote",
    bodyTheme: "theme-omotedake",
    stamp: "🔮 総合運"
  },
  anatadake: {
    name: "アナタダケ",
    image: "images/anatadake-main.png",
    label: "恋愛運：アナタダケ",
    title: "アナタダケ相談室",
    text: "アナタの気持ちや恋の流れを、やさしく見つめて占うよ…💖",
    titleCard: "💖 アナタダケ恋愛カード",
    cardClass: "card-anata",
    bodyTheme: "theme-anatadake",
    stamp: "💖 恋愛運"
  },
  osudake: {
    name: "オスダケ",
    image: "images/osudake-main.png",
    label: "仕事運：オスダケ",
    title: "オスダケ相談室",
    text: "仕事や現実の悩みは任せな。落ち着いて道を整えてやる。",
    titleCard: "📈 オスダケ仕事カード",
    cardClass: "card-osu",
    bodyTheme: "theme-osudake",
    stamp: "📈 仕事運"
  },
  kanedake: {
    name: "カネダケ",
    image: "images/kanedake-main.png",
    label: "金運：カネダケ",
    title: "カネダケ相談室",
    text: "お金の流れ、チャンス、タイミング。きらっと見つけるダケ💰",
    titleCard: "💰 カネダケ金運カード",
    cardClass: "card-kanedake",
    bodyTheme: "theme-kanedake",
    stamp: "💰 金運"
  },
  yakezake: {
    name: "ヤケザケ",
    image: "images/yakezake-main.png",
    label: "人間関係：ヤケザケ",
    title: "ヤケザケ相談室",
    text: "表では見えない空気や本音もあるわ。濁りはあたしが見る。",
    titleCard: "🫧 ヤケザケ人間関係カード",
    cardClass: "card-yake",
    bodyTheme: "theme-yakezake",
    stamp: "🫧 人間関係"
  },
  kamidake: {
    name: "神ダケ",
    image: "images/kamidake-main.png",
    label: "神託：神ダケ",
    title: "神ダケ降臨",
    text: "光と影、そのどちらも運命の一部ダケ。<br>吉も凶も、道を照らすためにあるダケ。",
    titleCard: "🌟 神ダケ神託カード",
    cardClass: "card-kami",
    bodyTheme: "theme-kamidake",
    stamp: "🌟 神託"
  },
  uradake: {
    name: "ウラダケ",
    image: "images/uradake-main.png",
    label: "裏運：ウラダケ",
    title: "ウラダケ相談室",
    text: "表では見えない流れや本音を、静かに映し出すダケ。",
    titleCard: "🌙 ウラダケ裏運カード",
    cardClass: "card-ura",
    bodyTheme: "theme-uradake",
    stamp: "🌙 裏運"
  },
  today: {
    name: "今日の運勢",
    image: "images/omotedake-main.png",
    label: "今日の運勢：ランダム",
    title: "今日の運勢相談室",
    text: "今日は5人のうち誰が答えるかな？ 1%で神ダケも降臨するよ。",
    titleCard: "🔮 今日の運勢カード",
    cardClass: "card-omote",
    bodyTheme: "theme-omotedake",
    stamp: "🔮 今日の運勢"
  }
};

function esc(text){
  const div = document.createElement("div");
  div.textContent = text;
  return div.innerHTML;
}

function nl2brSafe(text){
  return esc(text).replace(/\n/g,"<br>");
}

function scrollBottom(){
  messagesEl.scrollTop = messagesEl.scrollHeight;
}

function pick(arr){
  return arr[Math.floor(Math.random() * arr.length)];
}

function pulseCharacterImage(){
  omoteImage.classList.remove("pop");
  void omoteImage.offsetWidth;
  omoteImage.classList.add("pop");
}

function setBodyTheme(character){
  const data = characterMap[character] || characterMap.omotedake;
  document.body.classList.remove(
    "theme-omotedake",
    "theme-anatadake",
    "theme-osudake",
    "theme-kanedake",
    "theme-yakezake",
    "theme-kamidake"
  );
  document.body.classList.add(data.bodyTheme || "theme-omotedake");
}

function setCharacterUI(character){
  const current = characterMap[character] || characterMap.omotedake;
  omoteImage.src = current.image;
  omoteImage.alt = current.name;
  activeCharacterEl.textContent = current.label;
  sideTitleEl.textContent = current.title;
  sideTextEl.innerHTML = current.text;
  setBodyTheme(character);
  pulseCharacterImage();

  // 別キャラに切り替わったら神ダケ演出フラグをリセット
  if(character !== 'kamidake'){
    kamiSummonedShown = false;
  }
}

function addUserMessage(text){
  const row = document.createElement("div");
  row.className = "msgRow user";

  const main = document.createElement("div");
  main.className = "msgMain";

  const bubble = document.createElement("div");
  bubble.className = "bubble";
  bubble.innerHTML = nl2brSafe(text);

  main.appendChild(bubble);
  row.appendChild(main);
  messagesEl.appendChild(row);
  scrollBottom();
}

function addCharacterMessage(text, character="omotedake"){
  const charData = characterMap[character] || characterMap.omotedake;

  const row = document.createElement("div");
  row.className = "msgRow character " + character;
  row.classList.add("character-enter");

  const avatar = document.createElement("div");
  avatar.className = "msgAvatar";
  avatar.innerHTML = `<img src="${charData.image}" alt="${charData.name}">`;

  const main = document.createElement("div");
  main.className = "msgMain";

  const name = document.createElement("div");
  name.className = "msgName";
  name.textContent = charData.name;

  const bubble = document.createElement("div");
  bubble.className = "bubble";
  bubble.innerHTML = nl2brSafe(text) + `<div class="stamp">${charData.stamp || "🔮 占い"}</div>`;

  main.appendChild(name);
  main.appendChild(bubble);

  row.appendChild(avatar);
  row.appendChild(main);
  messagesEl.appendChild(row);
  scrollBottom();
}

function addTyping(){
  const row = document.createElement("div");
  row.className = "msgRow character " + (currentTalkCharacter || "omotedake");
  row.id = "typingRow";

  const avatar = document.createElement("div");
  avatar.className = "msgAvatar";
  avatar.innerHTML = `<img src="${characterMap[currentTalkCharacter]?.image || characterMap.omotedake.image}" alt="占い中">`;

  const main = document.createElement("div");
  main.className = "msgMain";

  const name = document.createElement("div");
  name.className = "msgName";
  name.textContent = "占い中…";

  const bubble = document.createElement("div");
  bubble.className = "bubble";
  bubble.innerHTML = '<span class="typing"><span></span><span></span><span></span></span>';

  main.appendChild(name);
  main.appendChild(bubble);
  row.appendChild(avatar);
  row.appendChild(main);
  messagesEl.appendChild(row);
  scrollBottom();
}

function removeTyping(){
  const el = document.getElementById("typingRow");
  if(el) el.remove();
}

function ensureFxLayer(){
  let layer = document.getElementById("fxLayer");
  if(!layer){
    layer = document.createElement("div");
    layer.id = "fxLayer";
    layer.className = "fxLayer";
    document.body.appendChild(layer);
  }
  return layer;
}

function randomBetween(min, max){
  return Math.random() * (max - min) + min;
}

function spawnEffect(type, count = 18){
  const layer = ensureFxLayer();

  for(let i=0; i<count; i++){
    const el = document.createElement("div");
    el.className = type;

    const left = randomBetween(0, 100);
    const dur  = randomBetween(2.8, 5.2);
    const delay= randomBetween(0, 0.9);
    const size = randomBetween(16, 34);

    el.style.left = left + "vw";
    el.style.top = "-40px";
    el.style.animationDuration = dur + "s";
    el.style.animationDelay = delay + "s";

    if(type === "fxStar"){
      el.textContent = Math.random() < 0.5 ? "✦" : "★";
      el.style.fontSize = size + "px";
    }else if(type === "fxHeart"){
      el.textContent = Math.random() < 0.5 ? "❤" : "♥";
      el.style.fontSize = size + "px";
    }else if(type === "fxCoin"){
      el.textContent = Math.random() < 0.5 ? "¥" : "●";
      el.style.fontSize = size + "px";
    }else if(type === "fxMist"){
      el.style.width = randomBetween(80, 160) + "px";
      el.style.height = el.style.width;
      el.style.left = randomBetween(-5, 95) + "vw";
      el.style.top = randomBetween(40, 95) + "vh";
      el.style.animationDuration = randomBetween(2.6, 4.8) + "s";
    }

    layer.appendChild(el);
    setTimeout(()=> el.remove(), (dur + delay + 0.4) * 1000);
  }
}

function starRain(count = 24){ spawnEffect("fxStar", count); }
function heartRain(count = 20){ spawnEffect("fxHeart", count); }
function coinRain(count = 22){ spawnEffect("fxCoin", count); }
function mistEffect(count = 14){ spawnEffect("fxMist", count); }

function showSuperLuckyBanner(text = "🌟 超大吉 🌟"){
  const banner = document.createElement("div");
  banner.className = "superLuckyBanner";
  banner.textContent = text;
  document.body.appendChild(banner);
  setTimeout(()=> banner.remove(), 2500);
}

function incrementKamiCount(){
  const key = "omotedake_kamidake_count";
  const current = parseInt(localStorage.getItem(key) || "0", 10) || 0;
  const next = current + 1;
  localStorage.setItem(key, String(next));
  updateKamiCount();
}

function updateKamiCount(){
  const key = "omotedake_kamidake_count";
  const count = parseInt(localStorage.getItem(key) || "0", 10) || 0;
  kamiCountEl.textContent = count;
}

function ensureKamiAudio(){
  if(!kamiBgmAudio){
    kamiBgmAudio = new Audio(KAMI_BGM_SRC);
    kamiBgmAudio.loop = true;
    kamiBgmAudio.preload = "auto";
  }
  return kamiBgmAudio;
}

// ユーザー操作（送信ボタンなど）の文脈で一度だけ再生してすぐ停止することで
// ブラウザの自動再生制限を解除し、後で音を鳴らせるようにするトリック。
function prepareKamiAudioUnlock(){
  try{
    const audio = ensureKamiAudio();
    // 再生してすぐ停止する（同一のユーザー操作内で行うことが重要）
    const p = audio.play();
    if(p && typeof p.then === "function"){
      p.then(()=>{ audio.pause(); audio.currentTime = 0; }).catch(()=>{/* 再生失敗は無視 */});
    }
  }catch(e){
    // 特に何もしない
  }
}

function showKamiBgmPanel(show){
  if(show){
    kamiBgmPanelEl.classList.add("show");
  }else{
    kamiBgmPanelEl.classList.remove("show");
    stopKamiBgm();
  }
}

async function playKamiBgm(){
  try{
    const audio = ensureKamiAudio();
    await audio.play();
    kamiBgmPlayBtn.textContent = "🔊 再生中";
  }catch(e){
    alert("BGMを再生できなかったダケ。ファイル名や配置を確認してほしいダケ。");
  }
}

function stopKamiBgm(){
  if(kamiBgmAudio){
    kamiBgmAudio.pause();
    kamiBgmAudio.currentTime = 0;
  }
  kamiBgmPlayBtn.textContent = "▶ 再生";
}

function showKamiModal(){

  const modal = document.createElement("div");
  modal.className = "kamiModal";

  modal.innerHTML = `
    <div class="kamiModal__card">
      <div class="kamiModal__title">🌟 神ダケ降臨 🌟</div>
      <div class="kamiModal__sub">終極神託が現れたダケ</div>

      <img class="kamiModal__img"
           src="images/kamidake-main.png">

      <div class="kamiModal__text">
        光の中にも影があり、影の中にも光があるダケ。<br>
        今日は運命の奥が少しだけ見える特別な日ダケ。
      </div>

      <button class="kamiModal__btn">神託を受け取る</button>
    </div>
  `;

  document.body.appendChild(modal);

  const card = modal.querySelector(".kamiModal__card");

  modal.querySelector(".kamiModal__btn").addEventListener("click", async ()=>{

    /* カード発光 */

    card.classList.add("kamiFlash");

    /* 画面フラッシュ */

    document.body.style.filter="brightness(1.5)";
    setTimeout(()=>{
      document.body.style.filter="";
    },300);

    /* スマホ振動（Android） */

    if("vibrate" in navigator){
      navigator.vibrate([100,50,150,50,250]);
    }

    /* 星大量 */
    showSuperLuckyBanner("🌟 神ダケ神託 🌟");
    starRain(40);

    /* BGM開始 */

    await playKamiBgm();

    /* 少し待ってから消す */

    setTimeout(()=>{

      modal.classList.add("kamiFadeOut");

      setTimeout(()=>{
        modal.remove();
      },600);

    },600);

  });

}

function flashKami(){
  document.body.classList.remove("kami-flash");
  void document.body.offsetWidth;
  document.body.classList.add("kami-flash");
}

function playCharacterEffect(character, isSuperLucky = false){

  if(character === "kamidake"){

    // 既に神ダケ降臨の演出を行っていれば、再度演出は行わない
    if(kamiSummonedShown){
      // BGMパネルは表示しておく
      showKamiBgmPanel(true);
      return;
    }

    kamiSummonedShown = true;

    /* SSR召喚 */

    kamiSummonEffect();

    /* 陰陽覚醒 */

    yinYangAwaken();

    /* 光フラッシュ */

    flashKami();

    /* スマホ振動 */

    if(typeof vibrateKami === 'function'){
      try{ vibrateKami([120,60,180,60,260]); }catch(e){}
    }else{
      if("vibrate" in navigator){ navigator.vibrate([120,60,180,60,260]); }
    }

    /* 神降臨 */

    showSuperLuckyBanner("🌟 神ダケ降臨 🌟");

    starRain(46);

    setTimeout(()=>{
      starRain(24);
    },260);

    showKamiModal();

    incrementKamiCount();

    showKamiBgmPanel(true);

    return;
  }

  // 別キャラなら神ダケフラグをリセットしてBGMパネルを閉じる
  kamiSummonedShown = false;
  showKamiBgmPanel(false);

  if(isSuperLucky){
    showSuperLuckyBanner("🌟 超大吉 🌟");
    starRain(30);
  }

  if(character === "anatadake"){
    heartRain(isSuperLucky ? 28 : 18);
  }else if(character === "kanedake"){
    coinRain(isSuperLucky ? 28 : 20);
  }else if(character === "yakezake"){
    mistEffect(isSuperLucky ? 20 : 12);
  }else{
    starRain(isSuperLucky ? 26 : 16);
  }

}

function generateFortuneCard(character){
  const starMap = {
    omotedake:["★★★☆☆","★★★★☆","★★★★★"],
    anatadake:["★★★☆☆","★★★★☆","★★★★★"],
    osudake:["★★☆☆☆","★★★☆☆","★★★★☆","★★★★★"],
    kanedake:["★★★☆☆","★★★★☆","★★★★★"],
    yakezake:["★★☆☆☆","★★★☆☆","★★★★☆"],
    kamidake:["∞∞∞∞∞"]
  };

  const colorMap = {
    omotedake:["ラベンダー","ミルキーホワイト","ゴールド"],
    anatadake:["ピンク","ローズ","ラベンダー"],
    osudake:["ネイビー","ワインレッド","ブラウン"],
    kanedake:["ゴールド","パールホワイト","アクアブルー"],
    yakezake:["パープル","ボルドー","スモーキーピンク"],
    kamidake:["白金","陰陽の黒白","星月の紫金"]
  };

  const itemMap = {
    omotedake:["星のチャーム","小さな鏡","月モチーフアクセ"],
    anatadake:["ハートチャーム","香りハンカチ","リボンアクセ"],
    osudake:["黒いペン","革小物","腕時計"],
    kanedake:["金色の小物","財布","きらめくアクセ"],
    yakezake:["香水","深色のノート","小さなキャンドル"],
    kamidake:["光を反射するもの","白黒の小物","月と星のモチーフ"]
  };

  const timeMap = {
    omotedake:["23:50","02:30","23:00"],
    anatadake:["16:00","17:00","21:00"],
    osudake:["19:00","22:00","21:00"],
    kanedake:["21:00","20:00","01:00"],
    yakezake:["17:00","22:00","23:00"],
    kamidake:["00:00","06:06","22:22"]
  };

  const superMessageMap = {
    omotedake:"今日は星の導きが強い日ダケ。迷ったら直感を信じると道がひらくダケ。",
    anatadake:"恋の空気がふわっと味方しているよ。素直な気持ちが奇跡を呼ぶかも…💖",
    osudake:"流れは悪くない。腹を決めて一歩出れば、ちゃんと前に進める日だ。",
    kanedake:"今日はお金の巡りがかなり良いダケ。小さな行動が大きな得につながる日ダケ。",
    yakezake:"濁っていた空気が少し晴れる日。距離を置く判断も、今日は運になるよ。"
  };

  const data = characterMap[character] || characterMap.omotedake;
  const star = pick(starMap[character] || starMap.omotedake);
  const color = pick(colorMap[character] || colorMap.omotedake);
  const item = pick(itemMap[character] || itemMap.omotedake);
  const time = pick(timeMap[character] || timeMap.omotedake);

  if(character === "kamidake"){
    fortuneCardEl.className = "fortuneCard " + data.cardClass;
    fortuneCardEl.innerHTML = `
      <div class="fortuneTitle">🌟 神ダケ神託 🌟</div>
      光と影は対立ではなく、巡りの形ダケ。<br>
      今の迷いも、次の答えへ向かう途中ダケ。<br>
      🎨 神聖カラー ${color}<br>
      🍀 神託アイテム ${item}<br>
      ⏰ 神域タイム ${time}
    `;
    playCharacterEffect(character, true);
    return;
  }

  const isSuperLucky = Math.random() < 0.05;
  fortuneCardEl.className = "fortuneCard " + (data.cardClass || "card-omote");

  if(isSuperLucky){
    fortuneCardEl.innerHTML = `
      <div class="fortuneTitle">🌟 超大吉 🌟</div>
      ${superMessageMap[character] || superMessageMap.omotedake}<br>
      🎨 特別カラー ${color}<br>
      🍀 特別アイテム ${item}<br>
      ⏰ チャンスタイム ${time}
    `;
  }else{
    fortuneCardEl.innerHTML = `
      <div class="fortuneTitle">${data.titleCard}</div>
      ⭐ 運勢 ${star}<br>
      🎨 ラッキーカラー ${color}<br>
      🍀 ラッキーアイテム ${item}<br>
      ⏰ 運気アップ時間 ${time}
    `;
  }

  playCharacterEffect(character, isSuperLucky);
}

async function sendMessage(prefill="", forcedCharacter=""){
  const text = (prefill || inputEl.value).trim();
  if(!text) return;

  // ユーザー操作の文脈で事前にオーディオ再生を試み、自動再生ブロックを解除する
  // これにより、神ダケ降臨時に後続の audio.play() が成功しやすくなる
  try{ prepareKamiAudioUnlock(); }catch(e){}

  addUserMessage(text);
  inputEl.value = "";


  let sendCharacter = "";
  if(forcedCharacter){
    sendCharacter = forcedCharacter;
  }else{
    sendCharacter = currentTalkCharacter || "omotedake";
  }

  addTyping();
  sendBtn.disabled = true;
  inputEl.disabled = true;

  try{
    const res = await fetch("omote_ai.php",{
      method:"POST",
      headers:{
        "Content-Type":"application/x-www-form-urlencoded"
      },
      body:
        "message=" + encodeURIComponent(text) +
        "&character=" + encodeURIComponent(sendCharacter)
    });

    const data = await res.json();
    removeTyping();

    const replyCharacter = data?.character || "omotedake";
    const content = data?.reply || "星の流れが乱れているダケ…もう一度話してほしいダケ";

    currentTalkCharacter = replyCharacter;
    setCharacterUI(replyCharacter);
    addCharacterMessage(content, replyCharacter);
    generateFortuneCard(replyCharacter);

  }catch(e){
    removeTyping();
    setCharacterUI(currentTalkCharacter || "omotedake");
    addCharacterMessage("今日は占いがうまく届かないダケ…また来てほしいダケ", currentTalkCharacter || "omotedake");
    generateFortuneCard(currentTalkCharacter || "omotedake");
  }finally{
    sendBtn.disabled = false;
    inputEl.disabled = false;
    inputEl.focus();
  }
}

kamiBgmPlayBtn.addEventListener("click", playKamiBgm);
kamiBgmPauseBtn.addEventListener("click", stopKamiBgm);

sendBtn.addEventListener("click", ()=> sendMessage());

inputEl.addEventListener("keydown", (e)=>{
  if(e.key === "Enter"){
    sendMessage();
  }
});

document.querySelectorAll(".quickBtns button").forEach(btn=>{
  btn.addEventListener("click", ()=>{
    const q = btn.dataset.q || "";
    const c = btn.dataset.character || "";
    sendMessage(q, c);
  });
});

updateKamiCount();
currentTalkCharacter = "omotedake";
setCharacterUI("omotedake");
addCharacterMessage(
  "こんにちはダケ！\n今日の運勢、恋愛、仕事、お金、人間関係…\n気になることを相談してほしいダケ🔮",
  "omotedake"
);
generateFortuneCard("omotedake");
showKamiBgmPanel(false);

function yinYangAwaken(){

  document.body.classList.add("yinYangMode");

  setTimeout(()=>{
    document.body.classList.remove("yinYangMode");
  },600);

}
function kamiSummonEffect(){

  const summon = document.createElement("div");
  summon.className="kamiSummon";

  summon.innerHTML=`
    <div class="kamiBeam"></div>
  `;

  document.body.appendChild(summon);

  setTimeout(()=>{
    summon.remove();
  },1200);

}
</script>

</body>
</html>