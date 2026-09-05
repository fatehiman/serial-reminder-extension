<?php
/** @var ?string $error */
$pageTitle = 'Log in — Serial Reminder';
?>
<div class="auth">
  <h1>Log in</h1>
  <p class="sub">Your shows, and which episode comes next.</p>

  <?php if ($error): ?><p class="error"><?= e($error) ?></p><?php endif; ?>

  <form method="post" action="/login" autocomplete="on">
    <label>Username
      <input type="text" name="username" required autofocus autocomplete="username">
    </label>
    <label>Password
      <input type="password" name="password" required autocomplete="current-password">
    </label>
    <button class="btn" type="submit">Log in</button>
  </form>

  <p class="hint">If the extension is installed, it opens this dashboard for you
     already logged in — you do not need to type anything.</p>
</div>
