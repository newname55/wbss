<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/store_access.php';
require_once __DIR__ . '/../../app/repo_casts.php';
require_once __DIR__ . '/../../app/cast_growth.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}

$pdo = db();
$err = '';
$msg = '';
$stores = [];
$storeId = 0;
$storeName = '';
$casts = [];
$snapshots = [];
$selectedCastId = (int)($_GET['cast_id'] ?? 0);
$statusFilter = (string)($_GET['status'] ?? 'all');
$typeFilter = (string)($_GET['type'] ?? 'all');
$sort = (string)($_GET['sort'] ?? 'follow');

function csrf_token_growth(): string {
  if (empty($_SESSION['_csrf_growth'])) {
    $_SESSION['_csrf_growth'] = bin2hex(random_bytes(32));
  }
  return (string)$_SESSION['_csrf_growth'];
}

function csrf_verify_growth(?string $token): void {
  if (!$token || empty($_SESSION['_csrf_growth']) || !hash_equals((string)$_SESSION['_csrf_growth'], (string)$token)) {
    http_response_code(403);
    exit('csrf');
  }
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'save_note') {
  csrf_verify_growth((string)($_POST['csrf_token'] ?? ''));
  $postStoreId = (int)($_POST['store_id'] ?? 0);
  $postCastId = (int)($_POST['cast_id'] ?? 0);
  $redirect = trim((string)($_POST['redirect'] ?? '/wbss/public/admin/cast_growth.php'));

  try {
    $allowedStoreId = store_access_resolve_manageable_store_id($pdo, $postStoreId);
    cast_growth_save_note($pdo, $allowedStoreId, $postCastId, (int)(current_user_id() ?? 0), (string)($_POST['manager_note'] ?? ''));
    $separator = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $separator . 'saved_note=1');
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

try {
  $stores = store_access_allowed_stores($pdo);
  $storeId = store_access_resolve_manageable_store_id($pdo, (int)($_GET['store_id'] ?? 0));
  $storeName = store_access_find_store_name($stores, $storeId);
  $casts = repo_fetch_casts($pdo, $storeId, 'active_only');

  $growthMap = require __DIR__ . '/../../app/service_training_growth_map.php';

  foreach ($casts as $cast) {
    $castId = (int)($cast['id'] ?? 0);
    if ($castId <= 0) {
      continue;
    }

    $latestQuiz = get_cast_latest_service_type($pdo, $storeId, $castId);
    $typeKey = (string)($latestQuiz['result_type_key'] ?? 'all_rounder');
    $growthTheme = (array)($growthMap[$typeKey] ?? $growthMap['all_rounder'] ?? []);
    $weakTags = get_cast_weak_skill_tags($pdo, $storeId, $castId, 3);
    $missionStats = get_cast_mission_stats($pdo, $storeId, $castId, 7);
    $recentMissions = get_cast_recent_missions($pdo, $storeId, $castId, 7);
    $trainingRows = cast_growth_fetch_training_summaries($pdo, $storeId, $castId, 3);
    $latestTraining = $trainingRows[0] ?? null;
    $currentMission = cast_growth_current_mission($pdo, $storeId, $castId, $typeKey, $growthTheme, $weakTags);
    $badges = get_cast_badges($pdo, $storeId, $castId, $latestQuiz);
    $status = get_cast_growth_status($pdo, $storeId, $castId, $latestQuiz, $latestTraining, $weakTags, $missionStats);
    $growthThemeForCoaching = $growthTheme;
    $growthThemeForCoaching['mission_achievement_rate'] = (int)($missionStats['achievement_rate'] ?? 0);
    $growthThemeForCoaching['_mission_stats'] = $missionStats;
    $coaching = get_cast_manager_coaching($castId, $latestQuiz, $weakTags, $growthThemeForCoaching, $latestTraining, $currentMission);
    $themeLabel = cast_growth_training_theme_label($growthTheme, $latestTraining);

    $snapshots[] = [
      'cast' => $cast,
      'latest_quiz' => $latestQuiz,
      'latest_training' => $latestTraining,
      'training_rows' => $trainingRows,
      'weak_tags' => $weakTags,
      'mission_stats' => $missionStats,
      'recent_missions' => $recentMissions,
      'current_mission' => $currentMission,
      'badges' => $badges,
      'status' => $status,
      'coaching' => $coaching,
      'growth_theme' => $growthTheme,
      'theme_label' => $themeLabel,
      'type_key' => $typeKey,
      'type_name' => (string)(($latestQuiz['result_type']['name'] ?? '') ?: '未診断'),
      'type_copy' => (string)(($latestQuiz['result_type']['copy'] ?? '') ?: ''),
    ];
  }

  $typeOptions = [];
  foreach ($snapshots as $snapshot) {
    $key = (string)$snapshot['type_key'];
    $label = (string)$snapshot['type_name'];
    if ($key !== '' && $label !== '') {
      $typeOptions[$key] = $label;
    }
  }
  ksort($typeOptions);

  $snapshots = array_values(array_filter($snapshots, static function (array $snapshot) use ($statusFilter, $typeFilter): bool {
    if ($statusFilter !== 'all' && (string)($snapshot['status']['label'] ?? '') !== $statusFilter) {
      return false;
    }
    if ($typeFilter !== 'all' && (string)($snapshot['type_key'] ?? '') !== $typeFilter) {
      return false;
    }
    return true;
  }));

  usort($snapshots, static function (array $a, array $b) use ($sort): int {
    if ($sort === 'achievement') {
      return ((int)($b['mission_stats']['achievement_rate'] ?? 0) <=> (int)($a['mission_stats']['achievement_rate'] ?? 0))
        ?: strcmp((string)($a['cast']['display_name'] ?? ''), (string)($b['cast']['display_name'] ?? ''));
    }
    if ($sort === 'streak') {
      return ((int)($b['mission_stats']['streak_days'] ?? 0) <=> (int)($a['mission_stats']['streak_days'] ?? 0))
        ?: strcmp((string)($a['cast']['display_name'] ?? ''), (string)($b['cast']['display_name'] ?? ''));
    }
    if ($sort === 'name') {
      return strcmp((string)($a['cast']['display_name'] ?? ''), (string)($b['cast']['display_name'] ?? ''));
    }

    return (cast_growth_status_sort_weight((string)($a['status']['label'] ?? '')) <=> cast_growth_status_sort_weight((string)($b['status']['label'] ?? '')))
      ?: ((int)($a['mission_stats']['achievement_rate'] ?? 0) <=> (int)($b['mission_stats']['achievement_rate'] ?? 0))
      ?: strcmp((string)($a['cast']['display_name'] ?? ''), (string)($b['cast']['display_name'] ?? ''));
  });

  if ($selectedCastId <= 0 && $snapshots) {
    $selectedCastId = (int)($snapshots[0]['cast']['id'] ?? 0);
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
  $typeOptions = [];
}

if ((string)($_GET['saved_note'] ?? '') === '1') {
  $msg = '育成メモを保存しました。';
}

$selected = null;
foreach ($snapshots as $snapshot) {
  if ((int)($snapshot['cast']['id'] ?? 0) === $selectedCastId) {
    $selected = $snapshot;
    break;
  }
}
if ($selected === null && $snapshots) {
  $selected = $snapshots[0];
}

$followCount = count(array_filter($snapshots, static fn(array $row): bool => (string)($row['status']['label'] ?? '') === '要フォロー'));
$goodCount = count(array_filter($snapshots, static fn(array $row): bool => (string)($row['status']['label'] ?? '') === '好調'));
$risingCount = count(array_filter($snapshots, static fn(array $row): bool => (string)($row['status']['label'] ?? '') === '伸び中'));
$mvpCandidate = cast_growth_mvp_candidate($snapshots);

function cast_growth_query(array $override = []): string {
  $params = array_merge($_GET, $override);
  foreach ($params as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    }
  }
  return http_build_query($params);
}

function cast_growth_status_badge(array $status): string {
  $label = h((string)($status['label'] ?? '未分析'));
  $class = h((string)($status['class'] ?? 'is-none'));
  return '<span class="growthStatusBadge ' . $class . '">' . $label . '</span>';
}

render_page_start('育成指示');
render_header('育成指示', [
  'back_href' => '/wbss/public/store_casts_list.php?store_id=' . (int)$storeId,
  'back_label' => '← キャスト一覧',
  'show_store' => false,
]);
?>
<div class="page">
  <div class="admin-wrap growthPage">
    <?php if ($err !== ''): ?>
      <div class="card growthError"><?= h($err) ?></div>
    <?php endif; ?>
    <?php if ($msg !== ''): ?>
      <div class="card growthFlash"><?= h($msg) ?></div>
    <?php endif; ?>

    <section class="card growthHero">
      <div>
        <div class="growthHero__eyebrow">WBSS Cast Growth</div>
        <h1>店長向け育成指示</h1>
        <p>接客タイプ、最近の弱点、ミッション状況をまとめて、今の声かけをすぐ判断できるようにしています。</p>
      </div>
      <div class="growthHero__stats">
        <div class="growthHero__stat">
          <span>要フォロー</span>
          <strong><?= (int)$followCount ?></strong>
        </div>
        <div class="growthHero__stat">
          <span>伸び中</span>
          <strong><?= (int)$risingCount ?></strong>
        </div>
        <div class="growthHero__stat">
          <span>好調</span>
          <strong><?= (int)$goodCount ?></strong>
        </div>
      </div>
    </section>

    <?php if ($mvpCandidate): ?>
      <?php
        $mvpCast = (array)($mvpCandidate['cast'] ?? []);
        $mvpMissionStats = (array)($mvpCandidate['mission_stats'] ?? []);
      ?>
      <section class="card growthMvpCard">
        <div class="growthMvpCard__eyebrow">今週のMVP候補</div>
        <div class="growthMvpCard__grid">
          <img class="growthMvpCard__image" src="<?= h(cast_growth_type_image_path((string)($mvpCandidate['type_key'] ?? 'all_rounder'))) ?>" alt="<?= h((string)($mvpCandidate['type_name'] ?? '')) ?>">
          <div>
            <div class="growthMvpCard__name"><?= h((string)($mvpCast['display_name'] ?? '')) ?></div>
            <div class="growthMvpCard__type"><?= h((string)($mvpCandidate['type_name'] ?? '')) ?></div>
            <p class="growthMvpCard__copy">達成率 <?= (int)($mvpMissionStats['achievement_rate'] ?? 0) ?>% / 連続 <?= (int)($mvpMissionStats['streak_days'] ?? 0) ?>日。今週の前向きな声かけ候補です。</p>
          </div>
          <a class="btn" href="?<?= h(cast_growth_query(['cast_id' => (int)($mvpCast['id'] ?? 0)])) ?>">詳細を見る</a>
        </div>
      </section>
    <?php endif; ?>

    <form method="get" class="card growthFilters">
      <div class="growthFilters__row">
        <?php if (count($stores) > 1): ?>
          <label>
            <span>店舗</span>
            <select name="store_id">
              <?php foreach ($stores as $store): ?>
                <option value="<?= (int)($store['id'] ?? 0) ?>" <?= (int)($store['id'] ?? 0) === $storeId ? 'selected' : '' ?>>
                  <?= h((string)($store['name'] ?? '')) ?>
                </option>
              <?php endforeach; ?>
            </select>
          </label>
        <?php else: ?>
          <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
        <?php endif; ?>

        <label>
          <span>状態</span>
          <select name="status">
            <?php foreach (['all' => 'すべて', '要フォロー' => '要フォロー', '伸び中' => '伸び中', '好調' => '好調', '未分析' => '未分析'] as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $statusFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          <span>タイプ</span>
          <select name="type">
            <option value="all">すべて</option>
            <?php foreach ($typeOptions as $value => $label): ?>
              <option value="<?= h($value) ?>" <?= $typeFilter === $value ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </label>

        <label>
          <span>並び順</span>
          <select name="sort">
            <option value="follow" <?= $sort === 'follow' ? 'selected' : '' ?>>要フォロー順</option>
            <option value="achievement" <?= $sort === 'achievement' ? 'selected' : '' ?>>達成率順</option>
            <option value="streak" <?= $sort === 'streak' ? 'selected' : '' ?>>連続達成順</option>
            <option value="name" <?= $sort === 'name' ? 'selected' : '' ?>>名前順</option>
          </select>
        </label>

        <button type="submit" class="btn btn-primary">更新</button>
      </div>
    </form>

    <section class="growthLayout">
      <div class="growthListCol">
        <div class="card growthList">
          <div class="growthList__head">
            <h2>キャスト一覧</h2>
            <span><?= count($snapshots) ?>人</span>
          </div>
          <?php if ($snapshots): ?>
            <div class="growthList__items">
              <?php foreach ($snapshots as $snapshot): ?>
                <?php
                  $cast = (array)$snapshot['cast'];
                  $castId = (int)($cast['id'] ?? 0);
                  $selectedClass = ($selected && (int)($selected['cast']['id'] ?? 0) === $castId) ? ' is-active' : '';
                  $weakPreview = array_slice(array_keys((array)$snapshot['weak_tags']), 0, 2);
                ?>
                <a class="growthListItem<?= $selectedClass ?>" href="?<?= h(cast_growth_query(['cast_id' => $castId])) ?>">
                  <img class="growthListItem__thumb" src="<?= h(cast_growth_type_image_path((string)$snapshot['type_key'])) ?>" alt="<?= h((string)$snapshot['type_name']) ?>">
                  <div class="growthListItem__main">
                    <div class="growthListItem__top">
                      <strong><?= h((string)($cast['display_name'] ?? '')) ?></strong>
                      <?= cast_growth_status_badge((array)$snapshot['status']) ?>
                    </div>
                    <div class="growthListItem__type"><?= h((string)$snapshot['type_name']) ?></div>
                    <div class="growthListItem__theme"><?= h((string)$snapshot['theme_label']) ?></div>
                    <div class="growthListItem__tags">
                      <?php if ($weakPreview): ?>
                        <?php foreach ($weakPreview as $tag): ?>
                          <span class="is-weak"><?= h((string)$tag) ?></span>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <span>弱点データなし</span>
                      <?php endif; ?>
                    </div>
                    <div class="growthListItem__meta">
                      <span>達成率 <?= (int)($snapshot['mission_stats']['achievement_rate'] ?? 0) ?>%</span>
                      <span>連続 <?= (int)($snapshot['mission_stats']['streak_days'] ?? 0) ?>日</span>
                      <span>バッジ <?= count((array)($snapshot['badges']['earned'] ?? [])) ?>個</span>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="growthEmpty">条件に合うキャストがいません。</div>
          <?php endif; ?>
        </div>
      </div>

      <div class="growthDetailCol">
        <?php if ($selected): ?>
          <?php
            $cast = (array)$selected['cast'];
            $quiz = (array)($selected['latest_quiz'] ?? []);
            $type = (array)($quiz['result_type'] ?? []);
            $training = (array)($selected['latest_training'] ?? []);
            $coaching = (array)($selected['coaching'] ?? []);
            $currentMission = (array)($selected['current_mission'] ?? []);
            $badges = (array)($selected['badges'] ?? []);
          ?>
          <section class="card growthDetailHero">
            <img class="growthDetailHero__image" src="<?= h(cast_growth_type_image_path((string)$selected['type_key'])) ?>" alt="<?= h((string)$selected['type_name']) ?>">
            <div class="growthDetailHero__main">
              <div class="growthDetailHero__name"><?= h((string)($cast['display_name'] ?? '')) ?></div>
              <div class="growthDetailHero__typeRow">
                <div>
                  <div class="growthDetailHero__type"><?= h((string)$selected['type_name']) ?></div>
                  <?php if ((string)$selected['type_copy'] !== ''): ?>
                    <div class="growthDetailHero__copy"><?= h((string)$selected['type_copy']) ?></div>
                  <?php endif; ?>
                </div>
                <?= cast_growth_status_badge((array)$selected['status']) ?>
              </div>
              <p class="growthDetailHero__summary"><?= h((string)($coaching['manager_summary'] ?? '')) ?></p>
            </div>
          </section>

          <section class="growthDetailGrid">
            <div class="card growthCard growthCard--focus">
              <h3>店長向け 今の声かけ</h3>
              <p class="growthFocusLine"><?= h((string)($coaching['coach_now'] ?? '')) ?></p>
              <div class="growthGuides">
                <div>
                  <dt>OKな伝え方</dt>
                  <dd><?= h((string)($coaching['ok_line'] ?? '')) ?></dd>
                </div>
                <div>
                  <dt>NGな言い方</dt>
                  <dd><?= h((string)($coaching['ng_line'] ?? '')) ?></dd>
                </div>
                <div>
                  <dt>伸ばし方</dt>
                  <dd><?= h((string)($coaching['growth_style'] ?? '')) ?></dd>
                </div>
              </div>
            </div>

            <div class="card growthCard">
              <h3>その子の強み</h3>
              <ul class="growthListBullets">
                <?php foreach (array_slice(array_values(array_unique(array_merge((array)($type['strengths'] ?? []), (array)($coaching['leverage_points'] ?? [])))), 0, 5) as $item): ?>
                  <li><?= h((string)$item) ?></li>
                <?php endforeach; ?>
              </ul>
            </div>

            <div class="card growthCard growthCard--weak">
              <h3>最近よく弱く出る所作</h3>
              <?php if (!empty($selected['weak_tags'])): ?>
                <div class="growthWeakTags">
                  <?php foreach ((array)$selected['weak_tags'] as $tag => $count): ?>
                    <span><?= h((string)$tag) ?> <small>× <?= (int)$count ?></small></span>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="growthMuted">まだ弱点データは十分に集まっていません。</p>
              <?php endif; ?>
            </div>

            <div class="card growthCard growthCard--mission">
              <h3>今の育成テーマ</h3>
              <?php if ($currentMission): ?>
                <div class="growthMission__label">今日のミッション</div>
                <div class="growthMission__title"><?= h((string)($currentMission['action_text'] ?? '')) ?></div>
                <div class="growthMission__reason">
                  <strong>なぜこれをやるか</strong>
                  <span><?= h(service_training_mission_reason($currentMission)) ?></span>
                </div>
                <div class="growthMission__tip">
                  <strong>今日のコツ</strong>
                  <span><?= h((string)($currentMission['success_hint'] ?? '')) ?></span>
                </div>
              <?php else: ?>
                <p class="growthMuted">今のミッションはまだ作られていません。</p>
              <?php endif; ?>
            </div>

            <div class="card growthCard">
              <h3>向いている席・客層</h3>
              <?php if (!empty($coaching['seat_matches'])): ?>
                <ul class="growthListBullets">
                  <?php foreach ((array)$coaching['seat_matches'] as $item): ?>
                    <li><?= h((string)$item) ?></li>
                  <?php endforeach; ?>
                </ul>
              <?php else: ?>
                <p class="growthMuted">客層のデータはまだありません。</p>
              <?php endif; ?>
            </div>

            <div class="card growthCard">
              <h3>最近のミッション履歴</h3>
              <?php if (!empty($selected['recent_missions'])): ?>
                <div class="growthMissionHistory">
                  <?php foreach ((array)$selected['recent_missions'] as $row): ?>
                    <div class="growthMissionHistory__item">
                      <div>
                        <strong><?= h((string)($row['mission_title'] ?? 'ミッション')) ?></strong>
                        <div class="growthMuted"><?= h((string)($row['log_date'] ?? '')) ?></div>
                      </div>
                      <span class="growthMiniStatus is-<?= h((string)($row['status'] ?? 'pending')) ?>"><?= h((string)($row['status'] ?? '')) ?></span>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php else: ?>
                <p class="growthMuted">まだミッション履歴はありません。</p>
              <?php endif; ?>
            </div>

            <div class="card growthCard">
              <h3>バッジ</h3>
              <div class="growthBadgeWrap">
                <?php foreach ((array)($badges['earned'] ?? []) as $badge): ?>
                  <span class="growthBadge is-earned"><?= h((string)($badge['name'] ?? '')) ?></span>
                <?php endforeach; ?>
                <?php foreach (array_slice((array)($badges['locked'] ?? []), 0, 3) as $badge): ?>
                  <span class="growthBadge"><?= h((string)($badge['display_name'] ?? $badge['name'] ?? '')) ?> <small><?= h((string)($badge['progress_text'] ?? '')) ?></small></span>
                <?php endforeach; ?>
              </div>
            </div>

            <div class="card growthCard growthCard--memo">
              <h3>店長メモ</h3>
              <?php $managerNote = cast_growth_fetch_note($pdo, $storeId, (int)($cast['id'] ?? 0)); ?>
              <form method="post" class="growthNoteForm">
                <input type="hidden" name="csrf_token" value="<?= h(csrf_token_growth()) ?>">
                <input type="hidden" name="action" value="save_note">
                <input type="hidden" name="store_id" value="<?= (int)$storeId ?>">
                <input type="hidden" name="cast_id" value="<?= (int)($cast['id'] ?? 0) ?>">
                <input type="hidden" name="redirect" value="<?= h('/wbss/public/admin/cast_growth.php?' . cast_growth_query(['cast_id' => (int)($cast['id'] ?? 0)])) ?>">
                <textarea name="manager_note" rows="5" placeholder="例: 今日は最初の笑顔と最後の一言を一回ずつ意識してもらう。"><?= h((string)($managerNote['note'] ?? '')) ?></textarea>
                <div class="growthNoteForm__footer">
                  <div class="growthMuted">
                    <?php if ($managerNote): ?>
                      最終更新: <?= h((string)($managerNote['updated_at'] ?? '')) ?>
                    <?php else: ?>
                      ここに店長の一言メモを残せます。
                    <?php endif; ?>
                  </div>
                  <button type="submit" class="btn btn-primary">メモを保存</button>
                </div>
              </form>
            </div>
          </section>
        <?php else: ?>
          <section class="card growthEmptyDetail">
            キャストを選ぶと、ここに育成指示の詳細が表示されます。
          </section>
        <?php endif; ?>
      </div>
    </section>
  </div>
</div>

<style>
.growthPage{display:grid;gap:16px}
.growthHero,.growthFilters,.growthList,.growthDetailHero,.growthCard,.growthEmptyDetail{
  background:
    radial-gradient(circle at top right, rgba(255,255,255,.82), transparent 30%),
    linear-gradient(180deg, #ffffff, #fbfbfd);
  border-color:#e6e7ee;
  box-shadow:0 18px 40px rgba(26,32,44,.06);
}
.growthHero{
  display:grid;
  grid-template-columns:minmax(0, 1fr) auto;
  gap:18px;
  align-items:start;
}
.growthHero__eyebrow{display:inline-flex;padding:7px 11px;border-radius:999px;border:1px solid #e4e8f0;background:#fff;color:#6b7280;font-size:11px;font-weight:1000;letter-spacing:.08em}
.growthHero h1{margin:12px 0 0;font-size:30px;line-height:1.2;font-weight:1000}
.growthHero p{margin:12px 0 0;color:#6b7280;font-size:14px;line-height:1.8;max-width:58em}
.growthHero__stats{display:grid;grid-template-columns:repeat(3, minmax(110px, 1fr));gap:12px}
.growthHero__stat{padding:16px;border-radius:18px;background:#f8fafc;border:1px solid #e7edf5;text-align:center}
.growthHero__stat span{display:block;font-size:12px;font-weight:800;color:#6b7280}
.growthHero__stat strong{display:block;margin-top:8px;font-size:28px;font-weight:1000;color:#111827}
.growthFlash{padding:14px 16px;color:#166534;border-color:#bbf7d0;background:linear-gradient(180deg, #ecfdf5 0%, #ffffff 100%)}
.growthMvpCard{padding:18px;border-color:#f5d8a8;background:linear-gradient(180deg, #fffaf0 0%, #ffffff 100%)}
.growthMvpCard__eyebrow{display:inline-flex;padding:7px 11px;border-radius:999px;border:1px solid #f2d4a4;background:#fff7e6;color:#9a3412;font-size:11px;font-weight:1000;letter-spacing:.08em}
.growthMvpCard__grid{display:grid;grid-template-columns:76px minmax(0, 1fr) auto;gap:14px;align-items:center;margin-top:14px}
.growthMvpCard__image{width:76px;height:108px;border-radius:16px;border:1px solid #eadbc6;background:#fff;object-fit:cover}
.growthMvpCard__name{font-size:22px;font-weight:1000;color:#111827}
.growthMvpCard__type{margin-top:4px;font-size:13px;font-weight:900;color:#9a3412}
.growthMvpCard__copy{margin:8px 0 0;color:#6b7280;font-size:13px;line-height:1.75}
.growthFilters{padding:16px}
.growthFilters__row{display:grid;grid-template-columns:repeat(5, minmax(0, 1fr));gap:12px;align-items:end}
.growthFilters label{display:grid;gap:6px}
.growthFilters span{font-size:12px;font-weight:800;color:#6b7280}
.growthFilters select{min-height:42px;padding:0 12px;border-radius:12px;border:1px solid #d8deea;background:#fff;font:inherit}
.growthLayout{display:grid;grid-template-columns:360px minmax(0, 1fr);gap:16px;align-items:start}
.growthList{padding:18px}
.growthList__head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px}
.growthList__head h2,.growthCard h3{margin:0;font-size:20px;font-weight:1000}
.growthList__head span{font-size:12px;font-weight:900;color:#6b7280}
.growthList__items{display:grid;gap:12px}
.growthListItem{
  display:grid;
  grid-template-columns:72px minmax(0, 1fr);
  gap:14px;
  padding:14px;
  border-radius:18px;
  border:1px solid #e6ebf2;
  background:#f8fafc;
  text-decoration:none;
  color:inherit;
  transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
}
.growthListItem:hover{transform:translateY(-1px);box-shadow:0 14px 28px rgba(15,23,42,.08)}
.growthListItem.is-active{border-color:#fdba74;background:#fff8f0}
.growthListItem__thumb{width:72px;height:108px;object-fit:cover;border-radius:14px;border:1px solid #e7d6c0;background:#fff}
.growthListItem__main{min-width:0}
.growthListItem__top{display:flex;align-items:flex-start;justify-content:space-between;gap:10px}
.growthListItem__top strong{font-size:18px;line-height:1.25}
.growthListItem__type{margin-top:6px;font-size:13px;font-weight:900;color:#7c2d12}
.growthListItem__theme{margin-top:6px;color:#6b7280;font-size:12px;line-height:1.6}
.growthListItem__tags{display:flex;flex-wrap:wrap;gap:8px;margin-top:10px}
.growthListItem__tags span{display:inline-flex;min-height:28px;align-items:center;padding:0 10px;border-radius:999px;background:#eef2f7;border:1px solid #d9e1ec;font-size:11px;font-weight:900;color:#4b5563}
.growthListItem__tags span.is-weak{background:#fff1f2;border-color:#fecdd3;color:#be123c}
.growthListItem__meta{display:flex;flex-wrap:wrap;gap:10px;margin-top:10px;color:#6b7280;font-size:11px;font-weight:800}
.growthStatusBadge{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:1000;border:1px solid #e5e7eb;background:#f8fafc;color:#6b7280}
.growthStatusBadge.is-good{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
.growthStatusBadge.is-rising{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.growthStatusBadge.is-follow{background:#fff1f2;border-color:#fecdd3;color:#be123c}
.growthStatusBadge.is-none{background:#f8fafc;border-color:#e5e7eb;color:#6b7280}
.growthDetailCol{display:grid;gap:16px}
.growthDetailHero{display:grid;grid-template-columns:190px minmax(0, 1fr);gap:20px;align-items:start}
.growthDetailHero__image{width:100%;border-radius:22px;border:1px solid #eadbc6;background:#fff;display:block}
.growthDetailHero__name{font-size:13px;font-weight:900;color:#6b7280}
.growthDetailHero__typeRow{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-top:10px}
.growthDetailHero__type{font-size:34px;line-height:1.15;font-weight:1000}
.growthDetailHero__copy{margin-top:8px;font-size:20px;line-height:1.4;font-weight:900;color:#7c2d12}
.growthDetailHero__summary{margin:14px 0 0;color:#4b5563;font-size:15px;line-height:1.9}
.growthDetailGrid{display:grid;grid-template-columns:repeat(2, minmax(0, 1fr));gap:16px}
.growthCard{padding:20px}
.growthCard--focus{border-color:#f6d0ad;background:linear-gradient(180deg, #fff4e8 0%, #ffffff 100%)}
.growthCard--weak{border-color:#fecdd3;background:linear-gradient(180deg, #fff6f7 0%, #ffffff 100%)}
.growthCard--mission{border-color:#dbeafe;background:linear-gradient(180deg, #f8fbff 0%, #ffffff 100%)}
.growthCard--memo{border-color:#d9e7f8;background:linear-gradient(180deg, #f7fbff 0%, #ffffff 100%)}
.growthFocusLine{margin:14px 0 0;font-size:24px;line-height:1.5;font-weight:1000;color:#7c2d12}
.growthGuides{display:grid;gap:14px;margin-top:18px}
.growthGuides dt{font-size:12px;font-weight:900;color:#6b7280}
.growthGuides dd{margin:6px 0 0;font-size:14px;line-height:1.8;color:#374151}
.growthListBullets{margin:14px 0 0;padding-left:1.2em}
.growthListBullets li{margin-bottom:10px;line-height:1.8;color:#374151}
.growthWeakTags{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.growthWeakTags span{display:inline-flex;align-items:center;min-height:34px;padding:0 12px;border-radius:999px;background:#fff1f2;border:1px solid #fecdd3;font-size:13px;font-weight:900;color:#be123c}
.growthWeakTags small{margin-left:6px;font-size:11px}
.growthMission__label{margin-top:14px;font-size:12px;font-weight:1000;letter-spacing:.08em;color:#6b7280}
.growthMission__title{margin-top:10px;font-size:24px;line-height:1.45;font-weight:1000;color:#111827}
.growthMission__reason,.growthMission__tip{margin-top:14px;display:grid;gap:6px}
.growthMission__reason strong,.growthMission__tip strong{font-size:12px;font-weight:900;color:#6b7280}
.growthMission__reason span,.growthMission__tip span{font-size:14px;line-height:1.8;color:#4b5563}
.growthMissionHistory{display:grid;gap:10px;margin-top:14px}
.growthMissionHistory__item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 13px;border-radius:14px;background:#f8fafc;border:1px solid #e5e7eb}
.growthMissionHistory__item strong{font-size:14px}
.growthMiniStatus{display:inline-flex;align-items:center;justify-content:center;min-height:28px;padding:0 10px;border-radius:999px;font-size:11px;font-weight:900;border:1px solid #e5e7eb;background:#f8fafc;color:#6b7280}
.growthMiniStatus.is-done{background:#ecfdf5;border-color:#bbf7d0;color:#166534}
.growthMiniStatus.is-pending{background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}
.growthMiniStatus.is-skipped{background:#fff1f2;border-color:#fecdd3;color:#be123c}
.growthBadgeWrap{display:flex;flex-wrap:wrap;gap:10px;margin-top:14px}
.growthBadge{display:inline-flex;align-items:center;min-height:34px;padding:0 12px;border-radius:999px;background:#f8fafc;border:1px solid #e5e7eb;font-size:12px;font-weight:900;color:#4b5563}
.growthBadge.is-earned{background:#fff7e6;border-color:#f5d8a8;color:#8a4b12}
.growthBadge small{margin-left:6px;font-size:11px}
.growthNoteForm{display:grid;gap:12px;margin-top:14px}
.growthNoteForm textarea{width:100%;min-height:138px;padding:14px;border-radius:16px;border:1px solid #d8deea;background:#ffffff;color:#111827;font:inherit;line-height:1.75;resize:vertical}
.growthNoteForm__footer{display:flex;align-items:center;justify-content:space-between;gap:12px}
.growthMuted{margin:14px 0 0;color:#6b7280;font-size:14px;line-height:1.8}
.growthEmpty,.growthEmptyDetail,.growthError{padding:20px;color:#6b7280}
body[data-theme="dark"] .growthHero,
body[data-theme="dark"] .growthFlash,
body[data-theme="dark"] .growthMvpCard,
body[data-theme="dark"] .growthFilters,
body[data-theme="dark"] .growthList,
body[data-theme="dark"] .growthDetailHero,
body[data-theme="dark"] .growthCard,
body[data-theme="dark"] .growthEmptyDetail{
  background:
    radial-gradient(circle at top right, rgba(255,146,194,.2), transparent 38%),
    radial-gradient(circle at bottom left, rgba(164,140,255,.16), transparent 34%),
    linear-gradient(180deg, rgba(38,43,61,.96), rgba(44,50,71,.94));
  border-color:rgba(255,255,255,.10);
}
body[data-theme="dark"] .growthHero h1,
body[data-theme="dark"] .growthHero__stat strong,
body[data-theme="dark"] .growthList__head h2,
body[data-theme="dark"] .growthListItem__top strong,
body[data-theme="dark"] .growthMvpCard__name,
body[data-theme="dark"] .growthDetailHero__type,
body[data-theme="dark"] .growthDetailHero__copy,
body[data-theme="dark"] .growthCard h3,
body[data-theme="dark"] .growthFocusLine,
body[data-theme="dark"] .growthMission__title{
  color:#fff8fc;
}
body[data-theme="dark"] .growthHero p,
body[data-theme="dark"] .growthHero__eyebrow,
body[data-theme="dark"] .growthHero__stat span,
body[data-theme="dark"] .growthMvpCard__copy,
body[data-theme="dark"] .growthFilters span,
body[data-theme="dark"] .growthList__head span,
body[data-theme="dark"] .growthListItem__theme,
body[data-theme="dark"] .growthListItem__meta,
body[data-theme="dark"] .growthMuted,
body[data-theme="dark"] .growthGuides dt,
body[data-theme="dark"] .growthGuides dd,
body[data-theme="dark"] .growthListBullets li,
body[data-theme="dark"] .growthMission__reason strong,
body[data-theme="dark"] .growthMission__tip strong,
body[data-theme="dark"] .growthMission__reason span,
body[data-theme="dark"] .growthMission__tip span{
  color:rgba(230,223,240,.82);
}
body[data-theme="dark"] .growthHero__stat,
body[data-theme="dark"] .growthListItem,
body[data-theme="dark"] .growthNoteForm textarea,
body[data-theme="dark"] .growthMissionHistory__item,
body[data-theme="dark"] .growthBadge,
body[data-theme="dark"] .growthListItem__tags span,
body[data-theme="dark"] .growthFilters select{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
  color:#fff8fc;
}
body[data-theme="dark"] .growthListItem__tags span.is-weak,
body[data-theme="dark"] .growthWeakTags span{
  background:rgba(248,113,113,.16);
  border-color:rgba(248,113,113,.24);
}
body[data-theme="dark"] .growthMvpCard__type{
  color:#fbbf24;
}
body[data-theme="dark"] .growthDetailHero__name,
body[data-theme="dark"] .growthMission__label{
  color:rgba(230,223,240,.68);
}
@media (max-width: 1180px){
  .growthLayout{grid-template-columns:1fr}
}
@media (max-width: 860px){
  .growthHero{grid-template-columns:1fr}
  .growthHero__stats{grid-template-columns:repeat(3, minmax(0, 1fr))}
  .growthMvpCard__grid{grid-template-columns:76px minmax(0, 1fr)}
  .growthMvpCard__grid .btn{grid-column:1 / -1}
  .growthFilters__row{grid-template-columns:repeat(2, minmax(0, 1fr))}
  .growthDetailHero{grid-template-columns:1fr}
  .growthDetailHero__image{max-width:240px}
  .growthDetailGrid{grid-template-columns:1fr}
}
@media (max-width: 640px){
  .growthHero__stats,.growthFilters__row{grid-template-columns:1fr}
  .growthMvpCard__grid{grid-template-columns:1fr}
  .growthMvpCard__image{width:88px;height:126px}
  .growthListItem{grid-template-columns:58px minmax(0, 1fr);padding:12px}
  .growthListItem__thumb{width:58px;height:88px}
  .growthDetailHero__type{font-size:28px}
  .growthDetailHero__copy{font-size:18px}
  .growthFocusLine,.growthMission__title{font-size:21px}
  .growthNoteForm__footer{flex-direction:column;align-items:stretch}
}
</style>
<?php render_page_end(); ?>
