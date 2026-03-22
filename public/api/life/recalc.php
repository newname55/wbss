<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DakeLife\Services\FortuneCalculatorService;

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    life_json_out(['ok' => false, 'error' => 'method not allowed'], 405);
}

$input = life_read_input();
$targetUserId = life_target_user_id($input);

try {
    $service = new FortuneCalculatorService();
    $state = $service->recalcByExternalUserId(
        $targetUserId,
        (string) ($input['display_name'] ?? ($_SESSION['display_name'] ?? ''))
    );

    life_json_out([
        'ok' => true,
        'user_id' => $targetUserId,
        'state' => $state,
    ]);
} catch (Throwable $e) {
    life_json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
