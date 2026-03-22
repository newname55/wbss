<?php
declare(strict_types=1);

namespace DakeLife\Repositories;

use DakeLife\Support\Database;
use PDO;

final class UserRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Database::pdo();
    }

    public function findByExternalUserId(int $externalUserId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM dl_users
            WHERE external_user_id = ?
            LIMIT 1
        ");
        $st->execute([$externalUserId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }

    public function findById(int $dlUserId): ?array
    {
        $st = $this->pdo->prepare("
            SELECT *
            FROM dl_users
            WHERE id = ?
            LIMIT 1
        ");
        $st->execute([$dlUserId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    }

    public function ensureUser(int $externalUserId, string $displayName): array
    {
        $displayName = trim($displayName);
        if ($displayName === '') {
            $displayName = 'user_' . $externalUserId;
        }

        $existing = $this->findByExternalUserId($externalUserId);
        if ($existing !== null) {
            if ((string) $existing['display_name'] !== $displayName && $displayName !== '') {
                $this->pdo->prepare("
                    UPDATE dl_users
                    SET display_name = ?, updated_at = NOW()
                    WHERE id = ?
                    LIMIT 1
                ")->execute([$displayName, (int) $existing['id']]);
                $existing['display_name'] = $displayName;
            }
            return $existing;
        }

        $this->pdo->prepare("
            INSERT INTO dl_users (
              external_user_id,
              display_name,
              status,
              timezone_name,
              created_at,
              updated_at
            ) VALUES (?, ?, 'active', 'Asia/Tokyo', NOW(), NOW())
        ")->execute([$externalUserId, $displayName]);

        return $this->findById((int) $this->pdo->lastInsertId()) ?? [];
    }

    public function fetchActiveUsers(?int $externalUserId = null): array
    {
        if ($externalUserId !== null && $externalUserId > 0) {
            $st = $this->pdo->prepare("
                SELECT *
                FROM dl_users
                WHERE status = 'active'
                  AND external_user_id = ?
                ORDER BY id ASC
            ");
            $st->execute([$externalUserId]);
            return $st->fetchAll() ?: [];
        }

        $st = $this->pdo->query("
            SELECT *
            FROM dl_users
            WHERE status = 'active'
            ORDER BY id ASC
        ");
        return $st->fetchAll() ?: [];
    }
}
