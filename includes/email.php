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

function infer_site_url_from_request_for_email(): string {
    $hostRaw = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    if ($hostRaw === '') {
        $hostRaw = trim((string) ($_SERVER['SERVER_NAME'] ?? ''));
    }
    if ($hostRaw === '' || preg_match('/[\s\/\\\\]/', $hostRaw)) {
        return '';
    }

    $hostRaw = strtolower($hostRaw);
    $host = $hostRaw;
    $port = '';

    if (str_starts_with($hostRaw, '[')) {
        $endBracketPos = strpos($hostRaw, ']');
        if ($endBracketPos === false) {
            return '';
        }
        $host = substr($hostRaw, 0, $endBracketPos + 1);
        $rest = substr($hostRaw, $endBracketPos + 1);
        if ($rest !== '') {
            if (!str_starts_with($rest, ':')) {
                return '';
            }
            $port = substr($rest, 1);
        }
    } else {
        $parts = explode(':', $hostRaw);
        if (count($parts) > 2) {
            return '';
        }
        $host = $parts[0];
        $port = $parts[1] ?? '';
    }

    $hostForValidation = trim($host, '[]');
    $hostIsValid = filter_var($hostForValidation, FILTER_VALIDATE_IP) !== false
        || filter_var($hostForValidation, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false
        || $hostForValidation === 'localhost';
    if (!$hostIsValid) {
        return '';
    }

    if ($port !== '') {
        if (!ctype_digit($port)) {
            return '';
        }
        $portInt = (int) $port;
        if ($portInt < 1 || $portInt > 65535) {
            return '';
        }
    }

    $forwardedProto = strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
    $httpsServer = strtolower((string) ($_SERVER['HTTPS'] ?? ''));
    $isHttps = ($httpsServer !== '' && $httpsServer !== 'off') || $forwardedProto === 'https';
    $scheme = $isHttps ? 'https' : 'http';

    $hostWithPort = $host;
    if ($port !== '') {
        $defaultPort = $isHttps ? 443 : 80;
        if ((int) $port !== $defaultPort) {
            $hostWithPort .= ':' . (int) $port;
        }
    }

    return $scheme . '://' . $hostWithPort;
}

function build_site_url(string $path = ''): string {
    $base = trim((string) SITE_URL);
    if ($base === '') {
        $base = infer_site_url_from_request_for_email();
    }

    if ($path === '') {
        return $base;
    }

    // Never derive absolute links from Host headers.
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $normalizedPath = '/' . ltrim($path, '/');
    if ($base === '') {
        return $normalizedPath;
    }

    return rtrim($base, '/') . $normalizedPath;
}

function build_plain_text_email_body(string $htmlBody): string {
    $textBody = strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody));
    return html_entity_decode($textBody, ENT_QUOTES, 'UTF-8');
}

function encode_utf8_mail_part(string $body): array {
    $normalized = str_replace(["\r\n", "\r"], "\n", $body);
    $normalized = str_replace("\n", "\r\n", $normalized);

    if (function_exists('quoted_printable_encode')) {
        $encoded = quoted_printable_encode($normalized);
        if (is_string($encoded) && $encoded !== '') {
            return [
                'encoding' => 'quoted-printable',
                'content' => $encoded,
            ];
        }
    }

    return [
        'encoding' => 'base64',
        'content' => rtrim(chunk_split(base64_encode($normalized), 76, "\r\n"), "\r\n"),
    ];
}

function encode_mail_subject_and_from(string $subject, string $fromName): array {
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

    return [$subjectHeader, $fromNameHeader];
}

function email_log_truncate(string $value, int $maxBytes = 200000): string {
    if ($maxBytes <= 0 || strlen($value) <= $maxBytes) {
        return $value;
    }

    return substr($value, 0, $maxBytes);
}

function email_log_context_label(): string {
    $trace = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 16);
    $ignoreFunctions = [
        'email_log_context_label',
        'email_log_write',
        'send_email_with_attachments',
        'send_email',
        'sanitize_mail_header_value',
        'sanitize_email_address',
        'build_plain_text_email_body',
        'encode_mail_subject_and_from',
    ];

    foreach ($trace as $frame) {
        $function = (string) ($frame['function'] ?? '');
        if ($function !== '' && !in_array($function, $ignoreFunctions, true)) {
            return $function;
        }

        $file = (string) ($frame['file'] ?? '');
        if ($file !== '' && basename($file) !== 'email.php') {
            return basename($file);
        }
    }

    return '';
}

function email_log_type_from_context(string $contextLabel, string $subject = ''): string {
    $ctx = strtolower(trim($contextLabel));
    $subj = strtolower(trim($subject));

    if ($ctx === 'booking_cancellation' || $ctx === 'send_booking_cancelled_email') {
        return 'storno';
    }
    if (
        $ctx === 'booking_confirmation_request'
        || $ctx === 'booking_confirmed'
        || $ctx === 'participant_confirmed'
        || $ctx === 'send_confirmation_email'
        || $ctx === 'send_booking_confirmed_email'
        || $ctx === 'send_participant_confirmed_email'
    ) {
        return 'bestaetigung';
    }
    if ($ctx === 'contact_admin' || $ctx === 'contact_reply' || $ctx === 'kontakt.php') {
        return 'kontakt';
    }
    if ($ctx === 'admin_custom' || $ctx === 'send_custom_email') {
        return 'individuell';
    }
    if ($ctx === 'invoice' || $ctx === 'send_rechnung_email') {
        return 'rechnung';
    }
    if ($ctx === 'booking_admin_notification' || $ctx === 'send_admin_notification') {
        return 'admin';
    }

    if (str_starts_with($subj, 'buchung storniert:')) {
        return 'storno';
    }
    if (str_starts_with($subj, 'kontaktanfrage:') || str_starts_with($subj, 'ihre anfrage:')) {
        return 'kontakt';
    }
    if (str_starts_with($subj, 'rechnung:')) {
        return 'rechnung';
    }
    if (str_starts_with($subj, 'neue buchung:')) {
        return 'admin';
    }

    return 'other';
}

function email_log_write(array $payload): void {
    try {
        $db = $GLOBALS['db'] ?? null;
        if (!$db instanceof SQLite3) {
            return;
        }

        $attachmentMeta = $payload['attachment_meta'] ?? [];
        if (!is_array($attachmentMeta)) {
            $attachmentMeta = [];
        }
        $attachmentMetaJson = json_encode($attachmentMeta, JSON_UNESCAPED_UNICODE);
        if (!is_string($attachmentMetaJson)) {
            $attachmentMetaJson = '[]';
        }

        $contextLabel = trim((string) ($payload['context_label'] ?? ''));
        if ($contextLabel === '') {
            $contextLabel = email_log_context_label();
        }
        $mailType = strtolower(trim((string) ($payload['mail_type'] ?? '')));
        if (!in_array($mailType, ['storno', 'bestaetigung', 'kontakt', 'individuell', 'rechnung', 'admin', 'other'], true)) {
            $mailType = email_log_type_from_context($contextLabel, (string) ($payload['subject'] ?? ''));
        }

        $requestUri = trim((string) ($_SERVER['REQUEST_URI'] ?? ''));
        $clientIp = '';
        if (function_exists('rate_limit_client_ip')) {
            $clientIp = (string) rate_limit_client_ip();
        } else {
            $clientIp = trim((string) ($_SERVER['REMOTE_ADDR'] ?? ''));
        }

        $status = (string) ($payload['send_status'] ?? 'failed');
        $sentAt = $status === 'sent' ? date('Y-m-d H:i:s') : null;

        $stmt = $db->prepare("
            INSERT INTO email_logs (
                transport,
                send_status,
                mail_type,
                recipient_email,
                sender_email,
                sender_name,
                subject,
                headers_raw,
                body_html,
                body_text,
                attachment_count,
                attachment_meta_json,
                context_label,
                request_uri,
                client_ip,
                error_message,
                sent_at
            )
            VALUES (
                :transport,
                :send_status,
                :mail_type,
                :recipient_email,
                :sender_email,
                :sender_name,
                :subject,
                :headers_raw,
                :body_html,
                :body_text,
                :attachment_count,
                :attachment_meta_json,
                :context_label,
                :request_uri,
                :client_ip,
                :error_message,
                :sent_at
            )
        ");

        if (!$stmt instanceof SQLite3Stmt) {
            return;
        }

        $stmt->bindValue(':transport', email_log_truncate((string) ($payload['transport'] ?? 'php_mail'), 40), SQLITE3_TEXT);
        $stmt->bindValue(':send_status', email_log_truncate($status, 20), SQLITE3_TEXT);
        $stmt->bindValue(':mail_type', email_log_truncate($mailType, 30), SQLITE3_TEXT);
        $stmt->bindValue(':recipient_email', email_log_truncate((string) ($payload['recipient_email'] ?? ''), 254), SQLITE3_TEXT);
        $stmt->bindValue(':sender_email', email_log_truncate((string) ($payload['sender_email'] ?? ''), 254), SQLITE3_TEXT);
        $stmt->bindValue(':sender_name', email_log_truncate((string) ($payload['sender_name'] ?? ''), 160), SQLITE3_TEXT);
        $stmt->bindValue(':subject', email_log_truncate((string) ($payload['subject'] ?? ''), 500), SQLITE3_TEXT);
        $stmt->bindValue(':headers_raw', email_log_truncate((string) ($payload['headers_raw'] ?? ''), 20000), SQLITE3_TEXT);
        $stmt->bindValue(':body_html', email_log_truncate((string) ($payload['body_html'] ?? ''), 200000), SQLITE3_TEXT);
        $stmt->bindValue(':body_text', email_log_truncate((string) ($payload['body_text'] ?? ''), 200000), SQLITE3_TEXT);
        $stmt->bindValue(':attachment_count', max(0, (int) ($payload['attachment_count'] ?? 0)), SQLITE3_INTEGER);
        $stmt->bindValue(':attachment_meta_json', email_log_truncate($attachmentMetaJson, 60000), SQLITE3_TEXT);
        $stmt->bindValue(':context_label', email_log_truncate($contextLabel, 120), SQLITE3_TEXT);
        $stmt->bindValue(':request_uri', email_log_truncate($requestUri, 500), SQLITE3_TEXT);
        $stmt->bindValue(':client_ip', email_log_truncate($clientIp, 90), SQLITE3_TEXT);
        $stmt->bindValue(':error_message', email_log_truncate((string) ($payload['error_message'] ?? ''), 2000), SQLITE3_TEXT);
        if ($sentAt === null) {
            $stmt->bindValue(':sent_at', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':sent_at', $sentAt, SQLITE3_TEXT);
        }

        $stmt->execute();
    } catch (Throwable) {
        // Logging must never break business email flows.
    }
}

function send_email_with_attachments(string $to, string $subject, string $htmlBody, array $attachments = [], string $contextLabel = ''): bool {
    $to = sanitize_email_address($to);
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        email_log_write([
            'transport' => 'php_mail',
            'send_status' => 'failed',
            'recipient_email' => $to,
            'sender_email' => sanitize_email_address(MAIL_FROM),
            'sender_name' => sanitize_mail_header_value(MAIL_FROM_NAME),
            'subject' => sanitize_mail_header_value($subject),
            'body_html' => $htmlBody,
            'body_text' => build_plain_text_email_body($htmlBody),
            'attachment_count' => is_array($attachments) ? count($attachments) : 0,
            'attachment_meta' => [],
            'context_label' => $contextLabel,
            'error_message' => 'invalid_recipient_email',
        ]);
        return false;
    }

    $from = sanitize_email_address(MAIL_FROM);
    if (!filter_var($from, FILTER_VALIDATE_EMAIL)) {
        email_log_write([
            'transport' => 'php_mail',
            'send_status' => 'failed',
            'recipient_email' => $to,
            'sender_email' => $from,
            'sender_name' => sanitize_mail_header_value(MAIL_FROM_NAME),
            'subject' => sanitize_mail_header_value($subject),
            'body_html' => $htmlBody,
            'body_text' => build_plain_text_email_body($htmlBody),
            'attachment_count' => is_array($attachments) ? count($attachments) : 0,
            'attachment_meta' => [],
            'context_label' => $contextLabel,
            'error_message' => 'invalid_sender_email',
        ]);
        return false;
    }

    $fromName = sanitize_mail_header_value(MAIL_FROM_NAME);
    if ($fromName === '') {
        $fromName = 'Workshop Team';
    }

    $subject = sanitize_mail_header_value($subject);
    if ($subject === '') {
        email_log_write([
            'transport' => 'php_mail',
            'send_status' => 'failed',
            'recipient_email' => $to,
            'sender_email' => $from,
            'sender_name' => $fromName,
            'subject' => '',
            'body_html' => $htmlBody,
            'body_text' => build_plain_text_email_body($htmlBody),
            'attachment_count' => is_array($attachments) ? count($attachments) : 0,
            'attachment_meta' => [],
            'context_label' => $contextLabel,
            'error_message' => 'empty_subject',
        ]);
        return false;
    }

    [$subjectHeader, $fromNameHeader] = encode_mail_subject_and_from($subject, $fromName);

    $headers  = "From: {$fromNameHeader} <{$from}>\r\n";
    $headers .= "Reply-To: {$from}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "X-Mailer: DisinfoWorkshops/1.0\r\n";

    $textBody = build_plain_text_email_body($htmlBody);

    $normalizedAttachments = [];
    $attachmentMeta = [];
    foreach ($attachments as $attachment) {
        if (!is_array($attachment)) {
            continue;
        }

        $rawContent = $attachment['content'] ?? '';
        if (!is_string($rawContent) || $rawContent === '') {
            continue;
        }

        $filename = sanitize_mail_header_value((string) ($attachment['filename'] ?? 'attachment.bin'));
        if ($filename === '') {
            $filename = 'attachment.bin';
        }
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', $filename);

        $mime = sanitize_mail_header_value((string) ($attachment['mime'] ?? 'application/octet-stream'));
        if ($mime === '') {
            $mime = 'application/octet-stream';
        }

        $normalizedAttachments[] = [
            'filename' => $filename,
            'mime' => $mime,
            'content' => $rawContent,
        ];
        $attachmentMeta[] = [
            'filename' => $filename,
            'mime' => $mime,
            'bytes' => strlen($rawContent),
        ];
    }

    $body = '';
    $sent = false;
    $errorMessage = '';
    $encodedTextPart = encode_utf8_mail_part($textBody);
    $encodedHtmlPart = encode_utf8_mail_part($htmlBody);

    try {
        if (empty($normalizedAttachments)) {
            $boundary = bin2hex(random_bytes(16));
            $headers .= "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n";

            $body  = "--{$boundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: " . $encodedTextPart['encoding'] . "\r\n\r\n";
            $body .= $encodedTextPart['content'] . "\r\n\r\n";
            $body .= "--{$boundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: " . $encodedHtmlPart['encoding'] . "\r\n\r\n";
            $body .= $encodedHtmlPart['content'] . "\r\n\r\n";
            $body .= "--{$boundary}--\r\n";
        } else {
            $mixedBoundary = 'mix_' . bin2hex(random_bytes(12));
            $altBoundary   = 'alt_' . bin2hex(random_bytes(12));

            $headers .= "Content-Type: multipart/mixed; boundary=\"{$mixedBoundary}\"\r\n";

            $body  = "--{$mixedBoundary}\r\n";
            $body .= "Content-Type: multipart/alternative; boundary=\"{$altBoundary}\"\r\n\r\n";

            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: " . $encodedTextPart['encoding'] . "\r\n\r\n";
            $body .= $encodedTextPart['content'] . "\r\n\r\n";

            $body .= "--{$altBoundary}\r\n";
            $body .= "Content-Type: text/html; charset=UTF-8\r\n";
            $body .= "Content-Transfer-Encoding: " . $encodedHtmlPart['encoding'] . "\r\n\r\n";
            $body .= $encodedHtmlPart['content'] . "\r\n\r\n";
            $body .= "--{$altBoundary}--\r\n";

            foreach ($normalizedAttachments as $attachment) {
                $body .= "\r\n--{$mixedBoundary}\r\n";
                $body .= "Content-Type: {$attachment['mime']}; name=\"{$attachment['filename']}\"\r\n";
                $body .= "Content-Transfer-Encoding: base64\r\n";
                $body .= "Content-Disposition: attachment; filename=\"{$attachment['filename']}\"\r\n\r\n";
                $body .= chunk_split(base64_encode($attachment['content'])) . "\r\n";
            }

            $body .= "--{$mixedBoundary}--\r\n";
        }

        $sent = mail($to, $subjectHeader, $body, $headers);
        if (!$sent) {
            $errorMessage = 'mail_returned_false';
        }
    } catch (Throwable $e) {
        $sent = false;
        $errorMessage = 'transport_exception: ' . $e->getMessage();
    }

    email_log_write([
        'transport' => 'php_mail',
        'send_status' => $sent ? 'sent' : 'failed',
        'recipient_email' => $to,
        'sender_email' => $from,
        'sender_name' => $fromName,
        'subject' => $subject,
        'headers_raw' => $headers,
        'body_html' => $htmlBody,
        'body_text' => $textBody,
        'attachment_count' => count($attachmentMeta),
        'attachment_meta' => $attachmentMeta,
        'context_label' => $contextLabel,
        'error_message' => $errorMessage,
    ]);

    return $sent;
}

function send_email(string $to, string $subject, string $htmlBody, string $contextLabel = ''): bool {
    return send_email_with_attachments($to, $subject, $htmlBody, [], $contextLabel);
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
                    <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;width:100%;max-width:640px;margin:0 auto;">
                        <tr>
                            <td style="font-family:Arial,sans-serif;background:#0d0d0d;color:#ffffff;padding:24px 16px;border-radius:10px;line-height:1.6;overflow-wrap:anywhere;word-wrap:break-word;word-break:break-word;box-sizing:border-box;width:100%;max-width:100%;">
                                ' . $innerHtml . '
                            </td>
                        </tr>
                    </table>
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
    $bookingTypeLabel = $participants <= 1 ? 'Einzelbuchung' : 'Gruppenbuchung';
    $rows[] = email_details_row('Buchungsart', $bookingTypeLabel);

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
        <div style="margin:22px 0;padding:15px 14px;border:1px solid #222;border-radius:8px;background:rgba(255,255,255,0.02);box-sizing:border-box;max-width:100%;">
            <div style="font-size:12px;letter-spacing:1.2px;text-transform:uppercase;color:#777;margin-bottom:8px;">
                Buchungsdetails
            </div>
            <table role="presentation" style="width:100%;max-width:100%;table-layout:fixed;border-collapse:collapse;font-size:14px;line-height:1.5;">
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

function booking_ics_timezone(): DateTimeZone {
    $timezoneCandidates = [
        trim((string) ($_ENV['WORKSHOP_TIMEZONE'] ?? '')),
        trim((string) getenv('WORKSHOP_TIMEZONE')),
        trim((string) ($_ENV['ICS_TIMEZONE'] ?? '')),
        trim((string) getenv('ICS_TIMEZONE')),
        trim((string) ($_ENV['APP_TIMEZONE'] ?? '')),
        trim((string) getenv('APP_TIMEZONE')),
        trim((string) ($_ENV['TIMEZONE'] ?? '')),
        trim((string) getenv('TIMEZONE')),
        'Europe/Berlin',
        trim((string) date_default_timezone_get()),
    ];

    foreach ($timezoneCandidates as $candidate) {
        if ($candidate === '') {
            continue;
        }

        try {
            return new DateTimeZone($candidate);
        } catch (Throwable) {
            continue;
        }
    }

    return new DateTimeZone('Europe/Berlin');
}

function parse_booking_ics_datetime(string $rawDate, DateTimeZone $timezone): ?DateTimeImmutable {
    $rawDate = trim($rawDate);
    if ($rawDate === '') {
        return null;
    }

    $formats = [
        '!Y-m-d H:i:s',
        '!Y-m-d H:i',
        '!Y-m-d\TH:i:s',
        '!Y-m-d\TH:i',
        '!Y-m-d',
    ];

    foreach ($formats as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $rawDate, $timezone);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                continue;
            }

            return $dt;
        }
    }

    $timestamp = strtotime($rawDate);
    if ($timestamp === false) {
        return null;
    }

    return (new DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
}

function escape_ics_text(string $value): string {
    return str_replace(
        ["\\", ";", ",", "\r\n", "\r", "\n"],
        ["\\\\", "\\;", "\\,", "\\n", "\\n", "\\n"],
        $value
    );
}

function fold_ics_line(string $line): string {
    $line = (string) $line;
    if ($line === '' || strlen($line) <= 75) {
        return $line;
    }

    $chunkBytes = 73;
    $remaining = $line;
    $folded = '';

    while ($remaining !== '') {
        if (strlen($remaining) <= 75) {
            $folded .= $remaining;
            break;
        }

        if (function_exists('mb_strcut')) {
            $chunk = (string) mb_strcut($remaining, 0, $chunkBytes, 'UTF-8');
        } else {
            $chunk = substr($remaining, 0, $chunkBytes);
        }

        if ($chunk === '') {
            $chunk = substr($remaining, 0, $chunkBytes);
        }
        if ($chunk === '') {
            break;
        }

        $folded .= $chunk . "\r\n ";
        $remaining = (string) substr($remaining, strlen($chunk));
    }

    return $folded;
}

function build_booking_ics_filename(string $workshopTitle, DateTimeImmutable $startAt): string {
    $slug = trim($workshopTitle);
    if (function_exists('iconv') && $slug !== '') {
        $asciiTitle = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $slug);
        if (is_string($asciiTitle) && $asciiTitle !== '') {
            $slug = $asciiTitle;
        }
    }

    $slug = strtolower($slug);
    $slug = preg_replace('/[^a-z0-9]+/', '-', $slug);
    $slug = trim((string) $slug, '-');
    if ($slug === '') {
        $slug = 'workshop';
    }

    $slug = substr($slug, 0, 64);

    return 'workshop-' . $startAt->format('Ymd') . '-' . $slug . '.ics';
}

function build_booking_ics_uid(array $booking, array $workshop): string {
    $uidSeed = implode('|', [
        (string) ($booking['id'] ?? ''),
        (string) ($booking['token'] ?? ''),
        (string) ($booking['email'] ?? ''),
        (string) ($booking['occurrence_id'] ?? ''),
        (string) ($workshop['id'] ?? $booking['workshop_id'] ?? ''),
        (string) ($workshop['title'] ?? $booking['workshop_title'] ?? ''),
        (string) ($workshop['event_date'] ?? $booking['event_date'] ?? ''),
    ]);

    if ($uidSeed === '||||||') {
        $uidSeed = bin2hex(random_bytes(16));
    }

    $host = parse_url(build_site_url('/'), PHP_URL_HOST);
    if (!is_string($host) || $host === '') {
        $host = 'localhost';
    }
    $host = preg_replace('/[^a-z0-9.-]/i', '', strtolower($host));
    if ($host === '') {
        $host = 'localhost';
    }

    return 'booking-' . substr(hash('sha256', $uidSeed), 0, 32) . '@' . $host;
}

function build_booking_ics_attachment(array $booking = [], array $workshop = []): ?array {
    $startRaw = trim((string) ($workshop['event_date'] ?? $booking['event_date'] ?? ''));
    if ($startRaw === '') {
        return null;
    }

    $endRaw = trim((string) ($workshop['event_date_end'] ?? $booking['event_date_end'] ?? ''));
    $title = trim((string) ($workshop['title'] ?? $booking['workshop_title'] ?? 'Workshop'));
    if ($title === '') {
        $title = 'Workshop';
    }

    $location = trim((string) ($workshop['location'] ?? $booking['location'] ?? ''));
    $timezone = booking_ics_timezone();
    $timezoneId = $timezone->getName();
    $startAt = parse_booking_ics_datetime($startRaw, $timezone);
    if (!$startAt instanceof DateTimeImmutable) {
        return null;
    }

    $endAt = parse_booking_ics_datetime($endRaw, $timezone);
    if ($endAt instanceof DateTimeImmutable && $endAt <= $startAt) {
        $endAt = null;
    }

    $workshopSlug = trim((string) ($workshop['slug'] ?? $booking['workshop_slug'] ?? ''));
    $occurrenceId = max(0, (int) ($booking['occurrence_id'] ?? $workshop['occurrence_id'] ?? 0));
    $workshopUrl = '';
    if ($workshopSlug !== '' && function_exists('app_url')) {
        $workshopQuery = ['slug' => $workshopSlug];
        if ($occurrenceId > 0) {
            $workshopQuery['occurrence'] = $occurrenceId;
        }
        $workshopUrl = build_site_url(app_url('workshop', $workshopQuery));
    }

    $dateLabel = format_event_date($startRaw, $endRaw);
    $descriptionParts = [
        'Ihre Workshop-Buchung wurde bestaetigt.',
        'Workshop: ' . $title,
    ];
    if ($dateLabel !== '') {
        $descriptionParts[] = 'Termin: ' . $dateLabel;
    }
    if ($location !== '') {
        $descriptionParts[] = 'Ort: ' . $location;
    }
    if ($workshopUrl !== '') {
        $descriptionParts[] = 'Details: ' . $workshopUrl;
    }
    $descriptionParts[] = 'Kontakt: ' . MAIL_FROM;
    $description = implode("\n", $descriptionParts);

    $summary = 'Workshop: ' . $title;
    $alarmDescription = 'Erinnerung: "' . $title . '" startet in einer Woche.';
    $uid = build_booking_ics_uid($booking, $workshop);
    $timestamp = gmdate('Ymd\THis\Z');
    $startAtUtc = $startAt->setTimezone(new DateTimeZone('UTC'));

    $lines = [
        'BEGIN:VCALENDAR',
        'VERSION:2.0',
        'PRODID:-//Disinfo Consulting//Workshop Booking//DE',
        'CALSCALE:GREGORIAN',
        'METHOD:PUBLISH',
        'X-WR-TIMEZONE:' . $timezoneId,
        'BEGIN:VEVENT',
        'UID:' . $uid,
        'DTSTAMP:' . $timestamp,
        'DTSTART:' . $startAtUtc->format('Ymd\THis\Z'),
    ];

    if ($endAt instanceof DateTimeImmutable) {
        $endAtUtc = $endAt->setTimezone(new DateTimeZone('UTC'));
        $lines[] = 'DTEND:' . $endAtUtc->format('Ymd\THis\Z');
    }

    $lines[] = 'SUMMARY:' . escape_ics_text($summary);
    $lines[] = 'DESCRIPTION:' . escape_ics_text($description);

    if ($location !== '') {
        $lines[] = 'LOCATION:' . escape_ics_text($location);
    }

    if ($workshopUrl !== '') {
        $lines[] = 'URL:' . sanitize_mail_header_value($workshopUrl);
    }

    $lines[] = 'STATUS:CONFIRMED';
    $lines[] = 'TRANSP:OPAQUE';
    $lines[] = 'BEGIN:VALARM';
    $lines[] = 'ACTION:DISPLAY';
    $lines[] = 'TRIGGER:-P7D';
    $lines[] = 'DESCRIPTION:' . escape_ics_text($alarmDescription);
    $lines[] = 'END:VALARM';
    $lines[] = 'END:VEVENT';
    $lines[] = 'END:VCALENDAR';

    $content = '';
    foreach ($lines as $line) {
        $content .= fold_ics_line($line) . "\r\n";
    }

    return [
        'filename' => build_booking_ics_filename($title, $startAt),
        'mime' => 'text/calendar; charset=UTF-8; method=PUBLISH',
        'content' => $content,
    ];
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
    $confirmUrl = build_site_url(app_url('confirm', ['token' => $token]));
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
        <p style="color:#a0a0a0;line-height:1.7;">Bitte bestätigen Sie Ihre Buchung direkt über den folgenden Button:</p>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" style="border-collapse:collapse;margin:20px 0 18px;">
            <tr>
                <td align="left">
                    <a href="' . e($confirmUrl) . '" style="display:inline-block;max-width:100%;box-sizing:border-box;padding:14px 20px;background:#ffffff;color:#000000;text-decoration:none;border-radius:6px;font-weight:bold;line-height:1.35;word-break:break-word;">Buchung bestätigen &rarr;</a>
                </td>
            </tr>
        </table>
        <p style="color:#666;font-size:13px;line-height:1.5;">Dieser Link ist 48 Stunden gültig. Falls Sie diese Anmeldung nicht durchgeführt haben, können Sie diese E-Mail ignorieren.</p>
        <p style="color:#a0a0a0;line-height:1.7;margin-top:20px;">Hier finden Sie alle Details zu Ihrer Anfrage:</p>
        ' . $detailsBlock . '
        ' . $participantsBlock . '
        ' . $cancellationBlock . '
        <hr style="border:none;border-top:1px solid #222;margin:30px 0;">
        <p style="color:#666;font-size:12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>';

    return send_email($to, 'Buchung bestätigen: ' . $workshopTitle, render_booking_email_shell($content), 'booking_confirmation_request');
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
    $attachments = [];
    $icsAttachment = build_booking_ics_attachment($booking, $workshop);
    if (is_array($icsAttachment)) {
        $attachments[] = $icsAttachment;
    }

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

    return send_email_with_attachments(
        $to,
        'Bestätigt: ' . $workshopTitle,
        render_booking_email_shell($content),
        $attachments,
        'booking_confirmed'
    );
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

    return send_email(MAIL_FROM, 'Neue Buchung: ' . $workshopTitle, render_booking_email_shell($content), 'booking_admin_notification');
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
    $attachments = [];
    $icsAttachment = build_booking_ics_attachment($booking, $workshop);
    if (is_array($icsAttachment)) {
        $attachments[] = $icsAttachment;
    }

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

    return send_email_with_attachments(
        $to,
        'Ihre Teilnahme wurde bestätigt: ' . $workshopTitle,
        render_booking_email_shell($content),
        $attachments,
        'participant_confirmed'
    );
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

    return send_email($to, 'Buchung storniert: ' . $workshopTitle, render_booking_email_shell($content), 'booking_cancellation');
}

/**
 * Send an invoice (Rechnung) email.
 *
 * $d keys:
 *   empfaenger, adresse, plz_ort, anrede, kontakt_name, kontakt_email,
 *   rechnung_datum (YYYY-MM-DD), rechnungs_nr,
 *   fuer_text, workshop_titel, veranstaltungs_datum,
 *   pos1_label, pos1_betrag, pos2_label (opt), pos2_betrag (opt),
 *   absender_name,
 *   line_items (optional array of [label => string, amount => float])
 */
function parse_rechnung_amount(string $rawValue): float {
    $raw = trim($rawValue);
    if ($raw === '') {
        return 0.0;
    }

    $raw = preg_replace('/[^0-9,.-]/', '', $raw);
    if ($raw === '' || $raw === '-' || $raw === ',' || $raw === '.') {
        return 0.0;
    }

    if (str_contains($raw, ',') && str_contains($raw, '.')) {
        $raw = str_replace('.', '', $raw);
        $raw = str_replace(',', '.', $raw);
    } elseif (str_contains($raw, ',')) {
        $raw = str_replace(',', '.', $raw);
    }

    return (float) $raw;
}

function format_rechnung_amount_html(float $amount): string {
    $prefix = $amount < 0 ? '- ' : '';
    return $prefix . 'EUR&nbsp;' . number_format(abs($amount), 2, ',', '.');
}

function format_rechnung_amount_text(float $amount): string {
    $prefix = $amount < 0 ? '- ' : '';
    return $prefix . 'EUR ' . number_format(abs($amount), 2, ',', '.');
}

function convert_utf8_to_windows_1252(string $text): string {
    if (function_exists('iconv')) {
        $iconvConverted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (is_string($iconvConverted) && $iconvConverted !== '') {
            return $iconvConverted;
        }
    }

    if (function_exists('mb_convert_encoding')) {
        $mbConverted = @mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
        if (is_string($mbConverted) && $mbConverted !== '') {
            return $mbConverted;
        }
    }

    return $text;
}

function pdf_escape_win_ansi_text(string $text): string {
    $converted = convert_utf8_to_windows_1252($text);

    $converted = str_replace(["\\", "(", ")"], ["\\\\", "\\(", "\\)"], $converted);
    $converted = str_replace(["\r", "\n"], '', $converted);

    return $converted;
}

function wrap_pdf_text_line(string $line, int $width = 96): array {
    $line = trim((string) preg_replace('/\s+/', ' ', $line));
    if ($line === '') {
        return [];
    }

    $words = preg_split('/\s+/', $line) ?: [];
    $lines = [];
    $current = '';

    foreach ($words as $word) {
        $word = trim((string) $word);
        if ($word === '') {
            continue;
        }

        $candidate = $current === '' ? $word : ($current . ' ' . $word);
        if (strlen($candidate) <= $width) {
            $current = $candidate;
            continue;
        }

        if ($current !== '') {
            $lines[] = $current;
            $current = '';
        }

        if (strlen($word) <= $width) {
            $current = $word;
            continue;
        }

        $chunks = str_split($word, $width);
        foreach ($chunks as $chunkIndex => $chunk) {
            if ($chunkIndex === count($chunks) - 1) {
                $current = $chunk;
            } else {
                $lines[] = $chunk;
            }
        }
    }

    if ($current !== '') {
        $lines[] = $current;
    }

    return $lines;
}

function pdf_num(float $value): string {
    $formatted = number_format($value, 3, '.', '');
    $formatted = rtrim(rtrim($formatted, '0'), '.');
    if ($formatted === '' || $formatted === '-0') {
        return '0';
    }

    return $formatted;
}

function pdf_rgb_hex(string $hex): array {
    $hex = ltrim(trim($hex), '#');
    if (strlen($hex) === 3) {
        $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
    }
    if (strlen($hex) !== 6) {
        return [0.0, 0.0, 0.0];
    }

    return [
        hexdec(substr($hex, 0, 2)) / 255,
        hexdec(substr($hex, 2, 2)) / 255,
        hexdec(substr($hex, 4, 2)) / 255,
    ];
}

function pdf_text_width_estimate(string $text, float $fontSize, string $font = 'F1'): float {
    $measure = convert_utf8_to_windows_1252($text);

    $len = max(1, strlen($measure));
    $factor = 0.52;
    if ($font === 'F2') {
        $factor = 0.55;
    } elseif ($font === 'F3') {
        $factor = 0.50;
    }

    return $len * $fontSize * $factor;
}

function pdf_add_rect(
    array &$stream,
    float $pageHeight,
    float $x,
    float $top,
    float $w,
    float $h,
    ?array $fillRgb = null,
    ?array $strokeRgb = null,
    float $lineWidth = 1.0
): void {
    $y = $pageHeight - $top - $h;

    if (is_array($fillRgb) && count($fillRgb) === 3) {
        $stream[] = pdf_num($fillRgb[0]) . ' ' . pdf_num($fillRgb[1]) . ' ' . pdf_num($fillRgb[2]) . ' rg';
    }
    if (is_array($strokeRgb) && count($strokeRgb) === 3) {
        $stream[] = pdf_num($strokeRgb[0]) . ' ' . pdf_num($strokeRgb[1]) . ' ' . pdf_num($strokeRgb[2]) . ' RG';
        $stream[] = pdf_num($lineWidth) . ' w';
    }

    $op = 'S';
    if (is_array($fillRgb) && is_array($strokeRgb)) {
        $op = 'B';
    } elseif (is_array($fillRgb)) {
        $op = 'f';
    }

    $stream[] = pdf_num($x) . ' ' . pdf_num($y) . ' ' . pdf_num($w) . ' ' . pdf_num($h) . ' re ' . $op;
}

function pdf_add_line(
    array &$stream,
    float $pageHeight,
    float $x1,
    float $top1,
    float $x2,
    float $top2,
    array $strokeRgb,
    float $lineWidth = 1.0
): void {
    $y1 = $pageHeight - $top1;
    $y2 = $pageHeight - $top2;

    $stream[] = pdf_num($strokeRgb[0]) . ' ' . pdf_num($strokeRgb[1]) . ' ' . pdf_num($strokeRgb[2]) . ' RG';
    $stream[] = pdf_num($lineWidth) . ' w';
    $stream[] = pdf_num($x1) . ' ' . pdf_num($y1) . ' m ' . pdf_num($x2) . ' ' . pdf_num($y2) . ' l S';
}

function pdf_add_text(
    array &$stream,
    float $pageHeight,
    float $x,
    float $baselineTop,
    string $text,
    string $font = 'F1',
    float $fontSize = 11.0,
    ?array $colorRgb = null
): void {
    $text = trim((string) $text);
    if ($text === '') {
        return;
    }

    $y = $pageHeight - $baselineTop;
    $escaped = pdf_escape_win_ansi_text($text);

    if (is_array($colorRgb) && count($colorRgb) === 3) {
        $stream[] = pdf_num($colorRgb[0]) . ' ' . pdf_num($colorRgb[1]) . ' ' . pdf_num($colorRgb[2]) . ' rg';
    }

    $stream[] = 'BT';
    $stream[] = '/' . $font . ' ' . pdf_num($fontSize) . ' Tf';
    $stream[] = '1 0 0 1 ' . pdf_num($x) . ' ' . pdf_num($y) . ' Tm';
    $stream[] = '(' . $escaped . ') Tj';
    $stream[] = 'ET';
}

function pdf_add_text_right(
    array &$stream,
    float $pageHeight,
    float $rightX,
    float $baselineTop,
    string $text,
    string $font = 'F1',
    float $fontSize = 11.0,
    ?array $colorRgb = null
): void {
    $width = pdf_text_width_estimate($text, $fontSize, $font);
    $x = $rightX - $width;
    pdf_add_text($stream, $pageHeight, $x, $baselineTop, $text, $font, $fontSize, $colorRgb);
}

function pdf_add_wrapped_text(
    array &$stream,
    float $pageHeight,
    float $x,
    float $baselineTop,
    string $text,
    int $wrapWidth,
    float $lineHeight,
    string $font = 'F1',
    float $fontSize = 11.0,
    ?array $colorRgb = null,
    int $maxLines = 0
): float {
    $lines = wrap_pdf_text_line($text, $wrapWidth);
    if ($maxLines > 0 && count($lines) > $maxLines) {
        $lines = array_slice($lines, 0, $maxLines);
        $last = trim((string) ($lines[$maxLines - 1] ?? ''));
        if ($last !== '') {
            $lines[$maxLines - 1] = rtrim(substr($last, 0, max(0, $wrapWidth - 3))) . '...';
        }
    }

    $y = $baselineTop;
    foreach ($lines as $line) {
        pdf_add_text($stream, $pageHeight, $x, $y, $line, $font, $fontSize, $colorRgb);
        $y += $lineHeight;
    }

    return $y;
}

function build_rechnung_pdf(array $d, array $lineItems, float $zwischensumme, float $ust, float $summe, string $datumFormatted): string {
    $pageWidth = 595.0;
    $pageHeight = 842.0;

    $cBg = pdf_rgb_hex('#f3efe8');
    $cPanel = pdf_rgb_hex('#fbf8f3');
    $cHeader = pdf_rgb_hex('#1f1812');
    $cText = pdf_rgb_hex('#1f1812');
    $cMuted = pdf_rgb_hex('#5f554b');
    $cDim = pdf_rgb_hex('#8f8376');
    $cBorder = pdf_rgb_hex('#d5c9bb');
    $cSoft = pdf_rgb_hex('#ebe5dc');
    $cWhite = [1.0, 1.0, 1.0];

    $panelX = 24.0;
    $panelY = 24.0;
    $panelW = $pageWidth - 48.0;
    $panelH = $pageHeight - 48.0;

    $innerX = $panelX + 22.0;
    $innerW = $panelW - 44.0;

    $stream = [];

    pdf_add_rect($stream, $pageHeight, 0.0, 0.0, $pageWidth, $pageHeight, $cBg, null);
    pdf_add_rect($stream, $pageHeight, $panelX, $panelY, $panelW, $panelH, $cPanel, $cBorder, 1.0);

    $headerH = 92.0;
    pdf_add_rect($stream, $pageHeight, $panelX, $panelY, $panelW, $headerH, $cHeader, $cHeader, 1.0);

    pdf_add_text($stream, $pageHeight, $panelX + 22.0, $panelY + 38.0, 'RECHNUNG', 'F3', 27.0, $cWhite);
    pdf_add_text($stream, $pageHeight, $panelX + 22.0, $panelY + 57.0, 'Disinfo Combat GmbH', 'F1', 10.0, $cSoft);

    $headerRight = $panelX + $panelW - 22.0;
    pdf_add_text_right($stream, $pageHeight, $headerRight, $panelY + 34.0, 'Nr. ' . (string) ($d['rechnungs_nr'] ?? '-'), 'F2', 11.0, $cWhite);
    pdf_add_text_right($stream, $pageHeight, $headerRight, $panelY + 52.0, 'Wien, ' . $datumFormatted, 'F1', 10.0, $cSoft);

    $recipientLines = [];
    foreach ([(string) ($d['empfaenger'] ?? ''), (string) ($d['adresse'] ?? ''), (string) ($d['plz_ort'] ?? '')] as $line) {
        if (trim($line) !== '') {
            $recipientLines[] = trim($line);
        }
    }
    $contactLine = trim((string) ($d['anrede'] ?? '') . ' ' . (string) ($d['kontakt_name'] ?? ''));
    if ($contactLine !== '') {
        $recipientLines[] = 'z. Hd. ' . $contactLine;
    }
    $mailLine = trim((string) ($d['kontakt_email'] ?? ''));
    if ($mailLine !== '') {
        $recipientLines[] = 'E-Mail: ' . $mailLine;
    }

    $recipientWrapped = [];
    foreach ($recipientLines as $line) {
        foreach (wrap_pdf_text_line($line, 46) as $wrapped) {
            $recipientWrapped[] = $wrapped;
        }
    }
    if (empty($recipientWrapped)) {
        $recipientWrapped[] = '-';
    }
    if (count($recipientWrapped) > 8) {
        $recipientWrapped = array_slice($recipientWrapped, 0, 8);
        $recipientWrapped[7] = rtrim(substr((string) $recipientWrapped[7], 0, 42)) . '...';
    }

    $detailLines = [];
    $ws = trim((string) ($d['workshop_titel'] ?? ''));
    $ev = trim((string) ($d['veranstaltungs_datum'] ?? ''));
    if ($ws !== '') {
        $detailLines[] = 'Workshop: ' . $ws;
    }
    if ($ev !== '') {
        $detailLines[] = 'Termin: ' . $ev;
    }
    if (empty($detailLines)) {
        $detailLines[] = 'Workshop: -';
    }

    $detailWrapped = [];
    foreach ($detailLines as $line) {
        foreach (wrap_pdf_text_line($line, 30) as $wrapped) {
            $detailWrapped[] = $wrapped;
        }
    }
    if (count($detailWrapped) > 8) {
        $detailWrapped = array_slice($detailWrapped, 0, 8);
        $detailWrapped[7] = rtrim(substr((string) $detailWrapped[7], 0, 26)) . '...';
    }

    $cursorTop = $panelY + $headerH + 18.0;
    $leftW = floor($innerW * 0.62);
    $gap = 14.0;
    $rightW = $innerW - $leftW - $gap;
    $lineHeight = 13.0;

    $leftBoxH = 42.0 + max(3, count($recipientWrapped)) * $lineHeight;
    $rightBoxH = 42.0 + max(3, count($detailWrapped)) * $lineHeight;
    $boxH = max($leftBoxH, $rightBoxH);

    pdf_add_rect($stream, $pageHeight, $innerX, $cursorTop, $leftW, $boxH, $cWhite, $cBorder, 0.8);
    pdf_add_rect($stream, $pageHeight, $innerX + $leftW + $gap, $cursorTop, $rightW, $boxH, $cWhite, $cBorder, 0.8);

    pdf_add_text($stream, $pageHeight, $innerX + 12.0, $cursorTop + 18.0, 'RECHNUNG AN', 'F2', 8.8, $cDim);
    $yLeft = $cursorTop + 35.0;
    foreach ($recipientWrapped as $idx => $line) {
        pdf_add_text(
            $stream,
            $pageHeight,
            $innerX + 12.0,
            $yLeft,
            $line,
            $idx === 0 ? 'F2' : 'F1',
            $idx === 0 ? 10.8 : 10.1,
            $idx === 0 ? $cText : $cMuted
        );
        $yLeft += $lineHeight;
    }

    $rightX = $innerX + $leftW + $gap;
    pdf_add_text($stream, $pageHeight, $rightX + 12.0, $cursorTop + 18.0, 'DETAILS', 'F2', 8.8, $cDim);
    $yRight = $cursorTop + 35.0;
    foreach ($detailWrapped as $line) {
        pdf_add_text($stream, $pageHeight, $rightX + 12.0, $yRight, $line, 'F1', 10.0, $cMuted);
        $yRight += $lineHeight;
    }

    $cursorTop += $boxH + 18.0;

    pdf_add_text($stream, $pageHeight, $innerX, $cursorTop + 10.0, 'Sehr geehrte Damen und Herren,', 'F1', 10.5, $cText);
    $cursorTop += 28.0;

    $leistungText = trim((string) ($d['fuer_text'] ?? ''));
    if ($leistungText !== '') {
        $cursorTop = pdf_add_wrapped_text(
            $stream,
            $pageHeight,
            $innerX,
            $cursorTop,
            'für ' . $leistungText,
            92,
            13.0,
            'F1',
            10.0,
            $cMuted,
            3
        ) + 2.0;
    }

    if ($ws !== '') {
        $cursorTop = pdf_add_wrapped_text(
            $stream,
            $pageHeight,
            $innerX,
            $cursorTop,
            $ws,
            88,
            13.0,
            'F2',
            10.6,
            $cText,
            2
        );
    }
    if ($ev !== '') {
        $cursorTop = pdf_add_wrapped_text(
            $stream,
            $pageHeight,
            $innerX,
            $cursorTop,
            'am ' . $ev,
            90,
            13.0,
            'F1',
            10.0,
            $cMuted,
            2
        );
    }

    $cursorTop += 14.0;

    $displayItems = $lineItems;
    if (count($displayItems) > 12) {
        $displayItems = array_slice($displayItems, 0, 11);
        $displayItems[] = ['label' => 'Weitere Positionen gekürzt', 'amount' => 0.0];
    }

    $tableX = $innerX;
    $tableW = $innerW;
    $tableHeaderH = 24.0;
    $rowH = 22.0;
    $sumRowH = 24.0;
    $totalRowH = 28.0;

    $tableHeight = $tableHeaderH + (count($displayItems) * $rowH) + (2 * $sumRowH) + $totalRowH;
    $maxTableBottom = $panelY + $panelH - 170.0;
    while ($cursorTop + $tableHeight > $maxTableBottom && count($displayItems) > 3) {
        array_pop($displayItems);
        $tableHeight = $tableHeaderH + (count($displayItems) * $rowH) + (2 * $sumRowH) + $totalRowH;
    }

    pdf_add_rect($stream, $pageHeight, $tableX, $cursorTop, $tableW, $tableHeight, $cWhite, $cBorder, 0.8);
    pdf_add_rect($stream, $pageHeight, $tableX, $cursorTop, $tableW, $tableHeaderH, $cSoft, $cBorder, 0.5);

    $amountRight = $tableX + $tableW - 12.0;
    pdf_add_text($stream, $pageHeight, $tableX + 12.0, $cursorTop + 16.0, 'Position', 'F2', 9.8, $cText);
    pdf_add_text_right($stream, $pageHeight, $amountRight, $cursorTop + 16.0, 'Betrag (netto)', 'F2', 9.8, $cText);

    $rowTop = $cursorTop + $tableHeaderH;
    foreach ($displayItems as $idx => $item) {
        if ($idx % 2 === 1) {
            pdf_add_rect($stream, $pageHeight, $tableX, $rowTop, $tableW, $rowH, pdf_rgb_hex('#f8f4ee'), null);
        }

        $label = trim((string) ($item['label'] ?? 'Position'));
        $labelLines = wrap_pdf_text_line($label, 62);
        $labelOut = $labelLines[0] ?? $label;
        if (count($labelLines) > 1) {
            $labelOut = rtrim(substr($labelOut, 0, 56)) . '...';
        }

        pdf_add_text($stream, $pageHeight, $tableX + 12.0, $rowTop + 14.0, $labelOut, 'F1', 10.0, $cMuted);
        pdf_add_text_right(
            $stream,
            $pageHeight,
            $amountRight,
            $rowTop + 14.0,
            format_rechnung_amount_text((float) ($item['amount'] ?? 0)),
            'F1',
            10.0,
            $cText
        );

        pdf_add_line($stream, $pageHeight, $tableX, $rowTop + $rowH, $tableX + $tableW, $rowTop + $rowH, $cBorder, 0.45);
        $rowTop += $rowH;
    }

    pdf_add_line($stream, $pageHeight, $tableX, $rowTop, $tableX + $tableW, $rowTop, $cBorder, 1.2);
    pdf_add_text($stream, $pageHeight, $tableX + 12.0, $rowTop + 16.0, 'Zwischensumme', 'F2', 10.0, $cText);
    pdf_add_text_right($stream, $pageHeight, $amountRight, $rowTop + 16.0, format_rechnung_amount_text($zwischensumme), 'F2', 10.0, $cText);

    $rowTop += $sumRowH;
    pdf_add_text($stream, $pageHeight, $tableX + 12.0, $rowTop + 16.0, '20 % USt.', 'F1', 10.0, $cMuted);
    pdf_add_text_right($stream, $pageHeight, $amountRight, $rowTop + 16.0, format_rechnung_amount_text($ust), 'F1', 10.0, $cText);

    $rowTop += $sumRowH;
    pdf_add_rect($stream, $pageHeight, $tableX, $rowTop, $tableW, $totalRowH, $cHeader, $cHeader, 0.7);
    pdf_add_text($stream, $pageHeight, $tableX + 12.0, $rowTop + 18.0, 'SUMME', 'F2', 11.0, $cWhite);
    pdf_add_text_right($stream, $pageHeight, $amountRight, $rowTop + 18.0, format_rechnung_amount_text($summe), 'F2', 11.0, $cWhite);

    $cursorTop += $tableHeight + 16.0;

    $invoiceReferenceRaw = trim((string) ($d['rechnungs_nr'] ?? ''));
    if ($invoiceReferenceRaw === '') {
        $invoiceReferenceRaw = '-';
    }
    $paymentReferenceLine = 'Wichtiger Hinweis: Bitte im Verwendungszweck die Rechnungsnummer "' . $invoiceReferenceRaw . '" angeben.';

    $paymentH = 94.0;
    pdf_add_rect($stream, $pageHeight, $innerX, $cursorTop, $innerW, $paymentH, $cSoft, $cBorder, 0.7);
    pdf_add_text($stream, $pageHeight, $innerX + 12.0, $cursorTop + 17.0, 'Zahlungsinformation', 'F2', 9.0, $cDim);
    pdf_add_text($stream, $pageHeight, $innerX + 12.0, $cursorTop + 33.0, 'Bitte überweisen Sie binnen 14 Tagen ab Rechnungsdatum.', 'F1', 10.0, $cMuted);
    $paymentBodyTop = pdf_add_wrapped_text(
        $stream,
        $pageHeight,
        $innerX + 12.0,
        $cursorTop + 47.0,
        $paymentReferenceLine,
        84,
        11.0,
        'F1',
        9.4,
        $cMuted,
        2
    ) + 3.0;
    pdf_add_text($stream, $pageHeight, $innerX + 12.0, $paymentBodyTop, 'Disinfo Combat GmbH  |  IBAN: AT39 2011 1844 5223 9900', 'F1', 10.0, $cText);
    pdf_add_text($stream, $pageHeight, $innerX + 12.0, $paymentBodyTop + 14.0, 'BIC: GIBAATWWXXX', 'F1', 10.0, $cText);

    $cursorTop += $paymentH + 18.0;

    pdf_add_text($stream, $pageHeight, $innerX, $cursorTop + 10.0, 'Wir danken für Ihren Auftrag und verbleiben', 'F1', 10.0, $cMuted);
    pdf_add_text($stream, $pageHeight, $innerX, $cursorTop + 24.0, 'mit freundlichen Grüßen', 'F1', 10.0, $cMuted);
    $sign = trim((string) ($d['absender_name'] ?? ''));
    if ($sign !== '') {
        pdf_add_text($stream, $pageHeight, $innerX, $cursorTop + 48.0, $sign, 'F2', 10.6, $cText);
    }

    pdf_add_text_right(
        $stream,
        $pageHeight,
        $panelX + $panelW - 12.0,
        $panelY + $panelH - 10.0,
        'Rechnungsnr.: ' . (string) ($d['rechnungs_nr'] ?? '-'),
        'F1',
        8.4,
        $cDim
    );

    $streamData = implode("\n", $stream) . "\n";

    $objects = [];
    $objects[] = "1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
    $objects[] = "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
    $objects[] = "3 0 obj\n<< /Type /Page /Parent 2 0 R /MediaBox [0 0 595 842] /Resources << /Font << /F1 4 0 R /F2 5 0 R /F3 6 0 R >> >> /Contents 7 0 R >>\nendobj\n";
    $objects[] = "4 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>\nendobj\n";
    $objects[] = "5 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";
    $objects[] = "6 0 obj\n<< /Type /Font /Subtype /Type1 /BaseFont /Times-Bold /Encoding /WinAnsiEncoding >>\nendobj\n";
    $objects[] = "7 0 obj\n<< /Length " . strlen($streamData) . " >>\nstream\n" . $streamData . "endstream\nendobj\n";

    $pdf = "%PDF-1.4\n";
    $offsets = [0];
    foreach ($objects as $object) {
        $offsets[] = strlen($pdf);
        $pdf .= $object;
    }

    $xrefPos = strlen($pdf);
    $pdf .= "xref\n0 " . (count($objects) + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= count($objects); $i++) {
        $pdf .= sprintf('%010d 00000 n ' . "\n", $offsets[$i]);
    }

    $pdf .= "trailer\n<< /Size " . (count($objects) + 1) . " /Root 1 0 R >>\n";
    $pdf .= "startxref\n" . $xrefPos . "\n%%EOF";

    return $pdf;
}
function send_rechnung_email(string $to, array $d): bool {
    $months = [
        '01' => 'Januar',  '02' => 'Februar', '03' => 'März',      '04' => 'April',
        '05' => 'Mai',     '06' => 'Juni',    '07' => 'Juli',      '08' => 'August',
        '09' => 'September','10' => 'Oktober','11' => 'November',  '12' => 'Dezember',
    ];

    $datumTs = strtotime((string) ($d['rechnung_datum'] ?? ''));
    $datumFormatted = ($datumTs)
        ? (int) date('j', $datumTs) . '. ' . ($months[date('m', $datumTs)] ?? date('m', $datumTs)) . ' ' . date('Y', $datumTs)
        : e((string) ($d['rechnung_datum'] ?? ''));

    $lineItems = [];
    if (isset($d['line_items']) && is_array($d['line_items'])) {
        foreach ($d['line_items'] as $item) {
            if (!is_array($item)) {
                continue;
            }

            $label = trim((string) ($item['label'] ?? ''));
            $amount = (float) ($item['amount'] ?? 0);
            if ($label === '' || abs($amount) < 0.00001) {
                continue;
            }

            $lineItems[] = [
                'label' => $label,
                'amount' => $amount,
            ];
        }
    }

    if (empty($lineItems)) {
        $pos1 = parse_rechnung_amount((string) ($d['pos1_betrag'] ?? '0'));
        $pos2 = parse_rechnung_amount((string) ($d['pos2_betrag'] ?? '0'));
        $pos1Label = trim((string) ($d['pos1_label'] ?? 'Position 1'));
        $pos2Label = trim((string) ($d['pos2_label'] ?? ''));

        if ($pos1Label !== '' && abs($pos1) > 0.00001) {
            $lineItems[] = ['label' => $pos1Label, 'amount' => $pos1];
        }
        if ($pos2Label !== '' && abs($pos2) > 0.00001) {
            $lineItems[] = ['label' => $pos2Label, 'amount' => $pos2];
        }
    }

    $zwischensumme = 0.0;
    foreach ($lineItems as $item) {
        $zwischensumme += (float) $item['amount'];
    }

    $ust = $zwischensumme * 0.20;
    $summe = $zwischensumme + $ust;
    $invoiceReferenceRaw = trim((string) ($d['rechnungs_nr'] ?? ''));
    if ($invoiceReferenceRaw === '') {
        $invoiceReferenceRaw = '-';
    }

    $row = fn(string $label, string $amount, bool $bold = false, string $topBorder = '', string $fs = '14px'): string =>
        '<tr>'
        . '<td style="padding:8px 0 8px 0;' . ($bold ? 'font-weight:bold;' : '') . ($topBorder ? "border-top:{$topBorder};" : '') . 'font-size:' . $fs . ';">' . $label . '</td>'
        . '<td style="text-align:right;white-space:nowrap;padding:8px 0 8px 16px;' . ($bold ? 'font-weight:bold;' : '') . ($topBorder ? "border-top:{$topBorder};" : '') . 'font-size:' . $fs . ';">' . $amount . '</td>'
        . '</tr>';

    $lineRows = '';
    foreach ($lineItems as $item) {
        $lineRows .= $row(e((string) $item['label']), format_rechnung_amount_html((float) $item['amount']));
    }

    $html = '
<div style="font-family:Arial,sans-serif;max-width:680px;width:100%;box-sizing:border-box;margin:0 auto;padding:32px 22px;color:#000;background:#fff;line-height:1.65;font-size:14px;overflow-wrap:anywhere;word-break:break-word;">

  <div style="margin-bottom:48px;">
    <strong>' . e((string) ($d['empfaenger'] ?? '')) . '</strong><br>
    ' . e((string) ($d['adresse'] ?? '')) . '<br>
    ' . e((string) ($d['plz_ort'] ?? '')) . '<br>
    z.&nbsp;Hd. ' . e((string) ($d['anrede'] ?? '')) . ' ' . e((string) ($d['kontakt_name'] ?? '')) . '<br>
    per E-Mail: ' . e((string) ($d['kontakt_email'] ?? '')) . '
  </div>

  <div style="text-align:right;margin-bottom:40px;">
    Wien, ' . $datumFormatted . '<br>
    Nr.&nbsp;' . e((string) ($d['rechnungs_nr'] ?? '')) . '
  </div>

  <p style="margin:0 0 6px 0;">Sehr geehrte Damen und Herren,</p>
  <p style="margin:0 0 28px 0;">für ' . e((string) ($d['fuer_text'] ?? '')) . '<br>
  <strong>' . e((string) ($d['workshop_titel'] ?? '')) . '</strong><br>
  am ' . e((string) ($d['veranstaltungs_datum'] ?? '')) . ' berechnen wir wie vereinbart:</p>

  <table style="width:100%;border-collapse:collapse;margin-bottom:8px;">
    ' . $lineRows
    . $row('Zwischensumme', format_rechnung_amount_html($zwischensumme), true, '1px solid #bbb')
    . $row('20&nbsp;%&nbsp;USt.', format_rechnung_amount_html($ust))
    . $row('SUMME', format_rechnung_amount_html($summe), true, '2px solid #000', '16px') . '
  </table>

  <p style="margin:32px 0 6px 0;">Wir bitten um Überweisung auf das untenstehende Konto binnen 14 Tagen ab Rechnungsdatum:</p>
  <p style="margin:0 0 10px 0;"><strong>Wichtiger Hinweis:</strong> Bitte geben Sie im Verwendungszweck zwingend die Rechnungsnummer "' . e($invoiceReferenceRaw) . '" an.</p>
  <div style="background:#f4f4f4;padding:16px 20px;border-radius:4px;margin:0 0 28px 0;">
    <strong>Disinfo Combat GmbH</strong><br>
    IBAN: AT39 2011 1844 5223 9900<br>
    BIC: GIBAATWWXXX
  </div>

  <p style="margin:0 0 4px 0;">Wir danken für Ihren Auftrag und verbleiben<br>mit freundlichen Grüßen</p>
  <p style="margin:28px 0 0 0;"><strong>' . e((string) ($d['absender_name'] ?? '')) . '</strong></p>

</div>';

    $subject = 'Rechnung: ' . (string) ($d['workshop_titel'] ?? '') . ' - Nr. ' . (string) ($d['rechnungs_nr'] ?? '');
    $pdfContent = build_rechnung_pdf($d, $lineItems, $zwischensumme, $ust, $summe, $datumFormatted);

    $invoiceNumberRaw = trim((string) ($d['rechnungs_nr'] ?? ''));
    if ($invoiceNumberRaw === '') {
        $invoiceNumberRaw = 'Rechnung';
    }
    $invoiceNumberSafe = preg_replace('/[^A-Za-z0-9._-]/', '_', $invoiceNumberRaw);
    $filename = 'Rechnung-' . $invoiceNumberSafe . '.pdf';

    return send_email_with_attachments($to, $subject, $html, [
        [
            'filename' => $filename,
            'mime' => 'application/pdf',
            'content' => $pdfContent,
        ],
    ], 'invoice');
}
/**
 * Send a custom email from admin.
 */
function send_custom_email(string $to, string $subject, string $messageText): bool {
    $content = '
        <div style="color:#d0d0d0;line-height:1.7;">' . nl2br(e($messageText)) . '</div>
        <hr style="border:none;border-top:1px solid #222;margin:30px 0;">
        <p style="color:#666;font-size:12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>';

    return send_email($to, $subject, render_booking_email_shell($content), 'admin_custom');
}
