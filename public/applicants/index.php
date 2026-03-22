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
$stores = store_access_allowed_stores($pdo);
$interviewers = repo_applicants_fetch_interviewers($pdo);
$allowedStoreIds = array_map(static fn(array $row): int => (int)($row['id'] ?? 0), $stores);

$filters = [
  'q' => trim((string)($_GET['q'] ?? '')),
  'phone' => trim((string)($_GET['phone'] ?? '')),
  'interviewer_user_id' => (int)($_GET['interviewer_user_id'] ?? 0),
  'store_id' => (int)($_GET['store_id'] ?? 0),
  'employment_filter' => trim((string)($_GET['employment_filter'] ?? '')),
  'trial_only' => trim((string)($_GET['trial_only'] ?? '')),
  'latest_interview_result' => trim((string)($_GET['latest_interview_result'] ?? '')),
  'latest_interview_from' => trim((string)($_GET['latest_interview_from'] ?? '')),
  'latest_interview_to' => trim((string)($_GET['latest_interview_to'] ?? '')),
];

if ($filters['store_id'] > 0 && $allowedStoreIds !== [] && !in_array($filters['store_id'], $allowedStoreIds, true)) {
  $filters['store_id'] = 0;
}

$rows = repo_applicants_list($pdo, $filters, $allowedStoreIds);

function h(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function applicant_age_label(?string $birthDate, $ageCached): string {
  if ($ageCached !== null && $ageCached !== '') {
    return (string)((int)$ageCached) . '歳';
  }
  if (!$birthDate) {
    return '—';
  }
  try {
    $birth = new DateTimeImmutable((string)$birthDate, new DateTimeZone('Asia/Tokyo'));
    $today = new DateTimeImmutable('now', new DateTimeZone('Asia/Tokyo'));
    return (string)$today->diff($birth)->y . '歳';
  } catch (Throwable $e) {
    return '—';
  }
}

function applicant_status_badge(string $status, int $isCurrent): array {
  if ($isCurrent === 1 || $status === 'active') {
    return ['在籍中', 'ok'];
  }
  return match ($status) {
    'trial' => ['体験入店', 'trial'],
    'left' => ['退店', 'off'],
    'hold' => ['保留', 'hold'],
    default => ['面接中', 'base'],
  };
}

function applicant_result_label(?string $value): string {
  return match ((string)$value) {
    'pass' => '合格',
    'hold' => '保留',
    'reject' => '不採用',
    'joined' => '入店',
    'pending' => '未判定',
    default => '—',
  };
}

function applicant_trial_label(?string $value): string {
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

render_page_start('面接者一覧');
render_header('面接者一覧', [
  'back_href' => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '<a class="btn btn-primary" href="/wbss/public/applicants/detail.php">新規作成</a>',
]);
?>
<style>
  .page{max-width:1380px;margin:0 auto;padding:14px}
  .admin-wrap{display:grid;gap:14px}
  .card{
    background:linear-gradient(180deg,var(--cardA,#fff),var(--cardB,#f8fafc));
    border:1px solid var(--line,#e5e7eb);
    border-radius:22px;
    padding:18px;
    box-shadow:var(--shadow,0 8px 24px rgba(15,23,42,.06));
  }
  .heroRow{display:flex;justify-content:space-between;gap:16px;flex-wrap:wrap;align-items:flex-start}
  .heroTitle{font-size:18px;font-weight:1000;line-height:1.2}
  .heroLead,.muted{color:var(--muted,#667085);font-size:12px;line-height:1.6}
  .summary{display:grid;grid-template-columns:repeat(4,minmax(110px,1fr));gap:12px}
  .sumCard{
    background:rgba(255,255,255,.68);
    border:1px solid var(--line,#e5e7eb);
    border-radius:18px;
    padding:14px 16px;
    min-height:88px;
  }
  .sumCard b{display:block;font-size:18px;font-weight:1000;margin-top:6px}
  .toolbar{display:grid;gap:14px;margin-top:14px}
  .filters{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px}
  .field{display:grid;gap:7px}
  .label{font-size:12px;color:var(--muted,#667085);font-weight:900}
  .input,.select,.btnLine{
    min-height:52px;
    border-radius:18px;
    border:1px solid var(--line,#d7deea);
    padding:12px 16px;
    background:rgba(255,255,255,.9);
    color:inherit;
    box-shadow:inset 0 1px 0 rgba(255,255,255,.55);
  }
  .btnRow{display:flex;justify-content:flex-end;gap:10px;flex-wrap:wrap}
  .btnLine{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    text-decoration:none;
    cursor:pointer;
    font-weight:900;
    min-width:112px;
  }
  .tableWrap{overflow:auto}
  table{width:100%;border-collapse:separate;border-spacing:0;min-width:1180px}
  th,td{padding:13px 12px;border-bottom:1px solid var(--line,#e5e7eb);vertical-align:top;background:transparent}
  th{font-size:12px;color:var(--muted,#667085);text-align:left;font-weight:900}
  tbody tr:hover td{background:rgba(255,255,255,.45)}
  tbody tr:first-child td{border-top:1px solid var(--line,#e5e7eb)}
  .statusBadge,.photoBadge,.storeChip{
    display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900;border:1px solid transparent
  }
  .status-ok{background:rgba(34,197,94,.12);color:#166534;border-color:rgba(34,197,94,.22)}
  .status-trial{background:rgba(245,158,11,.14);color:#92400e;border-color:rgba(245,158,11,.22)}
  .status-off{background:rgba(239,68,68,.12);color:#991b1b;border-color:rgba(239,68,68,.20)}
  .status-hold{background:rgba(99,102,241,.12);color:#4338ca;border-color:rgba(99,102,241,.18)}
  .status-base{background:rgba(148,163,184,.12);color:#475569;border-color:rgba(148,163,184,.18)}
  .photoBadge{background:rgba(59,130,246,.10);color:#1d4ed8;border-color:rgba(59,130,246,.16)}
  .storeChip{background:rgba(37,99,235,.10);color:#1d4ed8;border-color:rgba(37,99,235,.14);font-size:13px}
  .personLink{text-decoration:none;font-weight:1000;color:inherit}
  .sub{font-size:12px;color:var(--muted,#667085);margin-top:4px}
  .empty{
    padding:56px 18px;
    text-align:center;
    border:1px dashed #d8dfeb;
    border-radius:20px;
    background:rgba(255,255,255,.55);
  }
  @media (max-width: 980px){
    .filters,.summary{grid-template-columns:repeat(2,minmax(0,1fr))}
  }
  @media (max-width: 640px){
    .filters,.summary{grid-template-columns:1fr}
    .card{padding:14px}
    .btnRow{justify-content:stretch}
    .btnLine{flex:1}
  }
</style>

<div class="page">
  <div class="admin-wrap">
  <section class="card">
    <div class="heroRow">
      <div>
        <div class="heroTitle">面接者一覧</div>
        <div class="heroLead">最優先表示: 在籍状況 / 現在店舗。面接・体験入店・在籍・移動・退店履歴は詳細で確認します。</div>
      </div>
      <div class="summary">
        <div class="sumCard"><span class="muted" style="font-size:12px;">表示件数</span><b><?= count($rows) ?></b></div>
        <div class="sumCard"><span class="muted" style="font-size:12px;">在籍中</span><b><?= count(array_filter($rows, static fn(array $row): bool => (int)($row['is_currently_employed'] ?? 0) === 1)) ?></b></div>
        <div class="sumCard"><span class="muted" style="font-size:12px;">体験入店中</span><b><?= count(array_filter($rows, static fn(array $row): bool => (string)($row['current_status'] ?? '') === 'trial')) ?></b></div>
        <div class="sumCard"><span class="muted" style="font-size:12px;">写真未登録</span><b><?= count(array_filter($rows, static fn(array $row): bool => empty($row['primary_photo_id']))) ?></b></div>
      </div>
    </div>

    <form method="get" class="toolbar">
      <div class="filters">
        <div class="field">
          <label class="label">氏名 / ふりがな</label>
          <input class="input" type="text" name="q" value="<?= h($filters['q']) ?>" placeholder="氏名・かな・電話番号">
        </div>
        <div class="field">
          <label class="label">電話番号</label>
          <input class="input" type="text" name="phone" value="<?= h($filters['phone']) ?>" placeholder="090...">
        </div>
        <div class="field">
          <label class="label">面接担当者</label>
          <select class="select" name="interviewer_user_id">
            <option value="0">指定なし</option>
            <?php foreach ($interviewers as $user): ?>
              <option value="<?= (int)$user['id'] ?>" <?= (int)$filters['interviewer_user_id'] === (int)$user['id'] ? 'selected' : '' ?>><?= h((string)$user['display_name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">店舗</label>
          <select class="select" name="store_id">
            <option value="0">全体</option>
            <?php foreach ($stores as $store): ?>
              <option value="<?= (int)$store['id'] ?>" <?= (int)$filters['store_id'] === (int)$store['id'] ? 'selected' : '' ?>><?= h((string)$store['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">在籍状態</label>
          <select class="select" name="employment_filter">
            <option value="">すべて</option>
            <option value="active" <?= $filters['employment_filter'] === 'active' ? 'selected' : '' ?>>在籍中</option>
            <option value="inactive" <?= $filters['employment_filter'] === 'inactive' ? 'selected' : '' ?>>非在籍</option>
            <option value="left" <?= $filters['employment_filter'] === 'left' ? 'selected' : '' ?>>退店済み</option>
          </select>
        </div>
        <div class="field">
          <label class="label">体験入店中</label>
          <select class="select" name="trial_only">
            <option value="">すべて</option>
            <option value="1" <?= $filters['trial_only'] === '1' ? 'selected' : '' ?>>体験入店中のみ</option>
          </select>
        </div>
        <div class="field">
          <label class="label">最新面接結果</label>
          <select class="select" name="latest_interview_result">
            <option value="">すべて</option>
            <?php foreach (['pending' => '未判定', 'pass' => '合格', 'hold' => '保留', 'reject' => '不採用', 'joined' => '入店'] as $key => $label): ?>
              <option value="<?= h($key) ?>" <?= $filters['latest_interview_result'] === $key ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="field">
          <label class="label">最新面接日 From</label>
          <input class="input" type="date" name="latest_interview_from" value="<?= h($filters['latest_interview_from']) ?>">
        </div>
        <div class="field">
          <label class="label">最新面接日 To</label>
          <input class="input" type="date" name="latest_interview_to" value="<?= h($filters['latest_interview_to']) ?>">
        </div>
      </div>
      <div class="btnRow">
        <button class="btnLine">絞り込む</button>
        <a class="btnLine" href="/wbss/public/applicants/index.php">クリア</a>
      </div>
    </form>
  </section>

  <section class="card">
    <div class="tableWrap">
      <?php if ($rows): ?>
        <table>
          <thead>
            <tr>
              <th>写真</th>
              <th>在籍状況</th>
              <th>現在店舗</th>
              <th>ID</th>
              <th>氏名</th>
              <th>年齢</th>
              <th>電話番号</th>
              <th>面接担当者</th>
              <th>最新面接</th>
              <th>結果</th>
              <th>体験入店</th>
              <th>源氏名</th>
              <th>更新日</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $row): ?>
              <?php [$statusText, $statusClass] = applicant_status_badge((string)($row['current_status'] ?? ''), (int)($row['is_currently_employed'] ?? 0)); ?>
              <tr>
                <td><span class="photoBadge"><?= !empty($row['primary_photo_id']) ? '写真あり' : '写真なし' ?></span></td>
                <td><span class="statusBadge status-<?= h($statusClass) ?>"><?= h($statusText) ?></span></td>
                <td><?php if (!empty($row['current_store_name'])): ?><span class="storeChip"><?= h((string)$row['current_store_name']) ?></span><?php else: ?><span class="muted">—</span><?php endif; ?></td>
                <td>#<?= (int)$row['id'] ?></td>
                <td>
                  <a class="personLink" href="/wbss/public/applicants/detail.php?id=<?= (int)$row['id'] ?>"><?= h(trim((string)$row['last_name'] . ' ' . (string)$row['first_name'])) ?></a>
                  <div class="sub"><?= h(trim((string)($row['last_name_kana'] ?? '') . ' ' . (string)($row['first_name_kana'] ?? ''))) ?></div>
                </td>
                <td><?= h(applicant_age_label($row['birth_date'] ?? null, $row['age_cached'] ?? null)) ?></td>
                <td><?= h((string)($row['phone'] ?? '—')) ?></td>
                <td><?= h((string)($row['interviewer_name'] ?? '—')) ?></td>
                <td><?= h((string)($row['latest_interviewed_at'] ?? '—')) ?></td>
                <td><?= h(applicant_result_label($row['latest_interview_result'] ?? null)) ?></td>
                <td><?= h(applicant_trial_label($row['trial_status'] ?? null)) ?></td>
                <td><?= h((string)($row['current_stage_name'] ?? '—')) ?></td>
                <td><?= h((string)($row['updated_at'] ?? '—')) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php else: ?>
        <div class="empty">
          <div style="font-size:18px;font-weight:900;">該当データがありません</div>
          <div class="muted" style="margin-top:6px;">条件を変えるか、新規作成から面接者を登録してください。</div>
        </div>
      <?php endif; ?>
    </div>
  </section>
  </div>
</div>

<?php render_page_end(); ?>
