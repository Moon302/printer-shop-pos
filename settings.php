<?php
/**
 * settings.php — practical shop settings plus a Backup & Restore section.
 *
 * Settings: shop name, contact details, receipt width, footer text and the
 * negative-stock toggle (stored as key/value rows).
 *
 * Backup & Restore: download a complete .sql dump, or restore a previously
 * downloaded backup (with a confirmation, validation, and an automatic
 * safety backup taken first — stored outside the web root).
 */

require_once __DIR__ . '/includes/functions.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save_settings');

    // --- Download a full backup -------------------------------------------
    if ($action === 'backup') {
        $sql      = backup_database_sql(db());
        $filename = 'printer-pos-backup-' . date('Ymd-His') . '.sql';
        header('Content-Type: text/plain; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($sql));
        echo $sql;
        exit;
    }

    // --- Restore an uploaded backup ----------------------------------------
    if ($action === 'restore') {
        $error = '';
        $file  = $_FILES['backup_file'] ?? null;

        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $error = 'Please choose a valid backup (.sql) file to restore.';
        } elseif (($file['size'] ?? 0) <= 0 || ($file['size'] ?? 0) > 20 * 1024 * 1024) {
            $error = 'The backup file is empty or too large (maximum 20 MB).';
        } elseif (($_POST['confirm_restore'] ?? '') !== '1') {
            $error = 'Please tick the confirmation box before restoring.';
        } else {
            $sql = (string)file_get_contents($file['tmp_name']);

            if (trim($sql) === '') {
                $error = 'The uploaded file is empty.';
            } elseif (!preg_match('/CREATE\s+TABLE|INSERT\s+INTO/i', $sql)) {
                $error = 'This does not look like a valid backup file.';
            } elseif (preg_match('/\b(DROP|CREATE)\s+DATABASE\b/i', $sql) || preg_match('/^\s*USE\s+/im', $sql)) {
                $error = 'The backup contains database-level statements and was rejected for safety.';
            } else {
                // Take a safety backup first, stored OUTSIDE the web root so it
                // can never be downloaded through a browser.
                $safetyFile = backup_directory() . DIRECTORY_SEPARATOR . 'safety-before-restore-' . date('Ymd-His') . '.sql';
                $safety     = backup_database_sql(db());

                if (@file_put_contents($safetyFile, $safety) === false) {
                    $error = 'Could not write a safety backup, so the restore was cancelled.';
                } else {
                    $result = restore_database_sql($sql);
                    if ($result['ok']) {
                        // Force a fresh login against the restored users table.
                        unset($_SESSION['user_id'], $_SESSION['user_name']);
                        flash('success', 'Database restored successfully. A safety backup was saved. Please log in again.');
                        redirect('login.php');
                    }
                    $error = $result['error'];
                }
            }
        }

        flash('error', $error);
        redirect('settings.php');
    }

    // --- Default: save the settings ----------------------------------------
    $shopName    = trim((string)($_POST['shop_name'] ?? ''));
    $contact     = trim((string)($_POST['contact_number'] ?? ''));
    $address     = trim((string)($_POST['address'] ?? ''));
    $width       = ($_POST['receipt_width'] ?? '80') === '58' ? '58' : '80';
    $footer      = trim((string)($_POST['receipt_footer'] ?? ''));
    $allowNeg    = isset($_POST['allow_negative_stock']) ? '1' : '0';

    if ($shopName === '') {
        $shopName = 'Printer Shop';
    }

    set_setting('shop_name', $shopName);
    set_setting('contact_number', $contact);
    set_setting('address', $address);
    set_setting('receipt_width', $width);
    set_setting('receipt_footer', $footer);
    set_setting('allow_negative_stock', $allowNeg);

    flash('success', 'Settings saved.');
    redirect('settings.php');
}

$pageTitle = 'Settings';
$active    = 'settings';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
  <h1>Settings</h1>
</div>

<form method="post" action="settings.php" class="card" style="max-width:640px">
  <input type="hidden" name="action" value="save_settings">
  <?= csrf_field() ?>

  <div class="form-row">
    <label class="field">
      <span class="field__label">Shop name</span>
      <input type="text" name="shop_name" class="field__input"
             value="<?= e(get_setting('shop_name', 'Printer Shop')) ?>">
    </label>
    <label class="field">
      <span class="field__label">Contact number</span>
      <input type="text" name="contact_number" class="field__input"
             value="<?= e(get_setting('contact_number')) ?>">
    </label>
  </div>

  <label class="field">
    <span class="field__label">Address</span>
    <input type="text" name="address" class="field__input"
           value="<?= e(get_setting('address')) ?>">
  </label>

  <div class="form-row">
    <label class="field">
      <span class="field__label">Receipt width</span>
      <select name="receipt_width" class="field__select">
        <option value="80" <?= get_setting('receipt_width', '80') === '80' ? 'selected' : '' ?>>80 mm</option>
        <option value="58" <?= get_setting('receipt_width', '80') === '58' ? 'selected' : '' ?>>58 mm</option>
      </select>
      <span class="field__hint">Matches the thermal paper roll in the printer.</span>
    </label>
    <label class="field">
      <span class="field__label">Currency display</span>
      <input type="text" class="field__input" value="PKR" disabled>
      <span class="field__hint">Currency is fixed to PKR.</span>
    </label>
  </div>

  <label class="field">
    <span class="field__label">Receipt footer / thank-you message</span>
    <input type="text" name="receipt_footer" class="field__input"
           value="<?= e(get_setting('receipt_footer', 'Thank you for your business!')) ?>">
  </label>

  <label class="field" style="display:flex;align-items:center;gap:8px">
    <input type="checkbox" name="allow_negative_stock" value="1"
           <?= get_setting('allow_negative_stock', '0') === '1' ? 'checked' : '' ?>>
    <span class="field__label" style="margin:0">Allow overselling (permit negative stock)</span>
  </label>

  <div class="modal__actions" style="margin-top:6px">
    <button type="submit" class="btn btn--primary">Save settings</button>
  </div>
</form>

<div class="card" style="max-width:640px;margin-top:20px">
  <h3>Backup &amp; Restore</h3>
  <p style="color:var(--muted);font-size:13px;margin:0 0 16px">
    Download a complete backup of your data (products, services, bills, bill
    items, settings and users), or restore a previously downloaded backup.
  </p>

  <form method="post" action="settings.php" style="margin-bottom:18px">
    <input type="hidden" name="action" value="backup">
    <?= csrf_field() ?>
    <button type="submit" class="btn btn--primary">Download database backup (.sql)</button>
  </form>

  <form method="post" action="settings.php" enctype="multipart/form-data"
        onsubmit="return confirm('Restoring will REPLACE all current data with the chosen backup. Continue?');">
    <input type="hidden" name="action" value="restore">
    <?= csrf_field() ?>

    <label class="field">
      <span class="field__label">Restore from backup file (.sql)</span>
      <input type="file" name="backup_file" accept=".sql,application/sql,text/plain" class="field__input" required>
      <span class="field__hint">A safety backup of the current data is saved automatically before restoring.</span>
    </label>

    <label class="field" style="display:flex;align-items:flex-start;gap:8px">
      <input type="checkbox" name="confirm_restore" value="1" required>
      <span class="field__label" style="margin:0">I understand that restoring will overwrite the current database.</span>
    </label>

    <button type="submit" class="btn btn--danger">Restore backup</button>
  </form>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
