<?php
declare(strict_types=1);

namespace DakeLife\Services;

use DakeLife\Repositories\ActionLogRepository;
use DakeLife\Repositories\FortuneRuleRepository;
use DakeLife\Repositories\UserRepository;
use DakeLife\Support\Clock;
use DakeLife\Support\Json;
use InvalidArgumentException;

final class ActionLogService
{
    public function __construct(
        private ?UserRepository $userRepository = null,
        private ?ActionLogRepository $actionLogRepository = null,
        private ?FortuneRuleRepository $ruleRepository = null,
        private ?FortuneCalculatorService $calculator = null
    ) {
        $this->userRepository = $this->userRepository ?: new UserRepository();
        $this->actionLogRepository = $this->actionLogRepository ?: new ActionLogRepository();
        $this->ruleRepository = $this->ruleRepository ?: new FortuneRuleRepository();
        $this->calculator = $this->calculator ?: new FortuneCalculatorService();
    }

    public function registerAction(array $payload): array
    {
        $externalUserId = (int) ($payload['external_user_id'] ?? 0);
        if ($externalUserId <= 0) {
            throw new InvalidArgumentException('external_user_id is required');
        }

        $actionType = trim((string) ($payload['action_type'] ?? ''));
        if ($actionType === '') {
            throw new InvalidArgumentException('action_type is required');
        }

        $rules = $this->ruleRepository->fetchActiveMap();
        if (!isset($rules[$actionType])) {
            throw new InvalidArgumentException('unknown action_type');
        }

        $quantity = (float) ($payload['quantity'] ?? 1);
        if ($quantity <= 0) {
            $quantity = 1.0;
        }

        $occurredAtInput = trim((string) ($payload['occurred_at'] ?? ''));
        $legacyActionAtInput = trim((string) ($payload['action_at'] ?? ''));
        $legacyActionDateInput = trim((string) ($payload['action_date'] ?? ''));

        if ($occurredAtInput !== '') {
            $actionAt = Clock::normalizeDateTime($occurredAtInput);
            $actionDate = substr((string) $actionAt, 0, 10);
        } elseif ($legacyActionAtInput !== '') {
            $actionAt = Clock::normalizeDateTime($legacyActionAtInput, $legacyActionDateInput !== '' ? $legacyActionDateInput : null);
            $actionDate = substr((string) $actionAt, 0, 10);
        } else {
            $actionDate = Clock::normalizeDate($legacyActionDateInput, Clock::today());
            $actionAt = $actionDate . ' 00:00:00';
        }
        $meta = $payload['meta'] ?? [];
        if (!is_array($meta)) {
            $meta = [];
        }

        $user = $this->userRepository->ensureUser(
            $externalUserId,
            (string) ($payload['display_name'] ?? '')
        );

        $logId = $this->actionLogRepository->create([
            'dl_user_id' => (int) $user['id'],
            'action_date' => $actionDate,
            'action_at' => $actionAt,
            'action_type' => $actionType,
            'quantity' => $quantity,
            'meta_json' => Json::encode($meta),
            'source_code' => (string) ($payload['source_code'] ?? 'api'),
            'created_by_user_id' => isset($payload['created_by_user_id']) ? (int) $payload['created_by_user_id'] : null,
        ]);

        $state = $this->calculator->recalcByDlUserId((int) $user['id']);

        return [
            'log_id' => $logId,
            'dl_user_id' => (int) $user['id'],
            'external_user_id' => $externalUserId,
            'occurred_at' => $actionAt,
            'state' => $state,
        ];
    }
}
