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
  header('Location: /wbss/public/store_select.php');
  exit;
}

render_page_start('在庫ランチャー');
render_header('在庫ランチャー', [
  'back_href'  => '/wbss/public/dashboard.php',
  'back_label' => '← ダッシュボード',
]);

$isAdmin   = is_role('admin') || is_role('super_user');
$isManager = is_role('manager');
$isStaff   = is_role('staff') && !$isAdmin && !$isManager; // 純staff（manager/admin兼務でない想定）
$roleLabel = $isAdmin ? '管理者' : ($isManager ? '店長' : 'スタッフ');

?>
<div class="page">
  <div class="stock-shell">
    <section class="stock-hero card">
      <div class="stock-hero__main">
        <div class="stock-hero__eyebrow">在庫ランチャー</div>
        <span class="stock-hero__badge"><?= h($roleLabel) ?>メニュー</span>
        <h1>迷ったら「入出庫」から始めれば大丈夫です</h1>
        <p>操作の流れは <strong>スキャン → 数量 → 反映</strong> の3つだけ。棚卸は定期確認、マスタは管理者だけが触る想定です。</p>
      </div>
      <div class="stock-hero__aside">
        <div class="stock-hero__aside-label">今日の使い方</div>
        <div class="stock-hero__aside-title">まずはこの2画面だけ覚える</div>
        <div class="stock-hero__aside-text">日々の運用は「入出庫」、数合わせは「棚卸」でほぼ完結します。</div>
      </div>
    </section>

    <div class="stock-tabs" role="tablist" aria-label="在庫ランチャー切り替え">
      <button
        type="button"
        class="stock-tab is-active"
        data-tab-button
        data-target="stock-panel-main"
        role="tab"
        aria-selected="true"
        aria-controls="stock-panel-main"
        id="stock-tab-main"
      >今日の操作</button>
      <?php if (!$isStaff): ?>
      <button
        type="button"
        class="stock-tab"
        data-tab-button
        data-target="stock-panel-check"
        role="tab"
        aria-selected="false"
        aria-controls="stock-panel-check"
        id="stock-tab-check"
      >確認</button>
      <?php endif; ?>
      <?php if ($isAdmin): ?>
      <button
        type="button"
        class="stock-tab"
        data-tab-button
        data-target="stock-panel-master"
        role="tab"
        aria-selected="false"
        aria-controls="stock-panel-master"
        id="stock-tab-master"
      >マスタ管理</button>
      <?php endif; ?>
    </div>

    <section
      id="stock-panel-main"
      class="stock-section stock-panel card is-active"
      data-tab-panel
    >
      <div class="stock-section__head">
        <div>
          <h2>今日の操作</h2>
          <p>日常の在庫対応はここから開けば迷いません。</p>
        </div>
      </div>

      <div class="grid3">
        <a class="tile tile-primary" href="/wbss/public/stock/move.php">
          <div class="tile__top">
            <div class="tile__icon">📷</div>
            <div class="tile__hint">スキャン → 数量</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">入出庫</div>
            <div class="tile__desc">納品した / 使った を記録</div>
          </div>
        </a>

        <a class="tile tile-good" href="/wbss/public/stock/move.php?ptype=mixer">
          <div class="tile__top">
            <div class="tile__icon">🥤</div>
            <div class="tile__hint">片手で速い</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">割物モード</div>
            <div class="tile__desc">炭酸・氷・お茶など（現場用）</div>
          </div>
        </a>

        <a class="tile tile-warn" href="/wbss/public/stock/inventory.php">
          <div class="tile__top">
            <div class="tile__icon">📋</div>
            <div class="tile__hint">差分は自動</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">棚卸</div>
            <div class="tile__desc">いま実際にある数を入れる</div>
          </div>
        </a>

        <a class="tile" href="/wbss/public/stock/profit.php">
          <div class="tile__top">
            <div class="tile__icon">💴</div>
            <div class="tile__hint"><?= $isAdmin ? '価格更新OK' : '閲覧OK' ?></div>
          </div>
          <div class="tile__main">
            <div class="tile__title">価格と粗利</div>
            <div class="tile__desc">仕入れ・販売・粗利を確認</div>
          </div>
        </a>
      </div>
    </section>

    <?php if (!$isStaff): ?>
    <section
      id="stock-panel-check"
      class="stock-section stock-panel card"
      data-tab-panel
      hidden
    >
      <div class="stock-section__head">
        <div>
          <h2>確認</h2>
          <p>「在庫いくつ？」は在庫一覧、「誰がいつ動かした？」は入出庫履歴を見ればOKです。</p>
        </div>
      </div>

      <div class="grid3">
        <a class="tile" href="/wbss/public/stock/list.php">
          <div class="tile__top">
            <div class="tile__icon">📦</div>
            <div class="tile__hint">まずここ</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">在庫一覧</div>
            <div class="tile__desc">今の在庫を確認</div>
          </div>
        </a>

        <a class="tile" href="/wbss/public/stock/moves/history.php">
          <div class="tile__top">
            <div class="tile__icon">🔁</div>
            <div class="tile__hint">ミス検知</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">入出庫履歴</div>
            <div class="tile__desc">いつ・何を・何個 動かした</div>
          </div>
        </a>

        <?php if ($isAdmin): ?>
        <a class="tile" href="/wbss/public/stock/audit.php">
          <div class="tile__top">
            <div class="tile__icon">🛡</div>
            <div class="tile__hint">管理者向け</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">監査</div>
            <div class="tile__desc">棚卸差分・不整合チェック</div>
          </div>
        </a>
        <?php else: ?>
        <div class="tile tile-ghost" style="pointer-events:none;">
          <div class="tile__top">
            <div class="tile__icon">🛡</div>
            <div class="tile__hint">非表示</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">監査</div>
            <div class="tile__desc">管理者のみ</div>
          </div>
        </div>
        <?php endif; ?>
      </div>
    </section>
    <?php endif; ?>

    <?php if ($isAdmin): ?>
    <section
      id="stock-panel-master"
      class="stock-section stock-panel card"
      data-tab-panel
      hidden
    >
      <div class="stock-section__head">
        <div>
          <h2>マスタ管理</h2>
          <p>新しい商品や場所を増やすときだけ使う管理メニューです。</p>
        </div>
        <div class="stock-section__note">触る頻度は少なめでOK</div>
      </div>

      <div class="grid3">
        <a class="tile" href="/wbss/public/stock/products/index.php">
          <div class="tile__top">
            <div class="tile__icon">🧾</div>
            <div class="tile__hint">追加/修正</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">商品マスタ</div>
            <div class="tile__desc">画像・バーコード・種別</div>
          </div>
        </a>

        <a class="tile" href="/wbss/public/stock/categories/index.php">
          <div class="tile__top">
            <div class="tile__icon">🗂</div>
            <div class="tile__hint">整理</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">カテゴリ</div>
            <div class="tile__desc">分類の管理</div>
          </div>
        </a>

        <a class="tile" href="/wbss/public/stock/locations/index.php">
          <div class="tile__top">
            <div class="tile__icon">📍</div>
            <div class="tile__hint">追加/順番</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">場所マスタ</div>
            <div class="tile__desc">冷蔵庫/バックヤード等</div>
          </div>
        </a>
      </div>

      <div class="grid3 grid3-secondary">
        <a class="tile tile-danger" href="/wbss/public/stock/locations/init_grid.php"
           onclick="return confirm('初期化は管理者向けです。続行しますか？');">
          <div class="tile__top">
            <div class="tile__icon">🧩</div>
            <div class="tile__hint">慎重に</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">在庫初期化（場所×商品）</div>
            <div class="tile__desc">場所/商品を増やした後に</div>
          </div>
        </a>

        <div class="tile tile-ghost" style="pointer-events:none;">
          <div class="tile__top">
            <div class="tile__icon">✅</div>
            <div class="tile__hint">迷い防止</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">使い方のコツ</div>
            <div class="tile__desc">わからなければ「入出庫」だけ使えばOK</div>
          </div>
        </div>

        <div class="tile tile-ghost" style="pointer-events:none;">
          <div class="tile__top">
            <div class="tile__icon">🎯</div>
            <div class="tile__hint">事故防止</div>
          </div>
          <div class="tile__main">
            <div class="tile__title">現場ルール</div>
            <div class="tile__desc">音が鳴ったらOK。鳴らなければ止まって確認</div>
          </div>
        </div>
      </div>
    </section>
    <?php endif; ?>
  </div>
</div>

<style>
  .stock-shell{
    display:grid;
    gap:14px;
  }

  .stock-tabs{
    display:flex;
    gap:8px;
    flex-wrap:wrap;
    padding:4px;
    border:1px solid var(--line);
    border-radius:18px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.04), rgba(255,255,255,.02)),
      var(--cardA);
    box-shadow:0 10px 28px rgba(0,0,0,.10);
  }

  .stock-tab{
    appearance:none;
    border:1px solid transparent;
    background:transparent;
    color:var(--muted);
    border-radius:14px;
    padding:10px 14px;
    font-size:13px;
    font-weight:900;
    line-height:1.1;
    cursor:pointer;
    transition:background .16s ease, color .16s ease, border-color .16s ease, transform .16s ease;
  }

  .stock-tab:hover{
    color:var(--txt);
    border-color:rgba(255,255,255,.10);
    background:rgba(255,255,255,.04);
  }

  .stock-tab.is-active{
    color:#0f172a;
    border-color:rgba(250,204,21,.35);
    background:linear-gradient(135deg, #facc15, #fb923c);
  }

  .stock-hero{
    display:grid;
    grid-template-columns:minmax(0, 1.7fr) minmax(260px, 0.95fr);
    gap:12px;
    align-items:stretch;
    border-radius:22px;
  }

  .stock-hero__main,
  .stock-hero__aside{
    border:1px solid var(--line);
    border-radius:20px;
    background:
      linear-gradient(180deg, rgba(255,255,255,.05), rgba(255,255,255,.02)),
      var(--cardA);
    box-shadow:0 16px 40px rgba(0,0,0,.12);
  }

  .stock-hero__main{
    padding:20px 22px;
  }

  .stock-hero__eyebrow{
    font-size:14px;
    font-weight:900;
    color:var(--muted);
    margin-bottom:8px;
  }

  .stock-hero__badge{
    display:inline-flex;
    align-items:center;
    padding:6px 10px;
    border-radius:999px;
    font-size:12px;
    font-weight:800;
    color:#0f172a;
    background:linear-gradient(135deg, #facc15, #fb923c);
  }

  .stock-hero__main h1{
    margin:10px 0 8px;
    font-size:25px;
    line-height:1.25;
  }

  .stock-hero__main p{
    margin:0;
    color:var(--muted);
    font-size:13px;
    line-height:1.6;
  }

  .stock-hero__aside{
    padding:18px 20px;
    display:flex;
    flex-direction:column;
    justify-content:center;
    gap:8px;
  }

  .stock-hero__aside-label{
    font-size:12px;
    font-weight:800;
    color:var(--muted);
  }

  .stock-hero__aside-title{
    font-size:18px;
    font-weight:900;
    line-height:1.35;
  }

  .stock-hero__aside-text{
    font-size:12px;
    line-height:1.55;
    color:var(--muted);
  }

  .stock-section{
    border-radius:22px;
    padding:16px 18px 18px;
  }

  .stock-panel{
    animation:stockPanelIn .18s ease;
  }

  .stock-section__head{
    display:flex;
    justify-content:space-between;
    gap:10px;
    align-items:flex-end;
    flex-wrap:wrap;
    margin-bottom:10px;
  }

  .stock-section__head h2{
    margin:0;
    font-size:18px;
  }

  .stock-section__head p{
    margin:4px 0 0;
    font-size:12px;
    color:var(--muted);
    line-height:1.55;
  }

  .stock-section__note{
    font-size:12px;
    color:var(--muted);
  }

  .grid3{
    display:grid;
    grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
    gap:14px;
  }

  .grid3-secondary{
    margin-top:12px;
  }

  .tile{
    display:flex;
    flex-direction:column;
    gap:10px;
    padding:14px;
    border-radius:16px;
    border:1px solid rgba(255,255,255,.10);
    background:linear-gradient(180deg, rgba(255,255,255,.06), rgba(255,255,255,.02));
    box-shadow:none;
    min-height: 138px;
    text-decoration:none;
    color:inherit;
    transition:transform .16s ease, box-shadow .16s ease, border-color .16s ease;
  }
  .tile:hover{
    transform:translateY(-3px);
    box-shadow:0 16px 30px rgba(0,0,0,.18);
    border-color:rgba(255,255,255,.22);
    filter:none;
  }
  .tile:active{ transform: translateY(-1px); }

  .tile__top{
    display:flex;
    align-items:flex-start;
    justify-content:space-between;
    gap:8px;
    width:100%;
  }

  .tile__icon{
    width:54px; height:54px;
    border-radius:16px;
    display:flex; align-items:center; justify-content:center;
    font-size:26px;
    border:1px solid rgba(255,255,255,.10);
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
    border:1px solid rgba(255,255,255,.10);
    border-radius:999px;
    padding:6px 10px;
    background: color-mix(in srgb, var(--txt) 8%, transparent);
    white-space:nowrap;
  }

  /* 色付き（テーマ依存で映える） */
  .tile-primary{
    border-color:rgba(250,204,21,.45);
    background:linear-gradient(180deg, rgba(250,204,21,.14), rgba(255,255,255,.03));
  }
  .tile-good{
    border-color:rgba(74,222,128,.38);
    background:linear-gradient(180deg, rgba(74,222,128,.12), rgba(255,255,255,.03));
  }
  .tile-warn{
    border-color:rgba(245,158,11,.45);
    background:linear-gradient(180deg, rgba(245,158,11,.14), rgba(255,255,255,.03));
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

  @keyframes stockPanelIn{
    from{
      opacity:0;
      transform:translateY(4px);
    }
    to{
      opacity:1;
      transform:translateY(0);
    }
  }

  /* lightテーマで見やすく */
  body[data-theme="light"] .stock-tabs{
    background:#fff;
    box-shadow:0 10px 20px rgba(15,23,42,.06);
  }

  body[data-theme="light"] .stock-hero__main,
  body[data-theme="light"] .stock-hero__aside{
    background:#fff;
    box-shadow:0 16px 30px rgba(15,23,42,.08);
  }

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

  @media (max-width: 820px){
    .stock-hero{
      grid-template-columns:1fr;
    }
  }

  @media (max-width: 640px){
    .stock-section,
    .stock-hero{
      border-radius:18px;
    }

    .stock-hero__main,
    .stock-hero__aside{
      border-radius:18px;
    }

    .stock-hero__main{
      padding:20px;
    }

    .stock-hero__main h1{
      font-size:22px;
    }

    .stock-tabs{
      display:grid;
      grid-template-columns:repeat(2, minmax(0, 1fr));
    }

    .stock-tab{
      width:100%;
    }

    .grid3{
      grid-template-columns:1fr;
    }
  }
</style>

<script>
document.addEventListener('click', function(e){
  const tab = e.target.closest('[data-tab-button]');
  if (!tab) return;

  const targetId = tab.getAttribute('data-target') || '';
  if (!targetId) return;

  document.querySelectorAll('.stock-tab[data-tab-button]').forEach(function(el){
    const isActive = el === tab;
    el.classList.toggle('is-active', isActive);
    el.setAttribute('aria-selected', isActive ? 'true' : 'false');
  });

  document.querySelectorAll('.stock-panel[data-tab-panel]').forEach(function(panel){
    const isActive = panel.id === targetId;
    panel.classList.toggle('is-active', isActive);
    panel.hidden = !isActive;
  });
});
</script>

<?php render_page_end(); ?>
