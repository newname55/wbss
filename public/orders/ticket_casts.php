<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/orders_repo.php';

$attLib = __DIR__ . '/../../app/attendance.php';
if (is_file($attLib)) require_once $attLib;
$storeLib = __DIR__ . '/../../app/store.php';
if (is_file($storeLib)) require_once $storeLib;

require_login();
require_role(['admin', 'manager', 'super_user', 'staff']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
date_default_timezone_set('Asia/Tokyo');

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
function yen(int $value): string { return number_format($value); }
function qty_text(float $value): string {
  if (abs($value - round($value)) < 0.0001) {
    return number_format($value, 0);
  }
  return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
}

if (!function_exists('current_store_id')) {
  function current_store_id(): ?int {
    return isset($_SESSION['store_id']) ? (int)$_SESSION['store_id'] : null;
  }
}

$storeId = 0;
if (function_exists('att_safe_store_id')) $storeId = (int)att_safe_store_id();
if ($storeId <= 0) $storeId = (int)($_SESSION['store_id'] ?? 0);
if ($storeId <= 0) {
  header('Location: /wbss/public/store_select.php?next=' . urlencode($_SERVER['REQUEST_URI']));
  exit;
}
$_SESSION['store_id'] = $storeId;

$ticketId = (int)($_GET['ticket_id'] ?? $_POST['ticket_id'] ?? 0);

$flash = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    $orderItemId = (int)($_POST['order_item_id'] ?? 0);
    $assignments = $_POST['assignments'] ?? [];
    if (!is_array($assignments)) {
      $assignments = [];
    }
    orders_repo_replace_order_item_assignments($pdo, $storeId, $ticketId, $orderItemId, $assignments);
    $flash = '担当キャストを更新しました。';
  } catch (Throwable $e) {
    $error = $e->getMessage();
  }
}

$storeName = '#' . $storeId;
if (function_exists('att_fetch_store')) {
  $store = att_fetch_store($pdo, $storeId);
  if (is_array($store) && !empty($store['name'])) $storeName = (string)$store['name'];
}

$ticketStatus = '';
$seatId = 0;
$ticketBusinessDate = '';
if ($ticketId > 0) {
  $stTicket = $pdo->prepare("SELECT store_id, status, seat_id, business_date FROM tickets WHERE id = ? LIMIT 1");
  $stTicket->execute([$ticketId]);
  $ticketRow = $stTicket->fetch(PDO::FETCH_ASSOC) ?: [];
  if ((int)($ticketRow['store_id'] ?? 0) !== $storeId) {
    http_response_code(404);
    exit('ticket not found');
  }
  $ticketStatus = (string)($ticketRow['status'] ?? '');
  $seatId = (int)($ticketRow['seat_id'] ?? 0);
  $ticketBusinessDate = (string)($ticketRow['business_date'] ?? '');
}

$defaultDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ticketBusinessDate) ? $ticketBusinessDate : date('Y-m-d');
$from = (string)($_GET['from'] ?? $_POST['from'] ?? $defaultDate);
$to = (string)($_GET['to'] ?? $_POST['to'] ?? $defaultDate);
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) $from = $defaultDate;
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) $to = $defaultDate;
if ($from > $to) {
  [$from, $to] = [$to, $from];
}
$bottleOnly = (string)($_GET['bottle_only'] ?? $_POST['bottle_only'] ?? '') === '1';

$ticketSummaryDate = preg_match('/^\d{4}-\d{2}-\d{2}$/', $ticketBusinessDate) ? $ticketBusinessDate : $from;
$ticketAssignmentRows = $ticketId > 0
  ? orders_repo_fetch_cast_assignment_rows($pdo, $storeId, $ticketSummaryDate, $ticketSummaryDate, $ticketId)
  : [];
$summaryRows = $ticketId > 0 ? orders_repo_aggregate_cast_assignment_summary($ticketAssignmentRows, false) : [];

$periodRows = orders_repo_fetch_cast_assignment_rows($pdo, $storeId, $from, $to, null);
$periodSourceRows = $bottleOnly
  ? array_values(array_filter($periodRows, static fn(array $row): bool => !empty($row['is_bottle_item'])))
  : $periodRows;
$periodBottleMenuRows = orders_repo_aggregate_menu_assignment_summary($periodRows, true);
$periodCastRows = orders_repo_aggregate_cast_assignment_summary($periodRows, true);
$periodDailyRows = orders_repo_aggregate_business_date_assignment_summary($periodRows, true);
$orderItems = $ticketId > 0 ? orders_repo_fetch_ticket_order_items_with_assignments($pdo, $storeId, $ticketId) : [];
$candidateRows = $ticketId > 0 ? orders_repo_fetch_ticket_assignment_candidates($pdo, $storeId, $ticketId) : [];

$periodTotals = [
  'total_amount_yen' => 0,
  'total_consumed_qty' => 0.0,
  'bottle_amount_yen' => 0,
  'bottle_consumed_qty' => 0.0,
  'back_base_amount_yen' => 0,
  'back_amount_yen' => 0,
  'bottle_back_base_amount_yen' => 0,
  'bottle_back_amount_yen' => 0,
  'assignment_count' => 0,
  'bottle_assignment_count' => 0,
  'ticket_ids' => [],
  'bottle_ticket_ids' => [],
  'cast_user_ids' => [],
  'bottle_cast_user_ids' => [],
];
foreach ($periodSourceRows as $row) {
  $periodTotals['assignment_count']++;
  $periodTotals['total_amount_yen'] += (int)($row['amount_yen'] ?? 0);
  $periodTotals['total_consumed_qty'] += (float)($row['consumed_qty'] ?? 0);
  $periodTotals['back_base_amount_yen'] += (int)($row['back_base_amount_yen'] ?? 0);
  $periodTotals['back_amount_yen'] += (int)($row['back_amount_yen'] ?? 0);

  $ticketIdValue = (int)($row['ticket_id'] ?? 0);
  if ($ticketIdValue > 0) $periodTotals['ticket_ids'][$ticketIdValue] = true;
  $castUserId = (int)($row['cast_user_id'] ?? 0);
  if ($castUserId > 0) $periodTotals['cast_user_ids'][$castUserId] = true;

  if (!empty($row['is_bottle_item'])) {
    $periodTotals['bottle_assignment_count']++;
    $periodTotals['bottle_amount_yen'] += (int)($row['amount_yen'] ?? 0);
    $periodTotals['bottle_consumed_qty'] += (float)($row['consumed_qty'] ?? 0);
    $periodTotals['bottle_back_base_amount_yen'] += (int)($row['back_base_amount_yen'] ?? 0);
    $periodTotals['bottle_back_amount_yen'] += (int)($row['back_amount_yen'] ?? 0);
    if ($ticketIdValue > 0) $periodTotals['bottle_ticket_ids'][$ticketIdValue] = true;
    if ($castUserId > 0) $periodTotals['bottle_cast_user_ids'][$castUserId] = true;
  }
}
$periodTotals['ticket_count'] = count($periodTotals['ticket_ids']);
$periodTotals['bottle_ticket_count'] = count($periodTotals['bottle_ticket_ids']);
$periodTotals['cast_count'] = count($periodTotals['cast_user_ids']);
$periodTotals['bottle_cast_count'] = count($periodTotals['bottle_cast_user_ids']);

$candidateOptions = [];
foreach ($candidateRows as $candidate) {
  $userId = (int)($candidate['user_id'] ?? 0);
  if ($userId <= 0) continue;
  $candidateOptions[$userId] = sprintf(
    '%s番 %s',
    (string)($candidate['cast_no'] ?? '-'),
    (string)($candidate['display_name'] ?? ('user#' . $userId))
  );
}

render_page_start('注文ドリンク担当');
render_header('注文ドリンク担当', [
  'back_href' => $ticketId > 0 ? ('/wbss/public/orders/ticket_orders_summary.php?ticket_id=' . $ticketId) : '/wbss/public/orders/dashboard_orders.php',
  'back_label' => $ticketId > 0 ? '← 注文一覧へ' : '← 注文ランチャーへ',
  'right_html' => $ticketId > 0
    ? '<a class="btn" href="/wbss/public/cashier/cashier.php?store_id=' . $storeId . '&ticket_id=' . $ticketId . '">会計を開く</a>'
    : '<a class="btn" href="/wbss/public/orders/dashboard_orders.php">注文ランチャー</a>',
]);
?>
<div class="page">
  <div class="admin-wrap drink-cast-shell">
    <?php if ($flash !== ''): ?>
      <div class="card noticeOk"><?= h($flash) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="card noticeErr"><?= h($error) ?></div>
    <?php endif; ?>

    <section class="card">
      <div class="row">
        <div>
          <div class="title"><?= $ticketId > 0 ? '注文ドリンク担当の集計' : 'ボトルバック集計' ?></div>
          <div class="muted">
            店舗 <?= h($storeName) ?>
            <?php if ($ticketId > 0): ?>
              / 伝票 #<?= (int)$ticketId ?> / 席 <?= $seatId > 0 ? (int)$seatId : '-' ?> / 営業日 <?= h($ticketBusinessDate !== '' ? $ticketBusinessDate : '-') ?>
            <?php else: ?>
              / 期間 <?= h($from) ?> 〜 <?= h($to) ?>
            <?php endif; ?>
          </div>
        </div>
        <div class="pill"><?= h($ticketId > 0 ? ($ticketStatus !== '' ? $ticketStatus : '-') : 'period') ?></div>
      </div>
    </section>

    <section class="card">
      <form method="get" class="filters">
        <input type="hidden" name="ticket_id" value="<?= (int)$ticketId ?>">
        <div class="field">
          <div class="label">集計開始日</div>
          <input class="input" type="date" name="from" value="<?= h($from) ?>">
        </div>
        <div class="field">
          <div class="label">集計終了日</div>
          <input class="input" type="date" name="to" value="<?= h($to) ?>">
        </div>
        <label class="checkLine">
          <input type="checkbox" name="bottle_only" value="1" <?= $bottleOnly ? 'checked' : '' ?>>
          期間集計をボトルだけに絞る
        </label>
        <button class="btn btn-primary" type="submit">期間集計を更新</button>
      </form>
      <div class="muted" style="margin-top:8px">
        <?php if ($ticketId > 0): ?>
          このページでは、伝票 #<?= (int)$ticketId ?> の担当修正と、<?= h($from) ?> から <?= h($to) ?> までのボトルバック向け期間集計をまとめて確認できます。
        <?php else: ?>
          このページでは、<?= h($from) ?> から <?= h($to) ?> までのボトルバック向け期間集計を確認できます。
        <?php endif; ?>
      </div>
    </section>

    <?php if ($ticketId > 0): ?>
      <section class="card">
        <div class="sectionTitle">この伝票のキャスト別サマリ</div>
        <?php if (!$summaryRows): ?>
          <div class="muted">まだ担当キャストの割当がありません。</div>
        <?php else: ?>
          <div class="summaryGrid">
            <?php foreach ($summaryRows as $row): ?>
              <article class="summaryCard">
                <div class="summaryName"><?= h((string)($row['cast_name'] ?? '')) ?></div>
                <div class="summaryMeta">対象アイテム <?= (int)($row['item_count'] ?? 0) ?>件</div>
                <div class="summaryValue">¥<?= yen((int)($row['total_amount_yen'] ?? 0)) ?></div>
                <div class="summaryMeta">消費量 <?= h(qty_text((float)($row['total_consumed_qty'] ?? 0))) ?></div>
                <div class="summaryMeta">ボトル本数 <?= h(qty_text((float)($row['bottle_consumed_qty'] ?? 0))) ?> / ボトル金額 ¥<?= yen((int)($row['bottle_amount_yen'] ?? 0)) ?></div>
                <div class="summaryMeta">想定バック ¥<?= yen((int)($row['bottle_back_amount_yen'] ?? 0)) ?> / 対象売価 ¥<?= yen((int)($row['bottle_back_base_amount_yen'] ?? 0)) ?></div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <section class="card">
      <div class="sectionTitle">期間集計</div>
      <div class="summaryGrid">
        <article class="summaryCard">
          <div class="summaryName"><?= $bottleOnly ? '期間合計（ボトルのみ）' : '期間合計' ?></div>
          <div class="summaryValue">¥<?= yen((int)$periodTotals['total_amount_yen']) ?></div>
          <div class="summaryMeta">消費量 <?= h(qty_text((float)$periodTotals['total_consumed_qty'])) ?> / 割当 <?= (int)$periodTotals['assignment_count'] ?>件</div>
          <div class="summaryMeta">想定バック ¥<?= yen((int)$periodTotals['back_amount_yen']) ?> / 対象売価 ¥<?= yen((int)$periodTotals['back_base_amount_yen']) ?></div>
        </article>
        <article class="summaryCard">
          <div class="summaryName">ボトル合計</div>
          <div class="summaryValue">¥<?= yen((int)$periodTotals['bottle_amount_yen']) ?></div>
          <div class="summaryMeta">ボトル本数 <?= h(qty_text((float)$periodTotals['bottle_consumed_qty'])) ?> / 割当 <?= (int)$periodTotals['bottle_assignment_count'] ?>件</div>
          <div class="summaryMeta">想定バック ¥<?= yen((int)$periodTotals['bottle_back_amount_yen']) ?> / 対象売価 ¥<?= yen((int)$periodTotals['bottle_back_base_amount_yen']) ?></div>
        </article>
        <article class="summaryCard">
          <div class="summaryName">対象伝票</div>
          <div class="summaryValue"><?= (int)$periodTotals['ticket_count'] ?></div>
          <div class="summaryMeta">ボトル伝票 <?= (int)$periodTotals['bottle_ticket_count'] ?>件</div>
        </article>
        <article class="summaryCard">
          <div class="summaryName">対象キャスト</div>
          <div class="summaryValue"><?= (int)$periodTotals['cast_count'] ?></div>
          <div class="summaryMeta">ボトル担当キャスト <?= (int)$periodTotals['bottle_cast_count'] ?>人</div>
        </article>
      </div>
    </section>

    <section class="card">
      <div class="sectionTitle">ボトルだけ集計</div>
      <?php if (!$periodBottleMenuRows): ?>
        <div class="muted">期間内にボトル割当はありません。</div>
      <?php else: ?>
        <div class="tableWrap">
          <table class="reportTable">
            <thead>
              <tr>
                <th>ボトル</th>
                <th>カテゴリ</th>
                <th>本数</th>
                <th>金額</th>
                <th>想定バック</th>
                <th>担当キャスト数</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($periodBottleMenuRows as $row): ?>
                <tr>
                  <td><?= h((string)($row['menu_name'] ?? '')) ?></td>
                  <td><?= h((string)($row['category_name'] ?? '-')) ?></td>
                  <td><?= h(qty_text((float)($row['total_consumed_qty'] ?? 0))) ?></td>
                  <td>¥<?= yen((int)($row['total_amount_yen'] ?? 0)) ?></td>
                  <td>¥<?= yen((int)($row['back_amount_yen'] ?? 0)) ?></td>
                  <td><?= (int)($row['cast_count'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="sectionTitle">キャスト別本数</div>
      <?php if (!$periodCastRows): ?>
        <div class="muted">期間内にボトル担当の割当はありません。</div>
      <?php else: ?>
        <div class="tableWrap">
          <table class="reportTable">
            <thead>
              <tr>
                <th>キャスト</th>
                <th>ボトル本数</th>
                <th>ボトル金額</th>
                <th>想定バック</th>
                <th>ボトル種類数</th>
                <th>内訳</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($periodCastRows as $row): ?>
                <tr>
                  <td><?= h((string)($row['cast_name'] ?? '')) ?></td>
                  <td><?= h(qty_text((float)($row['bottle_consumed_qty'] ?? 0))) ?></td>
                  <td>¥<?= yen((int)($row['bottle_amount_yen'] ?? 0)) ?></td>
                  <td>¥<?= yen((int)($row['bottle_back_amount_yen'] ?? 0)) ?></td>
                  <td><?= count((array)($row['bottle_breakdown'] ?? [])) ?></td>
                  <td class="breakdownCell">
                    <?php if (!empty($row['bottle_breakdown']) && is_array($row['bottle_breakdown'])): ?>
                      <?php
                        $parts = [];
                        foreach ($row['bottle_breakdown'] as $label => $qtyValue) {
                          $parts[] = (string)$label . ' x' . qty_text((float)$qtyValue);
                        }
                      ?>
                      <?= h(implode(' / ', $parts)) ?>
                    <?php else: ?>
                      <span class="muted">-</span>
                    <?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <section class="card">
      <div class="sectionTitle">日別ボトル集計</div>
      <?php if (!$periodDailyRows): ?>
        <div class="muted">期間内にボトル担当の割当はありません。</div>
      <?php else: ?>
        <div class="tableWrap">
          <table class="reportTable">
            <thead>
              <tr>
                <th>営業日</th>
                <th>ボトル本数</th>
                <th>ボトル金額</th>
                <th>想定バック</th>
                <th>伝票数</th>
                <th>担当キャスト数</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($periodDailyRows as $row): ?>
                <tr>
                  <td><?= h((string)($row['business_date'] ?? '')) ?></td>
                  <td><?= h(qty_text((float)($row['bottle_consumed_qty'] ?? 0))) ?></td>
                  <td>¥<?= yen((int)($row['bottle_amount_yen'] ?? 0)) ?></td>
                  <td>¥<?= yen((int)($row['bottle_back_amount_yen'] ?? 0)) ?></td>
                  <td><?= (int)($row['ticket_count'] ?? 0) ?></td>
                  <td><?= (int)($row['cast_count'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>
    </section>

    <?php if ($ticketId > 0): ?>
      <section class="card">
        <div class="sectionTitle">注文アイテムごとの担当修正</div>
        <div class="muted">注文数の範囲で、誰がどれだけ飲んだかを調整できます。空行は無視されます。</div>

        <?php foreach ($orderItems as $item): ?>
          <?php
            $assignments = is_array($item['assignments'] ?? null) ? $item['assignments'] : [];
            $formRows = $assignments;
            while (count($formRows) < 3) {
              $formRows[] = ['cast_user_id' => 0, 'consumed_qty' => '', 'note' => ''];
            }
          ?>
          <form method="post" class="itemCard">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="ticket_id" value="<?= (int)$ticketId ?>">
            <input type="hidden" name="from" value="<?= h($from) ?>">
            <input type="hidden" name="to" value="<?= h($to) ?>">
            <input type="hidden" name="bottle_only" value="<?= $bottleOnly ? '1' : '0' ?>">
            <input type="hidden" name="order_item_id" value="<?= (int)$item['order_item_id'] ?>">

            <div class="itemHead">
              <div>
                <div class="itemTitle"><?= h((string)($item['menu_name'] ?? '')) ?></div>
                <div class="muted">注文 #<?= (int)($item['order_id'] ?? 0) ?> / 数量 <?= h((string)($item['qty'] ?? '0')) ?> / 単価 ¥<?= yen((int)($item['price_ex'] ?? 0)) ?></div>
              </div>
              <button class="btn btn-primary" type="submit">保存</button>
            </div>

            <div class="assignGrid assignGridHead">
              <div>キャスト</div>
              <div>数量</div>
              <div>メモ</div>
            </div>
            <?php foreach ($formRows as $index => $assignment): ?>
              <div class="assignGrid">
                <div>
                  <select class="input" name="assignments[<?= (int)$index ?>][cast_user_id]">
                    <option value="">未設定</option>
                    <?php foreach ($candidateOptions as $userId => $label): ?>
                      <option value="<?= (int)$userId ?>" <?= ((int)($assignment['cast_user_id'] ?? 0) === (int)$userId) ? 'selected' : '' ?>>
                        <?= h($label) ?>
                      </option>
                    <?php endforeach; ?>
                  </select>
                </div>
                <div>
                  <input class="input" name="assignments[<?= (int)$index ?>][consumed_qty]" value="<?= h((string)($assignment['consumed_qty'] ?? '')) ?>" placeholder="1 or 0.5">
                </div>
                <div>
                  <input class="input" name="assignments[<?= (int)$index ?>][note]" value="<?= h((string)($assignment['note'] ?? '')) ?>" placeholder="補足メモ">
                </div>
              </div>
            <?php endforeach; ?>
          </form>
        <?php endforeach; ?>
      </section>
    <?php endif; ?>
  </div>
</div>

<style>
.drink-cast-shell{max-width:1100px}
.row{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.title{font-size:18px;font-weight:1000}
.muted{opacity:.75;font-size:12px}
.pill{display:inline-flex;padding:6px 10px;border-radius:999px;border:1px solid var(--line)}
.sectionTitle{font-size:16px;font-weight:1000;margin-bottom:10px}
.filters{display:flex;gap:10px;align-items:end;flex-wrap:wrap}
.field{display:grid;gap:6px;min-width:180px}
.label{font-size:12px;font-weight:900;opacity:.75}
.checkLine{display:inline-flex;align-items:center;gap:8px;font-size:13px}
.summaryGrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:12px}
.summaryCard{border:1px solid var(--line);border-radius:14px;padding:12px;background:color-mix(in srgb, var(--cardA) 70%, transparent)}
.summaryName{font-weight:1000}
.summaryValue{font-size:22px;font-weight:1000;margin-top:8px}
.summaryMeta{font-size:12px;opacity:.75;margin-top:4px}
.tableWrap{overflow:auto}
.reportTable{width:100%;border-collapse:collapse;font-size:13px}
.reportTable th,.reportTable td{padding:10px 8px;border-bottom:1px solid var(--line);text-align:left;vertical-align:top}
.reportTable th{font-size:12px;opacity:.75}
.breakdownCell{min-width:260px}
.itemCard{border:1px solid var(--line);border-radius:14px;padding:12px;margin-top:12px;background:color-mix(in srgb, var(--cardA) 70%, transparent)}
.itemHead{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;margin-bottom:10px}
.itemTitle{font-size:15px;font-weight:1000}
.assignGrid{display:grid;grid-template-columns:minmax(220px,1.3fr) 120px 1fr;gap:10px;align-items:center;margin-top:8px}
.assignGridHead{font-size:12px;font-weight:900;opacity:.75}
.input{width:100%;border:1px solid var(--line);border-radius:12px;background:var(--cardA);color:inherit;padding:10px 12px;box-sizing:border-box}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:10px 14px;border-radius:12px;border:1px solid var(--line);background:var(--cardA);color:inherit;text-decoration:none}
.btn-primary{border-color:transparent;background:linear-gradient(135deg, rgba(96,165,250,.95), rgba(167,139,250,.85));color:#fff}
.noticeOk{border-color:rgba(34,197,94,.35);background:rgba(34,197,94,.10);margin-top:12px}
.noticeErr{border-color:rgba(239,68,68,.45);background:rgba(239,68,68,.10);margin-top:12px}
@media (max-width:800px){
  .assignGrid{grid-template-columns:1fr}
}
</style>
<?php render_page_end(); ?>
