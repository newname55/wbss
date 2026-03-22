<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/bootstrap.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/service_store_casts.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$pdo = db();
$err = trim((string)($_GET['error'] ?? ''));
$showInvite = null;
$invites = [];
$stores = [];
$storeName = '';

try {
  $stores = store_access_allowed_stores($pdo);
  $storeId = store_access_resolve_manageable_store_id($pdo, (int)($_GET['store_id'] ?? 0));
  $storeName = store_access_find_store_name($stores, $storeId);
  $invites = service_list_store_invites($pdo, $storeId);

  $showInviteId = (int)($_GET['show_invite_id'] ?? 0);
  if ($showInviteId > 0) {
    $showInvite = service_find_store_invite($pdo, $storeId, $showInviteId);
  }
} catch (Throwable $e) {
  $storeId = 0;
  $err = $err !== '' ? $err : $e->getMessage();
}

$inviteRaw = trim((string)($_GET['invite'] ?? ''));

render_page_start('招待リンク管理');
render_header('招待リンク管理', [
  'back_href' => $storeId > 0
    ? '/wbss/public/store_casts.php?store_id=' . $storeId
    : '/wbss/public/store_casts.php',
  'back_label' => '← キャスト一覧',
]);
?>
<div class="page">
  <div class="admin-wrap">
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:#ef4444"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="rowTop">
      <div>
        <div class="title">🔗 招待リンク管理</div>
        <div class="muted" style="margin-top:4px;">
          店舗：<b><?= h($storeName !== '' ? $storeName : '-') ?><?php if ($storeId > 0): ?> (#<?= (int)$storeId ?>)<?php endif; ?></b>
        </div>
      </div>

      <div class="rowBtns">
        <?php if (count($stores) > 1): ?>
          <form method="get">
            <select class="btn" name="store_id" onchange="this.form.submit()">
              <?php foreach ($stores as $store): ?>
                <?php $sid = (int)($store['id'] ?? 0); ?>
                <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
                  <?= h((string)($store['name'] ?? '')) ?> (#<?= $sid ?>)
                </option>
              <?php endforeach; ?>
            </select>
          </form>
        <?php endif; ?>
        <?php if ($storeId > 0): ?>
          <a class="btn" href="/wbss/public/store_casts.php?store_id=<?= (int)$storeId ?>">キャスト一覧へ</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($inviteRaw !== ''): ?>
      <div class="card" style="border-color:#22c55e; margin-top:12px;">
        <b>🔍 発行した招待リンク</b>
        <div class="muted" style="word-break:break-all;margin-top:6px">
          <?= h('/wbss/public/line_login_start.php?invite=' . $inviteRaw) ?>
        </div>
      </div>
    <?php endif; ?>

    <div class="card" style="margin-top:12px;">
      <h3>招待リンク発行</h3>
      <form method="post" action="/wbss/public/store_casts_invite_create.php" class="searchRow">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <label>期限
          <input class="btn" name="expires_at" placeholder="未入力で7日">
        </label>
        <button class="btn btn-primary" <?= $storeId <= 0 ? 'disabled' : '' ?>>発行</button>
      </form>
    </div>

    <div class="card">
      <h3>招待リンク一覧</h3>
      <table class="tbl">
        <tr><th>作成</th><th>期限</th><th>状態</th><th></th></tr>
        <?php foreach ($invites as $invite): ?>
          <tr>
            <td><?= h((string)($invite['created_at'] ?? '')) ?></td>
            <td><?= h((string)($invite['expires_at'] ?? '')) ?></td>
            <td>
              <?= !(int)($invite['is_active'] ?? 0) ? '使用済'
                : (((string)($invite['expires_at'] ?? '') < date('Y-m-d H:i:s')) ? '期限切れ' : '有効') ?>
            </td>
            <td>
              <a class="btn" href="/wbss/public/store_casts_invites.php?store_id=<?= (int)$storeId ?>&show_invite_id=<?= (int)($invite['id'] ?? 0) ?>">表示</a>
            </td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>

    <?php if ($showInvite): ?>
      <div class="card" style="border-color:#22c55e">
        <b>招待リンク詳細</b>
        <div class="muted" style="word-break:break-all;margin-top:6px">
          <?= h('/wbss/public/line_login_start.php?invite=' . (string)$showInvite['token']) ?>
        </div>
        <div style="margin-top:8px;display:flex;gap:8px;flex-wrap:wrap;">
          <a class="btn" target="_blank" href="/wbss/public/line_login_start.php?invite=<?= h((string)$showInvite['token']) ?>">開く</a>
          <a class="btn" target="_blank" href="/wbss/public/print_invite_qr.php?invite=<?= h((string)$showInvite['token']) ?>">QR</a>
        </div>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.rowTop{ display:flex; align-items:flex-start; justify-content:space-between; gap:12px; flex-wrap:wrap; }
.rowBtns{ display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.title{ font-weight:1000; font-size:18px; line-height:1.2; }
.btn{
  display:inline-flex; align-items:center; gap:6px;
  padding:10px 14px; border-radius:12px;
  border:1px solid var(--line);
  background:var(--cardA); color:inherit;
  text-decoration:none; cursor:pointer;
}
.muted{ opacity:.75; font-size:12px; }
.tbl{ width:100%; border-collapse:separate; border-spacing:0; }
.tbl th, .tbl td{ padding:10px 12px; border-bottom:1px solid var(--line); vertical-align:top; text-align:left; }
.tbl th{ font-size:12px; opacity:.8; }
</style>
<?php render_page_end(); ?>
