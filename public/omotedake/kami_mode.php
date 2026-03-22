<?php

$configFile = __DIR__.'/config_kami.php';
$config = require $configFile;

if($_SERVER["REQUEST_METHOD"]==="POST"){

    $rate = intval($_POST["rate"]);
    $force = isset($_POST["force"]) ? true : false;

    $rate = max(0,min(100,$rate));

    $newConfig = "<?php\n\nreturn [\n".
    "    \"force_kamidake\" => ".($force?"true":"false").",\n".
    "    \"kamidake_rate\" => ".$rate."\n".
    "];\n";

    $res = @file_put_contents($configFile,$newConfig);

    if ($res === false) {
        $error = '設定の保存に失敗しました。config_kami.php の書き込み権限を確認してください。';
    } else {
        header("Location: kami_mode.php");
        exit;
    }
}

?>

<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>神ダケ管理パネル</title>

<style>

body{
background:#111;
color:#fff;
font-family:sans-serif;
text-align:center;
padding:60px;
}

.panel{
background:#222;
padding:30px;
border-radius:14px;
display:inline-block;
}

h1{
margin-bottom:20px;
}

.rate{
font-size:30px;
margin:20px;
}

input[type=range]{
width:300px;
}

button{
margin-top:20px;
padding:12px 20px;
font-size:18px;
border:none;
border-radius:8px;
cursor:pointer;
background:#ffd700;
}

.mode{
margin-top:10px;
font-size:14px;
opacity:.8;
}

</style>
</head>

<body>

<div class="panel">

<?php if(!empty(
    $error
)):
?>
  <div style="background:#411; color:#ffd6d6; padding:12px; border-radius:8px; margin-bottom:12px;">
    <?= htmlspecialchars($error, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>
  </div>
<?php endif; ?>


<h1>🌟 神ダケ管理パネル</h1>

<p>現在確率</p>

<div class="rate">
<?= $config["force_kamidake"] ? "テストモード 100%" : $config["kamidake_rate"]."%" ?>
</div>

<form method="post">

<p>神ダケ出現率</p>

<input type="range"
       min="0"
       max="100"
       name="rate"
       value="<?= $config["kamidake_rate"] ?>"
       oninput="rateView.innerText=this.value+'%'">

<div id="rateView" class="mode">
<?= $config["kamidake_rate"] ?>%
</div>

<br><br>

<label>
<input type="checkbox"
name="force"
<?= $config["force_kamidake"] ? "checked" : "" ?>>
テストモード（100%）
</label>

<br>

<button type="submit">
保存
</button>

</form>

</div>

</body>
</html>