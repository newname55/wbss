<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="UTF-8">
<title>オモテダケ相談室</title>

<style>

:root{
--bg1:#fff7fb;
--bg2:#ffe8f6;
--txt:#444;
--accent:#ff8fcf;
}

body{
  min-height:100vh;
  font-family:"Hiragino Maru Gothic ProN","Yu Gothic","Meiryo",sans-serif;
  color:var(--txt);

  background:
    radial-gradient(circle at 10% 20%, rgba(255,255,255,.5) 0 2px, transparent 3px),
    radial-gradient(circle at 80% 30%, rgba(255,255,255,.35) 0 2px, transparent 3px),
    radial-gradient(circle at 30% 70%, rgba(255,255,255,.25) 0 2px, transparent 3px),
    linear-gradient(180deg,#fff7fb 0%,#ffe8f6 100%);

  background-size:200px 200px,180px 180px,220px 220px,cover;
}

header{
padding:20px;
}

header img{
width:500px;
  width:500px;
  margin:20px auto;
  display:block;
}

.chatbox{
max-width:520px;
margin:0 auto;
background:white;
border-radius:20px;
padding:20px;
box-shadow:0 10px 30px rgba(0,0,0,0.1);
}

.messages{
height:300px;
overflow-y:auto;
text-align:left;
padding:10px;
}

.msg{
margin:10px 0;
}

.omote{
background:#fff4fa;
padding:10px 14px;
border-radius:14px;
display:inline-block;
}

.user{
text-align:right;
}

.inputArea{
display:flex;
gap:10px;
margin-top:15px;
}

input{
flex:1;
padding:10px;
border-radius:12px;
border:1px solid #ddd;
}

button{
background:var(--accent);
color:white;
border:none;
padding:10px 16px;
border-radius:12px;
cursor:pointer;
}

.omoteImg{
width:120px;
margin:10px auto;
display:block;
}
.messages{
  height:320px;
  overflow-y:auto;
  padding:10px;
}

.msg{
  margin:12px 0;
}

.omote{
  background:#fff0f8;
  padding:10px 16px;
  border-radius:16px;
  display:inline-block;
  box-shadow:0 4px 10px rgba(0,0,0,0.08);
}

.user{
  text-align:right;
  color:#666;
}
.omoteFloat{
  width:500px;
  margin:20px auto;
  display:block;
  animation:float 4s ease-in-out infinite;
}

@keyframes float{
  0%{transform:translateY(0)}
  50%{transform:translateY(-10px)}
  100%{transform:translateY(0)}
}
</style>
</head>

<body>

<header>
<a href="index.html">
<img img src="images/header-main.png">
</a>
</header>
<img id="omoteImage" class="omoteFloat" src="images/omotedake-thinking.png">

<div class="chatbox">

<div class="messages" id="chat"></div>

<div class="inputArea">
<input id="userInput" placeholder="オモテダケに相談するダケ…">
<button onclick="sendMessage()">送信</button>
</div>

</div>

<script>

const chat = document.getElementById("chat");
const input = document.getElementById("userInput");
const image = document.getElementById("omoteImage");

function addMessage(text,type){

const div = document.createElement("div");
div.className="msg "+type;

if(type==="omote"){
div.innerHTML='<span class="omote">'+text+'</span>';
}else{
div.textContent=text;
}

chat.appendChild(div);
chat.scrollTop=chat.scrollHeight;

}

function sendMessage(){

const text=input.value.trim();
if(!text)return;

addMessage(text,"user");
input.value="";

setTimeout(()=>{
reply(text);
},500);

}

function reply(text){

let res="";

if(text.includes("恋")||text.includes("好き")){
image.src="images/omotedake-love.png";
res=pick([
"恋の風が吹いてるダケ💖",
"今日は優しく話すといいダケ",
"恋はゆっくり育てるダケ"
]);
}

else if(text.includes("仕事")){
image.src="images/omotedake-thinking.png";
res=pick([
"焦らず進めば大丈夫ダケ📈",
"段取りが運気を呼ぶダケ",
"今日は準備の日ダケ"
]);
}

else if(text.includes("お金")||text.includes("金運")){
image.src="images/omotedake-happy.png";
res=pick([
"小さなチャンスがあるダケ💰",
"節約が幸運を呼ぶダケ",
"今日は無駄遣い注意ダケ"
]);
}

else{
image.src="./images/omotadake-main.png";
res=pick([
"今日は穏やかな運勢ダケ🍄",
"ゆっくり過ごすといいダケ",
"いい流れが来てるダケ"
]);
}

addMessage(res,"omote");

}

function pick(arr){
return arr[Math.floor(Math.random()*arr.length)];
}
function sendMessage(){

const text=input.value.trim();
if(!text)return;

addMessage(text,"user");
input.value="";

addMessage("オモテダケが占っているダケ…","omote");

setTimeout(()=>{
reply(text);
},1200);

}
function sparkle(){

const star=document.createElement("div");

star.style.position="fixed";
star.style.left=Math.random()*window.innerWidth+"px";
star.style.top="-10px";
star.style.fontSize="20px";
star.style.color="#ffd6f6";
star.innerHTML="✨";

document.body.appendChild(star);

let y=0;

const fall=setInterval(()=>{

y+=4;
star.style.top=y+"px";

if(y>window.innerHeight){
star.remove();
clearInterval(fall);
}

},16);

}

</script>

</body>
</html>