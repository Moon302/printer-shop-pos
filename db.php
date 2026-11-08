<?php
/**
 * db.php — shared PDO database connection.
 *
 * Every page gets its database handle by calling db(). The connection is
 * created once and reused for the whole request. Credentials live in
 * config.php so they are in a single, easy-to-find place.
 */

define('PRINTER_POS', true);
require_once __DIR__ . '/config.php';

// Use one consistent timezone everywhere so dates/times never drift.
date_default_timezone_set(APP_TIMEZONE);

/**
 * Return the shared PDO connection, creating it on first use.
 *
 * @return PDO
 */
function db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . DB_CHARSET;

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // throw on SQL errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // fetch as arrays
            PDO::ATTR_EMULATE_PREPARES   => false,                  // real prepared statements
        ];

        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            // Never show raw connection details or passwords to the user.
            http_response_code(500);
            exit('Database connection failed. Please check DB_HOST, DB_NAME, DB_USER '
               . 'and DB_PASS in config.php, then make sure MySQL is running in XAMPP.');
        }
    }

    return $pdo;
}
