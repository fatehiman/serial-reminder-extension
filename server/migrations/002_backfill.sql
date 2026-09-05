-- When a show is first seen by the extension we assume every earlier episode was
-- already watched: you do not start a serial at episode 22. This remembers where
-- that starting point was, so episodes discovered later by the checker are
-- back-filled the same way instead of showing up as "new".
ALTER TABLE serials ADD COLUMN backfill_season INTEGER;
ALTER TABLE serials ADD COLUMN backfill_number INTEGER;
