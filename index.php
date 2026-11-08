<?php
/**
 * index.php — the main POS screen.
 *
 * This is the most important page. It shows a searchable catalog of products
 * and services on the left and the current bill/cart on the right. The cart is
 * handled in the browser (assets/script.js); when the shopkeeper clicks
 * "Save & Print", the bill is POSTed here as JSON and saved by create_bill()
 * (which recalculates every total on the server).
 */

require_once __DIR__ . '/includes/functions.php';
require_login();

// ---------------------------------------------------------------------------
// JSON save endpoint (called by the cart's fetch()).
// ---------------------------------------------------------------------------
$contentType = $_SERVER['CONTENT_TYPE'] ?? '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && str_contains($contentType, 'application/json')) {
    header('Content-Type: application/json; charset=utf-8');

    $data = read_json_body();
    verify_csrf_json($data);

    // Amount received is now mandatory — never save silently when it is empty.
    if (!array_key_exists('amount_paid', $data) || $data['amount_paid'] === null || $data['amount_paid'] === '') {
        echo json_encode(['ok' => false, 'error' => 'Amount received is required. Enter 0 if nothing was paid.']);
        exit;
    }

    $result = create_bill(
        $data['items'] ?? [],
        (string)($data['discount_type'] ?? 'fixed'),
        (float)($data['discount_value'] ?? 0),
        (float)($data['amount_paid'] ?? 0)
    );

    echo json_encode($result);
    exit;
}

// ---------------------------------------------------------------------------
// Render the POS screen.
// ---------------------------------------------------------------------------
$pdo = db();

// Load every active product and service into a JSON payload for instant,
// client-side search. Shop catalogues are small, so this is fast and avoids a
// round-trip on every keystroke.
$items = [];
foreach ($pdo->query('SELECT id, name, sku, selling_price, stock_quantity FROM products WHERE is_active = 1 ORDER BY name') as $p) {
    $items[] = [
        'kind'  => 'product',
        'id'    => (int)$p['id'],
        'name'  => $p['name'],
        'sku'   => $p['sku'],
        'price' => (float)$p['selling_price'],
        'stock' => (int)$p['stock_quantity'],
    ];
}
foreach ($pdo->query('SELECT id, name, price FROM services WHERE is_active = 1 ORDER BY name') as $s) {
    // A service with a blank or 0 configured price is variable-priced: the
    // shopkeeper enters its price at the point of sale (price = null here).
    $servicePrice = ($s['price'] === null || (float)$s['price'] <= 0) ? null : (float)$s['price'];
    $items[] = [
        'kind'  => 'service',
        'id'    => (int)$s['id'],
        'name'  => $s['name'],
        'sku'   => null,
        'price' => $servicePrice,
        'stock' => null,
    ];
}

$pageTitle = 'New Bill';
$active    = 'pos';
$bodyClass = 'page--pos';
require __DIR__ . '/includes/header.php';
?>

<div id="pos-app" class="pos">

  <!-- Left: searchable catalog -->
  <section class="pos-catalog">
    <div class="pos-search">
      <span class="pos-search__icon" aria-hidden="true">
        <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="7"></circle><line x1="21" y1="21" x2="16.65" y2="16.65"></line></svg>
      </span>
      <input type="text" class="pos-search__input" placeholder="Search products &amp; services… ( / to focus, Enter to add)" autocomplete="off">
    </div>

    <div class="pos-tabs" role="tablist">
      <button type="button" class="pos-tab is-active" data-tab="all">All</button>
      <button type="button" class="pos-tab" data-tab="product">Products</button>
      <button type="button" class="pos-tab" data-tab="service">Services</button>
    </div>

    <div class="pos-grid"></div>
  </section>

  <!-- Right: current bill / cart -->
  <aside class="pos-cart">
    <div class="pos-cart__head">
      <h2>Current Bill</h2>
      <button type="button" id="newBill" class="btn btn--ghost btn--sm">New Bill</button>
    </div>

    <div class="cart-empty">No items yet.<br>Search and click an item to add it.</div>
    <div class="cart-lines"></div>

    <div class="cart-totals">
      <div class="cart-totals__row"><span>Subtotal</span><span id="subtotal">PKR 0.00</span></div>
    </div>

    <div class="cart-discount">
      <div class="cart-discount__toggle">
        <button type="button" class="is-active" data-type="fixed" title="Discount in rupees">PKR</button>
        <button type="button" data-type="percent" title="Discount as a percentage">%</button>
      </div>
      <input type="number" id="discountValue" min="0" step="0.01" value="0" placeholder="Discount" aria-label="Discount">
    </div>

    <div class="cart-totals">
      <div class="cart-totals__row"><span>Discount</span><span id="discountAmount">-PKR 0.00</span></div>
    </div>

    <div class="cart-grand">
      <span class="cart-grand__label">Grand Total</span>
      <span class="cart-grand__amount" id="grandTotal">PKR 0.00</span>
    </div>

    <label class="field">
      <span class="field__label">Amount received (required)</span>
      <input type="number" id="amountReceived" min="0" step="0.01" class="field__input" placeholder="Enter 0 if unpaid" inputmode="decimal" required>
    </label>
    <div class="cart-change" id="changeRow" style="display:none"></div>

    <div class="cart-actions">
      <button type="button" id="addCustom" class="btn btn--ghost">+ Custom line</button>
      <button type="button" id="saveBill" class="btn btn--gold btn--lg">Save &amp; Print</button>
    </div>

    <div class="pos-status" id="posStatus"></div>
  </aside>
</div>

<script>
  window.POS_DATA = <?= json_encode(['csrf' => csrf_token(), 'items' => $items], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
