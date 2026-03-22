<?php
declare(strict_types=1);

namespace DakeLife\Repositories;

use DakeLife\Support\Database;
use PDO;

final class FortuneStateRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Database::pdo();
    }

    public function upsert(array $state): void
    {
        $st = $this->pdo->prepare("
            INSERT INTO dl_fortune_states (
              dl_user_id,
              encounter_score,
              comfort_score,
              challenge_score,
              flow_score,
              total_score,
              phase_code,
              bias_level,
              recent_action_count,
              last_message_text,
              based_on_date,
              last_calculated_at,
              created_at,
              updated_at
            ) VALUES (
              :dl_user_id,
              :encounter_score,
              :comfort_score,
              :challenge_score,
              :flow_score,
              :total_score,
              :phase_code,
              :bias_level,
              :recent_action_count,
              :last_message_text,
              :based_on_date,
              :last_calculated_at,
              NOW(),
              NOW()
            )
            ON DUPLICATE KEY UPDATE
              encounter_score = VALUES(encounter_score),
              comfort_score = VALUES(comfort_score),
              challenge_score = VALUES(challenge_score),
              flow_score = VALUES(flow_score),
              total_score = VALUES(total_score),
              phase_code = VALUES(phase_code),
              bias_level = VALUES(bias_level),
              recent_action_count = VALUES(recent_action_count),
              last_message_text = VALUES(last_message_text),
              based_on_date = VALUES(based_on_date),
              last_calculated_at = VALUES(last_calculated_at),
              updated_at = NOW()
        ");
        $st->execute([
            ':dl_user_id' => (int) $state['dl_user_id'],
            ':encounter_score' => (float) $state['encounter_score'],
            ':comfort_score' => (float) $state['comfort_score'],
            ':challenge_score' => (float) $state['challenge_score'],
            ':flow_score' => (float) $state['flow_score'],
            ':total_score' => (float) $state['total_score'],
            ':phase_code' => (string) $state['phase_code'],
            ':bias_level' => (float) $state['bias_level'],
            ':recent_action_count' => (int) $state['recent_action_count'],
            ':last_message_text' => (string) ($state['last_message_text'] ?? ''),
            ':based_on_date' => (string) $state['based_on_date'],
            ':last_calculated_at' => (string) $state['last_calculated_at'],
        ]);
    }

    public function findByDlUserId(int $dlUserId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT fs.*, u.external_user_id, u.display_name
            FROM dl_fortune_states fs
            JOIN dl_users u ON u.id = fs.dl_user_id
            WHERE fs.dl_user_id = ?
            LIMIT 1
        ");
        $st->execute([$dlUserId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }

    public function findByExternalUserId(int $externalUserId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT fs.*, u.external_user_id, u.display_name, u.id AS dl_user_id
            FROM dl_fortune_states fs
            JOIN dl_users u ON u.id = fs.dl_user_id
            WHERE u.external_user_id = ?
            LIMIT 1
        ");
        $st->execute([$externalUserId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }
}
