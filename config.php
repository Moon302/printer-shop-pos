<?php
/**
 * config.php — site-wide configuration.
 *
 * Holds the database credentials and a few application-wide constants.
 * Because this is a PHP file, its contents are never shown to a browser
 * visitor. It must only ever be included by other PHP files — never opened
 * directly in the browser (the guard below prevents that).
 *
 * IMPORTANT: after installing, update DB_USER / DB_PASS to match your own
 * MySQL account. Do NOT use a blank password if your MySQL root account has
 * one set.
 */

// The guard stops anyone from loading this file directly in a browser.
if (!defined('PRINTER_POS')) {
    exit('Direct access is not allowed.');
}

// ---------------------------------------------------------------------------
// Database connection
// ---------------------------------------------------------------------------
define('DB_HOST', '127.0.0.1');      // MySQL/MariaDB host (XAMPP default)
define('DB_NAME', 'printer_pos');     // Database created from database/database.sql
define('DB_USER', 'root');            // MySQL user
define('DB_PASS', '');                // MySQL password (change if yours has one)
define('DB_CHARSET', 'utf8mb4');

// ---------------------------------------------------------------------------
// Application settings
// ---------------------------------------------------------------------------
define('APP_TIMEZONE', 'Asia/Karachi');  // Consistent date/time zone for the shop
define('APP_CURRENCY', 'PKR');           // Currency label shown with every amount
define('APP_VERSION', '1.0.0');
