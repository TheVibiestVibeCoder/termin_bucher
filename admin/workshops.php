<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/email.php';
require_admin();

function workshop_col_exists(SQLite3 $db, string $col): bool {
    $res = $db->query('PRAGMA table_info(workshops)');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ((string) ($row['name'] ?? '') === $col) {
            return true;
        }
    }

    return false;
}

function ensure_workshop_archive_schema(SQLite3 $db): void {
    $cols = [
        'archived' => 'INTEGER NOT NULL DEFAULT 0',
        'archived_at' => 'DATETIME',
        'archived_by' => "TEXT NOT NULL DEFAULT ''",
        'archive_note' => "TEXT NOT NULL DEFAULT ''",
        'bookable' => 'INTEGER NOT NULL DEFAULT 1',
    ];

    foreach ($cols as $name => $def) {
        if (!workshop_col_exists($db, $name)) {
            $db->exec('ALTER TABLE workshops ADD COLUMN ' . $name . ' ' . $def);
        }
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_workshops_archived_active ON workshops(archived, active, sort_order, id)');
}

ensure_workshop_archive_schema($db);

function ensure_workshop_occurrence_bookable_schema(SQLite3 $db): void {
    $res = $db->query('PRAGMA table_info(workshop_occurrences)');
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if ((string) ($row['name'] ?? '') === 'bookable') {
            return;
        }
    }
    $db->exec('ALTER TABLE workshop_occurrences ADD COLUMN bookable INTEGER NOT NULL DEFAULT 1');
}

ensure_workshop_occurrence_bookable_schema($db);
function add_cancellation_recipient(array &$map, string $email, string $name): void {
    $mail = trim($email);
    if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $key = strtolower($mail);
    $cleanName = trim($name);

    if (!isset($map[$key])) {
        $map[$key] = [
            'name' => $cleanName,
            'email' => $mail,
        ];
        return;
    }

    if ($map[$key]['name'] === '' && $cleanName !== '') {
        $map[$key]['name'] = $cleanName;
    }
}

function fetch_workshop_cancellation_recipients(SQLite3 $db, int $workshopId): array {
    if ($workshopId <= 0) {
        return [];
    }

    $recipientMap = [];
    $stmt = $db->prepare('SELECT id, name, email FROM bookings WHERE workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0 ORDER BY id ASC');
    $stmt->bindValue(':wid', $workshopId, SQLITE3_INTEGER);
    $res = $stmt->execute();

    $participantStmt = $db->prepare('SELECT name, email FROM booking_participants WHERE booking_id = :bid ORDER BY id ASC');

    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $bookingId = (int) ($row['id'] ?? 0);

        add_cancellation_recipient(
            $recipientMap,
            (string) ($row['email'] ?? ''),
            (string) ($row['name'] ?? '')
        );

        if ($bookingId <= 0) {
            continue;
        }

        $participantStmt->bindValue(':bid', $bookingId, SQLITE3_INTEGER);
        $participantRes = $participantStmt->execute();
        while ($participant = $participantRes->fetchArray(SQLITE3_ASSOC)) {
            add_cancellation_recipient(
                $recipientMap,
                (string) ($participant['email'] ?? ''),
                (string) ($participant['name'] ?? '')
            );
        }
    }

    return array_values($recipientMap);
}

function archive_open_workshop_bookings(SQLite3 $db, int $workshopId, string $archiveNote = 'Workshop wurde abgesagt.'): int {
    if ($workshopId <= 0) {
        return 0;
    }

    $stmt = $db->prepare("
        UPDATE bookings
        SET
            archived = 1,
            archived_at = datetime('now'),
            archived_by = :archived_by,
            archive_note = CASE WHEN :archive_note = '' THEN archive_note ELSE :archive_note END
        WHERE workshop_id = :wid AND COALESCE(archived, 0) = 0
    ");
    $stmt->bindValue(':archived_by', 'admin', SQLITE3_TEXT);
    $stmt->bindValue(':archive_note', $archiveNote, SQLITE3_TEXT);
    $stmt->bindValue(':wid', $workshopId, SQLITE3_INTEGER);
    $result = $stmt->execute();
    if ($result === false) {
        return 0;
    }

    return (int) $db->changes();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    flash('error', 'Ungueltige Sitzung.');
    redirect(admin_url('workshops'));
}

// Archive workshop (default remove behavior)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_id'])) {
    $archiveId = max(0, (int) ($_POST['archive_id'] ?? 0));
    if ($archiveId <= 0) {
        flash('error', 'Workshop konnte nicht archiviert werden.');
        redirect(admin_url('workshops'));
    }

    $workshopStmt = $db->prepare('SELECT id, title, COALESCE(archived, 0) AS archived FROM workshops WHERE id = :id LIMIT 1');
    $workshopStmt->bindValue(':id', $archiveId, SQLITE3_INTEGER);
    $workshopRow = $workshopStmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$workshopRow) {
        flash('error', 'Workshop nicht gefunden.');
        redirect(admin_url('workshops'));
    }
    if ((int) ($workshopRow['archived'] ?? 0) === 1) {
        flash('error', 'Workshop ist bereits archiviert.');
        redirect(admin_url('workshops'));
    }

    $recipients = fetch_workshop_cancellation_recipients($db, $archiveId);

    $inTransaction = false;
    $archivedBookingCount = 0;

    try {
        $db->exec('BEGIN IMMEDIATE');
        $inTransaction = true;

        $archivedBookingCount = archive_open_workshop_bookings($db, $archiveId, 'Workshop wurde abgesagt.');

        $archiveStmt = $db->prepare('
            UPDATE workshops
            SET
                archived = 1,
                archived_at = datetime("now"),
                archived_by = :archived_by,
                archive_note = :archive_note,
                active = 0,
                updated_at = datetime("now")
            WHERE id = :id AND COALESCE(archived, 0) = 0
        ');
        $archiveStmt->bindValue(':archived_by', 'admin', SQLITE3_TEXT);
        $archiveStmt->bindValue(':archive_note', 'Workshop archiviert.', SQLITE3_TEXT);
        $archiveStmt->bindValue(':id', $archiveId, SQLITE3_INTEGER);
        $archiveResult = $archiveStmt->execute();
        if ($archiveResult === false || $db->changes() !== 1) {
            throw new RuntimeException('Workshop konnte nicht archiviert werden.');
        }

        $db->exec('COMMIT');
        $inTransaction = false;
    } catch (Throwable $e) {
        if ($inTransaction) {
            $db->exec('ROLLBACK');
        }
        flash('error', 'Workshop konnte nicht archiviert werden.');
        redirect(admin_url('workshops'));
    }

    $mailSent = 0;
    $mailFailed = 0;
    $workshopTitle = trim((string) ($workshopRow['title'] ?? ''));
    foreach ($recipients as $recipient) {
        $ok = send_booking_cancelled_email(
            (string) ($recipient['email'] ?? ''),
            (string) ($recipient['name'] ?? ''),
            $workshopTitle
        );
        if ($ok) {
            $mailSent++;
        } else {
            $mailFailed++;
        }
    }

    $msg = 'Workshop archiviert.';
    if ($archivedBookingCount > 0) {
        $msg .= ' Buchungen archiviert: ' . $archivedBookingCount . '.';
    }
    if (!empty($recipients)) {
        $msg .= ' Stornomails gesendet: ' . $mailSent;
        if ($mailFailed > 0) {
            $msg .= ', fehlgeschlagen: ' . $mailFailed . '.';
        } else {
            $msg .= '.';
        }
    }

    flash('success', $msg);
    redirect(admin_url('workshops'));
}

// Restore archived workshop
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['restore_id'])) {
    $restoreId = max(0, (int) ($_POST['restore_id'] ?? 0));
    if ($restoreId <= 0) {
        flash('error', 'Workshop konnte nicht reaktiviert werden.');
        redirect(admin_url('workshops'));
    }

    $stmt = $db->prepare('
        UPDATE workshops
        SET
            archived = 0,
            archived_at = NULL,
            archived_by = "",
            archive_note = "",
            active = 1,
            updated_at = datetime("now")
        WHERE id = :id AND COALESCE(archived, 0) = 1
    ');
    $stmt->bindValue(':id', $restoreId, SQLITE3_INTEGER);
    $result = $stmt->execute();

    if ($result !== false && $db->changes() === 1) {
        flash('success', 'Workshop reaktiviert.');
    } else {
        flash('error', 'Workshop konnte nicht reaktiviert werden.');
    }

    redirect(admin_url('workshops'));
}

// Hard delete (only archived workshops)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hard_delete_id'])) {
    $hardDeleteId = max(0, (int) ($_POST['hard_delete_id'] ?? 0));
    if ($hardDeleteId <= 0) {
        flash('error', 'Workshop konnte nicht endgueltig geloescht werden.');
        redirect(admin_url('workshops'));
    }

    $workshopStmt = $db->prepare('SELECT id, COALESCE(archived, 0) AS archived FROM workshops WHERE id = :id LIMIT 1');
    $workshopStmt->bindValue(':id', $hardDeleteId, SQLITE3_INTEGER);
    $workshopRow = $workshopStmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$workshopRow) {
        flash('error', 'Workshop nicht gefunden.');
        redirect(admin_url('workshops'));
    }
    if ((int) ($workshopRow['archived'] ?? 0) !== 1) {
        flash('error', 'Nur archivierte Workshops koennen endgueltig geloescht werden.');
        redirect(admin_url('workshops'));
    }

    $inTransaction = false;
    try {
        $db->exec('BEGIN IMMEDIATE');
        $inTransaction = true;

        $deleteStmt = $db->prepare('DELETE FROM workshops WHERE id = :id');
        $deleteStmt->bindValue(':id', $hardDeleteId, SQLITE3_INTEGER);
        $deleteResult = $deleteStmt->execute();
        if ($deleteResult === false || $db->changes() !== 1) {
            throw new RuntimeException('Delete failed');
        }

        $db->exec('COMMIT');
        $inTransaction = false;
        flash('success', 'Workshop endgueltig geloescht.');
    } catch (Throwable $e) {
        if ($inTransaction) {
            $db->exec('ROLLBACK');
        }
        flash('error', 'Workshop konnte nicht endgueltig geloescht werden (z. B. wegen bestehender Rechnungen).');
    }

    redirect(admin_url('workshops'));
}

// Handle toggle active (only non-archived workshops)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    $toggleId = max(0, (int) ($_POST['toggle_id'] ?? 0));
    if ($toggleId > 0) {
        $stmt = $db->prepare('UPDATE workshops SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END, updated_at = datetime("now") WHERE id = :id AND COALESCE(archived, 0) = 0');
        $stmt->bindValue(':id', $toggleId, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Status aktualisiert.');
    }
    redirect(admin_url('workshops'));
}

// Handle toggle bookable (only non-archived workshops)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_bookable_id'])) {
    $toggleBookableId = max(0, (int) ($_POST['toggle_bookable_id'] ?? 0));
    if ($toggleBookableId > 0) {
        $stmt = $db->prepare('UPDATE workshops SET bookable = CASE WHEN COALESCE(bookable, 1) = 1 THEN 0 ELSE 1 END, updated_at = datetime("now") WHERE id = :id AND COALESCE(archived, 0) = 0');
        $stmt->bindValue(':id', $toggleBookableId, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Buchbarkeit aktualisiert.');
    }
    redirect(admin_url('workshops'));
}

// Handle toggle bookable for one workshop date
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_occurrence_bookable_id'])) {
    $toggleOccurrenceBookableId = max(0, (int) ($_POST['toggle_occurrence_bookable_id'] ?? 0));
    if ($toggleOccurrenceBookableId > 0) {
        $stmt = $db->prepare('
            UPDATE workshop_occurrences
            SET
                bookable = CASE WHEN COALESCE(bookable, 1) = 1 THEN 0 ELSE 1 END,
                updated_at = datetime("now")
            WHERE
                id = :id
                AND active = 1
                AND workshop_id IN (
                    SELECT id FROM workshops WHERE COALESCE(archived, 0) = 0
                )
        ');
        $stmt->bindValue(':id', $toggleOccurrenceBookableId, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Termin-Buchbarkeit aktualisiert.');
    }
    redirect(admin_url('workshops'));
}

// Fetch all workshops and split into active vs archived sections
$result = $db->query('SELECT * FROM workshops ORDER BY COALESCE(archived, 0) ASC, sort_order ASC, id ASC');
$activeWorkshops = [];
$archivedWorkshops = [];
$occurrenceRowsByWorkshop = [];

$occurrenceResult = $db->query('
    SELECT id, workshop_id, start_at, end_at, sort_order, active, COALESCE(bookable, 1) AS bookable
    FROM workshop_occurrences
    WHERE active = 1
    ORDER BY workshop_id ASC, sort_order ASC, start_at ASC, id ASC
');
while ($occurrenceRow = $occurrenceResult->fetchArray(SQLITE3_ASSOC)) {
    $wid = (int) ($occurrenceRow['workshop_id'] ?? 0);
    if ($wid <= 0) {
        continue;
    }
    if (!isset($occurrenceRowsByWorkshop[$wid])) {
        $occurrenceRowsByWorkshop[$wid] = [];
    }
    $occurrenceRowsByWorkshop[$wid][] = $occurrenceRow;
}

$totalBookingStmt = $db->prepare('SELECT COUNT(*) AS cnt FROM bookings WHERE workshop_id = :wid');

while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $wid = (int) ($row['id'] ?? 0);
    $row['bookable'] = (int) ($row['bookable'] ?? 1);
    $row['occurrences'] = $occurrenceRowsByWorkshop[$wid] ?? [];

    if (($row['workshop_type'] ?? 'auf_anfrage') === 'open' && empty($row['occurrences']) && trim((string) ($row['event_date'] ?? '')) !== '') {
        $row['occurrences'][] = [
            'id' => 0,
            'workshop_id' => $wid,
            'start_at' => (string) ($row['event_date'] ?? ''),
            'end_at' => (string) ($row['event_date_end'] ?? ''),
            'sort_order' => 0,
            'active' => 1,
            'bookable' => (int) ($row['bookable'] ?? 1),
        ];
    }

    $row['booking_count'] = count_confirmed_bookings($db, $wid);

    $totalBookingStmt->bindValue(':wid', $wid, SQLITE3_INTEGER);
    $totalBookingRow = $totalBookingStmt->execute()->fetchArray(SQLITE3_ASSOC);
    $row['booking_total'] = (int) ($totalBookingRow['cnt'] ?? 0);

    if ((int) ($row['archived'] ?? 0) === 1) {
        $archivedWorkshops[] = $row;
    } else {
        $activeWorkshops[] = $row;
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <script>
    document.documentElement.classList.add('js');
    (function () {
        try {
            var storedTheme = localStorage.getItem('site-theme');
            if (storedTheme === 'light' || storedTheme === 'dark') {
                document.documentElement.setAttribute('data-theme', storedTheme);
            }
        } catch (e) {}
    })();
    </script>
    <title>Workshops verwalten &ndash; Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .workshop-main-title {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            flex-wrap: wrap;
        }
        .workshop-occurrence-row td {
            background: var(--surface-soft);
            border-top: 1px dashed var(--border);
            font-size: 0.86rem;
        }
        .workshop-occurrence-title {
            display: inline-flex;
            align-items: center;
            gap: 0.42rem;
            color: var(--muted);
        }
        .workshop-occurrence-thread {
            color: var(--dim);
            font-weight: 700;
            font-size: 0.76rem;
            letter-spacing: 0.6px;
        }
        .workshop-occurrence-actions {
            display: inline-flex;
            gap: 0.45rem;
            align-items: center;
            flex-wrap: wrap;
        }
    </style>
</head>
<body class="admin-page">
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>Workshops</h1>
            <a href="<?= e(admin_url('workshop-edit')) ?>" class="btn-admin">+ Neuer Workshop</a>
        </div>

        <?= render_flash() ?>

        <?php if (empty($activeWorkshops) && empty($archivedWorkshops)): ?>
            <p style="color:var(--muted);">Noch keine Workshops vorhanden. <a href="<?= e(admin_url('workshop-edit')) ?>" style="color:var(--text);">Jetzt erstellen</a></p>
        <?php endif; ?>

        <?php if (!empty($activeWorkshops)): ?>
            <div class="admin-section-head" style="display:flex;justify-content:space-between;align-items:center;margin:1.35rem 0 0.6rem;gap:0.9rem;flex-wrap:wrap;">
                <h2 style="margin:0;font-size:1rem;color:var(--text);">Aktive &amp; inaktive Workshops</h2>
                <span style="color:var(--dim);font-size:0.78rem;">Standardaktion: Archivieren</span>
            </div>
            <div class="admin-table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Format</th>
                            <th>Kapazit&auml;t</th>
                            <th>Gebucht</th>
                            <th>Status</th>
                            <th>Reihenfolge</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($activeWorkshops as $w): ?>
                        <?php
                            $workshopId = (int) ($w['id'] ?? 0);
                            $isWorkshopBookable = ((int) ($w['bookable'] ?? 1) === 1);
                            $occurrenceRows = array_values((array) ($w['occurrences'] ?? []));
                            $showOccurrenceRows = count($occurrenceRows) > 1;
                        ?>
                        <tr>
                            <td style="color:var(--text);">
                                <span class="workshop-main-title">
                                    <?= e((string) ($w['title'] ?? '')) ?>
                                    <?php if ((int) ($w['featured'] ?? 0) === 1): ?>
                                        <span style="font-size:0.65rem;background:var(--featured-pill-bg);color:var(--featured-pill-text);padding:2px 6px;border-radius:3px;">Featured</span>
                                    <?php endif; ?>
                                </span>
                            </td>
                            <td><?= e((string) ($w['tag_label'] ?? '')) ?></td>
                            <td><?= (int) ($w['capacity'] ?? 0) ?: '&infin;' ?></td>
                            <td><?= (int) ($w['booking_count'] ?? 0) ?></td>
                            <td>
                                <?php if ((int) ($w['active'] ?? 0) === 1): ?>
                                    <span class="status-badge status-confirmed">Aktiv</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">Inaktiv</span>
                                <?php endif; ?>
                                <?php if ($isWorkshopBookable): ?>
                                    <span class="status-badge status-confirmed">Buchbar</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending">Nicht buchbar</span>
                                <?php endif; ?>
                            </td>
                            <td><?= (int) ($w['sort_order'] ?? 0) ?></td>
                            <td>
                                <div class="admin-actions">
                                    <a href="<?= e(admin_url('workshop-edit', ['id' => (int) $w['id']])) ?>" class="btn-admin">Bearbeiten</a>
                                    <a href="<?= e(admin_url('bookings', ['workshop_id' => (int) $w['id']])) ?>" class="btn-admin">Buchungen</a>

                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="toggle_id" value="<?= (int) $w['id'] ?>">
                                        <button type="submit" class="btn-admin"><?= ((int) ($w['active'] ?? 0) === 1) ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                    </form>

                                    <form method="POST" style="display:inline;">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="toggle_bookable_id" value="<?= (int) $w['id'] ?>">
                                        <button type="submit" class="btn-admin"><?= $isWorkshopBookable ? 'Buchung sperren' : 'Buchung freigeben' ?></button>
                                    </form>

                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Workshop wirklich archivieren? Bei bestaetigten Buchungen werden Stornomails versendet und Buchungen archiviert.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="archive_id" value="<?= (int) $w['id'] ?>">
                                        <button type="submit" class="btn-admin btn-danger">Archivieren</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php if ($showOccurrenceRows): ?>
                            <?php foreach ($occurrenceRows as $occIdx => $occurrence): ?>
                                <?php
                                    $occurrenceId = (int) ($occurrence['id'] ?? 0);
                                    $occurrenceBookableSelf = ((int) ($occurrence['bookable'] ?? 1) === 1);
                                    $occurrenceBookable = $isWorkshopBookable && $occurrenceBookableSelf;
                                    $occurrenceBooked = $occurrenceId > 0
                                        ? count_confirmed_bookings($db, $workshopId, $occurrenceId)
                                        : count_confirmed_bookings($db, $workshopId);
                                ?>
                                <tr class="workshop-occurrence-row">
                                    <td>
                                        <span class="workshop-occurrence-title">
                                            <span class="workshop-occurrence-thread"><?= ($occIdx + 1 === count($occurrenceRows)) ? '&#9492;' : '&#9500;' ?></span>
                                            Termin <?= ($occIdx + 1) ?>: <?= e(format_event_date((string) ($occurrence['start_at'] ?? ''), (string) ($occurrence['end_at'] ?? ''))) ?>
                                        </span>
                                    </td>
                                    <td><?= e((string) ($w['tag_label'] ?? '')) ?></td>
                                    <td><?= (int) ($w['capacity'] ?? 0) ?: '&infin;' ?></td>
                                    <td><?= (int) $occurrenceBooked ?></td>
                                    <td>
                                        <?php if ($occurrenceBookable): ?>
                                            <span class="status-badge status-confirmed">Buchbar</span>
                                        <?php else: ?>
                                            <span class="status-badge status-pending">Nicht buchbar</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?= (int) ($occurrence['sort_order'] ?? $occIdx) ?></td>
                                    <td>
                                        <div class="workshop-occurrence-actions">
                                            <?php if ($occurrenceId > 0): ?>
                                                <form method="POST" style="display:inline;">
                                                    <?= csrf_field() ?>
                                                    <input type="hidden" name="toggle_occurrence_bookable_id" value="<?= $occurrenceId ?>">
                                                    <button type="submit" class="btn-admin"><?= $occurrenceBookableSelf ? 'Termin sperren' : 'Termin freigeben' ?></button>
                                                </form>
                                            <?php else: ?>
                                                <span style="color:var(--dim);font-size:0.75rem;">Legacy-Termin</span>
                                            <?php endif; ?>
                                            <a href="<?= e(admin_url('workshop-edit', ['id' => $workshopId])) ?>" class="btn-admin">Bearbeiten</a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <div class="admin-section-head" style="display:flex;justify-content:space-between;align-items:center;margin:1.5rem 0 0.6rem;gap:0.9rem;flex-wrap:wrap;">
            <h2 style="margin:0;font-size:1rem;color:var(--text);">Archivierte Workshops</h2>
            <a href="<?= e(admin_url('bookings', ['workshop_id' => 'archive'])) ?>" class="btn-admin">Archiv-Buchungen</a>
        </div>

        <?php if (empty($archivedWorkshops)): ?>
            <p style="color:var(--muted);margin-top:0;">Keine archivierten Workshops vorhanden.</p>
        <?php else: ?>
            <div class="admin-table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Titel</th>
                            <th>Format</th>
                            <th>Buchungen gesamt</th>
                            <th>Archiviert am</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($archivedWorkshops as $w): ?>
                        <tr>
                            <td style="color:var(--text);"><?= e((string) ($w['title'] ?? '')) ?></td>
                            <td><?= e((string) ($w['tag_label'] ?? '')) ?></td>
                            <td><?= (int) ($w['booking_total'] ?? 0) ?></td>
                            <td><?= e(format_admin_datetime((string) ($w['archived_at'] ?? ''))) ?></td>
                            <td>
                                <div class="admin-actions">
                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Workshop reaktivieren?')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="restore_id" value="<?= (int) $w['id'] ?>">
                                        <button type="submit" class="btn-admin btn-success">Reaktivieren</button>
                                    </form>

                                    <form method="POST" style="display:inline;" onsubmit="return confirm('Workshop endgueltig loeschen? Diese Aktion ist nicht rueckgaengig und loescht den Datensatz komplett.')">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="hard_delete_id" value="<?= (int) $w['id'] ?>">
                                        <button type="submit" class="btn-admin btn-danger">Endgueltig loeschen</button>
                                    </form>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/site-ui.js"></script>
</body>
</html>
