<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';

$store_id  = (int)($_GET['store_id'] ?? 0);
$ticket_id = (int)($_GET['ticket_id'] ?? 0);
if ($store_id <= 0 || $ticket_id <= 0) { http_response_code(400); exit('bad request'); }

$pdo = db();

/* セキュリティ：store_id一致必須（マルチ店舗） */
$st = $pdo->prepare("
  SELECT t.ticket_id, t.store_id, t.status, t.created_at,
         COALESCE(t.customer_name,'') AS customer_name,
         COALESCE(t.note,'') AS note
  FROM ticket_headers t
  WHERE t.ticket_id = :tid AND t.store_id = :sid
  LIMIT 1
");
$st->execute([':tid'=>$ticket_id, ':sid'=>$store_id]);
$head = $st->fetch(PDO::FETCH_ASSOC);
if (!$head) { http_response_code(404); exit('not found'); }

/* 明細（例：セット/指名/ドリンクなど）
   ※あなたの既存テーブル名に合わせてここを書き換えればOK */
$st2 = $pdo->prepare("
  SELECT item_type, item_name, qty, unit_price, amount
  FROM ticket_items
  WHERE ticket_id = :tid
  ORDER BY item_id ASC
");
$st2->execute([':tid'=>$ticket_id]);
$items = $st2->fetchAll(PDO::FETCH_ASSOC);

/* 合計（例） */
$subtotal = 0;
foreach($items as $it){ $subtotal += (int)($it['amount'] ?? 0); }

?>
<!doctype html>
<html lang="ja">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>伝票印刷 #<?= htmlspecialchars((string)$ticket_id) ?></title>
<style>
  :root{
    --w58: 58mm;
    --w80: 80mm;
  }
  body{ margin:0; font-family: ui-monospace, Menlo, Consolas, "Noto Sans JP", system-ui, sans-serif; }
  .paper{
    width: var(--w80); /* ← ここを58mmにしたいなら --w58 に */
    padding: 3mm;
    box-sizing: border-box;
  }
  h1{ font-size:14px; margin:0 0 6px; }
  .mut{ font-size:11px; opacity:.75; }
  .row{ display:flex; justify-content:space-between; gap:8px; font-size:12px; }
  .hr{ border-top:1px dashed #000; margin:6px 0; }
  table{ width:100%; border-collapse:collapse; font-size:12px; }
  th,td{ padding:2px 0; vertical-align:top; }
  th{ text-align:left; }
  .num{ text-align:right; white-space:nowrap; }
  .small{ font-size:11px; }
  .center{ text-align:center; }
  @media print{
    body{ -webkit-print-color-adjust: exact; print-color-adjust: exact; }
    .noPrint{ display:none !important; }
    @page{ margin:0; }
  }
</style>
</head>
<body>
  <div class="paper">
    <h1>伝票</h1>
    <div class="row"><div>伝票ID</div><div>#<?= (int)$ticket_id ?></div></div>
    <div class="row"><div>作成</div><div><?= htmlspecialchars((string)$head['created_at']) ?></div></div>
    <?php if ($head['customer_name']!==''): ?>
      <div class="row"><div>お客様</div><div><?= htmlspecialchars((string)$head['customer_name']) ?></div></div>
    <?php endif; ?>
    <div class="row"><div>状態</div><div><?= htmlspecialchars((string)$head['status']) ?></div></div>

    <div class="hr"></div>

    <table>
      <thead>
        <tr>
          <th>内容</th>
          <th class="num">数量</th>
          <th class="num">金額</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach($items as $it): ?>
          <tr>
            <td>
              <?= htmlspecialchars((string)($it['item_name'] ?? '')) ?>
              <?php if (!empty($it['item_type'])): ?>
                <span class="mut small">（<?= htmlspecialchars((string)$it['item_type']) ?>）</span>
              <?php endif; ?>
            </td>
            <td class="num"><?= (int)($it['qty'] ?? 0) ?></td>
            <td class="num"><?= number_format((int)($it['amount'] ?? 0)) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>

    <div class="hr"></div>

    <div class="row"><div>小計</div><div><?= number_format($subtotal) ?></div></div>
    <div class="mut small">※税/端数/入金は領収書側で確定する運用でもOK</div>

    <?php if ($head['note']!==''): ?>
      <div class="hr"></div>
      <div class="small">メモ：<?= nl2br(htmlspecialchars((string)$head['note'])) ?></div>
    <?php endif; ?>

    <div class="hr"></div>
    <div class="center small mut">WBSS</div>

    <div class="noPrint" style="margin-top:10px; display:flex; gap:8px;">
      <button onclick="window.print()">印刷</button>
      <button onclick="window.close()">閉じる</button>
    </div>
  </div>

<script>
  // 自動印刷（環境により印刷ダイアログは出る）
  window.addEventListener('load', () => {
    setTimeout(() => {
      window.print();
      // 印刷完了イベントはブラウザ依存なので少し待って閉じる
      setTimeout(() => window.close(), 600);
    }, 150);
  });
</script>
</body>
</html>
