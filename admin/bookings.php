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
        $stmt = $db->prepare('DELETE FROM bookings WHERE id = :id');
        $stmt->bindValue(':id', (int) $_POST['delete_booking_id'], SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Buchung gelöscht.');
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

    // Send custom email
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
                    <?php foreach ($bookings as $b): ?>
                    <tr>
                        <td style="color:#fff;"><?= e($b['name']) ?></td>
                        <td><a href="mailto:<?= e($b['email']) ?>" style="color:var(--muted);"><?= e($b['email']) ?></a></td>
                        <td><?= e($b['organization']) ?></td>
                        <td><?= e($b['workshop_title']) ?></td>
                        <td><?= (int) $b['participants'] ?></td>
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
