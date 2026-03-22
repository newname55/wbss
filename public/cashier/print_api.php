<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Tokyo');

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/print_escpos.php';
require_once __DIR__ . '/../../app/print_fetch_ticket.php';

// 認証・権限チェック
require_login();
if (function_exists('require_role')) {
  require_role(['cast','staff','manager','admin','super_user']);
}

header('Content-Type: application/json; charset=utf-8');

// debug log start
error_log('[print_api] start IP=' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' USER=' . (string)($_SESSION['user_id'] ?? '-') . ' URI=' . ($_SERVER['REQUEST_URI'] ?? '-'));


try {
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok'=>false,'message'=>'POST only']); exit;
  }

  $raw = file_get_contents('php://input');
  error_log('[print_api] raw_body_len=' . strlen((string)$raw));
  $j = json_decode($raw ?: '[]', true);
  if (!is_array($j)) $j = [];
  error_log('[print_api] parsed_json=' . substr(json_encode($j, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),0,1000));

  $store_id  = (int)($j['store_id'] ?? 0);
  $ticket_id = (int)($j['ticket_id'] ?? 0);
  $kind      = (string)($j['kind'] ?? 'slip'); // slip|receipt

  if ($store_id<=0 || $ticket_id<=0) throw new RuntimeException('store_id / ticket_id が不正');
  if (!in_array($kind, ['slip','receipt'], true)) throw new RuntimeException('kind が不正');
  error_log('[print_api] params store_id=' . $store_id . ' ticket_id=' . $ticket_id . ' kind=' . $kind);


  $pdo = db();

  // printer settings
  error_log('[print_api] lookup printer for store=' . $store_id . ' role=' . $kind);
  $st = $pdo->prepare("
    SELECT *
    FROM store_printers
    WHERE store_id=:sid AND printer_role=:role AND is_enabled=1
    LIMIT 1
  ");
  $st->execute([':sid'=>$store_id, ':role'=>$kind]);
  $pr = $st->fetch(PDO::FETCH_ASSOC);
  if (!$pr) throw new RuntimeException("プリンタ設定が見つかりません(store={$store_id}, role={$kind})");
  error_log('[print_api] printer row: ' . substr(json_encode($pr, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),0,1000));

  $method = (string)($pr['method'] ?? '');
  if ($method !== 'tcp_escpos') {
    throw new RuntimeException("このAPIは tcp_escpos 専用です（現在: {$method}）");
  }

  $ip   = (string)($pr['printer_ip'] ?? '');
  $port = (int)($pr['printer_port'] ?? 9100);
  $copies = (int)($pr['copies'] ?? 1);
  $paper  = (string)($pr['paper_mm'] ?? '80');

  if ($ip === '') throw new RuntimeException('printer_ip が未設定');

  $data = fetch_ticket_for_print($pdo, $store_id, $ticket_id);

  // build ESC/POS
  $cols = ($paper === '58') ? 32 : 48; // ざっくり目安（後で調整OK）

  $b = (new EscposBuilder())->init()->resetStyle();

  if ($kind === 'slip') {
    build_slip($b, $data, $cols);
  } else {
    build_receipt($b, $data, $cols);
  }

  // カットを半分切り（partial cut）に変更
  // 一部プリンタはカッター位置まで用紙を多めにフィードする必要があるため少し多めに給紙する
  $b->feed(6)->cut(false);

  $payload = $b->build();

  // send copies
  $copies = max(1, min(5, $copies));
  error_log('[print_api] sending to printer ip=' . $ip . ' port=' . $port . ' copies=' . $copies . ' payload_len=' . strlen($payload));
  for ($i=0; $i<$copies; $i++) {
    error_log('[print_api] send attempt ' . ($i+1) . '/' . $copies);
    escpos_tcp_send($ip, $port, $payload, 3);
    error_log('[print_api] send ok ' . ($i+1));
  }

  error_log('[print_api] all copies sent');
  echo json_encode(['ok'=>true,'message'=>'printed']);
} catch (Throwable $e) {
  // log full exception
  error_log('[print_api] ERROR: ' . $e->getMessage() . "\n" . $e->getTraceAsString());
  http_response_code(400);
  echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);
}

function build_slip(EscposBuilder $b, array $data, int $cols): void {
  $h = $data['header'];
  $items = $data['items'];
  $tot = $data['totals'];

  $ticketId = (int)($h['ticket_id'] ?? 0);
  $created  = (string)($h['created_at'] ?? '');
  $cust     = (string)($h['customer_name'] ?? '');
  $status   = (string)($h['status'] ?? '');

  $b->align('center')->bold(true)->text('伝票')->bold(false)->align('left');
  $b->text("Ticket #{$ticketId}");
  if ($created !== '') $b->text("作成: {$created}");
  if ($cust !== '')    $b->text("お客様: {$cust}");
  if ($status !== '')  $b->text("状態: {$status}");
  $b->hr($cols);

  if (!$items) {
    $b->text('（明細なし）');
  } else {
    foreach ($items as $it) {
      $name = trim((string)($it['name'] ?? ''));
      $qty  = (int)($it['qty'] ?? 1);
      $amt  = (int)($it['amount'] ?? 0);

      // 1行に収める簡易整形
      $left = $name;
      if ($qty !== 1) $left .= " x{$qty}";
      $right = number_format($amt);

      $b->text(fit_lr($left, $right, $cols));
    }
  }

  $b->hr($cols);
  $total = (int)($tot['total_in'] ?? 0);
  if ($total > 0) {
    $b->bold(true)->text(fit_lr('合計', number_format($total), $cols))->bold(false);
  }
  $note = trim((string)($h['note'] ?? ''));
  if ($note !== '') {
    $b->hr($cols);
    $b->text('メモ:');
    foreach (explode("\n", str_replace("\r", "", $note)) as $line) {
      $b->text($line);
    }
  }
}

function build_receipt(EscposBuilder $b, array $data, int $cols): void {
  $h = $data['header'];
  $tot = $data['totals'];
  $pays = $data['payments'];

  $ticketId = (int)($h['ticket_id'] ?? 0);
  $receiptName = trim((string)($h['receipt_name'] ?? ''));
  if ($receiptName === '') $receiptName = '上様';

  $subtotal = (int)($tot['subtotal_ex'] ?? 0);
  $tax      = (int)($tot['tax'] ?? 0);
  $total    = (int)($tot['total_in'] ?? 0);

  $b->align('center')->bold(true)->text('領収書')->bold(false)->align('left');
  $b->text("宛名: {$receiptName} 様");
  $b->text("Ticket #{$ticketId}");
  $b->text("発行: ".date('Y-m-d H:i'));
  $b->hr($cols);

  // Big amount
  $b->align('center')->bold(true)->size(2,2)->text('￥'.number_format($total))->size(1,1)->bold(false)->align('left');

  if ($subtotal>0 || $tax>0) {
    $b->text(fit_lr('税別', number_format($subtotal), $cols));
    $b->text(fit_lr('消費税', number_format($tax), $cols));
  }
  $b->hr($cols);

  if ($pays) {
    $b->text('お支払い内訳');
    foreach ($pays as $p) {
      $m = (string)($p['method'] ?? '');
      $a = (int)($p['amt'] ?? 0);
      $b->text(fit_lr($m, number_format($a), $cols));
    }
    $b->hr($cols);
  }

  $b->text('但し お品代として上記正に領収いたしました。');
}

function fit_lr(string $left, string $right, int $cols): string {
  // very simple monospaced padding (UTF-8 width is not perfect)
  $left = preg_replace('/\s+/', ' ', $left ?? '');
  $right = $right ?? '';
  $space = $cols - mb_strlen($left) - mb_strlen($right);
  if ($space < 1) {
    // truncate left
    $maxLeft = max(1, $cols - mb_strlen($right) - 1);
    $left = mb_substr($left, 0, $maxLeft);
    $space = 1;
  }
  return $left . str_repeat(' ', $space) . $right;
}