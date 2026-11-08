<?php
/**
 * products.php — add, edit and search sellable products (printers, parts,
 * ink, toner, etc.). Products are never deleted once referenced by a bill;
 * they are marked inactive instead.
 */

require_once __DIR__ . '/includes/functions.php';
require_login();

$pdo   = db();
$error = '';

// ---------------------------------------------------------------------------
// Handle save (create or update).
// ---------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
    verify_csrf();

    $id      = (int)($_POST['id'] ?? 0);
    $name    = trim((string)($_POST['name'] ?? ''));
    $sku     = trim((string)($_POST['sku'] ?? ''));
    $cost    = (float)($_POST['cost_price'] ?? 0);
    $selling = (float)($_POST['selling_price'] ?? 0);
    $stock   = (int)($_POST['stock_quantity'] ?? 0);
    $active  = isset($_POST['is_active']) ? 1 : 0;

    // An empty SKU becomes NULL below; on add it will be auto-generated.
    $sku = $sku === '' ? null : $sku;

    if ($name === '') {
        $error = 'Please enter a product name.';
    } elseif ($cost < 0 || $selling < 0) {
        $error = 'Cost and selling price cannot be negative.';
    } else {
        // When editing, fetch the current identifiers so they are preserved.
        $existingBarcode = null;
        if ($id > 0) {
            $stmt = $pdo->prepare('SELECT sku, barcode FROM products WHERE id = ?');
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            $existingBarcode = $existing['barcode'] ?? null;
            // If the SKU field was left blank on edit, keep the existing SKU.
            if ($sku === null && $existing && $existing['sku'] !== null) {
                $sku = $existing['sku'];
            }
        }

        // Auto-generate a unique SKU when a product is added without one.
        if ($sku === null) {
            $sku = generate_sku($pdo);
        }

        // Auto-generate a unique barcode if the product has none yet.
        $barcode = ($existingBarcode !== null && $existingBarcode !== '')
            ? $existingBarcode
            : generate_barcode($pdo);

        // Reject a duplicate SKU or barcode (except this same product).
        $dup = $pdo->prepare('SELECT id FROM products WHERE (sku = ? OR barcode = ?) AND id <> ?');
        $dup->execute([$sku, $barcode, $id]);
        if ($dup->fetch()) {
            $error = 'That SKU or barcode is already used by another product.';
        } else {
            if ($id > 0) {
                $pdo->prepare(
                    'UPDATE products
                        SET name = ?, sku = ?, barcode = ?, cost_price = ?, selling_price = ?,
                            stock_quantity = ?, is_active = ?
                      WHERE id = ?'
                )->execute([$name, $sku, $barcode, $cost, $selling, $stock, $active, $id]);
                flash('success', 'Product updated.');
            } else {
                $pdo->prepare(
                    'INSERT INTO products (name, sku, barcode, cost_price, selling_price, stock_quantity, is_active)
                     VALUES (?, ?, ?, ?, ?, ?, ?)'
                )->execute([$name, $sku, $barcode, $cost, $selling, $stock, $active]);
                flash('success', 'Product added.');
            }
            redirect('products.php');
        }
    }
}

// ---------------------------------------------------------------------------
// Load the product list (with optional search).
// ---------------------------------------------------------------------------
$q      = trim((string)($_GET['q'] ?? ''));
$params = [];
$sql    = 'SELECT * FROM products';

if ($q !== '') {
    $sql   .= ' WHERE name LIKE ? OR sku LIKE ?';
    $params = ["%$q%", "%$q%"];
}
$sql .= ' ORDER BY name';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll();

$pageTitle = 'Products';
$active    = 'products';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Products</h1>
  <div class="page-head__actions">
    <button type="button" class="btn btn--primary" data-modal-open="productModal" data-modal-reset="1" data-modal-title="Add product">+ Add product</button>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="flash flash--error"><?= e($error) ?></div>
<?php endif; ?>

<div class="toolbar">
  <form method="get" action="products.php" class="toolbar__search">
    <input type="text" name="q" value="<?= e($q) ?>" class="field__input"
           placeholder="Search by name or SKU…">
  </form>
  <?php if ($q !== ''): ?>
    <a href="products.php" class="btn btn--ghost btn--sm">Clear</a>
  <?php endif; ?>
</div>

<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>SKU</th>
        <th>Barcode</th>
        <th class="num">Cost</th>
        <th class="num">Selling</th>
        <th class="num">Stock</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($products) === 0): ?>
        <tr><td colspan="8" style="text-align:center;color:var(--muted)">No products found.</td></tr>
      <?php endif; ?>
      <?php foreach ($products as $p): ?>
        <tr>
          <td><?= e($p['name']) ?></td>
          <td><?= $p['sku'] !== null ? e($p['sku']) : '—' ?></td>
          <td style="font-family:var(--font-mono)"><?= $p['barcode'] !== null ? e($p['barcode']) : '—' ?></td>
          <td class="num"><?= money_plain($p['cost_price']) ?></td>
          <td class="num"><?= money_plain($p['selling_price']) ?></td>
          <td class="num"><?= (int)$p['stock_quantity'] ?></td>
          <td>
            <?php if ((int)$p['is_active'] === 1): ?>
              <span class="badge badge--active">Active</span>
            <?php else: ?>
              <span class="badge badge--inactive">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right;white-space:nowrap">
            <a href="barcode.php?id=<?= (int)$p['id'] ?>" class="btn btn--ghost btn--sm">Label</a>
            <button type="button" class="btn btn--ghost btn--sm"
                    data-modal-open="productModal"
                    data-edit-target="productModal"
                    data-modal-title="Edit product"
                    data-id="<?= (int)$p['id'] ?>"
                    data-name="<?= e($p['name']) ?>"
                    data-sku="<?= e($p['sku'] ?? '') ?>"
                    data-cost_price="<?= money_plain($p['cost_price']) ?>"
                    data-selling_price="<?= money_plain($p['selling_price']) ?>"
                    data-stock_quantity="<?= (int)$p['stock_quantity'] ?>"
                    data-is_active="<?= (int)$p['is_active'] ?>">Edit</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Add / edit product modal -->
<div class="modal" id="productModal">
  <div class="modal__card">
    <div class="modal__head">
      <h2 data-modal-title>Add product</h2>
      <button type="button" class="modal__close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="post" action="products.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="">

      <div class="form-row">
        <label class="field">
          <span class="field__label">Name</span>
          <input type="text" name="name" class="field__input" required>
        </label>
        <label class="field">
          <span class="field__label">SKU (optional)</span>
          <input type="text" name="sku" class="field__input">
          <span class="field__hint">Leave blank to auto-generate. A barcode is generated automatically.</span>
        </label>
      </div>

      <div class="form-row">
        <label class="field">
          <span class="field__label">Cost price (PKR)</span>
          <input type="number" name="cost_price" min="0" step="0.01" value="0.00" class="field__input">
        </label>
        <label class="field">
          <span class="field__label">Selling price (PKR)</span>
          <input type="number" name="selling_price" min="0" step="0.01" value="0.00" class="field__input">
        </label>
      </div>

      <div class="form-row">
        <label class="field">
          <span class="field__label">Stock quantity</span>
          <input type="number" name="stock_quantity" value="0" step="1" class="field__input">
        </label>
        <label class="field" style="display:flex;align-items:center;gap:8px;padding-top:22px">
          <input type="checkbox" name="is_active" value="1" checked>
          <span class="field__label" style="margin:0">Active (shown on the POS)</span>
        </label>
      </div>

      <div class="modal__actions">
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary">Save product</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
