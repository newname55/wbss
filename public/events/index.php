<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';

require_login();

if (!is_role('super_user') && !is_role('admin') && !is_role('manager') && !is_role('staff')) {
  http_response_code(403);
  exit('Forbidden');
}

$storeId = (int)current_store_id();
if ($storeId <= 0) {
  header('Location: /wbss/public/store_select.php?return=' . rawurlencode('/wbss/public/events/index.php'));
  exit;
}

header('Location: /wbss/public/store_events/index.php?' . http_build_query([
  'store_id' => $storeId,
  'tab' => 'internal',
]));
exit;
