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
  $zip = (string)($_POST['zip'] ?? '');
  $result = transport_lookup_address_by_zip($zip);
  echo json_encode([
    'ok' => true,
    'zip' => $result['zip'],
    'prefecture' => $result['prefecture'],
    'city' => $result['city'],
    'address1' => $result['address1'],
    'formatted_address' => $result['formatted_address'],
    'lat' => $result['lat'],
    'lng' => $result['lng'],
  ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
  http_response_code(400);
  echo json_encode([
    'ok' => false,
    'error' => $e->getMessage(),
  ], JSON_UNESCAPED_UNICODE);
}
