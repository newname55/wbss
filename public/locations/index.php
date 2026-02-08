<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager') && !is_role('staff')) {
  http_response_code(403);
  exit('Forbidden');
}

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /seika-app/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = db();

$msg = '';
$err = '';

/* ========= POST: save / toggle ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $mode = (string)($_POST['mode'] ?? '');

  // CSRF（もし実装済みなら使う）
  if (function_exists('csrf_verify')) {
    csrf_verify();
  }

  try {
    if ($mode === 'save') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $sort = (int)($_POST['sort_order'] ?? 0);
      $active = (int)($_POST['is_active'] ?? 1);
      $active = ($active === 1) ? 1 : 0;

      if ($name === '') {
        throw new RuntimeException('場所名を入力してください');
      }

      if ($id > 0) {
        // 更新（store_idも必ず一致させる）
        $st = $pdo->prepare("
          UPDATE stock_locations
          SET name = ?, sort_order = ?, is_active = ?
          WHERE id = ? AND store_id = ?
          LIMIT 1
        ");
        $st->execute([$name, $sort, $active, $id, $store_id]);
        $msg = '更新しました';
      } else {
        // 新規
        $st = $pdo->prepare("
          INSERT INTO stock_locations (store_id, name, sort_order, is_active)
          VALUES (?, ?, ?, ?)
        ");
        $st->execute([$store_id, $name, $sort, $active]);
        $msg = '追加しました';
      }

      header('Location: /seika-app/public/stock/locations/index.php?ok=1');
      exit;
    }

    if ($mode === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      $to = (int)($_POST['to'] ?? 0); // 1 or 0
      $to = ($to === 1) ? 1 : 0;

      if ($id <= 0) throw new RuntimeException('IDが不正です');

      $st = $pdo->prepare("
        UPDATE stock_locations
        SET is_active = ?
        WHERE id = ? AND store_id = ?
        LIMIT 1
      ");
      $st->execute([$to, $id, $store_id]);
      $msg = $to ? '有効にしました' : '無効にしました';

      header('Location: /seika-app/public/stock/locations/index.php?ok=1');
      exit;
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

/* ========= GET: edit target ========= */
$edit_id = (int)($_GET['edit_id'] ?? 0);
$edit = null;

if ($edit_id > 0) {
  $st = $pdo->prepare("
    SELECT id, name, sort_order, is_active, created_at, updated_at
    FROM stock_locations
    WHERE id = ? AND store_id = ?
    LIMIT 1
  ");
  $st->execute([$edit_id, $store_id]);
  $edit = $st->fetch() ?: null;
  if (!$edit) {
    $err = '編集対象が見つかりません';
    $edit_id = 0;
  }
}

/* ========= list ========= */
$st = $pdo->prepare("
  SELECT id, name, sort_order, is_active, created_at, updated_at
  FROM stock_locations
  WHERE store_id = ?
  ORDER BY is_active DESC, sort_order ASC, id ASC
");
$st->execute([$store_id]);
$rows = $st->fetchAll();

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫へ</a>';

render_page_start('場所マスタ');
render_header('場所マスタ', [
  'back_href'  => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>

<div class="page">

  <?php if (isset($_GET['ok'])): ?>
    <div class="card" style="border-color:rgba(52,211,153,.35);">OK</div>
  <?php endif; ?>
  <?php if ($msg): ?>
    <div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div>
  <?php endif; ?>

  <div class="card">
    <div style="display:flex; gap:10px; align-items:flex-end; justify-content:space-between; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:16px;"><?= $edit ? '場所を編集' : '場所を追加' ?></div>
        <div class="muted">例：バックヤード / キッチン / カウンター下 / 冷蔵庫 / 倉庫</div>
      </div>
      <?php if ($edit): ?>
        <a class="btn" href="/seika-app/public/stock/locations/index.php">＋ 新規追加に戻る</a>
      <?php endif; ?>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <form method="post" autocomplete="off">
      <input type="hidden" name="mode" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <?php if (function_exists('csrf_token')): ?>
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
      <?php endif; ?>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:260px;">
          <label class="muted">場所名</label><br>
          <input class="btn" style="width:100%;" name="name" value="<?= h((string)($edit['name'] ?? '')) ?>" placeholder="例）冷蔵庫">
        </div>

        <div style="min-width:180px;">
          <label class="muted">並び順</label><br>
          <input class="btn" style="width:100%;" type="number" name="sort_order" value="<?= (int)($edit['sort_order'] ?? 0) ?>">
        </div>

        <div style="min-width:200px;">
          <label class="muted">状態</label><br>
          <select class="btn" name="is_active" style="width:100%;">
            <?php $curActive = (int)($edit['is_active'] ?? 1); ?>
            <option value="1" <?= $curActive === 1 ? 'selected' : '' ?>>有効</option>
            <option value="0" <?= $curActive === 0 ? 'selected' : '' ?>>無効</option>
          </select>
        </div>

        <div>
          <label class="muted">保存</label><br>
          <button class="btn btn-primary" type="submit"><?= $edit ? '更新' : '追加' ?></button>
        </div>
      </div>

      <?php if ($edit): ?>
        <div class="muted" style="margin-top:10px;">
          作成: <?= h((string)$edit['created_at']) ?> / 更新: <?= h((string)$edit['updated_at']) ?>
        </div>
      <?php endif; ?>
    </form>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div style="font-weight:1000;">登録済み（<?= count($rows) ?>）</div>
      <div class="muted">※削除はしない（無効化）</div>
    </div>

    <div style="overflow:auto; margin-top:10px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ID</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">場所名</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">並び</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">状態</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">更新</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $active = (int)$r['is_active'] === 1;
              $badgeColor = $active ? 'var(--ok)' : 'var(--muted)';
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= (int)$r['id'] ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <?= h((string)$r['name']) ?>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;"><?= (int)$r['sort_order'] ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <span class="pill" style="color:var(--muted);">
                  <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:<?= h($badgeColor) ?>;"></span>
                  <?= $active ? '有効' : '無効' ?>
                </span>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$r['updated_at']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap;">
                <a class="btn" href="/seika-app/public/stock/locations/index.php?edit_id=<?= (int)$r['id'] ?>">編集</a>

                <form method="post" style="display:inline-block; margin:0 0 0 6px;">
                  <input type="hidden" name="mode" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <input type="hidden" name="to" value="<?= $active ? 0 : 1 ?>">
                  <?php if (function_exists('csrf_token')): ?>
                    <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
                  <?php endif; ?>
                  <button class="btn" type="submit"><?= $active ? '無効化' : '有効化' ?></button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="6" class="muted" style="padding:10px;">まだ場所がありません。上で追加してください。</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>

<?php render_page_end(); ?>