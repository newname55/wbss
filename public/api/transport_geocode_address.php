<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/service_transport.php';

require_login();
require_role(['manager', 'admin', 'super_user']);

header('Content-Type: application/json; charset=UTF-8');

try {
  csrf_verify((string)($_POST['csrf_token'] ?? ''));
  $address = transport_join_address_parts([
    (string)($_POST['prefecture'] ?? ''),
    (string)($_POST['city'] ?? ''),
    (string)($_POST['address1'] ?? ''),
    (string)($_POST['address2'] ?? ''),
    (string)($_POST['building'] ?? ''),
  ]);
  if ($address === '') {
    throw new RuntimeException('住所を入力してから座標取得してください');
  }

  $geo = transport_google_geocode($address);
  echo json_encode([
    'ok' => true,
    'lat' => $geo['lat'] ?? null,
    'lng' => $geo['lng'] ?? null,
    'formatted_address' => (string)($geo['formatted_address'] ?? ''),
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
