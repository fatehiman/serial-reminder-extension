-- Telegram message when a show gets a new episode.
--
-- The rule is "one message per episode, ever", so the fact that an episode was
-- announced has to survive restarts and be stored next to the episode itself.
-- NULL means "not announced yet".
ALTER TABLE episodes ADD COLUMN notified_at TEXT;

-- Everything that already exists has been on the dashboard for a while. Without
-- this line the first run after the update would announce the whole back
-- catalogue of every show at once.
UPDATE episodes SET notified_at = datetime('now');

CREATE INDEX IF NOT EXISTS idx_episodes_notify ON episodes(serial_id, notified_at);
