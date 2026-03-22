<?php
declare(strict_types=1);

/**
 * Fetch ticket data for printing.
 * You may need to adjust table/column names to your haruto_core schema.
 */
function fetch_ticket_for_print(PDO $pdo, int $store_id, int $ticket_id): array {
  // 1) header
  // Try common header tables/columns
  $header = null;

  $candidates = [
    // table, idcol, storecol, created, customer, status, note, receipt_name
    // ticket_headers: uses ticket_id
    ['ticket_headers', 'ticket_id', 'store_id', 'created_at', 'customer_name', 'status', 'note', 'receipt_name'],
    // tickets: common table with primary column `id` and opened_at/created_at
    ['tickets',        'id',        'store_id', 'opened_at',  'customer_name', 'status', 'note', 'receipt_name'],
    // singular ticket table fallback
    ['ticket',         'id',        'store_id', 'created_at', 'customer_name', 'status', 'note', 'receipt_name'],
  ];

  foreach ($candidates as $c) {
    [$tbl,$idc,$sc,$cc,$cust,$st,$note,$rname] = $c;
    if (!table_exists($pdo, $tbl)) continue;

    // 必要なキー（idcol と storecol）が存在するか確認
    if (!column_exists($pdo, $tbl, $idc) || !column_exists($pdo, $tbl, $sc)) continue;

    // 各カラムが存在するかをチェックして、存在しない場合はリテラルでフォールバックする
    $sel_created = column_exists($pdo, $tbl, $cc) ? $cc : null;
    $sel_customer = column_exists($pdo, $tbl, $cust) ? $cust : null;
    $sel_status = column_exists($pdo, $tbl, $st) ? $st : null;
    $sel_note = column_exists($pdo, $tbl, $note) ? $note : null;
    $sel_rname = column_exists($pdo, $tbl, $rname) ? $rname : null;

    // SELECT 式を組み立て（存在しないカラムはリテラルに置き換える）
    $created_expr = $sel_created ? "COALESCE({$sel_created}, NOW())" : "NOW()";
    $customer_expr = $sel_customer ? "COALESCE({$sel_customer}, '')" : "''";
    $status_expr = $sel_status ? "COALESCE({$sel_status}, '')" : "''";
    $note_expr = $sel_note ? "COALESCE({$sel_note}, '')" : "''";
    $rname_expr = $sel_rname ? "COALESCE({$sel_rname}, '')" : "''";

    $sql = "SELECT {$idc} AS ticket_id, {$sc} AS store_id, {$created_expr} AS created_at, {$customer_expr} AS customer_name, {$status_expr} AS status, {$note_expr} AS note, {$rname_expr} AS receipt_name FROM {$tbl} WHERE {$idc}=:tid AND {$sc}=:sid LIMIT 1";

    $stt = $pdo->prepare($sql);
    $stt->execute([':tid'=>$ticket_id, ':sid'=>$store_id]);
    $row = $stt->fetch(PDO::FETCH_ASSOC);
    if ($row) { $header = $row; break; }
  }
  if (!$header) throw new RuntimeException("伝票ヘッダが見つかりません(ticket_id={$ticket_id})");

  // 2) items (best-effort)
  $items = [];

  if (table_exists($pdo, 'ticket_items')) {
    // 柔軟にカラムを検出して SELECT を組み立てる
    $colStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t");
    $colStmt->execute([':t' => 'ticket_items']);
    $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

    $nameCandidates = ['item_name','name','label','title','product_name','description'];
    $qtyCandidates  = ['qty','quantity','count'];
    $amtCandidates  = ['amount','price','unit_price','total','subtotal','amt'];

    $nameCol = null; foreach ($nameCandidates as $c) if (in_array($c, $cols, true)) { $nameCol = $c; break; }
    $qtyCol  = null; foreach ($qtyCandidates  as $c) if (in_array($c, $cols, true)) { $qtyCol = $c; break; }
    $amtCol  = null; foreach ($amtCandidates  as $c) if (in_array($c, $cols, true)) { $amtCol = $c; break; }

    $sel_name = $nameCol ? "COALESCE(`$nameCol`,'') AS name" : "'' AS name";
    $sel_qty  = $qtyCol  ? "COALESCE(`$qtyCol`,1) AS qty" : "1 AS qty";
    $sel_amt  = $amtCol  ? "COALESCE(`$amtCol`,0) AS amount" : "0 AS amount";

    // 順序用のカラム候補
    $orderCol = in_array('item_id', $cols, true) ? 'item_id' : (in_array('id', $cols, true) ? 'id' : null);
    $orderBy = $orderCol ? "ORDER BY `$orderCol` ASC" : '';

    $sql = "SELECT {$sel_name}, {$sel_qty}, {$sel_amt} FROM `ticket_items` WHERE `ticket_id` = :tid {$orderBy}";
    $stI = $pdo->prepare($sql);
    $stI->execute([':tid'=>$ticket_id]);
    $items = $stI->fetchAll(PDO::FETCH_ASSOC);
  } else {
    // fallback: try ticket_charges (common)
    if (table_exists($pdo, 'ticket_charges')) {
      $colStmt = $pdo->prepare("SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t");
      $colStmt->execute([':t' => 'ticket_charges']);
      $cols = $colStmt->fetchAll(PDO::FETCH_COLUMN);

      $nameCandidates = ['label','name','item_name','title','description'];
      $qtyCandidates  = ['qty','quantity','count'];
      $amtCandidates  = ['amount','price','unit_price','total','subtotal','amt'];

      $nameCol = null; foreach ($nameCandidates as $c) if (in_array($c, $cols, true)) { $nameCol = $c; break; }
      $qtyCol  = null; foreach ($qtyCandidates  as $c) if (in_array($c, $cols, true)) { $qtyCol = $c; break; }
      $amtCol  = null; foreach ($amtCandidates  as $c) if (in_array($c, $cols, true)) { $amtCol = $c; break; }

      $sel_name = $nameCol ? "COALESCE(`$nameCol`,'') AS name" : "'' AS name";
      $sel_qty  = $qtyCol  ? "COALESCE(`$qtyCol`,1) AS qty" : "1 AS qty";
      $sel_amt  = $amtCol  ? "COALESCE(`$amtCol`,0) AS amount" : "0 AS amount";

      $orderCol = in_array('charge_id', $cols, true) ? 'charge_id' : (in_array('id', $cols, true) ? 'id' : null);
      $orderBy = $orderCol ? "ORDER BY `$orderCol` ASC" : '';

      $sql = "SELECT {$sel_name}, {$sel_qty}, {$sel_amt} FROM `ticket_charges` WHERE `ticket_id` = :tid {$orderBy}";
      $stC = $pdo->prepare($sql);
      $stC->execute([':tid'=>$ticket_id]);
      $items = $stC->fetchAll(PDO::FETCH_ASSOC);
    }
  }

  // 3) totals (optional)
  $totals = ['subtotal_ex'=>0,'tax'=>0,'total_in'=>0];
  if (table_exists($pdo, 'ticket_totals')) {
    $stT = $pdo->prepare("SELECT subtotal_ex, tax, total_in FROM ticket_totals WHERE ticket_id=:tid LIMIT 1");
    $stT->execute([':tid'=>$ticket_id]);
    $t = $stT->fetch(PDO::FETCH_ASSOC);
    if ($t) {
      $totals['subtotal_ex'] = (int)($t['subtotal_ex'] ?? 0);
      $totals['tax']         = (int)($t['tax'] ?? 0);
      $totals['total_in']    = (int)($t['total_in'] ?? 0);
    }
  } else {
    // derive from items if totals table not present
    $sum = 0;
    foreach ($items as $it) $sum += (int)($it['amount'] ?? 0);
    $totals['total_in'] = $sum; // (暫定) 税込/税別が不明ならまず total_in=合計で印字
  }

  // 4) payments (optional)
  $payments = [];
  if (table_exists($pdo, 'ticket_payments')) {
    $stP = $pdo->prepare("
      SELECT method, SUM(amount) AS amt
      FROM ticket_payments
      WHERE ticket_id=:tid
      GROUP BY method
      ORDER BY method
    ");
    $stP->execute([':tid'=>$ticket_id]);
    $payments = $stP->fetchAll(PDO::FETCH_ASSOC);
  }

  return [
    'header'=>$header,
    'items'=>$items,
    'totals'=>$totals,
    'payments'=>$payments,
  ];
}

function table_exists(PDO $pdo, string $table): bool {
  static $cache = [];
  if (array_key_exists($table, $cache)) return $cache[$table];
  $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = :t LIMIT 1");
  $st->execute([':t'=>$table]);
  $cache[$table] = (bool)$st->fetchColumn();
  return $cache[$table];
}

function column_exists(PDO $pdo, string $table, string $column): bool {
  static $cache = [];
  $k = $table . '.' . $column;
  if (array_key_exists($k, $cache)) return $cache[$k];
  $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = :t AND column_name = :c LIMIT 1");
  $st->execute([':t'=>$table, ':c'=>$column]);
  $cache[$k] = (bool)$st->fetchColumn();
  return $cache[$k];
}