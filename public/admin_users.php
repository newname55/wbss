<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();
require_role(['super_user','admin']); // ✅ 管理者だけ

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

$pdo = db();

function now_jst(): string {
  $dt = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
  return $dt->format('Y-m-d H:i:s');
}
function fmt_dt(?string $s): string {
  if (!$s || $s === '0000-00-00 00:00:00') return '—';
  return $s;
}

/**
 * 最終ログイン方法＆時刻を決める
 * - LINE/Google は user_identities.last_login_at
 * - PW は users.last_login_at
 * 返り値: [method, datetime]
 */
function resolve_last_login(array $u): array {
  $cands = [];

  $pw = (string)($u['pw_last_login_at'] ?? '');
  if ($pw !== '' && $pw !== '0000-00-00 00:00:00') $cands[] = ['PW', $pw];

  $l = (string)($u['line_last_login_at'] ?? '');
  if ($l !== '' && $l !== '0000-00-00 00:00:00') $cands[] = ['LINE', $l];

  $g = (string)($u['google_last_login_at'] ?? '');
  if ($g !== '' && $g !== '0000-00-00 00:00:00') $cands[] = ['Google', $g];

  if (!$cands) return ['—', '—'];

  usort($cands, fn($a,$b) => strcmp((string)$b[1], (string)$a[1])); // desc
  return [$cands[0][0], $cands[0][1]];
}

/** users 一覧 */
function fetch_users(PDO $pdo, string $q = ''): array {
  $q = trim($q);

  $sql = "
    SELECT
      u.id,
      u.login_id,
      u.display_name,
      u.is_active,
      u.created_at,
      u.updated_at,

      u.last_login_at AS pw_last_login_at,
      CASE WHEN u.password_hash IS NULL OR u.password_hash = '' THEN 0 ELSE 1 END AS has_password,

      ui_line.provider_user_id AS line_user_id,
      ui_line.display_name     AS line_name,
      ui_line.picture_url      AS line_pic,
      ui_line.linked_at        AS line_linked_at,
      ui_line.last_login_at    AS line_last_login_at,

      ui_g.provider_user_id    AS google_user_id,
      ui_g.display_name        AS google_name,
      ui_g.picture_url         AS google_pic,
      ui_g.linked_at           AS google_linked_at,
      ui_g.last_login_at       AS google_last_login_at

    FROM users u
    LEFT JOIN user_identities ui_line
      ON ui_line.user_id = u.id
     AND ui_line.provider = 'line'
     AND ui_line.is_active = 1
    LEFT JOIN user_identities ui_g
      ON ui_g.user_id = u.id
     AND ui_g.provider = 'google'
     AND ui_g.is_active = 1

    WHERE (:q = '' OR u.login_id LIKE :q_like1 OR u.display_name LIKE :q_like2)

    ORDER BY u.id DESC
    LIMIT 500
  ";

  $st = $pdo->prepare($sql);

  $like = '%' . $q . '%';
  $st->execute([
    ':q'        => $q,
    ':q_like1'  => $like,
    ':q_like2'  => $like,
  ]);

  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** 単体 user */
function fetch_user(PDO $pdo, int $id): ?array {
  $st = $pdo->prepare("
    SELECT id, login_id, password_hash, display_name, is_active, created_at, updated_at, last_login_at
    FROM users
    WHERE id = ?
    LIMIT 1
  ");
  $st->execute([$id]);
  $u = $st->fetch(PDO::FETCH_ASSOC);
  return $u ?: null;
}

/** roles */
function fetch_roles(PDO $pdo): array {
  $st = $pdo->query("SELECT id, code, name FROM roles ORDER BY id ASC");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** stores */
function fetch_stores(PDO $pdo): array {
  $st = $pdo->query("SELECT id, name, is_active FROM stores ORDER BY is_active DESC, id ASC");
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/** user_roles */
function fetch_user_roles(PDO $pdo, int $user_id): array {
  $st = $pdo->prepare("
    SELECT
      ur.id AS ur_id,
      ur.user_id,
      ur.role_id,
      ur.store_id,
      r.code AS role_code,
      r.name AS role_name,
      s.name AS store_name
    FROM user_roles ur
    JOIN roles r ON r.id = ur.role_id
    LEFT JOIN stores s ON s.id = ur.store_id
    WHERE ur.user_id = ?
    ORDER BY
      (ur.store_id IS NULL) DESC, ur.store_id ASC, r.id ASC
  ");
  $st->execute([$user_id]);
  return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

function user_has_assignment(PDO $pdo, int $user_id, int $role_id, ?int $store_id): bool {
  if ($store_id === null) {
    $st = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role_id=? AND store_id IS NULL LIMIT 1");
    $st->execute([$user_id, $role_id]);
    return (bool)$st->fetchColumn();
  }
  $st = $pdo->prepare("SELECT 1 FROM user_roles WHERE user_id=? AND role_id=? AND store_id=? LIMIT 1");
  $st->execute([$user_id, $role_id, $store_id]);
  return (bool)$st->fetchColumn();
}

function fetch_identity(PDO $pdo, int $user_id, string $provider): ?array {
  $st = $pdo->prepare("
    SELECT provider_user_id, provider_email, display_name, picture_url, linked_at, last_login_at, is_active
    FROM user_identities
    WHERE user_id = ?
      AND provider = ?
      AND is_active = 1
    ORDER BY linked_at DESC
    LIMIT 1
  ");
  $st->execute([$user_id, $provider]);
  $r = $st->fetch(PDO::FETCH_ASSOC);
  return $r ?: null;
}

$msg = '';
$err = '';

$q = trim((string)($_GET['q'] ?? ''));
$edit_id = (int)($_GET['id'] ?? 0);

// DEBUG（確認できたら消す）
error_log('admin_users GET q=' . ($_GET['q'] ?? 'NULL'));

/** ========= POST actions ========= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'save_user') {
      $id = (int)($_POST['id'] ?? 0);
      $login_id = trim((string)($_POST['login_id'] ?? ''));
      $display_name = trim((string)($_POST['display_name'] ?? ''));
      $is_active = ((string)($_POST['is_active'] ?? '1') === '0') ? 0 : 1;
      $new_pass = (string)($_POST['new_password'] ?? '');

      if ($login_id === '' || $display_name === '') {
        throw new RuntimeException('login_id と 表示名 は必須です');
      }

      $pdo->beginTransaction();
      $now = now_jst();

      if ($id <= 0) {
        if ($new_pass === '') {
          throw new RuntimeException('新規作成はパスワード必須です（LINE限定ユーザー運用にするなら別対応にします）');
        }
        $hash = password_hash($new_pass, PASSWORD_DEFAULT);

        $chk = $pdo->prepare("SELECT 1 FROM users WHERE login_id=? LIMIT 1");
        $chk->execute([$login_id]);
        if ($chk->fetchColumn()) throw new RuntimeException('この login_id は既に使われています');

        $st = $pdo->prepare("
          INSERT INTO users
            (login_id, password_hash, display_name, is_active, created_at, updated_at)
          VALUES
            (?, ?, ?, ?, ?, ?)
        ");
        $st->execute([$login_id, $hash, $display_name, $is_active, $now, $now]);
        $id = (int)$pdo->lastInsertId();
      } else {
        $chk = $pdo->prepare("SELECT 1 FROM users WHERE login_id=? AND id<>? LIMIT 1");
        $chk->execute([$login_id, $id]);
        if ($chk->fetchColumn()) throw new RuntimeException('この login_id は既に使われています');

        if ($new_pass !== '') {
          $hash = password_hash($new_pass, PASSWORD_DEFAULT);
          $st = $pdo->prepare("
            UPDATE users
            SET login_id=?, display_name=?, is_active=?, password_hash=?, updated_at=?
            WHERE id=?
            LIMIT 1
          ");
          $st->execute([$login_id, $display_name, $is_active, $hash, $now, $id]);
        } else {
          $st = $pdo->prepare("
            UPDATE users
            SET login_id=?, display_name=?, is_active=?, updated_at=?
            WHERE id=?
            LIMIT 1
          ");
          $st->execute([$login_id, $display_name, $is_active, $now, $id]);
        }
      }

      $pdo->commit();
      header('Location: /seika-app/public/admin_users.php?id=' . $id . '&ok=1');
      exit;
    }

    if ($action === 'unlink_identity') {
      $user_id = (int)($_POST['user_id'] ?? 0);
      $provider = (string)($_POST['provider'] ?? '');
      if ($user_id <= 0) throw new RuntimeException('不正な指定です');
      if (!in_array($provider, ['line','google'], true)) throw new RuntimeException('不正なproviderです');

      $pdo->prepare("
        UPDATE user_identities
        SET is_active = 0
        WHERE user_id = ?
          AND provider = ?
      ")->execute([$user_id, $provider]);

      header('Location: /seika-app/public/admin_users.php?id=' . $user_id . '&ok=1');
      exit;
    }

    if ($action === 'add_role') {
      $user_id = (int)($_POST['user_id'] ?? 0);
      $role_id = (int)($_POST['role_id'] ?? 0);
      $store_raw = trim((string)($_POST['store_id'] ?? ''));
      $store_id = ($store_raw === '') ? null : (int)$store_raw;

      if ($user_id <= 0 || $role_id <= 0) throw new RuntimeException('不正な指定です');

      if (!user_has_assignment($pdo, $user_id, $role_id, $store_id)) {
        if ($store_id === null) {
          $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, store_id) VALUES (?, ?, NULL)");
          $st->execute([$user_id, $role_id]);
        } else {
          $st = $pdo->prepare("INSERT INTO user_roles (user_id, role_id, store_id) VALUES (?, ?, ?)");
          $st->execute([$user_id, $role_id, $store_id]);
        }
      }

      header('Location: /seika-app/public/admin_users.php?id=' . $user_id . '&ok=1');
      exit;
    }

    if ($action === 'del_role') {
      $user_id = (int)($_POST['user_id'] ?? 0);
      $ur_id = (int)($_POST['ur_id'] ?? 0);
      if ($user_id <= 0 || $ur_id <= 0) throw new RuntimeException('不正な指定です');

      $st = $pdo->prepare("DELETE FROM user_roles WHERE id=? AND user_id=? LIMIT 1");
      $st->execute([$ur_id, $user_id]);

      header('Location: /seika-app/public/admin_users.php?id=' . $user_id . '&ok=1');
      exit;
    }

    throw new RuntimeException('不明な操作です');
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $err = $e->getMessage();
  }
}

if ((int)($_GET['ok'] ?? 0) === 1 && $err === '') $msg = '保存しました';

$users = fetch_users($pdo, $q);
$roles = fetch_roles($pdo);
$stores = fetch_stores($pdo);

$editing = null;
$editing_roles = [];
$editing_line = null;
$editing_google = null;

if ($edit_id > 0) {
  $editing = fetch_user($pdo, $edit_id);
  if (!$editing) {
    $err = $err ?: 'ユーザーが見つかりません';
    $edit_id = 0;
  } else {
    $editing_roles = fetch_user_roles($pdo, $edit_id);
    $editing_line = fetch_identity($pdo, $edit_id, 'line');
    $editing_google = fetch_identity($pdo, $edit_id, 'google');
  }
}

render_page_start('ユーザー管理');
render_header('ユーザー管理', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '<a class="btn" href="/seika-app/public/admin_users.php">一覧</a>',
]);
?>
<div class="page">
  <div class="admin-wrap">

    <?php if ($msg): ?><div class="card" style="border-color:rgba(52,211,153,.35);"><?=h($msg)?></div><?php endif; ?>
    <?php if ($err): ?><div class="card" style="border-color:rgba(251,113,133,.45);"><?=h($err)?></div><?php endif; ?>

    <div class="card">
      <div class="admin-top">
        <div>
          <div style="font-weight:1000; font-size:18px;">👤 ユーザー一覧</div>
          <div class="muted" style="margin-top:4px;">haruto_core.users / roles / user_roles / user_identities</div>
        </div>

        <form method="get" class="searchRow" action="/seika-app/public/admin_users.php">
          <input
            class="btn"
            type="text"
            name="q"
            value="<?= h($q) ?>"
            placeholder="login_id / 表示名で検索"
            style="min-width:240px;"
            autocomplete="off"
          >
          <button class="btn btn-primary" type="submit">検索</button>
          <a class="btn" href="/seika-app/public/admin_users.php">クリア</a>
          <a class="btn" href="/seika-app/public/admin_users.php?id=0#edit">＋ 新規</a>
        </form>
      </div>
    </div>

    <div class="card users-card" style="margin-top:14px;">
      <table class="tbl tbl-users">
        <colgroup>
          <col style="width:180px;">
          <col style="width:260px;">
          <col style="width:100px;">
          <col style="width:80px;">
          <col style="width:210px;">
        </colgroup>
        <thead>
          <tr>
            <td data-label="ユーザー">
            <td data-label="連携">
            <td data-label="最終ログイン">
            <td data-label="状態">
            <td data-label="操作" class="ops">
          </tr>
        </thead>
        <tbody>
        <?php if (!$users): ?>
          <tr><td colspan="5" class="muted" style="padding:12px;">該当なし</td></tr>
        <?php else: ?>
          <?php foreach ($users as $u): ?>
            <?php
              $active = ((int)$u['is_active'] === 1);
              $hasPw = ((int)($u['has_password'] ?? 0) === 1);

              $lineLinked = !empty($u['line_user_id']);
              $googleLinked = !empty($u['google_user_id']);

              [$lastMethod, $lastAt] = resolve_last_login($u);
              $dotColor = ($lastMethod === 'LINE') ? 'var(--ok)'
                        : (($lastMethod === 'Google') ? 'var(--accent)'
                        : (($lastMethod === 'PW') ? 'var(--warn)' : 'var(--line)'));
            ?>
            <tr>
              <!-- ユーザー -->
              <td>
                <div class="u-main">
                  <div class="u-id">#<?= (int)$u['id'] ?></div>
                  <div class="u-meta">
                    <div class="u-login"><?= h((string)$u['login_id']) ?></div>
                    <div class="u-name"><?= h((string)$u['display_name']) ?></div>
                    <div class="u-sub muted">
                      更新: <?= h((string)$u['updated_at']) ?> / PW: <?= $hasPw ? 'あり' : 'なし' ?>
                    </div>
                  </div>
                </div>
              </td>

              <!-- 連携 -->
              <td>
                <div class="links">
                  <div class="linkItem">
                    <div class="linkHead">
                      <span class="pill <?= $lineLinked ? 'on' : 'off' ?>">LINE</span>
                      <span class="muted"><?= $lineLinked ? '連携済' : '未連携' ?></span>
                    </div>
                    <?php if ($lineLinked): ?>
                      <div class="linkBody">
                        <?php if (!empty($u['line_pic'])): ?>
                          <img class="id-pic" src="<?= h((string)$u['line_pic']) ?>" alt="">
                        <?php else: ?>
                          <div class="id-pic id-pic--dummy">💬</div>
                        <?php endif; ?>
                        <div class="id-meta">
                          <div class="id-name"><?= h((string)($u['line_name'] ?? 'LINE')) ?></div>
                          <div class="id-sub">最終: <?= h(fmt_dt($u['line_last_login_at'] ?? null)) ?></div>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>

                  <div class="linkItem">
                    <div class="linkHead">
                      <span class="pill <?= $googleLinked ? 'on' : 'off' ?>">Google</span>
                      <span class="muted"><?= $googleLinked ? '連携済' : '未連携' ?></span>
                    </div>
                    <?php if ($googleLinked): ?>
                      <div class="linkBody">
                        <?php if (!empty($u['google_pic'])): ?>
                          <img class="id-pic" src="<?= h((string)$u['google_pic']) ?>" alt="">
                        <?php else: ?>
                          <div class="id-pic id-pic--dummy">G</div>
                        <?php endif; ?>
                        <div class="id-meta">
                          <div class="id-name"><?= h((string)($u['google_name'] ?? 'Google')) ?></div>
                          <div class="id-sub">最終: <?= h(fmt_dt($u['google_last_login_at'] ?? null)) ?></div>
                        </div>
                      </div>
                    <?php endif; ?>
                  </div>
                </div>
              </td>

              <!-- 最終ログイン -->
              <td>
                <span class="badge">
                  <span class="dot" style="background:<?= h($dotColor) ?>;"></span>
                  <?= h($lastMethod) ?>
                </span>
                <div class="muted" style="font-size:12px; margin-top:6px;"><?= h($lastAt) ?></div>
              </td>

              <!-- 状態 -->
              <td>
                <span class="badge" style="background:<?= $active ? 'rgba(52,211,153,.14)' : 'rgba(251,113,133,.16)' ?>;">
                  <span class="dot" style="background:<?= $active ? 'var(--ok)' : 'var(--ng)' ?>;"></span>
                  <?= $active ? '有効' : '無効' ?>
                </span>
              </td>

              <!-- 操作 -->
              <td class="ops">
                <a class="btn" href="/seika-app/public/admin_users.php?id=<?= (int)$u['id'] ?>#edit">編集</a>

                <?php if (!$lineLinked): ?>
                  <a class="btn" href="/seika-app/public/line_login_start.php?link_user_id=<?= (int)$u['id'] ?>">L連携</a>
                <?php else: ?>
                  <form method="post" onsubmit="return confirm('LINE連携を解除しますか？');" style="margin:0; display:inline;">
                    <input type="hidden" name="action" value="unlink_identity">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="provider" value="line">
                    <button class="btn btn-warn" type="submit">L解除</button>
                  </form>
                <?php endif; ?>

                <?php if (!$googleLinked): ?>
                  <a class="btn" href="/seika-app/public/google_login_start.php?link_user_id=<?= (int)$u['id'] ?>">G連携</a>
                <?php else: ?>
                  <form method="post" onsubmit="return confirm('Google連携を解除しますか？');" style="margin:0; display:inline;">
                    <input type="hidden" name="action" value="unlink_identity">
                    <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                    <input type="hidden" name="provider" value="google">
                    <button class="btn btn-warn" type="submit">G解除</button>
                  </form>
                <?php endif; ?>
              </td>
            </tr>
          <?php endforeach; ?>
        <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div id="edit" class="card" style="margin-top:14px;">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div style="font-weight:1000; font-size:16px;">✍️ <?= $editing ? 'ユーザー編集' : '新規作成' ?></div>

        <?php if ($editing): ?>
          <?php
            $lineOk = (bool)$editing_line;
            $gooOk  = (bool)$editing_google;
            $pwOk   = !empty($editing['password_hash']);

            $uTmp = [
              'pw_last_login_at' => (string)($editing['last_login_at'] ?? ''),
              'line_last_login_at' => (string)($editing_line['last_login_at'] ?? ''),
              'google_last_login_at' => (string)($editing_google['last_login_at'] ?? ''),
            ];
            [$m2,$t2] = resolve_last_login($uTmp);
            $dot2 = ($m2 === 'LINE') ? 'var(--ok)'
                  : (($m2 === 'Google') ? 'var(--accent)'
                  : (($m2 === 'PW') ? 'var(--warn)' : 'var(--line)'));
          ?>
          <div style="display:flex; gap:8px; flex-wrap:wrap; align-items:center;">
            <span class="badge"><span class="dot" style="background:<?= $pwOk ? 'var(--ok)' : 'var(--warn)' ?>;"></span>PW <?= $pwOk ? 'あり' : 'なし' ?></span>
            <span class="badge"><span class="dot" style="background:<?= $lineOk ? 'var(--ok)' : 'var(--warn)' ?>;"></span>LINE <?= $lineOk ? '連携済' : '未連携' ?></span>
            <span class="badge"><span class="dot" style="background:<?= $gooOk ? 'var(--ok)' : 'var(--warn)' ?>;"></span>Google <?= $gooOk ? '連携済' : '未連携' ?></span>
            <span class="badge"><span class="dot" style="background:<?= h($dot2) ?>;"></span>最終: <?= h($m2) ?> <?= h($t2) ?></span>
          </div>
        <?php endif; ?>
      </div>

      <div class="muted" style="margin-top:6px;">
        ※ パスワードは「入力した時だけ」更新（空なら変更なし）
      </div>

      <?php
        $u0 = $editing ?: [
          'id' => 0,
          'login_id' => '',
          'display_name' => '',
          'is_active' => 1,
        ];
      ?>

      <form method="post" style="margin-top:12px;">
        <input type="hidden" name="action" value="save_user">
        <input type="hidden" name="id" value="<?= (int)$u0['id'] ?>">

        <div style="display:grid; grid-template-columns: 1fr 1fr; gap:10px;">
          <div>
            <label class="muted">login_id（必須）</label><br>
            <input class="btn" style="width:100%;" name="login_id" value="<?= h((string)$u0['login_id']) ?>" required>
          </div>

          <div>
            <label class="muted">表示名（必須）</label><br>
            <input class="btn" style="width:100%;" name="display_name" value="<?= h((string)$u0['display_name']) ?>" required>
          </div>

          <div>
            <label class="muted">新しいパスワード（任意）</label><br>
            <input class="btn" style="width:100%;" type="password" name="new_password" value=""
                   placeholder="<?= ((int)$u0['id']>0) ? '空なら変更なし' : '新規作成は必須' ?>">
          </div>

          <div>
            <label class="muted">状態</label><br>
            <select class="btn" name="is_active" style="width:100%;">
              <option value="1" <?= ((int)$u0['is_active']===1)?'selected':'' ?>>有効</option>
              <option value="0" <?= ((int)$u0['is_active']===0)?'selected':'' ?>>無効</option>
            </select>
          </div>
        </div>

        <div style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap;">
          <button class="btn btn-primary" type="submit">保存</button>
          <?php if ($editing): ?>
            <a class="btn" href="/seika-app/public/admin_users.php?id=<?= (int)$editing['id'] ?>#roles">権限/店舗へ</a>
          <?php endif; ?>
        </div>
      </form>

      <?php if ($editing): ?>
        <div class="card" style="margin-top:12px; padding:12px;">
          <div style="font-weight:1000; margin-bottom:8px;">🔗 連携操作（このユーザー）</div>

          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <!-- LINE -->
            <?php if ($editing_line): ?>
              <form method="post" onsubmit="return confirm('LINE連携を解除しますか？');" style="margin:0;">
                <input type="hidden" name="action" value="unlink_identity">
                <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                <input type="hidden" name="provider" value="line">
                <button class="btn btn-warn" type="submit">LINE解除</button>
              </form>
              <a class="btn" href="/seika-app/public/line_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">LINE再リンク</a>
              <div class="muted" style="align-self:center; font-size:12px;">
                最終: <?= h(fmt_dt((string)($editing_line['last_login_at'] ?? ''))) ?>
              </div>
            <?php else: ?>
              <a class="btn" href="/seika-app/public/line_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">LINE連携</a>
            <?php endif; ?>

            <!-- Google -->
            <?php if ($editing_google): ?>
              <form method="post" onsubmit="return confirm('Google連携を解除しますか？');" style="margin:0;">
                <input type="hidden" name="action" value="unlink_identity">
                <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                <input type="hidden" name="provider" value="google">
                <button class="btn btn-warn" type="submit">Google解除</button>
              </form>
              <a class="btn" href="/seika-app/public/google_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">Google再リンク</a>
              <div class="muted" style="align-self:center; font-size:12px;">
                最終: <?= h(fmt_dt((string)($editing_google['last_login_at'] ?? ''))) ?>
              </div>
            <?php else: ?>
              <a class="btn" href="/seika-app/public/google_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">Google連携</a>
            <?php endif; ?>
          </div>

          <div class="muted" style="margin-top:10px; font-size:12px;">
            ※「連携」は、管理者が本人の端末でログインして紐付ける方式（運用事故が少ない）
          </div>
        </div>
      <?php endif; ?>
    </div>

    <?php if ($editing): ?>
    <div id="roles" class="card" style="margin-top:14px;">
      <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
        <div>
          <div style="font-weight:1000; font-size:16px;">🧩 権限割当（roles / user_roles）</div>
          <div class="muted" style="margin-top:6px;">
            「共通(全店)」= store_id NULL / 「店舗指定」= store_id 付き
          </div>
        </div>
      </div>

      <form method="post" style="margin-top:12px; display:grid; grid-template-columns: 1fr 1fr 1fr; gap:10px; align-items:end;">
        <input type="hidden" name="action" value="add_role">
        <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">

        <div>
          <label class="muted">ロール</label><br>
          <select class="btn" name="role_id" style="width:100%;" required>
            <option value="">選択</option>
            <?php foreach ($roles as $r): ?>
              <option value="<?= (int)$r['id'] ?>"><?= h((string)$r['code']) ?> / <?= h((string)$r['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="muted">店舗</label><br>
          <select class="btn" name="store_id" style="width:100%;">
            <option value="">共通（全店）</option>
            <?php foreach ($stores as $s): ?>
              <option value="<?= (int)$s['id'] ?>"><?= h((string)$s['name']) ?><?= ((int)$s['is_active']===1)?'':'（無効）' ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div>
          <label class="muted">追加</label><br>
          <button class="btn btn-primary" type="submit">割当を追加</button>
        </div>
      </form>

      <div class="table-scroll" style="margin-top:12px;">
        <table class="tbl" style="min-width:860px;">
        <thead>
          <tr>
            <th style="width:320px;">ユーザー</th>
            <th>連携</th>
            <th style="width:210px;">最終ログイン</th>
            <th style="width:110px;">状態</th>
            <th style="width:260px;">操作</th>
          </tr>
        </thead>
          <tbody>
            <?php if (!$editing_roles): ?>
              <tr><td colspan="4" class="muted" style="padding:12px;">割当なし</td></tr>
            <?php else: ?>
              <?php foreach ($editing_roles as $ar): ?>
                <tr>
                  <td style="font-weight:1000;"><?= h((string)$ar['role_code']) ?></td>
                  <td><?= h((string)$ar['role_name']) ?></td>
                  <td>
                    <?php if ($ar['store_id'] === null): ?>
                      <span class="badge"><span class="dot" style="background:var(--accent);"></span>共通（全店）</span>
                    <?php else: ?>
                      <span class="badge"><span class="dot" style="background:var(--ok);"></span><?= h((string)($ar['store_name'] ?? ('#'.$ar['store_id']))) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <form method="post" onsubmit="return confirm('この割当を削除しますか？');" style="margin:0;">
                      <input type="hidden" name="action" value="del_role">
                      <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                      <input type="hidden" name="ur_id" value="<?= (int)$ar['ur_id'] ?>">
                      <button class="btn" style="min-height:auto; padding:6px 10px;" type="submit">削除</button>
                    </form>
                  </td>
                </tr>
              <?php endforeach; ?>
            <?php endif; ?>
          </tbody>
        </table>
      </div>

      <div class="muted" style="margin-top:10px;">
        TIP: 同じロールを「共通」と「店舗指定」で二重に付けると実装側が複雑になるので避けるのが無難。
      </div>
    </div>
    <?php endif; ?>

  </div>
</div>

<style>
/* =========================
   admin_users 専用（このページだけ）
========================= */
.admin-wrap{
  width: min(1024px, calc(100% - 32px));
  margin: 0 auto;
}
.admin-top{
  display:flex;
  justify-content:space-between;
  gap:12px;
  flex-wrap:wrap;
  align-items:flex-end;
}
.searchRow{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}

/* table */
.tbl{
  width:100%;
  border-collapse:separate;
  border-spacing:0;
  font-size:14px;
  table-layout: fixed;
}
.tbl thead th{
  position: sticky;
  top: 0;
  z-index: 1;
  background: color-mix(in srgb, var(--cardA) 80%, transparent);
  backdrop-filter: blur(6px);
}
.tbl th{
  text-align:left;
  padding:12px 10px;
  border-bottom:2px solid var(--line);
  white-space:nowrap;
  opacity:.9;
}
.tbl td{
  padding:12px 10px;
  border-bottom:1px solid var(--line);
  vertical-align:top;
  overflow:hidden;
  text-overflow:ellipsis;
}
.tbl tbody tr:hover{ background: rgba(255,255,255,.04); }

/* badge */
.badge{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:6px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.06);
  white-space:nowrap;
}
.badge .dot{ width:8px; height:8px; border-radius:999px; }

/* ops */
.ops{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}
.ops .btn{
  min-height:auto;
  padding:6px 10px;
}
@media (min-width: 1300px){
  .ops{ flex-wrap:nowrap; }
}

/* user cell */
.u-main{ display:flex; gap:10px; align-items:flex-start; }
.u-id{
  font-weight:1000; opacity:.9;
  border:1px solid var(--line);
  background:rgba(255,255,255,.06);
  padding:6px 10px; border-radius:12px;
  white-space:nowrap;
}
.u-login{ font-weight:1000; }
.u-name{ margin-top:2px; }
.u-sub{ font-size:12px; margin-top:6px; }

/* links */
.links{
  display:grid;
  grid-template-columns: 1fr 1fr;
  gap:10px;
}
@media (max-width: 800px){
  .links{ grid-template-columns: 1fr; }
}

.linkItem{
  border:1px solid var(--line);
  border-radius:14px;
  padding:10px;
  background: rgba(255,255,255,.04);
  min-width:0;
}
.linkHead{
  display:flex;
  align-items:center;
  gap:8px;
  justify-content:space-between;
}
.pill{
  font-weight:1000;
  font-size:12px;
  padding:4px 8px;
  border-radius:999px;
  border:1px solid var(--line);
  white-space:nowrap;
}
.pill.on{ background:rgba(52,211,153,.12); }
.pill.off{ background:rgba(251,113,133,.10); opacity:.85; }

.linkBody{
  display:flex;
  gap:10px;
  align-items:center;
  margin-top:8px;
  min-width:0;
}
.id-pic{
  width:28px; height:28px; border-radius:50%;
  border:1px solid var(--line);
  background:#fff;
  object-fit:cover;
  flex:0 0 auto;
}
.id-pic--dummy{
  display:flex; align-items:center; justify-content:center;
  font-weight:1000;
  background: rgba(255,255,255,.12);
  color: var(--txt);
}
.id-meta{ line-height:1.2; min-width:0; }
.id-name{
  font-weight:1000; font-size:13px;
  overflow:hidden; text-overflow:ellipsis; white-space:nowrap;
}
.id-sub{ font-size:11px; color: var(--muted); margin-top:4px; }
/* ====== users table only: iPad/スマホ最適化（PCデザインはそのまま） ====== */
.tbl.tbl-users td{ min-width:0; } /* はみ出し防止 */

/* iPad以下はカード表示に変形（横スク・崩れ防止） */
@media (max-width: 640px){
  .tbl.tbl-users{ border-spacing:0 10px; }
  .tbl.tbl-users thead{ display:none; }

  /* ★追加：ブロック化した要素を必ず横幅100%にする */
  .tbl.tbl-users,
  .tbl.tbl-users tbody{ 
    display:block;
    width:100%;
  }

  .tbl.tbl-users tbody tr{
    width:100%;
    box-sizing:border-box;
  }

  .tbl.tbl-users tbody td{
    width:100%;
    box-sizing:border-box;
  }
  /* ★追加：colgroupの固定幅が残ると変な縮み方するので無効化 */
  .tbl.tbl-users colgroup{ display:none; }
}

/* さらに小さいスマホはラベル幅を縮める */
@media (max-width: 860px){
  .tbl.tbl-users tbody td::before{ flex-basis:72px; }
}
/* 一覧カードだけ：PCで幅を縮めた時は横スクロールに逃がす */
.card.users-card{
  overflow-x:auto;
  -webkit-overflow-scrolling: touch;
}

/* 横スクロール時に変な余白が出ないように */
.card.users-card > .tbl{
  min-width: 830px;          /* colgroup合計(830) + 余白ぶん */
}

/* ついで：iPad/スマホのカード表示時は min-width を解除 */
@media (max-width: 640px){
  .card.users-card > .tbl{
    min-width: 0;
  }
}
</style>

<?php render_page_end(); ?>