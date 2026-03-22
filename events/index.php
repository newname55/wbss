<?php
declare(strict_types=1);

$query = $_SERVER['QUERY_STRING'] ?? '';
$target = '/wbss/public/events/index.php';
if ($query !== '') {
  $target .= '?' . $query;
}

header('Location: ' . $target, true, 302);
exit;
