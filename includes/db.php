<?php
/**
 * Database schema - idempotent, safe to run on every request.
 * Uses PRAGMA table_info to detect and apply new columns (migrations).
 */

$db->exec("\nCREATE TABLE IF NOT EXISTS workshops (\n    id            INTEGER PRIMARY KEY AUTOINCREMENT,\n    title         TEXT    NOT NULL,\n    slug          TEXT    NOT NULL UNIQUE,\n    description   TEXT    NOT NULL DEFAULT '',\n    tag_label     TEXT    NOT NULL DEFAULT '',\n    capacity      INTEGER NOT NULL DEFAULT 0,\n    audiences     TEXT    NOT NULL DEFAULT '',\n    audience_labels TEXT  NOT NULL DEFAULT '',\n    format        TEXT    NOT NULL DEFAULT '',\n    featured      INTEGER NOT NULL DEFAULT 0,\n    sort_order    INTEGER NOT NULL DEFAULT 0,\n    active        INTEGER NOT NULL DEFAULT 1,\n    created_at    DATETIME NOT NULL DEFAULT (datetime('now')),\n    updated_at    DATETIME NOT NULL DEFAULT (datetime('now'))\n);\n");

$db->exec("\nCREATE TABLE IF NOT EXISTS bookings (\n    id            INTEGER PRIMARY KEY AUTOINCREMENT,\n    workshop_id   INTEGER NOT NULL,\n    name          TEXT    NOT NULL,\n    email         TEXT    NOT NULL,\n    organization  TEXT    NOT NULL DEFAULT '',\n    phone         TEXT    NOT NULL DEFAULT '',\n    message       TEXT    NOT NULL DEFAULT '',\n    participants  INTEGER NOT NULL DEFAULT 1,\n    token         TEXT    NOT NULL UNIQUE,\n    confirmed     INTEGER NOT NULL DEFAULT 0,\n    confirmed_at  DATETIME,\n    created_at    DATETIME NOT NULL DEFAULT (datetime('now')),\n    FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE\n);\n");

$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_workshop ON bookings(workshop_id);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_token    ON bookings(token);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_email    ON bookings(email);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_workshops_slug    ON workshops(slug);");

$db->exec("\nCREATE TABLE IF NOT EXISTS discount_codes (\n    id                   INTEGER PRIMARY KEY AUTOINCREMENT,\n    code                 TEXT    NOT NULL UNIQUE COLLATE NOCASE,\n    label                TEXT    NOT NULL DEFAULT '',\n    discount_type        TEXT    NOT NULL DEFAULT 'percent',\n    discount_value       REAL    NOT NULL DEFAULT 0,\n    active               INTEGER NOT NULL DEFAULT 1,\n    starts_at            TEXT    NOT NULL DEFAULT '',\n    expires_at           TEXT    NOT NULL DEFAULT '',\n    max_total_uses       INTEGER NOT NULL DEFAULT 0,\n    max_uses_per_email   INTEGER NOT NULL DEFAULT 0,\n    min_participants     INTEGER NOT NULL DEFAULT 0,\n    allowed_emails       TEXT    NOT NULL DEFAULT '',\n    allowed_workshop_ids TEXT    NOT NULL DEFAULT '',\n    created_at           DATETIME NOT NULL DEFAULT (datetime('now')),\n    updated_at           DATETIME NOT NULL DEFAULT (datetime('now'))\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_discount_codes_code   ON discount_codes(code);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_discount_codes_active ON discount_codes(active);");

// Individual participant details for group bookings
$db->exec("\nCREATE TABLE IF NOT EXISTS booking_participants (\n    id          INTEGER PRIMARY KEY AUTOINCREMENT,\n    booking_id  INTEGER NOT NULL,\n    name        TEXT    NOT NULL DEFAULT '',\n    email       TEXT    NOT NULL DEFAULT '',\n    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bp_booking ON booking_participants(booking_id);");

// Migrations: add new columns to existing databases safely.
function _col_exists(SQLite3 $db, string $table, string $col): bool {
    $res = $db->query("PRAGMA table_info(" . $db->escapeString($table) . ")");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ($row['name'] === $col) {
            return true;
        }
    }

    return false;
}

$migrations = [
    // booking mode: 'group' (all together) | 'individual' (separate names/emails per person)
    ['bookings', 'booking_mode',            "TEXT NOT NULL DEFAULT 'group'"],
    // booking price snapshot + optional discount snapshot
    ['bookings', 'price_per_person_netto',  "REAL NOT NULL DEFAULT 0"],
    ['bookings', 'booking_currency',        "TEXT NOT NULL DEFAULT ''"],
    ['bookings', 'discount_code_id',        "INTEGER"],
    ['bookings', 'discount_code',           "TEXT NOT NULL DEFAULT ''"],
    ['bookings', 'discount_type',           "TEXT NOT NULL DEFAULT ''"],
    ['bookings', 'discount_value',          "REAL NOT NULL DEFAULT 0"],
    ['bookings', 'discount_amount',         "REAL NOT NULL DEFAULT 0"],
    ['bookings', 'subtotal_netto',          "REAL NOT NULL DEFAULT 0"],
    ['bookings', 'total_netto',             "REAL NOT NULL DEFAULT 0"],

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

    // discount code columns for earlier installs
    ['discount_codes', 'label',                "TEXT NOT NULL DEFAULT ''"],
    ['discount_codes', 'discount_type',        "TEXT NOT NULL DEFAULT 'percent'"],
    ['discount_codes', 'discount_value',       "REAL NOT NULL DEFAULT 0"],
    ['discount_codes', 'active',               "INTEGER NOT NULL DEFAULT 1"],
    ['discount_codes', 'starts_at',            "TEXT NOT NULL DEFAULT ''"],
    ['discount_codes', 'expires_at',           "TEXT NOT NULL DEFAULT ''"],
    ['discount_codes', 'max_total_uses',       "INTEGER NOT NULL DEFAULT 0"],
    ['discount_codes', 'max_uses_per_email',   "INTEGER NOT NULL DEFAULT 0"],
    ['discount_codes', 'min_participants',     "INTEGER NOT NULL DEFAULT 0"],
    ['discount_codes', 'allowed_emails',       "TEXT NOT NULL DEFAULT ''"],
    ['discount_codes', 'allowed_workshop_ids', "TEXT NOT NULL DEFAULT ''"],
    ['discount_codes', 'updated_at',           "DATETIME NOT NULL DEFAULT (datetime('now'))"],
];

foreach ($migrations as [$table, $col, $def]) {
    if (!_col_exists($db, $table, $col)) {
        $db->exec("ALTER TABLE {$table} ADD COLUMN {$col} {$def}");
    }
}

$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_discount_code_id ON bookings(discount_code_id);");
