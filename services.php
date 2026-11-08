<?php
/**
 * services.php — configure services (toner/ink refilling, repairs, cleaning,
 * etc.). A service may have a standard price, or a blank price meaning the
 * amount is entered manually at the point of sale.
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

    $id          = (int)($_POST['id'] ?? 0);
    $name        = trim((string)($_POST['name'] ?? ''));
    $priceRaw    = trim((string)($_POST['price'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $active      = isset($_POST['is_active']) ? 1 : 0;

    // Blank price => NULL => "enter price manually at sale time".
    $price = ($priceRaw === '') ? null : (float)$priceRaw;

    if ($name === '') {
        $error = 'Please enter a service name.';
    } elseif ($price !== null && $price < 0) {
        $error = 'Price cannot be negative.';
    } else {
        if ($id > 0) {
            $pdo->prepare(
                'UPDATE services SET name = ?, price = ?, description = ?, is_active = ? WHERE id = ?'
            )->execute([$name, $price, $description, $active, $id]);
            flash('success', 'Service updated.');
        } else {
            $pdo->prepare(
                'INSERT INTO services (name, price, description, is_active) VALUES (?, ?, ?, ?)'
            )->execute([$name, $price, $description, $active]);
            flash('success', 'Service added.');
        }
        redirect('services.php');
    }
}

// ---------------------------------------------------------------------------
// Load the service list.
// ---------------------------------------------------------------------------
$services = $pdo->query('SELECT * FROM services ORDER BY name')->fetchAll();

$pageTitle = 'Services';
$active    = 'services';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Services</h1>
  <div class="page-head__actions">
    <button type="button" class="btn btn--primary" data-modal-open="serviceModal" data-modal-reset="1" data-modal-title="Add service">+ Add service</button>
  </div>
</div>

<?php if ($error !== ''): ?>
  <div class="flash flash--error"><?= e($error) ?></div>
<?php endif; ?>

<div class="card table-wrap">
  <table class="table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Description</th>
        <th class="num">Standard price</th>
        <th>Status</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php if (count($services) === 0): ?>
        <tr><td colspan="5" style="text-align:center;color:var(--muted)">No services yet. Add one, e.g. “Toner Refilling”.</td></tr>
      <?php endif; ?>
      <?php foreach ($services as $s): ?>
        <tr>
          <td><?= e($s['name']) ?></td>
          <td><?= e($s['description'] ?? '') ?: '—' ?></td>
          <td class="num"><?= $s['price'] === null ? '<em style="color:var(--muted)">Manual</em>' : money($s['price']) ?></td>
          <td>
            <?php if ((int)$s['is_active'] === 1): ?>
              <span class="badge badge--active">Active</span>
            <?php else: ?>
              <span class="badge badge--inactive">Inactive</span>
            <?php endif; ?>
          </td>
          <td style="text-align:right">
            <button type="button" class="btn btn--ghost btn--sm"
                    data-modal-open="serviceModal"
                    data-edit-target="serviceModal"
                    data-modal-title="Edit service"
                    data-id="<?= (int)$s['id'] ?>"
                    data-name="<?= e($s['name']) ?>"
                    data-price="<?= $s['price'] === null ? '' : money_plain($s['price']) ?>"
                    data-description="<?= e($s['description'] ?? '') ?>"
                    data-is_active="<?= (int)$s['is_active'] ?>">Edit</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<!-- Add / edit service modal -->
<div class="modal" id="serviceModal">
  <div class="modal__card">
    <div class="modal__head">
      <h2 data-modal-title>Add service</h2>
      <button type="button" class="modal__close" data-modal-close aria-label="Close">&times;</button>
    </div>
    <form method="post" action="services.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="id" value="">

      <label class="field">
        <span class="field__label">Name</span>
        <input type="text" name="name" class="field__input" required placeholder="e.g. Toner Refilling">
      </label>

      <label class="field">
        <span class="field__label">Standard price (PKR)</span>
        <input type="number" name="price" min="0" step="0.01" class="field__input" placeholder="Leave blank to enter price at sale">
        <span class="field__hint">Leave blank if this service has no fixed price and is priced per job.</span>
      </label>

      <label class="field">
        <span class="field__label">Description (optional)</span>
        <textarea name="description" class="field__textarea" placeholder="Short note about this service"></textarea>
      </label>

      <label class="field" style="display:flex;align-items:center;gap:8px">
        <input type="checkbox" name="is_active" value="1" checked>
        <span class="field__label" style="margin:0">Active (shown on the POS)</span>
      </label>

      <div class="modal__actions">
        <button type="button" class="btn btn--ghost" data-modal-close>Cancel</button>
        <button type="submit" class="btn btn--primary">Save service</button>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
