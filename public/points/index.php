<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$storeId = (int)(current_store_id() ?? 0);
if ($storeId <= 0) {
  header('Location: /wbss/public/store_select.php?return=' . rawurlencode('/wbss/public/points/index.php'));
  exit;
}

$query = $_GET;
$query['store_id'] = $storeId;

header('Location: /wbss/public/points_kpi.php?' . http_build_query($query), true, 302);
exit;
