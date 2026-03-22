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
$kind = ((string)($_GET['kind'] ?? $_POST['kind'] ?? 'normal') === 'thanks') ? 'thanks' : 'normal';
$storeId = message_resolve_store_id($pdo, (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0));
$storeName = message_store_name($pdo, $storeId);
$recipients = [];
$err = '';
$msg = '';

$presetRecipientIds = [];
$rawPresetRecipientIds = $_GET['recipient_user_ids'] ?? [];
if (is_array($rawPresetRecipientIds)) {
  $presetRecipientIds = array_values(array_filter(array_map('intval', $rawPresetRecipientIds), static fn(int $id): bool => $id > 0));
} elseif (is_string($rawPresetRecipientIds) && trim($rawPresetRecipientIds) !== '') {
  $presetRecipientIds = array_values(array_filter(array_map('intval', explode(',', $rawPresetRecipientIds)), static fn(int $id): bool => $id > 0));
}

$form = [
  'recipient_user_ids' => array_values(array_filter(array_map('intval', (array)($_POST['recipient_user_ids'] ?? [])), static fn(int $id): bool => $id > 0)),
  'title' => (string)($_POST['title'] ?? ''),
  'body' => (string)($_POST['body'] ?? ''),
];

$presetRecipientId = (int)($_GET['recipient_user_id'] ?? 0);
if ($presetRecipientIds && !$form['recipient_user_ids']) {
  $form['recipient_user_ids'] = $presetRecipientIds;
} elseif ($presetRecipientId > 0 && !$form['recipient_user_ids']) {
  $form['recipient_user_ids'] = [$presetRecipientId];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    if (!message_tables_ready($pdo)) {
      throw new RuntimeException('messages / message_recipients テーブルを先に作成してください');
    }

    message_create_many(
      $pdo,
      $storeId,
      $userId,
      (array)$form['recipient_user_ids'],
      $kind,
      (string)$form['title'],
      (string)$form['body']
    );

    $target = $kind === 'thanks' ? '/wbss/public/thanks.php' : '/wbss/public/messages.php';
    $qs = http_build_query([
      'store_id' => $storeId,
      'sent' => 1,
    ]);
    header('Location: ' . $target . '?' . $qs);
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

try {
  if (message_tables_ready($pdo)) {
    $recipients = message_fetch_recipients($pdo, $storeId, $userId);
  }
} catch (Throwable $e) {
  if ($err === '') {
    $err = $e->getMessage();
  }
}

$kindLabel = message_kind_label($kind);
$targetListPath = $kind === 'thanks' ? '/wbss/public/thanks.php' : '/wbss/public/messages.php';

render_page_start($kindLabel . '送信');
render_header($kindLabel . '送信', [
  'back_href' => $targetListPath . ($storeId > 0 ? ('?store_id=' . $storeId) : ''),
  'back_label' => '← 一覧へ',
]);
?>
<div class="page">
  <div class="admin-wrap send-shell">
    <div data-badge-sync data-store-id="<?= (int)$storeId ?>" hidden></div>
    <?php if ($msg !== ''): ?>
      <div class="card noticeOk"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:rgba(239,68,68,.45);margin-top:12px;"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="card send-intro" style="margin-top:12px;">
      <div class="send-title"><?= $kind === 'thanks' ? '💐 ありがとうカードを送る' : '📨 業務連絡を送る' ?></div>
      <div class="muted" style="margin-top:4px;">
        店舗：<b><?= h($storeName !== '' ? $storeName : '-') ?></b><?php if ($storeId > 0): ?> (#<?= (int)$storeId ?>)<?php endif; ?>
      </div>
      <div class="muted send-intro__text" style="margin-top:4px;">
        <?= $kind === 'thanks' ? '感謝だけを集める導線です。短くても、具体的に伝えると見返しやすくなります。' : '個別の業務連絡向けです。雑談ではなく、相手に必要な内容だけを送る前提で使います。'; ?>
      </div>
    </div>

    <form method="post" class="card send-form" style="margin-top:12px;">
      <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
      <input type="hidden" name="kind" value="<?= h($kind) ?>">

      <section class="field">
        <span class="field-label">送信先</span>
        <details class="recipient-picker" id="recipientPicker">
          <summary class="recipient-picker__summary">
            <span class="recipient-picker__summary-main">
              <span class="recipient-picker__hamburger" aria-hidden="true">
                <span></span>
                <span></span>
                <span></span>
              </span>
              <span class="recipient-picker__summary-text">
                <strong>送信先を選ぶ</strong>
                <span>タップで開閉</span>
              </span>
            </span>
            <span class="recipient-selection-state">
              <span class="recipient-selection-state__label">選択中</span>
              <strong id="recipientCount"><?= count((array)$form['recipient_user_ids']) ?></strong>
              <span>人</span>
            </span>
          </summary>
          <div class="recipient-toolbar">
            <div class="recipient-toolbar__main">
              <div class="recipient-selected-preview" id="recipientSelectedPreview">未選択</div>
              <input class="recipient-search-input" type="search" id="recipientSearchInput" placeholder="名前や店番で検索">
            </div>
            <button class="btn btn-ghost" type="button" id="recipientClearBtn">すべて解除</button>
          </div>
          <div class="recipient-grid">
            <?php foreach ($recipients as $recipient): ?>
              <?php
              $recipientId = (int)($recipient['user_id'] ?? 0);
              $staffCode = trim((string)($recipient['staff_code'] ?? ''));
              $displayName = (string)($recipient['display_name'] ?? '');
              $checked = in_array($recipientId, (array)$form['recipient_user_ids'], true);
            ?>
            <label class="recipient-option<?= $checked ? ' is-selected' : '' ?>" data-recipient-card data-recipient-name="<?= h($displayName) ?>" data-recipient-search="<?= h(mb_strtolower(trim($displayName . ' ' . $staffCode), 'UTF-8')) ?>">
                <input class="recipient-check" type="checkbox" name="recipient_user_ids[]" value="<?= $recipientId ?>" <?= $checked ? 'checked' : '' ?>>
                <span class="recipient-option__check" aria-hidden="true"></span>
                <span class="recipient-option__body">
                <span class="recipient-option__top">
                  <?php if ($staffCode !== ''): ?>
                    <span class="recipient-option__tag"><?= h($staffCode) ?></span>
                  <?php endif; ?>
                  <span class="recipient-option__name"><?= h($displayName) ?></span>
                </span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
        </details>
        <span class="muted">複数人に同じ内容を送れます。</span>
      </section>

      <div class="recipient-inline-summary" id="recipientInlineSummary" hidden>
        <span class="recipient-inline-summary__label">送信先</span>
        <span class="recipient-inline-summary__value" id="recipientInlineSummaryValue">未選択</span>
      </div>

      <label class="field">
        <span class="field-label">件名</span>
        <input
          class="input"
          type="text"
          name="title"
          maxlength="120"
          value="<?= h((string)$form['title']) ?>"
          placeholder="<?= h($kind === 'thanks' ? '例：昨日のフォローありがとう' : '例：明日の出勤時間について') ?>"
        >
      </label>

      <label class="field">
        <span class="field-label">本文</span>
        <textarea
          class="input textarea"
          name="body"
          rows="8"
          maxlength="2000"
          required
          placeholder="<?= h($kind === 'thanks' ? '例：忙しい中で席のフォローをしてくれて助かりました。次も一緒に頑張ろう。' : '例：明日は19:30までに入店お願いします。変更があれば早めに連絡してください。') ?>"
        ><?= h((string)$form['body']) ?></textarea>
      </label>

      <div class="send-actions">
        <a class="btn" href="<?= h($targetListPath . ($storeId > 0 ? ('?store_id=' . $storeId) : '')) ?>">戻る</a>
        <button class="btn btn-primary" type="submit"><?= h($kindLabel) ?>を送信</button>
      </div>
    </form>
  </div>
</div>

<style>
.send-shell{max-width:760px}
.send-intro{padding:11px 13px}
.send-title{font-size:16px;font-weight:1000}
.send-intro__text{font-size:11px;line-height:1.45}
.send-form{display:grid;gap:14px}
.field{display:grid;gap:6px}
.field-label{font-size:12px;font-weight:900;color:var(--muted)}
.input{width:100%;border:1px solid var(--line);border-radius:12px;background:var(--cardA);color:inherit;padding:12px 14px;font:inherit;box-sizing:border-box}
.textarea{resize:vertical;min-height:160px;line-height:1.7}
.recipient-picker{border:1px solid var(--line);border-radius:14px;background:rgba(255,255,255,.03);overflow:hidden}
.recipient-picker[open]{background:rgba(255,255,255,.04)}
.recipient-picker__summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:10px;padding:12px 13px;cursor:pointer;user-select:none}
.recipient-picker__summary::-webkit-details-marker{display:none}
.recipient-picker__summary-main{display:flex;align-items:center;gap:12px;min-width:0}
.recipient-picker__summary-text{display:grid;gap:2px}
.recipient-picker__summary-text strong{font-size:14px;line-height:1.2}
.recipient-picker__summary-text span{font-size:11px;color:var(--muted)}
.recipient-picker__hamburger{display:inline-flex;flex-direction:column;justify-content:center;gap:4px;width:18px;height:18px;flex:0 0 18px}
.recipient-picker__hamburger span{display:block;height:2px;border-radius:999px;background:currentColor}
.recipient-toolbar{display:flex;align-items:flex-start;justify-content:space-between;gap:8px;flex-wrap:wrap;padding:0 13px 10px}
.recipient-toolbar__main{display:grid;gap:8px;min-width:0;flex:1}
.recipient-selection-state{display:inline-flex;align-items:baseline;gap:6px;padding:8px 12px;border-radius:999px;border:1px solid var(--line);background:rgba(255,255,255,.04)}
.recipient-selection-state__label{font-size:11px;font-weight:900;color:var(--muted)}
.recipient-selection-state strong{font-size:16px;line-height:1}
.recipient-selected-preview{font-size:11px;color:var(--muted);min-height:28px;display:flex;align-items:center}
.recipient-search-input{width:100%;max-width:340px;border:1px solid var(--line);border-radius:10px;background:var(--cardA);color:inherit;padding:10px 12px;font:inherit;font-size:13px}
.recipient-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(190px,1fr));gap:9px;padding:0 13px 13px}
.recipient-option{position:relative;display:flex;gap:10px;align-items:center;min-height:72px;padding:10px 10px;border:1px solid var(--line);border-radius:14px;background:var(--cardA);cursor:pointer;transition:border-color .15s ease, background .15s ease, box-shadow .15s ease, transform .15s ease;user-select:none;-webkit-tap-highlight-color:transparent}
.recipient-option.is-hidden{display:none}
.recipient-option:hover{border-color:color-mix(in srgb, var(--accent) 35%, var(--line));box-shadow:0 10px 18px rgba(0,0,0,.06)}
.recipient-option.is-selected{border-color:color-mix(in srgb, var(--accent) 78%, #f59e0b);background:linear-gradient(180deg, color-mix(in srgb, var(--accent) 18%, #fff), color-mix(in srgb, var(--accent) 8%, var(--cardA)));box-shadow:0 14px 26px rgba(0,0,0,.12);transform:translateY(-1px)}
.recipient-check{position:absolute;opacity:0;pointer-events:none}
.recipient-option__check{width:22px;height:22px;flex:0 0 22px;border-radius:999px;border:1.5px solid var(--line);background:rgba(255,255,255,.5);position:relative}
.recipient-option.is-selected .recipient-option__check{border-color:var(--accent);background:var(--accent)}
.recipient-option.is-selected .recipient-option__check::after{content:'✓';position:absolute;inset:0;display:flex;align-items:center;justify-content:center;color:#fff;font-size:13px;font-weight:1000}
.recipient-option__body{display:grid;gap:2px;min-width:0;flex:1}
.recipient-option__top{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;min-width:0;text-align:center;width:100%}
.recipient-option__tag{display:inline-flex;align-items:center;justify-content:center;font-size:14px;font-weight:1000;line-height:1.2;color:var(--txt)}
.recipient-option__name{font-size:14px;font-weight:1000;line-height:1.2;word-break:break-word}
.recipient-inline-summary{display:flex;gap:8px;align-items:flex-start;flex-wrap:wrap;margin-top:-2px;padding:2px 2px 0}
.recipient-inline-summary__label{font-size:11px;font-weight:900;color:var(--muted);padding-top:2px}
.recipient-inline-summary__value{font-size:12px;line-height:1.5;color:var(--txt);opacity:.88}
.send-actions{display:flex;justify-content:flex-end;gap:8px;flex-wrap:wrap}
.muted{opacity:.75;font-size:12px}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none;cursor:pointer}
.btn-ghost{background:transparent;min-height:36px;padding:8px 12px;font-size:12px}
.btn-primary{border-color:transparent;background:<?= $kind === 'thanks' ? 'linear-gradient(135deg, #f59e0b, #fb7185)' : 'linear-gradient(135deg, rgba(96,165,250,.95), rgba(167,139,250,.85))' ?>;color:#fff}
.noticeOk{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10)}
@media (min-width:900px){
  .recipient-grid{grid-template-columns:repeat(5, minmax(0, 1fr))}
}
@media (max-width:640px){
  .recipient-toolbar{align-items:stretch}
  .recipient-toolbar .btn{width:100%}
  .recipient-grid{grid-template-columns:repeat(3, minmax(0, 1fr));gap:6px}
  .recipient-option{min-height:64px;padding:8px 8px;gap:8px}
  .recipient-option__body{gap:2px}
  .recipient-option__name{font-size:13px}
  .recipient-option__tag{font-size:13px}
  .recipient-option__check{width:18px;height:18px;flex-basis:18px}
  .send-actions{justify-content:stretch}
  .send-actions .btn{flex:1}
}
</style>
<script>
(function(){
  const cards = Array.from(document.querySelectorAll('[data-recipient-card]'));
  const countEl = document.getElementById('recipientCount');
  const clearBtn = document.getElementById('recipientClearBtn');
  const previewEl = document.getElementById('recipientSelectedPreview');
  const inlineSummaryEl = document.getElementById('recipientInlineSummary');
  const inlineSummaryValueEl = document.getElementById('recipientInlineSummaryValue');
  const searchInput = document.getElementById('recipientSearchInput');
  if (!cards.length || !countEl) return;

  function syncCard(card){
    const input = card.querySelector('.recipient-check');
    if (!input) return;
    card.classList.toggle('is-selected', !!input.checked);
  }

  function syncCount(){
    let count = 0;
    const names = [];
    cards.forEach(function(card){
      const input = card.querySelector('.recipient-check');
      if (input && input.checked) {
        count += 1;
        const name = card.getAttribute('data-recipient-name') || '';
        if (name) names.push(name);
      }
      syncCard(card);
    });
    countEl.textContent = String(count);
    let summaryText = '未選択';
    if (previewEl) {
      if (names.length === 0) {
        previewEl.textContent = '未選択';
      } else if (names.length <= 3) {
        summaryText = names.join(' / ');
        previewEl.textContent = summaryText;
      } else {
        summaryText = names.slice(0, 3).join(' / ') + ' ほか' + (names.length - 3) + '人';
        previewEl.textContent = summaryText;
      }
    }
    if (inlineSummaryEl && inlineSummaryValueEl) {
      inlineSummaryEl.hidden = count === 0;
      inlineSummaryValueEl.textContent = summaryText;
    }
  }

  function normalizeText(value){
    return String(value || '')
      .toLowerCase()
      .replace(/[#]/g, ' ')
      .replace(/\s+/g, ' ')
      .trim();
  }

  function applyFilter(){
    const keyword = normalizeText(searchInput ? searchInput.value : '');
    cards.forEach(function(card){
      const haystack = normalizeText([
        card.getAttribute('data-recipient-search') || '',
        card.getAttribute('data-recipient-name') || '',
        card.textContent || ''
      ].join(' '));
      card.classList.toggle('is-hidden', keyword !== '' && !haystack.includes(keyword));
    });
  }

  cards.forEach(function(card){
    const input = card.querySelector('.recipient-check');
    if (!input) return;
    card.addEventListener('click', function(e){
      if (e.target instanceof HTMLElement && e.target.closest('input, a, button, select, textarea')) {
        return;
      }
      e.preventDefault();
      input.checked = !input.checked;
      syncCount();
    });
    input.addEventListener('change', syncCount);
  });

  if (clearBtn) {
    clearBtn.addEventListener('click', function(){
      cards.forEach(function(card){
        const input = card.querySelector('.recipient-check');
        if (input) input.checked = false;
      });
      syncCount();
    });
  }

  if (searchInput) {
    searchInput.addEventListener('input', applyFilter);
    searchInput.addEventListener('search', applyFilter);
    searchInput.addEventListener('keydown', function(e){
      if (e.key === 'Enter') {
        e.preventDefault();
        applyFilter();
      }
    });
    if (searchInput.form) {
      searchInput.form.addEventListener('submit', function(e){
        if (document.activeElement === searchInput) {
          e.preventDefault();
          applyFilter();
        }
      });
    }
  }

  syncCount();
  applyFilter();
})();
</script>
<script src="/wbss/public/assets/js/push_notifications.js?v=20260320b"></script>
<?php render_page_end(); ?>
