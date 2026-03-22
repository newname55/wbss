<?php
declare(strict_types=1);

require_once __DIR__ . '/../../app/auth.php';
require_once __DIR__ . '/../../app/db.php';
require_once __DIR__ . '/../../app/store.php';
require_once __DIR__ . '/../../app/layout.php';

require_login();
require_role(['super_user', 'admin', 'manager', 'staff']);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }

function table_exists(PDO $pdo, string $table): bool {
  $t = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $table);
  $sql = "SHOW TABLES LIKE " . $pdo->quote($t);
  return (bool)$pdo->query($sql)->fetchColumn();
}

function table_has_column(PDO $pdo, string $table, string $col): bool {
  $c = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $col);
  $sql = "SHOW COLUMNS FROM `" . str_replace('`', '``', $table) . "` LIKE " . $pdo->quote($c);
  return (bool)$pdo->query($sql)->fetch();
}

function now_jst(): string {
  $dt = new DateTime('now', new DateTimeZone('Asia/Tokyo'));
  return $dt->format('Y-m-d H:i:s');
}

function format_yen(?int $value): string {
  if ($value === null) return '-';
  return '¥' . number_format($value);
}

function format_signed_yen(?int $value): string {
  if ($value === null) return '-';
  $sign = $value > 0 ? '+' : '';
  return $sign . '¥' . number_format($value);
}

function format_pct(?float $value): string {
  if ($value === null) return '-';
  return number_format($value, 1) . '%';
}

function format_dt(?string $value): string {
  $raw = trim((string)$value);
  if ($raw === '') return '-';
  $ts = strtotime($raw);
  if ($ts === false) return $raw;
  return date('Y-m-d H:i', $ts);
}

function parse_price_input($value): ?int {
  $raw = trim((string)$value);
  if ($raw === '') return null;
  $normalized = str_replace([',', '¥', '￥', ' '], '', $raw);
  if (!preg_match('/^\d+(?:\.\d+)?$/', $normalized)) {
    throw new InvalidArgumentException('価格は0以上の数字で入力してください');
  }
  return (int)round((float)$normalized);
}

$store_id = current_store_id();
if ($store_id === null) {
  header('Location: /wbss/public/store_select.php');
  exit;
}
$store_id = (int)$store_id;

$pdo = db();
$canEditPrices = can_edit_master();
$msg = '';
$err = '';

$hasPurchasePrice = table_has_column($pdo, 'stock_products', 'purchase_price_yen');
$hasSellingPrice = table_has_column($pdo, 'stock_products', 'selling_price_yen');
$hasUpdatedAt = table_has_column($pdo, 'stock_products', 'updated_at');
$hasIsActive = table_has_column($pdo, 'stock_products', 'is_active');
$hasItemLocations = table_exists($pdo, 'stock_item_locations') && table_has_column($pdo, 'stock_item_locations', 'qty');
$hasCategories = table_exists($pdo, 'stock_categories') && table_has_column($pdo, 'stock_products', 'category_id');
$hasHistoryTable = table_exists($pdo, 'stock_product_price_history');
$hasHistoryOldPrice = $hasHistoryTable && table_has_column($pdo, 'stock_product_price_history', 'old_price_yen');
$hasHistoryNewPrice = $hasHistoryTable && table_has_column($pdo, 'stock_product_price_history', 'new_price_yen');
$historyReady = $hasHistoryTable && $hasHistoryOldPrice && $hasHistoryNewPrice;
$priceReady = $hasPurchasePrice && $hasSellingPrice;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!$canEditPrices) {
    http_response_code(403);
    exit('Forbidden');
  }

  $action = (string)($_POST['action'] ?? '');
  if ($action === 'update_prices') {
    if (!$priceReady || !$historyReady) {
      $err = '価格管理用のDBカラムが未設定です。先に migration SQL を適用してください。';
    } else {
      try {
        $productId = (int)($_POST['product_id'] ?? 0);
        if ($productId <= 0) {
          throw new InvalidArgumentException('商品IDが不正です');
        }

        $purchasePrice = parse_price_input($_POST['purchase_price_yen'] ?? null);
        $sellingPrice = parse_price_input($_POST['selling_price_yen'] ?? null);
        $note = trim((string)($_POST['price_note'] ?? ''));
        if (mb_strlen($note) > 255) {
          $note = mb_substr($note, 0, 255);
        }

        $st = $pdo->prepare("
          SELECT id, name, purchase_price_yen, selling_price_yen
          FROM stock_products
          WHERE id = ? AND store_id = ?
          LIMIT 1
        ");
        $st->execute([$productId, $store_id]);
        $product = $st->fetch(PDO::FETCH_ASSOC);
        if (!$product) {
          throw new RuntimeException('商品が見つかりません');
        }

        $oldPurchase = ($product['purchase_price_yen'] === null) ? null : (int)$product['purchase_price_yen'];
        $oldSelling = ($product['selling_price_yen'] === null) ? null : (int)$product['selling_price_yen'];

        if ($oldPurchase === $purchasePrice && $oldSelling === $sellingPrice) {
          $msg = '価格の変更はありませんでした';
        } else {
          $pdo->beginTransaction();
          $now = now_jst();

          $updateSql = $hasUpdatedAt
            ? "UPDATE stock_products SET purchase_price_yen = ?, selling_price_yen = ?, updated_at = ? WHERE id = ? AND store_id = ? LIMIT 1"
            : "UPDATE stock_products SET purchase_price_yen = ?, selling_price_yen = ? WHERE id = ? AND store_id = ? LIMIT 1";
          $updateParams = $hasUpdatedAt
            ? [$purchasePrice, $sellingPrice, $now, $productId, $store_id]
            : [$purchasePrice, $sellingPrice, $productId, $store_id];

          $pdo->prepare($updateSql)->execute($updateParams);

          $ins = $pdo->prepare("
            INSERT INTO stock_product_price_history
              (store_id, product_id, price_type, old_price_yen, new_price_yen, note, changed_by, changed_at)
            VALUES
              (?, ?, ?, ?, ?, ?, ?, ?)
          ");

          if ($oldPurchase !== $purchasePrice) {
            $ins->execute([
              $store_id,
              $productId,
              'purchase',
              $oldPurchase,
              $purchasePrice,
              ($note !== '' ? $note : null),
              current_user_id(),
              $now,
            ]);
          }
          if ($oldSelling !== $sellingPrice) {
            $ins->execute([
              $store_id,
              $productId,
              'selling',
              $oldSelling,
              $sellingPrice,
              ($note !== '' ? $note : null),
              current_user_id(),
              $now,
            ]);
          }

          $pdo->commit();
          header(
            'Location: /wbss/public/stock/profit.php?updated=1&q='
            . urlencode((string)($_GET['q'] ?? ''))
            . '&limit=' . rawurlencode((string)($_GET['limit'] ?? '120'))
          );
          exit;
        }
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $err = $e->getMessage();
      }
    }
  }
}

if ((int)($_GET['updated'] ?? 0) === 1 && $msg === '' && $err === '') {
  $msg = '価格を更新しました';
}

$q = trim((string)($_GET['q'] ?? ''));
$limit = (int)($_GET['limit'] ?? 120);
if ($limit < 20) $limit = 20;
if ($limit > 300) $limit = 300;

$where = ["p.store_id = ?"];
$params = [$store_id];
if ($hasIsActive) {
  $where[] = "p.is_active = 1";
}
if ($q !== '') {
  $where[] = "(p.name LIKE ? OR p.barcode LIKE ?)";
  $params[] = '%' . $q . '%';
  $params[] = '%' . $q . '%';
}

$purchaseCol = $hasPurchasePrice ? 'p.purchase_price_yen' : 'NULL AS purchase_price_yen';
$sellingCol = $hasSellingPrice ? 'p.selling_price_yen' : 'NULL AS selling_price_yen';
$updatedAtCol = $hasUpdatedAt ? 'p.updated_at' : 'NULL AS updated_at';
$categoryCol = $hasCategories ? 'c.name AS category_name' : 'NULL AS category_name';
$qtyJoin = $hasItemLocations ? "
  LEFT JOIN (
    SELECT product_id, SUM(qty) AS qty
    FROM stock_item_locations
    WHERE store_id = ?
    GROUP BY product_id
  ) il ON il.product_id = p.id
" : '';
$qtyCol = $hasItemLocations ? 'COALESCE(il.qty, 0) AS qty' : '0 AS qty';

$sql = "
  SELECT
    p.id,
    p.name,
    p.unit,
    p.barcode,
    {$purchaseCol},
    {$sellingCol},
    {$updatedAtCol},
    {$categoryCol},
    {$qtyCol}
  FROM stock_products p
  " . ($hasCategories ? "LEFT JOIN stock_categories c ON c.id = p.category_id" : "") . "
  {$qtyJoin}
  WHERE " . implode(' AND ', $where) . "
  ORDER BY p.name ASC, p.id DESC
  LIMIT {$limit}
";

$st = $pdo->prepare($sql);
$bind = $hasItemLocations ? array_merge([$store_id], $params) : $params;
$st->execute($bind);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$productIds = [];
$totals = [
  'qty' => 0,
  'cost' => 0,
  'sales' => 0,
  'gross' => 0,
  'priced' => 0,
];

foreach ($rows as &$row) {
  $row['qty'] = (int)($row['qty'] ?? 0);
  $row['purchase_price_yen'] = $row['purchase_price_yen'] === null ? null : (int)$row['purchase_price_yen'];
  $row['selling_price_yen'] = $row['selling_price_yen'] === null ? null : (int)$row['selling_price_yen'];

  $unitGross = null;
  if ($row['purchase_price_yen'] !== null && $row['selling_price_yen'] !== null) {
    $unitGross = $row['selling_price_yen'] - $row['purchase_price_yen'];
    $totals['priced']++;
    $totals['cost'] += $row['purchase_price_yen'] * $row['qty'];
    $totals['sales'] += $row['selling_price_yen'] * $row['qty'];
    $totals['gross'] += $unitGross * $row['qty'];
  }
  $row['unit_gross_yen'] = $unitGross;
  $row['gross_margin_pct'] = ($unitGross !== null && $row['selling_price_yen'] > 0)
    ? ($unitGross / $row['selling_price_yen']) * 100
    : null;
  $row['stock_gross_yen'] = ($unitGross !== null) ? $unitGross * $row['qty'] : null;
  $row['stock_cost_yen'] = ($row['purchase_price_yen'] !== null) ? $row['purchase_price_yen'] * $row['qty'] : null;
  $row['stock_sales_yen'] = ($row['selling_price_yen'] !== null) ? $row['selling_price_yen'] * $row['qty'] : null;

  $totals['qty'] += $row['qty'];
  $productIds[] = (int)$row['id'];
}
unset($row);

$historyByProduct = [];
$recentChanges = [];

if ($historyReady && $productIds) {
  $ph = implode(',', array_fill(0, count($productIds), '?'));
  $historySql = "
    SELECT
      h.id,
      h.product_id,
      h.price_type,
      h.old_price_yen,
      h.new_price_yen,
      h.note,
      h.changed_at,
      u.display_name
    FROM stock_product_price_history h
    LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.store_id = ?
      AND h.product_id IN ({$ph})
    ORDER BY h.changed_at DESC, h.id DESC
  ";
  $hs = $pdo->prepare($historySql);
  $hs->execute(array_merge([$store_id], $productIds));
  $historyRows = $hs->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($historyRows as $historyRow) {
    $productId = (int)$historyRow['product_id'];
    $type = (string)$historyRow['price_type'];
    if (!isset($historyByProduct[$productId])) $historyByProduct[$productId] = [];
    if (!isset($historyByProduct[$productId][$type])) $historyByProduct[$productId][$type] = [];
    if (count($historyByProduct[$productId][$type]) < 5) {
      $historyByProduct[$productId][$type][] = $historyRow;
    }
  }

  $recentSql = "
    SELECT
      h.id,
      h.product_id,
      h.price_type,
      h.old_price_yen,
      h.new_price_yen,
      h.note,
      h.changed_at,
      p.name AS product_name,
      u.display_name
    FROM stock_product_price_history h
    JOIN stock_products p ON p.id = h.product_id
    LEFT JOIN users u ON u.id = h.changed_by
    WHERE h.store_id = ?
    ORDER BY h.changed_at DESC, h.id DESC
    LIMIT 20
  ";
  $recentSt = $pdo->prepare($recentSql);
  $recentSt->execute([$store_id]);
  $recentChanges = $recentSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

$right = '<a class="btn" href="/wbss/public/stock/index.php">在庫ランチャー</a>';
render_page_start('価格と粗利');
render_header('価格と粗利', [
  'back_href'  => '/wbss/public/stock/index.php',
  'back_label' => '← 在庫',
  'right_html' => $right,
]);
?>
<div class="page">
  <?php if ($msg !== ''): ?><div class="card notice ok"><?= h($msg) ?></div><?php endif; ?>
  <?php if ($err !== ''): ?><div class="card notice ng"><?= h($err) ?></div><?php endif; ?>

  <?php if (!$priceReady || !$historyReady): ?>
    <div class="card setup-card">
      <div class="setup-card__title">価格管理の初期設定がまだです</div>
      <p class="muted">
        商品ごとの現在価格と価格履歴を保存するため、DBに追加カラムが必要です。
        <code>docs/add_stock_price_tracking.sql</code> を適用すると、このページから更新できるようになります。
      </p>
      <div class="setup-card__chips">
        <span class="chip <?= $hasPurchasePrice ? 'chip-ok' : 'chip-ng' ?>">仕入れ価格列 <?= $hasPurchasePrice ? 'OK' : '未設定' ?></span>
        <span class="chip <?= $hasSellingPrice ? 'chip-ok' : 'chip-ng' ?>">販売価格列 <?= $hasSellingPrice ? 'OK' : '未設定' ?></span>
        <span class="chip <?= $historyReady ? 'chip-ok' : 'chip-ng' ?>">価格履歴テーブル <?= $historyReady ? 'OK' : '未設定' ?></span>
      </div>
    </div>
  <?php endif; ?>

  <div class="stock-top">
    <section class="card">
      <div class="section-head">
        <div>
          <h2>利益の見え方</h2>
          <p class="muted">現在在庫 × 現在価格で、粗利の目安をすぐ確認できます。</p>
        </div>
        <span class="role-badge"><?= $canEditPrices ? 'admin編集可' : '閲覧のみ' ?></span>
      </div>
      <div class="kpis">
        <div class="kpi">
          <div class="kpi__label">対象商品</div>
          <div class="kpi__value"><?= number_format(count($rows)) ?>件</div>
          <div class="kpi__sub">価格設定済み <?= number_format($totals['priced']) ?>件</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">現在在庫数</div>
          <div class="kpi__value"><?= number_format($totals['qty']) ?></div>
          <div class="kpi__sub">検索結果ベース</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">在庫原価合計</div>
          <div class="kpi__value"><?= format_yen($totals['cost']) ?></div>
          <div class="kpi__sub">仕入れ価格 × 在庫数</div>
        </div>
        <div class="kpi">
          <div class="kpi__label">想定売上合計</div>
          <div class="kpi__value"><?= format_yen($totals['sales']) ?></div>
          <div class="kpi__sub">販売価格 × 在庫数</div>
        </div>
        <div class="kpi kpi-gross">
          <div class="kpi__label">想定粗利合計</div>
          <div class="kpi__value"><?= format_signed_yen($totals['gross']) ?></div>
          <div class="kpi__sub">販売価格 - 仕入れ価格</div>
        </div>
      </div>
    </section>

    <section class="card">
      <div class="section-head">
        <div>
          <h2>絞り込み</h2>
          <p class="muted">商品名やJANで対象を絞れます。</p>
        </div>
      </div>
      <form method="get" class="filter-form">
        <div>
          <label class="muted">検索</label>
          <input class="btn" style="width:100%;" type="text" name="q" value="<?= h($q) ?>" placeholder="例) 角 / 490...">
        </div>
        <div>
          <label class="muted">件数</label>
          <select class="btn" style="width:100%;" name="limit">
            <?php foreach ([50, 120, 200, 300] as $n): ?>
              <option value="<?= $n ?>" <?= $limit === $n ? 'selected' : '' ?>><?= $n ?>件</option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-actions">
          <button class="btn btn-primary" type="submit">表示</button>
          <a class="btn" href="/wbss/public/stock/profit.php">クリア</a>
        </div>
      </form>
    </section>
  </div>

  <section class="card" style="margin-top:14px;">
    <div class="section-head">
      <div>
        <h2>商品ごとの価格と粗利</h2>
        <p class="muted">履歴は最大5件まで表示しています。</p>
      </div>
    </div>

    <div class="profit-table-wrap">
      <table class="profit-table">
        <thead>
          <tr>
            <th>商品</th>
            <th>在庫</th>
            <th>仕入れ価格</th>
            <th>販売価格</th>
            <th>粗利/個</th>
            <th>粗利率</th>
            <th>在庫粗利</th>
            <th>価格履歴</th>
            <?php if ($canEditPrices): ?><th>更新</th><?php endif; ?>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rows as $row): ?>
            <?php
              $purchaseHistory = $historyByProduct[(int)$row['id']]['purchase'] ?? [];
              $sellingHistory = $historyByProduct[(int)$row['id']]['selling'] ?? [];
            ?>
            <tr>
              <td>
                <div class="prod-name"><?= h((string)$row['name']) ?></div>
                <div class="muted small"><?= h((string)($row['category_name'] ?? '未分類')) ?> / <?= h((string)($row['unit'] ?? '-')) ?></div>
                <?php if ((string)($row['barcode'] ?? '') !== ''): ?>
                  <div class="muted small"><?= h((string)$row['barcode']) ?></div>
                <?php endif; ?>
              </td>
              <td class="num"><?= number_format((int)$row['qty']) ?></td>
              <td class="num"><?= format_yen($row['purchase_price_yen']) ?></td>
              <td class="num"><?= format_yen($row['selling_price_yen']) ?></td>
              <td class="num <?= (($row['unit_gross_yen'] ?? 0) < 0) ? 'neg' : 'pos' ?>"><?= format_signed_yen($row['unit_gross_yen']) ?></td>
              <td class="num"><?= format_pct($row['gross_margin_pct']) ?></td>
              <td class="num <?= (($row['stock_gross_yen'] ?? 0) < 0) ? 'neg' : 'pos' ?>"><?= format_signed_yen($row['stock_gross_yen']) ?></td>
              <td>
                <div class="history-pair">
                  <details>
                    <summary>仕入れ <?= $purchaseHistory ? '(' . count($purchaseHistory) . ')' : '' ?></summary>
                    <div class="history-list">
                      <?php if ($purchaseHistory): ?>
                        <?php foreach ($purchaseHistory as $history): ?>
                          <div class="history-item">
                            <div><?= h(format_dt((string)$history['changed_at'])) ?> / <?= h((string)($history['display_name'] ?? '')) ?></div>
                            <div><?= format_yen($history['old_price_yen'] === null ? null : (int)$history['old_price_yen']) ?> → <?= format_yen($history['new_price_yen'] === null ? null : (int)$history['new_price_yen']) ?></div>
                            <?php if ((string)($history['note'] ?? '') !== ''): ?><div class="muted"><?= h((string)$history['note']) ?></div><?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="muted">履歴なし</div>
                      <?php endif; ?>
                    </div>
                  </details>
                  <details>
                    <summary>販売 <?= $sellingHistory ? '(' . count($sellingHistory) . ')' : '' ?></summary>
                    <div class="history-list">
                      <?php if ($sellingHistory): ?>
                        <?php foreach ($sellingHistory as $history): ?>
                          <div class="history-item">
                            <div><?= h(format_dt((string)$history['changed_at'])) ?> / <?= h((string)($history['display_name'] ?? '')) ?></div>
                            <div><?= format_yen($history['old_price_yen'] === null ? null : (int)$history['old_price_yen']) ?> → <?= format_yen($history['new_price_yen'] === null ? null : (int)$history['new_price_yen']) ?></div>
                            <?php if ((string)($history['note'] ?? '') !== ''): ?><div class="muted"><?= h((string)$history['note']) ?></div><?php endif; ?>
                          </div>
                        <?php endforeach; ?>
                      <?php else: ?>
                        <div class="muted">履歴なし</div>
                      <?php endif; ?>
                    </div>
                  </details>
                </div>
              </td>
              <?php if ($canEditPrices): ?>
                <td>
                  <?php if ($priceReady && $historyReady): ?>
                    <details class="edit-box">
                      <summary>価格更新</summary>
                      <form method="post" class="edit-form">
                        <input type="hidden" name="action" value="update_prices">
                        <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                        <label class="muted">仕入れ価格</label>
                        <input class="btn" type="text" name="purchase_price_yen" value="<?= h((string)($row['purchase_price_yen'] ?? '')) ?>" inputmode="numeric">
                        <label class="muted">販売価格</label>
                        <input class="btn" type="text" name="selling_price_yen" value="<?= h((string)($row['selling_price_yen'] ?? '')) ?>" inputmode="numeric">
                        <label class="muted">変更メモ</label>
                        <input class="btn" type="text" name="price_note" value="" placeholder="例) 仕入先変更 / 値上げ">
                        <button class="btn btn-primary" type="submit">保存</button>
                      </form>
                    </details>
                  <?php else: ?>
                    <span class="muted">DB設定待ち</span>
                  <?php endif; ?>
                </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; ?>

          <?php if (!$rows): ?>
            <tr>
              <td colspan="<?= $canEditPrices ? '9' : '8' ?>" class="muted" style="padding:16px;">該当する商品がありません</td>
            </tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>

    <div class="profit-cards">
      <?php foreach ($rows as $row): ?>
        <?php
          $purchaseHistory = $historyByProduct[(int)$row['id']]['purchase'] ?? [];
          $sellingHistory = $historyByProduct[(int)$row['id']]['selling'] ?? [];
        ?>
        <article class="profit-card">
          <div class="profit-card__head">
            <div>
              <div class="prod-name"><?= h((string)$row['name']) ?></div>
              <div class="muted small"><?= h((string)($row['category_name'] ?? '未分類')) ?> / <?= h((string)($row['unit'] ?? '-')) ?></div>
              <?php if ((string)($row['barcode'] ?? '') !== ''): ?>
                <div class="muted small">JAN: <?= h((string)$row['barcode']) ?></div>
              <?php endif; ?>
            </div>
            <div class="profit-card__stock">在庫 <?= number_format((int)$row['qty']) ?></div>
          </div>

          <div class="profit-card__grid">
            <div class="metric-box">
              <span class="muted">仕入れ価格</span>
              <strong><?= format_yen($row['purchase_price_yen']) ?></strong>
            </div>
            <div class="metric-box">
              <span class="muted">販売価格</span>
              <strong><?= format_yen($row['selling_price_yen']) ?></strong>
            </div>
            <div class="metric-box">
              <span class="muted">粗利/個</span>
              <strong class="<?= (($row['unit_gross_yen'] ?? 0) < 0) ? 'neg' : 'pos' ?>"><?= format_signed_yen($row['unit_gross_yen']) ?></strong>
            </div>
            <div class="metric-box">
              <span class="muted">粗利率</span>
              <strong><?= format_pct($row['gross_margin_pct']) ?></strong>
            </div>
            <div class="metric-box metric-box--wide">
              <span class="muted">在庫粗利</span>
              <strong class="<?= (($row['stock_gross_yen'] ?? 0) < 0) ? 'neg' : 'pos' ?>"><?= format_signed_yen($row['stock_gross_yen']) ?></strong>
            </div>
          </div>

          <div class="profit-card__sections">
            <details class="section-box">
              <summary>仕入れ履歴 <?= $purchaseHistory ? '(' . count($purchaseHistory) . ')' : '' ?></summary>
              <div class="history-list">
                <?php if ($purchaseHistory): ?>
                  <?php foreach ($purchaseHistory as $history): ?>
                    <div class="history-item">
                      <div><?= h(format_dt((string)$history['changed_at'])) ?> / <?= h((string)($history['display_name'] ?? '')) ?></div>
                      <div><?= format_yen($history['old_price_yen'] === null ? null : (int)$history['old_price_yen']) ?> → <?= format_yen($history['new_price_yen'] === null ? null : (int)$history['new_price_yen']) ?></div>
                      <?php if ((string)($history['note'] ?? '') !== ''): ?><div class="muted"><?= h((string)$history['note']) ?></div><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="muted">履歴なし</div>
                <?php endif; ?>
              </div>
            </details>

            <details class="section-box">
              <summary>販売履歴 <?= $sellingHistory ? '(' . count($sellingHistory) . ')' : '' ?></summary>
              <div class="history-list">
                <?php if ($sellingHistory): ?>
                  <?php foreach ($sellingHistory as $history): ?>
                    <div class="history-item">
                      <div><?= h(format_dt((string)$history['changed_at'])) ?> / <?= h((string)($history['display_name'] ?? '')) ?></div>
                      <div><?= format_yen($history['old_price_yen'] === null ? null : (int)$history['old_price_yen']) ?> → <?= format_yen($history['new_price_yen'] === null ? null : (int)$history['new_price_yen']) ?></div>
                      <?php if ((string)($history['note'] ?? '') !== ''): ?><div class="muted"><?= h((string)$history['note']) ?></div><?php endif; ?>
                    </div>
                  <?php endforeach; ?>
                <?php else: ?>
                  <div class="muted">履歴なし</div>
                <?php endif; ?>
              </div>
            </details>

            <?php if ($canEditPrices): ?>
              <details class="section-box">
                <summary>価格更新</summary>
                <?php if ($priceReady && $historyReady): ?>
                  <form method="post" class="edit-form">
                    <input type="hidden" name="action" value="update_prices">
                    <input type="hidden" name="product_id" value="<?= (int)$row['id'] ?>">
                    <label class="muted">仕入れ価格</label>
                    <input class="btn" type="text" name="purchase_price_yen" value="<?= h((string)($row['purchase_price_yen'] ?? '')) ?>" inputmode="numeric">
                    <label class="muted">販売価格</label>
                    <input class="btn" type="text" name="selling_price_yen" value="<?= h((string)($row['selling_price_yen'] ?? '')) ?>" inputmode="numeric">
                    <label class="muted">変更メモ</label>
                    <input class="btn" type="text" name="price_note" value="" placeholder="例) 仕入先変更 / 値上げ">
                    <button class="btn btn-primary" type="submit">保存</button>
                  </form>
                <?php else: ?>
                  <div class="muted" style="padding:0 10px 10px;">DB設定待ち</div>
                <?php endif; ?>
              </details>
            <?php endif; ?>
          </div>
        </article>
      <?php endforeach; ?>

      <?php if (!$rows): ?>
        <div class="muted" style="padding:16px 0;">該当する商品がありません</div>
      <?php endif; ?>
    </div>
  </section>

  <section class="card" style="margin-top:14px;">
    <div class="section-head">
      <div>
        <h2>最近の価格変更</h2>
        <p class="muted">仕入れ値・売価の更新ログです。</p>
      </div>
    </div>

    <?php if ($historyReady && $recentChanges): ?>
      <div class="recent-list">
        <?php foreach ($recentChanges as $change): ?>
          <div class="recent-item">
            <div class="recent-item__main">
              <strong><?= h((string)$change['product_name']) ?></strong>
              <span class="pill lite"><?= ((string)$change['price_type'] === 'purchase') ? '仕入れ' : '販売' ?></span>
            </div>
            <div class="recent-item__meta">
              <?= h(format_dt((string)$change['changed_at'])) ?>
              / <?= h((string)($change['display_name'] ?? '')) ?>
              / <?= format_yen($change['old_price_yen'] === null ? null : (int)$change['old_price_yen']) ?> → <?= format_yen($change['new_price_yen'] === null ? null : (int)$change['new_price_yen']) ?>
            </div>
            <?php if ((string)($change['note'] ?? '') !== ''): ?>
              <div class="muted"><?= h((string)$change['note']) ?></div>
            <?php endif; ?>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <div class="muted">まだ価格変更履歴はありません。</div>
    <?php endif; ?>
  </section>
</div>

<style>
.notice.ok{ border-color:rgba(52,211,153,.35); }
.notice.ng{ border-color:rgba(251,113,133,.45); }
.stock-top{
  display:grid;
  grid-template-columns:1.35fr .85fr;
  gap:14px;
}
.section-head{
  display:flex;
  justify-content:space-between;
  gap:12px;
  align-items:flex-start;
  flex-wrap:wrap;
}
.section-head h2{
  margin:0 0 4px;
  font-size:18px;
}
.role-badge{
  display:inline-flex;
  align-items:center;
  padding:6px 12px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(250,204,21,.12);
  font-weight:900;
}
.kpis{
  display:grid;
  grid-template-columns:repeat(5, minmax(0, 1fr));
  gap:10px;
  margin-top:14px;
}
.kpi{
  padding:14px;
  border-radius:18px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
}
.kpi-gross{
  background:linear-gradient(180deg, rgba(250,204,21,.14), rgba(255,255,255,.03));
  border-color:rgba(250,204,21,.36);
}
.kpi__label{
  font-size:12px;
  color:var(--muted);
}
.kpi__value{
  margin-top:6px;
  font-size:24px;
  font-weight:1000;
}
.kpi__sub{
  margin-top:6px;
  font-size:12px;
  color:var(--muted);
}
.filter-form{
  display:grid;
  gap:10px;
  margin-top:14px;
}
.filter-actions{
  display:flex;
  gap:10px;
  flex-wrap:wrap;
}
.profit-table-wrap{ margin-top:12px; }
.profit-cards{ display:none; margin-top:12px; }
.profit-table{
  width:100%;
  border-collapse:collapse;
  font-size:13px;
  table-layout:fixed;
}
.profit-table th,
.profit-table td{
  padding:10px 8px;
  border-bottom:1px solid var(--line);
  vertical-align:top;
  word-break:break-word;
}
.profit-table th{
  text-align:left;
  color:var(--muted);
  font-size:12px;
}
.prod-name{
  font-weight:1000;
  font-size:14px;
}
.small{
  font-size:12px;
}
.num{
  text-align:right;
  white-space:nowrap;
}
.pos{
  color:#86efac;
}
.neg{
  color:#fda4af;
}
.history-pair{
  display:grid;
  gap:8px;
}
.history-pair details,
.edit-box,
.section-box{
  border:1px solid var(--line);
  border-radius:12px;
  background:rgba(255,255,255,.03);
}
.history-pair summary,
.edit-box summary,
.section-box summary{
  cursor:pointer;
  list-style:none;
  padding:8px 10px;
  font-weight:900;
}
.history-list{
  display:grid;
  gap:8px;
  padding:0 10px 10px;
}
.history-item{
  padding:8px 10px;
  border-radius:10px;
  background:rgba(255,255,255,.04);
}
.edit-form{
  display:grid;
  gap:8px;
  padding:0 10px 10px;
}
.recent-list{
  display:grid;
  gap:10px;
}
.recent-item{
  padding:12px 14px;
  border-radius:16px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
}
.recent-item__main{
  display:flex;
  align-items:center;
  gap:8px;
  flex-wrap:wrap;
}
.recent-item__meta{
  margin-top:6px;
  font-size:13px;
  color:var(--muted);
}
.pill.lite{
  padding:3px 9px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.06);
  font-size:11px;
}
.setup-card__title{
  font-size:18px;
  font-weight:1000;
}
.setup-card__chips{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin-top:10px;
}
.chip{
  display:inline-flex;
  align-items:center;
  padding:6px 12px;
  border-radius:999px;
  border:1px solid var(--line);
}
.chip-ok{ color:#86efac; }
.chip-ng{ color:#fda4af; }
.profit-card{
  padding:14px;
  border-radius:18px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.04);
  margin-top:10px;
}
.profit-card__head{
  display:flex;
  justify-content:space-between;
  gap:10px;
  align-items:flex-start;
}
.profit-card__stock{
  display:inline-flex;
  align-items:center;
  padding:4px 10px;
  border-radius:999px;
  border:1px solid var(--line);
  background:rgba(255,255,255,.06);
  white-space:nowrap;
}
.profit-card__grid{
  display:grid;
  grid-template-columns:repeat(2, minmax(0, 1fr));
  gap:10px;
  margin-top:12px;
}
.metric-box{
  padding:10px 12px;
  border-radius:12px;
  background:rgba(255,255,255,.03);
  display:grid;
  gap:4px;
}
.metric-box--wide{
  grid-column:1/-1;
}
.profit-card__sections{
  display:grid;
  gap:10px;
  margin-top:12px;
}
@media (max-width: 980px){
  .stock-top{
    grid-template-columns:1fr;
  }
  .kpis{
    grid-template-columns:repeat(2, minmax(0, 1fr));
  }
  .profit-table-wrap{ display:none; }
  .profit-cards{ display:block; }
}
@media (max-width: 640px){
  .kpis{
    grid-template-columns:1fr;
  }
  .profit-card__head{
    flex-direction:column;
  }
  .profit-card__grid{
    grid-template-columns:1fr;
  }
}
</style>

<?php render_page_end(); ?>
