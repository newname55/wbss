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

function build_admin_users_url(array $overrides = []): string {
  $params = [];

  $q = trim((string)($_GET['q'] ?? ''));
  if ($q !== '') {
    $params['q'] = $q;
  }

  $scope = trim((string)($_GET['scope'] ?? 'all'));
  if ($scope !== '' && $scope !== 'all') {
    $params['scope'] = $scope;
  }

  $page = max(1, (int)($_GET['page'] ?? 1));
  if ($page > 1) {
    $params['page'] = $page;
  }

  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '' || $value === 'all') {
      unset($params[$key]);
      continue;
    }
    $params[$key] = $value;
  }

  $qs = http_build_query($params);
  return '/wbss/public/admin_users.php' . ($qs !== '' ? ('?' . $qs) : '');
}

function fetch_user_role_summary_map(PDO $pdo, array $userIds): array {
  if (!$userIds) {
    return [];
  }

  $placeholders = implode(',', array_fill(0, count($userIds), '?'));
  $st = $pdo->prepare("
    SELECT
      ur.user_id,
      ur.store_id,
      s.name AS store_name
    FROM user_roles ur
    LEFT JOIN stores s ON s.id = ur.store_id
    WHERE ur.user_id IN ($placeholders)
  ");
  $st->execute(array_values($userIds));
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $map = [];
  foreach ($userIds as $userId) {
    $map[(int)$userId] = [
      'has_any' => false,
      'has_global' => false,
      'store_ids' => [],
      'store_names' => [],
      'store_count' => 0,
    ];
  }

  foreach ($rows as $row) {
    $uid = (int)($row['user_id'] ?? 0);
    if ($uid <= 0) {
      continue;
    }
    if (!isset($map[$uid])) {
      $map[$uid] = [
        'has_any' => false,
        'has_global' => false,
        'store_ids' => [],
        'store_names' => [],
        'store_count' => 0,
      ];
    }

    $map[$uid]['has_any'] = true;
    $storeId = $row['store_id'] === null ? null : (int)$row['store_id'];
    if ($storeId === null) {
      $map[$uid]['has_global'] = true;
      continue;
    }

    $map[$uid]['store_ids'][$storeId] = true;
    $name = trim((string)($row['store_name'] ?? ''));
    $map[$uid]['store_names'][$storeId] = $name !== '' ? $name : ('店舗#' . $storeId);
  }

  foreach ($map as $uid => $summary) {
    $storeIds = array_keys($summary['store_ids']);
    sort($storeIds);
    $storeNames = [];
    foreach ($storeIds as $storeId) {
      $storeNames[] = $summary['store_names'][$storeId] ?? ('店舗#' . $storeId);
    }
    $map[$uid]['store_ids'] = $storeIds;
    $map[$uid]['store_names'] = $storeNames;
    $map[$uid]['store_count'] = count($storeIds);
  }

  return $map;
}

$msg = '';
$err = '';

$q = trim((string)($_GET['q'] ?? ''));
$edit_id = (int)($_GET['id'] ?? 0);
$scope = trim((string)($_GET['scope'] ?? 'all'));
$page = max(1, (int)($_GET['page'] ?? 1));
if ($scope === '') {
  $scope = 'all';
}

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
      header('Location: ' . build_admin_users_url(['id' => $id, 'ok' => 1]));
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

      header('Location: ' . build_admin_users_url(['id' => $user_id, 'ok' => 1]));
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

      header('Location: ' . build_admin_users_url(['id' => $user_id, 'ok' => 1]));
      exit;
    }

    if ($action === 'del_role') {
      $user_id = (int)($_POST['user_id'] ?? 0);
      $ur_id = (int)($_POST['ur_id'] ?? 0);
      if ($user_id <= 0 || $ur_id <= 0) throw new RuntimeException('不正な指定です');

      $st = $pdo->prepare("DELETE FROM user_roles WHERE id=? AND user_id=? LIMIT 1");
      $st->execute([$ur_id, $user_id]);

      header('Location: ' . build_admin_users_url(['id' => $user_id, 'ok' => 1]));
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
$userIds = array_map(static fn(array $row): int => (int)$row['id'], $users);
$userRoleSummaries = fetch_user_role_summary_map($pdo, $userIds);

$filterTabs = [];
$filterTabs[] = [
  'key' => 'all',
  'label' => 'すべて',
  'count' => count($users),
  'href' => build_admin_users_url(['scope' => 'all', 'page' => null]),
];
foreach ($stores as $store) {
  $storeId = (int)$store['id'];
  $count = 0;
  foreach ($users as $row) {
    $summary = $userRoleSummaries[(int)$row['id']] ?? null;
    if ($summary && in_array($storeId, $summary['store_ids'], true)) {
      $count++;
    }
  }
  $filterTabs[] = [
    'key' => 'store:' . $storeId,
    'label' => (string)$store['name'],
    'count' => $count,
    'href' => build_admin_users_url(['scope' => 'store:' . $storeId, 'page' => null]),
  ];
}
$multiCount = 0;
$noneCount = 0;
foreach ($users as $row) {
  $summary = $userRoleSummaries[(int)$row['id']] ?? null;
  if (!$summary || !$summary['has_any']) {
    $noneCount++;
    continue;
  }
  if (($summary['store_count'] ?? 0) >= 2) {
    $multiCount++;
  }
}
$filterTabs[] = [
  'key' => 'multi',
  'label' => '複数',
  'count' => $multiCount,
  'href' => build_admin_users_url(['scope' => 'multi', 'page' => null]),
];
$filterTabs[] = [
  'key' => 'none',
  'label' => 'なし',
  'count' => $noneCount,
  'href' => build_admin_users_url(['scope' => 'none', 'page' => null]),
];

$filteredUsers = [];
foreach ($users as $row) {
  $uid = (int)$row['id'];
  $summary = $userRoleSummaries[$uid] ?? [
    'has_any' => false,
    'has_global' => false,
    'store_ids' => [],
    'store_names' => [],
    'store_count' => 0,
  ];

  $match = true;
  if ($scope === 'multi') {
    $match = (($summary['store_count'] ?? 0) >= 2);
  } elseif ($scope === 'none') {
    $match = empty($summary['has_any']);
  } elseif (preg_match('/^store:(\d+)$/', $scope, $m)) {
    $match = in_array((int)$m[1], $summary['store_ids'], true);
  }

  if ($match) {
    $filteredUsers[] = $row;
  }
}
$filteredUserCount = count($filteredUsers);
$filteredUsersForStats = $filteredUsers;
$perPage = 24;
$totalPages = max(1, (int)ceil($filteredUserCount / $perPage));
if ($page > $totalPages) {
  $page = $totalPages;
}
$pageStart = $filteredUserCount > 0 ? (($page - 1) * $perPage) + 1 : 0;
$pageEnd = min($filteredUserCount, $page * $perPage);
$paginationWindowStart = max(1, $page - 2);
$paginationWindowEnd = min($totalPages, $page + 2);
$users = array_slice($filteredUsers, ($page - 1) * $perPage, $perPage);

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
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '<a class="btn" href="/wbss/public/admin_users.php">一覧</a>',
]);
$currentPageCount = count($users);
$userCount = $filteredUserCount;
$filteredCount = $filteredUserCount;
$allUserCount = count($userIds);
$activeCount = 0;
$lineLinkedCount = 0;
$googleLinkedCount = 0;
$lineUnlinkedCount = 0;
foreach ($filteredUsersForStats as $row) {
  $isActive = ((int)($row['is_active'] ?? 0) === 1);
  $lineLinked = !empty($row['line_user_id']);
  $googleLinked = !empty($row['google_user_id']);
  if ($isActive) $activeCount++;
  if ($lineLinked) $lineLinkedCount++; else $lineUnlinkedCount++;
  if ($googleLinked) $googleLinkedCount++;
}
$u0 = $editing ?: [
  'id' => 0,
  'login_id' => '',
  'display_name' => '',
  'is_active' => 1,
];
$editingSummary = null;
if ($editing) {
  $lineOk = (bool)$editing_line;
  $gooOk  = (bool)$editing_google;
  $pwOk   = !empty($editing['password_hash']);
  $uTmp = [
    'pw_last_login_at' => (string)($editing['last_login_at'] ?? ''),
    'line_last_login_at' => (string)($editing_line['last_login_at'] ?? ''),
    'google_last_login_at' => (string)($editing_google['last_login_at'] ?? ''),
  ];
  [$m2, $t2] = resolve_last_login($uTmp);
  $editingSummary = [
    'line_ok' => $lineOk,
    'google_ok' => $gooOk,
    'pw_ok' => $pwOk,
    'last_method' => $m2,
    'last_at' => $t2,
  ];
}
?>
<div class="page">
  <div class="admin-wrap admin-users-shell">

    <?php if ($msg): ?>
      <div class="admin-flash is-success"><?= h($msg) ?></div>
    <?php endif; ?>
    <?php if ($err): ?>
      <div class="admin-flash is-error"><?= h($err) ?></div>
    <?php endif; ?>

    <section class="admin-hero">
      <div class="admin-hero-main">
        <div class="hero-kicker">Admin Console</div>
        <span class="hero-badge">アカウント管理</span>
        <h1>ユーザー、連携、権限をひとつの画面で整える</h1>
        <p>LINE と Google の連携状況、ログイン手段、権限付与をまとめて見られるように再構成しています。</p>
        <div class="hero-actions">
          <a class="admin-pill-link" href="<?= h(build_admin_users_url(['id' => 0])) ?>#edit">＋ 新規ユーザー</a>
          <a class="admin-pill-link" href="#edit">編集エリアへ</a>
        </div>
      </div>

      <div class="admin-hero-side">
        <form method="get" class="admin-search" action="/wbss/public/admin_users.php">
          <input type="hidden" name="scope" value="<?= h($scope) ?>">
          <label class="admin-label">ユーザー検索</label>
          <div class="admin-search-row">
            <input
              class="admin-input"
              type="text"
              name="q"
              value="<?= h($q) ?>"
              placeholder="login_id / 表示名で検索"
              autocomplete="off"
            >
            <button class="btn btn-primary" type="submit">検索</button>
          </div>
          <div class="admin-search-actions">
            <a class="btn" href="<?= h(build_admin_users_url(['q' => null, 'page' => null])) ?>">クリア</a>
            <a class="btn" href="<?= h(build_admin_users_url(['id' => 0])) ?>#edit">新規</a>
          </div>
        </form>
      </div>
    </section>

    <section class="admin-kpis">
      <div class="admin-kpi-card">
        <div class="admin-kpi-label">ユーザー総数</div>
        <div class="admin-kpi-value"><?= number_format($userCount) ?></div>
      </div>
      <div class="admin-kpi-card">
        <div class="admin-kpi-label">有効ユーザー</div>
        <div class="admin-kpi-value"><?= number_format($activeCount) ?></div>
      </div>
      <div class="admin-kpi-card">
        <div class="admin-kpi-label">LINE連携済み</div>
        <div class="admin-kpi-value"><?= number_format($lineLinkedCount) ?></div>
      </div>
      <div class="admin-kpi-card">
        <div class="admin-kpi-label">LINE未連携</div>
        <div class="admin-kpi-value"><?= number_format($lineUnlinkedCount) ?></div>
      </div>
      <div class="admin-kpi-card">
        <div class="admin-kpi-label">Google連携済み</div>
        <div class="admin-kpi-value"><?= number_format($googleLinkedCount) ?></div>
      </div>
    </section>

    <div class="admin-layout">
      <main class="admin-main">
        <section class="admin-section">
          <div class="admin-section-head">
            <div>
              <h2>ユーザー一覧</h2>
              <p>権限の付与先で切り替えながら、連携状態と最終ログインをまとめて確認できます。</p>
            </div>
          </div>

          <div class="admin-filter-tabs">
            <?php foreach ($filterTabs as $tab): ?>
              <?php $isCurrentTab = ($scope === $tab['key']) || ($scope === 'all' && $tab['key'] === 'all'); ?>
              <a class="admin-filter-tab<?= $isCurrentTab ? ' is-active' : '' ?>" href="<?= h($tab['href']) ?>">
                <span><?= h($tab['label']) ?></span>
                <span class="admin-filter-tab-count"><?= number_format((int)$tab['count']) ?></span>
              </a>
            <?php endforeach; ?>
          </div>

          <div class="admin-list-meta">
            <?php if ($filteredCount > 0): ?>
              <span><?= number_format($pageStart) ?> - <?= number_format($pageEnd) ?> / <?= number_format($filteredCount) ?>件</span>
            <?php else: ?>
              <span>0件</span>
            <?php endif; ?>
            <?php if ($scope !== 'all'): ?>
              <span>全体 <?= number_format($allUserCount) ?>件</span>
            <?php endif; ?>
            <?php if ($totalPages > 1): ?>
              <span><?= number_format($page) ?> / <?= number_format($totalPages) ?>ページ</span>
            <?php endif; ?>
          </div>

          <?php if (!$users): ?>
            <div class="admin-empty">該当するユーザーはいません。</div>
          <?php else: ?>
            <nav class="admin-pagination" aria-label="ユーザー一覧ページ">
              <a class="admin-page-btn<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : h(build_admin_users_url(['page' => $page - 1])) ?>">前へ</a>
              <?php for ($p = $paginationWindowStart; $p <= $paginationWindowEnd; $p++): ?>
                <a class="admin-page-btn<?= $p === $page ? ' is-current' : '' ?>" href="<?= h(build_admin_users_url(['page' => $p])) ?>"><?= number_format($p) ?></a>
              <?php endfor; ?>
              <a class="admin-page-btn<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : h(build_admin_users_url(['page' => $page + 1])) ?>">次へ</a>
            </nav>

            <div class="admin-user-grid">
              <?php foreach ($users as $u): ?>
                <?php
                  $active = ((int)$u['is_active'] === 1);
                  $lineLinked = !empty($u['line_user_id']);
                  $googleLinked = !empty($u['google_user_id']);
                  $isSelected = ($editing && (int)$editing['id'] === (int)$u['id']);
                ?>
                <article class="admin-user-card<?= $isSelected ? ' is-selected' : '' ?>">
                  <div class="admin-user-main">
                    <div class="admin-user-name"><?= h((string)$u['display_name']) ?></div>
                    <div class="admin-user-login">USER ID: <?= h((string)$u['login_id']) ?></div>
                  </div>

                  <div class="admin-user-meta">
                    <div class="admin-user-id">#<?= (int)$u['id'] ?></div>
                    <div class="admin-user-status <?= $active ? 'is-active' : 'is-inactive' ?>">
                      <?= $active ? '有効' : '無効' ?>
                    </div>
                  </div>

                  <div class="admin-compact-links" aria-label="連携状態">
                    <div class="admin-compact-link <?= $lineLinked ? 'is-on' : 'is-off' ?>">
                      <span class="admin-compact-link-label">LINE</span>
                      <span class="admin-compact-link-state"><?= $lineLinked ? '連携済' : '未連携' ?></span>
                    </div>
                    <div class="admin-compact-link <?= $googleLinked ? 'is-on' : 'is-off' ?>">
                      <span class="admin-compact-link-label">Google</span>
                      <span class="admin-compact-link-state"><?= $googleLinked ? '連携済' : '未連携' ?></span>
                    </div>
                  </div>

                  <div class="admin-user-actions">
                    <a class="btn" href="<?= h(build_admin_users_url(['id' => (int)$u['id']])) ?>#edit">編集</a>
                  </div>
                </article>
              <?php endforeach; ?>
            </div>

            <nav class="admin-pagination is-bottom" aria-label="ユーザー一覧ページ下部">
              <a class="admin-page-btn<?= $page <= 1 ? ' is-disabled' : '' ?>" href="<?= $page <= 1 ? '#' : h(build_admin_users_url(['page' => $page - 1])) ?>">前へ</a>
              <?php for ($p = $paginationWindowStart; $p <= $paginationWindowEnd; $p++): ?>
                <a class="admin-page-btn<?= $p === $page ? ' is-current' : '' ?>" href="<?= h(build_admin_users_url(['page' => $p])) ?>"><?= number_format($p) ?></a>
              <?php endfor; ?>
              <a class="admin-page-btn<?= $page >= $totalPages ? ' is-disabled' : '' ?>" href="<?= $page >= $totalPages ? '#' : h(build_admin_users_url(['page' => $page + 1])) ?>">次へ</a>
            </nav>
          <?php endif; ?>
        </section>
      </main>

      <aside class="admin-side">
        <section id="edit" class="admin-section admin-section-sticky">
          <div class="admin-section-head">
            <div>
              <h2><?= $editing ? 'ユーザー編集' : '新規作成' ?></h2>
              <p>基本情報を更新して、必要ならこのまま連携と権限付与へ進めます。</p>
            </div>
          </div>

          <?php if ($editing && $editingSummary): ?>
            <div class="admin-summary-chips">
              <span class="admin-badge">PW <?= $editingSummary['pw_ok'] ? 'あり' : 'なし' ?></span>
              <span class="admin-badge">LINE <?= $editingSummary['line_ok'] ? '連携済' : '未連携' ?></span>
              <span class="admin-badge">Google <?= $editingSummary['google_ok'] ? '連携済' : '未連携' ?></span>
              <span class="admin-badge">最終 <?= h($editingSummary['last_method']) ?></span>
            </div>
          <?php endif; ?>

          <div class="admin-note">パスワードは入力したときだけ更新されます。空欄なら変更しません。</div>

          <form method="post" class="admin-form">
            <input type="hidden" name="action" value="save_user">
            <input type="hidden" name="id" value="<?= (int)$u0['id'] ?>">

            <div class="admin-form-grid">
              <div class="admin-field">
                <label class="admin-label">login_id（必須）</label>
                <input class="admin-input" name="login_id" value="<?= h((string)$u0['login_id']) ?>" required>
              </div>
              <div class="admin-field">
                <label class="admin-label">表示名（必須）</label>
                <input class="admin-input" name="display_name" value="<?= h((string)$u0['display_name']) ?>" required>
              </div>
              <div class="admin-field">
                <label class="admin-label">新しいパスワード</label>
                <input
                  class="admin-input"
                  type="password"
                  name="new_password"
                  value=""
                  placeholder="<?= ((int)$u0['id'] > 0) ? '空なら変更なし' : '新規作成は必須' ?>"
                >
              </div>
              <div class="admin-field">
                <label class="admin-label">状態</label>
                <select class="admin-select" name="is_active">
                  <option value="1" <?= ((int)$u0['is_active'] === 1) ? 'selected' : '' ?>>有効</option>
                  <option value="0" <?= ((int)$u0['is_active'] === 0) ? 'selected' : '' ?>>無効</option>
                </select>
              </div>
            </div>

            <div class="admin-form-actions">
              <button class="btn btn-primary" type="submit">保存</button>
              <?php if ($editing): ?>
                <a class="btn" href="<?= h(build_admin_users_url(['id' => (int)$editing['id']])) ?>#roles">権限へ</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if ($editing): ?>
            <div class="admin-subsection">
              <div class="admin-subsection-title">連携操作</div>
              <div class="admin-link-actions">
                <?php if ($editing_line): ?>
                  <form method="post" onsubmit="return confirm('LINE連携を解除しますか？');" class="inline-form">
                    <input type="hidden" name="action" value="unlink_identity">
                    <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                    <input type="hidden" name="provider" value="line">
                    <button class="btn btn-warn" type="submit">LINE解除</button>
                  </form>
                  <a class="btn" href="/wbss/public/line_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">LINE再リンク</a>
                <?php else: ?>
                  <a class="btn" href="/wbss/public/line_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">LINE連携</a>
                  <a class="btn" target="_blank" href="/wbss/public/print_user_link_qr.php?user_id=<?= (int)$editing['id'] ?>">LINE連携QR</a>
                <?php endif; ?>

                <?php if ($editing_google): ?>
                  <form method="post" onsubmit="return confirm('Google連携を解除しますか？');" class="inline-form">
                    <input type="hidden" name="action" value="unlink_identity">
                    <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                    <input type="hidden" name="provider" value="google">
                    <button class="btn btn-warn" type="submit">Google解除</button>
                  </form>
                  <a class="btn" href="/wbss/public/google_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">Google再リンク</a>
                <?php else: ?>
                  <a class="btn" href="/wbss/public/google_login_start.php?link_user_id=<?= (int)$editing['id'] ?>">Google連携</a>
                <?php endif; ?>
              </div>
              <div class="admin-note">本人の端末でログインして紐付ける運用にしておくと事故が少ないです。</div>
            </div>
          <?php endif; ?>
        </section>

        <?php if ($editing): ?>
          <section id="roles" class="admin-section">
            <div class="admin-section-head">
              <div>
                <h2>権限割当</h2>
                <p>共通（全店）と店舗指定のロールをここで管理します。</p>
              </div>
            </div>

            <form method="post" class="admin-role-form">
              <input type="hidden" name="action" value="add_role">
              <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">

              <div class="admin-field">
                <label class="admin-label">ロール</label>
                <select class="admin-select" name="role_id" required>
                  <option value="">選択</option>
                  <?php foreach ($roles as $r): ?>
                    <option value="<?= (int)$r['id'] ?>"><?= h((string)$r['code']) ?> / <?= h((string)$r['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <div class="admin-field">
                <label class="admin-label">店舗</label>
                <select class="admin-select" name="store_id">
                  <option value="">共通（全店）</option>
                  <?php foreach ($stores as $s): ?>
                    <option value="<?= (int)$s['id'] ?>"><?= h((string)$s['name']) ?><?= ((int)$s['is_active'] === 1) ? '' : '（無効）' ?></option>
                  <?php endforeach; ?>
                </select>
              </div>

              <button class="btn btn-primary" type="submit">割当を追加</button>
            </form>

            <div class="admin-role-list">
              <?php if (!$editing_roles): ?>
                <div class="admin-empty">割当はまだありません。</div>
              <?php else: ?>
                <?php foreach ($editing_roles as $ar): ?>
                  <div class="admin-role-item">
                    <div>
                      <div class="admin-role-code"><?= h((string)$ar['role_code']) ?></div>
                      <div class="admin-role-name"><?= h((string)$ar['role_name']) ?></div>
                    </div>
                    <div class="admin-role-scope">
                      <?php if ($ar['store_id'] === null): ?>
                        <span class="admin-badge">共通（全店）</span>
                      <?php else: ?>
                        <span class="admin-badge"><?= h((string)($ar['store_name'] ?? ('#' . $ar['store_id']))) ?></span>
                      <?php endif; ?>
                    </div>
                    <form method="post" onsubmit="return confirm('この割当を削除しますか？');" class="inline-form">
                      <input type="hidden" name="action" value="del_role">
                      <input type="hidden" name="user_id" value="<?= (int)$editing['id'] ?>">
                      <input type="hidden" name="ur_id" value="<?= (int)$ar['ur_id'] ?>">
                      <button class="btn" type="submit">削除</button>
                    </form>
                  </div>
                <?php endforeach; ?>
              <?php endif; ?>
            </div>

            <div class="admin-note">同じロールを共通と店舗指定で二重に付けると扱いが複雑になるので、どちらか片方に寄せるのが安全です。</div>
          </section>
        <?php endif; ?>
      </aside>
    </div>
  </div>
</div>

<style>
.admin-users-shell{
  max-width:1360px;
  padding-bottom:28px;
}
.admin-hero,
.admin-section,
.admin-kpi-card,
.admin-flash{
  border:1px solid var(--line);
  border-radius:22px;
  background:
    linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
    var(--cardA);
  box-shadow:0 16px 40px rgba(0,0,0,.14);
}
.admin-flash{
  padding:12px 14px;
  margin-bottom:12px;
}
.admin-flash.is-success{ border-color:rgba(52,211,153,.35); }
.admin-flash.is-error{ border-color:rgba(251,113,133,.45); }

.admin-hero{
  display:grid;
  grid-template-columns:minmax(0, 1.75fr) minmax(300px, .95fr);
  gap:12px;
  padding:18px;
  margin-top:10px;
}
.admin-hero-main{
  min-width:0;
  display:flex;
  flex-direction:column;
  justify-content:center;
}
.hero-kicker{
  font-size:13px;
  font-weight:900;
  color:var(--muted);
}
.hero-badge{
  display:inline-flex;
  align-items:center;
  width:max-content;
  margin-top:8px;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:800;
  color:#0f172a;
  background:linear-gradient(135deg, #facc15, #fb923c);
}
.admin-hero-main h1{
  margin:12px 0 8px;
  font-size:30px;
  line-height:1.15;
}
.admin-hero-main p{
  margin:0;
  color:var(--muted);
  line-height:1.6;
}
.hero-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-top:16px;
}
.admin-pill-link{
  display:inline-flex;
  align-items:center;
  min-height:42px;
  padding:0 14px;
  border-radius:999px;
  border:1px solid var(--line);
  text-decoration:none;
  color:inherit;
  font-weight:900;
  background:rgba(255,255,255,.04);
}
.admin-pill-link:hover{
  border-color:color-mix(in srgb, var(--accent) 45%, var(--line));
  background:color-mix(in srgb, var(--accent) 10%, transparent);
}
.admin-hero-side{
  display:flex;
  align-items:stretch;
}
.admin-search{
  width:100%;
  display:flex;
  flex-direction:column;
  gap:10px;
}
.admin-search-row,
.admin-search-actions,
.admin-form-actions,
.admin-link-actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}
.admin-label{
  display:block;
  font-size:12px;
  font-weight:800;
  color:var(--muted);
  margin-bottom:6px;
}
.admin-input,
.admin-select{
  width:100%;
  min-height:46px;
  padding:11px 12px;
  border-radius:14px;
  border:1px solid rgba(255,255,255,.10);
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  color:var(--txt);
  font-size:14px;
  box-sizing:border-box;
}
.admin-input:focus,
.admin-select:focus{
  outline:none;
  border-color:color-mix(in srgb, var(--accent) 50%, var(--line));
  box-shadow:0 0 0 3px color-mix(in srgb, var(--accent) 14%, transparent);
}
.admin-kpis{
  display:grid;
  grid-template-columns:repeat(5, minmax(0, 1fr));
  gap:12px;
  margin-top:14px;
}
.admin-kpi-card{
  padding:14px 16px;
}
.admin-kpi-label{
  font-size:12px;
  font-weight:800;
  color:var(--muted);
}
.admin-kpi-value{
  margin-top:6px;
  font-size:24px;
  font-weight:1000;
}
.admin-layout{
  display:grid;
  grid-template-columns:minmax(0, 1.7fr) minmax(340px, .95fr);
  gap:14px;
  margin-top:14px;
  align-items:start;
}
.admin-main,
.admin-side{
  display:grid;
  gap:14px;
}
.admin-section{
  padding:16px 18px 18px;
}
.admin-section-sticky{
  position:sticky;
  top:18px;
}
.admin-section-head{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
  margin-bottom:12px;
}
.admin-section-head h2{
  margin:0;
  font-size:20px;
}
.admin-section-head p{
  margin:4px 0 0;
  color:var(--muted);
  font-size:12px;
  line-height:1.55;
}
.admin-empty{
  padding:18px;
  border-radius:16px;
  border:1px dashed var(--line);
  color:var(--muted);
  text-align:center;
}
.admin-filter-tabs{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-bottom:12px;
}
.admin-filter-tab{
  display:inline-flex;
  align-items:center;
  gap:8px;
  min-height:40px;
  padding:0 12px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  font-size:13px;
  font-weight:900;
  white-space:nowrap;
}
.admin-filter-tab.is-active{
  border-color:color-mix(in srgb, var(--accent) 45%, var(--line));
  background:color-mix(in srgb, var(--accent) 12%, transparent);
}
.admin-filter-tab-count{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:24px;
  height:24px;
  padding:0 8px;
  border-radius:999px;
  background:rgba(255,255,255,.10);
  font-size:11px;
}
.admin-list-meta{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
  margin-bottom:14px;
  color:var(--muted);
  font-size:12px;
  font-weight:800;
}
.admin-pagination{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
  margin-bottom:12px;
}
.admin-pagination.is-bottom{
  margin-top:12px;
  margin-bottom:0;
}
.admin-page-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-width:42px;
  min-height:42px;
  padding:0 12px;
  border-radius:14px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  font-size:13px;
  font-weight:900;
  white-space:nowrap;
}
.admin-page-btn.is-current{
  border-color:color-mix(in srgb, var(--accent) 45%, var(--line));
  background:color-mix(in srgb, var(--accent) 12%, transparent);
}
.admin-page-btn.is-disabled{
  opacity:.45;
  pointer-events:none;
}
.admin-user-grid{
  display:grid;
  gap:8px;
}
.admin-user-card{
  border:1px solid rgba(255,255,255,.10);
  border-radius:16px;
  padding:10px 12px;
  background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
  display:grid;
  grid-template-columns:minmax(0, 1.2fr) auto minmax(220px, 320px) 96px;
  gap:12px;
  align-items:center;
}
.admin-user-card.is-selected{
  border-color:rgba(250,204,21,.45);
  box-shadow:0 12px 24px rgba(0,0,0,.12);
}
.admin-user-main{
  min-width:0;
}
.admin-user-meta{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.admin-user-id,
.admin-user-status,
.admin-badge,
.admin-chip{
  display:inline-flex;
  align-items:center;
  min-height:30px;
  padding:0 10px;
  border-radius:999px;
  border:1px solid var(--line);
  font-size:12px;
  font-weight:800;
  background:rgba(255,255,255,.05);
}
.admin-user-status.is-active,
.admin-chip.is-on{
  border-color:rgba(52,211,153,.40);
  background:rgba(52,211,153,.12);
}
.admin-user-status.is-inactive,
.admin-chip.is-off{
  border-color:rgba(251,113,133,.35);
  background:rgba(251,113,133,.10);
}
.admin-user-name{
  font-size:16px;
  font-weight:1000;
  line-height:1.2;
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.admin-user-login{
  margin-top:3px;
  font-size:12px;
  font-weight:800;
  color:var(--muted);
  white-space:nowrap;
  overflow:hidden;
  text-overflow:ellipsis;
}
.admin-user-sub,
.admin-link-meta,
.admin-note,
.admin-role-name,
.admin-login-time{
  color:var(--muted);
  font-size:12px;
  line-height:1.55;
}
.admin-link-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:10px;
}
.admin-link-box{
  border:1px solid rgba(255,255,255,.08);
  border-radius:14px;
  padding:10px;
  background:rgba(255,255,255,.03);
}
.admin-link-title{
  display:flex;
  justify-content:space-between;
  gap:8px;
  align-items:center;
}
.admin-link-state{
  font-size:12px;
  color:var(--muted);
}
.admin-link-body{
  margin-top:8px;
}
.admin-link-name{
  font-size:13px;
  font-weight:900;
}
.admin-compact-links{
  display:flex;
  align-items:center;
  justify-content:flex-start;
  flex-wrap:wrap;
  gap:8px;
  min-width:0;
}
.admin-compact-link{
  display:flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  min-width:0;
  padding:7px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.03);
  white-space:nowrap;
}
.admin-compact-link-label{
  font-size:12px;
  font-weight:1000;
}
.admin-compact-link-state{
  font-size:11px;
  font-weight:800;
  color:var(--muted);
  white-space:nowrap;
}
.admin-compact-link.is-on{
  border-color:rgba(52,211,153,.35);
  background:rgba(52,211,153,.10);
}
.admin-compact-link.is-on .admin-compact-link-state{
  color:var(--ok);
}
.admin-compact-link.is-off{
  border-color:rgba(251,113,133,.25);
  background:rgba(255,255,255,.02);
}
.admin-login-row{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  align-items:center;
}
.admin-user-actions{
  display:block;
  margin-top:0;
}
.admin-user-actions > *,
.admin-link-actions > *,
.admin-form-actions > *{
  min-width:0;
}
.admin-user-actions .btn,
.admin-link-actions .btn,
.admin-form-actions .btn{
  width:100%;
  justify-content:center;
  white-space:nowrap;
}
.inline-form{
  margin:0;
  display:block;
}
.admin-summary-chips{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-bottom:10px;
}
.admin-note{
  margin-top:10px;
}
.admin-form{
  margin-top:12px;
}
.admin-form-grid{
  display:grid;
  grid-template-columns:1fr 1fr;
  gap:12px;
}
.admin-field{
  min-width:0;
}
.admin-subsection{
  margin-top:16px;
  padding-top:14px;
  border-top:1px solid var(--line);
}
.admin-subsection-title{
  font-size:15px;
  font-weight:1000;
  margin-bottom:10px;
}
.admin-link-actions{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:8px;
}
.admin-role-form{
  display:grid;
  gap:10px;
}
.admin-role-list{
  display:grid;
  gap:10px;
  margin-top:14px;
}
.admin-role-item{
  display:grid;
  grid-template-columns:minmax(0, 1fr) auto auto;
  gap:10px;
  align-items:center;
  padding:12px;
  border:1px solid rgba(255,255,255,.08);
  border-radius:16px;
  background:rgba(255,255,255,.03);
}
.admin-role-code{
  font-size:14px;
  font-weight:1000;
}
.admin-badge.is-muted{
  color:var(--muted);
  background:rgba(255,255,255,.02);
}
body[data-theme="light"] .admin-hero,
body[data-theme="light"] .admin-section,
body[data-theme="light"] .admin-kpi-card,
body[data-theme="light"] .admin-flash{
  background:#fff;
  border:1px solid var(--line);
  box-shadow:0 14px 26px rgba(15,18,34,.08);
}
body[data-theme="light"] .admin-input,
body[data-theme="light"] .admin-select,
body[data-theme="light"] .admin-user-card,
body[data-theme="light"] .admin-link-box,
body[data-theme="light"] .admin-compact-link,
body[data-theme="light"] .admin-role-item{
  background:#fff;
  border:1px solid var(--line);
  box-shadow:0 10px 18px rgba(0,0,0,.06);
}
body[data-theme="light"] .admin-user-card{
  gap:12px;
}
body[data-theme="light"] .admin-pill-link{
  background:#fff;
  border-color:var(--line);
  box-shadow:0 10px 18px rgba(0,0,0,.06);
}
body[data-theme="light"] .admin-filter-tab{
  background:#fff;
  border-color:var(--line);
  box-shadow:0 10px 18px rgba(0,0,0,.06);
}
body[data-theme="light"] .admin-page-btn{
  background:#fff;
  border-color:var(--line);
  box-shadow:0 10px 18px rgba(0,0,0,.06);
}
body[data-theme="light"] .admin-filter-tab.is-active{
  background:var(--softBlue);
  border-color:color-mix(in srgb, var(--blue) 35%, var(--line));
}
body[data-theme="light"] .admin-page-btn.is-current{
  background:var(--softBlue);
  border-color:color-mix(in srgb, var(--blue) 35%, var(--line));
}
body[data-theme="light"] .admin-filter-tab-count{
  background:rgba(37,99,235,.10);
}
body[data-theme="light"] .admin-user-actions .btn,
body[data-theme="light"] .admin-link-actions .btn,
body[data-theme="light"] .admin-form-actions .btn{
  min-height:50px;
  padding:12px 14px;
}
body[data-theme="light"] .btn-warn{
  background:var(--softRed);
  color:var(--red);
}
@media (max-width: 1120px){
  .admin-kpis{
    grid-template-columns:repeat(3, minmax(0, 1fr));
  }
  .admin-layout{
    grid-template-columns:1fr;
  }
  .admin-section-sticky{
    position:static;
  }
}
@media (max-width: 820px){
  .admin-hero{
    grid-template-columns:1fr;
  }
  .admin-link-grid,
  .admin-form-grid,
  .admin-compact-links{
    grid-template-columns:1fr;
  }
  .admin-user-card{
    grid-template-columns:minmax(0, 1fr);
    gap:10px;
    align-items:start;
  }
  .admin-user-actions{
    width:100%;
  }
  .admin-user-actions,
  .admin-link-actions{
    grid-template-columns:1fr;
  }
}
@media (max-width: 640px){
  .admin-users-shell{
    padding-bottom:28px;
  }
  .admin-hero,
  .admin-section,
  .admin-kpi-card,
  .admin-flash{
    border-radius:18px;
  }
  .admin-kpis{
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
  .admin-user-grid{
    grid-template-columns:1fr;
  }
  .admin-role-item{
    grid-template-columns:1fr;
    align-items:start;
  }
  .admin-kpi-value{
    font-size:20px;
  }
}
</style>

<?php render_page_end(); ?>
