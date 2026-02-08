<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$all = function_exists('getallheaders') ? getallheaders() : [];

echo json_encode([
  'ok' => true,
  'HTTP_AUTHORIZATION' => $_SERVER['HTTP_AUTHORIZATION'] ?? null,
  'Authorization_header' => $all['Authorization'] ?? ($all['authorization'] ?? null),
], JSON_UNESCAPED_UNICODE);
