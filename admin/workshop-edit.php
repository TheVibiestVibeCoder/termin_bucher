<?php
require __DIR__ . '/../includes/config.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$workshop = null;

if ($isEdit) {
    $workshop = get_workshop_by_id($db, $id);
    if (!$workshop) {
        flash('error', 'Workshop nicht gefunden.');
        redirect('workshops.php');
    }
}

$formData = $workshop ?? [
    'title'             => '',
    'slug'              => '',
    'description_short' => '',
    'description'       => '',
    'tag_label'         => '',
    'capacity'        => 30,
    'audiences'       => '',
    'audience_labels' => '',
    'format'          => 'Präsenz od. online',
    'featured'        => 0,
    'sort_order'      => 0,
    'active'          => 1,
    'workshop_type'   => 'auf_anfrage',
    'event_date'      => '',
    'event_date_end'  => '',
    'location'        => '',
    'min_participants'=> 0,
    'price_netto'     => '',
    'price_currency'  => 'EUR',
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify()) {
        $errors[] = 'Ungültige Sitzung.';
    }

    $formData['title']              = trim($_POST['title'] ?? '');
    $formData['slug']               = trim($_POST['slug'] ?? '') ?: slugify($formData['title']);
    $formData['slug']               = trim((string) preg_replace('/[^a-z0-9-]+/', '-', strtolower($formData['slug'])), '-');
    $formData['description_short']  = trim($_POST['description_short'] ?? '');
    $formData['description']        = trim($_POST['description'] ?? '');
    $formData['tag_label']        = trim($_POST['tag_label'] ?? '');
    $formData['capacity']         = max(0, (int) ($_POST['capacity'] ?? 0));
    $formData['audiences']        = trim($_POST['audiences'] ?? '');
    $formData['audience_labels']  = trim($_POST['audience_labels'] ?? '');
    $formData['format']           = trim($_POST['format'] ?? '');
    $formData['featured']         = isset($_POST['featured']) ? 1 : 0;
    $formData['sort_order']       = (int) ($_POST['sort_order'] ?? 0);
    $formData['active']           = isset($_POST['active']) ? 1 : 0;
    $formData['workshop_type']    = ($_POST['workshop_type'] ?? '') === 'open' ? 'open' : 'auf_anfrage';
    $formData['event_date']       = str_replace('T', ' ', trim($_POST['event_date'] ?? ''));
    $formData['event_date_end']   = str_replace('T', ' ', trim($_POST['event_date_end'] ?? ''));
    $formData['location']         = trim($_POST['location'] ?? '');
    $formData['min_participants'] = max(0, (int) ($_POST['min_participants'] ?? 0));
    $rawPrice                     = trim($_POST['price_netto'] ?? '');
    $formData['price_netto']      = $rawPrice === '' ? 0 : max(0, (float) str_replace(',', '.', $rawPrice));
    $formData['price_currency']   = trim($_POST['price_currency'] ?? 'EUR') ?: 'EUR';
    if (!in_array($formData['price_currency'], ['EUR', 'CHF', 'USD'], true)) {
        $formData['price_currency'] = 'EUR';
    }

    if (strlen($formData['title']) < 2) $errors[] = 'Titel ist erforderlich.';
    if (strlen($formData['slug']) < 2)  $errors[] = 'Slug ist erforderlich.';

    if ($formData['workshop_type'] === 'open' && empty($formData['event_date'])) {
        $errors[] = 'Für terminierte Workshops muss ein Startdatum gesetzt werden.';
    }

    if ($formData['capacity'] > 0 && $formData['min_participants'] > $formData['capacity']) {
        $errors[] = 'Mindest-Teilnehmende darf die Kapazitaet nicht uebersteigen.';
    }
    if (!empty($formData['event_date']) && !empty($formData['event_date_end'])) {
        $startTs = strtotime($formData['event_date']);
        $endTs   = strtotime($formData['event_date_end']);
        if ($startTs !== false && $endTs !== false && $endTs < $startTs) {
            $errors[] = 'Enddatum muss nach dem Startdatum liegen.';
        }
    }

    // Check unique slug
    if (empty($errors)) {
        $stmt = $db->prepare('SELECT id FROM workshops WHERE slug = :slug AND id != :id');
        $stmt->bindValue(':slug', $formData['slug'], SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        if ($stmt->execute()->fetchArray()) {
            $errors[] = 'Dieser Slug wird bereits verwendet.';
        }
    }

    if (empty($errors)) {
        if ($isEdit) {
            $stmt = $db->prepare('
                UPDATE workshops SET
                    title = :title, slug = :slug,
                    description_short = :desc_short, description = :desc,
                    tag_label = :tag,
                    capacity = :cap, audiences = :aud, audience_labels = :audl,
                    format = :fmt, featured = :feat, sort_order = :sort, active = :active,
                    workshop_type = :wtype, event_date = :edate, event_date_end = :edate_end,
                    location = :loc, min_participants = :minp,
                    price_netto = :price, price_currency = :currency,
                    updated_at = datetime("now")
                WHERE id = :id
            ');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            $stmt = $db->prepare('
                INSERT INTO workshops
                    (title, slug, description_short, description, tag_label, capacity, audiences, audience_labels,
                     format, featured, sort_order, active, workshop_type, event_date, event_date_end,
                     location, min_participants, price_netto, price_currency)
                VALUES
                    (:title, :slug, :desc_short, :desc, :tag, :cap, :aud, :audl,
                     :fmt, :feat, :sort, :active, :wtype, :edate, :edate_end,
                     :loc, :minp, :price, :currency)
            ');
        }
        $stmt->bindValue(':title',      $formData['title'],             SQLITE3_TEXT);
        $stmt->bindValue(':slug',       $formData['slug'],              SQLITE3_TEXT);
        $stmt->bindValue(':desc_short', $formData['description_short'], SQLITE3_TEXT);
        $stmt->bindValue(':desc',       $formData['description'],       SQLITE3_TEXT);
        $stmt->bindValue(':tag',      $formData['tag_label'],        SQLITE3_TEXT);
        $stmt->bindValue(':cap',      $formData['capacity'],         SQLITE3_INTEGER);
        $stmt->bindValue(':aud',      $formData['audiences'],        SQLITE3_TEXT);
        $stmt->bindValue(':audl',     $formData['audience_labels'],  SQLITE3_TEXT);
        $stmt->bindValue(':fmt',      $formData['format'],           SQLITE3_TEXT);
        $stmt->bindValue(':feat',     $formData['featured'],         SQLITE3_INTEGER);
        $stmt->bindValue(':sort',     $formData['sort_order'],       SQLITE3_INTEGER);
        $stmt->bindValue(':active',   $formData['active'],           SQLITE3_INTEGER);
        $stmt->bindValue(':wtype',    $formData['workshop_type'],    SQLITE3_TEXT);
        $stmt->bindValue(':edate',    $formData['event_date'],       SQLITE3_TEXT);
        $stmt->bindValue(':edate_end',$formData['event_date_end'],   SQLITE3_TEXT);
        $stmt->bindValue(':loc',      $formData['location'],         SQLITE3_TEXT);
        $stmt->bindValue(':minp',     $formData['min_participants'], SQLITE3_INTEGER);
        $stmt->bindValue(':price',    $formData['price_netto'],      SQLITE3_FLOAT);
        $stmt->bindValue(':currency', $formData['price_currency'],   SQLITE3_TEXT);
        $stmt->execute();

        flash('success', $isEdit ? 'Workshop aktualisiert.' : 'Workshop erstellt.');
        redirect('workshops.php');
    }
}

// Format stored datetime-local value for <input type="datetime-local">
function to_datetime_local(string $val): string {
    if (!$val) return '';
    // stored as 'YYYY-MM-DD HH:MM' — datetime-local needs 'YYYY-MM-DDTHH:MM'
    return str_replace(' ', 'T', substr($val, 0, 16));
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Workshop bearbeiten' : 'Neuer Workshop' ?> – Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
    <style>
        .type-toggle {
            display: flex; gap: 0; margin-bottom: 2rem;
            border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden;
            width: fit-content;
        }
        .type-toggle input[type="radio"] { display: none; }
        .type-toggle label {
            padding: 10px 24px; cursor: pointer;
            font-size: 0.88rem; font-weight: 500;
            color: var(--muted); transition: all 0.2s;
            background: transparent;
        }
        .type-toggle input[type="radio"]:checked + label {
            background: #fff; color: #000;
        }
        .section-box {
            background: rgba(255,255,255,0.025);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 1.5rem; margin-bottom: 1.5rem;
        }
        .section-box-title {
            font-size: 0.72rem; font-weight: 600;
            text-transform: uppercase; letter-spacing: 2px;
            color: var(--dim); margin-bottom: 1.25rem;
        }
        .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 1rem; }
        @media (max-width: 600px) { .form-row { grid-template-columns: 1fr; } }
        .hidden { display: none !important; }
        .price-input-wrap { position: relative; }
        .price-input-wrap input { padding-right: 50px; }
        .price-suffix {
            position: absolute; right: 12px; top: 50%;
            transform: translateY(-50%);
            font-size: 0.85rem; color: var(--dim); pointer-events: none;
        }
    </style>
</head>
<body>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1><?= $isEdit ? 'Workshop bearbeiten' : 'Neuer Workshop' ?></h1>
            <a href="workshops.php" class="btn-admin">&larr; Zurück</a>
        </div>

        <?= render_flash() ?>

        <?php if ($errors): ?>
            <div class="flash flash-error">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="max-width:720px;">
            <?= csrf_field() ?>
            <input type="hidden" name="save" value="1">

            <!-- ── Workshop Type Toggle ── -->
            <div class="section-box">
                <div class="section-box-title">Workshop-Typ</div>
                <div class="type-toggle">
                    <input type="radio" name="workshop_type" id="type_anfrage" value="auf_anfrage"
                           <?= $formData['workshop_type'] !== 'open' ? 'checked' : '' ?>>
                    <label for="type_anfrage">Auf Anfrage</label>
                    <input type="radio" name="workshop_type" id="type_open" value="open"
                           <?= $formData['workshop_type'] === 'open' ? 'checked' : '' ?>>
                    <label for="type_open">Terminiert (Open)</label>
                </div>
                <p id="type_hint_anfrage" style="font-size:0.85rem;color:var(--muted);"
                   class="<?= $formData['workshop_type'] === 'open' ? 'hidden' : '' ?>">
                    Kein festes Datum – Interessenten nehmen Kontakt auf. Datum wird als "Auf Anfrage" angezeigt.
                </p>
                <p id="type_hint_open" style="font-size:0.85rem;color:var(--muted);"
                   class="<?= $formData['workshop_type'] !== 'open' ? 'hidden' : '' ?>">
                    Fester Termin und Ort – der Workshop ist öffentlich buchbar. Datum und Standort werden angezeigt.
                </p>
            </div>

            <!-- ── Open Workshop Fields (date + location) ── -->
            <div id="open_fields" class="section-box <?= $formData['workshop_type'] !== 'open' ? 'hidden' : '' ?>">
                <div class="section-box-title">Termin &amp; Ort</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="event_date">Startdatum &amp; -uhrzeit</label>
                        <input type="datetime-local" id="event_date" name="event_date"
                               value="<?= e(to_datetime_local($formData['event_date'])) ?>">
                    </div>
                    <div class="form-group">
                        <label for="event_date_end">Enddatum &amp; -uhrzeit (optional)</label>
                        <input type="datetime-local" id="event_date_end" name="event_date_end"
                               value="<?= e(to_datetime_local($formData['event_date_end'])) ?>">
                    </div>
                </div>
                <div class="form-group">
                    <label for="location">Veranstaltungsort</label>
                    <input type="text" id="location" name="location"
                           placeholder="z.B. Berlin, Konferenzzentrum XY – oder 'Online via Zoom'"
                           value="<?= e($formData['location']) ?>">
                </div>
            </div>

            <!-- ── General Info ── -->
            <div class="section-box">
                <div class="section-box-title">Inhalt</div>
                <div class="form-group">
                    <label for="title">Titel *</label>
                    <input type="text" id="title" name="title" required
                           value="<?= e($formData['title']) ?>"
                           placeholder="z.B. FIMI-Grundlagen: Erkennen & Verstehen">
                </div>
                <div class="form-group">
                    <label for="slug">Slug (URL)</label>
                    <input type="text" id="slug" name="slug"
                           value="<?= e($formData['slug']) ?>"
                           placeholder="Wird automatisch generiert">
                </div>
                <div class="form-group">
                    <label for="description_short">Kurzbeschreibung <span style="color:var(--dim);font-weight:400;">(Übersichtsseite / Karte)</span></label>
                    <textarea id="description_short" name="description_short" rows="3"
                              placeholder="Kurzer Teaser-Text, der auf der Übersichtsseite in der Workshop-Karte erscheint. Empfohlen: max. 160 Zeichen."><?= e($formData['description_short']) ?></textarea>
                    <span id="desc_short_count" style="font-size:0.75rem;color:var(--dim);margin-top:4px;display:block;">
                        <?= mb_strlen($formData['description_short']) ?> / 160 Zeichen
                    </span>
                </div>
                <div class="form-group">
                    <label for="description">Ausführliche Beschreibung <span style="color:var(--dim);font-weight:400;">(Detailseite)</span></label>
                    <textarea id="description" name="description" rows="6"
                              placeholder="Vollständige Beschreibung des Workshop-Inhalts, Lernziele, Ablauf etc. Wird nur auf der Detailseite angezeigt."><?= e($formData['description']) ?></textarea>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="tag_label">Format-Label</label>
                        <input type="text" id="tag_label" name="tag_label"
                               value="<?= e($formData['tag_label']) ?>"
                               placeholder="z.B. Halbtag – 4 h">
                    </div>
                    <div class="form-group">
                        <label for="format">Durchführungsformat</label>
                        <input type="text" id="format" name="format"
                               value="<?= e($formData['format']) ?>"
                               placeholder="z.B. Präsenz od. online">
                    </div>
                </div>
            </div>

            <!-- ── Capacity & Participants ── -->
            <div class="section-box">
                <div class="section-box-title">Kapazität &amp; Teilnehmer</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="capacity">Max. Kapazität (0 = unbegrenzt)</label>
                        <input type="number" id="capacity" name="capacity" min="0"
                               value="<?= (int) $formData['capacity'] ?>">
                    </div>
                    <div class="form-group">
                        <label for="min_participants">Mindest-Teilnehmende (0 = keine Mindestanzahl)</label>
                        <input type="number" id="min_participants" name="min_participants" min="0"
                               value="<?= (int) $formData['min_participants'] ?>">
                        <span style="font-size:0.78rem;color:var(--dim);margin-top:4px;display:block;">
                            Workshop findet nur statt, wenn diese Anzahl erreicht wird.
                        </span>
                    </div>
                </div>
            </div>

            <!-- ── Price ── -->
            <div class="section-box">
                <div class="section-box-title">Preis</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="price_netto">Preis pro Person (Netto) – leer oder 0 = kostenlos / auf Anfrage</label>
                        <div class="price-input-wrap">
                            <input type="text" id="price_netto" name="price_netto"
                                   value="<?= $formData['price_netto'] > 0 ? number_format((float)$formData['price_netto'], 2, ',', '.') : '' ?>"
                                   placeholder="z.B. 490,00">
                            <span class="price-suffix"><?= e($formData['price_currency']) ?></span>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="price_currency">Währung</label>
                        <select id="price_currency" name="price_currency">
                            <option value="EUR" <?= $formData['price_currency'] === 'EUR' ? 'selected' : '' ?>>EUR (€)</option>
                            <option value="CHF" <?= $formData['price_currency'] === 'CHF' ? 'selected' : '' ?>>CHF</option>
                            <option value="USD" <?= $formData['price_currency'] === 'USD' ? 'selected' : '' ?>>USD ($)</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- ── Audience & Visibility ── -->
            <div class="section-box">
                <div class="section-box-title">Zielgruppen &amp; Sichtbarkeit</div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="audiences">Zielgruppen-Schlüssel (kommagetrennt)</label>
                        <input type="text" id="audiences" name="audiences"
                               value="<?= e($formData['audiences']) ?>"
                               placeholder="unternehmen,ngo,verwaltung,bildung">
                    </div>
                    <div class="form-group">
                        <label for="audience_labels">Zielgruppen-Anzeigenamen (kommagetrennt)</label>
                        <input type="text" id="audience_labels" name="audience_labels"
                               value="<?= e($formData['audience_labels']) ?>"
                               placeholder="Unternehmen,NGOs,Verwaltung,Bildung">
                    </div>
                </div>
                <div class="form-row">
                    <div class="form-group">
                        <label for="sort_order">Reihenfolge</label>
                        <input type="number" id="sort_order" name="sort_order"
                               value="<?= (int) $formData['sort_order'] ?>">
                    </div>
                </div>
                <div style="display:flex;gap:2rem;margin-top:0.5rem;">
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                        <input type="checkbox" name="featured" value="1"
                               <?= $formData['featured'] ? 'checked' : '' ?> style="width:auto;">
                        Empfohlen (Featured)
                    </label>
                    <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                        <input type="checkbox" name="active" value="1"
                               <?= $formData['active'] ? 'checked' : '' ?> style="width:auto;">
                        Aktiv (sichtbar)
                    </label>
                </div>
            </div>

            <button type="submit" class="btn-submit" style="max-width:300px;">
                <?= $isEdit ? 'Speichern' : 'Erstellen' ?> &rarr;
            </button>
        </form>
    </div>
</div>

<script>
// ── Type toggle ──────────────────────────────────────────────────────────────
const radios = document.querySelectorAll('input[name="workshop_type"]');
const openFields    = document.getElementById('open_fields');
const hintAnfrage   = document.getElementById('type_hint_anfrage');
const hintOpen      = document.getElementById('type_hint_open');
const eventDateInput = document.getElementById('event_date');

function updateTypeUI() {
    const isOpen = document.getElementById('type_open').checked;
    openFields.classList.toggle('hidden', !isOpen);
    hintAnfrage.classList.toggle('hidden', isOpen);
    hintOpen.classList.toggle('hidden', !isOpen);
    if (isOpen) {
        eventDateInput.setAttribute('required', 'required');
    } else {
        eventDateInput.removeAttribute('required');
    }
}

radios.forEach(r => r.addEventListener('change', updateTypeUI));
updateTypeUI();

// ── Auto-generate slug from title ────────────────────────────────────────────
const titleInput = document.getElementById('title');
const slugInput  = document.getElementById('slug');
<?php if (!$isEdit): ?>
titleInput.addEventListener('input', () => {
    if (!slugInput.dataset.manual) {
        slugInput.value = titleInput.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
});
slugInput.addEventListener('input', () => { slugInput.dataset.manual = '1'; });
<?php endif; ?>

// ── Short description character counter ─────────────────────────────────────
const descShort = document.getElementById('description_short');
const descShortCount = document.getElementById('desc_short_count');
if (descShort && descShortCount) {
    descShort.addEventListener('input', () => {
        const len = descShort.value.length;
        descShortCount.textContent = len + ' / 160 Zeichen';
        descShortCount.style.color = len > 160 ? '#e74c3c' : 'var(--dim)';
    });
}

// ── Update currency suffix live ──────────────────────────────────────────────
document.getElementById('price_currency').addEventListener('change', function () {
    document.querySelector('.price-suffix').textContent = this.value;
});
</script>

</body>
</html>
