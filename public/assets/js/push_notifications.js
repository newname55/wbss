(function(){
  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = window.atob(base64);
    const output = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; ++i) output[i] = raw.charCodeAt(i);
    return output;
  }

  async function syncBadge(count) {
    try {
      if ('setAppBadge' in navigator) {
        if (count > 0) {
          await navigator.setAppBadge(count);
        } else if ('clearAppBadge' in navigator) {
          await navigator.clearAppBadge();
        }
      }
    } catch (err) {}
  }

  async function fetchBadgeState(storeId) {
    const url = '/wbss/public/api/message_badge_state.php?store_id=' + encodeURIComponent(String(storeId || 0));
    const res = await fetch(url, { credentials: 'same-origin' });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.ok) {
      throw new Error((json && json.error) || 'badge_state_failed');
    }
    return parseInt(json.unread_count || 0, 10) || 0;
  }

  async function syncBadgeForStore(storeId, fallbackCount) {
    try {
      const count = await fetchBadgeState(storeId);
      await syncBadge(count);
      return count;
    } catch (err) {
      if (typeof fallbackCount === 'number') {
        await syncBadge(fallbackCount);
        return fallbackCount;
      }
      return 0;
    }
  }

  async function fetchPublicKey() {
    const res = await fetch('/wbss/public/api/push_public_key.php', { credentials: 'same-origin' });
    const json = await res.json();
    if (!res.ok || !json.ok || !json.public_key) {
      throw new Error((json && json.error) || 'public_key_failed');
    }
    return json.public_key;
  }

  async function getRegistration() {
    if ('serviceWorker' in navigator) {
      const registration = await navigator.serviceWorker.register('/wbss/public/sw.js');
      return await navigator.serviceWorker.ready || registration;
    }
    throw new Error('service_worker_not_supported');
  }

  function isIosStandaloneRequired() {
    const ua = navigator.userAgent || '';
    const isIOS = /iPhone|iPad|iPod/.test(ua);
    const standalone = window.matchMedia && window.matchMedia('(display-mode: standalone)').matches;
    return isIOS && !standalone;
  }

  function selectedStatusText(state) {
    if (state.enabled) return '通知は有効です';
    if (state.permission === 'denied') return '通知がブロックされています';
    if (state.requiresInstall) return 'iPhone はホーム画面追加後に通知できます';
    if (!state.supported) return 'この環境では通知に対応していません';
    return '通知はまだオフです';
  }

  async function refreshCard(card) {
    const status = card.querySelector('[data-push-status]');
    const note = card.querySelector('[data-push-note]');
    const enableBtn = card.querySelector('[data-push-enable]');
    const disableBtn = card.querySelector('[data-push-disable]');
    const state = {
      supported: ('serviceWorker' in navigator) && ('PushManager' in window) && ('Notification' in window),
      permission: ('Notification' in window) ? Notification.permission : 'denied',
      enabled: false,
      requiresInstall: isIosStandaloneRequired(),
    };

    if (state.supported) {
      try {
        const registration = await getRegistration();
        const subscription = await registration.pushManager.getSubscription();
        state.enabled = !!subscription && state.permission === 'granted';
        if (subscription) {
          card.dataset.pushEndpoint = subscription.endpoint || '';
        }
      } catch (err) {}
    }

    if (status) status.textContent = selectedStatusText(state);
    if (note) {
      note.textContent = state.requiresInstall
        ? 'Safari ではなく、ホーム画面に追加したアプリから通知を有効化してください。'
        : '未読メッセージとありがとうカードを受け取れます。';
    }
    if (enableBtn) enableBtn.hidden = !state.supported || state.enabled || state.permission === 'denied';
    if (disableBtn) disableBtn.hidden = !state.enabled;
    card.dataset.pushEnabled = state.enabled ? '1' : '0';
    const storeId = parseInt(card.dataset.storeId || '0', 10);
    const fallbackCount = parseInt(card.dataset.unreadCount || '0', 10);
    await syncBadgeForStore(storeId, fallbackCount);
  }

  async function subscribe(card) {
    const storeId = parseInt(card.dataset.storeId || '0', 10);
    const csrf = card.dataset.csrf || '';
    if (isIosStandaloneRequired()) {
      throw new Error('ホーム画面に追加した PWA から有効化してください');
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
      throw new Error(permission === 'denied' ? '通知が拒否されました' : '通知許可が必要です');
    }

    const registration = await getRegistration();
    const publicKey = await fetchPublicKey();
    let subscription = await registration.pushManager.getSubscription();
    if (!subscription) {
      subscription = await registration.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(publicKey),
      });
    }

    const params = new URLSearchParams();
    params.set('csrf_token', csrf);
    params.set('store_id', String(storeId));
    params.set('subscription', JSON.stringify(subscription));
    params.set('content_encoding', 'aes128gcm');

    const res = await fetch('/wbss/public/api/push_subscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: params.toString(),
    });
    const json = await res.json().catch(() => ({}));
    if (!res.ok || !json.ok) {
      throw new Error((json && json.error) || '購読保存に失敗しました');
    }
  }

  async function unsubscribe(card) {
    const csrf = card.dataset.csrf || '';
    const registration = await getRegistration();
    const subscription = await registration.pushManager.getSubscription();
    if (!subscription) return;

    const params = new URLSearchParams();
    params.set('csrf_token', csrf);
    params.set('endpoint', subscription.endpoint || '');
    await fetch('/wbss/public/api/push_unsubscribe.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      credentials: 'same-origin',
      body: params.toString(),
    }).catch(() => {});
    await subscription.unsubscribe().catch(() => {});
  }

  document.addEventListener('DOMContentLoaded', function(){
    document.querySelectorAll('[data-badge-sync]').forEach(function(el){
      const storeId = parseInt(el.dataset.storeId || '0', 10);
      const fallbackCount = parseInt(el.dataset.unreadCount || '0', 10);
      syncBadgeForStore(storeId, fallbackCount);
    });

    document.querySelectorAll('[data-push-ui]').forEach(function(card){
      const enableBtn = card.querySelector('[data-push-enable]');
      const disableBtn = card.querySelector('[data-push-disable]');
      refreshCard(card);

      if (enableBtn) {
        enableBtn.addEventListener('click', async function(){
          const status = card.querySelector('[data-push-status]');
          if (status) status.textContent = '通知を有効化しています…';
          try {
            await subscribe(card);
          } catch (err) {
            if (status) status.textContent = err && err.message ? err.message : '通知の有効化に失敗しました';
          }
          await refreshCard(card);
        });
      }

      if (disableBtn) {
        disableBtn.addEventListener('click', async function(){
          const status = card.querySelector('[data-push-status]');
          if (status) status.textContent = '通知を解除しています…';
          await unsubscribe(card);
          await refreshCard(card);
        });
      }
    });
  });

  window.WbssPush = { syncBadge, syncBadgeForStore };
})();
