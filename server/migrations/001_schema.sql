-- serial-reminder schema (SQLite)
PRAGMA journal_mode = WAL;
PRAGMA foreign_keys = ON;

CREATE TABLE IF NOT EXISTS users (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    username       TEXT    NOT NULL UNIQUE,
    password_hash  TEXT    NOT NULL,
    api_token      TEXT    NOT NULL UNIQUE,
    display_name   TEXT,
    created_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    last_login_at  TEXT
);

CREATE TABLE IF NOT EXISTS sessions (
    id          TEXT    PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    expires_at  TEXT    NOT NULL,
    user_agent  TEXT,
    ip          TEXT
);
CREATE INDEX IF NOT EXISTS idx_sessions_user ON sessions(user_id);

-- one-time tickets: the extension trades its API key for a ticket, then opens
-- /auth/t/<ticket> so the dashboard logs in without the user typing a password.
CREATE TABLE IF NOT EXISTS login_tickets (
    id          TEXT    PRIMARY KEY,
    user_id     INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TEXT    NOT NULL DEFAULT (datetime('now')),
    expires_at  TEXT    NOT NULL,
    used_at     TEXT
);

CREATE TABLE IF NOT EXISTS serials (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id               INTEGER NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    provider              TEXT    NOT NULL,
    provider_key          TEXT    NOT NULL,
    title                 TEXT    NOT NULL,
    series_url            TEXT,
    poster_url            TEXT,
    status                TEXT    NOT NULL DEFAULT 'watching',  -- watching | paused | finished
    last_watched_episode  INTEGER,          -- episodes.id of the newest episode the user finished
    last_watched_at       TEXT,
    latest_episode        INTEGER,          -- episodes.id of the newest episode known to exist
    last_checked_at       TEXT,
    check_error           TEXT,
    notes                 TEXT,
    created_at            TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at            TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(user_id, provider, provider_key)
);
CREATE INDEX IF NOT EXISTS idx_serials_user ON serials(user_id, status);

CREATE TABLE IF NOT EXISTS episodes (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    serial_id         INTEGER NOT NULL REFERENCES serials(id) ON DELETE CASCADE,
    season            INTEGER NOT NULL DEFAULT 1,
    number            INTEGER NOT NULL,
    title             TEXT,
    url               TEXT,
    duration_seconds  INTEGER,
    position_seconds  INTEGER NOT NULL DEFAULT 0,
    watched_seconds   INTEGER NOT NULL DEFAULT 0,   -- real time spent playing
    progress_ratio    REAL    NOT NULL DEFAULT 0,
    watched           INTEGER NOT NULL DEFAULT 0,
    source            TEXT    NOT NULL DEFAULT 'watch', -- watch | catalog
    released_at       TEXT,
    first_seen_at     TEXT    NOT NULL DEFAULT (datetime('now')),
    updated_at        TEXT    NOT NULL DEFAULT (datetime('now')),
    UNIQUE(serial_id, season, number)
);
CREATE INDEX IF NOT EXISTS idx_episodes_serial ON episodes(serial_id, season, number);

CREATE TABLE IF NOT EXISTS meta (
    key   TEXT PRIMARY KEY,
    value TEXT
);
