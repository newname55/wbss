<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/layout.php';
require_once __DIR__ . '/../../app/store_access.php';
require_once __DIR__ . '/../../app/repo_applicants.php';

require_login();
require_role(['admin', 'manager', 'super_user']);

$pdo = db();
$personId = (int)($_GET['id'] ?? 0);
$stores = store_access_allowed_stores($pdo);
$interviewers = repo_applicants_fetch_interviewers($pdo);

$person = $personId > 0 ? repo_applicants_find_person($pdo, $personId) : null;
if ($personId > 0 && !$person) {
  http_response_code(404);
  exit('面接者が見つかりません');
}

$photos = $person ? repo_applicants_fetch_photos($pdo, $personId) : [];
$interviews = $person ? repo_applicants_fetch_interviews($pdo, $personId) : [];
$assignments = $person ? repo_applicants_fetch_assignments($pdo, $personId) : [];
$logs = $person ? repo_applicants_fetch_logs($pdo, $personId) : [];

$msg = trim((string)($_GET['msg'] ?? ''));
$err = trim((string)($_GET['err'] ?? ''));

function h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function person_value(?array $person, string $key): string {
  return h((string)($person[$key] ?? ''));
}

function detail_status_badge(?array $person): array {
  $status = (string)($person['current_status'] ?? 'interviewing');
  $active = (int)($person['is_currently_employed'] ?? 0);
  if ($active === 1 || $status === 'active') {
    return ['在籍中', 'ok'];
  }
  return match ($status) {
    'trial' => ['体験入店', 'trial'],
    'left' => ['退店', 'off'],
    'hold' => ['保留', 'hold'],
    default => ['面接中', 'base'],
  };
}

function detail_result_label(?string $value): string {
  return match ((string)$value) {
    'pass' => '合格',
    'hold' => '保留',
    'reject' => '不採用',
    'joined' => '入店',
    'pending' => '未判定',
    default => '—',
  };
}

function detail_trial_label(?string $value): string {
  return match ((string)$value) {
    'scheduled' => '予定',
    'completed' => '実施',
    'passed' => '合格',
    'failed' => '見送り',
    'cancelled' => '取消',
    'not_set' => '未設定',
    default => '—',
  };
}

function detail_transition_label(?string $value): string {
  return match ((string)$value) {
    'join' => '入店',
    'rejoin' => '再入店',
    'move' => '移動',
    default => '—',
  };
}

[$statusText, $statusClass] = detail_status_badge($person ?? []);

render_page_start($person ? '面接者詳細 / 編集' : '面接者新規作成');
render_header($person ? '面接者詳細 / 編集' : '面接者新規作成', [
  'back_href' => '/wbss/public/applicants/index.php',
  'back_label' => '← 面接者一覧',
]);
?>
<style>
  .page{max-width:1380px;margin:0 auto;padding:14px}
  .detailWrap{display:grid;gap:14px}
  .card{
    background:linear-gradient(180deg,var(--cardA,#fff),var(--cardB,#f8fafc));
    border:1px solid var(--line,#e5e7eb);
    border-radius:22px;
    padding:18px;
    box-shadow:var(--shadow,0 8px 24px rgba(15,23,42,.06));
  }
  .hero{display:grid;grid-template-columns:300px 1fr;gap:16px}
  .photoBox{border:1px dashed #d7deea;border-radius:20px;padding:14px;display:grid;gap:12px;align-content:start;background:rgba(255,255,255,.52)}
  .photoPreview{width:100%;aspect-ratio:3/4;border-radius:18px;background:#f3f4f6;display:flex;align-items:center;justify-content:center;overflow:hidden}
  .photoPreview img{width:100%;height:100%;object-fit:cover}
  .summaryGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .sumCard{padding:14px 16px;border:1px solid var(--line,#e5e7eb);border-radius:18px;background:rgba(255,255,255,.64)}
  .sumCard b{display:block;font-size:18px}
  .badge{display:inline-flex;align-items:center;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900;border:1px solid transparent}
  .ok{background:rgba(34,197,94,.12);color:#166534;border-color:rgba(34,197,94,.22)}.trial{background:rgba(245,158,11,.14);color:#92400e;border-color:rgba(245,158,11,.22)}.off{background:rgba(239,68,68,.12);color:#991b1b;border-color:rgba(239,68,68,.20)}.hold{background:rgba(99,102,241,.12);color:#3730a3;border-color:rgba(99,102,241,.18)}.base{background:rgba(148,163,184,.12);color:#374151;border-color:rgba(148,163,184,.18)}
  .storeChip{display:inline-flex;align-items:center;border-radius:999px;padding:6px 12px;background:rgba(37,99,235,.10);color:#1d4ed8;font-weight:900;border:1px solid rgba(37,99,235,.14)}
  .tabs{display:flex;gap:8px;overflow:auto;padding-bottom:4px}
  .tabBtn{border:1px solid var(--line,#e5e7eb);background:rgba(255,255,255,.72);border-radius:999px;padding:9px 15px;font-weight:900;cursor:pointer;white-space:nowrap}
  .tabBtn.is-active{background:#111827;color:#fff;border-color:#111827}
  .tabPanel{display:none}
  .tabPanel.is-active{display:block}
  .formGrid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}
  .field{display:grid;gap:6px}
  .field.full{grid-column:1/-1}
  .label{font-size:12px;color:var(--muted,#667085);font-weight:900}
  .input,.select,.textarea,.btnLine{min-height:52px;border-radius:18px;border:1px solid var(--line,#d7deea);padding:12px 16px;background:rgba(255,255,255,.9)}
  .textarea{min-height:110px;resize:vertical}
  .sectionTitle{font-size:16px;font-weight:1000;margin-bottom:10px}
  .tableWrap{overflow:auto}
  table{width:100%;border-collapse:separate;border-spacing:0}
  th,td{padding:12px;border-bottom:1px solid var(--line,#e5e7eb);vertical-align:top;text-align:left}
  th{font-size:12px;color:var(--muted,#667085);font-weight:900}
  .actionGrid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}
  .mini{font-size:12px;color:var(--muted,#667085);line-height:1.6}
  .noticeOk,.noticeErr{padding:12px;border-radius:14px;font-weight:900}
  .noticeOk{background:#ecfdf5;color:#166534;border:1px solid #a7f3d0}
  .noticeErr{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}
  @media (max-width: 980px){
    .hero{grid-template-columns:1fr}
    .summaryGrid,.formGrid,.actionGrid{grid-template-columns:repeat(2,minmax(0,1fr))}
  }
  @media (max-width: 640px){
    .summaryGrid,.formGrid,.actionGrid{grid-template-columns:1fr}
    .card{padding:14px}
  }
</style>

<div class="page">
  <div class="detailWrap">
  <?php if ($msg !== ''): ?><div class="noticeOk"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="noticeErr"><?= h($err) ?></div><?php endif; ?>

  <section class="card hero">
    <div class="photoBox">
      <div class="sectionTitle">顔写真</div>
      <div class="photoPreview">
        <?php if ($person && !empty($person['primary_photo_path'])): ?>
          <img src="<?= h((string)$person['primary_photo_path']) ?>" alt="顔写真">
        <?php else: ?>
          <div class="mini">顔写真未登録</div>
        <?php endif; ?>
      </div>
      <div class="mini">顔写真は必須です。一覧では写真有無を即判定します。</div>
      <?php if ($person): ?>
        <form method="post" action="/wbss/public/applicants/upload_photo.php" enctype="multipart/form-data">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
          <div class="field">
            <input class="input" type="file" name="face_photo" accept="image/jpeg,image/png,image/webp" required>
          </div>
          <button class="btnLine" style="margin-top:8px;cursor:pointer;font-weight:900;">写真を更新</button>
        </form>
      <?php endif; ?>
    </div>

    <div style="display:grid;gap:12px;">
      <div>
        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
          <div style="font-size:22px;font-weight:1000;"><?= $person ? h(trim((string)$person['last_name'] . ' ' . (string)$person['first_name'])) : '新規面接者' ?></div>
          <span class="badge <?= h($statusClass) ?>"><?= h($statusText) ?></span>
          <?php if ($person && !empty($person['current_store_name'])): ?><span class="storeChip">現在店舗: <?= h((string)$person['current_store_name']) ?></span><?php endif; ?>
        </div>
        <div class="mini" style="margin-top:6px;">
          <?php if ($person): ?>
            applicant_person_id #<?= (int)$person['id'] ?> / 最新面接ID <?= (int)($person['latest_interview_id'] ?? 0) ?>
          <?php else: ?>
            まず面接基本情報と顔写真を登録してください。
          <?php endif; ?>
        </div>
      </div>

      <div class="summaryGrid">
        <div class="sumCard"><span class="mini">在籍状況</span><b><?= h($statusText) ?></b></div>
        <div class="sumCard"><span class="mini">現在店舗</span><b><?= h((string)($person['current_store_name'] ?? '—')) ?></b></div>
        <div class="sumCard"><span class="mini">最新面接日</span><b><?= h((string)($person['latest_interviewed_at'] ?? '—')) ?></b></div>
        <div class="sumCard"><span class="mini">最新結果</span><b><?= h((string)($person['latest_interview_result'] ?? '—')) ?></b></div>
      </div>

      <form method="post" action="/wbss/public/applicants/save.php" enctype="multipart/form-data" class="card" style="padding:14px;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="person_id" value="<?= (int)($person['id'] ?? 0) ?>">

        <?php if (!$person): ?>
          <div class="field" style="margin-bottom:10px;">
            <label class="label">顔写真</label>
            <input class="input" type="file" name="face_photo" accept="image/jpeg,image/png,image/webp" required>
          </div>
        <?php endif; ?>

        <div class="tabs" data-tabs>
          <?php foreach ([
            'basic' => '基本情報',
            'current' => '現在の状況',
            'staff' => '担当者記入項目',
            'body' => '身体情報',
            'career' => '経歴 / 条件',
          ] as $tabKey => $tabLabel): ?>
            <button type="button" class="tabBtn<?= $tabKey === 'basic' ? ' is-active' : '' ?>" data-tab-target="<?= h($tabKey) ?>"><?= h($tabLabel) ?></button>
          <?php endforeach; ?>
        </div>

        <div class="tabPanel is-active" data-tab-panel="basic" style="margin-top:12px;">
          <div class="formGrid">
            <div class="field"><label class="label">姓</label><input class="input" name="last_name" value="<?= person_value($person, 'last_name') ?>" required></div>
            <div class="field"><label class="label">名</label><input class="input" name="first_name" value="<?= person_value($person, 'first_name') ?>" required></div>
            <div class="field"><label class="label">姓かな</label><input class="input" name="last_name_kana" value="<?= person_value($person, 'last_name_kana') ?>"></div>
            <div class="field"><label class="label">名かな</label><input class="input" name="first_name_kana" value="<?= person_value($person, 'first_name_kana') ?>"></div>
            <div class="field"><label class="label">生年月日</label><input class="input" type="date" name="birth_date" value="<?= person_value($person, 'birth_date') ?>"></div>
            <div class="field"><label class="label">電話番号</label><input class="input" name="phone" value="<?= person_value($person, 'phone') ?>"></div>
            <div class="field"><label class="label">郵便番号</label><input class="input" name="postal_code" value="<?= person_value($person, 'postal_code') ?>"></div>
            <div class="field"><label class="label">血液型</label><input class="input" name="blood_type" value="<?= person_value($person, 'blood_type') ?>" placeholder="A / B / O / AB"></div>
            <div class="field full"><label class="label">現住所</label><input class="input" name="current_address" value="<?= person_value($person, 'current_address') ?>"></div>
            <div class="field full"><label class="label">以前住所</label><input class="input" name="previous_address" value="<?= person_value($person, 'previous_address') ?>"></div>
          </div>
        </div>

        <div class="tabPanel" data-tab-panel="current" style="margin-top:12px;">
          <div class="formGrid">
            <div class="field"><label class="label">現在ステータス</label><input class="input" value="<?= h($statusText) ?>" disabled></div>
            <div class="field"><label class="label">現在店舗</label><input class="input" value="<?= h((string)($person['current_store_name'] ?? '')) ?>" disabled></div>
            <div class="field"><label class="label">源氏名</label><input class="input" value="<?= person_value($person, 'current_stage_name') ?>" disabled></div>
            <div class="field"><label class="label">最新面接日</label><input class="input" value="<?= person_value($person, 'latest_interviewed_at') ?>" disabled></div>
            <div class="field"><label class="label">legacy_source</label><input class="input" name="legacy_source" value="<?= person_value($person, 'legacy_source') ?>"></div>
            <div class="field"><label class="label">legacy_record_no</label><input class="input" name="legacy_record_no" value="<?= person_value($person, 'legacy_record_no') ?>"></div>
            <div class="field"><label class="label">person_code</label><input class="input" name="person_code" value="<?= person_value($person, 'person_code') ?>"></div>
          </div>
        </div>

        <div class="tabPanel" data-tab-panel="staff" style="margin-top:12px;">
          <div class="formGrid">
            <div class="field full"><label class="label">担当者メモ</label><textarea class="textarea" name="notes"><?= person_value($person, 'notes') ?></textarea></div>
          </div>
        </div>

        <div class="tabPanel" data-tab-panel="body" style="margin-top:12px;">
          <div class="formGrid">
            <div class="field"><label class="label">身長(cm)</label><input class="input" type="number" name="body_height_cm" value="<?= person_value($person, 'body_height_cm') ?>"></div>
            <div class="field"><label class="label">体重(kg)</label><input class="input" type="number" step="0.1" name="body_weight_kg" value="<?= person_value($person, 'body_weight_kg') ?>"></div>
            <div class="field"><label class="label">バスト</label><input class="input" type="number" name="bust_cm" value="<?= person_value($person, 'bust_cm') ?>"></div>
            <div class="field"><label class="label">ウエスト</label><input class="input" type="number" name="waist_cm" value="<?= person_value($person, 'waist_cm') ?>"></div>
            <div class="field"><label class="label">ヒップ</label><input class="input" type="number" name="hip_cm" value="<?= person_value($person, 'hip_cm') ?>"></div>
            <div class="field"><label class="label">カップ</label><input class="input" name="cup_size" value="<?= person_value($person, 'cup_size') ?>"></div>
            <div class="field"><label class="label">靴サイズ</label><input class="input" type="number" step="0.5" name="shoe_size" value="<?= person_value($person, 'shoe_size') ?>"></div>
            <div class="field"><label class="label">上服サイズ</label><input class="input" name="clothing_top_size" value="<?= person_value($person, 'clothing_top_size') ?>"></div>
            <div class="field"><label class="label">下服サイズ</label><input class="input" name="clothing_bottom_size" value="<?= person_value($person, 'clothing_bottom_size') ?>"></div>
          </div>
        </div>

        <div class="tabPanel" data-tab-panel="career" style="margin-top:12px;">
          <div class="formGrid">
            <div class="field"><label class="label">前職</label><input class="input" name="previous_job" value="<?= person_value($person, 'previous_job') ?>"></div>
            <div class="field"><label class="label">希望時給</label><input class="input" type="number" step="1" name="desired_hourly_wage" value="<?= person_value($person, 'desired_hourly_wage') ?>"></div>
            <div class="field"><label class="label">希望日給</label><input class="input" type="number" step="1" name="desired_daily_wage" value="<?= person_value($person, 'desired_daily_wage') ?>"></div>
          </div>
        </div>

        <button class="btnLine" style="margin-top:12px;cursor:pointer;font-weight:900;background:#111827;color:#fff;border-color:#111827;">基本情報を保存</button>
      </form>
    </div>
  </section>

  <?php if ($person): ?>
    <section class="card">
      <div class="sectionTitle">面接情報 / 面接評価</div>
      <form method="post" action="/wbss/public/applicants/actions/add_interview.php">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
        <div class="formGrid">
          <div class="field"><label class="label">面接日</label><input class="input" type="date" name="interview_date" value="<?= h(date('Y-m-d')) ?>" required></div>
          <div class="field"><label class="label">面接時刻</label><input class="input" type="time" name="interview_time"></div>
          <div class="field"><label class="label">面接店舗</label><select class="select" name="interview_store_id" required><option value="">選択してください</option><?php foreach ($stores as $store): ?><option value="<?= (int)$store['id'] ?>"><?= h((string)$store['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">面接担当者</label><select class="select" name="interviewer_user_id"><option value="">未設定</option><?php foreach ($interviewers as $user): ?><option value="<?= (int)$user['id'] ?>"><?= h((string)$user['display_name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">面接結果</label><select class="select" name="interview_result"><?php foreach (['pending' => '未判定', 'pass' => '合格', 'hold' => '保留', 'reject' => '不採用', 'joined' => '入店'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">応募経路</label><input class="input" name="application_route"></div>
          <div class="field"><label class="label">希望時給</label><input class="input" type="number" step="1" name="desired_hourly_wage"></div>
          <div class="field"><label class="label">希望日給</label><input class="input" type="number" step="1" name="desired_daily_wage"></div>
          <div class="field"><label class="label">体験入店</label><select class="select" name="trial_status"><?php foreach (['not_set' => '未設定', 'scheduled' => '予定', 'completed' => '実施', 'passed' => '合格', 'failed' => '見送り', 'cancelled' => '取消'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">体験入店日</label><input class="input" type="date" name="trial_date"></div>
          <div class="field"><label class="label">入店判定</label><select class="select" name="join_decision"><?php foreach (['undecided' => '未判定', 'approved' => '承認', 'rejected' => '不採用', 'deferred' => '保留'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">入店予定日</label><input class="input" type="date" name="join_date"></div>
          <div class="field"><label class="label">見た目</label><input class="input" type="number" name="appearance_score" min="0" max="100"></div>
          <div class="field"><label class="label">会話力</label><input class="input" type="number" name="communication_score" min="0" max="100"></div>
          <div class="field"><label class="label">意欲</label><input class="input" type="number" name="motivation_score" min="0" max="100"></div>
          <div class="field"><label class="label">清潔感</label><input class="input" type="number" name="cleanliness_score" min="0" max="100"></div>
          <div class="field"><label class="label">営業力</label><input class="input" type="number" name="sales_potential_score" min="0" max="100"></div>
          <div class="field"><label class="label">定着見込</label><input class="input" type="number" name="retention_potential_score" min="0" max="100"></div>
          <div class="field full"><label class="label">面接メモ</label><textarea class="textarea" name="interview_notes"></textarea></div>
          <div class="field full"><label class="label">評価コメント</label><textarea class="textarea" name="score_comment"></textarea></div>
          <div class="field full"><label class="label">体験入店フィードバック / 次アクション</label><textarea class="textarea" name="trial_feedback"></textarea></div>
        </div>
        <button class="btnLine" style="margin-top:12px;cursor:pointer;font-weight:900;">面接記録を追加</button>
      </form>
    </section>

    <section class="card">
      <div class="sectionTitle">在籍化 / 体験入店 / 店舗移動 / 退店</div>
      <div class="actionGrid">
        <form method="post" action="/wbss/public/applicants/actions/change_status.php" class="card" style="padding:12px;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
          <input type="hidden" name="action" value="trial">
          <div class="sectionTitle" style="font-size:15px;">体験入店</div>
          <div class="field"><label class="label">体験入店状態</label><select class="select" name="trial_status"><?php foreach (['scheduled' => '予定', 'completed' => '実施', 'passed' => '合格', 'failed' => '見送り', 'cancelled' => '取消'] as $key => $label): ?><option value="<?= h($key) ?>"><?= h($label) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">体験入店日</label><input class="input" type="date" name="trial_date" value="<?= h(date('Y-m-d')) ?>"></div>
          <div class="field"><label class="label">メモ</label><textarea class="textarea" name="trial_feedback"></textarea></div>
          <button class="btnLine" style="margin-top:10px;cursor:pointer;font-weight:900;">体験入店へ更新</button>
        </form>

        <form method="post" action="/wbss/public/applicants/actions/change_status.php" class="card" style="padding:12px;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
          <input type="hidden" name="action" value="active">
          <div class="sectionTitle" style="font-size:15px;">在籍化</div>
          <div class="field"><label class="label">入店店舗</label><select class="select" name="store_id" required><option value="">選択してください</option><?php foreach ($stores as $store): ?><option value="<?= (int)$store['id'] ?>"><?= h((string)$store['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">入店日</label><input class="input" type="date" name="effective_date" value="<?= h(date('Y-m-d')) ?>" required></div>
          <div class="field"><label class="label">源氏名</label><input class="input" name="genji_name"></div>
          <div class="field"><label class="label">メモ</label><textarea class="textarea" name="note"></textarea></div>
          <button class="btnLine" style="margin-top:10px;cursor:pointer;font-weight:900;">在籍化する</button>
        </form>

        <form method="post" action="/wbss/public/applicants/actions/change_status.php" class="card" style="padding:12px;">
          <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
          <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
          <input type="hidden" name="action" value="left">
          <div class="sectionTitle" style="font-size:15px;">退店</div>
          <div class="field"><label class="label">退店日</label><input class="input" type="date" name="effective_date" value="<?= h(date('Y-m-d')) ?>" required></div>
          <div class="field"><label class="label">退店理由</label><textarea class="textarea" name="leave_reason"></textarea></div>
          <button class="btnLine" style="margin-top:10px;cursor:pointer;font-weight:900;">退店に更新</button>
        </form>
      </div>

      <form method="post" action="/wbss/public/applicants/actions/move_store.php" class="card" style="padding:12px;margin-top:12px;">
        <input type="hidden" name="csrf_token" value="<?= h(csrf_token()) ?>">
        <input type="hidden" name="person_id" value="<?= (int)$person['id'] ?>">
        <div class="sectionTitle" style="font-size:15px;">店舗移動</div>
        <div class="formGrid">
          <div class="field"><label class="label">現在店舗</label><input class="input" value="<?= h((string)($person['current_store_name'] ?? '')) ?>" disabled></div>
          <div class="field"><label class="label">移動先店舗</label><select class="select" name="to_store_id" required><option value="">選択してください</option><?php foreach ($stores as $store): ?><option value="<?= (int)$store['id'] ?>"><?= h((string)$store['name']) ?></option><?php endforeach; ?></select></div>
          <div class="field"><label class="label">移動日</label><input class="input" type="date" name="move_date" value="<?= h(date('Y-m-d')) ?>" required></div>
          <div class="field"><label class="label">新店舗での源氏名</label><input class="input" name="genji_name"></div>
          <div class="field full"><label class="label">移動理由</label><textarea class="textarea" name="move_reason"></textarea></div>
        </div>
        <button class="btnLine" style="margin-top:10px;cursor:pointer;font-weight:900;">店舗移動を登録</button>
      </form>
    </section>

    <section class="card">
      <div class="sectionTitle">面接履歴一覧</div>
      <div class="tableWrap">
        <?php if ($interviews): ?>
          <table>
            <thead>
              <tr>
                <th>ID</th>
                <th>面接日</th>
                <th>店舗</th>
                <th>担当者</th>
                <th>結果</th>
                <th>体験入店</th>
                <th>総合点</th>
                <th>メモ</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($interviews as $row): ?>
                <tr>
                  <td>#<?= (int)$row['id'] ?></td>
                  <td><?= h((string)$row['interview_date']) ?></td>
                  <td><?= h((string)($row['interview_store_name'] ?? '')) ?></td>
                  <td><?= h((string)($row['interviewer_name'] ?? '')) ?></td>
                  <td><?= h(detail_result_label($row['interview_result'] ?? null)) ?></td>
                  <td><?= h(detail_trial_label($row['trial_status'] ?? null)) ?></td>
                  <td><?= h((string)($row['total_score'] ?? '—')) ?></td>
                  <td><?= nl2br(h(trim((string)($row['interview_notes'] ?? '')) !== '' ? (string)$row['interview_notes'] : (string)($row['score_comment'] ?? ''))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="mini">面接履歴はまだありません。</div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="sectionTitle">店舗移動履歴 / 在籍履歴</div>
      <div class="tableWrap">
        <?php if ($assignments): ?>
          <table>
            <thead>
              <tr>
                <th>状態</th>
                <th>店舗</th>
                <th>区分</th>
                <th>開始日</th>
                <th>終了日</th>
                <th>源氏名</th>
                <th>理由</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($assignments as $row): ?>
                <tr>
                  <td><?= (int)$row['is_current'] === 1 ? '<span class="badge ok">現在在籍</span>' : '<span class="badge off">履歴</span>' ?></td>
                  <td><?= h((string)$row['store_name']) ?></td>
                  <td><?= h(detail_transition_label($row['transition_type'] ?? null)) ?></td>
                  <td><?= h((string)$row['start_date']) ?></td>
                  <td><?= h((string)($row['end_date'] ?? '—')) ?></td>
                  <td><?= h((string)($row['genji_name'] ?? '—')) ?></td>
                  <td><?= h((string)($row['move_reason'] ?: $row['leave_reason'] ?: '—')) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="mini">在籍履歴はまだありません。</div>
        <?php endif; ?>
      </div>
    </section>

    <section class="card">
      <div class="sectionTitle">履歴ログ</div>
      <div class="tableWrap">
        <?php if ($logs): ?>
          <table>
            <thead>
              <tr>
                <th>日時</th>
                <th>操作</th>
                <th>状態遷移</th>
                <th>店舗</th>
                <th>担当者</th>
                <th>内容</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($logs as $row): ?>
                <tr>
                  <td><?= h((string)$row['created_at']) ?></td>
                  <td><?= h((string)$row['action_type']) ?></td>
                  <td><?= h(trim((string)($row['from_status'] ?? '') . ' → ' . (string)($row['to_status'] ?? ''))) ?></td>
                  <td><?= h(trim((string)($row['store_name'] ?? '') . ((string)($row['target_store_name'] ?? '') !== '' ? ' → ' . (string)$row['target_store_name'] : ''))) ?></td>
                  <td><?= h((string)($row['actor_name'] ?? '')) ?></td>
                  <td><?= nl2br(h((string)($row['action_note'] ?? ''))) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php else: ?>
          <div class="mini">履歴ログはまだありません。</div>
        <?php endif; ?>
      </div>
    </section>
  <?php endif; ?>
  </div>
</div>

<script>
document.querySelectorAll('[data-tabs]').forEach((tabsRoot) => {
  const buttons = tabsRoot.querySelectorAll('[data-tab-target]');
  const host = tabsRoot.parentElement;
  if (!host) return;
  const panels = host.querySelectorAll('[data-tab-panel]');
  buttons.forEach((button) => {
    button.addEventListener('click', () => {
      const target = button.getAttribute('data-tab-target');
      buttons.forEach((btn) => btn.classList.toggle('is-active', btn === button));
      panels.forEach((panel) => panel.classList.toggle('is-active', panel.getAttribute('data-tab-panel') === target));
    });
  });
});
</script>

<?php render_page_end(); ?>
