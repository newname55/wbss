<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

/**
 * Legacy attendance listing endpoint.
 *
 * Canonical rules:
 * - cast actuals are listed from attendances
 * - non-cast legacy schedules are listed from attendance_shifts
 */

$root = dirname(__DIR__, 3);
$auth_candidates = [$root.'/app/auth.php', $root.'/auth.php'];
$db_candidates   = [$root.'/app/db.php', $root.'/db.php'];

foreach ($auth_candidates as $f) { if (is_file($f)) { require_once $f; break; } }
foreach ($db_candidates as $f)   { if (is_file($f)) { require_once $f; break; } }

header('Content-Type: application/json; charset=utf-8');

if (!function_exists('db')) { echo json_encode(['ok'=>false,'error'=>'db() not found'], JSON_UNESCAPED_UNICODE); exit; }
if (function_exists('require_login')) { require_login(); }

function out(array $a, int $code=200): never {
  http_response_code($code);
  echo json_encode($a, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  exit;
}

$ym = (string)($_GET['ym'] ?? date('Y-m'));
if (!preg_match('/^\d{4}-\d{2}$/', $ym)) out(['ok'=>false,'error'=>'invalid ym'], 400);

$store_id = isset($_GET['store_id']) && $_GET['store_id'] !== '' ? (int)$_GET['store_id'] : null;
$person_type = (string)($_GET['person_type'] ?? '');
$q = trim((string)($_GET['q'] ?? ''));

$from = $ym . '-01';
$to = (new DateTimeImmutable($from))->modify('+1 month')->format('Y-m-d');

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if ($person_type === '' || $person_type === 'cast') {
  $where = [];
  $params = [];

  $where[] = "a.business_date >= :from_d AND a.business_date < :to_d";
  $params['from_d'] = $from;
  $params['to_d'] = $to;

  if ($store_id !== null) {
    $where[] = "a.store_id = :store_id";
    $params['store_id'] = $store_id;
  }
  if ($q !== '') {
    $where[] = "(u.display_name LIKE :q1 OR a.note LIKE :q2)";
    $params['q1'] = '%'.$q.'%';
    $params['q2'] = '%'.$q.'%';
  }

  $sql = "SELECT
            a.id AS shift_id,
            a.id AS attendance_id,
            a.store_id,
            'cast' AS person_type,
            a.user_id AS person_id,
            COALESCE(NULLIF(u.display_name, ''), CONCAT('user#', a.user_id)) AS person_name,
            a.business_date AS shift_date,
            TIME_FORMAT(a.clock_in, '%H:%i') AS start_time,
            TIME_FORMAT(a.clock_out, '%H:%i') AS end_time,
            a.status,
            a.note,
            'attendances' AS source_table
          FROM attendances a
          LEFT JOIN users u ON u.id = a.user_id
          WHERE " . implode(' AND ', $where) . "
          ORDER BY a.business_date ASC, start_time ASC, person_name ASC, a.id ASC";

  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC);

  out([
    'ok' => true,
    'rows' => $rows,
    'table' => 'attendances',
  ]);
}

$where = [];
$params = [];

$where[] = "shift_date >= :from_d AND shift_date < :to_d";
$params['from_d'] = $from;
$params['to_d'] = $to;

if ($store_id !== null) {
  $where[] = "store_id = :store_id";
  $params['store_id'] = $store_id;
}
if ($person_type === 'staff') {
  $where[] = "person_type = :ptype";
  $params['ptype'] = $person_type;
}
if ($q !== '') {
  $where[] = "(person_name LIKE :q1 OR note LIKE :q2)";
  $params['q1'] = '%'.$q.'%';
  $params['q2'] = '%'.$q.'%';
}

$sql = "SELECT shift_id, store_id, person_type, person_id, person_name, shift_date, start_time, end_time, status, note,
               'attendance_shifts' AS source_table
        FROM attendance_shifts
        WHERE ".implode(' AND ', $where)."
        ORDER BY shift_date ASC, person_type ASC, start_time ASC, person_name ASC, shift_id ASC";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

out([
  'ok'=>true,
  'rows'=>$rows,
  'table'=>'attendance_shifts',
  'warning'=>'legacy_non_canonical_table',
]);
