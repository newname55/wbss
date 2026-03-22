<?php
declare(strict_types=1);

namespace DakeLife\Repositories;

use DakeLife\Support\Database;
use PDO;

final class SnapshotRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Database::pdo();
    }

    public function upsert(array $snapshot): void
    {
        $st = $this->pdo->prepare("
            INSERT INTO dl_daily_snapshots (
              dl_user_id,
              snapshot_date,
              encounter_score,
              comfort_score,
              challenge_score,
              flow_score,
              total_score,
              phase_code,
              message_text,
              source_state_updated_at,
              created_at,
              updated_at
            ) VALUES (
              :dl_user_id,
              :snapshot_date,
              :encounter_score,
              :comfort_score,
              :challenge_score,
              :flow_score,
              :total_score,
              :phase_code,
              :message_text,
              :source_state_updated_at,
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
              message_text = VALUES(message_text),
              source_state_updated_at = VALUES(source_state_updated_at),
              updated_at = NOW()
        ");
        $st->execute([
            ':dl_user_id' => (int) $snapshot['dl_user_id'],
            ':snapshot_date' => (string) $snapshot['snapshot_date'],
            ':encounter_score' => (float) $snapshot['encounter_score'],
            ':comfort_score' => (float) $snapshot['comfort_score'],
            ':challenge_score' => (float) $snapshot['challenge_score'],
            ':flow_score' => (float) $snapshot['flow_score'],
            ':total_score' => (float) $snapshot['total_score'],
            ':phase_code' => (string) $snapshot['phase_code'],
            ':message_text' => (string) ($snapshot['message_text'] ?? ''),
            ':source_state_updated_at' => (string) $snapshot['source_state_updated_at'],
        ]);
    }

    public function fetchRecentByExternalUserId(int $externalUserId, int $limit = 30): array
    {
        $limit = max(1, min(100, $limit));
        $st = $this->pdo->prepare("
            SELECT
              s.id,
              s.dl_user_id,
              u.external_user_id,
              s.snapshot_date,
              s.encounter_score,
              s.comfort_score,
              s.challenge_score,
              s.flow_score,
              s.total_score,
              s.phase_code,
              s.message_text,
              s.source_state_updated_at,
              s.created_at,
              s.updated_at
            FROM dl_daily_snapshots s
            JOIN dl_users u ON u.id = s.dl_user_id
            WHERE u.external_user_id = ?
            ORDER BY s.snapshot_date DESC, s.id DESC
            LIMIT {$limit}
        ");
        $st->execute([$externalUserId]);
        return $st->fetchAll() ?: [];
    }
}
