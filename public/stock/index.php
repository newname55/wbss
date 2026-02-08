<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
if (!is_role('super_user') && !is_role('admin') && !is_role('manager') && !is_role('staff')) {
  http_response_code(403);
  exit('Forbidden');
}

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /seika-app/public/store_select.php');
  exit;
}

render_page_start('在庫ランチャー');
render_header('在庫ランチャー', [
  'back_href'  => '/seika-app/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);

$isAdmin   = is_role('admin') || is_role('super_user');
$isManager = is_role('manager');
$isStaff   = is_role('staff') && !$isAdmin && !$isManager; // 純staff（manager/admin兼務でない想定）

?>
<div class="page">

  <!-- ============ 最上段：今日やること（全員） ============ -->
  <div class="card">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <div style="font-weight:1000; font-size:18px;">📦 在庫（今日の操作）</div>
        <div class="muted" style="margin-top:4px; line-height:1.6;">
          迷ったら <b>①スキャン → ②数量 → ③反映</b>。<br>
          「棚卸」は月に数回、「マスタ」は管理者だけ。
        </div>
      </div>
      <div class="muted" style="text-align:right;">
        まずはこの3つだけ覚えればOK
      </div>
    </div>

    <div class="grid3" style="margin-top:14px;">
      <a class="tile tile-primary" href="/seika-app/public/stock/move.php">
        <div class="tile__icon">📷</div>
        <div class="tile__main">
          <div class="tile__title">入出庫</div>
          <div class="tile__desc">納品した / 使った を記録</div>
        </div>
        <div class="tile__hint">スキャン → 数量</div>
      </a>

      <a class="tile tile-good" href="/seika-app/public/stock/move.php?ptype=mixer">
        <div class="tile__icon">🥤</div>
        <div class="tile__main">
          <div class="tile__title">割物モード</div>
          <div class="tile__desc">炭酸・氷・お茶など（現場用）</div>
        </div>
        <div class="tile__hint">片手で速い</div>
      </a>

      <a class="tile tile-warn" href="/seika-app/public/stock/inventory.php">
        <div class="tile__icon">📋</div>
        <div class="tile__main">
          <div class="tile__title">棚卸</div>
          <div class="tile__desc">いま実際にある数を入れる</div>
        </div>
        <div class="tile__hint">差分は自動</div>
      </a>
    </div>
  </div>

  <!-- ============ 2段目：確認（staffには出さない） ============ -->
  <?php if (!$isStaff): ?>
  <div class="card" style="margin-top:16px;">
    <div style="font-weight:1000; font-size:16px;">🔍 確認（困ったらここ）</div>
    <div class="muted" style="margin-top:6px;">
      「在庫いくつ？」→ 在庫一覧。　「誰がいつ動かした？」→ 入出庫履歴。
    </div>

    <div class="grid3" style="margin-top:12px;">
      <a class="tile" href="/seika-app/public/stock/list.php">
        <div class="tile__icon">📦</div>
        <div class="tile__main">
          <div class="tile__title">在庫一覧</div>
          <div class="tile__desc">今の在庫を確認</div>
        </div>
        <div class="tile__hint">まずここ</div>
      </a>

      <a class="tile" href="/seika-app/public/stock/moves/history.php">
        <div class="tile__icon">🔁</div>
        <div class="tile__main">
          <div class="tile__title">入出庫履歴</div>
          <div class="tile__desc">いつ・何を・何個 動かした</div>
        </div>
        <div class="tile__hint">ミス検知</div>
      </a>

      <?php if ($isAdmin): ?>
      <a class="tile" href="/seika-app/public/stock/audit.php">
        <div class="tile__icon">🛡</div>
        <div class="tile__main">
          <div class="tile__title">監査</div>
          <div class="tile__desc">棚卸差分・不整合チェック</div>
        </div>
        <div class="tile__hint">管理者向け</div>
      </a>
      <?php else: ?>
      <div class="tile tile-ghost" style="pointer-events:none;">
        <div class="tile__icon">🛡</div>
        <div class="tile__main">
          <div class="tile__title">監査</div>
          <div class="tile__desc">管理者のみ</div>
        </div>
        <div class="tile__hint">非表示</div>
      </div>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <!-- ============ 3段目：マスタ（管理者だけ） ============ -->
  <?php if ($isAdmin): ?>
  <div class="card" style="margin-top:16px;">
    <div style="display:flex; justify-content:space-between; gap:10px; flex-wrap:wrap; align-items:flex-end;">
      <div>
        <div style="font-weight:1000; font-size:16px;">⚙️ マスタ（管理者）</div>
        <div class="muted" style="margin-top:6px;">
          新しい商品・場所を増やす時だけ使う。
        </div>
      </div>
      <div class="muted">※触るのは少なめでOK</div>
    </div>

    <div class="grid3" style="margin-top:12px;">
      <a class="tile" href="/seika-app/public/stock/products/index.php">
        <div class="tile__icon">🧾</div>
        <div class="tile__main">
          <div class="tile__title">商品マスタ</div>
          <div class="tile__desc">画像・バーコード・種別</div>
        </div>
        <div class="tile__hint">追加/修正</div>
      </a>

      <a class="tile" href="/seika-app/public/stock/categories/index.php">
        <div class="tile__icon">🗂</div>
        <div class="tile__main">
          <div class="tile__title">カテゴリ</div>
          <div class="tile__desc">分類の管理</div>
        </div>
        <div class="tile__hint">整理</div>
      </a>

      <a class="tile" href="/seika-app/public/stock/locations/index.php">
        <div class="tile__icon">📍</div>
        <div class="tile__main">
          <div class="tile__title">場所マスタ</div>
          <div class="tile__desc">冷蔵庫/バックヤード等</div>
        </div>
        <div class="tile__hint">追加/順番</div>
      </a>
    </div>

    <div class="grid3" style="margin-top:12px;">
      <a class="tile tile-danger" href="/seika-app/public/stock/locations/init_grid.php"
         onclick="return confirm('初期化は管理者向けです。続行しますか？');">
        <div class="tile__icon">🧩</div>
        <div class="tile__main">
          <div class="tile__title">在庫初期化（場所×商品）</div>
          <div class="tile__desc">場所/商品を増やした後に</div>
        </div>
        <div class="tile__hint">慎重に</div>
      </a>

      <div class="tile tile-ghost" style="pointer-events:none;">
        <div class="tile__icon">✅</div>
        <div class="tile__main">
          <div class="tile__title">使い方のコツ</div>
          <div class="tile__desc">わからなければ「入出庫」だけ使えばOK</div>
        </div>
        <div class="tile__hint">迷い防止</div>
      </div>

      <div class="tile tile-ghost" style="pointer-events:none;">
        <div class="tile__icon">🎯</div>
        <div class="tile__main">
          <div class="tile__title">現場ルール</div>
          <div class="tile__desc">音が鳴ったらOK。鳴らなければ止まって確認</div>
        </div>
        <div class="tile__hint">事故防止</div>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div>

<style>
  .grid3{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap:14px;
  }

  .tile{
    display:flex;
    align-items:center;
    gap:12px;
    padding:14px;
    border-radius:18px;
    border:1px solid var(--line);
    background: linear-gradient(180deg, var(--cardA), var(--cardB));
    box-shadow: var(--shadow);
    min-height: 84px;
  }
  .tile:hover{ filter: brightness(1.06); }
  .tile:active{ transform: translateY(1px); }

  .tile__icon{
    width:54px; height:54px;
    border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    font-size:26px;
    border:1px solid var(--line);
    background: rgba(255,255,255,.04);
    flex: 0 0 auto;
  }
  .tile__main{ flex:1; min-width: 0; }
  .tile__title{ font-weight:1000; font-size:16px; letter-spacing:.2px; }
  .tile__desc{ margin-top:4px; color:var(--muted); font-size:12px; line-height:1.4; }
  .tile__hint{
    flex:0 0 auto;
    font-size:12px;
    font-weight:900;
    color: var(--muted);
    border:1px solid var(--line);
    border-radius:999px;
    padding:6px 10px;
    background: rgba(255,255,255,.03);
  }

  /* 色付き（テーマ依存で映える） */
  .tile-primary{
    border-color: color-mix(in srgb, var(--accent) 35%, var(--line));
    background: linear-gradient(180deg,
      color-mix(in srgb, var(--accent) 18%, var(--cardA)),
      var(--cardB)
    );
  }
  .tile-good{
    border-color: color-mix(in srgb, var(--ok) 35%, var(--line));
    background: linear-gradient(180deg,
      color-mix(in srgb, var(--ok) 14%, var(--cardA)),
      var(--cardB)
    );
  }
  .tile-warn{
    border-color: color-mix(in srgb, var(--warn) 35%, var(--line));
    background: linear-gradient(180deg,
      color-mix(in srgb, var(--warn) 14%, var(--cardA)),
      var(--cardB)
    );
  }
  .tile-danger{
    border-color: rgba(251,113,133,.45);
    background: linear-gradient(180deg,
      rgba(251,113,133,.14),
      var(--cardB)
    );
  }
  .tile-ghost{
    opacity:.85;
    background: rgba(255,255,255,.02);
  }

  /* lightテーマで見やすく */
  body[data-theme="light"] .tile{
    background:#fff;
    border:1px solid var(--line);
    box-shadow: 0 10px 18px rgba(0,0,0,.06);
  }
  body[data-theme="light"] .tile__icon{
    background: var(--softBlue);
    border: 1px solid var(--line);
  }
  body[data-theme="light"] .tile__hint{
    background:#fff;
    box-shadow: 0 10px 18px rgba(0,0,0,.05);
    border: 1px solid var(--line);
  }
</style>

<?php render_page_end(); ?>