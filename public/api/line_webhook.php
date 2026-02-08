<?php
declare(strict_types=1);

/**
 * LINE Messaging API Webhook (safe)
 * path: /seika-app/public/api/line_webhook.php
 */

ini_set('display_errors', '0'); // 画面に出さない（LINE向け）
error_reporting(E_ALL);

try {
  // ★ public/api から app へ： ../../app/db.php
  $dbPath = dirname(__DIR__, 2) . '/app/db.php';
  if (!is_file($dbPath)) {
    error_log('[line_webhook] db.php not found: ' . $dbPath);
    http_response_code(200);
    echo 'OK';
    exit;
  }
  require_once $dbPath;

  if (!function_exists('conf')) {
    function conf(string $key): string {
      if (defined($key)) return (string)constant($key);
      $v = getenv($key);
      return is_string($v) ? $v : '';
    }
  }

  // LINEは200以外だと「失敗」扱い（検証も落ちる）
  $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
  if ($method !== 'POST') {
    error_log('[line_webhook] non-POST method=' . $method);
    http_response_code(200);
    echo 'OK';
    exit;
  }

  $channelSecret = conf('LINE_MSG_CHANNEL_SECRET');
  $accessToken   = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');

  if ($channelSecret === '' || $accessToken === '') {
    error_log('[line_webhook] LINE env missing secret/token');
    http_response_code(200);
    echo 'OK';
    exit;
  }

  $rawBody = file_get_contents('php://input');
  if ($rawBody === false) $rawBody = '';
  $sig = $_SERVER['HTTP_X_LINE_SIGNATURE'] ?? '';

  if ($sig === '') {
    error_log('[line_webhook] Missing signature. body_len=' . strlen($rawBody));
    http_response_code(200);
    echo 'OK';
    exit;
  }
error_log('[line_webhook] using secret_head=' . substr($channelSecret, 0, 6) . ' token_len=' . strlen($accessToken));
  $expected = base64_encode(hash_hmac('sha256', $rawBody, $channelSecret, true));
  if (!hash_equals($expected, $sig)) {
    error_log('[line_webhook] invalid signature body_len=' . strlen($rawBody));
    // ここも 200 にしておく（LINE検証を通す・リトライ地獄回避）
    http_response_code(200);
    echo 'OK';
    exit;
  }

  error_log('[line_webhook] signature OK body_len=' . strlen($rawBody));

  $payload = json_decode($rawBody, true);
  if (!is_array($payload)) {
    error_log('[line_webhook] invalid json');
    http_response_code(200);
    echo 'OK';
    exit;
  }

  $events = $payload['events'] ?? [];
  if (!is_array($events)) $events = [];

  // ここから先でDBや返信などをやっても、落ちたら catch で 200 にする
  $pdo = db();

  // 返信ヘルパ
  $line_api_post = function(string $path, array $body) use ($accessToken): void {
    $url = 'https://api.line.me/v2/bot/' . ltrim($path, '/');
    $opts = [
      'http' => [
        'method' => 'POST',
        'header' => "Content-Type: application/json\r\nAuthorization: Bearer {$accessToken}\r\n",
        'content' => json_encode($body, JSON_UNESCAPED_UNICODE),
        'timeout' => 15,
      ]
    ];
    @file_get_contents($url, false, stream_context_create($opts));
  };

  foreach ($events as $ev) {
    $type = (string)($ev['type'] ?? '');
    $replyToken = (string)($ev['replyToken'] ?? '');
    $lineUserId = (string)($ev['source']['userId'] ?? '');

    // eventsが空の ping も来る（destinationだけでevents=[]）
    if ($type === '' || $replyToken === '' || $lineUserId === '') continue;

    // テスト：テキストだけオウム返し
    if ($type === 'message' && (($ev['message']['type'] ?? '') === 'text')) {
      $text = (string)($ev['message']['text'] ?? '');
      $line_api_post('message/reply', [
        'replyToken' => $replyToken,
        'messages' => [[ 'type'=>'text', 'text' => '受信OK: ' . $text ]]
      ]);
      continue;
    }

    // それ以外は未対応でもOK（落ちないのが大事）
  }

  http_response_code(200);
  echo 'OK';
  exit;

} catch (Throwable $e) {
  // ★ここが最重要：何が起きても 200 を返す
  error_log('[line_webhook] FATAL: ' . $e->getMessage());
  http_response_code(200);
  echo 'OK';
  exit;
}