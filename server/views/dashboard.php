<?php
/** @var array $serials @var string $status @var array $user */
$pageTitle = 'My shows — Serial Reminder';
$newCount  = count(array_filter($serials, static fn ($s) => $s['hasNew']));
?>
<div class="page-head">
  <h1>My shows</h1>
  <p class="sub">
    <?php if ($newCount > 0): ?>
      <strong class="hot"><?= $newCount ?></strong> show<?= $newCount === 1 ? '' : 's' ?> with something new to watch.
    <?php else: ?>
      You are up to date.
    <?php endif; ?>
  </p>
</div>

<div class="tabs">
  <?php foreach (['watching' => 'Watching', 'paused' => 'Paused', 'finished' => 'Finished', 'all' => 'All'] as $k => $label): ?>
    <a class="tab <?= $status === $k ? 'on' : '' ?>" href="/dashboard?status=<?= $k ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<?php if ($serials === []): ?>
  <div class="empty">
    <h2>Nothing here yet</h2>
    <p>Install the extension, put your API key in its settings, then just watch an episode.
       The show will show up here on its own.</p>
    <p><a class="btn" href="/settings">Get my API key</a></p>
  </div>
<?php endif; ?>

<div class="grid">
<?php foreach ($serials as $s):
    $next = $s['nextEpisode'];
    $last = $s['lastWatched'];
?>
  <article class="card <?= $s['hasNew'] ? 'is-new' : '' ?>" data-id="<?= (int) $s['id'] ?>">
    <a class="card-link" href="<?= e($s['watchUrl'] ?? $s['seriesUrl'] ?? '#') ?>" target="_blank" rel="noopener">
      <div class="poster">
        <?php if ($s['poster']): ?>
          <img src="<?= e($s['poster']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
        <?php else: ?>
          <span class="poster-fallback"><?= e(mb_substr($s['title'], 0, 1)) ?></span>
        <?php endif; ?>
        <?php if ($s['unwatchedCount'] > 0): ?>
          <span class="badge"><?= (int) $s['unwatchedCount'] ?> new</span>
        <?php endif; ?>
      </div>

      <div class="card-body">
        <h3 dir="auto" title="<?= e($s['title']) ?>"><?= e($s['title']) ?></h3>
        <p class="prov"><?= e($s['provider']) ?></p>

        <?php if ($next): ?>
          <p class="next">Watch next: <strong><?= e($next['label']) ?></strong>
            <?= $next['title'] ? '<span class="ep-title" dir="auto">' . e($next['title']) . '</span>' : '' ?>
          </p>
        <?php elseif ($last): ?>
          <p class="next done">Seen everything — last was <strong><?= e($last['label']) ?></strong></p>
        <?php else: ?>
          <p class="next done">No episodes recorded yet</p>
        <?php endif; ?>

        <p class="meta">
          <?php if ($last): ?>Last watched <?= e($last['label']) ?> · <?= e(sr_ago($s['lastWatchedAt'])) ?><?php endif; ?>
          <?php if ($s['checkError']): ?>
            <span class="warn" title="<?= e($s['checkError']) ?>">check failed</span>
          <?php endif; ?>
        </p>
      </div>
    </a>

    <div class="card-actions">
      <button class="mini" data-act="seen" data-id="<?= (int) $s['id'] ?>"
              <?= $next ? '' : 'disabled' ?>
              data-season="<?= (int) ($next['season'] ?? 0) ?>"
              data-episode="<?= (int) ($next['number'] ?? 0) ?>"><?= $next ? 'Mark ' . e($next['label']) . ' seen' : 'Nothing new' ?></button>
      <button class="mini" data-act="check" data-id="<?= (int) $s['id'] ?>">Check now</button>
      <select class="mini" data-act="status" data-id="<?= (int) $s['id'] ?>">
        <?php foreach (['watching' => 'Watching', 'paused' => 'Paused', 'finished' => 'Finished'] as $k => $l): ?>
          <option value="<?= $k ?>" <?= $s['status'] === $k ? 'selected' : '' ?>><?= $l ?></option>
        <?php endforeach; ?>
      </select>
      <button class="mini danger" data-act="delete" data-id="<?= (int) $s['id'] ?>" title="Stop following">✕</button>
    </div>
  </article>
<?php endforeach; ?>
</div>

<?php if ($serials !== []): ?>
<p class="bulk"><button class="btn ghost" id="check-all">Check every show for new episodes</button>
   <span id="check-status" class="muted"></span></p>
<?php endif; ?>

<script src="/assets/dashboard.js?v=3"></script>
