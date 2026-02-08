<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/auth.php';
require_once __DIR__ . '/../app/db.php';
require_once __DIR__ . '/../app/layout.php';

require_login();

$pdo = db();

/* =========================
   role 判定
========================= */
function has_role(string $role): bool {
  return isset($_SESSION['roles']) && in_array($role, $_SESSION['roles'], true);
}

$isSuper   = has_role('super_user');
$isAdmin   = has_role('admin');
$isManager = has_role('manager');
$isCast    = has_role('cast');

/* =========================
   振り分け（castは専用へ）
========================= */
if ($isCast && !$isAdmin && !$isManager) {
  header('Location: /seika-app/public/dashboard_cast.php');
  exit;
}

/* =========================
   admin / manager 用
   LINE未連携キャスト数
========================= */
$lineUnlinkedCount = 0;

if ($isAdmin || $isSuper || $isManager) {
  $st = $pdo->query("
    SELECT COUNT(DISTINCT u.id)
    FROM users u
    JOIN user_roles ur ON ur.user_id = u.id
    JOIN roles r ON r.id = ur.role_id AND r.code = 'cast'
    LEFT JOIN user_identities ui
      ON ui.user_id = u.id
      AND ui.provider = 'line'
      AND ui.is_active = 1
    WHERE ui.id IS NULL
      AND u.is_active = 1
  ");
  $lineUnlinkedCount = (int)$st->fetchColumn();
}

/* =========================
   店長：今日の注意事項（仮）
========================= */
$todayNotes = [];

if ($isManager) {
  $todayNotes = [
    '新人キャスト 2名 初出勤',
    'VIP予約あり（21:30〜）',
    '在庫：角ボトル残り少'
  ];
}

/* =========================
   画面
========================= */
render_page_start('ダッシュボード');
render_header('ダッシュボード');
?>

<div class="page">
<div class="admin-wrap">

  <div style="font-weight:1000; font-size:20px;">
    🏠 ダッシュボード
  </div>
  <div class="muted" style="margin-top:4px;">
    <?= $isSuper ? '全店舗管理者'
      : ($isAdmin ? '管理者'
      : ($isManager ? '店長'
      : 'キャスト')) ?> としてログイン中
  </div>

  <!-- =========================
       admin / super_user
  ========================= -->
  <?php if ($isAdmin || $isSuper): ?>
  <div class="card-grid" style="margin-top:16px;">

    <a class="card" href="/seika-app/public/store_casts.php">
      <div class="icon">➕</div>
      <b>キャスト追加</b>
      <div class="muted">招待リンク / QR</div>
    </a>

    <a class="card" href="/seika-app/public/store_casts.php">
      <div class="icon">👥</div>
      <b>店別キャスト管理</b>
      <div class="muted">所属 / 異動</div>
    </a>

    <a class="card" href="/seika-app/public/admin_users.php">
      <div class="icon">👤</div>
      <b>ユーザー管理</b>
      <div class="muted">権限・連携</div>
    </a>

    <a class="card" href="/seika-app/public/store_casts.php#invites">
      <div class="icon">🔗</div>
      <b>招待リンク</b>
      <div class="muted">履歴・失効</div>
    </a>

    <a class="card" href="/seika-app/public/store_casts.php#line-alert">
      <div class="icon">⚠</div>
      <b>
        LINE未連携
        <?php if ($lineUnlinkedCount > 0): ?>
          <span class="badge-red"><?= $lineUnlinkedCount ?></span>
        <?php endif; ?>
      </b>
      <div class="muted">要対応キャスト</div>
    </a>

    <!-- ✅ 追加：予定 -->
    <a class="card" href="/seika-app/public/manager_today_schedule.php">
      <div class="icon">📋</div>
      <b>本日の予定</b>
      <div class="muted">今日＋今週一覧</div>
    </a>

    <a class="card" href="/seika-app/public/cast_week_plans.php">
      <div class="icon">🗓</div>
      <b>週予定入力</b>
      <div class="muted">キャスト×7日</div>
    </a>

    <!-- ✅ 追加：在庫 -->
    <a class="card" href="/seika-app/public/stock/index.php">
      <div class="icon">📦</div>
      <b>酒在庫管理</b>
      <div class="muted">商品 / 移動 / 棚卸</div>
    </a>

  </div>
  <?php endif; ?>

  <!-- =========================
       店長（manager）
       ※ admin は上で出すので、ここは「店長専用」
  ========================= -->
  <?php if ($isManager && !$isAdmin): ?>
  <div class="card-grid" style="margin-top:16px;">

    <div class="card card-warn">
      <div class="icon">📌</div>
      <b>今日の注意事項</b>
      <ul class="note-list">
        <?php foreach ($todayNotes as $n): ?>
          <li><?= htmlspecialchars($n, ENT_QUOTES, 'UTF-8') ?></li>
        <?php endforeach; ?>
      </ul>
    </div>

    <!-- ✅ 予定 -->
    <a class="card" href="/seika-app/public/manager_today_schedule.php">
      <div class="icon">📋</div>
      <b>本日の予定</b>
      <div class="muted">今日＋今週一覧</div>
    </a>

    <a class="card" href="/seika-app/public/cast_week_plans.php">
      <div class="icon">🗓</div>
      <b>週予定入力</b>
      <div class="muted">キャスト×7日</div>
    </a>

    <!-- ✅ キャスト -->
    <a class="card" href="/seika-app/public/store_casts.php">
      <div class="icon">👥</div>
      <b>所属キャスト</b>
      <div class="muted">LINE連携確認</div>
    </a>

    <a class="card" href="/seika-app/public/store_casts.php#invites">
      <div class="icon">➕</div>
      <b>新人招待</b>
      <div class="muted">この店のみ</div>
    </a>

    <!-- ✅ 在庫 -->
    <a class="card" href="/seika-app/public/stock/list.php">
      <div class="icon">📦</div>
      <b>在庫</b>
      <div class="muted">商品 / 移動 / 棚卸</div>
    </a>

  </div>
  <?php endif; ?>

  <!-- =========================
       （保険）キャスト表示
       ※ 実際は冒頭のリダイレクトで入らない
  ========================= -->
  <?php if ($isCast && !$isAdmin && !$isManager): ?>
  <div class="card-grid cast-grid" style="margin-top:16px;">

    <a class="card big" href="/seika-app/public/cast_today.php">
      <div class="icon">⏰</div>
      <b>本日の出勤</b>
    </a>

    <a class="card big" href="/seika-app/public/cast_week.php">
      <div class="icon">🗓</div>
      <b>出勤予定</b>
    </a>

    <a class="card big" href="/seika-app/public/profile.php">
      <div class="icon">👤</div>
      <b>プロフィール</b>
    </a>

    <a class="card big" href="/seika-app/public/help.php">
      <div class="icon">❓</div>
      <b>ヘルプ</b>
    </a>

  </div>
  <?php endif; ?>

</div>
</div>

<style>
.card-grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(220px,1fr));
  gap:12px;
}
.card{
  padding:14px;
  border:1px solid var(--line);
  border-radius:14px;
  background:var(--cardA);
  text-decoration:none;
  color:inherit;
}
.card:hover{
  transform:translateY(-2px);
  box-shadow:0 10px 24px rgba(0,0,0,.18);
}
.card .icon{
  font-size:22px;
  margin-bottom:6px;
}
.card-warn{
  border-color:#f59e0b;
}
.note-list{
  margin:8px 0 0;
  padding-left:18px;
  font-size:13px;
}
.badge-red{
  background:#ef4444;
  color:#fff;
  font-size:11px;
  padding:2px 6px;
  border-radius:999px;
  margin-left:6px;
}
.cast-grid{
  grid-template-columns:1fr 1fr;
}
.cast-grid .card.big{
  padding:22px;
  text-align:center;
}
@media (max-width:420px){
  .cast-grid{
    grid-template-columns:1fr;
  }
}
</style>

<?php render_page_end(); ?>