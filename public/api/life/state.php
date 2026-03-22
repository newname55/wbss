<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DakeLife\Repositories\ActionLogRepository;
use DakeLife\Repositories\FortuneStateRepository;
use DakeLife\Repositories\UserRepository;
use DakeLife\Support\Json;

require_login();

$targetUserId = life_target_user_id($_GET);

try {
    $userRepository = new UserRepository();
    $stateRepository = new FortuneStateRepository();
    $actionLogRepository = new ActionLogRepository();

    $user = $userRepository->findByExternalUserId($targetUserId);
    if ($user === null) {
        life_json_out([
            'ok' => true,
            'user_id' => $targetUserId,
            'state' => null,
            'recent_logs' => [],
        ]);
    }

    $state = $stateRepository->findByExternalUserId($targetUserId);
    $logs = $actionLogRepository->fetchRecentForState((int) $user['id'], 20);
    foreach ($logs as &$log) {
        $log['meta'] = Json::decodeObject((string) ($log['meta_json'] ?? ''));
        $log['occurred_at'] = (string) ($log['action_at'] ?? ($log['action_date'] . ' 00:00:00'));
        unset($log['meta_json']);
    }
    unset($log);

    life_json_out([
        'ok' => true,
        'user_id' => $targetUserId,
        'state' => $state,
        'recent_logs' => $logs,
    ]);
} catch (Throwable $e) {
    life_json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
