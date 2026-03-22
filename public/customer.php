<?php
declare(strict_types=1);

/* =====================================
   requires
===================================== */
require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/repo_customers.php';
require_once __DIR__ . '/../app/service_customer.php';

require_login();
require_role(['cast','admin','manager','super_user']);

$pdo = db();
if (session_status() !== PHP_SESSION_ACTIVE) session_start();

/* =====================================
   helpers
===================================== */
function h(string $s): string {
  return htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
}
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}
function current_user_id_safe(): int {
  return function_exists('current_user_id')
    ? (int)current_user_id()
    : (int)($_SESSION['user_id'] ?? 0);
}
function csrf_token(): string {
  if (empty($_SESSION['_csrf'])) {
    $_SESSION['_csrf'] = bin2hex(random_bytes(32));
  }
  return $_SESSION['_csrf'];
}
function csrf_verify(?string $token): void {
  if (!$token || !isset($_SESSION['_csrf']) || !hash_equals($_SESSION['_csrf'], $token)) {
    http_response_code(403);
    exit('csrf error');
  }
}
function customer_status_label(string $status): string {
  if ($status === 'inactive') {
    return '休眠';
  }
  if ($status === 'merged') {
    return '統合済';
  }
  return 'アクティブ';
}
function customer_status_class(string $status): string {
  return ($status === 'inactive' || $status === 'merged') ? 'off' : 'ok';
}
function note_preview(?string $text, int $max = 56): string {
  $value = trim((string)$text);
  if ($value === '') {
    return '';
  }
  $value = preg_replace('/\s+/u', ' ', $value) ?? $value;
  if (mb_strlen($value) <= $max) {
    return $value;
  }
  return mb_substr($value, 0, $max) . '…';
}
function format_datetime_local(?string $value): string {
  $text = trim((string)$value);
  if ($text === '') {
    return '未登録';
  }
  $ts = strtotime($text);
  if ($ts === false) {
    return $text;
  }
  return date('Y-m-d H:i', $ts);
}
function display_text(?string $value, string $fallback = '未登録'): string {
  $text = trim((string)$value);
  return $text !== '' ? $text : $fallback;
}
function visit_frequency_label(?string $value): string {
  $text = trim((string)$value);
  if ($text === '') {
    return '来店日未登録';
  }
  $ts = strtotime($text);
  if ($ts === false) {
    return '来店日未登録';
  }
  $days = (int)floor((time() - $ts) / 86400);
  if ($days <= 7) {
    return '週1ペース';
  }
  if ($days <= 45) {
    return '月1ペース';
  }
  if ($days <= 120) {
    return '数ヶ月ペース';
  }
  return '年1ペース';
}

/* =====================================
   role / user
===================================== */
$userId   = current_user_id_safe();
$isSuper  = has_role('super_user');
$isStaff  = $isSuper || has_role('admin') || has_role('manager');
$isCast   = has_role('cast');
$castOnly = (!$isStaff && $isCast);

/* =====================================
   store resolve
===================================== */
$stores  = [];
$storeId = 0;

if ($isStaff) {
  if ($isSuper) {
    $stores = $pdo->query(
      "SELECT id,name FROM stores WHERE is_active=1 ORDER BY id"
    )->fetchAll(PDO::FETCH_ASSOC);
  } else {
    $st = $pdo->prepare("
      SELECT DISTINCT s.id,s.name
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code IN ('admin','manager')
      JOIN stores s ON s.id=ur.store_id AND s.is_active=1
      WHERE ur.user_id=?
    ");
    $st->execute([$userId]);
    $stores = $st->fetchAll(PDO::FETCH_ASSOC);
  }

  $storeId = (int)($_GET['store_id'] ?? $_POST['store_id'] ?? 0);
  if ($storeId <= 0) $storeId = (int)($stores[0]['id'] ?? 0);

  $allowed = array_map(fn($s)=>(int)$s['id'], $stores);
  if (!in_array($storeId, $allowed, true)) {
    $storeId = (int)($stores[0]['id'] ?? 0);
  }
}

if ($castOnly) {
  // cast は自分の store に固定
  $st = $pdo->prepare("
    SELECT store_id
    FROM cast_profiles
    WHERE user_id=?
    LIMIT 1
  ");
  $st->execute([$userId]);
  $storeId = (int)($st->fetchColumn() ?: 0);

  if ($storeId <= 0) {
    $st = $pdo->prepare("
      SELECT ur.store_id
      FROM user_roles ur
      JOIN roles r ON r.id=ur.role_id AND r.code='cast'
      WHERE ur.user_id=?
      LIMIT 1
    ");
    $st->execute([$userId]);
    $storeId = (int)($st->fetchColumn() ?: 0);
  }
}

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が特定できません');
}

/* =====================================
   input
===================================== */
$action = (string)($_POST['action'] ?? '');
$q      = trim((string)($_GET['q'] ?? ''));
$viewId = (int)($_GET['id'] ?? 0);
$visitFrequency = trim((string)($_GET['visit_frequency'] ?? ''));

$msg = '';
$err = '';

$forceAssignedUserId = $castOnly ? $userId : null;

/* =====================================
   POST actions
===================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    csrf_verify($_POST['csrf_token'] ?? null);

    if ($action === 'create_customer') {
      $name        = trim((string)$_POST['name']);
      $feature     = trim((string)($_POST['feature'] ?? ''));
      $notePublic  = trim((string)($_POST['note_public'] ?? ''));

      $assignedId = $castOnly
        ? $userId
        : ((string)($_POST['assigned_user_id'] ?? '') !== '' ? (int)$_POST['assigned_user_id'] : null);

      $newId = repo_customer_create(
        $pdo,
        $storeId,
        $name,
        $feature,
        $notePublic,
        $assignedId,
        $userId
      );

      header("Location: customer.php?store_id={$storeId}&id={$newId}");
      exit;
    }

    if ($action === 'update_customer') {
      $visitCountRaw = trim((string)($_POST['visit_count'] ?? ''));
      repo_customer_update(
        $pdo,
        $storeId,
        (int)$_POST['customer_id'],
        trim((string)$_POST['name']),
        trim((string)$_POST['feature']),
        trim((string)$_POST['note_public']),
        (string)$_POST['status'],
        $castOnly ? $userId : ((string)($_POST['assigned_user_id'] ?? '') !== '' ? (int)$_POST['assigned_user_id'] : null),
        trim((string)($_POST['last_visit_at'] ?? '')),
        $visitCountRaw !== '' ? max(0, (int)$visitCountRaw) : null,
        trim((string)($_POST['last_topic'] ?? '')),
        trim((string)($_POST['preferences_note'] ?? '')),
        trim((string)($_POST['ng_note'] ?? '')),
        trim((string)($_POST['contact_time_note'] ?? '')),
        trim((string)($_POST['visit_style_note'] ?? '')),
        trim((string)($_POST['referral_source'] ?? '')),
        trim((string)($_POST['caution_note'] ?? '')),
        trim((string)($_POST['next_action'] ?? ''))
      );
      $msg = '更新しました';
    }

    if ($action === 'add_note') {
      repo_customer_add_note(
        $pdo,
        $storeId,
        (int)$_POST['customer_id'],
        $userId,
        trim((string)$_POST['note_text'])
      );
      header("Location: customer.php?store_id={$storeId}&id=".$_POST['customer_id']);
      exit;
    }

    if ($action === 'merge') {
      service_customer_merge(
        $pdo,
        $storeId,
        (int)$_POST['from_id'],
        (int)$_POST['to_id'],
        $userId
      );
      header("Location: customer.php?store_id={$storeId}&id=".$_POST['to_id']);
      exit;
    }

  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

/* =====================================
   load
===================================== */
$st = $pdo->prepare("SELECT name FROM stores WHERE id=?");
$st->execute([$storeId]);
$storeName = (string)$st->fetchColumn();

$allowedVisitFrequencies = ['','weekly','monthly','few_months','yearly','unknown'];
if (!in_array($visitFrequency, $allowedVisitFrequencies, true)) {
  $visitFrequency = '';
}

$list = repo_customers_search($pdo, $storeId, $q, 80, $forceAssignedUserId, $visitFrequency);

$customer = null;
$notes = [];
$dupes = repo_customer_possible_duplicates(
  $pdo,
  $storeId,
  $viewId,
  10,
  $forceAssignedUserId
);
if ($viewId > 0) {
  $customer = repo_customer_get($pdo, $storeId, $viewId, $forceAssignedUserId);
  if ($castOnly && !$customer) {
    http_response_code(403);
    exit('forbidden');
  }
if ($customer) {
  $notes = repo_customer_notes($pdo, $storeId, $viewId, 50);
  $dupes = repo_customer_possible_duplicates($pdo, $storeId, $viewId, 10);
}
}

$hasQuery = ($q !== '');
$activeCount = 0;
$inactiveCount = 0;
foreach ($list as $row) {
  $status = (string)($row['status'] ?? 'active');
  if ($status === 'inactive' || $status === 'merged') {
    $inactiveCount++;
  } else {
    $activeCount++;
  }
}
$noteCount = count($notes);
$duplicateCount = count($dupes);

/* =====================================
   render
===================================== */
render_page_start('顧客管理');
render_header('顧客管理',[
  'back_href'=> $castOnly ? '/wbss/public/dashboard_cast.php' : '/wbss/public/dashboard.php',
  'back_label'=>'← ダッシュボード'
]);
?>
<div class="customerPage">
  <section class="welcomePanel">
    <div class="welcomeMain">
      <div class="eyebrow">Customer Note</div>
      <h1 class="pageTitle">お客様を探す、見る、書く</h1>
      <p class="pageLead">1. まず検索します。 2. 次にお客様をタップします。 3. 最後にメモを書きます。 むずかしい操作は下にまとめています。</p>
      <div class="jumpLinks">
        <a class="jumpBtn" href="#search-box">検索する</a>
        <a class="jumpBtn" href="#customer-list">一覧を見る</a>
        <a class="jumpBtn" href="#customer-create">新しく作る</a>
        <a class="jumpBtn" href="#customer-detail">詳細を見る</a>
      </div>
    </div>
    <div class="welcomeStats">
      <div class="summaryTile">
        <span>店舗</span>
        <strong><?= h($storeName) ?></strong>
        <small>#<?= (int)$storeId ?></small>
      </div>
      <div class="summaryTile">
        <span>表示中</span>
        <strong><?= count($list) ?>件</strong>
        <small><?= $hasQuery ? '絞り込み中' : '全件表示' ?></small>
      </div>
      <div class="summaryTile">
        <span>アクティブ</span>
        <strong><?= $activeCount ?></strong>
        <small>すぐ見たいお客様</small>
      </div>
      <div class="summaryTile">
        <span>休眠・統合済</span>
        <strong><?= $inactiveCount ?></strong>
        <small>あとで確認</small>
      </div>
    </div>
  </section>

  <?php if ($err): ?><div class="notice noticeError"><?= h($err) ?></div><?php endif; ?>
  <?php if ($msg): ?><div class="notice noticeSuccess"><?= h($msg) ?></div><?php endif; ?>

  <section class="searchBox" id="search-box">
    <div class="sectionLabel">STEP 1</div>
    <h2 class="sectionTitle">お客様を探す</h2>
    <p class="sectionHelp">名前、特徴、メモ、IDで探せます。迷ったら思い出せる言葉を1つ入れればOKです。</p>

    <?php if ($isStaff && $stores): ?>
      <form method="get" class="searchForm">
        <div class="inputGroup">
          <label class="fieldLabel">店舗</label>
          <select name="store_id" class="fieldInput">
            <?php foreach ($stores as $s): ?>
              <option value="<?= (int)$s['id'] ?>" <?= ((int)$s['id']===$storeId)?'selected':'' ?>>
                <?= h((string)$s['name']) ?> (#<?= (int)$s['id'] ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="inputGroup inputGroupWide">
          <label class="fieldLabel">検索</label>
          <input class="fieldInput" name="q" value="<?= h($q) ?>" placeholder="例：よく笑う / 角ハイ / 12">
        </div>
        <div class="inputGroup">
          <label class="fieldLabel">来店頻度</label>
          <select name="visit_frequency" class="fieldInput">
            <option value="" <?= $visitFrequency===''?'selected':'' ?>>すべて</option>
            <option value="weekly" <?= $visitFrequency==='weekly'?'selected':'' ?>>週1ペース</option>
            <option value="monthly" <?= $visitFrequency==='monthly'?'selected':'' ?>>月1ペース</option>
            <option value="few_months" <?= $visitFrequency==='few_months'?'selected':'' ?>>数ヶ月ペース</option>
            <option value="yearly" <?= $visitFrequency==='yearly'?'selected':'' ?>>年1ペース</option>
            <option value="unknown" <?= $visitFrequency==='unknown'?'selected':'' ?>>来店日未登録</option>
          </select>
        </div>
        <div class="formButtons">
          <button class="primaryBtn" type="submit">この条件で見る</button>
          <?php if ($hasQuery || $visitFrequency !== ''): ?>
            <a class="secondaryBtn" href="/wbss/public/customer.php?store_id=<?= (int)$storeId ?>">もとに戻す</a>
          <?php endif; ?>
        </div>
      </form>
    <?php else: ?>
      <form method="get" class="searchForm">
        <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <div class="inputGroup inputGroupWide">
          <label class="fieldLabel">検索</label>
          <input class="fieldInput" name="q" value="<?= h($q) ?>" placeholder="例：よく笑う / 角ハイ / 12">
        </div>
        <div class="inputGroup">
          <label class="fieldLabel">来店頻度</label>
          <select name="visit_frequency" class="fieldInput">
            <option value="" <?= $visitFrequency===''?'selected':'' ?>>すべて</option>
            <option value="weekly" <?= $visitFrequency==='weekly'?'selected':'' ?>>週1ペース</option>
            <option value="monthly" <?= $visitFrequency==='monthly'?'selected':'' ?>>月1ペース</option>
            <option value="few_months" <?= $visitFrequency==='few_months'?'selected':'' ?>>数ヶ月ペース</option>
            <option value="yearly" <?= $visitFrequency==='yearly'?'selected':'' ?>>年1ペース</option>
            <option value="unknown" <?= $visitFrequency==='unknown'?'selected':'' ?>>来店日未登録</option>
          </select>
        </div>
        <div class="formButtons">
          <button class="primaryBtn" type="submit">この条件で見る</button>
          <?php if ($hasQuery || $visitFrequency !== ''): ?>
            <a class="secondaryBtn" href="/wbss/public/customer.php?store_id=<?= (int)$storeId ?>">もとに戻す</a>
          <?php endif; ?>
        </div>
      </form>
    <?php endif; ?>
  </section>

  <div class="workspaceGrid">
    <section class="panel listPanel" id="customer-list">
      <div class="panelHead">
        <div>
          <div class="sectionLabel">STEP 2</div>
          <h2 class="sectionTitle">お客様を選ぶ</h2>
          <p class="sectionHelp">カードを押すと右側の詳細が切り替わります。スマホではこの下に詳細が続きます。</p>
        </div>
        <?php if ($customer): ?>
          <a class="secondaryBtn" href="/wbss/public/customer.php?store_id=<?= (int)$storeId ?>">選択を外す</a>
        <?php endif; ?>
      </div>

      <?php if ($list): ?>
        <div class="customerCards">
          <?php foreach ($list as $r): ?>
            <?php
              $status = (string)($r['status'] ?? 'active');
              $statusLabel = customer_status_label($status);
              $statusCls = customer_status_class($status);
              $feature = trim((string)($r['feature'] ?? ''));
              $publicNote = note_preview((string)($r['note_public'] ?? ''));
            ?>
            <a class="customerCard <?= ((int)$r['id']===$viewId)?'current':'' ?>" href="/wbss/public/customer.php?store_id=<?= (int)$storeId ?>&id=<?= (int)$r['id'] ?>">
              <div class="customerCardTop">
                <div>
                  <div class="customerId">#<?= (int)$r['id'] ?></div>
                  <div class="customerTitle"><?= h((string)$r['name']) ?></div>
                </div>
                <span class="statusPill <?= h($statusCls) ?>"><?= h($statusLabel) ?></span>
              </div>
              <div class="customerText"><?= $feature !== '' ? h($feature) : '特徴はまだありません' ?></div>
              <div class="customerSub">
                <span>頻度: <?= h(visit_frequency_label((string)($r['last_visit_at'] ?? ''))) ?></span>
                <span><?= !empty($r['assigned_name']) ? '担当: ' . h((string)$r['assigned_name']) : '担当: 未設定' ?></span>
                <?php if ($publicNote !== ''): ?>
                  <span>メモ: <?= h($publicNote) ?></span>
                <?php endif; ?>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div class="emptyBox">
          <div class="emptyTitle">見つかりませんでした</div>
          <p class="sectionHelp"><?= $hasQuery ? '言葉を変えてもう一度探すか、下の新規登録を使ってください。' : 'まだお客様がいません。下の新規登録から始められます。'; ?></p>
        </div>
      <?php endif; ?>

      <section class="subPanel createPanel" id="customer-create">
        <div class="sectionLabel">STEP 3</div>
        <h2 class="sectionTitle">新しく作る</h2>
        <p class="sectionHelp">まずは名前と特徴だけで大丈夫です。細かい情報はあとから足せます。</p>

        <form method="post" class="stackForm">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
          <input type="hidden" name="action" value="create_customer">

          <div class="inputGroup">
            <label class="fieldLabel">名前</label>
            <input class="fieldInput" name="name" required placeholder="例：短髪メガネの人 / たけし？">
          </div>

          <div class="inputGroup">
            <label class="fieldLabel">特徴</label>
            <input class="fieldInput" name="feature" placeholder="例：左手に指輪 / 角ハイ / よく笑う">
          </div>

          <div class="inputGroup">
            <label class="fieldLabel">店全体メモ</label>
            <input class="fieldInput" name="note_public" placeholder="例：初回は静か。褒めると伸びるタイプ。">
          </div>

          <?php if (!$castOnly): ?>
            <div class="inputGroup">
              <label class="fieldLabel">担当キャスト</label>
              <input class="fieldInput" name="assigned_user_id" inputmode="numeric" placeholder="例：10">
            </div>
          <?php else: ?>
            <input type="hidden" name="assigned_user_id" value="<?= (int)$userId ?>">
          <?php endif; ?>

          <button class="primaryBtn wideBtn" type="submit">この内容で作る</button>
        </form>
      </section>
    </section>

    <section class="panel detailPanel" id="customer-detail">
      <div class="panelHead">
        <div>
          <div class="sectionLabel">DETAIL</div>
          <h2 class="sectionTitle">選んだお客様の情報</h2>
          <p class="sectionHelp">ここでは見ることを中心にしています。変更は必要な場所だけ開いて行います。</p>
        </div>
      </div>

      <?php if (!$customer): ?>
        <div class="emptyBox tall">
          <div class="emptyTitle">まだお客様が選ばれていません</div>
          <p class="sectionHelp">左のカードを押すと、ここに情報が出ます。</p>
        </div>
      <?php else: ?>
        <?php
          $status = (string)($customer['status'] ?? 'active');
          $statusLabel = customer_status_label($status);
          $statusCls = customer_status_class($status);
        ?>
        <div class="profileHero">
          <div>
            <div class="customerId">#<?= (int)$customer['id'] ?></div>
            <div class="profileName"><?= h((string)$customer['display_name']) ?></div>
            <div class="profileFeature"><?= h((string)$customer['features']) ?></div>
          </div>
          <span class="statusPill <?= h($statusCls) ?>"><?= h($statusLabel) ?></span>
        </div>

        <div class="quickInfoGrid">
          <div class="quickInfoCard">
            <span>最終来店日</span>
            <strong><?= h(format_datetime_local((string)($customer['last_visit_at'] ?? ''))) ?></strong>
          </div>
          <div class="quickInfoCard">
            <span>来店回数</span>
            <strong><?= (int)($customer['visit_count'] ?? 0) ?>回</strong>
          </div>
          <div class="quickInfoCard">
            <span>次回やること</span>
            <strong><?= h(display_text((string)($customer['next_action'] ?? ''))) ?></strong>
          </div>
          <div class="quickInfoCard">
            <span>担当キャスト</span>
            <strong><?= !empty($customer['assigned_name']) ? h((string)$customer['assigned_name']) : (!empty($customer['assigned_user_id']) ? '#' . (int)$customer['assigned_user_id'] : '未設定') ?></strong>
          </div>
          <div class="quickInfoCard">
            <span>メモ数</span>
            <strong><?= $noteCount ?>件</strong>
          </div>
          <div class="quickInfoCard">
            <span>重複候補</span>
            <strong><?= $duplicateCount ?>件</strong>
          </div>
        </div>

        <div class="readBox">
          <div class="fieldLabel">店全体メモ</div>
          <div class="readValue"><?= h((string)($customer['note_public'] ?? '未登録')) ?></div>
        </div>

        <section class="subPanel">
          <div class="sectionLabel">TODAY</div>
          <h2 class="sectionTitle">今日見るメモ</h2>
          <div class="focusGrid">
            <div class="focusCard">
              <div class="fieldLabel">最後に話した話題</div>
              <div class="readValue"><?= h(display_text((string)($customer['last_topic'] ?? ''))) ?></div>
            </div>
            <div class="focusCard">
              <div class="fieldLabel">好きなもの</div>
              <div class="readValue"><?= h(display_text((string)($customer['preferences_note'] ?? ''))) ?></div>
            </div>
            <div class="focusCard danger">
              <div class="fieldLabel">NG</div>
              <div class="readValue"><?= h(display_text((string)($customer['ng_note'] ?? ''))) ?></div>
            </div>
            <div class="focusCard warn">
              <div class="fieldLabel">注意事項</div>
              <div class="readValue"><?= h(display_text((string)($customer['caution_note'] ?? ''))) ?></div>
            </div>
          </div>
        </section>

        <section class="subPanel">
          <div class="sectionLabel">RELATION</div>
          <h2 class="sectionTitle">連絡と関係の情報</h2>
          <div class="focusGrid">
            <div class="focusCard">
              <div class="fieldLabel">連絡OK時間帯</div>
              <div class="readValue"><?= h(display_text((string)($customer['contact_time_note'] ?? ''))) ?></div>
            </div>
            <div class="focusCard">
              <div class="fieldLabel">同伴・アフター傾向</div>
              <div class="readValue"><?= h(display_text((string)($customer['visit_style_note'] ?? ''))) ?></div>
            </div>
            <div class="focusCard">
              <div class="fieldLabel">紹介元</div>
              <div class="readValue"><?= h(display_text((string)($customer['referral_source'] ?? ''))) ?></div>
            </div>
          </div>
        </section>

        <details class="foldBox">
          <summary>
            <div>
              <div class="foldTitle">基本情報を変更する</div>
              <div class="sectionHelp">名前、特徴、担当キャスト、状態を変えるときだけ開きます。</div>
            </div>
          </summary>
          <form method="post" class="stackForm foldBody">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
            <input type="hidden" name="action" value="update_customer">
            <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">

            <div class="inputGroup">
              <label class="fieldLabel">名前</label>
              <input class="fieldInput" name="name" value="<?= h((string)$customer['display_name']) ?>" required>
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">特徴</label>
              <input class="fieldInput" name="feature" value="<?= h((string)$customer['features']) ?>">
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">店全体メモ</label>
              <input class="fieldInput" name="note_public" value="<?= h((string)($customer['note_public'] ?? '')) ?>">
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">最終来店日</label>
              <input class="fieldInput" name="last_visit_at" value="<?= h((string)($customer['last_visit_at'] ?? '')) ?>" placeholder="例：2026-03-17 21:30:00">
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">来店回数</label>
              <input class="fieldInput" name="visit_count" inputmode="numeric" value="<?= h((string)($customer['visit_count'] ?? '')) ?>" placeholder="例：12">
            </div>

            <?php if (!$castOnly): ?>
              <div class="inputGroup">
                <label class="fieldLabel">担当キャスト</label>
                <input class="fieldInput" name="assigned_user_id" inputmode="numeric" value="<?= h((string)($customer['assigned_user_id'] ?? '')) ?>" placeholder="例：10">
              </div>
            <?php else: ?>
              <input type="hidden" name="assigned_user_id" value="<?= (int)$userId ?>">
            <?php endif; ?>

            <div class="inputGroup">
              <label class="fieldLabel">状態</label>
              <select class="fieldInput" name="status">
                <option value="active" <?= ($status==='active')?'selected':'' ?>>アクティブ</option>
                <option value="inactive" <?= ($status==='inactive')?'selected':'' ?>>休眠</option>
              </select>
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">最後に話した話題</label>
              <textarea class="fieldTextarea shortArea" name="last_topic" rows="3" placeholder="例：前回は地元の野球の話で盛り上がった"><?= h((string)($customer['last_topic'] ?? '')) ?></textarea>
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">好きなもの</label>
              <textarea class="fieldTextarea shortArea" name="preferences_note" rows="3" placeholder="例：角ハイ、平成の曲、明るい話題"><?= h((string)($customer['preferences_note'] ?? '')) ?></textarea>
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">NG</label>
              <textarea class="fieldTextarea shortArea" name="ng_note" rows="3" placeholder="例：甘い酒は苦手、深い恋愛話は避ける"><?= h((string)($customer['ng_note'] ?? '')) ?></textarea>
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">注意事項</label>
              <textarea class="fieldTextarea shortArea" name="caution_note" rows="3" placeholder="例：酔うと静かになる。席替えは少なめが安心"><?= h((string)($customer['caution_note'] ?? '')) ?></textarea>
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">連絡OK時間帯</label>
              <input class="fieldInput" name="contact_time_note" value="<?= h((string)($customer['contact_time_note'] ?? '')) ?>" placeholder="例：平日19時以降 / 土日は昼でもOK">
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">同伴・アフター傾向</label>
              <input class="fieldInput" name="visit_style_note" value="<?= h((string)($customer['visit_style_note'] ?? '')) ?>" placeholder="例：イベント時に動きやすい / 店内中心">
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">紹介元</label>
              <input class="fieldInput" name="referral_source" value="<?= h((string)($customer['referral_source'] ?? '')) ?>" placeholder="例：まさかず経由 / 友人紹介">
            </div>

            <div class="inputGroup">
              <label class="fieldLabel">次回やること</label>
              <textarea class="fieldTextarea shortArea" name="next_action" rows="3" placeholder="例：前回の旅行の続きから入る。最初の1杯は角ハイを提案"><?= h((string)($customer['next_action'] ?? '')) ?></textarea>
            </div>

            <button class="primaryBtn wideBtn" type="submit">変更を保存する</button>
          </form>
        </details>

        <section class="subPanel">
          <div class="sectionLabel">STEP 4</div>
          <h2 class="sectionTitle">営業メモを書く</h2>
          <p class="sectionHelp">次に会うときに役立つことを書きます。短い文でも大丈夫です。</p>

          <form method="post" class="stackForm">
            <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
            <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
            <input type="hidden" name="action" value="add_note">
            <input type="hidden" name="customer_id" value="<?= (int)$customer['id'] ?>">

            <div class="inputGroup">
              <label class="fieldLabel">新しいメモ</label>
              <textarea class="fieldTextarea" name="note_text" rows="5" required placeholder="例：今日は機嫌がよかった。次は前回の話題を覚えていると言うと喜ぶ。好みは角ハイ。"></textarea>
            </div>

            <button class="primaryBtn wideBtn" type="submit">メモを追加する</button>
          </form>
        </section>

        <section class="subPanel">
          <div class="sectionLabel">MEMO</div>
          <h2 class="sectionTitle">これまでのメモ</h2>
          <?php if ($notes): ?>
            <div class="memoList">
              <?php foreach ($notes as $n): ?>
                <article class="memoCard">
                  <div class="memoMeta">
                    <span><?= h((string)($n['author_name'] ?? '')) ?></span>
                    <span><?= h((string)$n['created_at']) ?></span>
                  </div>
                  <div class="memoBody"><?= nl2br(h((string)$n['note_text'])) ?></div>
                </article>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="emptyBox compact">
              <div class="emptyTitle">まだメモはありません</div>
            </div>
          <?php endif; ?>
        </section>

        <details class="foldBox">
          <summary>
            <div>
              <div class="foldTitle">重複候補と統合を開く</div>
              <div class="sectionHelp">ふだんは閉じたままでOKです。同じ人が2回登録されたときだけ使います。</div>
            </div>
          </summary>
          <div class="foldBody">
            <?php if ($dupes): ?>
              <div class="dupeCards">
                <?php foreach ($dupes as $d): ?>
                  <div class="dupeCard">
                    <div class="customerId">#<?= (int)$d['id'] ?></div>
                    <div class="dupeName"><?= h((string)$d['name']) ?></div>
                    <div class="sectionHelp"><?= h((string)$d['feature']) ?></div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php else: ?>
              <div class="emptyBox compact">
                <div class="emptyTitle">重複候補はありません</div>
              </div>
            <?php endif; ?>

            <form method="post" class="mergeForm">
              <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
              <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
              <input type="hidden" name="action" value="merge">
              <div class="inputGroup">
                <label class="fieldLabel">統合元ID</label>
                <input class="fieldInput" name="from_id" inputmode="numeric" value="<?= (int)$customer['id'] ?>" placeholder="統合元ID">
              </div>
              <div class="inputGroup">
                <label class="fieldLabel">統合先ID</label>
                <input class="fieldInput" name="to_id" inputmode="numeric" placeholder="統合先ID">
              </div>
              <button class="secondaryBtn wideBtn" onclick="return confirm('統合します。戻せません。OK？')">この2件を統合する</button>
            </form>
          </div>
        </details>
      <?php endif; ?>
    </section>
  </div>
</div>

<style>
.customerPage{
  max-width:1280px;
  margin:0 auto;
  padding:16px;
  display:grid;
  gap:16px;
}
.welcomePanel{
  display:grid;
  grid-template-columns:minmax(0,1.3fr) minmax(320px,.9fr);
  gap:16px;
  padding:22px;
  border:1px solid var(--line);
  border-radius:28px;
  background:
    radial-gradient(circle at top left, color-mix(in srgb, var(--accent) 12%, transparent), transparent 36%),
    linear-gradient(180deg, color-mix(in srgb, var(--cardA) 92%, white 2%), color-mix(in srgb, var(--cardA) 98%, transparent));
  box-shadow:0 16px 40px rgba(15,23,42,.10);
}
.welcomeMain,
.welcomeStats{
  display:grid;
  gap:12px;
}
.eyebrow{
  font-size:12px;
  font-weight:900;
  letter-spacing:.12em;
  text-transform:uppercase;
  color:var(--muted);
}
.pageTitle{
  margin:0;
  font-size:clamp(26px, 4vw, 42px);
  line-height:1.1;
  font-weight:1000;
}
.pageLead{
  margin:0;
  font-size:15px;
  line-height:1.7;
  color:var(--muted);
}
.jumpLinks{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.jumpBtn,
.secondaryBtn,
.primaryBtn{
  min-height:52px;
  padding:12px 18px;
  border-radius:18px;
  text-decoration:none;
  font-weight:900;
  display:inline-flex;
  align-items:center;
  justify-content:center;
  border:1px solid var(--line);
  cursor:pointer;
}
.jumpBtn,
.secondaryBtn{
  background:color-mix(in srgb, var(--cardA) 90%, transparent);
  color:var(--txt);
}
.primaryBtn{
  background:color-mix(in srgb, var(--accent) 16%, var(--cardA));
  color:var(--txt);
  border-color:color-mix(in srgb, var(--accent) 45%, var(--line));
}
.wideBtn{
  width:100%;
}
.welcomeStats{
  grid-template-columns:repeat(2, minmax(0,1fr));
}
.summaryTile{
  padding:16px;
  border-radius:22px;
  border:1px solid var(--line);
  background:color-mix(in srgb, var(--cardA) 86%, transparent);
  display:grid;
  gap:6px;
}
.summaryTile span,
.summaryTile small{
  font-size:12px;
  color:var(--muted);
}
.summaryTile strong{
  font-size:28px;
  line-height:1;
}
.notice{
  padding:14px 16px;
  border-radius:18px;
  border:1px solid var(--line);
  font-weight:800;
}
.noticeError{
  border-color:rgba(239,68,68,.35);
  background:rgba(239,68,68,.10);
}
.noticeSuccess{
  border-color:rgba(34,197,94,.35);
  background:rgba(34,197,94,.10);
}
.searchBox,
.panel,
.subPanel{
  border:1px solid var(--line);
  border-radius:24px;
  background:color-mix(in srgb, var(--cardA) 94%, transparent);
  box-shadow:0 12px 28px rgba(15,23,42,.06);
}
.searchBox,
.panel{
  padding:20px;
}
.subPanel{
  padding:18px;
}
.sectionLabel{
  font-size:11px;
  font-weight:900;
  letter-spacing:.10em;
  text-transform:uppercase;
  color:var(--muted);
}
.sectionTitle{
  margin:4px 0 6px;
  font-size:26px;
  font-weight:1000;
  line-height:1.2;
}
.sectionHelp{
  margin:0;
  font-size:14px;
  line-height:1.6;
  color:var(--muted);
}
.searchForm{
  margin-top:16px;
  display:grid;
  grid-template-columns:minmax(180px,.45fr) minmax(0,1fr) auto;
  gap:12px;
  align-items:end;
}
.inputGroup{
  display:grid;
  gap:6px;
}
.inputGroupWide{
  min-width:0;
}
.fieldLabel{
  font-size:13px;
  font-weight:800;
  color:var(--muted);
}
.fieldInput,
.fieldTextarea{
  width:100%;
  min-height:56px;
  padding:14px 16px;
  border-radius:18px;
  border:1px solid var(--line);
  background:color-mix(in srgb, var(--cardA) 96%, white 3%);
  color:var(--txt);
  font-size:16px;
}
.fieldTextarea{
  min-height:132px;
  resize:vertical;
}
.shortArea{
  min-height:96px;
}
.formButtons{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.workspaceGrid{
  display:grid;
  grid-template-columns:minmax(0,0.95fr) minmax(0,1.15fr);
  gap:16px;
  align-items:start;
}
.panelHead{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
  flex-wrap:wrap;
  margin-bottom:14px;
}
.customerCards,
.memoList,
.dupeCards{
  display:grid;
  gap:12px;
}
.customerCard{
  display:grid;
  gap:10px;
  padding:16px;
  border-radius:22px;
  border:1px solid var(--line);
  background:color-mix(in srgb, var(--cardA) 92%, transparent);
  color:var(--txt);
  text-decoration:none;
  transition:transform .15s ease, border-color .15s ease, background .15s ease;
}
.customerCard:hover{
  transform:translateY(-1px);
  border-color:color-mix(in srgb, var(--accent) 35%, var(--line));
}
.customerCard.current{
  border-color:color-mix(in srgb, var(--accent) 50%, var(--line));
  background:color-mix(in srgb, var(--accent) 10%, var(--cardA));
  box-shadow:inset 0 0 0 1px color-mix(in srgb, var(--accent) 18%, transparent);
}
.customerCardTop,
.profileHero,
.memoMeta{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
}
.customerId{
  font-size:12px;
  color:var(--muted);
  font-weight:800;
}
.customerTitle,
.profileName,
.dupeName{
  font-size:24px;
  font-weight:1000;
  line-height:1.2;
}
.customerText,
.profileFeature,
.memoBody,
.readValue{
  font-size:15px;
  line-height:1.7;
  word-break:break-word;
}
.focusGrid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:12px;
}
.focusCard{
  padding:16px;
  border-radius:20px;
  border:1px solid var(--line);
  background:color-mix(in srgb, var(--cardA) 92%, transparent);
}
.focusCard.warn{
  background:color-mix(in srgb, #f59e0b 8%, var(--cardA));
  border-color:color-mix(in srgb, #f59e0b 28%, var(--line));
}
.focusCard.danger{
  background:color-mix(in srgb, #ef4444 8%, var(--cardA));
  border-color:color-mix(in srgb, #ef4444 28%, var(--line));
}
.customerSub{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  font-size:12px;
  color:var(--muted);
}
.statusPill{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:34px;
  padding:0 12px;
  border-radius:999px;
  font-size:12px;
  font-weight:900;
  border:1px solid var(--line);
  white-space:nowrap;
}
.statusPill.ok{
  background:rgba(34,197,94,.14);
  border-color:rgba(34,197,94,.35);
}
.statusPill.off{
  background:rgba(148,163,184,.14);
  border-color:rgba(148,163,184,.28);
}
.createPanel{
  margin-top:18px;
}
.emptyBox{
  padding:22px;
  border-radius:22px;
  border:1px dashed var(--line);
  background:color-mix(in srgb, var(--cardA) 90%, transparent);
}
.emptyBox.tall{
  min-height:220px;
  display:grid;
  place-items:center;
  text-align:center;
}
.emptyBox.compact{
  padding:16px;
}
.emptyTitle{
  font-size:18px;
  font-weight:1000;
}
.detailPanel{
  display:grid;
  gap:16px;
}
.quickInfoGrid{
  display:grid;
  grid-template-columns:repeat(3, minmax(0,1fr));
  gap:12px;
}
.quickInfoCard,
.readBox,
.memoCard,
.dupeCard{
  padding:16px;
  border-radius:20px;
  border:1px solid var(--line);
  background:color-mix(in srgb, var(--cardA) 92%, transparent);
}
.quickInfoCard{
  display:grid;
  gap:8px;
}
.quickInfoCard span{
  font-size:12px;
  color:var(--muted);
}
.quickInfoCard strong{
  font-size:22px;
  font-weight:1000;
}
.foldBox{
  border:1px solid var(--line);
  border-radius:22px;
  background:color-mix(in srgb, var(--cardA) 92%, transparent);
  overflow:hidden;
}
.foldBox summary{
  list-style:none;
  cursor:pointer;
  padding:18px;
}
.foldBox summary::-webkit-details-marker{
  display:none;
}
.foldBox summary::after{
  content:'開く';
  float:right;
  font-size:12px;
  font-weight:900;
  color:var(--muted);
}
.foldBox[open] summary::after{
  content:'閉じる';
}
.foldTitle{
  font-size:20px;
  font-weight:1000;
  margin-bottom:4px;
}
.foldBody{
  padding:0 18px 18px;
}
.stackForm{
  display:grid;
  gap:12px;
}
.memoMeta{
  font-size:12px;
  color:var(--muted);
  flex-wrap:wrap;
}
.mergeForm{
  margin-top:14px;
  display:grid;
  grid-template-columns:repeat(2, minmax(0,1fr));
  gap:12px;
  align-items:end;
}
.mergeForm .wideBtn{
  grid-column:1 / -1;
}
@media (max-width: 1100px){
  .workspaceGrid{
    grid-template-columns:1fr;
  }
}
@media (max-width: 900px){
  .welcomePanel,
  .searchForm{
    grid-template-columns:1fr;
  }
  .welcomeStats,
  .quickInfoGrid,
  .focusGrid{
    grid-template-columns:repeat(2, minmax(0,1fr));
  }
}
@media (max-width: 640px){
  .customerPage{
    padding:12px;
  }
  .welcomePanel,
  .searchBox,
  .panel,
  .subPanel{
    padding:16px;
    border-radius:20px;
  }
  .pageTitle{
    font-size:30px;
  }
  .sectionTitle{
    font-size:22px;
  }
  .welcomeStats,
  .focusGrid,
  .mergeForm{
    grid-template-columns:1fr;
  }
  .welcomeStats{
    grid-template-columns:repeat(2, minmax(0,1fr));
  }
  .quickInfoGrid{
    grid-template-columns:1fr;
  }
  .jumpLinks,
  .formButtons{
    flex-direction:column;
  }
  .jumpBtn,
  .secondaryBtn,
  .primaryBtn{
    width:100%;
  }
  .customerCardTop,
  .profileHero,
  .memoMeta{
    flex-direction:column;
  }
}
</style>
<?php render_page_end(); ?>
