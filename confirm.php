<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/email.php';

$token = trim($_GET['token'] ?? '');
$status = 'invalid';
$workshopTitle = '';

if ($token && strlen($token) === 64 && ctype_xdigit($token)) {
    $confirmedBooking = null;
    $inTransaction = false;

    try {
        $db->exec('BEGIN IMMEDIATE');
        $inTransaction = true;

        $stmt = $db->prepare('
            SELECT
                b.*,
                w.title AS workshop_title,
                w.slug AS workshop_slug,
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
            WHERE b.token = :token
        ');
        $stmt->bindValue(':token', $token, SQLITE3_TEXT);
        $booking = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

        if (!$booking) {
            $status = 'invalid';
        } else {
            $workshopTitle = $booking['workshop_title'];

            if ((int) $booking['confirmed'] === 1) {
                $status = 'already';
            } else {
                $created = strtotime((string) $booking['created_at']);
                if ($created === false || time() - $created > 48 * 3600) {
                    $status = 'expired';
                } else {
                    $booked = count_confirmed_bookings($db, (int) $booking['workshop_id']);
                    $capacity = (int) $booking['workshop_capacity'];

                    if ($capacity > 0 && ($booked + (int) $booking['participants']) > $capacity) {
                        $status = 'full';
                    } else {
                        $upd = $db->prepare("UPDATE bookings SET confirmed = 1, confirmed_at = datetime('now') WHERE id = :id AND confirmed = 0");
                        $upd->bindValue(':id', (int) $booking['id'], SQLITE3_INTEGER);
                        $updateResult = $upd->execute();

                        if ($updateResult === false || $db->changes() !== 1) {
                            $status = 'already';
                        } else {
                            $status = 'confirmed';
                            $confirmedBooking = $booking;
                        }
                    }
                }
            }
        }

        $db->exec('COMMIT');
        $inTransaction = false;
    } catch (Throwable $e) {
        if ($inTransaction) {
            $db->exec('ROLLBACK');
        }
        $status = 'invalid';
    }

    if ($status === 'confirmed' && is_array($confirmedBooking)) {
        $participants = [];

        $pstmt = $db->prepare('SELECT name, email FROM booking_participants WHERE booking_id = :bid');
        $pstmt->bindValue(':bid', (int) $confirmedBooking['id'], SQLITE3_INTEGER);
        $pres = $pstmt->execute();
        while ($p = $pres->fetchArray(SQLITE3_ASSOC)) {
            $participants[] = $p;
            if (strtolower((string) $p['email']) !== strtolower((string) $confirmedBooking['email'])) {
                $participantBookingView = [
                    'participants' => (int) $confirmedBooking['participants'],
                    'booking_mode' => (string) ($confirmedBooking['booking_mode'] ?? 'group'),
                ];
                send_participant_confirmed_email(
                    $p['email'],
                    $p['name'],
                    $workshopTitle,
                    $confirmedBooking['name'],
                    $participantBookingView,
                    $confirmedBooking
                );
            }
        }

        send_booking_confirmed_email(
            $confirmedBooking['email'],
            $confirmedBooking['name'],
            $workshopTitle,
            $confirmedBooking,
            $confirmedBooking,
            $participants
        );

        send_admin_notification($workshopTitle, $confirmedBooking, $confirmedBooking, $participants);
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">Light Mode</button>

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

<script src="assets/site-ui.js"></script>

</body>
</html>
