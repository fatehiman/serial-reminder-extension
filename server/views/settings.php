<?php
/** @var array $user @var ?string $notice */
$pageTitle = 'Settings — Serial Reminder';
$apiBase   = rtrim((string) SR\Config::get('app_url'), '/') . '/api';
?>
<div class="page-head">
  <h1>Settings</h1>
</div>

<?php if ($notice): ?><p class="notice"><?= e($notice) ?></p><?php endif; ?>

<section class="panel">
  <h2>Extension settings</h2>
  <p class="sub">Copy these two values into the extension options. Nothing else to set.</p>

  <label class="field">API URL
    <span class="copyrow">
      <input type="text" readonly value="<?= e($apiBase) ?>" id="f-url">
      <button class="mini" data-copy="f-url">Copy</button>
    </span>
  </label>

  <label class="field">API key
    <span class="copyrow">
      <input type="text" readonly value="<?= e($user['api_token']) ?>" id="f-key">
      <button class="mini" data-copy="f-key">Copy</button>
    </span>
  </label>

  <form method="post" class="inline" onsubmit="return confirm('Every computer will need the new key. Continue?')">
    <input type="hidden" name="action" value="rotate_token">
    <button class="mini danger" type="submit">Create a new API key</button>
  </form>
</section>

<section class="panel">
  <h2>Password</h2>
  <p class="sub">Used only when you open the dashboard on a computer without the extension.</p>
  <form method="post" class="inline">
    <input type="hidden" name="action" value="password">
    <input type="password" name="password" placeholder="New password (min 8 chars)" minlength="8" required
           autocomplete="new-password">
    <button class="mini" type="submit">Change password</button>
  </form>
</section>

<section class="panel">
  <h2>Account</h2>
  <p class="kv"><span>Username</span><strong><?= e($user['username']) ?></strong></p>
  <p class="kv"><span>Created</span><strong><?= e($user['created_at']) ?> UTC</strong></p>
  <p class="kv"><span>Last login</span><strong><?= e($user['last_login_at'] ?? '—') ?></strong></p>
</section>

<script>
document.querySelectorAll('[data-copy]').forEach(function (b) {
  b.addEventListener('click', function () {
    var el = document.getElementById(b.dataset.copy);
    el.select();
    navigator.clipboard.writeText(el.value).then(function () {
      var old = b.textContent; b.textContent = 'Copied'; setTimeout(function () { b.textContent = old; }, 1200);
    });
  });
});
</script>
