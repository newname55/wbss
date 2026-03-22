<?php
declare(strict_types=1);

namespace DakeLife\Repositories;

use DakeLife\Support\Database;
use DakeLife\Support\Json;
use PDO;

final class FortuneRuleRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Database::pdo();
    }

    public function fetchActiveMap(): array
    {
        $st = $this->pdo->query("
            SELECT action_type, effect_json
            FROM dl_fortune_rules
            WHERE is_active = 1
            ORDER BY id ASC
        ");

        $map = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $map[(string) $row['action_type']] = Json::decodeObject((string) $row['effect_json']);
        }

        return $map;
    }
}
