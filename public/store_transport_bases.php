<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/store_access.php';
require_once __DIR__ . '/../app/service_transport.php';

require_login();
require_role(['manager', 'admin', 'super_user']);

$pdo = db();
$msg = '';
$err = '';
$editId = (int)($_GET['edit_id'] ?? 0);

try {
  $stores = transport_allowed_stores($pdo);
  $storeId = transport_resolve_store_id($pdo, (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0));
  $storeName = store_access_find_store_name($stores, $storeId);
} catch (Throwable $e) {
  $stores = [];
  $storeId = 0;
  $storeName = '';
  $err = $e->getMessage();
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $storeId > 0 && $err === '') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
      transport_delete_store_base($pdo, $storeId, (int)($_POST['base_id'] ?? 0));
      header('Location: /wbss/public/store_transport_bases.php?store_id=' . $storeId . '&ok=deleted');
      exit;
    }

    $savedId = transport_save_store_base($pdo, $storeId, $_POST);
    header('Location: /wbss/public/store_transport_bases.php?store_id=' . $storeId . '&edit_id=' . $savedId . '&ok=saved');
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$ok = (string)($_GET['ok'] ?? '');
if ($ok === 'saved' && $err === '') {
  $msg = '送迎拠点を保存しました';
} elseif ($ok === 'deleted' && $err === '') {
  $msg = '送迎拠点を削除しました';
}

$bases = [];
$editBase = [
  'id' => 0,
  'base_type' => 'store',
  'name' => '',
  'address_text' => '',
  'lat' => '',
  'lng' => '',
  'is_default' => 1,
  'is_dispatch_origin' => 1,
  'sort_order' => 0,
];
if ($storeId > 0) {
  try {
    $hasDispatchOrigin = transport_store_base_has_dispatch_origin_field($pdo);
    $bases = transport_fetch_store_bases($pdo, $storeId);
    foreach ($bases as $base) {
      if ((int)($base['id'] ?? 0) === $editId) {
        $editBase = [
          'id' => (int)$base['id'],
          'base_type' => (string)($base['base_type'] ?? 'store'),
          'name' => (string)($base['name'] ?? ''),
          'address_text' => (string)($base['address_text'] ?? ''),
          'lat' => $base['lat'] !== null ? (string)$base['lat'] : '',
          'lng' => $base['lng'] !== null ? (string)$base['lng'] : '',
          'is_default' => (int)($base['is_default'] ?? 0),
          'is_dispatch_origin' => (int)($base['is_dispatch_origin'] ?? 0),
          'sort_order' => (int)($base['sort_order'] ?? 0),
        ];
        break;
      }
    }
  } catch (Throwable $e) {
    if ($err === '') {
      $err = $e->getMessage();
    }
  }
}
$hasDispatchOrigin = $hasDispatchOrigin ?? transport_store_base_has_dispatch_origin_field($pdo);

render_page_start('店舗送迎拠点');
render_header('店舗送迎拠点', [
  'back_href' => $storeId > 0 ? '/wbss/public/transport_routes.php?store_id=' . $storeId : '/wbss/public/dashboard.php',
  'back_label' => '← 送迎ルート',
  'right_html' => $storeId > 0
    ? '<a class="btn" href="/wbss/public/transport_routes.php?store_id=' . (int)$storeId . '">送迎ルートへ</a>'
    : '',
]);
?>
<div class="page">
  <div class="admin-wrap transportBasePage transportShell">
    <?php if ($msg !== ''): ?>
      <div class="card noticeOk"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err !== ''): ?>
      <div class="card" style="border-color:#ef4444"><?= h($err) ?></div>
    <?php endif; ?>

    <div class="pageHero">
      <div class="rowTop">
        <div class="titleWrap">
          <div class="title">店舗ごとの送迎出発拠点</div>
          <div class="heroMeta">
            <span class="heroChip">店舗 <b><?= h($storeName !== '' ? $storeName : '-') ?></b></span>
            <span class="heroChip">登録数 <b><?= count($bases) ?></b></span>
          </div>
        </div>
      </div>
      <div class="subInfo">
        <div class="muted">デフォルト拠点は店舗到着に使い、出発拠点は「車がどこから向かうか」の起点として使います。</div>
      </div>
    </div>

    <section class="transportToolbar transportPanel">
      <form method="get" class="searchRow transportFilterRow">
        <label class="muted">店舗</label>
        <select name="store_id" class="sel" onchange="this.form.submit()">
          <?php foreach ($stores as $store): ?>
            <?php $sid = (int)($store['id'] ?? 0); ?>
            <option value="<?= $sid ?>" <?= $sid === $storeId ? 'selected' : '' ?>>
              <?= h((string)($store['name'] ?? '')) ?> (#<?= $sid ?>)
            </option>
          <?php endforeach; ?>
        </select>
      </form>

      <div class="transportStatGrid">
        <div class="transportStatCard">
          <span class="transportStatLabel">登録拠点</span>
          <b class="transportStatValue"><?= count($bases) ?></b>
        </div>
        <div class="transportStatCard">
          <span class="transportStatLabel">デフォルト</span>
          <b class="transportStatValue"><?= count(array_filter($bases, static fn(array $base): bool => (int)($base['is_default'] ?? 0) === 1)) ?></b>
        </div>
        <div class="transportStatCard">
          <span class="transportStatLabel">出発拠点</span>
          <b class="transportStatValue"><?= count(array_filter($bases, static fn(array $base): bool => (int)($base['is_dispatch_origin'] ?? 0) === 1)) ?></b>
        </div>
      </div>
    </section>

    <div class="transportBaseLayout">
      <section class="card transportBaseFormCard transportPanel">
        <div class="cardTitle"><?= $editBase['id'] > 0 ? '拠点を編集' : '拠点を追加' ?></div>
        <form method="post" class="transportBaseForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="base_id" value="<?= (int)$editBase['id'] ?>">

          <label class="field">
            <span class="fieldLabel">種類</span>
            <select class="in" name="base_type">
              <option value="store" <?= $editBase['base_type'] === 'store' ? 'selected' : '' ?>>店舗</option>
              <option value="garage" <?= $editBase['base_type'] === 'garage' ? 'selected' : '' ?>>車庫</option>
              <option value="meeting_point" <?= $editBase['base_type'] === 'meeting_point' ? 'selected' : '' ?>>集合地点</option>
            </select>
          </label>

          <label class="field">
            <span class="fieldLabel">拠点名</span>
            <input class="in" name="name" maxlength="128" required value="<?= h((string)$editBase['name']) ?>" placeholder="例: 本店前">
          </label>

          <label class="field">
            <span class="fieldLabel">住所メモ</span>
            <input class="in" name="address_text" maxlength="255" value="<?= h((string)$editBase['address_text']) ?>" placeholder="例: ○○ビル前">
          </label>

          <div class="transportBaseCoords">
            <label class="field">
              <span class="fieldLabel">緯度</span>
              <input class="in mono" name="lat" inputmode="decimal" value="<?= h((string)$editBase['lat']) ?>" placeholder="35.0000000">
              <div class="muted">空欄なら住所メモから自動取得します</div>
            </label>
            <label class="field">
              <span class="fieldLabel">経度</span>
              <input class="in mono" name="lng" inputmode="decimal" value="<?= h((string)$editBase['lng']) ?>" placeholder="139.0000000">
              <div class="muted">手入力した場合はその値を優先します</div>
            </label>
          </div>

          <div class="transportBaseCoords">
            <label class="field">
              <span class="fieldLabel">並び順</span>
              <input class="in mono" type="number" name="sort_order" value="<?= (int)$editBase['sort_order'] ?>">
            </label>
            <label class="field checkField">
              <span class="fieldLabel">デフォルト</span>
              <label class="checkWrap">
                <input type="checkbox" name="is_default" value="1" <?= (int)$editBase['is_default'] === 1 ? 'checked' : '' ?>>
                <span>この拠点を標準にする</span>
              </label>
            </label>
            <?php if ($hasDispatchOrigin): ?>
              <label class="field checkField">
                <span class="fieldLabel">出発拠点</span>
                <label class="checkWrap">
                  <input type="checkbox" name="is_dispatch_origin" value="1" <?= (int)$editBase['is_dispatch_origin'] === 1 ? 'checked' : '' ?>>
                  <span>車の出発地点にする</span>
                </label>
              </label>
            <?php endif; ?>
          </div>

          <div class="transportBaseActions">
            <a class="btn" href="/wbss/public/store_transport_bases.php?store_id=<?= (int)$storeId ?>">新規入力へ</a>
            <button type="submit" class="btn btn-primary">保存</button>
          </div>
        </form>
      </section>

      <section class="card transportBaseListCard transportPanel">
        <div class="cardTitle">登録済み拠点</div>
        <div class="transportBaseList">
          <?php foreach ($bases as $base): ?>
            <article class="transportBaseItem">
              <div class="transportBaseItemHead">
                <div>
                  <b><?= h((string)($base['name'] ?? '')) ?></b>
                  <div class="muted"><?= h((string)($base['base_type'] ?? 'store')) ?> / sort <?= (int)($base['sort_order'] ?? 0) ?></div>
                </div>
                <div class="transportBaseBadges">
                  <?php if ((int)($base['is_default'] ?? 0) === 1): ?>
                    <span class="badge ok">デフォルト</span>
                  <?php endif; ?>
                  <?php if ((int)($base['is_dispatch_origin'] ?? 0) === 1): ?>
                    <span class="badge">出発拠点</span>
                  <?php endif; ?>
                </div>
              </div>
              <div class="muted"><?= h((string)($base['address_text'] ?? '')) ?></div>
              <div class="muted">
                緯度 <?= h((string)($base['lat'] ?? '-')) ?> / 経度 <?= h((string)($base['lng'] ?? '-')) ?>
              </div>
              <div class="transportBaseActions">
                <a class="miniBtn" href="/wbss/public/store_transport_bases.php?store_id=<?= (int)$storeId ?>&edit_id=<?= (int)$base['id'] ?>">編集</a>
                <form method="post" onsubmit="return confirm('この送迎拠点を削除しますか？');">
                  <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                  <input type="hidden" name="action" value="delete">
                  <input type="hidden" name="base_id" value="<?= (int)$base['id'] ?>">
                  <button type="submit" class="miniBtn dangerBtn">削除</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
          <?php if ($bases === []): ?>
            <div class="muted">まだ送迎拠点は登録されていません。まず1件デフォルト拠点を作ってください。</div>
          <?php endif; ?>
        </div>
      </section>
    </div>
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
.transportToolbar{ padding:16px 18px; display:grid; gap:14px; }
.transportFilterRow{ margin:0; }
.transportStatGrid{ display:grid; grid-template-columns:repeat(3, minmax(0, 1fr)); gap:10px; }
.transportStatCard{ padding:12px 14px; border:1px solid rgba(255,255,255,.08); border-radius:16px; background:rgba(255,255,255,.04); display:grid; gap:4px; }
.transportStatLabel{ font-size:11px; font-weight:800; color:var(--muted); }
.transportStatValue{ font-size:20px; font-weight:900; }
.transportBasePage .transportBaseLayout{ display:grid; grid-template-columns:minmax(0, 1fr) minmax(320px, .9fr); gap:14px; }
.transportBaseForm,.transportBaseList{ display:grid; gap:12px; }
.transportBaseFormCard,
.transportBaseListCard{ padding:18px; }
.transportBaseCoords{ display:grid; grid-template-columns:repeat(2, minmax(0, 1fr)); gap:12px; }
.transportBaseActions{ display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
.transportBaseItem{ border:1px solid var(--line); border-radius:16px; padding:12px; display:grid; gap:8px; background:rgba(255,255,255,.03); }
.transportBaseItemHead{ display:flex; justify-content:space-between; gap:12px; align-items:flex-start; }
.transportBaseBadges{ display:flex; gap:8px; flex-wrap:wrap; }
.checkWrap{ display:flex; gap:10px; align-items:center; min-height:46px; padding:10px 12px; border:1px solid var(--line); border-radius:12px; background:var(--cardA); }
.checkField{ align-self:end; }
@media (max-width: 900px){
  .transportStatGrid,
  .transportBasePage .transportBaseLayout,
  .transportBaseCoords{ grid-template-columns:1fr; }
}
</style>
<?php render_page_end(); ?>
