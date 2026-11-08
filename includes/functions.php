<?php
/**
 * functions.php — shared helper functions used across the application.
 *
 * Include this near the top of every page (usually via includes/header.php).
 * It pulls in the database connection, starts the session, and provides
 * small helpers for authentication, security, money, settings and billing.
 */

require_once __DIR__ . '/../db.php';

// Start the session once, with sensible cookie protection.
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,          // session cookie (expires when browser closes)
        'path'     => '/',
        'httponly' => true,       // JS cannot read the cookie
        'samesite' => 'Lax',
    ]);
    session_start();
}

/* --------------------------------------------------------------------------
 * Authentication
 * ------------------------------------------------------------------------ */

/**
 * Stop here unless the visitor is logged in. Call on every protected page.
 *
 * Also validates that the session's user still exists in the database. A stale
 * session (e.g. after a backup restore or a manual database change) would
 * otherwise pass the login check and then fail later with a foreign-key error
 * when a bill is saved (created_by references a deleted user). We log out
 * cleanly instead.
 */
function require_login(): void
{
    static $validated = false;

    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    if (!$validated) {
        $validated = true;

        $exists = true;
        try {
            $stmt = db()->prepare('SELECT id FROM users WHERE id = ?');
            $stmt->execute([(int)$_SESSION['user_id']]);
            $exists = (bool)$stmt->fetch();
        } catch (Throwable $e) {
            // Database not reachable — let the page fail on its own elsewhere.
            $exists = true;
        }

        if (!$exists) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_regenerate_id(true);
            }
            $_SESSION['flash'][] = [
                'type'    => 'warning',
                'message' => 'Your session is no longer valid. Please log in again.',
            ];
            header('Location: login.php');
            exit;
        }
    }
}

/**
 * Send logged-in users away from the login page back to the POS.
 */
function redirect_if_logged_in(): void
{
    if (!empty($_SESSION['user_id'])) {
        header('Location: index.php');
        exit;
    }
}

function current_user_id(): int
{
    return (int)($_SESSION['user_id'] ?? 0);
}

function current_user_name(): string
{
    return $_SESSION['user_name'] ?? 'User';
}

/* --------------------------------------------------------------------------
 * CSRF protection
 * ------------------------------------------------------------------------ */

/**
 * Return the current CSRF token, creating it if needed.
 */
function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Render a hidden CSRF input for use inside a <form>.
 */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the CSRF token sent in a POST request. Exits on failure.
 */
function verify_csrf(): void
{
    $sent = $_POST['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        exit('Invalid or expired security token. Please go back and try again.');
    }
}

/**
 * Verify a CSRF token supplied inside a JSON body (used by the POS screen).
 */
function verify_csrf_json(array $data): void
{
    $sent = $data['csrf_token'] ?? '';
    if (!is_string($sent) || !hash_equals($_SESSION['csrf_token'] ?? '', $sent)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Invalid or expired security token. Please refresh the page.']);
        exit;
    }
}

/* --------------------------------------------------------------------------
 * Output helpers
 * ------------------------------------------------------------------------ */

/**
 * Escape a value for safe output inside HTML.
 */
function e(?string $value): string
{
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

/**
 * Redirect to another page and stop.
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * Read and decode a JSON request body (used by fetch() calls).
 */
function read_json_body(): array
{
    $raw  = file_get_contents('php://input');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}

/* --------------------------------------------------------------------------
 * Flash messages (one-time success/error notices)
 * ------------------------------------------------------------------------ */

function flash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function flash_render(): string
{
    $html = '';
    if (!empty($_SESSION['flash']) && is_array($_SESSION['flash'])) {
        foreach ($_SESSION['flash'] as $f) {
            $html .= '<div class="flash flash--' . e($f['type']) . '">' . e($f['message']) . '</div>';
        }
        unset($_SESSION['flash']);
    }
    return $html;
}

/* --------------------------------------------------------------------------
 * Money and discount helpers
 * ------------------------------------------------------------------------ */

/**
 * Format an amount for display, e.g. "PKR 1,400.00".
 */
function money($amount): string
{
    return APP_CURRENCY . ' ' . number_format((float)$amount, 2, '.', ',');
}

/**
 * Format an amount as a plain number (no currency symbol), e.g. "1400.00".
 */
function money_plain($amount): string
{
    return number_format((float)$amount, 2, '.', '');
}

/**
 * Human-readable discount label for history rows, e.g. "10%" or "PKR 100.00".
 */
function discount_label(string $type, $value): string
{
    if ($type === 'percent') {
        return rtrim(rtrim(number_format((float)$value, 2, '.', ''), '0'), '.') . '%';
    }
    return money($value);
}

/**
 * Calculate the PKR amount subtracted by a discount.
 * type is 'fixed' (PKR) or 'percent'. Returns the raw amount; callers must
 * reject it if it exceeds the subtotal (the total can never go negative).
 */
function compute_discount_amount($subtotal, string $type, $value): float
{
    $subtotal = (float)$subtotal;
    $value    = (float)$value;

    $amount = $type === 'percent' ? $subtotal * ($value / 100) : $value;
    return round($amount, 2);
}

/**
 * Work out a bill's payment status from its total and amount paid.
 */
function payment_status_from($total, $paid): string
{
    if ((float)$paid >= (float)$total - 0.005) {
        return 'paid';
    }
    if ((float)$paid > 0) {
        return 'partial';
    }
    return 'unpaid';
}

/* --------------------------------------------------------------------------
 * Settings (key/value rows in the settings table)
 * ------------------------------------------------------------------------ */

/**
 * Read a setting value, falling back to $default. Results are cached.
 */
function get_setting(string $key, string $default = ''): string
{
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        foreach (db()->query('SELECT setting_key, setting_value FROM settings') as $row) {
            $cache[$row['setting_key']] = (string)$row['setting_value'];
        }
    }
    return $cache[$key] ?? $default;
}

/**
 * Save (insert or update) a single setting value.
 */
function set_setting(string $key, string $value): void
{
    $stmt = db()->prepare(
        'INSERT INTO settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = ?'
    );
    $stmt->execute([$key, $value, $value]);
}

/* --------------------------------------------------------------------------
 * Bill numbering
 * ------------------------------------------------------------------------ */

/**
 * Build a unique, readable bill number from the bill's auto-increment id.
 * Format: INV-YYYYMMDD-0001. Because it embeds the primary key, it can never
 * duplicate.
 */
function generate_bill_number(int $id): string
{
    return 'INV-' . date('Ymd') . '-' . str_pad((string)$id, 4, '0', STR_PAD_LEFT);
}

/* --------------------------------------------------------------------------
 * Product identifiers (SKU and barcode)
 * ------------------------------------------------------------------------ */

/**
 * EAN-13 check digit for a 12-digit string. Returns a single digit 0–9.
 */
function ean13_check_digit(string $digits): int
{
    $sum = 0;
    for ($i = 0; $i < 12; $i++) {
        $d = (int)$digits[$i];
        $sum += ($i % 2 === 0) ? $d : $d * 3;
    }
    return (10 - ($sum % 10)) % 10;
}

/**
 * Generate a unique 13-digit EAN-13 barcode value (896 prefix + 9 random
 * digits + check digit). These are internal codes for this shop's labels, not
 * GS1-registered numbers.
 */
function generate_barcode(PDO $pdo): string
{
    do {
        $digits = '896' . str_pad((string)random_int(0, 999999999), 9, '0', STR_PAD_LEFT);
        $code   = $digits . ean13_check_digit($digits);
        $stmt   = $pdo->prepare('SELECT COUNT(*) FROM products WHERE barcode = ?');
        $stmt->execute([$code]);
        $taken  = (int)$stmt->fetchColumn() > 0;
    } while ($taken);
    return $code;
}

/**
 * Generate a unique, readable SKU (e.g. "SKU-9A3F1C2B") for a product added
 * without a manual SKU.
 */
function generate_sku(PDO $pdo): string
{
    do {
        $sku   = 'SKU-' . strtoupper(bin2hex(random_bytes(4)));
        $stmt  = $pdo->prepare('SELECT COUNT(*) FROM products WHERE sku = ?');
        $stmt->execute([$sku]);
        $taken = (int)$stmt->fetchColumn() > 0;
    } while ($taken);
    return $sku;
}

/* --------------------------------------------------------------------------
 * Bill saving
 * ------------------------------------------------------------------------ */

/**
 * Create a bill from the POS payload, in a single database transaction.
 *
 * Expected $lines entries:
 *   ['kind' => 'product'|'service'|'custom', 'id' => int|null,
 *    'name' => string (custom only), 'qty' => int, 'price' => number]
 *
 * Returns ['ok' => true, 'bill_id' => int] or ['ok' => false, 'error' => msg].
 * All totals are recalculated here on the server — client numbers are never
 * trusted for the arithmetic, only for the individual line prices (which the
 * shopkeeper is allowed to override).
 */
function create_bill(array $lines, string $discountType, float $discountValue, float $amountPaid): array
{
    $lines = array_values($lines);

    // Basic sanity checks that need no database access.
    if (count($lines) === 0) {
        return ['ok' => false, 'error' => 'Add at least one item before saving the bill.'];
    }
    if (!in_array($discountType, ['fixed', 'percent'], true)) {
        return ['ok' => false, 'error' => 'Invalid discount type.'];
    }
    if ($discountValue < 0 || $amountPaid < 0) {
        return ['ok' => false, 'error' => 'Discount and amount received must be zero or more.'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $subtotal = 0.0;
        $resolved = [];   // normalised lines ready to insert
        $stockUses = [];  // product_id => quantity sold (for stock update)

        $productSql = 'SELECT name, selling_price, is_active, stock_quantity
                       FROM products WHERE id = ? FOR UPDATE';
        $serviceSql = 'SELECT name, price, is_active FROM services WHERE id = ?';

        foreach ($lines as $line) {
            $kind  = (string)($line['kind'] ?? '');
            $qty   = (int)($line['qty'] ?? 0);
            $price = (float)($line['price'] ?? 0);

            if ($qty < 1) {
                $qty = 1;
            }
            if ($price < 0) {
                throw new RuntimeException('Prices cannot be negative.');
            }

            if ($kind === 'product') {
                $id   = (int)($line['id'] ?? 0);
                $stmt = $pdo->prepare($productSql);
                $stmt->execute([$id]);
                $p = $stmt->fetch();

                if (!$p) {
                    throw new RuntimeException('A selected product no longer exists. Please refresh the page.');
                }
                if ((int)$p['is_active'] !== 1) {
                    throw new RuntimeException('Product "' . $p['name'] . '" is inactive and cannot be sold.');
                }
                $newStock = (int)$p['stock_quantity'] - $qty;
                if ($newStock < 0 && get_setting('allow_negative_stock', '0') !== '1') {
                    throw new RuntimeException(
                        'Not enough stock for "' . $p['name'] . '" (available: ' . (int)$p['stock_quantity'] . ').'
                    );
                }

                $resolved[] = [
                    'type'       => 'product',
                    'product_id' => $id,
                    'service_id' => null,
                    'name'       => $p['name'],
                    'qty'        => $qty,
                    'price'      => $price,
                ];
                $stockUses[$id] = ($stockUses[$id] ?? 0) + $qty;

            } elseif ($kind === 'service') {
                $id   = (int)($line['id'] ?? 0);
                $stmt = $pdo->prepare($serviceSql);
                $stmt->execute([$id]);
                $s = $stmt->fetch();

                if (!$s) {
                    throw new RuntimeException('A selected service no longer exists. Please refresh the page.');
                }
                if ((int)$s['is_active'] !== 1) {
                    throw new RuntimeException('Service "' . $s['name'] . '" is inactive and cannot be used.');
                }
                // A variable-price service (blank or 0 configured price) must
                // have a valid price entered at the point of sale.
                $configuredPrice = ($s['price'] === null) ? 0.0 : (float)$s['price'];
                if ($configuredPrice <= 0 && $price <= 0) {
                    throw new RuntimeException('Please enter a price for "' . $s['name'] . '".');
                }

                $resolved[] = [
                    'type'       => 'service',
                    'product_id' => null,
                    'service_id' => $id,
                    'name'       => $s['name'],
                    'qty'        => $qty,
                    'price'      => $price,
                ];

            } elseif ($kind === 'custom') {
                $name = trim((string)($line['name'] ?? ''));
                if ($name === '') {
                    $name = 'Item';
                }
                $resolved[] = [
                    'type'       => 'custom',
                    'product_id' => null,
                    'service_id' => null,
                    'name'       => mb_substr($name, 0, 160),
                    'qty'        => $qty,
                    'price'      => $price,
                ];

            } else {
                throw new RuntimeException('Unknown item type in the bill.');
            }
        }

        // Recalculate line totals and the subtotal here on the server.
        foreach ($resolved as &$r) {
            $r['line_total'] = round($r['qty'] * $r['price'], 2);
            $subtotal += $r['line_total'];
        }
        unset($r);
        $subtotal = round($subtotal, 2);

        // Discount: fixed PKR amount or percentage of the subtotal.
        $discountAmount = compute_discount_amount($subtotal, $discountType, $discountValue);
        if ($discountAmount > $subtotal + 0.005) {
            throw new RuntimeException('The discount cannot be greater than the subtotal.');
        }

        $total   = round($subtotal - $discountAmount, 2);
        $balance = round($total - $amountPaid, 2); // positive = due, negative = change
        $status  = payment_status_from($total, $amountPaid);

        // Insert the bill header with a temporary NULL bill number, then stamp
        // the final unique number from the new primary key.
        $stmt = $pdo->prepare(
            'INSERT INTO bills
                (bill_number, subtotal, discount_type, discount_value, discount_amount,
                 total, amount_paid, balance, payment_status, created_by)
             VALUES (NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $subtotal, $discountType, $discountValue, $discountAmount,
            $total, $amountPaid, $balance, $status, current_user_id(),
        ]);

        $billId     = (int)$pdo->lastInsertId();
        $billNumber = generate_bill_number($billId);
        $pdo->prepare('UPDATE bills SET bill_number = ? WHERE id = ?')->execute([$billNumber, $billId]);

        // Save the line items (name and unit price are snapshots).
        $itemStmt = $pdo->prepare(
            'INSERT INTO bill_items
                (bill_id, item_type, product_id, service_id, item_name, quantity, unit_price, line_total)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        foreach ($resolved as $r) {
            $itemStmt->execute([
                $billId, $r['type'], $r['product_id'], $r['service_id'],
                $r['name'], $r['qty'], $r['price'], $r['line_total'],
            ]);
        }

        // Reduce stock for each product sold.
        $stockStmt = $pdo->prepare('UPDATE products SET stock_quantity = stock_quantity - ? WHERE id = ?');
        foreach ($stockUses as $productId => $sold) {
            $stockStmt->execute([$sold, $productId]);
        }

        $pdo->commit();
        return ['ok' => true, 'bill_id' => $billId, 'bill_number' => $billNumber];

    } catch (RuntimeException $e) {
        // Expected, user-facing validation error.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];

    } catch (Throwable $e) {
        // Unexpected error: roll back and keep details private.
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not save the bill. Please try again.'];
    }
}

/**
 * Update only the discount on an existing bill, recalculating the total,
 * balance and payment status. Preserves the original bill number and items,
 * and records the change in bill_edit_history.
 */
function update_bill_discount(int $billId, string $discountType, float $discountValue): array
{
    if (!in_array($discountType, ['fixed', 'percent'], true)) {
        return ['ok' => false, 'error' => 'Invalid discount type.'];
    }
    if ($discountValue < 0) {
        return ['ok' => false, 'error' => 'Discount must be zero or more.'];
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $stmt = $pdo->prepare('SELECT * FROM bills WHERE id = ? FOR UPDATE');
        $stmt->execute([$billId]);
        $bill = $stmt->fetch();

        if (!$bill) {
            throw new RuntimeException('Bill not found.');
        }

        $subtotal        = (float)$bill['subtotal'];
        $discountAmount  = compute_discount_amount($subtotal, $discountType, $discountValue);
        if ($discountAmount > $subtotal + 0.005) {
            throw new RuntimeException('The discount cannot be greater than the subtotal.');
        }

        $newTotal   = round($subtotal - $discountAmount, 2);
        $amountPaid = (float)$bill['amount_paid'];
        $newBalance = round($newTotal - $amountPaid, 2);
        $newStatus  = payment_status_from($newTotal, $amountPaid);

        // Nothing changed? Skip the write and the history row.
        $changed = ((string)$bill['discount_type'] !== $discountType)
                || ((float)$bill['discount_value'] !== round($discountValue, 2));

        if ($changed) {
            $pdo->prepare(
                'UPDATE bills
                    SET discount_type = ?, discount_value = ?, discount_amount = ?,
                        total = ?, balance = ?, payment_status = ?, updated_by = ?
                  WHERE id = ?'
            )->execute([
                $discountType, $discountValue, $discountAmount,
                $newTotal, $newBalance, $newStatus, current_user_id(), $billId,
            ]);

            // Keep a small, human-readable record of the edit.
            $pdo->prepare(
                'INSERT INTO bill_edit_history
                    (bill_id, user_id, old_discount, new_discount, old_total, new_total)
                 VALUES (?, ?, ?, ?, ?, ?)'
            )->execute([
                $billId,
                current_user_id(),
                discount_label((string)$bill['discount_type'], (float)$bill['discount_value']),
                discount_label($discountType, $discountValue),
                (float)$bill['total'],
                $newTotal,
            ]);
        }

        $pdo->commit();
        return ['ok' => true, 'changed' => $changed];

    } catch (RuntimeException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => $e->getMessage()];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not update the bill. Please try again.'];
    }
}

/* --------------------------------------------------------------------------
 * Database backup and restore
 * ------------------------------------------------------------------------ */

/**
 * Build a complete SQL dump of the configured database (schema + data for
 * every table). Used for the downloadable backup and for the safety backup
 * taken before a restore.
 */
function backup_database_sql(PDO $pdo): string
{
    $out  = "-- Printer Shop POS database backup\n";
    $out .= "-- Database: " . DB_NAME . "\n";
    $out .= "-- Generated: " . date('Y-m-d H:i:s') . "\n";
    $out .= "-- Restore this file from Settings > Backup & Restore.\n\n";
    $out .= "SET NAMES utf8mb4;\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n\n";

    $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        $create = $pdo->query("SHOW CREATE TABLE `$table`")->fetch(PDO::FETCH_NUM);
        $out   .= "DROP TABLE IF EXISTS `$table`;\n";
        $out   .= $create[1] . ";\n\n";

        $rows = $pdo->query("SELECT * FROM `$table`");
        while ($row = $rows->fetch(PDO::FETCH_ASSOC)) {
            $cols = array_keys($row);
            $vals = [];
            foreach ($row as $v) {
                $vals[] = ($v === null) ? 'NULL' : $pdo->quote((string)$v);
            }
            $out .= "INSERT INTO `$table` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(',', $vals) . ");\n";
        }
        $out .= "\n";
    }

    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    return $out;
}

/**
 * Run a previously generated SQL dump against the current database. Uses a
 * dedicated connection with multi-statement execution so the whole file runs
 * as one import.
 */
function restore_database_sql(string $sql): array
{
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;
    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_EMULATE_PREPARES   => true,
        PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
    ];

    try {
        $conn = new PDO($dsn, DB_USER, DB_PASS, $options);
        $conn->exec($sql);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Restore failed. The safety backup was left untouched. Please try again with a valid backup file.'];
    }
}

/**
 * Directory for safety backups. Lives one level above the web root so the
 * files are never downloadable through a browser.
 */
function backup_directory(): string
{
    $projectRoot = dirname(__DIR__);        // .../htdocs/printer-pos
    $outsideWeb  = dirname($projectRoot, 2); // .../xampp
    $dir         = $outsideWeb . DIRECTORY_SEPARATOR . 'printer_pos_backups';

    if (!is_dir($dir)) {
        @mkdir($dir, 0755, true);
    }
    return $dir;
}
