<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DakeLife\Repositories\SnapshotRepository;

require_login();

$targetUserId = life_target_user_id($_GET);
$limit = (int) ($_GET['limit'] ?? 30);
$limit = max(1, min(30, $limit));

try {
    $repository = new SnapshotRepository();
    $rows = $repository->fetchRecentByExternalUserId($targetUserId, $limit);

    life_json_out([
        'ok' => true,
        'user_id' => $targetUserId,
        'snapshots' => $rows,
    ]);
} catch (Throwable $e) {
    life_json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
