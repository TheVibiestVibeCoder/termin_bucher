<?php
require __DIR__ . '/../includes/config.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    flash('error', 'Keine Buchungs-ID angegeben.');
    redirect('bookings.php');
}

$stmt = $db->prepare('SELECT b.*, w.title AS workshop_title, w.id AS workshop_id FROM bookings b JOIN workshops w ON b.workshop_id = w.id WHERE b.id = :id');
$stmt->bindValue(':id', $id, SQLITE3_INTEGER);
$booking = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

if (!$booking) {
    flash('error', 'Buchung nicht gefunden.');
    redirect('bookings.php');
}
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    $errors[] = 'Ungueltige Sitzung.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && csrf_verify()) {
    $name         = trim($_POST['name']         ?? '');
    $email        = trim($_POST['email']        ?? '');
    $organization = trim($_POST['organization'] ?? '');
    $phone        = trim($_POST['phone']        ?? '');
    $participants = max(1, (int) ($_POST['participants'] ?? 1));
    $message      = trim($_POST['message']      ?? '');
    $confirmed    = isset($_POST['confirmed']) ? 1 : 0;

    if (strlen($name) < 2) $errors[] = 'Bitte geben Sie einen Namen ein.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    if ($participants < 1 || $participants > 500) $errors[] = 'Ungültige Teilnehmerzahl.';

    if (mb_strlen($name) > 120) $errors[] = 'Name ist zu lang.';
    if (mb_strlen($email) > 254) $errors[] = 'E-Mail-Adresse ist zu lang.';
    if (mb_strlen($organization) > 180) $errors[] = 'Organisation ist zu lang.';
    if (mb_strlen($phone) > 60) $errors[] = 'Telefonnummer ist zu lang.';
    if (mb_strlen($message) > 3000) $errors[] = 'Nachricht ist zu lang.';

    if ($confirmed) {
        $capStmt = $db->prepare('SELECT capacity FROM workshops WHERE id = :wid');
        $capStmt->bindValue(':wid', (int) $booking['workshop_id'], SQLITE3_INTEGER);
        $capRow = $capStmt->execute()->fetchArray(SQLITE3_ASSOC);
        $capacity = (int) ($capRow['capacity'] ?? 0);

        if ($capacity > 0) {
            $sumStmt = $db->prepare('SELECT COALESCE(SUM(participants), 0) AS confirmed_sum FROM bookings WHERE workshop_id = :wid AND confirmed = 1 AND id != :id');
            $sumStmt->bindValue(':wid', (int) $booking['workshop_id'], SQLITE3_INTEGER);
            $sumStmt->bindValue(':id', $id, SQLITE3_INTEGER);
            $sumRow = $sumStmt->execute()->fetchArray(SQLITE3_ASSOC);
            $confirmedSum = (int) ($sumRow['confirmed_sum'] ?? 0);

            if (($confirmedSum + $participants) > $capacity) {
                $errors[] = 'Kapazitaet ueberschritten. Buchung kann so nicht bestaetigt werden.';
            }
        }
    }

    if (empty($errors)) {
        $upd = $db->prepare('
            UPDATE bookings
            SET name = :name, email = :email, organization = :org, phone = :phone,
                participants = :participants, message = :msg, confirmed = :confirmed,
                confirmed_at = CASE WHEN :confirmed = 1 THEN COALESCE(confirmed_at, datetime("now")) ELSE NULL END
            WHERE id = :id
        ');
        $upd->bindValue(':name',         $name,         SQLITE3_TEXT);
        $upd->bindValue(':email',        $email,        SQLITE3_TEXT);
        $upd->bindValue(':org',          $organization, SQLITE3_TEXT);
        $upd->bindValue(':phone',        $phone,        SQLITE3_TEXT);
        $upd->bindValue(':participants', $participants, SQLITE3_INTEGER);
        $upd->bindValue(':msg',          $message,      SQLITE3_TEXT);
        $upd->bindValue(':confirmed',    $confirmed,    SQLITE3_INTEGER);
        $upd->bindValue(':id',           $id,           SQLITE3_INTEGER);
        $upd->execute();

        flash('success', 'Buchung gespeichert.');
        redirect('booking-edit.php?id=' . $id);
    }

    // Repopulate booking array with submitted values for re-display
    $booking['name']         = $name;
    $booking['email']        = $email;
    $booking['organization'] = $organization;
    $booking['phone']        = $phone;
    $booking['participants'] = $participants;
    $booking['message']      = $message;
    $booking['confirmed']    = $confirmed;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Buchung bearbeiten – Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>Buchung bearbeiten</h1>
        </div>

        <?= render_flash() ?>

        <?php if ($errors): ?>
            <div class="flash flash-error" style="margin-bottom:1.5rem;">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <!-- Meta info -->
        <div style="margin-bottom:1.5rem;padding:1rem 1.25rem;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:var(--radius);font-size:0.85rem;color:var(--muted);">
            <div style="margin-bottom:0.25rem;">
                <strong style="color:#fff;">Workshop:</strong> <?= e($booking['workshop_title']) ?>
            </div>
            <div style="margin-bottom:0.25rem;">
                <strong style="color:#fff;">Buchungs-ID:</strong> #<?= $booking['id'] ?>
                &nbsp;&middot;&nbsp;
                <strong style="color:#fff;">Erstellt:</strong> <?= e(date('d.m.Y H:i', strtotime($booking['created_at']))) ?>
            </div>
            <?php if ($booking['confirmed'] && $booking['confirmed_at']): ?>
            <div>
                <strong style="color:#fff;">Bestätigt:</strong> <?= e(date('d.m.Y H:i', strtotime($booking['confirmed_at']))) ?>
            </div>
            <?php endif; ?>
        </div>

        <form method="POST" style="max-width:640px;">
            <?= csrf_field() ?>

            <div class="form-group">
                <label for="name">Name *</label>
                <input type="text" id="name" name="name" required
                       value="<?= e($booking['name']) ?>">
            </div>
            <div class="form-group">
                <label for="email">E-Mail *</label>
                <input type="email" id="email" name="email" required
                       value="<?= e($booking['email']) ?>">
            </div>
            <div class="form-group">
                <label for="organization">Organisation</label>
                <input type="text" id="organization" name="organization"
                       value="<?= e($booking['organization']) ?>">
            </div>
            <div class="form-group">
                <label for="phone">Telefon</label>
                <input type="tel" id="phone" name="phone"
                       value="<?= e($booking['phone']) ?>">
            </div>
            <div class="form-group">
                <label for="participants">Anzahl Teilnehmer *</label>
                <input type="number" id="participants" name="participants" min="1" max="500" required
                       value="<?= (int) $booking['participants'] ?>">
            </div>
            <div class="form-group">
                <label for="message">Nachricht</label>
                <textarea id="message" name="message" rows="5"><?= e($booking['message']) ?></textarea>
            </div>

            <div class="form-group" style="display:flex;align-items:center;gap:0.6rem;">
                <input type="checkbox" id="confirmed" name="confirmed" value="1"
                       <?= $booking['confirmed'] ? 'checked' : '' ?>
                       style="width:auto;accent-color:#2ecc71;">
                <label for="confirmed" style="margin-bottom:0;cursor:pointer;">Buchung bestätigt</label>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
                <button type="submit" class="btn-admin btn-success">Speichern</button>
                <a href="bookings.php?workshop_id=<?= $booking['workshop_id'] ?>" class="btn-admin">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
</body>
</html>
