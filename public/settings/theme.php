<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();

$pdo = db();
$msg = '';
$err = '';

$themes = [
  'dark' => 'ダーク（標準）',
  'light' => 'ライト（明るい）',
  'high_contrast' => '高コントラスト（間違えない）',
  'soft' => 'ソフト（疲れにくい）',
  'store_color' => '店舗カラー（アクセント強め）',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $t = (string)($_POST['ui_theme'] ?? 'dark');
  if (!isset($themes[$t])) {
    $err = '不正なテーマです';
  } else {
    $uid = (int)($_SESSION['user_id'] ?? 0);
    try {
      $st = $pdo->prepare("UPDATE users SET ui_theme = ? WHERE id = ? LIMIT 1");
      $st->execute([$t, $uid]);
      $_SESSION['ui_theme'] = $t; // 即反映
      $msg = 'テーマを変更しました';
    } catch (Throwable $e) {
      $err = $e->getMessage();
    }
  }
}

$current = (string)($_SESSION['ui_theme'] ?? current_ui_theme());

render_page_start('表示テーマ');
render_header('表示テーマ', [
  'back_href' => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
  'right_html' => '',
]);
?>
<div class="page">

  <?php if ($msg): ?><div class="card" style="border-color:color-mix(in srgb,var(--ok) 40%, transparent);"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err): ?><div class="card" style="border-color:color-mix(in srgb,var(--ng) 50%, transparent);"><?= h($err) ?></div><?php endif; ?>

  <div class="card">
    <div style="font-weight:1000; font-size:16px;">テーマ選択（ユーザー別）</div>
    <div class="muted" style="margin-top:6px;">iPad/スマホで「押し間違いを減らす」目的で見た目を変えられます。</div>

    <form method="post" style="margin-top:12px; display:flex; gap:10px; flex-wrap:wrap; align-items:end;">
      <div style="min-width:280px;">
        <label class="muted">テーマ</label><br>
        <select class="btn" name="ui_theme" style="width:100%;">
          <?php foreach ($themes as $k => $label): ?>
            <option value="<?= h($k) ?>" <?= $current === $k ? 'selected' : '' ?>><?= h($label) ?></option>
          <?php endforeach; ?>
        </select>
      </div>

      <div>
        <label class="muted">反映</label><br>
        <button class="btn btn-primary" type="submit">保存</button>
      </div>
    </form>

    <div class="muted" style="margin-top:10px;">
      ※ 保存後すぐ全ページに反映（セッションにも保持）  
      ※ high_contrast は「間違えない優先」の現場端末向け
    </div>
  </div>

</div>
<?php render_page_end(); ?>