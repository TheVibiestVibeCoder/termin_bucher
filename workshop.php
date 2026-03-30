<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/email.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) redirect(app_url());

$workshop = get_workshop_by_slug($db, $slug);
if (!$workshop) {
    http_response_code(404);
    redirect(app_url());
}

archive_expired_unconfirmed_bookings($db, 48);

$capacity     = (int) $workshop['capacity'];
$audLabels    = array_filter(array_map('trim', explode(',', $workshop['audience_labels'])));
$isOpen       = ($workshop['workshop_type'] ?? 'auf_anfrage') === 'open';
$price        = (float) ($workshop['price_netto'] ?? 0);
$currency     = $workshop['price_currency'] ?? 'EUR';
$minP         = (int) ($workshop['min_participants'] ?? 0);
$location     = $workshop['location']       ?? '';

$occurrences = [];
$selectedOccurrence = null;
$selectedOccurrenceId = (int) ($_POST['occurrence_id'] ?? $_GET['occurrence'] ?? 0);
$legacyOccurrenceBooked = 0;

if ($isOpen) {
    $occurrences = get_workshop_occurrences($db, (int) $workshop['id'], true);
    if (empty($occurrences) && trim((string) ($workshop['event_date'] ?? '')) !== '') {
        $occurrences[] = [
            'id' => 0,
            'start_at' => (string) ($workshop['event_date'] ?? ''),
            'end_at' => (string) ($workshop['event_date_end'] ?? ''),
            'sort_order' => 0,
            'active' => 1,
        ];
    }

    if (!empty($occurrences)) {
        $bookedByOccurrenceId = [];
        $occBookedStmt = $db->prepare('
            SELECT occurrence_id, COALESCE(SUM(participants), 0) AS booked
            FROM bookings
            WHERE workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0
            GROUP BY occurrence_id
        ');
        $occBookedStmt->bindValue(':wid', (int) $workshop['id'], SQLITE3_INTEGER);
        $occBookedRes = $occBookedStmt->execute();
        while ($occBookedRow = $occBookedRes->fetchArray(SQLITE3_ASSOC)) {
            $bookedCount = (int) ($occBookedRow['booked'] ?? 0);
            $occurrenceKeyRaw = $occBookedRow['occurrence_id'] ?? null;

            if ($occurrenceKeyRaw === null || (int) $occurrenceKeyRaw === 0) {
                $legacyOccurrenceBooked += $bookedCount;
            } else {
                $bookedByOccurrenceId[(int) $occurrenceKeyRaw] = $bookedCount;
            }
        }

        if ($legacyOccurrenceBooked > 0 && !empty($occurrences)) {
            $firstOccurrenceId = (int) ($occurrences[0]['id'] ?? 0);
            if ($firstOccurrenceId > 0) {
                $bookedByOccurrenceId[$firstOccurrenceId] = ($bookedByOccurrenceId[$firstOccurrenceId] ?? 0) + $legacyOccurrenceBooked;
            }
        }

        foreach ($occurrences as $index => $occurrenceRow) {
            $occurrenceId = (int) ($occurrenceRow['id'] ?? 0);
            $occurrenceBooked = $occurrenceId > 0
                ? (int) ($bookedByOccurrenceId[$occurrenceId] ?? 0)
                : $legacyOccurrenceBooked;

            $occurrenceSpotsLeft = $capacity > 0 ? max(0, $capacity - $occurrenceBooked) : null;
            $occurrenceFillPct = ($capacity > 0) ? min(100, round(($occurrenceBooked / $capacity) * 100)) : 0;
            $occurrenceFillClass = ($occurrenceFillPct >= 85) ? 'high' : (($occurrenceFillPct >= 50) ? 'medium' : '');

            $occurrenceRow['booked'] = $occurrenceBooked;
            $occurrenceRow['spots_left'] = $occurrenceSpotsLeft;
            $occurrenceRow['fill_pct'] = $occurrenceFillPct;
            $occurrenceRow['fill_class'] = $occurrenceFillClass;
            $occurrenceRow['is_full'] = ($capacity > 0 && $occurrenceSpotsLeft <= 0);
            $occurrenceRow['formatted_date'] = format_event_date(
                (string) ($occurrenceRow['start_at'] ?? ''),
                (string) ($occurrenceRow['end_at'] ?? '')
            );
            $occurrenceRow['is_guaranteed'] = ($minP > 0 && $occurrenceBooked >= $minP);
            $occurrenceRow['below_min'] = ($minP > 0 && $capacity > 0 && $occurrenceBooked < $minP);
            $occurrenceRow['above_min'] = ($minP > 0 && $capacity > 0 && $occurrenceBooked >= $minP);

            $occurrences[$index] = $occurrenceRow;
        }

        $selectedOccurrence = $occurrences[0];
        if ($selectedOccurrenceId > 0) {
            foreach ($occurrences as $occurrenceRow) {
                if ((int) ($occurrenceRow['id'] ?? 0) === $selectedOccurrenceId) {
                    $selectedOccurrence = $occurrenceRow;
                    break;
                }
            }
        }

        $selectedOccurrenceId = (int) ($selectedOccurrence['id'] ?? 0);
    }
}

if ($selectedOccurrence !== null) {
    $booked = (int) ($selectedOccurrence['booked'] ?? 0);
    $spotsLeft = $selectedOccurrence['spots_left'] ?? null;
    $fillPct = (int) ($selectedOccurrence['fill_pct'] ?? 0);
    $fillClass = (string) ($selectedOccurrence['fill_class'] ?? '');
    $isFull = (bool) ($selectedOccurrence['is_full'] ?? false);
    $eventDate = (string) ($selectedOccurrence['start_at'] ?? '');
    $eventDateEnd = (string) ($selectedOccurrence['end_at'] ?? '');
    $belowMin = (bool) ($selectedOccurrence['below_min'] ?? false);
    $aboveMin = (bool) ($selectedOccurrence['above_min'] ?? false);
} else {
    $booked = count_confirmed_bookings($db, (int) $workshop['id']);
    $spotsLeft = $capacity > 0 ? max(0, $capacity - $booked) : null;
    $fillPct = ($capacity > 0) ? min(100, round(($booked / $capacity) * 100)) : 0;
    $fillClass = ($fillPct >= 85) ? 'high' : (($fillPct >= 50) ? 'medium' : '');
    $isFull = $capacity > 0 && $spotsLeft <= 0;
    $eventDate = $workshop['event_date'] ?? '';
    $eventDateEnd = $workshop['event_date_end'] ?? '';
    $belowMin = ($minP > 0 && $capacity > 0 && $booked < $minP);
    $aboveMin = ($minP > 0 && $capacity > 0 && $booked >= $minP);
}

$minPct = ($minP > 0 && $capacity > 0) ? min(100, round(($minP / $capacity) * 100)) : 0;
$isGuaranteed = ($isOpen && $minP > 0 && $booked >= $minP);
$participantsMax = $capacity > 0 ? max(1, min(20, (int) $spotsLeft)) : 20;

$workshopPageParams = ['slug' => (string) $slug];
if ($selectedOccurrenceId > 0) {
    $workshopPageParams['occurrence'] = $selectedOccurrenceId;
}
$workshopPageUrl = app_url('workshop', $workshopPageParams);

$detailOccurrencePayload = [];
if (!empty($occurrences)) {
    foreach ($occurrences as $occurrenceRow) {
        $occurrenceId = (int) ($occurrenceRow['id'] ?? 0);
        $payloadQuery = ['slug' => (string) $slug];
        if ($occurrenceId > 0) {
            $payloadQuery['occurrence'] = $occurrenceId;
        }

        $detailOccurrencePayload[] = [
            'id' => $occurrenceId,
            'date' => (string) ($occurrenceRow['formatted_date'] ?? ''),
            'start' => (string) ($occurrenceRow['start_at'] ?? ''),
            'end' => (string) ($occurrenceRow['end_at'] ?? ''),
            'booked' => (int) ($occurrenceRow['booked'] ?? 0),
            'spotsLeft' => $occurrenceRow['spots_left'],
            'fillPct' => (int) ($occurrenceRow['fill_pct'] ?? 0),
            'fillClass' => (string) ($occurrenceRow['fill_class'] ?? ''),
            'isFull' => (bool) ($occurrenceRow['is_full'] ?? false),
            'isGuaranteed' => (bool) ($occurrenceRow['is_guaranteed'] ?? false),
            'belowMin' => (bool) ($occurrenceRow['below_min'] ?? false),
            'aboveMin' => (bool) ($occurrenceRow['above_min'] ?? false),
            'url' => app_url('workshop', $payloadQuery),
        ];
    }
}
$selectedOccurrenceIndex = 0;
if (!empty($occurrences)) {
    foreach ($occurrences as $occurrenceIdx => $occurrenceRow) {
        if ((int) ($occurrenceRow['id'] ?? 0) === $selectedOccurrenceId) {
            $selectedOccurrenceIndex = $occurrenceIdx;
            break;
        }
    }
}

$hasMultipleOccurrences = count($occurrences) > 1;
$prevOccurrenceUrl = '';
$nextOccurrenceUrl = '';
if ($hasMultipleOccurrences) {
    $prevIndex = ($selectedOccurrenceIndex - 1 + count($occurrences)) % count($occurrences);
    $nextIndex = ($selectedOccurrenceIndex + 1) % count($occurrences);

    $prevOccurrenceId = (int) ($occurrences[$prevIndex]['id'] ?? 0);
    $nextOccurrenceId = (int) ($occurrences[$nextIndex]['id'] ?? 0);

    $prevQuery = ['slug' => (string) $slug];
    $nextQuery = ['slug' => (string) $slug];
    if ($prevOccurrenceId > 0) {
        $prevQuery['occurrence'] = $prevOccurrenceId;
    }
    if ($nextOccurrenceId > 0) {
        $nextQuery['occurrence'] = $nextOccurrenceId;
    }

    $prevOccurrenceUrl = app_url('workshop', $prevQuery);
    $nextOccurrenceUrl = app_url('workshop', $nextQuery);
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['discount_preview'])) {
    header('Content-Type: application/json; charset=UTF-8');

    if (!csrf_verify()) {
        http_response_code(400);
        echo json_encode([
            'ok' => false,
            'message' => 'Sitzung ungültig. Bitte Seite neu laden.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!rate_limit('discount_preview', 20)) {
        http_response_code(429);
        echo json_encode([
            'ok' => false,
            'message' => 'Zu viele Anfragen. Bitte kurz warten und erneut versuchen.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $previewParticipants = max(1, min(50, (int) ($_POST['participants'] ?? 1)));
    $previewEmail = trim((string) ($_POST['email'] ?? ''));
    $previewCode = normalize_discount_code((string) ($_POST['discount_code'] ?? ''));

    $previewPricing = calculate_booking_totals($price, $previewParticipants);
    $previewDiscount = null;
    $previewOk = true;
    $previewMessage = 'Kein Rabattcode gesetzt.';

    if ($previewCode !== '') {
        $validation = validate_discount_for_booking(
            $db,
            $previewCode,
            (int) $workshop['id'],
            $previewEmail,
            $previewParticipants,
            (float) $previewPricing['subtotal']
        );

        if ($validation['ok'] && is_array($validation['code'])) {
            $previewPricing['discount'] = (float) $validation['discount'];
            $previewPricing['total'] = (float) $validation['total'];
            $previewDiscount = [
                'code' => (string) $validation['code']['code'],
                'type' => (string) $validation['code']['discount_type'],
                'value' => (float) $validation['code']['discount_value'],
                'minParticipants' => (int) $validation['code']['min_participants'],
                'label' => format_discount_value(
                    (string) $validation['code']['discount_type'],
                    (float) $validation['code']['discount_value'],
                    $currency
                ),
            ];
            $previewMessage = 'Rabattcode angewendet.';
        } else {
            $previewOk = false;
            $previewMessage = (string) ($validation['message'] ?? 'Rabattcode ungültig.');
        }
    }

    echo json_encode([
        'ok' => $previewOk,
        'message' => $previewMessage,
        'discount' => $previewDiscount,
        'pricing' => [
            'subtotal' => (float) $previewPricing['subtotal'],
            'discount' => (float) $previewPricing['discount'],
            'total' => (float) $previewPricing['total'],
            'subtotalFormatted' => format_price((float) $previewPricing['subtotal'], $currency),
            'discountFormatted' => format_price((float) $previewPricing['discount'], $currency),
            'totalFormatted' => format_price((float) $previewPricing['total'], $currency),
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$errors = [];
$formData = [
    'name' => '',
    'email' => '',
    'organization' => '',
    'phone' => '',
    'participants' => 1,
    'message' => '',
    'booking_mode' => 'group',
    'discount_code' => '',
];
$participantNames  = [];
$participantEmails = [];
$maxLen = [
    'name'         => 120,
    'email'        => 254,
    'organization' => 180,
    'phone'        => 60,
    'message'      => 3000,
];

$pricingSummary = calculate_booking_totals($price, 1);
$discountFeedback = null;
$discountContext = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book'])) {
    if (!csrf_verify()) {
        $errors[] = 'Ungültige Sitzung. Bitte versuchen Sie es erneut.';
    }
    // Allow a small burst for accidental double-submits/reloads without removing anti-spam protection.
    if (!rate_limit('booking', 12)) {
        $errors[] = 'Zu viele Anfragen. Bitte warten Sie einen Moment.';
    }

    $formData['name']         = trim($_POST['name'] ?? '');
    $formData['email']        = trim($_POST['email'] ?? '');
    $formData['organization'] = trim($_POST['organization'] ?? '');
    $formData['phone']        = trim($_POST['phone'] ?? '');
    $formData['participants'] = max(1, (int) ($_POST['participants'] ?? 1));
    $formData['message']      = trim($_POST['message'] ?? '');
    $formData['booking_mode'] = ($_POST['booking_mode'] ?? 'group') === 'individual' ? 'individual' : 'group';
    $formData['discount_code'] = normalize_discount_code((string) ($_POST['discount_code'] ?? ''));

    $activeOccurrenceId = $selectedOccurrenceId > 0 ? $selectedOccurrenceId : 0;
    if ($isOpen && !empty($occurrences)) {
        $hasOccurrenceMatch = false;
        foreach ($occurrences as $occurrenceRow) {
            if ((int) ($occurrenceRow['id'] ?? 0) === $activeOccurrenceId) {
                $hasOccurrenceMatch = true;
                break;
            }
        }

        if (!$hasOccurrenceMatch) {
            $hasLegacyOccurrence = false;
            foreach ($occurrences as $occurrenceRow) {
                if ((int) ($occurrenceRow['id'] ?? 0) === 0) {
                    $hasLegacyOccurrence = true;
                    break;
                }
            }

            if ($activeOccurrenceId === 0 && $hasLegacyOccurrence) {
                $hasOccurrenceMatch = true;
            }
        }

        if (!$hasOccurrenceMatch) {
            $errors[] = 'Der ausgewaehlte Termin ist nicht mehr verfuegbar.';
        }
    }

    if ($formData['booking_mode'] === 'individual') {
        $participantNames  = array_map('trim', (array)($_POST['participant_name']  ?? []));
        $participantEmails = array_map('trim', (array)($_POST['participant_email'] ?? []));
    }

    if (strlen($formData['name']) < 2) $errors[] = 'Bitte geben Sie Ihren Namen ein.';
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    if ($formData['participants'] < 1 || $formData['participants'] > 50) $errors[] = 'Ungültige Anzahl Teilnehmer:innen.';

    if (mb_strlen($formData['name']) > $maxLen['name']) $errors[] = 'Name ist zu lang.';
    if (mb_strlen($formData['email']) > $maxLen['email']) $errors[] = 'E-Mail-Adresse ist zu lang.';
    if (mb_strlen($formData['organization']) > $maxLen['organization']) $errors[] = 'Organisation ist zu lang.';
    if (mb_strlen($formData['phone']) > $maxLen['phone']) $errors[] = 'Telefonnummer ist zu lang.';
    if (mb_strlen($formData['message']) > $maxLen['message']) $errors[] = 'Nachricht ist zu lang.';

    if ($formData['booking_mode'] === 'individual' && empty($errors)) {
        $expected = $formData['participants'];
        if (count($participantNames) !== $expected || count($participantEmails) !== $expected) {
            $errors[] = 'Bitte füllen Sie die Daten für alle Teilnehmer:innen aus.';
        } else {
            foreach ($participantNames as $i => $pn) {
                if (strlen($pn) < 2) $errors[] = 'Teilnehmer:in ' . ($i+1) . ': Bitte geben Sie einen Namen ein.';
                $participantEmail = $participantEmails[$i] ?? '';
                if (mb_strlen($pn) > $maxLen['name']) $errors[] = 'Teilnehmer:in ' . ($i+1) . ': Name ist zu lang.';
                if (mb_strlen($participantEmail) > $maxLen['email']) $errors[] = 'Teilnehmer:in ' . ($i+1) . ': E-Mail-Adresse ist zu lang.';
                if (!filter_var($participantEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Teilnehmer:in ' . ($i+1) . ': Ungültige E-Mail-Adresse.';
                }
            }
        }
    }

    if ($capacity > 0) {
        $currentBooked = count_confirmed_bookings(
            $db,
            (int) $workshop['id'],
            $activeOccurrenceId > 0 ? $activeOccurrenceId : null
        );
        $currentSpotsLeft = max(0, $capacity - $currentBooked);

        if ($formData['participants'] > $currentSpotsLeft) {
            $errors[] = "Leider sind nur noch {$currentSpotsLeft} Pl�tze verf�gbar.";
        }
    }

    $pricingSummary = calculate_booking_totals($price, (int) $formData['participants']);

    if ($formData['discount_code'] !== '') {
        $discountFeedback = validate_discount_for_booking(
            $db,
            $formData['discount_code'],
            (int) $workshop['id'],
            (string) $formData['email'],
            (int) $formData['participants'],
            (float) $pricingSummary['subtotal']
        );

        if ($discountFeedback['ok'] && is_array($discountFeedback['code'])) {
            $discountContext = $discountFeedback;
            $pricingSummary['discount'] = (float) $discountFeedback['discount'];
            $pricingSummary['total'] = (float) $discountFeedback['total'];
        } else {
            $errors[] = $discountFeedback['message'] ?: 'Rabattcode ist ungültig.';
        }
    }

    if (empty($errors)) {
        $token = generate_token();

        $discountCodeId = null;
        $discountCodeText = '';
        $discountType = '';
        $discountValue = 0.0;
        $discountAmount = 0.0;

        if ($discountContext && is_array($discountContext['code'])) {
            $discountCodeId = (int) $discountContext['code']['id'];
            $discountCodeText = (string) $discountContext['code']['code'];
            $discountType = (string) $discountContext['code']['discount_type'];
            $discountValue = (float) $discountContext['code']['discount_value'];
            $discountAmount = (float) $pricingSummary['discount'];
        }

        $stmt = $db->prepare('
            INSERT INTO bookings (
                workshop_id,
                occurrence_id,
                name,
                email,
                organization,
                phone,
                participants,
                message,
                token,
                booking_mode,
                price_per_person_netto,
                booking_currency,
                discount_code_id,
                discount_code,
                discount_type,
                discount_value,
                discount_amount,
                subtotal_netto,
                total_netto
            )
            VALUES (
                :wid,
                :oid,
                :name,
                :email,
                :org,
                :phone,
                :participants,
                :msg,
                :token,
                :bmode,
                :ppnet,
                :currency,
                :dcid,
                :dcode,
                :dtype,
                :dvalue,
                :damount,
                :subtotal,
                :total
            )
        ');
        $stmt->bindValue(':wid',          $workshop['id'],           SQLITE3_INTEGER);
        if ($activeOccurrenceId > 0) {
            $stmt->bindValue(':oid', $activeOccurrenceId, SQLITE3_INTEGER);
        } else {
            $stmt->bindValue(':oid', null, SQLITE3_NULL);
        }
        $stmt->bindValue(':name',         $formData['name'],         SQLITE3_TEXT);
        $stmt->bindValue(':email',        $formData['email'],        SQLITE3_TEXT);
        $stmt->bindValue(':org',          $formData['organization'], SQLITE3_TEXT);
        $stmt->bindValue(':phone',        $formData['phone'],        SQLITE3_TEXT);
        $stmt->bindValue(':participants', $formData['participants'], SQLITE3_INTEGER);
        $stmt->bindValue(':msg',          $formData['message'],      SQLITE3_TEXT);
        $stmt->bindValue(':token',        $token,                    SQLITE3_TEXT);
        $stmt->bindValue(':bmode',        $formData['booking_mode'], SQLITE3_TEXT);
        $stmt->bindValue(':ppnet',        (float) $price,            SQLITE3_FLOAT);
        $stmt->bindValue(':currency',     $currency,                 SQLITE3_TEXT);
        if ($discountCodeId === null) {
            $stmt->bindValue(':dcid', null, SQLITE3_NULL);
        } else {
            $stmt->bindValue(':dcid', $discountCodeId, SQLITE3_INTEGER);
        }
        $stmt->bindValue(':dcode',        $discountCodeText,         SQLITE3_TEXT);
        $stmt->bindValue(':dtype',        $discountType,             SQLITE3_TEXT);
        $stmt->bindValue(':dvalue',       $discountValue,            SQLITE3_FLOAT);
        $stmt->bindValue(':damount',      $discountAmount,           SQLITE3_FLOAT);
        $stmt->bindValue(':subtotal',     (float) $pricingSummary['subtotal'], SQLITE3_FLOAT);
        $stmt->bindValue(':total',        (float) $pricingSummary['total'],    SQLITE3_FLOAT);
        $insertResult = $stmt->execute();
        if ($insertResult === false) {
            $errors[] = 'Technischer Fehler beim Speichern. Bitte versuchen Sie es erneut.';
        } else {
            $bookingId = $db->lastInsertRowID();

            $bookingParticipantsForEmail = [];

            // Save individual participant details if in individual mode
            if ($formData['booking_mode'] === 'individual') {
                foreach ($participantNames as $i => $pName) {
                    $participantEmail = $participantEmails[$i] ?? '';
                    $pstmt = $db->prepare('INSERT INTO booking_participants (booking_id, name, email) VALUES (:bid, :name, :email)');
                    $pstmt->bindValue(':bid',   $bookingId,        SQLITE3_INTEGER);
                    $pstmt->bindValue(':name',  $pName,            SQLITE3_TEXT);
                    $pstmt->bindValue(':email', $participantEmail, SQLITE3_TEXT);
                    $pstmt->execute();

                    $bookingParticipantsForEmail[] = [
                        'name'  => $pName,
                        'email' => $participantEmail,
                    ];
                }
            }

            $bookingForEmail = $formData;
            if ($activeOccurrenceId > 0) {
                $bookingForEmail['occurrence_id'] = $activeOccurrenceId;
            }
            $bookingForEmail['price_per_person_netto'] = (float) $price;
            $bookingForEmail['booking_currency'] = $currency;
            $bookingForEmail['discount_code'] = $discountCodeText;
            $bookingForEmail['discount_type'] = $discountType;
            $bookingForEmail['discount_value'] = $discountValue;
            $bookingForEmail['discount_amount'] = $discountAmount;
            $bookingForEmail['subtotal_netto'] = (float) $pricingSummary['subtotal'];
            $bookingForEmail['total_netto'] = (float) $pricingSummary['total'];

            $workshopForEmail = $workshop;
            if ($isOpen) {
                $occurrenceForEmail = null;
                foreach ($occurrences as $occurrenceRow) {
                    $rowOccurrenceId = (int) ($occurrenceRow['id'] ?? 0);
                    if ($rowOccurrenceId === $activeOccurrenceId) {
                        $occurrenceForEmail = $occurrenceRow;
                        break;
                    }
                    if ($activeOccurrenceId === 0 && $rowOccurrenceId === 0) {
                        $occurrenceForEmail = $occurrenceRow;
                    }
                }

                if ($occurrenceForEmail === null && $selectedOccurrence !== null) {
                    $occurrenceForEmail = $selectedOccurrence;
                }

                if (is_array($occurrenceForEmail)) {
                    $workshopForEmail['event_date'] = (string) ($occurrenceForEmail['start_at'] ?? '');
                    $workshopForEmail['event_date_end'] = (string) ($occurrenceForEmail['end_at'] ?? '');
                }
            }

            if (!send_confirmation_email(
                $formData['email'],
                $formData['name'],
                $workshop['title'],
                $token,
                $bookingForEmail,
                $workshopForEmail,
                $bookingParticipantsForEmail
            )) {
                $rollbackStmt = $db->prepare('DELETE FROM bookings WHERE id = :id');
                $rollbackStmt->bindValue(':id', $bookingId, SQLITE3_INTEGER);
                $rollbackStmt->execute();
                $errors[] = 'Die Bestätigungs-E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es erneut.';
            } else {
                flash('success', 'Vielen Dank! Wir haben Ihnen eine Bestätigungs-E-Mail gesendet. Bitte klicken Sie auf den Link in der E-Mail, um Ihre Buchung abzuschließen.');
                redirect($workshopPageUrl);
            }
        }
    }
}

$discountHintText = 'Code wird beim Absenden final geprüft.';
$discountHintClass = 'discount-code-hint';
if ($formData['discount_code'] !== '' && is_array($discountFeedback)) {
    if ($discountFeedback['ok'] && is_array($discountFeedback['code'])) {
        $discountHintText = 'Code aktiv: '
            . (string) $discountFeedback['code']['code']
            . ' ('
            . format_discount_value(
                (string) $discountFeedback['code']['discount_type'],
                (float) $discountFeedback['code']['discount_value'],
                $currency
            )
            . ')';
        $discountHintClass = 'discount-code-hint discount-code-hint-ok';
    } else {
        $discountHintText = (string) ($discountFeedback['message'] ?? 'Rabattcode ungültig.');
        $discountHintClass = 'discount-code-hint discount-code-hint-error';
    }
}

$detailMetaItems = [];
$mapsDirectionsUrl = '';
if ($location !== '') {
    $mapsDirectionsUrl = 'https://www.google.com/maps/dir/?api=1&destination=' . rawurlencode($location) . '&travelmode=transit';
}
if ($isOpen && $eventDate) {
    $detailMetaItems[] = [
        'label' => 'Datum & Uhrzeit',
        'value' => format_event_date($eventDate, $eventDateEnd),
        'field' => 'date',
    ];
}
if ($isOpen && $location) {
    $detailMetaItems[] = [
        'label' => 'Veranstaltungsort',
        'value' => (string) $location,
        'href' => $mapsDirectionsUrl,
    ];
}
$detailMetaItems[] = [
    'label' => !$isOpen ? 'Termin' : 'Terminart',
    'value' => !$isOpen ? 'Auf Anfrage' : 'Fester Termin',
];
if ($isOpen && $minP > 0) {
    $detailMetaItems[] = [
        'label' => 'Status',
        'value' => $isGuaranteed ? 'Findet statt' : 'Mindestanzahl offen',
        'field' => 'status',
    ];
}
$detailMetaItems[] = [
    'label' => 'Format',
    'value' => (string) $workshop['format'],
];
$detailMetaItems[] = [
    'label' => 'Dauer',
    'value' => (string) $workshop['tag_label'],
];
if ($capacity > 0) {
    $detailMetaItems[] = [
        'label' => 'Kapazität',
        'value' => $capacity . ' Plätze',
    ];
    $detailMetaItems[] = [
        'label' => 'Verfügbar',
        'value' => $isFull ? 'Ausgebucht' : ($spotsLeft . ' frei'),
        'field' => 'availability',
    ];
}
if ($minP > 0) {
    $detailMetaItems[] = [
        'label' => 'Mindest-Teilnehmende',
        'value' => $minP . ' Personen',
    ];
}
$primaryMetaItems = array_slice($detailMetaItems, 0, 3);
$extraMetaItems = array_slice($detailMetaItems, 3);
$hasMoreMetaItems = !empty($extraMetaItems);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($workshop['title']) ?> – <?= e(SITE_NAME) ?></title>
    <meta name="description" content="<?= e(mb_substr(($workshop['description_short'] ?? '') ?: $workshop['description'], 0, 160)) ?>">
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
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<a href="#main-content" class="skip-link">Direkt zum Inhalt</a>

<nav role="navigation" aria-label="Hauptnavigation">
    <div class="nav-inner">
        <a href="https://workshops.disinfoconsulting.eu/" class="nav-logo" aria-label="Disinfo Consulting Workshops – Startseite">
            <img src="https://disinfoconsulting.eu/wp-content/uploads/2026/02/Gemini_Generated_Image_vjal0gvjal0gvjal-scaled.png"
                 alt="Disinfo Consulting" height="30">
        </a>
        <button class="nav-burger" aria-label="Navigation öffnen" aria-expanded="false" id="burger">
            <span></span><span></span><span></span>
        </button>
        <ul class="nav-links" id="nav-links" role="list">
            <li><button type="button" class="theme-toggle" id="themeToggle" aria-pressed="false">&#9790;</button></li>
            <li><a href="<?= e(app_url('kontakt')) ?>" class="nav-cta">Kontakt</a></li>
        </ul>
    </div>
</nav>

<main id="main-content">
<section class="detail-hero">
    <div class="hero-noise"></div>
    <div class="hero-spotlight"></div>
    <div class="container" style="position:relative;z-index:2;">

        <a href="<?= e(app_url()) ?>" class="detail-back">&larr; Alle Workshops</a>

        <?= render_flash() ?>

        <div class="detail-grid">
            <!-- Left: Info -->
            <div class="detail-info">
                <!-- Type badge + format tag -->
                <div class="badge-row badge-row-detail">
                    <?php if ($isOpen): ?>
                        <?php if ($minP > 0): ?>
                            <?php
                                $statusBadgeClass = $isGuaranteed ? 'type-badge-confirmed' : 'type-badge-open-pending';
                                $statusBadgeLabel = $isGuaranteed ? 'Findet statt' : 'Mindestanzahl offen';
                            ?>
                            <span class="type-badge <?= e($statusBadgeClass) ?>" data-detail-status-badge>
                                <span class="badge-dot"></span>
                                <span data-detail-status-badge-text><?= e($statusBadgeLabel) ?></span>
                            </span>
                        <?php else: ?>
                            <span class="type-badge type-badge-open"><span class="badge-dot"></span>Anmeldung offen</span>
                        <?php endif; ?>
                    <?php else: ?>
                        <span class="type-badge type-badge-anfrage"><span class="badge-dot"></span>Auf Anfrage</span>
                    <?php endif; ?>
                    <div class="detail-tag"><span class="card-tag-dot"></span> <?= e($workshop['tag_label']) ?></div>
                    <?php if ($workshop['featured']): ?>
                        <span style="display:inline-block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#000;background:#fff;padding:4px 10px;border-radius:4px;">Empfohlen</span>
                    <?php endif; ?>
                </div>

                <h1><?= e($workshop['title']) ?></h1>

                <?php if ($isOpen && $hasMultipleOccurrences): ?>
                <div class="detail-occurrence-slider"
                     data-detail-occurrence-slider
                     data-occurrence-payload="<?= e(json_for_html($detailOccurrencePayload)) ?>"
                     data-occurrence-index="<?= (int) $selectedOccurrenceIndex ?>"
                     data-min-participants="<?= (int) $minP ?>"
                     data-capacity="<?= (int) $capacity ?>"
                     aria-label="Workshop-Termine">
                    <a href="<?= e($prevOccurrenceUrl) ?>" class="detail-occurrence-nav detail-occurrence-nav-prev" data-detail-occurrence-prev aria-label="Vorheriger Termin">
                        &#10094;
                    </a>
                    <div class="detail-occurrence-stack">
                        <?php foreach ($occurrences as $occurrenceIdx => $occurrenceRow):
                            $cardClass = ($occurrenceIdx === $selectedOccurrenceIndex) ? 'is-active' : 'is-hidden';
                        ?>
                        <article class="detail-occurrence-card <?= e($cardClass) ?>" data-detail-occurrence-card data-occurrence-id="<?= (int) ($occurrenceRow['id'] ?? 0) ?>">
                            <div class="detail-occurrence-kicker">Termin <?= ($occurrenceIdx + 1) ?> von <?= count($occurrences) ?></div>
                            <div class="detail-occurrence-date"><?= e((string) ($occurrenceRow['formatted_date'] ?? '')) ?></div>
                            <div class="detail-occurrence-meta">
                                <?php if ($capacity > 0): ?>
                                    <?= (int) ($occurrenceRow['booked'] ?? 0) ?> / <?= $capacity ?> gebucht
                                <?php else: ?>
                                    Offen fuer Buchung
                                <?php endif; ?>
                            </div>
                        </article>
                        <?php endforeach; ?>
                    </div>
                    <a href="<?= e($nextOccurrenceUrl) ?>" class="detail-occurrence-nav detail-occurrence-nav-next" data-detail-occurrence-next aria-label="Naechster Termin">
                        &#10095;
                    </a>
                </div>
                <?php endif; ?>
                <div class="detail-desc-wrap" id="detailDescWrap">
                    <div class="detail-desc-content">
                        <p class="detail-desc"><?= nl2br(e($workshop['description'])) ?></p>
                    </div>
                    <button class="detail-desc-toggle" id="detailDescToggle" aria-expanded="false">
                        Vollständig lesen <span class="toggle-arrow">&#8595;</span>
                    </button>
                </div>

                <!-- Price banner -->
                <?php if ($price > 0): ?>
                <div class="price-banner">
                    <div>
                        <div class="price-banner-amount"><?= e(format_price($price, $currency)) ?></div>
                        <div class="price-banner-label">pro Person &middot; Netto-Preis zzgl. MwSt.</div>
                    </div>
                </div>
                <?php else: ?>
                <div style="margin-bottom:2rem;padding:1rem 1.25rem;background:rgba(255,255,255,0.03);border:1px solid var(--border);border-radius:var(--radius);color:var(--muted);font-size:0.9rem;">
                    Preis auf Anfrage
                </div>
                <?php endif; ?>

                <div class="detail-meta-grid detail-meta-grid-main">
                    <?php foreach ($primaryMetaItems as $metaItem): ?>
                    <?php
                        $metaHref = trim((string) ($metaItem['href'] ?? ''));
                        $metaField = trim((string) ($metaItem['field'] ?? ''));
                    ?>
                    <div class="detail-meta-item<?= $metaHref !== '' ? ' detail-meta-item-link' : '' ?>">
                        <?php if ($metaHref !== ''): ?>
                        <a href="<?= e($metaHref) ?>" class="detail-meta-link detail-location-link" aria-label="Route mit Öffis zu dieser Adresse in Google Maps öffnen">
                            <span class="label"><?= e($metaItem['label']) ?></span>
                            <span class="value"<?= $metaField !== '' ? ' data-detail-field="' . e($metaField) . '"' : '' ?>><?= e($metaItem['value']) ?></span>
                        </a>
                        <?php else: ?>
                        <div class="label"><?= e($metaItem['label']) ?></div>
                        <div class="value"<?= $metaField !== '' ? ' data-detail-field="' . e($metaField) . '"' : '' ?>><?= e($metaItem['value']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($hasMoreMetaItems): ?>
                    <button
                        type="button"
                        id="detailMoreToggle"
                        class="detail-meta-item detail-more-toggle"
                        aria-expanded="false"
                        aria-haspopup="dialog"
                        aria-controls="detailMorePanel">
                        <span class="detail-more-kicker">Mehr anzeigen</span>
                        <span class="detail-more-title">Mehr Details</span>
                        <span class="detail-more-hint">Klicken oder tippen</span>
                    </button>
                    <?php endif; ?>
                </div>
                <?php if ($hasMoreMetaItems): ?>
                <div class="detail-more-layer" id="detailMoreLayer" hidden>
                    <div class="detail-more-backdrop" id="detailMoreBackdrop"></div>
                    <section
                        class="detail-more-panel"
                        id="detailMorePanel"
                        role="dialog"
                        aria-modal="true"
                        aria-label="Weitere Workshop-Details"
                        tabindex="-1">
                        <div class="detail-more-header">
                            <h2 class="detail-more-heading">Weitere Details</h2>
                            <button
                                type="button"
                                id="detailMoreClose"
                                class="detail-more-close"
                                aria-label="Mehr Details schließen">
                                &times;
                            </button>
                        </div>
                        <div class="detail-meta-grid detail-meta-grid-overlay">
                            <?php foreach ($extraMetaItems as $metaItem): ?>
                            <?php
                                $metaHref = trim((string) ($metaItem['href'] ?? ''));
                                $metaField = trim((string) ($metaItem['field'] ?? ''));
                            ?>
                            <div class="detail-meta-item<?= $metaHref !== '' ? ' detail-meta-item-link' : '' ?>">
                                <?php if ($metaHref !== ''): ?>
                                <a href="<?= e($metaHref) ?>" class="detail-meta-link detail-location-link" aria-label="Route mit Öffis zu dieser Adresse in Google Maps öffnen">
                                    <span class="label"><?= e($metaItem['label']) ?></span>
                                    <span class="value"<?= $metaField !== '' ? ' data-detail-field="' . e($metaField) . '"' : '' ?>><?= e($metaItem['value']) ?></span>
                                </a>
                                <?php else: ?>
                                <div class="label"><?= e($metaItem['label']) ?></div>
                                <div class="value"<?= $metaField !== '' ? ' data-detail-field="' . e($metaField) . '"' : '' ?>><?= e($metaItem['value']) ?></div>
                                <?php endif; ?>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </section>
                </div>
                <?php endif; ?>

                <?php if ($minP > 0): ?>
                <div class="min-participants-note js-detail-min-note" style="margin-bottom:1.5rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    <span class="js-detail-min-note-text"><?= $isGuaranteed
                        ? 'Mindestanzahl erreicht: Dieser Workshop findet statt.'
                        : ('Dieser Workshop findet statt, sobald mindestens ' . $minP . ' Teilnehmer:innen gebucht sind.') ?></span>
                </div>
                <?php endif; ?>

                <?php if ($capacity > 0): ?>
                <div class="seats-indicator js-detail-seats <?= $belowMin ? 'below-min' : ($aboveMin ? 'above-min' : '') ?>"
                     style="max-width:400px;margin-bottom:2rem;"
                     <?= ($minP > 0) ? 'title="Mindest-Teilnehmende: ' . $minP . '"' : '' ?>>
                    <div class="seats-bar">
                        <div class="seats-bar-track">
                            <div class="seats-bar-fill js-detail-seats-fill <?= $fillClass ?>" style="width:<?= $fillPct ?>%"></div>
                        </div>
                        <?php if ($minP > 0 && $capacity > 0): ?>
                        <div class="seats-bar-marker" style="left:<?= $minPct ?>%">
                            <span class="seats-bar-marker-label">min <?= $minP ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <span class="seats-text js-detail-seats-text"><?= $booked ?> / <?= $capacity ?> gebucht</span>
                </div>
                <?php endif; ?>

                <p class="card-audience" style="margin-bottom:0.5rem;">Zielgruppen</p>
                <div class="card-audience-tags" style="margin-bottom:0;">
                    <?php foreach ($audLabels as $al): ?>
                        <span class="aud-tag"><?= e($al) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right: Booking form -->
            <div class="booking-box">
                <?php if ($isFull): ?>
                    <h3>Ausgebucht</h3>
                    <p style="color:var(--muted);line-height:1.7;">Dieser Workshop ist leider voll ausgebucht. Kontaktieren Sie uns für Alternativtermine.</p>
                    <a href="<?= e(app_url('kontakt')) ?>" class="btn-submit" style="margin-top:1.5rem;display:block;text-align:center;text-decoration:none;">Kontakt aufnehmen</a>
                <?php else: ?>
                    <h3>Platz buchen</h3>

                    <?php if ($price > 0): ?>
                    <div style="background:var(--panel-bg);border:1px solid var(--panel-border);border-radius:var(--radius);padding:0.875rem 1rem;margin-bottom:1.5rem;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--dim);margin-bottom:0.25rem;">Preis pro Person</div>
                        <div style="font-size:1.1rem;font-weight:600;color:var(--text);"><?= e(format_price($price, $currency)) ?> <span style="font-size:0.75rem;font-weight:400;color:var(--muted);">netto zzgl. MwSt.</span></div>
                    </div>
                    <?php endif; ?>

                    <p style="color:var(--muted);font-size:0.85rem;line-height:1.6;margin-bottom:1.5rem;">
                        Füllen Sie das Formular aus. Sie erhalten eine E-Mail zur Bestätigung Ihrer Buchung.
                    </p>

                    <?php if ($errors): ?>
                        <div class="flash flash-error">
                            <?php foreach ($errors as $err): ?>
                                <div><?= e($err) ?></div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" action="<?= e($workshopPageUrl) ?>" id="bookingForm">
                        <input type="hidden" name="occurrence_id" id="occurrence_id" value="<?= (int) $selectedOccurrenceId ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="book" value="1">

                        <div class="flash flash-error" id="detailBookingUnavailable" hidden style="margin-bottom:0.9rem;">Dieser Termin ist aktuell ausgebucht. Bitte waehlen Sie einen anderen Termin.</div>

                        <div class="form-group">
                            <label for="name">Name *</label>
                            <input type="text" id="name" name="name" required placeholder="Ihr vollständiger Name"
                                   value="<?= e($formData['name']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="email">E-Mail *</label>
                            <input type="email" id="email" name="email" required placeholder="ihre@email.de"
                                   value="<?= e($formData['email']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="organization">Organisation</label>
                            <input type="text" id="organization" name="organization" placeholder="Firma / Organisation"
                                   value="<?= e($formData['organization']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="phone">Telefon</label>
                            <input type="tel" id="phone" name="phone" placeholder="+49 ..."
                                   value="<?= e($formData['phone']) ?>">
                        </div>
                        <div class="form-group">
                            <label for="participants">Anzahl Teilnehmer:innen</label>
                            <select id="participants" name="participants">
                                <?php for ($i = 1; $i <= $participantsMax; $i++): ?>
                                    <option value="<?= $i ?>" <?= $formData['participants'] == $i ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <?php if ($price > 0): ?>
                        <div class="form-group">
                            <label for="discount_code">Rabattcode (optional)</label>
                            <input type="text" id="discount_code" name="discount_code" placeholder="Code eingeben"
                                   value="<?= e($formData['discount_code']) ?>" autocomplete="off">
                            <span id="discount-feedback" class="<?= e($discountHintClass) ?>"><?= e($discountHintText) ?></span>
                        </div>
                        <?php endif; ?>
                        <!-- Booking mode toggle -->
                        <div class="form-group">
                            <label>Buchungsart</label>
                            <div class="booking-mode-toggle">
                                <input type="radio" name="booking_mode" id="mode_group" value="group"
                                       <?= $formData['booking_mode'] !== 'individual' ? 'checked' : '' ?>>
                                <label for="mode_group">Alle zusammen buchen</label>
                                <input type="radio" name="booking_mode" id="mode_individual" value="individual"
                                       <?= $formData['booking_mode'] === 'individual' ? 'checked' : '' ?>>
                                <label for="mode_individual">Einzeln buchen</label>
                            </div>
                            <span style="font-size:0.75rem;color:var(--dim);display:block;margin-top:0.35rem;">
                                „Einzeln buchen" – Sie können Namen und E-Mail jeder Person angeben. Jede Person erhält eine Bestätigung.
                            </span>
                        </div>

                        <!-- Individual participant fields (shown by JS) -->
                        <div id="participant-fields-wrap" style="display:none;">
                            <div class="participant-fields-wrap" id="participant-fields-inner">
                                <!-- dynamically populated by JS -->
                            </div>
                        </div>

                        <?php if ($formData['booking_mode'] === 'individual' && !empty($participantNames)): ?>
                        <script>window.__prefillParticipants = <?= json_for_html(array_map(null, $participantNames, $participantEmails)) ?>;</script>
                        <?php endif; ?>

                        <div class="form-group">
                            <label for="message">Nachricht (optional)</label>
                            <textarea id="message" name="message" placeholder="Besondere Anforderungen, Fragen..."><?= e($formData['message']) ?></textarea>
                        </div>

                        <?php if ($price > 0): ?>
                        <div id="price-summary" class="price-summary-box">
                            <div class="price-summary-row">
                                <span>Zwischensumme (Netto):</span>
                                <strong id="price-subtotal"><?= e(format_price((float) $pricingSummary['subtotal'], $currency)) ?></strong>
                            </div>
                            <div class="price-summary-row" id="price-discount-row" <?= (float) $pricingSummary['discount'] > 0 ? '' : 'style="display:none;"' ?>>
                                <span id="price-discount-label">
                                    Rabatt<?= ($discountContext && is_array($discountContext['code'])) ? ' (' . e($discountContext['code']['code']) . ')' : '' ?>
                                </span>
                                <strong id="price-discount" style="color:#2ecc71;">-<?= e(format_price((float) $pricingSummary['discount'], $currency)) ?></strong>
                            </div>
                            <div class="price-summary-row price-summary-row-total">
                                <span>Gesamtpreis (Netto):</span>
                                <strong id="price-total"><?= e(format_price((float) $pricingSummary['total'], $currency)) ?></strong>
                            </div>
                            <div style="font-size:0.74rem;color:var(--dim);margin-top:0.4rem;">zzgl. MwSt.</div>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="btn-submit" id="bookingSubmitBtn">Buchung anfragen &rarr;</button>

                        <p class="form-disclaimer">
                            Mit dem Absenden erklären Sie sich mit unserer
                            <a href="<?= e(app_url('datenschutz')) ?>">Datenschutzerklärung</a> einverstanden.
                            Sie erhalten eine Bestätigungs-E-Mail – erst danach ist Ihr Platz reserviert.
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
</main>

<footer>
    <p>&copy; <?= date('Y') ?> Disinfo Combat GmbH &nbsp;&middot;&nbsp;
       <a href="<?= e(app_url('impressum')) ?>">Impressum</a> &nbsp;&middot;&nbsp;
       <a href="<?= e(app_url('datenschutz')) ?>">Datenschutz</a>
    </p>
</footer>

<script>
const burger = document.getElementById('burger');
const navLinks = document.getElementById('nav-links');
if (burger && navLinks) {
    burger.addEventListener('click', () => {
        const open = navLinks.classList.toggle('open');
        burger.setAttribute('aria-expanded', open);
    });
}

<?php if ($price > 0): ?>
// Live price calculation + discount preview
const pricePerPerson = <?= json_for_html((float) $price) ?>;
const participantsSelect = document.getElementById('participants');
const subtotalEl = document.getElementById('price-subtotal');
const discountRowEl = document.getElementById('price-discount-row');
const discountEl = document.getElementById('price-discount');
const discountLabelEl = document.getElementById('price-discount-label');
const totalEl = document.getElementById('price-total');
const discountInput = document.getElementById('discount_code');
const discountFeedbackEl = document.getElementById('discount-feedback');
const emailInput = document.getElementById('email');
const csrfTokenInput = document.querySelector('form input[name="_token"]');
const discountPreviewUrl = <?= json_for_html($workshopPageUrl) ?>;
const currency = <?= json_for_html($currency) ?>;
const defaultDiscountHint = 'Code wird beim Absenden final geprüft.';
const symbols = { EUR: 'EUR', CHF: 'CHF', USD: 'USD' };

let activeDiscount = <?= json_for_html(
    ($discountContext && is_array($discountContext['code']))
        ? [
            'code' => (string) $discountContext['code']['code'],
            'type' => (string) $discountContext['code']['discount_type'],
            'value' => (float) $discountContext['code']['discount_value'],
            'minParticipants' => (int) $discountContext['code']['min_participants'],
        ]
        : null
) ?>;
let discountPreviewRequestId = 0;

function round2(amount) {
    return Math.round(amount * 100) / 100;
}

function formatPrice(amount) {
    const formatted = amount.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sym = symbols[currency] || currency;
    return currency === 'USD' ? sym + ' ' + formatted : formatted + ' ' + sym;
}

function normalizeCode(value) {
    return String(value || '').trim().toUpperCase().replace(/\s+/g, '');
}

function calcDiscount(subtotal, participants) {
    if (!activeDiscount) {
        return 0;
    }
    if ((activeDiscount.minParticipants || 0) > participants) {
        return 0;
    }
    if (activeDiscount.type === 'percent') {
        return Math.min(subtotal, round2(subtotal * ((activeDiscount.value || 0) / 100)));
    }
    if (activeDiscount.type === 'fixed') {
        return Math.min(subtotal, round2(activeDiscount.value || 0));
    }
    return 0;
}

function setDiscountFeedback(message, state) {
    if (!discountFeedbackEl) {
        return;
    }

    discountFeedbackEl.textContent = (message && String(message).trim() !== '')
        ? String(message)
        : defaultDiscountHint;

    discountFeedbackEl.className = 'discount-code-hint';
    if (state === 'ok') {
        discountFeedbackEl.classList.add('discount-code-hint-ok');
    } else if (state === 'error') {
        discountFeedbackEl.classList.add('discount-code-hint-error');
    }
}

function updatePriceSummary(serverPricing) {
    if (!participantsSelect || !subtotalEl || !totalEl || !discountRowEl || !discountEl || !discountLabelEl) {
        return;
    }

    const participants = parseInt(participantsSelect.value, 10) || 1;
    let subtotal = round2(pricePerPerson * participants);
    let discount = calcDiscount(subtotal, participants);
    let total = round2(subtotal - discount);

    if (serverPricing && typeof serverPricing === 'object') {
        const serverSubtotal = Number(serverPricing.subtotal);
        const serverDiscount = Number(serverPricing.discount);
        const serverTotal = Number(serverPricing.total);

        if (Number.isFinite(serverSubtotal)) {
            subtotal = round2(serverSubtotal);
        }
        if (Number.isFinite(serverDiscount)) {
            discount = Math.max(0, round2(serverDiscount));
        }
        if (Number.isFinite(serverTotal)) {
            total = round2(serverTotal);
        } else {
            total = round2(subtotal - discount);
        }
    }

    subtotalEl.textContent = formatPrice(subtotal);
    totalEl.textContent = formatPrice(total);

    if (discount > 0) {
        discountRowEl.style.display = '';
        discountEl.textContent = '-' + formatPrice(discount);
        discountLabelEl.textContent = activeDiscount && activeDiscount.code
            ? 'Rabatt (' + activeDiscount.code + ')'
            : 'Rabatt';
    } else {
        discountRowEl.style.display = 'none';
    }
}

async function previewDiscountCode() {
    if (!discountInput || !participantsSelect || !csrfTokenInput) {
        return;
    }

    const normalizedCode = normalizeCode(discountInput.value);
    if (normalizedCode === '') {
        activeDiscount = null;
        setDiscountFeedback(defaultDiscountHint, 'neutral');
        updatePriceSummary();
        return;
    }

    const requestId = ++discountPreviewRequestId;

    const payload = new URLSearchParams();
    payload.set('discount_preview', '1');
    payload.set('_token', csrfTokenInput.value || '');
    payload.set('discount_code', normalizedCode);
    payload.set('email', emailInput ? String(emailInput.value || '').trim() : '');
    payload.set('participants', String(parseInt(participantsSelect.value, 10) || 1));

    setDiscountFeedback('Rabattcode wird geprüft ...', 'neutral');

    try {
        const response = await fetch(discountPreviewUrl, {
            method: 'POST',
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
            },
            body: payload.toString(),
        });

        let data = null;
        try {
            data = await response.json();
        } catch (jsonError) {
            data = null;
        }

        if (requestId !== discountPreviewRequestId) {
            return;
        }

        if (!response.ok || !data || typeof data !== 'object') {
            throw new Error('invalid-response');
        }

        if (data.ok && data.discount && typeof data.discount === 'object') {
            activeDiscount = {
                code: String(data.discount.code || ''),
                type: String(data.discount.type || ''),
                value: Number(data.discount.value || 0),
                minParticipants: Number(data.discount.minParticipants || 0),
            };
            setDiscountFeedback(
                'Code aktiv: ' + activeDiscount.code + ' (' + String(data.discount.label || '') + ')',
                'ok'
            );
        } else {
            activeDiscount = null;
            setDiscountFeedback(
                data.message ? String(data.message) : 'Rabattcode ungültig.',
                'error'
            );
        }

        updatePriceSummary(data.pricing && typeof data.pricing === 'object' ? data.pricing : null);
    } catch (error) {
        if (requestId !== discountPreviewRequestId) {
            return;
        }

        activeDiscount = null;
        setDiscountFeedback('Rabattcode konnte nicht geprüft werden. Bitte erneut versuchen.', 'error');
        updatePriceSummary();
    }
}

if (participantsSelect) {
    participantsSelect.addEventListener('change', function () {
        updatePriceSummary();
        if (discountInput && normalizeCode(discountInput.value) !== '') {
            previewDiscountCode();
        }
    });
}

if (discountInput) {
    discountInput.addEventListener('blur', previewDiscountCode);

    discountInput.addEventListener('input', function () {
        const normalizedCode = normalizeCode(discountInput.value);

        if (normalizedCode === '') {
            activeDiscount = null;
            setDiscountFeedback(defaultDiscountHint, 'neutral');
            updatePriceSummary();
            return;
        }

        if (activeDiscount && normalizeCode(activeDiscount.code) !== normalizedCode) {
            activeDiscount = null;
            setDiscountFeedback('Rabattcode wird nach Verlassen des Feldes geprüft.', 'neutral');
            updatePriceSummary();
        }
    });
}

if (emailInput) {
    emailInput.addEventListener('blur', function () {
        if (discountInput && normalizeCode(discountInput.value) !== '') {
            previewDiscountCode();
        }
    });
}

updatePriceSummary();
if (discountInput && normalizeCode(discountInput.value) !== '') {
    previewDiscountCode();
}
<?php endif; ?>

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); }
    });
}, { threshold: 0.08 });
document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

// Booking mode + individual participant fields
(function () {
    const modeRadios = document.querySelectorAll('input[name="booking_mode"]');
    const countSelect = document.getElementById('participants');
    const wrap = document.getElementById('participant-fields-wrap');
    const inner = document.getElementById('participant-fields-inner');
    const nameInput = document.getElementById('name');
    const emailInputField = document.getElementById('email');
    const modeIndividual = document.getElementById('mode_individual');

    if (!modeIndividual || !countSelect || !wrap || !inner || !nameInput || !emailInputField) {
        return;
    }

    const modeGroupWrap = modeIndividual.closest('.form-group');
    if (!modeGroupWrap) {
        return;
    }

    function escAttr(s) {
        return String(s).replace(/&/g, '&amp;').replace(/"/g, '&quot;').replace(/</g, '&lt;').replace(/>/g, '&gt;');
    }

    function updateModeVisibility() {
        const count = parseInt(countSelect.value, 10) || 1;
        if (count <= 1) {
            modeGroupWrap.style.display = 'none';
            document.getElementById('mode_group').checked = true;
            wrap.style.display = 'none';
            inner.innerHTML = '';
        } else {
            modeGroupWrap.style.display = '';
        }
    }

    function buildParticipantFields() {
        const isIndividual = modeIndividual.checked;
        if (!isIndividual) {
            wrap.style.display = 'none';
            inner.innerHTML = '';
            return;
        }

        const count = parseInt(countSelect.value, 10) || 1;
        const prefill = window.__prefillParticipants || [];
        wrap.style.display = '';
        inner.innerHTML = '';

        for (let i = 0; i < count; i++) {
            const pName = prefill[i] ? prefill[i][0] : (i === 0 ? nameInput.value : '');
            const pEmail = prefill[i] ? prefill[i][1] : (i === 0 ? emailInputField.value : '');
            const entry = document.createElement('div');
            entry.className = 'participant-entry';
            entry.innerHTML = `
                <div class="participant-entry-num">Teilnehmer:in ${i + 1}</div>
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="participant_name[]" value="${escAttr(pName)}"
                           required placeholder="Vollstaendiger Name">
                </div>
                <div class="form-group">
                    <label>E-Mail *</label>
                    <input type="email" name="participant_email[]" value="${escAttr(pEmail)}"
                           required placeholder="email@beispiel.de">
                </div>`;
            inner.appendChild(entry);
        }
    }

    modeRadios.forEach(r => r.addEventListener('change', buildParticipantFields));
    countSelect.addEventListener('change', function () {
        updateModeVisibility();
        buildParticipantFields();
    });

    updateModeVisibility();
    buildParticipantFields();
})();

(function () {
    const slider = document.querySelector('[data-detail-occurrence-slider]');
    if (!slider) {
        return;
    }

    let payload = [];
    try {
        payload = JSON.parse(slider.getAttribute('data-occurrence-payload') || '[]');
    } catch (error) {
        payload = [];
    }

    if (!Array.isArray(payload) || payload.length <= 1) {
        return;
    }

    const cards = Array.from(slider.querySelectorAll('[data-detail-occurrence-card]'));
    const prevBtn = slider.querySelector('[data-detail-occurrence-prev]');
    const nextBtn = slider.querySelector('[data-detail-occurrence-next]');
    const minParticipants = parseInt(slider.getAttribute('data-min-participants') || '0', 10) || 0;
    const capacity = parseInt(slider.getAttribute('data-capacity') || '0', 10) || 0;

    const dateFields = document.querySelectorAll('[data-detail-field="date"]');
    const statusFields = document.querySelectorAll('[data-detail-field="status"]');
    const availabilityFields = document.querySelectorAll('[data-detail-field="availability"]');
    const statusBadge = document.querySelector('[data-detail-status-badge]');
    const statusBadgeText = statusBadge ? statusBadge.querySelector('[data-detail-status-badge-text]') : null;
    const minNoteText = document.querySelector('.js-detail-min-note-text');
    const seatsWrap = document.querySelector('.js-detail-seats');
    const seatsFill = document.querySelector('.js-detail-seats-fill');
    const seatsText = document.querySelector('.js-detail-seats-text');
    const occurrenceInput = document.getElementById('occurrence_id');
    const bookingForm = document.getElementById('bookingForm');
    const bookingSubmitBtn = document.getElementById('bookingSubmitBtn');
    const bookingUnavailable = document.getElementById('detailBookingUnavailable');
    const canSwitchClientSide = Boolean(bookingForm);

    let index = parseInt(slider.getAttribute('data-occurrence-index') || '0', 10);
    if (!Number.isFinite(index) || index < 0 || index >= payload.length) {
        index = 0;
    }

    let animating = false;
    let resetTimer = null;

    const normalizeIndex = (value) => ((value % payload.length) + payload.length) % payload.length;

    const updateNavTargets = () => {
        const prevIndex = normalizeIndex(index - 1);
        const nextIndex = normalizeIndex(index + 1);
        const prevOccurrence = payload[prevIndex] || {};
        const nextOccurrence = payload[nextIndex] || {};

        if (prevBtn && prevOccurrence.url) {
            prevBtn.setAttribute('href', String(prevOccurrence.url));
        }
        if (nextBtn && nextOccurrence.url) {
            nextBtn.setAttribute('href', String(nextOccurrence.url));
        }
    };

    const updateUi = (occurrence) => {
        if (!occurrence || typeof occurrence !== 'object') {
            return;
        }

        const booked = Number(occurrence.booked || 0);
        const spotsLeft = (occurrence.spotsLeft === null || typeof occurrence.spotsLeft === 'undefined')
            ? Math.max(0, capacity - booked)
            : Number(occurrence.spotsLeft || 0);

        dateFields.forEach((el) => {
            el.textContent = String(occurrence.date || '');
        });

        if (minParticipants > 0) {
            const occurrenceIsGuaranteed = Boolean(occurrence.isGuaranteed);
            const statusText = occurrenceIsGuaranteed ? 'Findet statt' : 'Mindestanzahl offen';
            statusFields.forEach((el) => {
                el.textContent = statusText;
            });
            if (statusBadgeText) {
                statusBadgeText.textContent = statusText;
            }
            if (statusBadge) {
                statusBadge.classList.remove('type-badge-confirmed', 'type-badge-open-pending');
                statusBadge.classList.add(occurrenceIsGuaranteed ? 'type-badge-confirmed' : 'type-badge-open-pending');
            }
        }

        availabilityFields.forEach((el) => {
            el.textContent = occurrence.isFull ? 'Ausgebucht' : (String(spotsLeft) + ' frei');
        });

        if (minNoteText && minParticipants > 0) {
            minNoteText.textContent = occurrence.isGuaranteed
                ? 'Mindestanzahl erreicht: Dieser Workshop findet statt.'
                : ('Dieser Workshop findet statt, sobald mindestens ' + minParticipants + ' Teilnehmer:innen gebucht sind.');
        }

        if (seatsWrap) {
            seatsWrap.classList.remove('below-min', 'above-min');
            if (occurrence.belowMin) {
                seatsWrap.classList.add('below-min');
            }
            if (occurrence.aboveMin) {
                seatsWrap.classList.add('above-min');
            }
        }

        if (seatsFill) {
            const fillPct = Number(occurrence.fillPct || 0);
            seatsFill.style.width = String(Math.max(0, Math.min(100, fillPct))) + '%';
            seatsFill.classList.remove('high', 'medium');
            if (occurrence.fillClass === 'high' || occurrence.fillClass === 'medium') {
                seatsFill.classList.add(occurrence.fillClass);
            }
        }

        if (seatsText && capacity > 0) {
            seatsText.textContent = String(booked) + ' / ' + String(capacity) + ' gebucht';
        }

        if (occurrenceInput) {
            occurrenceInput.value = String(parseInt(occurrence.id || 0, 10) || 0);
        }

        if (bookingForm && occurrence.url) {
            bookingForm.setAttribute('action', String(occurrence.url));
        }

        if (bookingSubmitBtn) {
            const occurrenceIsFull = Boolean(occurrence.isFull);
            bookingSubmitBtn.disabled = occurrenceIsFull;
            if (bookingUnavailable) {
                bookingUnavailable.hidden = !occurrenceIsFull;
            }
        }

        if (occurrence.url && window.history && typeof window.history.replaceState === 'function') {
            const targetUrl = String(occurrence.url);
            if (targetUrl !== window.location.pathname + window.location.search) {
                window.history.replaceState(null, '', targetUrl);
            }
        }
    };

    const finalizeCards = () => {
        cards.forEach((card, cardIndex) => {
            card.classList.remove('is-entering-next', 'is-entering-prev', 'is-leaving-next', 'is-leaving-prev');
            if (cardIndex === index) {
                card.classList.add('is-active');
                card.classList.remove('is-hidden');
            } else {
                card.classList.remove('is-active');
                card.classList.add('is-hidden');
            }
        });
    };

    const switchTo = (nextIndex, direction) => {
        const targetIndex = normalizeIndex(nextIndex);
        if (targetIndex === index || animating) {
            return;
        }

        const currentCard = cards[index];
        const nextCard = cards[targetIndex];
        if (!currentCard || !nextCard) {
            index = targetIndex;
            slider.setAttribute('data-occurrence-index', String(index));
            updateUi(payload[index]);
            updateNavTargets();
            finalizeCards();
            return;
        }

        animating = true;
        if (resetTimer) {
            window.clearTimeout(resetTimer);
        }

        currentCard.classList.remove('is-active');
        currentCard.classList.add(direction === 'next' ? 'is-leaving-next' : 'is-leaving-prev');

        nextCard.classList.remove('is-hidden', 'is-leaving-next', 'is-leaving-prev', 'is-entering-next', 'is-entering-prev');
        nextCard.classList.add(direction === 'next' ? 'is-entering-next' : 'is-entering-prev');

        void nextCard.offsetWidth;
        nextCard.classList.add('is-active');
        nextCard.classList.remove('is-entering-next', 'is-entering-prev');

        index = targetIndex;
        slider.setAttribute('data-occurrence-index', String(index));
        updateUi(payload[index]);
        updateNavTargets();

        resetTimer = window.setTimeout(() => {
            finalizeCards();
            animating = false;
        }, 430);
    };

    if (prevBtn) {
        prevBtn.addEventListener('click', (event) => {
            if (!canSwitchClientSide) {
                return;
            }
            event.preventDefault();
            switchTo(index - 1, 'prev');
        });
    }

    if (nextBtn) {
        nextBtn.addEventListener('click', (event) => {
            if (!canSwitchClientSide) {
                return;
            }
            event.preventDefault();
            switchTo(index + 1, 'next');
        });
    }

    slider.addEventListener('keydown', (event) => {
        if (!canSwitchClientSide) {
            return;
        }

        if (event.key === 'ArrowLeft') {
            event.preventDefault();
            switchTo(index - 1, 'prev');
        } else if (event.key === 'ArrowRight') {
            event.preventDefault();
            switchTo(index + 1, 'next');
        }
    });

    finalizeCards();
    updateUi(payload[index]);
    updateNavTargets();
})();

// Detail "more details" interaction (click/tap)
(function () {
    const moreToggle = document.getElementById('detailMoreToggle');
    const moreLayer = document.getElementById('detailMoreLayer');
    const morePanel = document.getElementById('detailMorePanel');
    const moreBackdrop = document.getElementById('detailMoreBackdrop');
    const moreClose = document.getElementById('detailMoreClose');

    if (!moreToggle || !moreLayer || !morePanel || !moreBackdrop || !moreClose) {
        return;
    }

    let panelOpen = false;

    function updateExpanded(expanded) {
        moreToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    function openPanel(focusPanel) {
        if (panelOpen) {
            return;
        }

        morePanel.style.left = '';
        morePanel.style.top = '';
        moreLayer.hidden = false;

        window.requestAnimationFrame(() => {
            moreLayer.classList.add('is-open');
            updateExpanded(true);
            if (focusPanel) {
                morePanel.focus({ preventScroll: true });
            }
        });

        panelOpen = true;
    }

    function closePanel() {
        if (!panelOpen) {
            return;
        }

        moreLayer.classList.remove('is-open');
        updateExpanded(false);
        panelOpen = false;

        window.setTimeout(() => {
            if (!panelOpen) {
                moreLayer.hidden = true;
                morePanel.style.left = '';
                morePanel.style.top = '';
            }
        }, 280);
    }

    moreToggle.addEventListener('click', (event) => {
        event.preventDefault();
        if (panelOpen) {
            closePanel();
            return;
        }
        openPanel(true);
    });

    moreBackdrop.addEventListener('click', () => {
        closePanel();
        moreToggle.focus({ preventScroll: true });
    });

    moreClose.addEventListener('click', () => {
        closePanel();
        moreToggle.focus({ preventScroll: true });
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && panelOpen) {
            closePanel();
            moreToggle.focus({ preventScroll: true });
        }
    });
})();
// Description expand/collapse (all screen sizes)
const descWrap = document.getElementById('detailDescWrap');
const descToggle = document.getElementById('detailDescToggle');
if (descToggle && descWrap) {
    function updateDescToggle(expanded) {
        descToggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
        descToggle.innerHTML = expanded
            ? 'Weniger anzeigen <span class="toggle-arrow">&#8595;</span>'
            : 'Vollstaendig lesen <span class="toggle-arrow">&#8595;</span>';
    }

    // Always start collapsed to declutter the detail page.
    descWrap.classList.remove('expanded');
    updateDescToggle(false);

    descToggle.addEventListener('click', () => {
        const expanded = descWrap.classList.toggle('expanded');
        updateDescToggle(expanded);
    });
}
</script>

<script src="/assets/site-ui.js"></script>

</body>
</html>


