<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/email.php';
require_admin();

$workshopId = (int) ($_GET['workshop_id'] ?? 0);
$workshop = null;
if ($workshopId) {
    $workshop = get_workshop_by_id($db, $workshopId);
}

// ── Handle actions ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {

    // Delete booking
    if (isset($_POST['delete_booking_id'])) {
        $delId = (int) $_POST['delete_booking_id'];

        // Fetch booking data before deletion for the cancellation email
        $dstmt = $db->prepare('SELECT b.name, b.email, b.confirmed, w.title AS workshop_title FROM bookings b JOIN workshops w ON b.workshop_id = w.id WHERE b.id = :id');
        $dstmt->bindValue(':id', $delId, SQLITE3_INTEGER);
        $drow = $dstmt->execute()->fetchArray(SQLITE3_ASSOC);

        $stmt = $db->prepare('DELETE FROM bookings WHERE id = :id');
        $stmt->bindValue(':id', $delId, SQLITE3_INTEGER);
        $stmt->execute();

        // Send cancellation email only if the booking was confirmed
        if ($drow && $drow['confirmed']) {
            send_booking_cancelled_email($drow['email'], $drow['name'], $drow['workshop_title']);
        }

        flash('success', 'Buchung gelöscht.' . ($drow && $drow['confirmed'] ? ' Stornierungsmail gesendet.' : ''));
        redirect('bookings.php' . ($workshopId ? "?workshop_id={$workshopId}" : ''));
    }

    // Manually confirm booking
    if (isset($_POST['confirm_booking_id'])) {
        $bid = (int) $_POST['confirm_booking_id'];
        $stmt = $db->prepare("UPDATE bookings SET confirmed = 1, confirmed_at = datetime('now') WHERE id = :id");
        $stmt->bindValue(':id', $bid, SQLITE3_INTEGER);
        $stmt->execute();

        // Send confirmation email
        $bstmt = $db->prepare('SELECT b.*, w.title AS workshop_title FROM bookings b JOIN workshops w ON b.workshop_id = w.id WHERE b.id = :id');
        $bstmt->bindValue(':id', $bid, SQLITE3_INTEGER);
        $brow = $bstmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($brow) {
            send_booking_confirmed_email($brow['email'], $brow['name'], $brow['workshop_title']);
        }

        flash('success', 'Buchung manuell bestätigt und E-Mail gesendet.');
        redirect('bookings.php' . ($workshopId ? "?workshop_id={$workshopId}" : ''));
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
                send_custom_email($brow['email'], $subject, $message);
                flash('success', "E-Mail an {$brow['email']} gesendet.");
            }
        } else {
            flash('error', 'Betreff und Nachricht sind erforderlich.');
        }
        redirect('bookings.php' . ($workshopId ? "?workshop_id={$workshopId}" : ''));
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
            $bstmt = $db->prepare('SELECT DISTINCT email FROM bookings WHERE workshop_id = :wid AND confirmed = 1');
            $bstmt->bindValue(':wid', $bulkWid, SQLITE3_INTEGER);
            $bres = $bstmt->execute();
            while ($br = $bres->fetchArray(SQLITE3_ASSOC)) {
                $emails[strtolower($br['email'])] = $br['email'];
            }

            // Individual participant emails
            $pstmt = $db->prepare('
                SELECT DISTINCT bp.email FROM booking_participants bp
                JOIN bookings b ON bp.booking_id = b.id
                WHERE b.workshop_id = :wid AND b.confirmed = 1
            ');
            $pstmt->bindValue(':wid', $bulkWid, SQLITE3_INTEGER);
            $pres = $pstmt->execute();
            while ($pr = $pres->fetchArray(SQLITE3_ASSOC)) {
                $emails[strtolower($pr['email'])] = $pr['email'];
            }

            $sent = 0;
            foreach ($emails as $email) {
                send_custom_email($email, $bulkSubject, $bulkMessage);
                $sent++;
            }
            flash('success', "E-Mail an {$sent} Empfänger gesendet.");
        }
        redirect('bookings.php' . ($workshopId ? "?workshop_id={$workshopId}" : ''));
    }
}

// ── Fetch bookings ──────────────────────────────────────────────────────────
$sql = '
    SELECT b.*, w.title AS workshop_title, w.slug AS workshop_slug
    FROM bookings b
    JOIN workshops w ON b.workshop_id = w.id
';
$params = [];
if ($workshopId) {
    $sql .= ' WHERE b.workshop_id = :wid';
    $params[':wid'] = $workshopId;
}
$sql .= ' ORDER BY b.created_at DESC';

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
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buchungen – Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .email-modal-overlay {
            display: none; position: fixed; inset: 0;
            background: rgba(0,0,0,0.8); z-index: 999;
            align-items: center; justify-content: center; padding: 1rem;
        }
        .email-modal-overlay.open { display: flex; }
        .email-modal {
            background: #0d0d0d; border: 1px solid var(--border);
            border-radius: 12px; padding: 2rem; width: 100%; max-width: 500px;
        }
        .email-modal h3 {
            font-family: var(--font-h); font-size: 1.3rem; font-weight: 400; margin-bottom: 1rem;
        }
    </style>
</head>
<body>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>
                Buchungen
                <?php if ($workshop): ?>
                    <span style="color:var(--muted);font-size:0.6em;font-weight:300;"> – <?= e($workshop['title']) ?></span>
                <?php endif; ?>
            </h1>
        </div>

        <?= render_flash() ?>

        <!-- Filter -->
        <div style="margin-bottom:1.5rem;">
            <form method="GET" style="display:flex;gap:0.75rem;align-items:center;flex-wrap:wrap;">
                <select name="workshop_id" style="padding:8px 14px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:var(--radius);color:#fff;font-family:var(--font-b);font-size:0.85rem;">
                    <option value="0">Alle Workshops</option>
                    <?php foreach ($allWorkshops as $ws): ?>
                        <option value="<?= $ws['id'] ?>" <?= $workshopId == $ws['id'] ? 'selected' : '' ?>><?= e($ws['title']) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-admin">Filtern</button>
            </form>
        </div>

        <?php if ($workshop): ?>
        <!-- Bulk email to all participants of this workshop -->
        <div style="margin-bottom:2rem;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:var(--radius);padding:1.25rem;">
            <div style="font-size:0.72rem;font-weight:600;text-transform:uppercase;letter-spacing:2px;color:var(--dim);margin-bottom:1rem;">
                E-Mail an alle bestätigten Teilnehmer senden
            </div>
            <form method="POST">
                <?= csrf_field() ?>
                <input type="hidden" name="email_all_submit" value="1">
                <input type="hidden" name="bulk_workshop_id" value="<?= $workshop['id'] ?>">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:1rem;margin-bottom:0.75rem;">
                    <div class="form-group" style="margin-bottom:0;">
                        <label for="bulk_subject">Betreff</label>
                        <input type="text" id="bulk_subject" name="bulk_subject" required placeholder="Betreff der E-Mail">
                    </div>
                </div>
                <div class="form-group" style="margin-bottom:0.75rem;">
                    <label for="bulk_message">Nachricht</label>
                    <textarea id="bulk_message" name="bulk_message" rows="4" required placeholder="Ihre Nachricht an alle Teilnehmer..."></textarea>
                </div>
                <button type="submit" class="btn-admin btn-success"
                        onclick="return confirm('E-Mail an alle bestätigten Teilnehmer von &quot;<?= e(addslashes($workshop['title'])) ?>&quot; senden?')">
                    An alle senden &rarr;
                </button>
                <span style="font-size:0.78rem;color:var(--dim);margin-left:0.75rem;">
                    Geht an alle bestätigten Bucher + einzeln angegebene Teilnehmer (keine Duplikate).
                </span>
            </form>
        </div>
        <?php endif; ?>

        <?php if (empty($bookings)): ?>
            <p style="color:var(--muted);">Keine Buchungen gefunden.</p>
        <?php else: ?>
            <p style="color:var(--dim);font-size:0.85rem;margin-bottom:1rem;"><?= count($bookings) ?> Buchung(en)</p>
            <div style="overflow-x:auto;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Organisation</th>
                        <th>Workshop</th>
                        <th>TN</th>
                        <th>Status</th>
                        <th>Datum</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($bookings as $b):
                        $bParts = $participantsByBooking[(int)$b['id']] ?? [];
                        $isIndividual = ($b['booking_mode'] ?? 'group') === 'individual';
                    ?>
                    <tr>
                        <td style="color:#fff;"><?= e($b['name']) ?></td>
                        <td><a href="mailto:<?= e($b['email']) ?>" style="color:var(--muted);"><?= e($b['email']) ?></a></td>
                        <td><?= e($b['organization']) ?></td>
                        <td><?= e($b['workshop_title']) ?></td>
                        <td>
                            <?= (int) $b['participants'] ?>
                            <?php if ($isIndividual && !empty($bParts)): ?>
                                <button type="button"
                                        onclick="var r=this.closest('tr').nextElementSibling;r.style.display=r.style.display==='none'?'':'none';"
                                        style="font-size:0.65rem;padding:1px 6px;margin-left:4px;background:rgba(255,255,255,0.07);border:1px solid var(--border);border-radius:3px;color:var(--muted);cursor:pointer;">
                                    einzeln
                                </button>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($b['confirmed']): ?>
                                <span class="status-badge status-confirmed">Bestätigt</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">Ausstehend</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?= e(date('d.m.Y H:i', strtotime($b['created_at']))) ?></td>
                        <td>
                            <div class="admin-actions">
                                <a href="booking-edit.php?id=<?= $b['id'] ?>" class="btn-admin" title="Bearbeiten">Bearbeiten</a>

                                <?php if (!$b['confirmed']): ?>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="confirm_booking_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn-admin btn-success" title="Manuell bestätigen">Bestätigen</button>
                                </form>
                                <?php endif; ?>

                                <button type="button" class="btn-admin"
                                        onclick="openEmailModal(<?= $b['id'] ?>, '<?= e(addslashes($b['name'])) ?>', '<?= e(addslashes($b['email'])) ?>')"
                                        title="E-Mail senden">E-Mail</button>

                                <form method="POST" style="display:inline;" onsubmit="return confirm('Buchung wirklich löschen?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_booking_id" value="<?= $b['id'] ?>">
                                    <button type="submit" class="btn-admin btn-danger" title="Löschen">Löschen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php if ($isIndividual && !empty($bParts)): ?>
                    <tr style="display:none;background:rgba(245,166,35,0.04);" class="parts-row">
                        <td colspan="8" style="padding:0.75rem 1rem 0.75rem 2.5rem;">
                            <div style="font-size:0.68rem;font-weight:700;text-transform:uppercase;letter-spacing:1.5px;color:var(--dim);margin-bottom:0.5rem;">
                                Einzeln angemeldete Teilnehmer
                            </div>
                            <div style="display:flex;flex-wrap:wrap;gap:0.5rem;">
                                <?php foreach ($bParts as $p): ?>
                                <span style="font-size:0.8rem;padding:3px 10px;background:rgba(255,255,255,0.05);border:1px solid var(--border);border-radius:4px;color:var(--muted);">
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
            <div style="display:flex;gap:0.75rem;">
                <button type="submit" class="btn-submit" style="flex:1;">Senden</button>
                <button type="button" class="btn-admin" onclick="closeEmailModal()">Abbrechen</button>
            </div>
        </form>
    </div>
</div>

<script>
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
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') closeEmailModal();
});
</script>

</body>
</html>
