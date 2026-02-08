<?php
declare(strict_types=1);
$storeId = (int)($_GET['store_id'] ?? 0);
$key = 'CHANGE_ME_VIEW_KEY';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>送迎ライブ</title>
<style>
  body{font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;background:#0b1020;color:#e8ecff;margin:0}
  .wrap{max-width:1100px;margin:16px auto;padding:0 12px}
  .card{background:#121a33;border:1px solid rgba(255,255,255,.12);border-radius:12px;padding:12px;margin:10px 0}
  .row{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
  .badge{padding:4px 8px;border-radius:999px;border:1px solid rgba(255,255,255,.15);font-size:12px}
  .bad{background:rgba(255,0,0,.15);border-color:rgba(255,0,0,.4)}
  .ok{background:rgba(0,255,140,.12);border-color:rgba(0,255,140,.35)}
  .muted{color:#aab3d6}
  table{width:100%;border-collapse:collapse}
  th,td{padding:10px;border-bottom:1px solid rgba(255,255,255,.10);text-align:left;font-size:14px}
</style>
</head>
<body>
<div class="wrap">
  <h2>送迎ライブ（更新 5秒）</h2>
  <div class="card">
    <div class="row">
      <span class="badge">store_id: <?= (int)$storeId ?></span>
      <span class="badge">停止判定: 45秒以上</span>
      <span class="muted" id="last">-</span>
    </div>
  </div>

  <div class="card">
    <table>
      <thead>
        <tr>
          <th>車両</th>
          <th>最終更新</th>
          <th>lat,lng</th>
          <th>速度(m/s)</th>
          <th>精度(m)</th>
          <th>状態</th>
        </tr>
      </thead>
      <tbody id="tbody"></tbody>
    </table>
  </div>
</div>

<script>
const STOP_SEC = 45;
const url = `/api/vehicles/live.php?k=<?= $key ?>&store_id=<?= (int)$storeId ?>`;

function fmtAgo(sec){
  if (sec == null) return '-';
  if (sec < 60) return `${sec}s`;
  const m = Math.floor(sec/60);
  const s = sec%60;
  return `${m}m ${s}s`;
}

async function tick(){
  const res = await fetch(url, {cache:'no-store'});
  if (!res.ok) return;
  const j = await res.json();
  if (!j.ok) return;

  const tb = document.getElementById('tbody');
  tb.innerHTML = '';

  for (const v of j.vehicles){
    const sec = v.seconds_since_update == null ? null : Number(v.seconds_since_update);
    const stopped = (sec != null && sec >= STOP_SEC) || sec == null;

    const tr = document.createElement('tr');

    const name = `${v.vehicle_name ?? '車両'} (#${v.vehicle_id})`;
    const received = v.received_at ?? '-';
    const latlng = (v.lat == null) ? '-' : `${Number(v.lat).toFixed(6)}, ${Number(v.lng).toFixed(6)}`;
    const speed = (v.speed_mps == null) ? '-' : Number(v.speed_mps).toFixed(1);
    const acc = (v.accuracy_m == null) ? '-' : v.accuracy_m;

    tr.innerHTML = `
      <td>${name}</td>
      <td>${received} <span class="muted">(${fmtAgo(sec)}前)</span></td>
      <td>${latlng}</td>
      <td>${speed}</td>
      <td>${acc}</td>
      <td><span class="badge ${stopped ? 'bad':'ok'}">${stopped ? '途切れ/停止疑い':'追跡中'}</span></td>
    `;
    tb.appendChild(tr);
  }

  document.getElementById('last').textContent = `last fetch: ${new Date().toLocaleTimeString()}`;
}

tick();
setInterval(tick, 5000);
</script>
</body>
</html>