<?php
declare(strict_types=1);

namespace DakeLife\Repositories;

use DakeLife\Support\Database;
use PDO;

final class MessageTemplateRepository
{
    public function __construct(private ?PDO $pdo = null)
    {
        $this->pdo = $this->pdo ?: Database::pdo();
    }

    public function findBestTemplate(string $phaseCode, float $totalScore): ?array
    {
        $sql = "
            SELECT *
            FROM dl_message_templates
            WHERE is_active = 1
              AND phase_code = :phase_code
              AND (min_total_score IS NULL OR min_total_score <= :total_score)
              AND (max_total_score IS NULL OR max_total_score >= :total_score)
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ";

        $st = $this->pdo->prepare($sql);
        $st->execute([
            ':phase_code' => $phaseCode,
            ':total_score' => $totalScore,
        ]);
        $row = $st->fetch();
        if (is_array($row)) {
            return $row;
        }

        $st = $this->pdo->prepare("
            SELECT *
            FROM dl_message_templates
            WHERE is_active = 1
              AND phase_code = 'default'
            ORDER BY sort_order ASC, id ASC
            LIMIT 1
        ");
        $st->execute();
        $fallback = $st->fetch();
        return is_array($fallback) ? $fallback : null;
    }
}
