<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_messages.php';

require_login();
require_role(['cast', 'admin', 'manager', 'super_user']);

$pdo = db();
$userId = message_current_user_id();
$storeId = message_resolve_store_id($pdo, (int)($_GET['store_id'] ?? 0));
$storeName = message_store_name($pdo, $storeId);
$rows = [];
$sentRows = [];
$err = '';
$marked = 0;
$sent = ((int)($_GET['sent'] ?? 0) === 1);

try {
  if (!message_tables_ready($pdo)) {
    throw new RuntimeException('messages / message_recipients テーブルを先に作成してください');
  }

  $rows = message_fetch_inbox($pdo, $storeId, $userId, 'normal', 100);
  $sentRows = message_fetch_sent_history($pdo, $storeId, $userId, 'normal', 30);
  $marked = message_mark_kind_as_read($pdo, $storeId, $userId, 'normal');
} catch (Throwable $e) {
  $err = $e->getMessage();
}

render_page_start('業務メッセージ');
render_header('業務メッセージ', [
  'back_href' => message_dashboard_path(),
  'back_label' => '← ダッシュボード',
  'right_html' => $storeId > 0 ? '<a class="btn btn-primary" href="/wbss/public/message_send.php?store_id=' . (int)$storeId . '&kind=normal">新規送信</a>' : '',
]);
?>
<div class="page">
  <div class="admin-wrap msg-shell">
    <div data-badge-sync data-store-id="<?= (int)$storeId ?>" data-unread-count="0" hidden></div>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:rgba(239,68,68,.45);"><?= h($err) ?></div>
    <?php else: ?>
      <?php if ($sent): ?>
        <div class="card noticeOk">業務連絡を送信しました。</div>
      <?php endif; ?>
      <?php if ($marked > 0): ?>
        <div class="card noticeOk">未読メッセージ <?= (int)$marked ?> 件を既読にしました。</div>
      <?php endif; ?>

      <div class="card msg-head">
        <div>
          <div class="msg-title">📨 自分宛の業務連絡</div>
          <div class="muted" style="margin-top:4px;">
            店舗：<b><?= h($storeName !== '' ? $storeName : '-') ?></b><?php if ($storeId > 0): ?> (#<?= (int)$storeId ?>)<?php endif; ?>
          </div>
        </div>
        <?php if ($storeId > 0): ?>
          <div class="msg-actions">
            <a class="btn" href="/wbss/public/thanks.php?store_id=<?= (int)$storeId ?>">ありがとう一覧</a>
            <a class="btn btn-primary" href="/wbss/public/message_send.php?store_id=<?= (int)$storeId ?>&kind=normal">送る</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$rows): ?>
        <div class="card">
          <div style="font-weight:900;">受信メッセージはまだありません。</div>
          <div class="muted" style="margin-top:6px;">新しい業務連絡が来るとここに表示されます。</div>
        </div>
      <?php endif; ?>

      <?php foreach ($rows as $row): ?>
        <article class="card msg-card <?= ((int)($row['is_read'] ?? 0) === 1) ? 'is-read' : 'is-unread' ?>">
          <div class="msg-card-top">
            <div>
              <div class="msg-card-title">
                <?= h(trim((string)($row['title'] ?? '')) !== '' ? (string)$row['title'] : '件名なし') ?>
              </div>
              <div class="muted" style="margin-top:4px;">
                送信者：<b><?= h((string)($row['sender_name'] ?? '')) ?></b>
              </div>
            </div>
            <div class="msg-card-side">
              <?php if ((int)($row['is_read'] ?? 0) === 0): ?>
                <span class="pill unread">未読</span>
              <?php else: ?>
                <span class="pill read">既読</span>
              <?php endif; ?>
              <div class="muted mono"><?= h((string)($row['created_at'] ?? '')) ?></div>
            </div>
          </div>
          <div class="msg-body"><?= nl2br(h((string)($row['body'] ?? ''))) ?></div>
        </article>
      <?php endforeach; ?>

      <div class="card msg-head" style="margin-top:16px;">
        <div>
          <div class="msg-title">🗂 送信履歴</div>
          <div class="muted" style="margin-top:4px;">自分が送った業務連絡です。</div>
        </div>
      </div>

      <?php if (!$sentRows): ?>
        <div class="card">
          <div style="font-weight:900;">送信履歴はまだありません。</div>
        </div>
      <?php endif; ?>

      <?php foreach ($sentRows as $row): ?>
        <?php
          $resendQs = http_build_query([
            'store_id' => $storeId,
            'kind' => 'normal',
            'recipient_user_ids' => (string)($row['recipient_user_ids'] ?? ''),
          ]);
        ?>
        <article class="card msg-card is-read">
          <div class="msg-card-top">
            <div>
              <div class="msg-card-title">
                <?= h(trim((string)($row['title'] ?? '')) !== '' ? (string)$row['title'] : '件名なし') ?>
              </div>
              <div class="muted" style="margin-top:4px;">
                宛先：<b><?= h((string)($row['recipient_names'] ?? '')) ?></b>
                <?php if ((int)($row['recipient_count'] ?? 0) > 1): ?>
                  / <?= (int)($row['recipient_count'] ?? 0) ?>人
                <?php endif; ?>
              </div>
            </div>
            <div class="msg-card-side">
              <span class="pill read">既読 <?= (int)($row['read_count'] ?? 0) ?>/<?= (int)($row['recipient_count'] ?? 0) ?></span>
              <div class="muted mono"><?= h((string)($row['created_at'] ?? '')) ?></div>
              <a class="btn" href="/wbss/public/message_send.php?<?= h($resendQs) ?>">同じ相手へ再送</a>
            </div>
          </div>
          <div class="msg-body"><?= nl2br(h((string)($row['body'] ?? ''))) ?></div>
        </article>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>
</div>

<style>
.msg-shell{max-width:920px}
.msg-head,.msg-card{margin-top:12px}
.msg-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.msg-title{font-size:18px;font-weight:1000}
.msg-actions{display:flex;gap:8px;flex-wrap:wrap}
.msg-card-top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.msg-card-title{font-size:17px;font-weight:1000;line-height:1.35}
.msg-card-side{display:flex;flex-direction:column;gap:8px;align-items:flex-end}
.msg-body{margin-top:12px;line-height:1.7;white-space:normal;word-break:break-word}
.pill{display:inline-flex;align-items:center;justify-content:center;padding:4px 9px;border-radius:999px;font-size:11px;font-weight:900}
.pill.unread{background:rgba(59,130,246,.18);border:1px solid rgba(59,130,246,.35)}
.pill.read{background:rgba(148,163,184,.16);border:1px solid rgba(148,163,184,.28)}
.noticeOk{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10);margin-top:12px}
.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
.muted{opacity:.75;font-size:12px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none}
.btn-primary{border-color:transparent;background:linear-gradient(135deg, rgba(96,165,250,.95), rgba(167,139,250,.85));color:#fff}
@media (max-width:640px){
  .msg-card-side{align-items:flex-start}
  .msg-actions{width:100%}
  .msg-actions .btn{flex:1}
}
</style>
<script src="/wbss/public/assets/js/push_notifications.js?v=20260320b"></script>
<?php render_page_end(); ?>
