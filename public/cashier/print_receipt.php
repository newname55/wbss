<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

$store_id  = (int)($_GET['store_id'] ?? 0);
$ticket_id = (int)($_GET['ticket_id'] ?? 0);
if ($store_id <= 0 || $ticket_id <= 0) { http_response_code(400); exit('bad request'); }

$pdo = db();

/* ヘッダ */
$st = $pdo->prepare("
  SELECT t.ticket_id, t.store_id, t.status, t.created_at,
         COALESCE(t.customer_name,'') AS customer_name,
         COALESCE(t.receipt_name,'') AS receipt_name
  FROM ticket_headers t
  WHERE t.ticket_id = :tid AND t.store_id = :sid
  LIMIT 1
");
$st->execute([':tid'=>$ticket_id, ':sid'=>$store_id]);
$head = $st->fetch(PDO::FETCH_ASSOC);
if (!$head) { http_response_code(404); exit('not found'); }

/* 合計（あなたの既存計算に寄せて置換してOK）
   ここでは ticket_totals がある想定にしているけど、無いなら items集計でもOK */
$stT = $pdo->prepare("
  SELECT subtotal_ex, tax, total_in
  FROM ticket_totals
  WHERE ticket_id = :tid
  LIMIT 1
");
$stT->execute([':tid'=>$ticket_id]);
$tot = $stT->fetch(PDO::FETCH_ASSOC);

$subtotal_ex = (int)($tot['subtotal_ex'] ?? 0);
$tax         = (int)($tot['tax'] ?? 0);
$total_in    = (int)($tot['total_in'] ?? ($subtotal_ex + $tax));

/* 入金内訳（複数決済対応） */
$stP = $pdo->prepare("
  SELECT method, SUM(amount) AS amt
  FROM ticket_payments
  WHERE ticket_id = :tid
  GROUP BY method
  ORDER BY method
");
$stP->execute([':tid'=>$ticket_id]);
$pays = $stP->fetchAll(PDO::FETCH_ASSOC);

$receipt_name = trim((string)($head['receipt_name'] ?? ''));
if ($receipt_name === '') $receipt_name = '上様';
?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>領収書 #<?= (int)$ticket_id ?></title>
<style>
  body{ margin:0; font-family: ui-monospace, Menlo, Consolas, "Noto Sans JP", system-ui, sans-serif; }
  .paper{ width:80mm; padding:3mm; box-sizing:border-box; }
  h1{ font-size:14px; margin:0 0 6px; }
  .row{ display:flex; justify-content:space-between; gap:8px; font-size:12px; }
  .hr{ border-top:1px dashed #000; margin:6px 0; }
  .big{ font-size:18px; font-weight:900; text-align:center; margin:6px 0; }
  .mut{ font-size:11px; opacity:.75; }
  .small{ font-size:11px; }
  table{ width:100%; border-collapse:collapse; font-size:12px; }
  td{ padding:2px 0; }
  .num{ text-align:right; white-space:nowrap; }
  @media print{ @page{ margin:0; } .noPrint{ display:none !important; } }
</style>
</head>
<body>
  <div class="paper">
    <h1>領収書</h1>
    <div class="row"><div>宛名</div><div><?= htmlspecialchars($receipt_name) ?> 様</div></div>
    <div class="row"><div>伝票ID</div><div>#<?= (int)$ticket_id ?></div></div>
    <div class="row"><div>日付</div><div><?= date('Y-m-d H:i') ?></div></div>

    <div class="hr"></div>

    <div class="big">￥<?= number_format($total_in) ?></div>
    <div class="row small"><div>（税別）</div><div><?= number_format($subtotal_ex) ?></div></div>
    <div class="row small"><div>消費税</div><div><?= number_format($tax) ?></div></div>

    <div class="hr"></div>

    <?php if ($pays): ?>
      <div class="small">お支払い内訳</div>
      <table>
        <?php foreach($pays as $p): ?>
          <tr>
            <td><?= htmlspecialchars((string)$p['method']) ?></td>
            <td class="num"><?= number_format((int)$p['amt']) ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
      <div class="hr"></div>
    <?php endif; ?>

    <div class="mut small">但し お品代として上記正に領収いたしました。</div>
    <div class="hr"></div>

    <div class="small">
      店舗：<?= (int)$store_id ?><br>
      ※印紙は金額・形態により取扱が異なります（運用ルールに準拠）
    </div>

    <div class="noPrint" style="margin-top:10px; display:flex; gap:8px;">
      <button onclick="window.print()">印刷</button>
      <button onclick="window.close()">閉じる</button>
    </div>
  </div>

<script>
  window.addEventListener('load', () => {
    setTimeout(() => {
      window.print();
      setTimeout(() => window.close(), 600);
    }, 150);
  });
</script>
</body>
</html>