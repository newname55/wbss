<?php
declare(strict_types=1);

if (!function_exists('conf')) {
  function conf(string $key): string {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return is_string($v) ? $v : '';
  }
}

$channelSecret = (string)conf('LINE_MSG_CHANNEL_SECRET'); // ★Messaging用 secret
if ($channelSecret === '') {
  error_log('[line_webhook] missing LINE_MSG_CHANNEL_SECRET');
  http_response_code(500);
  echo 'LINE config missing';
  exit;
}

$body = file_get_contents('php://input');
if ($body === false) $body = '';

// 署名ヘッダ取得（環境差を吸収）
$signature = '';
if (!empty($_SERVER['HTTP_X_LINE_SIGNATURE'])) {
  $signature = (string)$_SERVER['HTTP_X_LINE_SIGNATURE'];
} else {
  // Apache/PHP-FPM等で $_SERVER に載らない場合
  if (function_exists('getallheaders')) {
    $h = getallheaders();
    if (is_array($h)) {
      foreach ($h as $k => $v) {
        if (strcasecmp((string)$k, 'X-Line-Signature') === 0) {
          $signature = (string)$v;
          break;
        }
      }
    }
  }
}
$signature = trim($signature);

if ($signature === '') {
  // デバッグ：来てるヘッダ名だけ出す（値は出さない）
  $keys = [];
  if (function_exists('getallheaders')) {
    $h = getallheaders();
    if (is_array($h)) $keys = array_keys($h);
  }
  error_log('[line_webhook] Missing signature. method=' . ($_SERVER['REQUEST_METHOD'] ?? '')
    . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
    . ' headers=' . implode(',', $keys)
    . ' body_len=' . strlen($body)
  );

  http_response_code(400);
  echo 'Missing signature';
  exit;
}

$expected = base64_encode(hash_hmac('sha256', $body, $channelSecret, true));

if (!hash_equals($expected, $signature)) {
  error_log('[line_webhook] Bad signature'
    . ' method=' . ($_SERVER['REQUEST_METHOD'] ?? '')
    . ' ua=' . ($_SERVER['HTTP_USER_AGENT'] ?? '')
    . ' body_len=' . strlen($body)
    . ' sig_len=' . strlen($signature)
    . ' exp_len=' . strlen($expected)
    . ' secret_len=' . strlen($channelSecret)
  );
  http_response_code(401);
  echo 'Bad signature';
  exit;
}

error_log('[line_webhook] signature OK. body_len=' . strlen($body));
http_response_code(200);
echo 'OK';