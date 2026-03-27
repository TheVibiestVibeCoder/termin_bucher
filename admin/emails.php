<?php
require __DIR__ . '/../includes/config.php';
require_admin();

$statusFilter = strtolower(trim((string) ($_GET['status'] ?? 'all')));
if (!in_array($statusFilter, ['all', 'sent', 'failed'], true)) {
    $statusFilter = 'all';
}

$mailTypeLabels = [
    'all' => 'Alle Typen',
    'storno' => 'Storno',
    'bestaetigung' => 'Bestaetigung',
    'kontakt' => 'Kontakt',
    'individuell' => 'Individuell',
    'rechnung' => 'Rechnung',
    'admin' => 'Admin',
    'other' => 'Sonstige',
];
$typeFilter = strtolower(trim((string) ($_GET['type'] ?? 'all')));
if (!array_key_exists($typeFilter, $mailTypeLabels)) {
    $typeFilter = 'all';
}

$searchQuery = trim((string) ($_GET['q'] ?? ''));
$page = max(1, (int) ($_GET['page'] ?? 1));
$perPage = 25;

$mailTypeSqlExpr = "
CASE
    WHEN lower(trim(COALESCE(mail_type, ''))) IN ('storno', 'bestaetigung', 'kontakt', 'individuell', 'rechnung', 'admin')
        THEN lower(trim(COALESCE(mail_type, '')))
    WHEN lower(COALESCE(context_label, '')) IN ('booking_cancellation', 'send_booking_cancelled_email')
        THEN 'storno'
    WHEN lower(COALESCE(context_label, '')) IN (
        'booking_confirmation_request',
        'booking_confirmed',
        'participant_confirmed',
        'send_confirmation_email',
        'send_booking_confirmed_email',
        'send_participant_confirmed_email'
    )
        THEN 'bestaetigung'
    WHEN lower(COALESCE(context_label, '')) IN ('contact_admin', 'contact_reply', 'kontakt.php')
        THEN 'kontakt'
    WHEN lower(COALESCE(context_label, '')) IN ('admin_custom', 'send_custom_email')
        THEN 'individuell'
    WHEN lower(COALESCE(context_label, '')) IN ('invoice', 'send_rechnung_email')
        THEN 'rechnung'
    WHEN lower(COALESCE(context_label, '')) IN ('booking_admin_notification', 'send_admin_notification')
        THEN 'admin'
    WHEN lower(COALESCE(subject, '')) LIKE 'buchung storniert:%'
        THEN 'storno'
    WHEN lower(COALESCE(subject, '')) LIKE 'kontaktanfrage:%'
        THEN 'kontakt'
    WHEN lower(COALESCE(subject, '')) LIKE 'ihre anfrage:%'
        THEN 'kontakt'
    WHEN lower(COALESCE(subject, '')) LIKE 'rechnung:%'
        THEN 'rechnung'
    WHEN lower(COALESCE(subject, '')) LIKE 'neue buchung:%'
        THEN 'admin'
    ELSE 'other'
END
";

$where = [];
$params = [];
if ($statusFilter !== 'all') {
    $where[] = 'send_status = :status';
    $params[':status'] = [$statusFilter, SQLITE3_TEXT];
}
if ($typeFilter !== 'all') {
    $where[] = '(' . $mailTypeSqlExpr . ') = :mail_type';
    $params[':mail_type'] = [$typeFilter, SQLITE3_TEXT];
}
if ($searchQuery !== '') {
    $where[] = '(lower(recipient_email) LIKE :q OR lower(subject) LIKE :q OR lower(context_label) LIKE :q)';
    $params[':q'] = ['%' . strtolower($searchQuery) . '%', SQLITE3_TEXT];
}
$whereSql = empty($where) ? '' : (' WHERE ' . implode(' AND ', $where));

$bindParams = static function (SQLite3Stmt $stmt, array $params): void {
    foreach ($params as $key => $entry) {
        $stmt->bindValue($key, $entry[0], $entry[1]);
    }
};

$statsRow = $db->query('
    SELECT
        COUNT(*) AS total_count,
        SUM(CASE WHEN send_status = "sent" THEN 1 ELSE 0 END) AS sent_count,
        SUM(CASE WHEN send_status = "failed" THEN 1 ELSE 0 END) AS failed_count
    FROM email_logs
')->fetchArray(SQLITE3_ASSOC) ?: [];

$globalTotal = (int) ($statsRow['total_count'] ?? 0);
$globalSent = (int) ($statsRow['sent_count'] ?? 0);
$globalFailed = (int) ($statsRow['failed_count'] ?? 0);

$countStmt = $db->prepare('SELECT COUNT(*) AS c FROM email_logs' . $whereSql);
if ($countStmt instanceof SQLite3Stmt) {
    $bindParams($countStmt, $params);
}
$countRow = $countStmt instanceof SQLite3Stmt
    ? $countStmt->execute()->fetchArray(SQLITE3_ASSOC)
    : ['c' => 0];
$filteredTotal = (int) ($countRow['c'] ?? 0);
$totalPages = max(1, (int) ceil($filteredTotal / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
}
$offset = ($page - 1) * $perPage;

$buildUrl = static function (array $overrides = []) use ($statusFilter, $typeFilter, $searchQuery, $page): string {
    $query = [];
    if ($statusFilter !== 'all') {
        $query['status'] = $statusFilter;
    }
    if ($typeFilter !== 'all') {
        $query['type'] = $typeFilter;
    }
    if ($searchQuery !== '') {
        $query['q'] = $searchQuery;
    }
    if ($page > 1) {
        $query['page'] = $page;
    }

    foreach ($overrides as $key => $value) {
        if (
            $value === null
            || $value === ''
            || ($key === 'status' && $value === 'all')
            || ($key === 'type' && $value === 'all')
        ) {
            unset($query[$key]);
            continue;
        }
        $query[$key] = $value;
    }

    return admin_url('emails', $query);
};

$listStmt = $db->prepare('
    SELECT
        *,
        (' . $mailTypeSqlExpr . ') AS mail_type_effective
    FROM email_logs
    ' . $whereSql . '
    ORDER BY created_at DESC, id DESC
    LIMIT :limit OFFSET :offset
');

$emailLogs = [];
if ($listStmt instanceof SQLite3Stmt) {
    $bindParams($listStmt, $params);
    $listStmt->bindValue(':limit', $perPage, SQLITE3_INTEGER);
    $listStmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $result = $listStmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $attachmentMeta = json_decode((string) ($row['attachment_meta_json'] ?? '[]'), true);
        if (!is_array($attachmentMeta)) {
            $attachmentMeta = [];
        }
        $row['attachment_meta'] = $attachmentMeta;
        $emailLogs[] = $row;
    }
}
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
    <title>E-Mails - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .email-toolbar {
            display: grid;
            gap: 0.8rem;
            grid-template-columns: minmax(220px, 1fr) 170px 190px auto auto;
            margin-bottom: 1.15rem;
        }
        .email-toolbar input[type="text"],
        .email-toolbar select {
            width: 100%;
            min-height: 42px;
            margin: 0;
            border: 1px solid var(--border);
            border-radius: 12px;
            background: linear-gradient(180deg, var(--surface-soft) 0%, var(--surface) 100%);
            color: var(--text);
            font-family: var(--font-b);
            font-size: 0.86rem;
            line-height: 1.2;
            padding: 0.62rem 0.78rem;
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, 0.04);
            transition: border-color .18s ease, box-shadow .18s ease, background .18s ease;
        }
        .email-toolbar input[type="text"]::placeholder {
            color: var(--dim);
            opacity: 1;
        }
        .email-toolbar input[type="text"]:hover,
        .email-toolbar select:hover {
            border-color: var(--border-h);
        }
        .email-toolbar input[type="text"]:focus-visible,
        .email-toolbar select:focus-visible {
            outline: none;
            border-color: rgba(46, 204, 113, 0.55);
            box-shadow: 0 0 0 3px rgba(46, 204, 113, 0.16);
            background: linear-gradient(180deg, var(--surface) 0%, var(--surface-soft) 100%);
        }
        .email-toolbar select {
            -webkit-appearance: none;
            appearance: none;
            padding-right: 2.2rem;
            background-image:
                linear-gradient(45deg, transparent 50%, var(--dim) 50%),
                linear-gradient(135deg, var(--dim) 50%, transparent 50%);
            background-position:
                calc(100% - 16px) calc(50% - 2px),
                calc(100% - 11px) calc(50% - 2px);
            background-size: 5px 5px, 5px 5px;
            background-repeat: no-repeat;
            color-scheme: light;
        }
        .email-toolbar select option,
        .email-toolbar select optgroup {
            color: #111;
            background: #fff;
        }
        .email-toolbar .btn-admin {
            min-height: 42px;
            white-space: nowrap;
        }
        .email-log-list {
            display: grid;
            gap: 0.8rem;
        }
        .email-log-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            overflow: hidden;
        }
        .email-log-summary {
            list-style: none;
            cursor: pointer;
            padding: 0.95rem 1rem;
            display: grid;
            gap: 0.45rem;
        }
        .email-log-summary::-webkit-details-marker { display: none; }
        .email-log-summary-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .email-status-pill {
            display: inline-flex;
            align-items: center;
            gap: 0.4rem;
            padding: 0.42rem 0.85rem;
            border-radius: 999px;
            font-size: 0.69rem;
            font-weight: 700;
            letter-spacing: 0.9px;
            text-transform: uppercase;
            line-height: 1;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .email-status-pill::before {
            content: '';
            width: 0.42rem;
            height: 0.42rem;
            border-radius: 50%;
            background: currentColor;
            opacity: 0.95;
        }
        .email-status-pill-sent {
            background: rgba(46, 204, 113, 0.16);
            border-color: rgba(46, 204, 113, 0.35);
            color: #2ecc71;
            box-shadow: inset 0 0 0 1px rgba(46, 204, 113, 0.08);
        }
        .email-status-pill-failed {
            background: rgba(241, 196, 15, 0.16);
            border-color: rgba(241, 196, 15, 0.35);
            color: #f1c40f;
            box-shadow: inset 0 0 0 1px rgba(241, 196, 15, 0.08);
        }
        .email-log-subject {
            color: var(--text);
            font-weight: 600;
            font-size: 0.95rem;
            line-height: 1.4;
            overflow-wrap: anywhere;
        }
        .email-log-meta-line {
            display: flex;
            flex-wrap: wrap;
            gap: 0.85rem;
            font-size: 0.77rem;
            color: var(--muted);
        }
        .email-log-date {
            font-size: 0.74rem;
            color: var(--dim);
            white-space: nowrap;
        }
        .email-log-detail {
            border-top: 1px solid var(--border);
            padding: 1rem;
            display: grid;
            gap: 1rem;
        }
        .email-log-grid {
            display: grid;
            gap: 0.75rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        .email-log-kv {
            border: 1px solid var(--border);
            border-radius: 8px;
            background: var(--surface-soft);
            padding: 0.65rem 0.7rem;
        }
        .email-log-kv .k {
            font-size: 0.68rem;
            letter-spacing: 1px;
            text-transform: uppercase;
            color: var(--dim);
            margin-bottom: 0.25rem;
        }
        .email-log-kv .v {
            color: var(--text);
            font-size: 0.82rem;
            line-height: 1.45;
            overflow-wrap: anywhere;
        }
        .email-log-pre {
            margin: 0;
            white-space: pre-wrap;
            overflow-wrap: anywhere;
            max-height: 320px;
            overflow: auto;
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 0.7rem;
            background: var(--surface-soft);
            font-size: 0.78rem;
            color: var(--text);
            line-height: 1.4;
        }
        .email-log-section h3 {
            margin: 0 0 0.45rem;
            font-size: 0.84rem;
            color: var(--text);
            font-weight: 600;
        }
        .email-log-attachments {
            border: 1px solid var(--border);
            border-radius: 8px;
            overflow: hidden;
            overflow-x: auto;
        }
        .email-log-attachments table {
            width: 100%;
            border-collapse: collapse;
            min-width: 460px;
        }
        .email-log-attachments th,
        .email-log-attachments td {
            padding: 0.55rem 0.6rem;
            font-size: 0.76rem;
            border-bottom: 1px solid var(--border);
            text-align: left;
        }
        .email-log-attachments th {
            color: var(--dim);
            text-transform: uppercase;
            letter-spacing: 0.8px;
        }
        .email-log-pagination {
            margin-top: 1rem;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .email-log-empty {
            background: var(--surface);
            border: 1px dashed var(--border);
            border-radius: var(--radius);
            padding: 1.1rem;
            color: var(--muted);
            font-size: 0.9rem;
        }
        @media (max-width: 980px) {
            .email-toolbar {
                grid-template-columns: 1fr 1fr 1fr;
            }
            .email-log-grid {
                grid-template-columns: 1fr;
            }
            .email-log-summary {
                padding: 0.9rem 0.9rem;
            }
        }
        @media (max-width: 640px) {
            .email-toolbar {
                grid-template-columns: 1fr;
            }
            .email-toolbar input,
            .email-toolbar select,
            .email-toolbar .btn-admin {
                width: 100%;
            }
            .email-toolbar .btn-admin {
                min-height: 40px;
            }
            .stats-row {
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 0.7rem;
                margin-bottom: 1rem;
            }
            .stat-card {
                padding: 1rem 0.85rem;
            }
            .stat-card .stat-card-num {
                font-size: 1.55rem;
            }
            .email-log-summary-top {
                align-items: center;
            }
            .email-log-summary {
                gap: 0.55rem;
                padding: 0.85rem;
            }
            .email-log-date {
                font-size: 0.72rem;
                margin-left: auto;
            }
            .email-status-pill {
                padding: 0.4rem 0.78rem;
                font-size: 0.66rem;
            }
            .email-log-meta-line {
                display: grid;
                grid-template-columns: 1fr 1fr;
                gap: 0.45rem 0.8rem;
                font-size: 0.74rem;
            }
            .email-log-detail {
                padding: 0.85rem;
                gap: 0.85rem;
            }
            .email-log-kv {
                padding: 0.58rem 0.6rem;
            }
            .email-log-kv .k {
                font-size: 0.64rem;
            }
            .email-log-kv .v {
                font-size: 0.78rem;
            }
            .email-log-section h3 {
                font-size: 0.8rem;
            }
            .email-log-pre {
                max-height: 250px;
                font-size: 0.74rem;
                padding: 0.62rem;
            }
            .email-log-attachments {
                overflow: visible;
            }
            .email-log-attachments table {
                min-width: 0;
            }
            .email-log-attachments thead {
                display: none;
            }
            .email-log-attachments tbody,
            .email-log-attachments tr,
            .email-log-attachments td {
                display: block;
                width: 100%;
            }
            .email-log-attachments tr {
                border-bottom: 1px solid var(--border);
                padding: 0.35rem 0.6rem 0.45rem;
            }
            .email-log-attachments tr:last-child {
                border-bottom: none;
            }
            .email-log-attachments td {
                border-bottom: none;
                padding: 0.26rem 0;
                font-size: 0.75rem;
            }
            .email-log-attachments td::before {
                content: attr(data-label);
                display: inline-block;
                min-width: 96px;
                margin-right: 0.45rem;
                font-size: 0.64rem;
                letter-spacing: 0.8px;
                text-transform: uppercase;
                color: var(--dim);
            }
            .email-log-pagination {
                flex-direction: column;
                align-items: stretch;
                gap: 0.5rem;
            }
            .email-log-pagination .btn-admin {
                width: 100%;
                text-align: center;
            }
            .email-log-pagination > span {
                text-align: center;
                font-size: 0.76rem !important;
            }
        }
        @media (max-width: 420px) {
            .stats-row {
                grid-template-columns: 1fr;
            }
            .email-log-meta-line {
                grid-template-columns: 1fr;
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
            <h1>E-Mail-Archiv</h1>
        </div>

        <?= render_flash() ?>

        <div class="stats-row" style="margin-bottom:1rem;">
            <div class="stat-card">
                <div class="stat-card-label">Gesamt</div>
                <div class="stat-card-num"><?= $globalTotal ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Gesendet</div>
                <div class="stat-card-num"><?= $globalSent ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Fehlgeschlagen</div>
                <div class="stat-card-num"><?= $globalFailed ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Treffer aktuell</div>
                <div class="stat-card-num"><?= $filteredTotal ?></div>
            </div>
        </div>

        <form method="GET" action="<?= e(admin_url('emails')) ?>" class="email-toolbar">
            <input type="text" name="q" value="<?= e($searchQuery) ?>" placeholder="Suche: Empfaenger, Betreff, Quelle">
            <select name="status">
                <option value="all" <?= $statusFilter === 'all' ? 'selected' : '' ?>>Alle Stati</option>
                <option value="sent" <?= $statusFilter === 'sent' ? 'selected' : '' ?>>Gesendet</option>
                <option value="failed" <?= $statusFilter === 'failed' ? 'selected' : '' ?>>Fehlgeschlagen</option>
            </select>
            <select name="type">
                <?php foreach ($mailTypeLabels as $typeKey => $typeLabel): ?>
                    <option value="<?= e($typeKey) ?>" <?= $typeFilter === $typeKey ? 'selected' : '' ?>><?= e($typeLabel) ?></option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn-admin btn-success">Filtern</button>
            <a href="<?= e(admin_url('emails')) ?>" class="btn-admin">Zuruecksetzen</a>
        </form>

        <?php if (empty($emailLogs)): ?>
            <div class="email-log-empty">Keine E-Mail-Eintraege fuer die aktuelle Auswahl gefunden.</div>
        <?php else: ?>
            <div class="email-log-list">
                <?php foreach ($emailLogs as $log): ?>
                    <?php
                    $status = strtolower(trim((string) ($log['send_status'] ?? 'failed')));
                    $isSent = $status === 'sent';
                    $statusLabel = $isSent ? 'Gesendet' : 'Fehlgeschlagen';
                    $statusClass = $isSent ? 'email-status-pill-sent' : 'email-status-pill-failed';
                    $subject = trim((string) ($log['subject'] ?? ''));
                    if ($subject === '') {
                        $subject = '(ohne Betreff)';
                    }
                    $mailType = strtolower(trim((string) ($log['mail_type_effective'] ?? $log['mail_type'] ?? 'other')));
                    if (!array_key_exists($mailType, $mailTypeLabels)) {
                        $mailType = 'other';
                    }
                    $mailTypeLabel = (string) ($mailTypeLabels[$mailType] ?? 'Sonstige');
                    $createdTs = strtotime((string) ($log['created_at'] ?? ''));
                    $sentTs = strtotime((string) ($log['sent_at'] ?? ''));
                    $createdLabel = $createdTs ? date('d.m.Y H:i', $createdTs) : (string) ($log['created_at'] ?? '');
                    $sentLabel = $sentTs ? date('d.m.Y H:i', $sentTs) : 'nicht versendet';
                    $attachments = is_array($log['attachment_meta'] ?? null) ? $log['attachment_meta'] : [];
                    ?>
                    <details class="email-log-card">
                        <summary class="email-log-summary">
                            <div class="email-log-summary-top">
                                <span class="email-status-pill <?= e($statusClass) ?>"><?= e($statusLabel) ?></span>
                                <span class="email-log-date"><?= e($createdLabel) ?></span>
                            </div>
                            <div class="email-log-subject"><?= e($subject) ?></div>
                            <div class="email-log-meta-line">
                                <span>An: <?= e((string) ($log['recipient_email'] ?? '')) ?></span>
                                <span>Typ: <?= e($mailTypeLabel) ?></span>
                                <span>Quelle: <?= e((string) (($log['context_label'] ?? '') ?: '-')) ?></span>
                                <span>Anhaenge: <?= (int) ($log['attachment_count'] ?? 0) ?></span>
                                <span>Transport: <?= e((string) ($log['transport'] ?? 'php_mail')) ?></span>
                            </div>
                        </summary>
                        <div class="email-log-detail">
                            <div class="email-log-grid">
                                <div class="email-log-kv">
                                    <div class="k">Empfaenger</div>
                                    <div class="v"><?= e((string) ($log['recipient_email'] ?? '')) ?></div>
                                </div>
                                <div class="email-log-kv">
                                    <div class="k">Absender</div>
                                    <div class="v"><?= e((string) ($log['sender_name'] ?? '')) ?> &lt;<?= e((string) ($log['sender_email'] ?? '')) ?>&gt;</div>
                                </div>
                                <div class="email-log-kv">
                                    <div class="k">Mail-Typ</div>
                                    <div class="v"><?= e($mailTypeLabel) ?></div>
                                </div>
                                <div class="email-log-kv">
                                    <div class="k">Erstellt am</div>
                                    <div class="v"><?= e($createdLabel) ?></div>
                                </div>
                                <div class="email-log-kv">
                                    <div class="k">Versendet am</div>
                                    <div class="v"><?= e($sentLabel) ?></div>
                                </div>
                                <div class="email-log-kv">
                                    <div class="k">Status</div>
                                    <div class="v"><?= e($statusLabel) ?></div>
                                </div>
                                <div class="email-log-kv">
                                    <div class="k">Request / IP</div>
                                    <div class="v"><?= e((string) (($log['request_uri'] ?? '') ?: '-')) ?> | <?= e((string) (($log['client_ip'] ?? '') ?: '-')) ?></div>
                                </div>
                            </div>

                            <?php if (!empty($attachments)): ?>
                                <div class="email-log-section">
                                    <h3>Anhaenge</h3>
                                    <div class="email-log-attachments">
                                        <table>
                                            <thead>
                                                <tr>
                                                    <th>Datei</th>
                                                    <th>MIME</th>
                                                    <th>Groesse (Bytes)</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($attachments as $attachment): ?>
                                                    <tr>
                                                        <td data-label="Datei"><?= e((string) ($attachment['filename'] ?? '')) ?></td>
                                                        <td data-label="MIME"><?= e((string) ($attachment['mime'] ?? '')) ?></td>
                                                        <td data-label="Groesse"><?= (int) ($attachment['bytes'] ?? 0) ?></td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if (trim((string) ($log['error_message'] ?? '')) !== ''): ?>
                                <div class="email-log-section">
                                    <h3>Fehler</h3>
                                    <pre class="email-log-pre"><?= e((string) ($log['error_message'] ?? '')) ?></pre>
                                </div>
                            <?php endif; ?>

                            <div class="email-log-section">
                                <h3>Text-Version</h3>
                                <pre class="email-log-pre"><?= e((string) ($log['body_text'] ?? '')) ?></pre>
                            </div>

                            <div class="email-log-section">
                                <h3>HTML-Version (Quelltext)</h3>
                                <pre class="email-log-pre"><?= e((string) ($log['body_html'] ?? '')) ?></pre>
                            </div>

                            <?php if (trim((string) ($log['headers_raw'] ?? '')) !== ''): ?>
                                <div class="email-log-section">
                                    <h3>Mail-Header</h3>
                                    <pre class="email-log-pre"><?= e((string) ($log['headers_raw'] ?? '')) ?></pre>
                                </div>
                            <?php endif; ?>
                        </div>
                    </details>
                <?php endforeach; ?>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="email-log-pagination">
                    <?php if ($page > 1): ?>
                        <a class="btn-admin" href="<?= e($buildUrl(['page' => $page - 1])) ?>">&larr; Vorherige Seite</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                    <span style="font-size:0.82rem;color:var(--muted);">Seite <?= $page ?> von <?= $totalPages ?></span>
                    <?php if ($page < $totalPages): ?>
                        <a class="btn-admin" href="<?= e($buildUrl(['page' => $page + 1])) ?>">Naechste Seite &rarr;</a>
                    <?php else: ?>
                        <span></span>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/site-ui.js"></script>
</body>
</html>
