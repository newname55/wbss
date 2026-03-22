<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';

use DakeLife\Repositories\FortuneStateRepository;
use DakeLife\Repositories\UserRepository;
use DakeLife\Services\FortuneCalculatorService;
use DakeLife\Services\MessageBuilderService;

require_login();

$targetUserId = life_target_user_id($_GET);

try {
    $userRepository = new UserRepository();
    $stateRepository = new FortuneStateRepository();
    $calculator = new FortuneCalculatorService();
    $messageBuilder = new MessageBuilderService();

    $user = $userRepository->findByExternalUserId($targetUserId);
    if ($user === null) {
        $user = $userRepository->ensureUser($targetUserId, (string) ($_SESSION['display_name'] ?? ''));
    }

    $state = $stateRepository->findByExternalUserId($targetUserId);
    if ($state === null) {
        $state = $calculator->recalcByExternalUserId(
            $targetUserId,
            (string) ($user['display_name'] ?? ($_SESSION['display_name'] ?? ''))
        );
    }

    $message = $messageBuilder->buildFromState($state, $user);

    life_json_out([
        'ok' => true,
        'user_id' => $targetUserId,
        'message' => [
            'headline' => $message['headline'],
            'phase_text' => $message['phase_text'],
            'strong_point' => $message['strong_point'],
            'warning' => $message['warning'],
            'suggestion' => $message['suggestion'],
            'message_text' => $message['message_text'],
        ],
        'state' => $state,
    ]);
} catch (Throwable $e) {
    life_json_out(['ok' => false, 'error' => $e->getMessage()], 500);
}
