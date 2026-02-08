<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/store.php';
require_once __DIR__ . '/../../../app/layout.php';

require_login();

// マスタは原則 管理者のみ（必要なら manager も許可に変更OK）
if (!is_role('super_user') && !is_role('admin')) {
  http_response_code(403);
  exit('Forbidden');
}

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /seika-app/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

/** CSRF（存在する環境だけ使う） */
function csrf_field(): string {
  if (function_exists('csrf_token')) {
    return '<input type="hidden" name="csrf_token" value="'.h((string)csrf_token()).'">';
  }
  return '';
}
function csrf_check_or_die(): void {
  if (function_exists('verify_csrf')) {
    // verify_csrf() が死ぬ実装ならそのまま任せる
    verify_csrf((string)($_POST['csrf_token'] ?? ''));
  }
}

$pdo = db();

$msg = '';
$err = '';

$edit_id = (int)($_GET['edit_id'] ?? 0);

/* =========================
   POST actions
========================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_check_or_die();

    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
      $name = trim((string)($_POST['name'] ?? ''));
      $sort = (int)($_POST['sort_order'] ?? 0);
      $is_active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;

      if ($name === '') throw new RuntimeException('場所名を入力してください');

      $st = $pdo->prepare("
        INSERT INTO stock_locations (store_id, name, sort_order, is_active)
        VALUES (?, ?, ?, ?)
      ");
      $st->execute([$store_id, $name, $sort, $is_active]);

      $msg = '場所を追加しました';
      $edit_id = 0;
      header('Location: /seika-app/public/stock/locations/index.php?msg=' . urlencode($msg));
      exit;
    }

    if ($action === 'update') {
      $id   = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $sort = (int)($_POST['sort_order'] ?? 0);
      $is_active = (int)($_POST['is_active'] ?? 1) ? 1 : 0;

      if ($id <= 0) throw new RuntimeException('ID不正');
      if ($name === '') throw new RuntimeException('場所名を入力してください');

      $st = $pdo->prepare("
        UPDATE stock_locations
        SET name = ?, sort_order = ?, is_active = ?
        WHERE id = ? AND store_id = ?
        LIMIT 1
      ");
      $st->execute([$name, $sort, $is_active, $id, $store_id]);

      $msg = '更新しました';
      header('Location: /seika-app/public/stock/locations/index.php?edit_id=' . $id . '&msg=' . urlencode($msg));
      exit;
    }

    if ($action === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('ID不正');

      $st = $pdo->prepare("
        UPDATE stock_locations
        SET is_active = IF(is_active=1, 0, 1)
        WHERE id = ? AND store_id = ?
        LIMIT 1
      ");
      $st->execute([$id, $store_id]);

      $msg = '切替しました';
      header('Location: /seika-app/public/stock/locations/index.php?msg=' . urlencode($msg));
      exit;
    }

    throw new RuntimeException('action不正');
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

if (isset($_GET['msg']) && $msg === '') {
  $msg = (string)$_GET['msg'];
}

/* =========================
   Load list
========================= */
$rows = [];
try {
  $st = $pdo->prepare("
    SELECT id, name, sort_order, is_active, updated_at
    FROM stock_locations
    WHERE store_id = ?
    ORDER BY is_active DESC, sort_order ASC, id ASC
  ");
  $st->execute([$store_id]);
  $rows = $st->fetchAll();
} catch (Throwable $e) {
  $err = $err ?: $e->getMessage();
}

/* =========================
   Load edit row
========================= */
$edit = null;
if ($edit_id > 0) {
  $st = $pdo->prepare("
    SELECT id, name, sort_order, is_active
    FROM stock_locations
    WHERE id = ? AND store_id = ?
    LIMIT 1
  ");
  $st->execute([$edit_id, $store_id]);
  $edit = $st->fetch() ?: null;
  if (!$edit) $edit_id = 0;
}

$right = '<a class="btn" href="/seika-app/public/stock/index.php">在庫ランチャー</a>';

render_page_start('場所マスタ');
render_header('場所マスタ', [
  'back_href'  => '/seika-app/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">

  <?php if ($msg): ?>
    <div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div>
  <?php endif; ?>
  <?php if ($err): ?>
    <div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div>
  <?php endif; ?>

  <div class="card">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:12px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:16px;">📍 場所マスタ（店舗別）</div>
        <div class="muted" style="margin-top:4px;">棚卸・場所別在庫に使う「バックヤード」「冷蔵庫」など。</div>
      </div>
      <div style="display:flex; gap:10px; flex-wrap:wrap;">
        <?php if ($edit_id > 0): ?>
          <a class="btn" href="/seika-app/public/stock/locations/index.php">＋ 新規追加へ</a>
        <?php endif; ?>
      </div>
    </div>

    <hr style="border:none; border-top:1px solid var(--line); margin:12px 0;">

    <!-- ===== create / update form ===== -->
    <form method="post" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="<?= $edit_id > 0 ? 'update' : 'create' ?>">
      <?php if ($edit_id > 0): ?>
        <input type="hidden" name="id" value="<?= (int)$edit_id ?>">
      <?php endif; ?>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div style="flex:1; min-width:260px;">
          <label class="muted">場所名</label><br>
          <input class="btn" style="width:100%;" name="name" required
                 value="<?= h((string)($edit['name'] ?? '')) ?>"
                 placeholder="例) バックヤード / 冷蔵庫 / カウンター">
        </div>

        <div style="min-width:170px;">
          <label class="muted">並び順</label><br>
          <input class="btn" style="width:100%;" name="sort_order" inputmode="numeric"
                 value="<?= h((string)($edit['sort_order'] ?? '0')) ?>">
        </div>

        <div style="min-width:200px;">
          <label class="muted">状態</label><br>
          <select class="btn" name="is_active" style="width:100%;">
            <?php
              $cur_active = (int)($edit['is_active'] ?? 1);
            ?>
            <option value="1" <?= $cur_active === 1 ? 'selected' : '' ?>>有効</option>
            <option value="0" <?= $cur_active === 0 ? 'selected' : '' ?>>無効</option>
          </select>
        </div>

        <div>
          <label class="muted"><?= $edit_id > 0 ? '更新' : '追加' ?></label><br>
          <button class="btn btn-primary" type="submit"><?= $edit_id > 0 ? '保存' : '追加' ?></button>
        </div>
      </div>

      <div class="muted" style="margin-top:10px;">
        並び順は小さいほど上。無効にすると選択肢から外す運用にできます。
      </div>
    </form>
  </div>

  <!-- ===== list ===== -->
  <div class="card" style="margin-top:14px;">
    <div style="font-weight:1000;">一覧</div>

    <div style="overflow:auto; margin-top:10px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ID</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">場所名</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">並び順</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">状態</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">更新</th>
            <th style="text-align:right; padding:8px; border-bottom:1px solid var(--line);">操作</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $r): ?>
            <?php
              $active = (int)$r['is_active'] === 1;
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= (int)$r['id'] ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); font-weight:900;"><?= h((string)$r['name']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right;"><?= (int)$r['sort_order'] ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <span style="display:inline-flex; align-items:center; gap:6px; padding:3px 10px; border-radius:999px; border:1px solid var(--line); background:rgba(255,255,255,.06); color:var(--muted);">
                  <span style="display:inline-block; width:8px; height:8px; border-radius:999px; background:<?= $active ? 'var(--ok)' : 'var(--ng)' ?>;"></span>
                  <?= $active ? '有効' : '無効' ?>
                </span>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$r['updated_at']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line); text-align:right; white-space:nowrap;">
                <a class="btn" style="min-height:38px; padding:8px 10px;" href="/seika-app/public/stock/locations/index.php?edit_id=<?= (int)$r['id'] ?>">編集</a>

                <form method="post" style="display:inline-block; margin:0 0 0 6px;">
                  <?= csrf_field() ?>
                  <input type="hidden" name="action" value="toggle">
                  <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                  <button class="btn" style="min-height:38px; padding:8px 10px;" type="submit">
                    <?= $active ? '無効化' : '有効化' ?>
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          <?php if (!$rows): ?>
            <tr>
              <td colspan="6" class="muted" style="padding:10px;">まだ場所がありません（上で追加してください）</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
  </div>

</div>
<?php render_page_end(); ?>