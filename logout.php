<?php
/**
 * logout.php — ends the session securely and returns to the login page.
 *
 * Clears the session data, expires the session cookie, and destroys the
 * session so it cannot be reused.
 */

require_once __DIR__ . '/includes/functions.php';

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $p['path'],
        $p['domain'],
        $p['secure'],
        $p['httponly']
    );
}

session_destroy();

header('Location: login.php');
exit;
