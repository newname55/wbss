<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/DakeLife/Support/Loader.php';

\DakeLife\Support\Loader::register();

use DakeLife\Services\SnapshotService;

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("CLI only\n");
}

function dake_life_parse_options(array $argv): array
{
    $options = [
        'date' => null,
        'user_id' => null,
        'dry_run' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if (strpos($arg, '--date=') === 0) {
            $options['date'] = substr($arg, 7);
            continue;
        }
        if (strpos($arg, '--user-id=') === 0) {
            $value = substr($arg, 10);
            if ($value !== '' && ctype_digit($value)) {
                $options['user_id'] = (int) $value;
            }
        }
    }

    return $options;
}

$options = dake_life_parse_options($argv);

try {
    $service = new SnapshotService();
    $results = $service->createDailySnapshots($options['date'], $options['user_id'], !$options['dry_run']);

    foreach ($results as $row) {
        $line = sprintf(
            "[dake_life_snapshot_daily] snapshot_date=%s user_id=%d dl_user_id=%d phase=%s total_score=%.1f",
            $row['snapshot_date'],
            $row['external_user_id'],
            $row['dl_user_id'],
            $row['phase_code'],
            $row['total_score']
        );
        fwrite(STDOUT, $line . PHP_EOL);
    }

    fwrite(STDOUT, sprintf(
        "[dake_life_snapshot_daily] completed count=%d dry_run=%s\n",
        count($results),
        $options['dry_run'] ? 'true' : 'false'
    ));
} catch (Throwable $e) {
    fwrite(STDERR, '[dake_life_snapshot_daily] failed: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
