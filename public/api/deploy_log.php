<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/bootstrap.php';
require_once __DIR__ . '/../../app/db.php';

header('Content-Type: application/json; charset=UTF-8');

$secret = trim((string) conf('CRON_SECRET'));
$given  = trim((string)($_POST['secret'] ?? ''));

if ($secret === '' || !hash_equals($secret, $given)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'forbidden'], JSON_UNESCAPED_UNICODE);
    exit;
}

$environment  = trim((string)($_POST['environment'] ?? ''));
$hostName     = trim((string)($_POST['host_name'] ?? ''));
$branchName   = trim((string)($_POST['branch_name'] ?? ''));
$beforeCommit = trim((string)($_POST['before_commit'] ?? ''));
$afterCommit  = trim((string)($_POST['after_commit'] ?? ''));
$status       = trim((string)($_POST['status'] ?? ''));
$executedBy   = trim((string)($_POST['executed_by'] ?? ''));
$detailText   = trim((string)($_POST['detail_text'] ?? ''));

if (
    $environment === '' ||
    $hostName === '' ||
    $branchName === '' ||
    !in_array($status, ['success', 'failed'], true)
) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => 'invalid params'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $pdo = db();

    $st = $pdo->prepare("
        INSERT INTO deploy_logs
            (environment, host_name, branch_name, before_commit, after_commit, status, executed_by, detail_text, created_at)
        VALUES
            (?, ?, ?, NULLIF(?, ''), NULLIF(?, ''), ?, NULLIF(?, ''), NULLIF(?, ''), NOW())
    ");

    $st->execute([
        $environment,
        $hostName,
        $branchName,
        $beforeCommit,
        $afterCommit,
        $status,
        $executedBy,
        $detailText,
    ]);

    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'ok' => false,
        'error' => 'db_error',
        'message' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE);
}