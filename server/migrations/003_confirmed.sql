-- Opening a show and watching a few seconds should NOT add it to the list.
-- A show is only "followed" once one of its episodes actually counts as watched.
-- Until then the row exists but stays hidden, so partial progress survives a
-- browser restart and adds up across sessions.
--
-- Default 1 so shows that were already followed before this change stay visible.
ALTER TABLE serials ADD COLUMN confirmed INTEGER NOT NULL DEFAULT 1;
CREATE INDEX IF NOT EXISTS idx_serials_confirmed ON serials(user_id, confirmed, status);
