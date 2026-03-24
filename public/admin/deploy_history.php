<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/deploy.php';

require_login();
require_role(['super_user', 'admin']);

$pdo = db();
$logs = deploy_enrich_log_rows(deploy_fetch_logs($pdo, 200));
$prodSuccessLogs = deploy_fetch_prod_success_logs($pdo, 200);
$prodHead = null;
$prodHeadError = '';
$eligibleTarget = null;
$eligibleTargetHash = '';
$githubCompareRepo = deploy_github_compare_repo();

try {
  $prodHead = deploy_get_current_prod_head();
  $eligibleTarget = deploy_find_eligible_rollback_target($prodSuccessLogs, (string)$prodHead['full']);
  $eligibleTargetHash = strtolower((string)($eligibleTarget['after_hash'] ?? ''));
} catch (Throwable $e) {
  $prodHeadError = $e->getMessage();
}

render_page_start('本番 deploy / rollback');
render_header('本番 deploy / rollback', [
  'back_href' => '/wbss/public/admin/index.php',
  'back_label' => '← 管理ランチャー',
  'show_store' => false,
]);
?>
<div class="page">
  <div class="deploy-summary">
    <div class="deploy-summary__item is-current">
      <div class="deploy-summary__label">現在本番</div>
      <div class="deploy-summary__value">
        <?= $prodHead !== null ? h((string)$prodHead['short']) : '取得失敗' ?>
      </div>
    </div>
    <div class="deploy-summary__item is-target">
      <div class="deploy-summary__label">rollback対象</div>
      <div class="deploy-summary__value">
        <?= $eligibleTargetHash !== '' ? h(substr($eligibleTargetHash, 0, 12)) : 'なし' ?>
      </div>
    </div>
    <div class="deploy-summary__item">
      <div class="deploy-summary__label">権限</div>
      <div class="deploy-summary__value">admin / super_user</div>
    </div>
  </div>

  <div class="card">
    <div class="deploy-head">
      <div>
        <div class="deploy-title">本番 deploy 履歴</div>
        <div class="muted deploy-lead">prod success の中から「現在の本番の1つ前」だけ rollback できます。</div>
      </div>
      <div class="deploy-meta">
        <?php if ($githubCompareRepo !== ''): ?>
          <span class="pill"><span class="pill__label">GitHub</span><span class="pill__value"><?= h($githubCompareRepo) ?></span></span>
        <?php endif; ?>
        <?php if (!empty($eligibleTarget['created_at'])): ?>
          <span class="pill"><span class="pill__label">対象時刻</span><span class="pill__value"><?= h((string)$eligibleTarget['created_at']) ?></span></span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($prodHeadError !== ''): ?>
      <div class="notice notice-error">本番HEADを取得できなかったため rollback は無効です。<?= h($prodHeadError) ?></div>
    <?php elseif ($eligibleTargetHash === ''): ?>
      <div class="notice">rollback 可能な直前履歴が見つかりませんでした。</div>
    <?php else: ?>
      <div class="notice notice-ok">
        rollback 対象: <?= h(substr($eligibleTargetHash, 0, 12)) ?>
        <?php if (!empty($eligibleTarget['created_at'])): ?>
          / <?= h((string)$eligibleTarget['created_at']) ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <div class="deploy-table-wrap">
      <table class="deploy-table">
        <thead>
          <tr>
            <th>日時</th>
            <th>env</th>
            <th>before</th>
            <th>after</th>
            <th>status</th>
            <th>差分</th>
            <th>executed_by</th>
            <th>detail</th>
            <th>操作</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$logs): ?>
            <tr>
              <td colspan="9" class="muted">deploy_logs はまだありません。</td>
            </tr>
          <?php endif; ?>
          <?php foreach ($logs as $row): ?>
            <?php
              $environment = (string)($row['environment'] ?? '');
              $beforeCommit = (string)($row['before_commit'] ?? '');
              $afterCommit = (string)($row['after_commit'] ?? '');
              $beforeHash = strtolower((string)($row['before_hash'] ?? ''));
              $status = (string)($row['status'] ?? '');
              $afterHash = strtolower((string)($row['after_hash'] ?? ''));
              $isProdSuccess = ($environment === 'prod' && $status === 'success' && $afterHash !== '');
              $isCurrentHead = $prodHead !== null && deploy_hash_matches($afterHash, (string)$prodHead['full']);
              $isEligible = $eligibleTargetHash !== '' && deploy_hash_matches($afterHash, $eligibleTargetHash);
              $compareUrl = $isProdSuccess ? deploy_github_compare_url($beforeCommit, $afterCommit) : '';
            ?>
            <tr>
              <td><?= h((string)($row['created_at'] ?? '')) ?></td>
              <td><span class="env-pill env-<?= h($environment !== '' ? $environment : 'other') ?>"><?= h($environment !== '' ? $environment : '-') ?></span></td>
              <td class="mono"><?= h($beforeCommit) ?></td>
              <td class="mono"><?= h($afterCommit) ?></td>
              <td><span class="status-pill status-<?= h($status !== '' ? $status : 'other') ?>"><?= h($status !== '' ? $status : '-') ?></span></td>
              <td>
                <?php if ($compareUrl !== ''): ?>
                  <a class="btn btn-ghost" href="<?= h($compareUrl) ?>" target="_blank" rel="noopener noreferrer">差分を見る</a>
                <?php else: ?>
                  <span class="muted">-</span>
                <?php endif; ?>
              </td>
              <td><?= h((string)($row['executed_by'] ?? '')) ?></td>
              <td><?= nl2br(h((string)($row['detail_text'] ?? ''))) ?></td>
              <td>
                <?php if (!$isProdSuccess): ?>
                  <span class="muted">-</span>
                <?php elseif ($prodHead === null): ?>
                  <span class="muted">HEAD取得不可</span>
                <?php elseif ($isCurrentHead): ?>
                  <button type="button" class="btn" disabled>現在HEAD</button>
                <?php elseif ($isEligible): ?>
                  <form
                    class="js-rollback-form"
                    method="post"
                    action="/wbss/public/api/deploy_rollback.php"
                    data-current-head="<?= h((string)($prodHead['short'] ?? '')) ?>"
                    data-target-commit="<?= h($afterHash) ?>"
                    data-target-created-at="<?= h((string)($row['created_at'] ?? '')) ?>"
                  >
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                    <input type="hidden" name="target_commit" value="<?= h($afterHash) ?>">
                    <button type="submit" class="btn btn-danger">rollback</button>
                  </form>
                <?php else: ?>
                  <button type="button" class="btn" disabled>直前のみ</button>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<dialog id="rollbackDialog" class="rollback-dialog">
  <form method="dialog" class="rollback-dialog__panel">
    <div class="rollback-dialog__head">
      <div class="rollback-dialog__title">rollback 確認</div>
      <button type="submit" class="btn">閉じる</button>
    </div>
    <div class="rollback-dialog__body">
      <div class="rollback-dialog__summary">
        <div class="rollback-dialog__item">
          <div class="rollback-dialog__label">現在本番HEAD</div>
          <div class="rollback-dialog__value mono" data-modal-current-head>-</div>
        </div>
        <div class="rollback-dialog__item">
          <div class="rollback-dialog__label">戻り先commit</div>
          <div class="rollback-dialog__value mono" data-modal-target-commit>-</div>
        </div>
        <div class="rollback-dialog__item">
          <div class="rollback-dialog__label">履歴日時</div>
          <div class="rollback-dialog__value" data-modal-target-created-at>-</div>
        </div>
      </div>
      <div class="notice notice-error">
        本番環境に影響します。内容を確認してから rollback を実行してください。
      </div>
    </div>
    <div class="rollback-dialog__actions">
      <button type="button" class="btn" data-modal-cancel>キャンセル</button>
      <button type="button" class="btn btn-danger" data-modal-confirm>本番 rollback を実行</button>
    </div>
  </form>
</dialog>

<script>
(() => {
  const dialog = document.getElementById('rollbackDialog');
  const currentHeadEl = dialog ? dialog.querySelector('[data-modal-current-head]') : null;
  const targetCommitEl = dialog ? dialog.querySelector('[data-modal-target-commit]') : null;
  const targetCreatedAtEl = dialog ? dialog.querySelector('[data-modal-target-created-at]') : null;
  const cancelBtn = dialog ? dialog.querySelector('[data-modal-cancel]') : null;
  const confirmBtn = dialog ? dialog.querySelector('[data-modal-confirm]') : null;
  let activeForm = null;

  async function runRollback(form) {
    const fd = new FormData(form);
    const btn = form.querySelector('button[type="submit"]');
    if (btn) btn.disabled = true;
    if (confirmBtn) confirmBtn.disabled = true;

    try {
      const res = await fetch(form.action, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
      });
      const json = await res.json().catch(() => ({}));
      if (!res.ok || !json.ok) {
        throw new Error(json.error || 'rollback に失敗しました');
      }
      if (dialog && typeof dialog.close === 'function') dialog.close();
      alert(json.message || 'rollback を開始しました');
      window.location.reload();
    } catch (error) {
      alert((error && error.message) ? error.message : 'rollback に失敗しました');
      if (btn) btn.disabled = false;
      if (confirmBtn) confirmBtn.disabled = false;
    }
  }

  document.querySelectorAll('.js-rollback-form').forEach((form) => {
    form.addEventListener('submit', (event) => {
      event.preventDefault();
      activeForm = form;

      const currentHead = String(form.dataset.currentHead || '');
      const targetCommit = String(form.dataset.targetCommit || '');
      const targetCreatedAt = String(form.dataset.targetCreatedAt || '');

      if (!dialog || typeof dialog.showModal !== 'function') {
        if (window.confirm('現在本番: ' + currentHead + '\n戻り先: ' + targetCommit + '\n本番環境に影響します。実行してよいですか？')) {
          runRollback(form);
        }
        return;
      }

      if (currentHeadEl) currentHeadEl.textContent = currentHead || '-';
      if (targetCommitEl) targetCommitEl.textContent = targetCommit || '-';
      if (targetCreatedAtEl) targetCreatedAtEl.textContent = targetCreatedAt || '-';
      if (confirmBtn) confirmBtn.disabled = false;
      dialog.showModal();
    });
  });

  if (cancelBtn) {
    cancelBtn.addEventListener('click', () => {
      if (dialog && typeof dialog.close === 'function') dialog.close();
    });
  }

  if (confirmBtn) {
    confirmBtn.addEventListener('click', () => {
      if (activeForm) runRollback(activeForm);
    });
  }
})();
</script>

<style>
.deploy-summary{
  position:sticky;
  top:78px;
  z-index:15;
  display:grid;
  grid-template-columns: repeat(3, minmax(0, 1fr));
  gap:10px;
  margin-bottom:14px;
}
.deploy-summary__item{
  border:1px solid var(--line);
  border-radius:18px;
  padding:12px 14px;
  background:linear-gradient(180deg, var(--cardA), var(--cardB));
  box-shadow:var(--shadow);
}
.deploy-summary__item.is-current{
  border-color:color-mix(in srgb, var(--accent) 42%, var(--line));
}
.deploy-summary__item.is-target{
  border-color:color-mix(in srgb, var(--warn) 42%, var(--line));
}
.deploy-summary__label{
  font-size:12px;
  color:var(--muted);
  font-weight:800;
}
.deploy-summary__value{
  margin-top:4px;
  font-size:20px;
  font-weight:1000;
}
.deploy-head{
  display:flex;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  align-items:flex-start;
}
.deploy-title{
  font-size:18px;
  font-weight:1000;
}
.deploy-lead{
  margin-top:4px;
}
.deploy-meta{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.deploy-table-wrap{
  margin-top:14px;
  overflow:auto;
}
.deploy-table{
  width:100%;
  border-collapse:collapse;
  font-size:13px;
}
.deploy-table th,
.deploy-table td{
  padding:10px 8px;
  border-bottom:1px solid var(--line);
  vertical-align:top;
}
.deploy-table th{
  text-align:left;
  white-space:nowrap;
}
.mono{
  font-family:ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
  word-break:break-all;
}
.env-pill{
  display:inline-flex;
  align-items:center;
  padding:4px 8px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  font-size:12px;
  font-weight:800;
}
.env-prod{
  color:color-mix(in srgb, var(--accent) 86%, var(--txt));
  background:color-mix(in srgb, var(--accent) 12%, var(--cardA));
  border-color:color-mix(in srgb, var(--accent) 40%, var(--line));
}
.env-rollback{
  color:color-mix(in srgb, var(--warn) 92%, var(--txt));
  background:color-mix(in srgb, var(--warn) 14%, var(--cardA));
  border-color:color-mix(in srgb, var(--warn) 45%, var(--line));
}
.env-dev,
.env-other{
  background:rgba(255,255,255,.04);
}
.status-pill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:76px;
  padding:4px 9px;
  border-radius:999px;
  border:1px solid var(--line);
  font-size:12px;
  font-weight:900;
}
.status-success{
  color:color-mix(in srgb, var(--ok) 92%, var(--txt));
  background:color-mix(in srgb, var(--ok) 12%, var(--cardA));
  border-color:color-mix(in srgb, var(--ok) 40%, var(--line));
}
.status-failed{
  color:color-mix(in srgb, var(--ng) 92%, var(--txt));
  background:color-mix(in srgb, var(--ng) 12%, var(--cardA));
  border-color:color-mix(in srgb, var(--ng) 42%, var(--line));
}
.btn-ghost{
  background:transparent;
}
.notice{
  margin-top:12px;
  padding:10px 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
}
.notice-ok{
  border-color:color-mix(in srgb, var(--ok) 40%, var(--line));
}
.notice-error{
  border-color:color-mix(in srgb, var(--ng) 45%, var(--line));
}
.btn-danger{
  background:color-mix(in srgb, var(--ng) 16%, var(--cardA));
  border-color:color-mix(in srgb, var(--ng) 40%, var(--line));
}
.rollback-dialog{
  border:none;
  padding:0;
  background:transparent;
  color:inherit;
  max-width:min(560px, calc(100vw - 24px));
  width:100%;
}
.rollback-dialog::backdrop{
  background:rgba(0,0,0,.55);
}
.rollback-dialog__panel{
  border:1px solid var(--line);
  border-radius:22px;
  background:linear-gradient(180deg, var(--cardA), var(--cardB));
  box-shadow:var(--shadow);
  overflow:hidden;
}
.rollback-dialog__head,
.rollback-dialog__actions{
  display:flex;
  align-items:center;
  justify-content:space-between;
  gap:10px;
  padding:14px 16px;
}
.rollback-dialog__head{
  border-bottom:1px solid var(--line);
}
.rollback-dialog__title{
  font-size:18px;
  font-weight:1000;
}
.rollback-dialog__body{
  padding:16px;
}
.rollback-dialog__summary{
  display:grid;
  gap:10px;
}
.rollback-dialog__item{
  border:1px solid var(--line);
  border-radius:16px;
  padding:12px;
  background:rgba(255,255,255,.04);
}
.rollback-dialog__label{
  font-size:12px;
  color:var(--muted);
  font-weight:800;
}
.rollback-dialog__value{
  margin-top:4px;
  font-size:16px;
  font-weight:900;
}
.rollback-dialog__actions{
  border-top:1px solid var(--line);
}
@media (max-width: 900px){
  .deploy-summary{
    grid-template-columns:1fr;
    top:70px;
  }
  .deploy-table{
    min-width:1120px;
  }
}
</style>

<?php render_page_end(); ?>
