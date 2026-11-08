<?php
/**
 * includes/header.php — shared page head + top navigation for protected pages.
 *
 * Pages set these variables BEFORE including this file:
 *   $pageTitle  — shown in the browser tab.
 *   $active     — which nav link to highlight ('pos','products','services','bills','reports','settings').
 *   $bodyClass  — optional extra CSS class for the <body>.
 */

require_once __DIR__ . '/functions.php';
require_login();

$pageTitle = $pageTitle ?? 'Printer Shop POS';
$active    = $active ?? '';
$bodyClass = $bodyClass ?? '';
$autoPrint = !empty($autoPrint) ? '1' : '0';
$shopName  = get_setting('shop_name', 'Printer Shop');
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($pageTitle) ?> · <?= e($shopName) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Manrope:wght@400;500;600;700&family=Sora:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="assets/style.css">
</head>
<body class="<?= e($bodyClass) ?>" data-autoprint="<?= $autoPrint ?>">
  <header class="topbar">
    <div class="topbar__brand">
      <span class="topbar__mark"><?= e(mb_substr($shopName, 0, 1)) ?></span>
      <span class="topbar__name"><?= e($shopName) ?></span>
    </div>

    <nav class="topnav" aria-label="Main navigation">
      <a href="index.php"     class="topnav__link <?= $active === 'pos'      ? 'is-active' : '' ?>">New Bill</a>
      <a href="products.php"  class="topnav__link <?= $active === 'products' ? 'is-active' : '' ?>">Products</a>
      <a href="services.php"  class="topnav__link <?= $active === 'services' ? 'is-active' : '' ?>">Services</a>
      <a href="bills.php"     class="topnav__link <?= $active === 'bills'    ? 'is-active' : '' ?>">Bills</a>
      <a href="reports.php"   class="topnav__link <?= $active === 'reports'  ? 'is-active' : '' ?>">Reports</a>
      <a href="settings.php"  class="topnav__link <?= $active === 'settings' ? 'is-active' : '' ?>">Settings</a>
    </nav>

    <div class="topbar__user">
      <span class="topbar__user-name" title="Logged in user"><?= e(current_user_name()) ?></span>
      <a href="logout.php" class="btn btn--ghost btn--sm">Log out</a>
    </div>
  </header>

  <main class="main">
    <?= flash_render() ?>
