<?php
/**
 * setup.php — one-time creation of the first admin account.
 *
 * This page only works while the users table is EMPTY. Once the first account
 * exists it redirects to the login page, so there is no public registration
 * and no hardcoded default password anywhere.
 */

require_once __DIR__ . '/includes/functions.php';

$error     = '';
$userCount = 0;
$dbReady   = true;

try {
    $userCount = (int)db()->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    $dbReady = false; // database/schema not imported yet
}

// Already configured — send the visitor back to login.
if ($dbReady && $userCount > 0) {
    flash('error', 'Setup is already complete. Please log in instead.');
    redirect('login.php');
}

if ($dbReady && $userCount === 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    $username    = trim((string)($_POST['username'] ?? ''));
    $displayName = trim((string)($_POST['display_name'] ?? ''));
    $password    = (string)($_POST['password'] ?? '');
    $confirm     = (string)($_POST['confirm'] ?? '');

    if (!preg_match('/^[A-Za-z0-9_.\-]{3,60}$/', $username)) {
        $error = 'Username must be 3–60 characters using letters, numbers, dots, dashes or underscores.';
    } elseif ($displayName === '' || mb_strlen($displayName) > 120) {
        $error = 'Please enter a display name (up to 120 characters).';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm) {
        $error = 'The two passwords do not match.';
    } else {
        // Password is never stored in plain text.
        $hash = password_hash($password, PASSWORD_DEFAULT);
        db()->prepare(
            'INSERT INTO users (username, password_hash, display_name) VALUES (?, ?, ?)'
        )->execute([$username, $hash, $displayName]);

        flash('success', 'Admin account created. Please log in.');
        redirect('login.php');
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Set up admin · Printer Shop POS</title>
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
      <p class="auth__subtitle">Create the first admin account</p>
    </div>

    <?= flash_render() ?>

    <?php if (!$dbReady): ?>
      <div class="flash flash--error">
        The database is not ready. Please start MySQL in XAMPP, create the
        database and import <code>database/database.sql</code>, then check the
        credentials in <code>config.php</code>. See <code>README.md</code>.
      </div>
    <?php else: ?>
      <?php if ($error !== ''): ?>
        <div class="flash flash--error"><?= e($error) ?></div>
      <?php endif; ?>

      <form method="post" action="setup.php" class="auth__form" autocomplete="off">
        <?= csrf_field() ?>
        <label class="field">
          <span class="field__label">Username</span>
          <input type="text" name="username" class="field__input" required autofocus
                 value="<?= e($_POST['username'] ?? '') ?>" placeholder="e.g. admin">
        </label>

        <label class="field">
          <span class="field__label">Display name</span>
          <input type="text" name="display_name" class="field__input" required
                 value="<?= e($_POST['display_name'] ?? '') ?>" placeholder="e.g. Shop Owner">
        </label>

        <label class="field">
          <span class="field__label">Password</span>
          <input type="password" name="password" class="field__input" required placeholder="At least 6 characters">
        </label>

        <label class="field">
          <span class="field__label">Confirm password</span>
          <input type="password" name="confirm" class="field__input" required placeholder="Repeat the password">
        </label>

        <button type="submit" class="btn btn--primary btn--block">Create admin account</button>
      </form>

      <p class="auth__hint"><a href="login.php">Back to login</a></p>
    <?php endif; ?>
  </div>
</body>
</html>
