cat > /var/www/html/seika-app/public/api/ping.php <<'PHP'
<?php
declare(strict_types=1);
require_once __DIR__ . '/_db.php';
$pdo = db();
header('Content-Type: application/json; charset=utf-8');
echo json_encode([
  'ok' => true,
  'db' => $pdo->query('SELECT DATABASE()')->fetchColumn(),
], JSON_UNESCAPED_UNICODE);
PHP