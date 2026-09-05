<?php
/** @var string $title @var string $body */
$pageTitle = $title . ' — Serial Reminder';
?>
<div class="auth">
  <h1><?= e($title) ?></h1>
  <p class="sub"><?= $body ?></p>
  <p><a class="btn" href="/dashboard">Go to my shows</a></p>
</div>
