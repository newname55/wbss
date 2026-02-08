<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';

require_login();
$pdo = db();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

/* =========================
   role 判定（session roles）
========================= */
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && is_array($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

/* =========================
   このユーザーがアクセス可能な店舗一覧を取る
   - store_id NULL の全店権限は「店舗未確定」として扱う
========================= */
function fetch_accessible_store_ids(PDO $pdo, int $userId): array {
  $st = $pdo->prepare("
    SELECT DISTINCT ur.store_id
    FROM user_roles ur
    WHERE ur.user_id = ?
      AND ur.store_id IS NOT NULL
    ORDER BY ur.store_id ASC
  ");
  $st->execute([$userId]);
  $rows = $st->fetchAll(PDO::FETCH_COLUMN);
  $ids = [];
  foreach ($rows as $v) {
    $i = (int)$v;
    if ($i > 0) $ids[] = $i;
  }
  return $ids;
}

/* =========================
   current_store_id をセット
========================= */
function set_current_store(PDO $pdo, int $userId): void {
  // すでに選択済みならOK
  if (!empty($_SESSION['current_store_id']) && (int)$_SESSION['current_store_id'] > 0) return;

  $ids = fetch_accessible_store_ids($pdo, $userId);

  // 店舗が1つなら自動確定
  if (count($ids) === 1) {
    $_SESSION['current_store_id'] = $ids[0];
    return;
  }

  // 複数あるなら選択画面へ
  if (count($ids) >= 2) {
    header('Location: /seika-app/public/store_select.php');
    exit;
  }

  // ここまで来た = store_id が1件も無い
  // 全店管理者（super_user / 全店admin）なら、店舗未確定のまま dashboard へ通す
  if (has_role('super_user') || has_role('admin')) {
    // 何もしない（store_select を出したいならここで飛ばす）
    return;
  }

  // それ以外はエラーにする
  throw new RuntimeException('所属店舗が見つかりません（店舗割当が必要です）');
}

/* =========================
   実行 → ダッシュボードへ
========================= */
try {
  $userId = current_user_id();
  set_current_store($pdo, $userId);
  header('Location: /seika-app/public/dashboard.php');
  exit;
} catch (Throwable $e) {
  http_response_code(400);
  echo 'ログイン後の初期化に失敗しました: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
  exit;
}