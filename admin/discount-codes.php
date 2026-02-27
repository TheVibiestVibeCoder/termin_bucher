<?php
require __DIR__ . '/../includes/config.php';
require_admin();

$errors = [];

$defaultForm = [
    'id' => 0,
    'code' => '',
    'label' => '',
    'discount_type' => 'percent',
    'discount_value' => '',
    'active' => 1,
    'starts_at' => '',
    'expires_at' => '',
    'max_total_uses' => 0,
    'max_uses_per_email' => 0,
    'min_participants' => 0,
    'allowed_emails' => '',
    'allowed_workshop_ids' => [],
];
$formData = $defaultForm;

$wsRes = $db->query('SELECT id, title FROM workshops ORDER BY sort_order ASC, title ASC');
$workshops = [];
while ($row = $wsRes->fetchArray(SQLITE3_ASSOC)) {
    $workshops[] = $row;
}
$workshopMap = [];
foreach ($workshops as $w) {
    $workshopMap[(int) $w['id']] = $w['title'];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_verify()) {
    flash('error', 'Ungueltige Sitzung.');
    redirect('discount-codes.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['delete_discount_id'])) {
        $deleteId = (int) $_POST['delete_discount_id'];
        $stmt = $db->prepare('DELETE FROM discount_codes WHERE id = :id');
        $stmt->bindValue(':id', $deleteId, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Rabattcode geloescht.');
        redirect('discount-codes.php');
    }

    if (isset($_POST['toggle_discount_id'])) {
        $toggleId = (int) $_POST['toggle_discount_id'];
        $stmt = $db->prepare('UPDATE discount_codes SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END, updated_at = datetime("now") WHERE id = :id');
        $stmt->bindValue(':id', $toggleId, SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Status aktualisiert.');
        redirect('discount-codes.php');
    }

    if (isset($_POST['save_discount'])) {
        $formData['id'] = (int) ($_POST['discount_id'] ?? 0);
        $formData['code'] = normalize_discount_code((string) ($_POST['code'] ?? ''));
        $formData['label'] = trim((string) ($_POST['label'] ?? ''));
        $formData['discount_type'] = ($_POST['discount_type'] ?? 'percent') === 'fixed' ? 'fixed' : 'percent';

        $discountValueRaw = str_replace(',', '.', trim((string) ($_POST['discount_value'] ?? '')));
        $formData['discount_value'] = $discountValueRaw;

        $formData['active'] = isset($_POST['active']) ? 1 : 0;
        $formData['starts_at'] = normalize_admin_datetime_input((string) ($_POST['starts_at'] ?? ''));
        $formData['expires_at'] = normalize_admin_datetime_input((string) ($_POST['expires_at'] ?? ''));
        $formData['max_total_uses'] = max(0, (int) ($_POST['max_total_uses'] ?? 0));
        $formData['max_uses_per_email'] = max(0, (int) ($_POST['max_uses_per_email'] ?? 0));
        $formData['min_participants'] = max(0, (int) ($_POST['min_participants'] ?? 0));

        $selectedWorkshops = array_map(
            'intval',
            array_filter((array) ($_POST['allowed_workshop_ids'] ?? []), fn($id) => (int) $id > 0)
        );
        $selectedWorkshops = array_values(array_unique($selectedWorkshops));
        sort($selectedWorkshops, SORT_NUMERIC);
        $formData['allowed_workshop_ids'] = $selectedWorkshops;

        $allowedEmailsRaw = trim((string) ($_POST['allowed_emails'] ?? ''));
        $emailTokens = preg_split('/[\s,;]+/', $allowedEmailsRaw) ?: [];
        $invalidEmails = [];
        foreach ($emailTokens as $token) {
            $token = trim($token);
            if ($token !== '' && !filter_var($token, FILTER_VALIDATE_EMAIL)) {
                $invalidEmails[] = $token;
            }
        }
        $formData['allowed_emails'] = normalize_discount_email_list($allowedEmailsRaw);

        if (!preg_match('/^[A-Z0-9_-]{3,40}$/', $formData['code'])) {
            $errors[] = 'Code muss 3-40 Zeichen lang sein und darf nur A-Z, 0-9, _ oder - enthalten.';
        }

        if ($formData['label'] !== '' && mb_strlen($formData['label']) > 120) {
            $errors[] = 'Bezeichnung ist zu lang (max. 120 Zeichen).';
        }

        $discountValue = (float) $discountValueRaw;
        if ($discountValue <= 0) {
            $errors[] = 'Rabattwert muss groesser als 0 sein.';
        }

        if ($formData['discount_type'] === 'percent' && $discountValue > 100) {
            $errors[] = 'Prozentrabatt darf nicht groesser als 100 sein.';
        }

        if (!empty($invalidEmails)) {
            $errors[] = 'Mindestens eine E-Mail in der Freigabeliste ist ungueltig.';
        }

        if (
            $formData['starts_at'] !== ''
            && $formData['expires_at'] !== ''
            && strtotime($formData['starts_at']) > strtotime($formData['expires_at'])
        ) {
            $errors[] = 'Startdatum darf nicht nach dem Enddatum liegen.';
        }

        if (empty($errors)) {
            try {
                if ($formData['id'] > 0) {
                    $stmt = $db->prepare('
                        UPDATE discount_codes
                        SET
                            code = :code,
                            label = :label,
                            discount_type = :dtype,
                            discount_value = :dvalue,
                            active = :active,
                            starts_at = :starts_at,
                            expires_at = :expires_at,
                            max_total_uses = :max_total,
                            max_uses_per_email = :max_email,
                            min_participants = :minp,
                            allowed_emails = :emails,
                            allowed_workshop_ids = :wids,
                            updated_at = datetime("now")
                        WHERE id = :id
                    ');
                    $stmt->bindValue(':id', $formData['id'], SQLITE3_INTEGER);
                } else {
                    $stmt = $db->prepare('
                        INSERT INTO discount_codes (
                            code,
                            label,
                            discount_type,
                            discount_value,
                            active,
                            starts_at,
                            expires_at,
                            max_total_uses,
                            max_uses_per_email,
                            min_participants,
                            allowed_emails,
                            allowed_workshop_ids
                        ) VALUES (
                            :code,
                            :label,
                            :dtype,
                            :dvalue,
                            :active,
                            :starts_at,
                            :expires_at,
                            :max_total,
                            :max_email,
                            :minp,
                            :emails,
                            :wids
                        )
                    ');
                }

                $stmt->bindValue(':code', $formData['code'], SQLITE3_TEXT);
                $stmt->bindValue(':label', $formData['label'], SQLITE3_TEXT);
                $stmt->bindValue(':dtype', $formData['discount_type'], SQLITE3_TEXT);
                $stmt->bindValue(':dvalue', $discountValue, SQLITE3_FLOAT);
                $stmt->bindValue(':active', $formData['active'], SQLITE3_INTEGER);
                $stmt->bindValue(':starts_at', $formData['starts_at'], SQLITE3_TEXT);
                $stmt->bindValue(':expires_at', $formData['expires_at'], SQLITE3_TEXT);
                $stmt->bindValue(':max_total', $formData['max_total_uses'], SQLITE3_INTEGER);
                $stmt->bindValue(':max_email', $formData['max_uses_per_email'], SQLITE3_INTEGER);
                $stmt->bindValue(':minp', $formData['min_participants'], SQLITE3_INTEGER);
                $stmt->bindValue(':emails', $formData['allowed_emails'], SQLITE3_TEXT);
                $stmt->bindValue(':wids', implode(',', $formData['allowed_workshop_ids']), SQLITE3_TEXT);
                $stmt->execute();

                flash('success', $formData['id'] > 0 ? 'Rabattcode aktualisiert.' : 'Rabattcode erstellt.');
                redirect('discount-codes.php');
            } catch (Throwable $e) {
                if (str_contains(strtolower($e->getMessage()), 'unique')) {
                    $errors[] = 'Dieser Code existiert bereits.';
                } else {
                    $errors[] = 'Technischer Fehler beim Speichern.';
                }
            }
        }
    }
}

$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0 && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $stmt = $db->prepare('SELECT * FROM discount_codes WHERE id = :id');
    $stmt->bindValue(':id', $editId, SQLITE3_INTEGER);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $formData['id'] = (int) $row['id'];
        $formData['code'] = (string) $row['code'];
        $formData['label'] = (string) $row['label'];
        $formData['discount_type'] = (string) $row['discount_type'];
        $formData['discount_value'] = number_format((float) $row['discount_value'], 2, '.', '');
        $formData['active'] = (int) $row['active'];
        $formData['starts_at'] = normalize_admin_datetime_input((string) $row['starts_at']);
        $formData['expires_at'] = normalize_admin_datetime_input((string) $row['expires_at']);
        $formData['max_total_uses'] = (int) $row['max_total_uses'];
        $formData['max_uses_per_email'] = (int) $row['max_uses_per_email'];
        $formData['min_participants'] = (int) $row['min_participants'];
        $formData['allowed_emails'] = (string) $row['allowed_emails'];
        $formData['allowed_workshop_ids'] = parse_discount_workshop_ids((string) $row['allowed_workshop_ids']);
    }
}

$codesResult = $db->query('
    SELECT
        dc.*,
        COUNT(b.id) AS usage_total,
        COALESCE(SUM(CASE WHEN b.confirmed = 1 THEN 1 ELSE 0 END), 0) AS usage_confirmed
    FROM discount_codes dc
    LEFT JOIN bookings b ON b.discount_code_id = dc.id
    GROUP BY dc.id
    ORDER BY dc.created_at DESC, dc.id DESC
');
$codes = [];
while ($row = $codesResult->fetchArray(SQLITE3_ASSOC)) {
    $codes[] = $row;
}

$statusLabels = [
    'active' => 'Aktiv',
    'inactive' => 'Deaktiviert',
    'scheduled' => 'Geplant',
    'expired' => 'Abgelaufen',
];
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
    <title>Rabattcodes - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .discount-form-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.25rem;
            margin-bottom: 2rem;
        }
        .discount-form-title {
            font-size: 0.72rem;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 2px;
            color: var(--dim);
            margin-bottom: 1rem;
        }
        .discount-form {
            display: flex;
            flex-direction: column;
            gap: 1rem;
        }
        .discount-form .form-group {
            margin-bottom: 0;
        }
        .discount-grid-2 {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1rem;
        }
        .discount-grid-3 {
            display: grid;
            grid-template-columns: repeat(3, minmax(0, 1fr));
            gap: 1rem;
        }
        .discount-grid-align-start {
            align-items: start;
        }
        .discount-form input[type="text"],
        .discount-form input[type="number"],
        .discount-form input[type="datetime-local"],
        .discount-form select {
            min-height: 42px;
        }
        .discount-form textarea {
            min-height: 124px;
        }
        .discount-form-note {
            display: block;
            font-size: 0.72rem;
            color: var(--dim);
            margin-top: 0.35rem;
            line-height: 1.4;
        }
        .discount-active-row {
            display: flex;
            align-items: center;
            gap: 0.6rem;
        }
        .discount-active-row input {
            width: auto;
            accent-color: #2ecc71;
        }
        .discount-active-row label {
            margin-bottom: 0;
            cursor: pointer;
        }
        .discount-form-actions {
            display: flex;
            gap: 0.75rem;
            flex-wrap: wrap;
            align-items: stretch;
        }
        .discount-form-actions .btn-admin {
            min-width: 150px;
            min-height: 40px;
        }
        .discount-workshop-picker-row select {
            min-height: 42px;
        }
        .discount-workshop-picker-row .btn-admin {
            min-height: 42px;
            padding-inline: 14px;
        }
        @media (max-width: 980px) {
            .discount-grid-2,
            .discount-grid-3 {
                grid-template-columns: 1fr;
            }
        }
        @media (max-width: 640px) {
            .discount-form-actions .btn-admin {
                width: 100%;
            }
        }
    </style>
</head>
<body>
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>Rabattcodes</h1>
            <?php if ((int) $formData['id'] > 0): ?>
                <a href="discount-codes.php" class="btn-admin">+ Neuer Code</a>
            <?php endif; ?>
        </div>

        <?= render_flash() ?>

        <?php if (!empty($errors)): ?>
            <div class="flash flash-error" style="margin-bottom:1.5rem;">
                <?php foreach ($errors as $error): ?>
                    <div><?= e($error) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="discount-form-card">
            <div class="discount-form-title">
                <?= (int) $formData['id'] > 0 ? 'Code bearbeiten' : 'Neuen Code erstellen' ?>
            </div>
            <form method="POST" class="discount-form">
                <?= csrf_field() ?>
                <input type="hidden" name="save_discount" value="1">
                <input type="hidden" name="discount_id" value="<?= (int) $formData['id'] ?>">

                <div class="discount-grid-2">
                    <div class="form-group">
                        <label for="code">Code</label>
                        <input type="text" id="code" name="code" required placeholder="Z.B. SPRING25"
                               value="<?= e((string) $formData['code']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="label">Bezeichnung (optional)</label>
                        <input type="text" id="label" name="label" placeholder="z.B. Fruehjahrsaktion"
                               value="<?= e((string) $formData['label']) ?>">
                    </div>
                </div>

                <div class="discount-grid-2">
                    <div class="form-group">
                        <label for="discount_type">Rabattart</label>
                        <select id="discount_type" name="discount_type">
                            <option value="percent" <?= $formData['discount_type'] === 'percent' ? 'selected' : '' ?>>Prozent</option>
                            <option value="fixed" <?= $formData['discount_type'] === 'fixed' ? 'selected' : '' ?>>Fixbetrag</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label for="discount_value">Rabattwert</label>
                        <input type="text" id="discount_value" name="discount_value" required placeholder="z.B. 15 oder 49.90"
                               value="<?= e((string) $formData['discount_value']) ?>">
                    </div>
                </div>

                <div class="discount-grid-2">
                    <div class="form-group">
                        <label for="starts_at">Gueltig ab (optional)</label>
                        <input type="datetime-local" id="starts_at" name="starts_at"
                               value="<?= e(datetime_input_value((string) $formData['starts_at'])) ?>">
                    </div>
                    <div class="form-group">
                        <label for="expires_at">Gueltig bis (optional)</label>
                        <input type="datetime-local" id="expires_at" name="expires_at"
                               value="<?= e(datetime_input_value((string) $formData['expires_at'])) ?>">
                    </div>
                </div>

                <div class="discount-grid-3">
                    <div class="form-group">
                        <label for="max_total_uses">Max. Nutzungen gesamt (0 = unbegrenzt)</label>
                        <input type="number" id="max_total_uses" name="max_total_uses" min="0"
                               value="<?= (int) $formData['max_total_uses'] ?>">
                    </div>
                    <div class="form-group">
                        <label for="max_uses_per_email">Max. pro E-Mail (0 = unbegrenzt)</label>
                        <input type="number" id="max_uses_per_email" name="max_uses_per_email" min="0"
                               value="<?= (int) $formData['max_uses_per_email'] ?>">
                    </div>
                    <div class="form-group">
                        <label for="min_participants">Min. Teilnehmer:innen</label>
                        <input type="number" id="min_participants" name="min_participants" min="0"
                               value="<?= (int) $formData['min_participants'] ?>">
                    </div>
                </div>

                <div class="discount-grid-2 discount-grid-align-start">
                    <div class="form-group">
                        <label for="workshop_picker">Nur fuer bestimmte Workshops (optional)</label>
                        <div class="discount-workshop-picker">
                            <div class="discount-workshop-picker-row">
                                <select id="workshop_picker">
                                    <option value="">Workshop auswaehlen ...</option>
                                    <?php foreach ($workshops as $w): ?>
                                        <option value="<?= (int) $w['id'] ?>"><?= e((string) $w['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" class="btn-admin" id="add_workshop_btn">Hinzufuegen</button>
                            </div>

                            <div class="discount-workshop-selected" id="selected-workshops">
                                <?php if (empty($formData['allowed_workshop_ids'])): ?>
                                    <div class="discount-workshop-empty">Leer = gilt fuer alle Workshops.</div>
                                <?php else: ?>
                                    <?php foreach ($formData['allowed_workshop_ids'] as $wid): ?>
                                        <?php $wid = (int) $wid; ?>
                                        <?php if (isset($workshopMap[$wid])): ?>
                                            <span class="discount-workshop-chip" data-workshop-id="<?= $wid ?>">
                                                <span class="discount-workshop-chip-label"><?= e((string) $workshopMap[$wid]) ?></span>
                                                <button type="button" class="discount-workshop-chip-remove" aria-label="Workshop entfernen">&times;</button>
                                                <input type="hidden" name="allowed_workshop_ids[]" value="<?= $wid ?>">
                                            </span>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                        <span class="discount-form-note">Leer lassen = gilt fuer alle Workshops.</span>
                    </div>
                    <div class="form-group">
                        <label for="allowed_emails">Nur fuer bestimmte E-Mails (optional)</label>
                        <textarea id="allowed_emails" name="allowed_emails" rows="6"
                                  placeholder="max@example.com&#10;team@example.com"><?= e((string) $formData['allowed_emails']) ?></textarea>
                        <span class="discount-form-note">Eine E-Mail pro Zeile oder mit Komma getrennt.</span>
                    </div>
                </div>

                <div class="discount-active-row">
                    <input type="checkbox" id="active" name="active" value="1" <?= (int) $formData['active'] === 1 ? 'checked' : '' ?>>
                    <label for="active">Code aktiv</label>
                </div>

                <div class="discount-form-actions">
                    <button type="submit" class="btn-admin btn-success">Speichern</button>
                    <?php if ((int) $formData['id'] > 0): ?>
                        <a href="discount-codes.php" class="btn-admin">Abbrechen</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>

        <?php if (empty($codes)): ?>
            <p style="color:var(--muted);">Noch keine Rabattcodes vorhanden.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
                <table class="admin-table">
                    <thead>
                        <tr>
                            <th>Code</th>
                            <th>Rabatt</th>
                            <th>Status</th>
                            <th>Gueltig</th>
                            <th>Limits</th>
                            <th>Nutzung</th>
                            <th>Einsatz</th>
                            <th>Aktionen</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($codes as $code):
                            $status = discount_code_status($code);
                            $statusClass = $status === 'active' ? 'status-confirmed' : 'status-pending';
                            $maxTotal = (int) ($code['max_total_uses'] ?? 0);
                            $remaining = $maxTotal > 0 ? max(0, $maxTotal - (int) $code['usage_total']) : null;
                            $workshopIds = parse_discount_workshop_ids((string) ($code['allowed_workshop_ids'] ?? ''));
                            $workshopNames = [];
                            foreach ($workshopIds as $wid) {
                                if (isset($workshopMap[$wid])) {
                                    $workshopNames[] = $workshopMap[$wid];
                                }
                            }
                            $emailRestrictionCount = count(parse_discount_email_list((string) ($code['allowed_emails'] ?? '')));
                        ?>
                            <tr>
                                <td style="color:var(--text);font-weight:600;">
                                    <?= e((string) $code['code']) ?>
                                    <?php if (trim((string) $code['label']) !== ''): ?>
                                        <div style="font-size:0.75rem;color:var(--dim);font-weight:400;">
                                            <?= e((string) $code['label']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td><?= e(format_discount_value((string) $code['discount_type'], (float) $code['discount_value'])) ?></td>
                                <td><span class="status-badge <?= $statusClass ?>"><?= e($statusLabels[$status] ?? $status) ?></span></td>
                                <td>
                                    <div>ab: <?= e(format_admin_datetime((string) $code['starts_at'])) ?></div>
                                    <div>bis: <?= e(format_admin_datetime((string) $code['expires_at'])) ?></div>
                                </td>
                                <td>
                                    <div>gesamt: <?= $maxTotal > 0 ? $maxTotal : 'unbegrenzt' ?></div>
                                    <div>pro E-Mail: <?= (int) $code['max_uses_per_email'] > 0 ? (int) $code['max_uses_per_email'] : 'unbegrenzt' ?></div>
                                    <div>min TN: <?= (int) $code['min_participants'] > 0 ? (int) $code['min_participants'] : '-' ?></div>
                                </td>
                                <td>
                                    <div><?= (int) $code['usage_total'] ?>x verwendet</div>
                                    <div style="font-size:0.75rem;color:var(--dim);">
                                        bestaetigt: <?= (int) $code['usage_confirmed'] ?>
                                        <?php if ($remaining !== null): ?>
                                            - rest: <?= $remaining ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <div style="font-size:0.8rem;color:var(--muted);">
                                        Workshops: <?= empty($workshopNames) ? 'alle' : e(implode(', ', $workshopNames)) ?>
                                    </div>
                                    <div style="font-size:0.75rem;color:var(--dim);">
                                        E-Mail-Limit: <?= $emailRestrictionCount > 0 ? $emailRestrictionCount . ' Adresse(n)' : 'keins' ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="admin-actions">
                                        <a href="discount-codes.php?edit=<?= (int) $code['id'] ?>" class="btn-admin">Bearbeiten</a>
                                        <form method="POST" style="display:inline;">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="toggle_discount_id" value="<?= (int) $code['id'] ?>">
                                            <button type="submit" class="btn-admin"><?= (int) $code['active'] === 1 ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                        </form>
                                        <form method="POST" style="display:inline;" onsubmit="return confirm('Code wirklich loeschen?');">
                                            <?= csrf_field() ?>
                                            <input type="hidden" name="delete_discount_id" value="<?= (int) $code['id'] ?>">
                                            <button type="submit" class="btn-admin btn-danger">Loeschen</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    const picker = document.getElementById('workshop_picker');
    const addButton = document.getElementById('add_workshop_btn');
    const selectedWrap = document.getElementById('selected-workshops');

    if (!picker || !addButton || !selectedWrap) {
        return;
    }

    function escapeHtml(value) {
        return String(value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    function hasWorkshop(id) {
        const lookup = String(id);
        return Array.from(selectedWrap.querySelectorAll('.discount-workshop-chip')).some(function (chip) {
            return String(chip.dataset.workshopId || '') === lookup;
        });
    }

    function ensureEmptyHint() {
        const hasChips = selectedWrap.querySelector('.discount-workshop-chip');
        const emptyHint = selectedWrap.querySelector('.discount-workshop-empty');

        if (!hasChips && !emptyHint) {
            const hint = document.createElement('div');
            hint.className = 'discount-workshop-empty';
            hint.textContent = 'Leer = gilt fuer alle Workshops.';
            selectedWrap.appendChild(hint);
        }

        if (hasChips && emptyHint) {
            emptyHint.remove();
        }
    }

    function addWorkshop(id, title) {
        if (!id || hasWorkshop(id)) {
            return;
        }

        const chip = document.createElement('span');
        chip.className = 'discount-workshop-chip';
        chip.dataset.workshopId = String(id);
        chip.innerHTML =
            '<span class="discount-workshop-chip-label">' + escapeHtml(title) + '</span>' +
            '<button type="button" class="discount-workshop-chip-remove" aria-label="Workshop entfernen">&times;</button>' +
            '<input type="hidden" name="allowed_workshop_ids[]" value="' + escapeHtml(id) + '">';

        selectedWrap.appendChild(chip);
        ensureEmptyHint();
    }

    addButton.addEventListener('click', function () {
        const option = picker.options[picker.selectedIndex];
        if (!option || !option.value) {
            return;
        }

        addWorkshop(option.value, option.textContent || option.innerText || 'Workshop');
        picker.value = '';
    });

    picker.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            addButton.click();
        }
    });

    selectedWrap.addEventListener('click', function (event) {
        const removeButton = event.target.closest('.discount-workshop-chip-remove');
        if (!removeButton) {
            return;
        }

        const chip = removeButton.closest('.discount-workshop-chip');
        if (chip) {
            chip.remove();
        }
        ensureEmptyHint();
    });

    ensureEmptyHint();
})();
</script>
<script src="../assets/site-ui.js"></script>
</body>
</html>

