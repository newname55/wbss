<?php
declare(strict_types=1);

// 招待トークン
$invite = (string)($_GET['invite'] ?? '');
if ($invite === '') {
  http_response_code(400);
  exit('invalid invite');
}

// QR生成URL
function qr_url(string $text, int $size = 320): string {
  return 'https://api.qrserver.com/v1/create-qr-code/?'
       . http_build_query([
           'size' => $size . 'x' . $size,
           'data' => $text,
         ]);
}

// QRに埋め込むURL（LINEログイン開始）
$inviteUrl =
  'https://ss5456ds1fds2f1dsf.asuscomm.com'
  . '/seika-app/public/line_login_start.php'
  . '?invite=' . urlencode($invite);
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>キャスト招待QR</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
body{
  margin:0;
  min-height:100vh;
  display:flex;
  align-items:center;
  justify-content:center;
  background:#0b1020;
  color:#e8ecff;
  font-family: system-ui, sans-serif;
}
.card{
  background:rgba(255,255,255,.06);
  border:1px solid rgba(255,255,255,.14);
  border-radius:18px;
  padding:22px;
  text-align:center;
  max-width:420px;
  width:100%;
}
.qr img{
  width:320px;
  max-width:100%;
  background:#fff;
  padding:10px;
  border-radius:12px;
}
.note{
  font-size:14px;
  margin-top:10px;
  opacity:.85;
}
.url{
  margin-top:8px;
  font-size:12px;
  word-break:break-all;
  opacity:.6;
}
</style>
</head>
<body>
  <div class="card">
    <h2>📱 キャスト登録</h2>
    <div class="qr">
      <img src="<?= qr_url($inviteUrl) ?>" alt="招待QR">
    </div>
    <div class="note">
      キャスト本人のLINEで読み取り → ログイン → 登録完了
    </div>
    <div class="url">
      <?= htmlspecialchars($inviteUrl, ENT_QUOTES, 'UTF-8') ?>
    </div>
  </div>
</body>
</html>