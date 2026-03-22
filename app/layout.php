<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/store.php';

if (!function_exists('h')) {
  function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

/** テーマ取得（session優先 → DB → default） */
function current_ui_theme(): string {
  // 古いテーマキーのマッピング（マイグレーション用）
  $legacy_map = [
    'soft' => 'dark',            // 旧: Soft/Night -> dark に統合
    'high_contrast' => 'staff',  // 旧: High Contrast -> staff
    'store_color' => 'cast',     // 旧: Store Color -> cast
  ];

  $t = (string)($_SESSION['ui_theme'] ?? '');

  if ($t !== '') {
    if (array_key_exists($t, $legacy_map)) {
      $t = $legacy_map[$t];
      $_SESSION['ui_theme'] = $t;
    }
    return $t;
  }

  $uid = (int)($_SESSION['user_id'] ?? 0);
  if ($uid <= 0) return 'dark';

  try {
    $pdo = db();
    $st = $pdo->prepare("SELECT ui_theme FROM users WHERE id = ? LIMIT 1");
    $st->execute([$uid]);
    $r = $st->fetch();
    $t = $r ? (string)($r['ui_theme'] ?? '') : '';
    if ($t === '') $t = 'dark';
    if (array_key_exists($t, $legacy_map)) {
      $t = $legacy_map[$t];
    }
    $_SESSION['ui_theme'] = $t; // キャッシュ
    return $t;
  } catch (Throwable $e) {
    return 'dark';
  }
}

/** store_id -> 店舗名（失敗しても空文字で返す） */
function layout_store_name(?int $store_id): string {
  if ($store_id === null) return '';
  try {
    $pdo = db();
    $st = $pdo->prepare("SELECT name FROM stores WHERE id = ? LIMIT 1");
    $st->execute([$store_id]);
    $r = $st->fetch();
    return $r ? (string)$r['name'] : '';
  } catch (Throwable $e) {
    return '';
  }
}
function render_user_menu_html(): string {
  $options = [





    'light' => 'Light（現場）',
    'dark'  => 'Dark（標準）',
    'cast'  => 'ピンク',
    'staff' => 'ブルー',
  ];
  $cur = current_ui_theme();

  $isAdmin = function_exists('is_role') && (is_role('super_user') || is_role('admin'));

  $login_id = (string)($_SESSION['login_id'] ?? '');
  $display  = (string)($_SESSION['display_name'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');
  $who = $display !== '' ? $display : $login_id;

  $initial = 'U';
  if ($who !== '') {
    $initial = mb_substr($who, 0, 1);
  }

  $storeId = function_exists('current_store_id') ? (int)(current_store_id() ?? 0) : (int)($_SESSION['store_id'] ?? 0);
  $csrf = '';
  if (function_exists('csrf_token')) {
    $csrf = (string)csrf_token();
  } else {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(16));
    $csrf = (string)$_SESSION['csrf_token'];
  }

  ob_start();
  ?>
  <div class="userMenu" id="userMenu">
    <button type="button" class="userMenu__btn" id="userMenuBtn" aria-haspopup="menu" aria-expanded="false">
      <span class="userMenu__avatar"><?= h($initial) ?></span>
      <span class="userMenu__who">
        <span class="userMenu__name"><?= h($who !== '' ? $who : '-') ?></span>
        <span class="userMenu__role"><?= h($role !== '' ? $role : '') ?></span>
      </span>
      <span class="userMenu__chev">▾</span>
    </button>

    <div class="userMenu__panel" id="userMenuPanel" role="menu" hidden>
      <?php if ($isAdmin): ?>
        <a class="userMenu__item" href="/wbss/public/admin/users/index.php" role="menuitem">👤 ユーザー管理</a>
        <div class="userMenu__sep"></div>
      <?php endif; ?>

      <div class="userMenu__label">🎨 テーマ</div>
      <div class="userMenu__grid">
        <?php foreach ($options as $k => $label): ?>
          <button
            type="button"
            class="userMenu__themeBtn <?= $cur === $k ? 'on' : '' ?>"
            onclick="setTheme('<?= h($k) ?>')"
            role="menuitem"
          ><?= h($label) ?></button>
        <?php endforeach; ?>
      </div>

      <div class="userMenu__sep"></div>

      <div
        class="userMenu__push"
        data-push-ui
        data-store-id="<?= (int)$storeId ?>"
        data-csrf="<?= h($csrf) ?>"
        data-unread-count="0"
      >
        <div class="userMenu__label">🔔 通知</div>
        <div class="userMenu__pushRow">
          <div class="userMenu__pushStatus" data-push-status>状態を確認中…</div>
          <div class="userMenu__pushActions">
            <button type="button" class="userMenu__pushBtn" data-push-enable>ON</button>
            <button type="button" class="userMenu__pushBtn" data-push-disable hidden>OFF</button>
          </div>
        </div>
      </div>

      <div class="userMenu__sep"></div>

      <a class="userMenu__item danger" href="/wbss/public/logout.php" role="menuitem">🚪 ログアウト</a>
    </div>
  </div>

  <script>
(function(){
  const btn = document.getElementById('userMenuBtn');
  const panel = document.getElementById('userMenuPanel');
  if(!btn || !panel) return;

  function open(){
    panel.hidden = false;
    btn.setAttribute('aria-expanded','true');
  }
  function close(){
    panel.hidden = true;
    btn.setAttribute('aria-expanded','false');
  }

  btn.addEventListener('click', (e) => {
    e.preventDefault();
    e.stopPropagation();
    if (panel.hidden) open(); else close();
  });

  document.addEventListener('click', () => close());
  document.addEventListener('keydown', (e) => { if (e.key === 'Escape') close(); });

  window.setTheme = async function(theme){
    try{
      const res = await fetch('/wbss/public/api/set_theme.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        credentials:'same-origin',
        body: 'theme=' + encodeURIComponent(theme)
      });
      const j = await res.json().catch(()=>({}));
      if (j && j.ok){
        document.body.setAttribute('data-theme', theme);

        // ボタンのon表示を更新
        document.querySelectorAll('.userMenu__themeBtn').forEach(el => el.classList.remove('on'));
        const onBtn = document.querySelector('.userMenu__themeBtn[onclick*="' + theme + '"]');
        if (onBtn) onBtn.classList.add('on');

        close();
        return;
      }
    }catch(e){}
    alert('テーマ変更に失敗しました');
  };
})();
</script>
  <?php
  return (string)ob_get_clean();
}
/** 共通ヘッダ */
function render_header(string $title, array $opts = []): void {
  $back_href  = array_key_exists('back_href', $opts) ? $opts['back_href'] : '/wbss/public/dashboard.php';
  $back_label = (string)($opts['back_label'] ?? '← 戻る');
  $right_html = (string)($opts['right_html'] ?? '');
  $show_store = (bool)($opts['show_store'] ?? true);
  $show_user  = (bool)($opts['show_user'] ?? true);

  $store_id = null;
  if (function_exists('current_store_id')) {
    $sid = current_store_id();
    $store_id = ($sid === null) ? null : (int)$sid;
  }
  $store_name = layout_store_name($store_id);

  $login_id = (string)($_SESSION['login_id'] ?? '');
  $display  = (string)($_SESSION['display_name'] ?? '');
  $role     = (string)($_SESSION['role'] ?? '');
  $who = $display !== '' ? $display : $login_id;

  ?>
  <header class="app-header">
    <div class="app-header__inner">
      <div class="app-header__start">
        <?php if ($back_href !== null && $back_href !== ''): ?>
          <a class="app-back" href="<?= h((string)$back_href) ?>">
            <span class="app-back__icon">←</span>
            <span class="app-back__label"><?= h(preg_replace('/^←\s*/u', '', $back_label) ?? $back_label) ?></span>
          </a>
        <?php endif; ?>
      </div>

      <div class="app-header__main">
        <div class="app-titleRow">
          <div class="app-title"><?= h($title) ?></div>
        </div>
        <div class="app-sub" aria-label="ページ情報">
          <?php if ($show_store): ?>
            <span class="pill"><span class="pill__label">店舗</span><span class="pill__value"><?= h($store_name !== '' ? $store_name : '未選択') ?><?= $store_id !== null ? ' (#'.(int)$store_id.')' : '' ?></span></span>
          <?php endif; ?>
          <?php if ($show_user): ?>
            <span class="pill"><span class="pill__label">ユーザー</span><span class="pill__value"><?= h($who !== '' ? $who : '-') ?><?= $role !== '' ? ' / '.h($role) : '' ?></span></span>
          <?php endif; ?>
        </div>
      </div>

      <div class="app-header__end">
        <?php if ($right_html !== ''): ?>
          <div class="app-actions">
            <?= $right_html ?>
          </div>
        <?php endif; ?>
        <?= render_user_menu_html() ?>
      </div>
    </div>
  </header>
  <?php
}

/** 共通CSS（テーマ変数方式） */
function render_layout_css(): void {
  ?>
  <link rel="stylesheet" href="/wbss/public/assets/css/style.css?v=20260317a">
  <?php
}

/** ページ開始（PWA meta込み） */
function render_page_start(string $title): void {
  $theme = current_ui_theme();
  ?>
  <!doctype html>
  <html lang="ja">
  <head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title><?= h($title) ?></title>

    <!-- PWA -->
    <link rel="manifest" href="/wbss/public/manifest.webmanifest">
    <meta name="theme-color" content="#0b1220">

    <!-- iOS PWA -->
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="WBSS">
    <link rel="apple-touch-icon" href="/wbss/public/assets/apple-touch-icon.png?v=3">

    <?php render_layout_css(); ?>
  </head>
  <body data-theme="<?= h($theme) ?>">
  <?php
}

/** ページ終了 */
function render_page_end(): void {
  ?>
  <style>
  .pwa-refresh-indicator{
    position:fixed;
    top:10px;
    left:50%;
    transform:translate(-50%, -10px);
    z-index:9999;
    padding:8px 12px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.14);
    background:rgba(15,23,42,.88);
    color:#fff;
    font-size:12px;
    font-weight:800;
    letter-spacing:.01em;
    opacity:0;
    pointer-events:none;
    transition:opacity .16s ease, transform .16s ease;
    box-shadow:0 10px 24px rgba(0,0,0,.18);
  }
  .pwa-refresh-indicator.is-visible{
    opacity:1;
    transform:translate(-50%, 0);
  }
  .pwa-refresh-indicator.is-ready{
    background:rgba(37,99,235,.92);
  }
  </style>
  <script>
  if ("serviceWorker" in navigator) {
    window.addEventListener("load", () => {
      navigator.serviceWorker.register("/wbss/public/sw.js").catch(()=>{});
    });
  }

  (function(){
    const isStandalone = (() => {
      const mq = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
      const iosStandalone = typeof navigator !== 'undefined' && 'standalone' in navigator && !!navigator.standalone;
      return !!(mq || iosStandalone);
    })();
    if (!isStandalone || !('ontouchstart' in window)) return;

    const indicator = document.createElement('div');
    indicator.className = 'pwa-refresh-indicator';
    indicator.textContent = '下に引っ張って更新';
    document.body.appendChild(indicator);

    let startY = 0;
    let pulling = false;
    let ready = false;
    const threshold = 88;

    function resetIndicator(){
      indicator.classList.remove('is-visible', 'is-ready');
      indicator.textContent = '下に引っ張って更新';
      ready = false;
    }

    window.addEventListener('touchstart', function(e){
      if (!e.touches || e.touches.length !== 1) return;
      if ((window.scrollY || document.documentElement.scrollTop || 0) > 0) return;
      startY = e.touches[0].clientY;
      pulling = true;
      ready = false;
    }, { passive: true });

    window.addEventListener('touchmove', function(e){
      if (!pulling || !e.touches || e.touches.length !== 1) return;
      const deltaY = e.touches[0].clientY - startY;
      if (deltaY <= 8) {
        resetIndicator();
        return;
      }
      indicator.classList.add('is-visible');
      if (deltaY >= threshold) {
        ready = true;
        indicator.classList.add('is-ready');
        indicator.textContent = '離すと更新します';
      } else {
        ready = false;
        indicator.classList.remove('is-ready');
        indicator.textContent = '下に引っ張って更新';
      }
    }, { passive: true });

    window.addEventListener('touchend', function(){
      if (!pulling) return;
      pulling = false;
      if (ready) {
        indicator.classList.add('is-ready');
        indicator.classList.add('is-visible');
        indicator.textContent = '更新中…';
        window.location.reload();
        return;
      }
      resetIndicator();
    }, { passive: true });

    window.addEventListener('touchcancel', function(){
      pulling = false;
      resetIndicator();
    }, { passive: true });
  })();
  </script>
  </body>
  </html>
  <?php
}
