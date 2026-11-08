<?php
/**
 * reports.php — a simple, date-filtered sales report.
 *
 * Shows totals for the selected period: sales, number of bills, discounts,
 * amount received, and outstanding balance, plus a per-item breakdown of
 * product and service sales.
 */

require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();

$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

// Default to the current calendar month.
if ($from === '') {
    $from = date('Y-m-01');
}
if ($to === '') {
    $to = date('Y-m-d');
}

$fromTs = $from . ' 00:00:00';
$toTs   = $to . ' 23:59:59';

// --- Period summary ----------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT COUNT(*) AS bills,
            COALESCE(SUM(total), 0) AS sales,
            COALESCE(SUM(discount_amount), 0) AS discounts,
            COALESCE(SUM(amount_paid), 0) AS received,
            COALESCE(SUM(CASE WHEN balance > 0 THEN balance ELSE 0 END), 0) AS outstanding
       FROM bills
      WHERE created_at >= ? AND created_at <= ?'
);
$stmt->execute([$fromTs, $toTs]);
$summary = $stmt->fetch();

// --- Item breakdown (product / service / custom) ----------------------------
$breakdownSql =
    'SELECT bi.item_name, SUM(bi.quantity) AS qty, SUM(bi.line_total) AS revenue
       FROM bill_items bi
       JOIN bills b ON b.id = bi.bill_id
      WHERE bi.item_type = ? AND b.created_at >= ? AND b.created_at <= ?
      GROUP BY bi.item_name
      ORDER BY revenue DESC';

$stmt = $pdo->prepare($breakdownSql);

$stmt->execute(['product', $fromTs, $toTs]);
$productSales = $stmt->fetchAll();

$stmt->execute(['service', $fromTs, $toTs]);
$serviceSales = $stmt->fetchAll();

$stmt->execute(['custom', $fromTs, $toTs]);
$customSales = $stmt->fetchAll();

$pageTitle = 'Reports';
$active    = 'reports';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Reports</h1>
</div>

<div class="card" style="margin-bottom:16px">
  <form method="get" action="reports.php" class="form-row form-row--3" style="margin-bottom:0">
    <label class="field" style="margin-bottom:0">
      <span class="field__label">From date</span>
      <input type="date" name="from" value="<?= e($from) ?>" class="field__input">
    </label>
    <label class="field" style="margin-bottom:0">
      <span class="field__label">To date</span>
      <input type="date" name="to" value="<?= e($to) ?>" class="field__input">
    </label>
    <div style="display:flex;gap:8px;align-items:end">
      <button type="submit" class="btn btn--primary">Apply</button>
      <a href="reports.php" class="btn btn--ghost">This month</a>
    </div>
  </form>
</div>

<div class="report-cards">
  <div class="report-card">
    <div class="report-card__label">Total sales</div>
    <div class="report-card__value"><?= money($summary['sales']) ?></div>
  </div>
  <div class="report-card">
    <div class="report-card__label">Bills</div>
    <div class="report-card__value"><?= (int)$summary['bills'] ?></div>
  </div>
  <div class="report-card">
    <div class="report-card__label">Discounts</div>
    <div class="report-card__value"><?= money($summary['discounts']) ?></div>
  </div>
  <div class="report-card">
    <div class="report-card__label">Amount received</div>
    <div class="report-card__value"><?= money($summary['received']) ?></div>
  </div>
  <div class="report-card report-card--accent">
    <div class="report-card__label">Outstanding balance</div>
    <div class="report-card__value"><?= money($summary['outstanding']) ?></div>
  </div>
</div>

<div class="report-grid">
  <div class="card">
    <h3>Product sales</h3>
    <table class="table">
      <thead>
        <tr><th>Product</th><th class="num">Qty sold</th><th class="num">Revenue</th></tr>
      </thead>
      <tbody>
        <?php if (count($productSales) === 0): ?>
          <tr><td colspan="3" style="text-align:center;color:var(--muted)">No product sales in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($productSales as $r): ?>
          <tr>
            <td><?= e($r['item_name']) ?></td>
            <td class="num"><?= (int)$r['qty'] ?></td>
            <td class="num"><?= money($r['revenue']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="card">
    <h3>Service sales</h3>
    <table class="table">
      <thead>
        <tr><th>Service</th><th class="num">Qty</th><th class="num">Revenue</th></tr>
      </thead>
      <tbody>
        <?php if (count($serviceSales) === 0): ?>
          <tr><td colspan="3" style="text-align:center;color:var(--muted)">No service sales in this period.</td></tr>
        <?php endif; ?>
        <?php foreach ($serviceSales as $r): ?>
          <tr>
            <td><?= e($r['item_name']) ?></td>
            <td class="num"><?= (int)$r['qty'] ?></td>
            <td class="num"><?= money($r['revenue']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if (count($customSales) > 0): ?>
  <div class="card" style="margin-top:16px">
    <h3>Other / custom lines</h3>
    <table class="table">
      <thead>
        <tr><th>Item</th><th class="num">Qty</th><th class="num">Revenue</th></tr>
      </thead>
      <tbody>
        <?php foreach ($customSales as $r): ?>
          <tr>
            <td><?= e($r['item_name']) ?></td>
            <td class="num"><?= (int)$r['qty'] ?></td>
            <td class="num"><?= money($r['revenue']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
