<?php
/**
 * barcode.php — printable barcode labels for a product.
 *
 * Shows the shop name, product name, SKU, selling price and a scannable
 * EAN-13 barcode. The shopkeeper can choose how many labels to print; each
 * label prints on its own page so a label printer outputs that many copies.
 * Only the labels print (everything else is .no-print).
 * Add ?qty=N&print=1 to print N labels automatically.
 */

require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo = db();
$id  = (int)($_GET['id'] ?? 0);

$stmt = $pdo->prepare('SELECT * FROM products WHERE id = ?');
$stmt->execute([$id]);
$product = $stmt->fetch();

$pageTitle = 'Barcode label';
$active    = 'products';

if (!$product) {
    $bodyClass = '';
    $autoPrint = false;
    require __DIR__ . '/includes/header.php';
    echo '<div class="flash flash--error">Product not found.</div>';
    echo '<p><a href="products.php" class="btn btn--ghost">Back to products</a></p>';
    require __DIR__ . '/includes/footer.php';
    exit;
}

$shopName = get_setting('shop_name', 'Printer Shop');
$barcode  = $product['barcode'] ?? '';

// Number of labels to print (clamped to a sensible range).
$qty = (int)($_GET['qty'] ?? 1);
if ($qty < 1)   { $qty = 1; }
if ($qty > 100) { $qty = 100; }

$bodyClass = 'page--receipt';
$autoPrint = isset($_GET['print']);
require __DIR__ . '/includes/header.php';
?>

<div class="no-print">
  <div class="page-head">
    <div>
      <a href="products.php">&larr; Back to products</a>
      <h1>Barcode label</h1>
    </div>
    <div class="page-head__actions" style="align-items:center">
      <label style="display:flex;align-items:center;gap:6px;font-size:13px;font-weight:600;color:#4a4242">
        Labels
        <input type="number" id="labelQty" min="1" max="100" value="<?= $qty ?>" class="field__input" style="width:74px;padding:6px 8px">
      </label>
      <button type="button" onclick="printLabels()" class="btn btn--gold">Print labels</button>
    </div>
  </div>
  <p class="field__hint" style="margin:-4px 0 12px">
    Showing <strong><?= (int)$qty ?></strong> label(s) &middot; they print in rows of five.
  </p>
  <?php if ($barcode === '' || $barcode === null): ?>
    <div class="flash flash--warning">This product has no barcode yet. Edit the product to generate one.</div>
  <?php endif; ?>
</div>

<div class="label-grid">
  <?php for ($i = 0; $i < $qty; $i++): ?>
    <div class="barcode-label">
      <div class="barcode-label__shop"><?= e($shopName) ?></div>
      <div class="barcode-label__name"><?= e($product['name']) ?></div>
      <div class="barcode-label__meta">
        SKU: <?= e($product['sku'] ?? '—') ?> &middot; <?= money($product['selling_price']) ?>
      </div>
      <?php if ($barcode !== '' && $barcode !== null): ?>
        <svg class="barcode-label__svg" data-code="<?= e($barcode) ?>" role="img" aria-label="Barcode"></svg>
        <div class="barcode-label__code"><?= e($barcode) ?></div>
      <?php endif; ?>
    </div>
  <?php endfor; ?>
</div>

<script>
  /* Render every EAN-13 barcode as an SVG (no external library required). */
  (function () {
    var L = ['0001101','0011001','0010011','0111101','0100011','0110001','0101111','0111011','0110111','0001011'];
    var G = ['0100111','0110011','0011011','0100001','0011101','0111001','0000101','0010001','0001001','0010111'];
    var R = ['1110010','1100110','1101100','1000010','1011100','1001110','1010000','1000100','1001000','1110100'];
    var parity = ['LLLLLL','LLGLGG','LLGGLG','LLGGGL','LGLLGG','LGGLLG','LGGGLL','LGLGLG','LGLGGL','LGGLGL'];

    function encode(code) {
      var first = parseInt(code.charAt(0), 10);
      var pattern = parity[first];
      var bits = '101'; // start guard
      for (var i = 1; i <= 6; i++) {
        var d = parseInt(code.charAt(i), 10);
        bits += (pattern.charAt(i - 1) === 'L') ? L[d] : G[d];
      }
      bits += '01010'; // middle guard
      for (var j = 7; j <= 12; j++) {
        bits += R[parseInt(code.charAt(j), 10)];
      }
      bits += '101'; // end guard
      return bits;
    }

    document.querySelectorAll('.barcode-label__svg[data-code]').forEach(function (el) {
      var code = el.getAttribute('data-code');
      if (!code || code.length !== 13) { return; }
      var bits = encode(code);
      var bars = '';
      for (var i = 0; i < bits.length; i++) {
        if (bits.charAt(i) === '1') {
          bars += '<rect x="' + i + '" y="0" width="1" height="1" fill="#000"/>';
        }
      }
      el.setAttribute('viewBox', '0 0 ' + bits.length + ' 1');
      el.setAttribute('preserveAspectRatio', 'none');
      el.setAttribute('shape-rendering', 'crispEdges');
      el.setAttribute('width', String(bits.length * 2));
      el.innerHTML = bars;
    });
  })();

  // Read the chosen quantity, reload with that many labels and auto-print.
  function printLabels() {
    var qty = document.getElementById('labelQty').value || '1';
    window.location = 'barcode.php?id=<?= (int)$product['id'] ?>&qty=' + encodeURIComponent(qty) + '&print=1';
  }
</script>

<?php require __DIR__ . '/includes/footer.php'; ?>
