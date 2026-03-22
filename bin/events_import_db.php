<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

$root = dirname(__DIR__);
require_once $root . '/app/db.php';
if (!function_exists('db')) { fwrite(STDERR, "db() not found\n"); exit(1); }

function logi(string $m): void { fwrite(STDOUT, '['.(new DateTimeImmutable())->format('Y-m-d H:i:s')."] $m\n"); }

$jsonPath = $argv[1] ?? ($root . '/storage/events/okayama_events.json');
if (!is_file($jsonPath)) { fwrite(STDERR, "json not found: $jsonPath\n"); exit(1); }

$j = json_decode((string)file_get_contents($jsonPath), true);
if (!is_array($j)) { fwrite(STDERR, "json decode failed\n"); exit(1); }

$events = $j['items'] ?? null;
if (!is_array($events)) {
  fwrite(STDERR, "items not found in json\n");
  logi("keys=".implode(',', array_keys($j)));
  exit(1);
}

logi("load items=" . count($events));

$pdo = db();
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

// event_sources: name -> source_id
$stSelSource = $pdo->prepare("SELECT source_id FROM event_sources WHERE name=:name LIMIT 1");
$stInsSource = $pdo->prepare("INSERT INTO event_sources (name, created_at, updated_at) VALUES (:name, NOW(), NOW())");

$getSourceId = function(string $name) use ($pdo, $stSelSource, $stInsSource): int {
  $stSelSource->execute(['name'=>$name]);
  $id = $stSelSource->fetchColumn();
  if ($id !== false) return (int)$id;
  $stInsSource->execute(['name'=>$name]);
  return (int)$pdo->lastInsertId();
};

// events UPSERT（※ events テーブルの列名が違う場合はここを合わせて）
$stUpsert = $pdo->prepare("
INSERT INTO events (
  source_id, source_event_id,
  title, starts_at, ends_at, all_day,
  venue_name, address,
  organizer_name, contact_name,
  source_url,
  status,
  created_at, updated_at
) VALUES (
  :source_id, :source_event_id,
  :title, :starts_at, :ends_at, :all_day,
  :venue_name, :address,
  :organizer_name, :contact_name,
  :source_url,
  :status,
  NOW(), NOW()
)
ON DUPLICATE KEY UPDATE
  title=VALUES(title),
  starts_at=VALUES(starts_at),
  ends_at=VALUES(ends_at),
  all_day=VALUES(all_day),
  venue_name=VALUES(venue_name),
  address=VALUES(address),
  organizer_name=VALUES(organizer_name),
  contact_name=VALUES(contact_name),
  source_url=VALUES(source_url),
  status=VALUES(status),
  updated_at=NOW()
");

$pdo->beginTransaction();

$ins = 0;
$skip = 0;
$bySource = [];

foreach ($events as $ev) {
  if (!is_array($ev)) { $skip++; continue; }

  $source = trim((string)($ev['source'] ?? ''));
  $sid    = trim((string)($ev['source_id'] ?? ''));
  $title  = trim((string)($ev['title'] ?? ''));
  if ($source==='' || $sid==='' || $title==='') { $skip++; continue; }

  $source_id = $getSourceId($source);

  $starts = $ev['starts_at'] ?? null;
  $ends   = $ev['ends_at'] ?? null;
  $starts = (is_string($starts) && $starts!=='') ? $starts : null;
  $ends   = (is_string($ends)   && $ends!=='')   ? $ends   : null;

  $allDay = (int)($ev['all_day'] ?? 0);

  $venue  = isset($ev['venue_name']) ? (string)$ev['venue_name'] : null;

  // JSON側は venue_addr のことがあるので両対応
  $addr = null;
  if (isset($ev['address'])) $addr = (string)$ev['address'];
  if ($addr === null && isset($ev['venue_addr'])) $addr = (string)$ev['venue_addr'];

  $org = isset($ev['organizer_name']) ? (string)$ev['organizer_name'] : null;

  // JSON側は organizer_contact のことがあるので両対応
  $contact = null;
  if (isset($ev['contact_name'])) $contact = (string)$ev['contact_name'];
  if ($contact === null && isset($ev['organizer_contact'])) $contact = (string)$ev['organizer_contact'];

  $url = trim((string)($ev['source_url'] ?? ''));
  if ($url === '') { $skip++; continue; }

  $stUpsert->execute([
    'source_id' => $source_id,
    'source_event_id' => $sid,
    'title' => $title,
    'starts_at' => $starts,
    'ends_at' => $ends,
    'all_day' => $allDay,
    'venue_name' => $venue,
    'address' => $addr,
    'organizer_name' => $org,
    'contact_name' => $contact,
    'source_url' => $url,
    'status' => 'published',
  ]);

  $ins++;
  $bySource[$source] = ($bySource[$source] ?? 0) + 1;
}

$pdo->commit();

logi("upsert=$ins skip=$skip");
foreach ($bySource as $k=>$v) logi("  $k=$v");
logi("done");