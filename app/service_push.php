<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function push_base64url_encode(string $bin): string {
  return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
}

function push_base64url_decode(string $value): string {
  $value = strtr($value, '-_', '+/');
  $pad = strlen($value) % 4;
  if ($pad > 0) {
    $value .= str_repeat('=', 4 - $pad);
  }
  $decoded = base64_decode($value, true);
  if ($decoded === false) {
    throw new RuntimeException('base64url decode failed');
  }
  return $decoded;
}

function push_table_exists(PDO $pdo, string $table): bool {
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM information_schema.TABLES
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
    ");
    $st->execute([$table]);
    return ((int)$st->fetchColumn() > 0);
  } catch (Throwable $e) {
    return false;
  }
}

function push_tables_ready(PDO $pdo): bool {
  static $ready = null;
  if ($ready !== null) {
    return $ready;
  }
  $ready = push_table_exists($pdo, 'push_subscriptions');
  return $ready;
}

function push_vapid_storage_path(): string {
  return __DIR__ . '/webpush_vapid_keys.json';
}

function push_vapid_subject(): string {
  $subject = trim((string) conf('WBSS_WEBPUSH_VAPID_SUBJECT'));
  if ($subject === '') {
    $subject = trim((string) conf('SEIKA_WEBPUSH_VAPID_SUBJECT'));
  }
  if ($subject !== '') {
    return $subject;
  }

  $host = trim((string)($_SERVER['HTTP_HOST'] ?? ''));
  if ($host !== '') {
    return 'https://' . $host;
  }
  return 'mailto:wbss@example.invalid';
}

function push_generate_vapid_keys(): array {
  $res = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
  ]);
  if ($res === false) {
    throw new RuntimeException('VAPID key generation failed');
  }

  if (!openssl_pkey_export($res, $privatePem)) {
    throw new RuntimeException('VAPID private key export failed');
  }

  $details = openssl_pkey_get_details($res);
  if (!is_array($details) || empty($details['ec']['x']) || empty($details['ec']['y']) || empty($details['ec']['d'])) {
    throw new RuntimeException('VAPID key details unavailable');
  }

  $publicRaw = "\x04" . $details['ec']['x'] . $details['ec']['y'];
  $privateRaw = $details['ec']['d'];

  return [
    'subject' => push_vapid_subject(),
    'public_key' => push_base64url_encode($publicRaw),
    'private_key' => push_base64url_encode($privateRaw),
    'private_pem' => $privatePem,
  ];
}

function push_vapid_config(): array {
  static $config = null;
  if (is_array($config)) {
    return $config;
  }

  $envPublic = trim((string) conf('WBSS_WEBPUSH_VAPID_PUBLIC_KEY'));
  $envPrivate = trim((string) conf('WBSS_WEBPUSH_VAPID_PRIVATE_KEY'));
  if ($envPublic === '' || $envPrivate === '') {
    $envPublic = trim((string) conf('SEIKA_WEBPUSH_VAPID_PUBLIC_KEY'));
    $envPrivate = trim((string) conf('SEIKA_WEBPUSH_VAPID_PRIVATE_KEY'));
  }
  if ($envPublic !== '' && $envPrivate !== '') {
    $config = [
      'subject' => push_vapid_subject(),
      'public_key' => $envPublic,
      'private_key' => $envPrivate,
      'private_pem' => null,
    ];
    return $config;
  }

  $path = push_vapid_storage_path();
  if (is_file($path)) {
    $loaded = json_decode((string)file_get_contents($path), true);
    if (is_array($loaded) && !empty($loaded['public_key']) && !empty($loaded['private_key']) && !empty($loaded['private_pem'])) {
      $config = $loaded;
      return $config;
    }
  }

  $generated = push_generate_vapid_keys();
  file_put_contents($path, json_encode($generated, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
  $config = $generated;
  return $config;
}

function push_p256_public_key_to_pem(string $rawPublicKey): string {
  $der = hex2bin('3059301306072A8648CE3D020106082A8648CE3D030107034200') . $rawPublicKey;
  $pem = "-----BEGIN PUBLIC KEY-----\n";
  $pem .= chunk_split(base64_encode($der), 64, "\n");
  $pem .= "-----END PUBLIC KEY-----\n";
  return $pem;
}

function push_hkdf_expand(string $prk, string $info, int $length): string {
  $result = '';
  $block = '';
  $counter = 1;
  while (strlen($result) < $length) {
    $block = hash_hmac('sha256', $block . $info . chr($counter), $prk, true);
    $result .= $block;
    $counter++;
  }
  return substr($result, 0, $length);
}

function push_der_signature_to_jose(string $derSignature, int $partLength = 32): string {
  $offset = 0;
  if (ord($derSignature[$offset]) !== 0x30) {
    throw new RuntimeException('Invalid DER signature');
  }
  $offset++;
  $seqLen = ord($derSignature[$offset]);
  if ($seqLen & 0x80) {
    $numBytes = $seqLen & 0x7f;
    $offset++;
    $seqLen = 0;
    for ($i = 0; $i < $numBytes; $i++) {
      $seqLen = ($seqLen << 8) | ord($derSignature[$offset + $i]);
    }
    $offset += $numBytes;
  } else {
    $offset++;
  }

  $readInt = function() use ($derSignature, &$offset, $partLength): string {
    if (ord($derSignature[$offset]) !== 0x02) {
      throw new RuntimeException('Invalid DER integer');
    }
    $offset++;
    $len = ord($derSignature[$offset]);
    $offset++;
    $value = substr($derSignature, $offset, $len);
    $offset += $len;
    $value = ltrim($value, "\x00");
    return str_pad($value, $partLength, "\x00", STR_PAD_LEFT);
  };

  return $readInt() . $readInt();
}

function push_jwt(string $audience): array {
  $config = push_vapid_config();
  $header = push_base64url_encode(json_encode([
    'typ' => 'JWT',
    'alg' => 'ES256',
  ], JSON_UNESCAPED_SLASHES));
  $payload = push_base64url_encode(json_encode([
    'aud' => $audience,
    'exp' => time() + 12 * 60 * 60,
    'sub' => $config['subject'],
  ], JSON_UNESCAPED_SLASHES));
  $input = $header . '.' . $payload;

  $privatePem = $config['private_pem'];
  if (!is_string($privatePem) || $privatePem === '') {
    $privateKeyRaw = push_base64url_decode((string)$config['private_key']);
    $res = openssl_pkey_new([
      'private_key_type' => OPENSSL_KEYTYPE_EC,
      'curve_name' => 'prime256v1',
    ]);
    if ($res === false || !openssl_pkey_export($res, $privatePem)) {
      throw new RuntimeException('VAPID private PEM unavailable');
    }
    $details = openssl_pkey_get_details($res);
    if (!is_array($details) || empty($details['ec']['d']) || $details['ec']['d'] !== $privateKeyRaw) {
      throw new RuntimeException('Environment VAPID private key PEM import is not supported in this build');
    }
  }

  if (!openssl_sign($input, $signatureDer, $privatePem, OPENSSL_ALGO_SHA256)) {
    throw new RuntimeException('VAPID sign failed');
  }

  return [
    'token' => $input . '.' . push_base64url_encode(push_der_signature_to_jose($signatureDer)),
    'public_key' => (string)$config['public_key'],
  ];
}

function push_encrypt_payload(string $payloadJson, string $userPublicKeyB64, string $authB64): array {
  $userPublicRaw = push_base64url_decode($userPublicKeyB64);
  $authSecret = push_base64url_decode($authB64);

  $ephemeral = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
  ]);
  if ($ephemeral === false) {
    throw new RuntimeException('Ephemeral key generation failed');
  }
  $ephemeralDetails = openssl_pkey_get_details($ephemeral);
  if (!is_array($ephemeralDetails) || empty($ephemeralDetails['ec']['x']) || empty($ephemeralDetails['ec']['y'])) {
    throw new RuntimeException('Ephemeral key details unavailable');
  }
  $serverPublicRaw = "\x04" . $ephemeralDetails['ec']['x'] . $ephemeralDetails['ec']['y'];

  $sharedSecret = openssl_pkey_derive(push_p256_public_key_to_pem($userPublicRaw), $ephemeral, 32);
  if (!is_string($sharedSecret) || strlen($sharedSecret) !== 32) {
    throw new RuntimeException('ECDH derive failed');
  }

  $keyInfo = "WebPush: info\0" . $userPublicRaw . $serverPublicRaw;
  $prkKey = hash_hmac('sha256', $sharedSecret, $authSecret, true);
  $ikm = push_hkdf_expand($prkKey, $keyInfo, 32);

  $salt = random_bytes(16);
  $prk = hash_hmac('sha256', $ikm, $salt, true);
  $contentEncryptionKey = push_hkdf_expand($prk, "Content-Encoding: aes128gcm\0", 16);
  $nonce = push_hkdf_expand($prk, "Content-Encoding: nonce\0", 12);

  $plaintext = $payloadJson . "\x02";
  $ciphertext = openssl_encrypt($plaintext, 'aes-128-gcm', $contentEncryptionKey, OPENSSL_RAW_DATA, $nonce, $tag);
  if (!is_string($ciphertext) || !is_string($tag)) {
    throw new RuntimeException('Payload encryption failed');
  }

  $recordSize = pack('N', 4096);
  $body = $salt . $recordSize . chr(strlen($serverPublicRaw)) . $serverPublicRaw . $ciphertext . $tag;

  return [
    'body' => $body,
    'content_encoding' => 'aes128gcm',
  ];
}

function push_endpoint_hash(string $endpoint): string {
  return hash('sha256', $endpoint);
}

function push_save_subscription(PDO $pdo, int $userId, int $storeId, array $subscription, string $contentEncoding = 'aes128gcm'): void {
  if (!push_tables_ready($pdo)) {
    throw new RuntimeException('push_subscriptions テーブルが未作成です');
  }

  $endpoint = trim((string)($subscription['endpoint'] ?? ''));
  $p256dh = trim((string)($subscription['keys']['p256dh'] ?? ''));
  $auth = trim((string)($subscription['keys']['auth'] ?? ''));
  if ($userId <= 0 || $storeId <= 0 || $endpoint === '' || $p256dh === '' || $auth === '') {
    throw new InvalidArgumentException('subscription payload is invalid');
  }

  $endpointHash = push_endpoint_hash($endpoint);
  $st = $pdo->prepare("
    INSERT INTO push_subscriptions
      (user_id, store_id, endpoint, endpoint_hash, content_encoding, p256dh_key, auth_key, user_agent, is_active, last_seen_at, created_at, updated_at)
    VALUES
      (:user_id, :store_id, :endpoint, :endpoint_hash, :content_encoding, :p256dh_key, :auth_key, :user_agent, 1, NOW(), NOW(), NOW())
    ON DUPLICATE KEY UPDATE
      user_id = VALUES(user_id),
      store_id = VALUES(store_id),
      content_encoding = VALUES(content_encoding),
      p256dh_key = VALUES(p256dh_key),
      auth_key = VALUES(auth_key),
      user_agent = VALUES(user_agent),
      is_active = 1,
      last_seen_at = NOW(),
      updated_at = NOW()
  ");
  $st->execute([
    ':user_id' => $userId,
    ':store_id' => $storeId,
    ':endpoint' => $endpoint,
    ':endpoint_hash' => $endpointHash,
    ':content_encoding' => $contentEncoding,
    ':p256dh_key' => $p256dh,
    ':auth_key' => $auth,
    ':user_agent' => (string)($_SERVER['HTTP_USER_AGENT'] ?? ''),
  ]);
}

function push_deactivate_subscription(PDO $pdo, string $endpoint): void {
  if ($endpoint === '' || !push_tables_ready($pdo)) {
    return;
  }
  $st = $pdo->prepare("
    UPDATE push_subscriptions
    SET is_active = 0,
        updated_at = NOW()
    WHERE endpoint_hash = ?
  ");
  $st->execute([push_endpoint_hash($endpoint)]);
}

function push_fetch_active_subscriptions(PDO $pdo, array $userIds, int $storeId): array {
  if (!push_tables_ready($pdo) || !$userIds || $storeId <= 0) {
    return [];
  }

  $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn(int $id): bool => $id > 0)));
  if (!$userIds) {
    return [];
  }

  $ph = implode(',', array_fill(0, count($userIds), '?'));
  $sql = "
    SELECT id, user_id, store_id, endpoint, content_encoding, p256dh_key, auth_key
    FROM push_subscriptions
    WHERE is_active = 1
      AND store_id = ?
      AND user_id IN ({$ph})
    ORDER BY id DESC
  ";
  $st = $pdo->prepare($sql);
  $st->execute(array_merge([$storeId], $userIds));
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function push_unread_message_count(PDO $pdo, int $storeId, int $userId): int {
  if ($storeId <= 0 || $userId <= 0) {
    return 0;
  }
  try {
    $st = $pdo->prepare("
      SELECT COUNT(*)
      FROM message_recipients mr
      JOIN messages m ON m.id = mr.message_id
      WHERE mr.recipient_user_id = ?
        AND mr.is_read = 0
        AND m.store_id = ?
    ");
    $st->execute([$userId, $storeId]);
    return (int)($st->fetchColumn() ?: 0);
  } catch (Throwable $e) {
    return 0;
  }
}

function push_store_name(PDO $pdo, int $storeId): string {
  try {
    $st = $pdo->prepare("SELECT name FROM stores WHERE id = ? LIMIT 1");
    $st->execute([$storeId]);
    return (string)($st->fetchColumn() ?: '');
  } catch (Throwable $e) {
    return '';
  }
}

function push_send_to_subscription(PDO $pdo, array $subscription, array $payload): bool {
  $endpoint = trim((string)($subscription['endpoint'] ?? ''));
  $p256dh = trim((string)($subscription['p256dh_key'] ?? ''));
  $auth = trim((string)($subscription['auth_key'] ?? ''));
  if ($endpoint === '' || $p256dh === '' || $auth === '') {
    return false;
  }

  $url = parse_url($endpoint);
  $scheme = (string)($url['scheme'] ?? 'https');
  $host = (string)($url['host'] ?? '');
  if ($host === '') {
    return false;
  }
  $audience = $scheme . '://' . $host;

  $jwt = push_jwt($audience);
  $encrypted = push_encrypt_payload(json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $p256dh, $auth);

  $headers = [
    'TTL: 60',
    'Content-Type: application/octet-stream',
    'Content-Encoding: ' . $encrypted['content_encoding'],
    'Authorization: vapid t=' . $jwt['token'] . ', k=' . $jwt['public_key'],
  ];

  $ch = curl_init($endpoint);
  curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => $headers,
    CURLOPT_POSTFIELDS => $encrypted['body'],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_HEADER => false,
  ]);
  $response = curl_exec($ch);
  $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
  $curlError = curl_error($ch);
  curl_close($ch);

  if ($code === 404 || $code === 410) {
    push_deactivate_subscription($pdo, $endpoint);
    return false;
  }

  return $curlError === '' && $code >= 200 && $code < 300;
}

function push_notify_unread_message(PDO $pdo, int $storeId, int $senderUserId, array $recipientUserIds, string $kind, string $title, string $body): void {
  if (!push_tables_ready($pdo) || $storeId <= 0 || !$recipientUserIds) {
    return;
  }

  $recipientUserIds = array_values(array_unique(array_filter(array_map('intval', $recipientUserIds), static fn(int $id): bool => $id > 0)));
  if (!$recipientUserIds) {
    return;
  }

  $senderName = '';
  try {
    $stSender = $pdo->prepare("SELECT COALESCE(NULLIF(TRIM(display_name), ''), login_id, CONCAT('user#', id)) FROM users WHERE id = ? LIMIT 1");
    $stSender->execute([$senderUserId]);
    $senderName = (string)($stSender->fetchColumn() ?: '');
  } catch (Throwable $e) {
    $senderName = '';
  }

  $storeName = push_store_name($pdo, $storeId);
  $subscriptions = push_fetch_active_subscriptions($pdo, $recipientUserIds, $storeId);
  if (!$subscriptions) {
    return;
  }

  foreach ($subscriptions as $subscription) {
    $recipientUserId = (int)($subscription['user_id'] ?? 0);
    $unreadCount = push_unread_message_count($pdo, $storeId, $recipientUserId);
    $payload = [
      'title' => $kind === 'thanks' ? 'ありがとうカードが届きました' : '新しい業務メッセージ',
      'body' => trim($senderName . ' / ' . ($title !== '' ? $title : mb_substr($body, 0, 40))),
      'url' => ($kind === 'thanks' ? '/wbss/public/thanks.php' : '/wbss/public/messages.php') . '?store_id=' . $storeId,
      'badgeCount' => $unreadCount,
      'storeName' => $storeName,
      'kind' => $kind,
    ];
    try {
      push_send_to_subscription($pdo, $subscription, $payload);
    } catch (Throwable $e) {
      // best effort
    }
  }
}
