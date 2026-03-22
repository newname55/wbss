<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/DakeLife/Support/Loader.php';

\DakeLife\Support\Loader::register();

if (!function_exists('life_json_out')) {
    function life_json_out(array $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }
}

if (!function_exists('life_read_input')) {
    function life_read_input(): array
    {
        $raw = file_get_contents('php://input');
        if (is_string($raw) && trim($raw) !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
    }
}

if (!function_exists('life_may_access_user')) {
    function life_may_access_user(int $targetUserId): bool
    {
        $currentUserId = (int) (current_user_id() ?? 0);
        if ($targetUserId <= 0 || $currentUserId <= 0) {
            return false;
        }
        if ($targetUserId === $currentUserId) {
            return true;
        }

        return is_role('manager') || is_role('admin') || is_role('super_user');
    }
}

if (!function_exists('life_target_user_id')) {
    function life_target_user_id(array $input): int
    {
        $userId = (int) ($input['user_id'] ?? ($_GET['user_id'] ?? current_user_id() ?? 0));
        if ($userId <= 0) {
            life_json_out(['ok' => false, 'error' => 'user_id required'], 400);
        }
        if (!life_may_access_user($userId)) {
            life_json_out(['ok' => false, 'error' => 'forbidden'], 403);
        }
        return $userId;
    }
}
