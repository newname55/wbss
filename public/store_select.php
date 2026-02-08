<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store.php';

ensure_session();
require_login();

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/* CSRF */
if (!function_exists('csrf_token')) {
  function csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['_csrf'])) $_SESSION['_csrf'] = bin2hex(random_bytes(16));
    return (string)$_SESSION['_csrf'];
  }
}
function verify_csrf(): void {
  $t = (string)($_POST['csrf_token'] ?? '');
  if ($t === '' || !hash_equals((string)($_SESSION['_csrf'] ?? ''), $t)) {
    http_response_code(400);
    exit('Bad Request (csrf)');
  }
}

$pdo = db();
$uid = (int)($_SESSION['user_id'] ?? 0);
$msg = '';
$err = '';

$next = (string)($_GET['next'] ?? '/seika-app/public/dashboard.php');

/* POST: 選択 */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  verify_csrf();
  $sid = (int)($_POST['store_id'] ?? 0);
  if ($sid <= 0) {
    $err = '店舗を選択してください';
  } else {
    // アクセス可能か確認
    $isAdmin = function_exists('is_role') ? (is_role('super_user') || is_role('admin')) : false;

    if ($isAdmin) {
      $ok = (bool)$pdo->prepare("SELECT 1 FROM stores WHERE id=? AND is_active=1 LIMIT 1")
                      ->execute([$sid]) ?: true;
    } else {
      $st = $pdo->prepare("
        SELECT 1
        FROM stores s
        WHERE s.id=? AND s.is_active=1
          AND EXISTS (
            SELECT 1
            FROM user_roles ur
            WHERE ur.user_id=?
              AND (ur.store_id = s.id OR ur.store_id IS NULL)
          )
        LIMIT 1
      ");
      $st->execute([$sid, $uid]);
      $ok = (bool)$st->fetchColumn();
    }

    if (!$ok) {
      $err = 'この店舗への権限がありません';
    } else {
      // store.php 側に setter があるなら使う（無ければセッション直書き）
      if (function_exists('set_current_store_id')) {
        set_current_store_id($sid);
      } else {
        $_SESSION['store_id'] = $sid;
      }
      header('Location: ' . $next);
      exit;
    }
  }
}

/* 店舗一覧 */
$isAdmin = function_exists('is_role') ? (is_role('super_user') || is_role('admin')) : false;

if ($isAdmin) {
  $stores = $pdo->query("SELECT id,name,is_active FROM stores WHERE is_active=1 ORDER BY id")->fetchAll();
} else {
  $st = $pdo->prepare("
    SELECT s.id, s.name, s.is_active
    FROM stores s
    WHERE s.is_active=1
      AND EXISTS (
        SELECT 1
        FROM user_roles ur
        WHERE ur.user_id=?
          AND (ur.store_id = s.id OR ur.store_id IS NULL)
      )
    ORDER BY s.id
  ");
  $st->execute([$uid]);
  $stores = $st->fetchAll();
}

render_page_start('店舗選択');
render_header('店舗選択', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← 戻る',
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>
  <div class="card">
    <div style="font-weight:1000; font-size:16px;">入る店舗を選んでください</div>
    <div class="muted" style="margin-top:6px;">
      このユーザーが権限を持つ店舗だけ表示します（応援・兼務もOK）。
    </div>
<?php if (is_role('admin') || is_role('super_user')): ?>
  <a class="btn" href="/seika-app/public/admin/index.php">管理</a>
<?php endif; ?>
    <form method="post" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="next" value="<?= h($next) ?>">

      <div style="display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:10px; margin-top:10px;">
        <?php foreach ($stores as $s): ?>
          <button class="btn" type="submit" name="store_id" value="<?= (int)$s['id'] ?>" style="justify-content:flex-start;">
            🏪 <?= h((string)$s['name']) ?> <span class="muted" style="margin-left:auto;">#<?= (int)$s['id'] ?></span>
          </button>
        <?php endforeach; ?>
      </div>

      <?php if (!$stores): ?>
        <div class="muted" style="margin-top:12px;">権限のある店舗がありません（管理者に権限付与を依頼してください）</div>
      <?php endif; ?>
    </form>
  </div>

</div>
<?php render_page_end(); ?>