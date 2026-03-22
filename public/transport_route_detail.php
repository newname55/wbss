<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_transport.php';

require_login();
require_role(['manager', 'admin', 'super_user']);

$pdo = db();
$userId = (int)(current_user_id() ?? 0);
$routePlanId = (int)($_GET['id'] ?? 0);
$detail = null;
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    $routePlanId = (int)($_POST['route_plan_id'] ?? $routePlanId);
    $action = (string)($_POST['action'] ?? '');
    $detailForAction = transport_fetch_route_detail($pdo, $routePlanId);
    if ($detailForAction === null) {
      throw new RuntimeException('対象ルートが見つかりません');
    }
    $planForAction = $detailForAction['plan'] ?? [];

    if ($action === 'confirm_route') {
      transport_update_route_plan_status($pdo, $routePlanId, 'confirmed', $userId);
      header('Location: /wbss/public/transport_route_detail.php?id=' . $routePlanId . '&ok=confirmed');
      exit;
    }

    if ($action === 'regenerate_route') {
      $routePlanIds = transport_generate_and_save_routes(
        $pdo,
        (int)($planForAction['store_id'] ?? 0),
        (string)($planForAction['business_date'] ?? ''),
        substr((string)($planForAction['target_arrival_time'] ?? ''), 0, 5) ?: null,
        $userId,
        (int)($planForAction['requested_vehicle_count'] ?? 1),
        (int)($planForAction['max_passengers'] ?? 6)
      );
      $preferredVehicleNo = (int)($planForAction['vehicle_no'] ?? 1);
      $newRoutePlanId = (int)($routePlanIds[0] ?? 0);
      foreach ($routePlanIds as $candidateId) {
        $candidateDetail = transport_fetch_route_detail($pdo, (int)$candidateId);
        if ((int)($candidateDetail['plan']['vehicle_no'] ?? 0) === $preferredVehicleNo) {
          $newRoutePlanId = (int)$candidateId;
          break;
        }
      }
      header('Location: /wbss/public/transport_route_detail.php?id=' . $newRoutePlanId . '&ok=regenerated&from=' . $routePlanId);
      exit;
    }

    if ($action === 'reorder_route') {
      $requestedOrders = $_POST['stop_order'] ?? [];
      if (!is_array($requestedOrders)) {
        throw new RuntimeException('並び順の入力が不正です');
      }
      transport_reorder_route_plan($pdo, $routePlanId, $requestedOrders);
      header('Location: /wbss/public/transport_route_detail.php?id=' . $routePlanId . '&ok=reordered');
      exit;
    }

    throw new RuntimeException('不明な操作です');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

if ($routePlanId <= 0) {
  $err = 'ルートIDが不正です';
} else {
  try {
    $detail = transport_fetch_route_detail($pdo, $routePlanId);
    if ($detail === null) {
      $err = 'ルートが見つかりません';
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$plan = $detail['plan'] ?? [];
$stops = $detail['stops'] ?? [];
$storeId = (int)($plan['store_id'] ?? 0);
$ok = (string)($_GET['ok'] ?? '');
$msg = '';
if ($ok === 'confirmed') {
  $msg = 'ルートを確定しました';
} elseif ($ok === 'regenerated') {
  $msg = 'ルートを再生成しました';
} elseif ($ok === 'reordered') {
  $msg = '停車順を更新しました';
}

render_page_start('送迎ルート詳細');
render_header('送迎ルート詳細', [
  'back_href' => $storeId > 0
    ? '/wbss/public/transport_routes.php?store_id=' . $storeId . '&business_date=' . urlencode((string)($plan['business_date'] ?? ''))
    : '/wbss/public/transport_routes.php',
  'back_label' => '← 送迎ルート',
  'right_html' => $storeId > 0
    ? '<a class="btn" href="/wbss/public/store_transport_bases.php?store_id=' . $storeId . '">拠点設定へ</a>'
    : '',
]);
?>
<div class="page">
  <div class="admin-wrap transportDetailPage transportShell">
    <?php if ($msg !== ''): ?>
      <div class="card noticeOk"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:#ef4444"><?= h($err) ?></div>
    <?php elseif ($detail !== null): ?>
      <div class="pageHero">
        <div class="rowTop">
          <div class="titleWrap">
            <div class="title"><?= h((string)($plan['store_name'] ?? '')) ?> の送迎下書き</div>
            <div class="heroMeta">
              <span class="heroChip">ルートID <b>#<?= (int)$plan['id'] ?></b></span>
              <span class="heroChip">車両 <b><?= h((string)($plan['vehicle_label'] ?? '1号車')) ?></b></span>
              <span class="heroChip">営業日 <b><?= h((string)$plan['business_date']) ?></b></span>
              <span class="heroChip">距離 <b><?= h((string)($plan['total_distance_km'] ?? '-')) ?> km</b></span>
              <span class="heroChip">所要 <b><?= (int)($plan['total_duration_min'] ?? 0) ?> 分</b></span>
            </div>
          </div>
        </div>
      </div>

      <section class="transportSummaryGrid">
        <div class="transportSummaryCard transportPanel">
          <span class="transportSummaryLabel">到着目安</span>
          <b class="transportSummaryValue"><?= h(substr((string)($plan['target_arrival_time'] ?? ''), 0, 5) ?: '-') ?></b>
        </div>
        <div class="transportSummaryCard transportPanel">
          <span class="transportSummaryLabel">状態</span>
          <b class="transportSummaryValue"><?= h((string)($plan['plan_status'] ?? 'draft')) ?></b>
        </div>
        <div class="transportSummaryCard transportPanel">
          <span class="transportSummaryLabel">停車数</span>
          <b class="transportSummaryValue"><?= count($stops) ?></b>
        </div>
        <div class="transportSummaryCard transportPanel">
          <span class="transportSummaryLabel">配車条件</span>
          <b class="transportSummaryValue"><?= (int)($plan['requested_vehicle_count'] ?? 1) ?> 台 / <?= (int)($plan['max_passengers'] ?? 6) ?> 名</b>
        </div>
      </section>

      <section class="transportActionPanel transportPanel">
        <form method="post" class="transportActionForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="route_plan_id" value="<?= (int)$routePlanId ?>">
          <input type="hidden" name="action" value="confirm_route">
          <button type="submit" class="btn btn-primary" <?= (string)($plan['plan_status'] ?? '') === 'confirmed' ? 'disabled' : '' ?>>確定</button>
        </form>

        <form method="post" class="transportActionForm" onsubmit="return confirm('同じ条件で下書きを再生成しますか？');">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="route_plan_id" value="<?= (int)$routePlanId ?>">
          <input type="hidden" name="action" value="regenerate_route">
          <button type="submit" class="btn">再生成</button>
        </form>
      </section>

      <div class="card transportPanel">
        <div class="sectionHead">
          <div>
            <div class="cardTitle">停車順</div>
            <div class="muted">到着目安 <?= h(substr((string)($plan['target_arrival_time'] ?? ''), 0, 5) ?: '-') ?> / 状態 <?= h((string)($plan['plan_status'] ?? 'draft')) ?> / 並び順を変えると距離と時刻を再計算します</div>
          </div>
        </div>

        <form method="post" class="routeReorderForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="route_plan_id" value="<?= (int)$routePlanId ?>">
          <input type="hidden" name="action" value="reorder_route">

        <div class="routeDetailList">
          <?php foreach ($stops as $stop): ?>
            <div class="routeDetailItem">
              <div class="routeDetailOrder"><?= (int)($stop['stop_order'] ?? 0) ?></div>
              <div class="routeDetailBody">
                <div class="routeDetailMain">
                  <b><?= h((string)($stop['display_name'] ?? '店舗到着')) ?></b>
                  <span class="muted"><?= h((string)($stop['planned_at'] ?? '')) ?></span>
                </div>
                <?php if ((string)($stop['stop_type'] ?? '') === 'pickup'): ?>
                  <div class="routeOrderEditor">
                    <label class="muted">表示順</label>
                    <input class="routeOrderInput" type="number" min="1" name="stop_order[<?= (int)($stop['id'] ?? 0) ?>]" value="<?= (int)($stop['stop_order'] ?? 0) ?>">
                  </div>
                <?php endif; ?>
                <div class="muted"><?= h((string)($stop['address_snapshot'] ?? '')) ?></div>
                <div class="muted">
                  種別 <?= h((string)($stop['stop_type'] ?? '')) ?> /
                  <?php if ((string)($stop['stop_type'] ?? '') === 'dispatch_origin'): ?>車の出発地点 /<?php endif; ?>
                  <?php if ((string)($stop['stop_type'] ?? '') === 'store_arrival'): ?>店舗到着 /<?php endif; ?>
                  前地点から <?= h((string)($stop['distance_km_from_prev'] ?? 0)) ?> km /
                  <?= (int)($stop['travel_minutes_from_prev'] ?? 0) ?> 分
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
          <div class="routeReorderActions">
            <button type="submit" class="btn">並び順を保存</button>
          </div>
        </form>
      </div>
    <?php endif; ?>
  </div>
</div>

<style>
.transportShell{ display:grid; gap:14px; padding-bottom:28px; }
.transportPanel{
  border:1px solid var(--line);
  border-radius:22px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 16px 40px rgba(0,0,0,.14);
}
.transportSummaryGrid{ display:grid; grid-template-columns:repeat(4, minmax(0, 1fr)); gap:12px; }
.transportSummaryCard{ padding:14px 16px; display:grid; gap:5px; }
.transportSummaryLabel{ font-size:11px; font-weight:800; color:var(--muted); }
.transportSummaryValue{ font-size:20px; font-weight:900; }
.transportActionPanel{ padding:16px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }
.transportActionForm{ margin:0; }
.routeReorderForm{ display:grid; gap:12px; }
.routeDetailList{ display:grid; gap:10px; }
.routeDetailItem{ display:grid; grid-template-columns:56px 1fr; gap:12px; padding:14px 0; border-top:1px solid rgba(255,255,255,.08); }
.routeDetailItem:first-child{ border-top:0; }
.routeDetailOrder{ width:56px; height:56px; border-radius:16px; display:grid; place-items:center; font-size:18px; font-weight:900; background:rgba(255,255,255,.08); }
.routeDetailBody{ display:grid; gap:6px; }
.routeDetailMain{ display:flex; justify-content:space-between; gap:12px; }
.routeOrderEditor{ display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.routeOrderInput{ width:88px; min-height:40px; padding:8px 10px; border-radius:12px; border:1px solid var(--line); background:rgba(255,255,255,.08); color:inherit; }
.routeReorderActions{ display:flex; justify-content:flex-end; }
@media (max-width: 900px){
  .transportSummaryGrid{ grid-template-columns:1fr; }
}
@media (max-width: 640px){
  .transportActionPanel{ align-items:stretch; }
  .transportActionForm .btn{ width:100%; }
  .routeDetailItem{ grid-template-columns:1fr; }
  .routeDetailMain{ flex-direction:column; align-items:stretch; }
}
</style>
<?php render_page_end(); ?>
