<?php
declare(strict_types=1);

namespace DakeLife\Services;

use DakeLife\Repositories\ActionLogRepository;
use DakeLife\Repositories\FortuneRuleRepository;
use DakeLife\Repositories\FortuneStateRepository;
use DakeLife\Repositories\UserRepository;
use DakeLife\Support\Clock;

final class FortuneCalculatorService
{
    private const BASE_SCORES = [
        'encounter_score' => 50.0,
        'comfort_score' => 50.0,
        'challenge_score' => 50.0,
        'flow_score' => 50.0,
    ];

    private const DAILY_DECAY = [
        'encounter_score' => 0.8,
        'comfort_score' => 0.3,
        'challenge_score' => 0.7,
        'flow_score' => 0.9,
    ];

    public function __construct(
        private ?UserRepository $userRepository = null,
        private ?ActionLogRepository $actionLogRepository = null,
        private ?FortuneRuleRepository $ruleRepository = null,
        private ?FortuneStateRepository $stateRepository = null,
        private ?MessageBuilderService $messageBuilder = null
    ) {
        $this->userRepository = $this->userRepository ?: new UserRepository();
        $this->actionLogRepository = $this->actionLogRepository ?: new ActionLogRepository();
        $this->ruleRepository = $this->ruleRepository ?: new FortuneRuleRepository();
        $this->stateRepository = $this->stateRepository ?: new FortuneStateRepository();
        $this->messageBuilder = $this->messageBuilder ?: new MessageBuilderService();
    }

    public function recalcByExternalUserId(int $externalUserId, string $displayName = ''): array
    {
        $user = $this->userRepository->ensureUser($externalUserId, $displayName);
        return $this->recalcByDlUserId((int) $user['id']);
    }

    public function recalcByDlUserId(int $dlUserId): array
    {
        $user = $this->userRepository->findById($dlUserId);
        if ($user === null) {
            throw new \RuntimeException('DAKE_LIFE user not found');
        }

        $rules = $this->ruleRepository->fetchActiveMap();
        $logs = $this->actionLogRepository->fetchRecentByUserId($dlUserId, 30);

        $state = $this->buildState($user, $logs, $rules);
        $this->stateRepository->upsert($state);

        return $this->stateRepository->findByDlUserId($dlUserId) ?? $state;
    }

    private function buildState(array $user, array $logs, array $rules): array
    {
        $scores = self::BASE_SCORES;
        $recentActionCount = count($logs);
        $activeDates = [];
        $repeatCount = 0;
        $newCount = 0;

        foreach ($logs as $log) {
            $actionType = (string) ($log['action_type'] ?? '');
            $quantity = (float) ($log['quantity'] ?? 1);
            if ($quantity <= 0) {
                $quantity = 1.0;
            }

            if (!empty($log['action_date'])) {
                $activeDates[(string) $log['action_date']] = true;
            }

            if (strpos($actionType, 'repeat_') === 0 || $actionType === 'quick_return') {
                $repeatCount++;
            }
            if (strpos($actionType, 'new_') === 0 || $actionType === 'special_event_join' || $actionType === 'long_gap_return') {
                $newCount++;
            }

            $effect = $rules[$actionType] ?? [];
            $scores['encounter_score'] += ((float) ($effect['encounter'] ?? 0)) * $quantity;
            $scores['comfort_score'] += ((float) ($effect['comfort'] ?? 0)) * $quantity;
            $scores['challenge_score'] += ((float) ($effect['challenge'] ?? 0)) * $quantity;
            $scores['flow_score'] += ((float) ($effect['flow'] ?? 0)) * $quantity;
        }

        $inactiveDays = max(0, 30 - count($activeDates));
        foreach (self::DAILY_DECAY as $key => $decay) {
            $scores[$key] -= $decay * $inactiveDays;
        }

        $biasLevel = 0.0;
        if ($repeatCount >= 4 && $repeatCount > $newCount) {
            $gap = $repeatCount - $newCount;
            $scores['challenge_score'] -= min(14.0, $gap * 2.5);
            $scores['flow_score'] -= min(10.0, $gap * 1.5);
            $biasLevel = min(100.0, $gap * 12.0);
        }

        foreach ($scores as $key => $value) {
            $scores[$key] = $this->clamp($value, 0.0, 100.0);
        }

        $totalScore = round(array_sum($scores) / 4, 1);
        $phaseCode = $this->detectPhase($scores, $recentActionCount, $biasLevel);
        $now = Clock::now()->format('Y-m-d H:i:s');
        $message = $this->messageBuilder->buildFromState([
            'dl_user_id' => (int) $user['id'],
            'encounter_score' => $scores['encounter_score'],
            'comfort_score' => $scores['comfort_score'],
            'challenge_score' => $scores['challenge_score'],
            'flow_score' => $scores['flow_score'],
            'total_score' => $totalScore,
            'phase_code' => $phaseCode,
            'bias_level' => round($biasLevel, 1),
            'recent_action_count' => $recentActionCount,
        ], $user);

        return [
            'dl_user_id' => (int) $user['id'],
            'encounter_score' => $scores['encounter_score'],
            'comfort_score' => $scores['comfort_score'],
            'challenge_score' => $scores['challenge_score'],
            'flow_score' => $scores['flow_score'],
            'total_score' => $totalScore,
            'phase_code' => $phaseCode,
            'bias_level' => round($biasLevel, 1),
            'recent_action_count' => $recentActionCount,
            'last_message_text' => (string) ($message['message_text'] ?? ''),
            'based_on_date' => Clock::today(),
            'last_calculated_at' => $now,
        ];
    }

    private function detectPhase(array $scores, int $recentActionCount, float $biasLevel): string
    {
        if ($recentActionCount <= 1) {
            return 'rest';
        }
        if ($biasLevel >= 24.0) {
            return 'biased';
        }
        if ($scores['challenge_score'] >= 68.0 && $scores['encounter_score'] >= 55.0) {
            return 'challenge';
        }
        if ($scores['flow_score'] >= 65.0 && $scores['comfort_score'] >= 55.0) {
            return 'flowing';
        }
        if ($scores['encounter_score'] >= 60.0 || $scores['challenge_score'] >= 60.0) {
            return 'sprout';
        }

        $spread = max($scores) - min($scores);
        if ($spread <= 12.0 && $recentActionCount >= 2) {
            return 'plateau';
        }

        return 'rest';
    }
    private function clamp(float $value, float $min, float $max): float
    {
        return round(max($min, min($max, $value)), 1);
    }
}
