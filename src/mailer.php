<?php
declare(strict_types=1);

function send_booking_confirmation(array $booking, array $workshop, array $invoiceSettings): bool
{
    $subject = 'Buchungsbestaetigung: ' . $workshop['title'];
    $to = (string) $booking['email'];

    $paymentDays = (int) ($invoiceSettings['payment_terms_days'] ?? 14);
    $policy = (string) ($invoiceSettings['cancellation_policy'] ?? '');

    $bodyLines = [
        'Hallo ' . $booking['name'] . ',',
        '',
        'vielen Dank fuer Ihre Buchung. Ihr Platz wurde erfolgreich reserviert.',
        '',
        'Workshop: ' . $workshop['title'],
        'Datum: ' . format_datetime_de((string) $workshop['date_starts']) . ' Uhr',
        'Ort: ' . $workshop['location'],
        'Preis: ' . format_price_eur((int) $workshop['price_cents']),
        'Rechnungsnummer: ' . $booking['invoice_number'],
        '',
        'Zahlungsziel: ' . $paymentDays . ' Tage',
        'Stornobedingungen: ' . $policy,
        '',
        'Rechnungsdaten:',
        (string) ($invoiceSettings['company_name'] ?? ''),
        (string) ($invoiceSettings['company_address'] ?? ''),
        ((string) ($invoiceSettings['company_vat'] ?? '') !== '' ? 'USt-ID: ' . $invoiceSettings['company_vat'] : ''),
        ((string) ($invoiceSettings['company_iban'] ?? '') !== '' ? 'IBAN: ' . $invoiceSettings['company_iban'] : ''),
        ((string) ($invoiceSettings['company_bic'] ?? '') !== '' ? 'BIC: ' . $invoiceSettings['company_bic'] : ''),
        '',
        'Wir freuen uns auf Ihre Teilnahme.',
    ];

    $body = trim(implode("\n", array_filter($bodyLines, static fn ($line) => $line !== null)));

    $from = (string) ($invoiceSettings['email_from'] ?? config('mail.default_from'));
    $replyTo = (string) ($invoiceSettings['reply_to'] ?? config('mail.default_reply_to'));

    $headers = [
        'MIME-Version: 1.0',
        'Content-Type: text/plain; charset=UTF-8',
        'From: ' . $from,
        'Reply-To: ' . $replyTo,
    ];

    $sent = @mail($to, $subject, $body, implode("\r\n", $headers));
    log_mail_event($to, $subject, $body, $sent);

    return $sent;
}

function log_mail_event(string $to, string $subject, string $body, bool $sent): void
{
    $path = (string) config('paths.mail_log');
    $entry = sprintf(
        "[%s] sent=%s to=%s subject=%s\n%s\n---\n",
        (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        $sent ? 'yes' : 'no',
        $to,
        $subject,
        $body
    );
    @file_put_contents($path, $entry, FILE_APPEND);
}

