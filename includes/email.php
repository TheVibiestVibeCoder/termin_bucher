<?php
/**
 * Email sending via PHP mail().
 * Uses proper headers for deliverability.
 */

function sanitize_mail_header_value(string $value): string {
    $value = str_replace(["\r", "\n"], ' ', $value);
    $value = preg_replace('/\s+/', ' ', $value);
    return trim((string) $value);
}

function sanitize_email_address(string $email): string {
    return trim(str_replace(["\r", "\n"], '', $email));
}

function build_site_url(string $path = ''): string {
    $base = SITE_URL;
    if ($base === '') {
        $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
        $httpsServer    = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
        $isHttps        = ($httpsServer !== '' && $httpsServer !== 'off') || $forwardedProto === 'https';
        $scheme         = $isHttps ? 'https' : 'http';
        $host           = sanitize_mail_header_value((string) ($_SERVER['HTTP_HOST'] ?? ''));
        if ($host !== '') {
            $base = $scheme . '://' . $host;
        }
    }

    if ($path === '') {
        return $base;
    }
    if ($base === '') {
        return $path;
    }

    return rtrim($base, '/') . '/' . ltrim($path, '/');
}

function send_email(string $to, string $subject, string $htmlBody): bool {
    $to = sanitize_email_address($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $from = sanitize_email_address(MAIL_FROM);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $fromName = sanitize_mail_header_value(MAIL_FROM_NAME);
    if ($fromName === '') {
        $fromName = 'Workshop Team';
    }

    $subject = sanitize_mail_header_value($subject);
    if ($subject === '') {
        return false;
    }

    $subjectHeader = $subject;
    $fromNameHeader = $fromName;
    if (function_exists('mb_encode_mimeheader')) {
        $encodedSubject = mb_encode_mimeheader($subject, 'UTF-8', 'B');
        $encodedFrom    = mb_encode_mimeheader($fromName, 'UTF-8', 'B');
        if (is_string($encodedSubject) && $encodedSubject !== '') {
            $subjectHeader = $encodedSubject;
        }
        if (is_string($encodedFrom) && $encodedFrom !== '') {
            $fromNameHeader = $encodedFrom;
        }
    }

    $boundary = bin2hex(random_bytes(16));

    $headers  = "From: {$fromNameHeader} <{$from}>\r\n";
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

    return mail($to, $subjectHeader, $body, $headers);
}

/**
 * Wrap booking emails in a mobile-safe shell.
 */
function render_booking_email_shell(string $innerHtml): string {
    return '
    <div style="margin:0;padding:0;background:#060606;">
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="border-collapse:collapse;background:#060606;">
            <tr>
                <td align="center" style="padding:14px 10px;">
                    <div style="font-family:Arial,sans-serif;max-width:640px;width:100%;margin:0 auto;background:#0d0d0d;color:#ffffff;padding:24px 16px;border-radius:10px;line-height:1.6;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-word;">
                        ' . $innerHtml . '
                    </div>
                </td>
            </tr>
        </table>
    </div>';
}

/**
 * Build one details row for email tables.
 */
function email_details_row(string $label, string $valueHtml): string {
    return '
        <tr>
            <td style="padding:10px 0;border-bottom:1px solid #1d1d1d;vertical-align:top;">
                <div style="font-size:11px;letter-spacing:1px;text-transform:uppercase;color:#818181;margin-bottom:5px;">' . $label . '</div>
                <div style="font-size:14px;line-height:1.6;color:#ffffff;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-word;">' . $valueHtml . '</div>
            </td>
        </tr>';
}

/**
 * Render the full booking details block for confirmation emails.
 */
function render_booking_details_block(array $booking = [], array $workshop = []): string {
    $title        = trim((string) ($workshop['title'] ?? $booking['workshop_title'] ?? ''));
    $format       = trim((string) ($workshop['format'] ?? $booking['format'] ?? ''));
    $duration     = trim((string) ($workshop['tag_label'] ?? $booking['tag_label'] ?? ''));
    $workshopType = trim((string) ($workshop['workshop_type'] ?? $booking['workshop_type'] ?? ''));
    $eventDate    = trim((string) ($workshop['event_date'] ?? $booking['event_date'] ?? ''));
    $eventDateEnd = trim((string) ($workshop['event_date_end'] ?? $booking['event_date_end'] ?? ''));
    $location     = trim((string) ($workshop['location'] ?? $booking['location'] ?? ''));
    $participants = max(1, (int) ($booking['participants'] ?? 1));
    $bookingMode  = trim((string) ($booking['booking_mode'] ?? 'group'));

    $priceNetto = isset($workshop['price_netto']) || isset($booking['price_netto'])
        ? (float) ($workshop['price_netto'] ?? $booking['price_netto'] ?? 0)
        : null;

    $currency = trim((string) (
        $booking['booking_currency']
        ?? $workshop['price_currency']
        ?? $booking['price_currency']
        ?? 'EUR'
    ));

    $pricePerPersonBooked = isset($booking['price_per_person_netto'])
        ? (float) $booking['price_per_person_netto']
        : ($priceNetto ?? 0.0);

    if ($pricePerPersonBooked <= 0 && $priceNetto !== null && $priceNetto > 0) {
        $pricePerPersonBooked = $priceNetto;
    }

    $subtotalBooked = isset($booking['subtotal_netto']) ? (float) $booking['subtotal_netto'] : 0.0;
    if ($subtotalBooked <= 0 && $pricePerPersonBooked > 0) {
        $subtotalBooked = $pricePerPersonBooked * $participants;
    }

    $discountAmount = max(0.0, (float) ($booking['discount_amount'] ?? 0));
    $discountType   = trim((string) ($booking['discount_type'] ?? ''));
    $discountValue  = (float) ($booking['discount_value'] ?? 0);
    $discountCode   = trim((string) ($booking['discount_code'] ?? ''));

    $totalBooked = isset($booking['total_netto']) ? (float) $booking['total_netto'] : 0.0;
    if ($subtotalBooked > 0) {
        if ($discountAmount > 0) {
            $totalBooked = max(0, $subtotalBooked - $discountAmount);
        } elseif ($totalBooked <= 0) {
            $totalBooked = $subtotalBooked;
        }
    }

    $rows = [];
    if ($title !== '') {
        $rows[] = email_details_row('Workshop', e($title));
    }
    if ($workshopType === 'open') {
        $rows[] = email_details_row('Terminart', 'Fester Termin');
    } elseif ($workshopType === 'auf_anfrage') {
        $rows[] = email_details_row('Terminart', 'Auf Anfrage');
    }
    if ($eventDate !== '') {
        $rows[] = email_details_row('Datum / Uhrzeit', e(format_event_date($eventDate, $eventDateEnd)));
    }
    if ($location !== '') {
        $rows[] = email_details_row('Ort', e($location));
    }
    if ($format !== '') {
        $rows[] = email_details_row('Format', e($format));
    }
    if ($duration !== '') {
        $rows[] = email_details_row('Dauer', e($duration));
    }

    $rows[] = email_details_row('Teilnehmer:innen', (string) $participants);
    $rows[] = email_details_row(
        'Buchungsart',
        $bookingMode === 'individual' ? 'Einzelanmeldung' : 'Gruppenanmeldung'
    );

    if ($pricePerPersonBooked > 0) {
        $rows[] = email_details_row('Preis pro Person (netto)', e(format_price($pricePerPersonBooked, $currency)));
        if ($discountAmount > 0) {
            $rows[] = email_details_row('Zwischensumme (netto)', e(format_price($subtotalBooked, $currency)));
            if ($discountCode !== '') {
                $rows[] = email_details_row('Rabattcode', e($discountCode));
            }
            if ($discountType !== '' && $discountValue > 0) {
                $rows[] = email_details_row(
                    'Rabatt',
                    '- ' . e(format_discount_value($discountType, $discountValue, $currency))
                );
            }
            $rows[] = email_details_row('Rabattbetrag (netto)', '- ' . e(format_price($discountAmount, $currency)));
            $rows[] = email_details_row('Gesamtpreis (netto)', e(format_price($totalBooked, $currency)));
        } else {
            $rows[] = email_details_row('Gesamtpreis (netto)', e(format_price($subtotalBooked, $currency)));
        }
        $rows[] = email_details_row('Hinweis', 'zzgl. MwSt.');
    } elseif ($priceNetto !== null) {
        $rows[] = email_details_row('Preis', 'Auf Anfrage');
    }

    $contactName = trim((string) ($booking['name'] ?? ''));
    $contactMail = trim((string) ($booking['email'] ?? ''));
    $org         = trim((string) ($booking['organization'] ?? ''));
    $phone       = trim((string) ($booking['phone'] ?? ''));
    $message     = trim((string) ($booking['message'] ?? ''));

    if ($contactName !== '') {
        $rows[] = email_details_row('Buchende Person', e($contactName));
    }
    if ($contactMail !== '') {
        $rows[] = email_details_row('Kontakt-E-Mail', e($contactMail));
    }
    if ($org !== '') {
        $rows[] = email_details_row('Organisation', e($org));
    }
    if ($phone !== '') {
        $rows[] = email_details_row('Telefon', e($phone));
    }
    if ($message !== '') {
        $rows[] = email_details_row('Nachricht', nl2br(e($message)));
    }

    if (empty($rows)) {
        return '';
    }

    return '
        <div style="margin:22px 0;padding:15px 14px;border:1px solid #222;border-radius:8px;background:rgba(255,255,255,0.02);">
            <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#777;margin-bottom:8px;">
                Buchungsdetails
            </div>
            <table role="presentation" style="width:100%;border-collapse:collapse;font-size:14px;line-height:1.5;">
                ' . implode('', $rows) . '
            </table>
        </div>';
}

/**
 * Render participant list for bookings that include named participants.
 */
function render_booking_participants_block(array $participants): string {
    $items = [];
    foreach ($participants as $i => $participant) {
        $name = trim((string) ($participant['name'] ?? ''));
        $mail = trim((string) ($participant['email'] ?? ''));
        if ($name === '' && $mail === '') {
            continue;
        }

        $label = $name !== '' ? e($name) : ('Teilnehmer:in ' . ($i + 1));
        if ($mail !== '') {
            $label .= ' <span style="color:#a0a0a0;">(' . e($mail) . ')</span>';
        }

        $items[] = '<li style="margin:0 0 7px 16px;color:#d8d8d8;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-word;">' . $label . '</li>';
    }

    if (empty($items)) {
        return '';
    }

    return '
        <div style="margin:0 0 22px;padding:14px 14px;border:1px solid #222;border-radius:8px;background:rgba(255,255,255,0.02);">
            <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#777;margin-bottom:8px;">
                Angemeldete Teilnehmer:innen
            </div>
            <ul style="margin:0;padding:0;">
                ' . implode('', $items) . '
            </ul>
        </div>';
}

/**
 * Cancellation policy block used in booking confirmation mails.
 */
function render_cancellation_policy_block(): string {
    return '
        <div style="margin:0 0 22px;padding:14px 16px;border:1px solid rgba(245,166,35,0.45);border-radius:8px;background:rgba(245,166,35,0.08);">
            <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#d6b180;margin-bottom:6px;">Stornobedingungen</div>
            <p style="margin:0;font-size:14px;line-height:1.6;color:#f0dfc4;">
                Absagen bis 14 Kalendertage vor Veranstaltungsbeginn sind kostenfrei.
                Bei späteren Absagen stellen wir 80&nbsp;% des vereinbarten Gesamtpreises in Rechnung.
            </p>
        </div>';
}

/**
 * Send booking confirmation request (double opt-in).
 */
function send_confirmation_email(
    string $to,
    string $name,
    string $workshopTitle,
    string $token,
    array $booking = [],
    array $workshop = [],
    array $participants = []
): bool {
    $confirmUrl = build_site_url('/confirm.php?token=' . urlencode($token));
    if (!isset($workshop['title']) || trim((string) $workshop['title']) === '') {
        $workshop['title'] = $workshopTitle;
    }
    $detailsBlock      = render_booking_details_block($booking, $workshop);
    $participantsBlock = render_booking_participants_block($participants);
    $cancellationBlock = render_cancellation_policy_block();

    $content = '
        <h2 style="font-family:Georgia,serif;font-weight:normal;font-size:24px;line-height:1.25;margin-bottom:16px;">Buchung bestätigen</h2>
        <p style="color:#a0a0a0;line-height:1.7;">Hallo ' . e($name) . ',</p>
        <p style="color:#a0a0a0;line-height:1.7;">vielen Dank für Ihre Anmeldung zum Workshop:</p>
        <p style="font-size:18px;font-weight:bold;margin:20px 0;color:#ffffff;">' . e($workshopTitle) . '</p>
        <p style="color:#a0a0a0;line-height:1.7;">Hier finden Sie alle Details zu Ihrer Anfrage:</p>
        ' . $detailsBlock . '
        ' . $participantsBlock . '
        ' . $cancellationBlock . '
        <p style="color:#a0a0a0;line-height:1.7;">Bitte bestätigen Sie Ihre Buchung, indem Sie auf den folgenden Link klicken:</p>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:28px 0 24px;">
            <tr>
                <td align="left">
                    <a href="' . e($confirmUrl) . '" style="display:inline-block;max-width:100%;box-sizing:border-box;padding:14px 20px;background:#ffffff;color:#000000;text-decoration:none;border-radius:6px;font-weight:bold;line-height:1.35;word-break:break-word;">Buchung bestätigen &rarr;</a>
                </td>
            </tr>
        </table>
        <p style="color:#666;font-size:13px;line-height:1.5;">Dieser Link ist 48 Stunden gültig. Falls Sie diese Anmeldung nicht durchgeführt haben, können Sie diese E-Mail ignorieren.</p>
        <hr style="border:none;border-top:1px solid #222;margin:30px 0;">
        <p style="color:#666;font-size:12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>';

    return send_email($to, 'Buchung bestätigen: ' . $workshopTitle, render_booking_email_shell($content));
}

/**
 * Send final confirmation (booking is confirmed).
 */
function send_booking_confirmed_email(
    string $to,
    string $name,
    string $workshopTitle,
    array $booking = [],
    array $workshop = [],
    array $participants = []
): bool {
    if (!isset($workshop['title']) || trim((string) $workshop['title']) === '') {
        $workshop['title'] = $workshopTitle;
    }
    $detailsBlock      = render_booking_details_block($booking, $workshop);
    $participantsBlock = render_booking_participants_block($participants);
    $cancellationBlock = render_cancellation_policy_block();

    $content = '
        <h2 style="font-family:Georgia,serif;font-weight:normal;font-size:24px;line-height:1.25;margin-bottom:16px;">Buchung bestätigt!</h2>
        <p style="color:#a0a0a0;line-height:1.7;">Hallo ' . e($name) . ',</p>
        <p style="color:#a0a0a0;line-height:1.7;">Ihre Buchung für den folgenden Workshop wurde erfolgreich bestätigt:</p>
        <p style="font-size:18px;font-weight:bold;margin:20px 0;color:#ffffff;">' . e($workshopTitle) . '</p>
        ' . $detailsBlock . '
        ' . $participantsBlock . '
        ' . $cancellationBlock . '
        <p style="color:#a0a0a0;line-height:1.7;">Bei Fragen erreichen Sie uns unter <a href="mailto:' . e(MAIL_FROM) . '" style="color:#ffffff;">' . e(MAIL_FROM) . '</a>.</p>
        <hr style="border:none;border-top:1px solid #222;margin:30px 0;">
        <p style="color:#666;font-size:12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>';

    return send_email($to, 'Bestätigt: ' . $workshopTitle, render_booking_email_shell($content));
}

/**
 * Notify admin about a new confirmed booking.
 */
function send_admin_notification(
    string $workshopTitle,
    array $booking,
    array $workshop = [],
    array $participants = []
): bool {
    if (!isset($workshop['title']) || trim((string) $workshop['title']) === '') {
        $workshop['title'] = $workshopTitle;
    }
    $detailsBlock      = render_booking_details_block($booking, $workshop);
    $participantsBlock = render_booking_participants_block($participants);

    $content = '
        <h2 style="margin:0 0 14px;color:#ffffff;">Neue Buchung bestätigt</h2>
        <p style="margin:0 0 10px;color:#c8c8c8;"><strong style="color:#ffffff;">Workshop:</strong> ' . e($workshopTitle) . '</p>
        ' . $detailsBlock . '
        ' . $participantsBlock . '
        <p style="margin:14px 0 0;color:#8c8c8c;font-size:12px;">Automatische Admin-Benachrichtigung</p>';

    return send_email(MAIL_FROM, 'Neue Buchung: ' . $workshopTitle, render_booking_email_shell($content));
}

/**
 * Notify an individual participant that their spot is confirmed (booked by someone else).
 */
function send_participant_confirmed_email(
    string $to,
    string $participantName,
    string $workshopTitle,
    string $bookerName,
    array $booking = [],
    array $workshop = []
): bool {
    if (!isset($workshop['title']) || trim((string) $workshop['title']) === '') {
        $workshop['title'] = $workshopTitle;
    }
    $detailsBlock = render_booking_details_block($booking, $workshop);
    $cancellationBlock = render_cancellation_policy_block();

    $content = '
        <h2 style="font-family:Georgia,serif;font-weight:normal;font-size:24px;line-height:1.25;margin-bottom:16px;">Teilnahme bestätigt!</h2>
        <p style="color:#a0a0a0;line-height:1.7;">Hallo ' . e($participantName) . ',</p>
        <p style="color:#a0a0a0;line-height:1.7;"><strong style="color:#ffffff;">' . e($bookerName) . '</strong> hat Sie für den folgenden Workshop angemeldet:</p>
        <p style="font-size:18px;font-weight:bold;margin:20px 0;color:#ffffff;">' . e($workshopTitle) . '</p>
        ' . $detailsBlock . '
        ' . $cancellationBlock . '
        <p style="color:#a0a0a0;line-height:1.7;">Ihre Teilnahme wurde erfolgreich bestätigt.</p>
        <p style="color:#a0a0a0;line-height:1.7;">Bei Fragen erreichen Sie uns unter <a href="mailto:' . e(MAIL_FROM) . '" style="color:#ffffff;">' . e(MAIL_FROM) . '</a>.</p>
        <hr style="border:none;border-top:1px solid #222;margin:30px 0;">
        <p style="color:#666;font-size:12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>';

    return send_email($to, 'Ihre Teilnahme wurde bestätigt: ' . $workshopTitle, render_booking_email_shell($content));
}

/**
 * Notify booker that their booking was cancelled by admin.
 */
function send_booking_cancelled_email(string $to, string $name, string $workshopTitle): bool {
    $content = '
        <h2 style="font-family:Georgia,serif;font-weight:normal;font-size:24px;margin-bottom:20px;">Buchung storniert</h2>
        <p style="color:#a0a0a0;line-height:1.7;">Hallo ' . e($name) . ',</p>
        <p style="color:#a0a0a0;line-height:1.7;">Ihre Buchung für den folgenden Workshop wurde leider storniert:</p>
        <p style="font-size:18px;font-weight:bold;margin:20px 0;color:#ffffff;">' . e($workshopTitle) . '</p>
        <p style="color:#a0a0a0;line-height:1.7;">Falls dies ein Versehen war oder Sie Fragen haben, kontaktieren Sie uns bitte unter <a href="mailto:' . e(MAIL_FROM) . '" style="color:#ffffff;">' . e(MAIL_FROM) . '</a> - wir helfen Ihnen gerne weiter.</p>
        <hr style="border:none;border-top:1px solid #222;margin:30px 0;">
        <p style="color:#666;font-size:12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>';

    return send_email($to, 'Buchung storniert: ' . $workshopTitle, render_booking_email_shell($content));
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

    $subject = 'Rechnung: ' . $d['workshop_titel'] . ' - Nr. ' . $d['rechnungs_nr'];
    return send_email($to, $subject, $html);
}

/**
 * Send a custom email from admin.
 */
function send_custom_email(string $to, string $subject, string $messageText): bool {
    $content = '
        <div style="color:#d0d0d0;line-height:1.7;">' . nl2br(e($messageText)) . '</div>
        <hr style="border:none;border-top:1px solid #222;margin:30px 0;">
        <p style="color:#666;font-size:12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>';

    return send_email($to, $subject, render_booking_email_shell($content));
}



