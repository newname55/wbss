<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/db.php';

$pdo = db();

// pendingを1件取得
$st = $pdo->prepare("
  SELECT * FROM ticket_receipt_jobs
  WHERE status='pending'
  ORDER BY id
  LIMIT 1
  FOR UPDATE
");

$pdo->beginTransaction();
$st->execute();
$job = $st->fetch(PDO::FETCH_ASSOC);

if(!$job){
  $pdo->rollBack();
  exit("no job\n");
}

// printingへ
$pdo->prepare("
  UPDATE ticket_receipt_jobs
  SET status='printing', try_count=try_count+1
  WHERE id=?
")->execute([$job['id']]);

$pdo->commit();

try {

  $ticket_id = (int)$job['ticket_id'];

  // ★ ここでレシート生成
  $text = generateReceiptText($pdo, $ticket_id);

  // ★ mC-Print2 へ送信
  file_put_contents('/dev/usb/lp0', $text);

  $pdo->prepare("
    UPDATE ticket_receipt_jobs
    SET status='done'
    WHERE id=?
  ")->execute([$job['id']]);

} catch(Throwable $e){

  $pdo->prepare("
    UPDATE ticket_receipt_jobs
    SET status='error', error_message=?
    WHERE id=?
  ")->execute([$e->getMessage(),$job['id']]);

}