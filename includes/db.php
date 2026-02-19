<?php
/**
 * Database schema — runs once (idempotent).
 * $db is already available from config.php.
 */

$db->exec("
CREATE TABLE IF NOT EXISTS workshops (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    title         TEXT    NOT NULL,
    slug          TEXT    NOT NULL UNIQUE,
    description   TEXT    NOT NULL DEFAULT '',
    tag_label     TEXT    NOT NULL DEFAULT '',
    capacity      INTEGER NOT NULL DEFAULT 0,
    audiences     TEXT    NOT NULL DEFAULT '',
    audience_labels TEXT  NOT NULL DEFAULT '',
    format        TEXT    NOT NULL DEFAULT '',
    featured      INTEGER NOT NULL DEFAULT 0,
    sort_order    INTEGER NOT NULL DEFAULT 0,
    active        INTEGER NOT NULL DEFAULT 1,
    created_at    DATETIME NOT NULL DEFAULT (datetime('now')),
    updated_at    DATETIME NOT NULL DEFAULT (datetime('now'))
);
");

$db->exec("
CREATE TABLE IF NOT EXISTS bookings (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    workshop_id   INTEGER NOT NULL,
    name          TEXT    NOT NULL,
    email         TEXT    NOT NULL,
    organization  TEXT    NOT NULL DEFAULT '',
    phone         TEXT    NOT NULL DEFAULT '',
    message       TEXT    NOT NULL DEFAULT '',
    participants  INTEGER NOT NULL DEFAULT 1,
    token         TEXT    NOT NULL UNIQUE,
    confirmed     INTEGER NOT NULL DEFAULT 0,
    confirmed_at  DATETIME,
    created_at    DATETIME NOT NULL DEFAULT (datetime('now')),
    FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE
);
");

$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_workshop ON bookings(workshop_id);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_token    ON bookings(token);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_email    ON bookings(email);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_workshops_slug    ON workshops(slug);");
