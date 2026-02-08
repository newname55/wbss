<?php
declare(strict_types=1);
header('Content-Type: text/plain; charset=utf-8');

$keys = [
  'GOOGLE_CLIENT_ID',
  'GOOGLE_CLIENT_SECRET',
  'GOOGLE_REDIRECT_URI',
  'OAUTH_STATE_SECRET',
];

foreach ($keys as $k) {
  $v = getenv($k);
  echo $k . '=' . ($v !== false && $v !== '' ? 'OK' : 'MISSING') . "\n";
}
