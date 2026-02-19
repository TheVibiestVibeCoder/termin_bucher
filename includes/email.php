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
