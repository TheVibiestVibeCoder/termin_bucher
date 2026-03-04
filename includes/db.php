<?php
/**
 * Database schema - idempotent, safe to run on every request.
 * Uses PRAGMA table_info to detect and apply new columns (migrations).
 */

$db->exec("\nCREATE TABLE IF NOT EXISTS workshops (\n    id            INTEGER PRIMARY KEY AUTOINCREMENT,\n    title         TEXT    NOT NULL,\n    slug          TEXT    NOT NULL UNIQUE,\n    description   TEXT    NOT NULL DEFAULT '',\n    tag_label     TEXT    NOT NULL DEFAULT '',\n    capacity      INTEGER NOT NULL DEFAULT 0,\n    audiences     TEXT    NOT NULL DEFAULT '',\n    audience_labels TEXT  NOT NULL DEFAULT '',\n    format        TEXT    NOT NULL DEFAULT '',\n    featured      INTEGER NOT NULL DEFAULT 0,\n    sort_order    INTEGER NOT NULL DEFAULT 0,\n    active        INTEGER NOT NULL DEFAULT 1,\n    created_at    DATETIME NOT NULL DEFAULT (datetime('now')),\n    updated_at    DATETIME NOT NULL DEFAULT (datetime('now'))\n);\n");

$db->exec("\nCREATE TABLE IF NOT EXISTS workshop_groups (\n    id           INTEGER PRIMARY KEY AUTOINCREMENT,\n    name         TEXT    NOT NULL,\n    slug         TEXT    NOT NULL UNIQUE,\n    description  TEXT    NOT NULL DEFAULT '',\n    sort_order   INTEGER NOT NULL DEFAULT 0,\n    active       INTEGER NOT NULL DEFAULT 1,\n    created_at   DATETIME NOT NULL DEFAULT (datetime('now')),\n    updated_at   DATETIME NOT NULL DEFAULT (datetime('now'))\n);\n");

$db->exec("\nCREATE TABLE IF NOT EXISTS workshop_group_workshops (\n    group_id     INTEGER NOT NULL,\n    workshop_id  INTEGER NOT NULL,\n    sort_order   INTEGER NOT NULL DEFAULT 0,\n    created_at   DATETIME NOT NULL DEFAULT (datetime('now')),\n    PRIMARY KEY (group_id, workshop_id),\n    FOREIGN KEY (group_id) REFERENCES workshop_groups(id) ON DELETE CASCADE,\n    FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE\n);\n");

$db->exec("\nCREATE TABLE IF NOT EXISTS bookings (\n    id            INTEGER PRIMARY KEY AUTOINCREMENT,\n    workshop_id   INTEGER NOT NULL,\n    name          TEXT    NOT NULL,\n    email         TEXT    NOT NULL,\n    organization  TEXT    NOT NULL DEFAULT '',\n    phone         TEXT    NOT NULL DEFAULT '',\n    message       TEXT    NOT NULL DEFAULT '',\n    participants  INTEGER NOT NULL DEFAULT 1,\n    token         TEXT    NOT NULL UNIQUE,\n    confirmed     INTEGER NOT NULL DEFAULT 0,\n    confirmed_at  DATETIME,\n    created_at    DATETIME NOT NULL DEFAULT (datetime('now')),\n    FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE\n);\n");

$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_workshop ON bookings(workshop_id);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_token    ON bookings(token);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_email    ON bookings(email);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_workshops_slug    ON workshops(slug);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_workshop_groups_active_order ON workshop_groups(active, sort_order, id);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_wgw_group_order ON workshop_group_workshops(group_id, sort_order);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_wgw_workshop ON workshop_group_workshops(workshop_id);");

$db->exec("\nCREATE TABLE IF NOT EXISTS workshop_occurrences (\n    id          INTEGER PRIMARY KEY AUTOINCREMENT,\n    workshop_id INTEGER NOT NULL,\n    start_at    TEXT    NOT NULL DEFAULT '',\n    end_at      TEXT    NOT NULL DEFAULT '',\n    sort_order  INTEGER NOT NULL DEFAULT 0,\n    active      INTEGER NOT NULL DEFAULT 1,\n    created_at  DATETIME NOT NULL DEFAULT (datetime('now')),\n    updated_at  DATETIME NOT NULL DEFAULT (datetime('now')),\n    FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_workshop_occurrences_workshop ON workshop_occurrences(workshop_id, active, sort_order, id);");
$db->exec("\nCREATE TABLE IF NOT EXISTS discount_codes (\n    id                   INTEGER PRIMARY KEY AUTOINCREMENT,\n    code                 TEXT    NOT NULL UNIQUE COLLATE NOCASE,\n    label                TEXT    NOT NULL DEFAULT '',\n    discount_type        TEXT    NOT NULL DEFAULT 'percent',\n    discount_value       REAL    NOT NULL DEFAULT 0,\n    active               INTEGER NOT NULL DEFAULT 1,\n    starts_at            TEXT    NOT NULL DEFAULT '',\n    expires_at           TEXT    NOT NULL DEFAULT '',\n    max_total_uses       INTEGER NOT NULL DEFAULT 0,\n    max_uses_per_email   INTEGER NOT NULL DEFAULT 0,\n    min_participants     INTEGER NOT NULL DEFAULT 0,\n    allowed_emails       TEXT    NOT NULL DEFAULT '',\n    allowed_workshop_ids TEXT    NOT NULL DEFAULT '',\n    created_at           DATETIME NOT NULL DEFAULT (datetime('now')),\n    updated_at           DATETIME NOT NULL DEFAULT (datetime('now'))\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_discount_codes_code   ON discount_codes(code);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_discount_codes_active ON discount_codes(active);");

// Individual participant details for group bookings
$db->exec("\nCREATE TABLE IF NOT EXISTS booking_participants (\n    id          INTEGER PRIMARY KEY AUTOINCREMENT,\n    booking_id  INTEGER NOT NULL,\n    name        TEXT    NOT NULL DEFAULT '',\n    email       TEXT    NOT NULL DEFAULT '',\n    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE CASCADE\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bp_booking ON booking_participants(booking_id);");

$db->exec("\nCREATE TABLE IF NOT EXISTS request_rate_limits (\n    rl_key       TEXT    NOT NULL,\n    client_id    TEXT    NOT NULL,\n    window_start INTEGER NOT NULL,\n    hits         INTEGER NOT NULL DEFAULT 0,\n    PRIMARY KEY (rl_key, client_id)\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_request_rate_limits_window_start ON request_rate_limits(window_start);");

$db->exec("\nCREATE TABLE IF NOT EXISTS invoice_circles (\n    id           INTEGER PRIMARY KEY AUTOINCREMENT,\n    circle_code  TEXT    NOT NULL UNIQUE,\n    circle_label TEXT    NOT NULL,\n    prefix       TEXT    NOT NULL DEFAULT 'WS',\n    year         INTEGER NOT NULL DEFAULT 0,\n    next_number  INTEGER NOT NULL DEFAULT 1,\n    active       INTEGER NOT NULL DEFAULT 1,\n    created_at   DATETIME NOT NULL DEFAULT (datetime('now')),\n    updated_at   DATETIME NOT NULL DEFAULT (datetime('now'))\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_invoice_circles_active ON invoice_circles(active);");

$db->exec("\nCREATE TABLE IF NOT EXISTS invoice_counter_audit_log (\n    id                   INTEGER PRIMARY KEY AUTOINCREMENT,\n    circle_id            INTEGER NOT NULL,\n    previous_next_number INTEGER NOT NULL,\n    new_next_number      INTEGER NOT NULL,\n    reason               TEXT    NOT NULL,\n    changed_by           TEXT    NOT NULL DEFAULT 'admin',\n    changed_at           DATETIME NOT NULL DEFAULT (datetime('now')),\n    FOREIGN KEY (circle_id) REFERENCES invoice_circles(id) ON DELETE RESTRICT\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_invoice_counter_audit_circle ON invoice_counter_audit_log(circle_id, changed_at DESC);");

$db->exec("\nCREATE TABLE IF NOT EXISTS invoices (\n    id                     INTEGER PRIMARY KEY AUTOINCREMENT,\n    workshop_id            INTEGER NOT NULL,\n    booking_id             INTEGER NOT NULL UNIQUE,\n    circle_id              INTEGER NOT NULL,\n    invoice_number         INTEGER NOT NULL,\n    invoice_number_display TEXT    NOT NULL,\n    recipient_name         TEXT    NOT NULL DEFAULT '',\n    recipient_email        TEXT    NOT NULL DEFAULT '',\n    send_status            TEXT    NOT NULL DEFAULT 'issued',\n    issued_at              DATETIME NOT NULL DEFAULT (datetime('now')),\n    sent_at                DATETIME,\n    line_items_json        TEXT    NOT NULL DEFAULT '',\n    payload_json           TEXT    NOT NULL DEFAULT '',\n    FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE RESTRICT,\n    FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT,\n    FOREIGN KEY (circle_id) REFERENCES invoice_circles(id) ON DELETE RESTRICT,\n    UNIQUE (circle_id, invoice_number),\n    UNIQUE (invoice_number_display)\n);\n");
$db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_workshop ON invoices(workshop_id);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_circle ON invoices(circle_id);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_invoices_send_status ON invoices(send_status);");

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
    ['bookings', 'occurrence_id',             "INTEGER"],
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
    ['bookings', 'archived',                "INTEGER NOT NULL DEFAULT 0"],
    ['bookings', 'archived_at',             "DATETIME"],
    ['bookings', 'archived_by',             "TEXT NOT NULL DEFAULT ''"],
    ['bookings', 'archive_note',            "TEXT NOT NULL DEFAULT ''"],

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
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_archived_workshop ON bookings(archived, workshop_id, confirmed);");
$db->exec("CREATE INDEX IF NOT EXISTS idx_bookings_occurrence ON bookings(occurrence_id, confirmed, archived);");


// Backfill legacy single-date workshops into the new occurrences table.
// This keeps existing installations compatible without manual data migration.
$legacyWorkshopsRes = $db->query("
    SELECT id, event_date, event_date_end
    FROM workshops
    WHERE workshop_type = 'open' AND TRIM(COALESCE(event_date, '')) <> ''
");
while ($legacyWorkshop = $legacyWorkshopsRes->fetchArray(SQLITE3_ASSOC)) {
    $legacyWorkshopId = (int) ($legacyWorkshop['id'] ?? 0);
    if ($legacyWorkshopId <= 0) {
        continue;
    }

    $existingOccStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM workshop_occurrences WHERE workshop_id = :wid');
    $existingOccStmt->bindValue(':wid', $legacyWorkshopId, SQLITE3_INTEGER);
    $existingOccRow = $existingOccStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $existingCount = (int) ($existingOccRow['cnt'] ?? 0);
    if ($existingCount > 0) {
        continue;
    }

    $insertLegacyOcc = $db->prepare("
        INSERT INTO workshop_occurrences (workshop_id, start_at, end_at, sort_order, active)
        VALUES (:wid, :start_at, :end_at, 0, 1)
    ");
    $insertLegacyOcc->bindValue(':wid', $legacyWorkshopId, SQLITE3_INTEGER);
    $insertLegacyOcc->bindValue(':start_at', (string) ($legacyWorkshop['event_date'] ?? ''), SQLITE3_TEXT);
    $insertLegacyOcc->bindValue(':end_at', (string) ($legacyWorkshop['event_date_end'] ?? ''), SQLITE3_TEXT);
    $insertLegacyOcc->execute();
}

// Map legacy bookings (without occurrence_id) to the only occurrence of a workshop.
// This is safe if and only if exactly one occurrence exists.
$singleOccurrenceRes = $db->query("
    SELECT workshop_id, MIN(id) AS occurrence_id
    FROM workshop_occurrences
    GROUP BY workshop_id
    HAVING COUNT(*) = 1
");
while ($singleOccurrence = $singleOccurrenceRes->fetchArray(SQLITE3_ASSOC)) {
    $legacyWorkshopId = (int) ($singleOccurrence['workshop_id'] ?? 0);
    $occurrenceId = (int) ($singleOccurrence['occurrence_id'] ?? 0);
    if ($legacyWorkshopId <= 0 || $occurrenceId <= 0) {
        continue;
    }

    $mapStmt = $db->prepare("
        UPDATE bookings
        SET occurrence_id = :oid
        WHERE workshop_id = :wid AND occurrence_id IS NULL
    ");
    $mapStmt->bindValue(':oid', $occurrenceId, SQLITE3_INTEGER);
    $mapStmt->bindValue(':wid', $legacyWorkshopId, SQLITE3_INTEGER);
    $mapStmt->execute();
}

