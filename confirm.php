<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/email.php';

$token = trim($_GET['token'] ?? '');
$status = 'invalid';
$workshopTitle = '';

if ($token && strlen($token) === 64 && ctype_xdigit($token)) {
    $stmt = $db->prepare('SELECT b.*, w.title AS workshop_title, w.slug AS workshop_slug FROM bookings b JOIN workshops w ON b.workshop_id = w.id WHERE b.token = :token');
    $stmt->bindValue(':token', $token, SQLITE3_TEXT);
    $booking = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if ($booking) {
        $workshopTitle = $booking['workshop_title'];

        if ($booking['confirmed']) {
            $status = 'already';
        } else {
            // Check if token is older than 48h
            $created = strtotime($booking['created_at']);
            if (time() - $created > 48 * 3600) {
                $status = 'expired';
            } else {
                // Check capacity
                $workshop = get_workshop_by_id($db, $booking['workshop_id']);
                $booked = count_confirmed_bookings($db, $booking['workshop_id']);
                $capacity = (int) $workshop['capacity'];

                if ($capacity > 0 && ($booked + $booking['participants']) > $capacity) {
                    $status = 'full';
                } else {
                    // Confirm the booking
                    $upd = $db->prepare("UPDATE bookings SET confirmed = 1, confirmed_at = datetime('now') WHERE id = :id");
                    $upd->bindValue(':id', $booking['id'], SQLITE3_INTEGER);
                    $upd->execute();

                    $status = 'confirmed';

                    // Send confirmation email to booker
                    send_booking_confirmed_email($booking['email'], $booking['name'], $workshopTitle);

                    // Notify admin
                    send_admin_notification($workshopTitle, $booking);
                }
            }
        }
    }
}

$messages = [
    'confirmed' => ['Buchung bestätigt!', 'Ihre Buchung wurde erfolgreich bestätigt. Wir werden uns in Kürze mit weiteren Details bei Ihnen melden.', 'check'],
    'already'   => ['Bereits bestätigt', 'Diese Buchung wurde bereits bestätigt. Sie brauchen nichts weiter zu tun.', 'info'],
    'expired'   => ['Link abgelaufen', 'Dieser Bestätigungslink ist leider abgelaufen (48 Stunden). Bitte buchen Sie erneut.', 'clock'],
    'full'      => ['Workshop ausgebucht', 'Leider ist der Workshop inzwischen ausgebucht. Bitte kontaktieren Sie uns für Alternativen.', 'alert'],
    'invalid'   => ['Ungültiger Link', 'Dieser Bestätigungslink ist ungültig. Bitte überprüfen Sie die URL aus Ihrer E-Mail.', 'alert'],
];

$msg = $messages[$status];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($msg[0]) ?> – <?= e(SITE_NAME) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<div class="confirm-page">
    <div class="confirm-box">
        <div class="icon" aria-hidden="true">
            <?php if ($msg[2] === 'check'): ?>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#2ecc71" stroke-width="2"><polyline points="20 6 9 17 4 12"/></svg>
            <?php elseif ($msg[2] === 'info'): ?>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#3498db" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="16" x2="12" y2="12"/><line x1="12" y1="8" x2="12.01" y2="8"/></svg>
            <?php elseif ($msg[2] === 'clock'): ?>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#f39c12" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
            <?php else: ?>
                <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#e74c3c" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>
            <?php endif; ?>
        </div>

        <h1><?= e($msg[0]) ?></h1>

        <?php if ($workshopTitle): ?>
            <p style="color:#fff;font-size:1.1rem;margin-bottom:0.5rem;"><?= e($workshopTitle) ?></p>
        <?php endif; ?>

        <p><?= e($msg[1]) ?></p>

        <a href="index.php" class="btn-primary" style="display:inline-block;text-decoration:none;margin-top:0.5rem;">&larr; Zurück zu den Workshops</a>
    </div>
</div>

</body>
</html>
