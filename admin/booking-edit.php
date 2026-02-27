<?php
require __DIR__ . '/../includes/config.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
if (!$id) {
    flash('error', 'Keine Buchungs-ID angegeben.');
    redirect('bookings.php');
}

$stmt = $db->prepare('SELECT b.*, w.title AS workshop_title, w.id AS workshop_id, w.price_netto AS workshop_price_netto, w.price_currency AS workshop_currency FROM bookings b JOIN workshops w ON b.workshop_id = w.id WHERE b.id = :id');
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
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte geben Sie eine gÃ¼ltige E-Mail-Adresse ein.';
    if ($participants < 1 || $participants > 500) $errors[] = 'UngÃ¼ltige Anzahl Teilnehmer:innen.';

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
    $pricePerPersonSnapshot = (float) ($booking['price_per_person_netto'] ?? 0);
    if ($pricePerPersonSnapshot <= 0) {
        $pricePerPersonSnapshot = (float) ($booking['workshop_price_netto'] ?? 0);
    }

    $bookingCurrency = trim((string) ($booking['booking_currency'] ?? ''));
    if ($bookingCurrency === '') {
        $bookingCurrency = trim((string) ($booking['workshop_currency'] ?? 'EUR'));
    }

    $discountTypeSnapshot = (string) ($booking['discount_type'] ?? '');
    $discountValueSnapshot = (float) ($booking['discount_value'] ?? 0);
    $recalculatedTotals = calculate_booking_totals(
        $pricePerPersonSnapshot,
        $participants,
        $discountTypeSnapshot,
        $discountValueSnapshot
    );

    if (empty($errors)) {
        $upd = $db->prepare('
            UPDATE bookings
            SET name = :name, email = :email, organization = :org, phone = :phone,
                participants = :participants, message = :msg, confirmed = :confirmed,
                price_per_person_netto = :ppnet, booking_currency = :bcurrency,
                subtotal_netto = :subtotal, discount_amount = :damount, total_netto = :total,
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
        $upd->bindValue(':ppnet',        $pricePerPersonSnapshot,             SQLITE3_FLOAT);
        $upd->bindValue(':bcurrency',    $bookingCurrency,                    SQLITE3_TEXT);
        $upd->bindValue(':subtotal',     (float) $recalculatedTotals['subtotal'], SQLITE3_FLOAT);
        $upd->bindValue(':damount',      (float) $recalculatedTotals['discount'], SQLITE3_FLOAT);
        $upd->bindValue(':total',        (float) $recalculatedTotals['total'],    SQLITE3_FLOAT);
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
    $booking['price_per_person_netto'] = $pricePerPersonSnapshot;
    $booking['booking_currency'] = $bookingCurrency;
    $booking['subtotal_netto'] = (float) $recalculatedTotals['subtotal'];
    $booking['discount_amount'] = (float) $recalculatedTotals['discount'];
    $booking['total_netto'] = (float) $recalculatedTotals['total'];
}

$metaCurrency = trim((string) ($booking['booking_currency'] ?? ''));
if ($metaCurrency === '') {
    $metaCurrency = trim((string) ($booking['workshop_currency'] ?? 'EUR'));
}

$metaPricePerPerson = (float) ($booking['price_per_person_netto'] ?? 0);
if ($metaPricePerPerson <= 0) {
    $metaPricePerPerson = (float) ($booking['workshop_price_netto'] ?? 0);
}

$metaSubtotal = (float) ($booking['subtotal_netto'] ?? 0);
$metaDiscount = (float) ($booking['discount_amount'] ?? 0);
$metaTotal = (float) ($booking['total_netto'] ?? 0);
if ($metaSubtotal <= 0 && $metaPricePerPerson > 0) {
    $metaSubtotal = $metaPricePerPerson * (int) ($booking['participants'] ?? 1);
}
if ($metaTotal <= 0 && $metaSubtotal > 0) {
    $metaTotal = max(0, $metaSubtotal - $metaDiscount);
}?>
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
    <title>Buchung bearbeiten â€“ Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
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
        <div style="margin-bottom:1.5rem;padding:1rem 1.25rem;background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);font-size:0.85rem;color:var(--muted);">
            <div style="margin-bottom:0.25rem;">
                <strong style="color:var(--text);">Workshop:</strong> <?= e($booking['workshop_title']) ?>
            </div>
            <div style="margin-bottom:0.25rem;">
                <strong style="color:var(--text);">Buchungs-ID:</strong> #<?= $booking['id'] ?>
                &nbsp;&middot;&nbsp;
                <strong style="color:var(--text);">Erstellt:</strong> <?= e(date('d.m.Y H:i', strtotime($booking['created_at']))) ?>
            </div>
            <?php if ($booking['confirmed'] && $booking['confirmed_at']): ?>
            <div>
                <strong style="color:var(--text);">BestÃ¤tigt:</strong> <?= e(date('d.m.Y H:i', strtotime($booking['confirmed_at']))) ?>
            </div>
            <?php endif; ?>
            <?php if ($metaPricePerPerson > 0): ?>
            <div style="margin-top:0.45rem;">
                <strong style="color:var(--text);">Preis / Person (netto):</strong>
                <?= e(format_price($metaPricePerPerson, $metaCurrency)) ?>
            </div>
            <div style="margin-top:0.2rem;">
                <strong style="color:var(--text);">Zwischensumme (netto):</strong>
                <?= e(format_price($metaSubtotal, $metaCurrency)) ?>
            </div>
            <?php if ($metaDiscount > 0): ?>
            <div style="margin-top:0.2rem;">
                <strong style="color:var(--text);">Rabatt:</strong>
                <?= e($booking['discount_code'] ?: '-') ?>
                <span style="color:#2ecc71;">(-<?= e(format_price($metaDiscount, $metaCurrency)) ?>)</span>
            </div>
            <?php endif; ?>
            <div style="margin-top:0.2rem;">
                <strong style="color:var(--text);">Gesamt (netto):</strong>
                <?= e(format_price($metaTotal, $metaCurrency)) ?>
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
                <label for="participants">Anzahl Teilnehmer:innen *</label>
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
                <label for="confirmed" style="margin-bottom:0;cursor:pointer;">Buchung bestÃ¤tigt</label>
            </div>

            <div style="display:flex;gap:0.75rem;margin-top:0.5rem;">
                <button type="submit" class="btn-admin btn-success">Speichern</button>
                <a href="bookings.php?workshop_id=<?= $booking['workshop_id'] ?>" class="btn-admin">Abbrechen</a>
            </div>
        </form>
    </div>
</div>
<script src="../assets/site-ui.js"></script>
</body>
</html>








