-- Which account is logged in on each site.
--
-- Every platform signs you up by mobile number, and one person can hold several
-- numbers with a subscription on a different one for each site. Logging in with
-- the wrong number looks like "my subscription disappeared". The dashboard shows
-- the number next to the provider so the right one is obvious.
--
-- The extension reports this only while something is actually playing, which is
-- proof that the account really does have a working subscription.
CREATE TABLE IF NOT EXISTS provider_accounts (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id    INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider   TEXT    NOT NULL,
    label      TEXT    NOT NULL,   -- the mobile number, as people write it
    name       TEXT,               -- account holder, when the site tells us
    note       TEXT,               -- e.g. the subscription plan
    seen_at    TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(user_id, provider)
);
