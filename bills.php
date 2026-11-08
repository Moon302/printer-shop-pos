<?php
/**
 * bills.php — bill history. Search by bill number, filter by date range, and
 * open a bill to view, edit its discount or reprint its receipt.
 */

require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();

$q    = trim((string)($_GET['q'] ?? ''));
$from = trim((string)($_GET['from'] ?? ''));
$to   = trim((string)($_GET['to'] ?? ''));

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;

$where  = [];
$params = [];
if ($q !== '')    { $where[] = 'b.bill_number LIKE ?'; $params[] = "%$q%"; }
if ($from !== '') { $where[] = 'b.created_at >= ?';    $params[] = $from . ' 00:00:00'; }
if ($to !== '')   { $where[] = 'b.created_at <= ?';    $params[] = $to . ' 23:59:59'; }
$whereSql = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM bills b $whereSql");
$countStmt->execute($params);
$totalRows  = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
$page       = min($page, $totalPages);
$offset     = ($page - 1) * $perPage;

$stmt = $pdo->prepare(
    "SELECT b.*, COALESCE(u.display_name, u.username) AS created_name
       FROM bills b
       LEFT JOIN users u ON u.id = b.created_by
       $whereSql
      ORDER BY b.created_at DESC, b.id DESC
      LIMIT $perPage OFFSET $offset"
);
$stmt->execute($params);
$bills = $stmt->fetchAll();

$pageTitle = 'Bills';
$active    = 'bills';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Bill history</h1>
  <div class="page-head__actions">
    <a href="index.php" class="btn btn--primary">+ New bill</a>
  </div>
</div>

<div class="card" style="margin-bottom:14px">
  <form method="get" action="bills.php" class="form-row form-row--3" style="margin-bottom:0">
    <label class="field" style="margin-bottom:0">
      <span class="field__label">Bill number</span>
      <input type="text" name="q" value="<?= e($q) ?>" class="field__input" placeholder="e.g. INV-20261022-0001">
    </label>
    <label class="field" style="margin-bottom:0">
      <span class="field__label">From date</span>
      <input type="date" name="from" value="<?= e($from) ?>" class="field__input">
    </label>
    <label class="field" style="margin-bottom:0">
      <span class="field__label">To date</span>
      <input type="date" name="to" value="<?= e($to) ?>" class="field__input">
    </label>
    <div style="grid-column:1/-1;display:flex;gap:8px;align-items:end">
      <button type="submit" class="btn btn--primary">Search</button>
      <a href="bills.php" class="btn btn--ghost">Clear</a>
      <span style="margin-left:auto;font-size:13px;color:var(--muted)"><?= (int)$totalRows ?> bill(s)</span>
    </div>
  </form>
</div>

<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Bill #</th>
        <th>Date &amp; time</th>
        <th class="num">Total</th>
        <th class="num">Discount</th>
        <th class="num">Paid</th>
        <th class="num">Balance</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($bills) === 0): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--muted)">No bills found.</td></tr>
      <?php endif; ?>

      <?php foreach ($bills as $b): ?>
        <?php $balance = (float)$b['balance']; ?>
        <tr>
          <td style="white-space:nowrap"><a href="bill-view.php?id=<?= (int)$b['id'] ?>"><?= e($b['bill_number']) ?></a></td>
          <td style="white-space:nowrap"><?= e(date('d-m-Y h:i A', strtotime($b['created_at']))) ?></td>
          <td class="num"><strong><?= money($b['total']) ?></strong></td>
          <td class="num"><?= e(discount_label((string)$b['discount_type'], (float)$b['discount_value'])) ?></td>
          <td class="num"><?= money($b['amount_paid']) ?></td>
          <td class="num">
            <?php if ($balance > 0.004): ?>
              <span style="color:var(--danger)">Due <?= money($balance) ?></span>
            <?php elseif ($balance < -0.004): ?>
              <span style="color:var(--success)">Change <?= money(-$balance) ?></span>
            <?php else: ?>—<?php endif; ?>
          </td>
          <td>
            <?php $st = (string)$b['payment_status']; ?>
            <span class="badge badge--<?= e($st) ?>"><?= e(ucfirst($st)) ?></span>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a href="bill-view.php?id=<?= (int)$b['id'] ?>" class="btn btn--ghost btn--sm">View</a>
            <a href="bill-view.php?id=<?= (int)$b['id'] ?>&amp;print=1" class="btn btn--gold btn--sm">Print</a>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php if ($totalPages > 1): ?>
  <div style="display:flex;gap:8px;justify-content:center;margin-top:14px;align-items:center">
    <?php if ($page > 1): ?>
      <a href="bills.php?page=<?= $page - 1 ?>&amp;q=<?= e($q) ?>&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>" class="btn btn--ghost btn--sm">Prev</a>
    <?php endif; ?>
    <span style="font-size:13px;color:var(--muted)">Page <?= $page ?> of <?= $totalPages ?></span>
    <?php if ($page < $totalPages): ?>
      <a href="bills.php?page=<?= $page + 1 ?>&amp;q=<?= e($q) ?>&amp;from=<?= e($from) ?>&amp;to=<?= e($to) ?>" class="btn btn--ghost btn--sm">Next</a>
    <?php endif; ?>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/footer.php'; ?>
