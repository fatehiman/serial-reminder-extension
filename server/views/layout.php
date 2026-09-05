<?php
/** @var string $viewFile */
$currentUser = SR\Auth::currentUser();
$pageTitle   = $pageTitle ?? 'Serial Reminder';
?>
<!doctype html>
<html lang="en" dir="ltr">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= e($pageTitle) ?></title>
<link rel="icon" href="/assets/icon.svg" type="image/svg+xml">
<link rel="stylesheet" href="/assets/app.css?v=3">
</head>
<body>
<header class="topbar">
  <a class="brand" href="/dashboard">
    <span class="brand-dot"></span> Serial Reminder
  </a>
  <?php if ($currentUser): ?>
    <nav>
      <a href="/dashboard">Shows</a>
      <a href="/settings">Settings</a>
      <a href="/logout" class="muted">Log out</a>
    </nav>
  <?php endif; ?>
</header>

<main class="wrap">
<?php require $viewFile; ?>
</main>

<footer class="foot">
  serial-reminder &middot; <a href="https://github.com/fatehiman/serial-reminder-extension">source</a>
</footer>
</body>
</html>
