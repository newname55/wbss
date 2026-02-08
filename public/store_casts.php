<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['admin','super_user']);

$pdo = db();

/* =========================
   helper
========================= */
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}

function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

function current_admin_store_id(PDO $pdo, int $userId): int {
  $st = $pdo->prepare("
    SELECT ur.store_id
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    WHERE ur.user_id = ?
      AND r.code = 'admin'
      AND ur.store_id IS NOT NULL
    LIMIT 1
  ");
  $st->execute([$userId]);
  $sid = $st->fetchColumn();
  if (!$sid) throw new RuntimeException('管理店舗が設定されていません');
  return (int)$sid;
}

/* =========================
   権限 / 店舗確定
========================= */
$isSuper = has_role('super_user');

if ($isSuper) {
  $storeId = (int)($_GET['store_id'] ?? 0);
  if ($storeId <= 0) {
    $storeId = (int)$pdo->query("
      SELECT id FROM stores WHERE is_active=1 ORDER BY id ASC LIMIT 1
    ")->fetchColumn();
    if ($storeId <= 0) throw new RuntimeException('有効な店舗がありません');
  }
} else {
  $storeId = current_admin_store_id($pdo, current_user_id());
}

/* =========================
   マスタ
========================= */
$stores = $isSuper
  ? $pdo->query("SELECT id,name FROM stores WHERE is_active=1 ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC)
  : [];

/* =========================
   キャスト一覧
========================= */
$st = $pdo->prepare("
  SELECT
    u.id, u.display_name,
    MAX(CASE WHEN ui.provider='line' AND ui.is_active=1 THEN 1 ELSE 0 END) AS has_line
  FROM user_roles ur
  JOIN users u ON u.id=ur.user_id
  JOIN roles r ON r.id=ur.role_id AND r.code='cast'
  LEFT JOIN user_identities ui ON ui.user_id=u.id
  WHERE ur.store_id=?
  GROUP BY u.id
  ORDER BY u.id DESC
");
$st->execute([$storeId]);
$casts = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   招待リンク一覧
========================= */
$st = $pdo->prepare("
  SELECT *
  FROM invite_tokens
  WHERE store_id=?
  ORDER BY created_at DESC
  LIMIT 50
");
$st->execute([$storeId]);
$invites = $st->fetchAll(PDO::FETCH_ASSOC);

/* =========================
   POST
========================= */
$msg=''; $err='';

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try{
    if (($_POST['action'] ?? '') === 'create_invite') {

      $expires = trim((string)($_POST['expires_at'] ?? ''));
      if ($expires === '') {
        $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
      }

      $raw  = rtrim(strtr(base64_encode(random_bytes(24)),'+/','-_'),'=');
      $hash = hash('sha256',$raw);

      $pdo->prepare("
        INSERT INTO invite_tokens
          (token, token_hash, store_id, invite_type,
           created_by_user_id, created_at, expires_at, is_active)
        VALUES
          (?, ?, ?, 'cast', ?, NOW(), ?, 1)
      ")->execute([
        $raw, $hash, $storeId, current_user_id(), $expires
      ]);

      header('Location: store_casts.php?store_id='.$storeId.'&invite='.$raw);
      exit;
    }
  } catch(Throwable $e){
    $err = $e->getMessage();
  }
}

/* =========================
   表示
========================= */
render_page_start('店別キャスト管理');
render_header('店別キャスト管理',[
  'back_href'=>'/seika-app/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);

$inviteRaw = (string)($_GET['invite'] ?? '');
$showInviteId = (int)($_GET['show_invite_id'] ?? 0);
$showInvite = null;

if ($showInviteId > 0) {
  $st = $pdo->prepare("SELECT * FROM invite_tokens WHERE id=? AND store_id=?");
  $st->execute([$showInviteId,$storeId]);
  $showInvite = $st->fetch(PDO::FETCH_ASSOC);
}
?>
<div class="page">
<div class="admin-wrap">

<?php if($inviteRaw): ?>
<div class="card" style="border-color:#22c55e">
  <b>🔗 招待リンク</b>
  <div class="muted" style="word-break:break-all;margin-top:6px">
    <?=h('/seika-app/public/line_login_start.php?invite='.$inviteRaw)?>
  </div>
</div>
<?php endif; ?>

<?php if($err): ?><div class="card" style="border-color:#ef4444"><?=h($err)?></div><?php endif; ?>

<div class="card">
<h3>🔗 招待リンク発行</h3>
<form method="post" class="searchRow">
<input type="hidden" name="action" value="create_invite">
<label>期限
  <input class="btn" name="expires_at" placeholder="未入力で7日">
</label>
<button class="btn btn-primary">発行</button>
</form>
</div>

<div class="card">
<h3>📜 招待リンク一覧</h3>
<table class="tbl">
<tr><th>作成</th><th>期限</th><th>状態</th><th></th></tr>
<?php foreach($invites as $i): ?>
<tr>
<td><?=h($i['created_at'])?></td>
<td><?=h($i['expires_at'])?></td>
<td>
<?= !$i['is_active'] ? '使用済'
   : ($i['expires_at']<date('Y-m-d H:i:s') ? '期限切れ':'有効') ?>
</td>
<td>
<a class="btn"
 href="store_casts.php?store_id=<?=$storeId?>&show_invite_id=<?=$i['id']?>">表示</a>
</td>
</tr>
<?php endforeach; ?>
</table>
</div>

<?php if($showInvite): ?>
<div class="card" style="border-color:#22c55e">
  <b>🔍 招待リンク詳細</b>
  <div class="muted" style="word-break:break-all;margin-top:6px">
    <?=h('/seika-app/public/line_login_start.php?invite='.$showInvite['token'])?>
  </div>
  <div style="margin-top:8px;display:flex;gap:8px">
    <a class="btn" target="_blank"
       href="/seika-app/public/line_login_start.php?invite=<?=h($showInvite['token'])?>">
       開く
    </a>
    <a class="btn" target="_blank"
       href="/seika-app/public/print_invite_qr.php?invite=<?=h($showInvite['token'])?>">
       🖨 QR
    </a>
  </div>
</div>
<?php endif; ?>

<div class="card">
<h3>👥 キャスト一覧</h3>
<table class="tbl">
<tr><th>名前</th><th>LINE</th></tr>
<?php foreach($casts as $c): ?>
<tr>
<td><?=h($c['display_name'])?></td>
<td><?= $c['has_line'] ? 'OK' : '⚠ 未連携' ?></td>
</tr>
<?php endforeach; ?>
</table>
</div>

</div>
</div>
<?php render_page_end(); ?>