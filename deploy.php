<?php

$secret = "your_secret_key_here";

// GitHub署名チェック
$payload = file_get_contents("php://input");
$sig = $_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? '';

$hash = 'sha256=' . hash_hmac('sha256', $payload, $secret);

if (!hash_equals($hash, $sig)) {
    http_response_code(403);
    exit('Invalid signature');
}

// deploy実行
$output = [];
exec('/var/www/html/wbss/deploy.sh 2>&1', $output);

echo implode("\n", $output);
