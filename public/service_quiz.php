<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';
require_once __DIR__ . '/../app/service_quiz.php';

require_login();
require_role(['cast']);

$pdo = db();
$userId = service_quiz_current_user_id();
$storeId = service_quiz_resolve_cast_store_id($pdo, $userId);

if ($storeId <= 0) {
  http_response_code(400);
  exit('店舗が未設定です。管理者に所属店舗を設定してもらってください。');
}

$questions = service_quiz_questions();
$questionMap = service_quiz_question_map();
$tableReady = service_quiz_results_table_ready($pdo);
$error = '';
$saveNotice = '';
$latestResult = null;
$displayResult = null;
$resultId = (int)($_GET['result_id'] ?? 0);
$isQuestionMode = ((string)($_GET['start'] ?? '') === '1');
$currentAnswers = [];

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  csrf_verify((string)($_POST['csrf_token'] ?? ''));

  if ((string)($_POST['action'] ?? '') === 'restart') {
    header('Location: /wbss/public/service_quiz.php?start=1');
    exit;
  }

  $currentAnswers = service_quiz_normalize_answers((array)($_POST['answers'] ?? []));
  $questionId = (int)($_POST['question_id'] ?? 0);
  $choice = strtoupper(trim((string)($_POST['choice'] ?? '')));

  if (!isset($questionMap[$questionId])) {
    $error = '診断データの読み込みに失敗しました。最初からやり直してください。';
    $isQuestionMode = true;
  } else {
    $choices = service_quiz_choice_map($questionMap[$questionId]);
    if (!isset($choices[$choice])) {
      $error = '選択肢を選んでください。';
      $isQuestionMode = true;
    } else {
      $currentAnswers[$questionId] = $choice;
      ksort($currentAnswers);

      if (count($currentAnswers) >= count($questions)) {
        $displayResult = service_quiz_calculate($currentAnswers);

        if ($tableReady) {
          try {
            $newId = service_quiz_save_result($pdo, $storeId, $userId, $currentAnswers);
            header('Location: /wbss/public/service_quiz.php?result_id=' . $newId . '&saved=1');
            exit;
          } catch (Throwable $e) {
            $error = '診断結果の保存に失敗しました: ' . $e->getMessage();
          }
        } else {
          $error = '診断結果テーブルが未作成のため、結果は表示のみです。`docs/create_cast_service_quiz_results.sql` を適用してください。';
        }
      } else {
        $isQuestionMode = true;
      }
    }
  }
}

if ($displayResult === null && $tableReady) {
  if ($resultId > 0) {
    $displayResult = service_quiz_fetch_result_by_id($pdo, $resultId, $storeId, $userId);
  }
  $latestResult = service_quiz_fetch_latest_result($pdo, $storeId, $userId);
  if ($displayResult === null && !$isQuestionMode) {
    $displayResult = $latestResult;
  }
} elseif ($displayResult === null) {
  $latestResult = null;
}

if ((string)($_GET['saved'] ?? '') === '1') {
  $saveNotice = '診断結果を保存しました。';
}

$nextIndex = count($currentAnswers);
$currentQuestion = $isQuestionMode ? ($questions[$nextIndex] ?? null) : null;
$progressCurrent = min($nextIndex + 1, count($questions));

render_page_start('接客タイプ診断');
render_header('接客タイプ診断', [
  'back_href' => '/wbss/public/dashboard_cast.php',
  'back_label' => '← ダッシュボード',
  'show_store' => false,
  'show_user' => false,
]);
?>
<div class="page">
  <div class="serviceQuiz">
    <?php if ($saveNotice !== ''): ?>
      <div class="card serviceQuizNotice serviceQuizNotice--ok"><?= h($saveNotice) ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
      <div class="card serviceQuizNotice serviceQuizNotice--error"><?= h($error) ?></div>
    <?php endif; ?>

    <?php if ($currentQuestion !== null): ?>
      <?php
        $progressPercent = (int)floor(($progressCurrent / max(1, count($questions))) * 100);
        $questionTypeLabel = (string)($currentQuestion['type'] ?? 'question');
      ?>
      <section class="card serviceQuizCard serviceQuizHero">
        <div class="serviceQuizHero__top">
          <div>
            <div class="serviceQuizEyebrow">WBSS 接客タイプ診断</div>
            <h1 class="serviceQuizTitle"><?= h((string)$currentQuestion['title']) ?> / <?= $progressCurrent ?>問目</h1>
          </div>
          <div class="serviceQuizCount"><?= count($currentAnswers) ?>/<?= count($questions) ?> 回答済み</div>
        </div>
        <div class="serviceQuizProgress" aria-hidden="true">
          <span style="width:<?= $progressPercent ?>%"></span>
        </div>
        <div class="serviceQuizProgressMeta"><?= $progressPercent ?>% 完了</div>
      </section>

      <section class="card serviceQuizCard serviceQuizQuestion">
        <div class="serviceQuizQuestion__label">
          <?php if ($questionTypeLabel === 'customer_quote'): ?>
            お客様:
          <?php elseif ($questionTypeLabel === 'situation'): ?>
            状況:
          <?php else: ?>
            シーン:
          <?php endif; ?>
        </div>
        <div class="serviceQuizQuestion__body">
          <?= h((string)$currentQuestion['question']) ?>
        </div>
        <div class="serviceQuizQuestion__prompt">
          <?= h((string)($currentQuestion['prompt'] ?? 'あなたが自然に返しやすいのは？')) ?>
        </div>

        <form method="post" class="serviceQuizChoices">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="question_id" value="<?= (int)$currentQuestion['id'] ?>">
          <?php foreach ($currentAnswers as $answeredQuestionId => $answeredChoice): ?>
            <input type="hidden" name="answers[<?= (int)$answeredQuestionId ?>]" value="<?= h($answeredChoice) ?>">
          <?php endforeach; ?>

          <?php foreach ((array)$currentQuestion['choices'] as $choice): ?>
            <button class="serviceQuizChoice" type="submit" name="choice" value="<?= h((string)$choice['key']) ?>">
              <span class="serviceQuizChoice__key"><?= h((string)$choice['key']) ?></span>
              <span class="serviceQuizChoice__text"><?= h((string)$choice['text']) ?></span>
            </button>
          <?php endforeach; ?>
        </form>
      </section>

      <form method="post" class="serviceQuizRestart">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="action" value="restart">
        <button type="submit" class="btn">最初からやり直す</button>
      </form>

    <?php elseif (is_array($displayResult)): ?>
      <?php
        $type = (array)($displayResult['result_type'] ?? []);
        $scores = (array)($displayResult['scores'] ?? []);
        $labels = (array)($displayResult['axis_labels'] ?? []);
        $createdAt = trim((string)($displayResult['created_at'] ?? ''));
      ?>
      <section class="card serviceQuizCard serviceQuizResultHero">
        <div class="serviceQuizEyebrow">あなたの接客タイプ</div>
        <h1 class="serviceQuizResultHero__title"><?= h((string)($type['name'] ?? '診断結果')) ?></h1>
        <p class="serviceQuizResultHero__summary"><?= h((string)($type['summary'] ?? '')) ?></p>
        <div class="serviceQuizAxisGrid">
          <div class="serviceQuizAxisCard">
            <span>会話</span>
            <strong><?= (int)($scores['talk_axis'] ?? 0) ?></strong>
            <small><?= h((string)($labels['talk_axis'] ?? '')) ?></small>
          </div>
          <div class="serviceQuizAxisCard">
            <span>空気</span>
            <strong><?= (int)($scores['mood_axis'] ?? 0) ?></strong>
            <small><?= h((string)($labels['mood_axis'] ?? '')) ?></small>
          </div>
          <div class="serviceQuizAxisCard">
            <span>反応</span>
            <strong><?= (int)($scores['response_axis'] ?? 0) ?></strong>
            <small><?= h((string)($labels['response_axis'] ?? '')) ?></small>
          </div>
          <div class="serviceQuizAxisCard">
            <span>関係性</span>
            <strong><?= (int)($scores['relation_axis'] ?? 0) ?></strong>
            <small><?= h((string)($labels['relation_axis'] ?? '')) ?></small>
          </div>
        </div>
        <?php if ($createdAt !== ''): ?>
          <div class="serviceQuizResultHero__meta">保存日時: <?= h($createdAt) ?></div>
        <?php endif; ?>
      </section>

      <section class="serviceQuizResultGrid">
        <div class="card serviceQuizCard serviceQuizSection">
          <h2>強み</h2>
          <ul>
            <?php foreach ((array)($type['strengths'] ?? []) as $item): ?>
              <li><?= h((string)$item) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="card serviceQuizCard serviceQuizSection">
          <h2>注意点</h2>
          <ul>
            <?php foreach ((array)($type['cautions'] ?? []) as $item): ?>
              <li><?= h((string)$item) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="card serviceQuizCard serviceQuizSection">
          <h2>相性の良い客層</h2>
          <ul>
            <?php foreach ((array)($type['best_customers'] ?? []) as $item): ?>
              <li><?= h((string)$item) ?></li>
            <?php endforeach; ?>
          </ul>
        </div>
        <div class="card serviceQuizCard serviceQuizSection serviceQuizSection--tip">
          <h2>今日の一言</h2>
          <p><?= h((string)($type['today_tip'] ?? '')) ?></p>
        </div>
      </section>

      <div class="serviceQuizActions">
        <a class="btn btn-primary" href="/wbss/public/service_quiz.php?start=1">もう一度診断する</a>
        <a class="btn" href="/wbss/public/dashboard_cast.php">ダッシュボードへ戻る</a>
      </div>

    <?php else: ?>
      <section class="card serviceQuizCard serviceQuizIntro">
        <div class="serviceQuizEyebrow">WBSS 接客タイプ診断</div>
        <h1 class="serviceQuizTitle">接客実務向けの自己診断</h1>
        <p class="serviceQuizIntro__lead">MBTI風ですが、性格診断ではなく「接客で自然に出やすい反応傾向」を見る12問の4択診断です。1問ずつ答えるだけで、4軸スコアから8タイプに分類します。</p>
        <div class="serviceQuizIntro__chips">
          <span>12問</span>
          <span>4軸スコア</span>
          <span>8タイプ判定</span>
          <span>最新結果を保存</span>
        </div>
        <?php if (is_array($latestResult)): ?>
          <div class="serviceQuizLatest">
            <div class="serviceQuizLatest__label">最新結果</div>
            <div class="serviceQuizLatest__name"><?= h((string)(($latestResult['result_type'] ?? [])['name'] ?? '')) ?></div>
            <div class="serviceQuizLatest__summary"><?= h((string)(($latestResult['result_type'] ?? [])['today_tip'] ?? '')) ?></div>
          </div>
        <?php endif; ?>
        <div class="serviceQuizActions">
          <a class="btn btn-primary" href="/wbss/public/service_quiz.php?start=1">診断を始める</a>
          <?php if (is_array($latestResult)): ?>
            <a class="btn" href="/wbss/public/service_quiz.php?result_id=<?= (int)$latestResult['id'] ?>">最新結果を見る</a>
          <?php endif; ?>
        </div>
      </section>
    <?php endif; ?>
  </div>
</div>

<style>
.serviceQuiz{max-width:760px;margin:0 auto;display:grid;gap:14px}
.serviceQuizCard{padding:18px}
.serviceQuizHero,.serviceQuizResultHero,.serviceQuizIntro{
  background:
    radial-gradient(circle at top right, rgba(255,168,206,.26), transparent 38%),
    radial-gradient(circle at bottom left, rgba(255,228,239,.34), transparent 36%),
    linear-gradient(180deg, color-mix(in srgb, var(--cardA) 95%, #fff8fc), color-mix(in srgb, var(--cardB) 94%, #fff2f8));
}
.serviceQuizEyebrow{display:inline-flex;padding:7px 11px;border-radius:999px;border:1px solid color-mix(in srgb, var(--accent) 24%, var(--line));font-size:11px;font-weight:1000;letter-spacing:.08em;color:var(--muted);background:rgba(255,255,255,.56)}
.serviceQuizTitle,.serviceQuizResultHero__title{margin:12px 0 0;font-size:28px;line-height:1.2;font-weight:1000}
.serviceQuizHero__top{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.serviceQuizCount,.serviceQuizResultHero__meta{color:var(--muted);font-size:12px;font-weight:800}
.serviceQuizProgress{height:10px;border-radius:999px;background:rgba(255,255,255,.62);margin-top:14px;overflow:hidden}
.serviceQuizProgress span{display:block;height:100%;border-radius:inherit;background:linear-gradient(90deg, #ff7aad, #ffb6d8)}
.serviceQuizProgressMeta{margin-top:8px;color:var(--muted);font-size:12px}
.serviceQuizQuestion__label{font-size:12px;font-weight:1000;color:var(--muted);letter-spacing:.04em}
.serviceQuizQuestion__body{margin-top:10px;font-size:24px;line-height:1.45;font-weight:1000}
.serviceQuizQuestion__prompt{margin-top:16px;color:var(--muted);font-size:13px}
.serviceQuizChoices{display:grid;gap:10px;margin-top:18px}
.serviceQuizChoice{
  display:flex;align-items:flex-start;gap:12px;width:100%;padding:16px;border-radius:18px;
  border:1px solid color-mix(in srgb, var(--accent) 18%, var(--line));background:rgba(255,255,255,.78);
  color:var(--txt);font:inherit;text-align:left;cursor:pointer;transition:transform .14s ease,border-color .14s ease,box-shadow .14s ease
}
.serviceQuizChoice:hover{transform:translateY(-1px);border-color:rgba(255,145,194,.56);box-shadow:0 16px 30px rgba(255,165,206,.12)}
.serviceQuizChoice__key{
  width:38px;height:38px;flex:0 0 auto;display:flex;align-items:center;justify-content:center;
  border-radius:14px;background:linear-gradient(180deg, rgba(255,245,250,.98), rgba(255,231,241,.92));
  border:1px solid rgba(255,182,211,.42);font-size:15px;font-weight:1000
}
.serviceQuizChoice__text{font-size:15px;line-height:1.65;font-weight:800}
.serviceQuizRestart{display:flex;justify-content:center}
.serviceQuizNotice{padding:14px 16px;font-weight:800}
.serviceQuizNotice--ok{border-color:rgba(111,224,176,.42);background:rgba(236,255,246,.8)}
.serviceQuizNotice--error{border-color:rgba(239,68,68,.35);background:rgba(255,241,241,.9)}
.serviceQuizResultHero__summary,.serviceQuizIntro__lead{margin:14px 0 0;color:var(--muted);font-size:14px;line-height:1.8}
.serviceQuizAxisGrid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:18px}
.serviceQuizAxisCard{
  display:grid;gap:4px;padding:14px;border-radius:18px;border:1px solid var(--line);
  background:rgba(255,255,255,.74)
}
.serviceQuizAxisCard span{font-size:12px;color:var(--muted);font-weight:800}
.serviceQuizAxisCard strong{font-size:28px;line-height:1;font-weight:1000}
.serviceQuizAxisCard small{font-size:12px;font-weight:800}
.serviceQuizResultGrid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.serviceQuizSection h2{margin:0 0 12px;font-size:16px}
.serviceQuizSection ul{margin:0;padding-left:18px;display:grid;gap:8px}
.serviceQuizSection li,.serviceQuizSection p{line-height:1.7}
.serviceQuizSection--tip p{margin:0;font-weight:800}
.serviceQuizActions{display:flex;gap:10px;flex-wrap:wrap}
.serviceQuizIntro__chips{display:flex;gap:8px;flex-wrap:wrap;margin-top:16px}
.serviceQuizIntro__chips span,.serviceQuizLatest{
  border:1px solid var(--line);background:rgba(255,255,255,.68);border-radius:16px
}
.serviceQuizIntro__chips span{padding:8px 12px;font-size:12px;font-weight:900}
.serviceQuizLatest{margin-top:16px;padding:14px}
.serviceQuizLatest__label{font-size:11px;color:var(--muted);font-weight:1000;letter-spacing:.08em}
.serviceQuizLatest__name{margin-top:6px;font-size:20px;font-weight:1000}
.serviceQuizLatest__summary{margin-top:6px;color:var(--muted);font-size:13px;line-height:1.7}
body[data-theme="dark"] .serviceQuizHero,
body[data-theme="dark"] .serviceQuizResultHero,
body[data-theme="dark"] .serviceQuizIntro{
  background:
    radial-gradient(circle at top right, rgba(255,146,194,.2), transparent 38%),
    radial-gradient(circle at bottom left, rgba(164,140,255,.16), transparent 34%),
    linear-gradient(180deg, rgba(38,43,61,.96), rgba(44,50,71,.94));
}
body[data-theme="dark"] .serviceQuizEyebrow,
body[data-theme="dark"] .serviceQuizAxisCard,
body[data-theme="dark"] .serviceQuizChoice,
body[data-theme="dark"] .serviceQuizIntro__chips span,
body[data-theme="dark"] .serviceQuizLatest{
  background:rgba(255,255,255,.08);
  border-color:rgba(255,255,255,.12);
  color:#fff8fc;
}
body[data-theme="dark"] .serviceQuizProgress{background:rgba(255,255,255,.1)}
body[data-theme="dark"] .serviceQuizQuestion__label,
body[data-theme="dark"] .serviceQuizQuestion__prompt,
body[data-theme="dark"] .serviceQuizCount,
body[data-theme="dark"] .serviceQuizProgressMeta,
body[data-theme="dark"] .serviceQuizResultHero__summary,
body[data-theme="dark"] .serviceQuizIntro__lead,
body[data-theme="dark"] .serviceQuizLatest__summary,
body[data-theme="dark"] .serviceQuizResultHero__meta,
body[data-theme="dark"] .serviceQuizAxisCard span{
  color:rgba(230,223,240,.82);
}
body[data-theme="dark"] .serviceQuizQuestion__body,
body[data-theme="dark"] .serviceQuizTitle,
body[data-theme="dark"] .serviceQuizResultHero__title,
body[data-theme="dark"] .serviceQuizLatest__name{
  color:#fff8fc;
}
body[data-theme="dark"] .serviceQuizChoice__key{background:rgba(255,255,255,.1);border-color:rgba(255,255,255,.14)}
@media (max-width: 640px){
  .serviceQuizCard{padding:16px}
  .serviceQuizTitle,.serviceQuizResultHero__title{font-size:24px}
  .serviceQuizQuestion__body{font-size:21px}
  .serviceQuizAxisGrid,.serviceQuizResultGrid{grid-template-columns:1fr}
  .serviceQuizActions{display:grid}
  .serviceQuizActions .btn{width:100%;text-align:center}
}
</style>
<?php render_page_end(); ?>
