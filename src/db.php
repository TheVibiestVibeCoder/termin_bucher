<?php
declare(strict_types=1);

function ensure_data_directories(): void
{
    $paths = [
        (string) config('paths.data'),
        (string) config('paths.logs'),
        (string) config('paths.backups'),
    ];

    foreach ($paths as $path) {
        if (!is_dir($path)) {
            mkdir($path, 0775, true);
        }
    }
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    ensure_data_directories();

    $dbPath = (string) config('paths.db');
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $pdo->exec('PRAGMA journal_mode=WAL;');
    $pdo->exec('PRAGMA foreign_keys=ON;');
    $pdo->exec('PRAGMA busy_timeout=5000;');

    run_migrations($pdo);

    return $pdo;
}

function run_migrations(PDO $pdo): void
{
    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS workshops (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            title TEXT NOT NULL,
            short_description TEXT NOT NULL,
            detailed_description TEXT NOT NULL,
            date_starts TEXT NOT NULL,
            duration_minutes INTEGER NOT NULL DEFAULT 180,
            location TEXT NOT NULL,
            target_group TEXT NOT NULL,
            language TEXT NOT NULL,
            total_seats INTEGER NOT NULL,
            price_cents INTEGER NOT NULL,
            status TEXT NOT NULL CHECK (status IN (\'draft\', \'live\')) DEFAULT \'draft\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS bookings (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            workshop_id INTEGER NOT NULL,
            name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT NOT NULL,
            company TEXT DEFAULT \'\',
            billing_address TEXT DEFAULT \'\',
            notes TEXT DEFAULT \'\',
            status TEXT NOT NULL CHECK (status IN (\'booked\', \'cancelled\')) DEFAULT \'booked\',
            invoice_number TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL,
            cancelled_at TEXT DEFAULT NULL,
            FOREIGN KEY (workshop_id) REFERENCES workshops(id) ON DELETE CASCADE
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS invoice_settings (
            id INTEGER PRIMARY KEY CHECK (id = 1),
            company_name TEXT NOT NULL,
            company_address TEXT NOT NULL,
            company_vat TEXT DEFAULT \'\',
            company_iban TEXT DEFAULT \'\',
            company_bic TEXT DEFAULT \'\',
            payment_terms_days INTEGER NOT NULL DEFAULT 14,
            cancellation_policy TEXT NOT NULL,
            email_from TEXT NOT NULL,
            reply_to TEXT NOT NULL,
            invoice_prefix TEXT NOT NULL DEFAULT \'INV\',
            last_invoice_counter INTEGER NOT NULL DEFAULT 1000,
            updated_at TEXT NOT NULL
        )'
    );

    $pdo->exec(
        'CREATE TABLE IF NOT EXISTS rate_limit_hits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL,
            ip TEXT NOT NULL,
            created_at INTEGER NOT NULL
        )'
    );

    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_workshops_status_date ON workshops(status, date_starts)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_bookings_workshop_status ON bookings(workshop_id, status)');
    $pdo->exec('CREATE INDEX IF NOT EXISTS idx_rate_limit_bucket_ip_time ON rate_limit_hits(bucket, ip, created_at)');

    seed_admin_account($pdo);
    seed_invoice_settings($pdo);
}

function seed_admin_account(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $insert = $pdo->prepare(
        'INSERT INTO admins (username, password_hash, created_at, updated_at)
         VALUES (:username, :password_hash, :created_at, :updated_at)'
    );
    $insert->execute([
        ':username' => (string) config('admin.default_username'),
        ':password_hash' => password_hash((string) config('admin.default_password'), PASSWORD_DEFAULT),
        ':created_at' => $now,
        ':updated_at' => $now,
    ]);
}

function seed_invoice_settings(PDO $pdo): void
{
    $count = (int) $pdo->query('SELECT COUNT(*) FROM invoice_settings WHERE id = 1')->fetchColumn();
    if ($count > 0) {
        return;
    }

    $now = (new DateTimeImmutable())->format('Y-m-d H:i:s');
    $insert = $pdo->prepare(
        'INSERT INTO invoice_settings (
            id, company_name, company_address, company_vat, company_iban, company_bic,
            payment_terms_days, cancellation_policy, email_from, reply_to, invoice_prefix, last_invoice_counter, updated_at
        ) VALUES (
            1, :company_name, :company_address, :company_vat, :company_iban, :company_bic,
            :payment_terms_days, :cancellation_policy, :email_from, :reply_to, :invoice_prefix, :last_invoice_counter, :updated_at
        )'
    );
    $insert->execute([
        ':company_name' => 'Disinformation Consulting',
        ':company_address' => "Musterstrasse 1\n10115 Berlin\nDeutschland",
        ':company_vat' => '',
        ':company_iban' => '',
        ':company_bic' => '',
        ':payment_terms_days' => 14,
        ':cancellation_policy' => 'Stornierung bis 7 Tage vor Workshop-Beginn kostenfrei, danach 50% der Kosten.',
        ':email_from' => (string) config('mail.default_from'),
        ':reply_to' => (string) config('mail.default_reply_to'),
        ':invoice_prefix' => 'INV',
        ':last_invoice_counter' => 1000,
        ':updated_at' => $now,
    ]);
}

function create_database_backup(): void
{
    $dbPath = (string) config('paths.db');
    if (!is_file($dbPath)) {
        return;
    }

    $backupDir = (string) config('paths.backups');
    if (!is_dir($backupDir)) {
        mkdir($backupDir, 0775, true);
    }

    $date = (new DateTimeImmutable())->format('Y-m-d');
    $target = $backupDir . DIRECTORY_SEPARATOR . 'workshops-' . $date . '.sqlite';
    if (!is_file($target)) {
        @copy($dbPath, $target);
    }
}

