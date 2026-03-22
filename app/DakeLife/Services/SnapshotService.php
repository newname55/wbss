<?php
declare(strict_types=1);

namespace DakeLife\Services;

use DakeLife\Repositories\SnapshotRepository;
use DakeLife\Repositories\UserRepository;
use DakeLife\Support\Clock;

final class SnapshotService
{
    public function __construct(
        private ?UserRepository $userRepository = null,
        private ?SnapshotRepository $snapshotRepository = null,
        private ?FortuneCalculatorService $calculator = null
    ) {
        $this->userRepository = $this->userRepository ?: new UserRepository();
        $this->snapshotRepository = $this->snapshotRepository ?: new SnapshotRepository();
        $this->calculator = $this->calculator ?: new FortuneCalculatorService();
    }

    public function createDailySnapshots(?string $snapshotDate = null, ?int $externalUserId = null, bool $writeSnapshots = true): array
    {
        $snapshotDate = Clock::normalizeDate($snapshotDate, Clock::today());
        $users = $this->userRepository->fetchActiveUsers($externalUserId);

        $results = [];
        foreach ($users as $user) {
            $state = $this->calculator->recalcByDlUserId((int) $user['id']);
            if ($writeSnapshots) {
                $this->snapshotRepository->upsert([
                    'dl_user_id' => (int) $user['id'],
                    'snapshot_date' => $snapshotDate,
                    'encounter_score' => (float) $state['encounter_score'],
                    'comfort_score' => (float) $state['comfort_score'],
                    'challenge_score' => (float) $state['challenge_score'],
                    'flow_score' => (float) $state['flow_score'],
                    'total_score' => (float) $state['total_score'],
                    'phase_code' => (string) $state['phase_code'],
                    'message_text' => (string) ($state['last_message_text'] ?? ''),
                    'source_state_updated_at' => (string) $state['updated_at'],
                ]);
            }

            $results[] = [
                'dl_user_id' => (int) $user['id'],
                'external_user_id' => (int) $user['external_user_id'],
                'snapshot_date' => $snapshotDate,
                'phase_code' => (string) $state['phase_code'],
                'total_score' => (float) $state['total_score'],
            ];
        }

        return $results;
    }
}
