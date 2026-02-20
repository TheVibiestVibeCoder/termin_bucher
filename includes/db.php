<?php
/**
 * Database schema — idempotent, safe to run on every request.
 * Uses PRAGMA table_info to detect and apply new columns (migrations).
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

// ── Migrations: add new columns to existing databases safely ────────────────
function _col_exists(SQLite3 $db, string $table, string $col): bool {
    $res = $db->query("PRAGMA table_info(" . $db->escapeString($table) . ")");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $col) return true;
    }
    return false;
}

$migrations = [
    // short description for listing cards (long description stays in 'description')
    ['workshops', 'description_short', "TEXT NOT NULL DEFAULT ''"],
    // type: 'auf_anfrage' (contact-us) | 'open' (fixed scheduled event)
    ['workshops', 'workshop_type',    "TEXT NOT NULL DEFAULT 'auf_anfrage'"],
    // fixed event date/time (ISO: 'YYYY-MM-DD HH:MM')
    ['workshops', 'event_date',       "TEXT NOT NULL DEFAULT ''"],
    ['workshops', 'event_date_end',   "TEXT NOT NULL DEFAULT ''"],
    // location / venue
    ['workshops', 'location',         "TEXT NOT NULL DEFAULT ''"],
    // minimum participants required for the workshop to take place
    ['workshops', 'min_participants', "INTEGER NOT NULL DEFAULT 0"],
    // price per person, netto (0 = free / included / on request)
    ['workshops', 'price_netto',      "REAL NOT NULL DEFAULT 0"],
    ['workshops', 'price_currency',   "TEXT NOT NULL DEFAULT 'EUR'"],
];

foreach ($migrations as [$table, $col, $def]) {
    if (!_col_exists($db, $table, $col)) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
}
