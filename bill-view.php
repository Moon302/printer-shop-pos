<?php
/**
 * bill-view.php — view one bill, edit its discount, and print its thermal
 * receipt. The receipt is the only element that prints (everything else is
 * marked .no-print). Add ?print=1 to open the print dialog automatically.
 */

require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

// ---------------------------------------------------------------------------
// Handle discount edit (keeps the original bill number and items).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_discount') {
    verify_csrf();

    $type  = ($_POST['discount_type'] ?? 'fixed') === 'percent' ? 'percent' : 'fixed';
    $value = (float)($_POST['discount_value'] ?? 0);

    $result = update_bill_discount($id, $type, $value);
    if ($result['ok']) {
        flash('success', !empty($result['changed']) ? 'Discount updated and totals recalculated.' : 'No change was needed.');
    } else {
        flash('error', $result['error']);
    }
    redirect('bill-view.php?id=' . $id);
}

// ---------------------------------------------------------------------------
// Load the bill, its items and its edit history.
// ---------------------------------------------------------------------------
$stmt = $pdo->prepare(
    'SELECT b.*,
            COALESCE(c.display_name, c.username) AS created_name,
            COALESCE(u.display_name, u.username) AS updated_name
       FROM bills b
       LEFT JOIN users c ON c.id = b.created_by
       LEFT JOIN users u ON u.id = b.updated_by
      WHERE b.id = ?'
);
$stmt->execute([$id]);
$bill = $stmt->fetch();

$pageTitle = 'Bill';
$active    = 'bills';

if (!$bill) {
    $bodyClass = '';
    $autoPrint = false;
    require __DIR__ . '/includes/header.php';
    echo '<div class="flash flash--error">Bill not found.</div>';
    echo '<p><a href="bills.php" class="btn btn--ghost">Back to bills</a></p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$itemStmt = $pdo->prepare('SELECT * FROM bill_items WHERE bill_id = ? ORDER BY id');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();

$histStmt = $pdo->prepare(
    'SELECT h.*, COALESCE(u.display_name, u.username) AS user_name
       FROM bill_edit_history h
       LEFT JOIN users u ON u.id = h.user_id
      WHERE h.bill_id = ?
      ORDER BY h.id DESC'
);
$histStmt->execute([$id]);
$history = $histStmt->fetchAll();

$shopName   = get_setting('shop_name', 'Printer Shop');
$contact    = get_setting('contact_number');
$address    = get_setting('address');
$footerText = get_setting('receipt_footer', 'Thank you for your business!');
$width      = get_setting('receipt_width', '80') === '58' ? '58' : '80';

$balance = (float)$bill['balance'];

$bodyClass = 'page--receipt';
$autoPrint = isset($_GET['print']);
require __DIR__ . '/includes/header.php';
?>

<div class="no-print">
  <div class="page-head">
    <div>
      <a href="bills.php">&larr; Back to bills</a>
      <h1>Bill <?= e($bill['bill_number']) ?></h1>
    </div>
    <div class="page-head__actions">
      <button type="button" onclick="window.print()" class="btn btn--gold">Print receipt</button>
    </div>
  </div>

  <div class="bill-layout">
    <div class="card">
      <h3>Edit discount</h3>
      <form method="post" action="bill-view.php?id=<?= $id ?>" class="form-row">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="update_discount">

        <label class="field">
          <span class="field__label">Discount type</span>
          <select name="discount_type" class="field__select">
            <option value="fixed"   <?= $bill['discount_type'] === 'fixed'   ? 'selected' : '' ?>>Fixed (PKR)</option>
            <option value="percent" <?= $bill['discount_type'] === 'percent' ? 'selected' : '' ?>>Percent (%)</option>
          </select>
        </label>
        <label class="field">
          <span class="field__label">Discount value</span>
          <input type="number" name="discount_value" min="0" step="0.01"
                 value="<?= e(rtrim(rtrim(number_format((float)$bill['discount_value'], 2, '.', ''), '0'), '.')) ?>"
                 class="field__input">
        </label>

        <div style="grid-column:1/-1;display:flex;align-items:center;gap:12px">
          <button type="submit" class="btn btn--primary">Update discount</button>
          <span class="field__hint" style="margin:0">
            Current: <?= e(discount_label((string)$bill['discount_type'], (float)$bill['discount_value'])) ?>
            &middot; The bill number and items are preserved.
          </span>
        </div>
      </form>
    </div>

    <div class="card">
      <h3>Payment</h3>
      <div class="bill-meta">
        <div class="bill-meta__item">
          <div class="field__label">Total</div>
          <div class="bill-meta__value"><?= money($bill['total']) ?></div>
        </div>
        <div class="bill-meta__item">
          <div class="field__label">Paid</div>
          <div class="bill-meta__value"><?= money($bill['amount_paid']) ?></div>
        </div>
        <div class="bill-meta__item">
          <div class="field__label">Balance / change</div>
          <div class="bill-meta__value">
            <?php if ($balance > 0.004): ?>
              <span style="color:var(--danger)">Due <?= money($balance) ?></span>
            <?php elseif ($balance < -0.004): ?>
              <span style="color:var(--success)">Change <?= money(-$balance) ?></span>
            <?php else: ?>Settled<?php endif; ?>
          </div>
        </div>
        <div class="bill-meta__item">
          <div class="field__label">Status</div>
          <div class="bill-meta__value">
            <span class="badge badge--<?= e($bill['payment_status']) ?>"><?= e(ucfirst($bill['payment_status'])) ?></span>
          </div>
        </div>
      </div>

      <h3 style="margin-top:16px">Edit history</h3>
      <div class="edit-history">
        <?php if (count($history) === 0): ?>
          <div style="color:var(--muted);font-size:13px">No edits recorded for this bill.</div>
        <?php endif; ?>
        <?php foreach ($history as $h): ?>
          <div class="edit-history__row">
            <strong><?= e($h['user_name']) ?></strong> changed discount
            <strong><?= e($h['old_discount']) ?></strong> &rarr; <strong><?= e($h['new_discount']) ?></strong>
            (total <?= money($h['old_total']) ?> &rarr; <?= money($h['new_total']) ?>)
            <span style="color:var(--muted)">&middot; <?= e(date('d-m-Y h:i A', strtotime($h['created_at']))) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>
</div>

<!-- Receipt (the only thing that prints) -->
<div class="receipt-scroll">
  <div class="receipt receipt--<?= e($width) ?>">
    <header class="receipt__header">
      <div class="receipt__shop"><?= e($shopName) ?></div>
      <?php if ($contact !== '' || $address !== ''): ?>
        <div class="receipt__sub"><?= e($contact) ?><?= ($contact !== '' && $address !== '') ? ' &middot; ' : '' ?><?= e($address) ?></div>
      <?php endif; ?>
    </header>

    <div class="receipt__rule receipt__rule--double"></div>

    <div class="receipt__info">
      <div class="receipt__info-row"><span>Bill</span><span><?= e($bill['bill_number']) ?></span></div>
      <div class="receipt__info-row"><span>Date</span><span><?= e(date('d-m-Y h:i A', strtotime($bill['created_at']))) ?></span></div>
    </div>

    <div class="receipt__rule"></div>

    <div class="receipt__items">
      <?php foreach ($items as $it): ?>
        <div class="receipt__item">
          <div class="receipt__item-line">
            <span class="receipt__item-name"><?= e($it['item_name']) ?></span>
            <span class="receipt__item-total"><?= money_plain($it['line_total']) ?></span>
          </div>
          <div class="receipt__item-sub"><?= (int)$it['quantity'] ?> &times; <?= money_plain($it['unit_price']) ?></div>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="receipt__rule"></div>

    <div class="receipt__totals">
      <div class="receipt__row"><span>Subtotal</span><span><?= money_plain($bill['subtotal']) ?></span></div>
      <div class="receipt__row"><span>Discount</span><span>-<?= money_plain($bill['discount_amount']) ?></span></div>
      <div class="receipt__total">
        <span>Total</span>
        <span><?= money_plain($bill['total']) ?></span>
      </div>
      <div class="receipt__row"><span>Paid</span><span><?= money_plain($bill['amount_paid']) ?></span></div>
      <?php if ($balance > 0.004): ?>
        <div class="receipt__row receipt__row--due"><span>Balance due</span><span><?= money_plain($balance) ?></span></div>
      <?php elseif ($balance < -0.004): ?>
        <div class="receipt__row receipt__row--change"><span>Change</span><span><?= money_plain(-$balance) ?></span></div>
      <?php endif; ?>
    </div>

    <div class="receipt__rule"></div>

    <footer class="receipt__footer">
      <div class="receipt__thanks"><?= e($footerText) ?></div>
    </footer>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
