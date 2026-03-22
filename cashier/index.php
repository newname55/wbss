<?php
declare(strict_types=1);

session_start();

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/wbss/public/cashier/index.php';
if ($query !== '') {
  $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
