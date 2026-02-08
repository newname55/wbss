<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

require_login();
require_role(['manager','admin','super_user']);

ensure_session();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('conf')) {
  function conf(string $key): string {
    if (defined($key)) return (string)constant($key);
    $v = getenv($key);
    return is_string($v) ? $v : '';
  }
}

function json_out(array $a, int $code=200): void {
  http_response_code($code);
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode($a, JSON_UNESCAPED_UNICODE);
  exit;
}

function csrf_fail(): void { json_out(['ok'=>false,'error'=>'CSRF'], 400); }

$csrf = (string)($_POST['csrf_token'] ?? '');
if (!function_exists('csrf_token') || !hash_equals(csrf_token(), $csrf)) csrf_fail();

$pdo = db();

$storeId = (int)($_POST['store_id'] ?? 0);
$castUserId = (int)($_POST['cast_user_id'] ?? 0);
$kind = (string)($_POST['kind'] ?? '');
$businessDate = (string)($_POST['business_date'] ?? '');
$text = trim((string)($_POST['text'] ?? ''));

if ($storeId <= 0 || $castUserId <= 0) json_out(['ok'=>false,'error'=>'bad params'], 400);
if (!in_array($kind, ['late','absent'], true)) json_out(['ok'=>false,'error'=>'bad kind'], 400);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $businessDate)) json_out(['ok'=>false,'error'=>'bad date'], 400);
if ($text === '') json_out(['ok'=>false,'error'=>'empty text'], 400);

$accessToken = conf('LINE_MSG_CHANNEL_ACCESS_TOKEN');
if ($accessToken === '') json_out(['ok'=>false,'error'=>'LINE token missing'], 500);

/** 店長（送信者） */
$senderId = function_exists('current_user_id') ? (int)current_user_id() : (int)($_SESSION['user_id'] ?? 0);

/** 通知先LINE userId を取得（user_identities） */
$st = $pdo->prepare("
  SELECT provider_user_id
  FROM user_identities
  WHERE user_id=? AND provider='line' AND is_active=1
  LIMIT 1
");
$st->execute([$castUserId]);
$lineUserId = (string)($st->fetchColumn() ?: '');
if ($lineUserId === '') {
  json_out(['ok'=>false,'error'=>'このキャストはLINE未連携です'], 400);
}

/** token 作成（返信紐付け） */
$token = 'N' . bin2hex(random_bytes(8)); // 先頭N + 16hex = 17文字

$kindLabel = ($kind === 'late') ? '遅刻' : '欠勤';

/**
 * メッセージ末尾に token を入れる（返信が来た時に紐付く）
 * ※キャストが token を消して返信しても、最新未返信通知に紐付ける保険も webhook 側で実装する
 */
$sentText = $text . "\n\n"
  . "――――\n"
  . "返信すると店長画面に自動反映されます。\n"
  . "ID: {$token}";

$pdo->beginTransaction();
$actionId = 0;

try {
  // 履歴保存（押した瞬間に確保）
  $ins = $pdo->prepare("
    INSERT INTO line_notice_actions
      (store_id, business_date, cast_user_id, kind, token, template_text, sent_text,
       sent_by_user_id, sent_at, status, created_at, updated_at)
    VALUES
      (?, ?, ?, ?, ?, ?, ?, ?, NOW(), 'sent', NOW(), NOW())
  ");
  $ins->execute([$storeId, $businessDate, $castUserId, $kind, $token, $text, $sentText, $senderId]);
  $actionId = (int)$pdo->lastInsertId();

  // LINE push
  $payload = json_encode([
    'to' => $lineUserId,
    'messages' => [[
      'type' => 'text',
      'text' => $sentText,
    ]]
  ], JSON_UNESCAPED_UNICODE);

  $ch = curl_init('https://api.line.me/v2/bot/message/push');
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
      'Content-Type: application/json',
      'Authorization: Bearer ' . $accessToken,
    ],
    CURLOPT_POSTFIELDS => $payload,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 10,
  ]);
  $res = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $err = curl_error($ch);
  curl_close($ch);

  if ($code >= 300) {
    $pdo->prepare("
      UPDATE line_notice_actions
      SET status='failed', error_msg=?, updated_at=NOW()
      WHERE id=?
      LIMIT 1
    ")->execute([substr(($err ?: (string)$res), 0, 255), $actionId]);

    $pdo->commit();
    json_out(['ok'=>false,'error'=>'LINE送信失敗', 'http'=>$code, 'detail'=>substr((string)$res,0,200)], 500);
  }

  $pdo->commit();
  json_out(['ok'=>true, 'action_id'=>$actionId, 'token'=>$token]);

} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  json_out(['ok'=>false,'error'=>$e->getMessage()], 500);
}