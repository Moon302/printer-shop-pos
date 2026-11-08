<?php
/**
 * login.php — the single sign-in page for the POS.
 *
 * Users log in with their username and password. Passwords are verified with
 * password_verify() against the hash stored in the database.
 */

require_once __DIR__ . '/includes/functions.php';

redirect_if_logged_in();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = 'Please enter your username and password.';
    } else {
        $stmt = db()->prepare(
            'SELECT id, username, password_hash, display_name FROM users WHERE username = ?'
        );
        $stmt->execute([$username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password_hash'])) {
            // New session id on login to help prevent session fixation.
            session_regenerate_id(true);
            $_SESSION['user_id']   = (int)$user['id'];
            $_SESSION['user_name'] = $user['display_name'] !== '' ? $user['display_name'] : $user['username'];
            flash('success', 'Welcome back, ' . $_SESSION['user_name'] . '!');
            redirect('index.php');
        }

        // Same message for a wrong user and a wrong password (no user enumeration).
        $error = 'Invalid username or password.';
    }
}

// Show the "set up first admin" link only while the users table is empty.
$userCount = 0;
try {
    $userCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    $userCount = 0; // handled by the setup hint below if the table is missing
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Log in · Printer Shop POS</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="auth-page">
  <div class="auth">
    <div class="auth__brand">
      <span class="auth__mark">P</span>
      <h1 class="auth__title">Printer Shop POS</h1>
      <p class="auth__subtitle">Fast billing &amp; receipt printing</p>
    </div>

    <?= flash_render() ?>

    <?php if ($error !== ''): ?>
      <div class="flash flash--error"><?= e($error) ?></div>
    <?php endif; ?>

    <form method="post" action="login.php" class="auth__form" autocomplete="off">
      <?= csrf_field() ?>
      <label class="field">
        <span class="field__label">Username</span>
        <input type="text" name="username" class="field__input" required autofocus
               value="<?= e($_POST['username'] ?? '') ?>" placeholder="Your username">
      </label>

      <label class="field">
        <span class="field__label">Password</span>
        <input type="password" name="password" class="field__input" required placeholder="Your password">
      </label>

      <button type="submit" class="btn btn--primary btn--block">Log in</button>
    </form>

    <?php if ($userCount === 0): ?>
      <p class="auth__hint">
        No account yet?
        <a href="setup.php">Set up the first admin account</a>
      </p>
    <?php endif; ?>
  </div>
</body>
</html>
