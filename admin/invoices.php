<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/email.php';
require_admin();

function invoice_circle_code(string $prefix, int $year): string {
    return strtoupper(trim($prefix)) . '-' . $year;
}

function invoice_circle_label(string $prefix, int $year): string {
    return strtoupper(trim($prefix)) . ' ' . $year;
}

function invoice_number_display(array $circle, int $number): string {
    $label = trim((string) ($circle['circle_label'] ?? 'WS ' . date('Y')));

    return $label . ' / ' . $number;
}

function invoice_workshop_filter_value(int $workshopId, int $occurrenceId = 0): string {
    if ($workshopId <= 0) {
        return '0';
    }

    return $occurrenceId > 0 ? ($workshopId . ':' . $occurrenceId) : (string) $workshopId;
}

function parse_invoice_workshop_filter(string $raw): array {
    $raw = trim($raw);
    $workshopId = 0;
    $occurrenceId = 0;

    if (preg_match('/^(\d+):(\d+)$/', $raw, $match) === 1) {
        $workshopId = (int) $match[1];
        $occurrenceId = (int) $match[2];
    } elseif (ctype_digit($raw)) {
        $workshopId = (int) $raw;
    }

    return [
        'workshop_id' => $workshopId,
        'occurrence_id' => $occurrenceId,
        'value' => invoice_workshop_filter_value($workshopId, $occurrenceId),
    ];
}

function redirect_invoices(string $workshopFilter = '0', int $circleId = 0): never {
    $query = [];
    if ($workshopFilter !== '0' && $workshopFilter !== '') {
        $query['workshop_id'] = $workshopFilter;
    }
    if ($circleId > 0) {
        $query['circle_id'] = $circleId;
    }

    redirect(admin_url('invoices', $query));
}

$selectedWorkshopFilter = parse_invoice_workshop_filter((string) ($_GET['workshop_id'] ?? '0'));
$selectedWorkshopId = (int) $selectedWorkshopFilter['workshop_id'];
$selectedOccurrenceId = (int) $selectedWorkshopFilter['occurrence_id'];
$selectedWorkshopFilterValue = (string) $selectedWorkshopFilter['value'];
$selectedCircleId = max(0, (int) ($_GET['circle_id'] ?? 0));

$currentYear = (int) date('Y');
$defaultPrefix = 'WS';
$defaultCode = invoice_circle_code($defaultPrefix, $currentYear);
$defaultLabel = invoice_circle_label($defaultPrefix, $currentYear);

$defaultCircleStmt = $db->prepare('SELECT id FROM invoice_circles WHERE circle_code = :code LIMIT 1');
$defaultCircleStmt->bindValue(':code', $defaultCode, SQLITE3_TEXT);
$defaultCircle = $defaultCircleStmt->execute()->fetchArray(SQLITE3_ASSOC);
if (!$defaultCircle) {
    $insertDefaultCircleStmt = $db->prepare('
        INSERT INTO invoice_circles (circle_code, circle_label, prefix, year, next_number, active, created_at, updated_at)
        VALUES (:code, :label, :prefix, :year, 1, 1, datetime("now"), datetime("now"))
    ');
    $insertDefaultCircleStmt->bindValue(':code', $defaultCode, SQLITE3_TEXT);
    $insertDefaultCircleStmt->bindValue(':label', $defaultLabel, SQLITE3_TEXT);
    $insertDefaultCircleStmt->bindValue(':prefix', $defaultPrefix, SQLITE3_TEXT);
    $insertDefaultCircleStmt->bindValue(':year', $currentYear, SQLITE3_INTEGER);
    $insertDefaultCircleStmt->execute();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    flash('error', 'Ungültige Sitzung.');
    redirect_invoices($selectedWorkshopFilterValue, $selectedCircleId);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['create_circle_submit'])) {
        $prefixRaw = strtoupper(trim((string) ($_POST['circle_prefix'] ?? 'WS')));
        $prefix = preg_replace('/[^A-Z0-9]/', '', $prefixRaw) ?? '';
        $year = (int) ($_POST['circle_year'] ?? date('Y'));
        $nextNumber = max(1, (int) ($_POST['circle_next_number'] ?? 1));

        if ($prefix === '' || strlen($prefix) > 12) {
            flash('error', 'Prefix muss 1-12 Zeichen (A-Z, 0-9) haben.');
            redirect_invoices($selectedWorkshopFilterValue, $selectedCircleId);
        }
        if ($year < 2000 || $year > 2100) {
            flash('error', 'Jahr muss zwischen 2000 und 2100 liegen.');
            redirect_invoices($selectedWorkshopFilterValue, $selectedCircleId);
        }

        $code = invoice_circle_code($prefix, $year);
        $label = invoice_circle_label($prefix, $year);

        $existsStmt = $db->prepare('SELECT id FROM invoice_circles WHERE circle_code = :code LIMIT 1');
        $existsStmt->bindValue(':code', $code, SQLITE3_TEXT);
        $existsRow = $existsStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if ($existsRow) {
            flash('error', 'Dieser Rechnungskreis existiert bereits.');
            redirect_invoices($selectedWorkshopFilterValue, (int) $existsRow['id']);
        }

        $createStmt = $db->prepare('
            INSERT INTO invoice_circles (circle_code, circle_label, prefix, year, next_number, active, created_at, updated_at)
            VALUES (:code, :label, :prefix, :year, :next, 1, datetime("now"), datetime("now"))
        ');
        $createStmt->bindValue(':code', $code, SQLITE3_TEXT);
        $createStmt->bindValue(':label', $label, SQLITE3_TEXT);
        $createStmt->bindValue(':prefix', $prefix, SQLITE3_TEXT);
        $createStmt->bindValue(':year', $year, SQLITE3_INTEGER);
        $createStmt->bindValue(':next', $nextNumber, SQLITE3_INTEGER);
        $createStmt->execute();

        $newCircleId = (int) $db->lastInsertRowID();
        flash('success', 'Rechnungskreis ' . $label . ' wurde angelegt.');
        redirect_invoices($selectedWorkshopFilterValue, $newCircleId);
    }

    if (isset($_POST['adjust_counter_submit'])) {
        $adjustCircleId = max(0, (int) ($_POST['adjust_circle_id'] ?? 0));
        $newNext = max(1, (int) ($_POST['adjust_new_next'] ?? 0));
        $reason = trim((string) ($_POST['adjust_reason'] ?? ''));

        if ($adjustCircleId <= 0) {
            flash('error', 'Bitte einen Rechnungskreis wählen.');
            redirect_invoices($selectedWorkshopFilterValue, $selectedCircleId);
        }
        if ($reason === '') {
            flash('error', 'Begründung ist verpflichtend.');
            redirect_invoices($selectedWorkshopFilterValue, $adjustCircleId);
        }

        $circleStmt = $db->prepare('SELECT * FROM invoice_circles WHERE id = :id LIMIT 1');
        $circleStmt->bindValue(':id', $adjustCircleId, SQLITE3_INTEGER);
        $circle = $circleStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$circle) {
            flash('error', 'Rechnungskreis nicht gefunden.');
            redirect_invoices($selectedWorkshopFilterValue, $selectedCircleId);
        }

        $oldNext = max(1, (int) ($circle['next_number'] ?? 1));

        $maxIssuedStmt = $db->prepare('SELECT COALESCE(MAX(invoice_number), 0) AS max_number FROM invoices WHERE circle_id = :cid');
        $maxIssuedStmt->bindValue(':cid', $adjustCircleId, SQLITE3_INTEGER);
        $maxIssued = (int) ($maxIssuedStmt->execute()->fetchArray(SQLITE3_ASSOC)['max_number'] ?? 0);

        if ($newNext <= $maxIssued) {
            flash('error', 'Neue Startnummer muss größer als die höchste bereits vergebene Nummer sein (' . $maxIssued . ').');
            redirect_invoices($selectedWorkshopFilterValue, $adjustCircleId);
        }

        if ($newNext === $oldNext) {
            flash('error', 'Neue Startnummer ist identisch mit dem aktuellen Stand.');
            redirect_invoices($selectedWorkshopFilterValue, $adjustCircleId);
        }

        $inTransaction = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $inTransaction = true;

            $updateCircleStmt = $db->prepare('UPDATE invoice_circles SET next_number = :next, updated_at = datetime("now") WHERE id = :id');
            $updateCircleStmt->bindValue(':next', $newNext, SQLITE3_INTEGER);
            $updateCircleStmt->bindValue(':id', $adjustCircleId, SQLITE3_INTEGER);
            $updateCircleStmt->execute();

            $auditStmt = $db->prepare('
                INSERT INTO invoice_counter_audit_log (circle_id, previous_next_number, new_next_number, reason, changed_by, changed_at)
                VALUES (:cid, :prev, :new, :reason, :changed_by, datetime("now"))
            ');
            $auditStmt->bindValue(':cid', $adjustCircleId, SQLITE3_INTEGER);
            $auditStmt->bindValue(':prev', $oldNext, SQLITE3_INTEGER);
            $auditStmt->bindValue(':new', $newNext, SQLITE3_INTEGER);
            $auditStmt->bindValue(':reason', $reason, SQLITE3_TEXT);
            $auditStmt->bindValue(':changed_by', 'admin', SQLITE3_TEXT);
            $auditStmt->execute();

            $db->exec('COMMIT');
            $inTransaction = false;
        } catch (Throwable) {
            if ($inTransaction) {
                $db->exec('ROLLBACK');
            }
            flash('error', 'Counter konnte nicht angepasst werden.');
            redirect_invoices($selectedWorkshopFilterValue, $adjustCircleId);
        }

        flash('success', 'Counter aktualisiert: ' . $oldNext . ' -> ' . $newNext . '. Änderung wurde im Audit-Log gespeichert.');
        redirect_invoices($selectedWorkshopFilterValue, $adjustCircleId);
    }

    if (isset($_POST['invoice_resend_submit'])) {
        $postWorkshopId = max(0, (int) ($_POST['invoice_workshop_id'] ?? 0));
        $postOccurrenceId = max(0, (int) ($_POST['invoice_occurrence_id'] ?? 0));
        $postWorkshopFilterValue = invoice_workshop_filter_value($postWorkshopId, $postOccurrenceId);
        $postCircleId = max(0, (int) ($_POST['invoice_circle_id'] ?? 0));

        $resendIndex = max(0, (int) ($_POST['invoice_resend_submit'] ?? 0));
        $invoiceId = max(0, (int) ($_POST['r_existing_invoice_id'][$resendIndex] ?? 0));
        $recipientEmail = trim((string) ($_POST['r_resend_email'][$resendIndex] ?? ''));

        if ($postWorkshopId <= 0 || $postCircleId <= 0 || $invoiceId <= 0) {
            flash('error', 'Resend-Daten unvollständig. Bitte erneut versuchen.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }
        if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            flash('error', 'Bitte eine gültige Empfänger-E-Mail für den erneuten Versand angeben.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $invoiceFetchSql = '
            SELECT
                i.id,
                i.invoice_number_display,
                i.payload_json
            FROM invoices i
            JOIN bookings b ON b.id = i.booking_id
            WHERE i.id = :iid AND i.workshop_id = :wid
        ';
        if ($postOccurrenceId > 0) {
            $invoiceFetchSql .= ' AND b.occurrence_id = :oid';
        }
        $invoiceFetchSql .= ' LIMIT 1';

        $invoiceFetchStmt = $db->prepare($invoiceFetchSql);
        $invoiceFetchStmt->bindValue(':iid', $invoiceId, SQLITE3_INTEGER);
        $invoiceFetchStmt->bindValue(':wid', $postWorkshopId, SQLITE3_INTEGER);
        if ($postOccurrenceId > 0) {
            $invoiceFetchStmt->bindValue(':oid', $postOccurrenceId, SQLITE3_INTEGER);
        }
        $invoiceRow = $invoiceFetchStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$invoiceRow) {
            flash('error', 'Finalisierte Rechnung wurde für diesen Filter nicht gefunden.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $payload = json_decode((string) ($invoiceRow['payload_json'] ?? ''), true);
        if (!is_array($payload) || empty($payload)) {
            flash('error', 'Rechnung kann nicht erneut gesendet werden: Rechnungsdaten fehlen.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $payload['kontakt_email'] = $recipientEmail;
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if (!is_string($payloadJson) || $payloadJson === '') {
            $payloadJson = '{}';
        }

        $ok = send_rechnung_email($recipientEmail, $payload);
        $status = $ok ? 'sent' : 'send_failed';

        $updateInvoiceStmt = $db->prepare('
            UPDATE invoices
            SET
                recipient_email = :recipient_email,
                payload_json = :payload_json,
                send_status = :status,
                sent_at = CASE WHEN :status = "sent" THEN datetime("now") ELSE sent_at END
            WHERE id = :id
        ');
        $updateInvoiceStmt->bindValue(':recipient_email', $recipientEmail, SQLITE3_TEXT);
        $updateInvoiceStmt->bindValue(':payload_json', $payloadJson, SQLITE3_TEXT);
        $updateInvoiceStmt->bindValue(':status', $status, SQLITE3_TEXT);
        $updateInvoiceStmt->bindValue(':id', $invoiceId, SQLITE3_INTEGER);
        $updateInvoiceStmt->execute();

        $invoiceNumberDisplay = trim((string) ($invoiceRow['invoice_number_display'] ?? ''));
        if ($invoiceNumberDisplay === '') {
            $invoiceNumberDisplay = '#' . $invoiceId;
        }

        if ($ok) {
            flash('success', 'Rechnung ' . $invoiceNumberDisplay . ' wurde erneut an ' . $recipientEmail . ' gesendet.');
        } else {
            flash('error', 'Rechnung ' . $invoiceNumberDisplay . ' konnte nicht erneut an ' . $recipientEmail . ' gesendet werden.');
        }
        redirect_invoices($postWorkshopFilterValue, $postCircleId);
    }

    if (isset($_POST['invoice_send_submit'])) {
        $postWorkshopId = max(0, (int) ($_POST['invoice_workshop_id'] ?? 0));
        $postOccurrenceId = max(0, (int) ($_POST['invoice_occurrence_id'] ?? 0));
        $postWorkshopFilterValue = invoice_workshop_filter_value($postWorkshopId, $postOccurrenceId);
        $postCircleId = max(0, (int) ($_POST['invoice_circle_id'] ?? 0));

        if ($postWorkshopId <= 0 || $postCircleId <= 0) {
            flash('error', 'Workshop und Rechnungskreis sind erforderlich.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $workshop = get_workshop_by_id($db, $postWorkshopId);
        if (!$workshop) {
            flash('error', 'Workshop nicht gefunden.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        if ($postOccurrenceId > 0) {
            $postOccurrence = get_workshop_occurrence_by_id($db, $postWorkshopId, $postOccurrenceId, false);
            if (!$postOccurrence) {
                flash('error', 'Ausgewählter Termin wurde nicht gefunden.');
                redirect_invoices(invoice_workshop_filter_value($postWorkshopId), $postCircleId);
            }
        }

        $circleStmt = $db->prepare('SELECT * FROM invoice_circles WHERE id = :id LIMIT 1');
        $circleStmt->bindValue(':id', $postCircleId, SQLITE3_INTEGER);
        $circle = $circleStmt->execute()->fetchArray(SQLITE3_ASSOC);
        if (!$circle) {
            flash('error', 'Rechnungskreis nicht gefunden.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $commonData = [
            'rechnung_datum'       => trim((string) ($_POST['r_rechnung_datum'] ?? date('Y-m-d'))),
            'fuer_text'            => trim((string) ($_POST['r_fuer_text'] ?? '')),
            'workshop_titel'       => trim((string) ($_POST['r_workshop_titel'] ?? '')),
            'veranstaltungs_datum' => trim((string) ($_POST['r_veranstaltungs_datum'] ?? '')),
            'pos1_label'           => trim((string) ($_POST['r_pos1_label'] ?? '')),
            'pos1_betrag'          => trim((string) ($_POST['r_pos1_betrag'] ?? '0')),
            'pos2_label'           => trim((string) ($_POST['r_pos2_label'] ?? '')),
            'pos2_betrag'          => trim((string) ($_POST['r_pos2_betrag'] ?? '')),
            'absender_name'        => trim((string) ($_POST['r_absender_name'] ?? '')),
        ];

        $selectedCards = $_POST['r_booking_selected'] ?? [];
        if (!is_array($selectedCards) || empty($selectedCards)) {
            flash('error', 'Bitte mindestens eine Buchung auswählen.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $baseLineItems = [];
        $pos1Amount = parse_rechnung_amount((string) ($commonData['pos1_betrag'] ?? '0'));
        $pos2Amount = parse_rechnung_amount((string) ($commonData['pos2_betrag'] ?? '0'));
        $pos1Label = trim((string) ($commonData['pos1_label'] ?? ''));
        $pos2Label = trim((string) ($commonData['pos2_label'] ?? ''));

        if ($pos1Label !== '' && abs($pos1Amount) > 0.00001) {
            $baseLineItems[] = ['label' => $pos1Label, 'amount' => $pos1Amount];
        }
        if ($pos2Label !== '' && abs($pos2Amount) > 0.00001) {
            $baseLineItems[] = ['label' => $pos2Label, 'amount' => $pos2Amount];
        }

        if (empty($baseLineItems)) {
            flash('error', 'Mindestens eine Rechnungsposition mit Betrag ist erforderlich.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $baseSubtotal = 0.0;
        foreach ($baseLineItems as $lineItem) {
            $baseSubtotal += (float) $lineItem['amount'];
        }

        $selectedIndices = array_map(static fn($idx) => (int) $idx, array_keys($selectedCards));
        sort($selectedIndices, SORT_NUMERIC);

        $bookingFetchSql = '
            SELECT id, name, email, organization, discount_code, discount_amount
            FROM bookings
            WHERE id = :id AND workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0
        ';
        if ($postOccurrenceId > 0) {
            $bookingFetchSql .= ' AND occurrence_id = :oid';
        }
        $bookingFetchSql .= ' LIMIT 1';
        $bookingFetchStmt = $db->prepare($bookingFetchSql);
        $existingInvoiceStmt = $db->prepare('SELECT id, invoice_number_display FROM invoices WHERE booking_id = :bid LIMIT 1');
        $insertInvoiceStmt = $db->prepare('
            INSERT INTO invoices (
                workshop_id,
                booking_id,
                circle_id,
                invoice_number,
                invoice_number_display,
                recipient_name,
                recipient_email,
                send_status,
                issued_at,
                line_items_json,
                payload_json
            ) VALUES (
                :workshop_id,
                :booking_id,
                :circle_id,
                :invoice_number,
                :invoice_number_display,
                :recipient_name,
                :recipient_email,
                :send_status,
                datetime("now"),
                :line_items_json,
                :payload_json
            )
        ');

        $queue = [];
        $warnings = [];
        $inTransaction = false;

        try {
            $db->exec('BEGIN IMMEDIATE');
            $inTransaction = true;

            $lockCircleStmt = $db->prepare('SELECT * FROM invoice_circles WHERE id = :id LIMIT 1');
            $lockCircleStmt->bindValue(':id', $postCircleId, SQLITE3_INTEGER);
            $lockedCircle = $lockCircleStmt->execute()->fetchArray(SQLITE3_ASSOC);
            if (!$lockedCircle) {
                throw new RuntimeException('Rechnungskreis nicht gefunden.');
            }

            $nextNumber = max(1, (int) ($lockedCircle['next_number'] ?? 1));
            $nextNumberStart = $nextNumber;

            foreach ($selectedIndices as $idx) {
                $bookingId = max(0, (int) ($_POST['r_booking_id'][$idx] ?? 0));
                if ($bookingId <= 0) {
                    continue;
                }

                $recipientEmail = trim((string) ($_POST['r_booking_kontakt_email'][$idx] ?? ''));
                if (!filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
                    $warnings[] = 'Buchung #' . $bookingId . ': ungültige Empfänger-E-Mail, übersprungen.';
                    continue;
                }

                $bookingFetchStmt->bindValue(':id', $bookingId, SQLITE3_INTEGER);
                $bookingFetchStmt->bindValue(':wid', $postWorkshopId, SQLITE3_INTEGER);
                if ($postOccurrenceId > 0) {
                    $bookingFetchStmt->bindValue(':oid', $postOccurrenceId, SQLITE3_INTEGER);
                }
                $bookingRow = $bookingFetchStmt->execute()->fetchArray(SQLITE3_ASSOC);
                if (!$bookingRow) {
                    $warnings[] = 'Buchung #' . $bookingId . ' nicht bestätigt oder nicht gefunden, übersprungen.';
                    continue;
                }

                $existingInvoiceStmt->bindValue(':bid', $bookingId, SQLITE3_INTEGER);
                $existingInvoice = $existingInvoiceStmt->execute()->fetchArray(SQLITE3_ASSOC);
                if ($existingInvoice) {
                    $warnings[] = 'Buchung #' . $bookingId . ' hat bereits eine finalisierte Rechnung (' . (string) $existingInvoice['invoice_number_display'] . ').';
                    continue;
                }

                $invoiceLineItems = $baseLineItems;
                $discountCode = trim((string) ($bookingRow['discount_code'] ?? ''));
                $discountAmount = max(0.0, (float) ($bookingRow['discount_amount'] ?? 0));
                if ($discountAmount > 0 && $baseSubtotal > 0) {
                    $effectiveDiscount = min($discountAmount, $baseSubtotal);
                    $discountLabel = 'Rabatt';
                    if ($discountCode !== '') {
                        $discountLabel .= ' (' . $discountCode . ')';
                    }
                    $invoiceLineItems[] = [
                        'label' => $discountLabel,
                        'amount' => -$effectiveDiscount,
                    ];
                }

                $displayNumber = invoice_number_display($lockedCircle, $nextNumber);

                $invoicePayload = array_merge($commonData, [
                    'empfaenger'    => trim((string) ($_POST['r_booking_empfaenger'][$idx] ?? '')),
                    'adresse'       => trim((string) ($_POST['r_booking_adresse'][$idx] ?? '')),
                    'plz_ort'       => trim((string) ($_POST['r_booking_plz_ort'][$idx] ?? '')),
                    'anrede'        => trim((string) ($_POST['r_booking_anrede'][$idx] ?? 'Herrn')),
                    'kontakt_name'  => trim((string) ($_POST['r_booking_kontakt_name'][$idx] ?? '')),
                    'kontakt_email' => $recipientEmail,
                    'rechnungs_nr'  => $displayNumber,
                    'line_items'    => $invoiceLineItems,
                ]);

                $lineItemsJson = json_encode($invoiceLineItems, JSON_UNESCAPED_UNICODE);
                if (!is_string($lineItemsJson)) {
                    $lineItemsJson = '[]';
                }
                $payloadJson = json_encode($invoicePayload, JSON_UNESCAPED_UNICODE);
                if (!is_string($payloadJson)) {
                    $payloadJson = '{}';
                }

                $insertInvoiceStmt->bindValue(':workshop_id', $postWorkshopId, SQLITE3_INTEGER);
                $insertInvoiceStmt->bindValue(':booking_id', $bookingId, SQLITE3_INTEGER);
                $insertInvoiceStmt->bindValue(':circle_id', $postCircleId, SQLITE3_INTEGER);
                $insertInvoiceStmt->bindValue(':invoice_number', $nextNumber, SQLITE3_INTEGER);
                $insertInvoiceStmt->bindValue(':invoice_number_display', $displayNumber, SQLITE3_TEXT);
                $insertInvoiceStmt->bindValue(':recipient_name', trim((string) ($_POST['r_booking_kontakt_name'][$idx] ?? '')), SQLITE3_TEXT);
                $insertInvoiceStmt->bindValue(':recipient_email', $recipientEmail, SQLITE3_TEXT);
                $insertInvoiceStmt->bindValue(':send_status', 'issued', SQLITE3_TEXT);
                $insertInvoiceStmt->bindValue(':line_items_json', $lineItemsJson, SQLITE3_TEXT);
                $insertInvoiceStmt->bindValue(':payload_json', $payloadJson, SQLITE3_TEXT);
                $insertInvoiceStmt->execute();

                $invoiceId = (int) $db->lastInsertRowID();
                $queue[] = [
                    'invoice_id' => $invoiceId,
                    'email' => $recipientEmail,
                    'payload' => $invoicePayload,
                    'booking_id' => $bookingId,
                    'number_display' => $displayNumber,
                ];

                $nextNumber++;
            }

            if ($nextNumber !== $nextNumberStart) {
                $updateCircleStmt = $db->prepare('UPDATE invoice_circles SET next_number = :next, updated_at = datetime("now") WHERE id = :id');
                $updateCircleStmt->bindValue(':next', $nextNumber, SQLITE3_INTEGER);
                $updateCircleStmt->bindValue(':id', $postCircleId, SQLITE3_INTEGER);
                $updateCircleStmt->execute();
            }

            $db->exec('COMMIT');
            $inTransaction = false;
        } catch (Throwable) {
            if ($inTransaction) {
                $db->exec('ROLLBACK');
            }
            flash('error', 'Rechnungsnummern konnten nicht reserviert werden.');
            redirect_invoices($postWorkshopFilterValue, $postCircleId);
        }

        $statusUpdateStmt = $db->prepare('UPDATE invoices SET send_status = :status, sent_at = CASE WHEN :status = "sent" THEN datetime("now") ELSE sent_at END WHERE id = :id');
        $sent = 0;
        $failed = 0;
        foreach ($queue as $entry) {
            $ok = send_rechnung_email((string) $entry['email'], (array) $entry['payload']);
            $status = $ok ? 'sent' : 'send_failed';
            $statusUpdateStmt->bindValue(':status', $status, SQLITE3_TEXT);
            $statusUpdateStmt->bindValue(':id', (int) $entry['invoice_id'], SQLITE3_INTEGER);
            $statusUpdateStmt->execute();

            if ($ok) {
                $sent++;
            } else {
                $failed++;
                $warnings[] = 'Rechnung ' . (string) $entry['number_display'] . ' konnte nicht per E-Mail gesendet werden.';
            }
        }

        if (empty($queue)) {
            flash('error', 'Keine Rechnungen erstellt. Bitte Auswahl prüfen.');
        } else {
            $summary = 'Rechnungen finalisiert: ' . count($queue) . ', versendet: ' . $sent . ', fehlgeschlagen: ' . $failed . '.';
            flash($failed > 0 ? 'error' : 'success', $summary);
        }
        foreach ($warnings as $warning) {
            flash('error', $warning);
        }

        redirect_invoices($postWorkshopFilterValue, $postCircleId);
    }
}

$workshops = [];
$workshopFilterOptions = [];
$workshopsResult = $db->query('SELECT id, title, workshop_type, event_date, event_date_end, tag_label, price_netto FROM workshops ORDER BY sort_order ASC, title ASC');
while ($row = $workshopsResult->fetchArray(SQLITE3_ASSOC)) {
    $workshops[] = $row;

    $wid = (int) ($row['id'] ?? 0);
    $title = trim((string) ($row['title'] ?? ''));
    if ($wid <= 0 || $title === '') {
        continue;
    }

    $isOpenWorkshop = ((string) ($row['workshop_type'] ?? '') === 'open');
    $occurrencesForFilter = [];

    if ($isOpenWorkshop) {
        $occurrencesForFilter = get_workshop_occurrences($db, $wid, true);
        if (empty($occurrencesForFilter) && trim((string) ($row['event_date'] ?? '')) !== '') {
            $occurrencesForFilter[] = [
                'id' => 0,
                'start_at' => (string) ($row['event_date'] ?? ''),
                'end_at' => (string) ($row['event_date_end'] ?? ''),
            ];
        }
    }

    if (!$isOpenWorkshop) {
        $workshopFilterOptions[] = [
            'value' => (string) $wid,
            'label' => $title,
        ];
        continue;
    }

    if (count($occurrencesForFilter) <= 1) {
        $workshopFilterOptions[] = [
            'value' => (string) $wid,
            'label' => $title,
        ];
        continue;
    }

    foreach ($occurrencesForFilter as $occurrenceRow) {
        $oid = (int) ($occurrenceRow['id'] ?? 0);
        if ($oid <= 0) {
            continue;
        }

        $dateLabel = format_event_date(
            (string) ($occurrenceRow['start_at'] ?? ''),
            (string) ($occurrenceRow['end_at'] ?? '')
        );
        if ($dateLabel === '') {
            continue;
        }

        $workshopFilterOptions[] = [
            'value' => $wid . ':' . $oid,
            'label' => $title . ' - ' . $dateLabel,
        ];
    }
}

$invoiceCircles = [];
$circlesResult = $db->query('
    SELECT
        c.*,
        (SELECT COUNT(*) FROM invoices i WHERE i.circle_id = c.id) AS issued_count,
        (SELECT COALESCE(MAX(i.invoice_number), 0) FROM invoices i WHERE i.circle_id = c.id) AS max_issued_number
    FROM invoice_circles c
    ORDER BY c.year DESC, c.prefix ASC, c.id DESC
');
while ($row = $circlesResult->fetchArray(SQLITE3_ASSOC)) {
    $invoiceCircles[] = $row;
}

if ($selectedCircleId <= 0 && !empty($invoiceCircles)) {
    $selectedCircleId = (int) $invoiceCircles[0]['id'];
}

$selectedCircle = null;
foreach ($invoiceCircles as $circleRow) {
    if ((int) $circleRow['id'] === $selectedCircleId) {
        $selectedCircle = $circleRow;
        break;
    }
}
if (!$selectedCircle && !empty($invoiceCircles)) {
    $selectedCircle = $invoiceCircles[0];
    $selectedCircleId = (int) $selectedCircle['id'];
}

$selectedWorkshop = null;
$selectedOccurrence = null;
$selectedOccurrenceLabel = '';
if ($selectedWorkshopId > 0) {
    $selectedWorkshop = get_workshop_by_id($db, $selectedWorkshopId);
    if (!$selectedWorkshop) {
        $selectedWorkshopId = 0;
        $selectedOccurrenceId = 0;
    }
}
if ($selectedWorkshop && $selectedOccurrenceId > 0) {
    $selectedOccurrence = get_workshop_occurrence_by_id($db, $selectedWorkshopId, $selectedOccurrenceId, false);
    if (!$selectedOccurrence) {
        $selectedOccurrenceId = 0;
    } else {
        $selectedOccurrenceLabel = format_event_date(
            (string) ($selectedOccurrence['start_at'] ?? ''),
            (string) ($selectedOccurrence['end_at'] ?? '')
        );
    }
}
$selectedWorkshopFilterValue = invoice_workshop_filter_value($selectedWorkshopId, $selectedOccurrenceId);

$confirmedBookingsForInvoices = [];
if ($selectedWorkshopId > 0) {
    $bookingsSql = '
        SELECT
            b.id,
            b.name,
            b.email,
            b.organization,
            b.discount_code,
            b.discount_amount,
            b.confirmed_at,
            b.created_at,
            b.occurrence_id,
            o.start_at AS occurrence_start_at,
            o.end_at AS occurrence_end_at,
            i.id AS invoice_id,
            i.invoice_number_display,
            i.send_status,
            i.recipient_email AS invoice_recipient_email
        FROM bookings b
        LEFT JOIN workshop_occurrences o ON o.id = b.occurrence_id AND o.workshop_id = b.workshop_id
        LEFT JOIN invoices i ON i.booking_id = b.id
        WHERE b.workshop_id = :wid AND b.confirmed = 1 AND COALESCE(b.archived, 0) = 0
    ';
    if ($selectedOccurrenceId > 0) {
        $bookingsSql .= ' AND b.occurrence_id = :oid';
    }
    $bookingsSql .= ' ORDER BY b.confirmed_at ASC, b.created_at ASC';

    $bookingsStmt = $db->prepare($bookingsSql);
    $bookingsStmt->bindValue(':wid', $selectedWorkshopId, SQLITE3_INTEGER);
    if ($selectedOccurrenceId > 0) {
        $bookingsStmt->bindValue(':oid', $selectedOccurrenceId, SQLITE3_INTEGER);
    }
    $bookingsResult = $bookingsStmt->execute();
    while ($row = $bookingsResult->fetchArray(SQLITE3_ASSOC)) {
        $confirmedBookingsForInvoices[] = $row;
    }
}

$recentInvoices = [];
$recentInvoicesResult = $db->query('
    SELECT
        i.invoice_number_display,
        i.invoice_number,
        i.send_status,
        i.issued_at,
        i.sent_at,
        i.recipient_email,
        w.title AS workshop_title,
        o.start_at AS occurrence_start_at,
        o.end_at AS occurrence_end_at,
        c.circle_label,
        b.name AS booking_name
    FROM invoices i
    JOIN workshops w ON w.id = i.workshop_id
    JOIN invoice_circles c ON c.id = i.circle_id
    JOIN bookings b ON b.id = i.booking_id
    LEFT JOIN workshop_occurrences o ON o.id = b.occurrence_id AND o.workshop_id = w.id
    ORDER BY i.issued_at DESC, i.id DESC
    LIMIT 40
');
while ($row = $recentInvoicesResult->fetchArray(SQLITE3_ASSOC)) {
    $recentInvoices[] = $row;
}

$auditRows = [];
$auditResult = $db->query('
    SELECT
        l.*,
        c.circle_label,
        c.circle_code
    FROM invoice_counter_audit_log l
    JOIN invoice_circles c ON c.id = l.circle_id
    ORDER BY l.changed_at DESC, l.id DESC
    LIMIT 80
');
while ($row = $auditResult->fetchArray(SQLITE3_ASSOC)) {
    $auditRows[] = $row;
}

$evtDateDefault = $selectedOccurrenceLabel !== ''
    ? $selectedOccurrenceLabel
    : (($selectedWorkshop && !empty($selectedWorkshop['event_date']))
        ? format_event_date((string) $selectedWorkshop['event_date'], (string) ($selectedWorkshop['event_date_end'] ?? ''))
        : '');
$pos1LabelDefault = $selectedWorkshop
    ? (!empty($selectedWorkshop['tag_label']) ? (string) $selectedWorkshop['tag_label'] : (string) $selectedWorkshop['title'])
    : '';
$pos1BetragDefault = ($selectedWorkshop && (float) ($selectedWorkshop['price_netto'] ?? 0) > 0)
    ? number_format((float) $selectedWorkshop['price_netto'], 2, ',', '.')
    : '';
?>
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
    <title>Rechnungen - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .invoice-grid-top {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            margin-bottom: 1.4rem;
        }
        .invoice-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1rem;
        }
        .invoice-card h2 {
            font-family: var(--font-h);
            font-size: 1.15rem;
            margin-bottom: 0.25rem;
            font-weight: 400;
        }
        .invoice-card-note {
            color: var(--dim);
            font-size: 0.78rem;
            margin-bottom: 0.85rem;
            line-height: 1.45;
        }
        .invoice-circle-list {
            display: grid;
            gap: 0.55rem;
            margin-bottom: 1rem;
            max-height: 260px;
            overflow-y: auto;
            padding-right: 0.2rem;
        }
        .invoice-circle-item {
            border: 1px solid var(--border);
            background: var(--surface-soft);
            border-radius: 8px;
            padding: 0.6rem 0.7rem;
            display: grid;
            gap: 0.3rem;
        }
        .invoice-circle-item.active {
            border-color: rgba(46, 204, 113, 0.38);
        }
        .invoice-circle-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.5rem;
        }
        .invoice-circle-label {
            font-size: 0.86rem;
            font-weight: 600;
            color: var(--text);
        }
        .invoice-circle-meta {
            color: var(--dim);
            font-size: 0.72rem;
            line-height: 1.4;
        }
        .invoice-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            padding: 0.12rem 0.42rem;
            font-size: 0.64rem;
            border-radius: 999px;
            border: 1px solid var(--border);
            color: var(--dim);
            line-height: 1.2;
        }
        .invoice-badge.ok {
            border-color: rgba(46, 204, 113, 0.45);
            color: #2ecc71;
        }
        .invoice-badge.fail {
            border-color: rgba(231, 76, 60, 0.45);
            color: #e74c3c;
        }
        .invoice-toolbar {
            margin-bottom: 1rem;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 0.9rem;
        }
        .invoice-toolbar form {
            display: grid;
            gap: 0.65rem;
            grid-template-columns: 1fr 1fr auto;
            align-items: end;
        }
        .invoice-toolbar .form-group {
            margin-bottom: 0;
        }
        .invoice-booking-list {
            display: grid;
            gap: 0.65rem;
            margin-top: 0.85rem;
        }
        .invoice-booking-card {
            border: 1px solid var(--border);
            border-radius: var(--radius);
            background: var(--surface-soft);
            padding: 0.75rem;
            display: grid;
            gap: 0.65rem;
        }
        .invoice-booking-head {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.7rem;
            flex-wrap: wrap;
        }
        .invoice-booking-head label {
            display: flex;
            align-items: center;
            gap: 0.55rem;
            min-width: 0;
            flex: 1;
        }
        .invoice-booking-title {
            font-size: 0.82rem;
            color: var(--text);
            line-height: 1.35;
            word-break: break-word;
        }
        .invoice-booking-sub {
            color: var(--dim);
            font-size: 0.72rem;
        }
        .invoice-booking-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.55rem;
        }
        .invoice-booking-grid .form-group {
            margin-bottom: 0;
        }
        .invoice-number-preview {
            font-size: 0.74rem;
            color: var(--dim);
            border: 1px dashed var(--border);
            border-radius: 6px;
            padding: 0.35rem 0.45rem;
            width: fit-content;
            max-width: 100%;
        }
        .invoice-resend-wrap {
            display: grid;
            gap: 0.5rem;
            padding: 0.55rem 0.6rem;
            border: 1px dashed var(--border);
            border-radius: 8px;
            background: var(--surface);
        }
        .invoice-resend-title {
            color: var(--dim);
            font-size: 0.66rem;
            font-weight: 700;
            letter-spacing: 1.35px;
            text-transform: uppercase;
            line-height: 1.3;
        }
        .invoice-resend-row {
            display: grid;
            gap: 0.55rem;
            grid-template-columns: 1fr auto;
            align-items: end;
        }
        .invoice-resend-row .form-group {
            margin-bottom: 0;
        }
        .invoice-section-title {
            font-size: 0.68rem;
            text-transform: uppercase;
            letter-spacing: 1.5px;
            color: var(--dim);
            font-weight: 700;
            margin: 1rem 0 0.55rem;
        }
        .invoice-form-grid2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 0.65rem;
        }
        .invoice-submit-row {
            margin-top: 1rem;
            display: flex;
            gap: 0.6rem;
            flex-wrap: wrap;
        }
        .invoice-log-wrap {
            margin-top: 1.4rem;
        }

        @media (max-width: 980px) {
            .invoice-grid-top {
                grid-template-columns: 1fr;
            }
            .invoice-toolbar form {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 700px) {
            .invoice-form-grid2,
            .invoice-booking-grid {
                grid-template-columns: 1fr;
            }
            .invoice-resend-row {
                grid-template-columns: 1fr;
            }
            .invoice-submit-row .btn-admin,
            .invoice-submit-row .btn-submit {
                width: 100%;
            }
            .invoice-resend-row .btn-admin {
                width: 100%;
            }
        }
    </style>
</head>
<body class="admin-page">
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>Rechnungen</h1>
        </div>

        <?= render_flash() ?>

        <div class="invoice-grid-top">
            <section class="invoice-card">
                <h2>Rechnungskreise</h2>
                <p class="invoice-card-note">
                    Jede Rechnungsnummer folgt strikt dem Kreis-Muster <strong>WS 2026 / N</strong>. Nummern werden fortlaufend und eindeutig vergeben.
                </p>

                <div class="invoice-circle-list">
                    <?php foreach ($invoiceCircles as $circleRow): ?>
                    <?php $isActiveCircle = (int) $circleRow['id'] === (int) $selectedCircleId; ?>
                    <div class="invoice-circle-item <?= $isActiveCircle ? 'active' : '' ?>">
                        <div class="invoice-circle-top">
                            <span class="invoice-circle-label"><?= e((string) $circleRow['circle_label']) ?></span>
                            <?php if ((int) $circleRow['active'] === 1): ?>
                                <span class="invoice-badge ok">aktiv</span>
                            <?php else: ?>
                                <span class="invoice-badge">inaktiv</span>
                            <?php endif; ?>
                        </div>
                        <div class="invoice-circle-meta">
                            Code: <?= e((string) $circleRow['circle_code']) ?>
                            &nbsp;|&nbsp; Nächste Nummer: <strong><?= (int) $circleRow['next_number'] ?></strong>
                            &nbsp;|&nbsp; Finalisiert: <?= (int) $circleRow['issued_count'] ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div class="invoice-section-title" style="margin-top:0;">Neuen Rechnungskreis anlegen</div>
                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="create_circle_submit" value="1">
                    <div class="invoice-form-grid2">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Prefix</label>
                            <input type="text" name="circle_prefix" value="WS" maxlength="12" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Jahr</label>
                            <input type="number" name="circle_year" value="<?= (int) date('Y') ?>" min="2000" max="2100" required>
                        </div>
                    </div>
                    <div class="form-group" style="margin:0.65rem 0 0;">
                        <label>Startnummer</label>
                        <input type="number" name="circle_next_number" value="1" min="1" required>
                    </div>
                    <div class="invoice-submit-row" style="margin-top:0.75rem;">
                        <button type="submit" class="btn-admin">Kreis anlegen</button>
                    </div>
                </form>
            </section>

            <section class="invoice-card">
                <h2>Counter manuell anpassen</h2>
                <p class="invoice-card-note">
                    Jede manuelle Counter-Änderung verlangt eine Begründung und landet unveränderbar im Audit-Log.
                    Finalisierte Nummern bleiben gesperrt und können nicht wiederverwendet werden.
                </p>

                <form method="POST">
                    <?= csrf_field() ?>
                    <input type="hidden" name="adjust_counter_submit" value="1">
                    <div class="form-group">
                        <label>Rechnungskreis</label>
                        <select name="adjust_circle_id" id="adjustCircleSelect" required>
                            <?php foreach ($invoiceCircles as $circleRow): ?>
                                <option
                                    value="<?= (int) $circleRow['id'] ?>"
                                    data-next="<?= (int) $circleRow['next_number'] ?>"
                                    data-max="<?= (int) $circleRow['max_issued_number'] ?>"
                                    <?= (int) $circleRow['id'] === (int) $selectedCircleId ? 'selected' : '' ?>
                                >
                                    <?= e((string) $circleRow['circle_label']) ?> (nächste: <?= (int) $circleRow['next_number'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="invoice-form-grid2">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Neue nächste Nummer</label>
                            <input type="number" name="adjust_new_next" id="adjustNewNext" min="1" value="<?= (int) ($selectedCircle['next_number'] ?? 1) ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Höchste vergebene Nummer</label>
                            <input type="text" id="adjustMaxIssued" value="<?= (int) ($selectedCircle['max_issued_number'] ?? 0) ?>" readonly>
                        </div>
                    </div>
                    <div class="form-group" style="margin-top:0.65rem;">
                        <label>Begründung (pflicht)</label>
                        <textarea name="adjust_reason" rows="3" required placeholder="Warum wird der Counter angepasst?"></textarea>
                    </div>
                    <div class="invoice-submit-row">
                        <button type="submit" class="btn-admin">Counter speichern</button>
                    </div>
                </form>
            </section>
        </div>

        <section class="invoice-card">
            <h2>Rechnungen erstellen und senden</h2>
            <p class="invoice-card-note">
                Auswahl nach Workshop und Rechnungskreis. Nummern werden beim Versand final reserviert.
            </p>

            <div class="invoice-toolbar">
                <form method="GET">
                    <div class="form-group">
                        <label for="workshopSelect">Workshop</label>
                        <select id="workshopSelect" name="workshop_id">
                            <option value="0" <?= $selectedWorkshopFilterValue === '0' ? 'selected' : '' ?>>Workshop wählen</option>
                            <?php foreach ($workshopFilterOptions as $filterOption): ?>
                                <?php $optionValue = (string) ($filterOption['value'] ?? '0'); ?>
                                <option value="<?= e($optionValue) ?>" <?= $selectedWorkshopFilterValue === $optionValue ? 'selected' : '' ?>>
                                    <?= e((string) ($filterOption['label'] ?? '')) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="circleSelect">Rechnungskreis</label>
                        <select id="circleSelect" name="circle_id">
                            <?php foreach ($invoiceCircles as $circleRow): ?>
                                <option value="<?= (int) $circleRow['id'] ?>" <?= (int) $circleRow['id'] === (int) $selectedCircleId ? 'selected' : '' ?>>
                                    <?= e((string) $circleRow['circle_label']) ?> (nächste: <?= (int) $circleRow['next_number'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <button type="submit" class="btn-admin" style="width:100%;">Laden</button>
                    </div>
                </form>
            </div>

            <?php if (!$selectedWorkshop || !$selectedCircle): ?>
                <p style="color:var(--dim);font-size:0.85rem;">Bitte Workshop und Rechnungskreis wählen.</p>
            <?php else: ?>
                <form method="POST" id="invoiceSendForm">
                    <?= csrf_field() ?>
                    <input type="hidden" name="invoice_send_submit" value="1">
                    <input type="hidden" name="invoice_workshop_id" value="<?= (int) $selectedWorkshopId ?>">
                    <input type="hidden" name="invoice_occurrence_id" value="<?= (int) $selectedOccurrenceId ?>">
                    <input type="hidden" name="invoice_circle_id" value="<?= (int) $selectedCircleId ?>">

                    <div class="invoice-section-title" style="margin-top:0;">Gemeinsame Rechnungsdaten</div>
                    <div class="invoice-form-grid2" style="margin-bottom:0.65rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Rechnungsdatum</label>
                            <input type="date" name="r_rechnung_datum" value="<?= date('Y-m-d') ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Rechnungskreis</label>
                            <input type="text" value="<?= e((string) $selectedCircle['circle_label']) ?>" readonly>
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0.65rem;">
                        <label>Für (Leistungsbeschreibung)</label>
                        <input type="text" name="r_fuer_text" value="die Abhaltung des Workshops" required>
                    </div>
                    <div class="invoice-form-grid2" style="margin-bottom:0.65rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Workshop-Titel</label>
                            <input type="text" name="r_workshop_titel" value="<?= e((string) $selectedWorkshop['title']) ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Veranstaltungsdatum</label>
                            <input type="text" name="r_veranstaltungs_datum" value="<?= e($evtDateDefault) ?>">
                        </div>
                    </div>

                    <div class="invoice-section-title">Positionen (netto)</div>
                    <div class="invoice-form-grid2" style="margin-bottom:0.5rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Position 1</label>
                            <input type="text" name="r_pos1_label" value="<?= e($pos1LabelDefault) ?>" required>
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>EUR (netto)</label>
                            <input type="text" name="r_pos1_betrag" value="<?= e($pos1BetragDefault) ?>" placeholder="1.200,00" required>
                        </div>
                    </div>
                    <div class="invoice-form-grid2" style="margin-bottom:0.5rem;">
                        <div class="form-group" style="margin-bottom:0;">
                            <label>Position 2 (optional)</label>
                            <input type="text" name="r_pos2_label" placeholder="z.B. Reisekosten">
                        </div>
                        <div class="form-group" style="margin-bottom:0;">
                            <label>EUR (netto, optional)</label>
                            <input type="text" name="r_pos2_betrag" placeholder="200,00">
                        </div>
                    </div>

                    <div class="form-group" style="margin-bottom:0.65rem;">
                        <label>Absender (Unterschrift)</label>
                        <input type="text" name="r_absender_name" placeholder="Mag.a Maria Muster">
                    </div>

                    <div class="invoice-section-title">
                        Empfänger (<?= count($confirmedBookingsForInvoices) ?> bestätigt)
                    </div>

                    <?php if (empty($confirmedBookingsForInvoices)): ?>
                        <p style="color:var(--dim);font-size:0.85rem;">Keine bestätigten Buchungen für diesen Workshop.</p>
                    <?php else: ?>
                        <div class="invoice-booking-list" id="invoiceBookingList" data-circle-label="<?= e((string) $selectedCircle['circle_label']) ?>" data-circle-next="<?= (int) $selectedCircle['next_number'] ?>">
                            <?php foreach ($confirmedBookingsForInvoices as $idx => $bookingRow): ?>
                                <?php
                                    $bookingId = (int) ($bookingRow['id'] ?? 0);
                                    $hasInvoice = trim((string) ($bookingRow['invoice_number_display'] ?? '')) !== '';
                                    $invoiceId = (int) ($bookingRow['invoice_id'] ?? 0);
                                    $organization = trim((string) ($bookingRow['organization'] ?? ''));
                                    $name = trim((string) ($bookingRow['name'] ?? ''));
                                    $email = trim((string) ($bookingRow['email'] ?? ''));
                                    $invoiceRecipientEmail = trim((string) ($bookingRow['invoice_recipient_email'] ?? ''));
                                    $emailForDisplay = ($hasInvoice && $invoiceRecipientEmail !== '') ? $invoiceRecipientEmail : $email;
                                    $resendEmailDefault = $invoiceRecipientEmail !== '' ? $invoiceRecipientEmail : $email;
                                    $displayName = $organization !== '' ? $organization : $name;
                                    $status = trim((string) ($bookingRow['send_status'] ?? ''));
                                ?>
                                <div class="invoice-booking-card invoice-row" data-locked="<?= $hasInvoice ? '1' : '0' ?>">
                                    <div class="invoice-booking-head">
                                        <label>
                                            <input
                                                type="checkbox"
                                                class="invoice-row-check"
                                                name="r_booking_selected[<?= (int) $idx ?>]"
                                                value="1"
                                                <?= $hasInvoice ? 'disabled' : 'checked' ?>
                                            >
                                            <span class="invoice-booking-title">
                                                <strong><?= e($displayName) ?></strong>
                                                <?php if ($organization !== '' && $organization !== $name): ?>
                                                    <span class="invoice-booking-sub"> - <?= e($name) ?></span>
                                                <?php endif; ?>
                                                <span class="invoice-booking-sub">&middot; <?= e($emailForDisplay) ?></span>
                                                <?php if (!empty($bookingRow['occurrence_start_at'])): ?>
                                                    <span class="invoice-booking-sub">&middot; Termin: <?= e(format_event_date((string) ($bookingRow['occurrence_start_at'] ?? ''), (string) ($bookingRow['occurrence_end_at'] ?? ''))) ?></span>
                                                <?php elseif ($selectedOccurrenceLabel !== ''): ?>
                                                    <span class="invoice-booking-sub">&middot; Termin: <?= e($selectedOccurrenceLabel) ?></span>
                                                <?php endif; ?>
                                            </span>
                                        </label>

                                        <?php if ($hasInvoice): ?>
                                            <span class="invoice-badge ok">
                                                finalisiert: <?= e((string) $bookingRow['invoice_number_display']) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <div class="invoice-number-preview invoice-row-preview">
                                        <?php if ($hasInvoice): ?>
                                            <?= e((string) $bookingRow['invoice_number_display']) ?>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                        <?php if ($hasInvoice && $status !== ''): ?>
                                            &nbsp;|&nbsp;
                                            <span class="<?= $status === 'sent' ? 'invoice-badge ok' : ($status === 'send_failed' ? 'invoice-badge fail' : 'invoice-badge') ?>">
                                                <?= e($status) ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>

                                    <input type="hidden" name="r_booking_id[<?= (int) $idx ?>]" value="<?= $bookingId ?>">
                                    <?php if ($hasInvoice): ?>
                                        <input type="hidden" name="r_existing_invoice_id[<?= (int) $idx ?>]" value="<?= $invoiceId ?>">
                                    <?php endif; ?>

                                    <div class="invoice-booking-grid">
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Firma / Organisation</label>
                                            <input type="text" name="r_booking_empfaenger[<?= (int) $idx ?>]" value="<?= e($organization) ?>" <?= $hasInvoice ? 'readonly' : '' ?>>
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Adresse</label>
                                            <input type="text" name="r_booking_adresse[<?= (int) $idx ?>]" placeholder="Musterstrasse 12" <?= $hasInvoice ? 'readonly' : '' ?>>
                                        </div>
                                    </div>
                                    <div class="invoice-booking-grid">
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>PLZ / Ort</label>
                                            <input type="text" name="r_booking_plz_ort[<?= (int) $idx ?>]" placeholder="1010 Wien" <?= $hasInvoice ? 'readonly' : '' ?>>
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>Anrede</label>
                                            <select name="r_booking_anrede[<?= (int) $idx ?>]" <?= $hasInvoice ? 'disabled' : '' ?>>
                                                <option value="Herrn">Herrn</option>
                                                <option value="Frau">Frau</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="invoice-booking-grid">
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>z.Hd. Name</label>
                                            <input type="text" name="r_booking_kontakt_name[<?= (int) $idx ?>]" value="<?= e($name) ?>" <?= $hasInvoice ? 'readonly' : '' ?>>
                                        </div>
                                        <div class="form-group" style="margin-bottom:0;">
                                            <label>E-Mail</label>
                                            <input type="email" name="r_booking_kontakt_email[<?= (int) $idx ?>]" value="<?= e($emailForDisplay) ?>" <?= $hasInvoice ? 'readonly' : '' ?>>
                                        </div>
                                    </div>

                                    <?php if ($hasInvoice): ?>
                                    <div class="invoice-resend-wrap">
                                        <div class="invoice-resend-title">Rechnung erneut senden</div>
                                        <div class="invoice-resend-row">
                                            <div class="form-group">
                                                <label>E-Mail für erneuten Versand</label>
                                                <input
                                                    type="email"
                                                    name="r_resend_email[<?= (int) $idx ?>]"
                                                    value="<?= e($resendEmailDefault) ?>"
                                                    required
                                                >
                                            </div>
                                            <button
                                                type="submit"
                                                class="btn-admin"
                                                name="invoice_resend_submit"
                                                value="<?= (int) $idx ?>"
                                                formnovalidate
                                            >
                                                Rechnung erneut senden
                                            </button>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <div class="invoice-submit-row">
                        <button type="submit" class="btn-submit" <?= empty($confirmedBookingsForInvoices) ? 'disabled' : '' ?>>
                            Rechnungen finalisieren und senden
                        </button>
                        <a href="<?= e(admin_url('bookings', ['workshop_id' => $selectedWorkshopFilterValue])) ?>" class="btn-admin">Zu Buchungen</a>
                    </div>
                </form>
            <?php endif; ?>
        </section>

        <section class="invoice-card invoice-log-wrap">
            <h2>Finalisierte Rechnungen</h2>
            <?php if (empty($recentInvoices)): ?>
                <p style="color:var(--dim);font-size:0.85rem;">Noch keine finalisierten Rechnungen vorhanden.</p>
            <?php else: ?>
                <div class="admin-table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Rechnungsnummer</th>
                            <th>Workshop</th>
                            <th>Buchung</th>
                            <th>E-Mail</th>
                            <th>Status</th>
                            <th>Erstellt</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recentInvoices as $row): ?>
                        <tr>
                            <td style="color:var(--text);font-weight:600;"><?= e((string) $row['invoice_number_display']) ?></td>
                            <td>
                                <?= e((string) $row['workshop_title']) ?>
                                <?php if (!empty($row['occurrence_start_at'])): ?>
                                    <div style="font-size:0.72rem;color:var(--dim);"><?= e(format_event_date((string) ($row['occurrence_start_at'] ?? ''), (string) ($row['occurrence_end_at'] ?? ''))) ?></div>
                                <?php endif; ?>
                            </td>
                            <td><?= e((string) $row['booking_name']) ?></td>
                            <td><a href="mailto:<?= e((string) $row['recipient_email']) ?>" style="color:var(--muted);"><?= e((string) $row['recipient_email']) ?></a></td>
                            <td>
                                <?php if ((string) $row['send_status'] === 'sent'): ?>
                                    <span class="status-badge status-confirmed">gesendet</span>
                                <?php elseif ((string) $row['send_status'] === 'send_failed'): ?>
                                    <span class="status-badge status-pending" style="background:rgba(231,76,60,0.14);color:#e74c3c;border-color:rgba(231,76,60,0.45);">fehlgeschlagen</span>
                                <?php else: ?>
                                    <span class="status-badge status-pending"><?= e((string) $row['send_status']) ?></span>
                                <?php endif; ?>
                            </td>
                            <td style="white-space:nowrap;"><?= e(date('d.m.Y H:i', strtotime((string) $row['issued_at']))) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>

        <section class="invoice-card invoice-log-wrap">
            <h2>Counter Audit-Log (immutable)</h2>
            <?php if (empty($auditRows)): ?>
                <p style="color:var(--dim);font-size:0.85rem;">Noch keine Counter-Änderungen protokolliert.</p>
            <?php else: ?>
                <div class="admin-table-scroll">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Zeitpunkt</th>
                            <th>Kreis</th>
                            <th>Von</th>
                            <th>Auf</th>
                            <th>Begründung</th>
                            <th>Durch</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($auditRows as $row): ?>
                        <tr>
                            <td style="white-space:nowrap;"><?= e(date('d.m.Y H:i', strtotime((string) $row['changed_at']))) ?></td>
                            <td><?= e((string) $row['circle_label']) ?></td>
                            <td><?= (int) $row['previous_next_number'] ?></td>
                            <td><?= (int) $row['new_next_number'] ?></td>
                            <td style="color:var(--text);"><?= e((string) $row['reason']) ?></td>
                            <td><?= e((string) $row['changed_by']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</div>

<script>
(function () {
    var adjustCircleSelect = document.getElementById('adjustCircleSelect');
    var adjustNewNext = document.getElementById('adjustNewNext');
    var adjustMaxIssued = document.getElementById('adjustMaxIssued');

    function syncAdjustFields() {
        if (!adjustCircleSelect) {
            return;
        }
        var option = adjustCircleSelect.options[adjustCircleSelect.selectedIndex];
        if (!option) {
            return;
        }
        var nextVal = option.getAttribute('data-next') || '1';
        var maxVal = option.getAttribute('data-max') || '0';
        if (adjustNewNext && (adjustNewNext.value === '' || document.activeElement !== adjustNewNext)) {
            adjustNewNext.value = nextVal;
        }
        if (adjustMaxIssued) {
            adjustMaxIssued.value = maxVal;
        }
    }

    if (adjustCircleSelect) {
        adjustCircleSelect.addEventListener('change', syncAdjustFields);
        syncAdjustFields();
    }

    var bookingList = document.getElementById('invoiceBookingList');
    if (!bookingList) {
        return;
    }

    var circleLabel = bookingList.getAttribute('data-circle-label') || 'WS ' + new Date().getFullYear();
    var circleNext = parseInt(bookingList.getAttribute('data-circle-next') || '1', 10);
    if (!Number.isFinite(circleNext) || circleNext < 1) {
        circleNext = 1;
    }

    function updatePreviewNumbers() {
        var number = circleNext;
        var rows = bookingList.querySelectorAll('.invoice-row');
        rows.forEach(function (row) {
            var locked = row.getAttribute('data-locked') === '1';
            var preview = row.querySelector('.invoice-row-preview');
            if (!preview) {
                return;
            }
            if (locked) {
                return;
            }

            var check = row.querySelector('.invoice-row-check');
            if (check && check.checked && !check.disabled) {
                preview.textContent = circleLabel + ' / ' + number;
                number += 1;
            } else {
                preview.textContent = '-';
            }
        });
    }

    bookingList.querySelectorAll('.invoice-row-check').forEach(function (check) {
        check.addEventListener('change', updatePreviewNumbers);
    });

    updatePreviewNumbers();
})();
</script>

<script src="/assets/site-ui.js"></script>
</body>
</html>
