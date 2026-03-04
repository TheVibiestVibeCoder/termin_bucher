<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/email.php';
require_admin();

$workshopFilterRaw = isset($_GET['workshop_id']) ? trim((string) $_GET['workshop_id']) : '0';
$isArchiveView = strcasecmp($workshopFilterRaw, 'archive') === 0;
$workshopId = 0;
if (!$isArchiveView && ctype_digit($workshopFilterRaw)) {
    $workshopId = (int) $workshopFilterRaw;
}

$workshop = null;
if ($workshopId > 0) {
    $workshop = get_workshop_by_id($db, $workshopId);
}

$returnParams = [];
if ($isArchiveView) {
    $returnParams['workshop_id'] = 'archive';
} elseif ($workshopId > 0) {
    $returnParams['workshop_id'] = $workshopId;
}
$returnUrl = admin_url('bookings', $returnParams);

if (!$isArchiveView && $workshopId && (string) ($_GET['export_participants'] ?? '') === '1') {
    if (!$workshop) {
        flash('error', 'Workshop nicht gefunden.');
        redirect(admin_url('bookings'));
    }

    $rows = [];
    $rowKeys = [];

    $participantStmt = $db->prepare('SELECT name, email FROM booking_participants WHERE booking_id = :bid ORDER BY id ASC');
    $bookingStmt = $db->prepare('
        SELECT
            id,
            name,
            email,
            participants,
            booking_mode,
            confirmed_at,
            created_at
        FROM bookings
        WHERE workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0
        ORDER BY confirmed_at ASC, created_at ASC
    ');
    $bookingStmt->bindValue(':wid', $workshopId, SQLITE3_INTEGER);
    $bookingRes = $bookingStmt->execute();

    $appendRow = static function (
        array &$targetRows,
        array &$targetKeys,
        int $bookingId,
        string $role,
        string $name,
        string $email,
        int $participants,
        string $bookingMode,
        string $confirmedAt
    ): void {
        $name = trim($name);
        $email = trim($email);
        if ($name === '' && $email === '') {
            return;
        }

        $dedupeKey = $bookingId . '|' . strtolower($name) . '|' . strtolower($email) . '|' . strtolower($role);
        if (isset($targetKeys[$dedupeKey])) {
            return;
        }
        $targetKeys[$dedupeKey] = true;

        $targetRows[] = [
            'booking_id' => $bookingId,
            'role' => $role,
            'name' => $name,
            'email' => $email,
            'participants' => $participants,
            'booking_type' => $participants <= 1 ? 'Einzelbuchung' : 'Gruppenbuchung',
            'booking_mode' => $bookingMode,
            'confirmed_at' => $confirmedAt,
        ];
    };

    while ($booking = $bookingRes->fetchArray(SQLITE3_ASSOC)) {
        $bookingId = (int) ($booking['id'] ?? 0);
        $participants = max(1, (int) ($booking['participants'] ?? 1));
        $bookingMode = (string) ($booking['booking_mode'] ?? 'group');
        $confirmedAt = trim((string) ($booking['confirmed_at'] ?? $booking['created_at'] ?? ''));

        $appendRow(
            $rows,
            $rowKeys,
            $bookingId,
            'Buchende Person',
            (string) ($booking['name'] ?? ''),
            (string) ($booking['email'] ?? ''),
            $participants,
            $bookingMode,
            $confirmedAt
        );

        $participantStmt->bindValue(':bid', $bookingId, SQLITE3_INTEGER);
        $pRes = $participantStmt->execute();
        while ($participant = $pRes->fetchArray(SQLITE3_ASSOC)) {
            $appendRow(
                $rows,
                $rowKeys,
                $bookingId,
                'Teilnehmer:in',
                (string) ($participant['name'] ?? ''),
                (string) ($participant['email'] ?? ''),
                $participants,
                $bookingMode,
                $confirmedAt
            );
        }
    }

    $slugPart = trim((string) ($workshop['slug'] ?? ''));
    if ($slugPart === '') {
        $slugPart = preg_replace('/[^a-z0-9]+/i', '-', strtolower((string) ($workshop['title'] ?? 'workshop')));
    }
    $slugPart = trim((string) $slugPart, '-');
    if ($slugPart === '') {
        $slugPart = 'workshop';
    }

    $filename = 'teilnehmerliste-' . $slugPart . '-' . date('Ymd-His') . '.xls';
    $tableCell = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES, 'UTF-8');

    header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0, no-cache, no-store, must-revalidate');
    header('Pragma: public');

    echo "\xEF\xBB\xBF";
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
    echo '<table border="1" cellspacing="0" cellpadding="6">';
    echo '<tr>';
    echo '<th>Workshop</th>';
    echo '<th>Buchungs-ID</th>';
    echo '<th>Rolle</th>';
    echo '<th>Name</th>';
    echo '<th>E-Mail</th>';
    echo '<th>Buchungsart</th>';
    echo '<th>Teilnehmer laut Buchung</th>';
    echo '<th>Bestaetigt am</th>';
    echo '</tr>';

    if (empty($rows)) {
        echo '<tr>';
        echo '<td>' . $tableCell((string) ($workshop['title'] ?? '')) . '</td>';
        echo '<td></td><td></td><td></td><td></td><td></td><td></td>';
        echo '<td>Keine bestaetigten Buchungen vorhanden.</td>';
        echo '</tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';
            echo '<td>' . $tableCell((string) ($workshop['title'] ?? '')) . '</td>';
            echo '<td>' . $tableCell((string) $row['booking_id']) . '</td>';
            echo '<td>' . $tableCell((string) $row['role']) . '</td>';
            echo '<td>' . $tableCell((string) $row['name']) . '</td>';
            echo '<td>' . $tableCell((string) $row['email']) . '</td>';
            echo '<td>' . $tableCell((string) $row['booking_type']) . '</td>';
            echo '<td>' . $tableCell((string) $row['participants']) . '</td>';
            echo '<td>' . $tableCell((string) $row['confirmed_at']) . '</td>';
            echo '</tr>';
        }
    }

    echo '</table></body></html>';
    exit;
}


// -- Handle actions ----------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    flash('error', 'Ungültige Sitzung.');
    redirect($returnUrl);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Archive booking (soft delete). Keep old delete_booking_id for compatibility.
    if (isset($_POST['archive_booking_id']) || isset($_POST['delete_booking_id'])) {
        $archiveId = isset($_POST['archive_booking_id'])
            ? (int) $_POST['archive_booking_id']
            : (int) $_POST['delete_booking_id'];
        $archiveNote = trim((string) ($_POST['archive_note'] ?? ''));

        $dstmt = $db->prepare('
            SELECT
                b.name,
                b.email,
                b.confirmed,
                COALESCE(b.archived, 0) AS archived,
                w.title AS workshop_title
            FROM bookings b
            JOIN workshops w ON b.workshop_id = w.id
            WHERE b.id = :id
            LIMIT 1
        ');
        $dstmt->bindValue(':id', $archiveId, SQLITE3_INTEGER);
        $drow = $dstmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$drow) {
            flash('error', 'Buchung nicht gefunden.');
            redirect($returnUrl);
        }

        if ((int) ($drow['archived'] ?? 0) === 1) {
            flash('success', 'Buchung ist bereits archiviert.');
            redirect($returnUrl);
        }

        $stmt = $db->prepare("\n            UPDATE bookings\n            SET\n                archived = 1,\n                archived_at = datetime('now'),\n                archived_by = :archived_by,\n                archive_note = CASE WHEN :archive_note = '' THEN archive_note ELSE :archive_note END\n            WHERE id = :id AND COALESCE(archived, 0) = 0\n        ");
        $stmt->bindValue(':id', $archiveId, SQLITE3_INTEGER);
        $stmt->bindValue(':archived_by', 'admin', SQLITE3_TEXT);
        $stmt->bindValue(':archive_note', $archiveNote, SQLITE3_TEXT);
        $updateResult = $stmt->execute();

        if ($updateResult !== false && $db->changes() === 1) {
            if ((int) ($drow['confirmed'] ?? 0) === 1) {
                send_booking_cancelled_email((string) $drow['email'], (string) $drow['name'], (string) $drow['workshop_title']);
            }
            flash('success', 'Buchung archiviert.' . ((int) ($drow['confirmed'] ?? 0) === 1 ? ' Stornierungsmail gesendet.' : ''));
        } else {
            flash('error', 'Buchung konnte nicht archiviert werden.');
        }

        redirect($returnUrl);
    }

    // Manually confirm booking
    if (isset($_POST['confirm_booking_id'])) {
        $bid = (int) $_POST['confirm_booking_id'];
        $sendConfirmationEmail = null;
        $inTransaction = false;

        try {
            $db->exec('BEGIN IMMEDIATE');
            $inTransaction = true;

            $bstmt = $db->prepare('
                SELECT
                    b.*,
                    w.title AS workshop_title,
                    w.capacity AS workshop_capacity,
                    w.format,
                    w.tag_label,
                    w.workshop_type,
                    w.event_date,
                    w.event_date_end,
                    w.location,
                    w.price_netto,
                    w.price_currency
                FROM bookings b
                JOIN workshops w ON b.workshop_id = w.id
                WHERE b.id = :id AND COALESCE(b.archived, 0) = 0
            ');
            $bstmt->bindValue(':id', $bid, SQLITE3_INTEGER);
            $brow = $bstmt->execute()->fetchArray(SQLITE3_ASSOC);

            if (!$brow) {
                flash('error', 'Buchung nicht gefunden.');
            } elseif ((int) $brow['confirmed'] === 1) {
                flash('success', 'Buchung ist bereits bestätigt.');
            } else {
                $capacity = (int) $brow['workshop_capacity'];
                $booked = count_confirmed_bookings($db, (int) $brow['workshop_id']);

                if ($capacity > 0 && ($booked + (int) $brow['participants']) > $capacity) {
                    flash('error', 'Buchung kann nicht bestätigt werden: Kapazität erreicht.');
                } else {
                    $stmt = $db->prepare("UPDATE bookings SET confirmed = 1, confirmed_at = datetime('now') WHERE id = :id AND confirmed = 0 AND COALESCE(archived, 0) = 0");
                    $stmt->bindValue(':id', $bid, SQLITE3_INTEGER);
                    $updateResult = $stmt->execute();

                    if ($updateResult !== false && $db->changes() === 1) {
                        $sendConfirmationEmail = $brow;
                        flash('success', 'Buchung manuell bestätigt.');
                    } else {
                        flash('error', 'Buchung konnte nicht bestätigt werden.');
                    }
                }
            }

            $db->exec('COMMIT');
            $inTransaction = false;
        } catch (Throwable $e) {
            if ($inTransaction) {
                $db->exec('ROLLBACK');
            }
            flash('error', 'Technischer Fehler bei der Bestätigung.');
        }

        if (is_array($sendConfirmationEmail)) {
            $emailParticipants = [];
            $pstmt = $db->prepare('SELECT name, email FROM booking_participants WHERE booking_id = :bid ORDER BY id ASC');
            $pstmt->bindValue(':bid', (int) $sendConfirmationEmail['id'], SQLITE3_INTEGER);
            $pres = $pstmt->execute();
            while ($p = $pres->fetchArray(SQLITE3_ASSOC)) {
                $emailParticipants[] = $p;
            }

            if (!send_booking_confirmed_email(
                $sendConfirmationEmail['email'],
                $sendConfirmationEmail['name'],
                $sendConfirmationEmail['workshop_title'],
                $sendConfirmationEmail,
                $sendConfirmationEmail,
                $emailParticipants
            )) {
                flash('error', 'Bestätigungs-E-Mail konnte nicht gesendet werden.');
            }
        }

        redirect($returnUrl);
    }

    // Send custom email to single booking
    if (isset($_POST['email_booking_id'])) {
        $bid     = (int) $_POST['email_booking_id'];
        $subject = trim($_POST['email_subject'] ?? '');
        $message = trim($_POST['email_message'] ?? '');

        if ($subject && $message) {
            $bstmt = $db->prepare('SELECT email, name FROM bookings WHERE id = :id');
            $bstmt->bindValue(':id', $bid, SQLITE3_INTEGER);
            $brow = $bstmt->execute()->fetchArray(SQLITE3_ASSOC);
            if ($brow) {
                if (send_custom_email($brow['email'], $subject, $message)) {
                    flash('success', "E-Mail an {$brow['email']} gesendet.");
                } else {
                    flash('error', 'E-Mail konnte nicht gesendet werden.');
                }
            }
        } else {
            flash('error', 'Betreff und Nachricht sind erforderlich.');
        }
        redirect($returnUrl);
    }

    // Send per-booking Rechnungen for a workshop
    if (isset($_POST['rechnung_submit'])) {
        $rwid = (int) ($_POST['rechnung_workshop_id'] ?? 0);
        if (!$rwid) {
            flash('error', 'Kein Workshop ausgewählt.');
        } else {
            $commonData = [
                'rechnung_datum'       => trim($_POST['r_rechnung_datum']       ?? date('Y-m-d')),
                'fuer_text'            => trim($_POST['r_fuer_text']            ?? ''),
                'workshop_titel'       => trim($_POST['r_workshop_titel']       ?? ''),
                'veranstaltungs_datum' => trim($_POST['r_veranstaltungs_datum'] ?? ''),
                'pos1_label'           => trim($_POST['r_pos1_label']           ?? ''),
                'pos1_betrag'          => trim($_POST['r_pos1_betrag']          ?? '0'),
                'pos2_label'           => trim($_POST['r_pos2_label']           ?? ''),
                'pos2_betrag'          => trim($_POST['r_pos2_betrag']          ?? ''),
                'absender_name'        => trim($_POST['r_absender_name']        ?? ''),
            ];

            // r_booking_selected is an assoc array: index => "1" for checked cards
            $selectedCards = $_POST['r_booking_selected'] ?? [];
            $baseLineItems = [];
            $pos1Label = trim((string) ($commonData['pos1_label'] ?? ''));
            $pos2Label = trim((string) ($commonData['pos2_label'] ?? ''));
            $pos1Amount = parse_rechnung_amount((string) ($commonData['pos1_betrag'] ?? '0'));
            $pos2Amount = parse_rechnung_amount((string) ($commonData['pos2_betrag'] ?? '0'));

            if ($pos1Label !== '' && abs($pos1Amount) > 0.00001) {
                $baseLineItems[] = ['label' => $pos1Label, 'amount' => $pos1Amount];
            }
            if ($pos2Label !== '' && abs($pos2Amount) > 0.00001) {
                $baseLineItems[] = ['label' => $pos2Label, 'amount' => $pos2Amount];
            }

            $baseSubtotal = 0.0;
            foreach ($baseLineItems as $lineItem) {
                $baseSubtotal += (float) $lineItem['amount'];
            }

            $rsent = 0;

            foreach (array_keys($selectedCards) as $i) {
                $i = (int) $i;
                $email = trim($_POST['r_booking_kontakt_email'][$i] ?? '');
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) continue;

                $bookingId = (int) ($_POST['r_booking_id'][$i] ?? 0);
                $discountCode = '';
                $discountAmount = 0.0;
                if ($bookingId > 0) {
                    $rbStmt = $db->prepare('SELECT discount_code, discount_amount FROM bookings WHERE id = :id AND workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0 LIMIT 1');
                    $rbStmt->bindValue(':id', $bookingId, SQLITE3_INTEGER);
                    $rbStmt->bindValue(':wid', $rwid, SQLITE3_INTEGER);
                    $rbRow = $rbStmt->execute()->fetchArray(SQLITE3_ASSOC);
                    if ($rbRow) {
                        $discountCode = trim((string) ($rbRow['discount_code'] ?? ''));
                        $discountAmount = max(0.0, (float) ($rbRow['discount_amount'] ?? 0));
                    }
                }

                $invoiceLineItems = $baseLineItems;
                if ($discountAmount > 0 && $baseSubtotal > 0) {
                    $effectiveDiscount = min($discountAmount, $baseSubtotal);
                    $discountLabel = 'Rabatt';
                    if ($discountCode !== '') {
                        $discountLabel .= ' (' . $discountCode . ')';
                    }
                    $invoiceLineItems[] = [
                        'label' => $discountLabel,
                        'amount' => -$effectiveDiscount,
                    ];
                }

                $invoiceData = array_merge($commonData, [
                    'empfaenger'    => trim($_POST['r_booking_empfaenger'][$i]   ?? ''),
                    'adresse'       => trim($_POST['r_booking_adresse'][$i]      ?? ''),
                    'plz_ort'       => trim($_POST['r_booking_plz_ort'][$i]      ?? ''),
                    'anrede'        => trim($_POST['r_booking_anrede'][$i]       ?? 'Herrn'),
                    'kontakt_name'  => trim($_POST['r_booking_kontakt_name'][$i] ?? ''),
                    'kontakt_email' => $email,
                    'rechnungs_nr'  => trim($_POST['r_booking_rechnungs_nr'][$i] ?? ''),
                    'line_items'    => $invoiceLineItems,
                ]);

                if (send_rechnung_email($email, $invoiceData)) {
                    $rsent++;
                }
            }
            if ($rsent === 0) {
                flash('error', 'Keine Empfänger ausgewählt oder keine gültigen E-Mail-Adressen vorhanden.');
            } else {
                flash('success', "Rechnung an {$rsent} Empfänger gesendet.");
            }
        }
        redirect($returnUrl);
    }

    // Bulk email to ALL participants of a workshop
    if (isset($_POST['email_all_submit'])) {
        $bulkWid     = (int) ($_POST['bulk_workshop_id'] ?? 0);
        $bulkSubject = trim($_POST['bulk_subject'] ?? '');
        $bulkMessage = trim($_POST['bulk_message'] ?? '');

        if (!$bulkWid) {
            flash('error', 'Kein Workshop ausgewählt.');
        } elseif (!$bulkSubject || !$bulkMessage) {
            flash('error', 'Betreff und Nachricht sind erforderlich.');
        } else {
            // Collect all unique email addresses: booker emails + participant emails
            $emails = [];

            // Booker emails (confirmed bookings)
            $bstmt = $db->prepare('SELECT DISTINCT email FROM bookings WHERE workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0');
            $bstmt->bindValue(':wid', $bulkWid, SQLITE3_INTEGER);
            $bres = $bstmt->execute();
            while ($br = $bres->fetchArray(SQLITE3_ASSOC)) {
                $emails[strtolower($br['email'])] = $br['email'];
            }

            // Individual participant emails
            $pstmt = $db->prepare('
                SELECT DISTINCT bp.email FROM booking_participants bp
                JOIN bookings b ON bp.booking_id = b.id
                WHERE b.workshop_id = :wid AND b.confirmed = 1 AND COALESCE(b.archived, 0) = 0
            ');
            $pstmt->bindValue(':wid', $bulkWid, SQLITE3_INTEGER);
            $pres = $pstmt->execute();
            while ($pr = $pres->fetchArray(SQLITE3_ASSOC)) {
                $emails[strtolower($pr['email'])] = $pr['email'];
            }

            $sent = 0;
            foreach ($emails as $email) {
                if (filter_var($email, FILTER_VALIDATE_EMAIL) && send_custom_email($email, $bulkSubject, $bulkMessage)) {
                    $sent++;
                }
            }
            flash('success', "E-Mail an {$sent} Empfänger gesendet.");
        }
        redirect($returnUrl);
    }
}

// -- Fetch bookings ----------------------------------------------------------
$sql = '
    SELECT b.*, w.title AS workshop_title, w.slug AS workshop_slug, w.price_currency AS workshop_currency
    FROM bookings b
    JOIN workshops w ON b.workshop_id = w.id
';
$params = [];
$where = [];

if ($isArchiveView) {
    $where[] = 'COALESCE(b.archived, 0) = 1';
} else {
    $where[] = 'COALESCE(b.archived, 0) = 0';
    if ($workshopId > 0) {
        $where[] = 'b.workshop_id = :wid';
        $params[':wid'] = $workshopId;
    }
}

if (!empty($where)) {
    $sql .= ' WHERE ' . implode(' AND ', $where);
}
if ($isArchiveView) {
    $sql .= " ORDER BY COALESCE(b.archived_at, b.created_at) DESC, b.id DESC";
} else {
    $sql .= ' ORDER BY b.created_at DESC';
}

$stmt = $db->prepare($sql);
foreach ($params as $k => $v) {
    $stmt->bindValue($k, $v, SQLITE3_INTEGER);
}
$result = $stmt->execute();
$bookings = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $bookings[] = $row;
}

// Fetch individual participant details for all displayed bookings
$participantsByBooking = [];
if (!empty($bookings)) {
    $bids = implode(',', array_map(fn($b) => (int)$b['id'], $bookings));
    $pres = $db->query("SELECT booking_id, name, email FROM booking_participants WHERE booking_id IN ({$bids}) ORDER BY id ASC");
    while ($pr = $pres->fetchArray(SQLITE3_ASSOC)) {
        $participantsByBooking[(int)$pr['booking_id']][] = $pr;
    }
}

// Workshops list for filter dropdown
$wsResult = $db->query('SELECT id, title FROM workshops ORDER BY sort_order ASC, title ASC');
$allWorkshops = [];
while ($row = $wsResult->fetchArray(SQLITE3_ASSOC)) {
    $allWorkshops[] = $row;
}

// Confirmed bookings for Rechnung modal (one card per booking)
$confirmedBookingsForRechnung = [];
if ($workshop && !$isArchiveView) {
    $rcStmt = $db->prepare('SELECT id, name, email, organization FROM bookings WHERE workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0 ORDER BY confirmed_at ASC, created_at ASC');
    $rcStmt->bindValue(':wid', $workshopId, SQLITE3_INTEGER);
    $rcResult = $rcStmt->execute();
    while ($rcRow = $rcResult->fetchArray(SQLITE3_ASSOC)) {
        $confirmedBookingsForRechnung[] = $rcRow;
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
    <title>Buchungen - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .email-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.8); z-index: 999;
            align-items: center; justify-content: center; padding: 1rem;
        }
        .email-modal-overlay.open { display: flex; }
        .email-modal {
            background: var(--bg-alt); border: 1px solid var(--border);
            border-radius: 12px; padding: 2rem; width: 100%; max-width: 500px;
        }
        .email-modal h3 {
            font-family: var(--font-h); font-size: 1.3rem; font-weight: 400; margin-bottom: 1rem;
        }
        .rechnung-modal {
            background: var(--bg-alt); border: 1px solid var(--border);
            border-radius: 12px; padding: 2rem; width: 100%; max-width: 680px;
            max-height: 90vh; overflow-y: auto;
        }
        .rechnung-modal h3 {
            font-family: var(--font-h); font-size: 1.3rem; font-weight: 400; margin-bottom: 1.25rem;
        }
        .rechnung-grid-2 {
            display: grid; grid-template-columns: 1fr 1fr; gap: 0.75rem;
        }
        .rechnung-section-label {
            font-size: 0.68rem; font-weight: 700; text-transform: uppercase;
            letter-spacing: 1.5px; color: var(--dim); margin: 1.25rem 0 0.6rem;
        }
        .rechnung-card {
            border: 1px solid var(--border); border-radius: var(--radius);
            margin-bottom: 0.6rem; overflow: hidden;
        }
        .rechnung-card-header {
            display: flex; align-items: center; justify-content: space-between;
            padding: 0.65rem 1rem; background: var(--surface-soft);
            cursor: pointer; gap: 0.75rem; user-select: none;
        }
        .rechnung-card-header label { display: flex; align-items: center; gap: 0.6rem; cursor: pointer; min-width: 0; flex: 1; }
        .rechnung-card-summary { font-size: 0.83rem; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
        .rechnung-card-toggle { font-size: 0.65rem; color: var(--dim); flex-shrink: 0; }
        .rechnung-card-body { padding: 1rem; border-top: 1px solid var(--border); transition: opacity 0.15s; }
        .rechnung-card-body.rc-hidden { display: none; }
    
        .booking-filter-bar {
            margin-bottom: 1.5rem;
        }
        .booking-filter-form {
            display: flex;
            gap: 0.75rem;
            align-items: center;
            flex-wrap: wrap;
        }
        .booking-filter-select {
            min-width: 260px;
            padding: 8px 14px;
            background: var(--input-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            color: var(--text);
            font-family: var(--font-b);
            font-size: 0.85rem;
        }
        .booking-filter-select option {
            background: var(--input-option-bg);
            color: var(--text);
        }
        html[data-theme="dark"] .booking-filter-select,
        html[data-theme="dark"] .booking-filter-select option {
            background: #141414;
            color: #ffffff;
        }
        html[data-theme="light"] .booking-filter-select,
        html[data-theme="light"] .booking-filter-select option {
            background: #f6f1e8;
            color: #1f1812;
        }

        .workshop-actions {
            margin-bottom: 1.5rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.85rem 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            flex-wrap: wrap;
        }
        .workshop-actions-copy {
            min-width: 220px;
            flex: 1;
        }
        .workshop-actions-title {
            font-size: 0.7rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--dim);
            margin-bottom: 0.2rem;
        }
        .workshop-actions-note {
            color: var(--muted);
            font-size: 0.8rem;
            line-height: 1.35;
        }
        .workshop-actions-buttons {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            flex-wrap: wrap;
        }
        .bulk-email-modal {
            max-width: 680px;
        }

        @media (max-width: 700px) {
            .booking-filter-select {
                width: 100%;
                min-width: 0;
            }
            .workshop-actions {
                align-items: flex-start;
            }
            .workshop-actions-buttons {
                width: 100%;
            }
            .workshop-actions-buttons .btn-admin {
                flex: 1;
            }
        }


        .workshop-actions {
            padding: 1rem;
            display: grid;
            gap: 1rem;
        }
        .workshop-actions-copy {
            min-width: 0;
            flex: 1;
        }
        .workshop-actions-note {
            line-height: 1.4;
        }
        .workshop-actions-buttons {
            display: grid;
            width: 100%;
            grid-template-columns: repeat(2, minmax(170px, 1fr));
            gap: 0.65rem;
        }
        .workshop-action-btn {
            width: 100%;
            min-height: 78px;
            justify-content: flex-start;
            align-items: flex-start;
            text-align: left;
            padding: 0.8rem 0.9rem;
            gap: 0.65rem;
            background: var(--surface-soft);
            color: var(--text);
            border-color: var(--border);
        }
        .workshop-action-btn:hover {
            transform: translateY(-1px);
        }
        .workshop-action-btn.action-rechnung {
            border-color: rgba(46, 204, 113, 0.35);
        }
        .workshop-action-btn.action-email {
            border-color: rgba(245, 166, 35, 0.38);
        }
        .workshop-action-btn.action-export {
            border-color: rgba(52, 152, 219, 0.38);
        }
        .workshop-action-icon {
            width: 28px;
            height: 28px;
            border-radius: 999px;
            border: 1px solid currentColor;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            margin-top: 0;
        }
        .workshop-action-icon svg {
            width: 14px;
            height: 14px;
            display: block;
        }
        .workshop-action-copy {
            min-width: 0;
        }
        .workshop-action-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text);
            line-height: 1.2;
            margin-bottom: 0.18rem;
        }
        .workshop-action-sub {
            display: block;
            font-size: 0.72rem;
            color: var(--dim);
            line-height: 1.35;
        }
        .modal-btn-row {
            display: flex;
            gap: 0.75rem;
        }
        .modal-btn-row .btn-submit {
            flex: 1;
        }
        .rechnung-toolbar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            border-top: 1px solid var(--border);
            padding-top: 1.1rem;
            margin-top: 0.6rem;
            gap: 0.65rem;
            flex-wrap: wrap;
        }
        .rechnung-toolbar-actions {
            display: flex;
            gap: 0.4rem;
            flex-wrap: wrap;
        }
        .rechnung-toolbar-actions .btn-admin {
            min-height: 30px;
            padding-inline: 10px;
            font-size: 0.72rem;
        }
        .admin-table-scroll {
            margin-bottom: 0;
        }

        @media (max-width: 980px) {
            .workshop-actions-buttons {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        @media (max-width: 700px) {
            .email-modal-overlay {
                padding: 0.55rem;
            }
            .email-modal,
            .rechnung-modal {
                padding: 1.1rem;
                max-height: calc(100vh - 1.1rem);
            }
            .rechnung-grid-2 {
                grid-template-columns: 1fr;
            }
            .rechnung-card-header {
                padding: 0.6rem 0.75rem;
            }
            .rechnung-card-summary {
                white-space: normal;
                line-height: 1.35;
            }
            .booking-filter-form .btn-admin {
                width: 100%;
            }
            .workshop-actions-buttons {
                width: 100%;
                grid-template-columns: 1fr;
            }
            .workshop-action-btn {
                min-height: 72px;
            }
            .modal-btn-row {
                flex-direction: column;
            }
            .modal-btn-row .btn-admin,
            .modal-btn-row .btn-submit {
                width: 100%;
            }
            .rechnung-toolbar {
                align-items: stretch;
            }
            .rechnung-toolbar-actions {
                width: 100%;
            }
            .rechnung-toolbar-actions .btn-admin {
                flex: 1;
            }
        }

    </style>
</head>
<body class="admin-page">
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                Buchungen
                <?php if ($workshop && !$isArchiveView): ?>
                    <span style="color:var(--muted);font-size:0.6em;font-weight:300;"> - <?= e($workshop['title']) ?></span>
                <?php elseif ($isArchiveView): ?>
                    <span style="color:var(--muted);font-size:0.6em;font-weight:300;"> - Archiv</span>
                <?php endif; ?>
            </h1>
        </div>

        <?= render_flash() ?>

        <!-- Filter -->
        <div class="booking-filter-bar">
            <form method="GET" class="booking-filter-form">
                <select name="workshop_id" class="booking-filter-select">
                    <option value="0" <?= !$isArchiveView && $workshopId === 0 ? 'selected' : '' ?>>Alle Workshops</option>
                    <option value="archive" <?= $isArchiveView ? 'selected' : '' ?>>Archiv</option>
                    <?php foreach ($allWorkshops as $ws): ?>
                        <option value="<?= $ws['id'] ?>" <?= !$isArchiveView && $workshopId === (int) $ws['id'] ? 'selected' : '' ?>><?= e($ws['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-admin">Filtern</button>
            </form>
        </div>

        <?php if ($workshop && !$isArchiveView): ?>
        <div class="workshop-actions">
            <div class="workshop-actions-copy">
                <div class="workshop-actions-title">Workshop-Aktionen</div>
                <div class="workshop-actions-note">
                    Die Aktionen arbeiten mit bestaetigten Buchungen dieses Workshops. Rechnungen verwalten Sie im Reiter "Rechnungen".
                </div>
            </div>
            <div class="workshop-actions-buttons">

                <button type="button" class="btn-admin workshop-action-btn action-email" onclick="openBulkEmailModal()">
                    <span class="workshop-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="4"/><path d="M16 8v5a4 4 0 1 0 4 0V8"/><path d="M12 20c-4.4 0-8-3.6-8-8s3.6-8 8-8 8 3.6 8 8"/></svg></span>
                    <span class="workshop-action-copy">
                        <span class="workshop-action-label">E-Mail an alle</span>
                        <span class="workshop-action-sub">Eine Sammelnachricht an alle bestaetigten Kontakte senden.</span>
                    </span>
                </button>
                <a href="<?= e(admin_url('bookings', ['workshop_id' => (int) $workshop['id'], 'export_participants' => 1])) ?>" class="btn-admin workshop-action-btn action-export">
                    <span class="workshop-action-icon"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><path d="m9 16 2 2 4-4"/></svg></span>
                    <span class="workshop-action-copy">
                        <span class="workshop-action-label">Teilnehmerliste exportieren</span>
                        <span class="workshop-action-sub">Namen und E-Mail-Adressen als Excel-Datei herunterladen.</span>
                    </span>
                </a>
            </div>
        </div>
        <?php endif; ?>

        <?php if (empty($bookings)): ?>
            <p style="color:var(--muted);"><?= $isArchiveView ? 'Keine archivierten Buchungen gefunden.' : 'Keine Buchungen gefunden.' ?></p>
        <?php else: ?>
            <p style="color:var(--dim);font-size:0.85rem;margin-bottom:1rem;"><?= count($bookings) ?> <?= $isArchiveView ? 'archivierte Buchung(en)' : 'Buchung(en)' ?></p>
            <div class="admin-table-scroll">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Organisation</th>
                        <th>Workshop</th>
                        <th>TN</th>
                        <th>Rabatt</th>
                        <th>Status</th>
                        <th>Datum</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b):
    $bParts = $participantsByBooking[(int)$b['id']] ?? [];
    $isIndividual = ($b['booking_mode'] ?? 'group') === 'individual';
    $bookingCurrency = trim((string)($b['booking_currency'] ?? ''));
    if ($bookingCurrency === '') {
        $bookingCurrency = trim((string)($b['workshop_currency'] ?? 'EUR'));
    }
    $isArchivedRow = (int) ($b['archived'] ?? 0) === 1;
    $rowDateValue = $isArchivedRow && !empty($b['archived_at'])
        ? (string) $b['archived_at']
        : (string) $b['created_at'];
?>
                    <tr>
                        <td style="color:var(--text);"><?= e($b['name']) ?></td>
                        <td><a href="mailto:<?= e($b['email']) ?>" style="color:var(--muted);"><?= e($b['email']) ?></a></td>
                        <td><?= e($b['organization']) ?></td>
                        <td><?= e($b['workshop_title']) ?></td>
                        <td>
    <?= (int) $b['participants'] ?>
    <?php if ($isIndividual && !empty($bParts)): ?>
        <button type="button"
                onclick="var r=this.closest('tr').nextElementSibling;r.style.display=r.style.display==='none'?'':'none';"
                style="font-size:0.65rem;padding:1px 6px;margin-left:4px;background:var(--surface-soft);border:1px solid var(--border);border-radius:3px;color:var(--muted);cursor:pointer;">
            einzeln
        </button>
    <?php endif; ?>
</td>
<td>
    <?php if ((float) $b['discount_amount'] > 0): ?>
        <div style="color:var(--text);font-weight:600;"><?= e($b['discount_code']) ?></div>
        <div style="font-size:0.75rem;color:#2ecc71;">-<?= e(format_price((float) $b['discount_amount'], $bookingCurrency)) ?></div>
    <?php elseif (trim((string)($b['discount_code'] ?? '')) !== ''): ?>
        <div style="color:var(--text);"><?= e($b['discount_code']) ?></div>
        <div style="font-size:0.75rem;color:var(--dim);">kein Abzug</div>
    <?php else: ?>
        <span style="color:var(--dim);">-</span>
    <?php endif; ?>
</td>
                        <td>
                            <?php if ($isArchivedRow): ?>
                                <span class="status-badge status-pending">Archiviert</span>
                            <?php elseif ($b['confirmed']): ?>
                                <span class="status-badge status-confirmed">Best&auml;tigt</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">Ausstehend</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?= e(date('d.m.Y H:i', strtotime($rowDateValue))) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a href="<?= e(admin_url('booking-edit', ['id' => (int) $b['id']])) ?>" class="btn-admin" title="Bearbeiten">Bearbeiten</a>

                                <?php if (!$b['confirmed'] && !$isArchivedRow): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="confirm_booking_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn-admin btn-success" title="Manuell bestätigen">Bestätigen</button>
                                </form>
                                <?php endif; ?>

                                <button type="button" class="btn-admin"
                                        onclick='openEmailModal(<?= (int) $b['id'] ?>, <?= json_for_html((string) $b['name']) ?>, <?= json_for_html((string) $b['email']) ?>)'
                                        title="E-Mail senden">E-Mail</button>

                                <?php if (!$isArchivedRow): ?>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Buchung wirklich archivieren?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="archive_booking_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn-admin btn-danger" title="Archivieren">Archivieren</button>
                                </form>
                                <?php endif; ?>
                            </div>
                        </td>
                    </tr>
                    <?php if ($isIndividual && !empty($bParts)): ?>
                    <tr style="display:none;background:rgba(245,166,35,0.04);" class="parts-row">
                        <td colspan="9" style="padding:0.75rem 1rem 0.75rem 2.5rem;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--dim);margin-bottom:0.5rem;">
                                Einzeln angemeldete Teilnehmer:innen
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                                <?php foreach ($bParts as $p): ?>
                                <span style="font-size:0.8rem;padding:3px 10px;background:var(--surface-soft);border:1px solid var(--border);border-radius:4px;color:var(--muted);">
                                    <?= e($p['name']) ?>
                                    <a href="mailto:<?= e($p['email']) ?>" style="color:var(--dim);margin-left:4px;"><?= e($p['email']) ?></a>
                                </span>
                                <?php endforeach; ?>
                            </div>
                        </td>
                    </tr>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Email Modal -->
<div class="email-modal-overlay" id="emailModal">
    <div class="email-modal">
        <h3>E-Mail senden</h3>
        <p style="color:var(--muted);font-size:0.85rem;margin-bottom:1rem;" id="emailRecipient"></p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="email_booking_id" id="emailBookingId">
            <div class="form-group">
                <label for="email_subject">Betreff</label>
                <input type="text" id="email_subject" name="email_subject" required placeholder="Betreff der E-Mail">
            </div>
            <div class="form-group">
                <label for="email_message">Nachricht</label>
                <textarea id="email_message" name="email_message" rows="6" required placeholder="Ihre Nachricht..."></textarea>
            </div>
            <div class="modal-btn-row">
                <button type="submit" class="btn-submit">Senden</button>
                <button type="button" class="btn-admin" onclick="closeEmailModal()">Abbrechen</button>
            </div>
        </form>
    </div>
</div>

<?php if ($workshop && !$isArchiveView):
    $evtDateDefault   = !empty($workshop['event_date'])
        ? format_event_date($workshop['event_date'], $workshop['event_date_end'] ?? '')
        : '';
    $pos1LabelDefault  = !empty($workshop['tag_label']) ? $workshop['tag_label'] : $workshop['title'];
    $pos1BetragDefault = !empty($workshop['price_netto'])
        ? number_format((float)$workshop['price_netto'], 2, ',', '.')
        : '';
    $selStyle = 'width:100%;padding:8px 14px;background:var(--input-bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-family:var(--font-b);font-size:0.9rem;';
?>
<!-- Bulk Email Modal -->
<div class="email-modal-overlay" id="bulkEmailModal">
    <div class="email-modal bulk-email-modal">
        <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:0.3rem;">
            <h3 style="margin-bottom:0;">E-Mail an alle senden</h3>
            <button type="button" onclick="closeBulkEmailModal()" style="background:none;border:none;color:var(--dim);font-size:1.3rem;cursor:pointer;line-height:1;">&times;</button>
        </div>
        <p style="color:var(--dim);font-size:0.83rem;margin-bottom:1rem;">
            Diese Nachricht wird an alle bestätigten Buchenden und einzeln erfassten Teilnehmer:innen dieses Workshops gesendet.
        </p>
        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="email_all_submit" value="1">
            <input type="hidden" name="bulk_workshop_id" value="<?= $workshop['id'] ?>">
            <div class="form-group">
                <label for="bulk_subject">Betreff</label>
                <input type="text" id="bulk_subject" name="bulk_subject" required placeholder="Betreff der E-Mail">
            </div>
            <div class="form-group">
                <label for="bulk_message">Nachricht</label>
                <textarea id="bulk_message" name="bulk_message" rows="6" required placeholder="Ihre Nachricht an alle Teilnehmer:innen..."></textarea>
            </div>
            <div class="modal-btn-row">
                <button type="submit" class="btn-submit"
                        onclick='return confirm(<?= json_for_html("E-Mail an alle bestätigten Teilnehmer:innen von \\\"{$workshop['title']}\\\" senden?") ?>)'>
                    An alle senden &rarr;
                </button>
                <button type="button" class="btn-admin" onclick="closeBulkEmailModal()">Abbrechen</button>
            </div>
        </form>
    </div>
</div>

<!-- Rechnung Modal -->
<div class="email-modal-overlay" id="rechnungModal">
  <div class="rechnung-modal">
    <div style="display:flex;align-items:baseline;justify-content:space-between;margin-bottom:0.25rem;">
        <h3 style="margin-bottom:0;">Rechnungen erstellen &amp; senden</h3>
        <button type="button" onclick="closeRechnungModal()" style="background:none;border:none;color:var(--dim);font-size:1.3rem;cursor:pointer;line-height:1;">&times;</button>
    </div>
    <p style="color:var(--dim);font-size:0.8rem;margin-bottom:1.25rem;">
        <strong style="color:var(--muted);"><?= e($workshop['title']) ?></strong>
        &mdash; pro Buchung wird eine individuelle Rechnung verschickt.
    </p>

    <form method="POST">
        <?= csrf_field() ?>
        <input type="hidden" name="rechnung_submit" value="1">
        <input type="hidden" name="rechnung_workshop_id" value="<?= $workshop['id'] ?>">

        <!-- -- Gemeinsame Felder ----------------------------------------- -->
        <div class="rechnung-section-label" style="margin-top:0;">Gemeinsame Rechnungsdaten</div>
        <div class="rechnung-grid-2" style="margin-bottom:0.6rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label>Rechnungsdatum</label>
                <input type="date" id="r_rechnung_datum" name="r_rechnung_datum"
                       value="<?= date('Y-m-d') ?>" oninput="rcUpdateNrs()">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Startnummer <span style="color:var(--dim);font-size:0.78em;">(+1 je Empfänger)</span></label>
                <input type="number" id="r_start_nr" name="r_start_nr" value="1" min="1" oninput="rcUpdateNrs()">
            </div>
        </div>
        <div class="form-group" style="margin-bottom:0.6rem;">
            <label>Für (Leistungsbeschreibung)</label>
            <input type="text" name="r_fuer_text" value="die Abhaltung des Workshops"
                   placeholder="z.B. die Abhaltung des zweistündigen Workshops">
        </div>
        <div class="rechnung-grid-2" style="margin-bottom:0.6rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label>Workshop-Titel</label>
                <input type="text" name="r_workshop_titel" value="<?= e($workshop['title']) ?>">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>Veranstaltungsdatum</label>
                <input type="text" name="r_veranstaltungs_datum" value="<?= e($evtDateDefault) ?>"
                       placeholder="z.B. 15. März 2025, 09:00 Uhr">
            </div>
        </div>

        <div class="rechnung-section-label">Positionen (netto)</div>
        <div class="rechnung-grid-2" style="margin-bottom:0.4rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label>Position 1</label>
                <input type="text" name="r_pos1_label" value="<?= e($pos1LabelDefault) ?>" required>
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>EUR (netto)</label>
                <input type="text" name="r_pos1_betrag" value="<?= e($pos1BetragDefault) ?>"
                       placeholder="1.200,00" required>
            </div>
        </div>
        <div class="rechnung-grid-2" style="margin-bottom:0.2rem;">
            <div class="form-group" style="margin-bottom:0;">
                <label>Position 2 <span style="color:var(--dim);font-size:0.78em;">(optional)</span></label>
                <input type="text" name="r_pos2_label" placeholder="z.B. Reisekosten">
            </div>
            <div class="form-group" style="margin-bottom:0;">
                <label>EUR (netto) <span style="color:var(--dim);font-size:0.78em;">(optional)</span></label>
                <input type="text" name="r_pos2_betrag" placeholder="200,00">
            </div>
        </div>
        <p style="font-size:0.73rem;color:var(--dim);margin:0.25rem 0 0.35rem;">
            Zwischensumme, 20&nbsp;% USt. und SUMME werden automatisch berechnet.
        </p>
        <p style="font-size:0.73rem;color:var(--dim);margin:0 0 0.6rem;">
            Vorhandene Buchungsrabatte werden pro Empfänger automatisch als eigene Rabattposition abgezogen.
        </p>

        <div class="rechnung-section-label">Absender</div>
        <div class="form-group" style="margin-bottom:0.5rem;">
            <label>Name (Unterschrift)</label>
            <input type="text" name="r_absender_name" placeholder="Mag.a Maria Muster">
        </div>

        <!-- -- Pro-Buchung-Karten ---------------------------------------- -->
        <div class="rechnung-toolbar">
            <div class="rechnung-section-label" style="margin:0;">
                Empfänger
                <span style="color:var(--dim);font-weight:400;text-transform:none;letter-spacing:0;">
                    (<?= count($confirmedBookingsForRechnung) ?> bestätigt)
                </span>
            </div>
            <div class="rechnung-toolbar-actions">
                <button type="button" class="btn-admin" onclick="rcSelectAll(true)">
                    Alle
                </button>
                <button type="button" class="btn-admin" onclick="rcSelectAll(false)">
                    Keine
                </button>
            </div>
        </div>

        <?php if (empty($confirmedBookingsForRechnung)): ?>
        <p style="color:var(--dim);font-size:0.85rem;padding:0.75rem 0;">
            Keine bestätigten Buchungen für diesen Workshop.
        </p>
        <?php endif; ?>

        <?php foreach ($confirmedBookingsForRechnung as $i => $cb): ?>
        <div class="rechnung-card" id="rc-card-<?= $i ?>">
            <!-- Card header (click = toggle body) -->
            <div class="rechnung-card-header" onclick="rcToggle(<?= $i ?>)">
                <label onclick="event.stopPropagation();" style="display:flex;align-items:center;gap:0.6rem;cursor:pointer;min-width:0;flex:1;">
                    <input type="checkbox" name="r_booking_selected[<?= $i ?>]" value="1" checked
                           class="rc-checkbox"
                           onchange="rcDimCard(<?= $i ?>, this.checked)">
                    <span class="rechnung-card-summary">
                        <strong><?= e($cb['organization'] ?: $cb['name']) ?></strong>
                        <?php if ($cb['organization'] && $cb['organization'] !== $cb['name']): ?>
                            <span style="color:var(--dim);"> - <?= e($cb['name']) ?></span>
                        <?php endif; ?>
                        <span style="color:var(--dim);"> · <?= e($cb['email']) ?></span>
                    </span>
                </label>
                <span class="rechnung-card-toggle" id="rc-tog-<?= $i ?>">&#9650;</span>
            </div>
            <!-- Card body (collapsible) -->
            <div class="rechnung-card-body" id="rc-body-<?= $i ?>">
                <input type="hidden" name="r_booking_id[<?= $i ?>]" value="<?= (int) $cb['id'] ?>">
                <div class="rechnung-grid-2" style="margin-bottom:0.5rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Firma / Organisation</label>
                        <input type="text" name="r_booking_empfaenger[<?= $i ?>]"
                               value="<?= e($cb['organization']) ?>" placeholder="Firma GmbH">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Adresse</label>
                        <input type="text" name="r_booking_adresse[<?= $i ?>]" placeholder="Musterstraße 12">
                    </div>
                </div>
                <div class="rechnung-grid-2" style="margin-bottom:0.5rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>PLZ &amp; Ort</label>
                        <input type="text" name="r_booking_plz_ort[<?= $i ?>]" placeholder="1010 Wien">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>Anrede</label>
                        <select name="r_booking_anrede[<?= $i ?>]" style="<?= $selStyle ?>">
                            <option value="Herrn">Herrn</option>
                            <option value="Frau">Frau</option>
                        </select>
                    </div>
                </div>
                <div class="rechnung-grid-2" style="margin-bottom:0.5rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label>z. Hd. Name</label>
                        <input type="text" name="r_booking_kontakt_name[<?= $i ?>]"
                               value="<?= e($cb['name']) ?>">
                    </div>
                    <div class="form-group" style="margin-bottom:0;">
                        <label>E-Mail <span style="color:var(--dim);font-size:0.78em;">(Versand &amp; auf Rechnung)</span></label>
                        <input type="email" name="r_booking_kontakt_email[<?= $i ?>]"
                               value="<?= e($cb['email']) ?>">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0;">
                    <label>Rechnungs-Nr. <span style="color:var(--dim);font-size:0.78em;">(individuell anpassbar)</span></label>
                    <input type="text" class="rc-nr" name="r_booking_rechnungs_nr[<?= $i ?>]"
                           id="rc-nr-<?= $i ?>" placeholder="0001/<?= date('Y') ?>"
                           oninput="this.dataset.custom='1'">
                </div>
            </div>
        </div>
        <?php endforeach; ?>

        <div class="modal-btn-row" style="margin-top:1.25rem;">
            <button type="submit" class="btn-submit">
                Rechnungen senden &rarr;
            </button>
            <button type="button" class="btn-admin" onclick="closeRechnungModal()">Abbrechen</button>
        </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
// -- Email modal --------------------------------------------------------------
function openEmailModal(id, name, email) {
    document.getElementById('emailBookingId').value = id;
    document.getElementById('emailRecipient').textContent = 'An: ' + name + ' (' + email + ')';
    document.getElementById('emailModal').classList.add('open');
}
function closeEmailModal() {
    document.getElementById('emailModal').classList.remove('open');
}
document.getElementById('emailModal').addEventListener('click', function(e) {
    if (e.target === this) closeEmailModal();
});

// -- Bulk email modal ---------------------------------------------------------
function openBulkEmailModal() {
    var modal = document.getElementById('bulkEmailModal');
    if (modal) modal.classList.add('open');
}
function closeBulkEmailModal() {
    var modal = document.getElementById('bulkEmailModal');
    if (modal) modal.classList.remove('open');
}
var bulkEmailModal = document.getElementById('bulkEmailModal');
if (bulkEmailModal) {
    bulkEmailModal.addEventListener('click', function(e) {
        if (e.target === this) closeBulkEmailModal();
    });
}

// -- Rechnung modal -----------------------------------------------------------
function openRechnungModal() {
    document.getElementById('rechnungModal').classList.add('open');
    rcUpdateNrs();           // initialise invoice numbers on open
}
function closeRechnungModal() {
    document.getElementById('rechnungModal').classList.remove('open');
}
var rechnungModal = document.getElementById('rechnungModal');
if (rechnungModal) {
    rechnungModal.addEventListener('click', function(e) {
        if (e.target === this) closeRechnungModal();
    });
}

// Auto-number: sets each card's invoice number from start nr + date year
// unless the field has been manually edited (data-custom attribute set)
function rcUpdateNrs() {
    var startEl = document.getElementById('r_start_nr');
    var dateEl  = document.getElementById('r_rechnung_datum');
    if (!startEl) return;
    var start = parseInt(startEl.value) || 1;
    var year  = dateEl && dateEl.value ? dateEl.value.substring(0, 4) : new Date().getFullYear();
    document.querySelectorAll('.rc-nr').forEach(function(el, i) {
        if (!el.dataset.custom) {
            el.value = String(start + i).padStart(4, '0') + '/' + year;
        }
    });
}

// Toggle card body open/closed
function rcToggle(i) {
    var body = document.getElementById('rc-body-' + i);
    var tog  = document.getElementById('rc-tog-' + i);
    if (!body) return;
    body.classList.toggle('rc-hidden');
    if (tog) tog.textContent = body.classList.contains('rc-hidden') ? '\u25BC' : '\u25B2';
}

// Dim card when deselected
function rcDimCard(i, checked) {
    var body = document.getElementById('rc-body-' + i);
    if (body) body.style.opacity = checked ? '1' : '0.4';
}

// Select / deselect all cards
function rcSelectAll(state) {
    document.querySelectorAll('.rc-checkbox').forEach(function(cb, i) {
        cb.checked = state;
        rcDimCard(i, state);
    });
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') { closeEmailModal(); closeBulkEmailModal(); closeRechnungModal(); }
});
</script>

<script src="/assets/site-ui.js"></script>

</body>
</html>

