<?php
/**
 * Email sending via PHP mail().
 * Uses proper headers for deliverability.
 */

function send_email(string $to, string $subject, string $htmlBody): bool {
    $from     = MAIL_FROM;
    $fromName = MAIL_FROM_NAME;

    $boundary = md5(uniqid(time()));

    $headers  = "From: {$fromName} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";
    $headers .= "X-Mailer: DisinfoWorkshops/1.0\r\n";

    // Plain-text version (strip tags)
    $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    $textBody = html_entity_decode($textBody, ENT_QUOTES, 'UTF-8');

    $body  = "--{$boundary}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $textBody . "\r\n\r\n";
    $body .= "--{$boundary}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: 8bit\r\n\r\n";
    $body .= $htmlBody . "\r\n\r\n";
    $body .= "--{$boundary}--\r\n";

    return mail($to, $subject, $body, $headers);
}

/**
 * Send booking confirmation request (double opt-in).
 */
function send_confirmation_email(string $to, string $name, string $workshopTitle, string $token): bool {
    $confirmUrl = SITE_URL . '/confirm.php?token=' . urlencode($token);

    $html = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0d0d0d; color: #ffffff; padding: 40px; border-radius: 8px;">
        <h2 style="font-family: Georgia, serif; font-weight: normal; font-size: 24px; margin-bottom: 20px;">Buchung bestätigen</h2>
        <p style="color: #a0a0a0; line-height: 1.7;">Hallo ' . e($name) . ',</p>
        <p style="color: #a0a0a0; line-height: 1.7;">vielen Dank für Ihre Anmeldung zum Workshop:</p>
        <p style="font-size: 18px; font-weight: bold; margin: 20px 0; color: #ffffff;">' . e($workshopTitle) . '</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Bitte bestätigen Sie Ihre Buchung, indem Sie auf den folgenden Link klicken:</p>
        <p style="margin: 30px 0;">
            <a href="' . e($confirmUrl) . '" style="display: inline-block; padding: 14px 32px; background: #ffffff; color: #000000; text-decoration: none; border-radius: 6px; font-weight: bold;">Buchung bestätigen &rarr;</a>
        </p>
        <p style="color: #666; font-size: 13px; line-height: 1.5;">Dieser Link ist 48 Stunden gültig. Falls Sie diese Anmeldung nicht durchgeführt haben, können Sie diese E-Mail ignorieren.</p>
        <hr style="border: none; border-top: 1px solid #222; margin: 30px 0;">
        <p style="color: #666; font-size: 12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>
    </div>';

    return send_email($to, 'Buchung bestätigen: ' . $workshopTitle, $html);
}

/**
 * Send final confirmation (booking is confirmed).
 */
function send_booking_confirmed_email(string $to, string $name, string $workshopTitle): bool {
    $html = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0d0d0d; color: #ffffff; padding: 40px; border-radius: 8px;">
        <h2 style="font-family: Georgia, serif; font-weight: normal; font-size: 24px; margin-bottom: 20px;">Buchung bestätigt!</h2>
        <p style="color: #a0a0a0; line-height: 1.7;">Hallo ' . e($name) . ',</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Ihre Buchung für den folgenden Workshop wurde erfolgreich bestätigt:</p>
        <p style="font-size: 18px; font-weight: bold; margin: 20px 0; color: #ffffff;">' . e($workshopTitle) . '</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Wir werden uns in Kürze mit weiteren Details bei Ihnen melden.</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Bei Fragen erreichen Sie uns unter <a href="mailto:' . e(MAIL_FROM) . '" style="color: #ffffff;">' . e(MAIL_FROM) . '</a>.</p>
        <hr style="border: none; border-top: 1px solid #222; margin: 30px 0;">
        <p style="color: #666; font-size: 12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>
    </div>';

    return send_email($to, 'Bestätigt: ' . $workshopTitle, $html);
}

/**
 * Notify admin about a new confirmed booking.
 */
function send_admin_notification(string $workshopTitle, array $booking): bool {
    $html = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
        <h2>Neue Buchung bestätigt</h2>
        <p><strong>Workshop:</strong> ' . e($workshopTitle) . '</p>
        <p><strong>Name:</strong> ' . e($booking['name']) . '</p>
        <p><strong>E-Mail:</strong> ' . e($booking['email']) . '</p>
        <p><strong>Organisation:</strong> ' . e($booking['organization']) . '</p>
        <p><strong>Telefon:</strong> ' . e($booking['phone']) . '</p>
        <p><strong>Teilnehmer:</strong> ' . (int)$booking['participants'] . '</p>
        <p><strong>Nachricht:</strong> ' . e($booking['message']) . '</p>
    </div>';

    return send_email(MAIL_FROM, 'Neue Buchung: ' . $workshopTitle, $html);
}

/**
 * Notify an individual participant that their spot is confirmed (booked by someone else).
 */
function send_participant_confirmed_email(string $to, string $participantName, string $workshopTitle, string $bookerName): bool {
    $html = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0d0d0d; color: #ffffff; padding: 40px; border-radius: 8px;">
        <h2 style="font-family: Georgia, serif; font-weight: normal; font-size: 24px; margin-bottom: 20px;">Teilnahme bestätigt!</h2>
        <p style="color: #a0a0a0; line-height: 1.7;">Hallo ' . e($participantName) . ',</p>
        <p style="color: #a0a0a0; line-height: 1.7;"><strong style="color:#ffffff;">' . e($bookerName) . '</strong> hat Sie für den folgenden Workshop angemeldet:</p>
        <p style="font-size: 18px; font-weight: bold; margin: 20px 0; color: #ffffff;">' . e($workshopTitle) . '</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Ihre Teilnahme wurde erfolgreich bestätigt. Wir werden uns in Kürze mit weiteren Details melden.</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Bei Fragen erreichen Sie uns unter <a href="mailto:' . e(MAIL_FROM) . '" style="color: #ffffff;">' . e(MAIL_FROM) . '</a>.</p>
        <hr style="border: none; border-top: 1px solid #222; margin: 30px 0;">
        <p style="color: #666; font-size: 12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>
    </div>';

    return send_email($to, 'Ihre Teilnahme wurde bestätigt: ' . $workshopTitle, $html);
}

/**
 * Notify booker that their booking was cancelled by admin.
 */
function send_booking_cancelled_email(string $to, string $name, string $workshopTitle): bool {
    $html = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0d0d0d; color: #ffffff; padding: 40px; border-radius: 8px;">
        <h2 style="font-family: Georgia, serif; font-weight: normal; font-size: 24px; margin-bottom: 20px;">Buchung storniert</h2>
        <p style="color: #a0a0a0; line-height: 1.7;">Hallo ' . e($name) . ',</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Ihre Buchung für den folgenden Workshop wurde leider storniert:</p>
        <p style="font-size: 18px; font-weight: bold; margin: 20px 0; color: #ffffff;">' . e($workshopTitle) . '</p>
        <p style="color: #a0a0a0; line-height: 1.7;">Falls dies ein Versehen war oder Sie Fragen haben, kontaktieren Sie uns bitte unter <a href="mailto:' . e(MAIL_FROM) . '" style="color: #ffffff;">' . e(MAIL_FROM) . '</a> – wir helfen Ihnen gerne weiter.</p>
        <hr style="border: none; border-top: 1px solid #222; margin: 30px 0;">
        <p style="color: #666; font-size: 12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>
    </div>';

    return send_email($to, 'Buchung storniert: ' . $workshopTitle, $html);
}

/**
 * Send an invoice (Rechnung) email.
 *
 * $d keys:
 *   empfaenger, adresse, plz_ort, anrede, kontakt_name, kontakt_email,
 *   rechnung_datum (YYYY-MM-DD), rechnungs_nr,
 *   fuer_text, workshop_titel, veranstaltungs_datum,
 *   pos1_label, pos1_betrag, pos2_label (opt), pos2_betrag (opt),
 *   absender_name
 */
function send_rechnung_email(string $to, array $d): bool {
    $months = [
        '01' => 'Januar',  '02' => 'Februar', '03' => 'März',     '04' => 'April',
        '05' => 'Mai',     '06' => 'Juni',    '07' => 'Juli',     '08' => 'August',
        '09' => 'September','10' => 'Oktober','11' => 'November', '12' => 'Dezember',
    ];

    // Format invoice date
    $datumTs = strtotime($d['rechnung_datum']);
    $datumFormatted = ($datumTs)
        ? (int)date('j', $datumTs) . '. ' . ($months[date('m', $datumTs)] ?? date('m', $datumTs)) . ' ' . date('Y', $datumTs)
        : e($d['rechnung_datum']);

    // Amounts
    $pos1 = (float) str_replace(',', '.', $d['pos1_betrag'] ?? '0');
    $pos2 = !empty($d['pos2_betrag']) ? (float) str_replace(',', '.', $d['pos2_betrag']) : 0.0;
    $hasPos2 = !empty($d['pos2_label']) && $pos2 > 0;

    $zwischensumme = $pos1 + $pos2;
    $ust           = $zwischensumme * 0.20;
    $summe         = $zwischensumme + $ust;

    $fmt = fn(float $n): string => 'EUR&nbsp;' . number_format($n, 2, ',', '.');

    // Row helper
    $row = fn(string $label, string $amount, bool $bold = false, string $topBorder = '', string $fs = '14px'): string =>
        '<tr>'
        . '<td style="padding:8px 0 8px 0;' . ($bold ? 'font-weight:bold;' : '') . ($topBorder ? "border-top:{$topBorder};" : '') . 'font-size:' . $fs . ';">' . $label . '</td>'
        . '<td style="text-align:right;white-space:nowrap;padding:8px 0 8px 16px;' . ($bold ? 'font-weight:bold;' : '') . ($topBorder ? "border-top:{$topBorder};" : '') . 'font-size:' . $fs . ';">' . $amount . '</td>'
        . '</tr>';

    $pos2Row = $hasPos2
        ? $row(e($d['pos2_label']), $fmt($pos2))
        : '';

    $html = '
<div style="font-family:Arial,sans-serif;max-width:680px;margin:0 auto;padding:50px 40px;color:#000;background:#fff;line-height:1.65;font-size:14px;">

  <!-- Recipient block -->
  <div style="margin-bottom:48px;">
    <strong>' . e($d['empfaenger']) . '</strong><br>
    ' . e($d['adresse']) . '<br>
    ' . e($d['plz_ort']) . '<br>
    z.&nbsp;Hd. ' . e($d['anrede']) . ' ' . e($d['kontakt_name']) . '<br>
    per E-Mail: ' . e($d['kontakt_email']) . '
  </div>

  <!-- Date + invoice number (right-aligned) -->
  <div style="text-align:right;margin-bottom:40px;">
    Wien, ' . $datumFormatted . '<br>
    Nr.&nbsp;' . e($d['rechnungs_nr']) . '
  </div>

  <!-- Salutation + intro -->
  <p style="margin:0 0 6px 0;">Sehr geehrte Damen und Herren,</p>
  <p style="margin:0 0 28px 0;">für ' . e($d['fuer_text']) . '<br>
  <strong>' . e($d['workshop_titel']) . '</strong><br>
  am ' . e($d['veranstaltungs_datum']) . ' berechnen wir wie vereinbart:</p>

  <!-- Line items -->
  <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
    ' . $row(e($d['pos1_label']), $fmt($pos1))
    . $pos2Row
    . $row('Zwischensumme', $fmt($zwischensumme), true, '1px solid #bbb')
    . $row('20&nbsp;%&nbsp;USt.', $fmt($ust))
    . $row('SUMME', $fmt($summe), true, '2px solid #000', '16px') . '
  </table>

  <!-- Payment instructions -->
  <p style="margin:32px 0 6px 0;">Wir bitten um Überweisung auf das untenstehende Konto binnen 14 Tagen ab Rechnungsdatum:</p>
  <div style="background:#f4f4f4;padding:16px 20px;border-radius:4px;margin:0 0 28px 0;">
    <strong>Disinfo Combat GmbH</strong><br>
    IBAN: AT39 2011 1844 5223 9900<br>
    BIC: GIBAATWWXXX
  </div>

  <!-- Closing -->
  <p style="margin:0 0 4px 0;">Wir danken für Ihren Auftrag und verbleiben<br>mit freundlichen Grüßen</p>
  <p style="margin:28px 0 0 0;"><strong>' . e($d['absender_name']) . '</strong></p>

</div>';

    $subject = 'Rechnung: ' . $d['workshop_titel'] . ' – Nr. ' . $d['rechnungs_nr'];
    return send_email($to, $subject, $html);
}

/**
 * Send a custom email from admin.
 */
function send_custom_email(string $to, string $subject, string $messageText): bool {
    $html = '
    <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0d0d0d; color: #ffffff; padding: 40px; border-radius: 8px;">
        <div style="color: #a0a0a0; line-height: 1.7;">' . nl2br(e($messageText)) . '</div>
        <hr style="border: none; border-top: 1px solid #222; margin: 30px 0;">
        <p style="color: #666; font-size: 12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>
    </div>';

    return send_email($to, $subject, $html);
}
