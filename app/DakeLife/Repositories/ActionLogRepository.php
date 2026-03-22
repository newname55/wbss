<?php
declare(strict_types=1);

namespace DakeLife\Repositories;

use DakeLife\Support\Database;
use PDO;

final class ActionLogRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Database::pdo();
    }

    public function create(array $row): int
    {
        $st = $this->pdo->prepare("
            INSERT INTO dl_action_logs (
              dl_user_id,
              action_date,
              action_at,
              action_type,
              quantity,
              meta_json,
              source_code,
              created_by_user_id,
              created_at
            ) VALUES (
              :dl_user_id,
              :action_date,
              :action_at,
              :action_type,
              :quantity,
              :meta_json,
              :source_code,
              :created_by_user_id,
              NOW()
            )
        ");
        $st->execute([
            ':dl_user_id' => (int) $row['dl_user_id'],
            ':action_date' => (string) $row['action_date'],
            ':action_at' => $row['action_at'] ?? null,
            ':action_type' => (string) $row['action_type'],
            ':quantity' => (float) ($row['quantity'] ?? 1),
            ':meta_json' => $row['meta_json'] ?? null,
            ':source_code' => (string) ($row['source_code'] ?? 'api'),
            ':created_by_user_id' => isset($row['created_by_user_id']) ? (int) $row['created_by_user_id'] : null,
        ]);

        return (int) $this->pdo->lastInsertId();
    }

    public function fetchRecentByUserId(int $dlUserId, int $days = 30): array
    {
        $days = max(1, min(365, $days));
        $st = $this->pdo->prepare("
            SELECT *
            FROM dl_action_logs
            WHERE dl_user_id = :dl_user_id
              AND action_date >= DATE_SUB(CURDATE(), INTERVAL {$days} DAY)
            ORDER BY action_date ASC, id ASC
        ");
        $st->execute([':dl_user_id' => $dlUserId]);
        return $st->fetchAll() ?: [];
    }

    public function fetchRecentForState(int $dlUserId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $st = $this->pdo->prepare("
            SELECT id, action_date, action_at, action_type, quantity, meta_json, source_code, created_at
            FROM dl_action_logs
            WHERE dl_user_id = ?
            ORDER BY action_date DESC, id DESC
            LIMIT {$limit}
        ");
        $st->execute([$dlUserId]);
        return $st->fetchAll() ?: [];
    }

    public function fetchRecentByExternalUserId(int $externalUserId, int $limit = 20): array
    {
        $limit = max(1, min(100, $limit));
        $st = $this->pdo->prepare("
            SELECT
              l.id,
              l.dl_user_id,
              u.external_user_id,
              l.action_date,
              l.action_at,
              l.action_type,
              l.quantity,
              l.meta_json,
              l.source_code,
              l.created_at
            FROM dl_action_logs l
            JOIN dl_users u ON u.id = l.dl_user_id
            WHERE u.external_user_id = ?
            ORDER BY l.action_date DESC, l.id DESC
            LIMIT {$limit}
        ");
        $st->execute([$externalUserId]);
        return $st->fetchAll() ?: [];
    }
}
