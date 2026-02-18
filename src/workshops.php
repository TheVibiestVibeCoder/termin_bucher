<?php
declare(strict_types=1);

function get_live_workshops(): array
{
    $sql = '
        SELECT
            w.*,
            COALESCE(b.booked_count, 0) AS booked_count,
            MAX(w.total_seats - COALESCE(b.booked_count, 0), 0) AS seats_left
        FROM workshops w
        LEFT JOIN (
            SELECT workshop_id, COUNT(*) AS booked_count
            FROM bookings
            WHERE status = \'booked\'
            GROUP BY workshop_id
        ) b ON b.workshop_id = w.id
        WHERE w.status = \'live\'
        ORDER BY w.date_starts ASC
    ';

    return db()->query($sql)->fetchAll();
}

function get_all_workshops_admin(): array
{
    $sql = '
        SELECT
            w.*,
            COALESCE(b.booked_count, 0) AS booked_count,
            MAX(w.total_seats - COALESCE(b.booked_count, 0), 0) AS seats_left
        FROM workshops w
        LEFT JOIN (
            SELECT workshop_id, COUNT(*) AS booked_count
            FROM bookings
            WHERE status = \'booked\'
            GROUP BY workshop_id
        ) b ON b.workshop_id = w.id
        ORDER BY w.date_starts ASC
    ';

    return db()->query($sql)->fetchAll();
}

function get_workshop_by_id(int $id, bool $includeDraft = false): ?array
{
    $sql = '
        SELECT
            w.*,
            COALESCE(b.booked_count, 0) AS booked_count,
            MAX(w.total_seats - COALESCE(b.booked_count, 0), 0) AS seats_left
        FROM workshops w
        LEFT JOIN (
            SELECT workshop_id, COUNT(*) AS booked_count
            FROM bookings
            WHERE status = \'booked\'
            GROUP BY workshop_id
        ) b ON b.workshop_id = w.id
        WHERE w.id = :id
    ';

    if (!$includeDraft) {
        $sql .= " AND w.status = 'live'";
    }

    $stmt = db()->prepare($sql . ' LIMIT 1');
    $stmt->execute([':id' => $id]);
    $row = $stmt->fetch();

    return $row ?: null;
}

function save_workshop(array $data, ?int $id = null): int
{
    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $payload = [
        ':title' => $data['title'],
        ':short_description' => $data['short_description'],
        ':detailed_description' => $data['detailed_description'],
        ':date_starts' => $data['date_starts'],
        ':duration_minutes' => (int) $data['duration_minutes'],
        ':location' => $data['location'],
        ':target_group' => $data['target_group'],
        ':language' => $data['language'],
        ':total_seats' => (int) $data['total_seats'],
        ':price_cents' => (int) $data['price_cents'],
        ':status' => $data['status'],
        ':updated_at' => $now,
    ];

    if ($id === null) {
        $stmt = db()->prepare(
            'INSERT INTO workshops (
                title, short_description, detailed_description, date_starts, duration_minutes, location,
                target_group, language, total_seats, price_cents, status, created_at, updated_at
            ) VALUES (
                :title, :short_description, :detailed_description, :date_starts, :duration_minutes, :location,
                :target_group, :language, :total_seats, :price_cents, :status, :created_at, :updated_at
            )'
        );
        $payload[':created_at'] = $now;
        $stmt->execute($payload);
        create_database_backup();
        return (int) db()->lastInsertId();
    }

    $payload[':id'] = $id;
    $stmt = db()->prepare(
        'UPDATE workshops
         SET title = :title,
             short_description = :short_description,
             detailed_description = :detailed_description,
             date_starts = :date_starts,
             duration_minutes = :duration_minutes,
             location = :location,
             target_group = :target_group,
             language = :language,
             total_seats = :total_seats,
             price_cents = :price_cents,
             status = :status,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute($payload);
    create_database_backup();
    return $id;
}

function delete_workshop(int $id): void
{
    $stmt = db()->prepare('DELETE FROM workshops WHERE id = :id');
    $stmt->execute([':id' => $id]);
    create_database_backup();
}

function set_workshop_status(int $id, string $status): void
{
    $allowed = ['draft', 'live'];
    if (!in_array($status, $allowed, true)) {
        return;
    }

    $stmt = db()->prepare('UPDATE workshops SET status = :status, updated_at = :updated_at WHERE id = :id');
    $stmt->execute([
        ':status' => $status,
        ':updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ':id' => $id,
    ]);
    create_database_backup();
}

function create_booking(array $input): array
{
    $pdo = db();
    try {
        $pdo->exec('BEGIN IMMEDIATE TRANSACTION');

        $workshopStmt = $pdo->prepare(
            'SELECT w.id, w.title, w.status, w.total_seats, w.date_starts,
                (SELECT COUNT(*) FROM bookings b WHERE b.workshop_id = w.id AND b.status = \'booked\') AS booked_count
             FROM workshops w
             WHERE w.id = :id
             LIMIT 1'
        );
        $workshopStmt->execute([':id' => $input['workshop_id']]);
        $workshop = $workshopStmt->fetch();

        if (!$workshop || (string) $workshop['status'] !== 'live') {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Dieser Workshop ist aktuell nicht buchbar.'];
        }

        $seatsLeft = max((int) $workshop['total_seats'] - (int) $workshop['booked_count'], 0);
        if ($seatsLeft <= 0) {
            $pdo->rollBack();
            return ['ok' => false, 'error' => 'Leider sind keine Plaetze mehr frei.'];
        }

        $invoiceSettings = get_invoice_settings();
        $counter = (int) $invoiceSettings['last_invoice_counter'] + 1;
        $invoicePrefix = normalize_text((string) $invoiceSettings['invoice_prefix'], 12);
        $invoiceNumber = sprintf('%s-%s-%05d', $invoicePrefix, (new DateTimeImmutable())->format('Y'), $counter);

        $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');

        $updateCounter = $pdo->prepare(
            'UPDATE invoice_settings
             SET last_invoice_counter = :counter, updated_at = :updated_at
             WHERE id = 1'
        );
        $updateCounter->execute([
            ':counter' => $counter,
            ':updated_at' => $now,
        ]);

        $insert = $pdo->prepare(
            'INSERT INTO bookings (
                workshop_id, name, email, phone, company, billing_address, notes,
                status, invoice_number, created_at, updated_at
            ) VALUES (
                :workshop_id, :name, :email, :phone, :company, :billing_address, :notes,
                \'booked\', :invoice_number, :created_at, :updated_at
            )'
        );
        $insert->execute([
            ':workshop_id' => (int) $input['workshop_id'],
            ':name' => $input['name'],
            ':email' => $input['email'],
            ':phone' => $input['phone'],
            ':company' => $input['company'] ?? '',
            ':billing_address' => $input['billing_address'] ?? '',
            ':notes' => $input['notes'] ?? '',
            ':invoice_number' => $invoiceNumber,
            ':created_at' => $now,
            ':updated_at' => $now,
        ]);

        $bookingId = (int) $pdo->lastInsertId();
        $pdo->commit();
        create_database_backup();

        return [
            'ok' => true,
            'booking' => get_booking_by_id($bookingId),
        ];
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        return ['ok' => false, 'error' => 'Die Buchung konnte nicht gespeichert werden. Bitte erneut versuchen.'];
    }
}

function get_bookings_for_workshop(int $workshopId): array
{
    $stmt = db()->prepare(
        'SELECT *
         FROM bookings
         WHERE workshop_id = :workshop_id
         ORDER BY created_at DESC'
    );
    $stmt->execute([':workshop_id' => $workshopId]);
    return $stmt->fetchAll();
}

function get_booking_by_id(int $bookingId): ?array
{
    $stmt = db()->prepare('SELECT * FROM bookings WHERE id = :id LIMIT 1');
    $stmt->execute([':id' => $bookingId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function update_booking(int $bookingId, array $data): void
{
    $status = in_array($data['status'], ['booked', 'cancelled'], true) ? $data['status'] : 'booked';
    $cancelledAt = $status === 'cancelled' ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null;

    $stmt = db()->prepare(
        'UPDATE bookings
         SET name = :name,
             email = :email,
             phone = :phone,
             company = :company,
             billing_address = :billing_address,
             notes = :notes,
             status = :status,
             cancelled_at = :cancelled_at,
             updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':name' => $data['name'],
        ':email' => $data['email'],
        ':phone' => $data['phone'],
        ':company' => $data['company'] ?? '',
        ':billing_address' => $data['billing_address'] ?? '',
        ':notes' => $data['notes'] ?? '',
        ':status' => $status,
        ':cancelled_at' => $cancelledAt,
        ':updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ':id' => $bookingId,
    ]);

    create_database_backup();
}

function set_booking_status(int $bookingId, string $status): void
{
    if (!in_array($status, ['booked', 'cancelled'], true)) {
        return;
    }

    $cancelledAt = $status === 'cancelled' ? (new DateTimeImmutable())->format('Y-m-d H:i:s') : null;
    $stmt = db()->prepare(
        'UPDATE bookings
         SET status = :status, cancelled_at = :cancelled_at, updated_at = :updated_at
         WHERE id = :id'
    );
    $stmt->execute([
        ':status' => $status,
        ':cancelled_at' => $cancelledAt,
        ':updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
        ':id' => $bookingId,
    ]);

    create_database_backup();
}

function delete_booking(int $bookingId): void
{
    $stmt = db()->prepare('DELETE FROM bookings WHERE id = :id');
    $stmt->execute([':id' => $bookingId]);
    create_database_backup();
}

function get_invoice_settings(): array
{
    $row = db()->query('SELECT * FROM invoice_settings WHERE id = 1 LIMIT 1')->fetch();
    if (!$row) {
        return [];
    }

    return $row;
}

function update_invoice_settings(array $input): void
{
    $stmt = db()->prepare(
        'UPDATE invoice_settings
         SET company_name = :company_name,
             company_address = :company_address,
             company_vat = :company_vat,
             company_iban = :company_iban,
             company_bic = :company_bic,
             payment_terms_days = :payment_terms_days,
             cancellation_policy = :cancellation_policy,
             email_from = :email_from,
             reply_to = :reply_to,
             invoice_prefix = :invoice_prefix,
             updated_at = :updated_at
         WHERE id = 1'
    );
    $stmt->execute([
        ':company_name' => $input['company_name'],
        ':company_address' => $input['company_address'],
        ':company_vat' => $input['company_vat'],
        ':company_iban' => $input['company_iban'],
        ':company_bic' => $input['company_bic'],
        ':payment_terms_days' => (int) $input['payment_terms_days'],
        ':cancellation_policy' => $input['cancellation_policy'],
        ':email_from' => $input['email_from'],
        ':reply_to' => $input['reply_to'],
        ':invoice_prefix' => $input['invoice_prefix'],
        ':updated_at' => (new DateTimeImmutable())->format('Y-m-d H:i:s'),
    ]);

    create_database_backup();
}

