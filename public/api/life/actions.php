<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DakeLife\Services\ActionLogService;

require_login();

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    life_json_out(['ok' => false, 'error' => 'method not allowed'], 405);
}

$input = life_read_input();
$targetUserId = life_target_user_id($input);

try {
    $service = new ActionLogService();
    $result = $service->registerAction([
        'external_user_id' => $targetUserId,
        'display_name' => (string) ($input['display_name'] ?? ($_SESSION['display_name'] ?? '')),
        'action_type' => (string) ($input['action_type'] ?? ''),
        'occurred_at' => (string) ($input['occurred_at'] ?? ''),
        'action_date' => (string) ($input['action_date'] ?? ''),
        'action_at' => (string) ($input['action_at'] ?? ''),
        'quantity' => $input['quantity'] ?? 1,
        'meta' => is_array($input['meta'] ?? null) ? $input['meta'] : [],
        'source_code' => (string) ($input['source_code'] ?? 'api'),
        'created_by_user_id' => (int) (current_user_id() ?? 0),
    ]);

    life_json_out([
        'ok' => true,
        'log_id' => $result['log_id'],
        'dl_user_id' => $result['dl_user_id'],
        'user_id' => $result['external_user_id'],
        'occurred_at' => $result['occurred_at'],
        'state' => $result['state'],
    ]);
} catch (InvalidArgumentException $e) {
    life_json_out(['ok' => false, 'error' => $e->getMessage()], 400);
} catch (Throwable $e) {
    life_json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
