<?php
declare(strict_types=1);

require_once __DIR__ . '/../../../app/auth.php';
require_once __DIR__ . '/../../../app/db.php';
require_once __DIR__ . '/../../../app/layout.php';
require_once __DIR__ . '/../../../app/store.php';

require_login();
if (!is_role('super_user') && !is_role('admin')) {
  http_response_code(403);
  exit('Forbidden');
}

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = db();

/* ========= helpers ========= */
function col_exists(PDO $pdo, string $table, string $col): bool {
  $st = $pdo->prepare("
    SELECT 1
    FROM information_schema.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = ?
      AND COLUMN_NAME = ?
    LIMIT 1
  ");
  $st->execute([$table, $col]);
  return (bool)$st->fetchColumn();
}

function post_array(string $key): array {
  $v = $_POST[$key] ?? [];
  return is_array($v) ? $v : [];
}

$msg = '';
$err = '';

$has_users_updated_at = col_exists($pdo, 'users', 'updated_at'); // 環境差分吸収

/* ========= master data ========= */
$roles = $pdo->query("SELECT id, code, name FROM roles ORDER BY id ASC")->fetchAll();

$stores = $pdo->query("
  SELECT id, code, name
  FROM stores
  WHERE is_active=1
  ORDER BY id ASC
")->fetchAll();

/* ========= actions ========= */
$action = (string)($_POST['action'] ?? '');
$edit_id = (int)($_GET['edit_id'] ?? ($_POST['user_id'] ?? 0));

/** 役割保存（user_roles 再構築） */
function save_user_roles(PDO $pdo, int $user_id): void {
  $roles_global = post_array('roles_global'); // role_id[]
  $roles_store  = $_POST['roles_store'] ?? []; // roles_store[store_id] => role_id[]
  if (!is_array($roles_store)) $roles_store = [];

  // 全削除→入れ直し（uniq制約 uq_user_role_store があるので重複は避ける）
  $pdo->prepare("DELETE FROM user_roles WHERE user_id=?")->execute([$user_id]);

  $ins = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, store_id) VALUES (?,?,?)");

  // global(store_id NULL)
  foreach ($roles_global as $rid) {
    $rid = (int)$rid;
    if ($rid <= 0) continue;
    $ins->execute([$user_id, $rid, null]);
  }

  // per store
  foreach ($roles_store as $sid => $arr) {
    $sid = (int)$sid;
    if ($sid <= 0) continue;
    if (!is_array($arr)) continue;
    foreach ($arr as $rid) {
      $rid = (int)$rid;
      if ($rid <= 0) continue;
      $ins->execute([$user_id, $rid, $sid]);
    }
  }
}

/** ユーザー作成 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'create_user') {
  $login_id = trim((string)($_POST['login_id'] ?? ''));
  $display  = trim((string)($_POST['display_name'] ?? ''));
  $pw       = (string)($_POST['password'] ?? '');
  $theme    = trim((string)($_POST['ui_theme'] ?? 'dark'));
  $is_active = (int)($_POST['is_active'] ?? 1);

  if ($login_id === '' || $display === '' || $pw === '') {
    $err = 'login_id / display_name / password は必須です';
  } else {
    try {
      $pdo->beginTransaction();

      $hash = password_hash($pw, PASSWORD_DEFAULT);

      // users.updated_at がある/ない両対応
      if ($has_users_updated_at) {
        $st = $pdo->prepare("
          INSERT INTO users (login_id,password_hash,display_name,is_active,ui_theme,created_at,updated_at)
          VALUES (?,?,?,?,?, NOW(), NOW())
        ");
        $st->execute([$login_id, $hash, $display, $is_active ? 1 : 0, $theme ?: 'dark']);
      } else {
        $st = $pdo->prepare("
          INSERT INTO users (login_id,password_hash,display_name,is_active,ui_theme,created_at)
          VALUES (?,?,?,?,?, NOW())
        ");
        $st->execute([$login_id, $hash, $display, $is_active ? 1 : 0, $theme ?: 'dark']);
      }

      $new_id = (int)$pdo->lastInsertId();

      save_user_roles($pdo, $new_id);

      $pdo->commit();
      $msg = "作成OK (#{$new_id})";
      $edit_id = $new_id;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->getMessage();
    }
  }
}

/** ユーザー更新 */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'update_user') {
  $user_id = (int)($_POST['user_id'] ?? 0);
  $login_id = trim((string)($_POST['login_id'] ?? ''));
  $display  = trim((string)($_POST['display_name'] ?? ''));
  $pw       = (string)($_POST['password'] ?? '');
  $theme    = trim((string)($_POST['ui_theme'] ?? 'dark'));
  $is_active = (int)($_POST['is_active'] ?? 1);

  if ($user_id <= 0) {
    $err = 'user_id が不正です';
  } elseif ($login_id === '' || $display === '') {
    $err = 'login_id / display_name は必須です';
  } else {
    try {
      $pdo->beginTransaction();

      // password は空なら据え置き
      if ($pw !== '') {
        $hash = password_hash($pw, PASSWORD_DEFAULT);

        if ($has_users_updated_at) {
          $st = $pdo->prepare("
            UPDATE users
            SET login_id=?, display_name=?, is_active=?, ui_theme=?, password_hash=?, updated_at=NOW()
            WHERE id=?
          ");
          $st->execute([$login_id, $display, $is_active ? 1 : 0, $theme ?: 'dark', $hash, $user_id]);
        } else {
          $st = $pdo->prepare("
            UPDATE users
            SET login_id=?, display_name=?, is_active=?, ui_theme=?, password_hash=?
            WHERE id=?
          ");
          $st->execute([$login_id, $display, $is_active ? 1 : 0, $theme ?: 'dark', $hash, $user_id]);
        }
      } else {
        if ($has_users_updated_at) {
          $st = $pdo->prepare("
            UPDATE users
            SET login_id=?, display_name=?, is_active=?, ui_theme=?, updated_at=NOW()
            WHERE id=?
          ");
          $st->execute([$login_id, $display, $is_active ? 1 : 0, $theme ?: 'dark', $user_id]);
        } else {
          $st = $pdo->prepare("
            UPDATE users
            SET login_id=?, display_name=?, is_active=?, ui_theme=?
            WHERE id=?
          ");
          $st->execute([$login_id, $display, $is_active ? 1 : 0, $theme ?: 'dark', $user_id]);
        }
      }

      save_user_roles($pdo, $user_id);

      $pdo->commit();
      $msg = "更新OK (#{$user_id})";
      $edit_id = $user_id;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $err = $e->getMessage();
    }
  }
}

/* ========= fetch list ========= */
$users_sql = "
  SELECT id, login_id, display_name, is_active, created_at, ui_theme
  ".($has_users_updated_at ? ", updated_at" : "")."
  FROM users
  ORDER BY id DESC
";
$users = $pdo->query($users_sql)->fetchAll();

/* roles map */
$role_map = [];
foreach ($roles as $r) $role_map[(int)$r['id']] = $r;

/* user_roles for listing */
$urs = $pdo->query("
  SELECT ur.user_id, ur.role_id, ur.store_id, r.code, r.name
  FROM user_roles ur
  JOIN roles r ON r.id = ur.role_id
")->fetchAll();

$user_roles_by_user = []; // user_id => [ ['store_id'=>..., 'role_id'=>..., 'code'=>..., 'name'=>...], ... ]
foreach ($urs as $x) {
  $uid = (int)$x['user_id'];
  if (!isset($user_roles_by_user[$uid])) $user_roles_by_user[$uid] = [];
  $user_roles_by_user[$uid][] = [
    'store_id' => $x['store_id'] === null ? null : (int)$x['store_id'],
    'role_id'  => (int)$x['role_id'],
    'code'     => (string)$x['code'],
    'name'     => (string)$x['name'],
  ];
}

/* ========= edit user ========= */
$edit_user = null;
$edit_roles_global = [];          // role_id => true
$edit_roles_store  = [];          // store_id => [role_id=>true]
if ($edit_id > 0) {
  $st = $pdo->prepare("
    SELECT id, login_id, display_name, is_active, created_at, ui_theme
    ".($has_users_updated_at ? ", updated_at" : "")."
    FROM users WHERE id=? LIMIT 1
  ");
  $st->execute([$edit_id]);
  $edit_user = $st->fetch() ?: null;

  if ($edit_user) {
    $rows = $user_roles_by_user[(int)$edit_user['id']] ?? [];
    foreach ($rows as $r) {
      $sid = $r['store_id'];
      $rid = (int)$r['role_id'];
      if ($sid === null) {
        $edit_roles_global[$rid] = true;
      } else {
        if (!isset($edit_roles_store[$sid])) $edit_roles_store[$sid] = [];
        $edit_roles_store[$sid][$rid] = true;
      }
    }
  }
}

$right = '<a class="btn" href="/wbss/public/store_select.php">店舗選択へ</a>';

render_page_start('ユーザー管理');
render_header('ユーザー管理', [
  'back_href' => '/wbss/public/store_select.php',
  'back_label' => '← 戻る',
  'right_html' => $right,
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="display:flex; align-items:flex-end; justify-content:space-between; gap:10px; flex-wrap:wrap;">
      <div>
        <div style="font-weight:1000; font-size:16px;">ユーザー一覧</div>
        <div class="muted">削除は禁止（is_active=0で無効化）。役割は複数・店舗別に付与OK。</div>
      </div>
      <a class="btn btn-primary" href="/wbss/public/admin/users/index.php?edit_id=0">＋ 新規作成</a>
    </div>

    <div style="overflow:auto; margin-top:12px;">
      <table style="width:100%; border-collapse:collapse; font-size:13px;">
        <thead>
          <tr>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">ID</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">login_id</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">表示名</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">状態</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">役割</th>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">作成</th>
            <?php if ($has_users_updated_at): ?>
              <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);">更新</th>
            <?php endif; ?>
            <th style="text-align:left; padding:8px; border-bottom:1px solid var(--line);"></th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($users as $u): ?>
            <?php
              $uid = (int)$u['id'];
              $urs_u = $user_roles_by_user[$uid] ?? [];
              $pill = [];
              foreach ($urs_u as $rr) {
                $s = $rr['store_id'] === null ? 'ALL' : ('S'.$rr['store_id']);
                $pill[] = $s.':'.$rr['code'];
              }
            ?>
            <tr>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= (int)$u['id'] ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$u['login_id']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);"><?= h((string)$u['display_name']) ?></td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <?php if ((int)$u['is_active'] === 1): ?>
                  <span class="pill" style="border-color:rgba(52,211,153,.35);">active</span>
                <?php else: ?>
                  <span class="pill" style="border-color:rgba(251,113,133,.35);">inactive</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line);">
                <?php if ($pill): ?>
                  <?php foreach ($pill as $p): ?><span class="pill"><?= h($p) ?></span><?php endforeach; ?>
                <?php else: ?>
                  <span class="muted">（未設定）</span>
                <?php endif; ?>
              </td>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)$u['created_at']) ?></td>
              <?php if ($has_users_updated_at): ?>
                <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;"><?= h((string)($u['updated_at'] ?? '')) ?></td>
              <?php endif; ?>
              <td style="padding:8px; border-bottom:1px solid var(--line); white-space:nowrap;">
                <a class="btn" href="/wbss/public/admin/users/index.php?edit_id=<?= (int)$u['id'] ?>">編集</a>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="card" style="margin-top:14px;">
    <div style="font-weight:1000; font-size:16px;">
      <?= $edit_user ? 'ユーザー編集' : '新規作成' ?>
    </div>
    <div class="muted" style="margin-top:6px;">
      役割は「全店舗(ALL)」と「店舗別」を併用できます（応援・兼務OK）。
    </div>

    <form method="post" style="margin-top:12px;">
      <?php if ($edit_user): ?>
        <input type="hidden" name="action" value="update_user">
        <input type="hidden" name="user_id" value="<?= (int)$edit_user['id'] ?>">
      <?php else: ?>
        <input type="hidden" name="action" value="create_user">
      <?php endif; ?>

      <div style="display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
        <div style="min-width:220px; flex:1;">
          <label class="muted">login_id（文字列）</label><br>
          <input class="btn" style="width:100%;" name="login_id" required value="<?= h((string)($edit_user['login_id'] ?? '')) ?>">
        </div>

        <div style="min-width:220px; flex:1;">
          <label class="muted">表示名</label><br>
          <input class="btn" style="width:100%;" name="display_name" required value="<?= h((string)($edit_user['display_name'] ?? '')) ?>">
        </div>

        <div style="min-width:220px; flex:1;">
          <label class="muted">パスワード（空なら据え置き）</label><br>
          <input class="btn" style="width:100%;" name="password" type="password" autocomplete="new-password" <?= $edit_user ? '' : 'required' ?>>
        </div>

        <div style="min-width:180px;">
          <label class="muted">テーマ</label><br>
          <select class="btn" name="ui_theme" style="min-width:180px;">
            <?php
              $themes = ['dark','light','soft','high_contrast','store_color'];
              $cur = (string)($edit_user['ui_theme'] ?? 'dark');
              foreach ($themes as $t):
            ?>
              <option value="<?= h($t) ?>" <?= $cur===$t?'selected':'' ?>><?= h($t) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div style="min-width:180px;">
          <label class="muted">状態</label><br>
          <?php $ia = (int)($edit_user['is_active'] ?? 1); ?>
          <select class="btn" name="is_active" style="min-width:180px;">
            <option value="1" <?= $ia===1?'selected':'' ?>>active</option>
            <option value="0" <?= $ia===0?'selected':'' ?>>inactive</option>
          </select>
        </div>

        <div>
          <label class="muted">保存</label><br>
          <button class="btn btn-primary" type="submit"><?= $edit_user ? '更新' : '作成' ?></button>
        </div>
      </div>

      <hr style="border:none;border-top:1px solid var(--line); margin:12px 0;">

      <div style="display:grid; grid-template-columns: 1fr 1fr; gap:12px;">
        <div class="card" style="box-shadow:none;">
          <div style="font-weight:1000;">全店舗（ALL）で付与する役割</div>
          <div class="muted" style="margin-top:6px;">ここに付けた役割は、store_select でも全店に効く前提で扱えます。</div>

          <div style="display:flex; flex-wrap:wrap; gap:8px; margin-top:10px;">
            <?php foreach ($roles as $r): ?>
              <?php $rid = (int)$r['id']; ?>
              <label class="pill" style="cursor:pointer;">
                <input type="checkbox" name="roles_global[]" value="<?= (int)$rid ?>" <?= isset($edit_roles_global[$rid])?'checked':'' ?>>
                <?= h((string)$r['code']) ?> / <?= h((string)$r['name']) ?>
              </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="card" style="box-shadow:none;">
          <div style="font-weight:1000;">店舗別（応援・兼務）</div>
          <div class="muted" style="margin-top:6px;">店舗ごとに役割を付けられます（複数チェックOK）。</div>

          <div style="display:flex; flex-direction:column; gap:10px; margin-top:10px;">
            <?php foreach ($stores as $s): ?>
              <?php $sid = (int)$s['id']; ?>
              <div style="border:1px solid var(--line); border-radius:14px; padding:10px;">
                <div style="font-weight:900; margin-bottom:6px;">
                  <?= h((string)$s['name']) ?> <span class="muted">(#<?= (int)$sid ?>)</span>
                </div>
                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                  <?php foreach ($roles as $r): ?>
                    <?php $rid = (int)$r['id']; ?>
                    <label class="pill" style="cursor:pointer;">
                      <input type="checkbox"
                             name="roles_store[<?= (int)$sid ?>][]"
                             value="<?= (int)$rid ?>"
                             <?= isset($edit_roles_store[$sid][$rid])?'checked':'' ?>>
                      <?= h((string)$r['code']) ?>
                    </label>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        </div>
      </div>

    </form>
  </div>

</div>

<?php render_page_end(); ?>