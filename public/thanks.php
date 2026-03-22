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
$monthlyThanksCount = 0;

try {
  if (!message_tables_ready($pdo)) {
    throw new RuntimeException('messages / message_recipients テーブルを先に作成してください');
  }

  $rows = message_fetch_inbox($pdo, $storeId, $userId, 'thanks', 100);
  $sentRows = message_fetch_sent_history($pdo, $storeId, $userId, 'thanks', 30);
  $marked = message_mark_kind_as_read($pdo, $storeId, $userId, 'thanks');
  $summary = message_fetch_dashboard_summary($pdo, $storeId, $userId, 3);
  $monthlyThanksCount = (int)($summary['monthly_thanks_count'] ?? 0);
} catch (Throwable $e) {
  $err = $e->getMessage();
}

render_page_start('ありがとう一覧');
render_header('ありがとう一覧', [
  'back_href' => message_dashboard_path(),
  'back_label' => '← ダッシュボード',
  'right_html' => $storeId > 0 ? '<a class="btn btn-primary" href="/wbss/public/message_send.php?store_id=' . (int)$storeId . '&kind=thanks">ありがとうを送る</a>' : '',
]);
?>
<div class="page">
  <div class="admin-wrap thanks-shell">
    <div data-badge-sync data-store-id="<?= (int)$storeId ?>" data-unread-count="0" hidden></div>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:rgba(239,68,68,.45);"><?= h($err) ?></div>
    <?php else: ?>
      <?php if ($sent): ?>
        <div class="card noticeOk">ありがとうカードを送信しました。</div>
      <?php endif; ?>
      <?php if ($marked > 0): ?>
        <div class="card noticeOk">最近のありがとう <?= (int)$marked ?> 件を開きました。</div>
      <?php endif; ?>

      <div class="card thanks-head">
        <div>
          <div class="thanks-title">💐 最近受け取ったありがとう</div>
          <div class="muted" style="margin-top:4px;">
            店舗：<b><?= h($storeName !== '' ? $storeName : '-') ?></b><?php if ($storeId > 0): ?> (#<?= (int)$storeId ?>)<?php endif; ?>
          </div>
          <div class="thanks-monthly">今月受け取ったありがとう <strong><?= (int)$monthlyThanksCount ?></strong> 件</div>
        </div>
        <?php if ($storeId > 0): ?>
          <div class="thanks-actions">
            <a class="btn" href="/wbss/public/messages.php?store_id=<?= (int)$storeId ?>">業務連絡</a>
            <a class="btn btn-primary" href="/wbss/public/message_send.php?store_id=<?= (int)$storeId ?>&kind=thanks">送る</a>
          </div>
        <?php endif; ?>
      </div>

      <?php if (!$rows): ?>
        <div class="card">
          <div style="font-weight:900;">まだありがとうカードは届いていません。</div>
          <div class="muted" style="margin-top:6px;">感謝を受け取るとここに新しい順で並びます。</div>
        </div>
      <?php endif; ?>

      <div class="thanks-grid">
        <?php foreach ($rows as $row): ?>
          <article class="card thanks-card">
            <div class="thanks-card-top">
              <span class="thanks-badge">THANKS</span>
              <div class="muted mono"><?= h((string)($row['created_at'] ?? '')) ?></div>
            </div>
            <div class="thanks-card-title">
              <?= h(trim((string)($row['title'] ?? '')) !== '' ? (string)$row['title'] : 'ありがとう') ?>
            </div>
            <div class="thanks-from">from <?= h((string)($row['sender_name'] ?? '')) ?></div>
            <div class="thanks-body"><?= nl2br(h((string)($row['body'] ?? ''))) ?></div>
          </article>
        <?php endforeach; ?>
      </div>

      <div class="card thanks-head" style="margin-top:16px;">
        <div>
          <div class="thanks-title">🗂 送信したありがとう</div>
          <div class="muted" style="margin-top:4px;">自分が送った感謝カードです。</div>
        </div>
      </div>

      <?php if (!$sentRows): ?>
        <div class="card">
          <div style="font-weight:900;">送信履歴はまだありません。</div>
        </div>
      <?php endif; ?>

      <div class="thanks-grid">
        <?php foreach ($sentRows as $row): ?>
          <?php
            $resendQs = http_build_query([
              'store_id' => $storeId,
              'kind' => 'thanks',
              'recipient_user_ids' => (string)($row['recipient_user_ids'] ?? ''),
            ]);
          ?>
          <article class="card thanks-card">
            <div class="thanks-card-top">
              <span class="thanks-badge">SENT</span>
              <div class="muted mono"><?= h((string)($row['created_at'] ?? '')) ?></div>
            </div>
            <div class="thanks-card-title">
              <?= h(trim((string)($row['title'] ?? '')) !== '' ? (string)$row['title'] : 'ありがとう') ?>
            </div>
            <div class="thanks-from">to <?= h((string)($row['recipient_names'] ?? '')) ?></div>
            <div class="muted" style="margin-top:6px;">
              既読 <?= (int)($row['read_count'] ?? 0) ?>/<?= (int)($row['recipient_count'] ?? 0) ?>
            </div>
            <div class="thanks-body"><?= nl2br(h((string)($row['body'] ?? ''))) ?></div>
            <div style="margin-top:10px;">
              <a class="btn" href="/wbss/public/message_send.php?<?= h($resendQs) ?>">同じ相手へ再送</a>
            </div>
          </article>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.thanks-shell{max-width:980px}
.thanks-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-top:12px}
.thanks-title{font-size:18px;font-weight:1000}
.thanks-monthly{margin-top:8px;font-size:13px;font-weight:800}
.thanks-monthly strong{font-size:20px}
.thanks-actions{display:flex;gap:8px;flex-wrap:wrap}
.thanks-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(250px,1fr));gap:12px;margin-top:12px}
.thanks-card{background:linear-gradient(180deg, rgba(250,204,21,.14), rgba(251,146,60,.07));border-color:rgba(251,191,36,.32)}
.thanks-card-top{display:flex;justify-content:space-between;gap:8px;align-items:center}
.thanks-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;background:rgba(255,255,255,.55);color:#7c2d12;font-size:11px;font-weight:1000;letter-spacing:.08em}
.thanks-card-title{margin-top:12px;font-size:17px;font-weight:1000;line-height:1.35}
.thanks-from{margin-top:6px;font-size:13px;font-weight:800}
.thanks-body{margin-top:12px;line-height:1.7;word-break:break-word}
.noticeOk{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10);margin-top:12px}
.mono{font-family:ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace}
.muted{opacity:.75;font-size:12px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none}
.btn-primary{border-color:transparent;background:linear-gradient(135deg, #f59e0b, #fb7185);color:#fff}
@media (max-width:640px){
  .thanks-actions{width:100%}
  .thanks-actions .btn{flex:1}
}
</style>
<script src="/wbss/public/assets/js/push_notifications.js?v=20260320b"></script>
<?php render_page_end(); ?>
