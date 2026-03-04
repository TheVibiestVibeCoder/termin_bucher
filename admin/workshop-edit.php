<?php
require __DIR__ . '/../includes/config.php';
require_admin();

function to_datetime_local(string $val): string {
    if ($val === '') {
        return '';
    }

    return str_replace(' ', 'T', substr($val, 0, 16));
}

function normalize_datetime_input(?string $raw): string {
    $value = trim((string) $raw);
    if ($value === '') {
        return '';
    }

    return str_replace('T', ' ', $value);
}

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$workshop = null;

if ($isEdit) {
    $workshop = get_workshop_by_id($db, $id);
    if (!$workshop) {
        flash('error', 'Workshop nicht gefunden.');
        redirect(admin_url('workshops'));
    }
}

$formData = $workshop ?? [
    'title'             => '',
    'slug'              => '',
    'description_short' => '',
    'description'       => '',
    'tag_label'         => '',
    'capacity'          => 30,
    'audiences'         => '',
    'audience_labels'   => '',
    'format'            => 'Praesenz oder online',
    'featured'          => 0,
    'sort_order'        => 0,
    'active'            => 1,
    'workshop_type'     => 'auf_anfrage',
    'event_date'        => '',
    'event_date_end'    => '',
    'location'          => '',
    'min_participants'  => 0,
    'price_netto'       => '',
    'price_currency'    => 'EUR',
];

$occurrenceRows = [];
if ($isEdit) {
    $occurrenceRows = get_workshop_occurrences($db, $id, true);
}
if (empty($occurrenceRows) && (($formData['event_date'] ?? '') !== '')) {
    $occurrenceRows[] = [
        'id' => 0,
        'start_at' => (string) ($formData['event_date'] ?? ''),
        'end_at' => (string) ($formData['event_date_end'] ?? ''),
        'sort_order' => 0,
        'active' => 1,
    ];
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify()) {
        $errors[] = 'Ungueltige Sitzung.';
    }

    $formData['title']              = trim((string) ($_POST['title'] ?? ''));
    $formData['slug']               = trim((string) ($_POST['slug'] ?? '')) ?: slugify($formData['title']);
    $formData['slug']               = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower((string) $formData['slug'])), '-');
    $formData['description_short']  = trim((string) ($_POST['description_short'] ?? ''));
    $formData['description']        = trim((string) ($_POST['description'] ?? ''));
    $formData['tag_label']          = trim((string) ($_POST['tag_label'] ?? ''));
    $formData['capacity']           = max(0, (int) ($_POST['capacity'] ?? 0));
    $formData['audiences']          = trim((string) ($_POST['audiences'] ?? ''));
    $formData['audience_labels']    = trim((string) ($_POST['audience_labels'] ?? ''));
    $formData['format']             = trim((string) ($_POST['format'] ?? ''));
    $formData['featured']           = isset($_POST['featured']) ? 1 : 0;
    $formData['sort_order']         = (int) ($_POST['sort_order'] ?? 0);
    $formData['active']             = isset($_POST['active']) ? 1 : 0;
    $formData['workshop_type']      = (($_POST['workshop_type'] ?? '') === 'open') ? 'open' : 'auf_anfrage';
    $formData['location']           = trim((string) ($_POST['location'] ?? ''));
    $formData['min_participants']   = max(0, (int) ($_POST['min_participants'] ?? 0));

    $rawPrice = trim((string) ($_POST['price_netto'] ?? ''));
    $formData['price_netto'] = $rawPrice === '' ? 0 : max(0, (float) str_replace(',', '.', $rawPrice));

    $formData['price_currency'] = trim((string) ($_POST['price_currency'] ?? 'EUR')) ?: 'EUR';
    if (!in_array($formData['price_currency'], ['EUR', 'CHF', 'USD'], true)) {
        $formData['price_currency'] = 'EUR';
    }

    $postedOccurrenceIds = array_values((array) ($_POST['occurrence_id'] ?? []));
    $postedOccurrenceStarts = array_values((array) ($_POST['occurrence_start'] ?? []));
    $postedOccurrenceEnds = array_values((array) ($_POST['occurrence_end'] ?? []));

    $occurrenceRows = [];
    $occurrenceCount = max(count($postedOccurrenceIds), count($postedOccurrenceStarts), count($postedOccurrenceEnds));

    for ($i = 0; $i < $occurrenceCount; $i++) {
        $occurrenceId = (int) ($postedOccurrenceIds[$i] ?? 0);
        $startAt = normalize_datetime_input($postedOccurrenceStarts[$i] ?? '');
        $endAt = normalize_datetime_input($postedOccurrenceEnds[$i] ?? '');

        if ($startAt === '' && $endAt === '') {
            continue;
        }

        if ($startAt === '') {
            $errors[] = 'Termin ' . ($i + 1) . ': Startdatum ist erforderlich.';
            continue;
        }

        $startTs = strtotime($startAt);
        if ($startTs === false) {
            $errors[] = 'Termin ' . ($i + 1) . ': Startdatum ist ungueltig.';
            continue;
        }

        if ($endAt !== '') {
            $endTs = strtotime($endAt);
            if ($endTs === false) {
                $errors[] = 'Termin ' . ($i + 1) . ': Enddatum ist ungueltig.';
                continue;
            }
            if ($endTs < $startTs) {
                $errors[] = 'Termin ' . ($i + 1) . ': Enddatum muss nach dem Startdatum liegen.';
                continue;
            }
        }

        $occurrenceRows[] = [
            'id' => $occurrenceId,
            'start_at' => $startAt,
            'end_at' => $endAt,
            'sort_order' => count($occurrenceRows),
            'active' => 1,
        ];
    }

    if (strlen($formData['title']) < 2) {
        $errors[] = 'Titel ist erforderlich.';
    }
    if (strlen($formData['slug']) < 2) {
        $errors[] = 'Slug ist erforderlich.';
    }

    if ($formData['workshop_type'] === 'open' && empty($occurrenceRows)) {
        $errors[] = 'Fuer terminierte Workshops ist mindestens ein Termin erforderlich.';
    }

    if ($formData['capacity'] > 0 && $formData['min_participants'] > $formData['capacity']) {
        $errors[] = 'Mindest-Teilnehmende darf die Kapazitaet nicht uebersteigen.';
    }

    if ($formData['workshop_type'] === 'open' && !empty($occurrenceRows)) {
        usort($occurrenceRows, static fn(array $a, array $b): int => strcmp((string) ($a['start_at'] ?? ''), (string) ($b['start_at'] ?? '')));
        $firstOccurrence = $occurrenceRows[0];
        $formData['event_date'] = (string) ($firstOccurrence['start_at'] ?? '');
        $formData['event_date_end'] = (string) ($firstOccurrence['end_at'] ?? '');

        foreach ($occurrenceRows as $idx => $row) {
            $occurrenceRows[$idx]['sort_order'] = $idx;
        }
    } else {
        $formData['event_date'] = '';
        $formData['event_date_end'] = '';
    }

    if (empty($errors)) {
        $stmt = $db->prepare('SELECT id FROM workshops WHERE slug = :slug AND id != :id LIMIT 1');
        $stmt->bindValue(':slug', $formData['slug'], SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        if ($stmt->execute()->fetchArray(SQLITE3_ASSOC)) {
            $errors[] = 'Dieser Slug wird bereits verwendet.';
        }
    }

    if (empty($errors)) {
        $inTransaction = false;

        try {
            $db->exec('BEGIN IMMEDIATE');
            $inTransaction = true;

            if ($isEdit) {
                $stmt = $db->prepare('
                    UPDATE workshops SET
                        title = :title,
                        slug = :slug,
                        description_short = :desc_short,
                        description = :desc,
                        tag_label = :tag,
                        capacity = :cap,
                        audiences = :aud,
                        audience_labels = :audl,
                        format = :fmt,
                        featured = :feat,
                        sort_order = :sort,
                        active = :active,
                        workshop_type = :wtype,
                        event_date = :edate,
                        event_date_end = :edate_end,
                        location = :loc,
                        min_participants = :minp,
                        price_netto = :price,
                        price_currency = :currency,
                        updated_at = datetime("now")
                    WHERE id = :id
                ');
                $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
            } else {
                $stmt = $db->prepare('
                    INSERT INTO workshops (
                        title, slug, description_short, description, tag_label,
                        capacity, audiences, audience_labels, format, featured,
                        sort_order, active, workshop_type, event_date, event_date_end,
                        location, min_participants, price_netto, price_currency
                    ) VALUES (
                        :title, :slug, :desc_short, :desc, :tag,
                        :cap, :aud, :audl, :fmt, :feat,
                        :sort, :active, :wtype, :edate, :edate_end,
                        :loc, :minp, :price, :currency
                    )
                ');
            }

            $stmt->bindValue(':title', $formData['title'], SQLITE3_TEXT);
            $stmt->bindValue(':slug', $formData['slug'], SQLITE3_TEXT);
            $stmt->bindValue(':desc_short', $formData['description_short'], SQLITE3_TEXT);
            $stmt->bindValue(':desc', $formData['description'], SQLITE3_TEXT);
            $stmt->bindValue(':tag', $formData['tag_label'], SQLITE3_TEXT);
            $stmt->bindValue(':cap', $formData['capacity'], SQLITE3_INTEGER);
            $stmt->bindValue(':aud', $formData['audiences'], SQLITE3_TEXT);
            $stmt->bindValue(':audl', $formData['audience_labels'], SQLITE3_TEXT);
            $stmt->bindValue(':fmt', $formData['format'], SQLITE3_TEXT);
            $stmt->bindValue(':feat', $formData['featured'], SQLITE3_INTEGER);
            $stmt->bindValue(':sort', $formData['sort_order'], SQLITE3_INTEGER);
            $stmt->bindValue(':active', $formData['active'], SQLITE3_INTEGER);
            $stmt->bindValue(':wtype', $formData['workshop_type'], SQLITE3_TEXT);
            $stmt->bindValue(':edate', $formData['event_date'], SQLITE3_TEXT);
            $stmt->bindValue(':edate_end', $formData['event_date_end'], SQLITE3_TEXT);
            $stmt->bindValue(':loc', $formData['location'], SQLITE3_TEXT);
            $stmt->bindValue(':minp', $formData['min_participants'], SQLITE3_INTEGER);
            $stmt->bindValue(':price', (float) $formData['price_netto'], SQLITE3_FLOAT);
            $stmt->bindValue(':currency', $formData['price_currency'], SQLITE3_TEXT);

            if ($stmt->execute() === false) {
                throw new RuntimeException('Workshop konnte nicht gespeichert werden.');
            }

            $savedWorkshopId = $isEdit ? $id : (int) $db->lastInsertRowID();

            if ($formData['workshop_type'] === 'open') {
                $existingIds = [];
                $existingStmt = $db->prepare('SELECT id FROM workshop_occurrences WHERE workshop_id = :wid');
                $existingStmt->bindValue(':wid', $savedWorkshopId, SQLITE3_INTEGER);
                $existingRes = $existingStmt->execute();
                while ($existingRow = $existingRes->fetchArray(SQLITE3_ASSOC)) {
                    $existingIds[(int) $existingRow['id']] = true;
                }

                $keptIds = [];
                foreach ($occurrenceRows as $index => $occurrence) {
                    $occurrenceId = (int) ($occurrence['id'] ?? 0);
                    $startAt = (string) ($occurrence['start_at'] ?? '');
                    $endAt = (string) ($occurrence['end_at'] ?? '');

                    if ($occurrenceId > 0 && isset($existingIds[$occurrenceId])) {
                        $u = $db->prepare('
                            UPDATE workshop_occurrences
                            SET start_at = :start_at,
                                end_at = :end_at,
                                sort_order = :sort_order,
                                active = 1,
                                updated_at = datetime("now")
                            WHERE id = :id AND workshop_id = :wid
                        ');
                        $u->bindValue(':start_at', $startAt, SQLITE3_TEXT);
                        $u->bindValue(':end_at', $endAt, SQLITE3_TEXT);
                        $u->bindValue(':sort_order', $index, SQLITE3_INTEGER);
                        $u->bindValue(':id', $occurrenceId, SQLITE3_INTEGER);
                        $u->bindValue(':wid', $savedWorkshopId, SQLITE3_INTEGER);
                        if ($u->execute() === false) {
                            throw new RuntimeException('Termin konnte nicht aktualisiert werden.');
                        }
                        $keptIds[] = $occurrenceId;
                    } else {
                        $iStmt = $db->prepare('
                            INSERT INTO workshop_occurrences (workshop_id, start_at, end_at, sort_order, active)
                            VALUES (:wid, :start_at, :end_at, :sort_order, 1)
                        ');
                        $iStmt->bindValue(':wid', $savedWorkshopId, SQLITE3_INTEGER);
                        $iStmt->bindValue(':start_at', $startAt, SQLITE3_TEXT);
                        $iStmt->bindValue(':end_at', $endAt, SQLITE3_TEXT);
                        $iStmt->bindValue(':sort_order', $index, SQLITE3_INTEGER);
                        if ($iStmt->execute() === false) {
                            throw new RuntimeException('Termin konnte nicht gespeichert werden.');
                        }
                        $keptIds[] = (int) $db->lastInsertRowID();
                    }
                }

                $deactivateSql = 'UPDATE workshop_occurrences SET active = 0, updated_at = datetime("now") WHERE workshop_id = ' . (int) $savedWorkshopId;
                if (!empty($keptIds)) {
                    $deactivateSql .= ' AND id NOT IN (' . implode(',', array_map('intval', $keptIds)) . ')';
                }
                $db->exec($deactivateSql);
            } else {
                $db->exec('UPDATE workshop_occurrences SET active = 0, updated_at = datetime("now") WHERE workshop_id = ' . (int) $savedWorkshopId);
            }

            $db->exec('COMMIT');
            $inTransaction = false;

            flash('success', $isEdit ? 'Workshop aktualisiert.' : 'Workshop erstellt.');
            redirect(admin_url('workshops'));
        } catch (Throwable $e) {
            if ($inTransaction) {
                $db->exec('ROLLBACK');
            }
            $errors[] = 'Technischer Fehler beim Speichern. Bitte erneut versuchen.';
        }
    }

    if ($formData['workshop_type'] !== 'open') {
        $occurrenceRows = [];
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
    <title><?= $isEdit ? 'Workshop bearbeiten' : 'Neuer Workshop' ?> - Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
    <style>
        .type-toggle { display:flex; gap:0; margin-bottom:2rem; border:1px solid var(--border); border-radius:var(--radius); overflow:hidden; width:fit-content; flex-wrap:wrap; }
        .type-toggle input[type="radio"] { display:none; }
        .type-toggle label { padding:10px 24px; cursor:pointer; font-size:0.88rem; font-weight:500; color:var(--muted); transition:all 0.2s; background:transparent; }
        .type-toggle input[type="radio"]:checked + label { background:var(--btn-hover-bg); color:var(--btn-hover-text); }
        .section-box { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius); padding:1.5rem; margin-bottom:1.5rem; }
        .section-box-title { font-size:0.72rem; font-weight:600; text-transform:uppercase; letter-spacing:2px; color:var(--dim); margin-bottom:1.25rem; }
        .form-row { display:grid; grid-template-columns:1fr 1fr; gap:1rem; }
        .hidden { display:none !important; }
        .price-input-wrap { position:relative; }
        .price-input-wrap input { padding-right:50px; }
        .price-suffix { position:absolute; right:12px; top:50%; transform:translateY(-50%); font-size:0.85rem; color:var(--dim); pointer-events:none; }
        .occurrence-editor { display:flex; flex-direction:column; gap:0.8rem; margin-bottom:0.85rem; }
        .occurrence-row { display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr) auto; gap:0.65rem; align-items:end; padding:0.75rem; border:1px solid var(--border); border-radius:var(--radius); background:var(--surface-soft); }
        .occurrence-row .form-group { margin-bottom:0; }
        .occurrence-remove { min-width:42px; width:42px; height:42px; border-radius:10px; padding:0; line-height:1; font-size:1.1rem; justify-content:center; }
        @media (max-width:760px) {
            .form-row,.occurrence-row { grid-template-columns:1fr; }
            .occurrence-remove { width:100%; min-width:100%; }
        }
    </style>
</head>
<body class="admin-page">
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
<div class="admin-layout">
    <?php include __DIR__ . '/sidebar.php'; ?>
    <div class="admin-main">
        <div class="admin-header">
            <h1><?= $isEdit ? 'Workshop bearbeiten' : 'Neuer Workshop' ?></h1>
            <a href="<?= e(admin_url('workshops')) ?>" class="btn-admin">&larr; Zurueck</a>
        </div>
        <?= render_flash() ?>
        <?php if ($errors): ?>
            <div class="flash flash-error">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="max-width:780px;">
            <?= csrf_field() ?>
            <input type="hidden" name="save" value="1">
            <div class="section-box">
                <div class="section-box-title">Workshop-Typ</div>
                <div class="type-toggle">
                    <input type="radio" name="workshop_type" id="type_anfrage" value="auf_anfrage" <?= $formData['workshop_type'] !== 'open' ? 'checked' : '' ?>>
                    <label for="type_anfrage">Auf Anfrage</label>
                    <input type="radio" name="workshop_type" id="type_open" value="open" <?= $formData['workshop_type'] === 'open' ? 'checked' : '' ?>>
                    <label for="type_open">Terminiert</label>
                </div>
                <p id="type_hint_anfrage" style="font-size:0.85rem;color:var(--muted);" class="<?= $formData['workshop_type'] === 'open' ? 'hidden' : '' ?>">
                    Kein festes Datum. Interessierte nehmen Kontakt auf.
                </p>
                <p id="type_hint_open" style="font-size:0.85rem;color:var(--muted);" class="<?= $formData['workshop_type'] !== 'open' ? 'hidden' : '' ?>">
                    Ein Workshop kann mehrere Termine haben. Buchungen werden pro Termin gefuehrt.
                </p>
            </div>

            <div id="open_fields" class="section-box <?= $formData['workshop_type'] !== 'open' ? 'hidden' : '' ?>">
                <div class="section-box-title">Termine und Ort</div>
                <div id="occurrence_editor" class="occurrence-editor">
                    <?php foreach ($occurrenceRows as $occurrence): ?>
                    <div class="occurrence-row" data-occurrence-row>
                        <input type="hidden" name="occurrence_id[]" value="<?= (int) ($occurrence['id'] ?? 0) ?>">
                        <div class="form-group">
                            <label>Startdatum und Uhrzeit *</label>
                            <input type="datetime-local" name="occurrence_start[]" value="<?= e(to_datetime_local((string) ($occurrence['start_at'] ?? ''))) ?>" data-occurrence-start>
                        </div>
                        <div class="form-group">
                            <label>Enddatum und Uhrzeit (optional)</label>
                            <input type="datetime-local" name="occurrence_end[]" value="<?= e(to_datetime_local((string) ($occurrence['end_at'] ?? ''))) ?>">
                        </div>
                        <button type="button" class="btn-admin btn-danger occurrence-remove" data-occurrence-remove aria-label="Termin entfernen">&times;</button>
                    </div>
                    <?php endforeach; ?>
                </div>
                <button type="button" id="add_occurrence_btn" class="btn-admin">+ Termin hinzufuegen</button>

                <div class="form-group" style="margin-top:1rem;">
                    <label for="location">Veranstaltungsort</label>
                    <input type="text" id="location" name="location" placeholder="z.B. Berlin, Konferenzzentrum XY oder Online" value="<?= e((string) ($formData['location'] ?? '')) ?>">
                </div>
            </div>

            <div class="section-box">
                <div class="section-box-title">Inhalt</div>
                <div class="form-group">
                    <label for="title">Titel *</label>
                    <input type="text" id="title" name="title" required value="<?= e($formData['title']) ?>" placeholder="z.B. FIMI-Grundlagen: Erkennen und Verstehen">
                </div>
                <div class="form-group">
                    <label for="slug">Slug (URL)</label>
                    <input type="text" id="slug" name="slug" value="<?= e($formData['slug']) ?>" placeholder="Wird automatisch generiert">
                </div>
                <div class="form-group">
                    <label for="description_short">Kurzbeschreibung <span style="color:var(--dim);font-weight:400;">(Uebersicht/Karte)</span></label>
                    <textarea id="description_short" name="description_short" rows="3" placeholder="Kurzer Teasertext fuer die Karte. Empfohlen: max. 160 Zeichen."><?= e($formData['description_short']) ?></textarea>
                    <span id="desc_short_count" style="font-size:0.75rem;color:var(--dim);margin-top:4px;display:block;"><?= mb_strlen((string) $formData['description_short']) ?> / 160 Zeichen</span>
                </div>
                <div class="form-group">
                    <label for="description">Ausfuehrliche Beschreibung <span style="color:var(--dim);font-weight:400;">(Detailseite)</span></label>
                    <textarea id="description" name="description" rows="6" placeholder="Vollstaendige Beschreibung des Workshop-Inhalts."><?= e($formData['description']) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="tag_label">Format-Label</label>
                        <input type="text" id="tag_label" name="tag_label" value="<?= e((string) $formData['tag_label']) ?>" placeholder="z.B. Halbtag - 4 h">
                    </div>
                    <div class="form-group">
                        <label for="format">Durchfuehrungsformat</label>
                        <input type="text" id="format" name="format" value="<?= e((string) $formData['format']) ?>" placeholder="z.B. Praesenz oder online">
                    </div>
                </div>
            </div>

            <div class="section-box">
                <div class="section-box-title">Kapazitaet und Teilnehmende</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="capacity">Max. Kapazitaet (0 = unbegrenzt)</label>
                        <input type="number" id="capacity" name="capacity" min="0" value="<?= (int) $formData['capacity'] ?>">
                    </div>
                    <div class="form-group">
                        <label for="min_participants">Mindest-Teilnehmende (0 = keine Mindestanzahl)</label>
                        <input type="number" id="min_participants" name="min_participants" min="0" value="<?= (int) $formData['min_participants'] ?>">
                    </div>
                </div>
            </div>

            <div class="section-box">
                <div class="section-box-title">Preis</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="price_netto">Preis pro Person (Netto) - leer oder 0 = kostenlos/auf Anfrage</label>
                        <div class="price-input-wrap">
                            <input type="text" id="price_netto" name="price_netto" value="<?= ((float) $formData['price_netto'] > 0) ? number_format((float) $formData['price_netto'], 2, ',', '.') : '' ?>" placeholder="z.B. 490,00">
                            <span class="price-suffix"><?= e((string) $formData['price_currency']) ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="price_currency">Waehrung</label>
                        <select id="price_currency" name="price_currency">
                            <option value="EUR" <?= $formData['price_currency'] === 'EUR' ? 'selected' : '' ?>>EUR (&euro;)</option>
                            <option value="CHF" <?= $formData['price_currency'] === 'CHF' ? 'selected' : '' ?>>CHF</option>
                            <option value="USD" <?= $formData['price_currency'] === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        </select>
                    </div>
                </div>
            </div>

            <div class="section-box">
                <div class="section-box-title">Zielgruppen und Sichtbarkeit</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="audiences">Zielgruppen-Schluessel (kommagetrennt)</label>
                        <input type="text" id="audiences" name="audiences" value="<?= e((string) $formData['audiences']) ?>" placeholder="unternehmen,ngo,verwaltung,bildung">
                    </div>
                    <div class="form-group">
                        <label for="audience_labels">Zielgruppen-Anzeigenamen (kommagetrennt)</label>
                        <input type="text" id="audience_labels" name="audience_labels" value="<?= e((string) $formData['audience_labels']) ?>" placeholder="Unternehmen,NGOs,Verwaltung,Bildung">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="sort_order">Reihenfolge</label>
                        <input type="number" id="sort_order" name="sort_order" value="<?= (int) $formData['sort_order'] ?>">
                    </div>
                </div>
                <div style="display:flex;gap:2rem;margin-top:0.5rem;flex-wrap:wrap;">
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                        <input type="checkbox" name="featured" value="1" <?= $formData['featured'] ? 'checked' : '' ?> style="width:auto;"> Empfohlen (Featured)
                    </label>
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                        <input type="checkbox" name="active" value="1" <?= $formData['active'] ? 'checked' : '' ?> style="width:auto;"> Aktiv (sichtbar)
                    </label>
                </div>
            </div>

            <button type="submit" class="btn-submit" style="max-width:320px;">
                <?= $isEdit ? 'Speichern' : 'Erstellen' ?> &rarr;
            </button>
        </form>
    </div>
</div>

<template id="occurrence_row_template">
    <div class="occurrence-row" data-occurrence-row>
        <input type="hidden" name="occurrence_id[]" value="0">
        <div class="form-group">
            <label>Startdatum und Uhrzeit *</label>
            <input type="datetime-local" name="occurrence_start[]" data-occurrence-start>
        </div>
        <div class="form-group">
            <label>Enddatum und Uhrzeit (optional)</label>
            <input type="datetime-local" name="occurrence_end[]">
        </div>
        <button type="button" class="btn-admin btn-danger occurrence-remove" data-occurrence-remove aria-label="Termin entfernen">&times;</button>
    </div>
</template>

<script>
const radios = document.querySelectorAll('input[name="workshop_type"]');
const openFields = document.getElementById('open_fields');
const hintAnfrage = document.getElementById('type_hint_anfrage');
const hintOpen = document.getElementById('type_hint_open');
const occurrenceEditor = document.getElementById('occurrence_editor');
const addOccurrenceBtn = document.getElementById('add_occurrence_btn');
const occurrenceTemplate = document.getElementById('occurrence_row_template');

function createOccurrenceRow() {
    if (!occurrenceTemplate) {
        return null;
    }
    const fragment = occurrenceTemplate.content.cloneNode(true);
    return fragment.firstElementChild;
}

function ensureAtLeastOneOccurrenceRow() {
    if (!occurrenceEditor) {
        return;
    }
    if (occurrenceEditor.querySelectorAll('[data-occurrence-row]').length === 0) {
        const row = createOccurrenceRow();
        if (row) {
            occurrenceEditor.appendChild(row);
        }
    }
}

function updateOccurrenceRequiredState() {
    if (!occurrenceEditor) {
        return;
    }
    const isOpen = document.getElementById('type_open').checked;
    const starts = occurrenceEditor.querySelectorAll('input[data-occurrence-start]');
    starts.forEach((input, idx) => {
        if (isOpen && idx === 0) {
            input.setAttribute('required', 'required');
        } else {
            input.removeAttribute('required');
        }
    });
}

function updateTypeUI() {
    const isOpen = document.getElementById('type_open').checked;
    openFields.classList.toggle('hidden', !isOpen);
    hintAnfrage.classList.toggle('hidden', isOpen);
    hintOpen.classList.toggle('hidden', !isOpen);

    if (isOpen) {
        ensureAtLeastOneOccurrenceRow();
    }

    updateOccurrenceRequiredState();
}

if (addOccurrenceBtn && occurrenceEditor) {
    addOccurrenceBtn.addEventListener('click', function () {
        const row = createOccurrenceRow();
        if (!row) {
            return;
        }
        occurrenceEditor.appendChild(row);
        updateOccurrenceRequiredState();
        const startInput = row.querySelector('input[data-occurrence-start]');
        if (startInput) {
            startInput.focus({ preventScroll: true });
        }
    });

    occurrenceEditor.addEventListener('click', function (event) {
        const removeButton = event.target.closest('[data-occurrence-remove]');
        if (!removeButton) {
            return;
        }
        const row = removeButton.closest('[data-occurrence-row]');
        if (!row) {
            return;
        }
        row.remove();
        if (document.getElementById('type_open').checked) {
            ensureAtLeastOneOccurrenceRow();
        }
        updateOccurrenceRequiredState();
    });
}

radios.forEach(r => r.addEventListener('change', updateTypeUI));
updateTypeUI();

const titleInput = document.getElementById('title');
const slugInput = document.getElementById('slug');
<?php if (!$isEdit): ?>
titleInput.addEventListener('input', () => {
    if (!slugInput.dataset.manual) {
        slugInput.value = titleInput.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
});
slugInput.addEventListener('input', () => { slugInput.dataset.manual = '1'; });
<?php endif; ?>

const descShort = document.getElementById('description_short');
const descShortCount = document.getElementById('desc_short_count');
if (descShort && descShortCount) {
    descShort.addEventListener('input', () => {
        const len = descShort.value.length;
        descShortCount.textContent = len + ' / 160 Zeichen';
        descShortCount.style.color = len > 160 ? '#e74c3c' : 'var(--dim)';
    });
}

const currencySelect = document.getElementById('price_currency');
if (currencySelect) {
    currencySelect.addEventListener('change', function () {
        const suffix = document.querySelector('.price-suffix');
        if (suffix) {
            suffix.textContent = this.value;
        }
    });
}
</script>

<script src="/assets/site-ui.js"></script>
</body>
</html>

