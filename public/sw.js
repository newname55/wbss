/* Simple SW: htmlはネット優先、静的はキャッシュ優先 */
const CACHE = "wbss-v2";
const STATIC = [
  "/wbss/public/dashboard.php",
  "/wbss/public/pwa_help.php",
  "/wbss/public/manifest.webmanifest"
  // 必要ならCSS/JS/アイコンも追加
];

self.addEventListener("install", (e) => {
  e.waitUntil(
    caches.open(CACHE).then(c => c.addAll(STATIC)).then(() => self.skipWaiting())
  );
});

self.addEventListener("activate", (e) => {
  e.waitUntil(
    caches.keys().then(keys => Promise.all(keys.map(k => (k === CACHE ? null : caches.delete(k)))))
      .then(() => self.clients.claim())
  );
});

self.addEventListener("fetch", (e) => {
  const req = e.request;
  const url = new URL(req.url);

  // 同一オリジンのみ
  if (url.origin !== self.location.origin) return;

  // API系は常にネット（最新が欲しい）
  if (url.pathname.includes("/api/")) {
    e.respondWith(fetch(req));
    return;
  }

  // GETのみ
  if (req.method !== "GET") return;

  // html/phpはネット優先（ログインや画面が変わる）
  const accept = req.headers.get("accept") || "";
  const isDoc = accept.includes("text/html") || url.pathname.endsWith(".php");

  if (isDoc) {
    e.respondWith(
      fetch(req).then(res => {
        const copy = res.clone();
        caches.open(CACHE).then(c => c.put(req, copy));
        return res;
      }).catch(() => caches.match(req))
    );
    return;
  }

  // 静的はキャッシュ優先
  e.respondWith(
    caches.match(req).then(hit => hit || fetch(req).then(res => {
      const copy = res.clone();
      caches.open(CACHE).then(c => c.put(req, copy));
      return res;
    }))
  );
});

self.addEventListener("push", (event) => {
  let payload = {
    title: "新しい通知",
    body: "未読メッセージがあります",
    url: "/wbss/public/dashboard.php",
    badgeCount: 0
  };

  try {
    const data = event.data ? event.data.json() : null;
    if (data && typeof data === "object") {
      payload = Object.assign(payload, data);
    }
  } catch (err) {}

  event.waitUntil((async () => {
    if ("setAppBadge" in self.navigator || "setAppBadge" in self.registration) {
      try {
        const count = Number(payload.badgeCount || 0);
        if ("setAppBadge" in self.navigator) {
          if (count > 0) await self.navigator.setAppBadge(count);
          else if ("clearAppBadge" in self.navigator) await self.navigator.clearAppBadge();
        } else if ("setAppBadge" in self.registration) {
          if (count > 0) await self.registration.setAppBadge(count);
          else if ("clearAppBadge" in self.registration) await self.registration.clearAppBadge();
        }
      } catch (err) {}
    }

    await self.registration.showNotification(payload.title, {
      body: payload.body || "",
      icon: "/wbss/public/assets/icon-192.png",
      badge: "/wbss/public/assets/icon-192.png",
      data: {
        url: payload.url || "/wbss/public/dashboard.php"
      },
      tag: payload.kind || "message",
      renotify: true
    });
  })());
});

self.addEventListener("notificationclick", (event) => {
  event.notification.close();
  const targetUrl = (event.notification && event.notification.data && event.notification.data.url)
    ? event.notification.data.url
    : "/wbss/public/dashboard.php";

  event.waitUntil(
    clients.matchAll({ type: "window", includeUncontrolled: true }).then((clientList) => {
      for (const client of clientList) {
        if ("focus" in client) {
          if (client.url === new URL(targetUrl, self.location.origin).href) {
            return client.focus();
          }
        }
      }
      if (clients.openWindow) {
        return clients.openWindow(targetUrl);
      }
      return null;
    })
  );
});
