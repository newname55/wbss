/* Simple SW: htmlはネット優先、静的はキャッシュ優先 */
const CACHE = "seika-app-v1";
const STATIC = [
  "/seika-app/public/dashboard.php",
  "/seika-app/public/pwa_help.php",
  "/seika-app/public/manifest.webmanifest"
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