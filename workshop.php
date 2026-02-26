<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/email.php';

$slug = trim($_GET['slug'] ?? '');
if (!$slug) redirect('index.php');

$workshop = get_workshop_by_slug($db, $slug);
if (!$workshop) {
    http_response_code(404);
    redirect('index.php');
}

$booked       = count_confirmed_bookings($db, $workshop['id']);
$capacity     = (int) $workshop['capacity'];
$spotsLeft    = $capacity > 0 ? max(0, $capacity - $booked) : null;
$fillPct      = ($capacity > 0) ? min(100, round(($booked / $capacity) * 100)) : 0;
$fillClass    = ($fillPct >= 85) ? 'high' : (($fillPct >= 50) ? 'medium' : '');
$isFull       = $capacity > 0 && $spotsLeft <= 0;
$audLabels    = array_filter(array_map('trim', explode(',', $workshop['audience_labels'])));
$isOpen       = ($workshop['workshop_type'] ?? 'auf_anfrage') === 'open';
$price        = (float) ($workshop['price_netto'] ?? 0);
$currency     = $workshop['price_currency'] ?? 'EUR';
$minP         = (int) ($workshop['min_participants'] ?? 0);
$minPct       = ($minP > 0 && $capacity > 0) ? min(100, round(($minP / $capacity) * 100)) : 0;
$belowMin     = ($minP > 0 && $capacity > 0 && $booked < $minP);
$aboveMin     = ($minP > 0 && $capacity > 0 && $booked >= $minP);
$eventDate    = $workshop['event_date']     ?? '';
$eventDateEnd = $workshop['event_date_end'] ?? '';
$location     = $workshop['location']       ?? '';

$errors           = [];
$formData         = ['name' => '', 'email' => '', 'organization' => '', 'phone' => '', 'participants' => 1, 'message' => '', 'booking_mode' => 'group'];
$participantNames  = [];
$participantEmails = [];
$maxLen = [
    'name'         => 120,
    'email'        => 254,
    'organization' => 180,
    'phone'        => 60,
    'message'      => 3000,
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['book'])) {
    if (!csrf_verify()) {
        $errors[] = 'Ungültige Sitzung. Bitte versuchen Sie es erneut.';
    }
    if (!rate_limit('booking', 3)) {
        $errors[] = 'Zu viele Anfragen. Bitte warten Sie einen Moment.';
    }

    $formData['name']         = trim($_POST['name'] ?? '');
    $formData['email']        = trim($_POST['email'] ?? '');
    $formData['organization'] = trim($_POST['organization'] ?? '');
    $formData['phone']        = trim($_POST['phone'] ?? '');
    $formData['participants'] = max(1, (int) ($_POST['participants'] ?? 1));
    $formData['message']      = trim($_POST['message'] ?? '');
    $formData['booking_mode'] = ($_POST['booking_mode'] ?? 'group') === 'individual' ? 'individual' : 'group';

    if ($formData['booking_mode'] === 'individual') {
        $participantNames  = array_map('trim', (array)($_POST['participant_name']  ?? []));
        $participantEmails = array_map('trim', (array)($_POST['participant_email'] ?? []));
    }

    if (strlen($formData['name']) < 2) $errors[] = 'Bitte geben Sie Ihren Namen ein.';
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    if ($formData['participants'] < 1 || $formData['participants'] > 50) $errors[] = 'Ungültige Teilnehmerzahl.';

    if (mb_strlen($formData['name']) > $maxLen['name']) $errors[] = 'Name ist zu lang.';
    if (mb_strlen($formData['email']) > $maxLen['email']) $errors[] = 'E-Mail-Adresse ist zu lang.';
    if (mb_strlen($formData['organization']) > $maxLen['organization']) $errors[] = 'Organisation ist zu lang.';
    if (mb_strlen($formData['phone']) > $maxLen['phone']) $errors[] = 'Telefonnummer ist zu lang.';
    if (mb_strlen($formData['message']) > $maxLen['message']) $errors[] = 'Nachricht ist zu lang.';

    if ($formData['booking_mode'] === 'individual' && empty($errors)) {
        $expected = $formData['participants'];
        if (count($participantNames) !== $expected || count($participantEmails) !== $expected) {
            $errors[] = 'Bitte füllen Sie die Daten für alle Teilnehmer aus.';
        } else {
            foreach ($participantNames as $i => $pn) {
                if (strlen($pn) < 2) $errors[] = 'Teilnehmer ' . ($i+1) . ': Bitte geben Sie einen Namen ein.';
                $participantEmail = $participantEmails[$i] ?? '';
                if (mb_strlen($pn) > $maxLen['name']) $errors[] = 'Teilnehmer ' . ($i+1) . ': Name ist zu lang.';
                if (mb_strlen($participantEmail) > $maxLen['email']) $errors[] = 'Teilnehmer ' . ($i+1) . ': E-Mail-Adresse ist zu lang.';
                if (!filter_var($participantEmail, FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Teilnehmer ' . ($i+1) . ': Ungültige E-Mail-Adresse.';
                }
            }
        }
    }

    if ($capacity > 0 && $formData['participants'] > $spotsLeft) {
        $errors[] = "Leider sind nur noch {$spotsLeft} Plätze verfügbar.";
    }

    if (empty($errors)) {
        $token = generate_token();
        $stmt = $db->prepare('
            INSERT INTO bookings (workshop_id, name, email, organization, phone, participants, message, token, booking_mode)
            VALUES (:wid, :name, :email, :org, :phone, :participants, :msg, :token, :bmode)
        ');
        $stmt->bindValue(':wid',          $workshop['id'],           SQLITE3_INTEGER);
        $stmt->bindValue(':name',         $formData['name'],         SQLITE3_TEXT);
        $stmt->bindValue(':email',        $formData['email'],        SQLITE3_TEXT);
        $stmt->bindValue(':org',          $formData['organization'], SQLITE3_TEXT);
        $stmt->bindValue(':phone',        $formData['phone'],        SQLITE3_TEXT);
        $stmt->bindValue(':participants', $formData['participants'], SQLITE3_INTEGER);
        $stmt->bindValue(':msg',          $formData['message'],      SQLITE3_TEXT);
        $stmt->bindValue(':token',        $token,                    SQLITE3_TEXT);
        $stmt->bindValue(':bmode',        $formData['booking_mode'], SQLITE3_TEXT);
        $insertResult = $stmt->execute();
        if ($insertResult === false) {
            $errors[] = 'Technischer Fehler beim Speichern. Bitte versuchen Sie es erneut.';
        } else {
            $bookingId = $db->lastInsertRowID();

        // Save individual participant details if in individual mode
        if ($formData['booking_mode'] === 'individual') {
            foreach ($participantNames as $i => $pName) {
                $pstmt = $db->prepare('INSERT INTO booking_participants (booking_id, name, email) VALUES (:bid, :name, :email)');
                $pstmt->bindValue(':bid',   $bookingId,                 SQLITE3_INTEGER);
                $pstmt->bindValue(':name',  $pName,                     SQLITE3_TEXT);
                $pstmt->bindValue(':email', $participantEmails[$i] ?? '', SQLITE3_TEXT);
                $pstmt->execute();
            }
        }

            if (!send_confirmation_email($formData['email'], $formData['name'], $workshop['title'], $token)) {
                $rollbackStmt = $db->prepare('DELETE FROM bookings WHERE id = :id');
                $rollbackStmt->bindValue(':id', $bookingId, SQLITE3_INTEGER);
                $rollbackStmt->execute();
                $errors[] = 'Die Bestaetigungs-E-Mail konnte nicht gesendet werden. Bitte versuchen Sie es erneut.';
            } else {

        flash('success', 'Vielen Dank! Wir haben Ihnen eine Bestätigungs-E-Mail gesendet. Bitte klicken Sie auf den Link in der E-Mail, um Ihre Buchung abzuschließen.');
                redirect('workshop.php?slug=' . urlencode($slug));
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($workshop['title']) ?> – <?= e(SITE_NAME) ?></title>
    <meta name="description" content="<?= e(mb_substr(($workshop['description_short'] ?? '') ?: $workshop['description'], 0, 160)) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
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
            <li><a href="kontakt.php" class="nav-cta">Kontakt</a></li>
        </ul>
    </div>
</nav>

<main id="main-content">
<section class="detail-hero">
    <div class="hero-noise"></div>
    <div class="hero-spotlight"></div>
    <div class="container" style="position:relative;z-index:2;">

        <a href="index.php" class="detail-back">&larr; Alle Workshops</a>

        <?= render_flash() ?>

        <div class="detail-grid">
            <!-- Left: Info -->
            <div class="detail-info">
                <!-- Type badge + format tag -->
                <div style="display:flex;align-items:center;gap:0.6rem;flex-wrap:wrap;margin-bottom:1.25rem;">
                    <?php if ($isOpen): ?>
                        <span class="type-badge type-badge-open"><span class="badge-dot"></span>Fester Termin</span>
                    <?php else: ?>
                        <span class="type-badge type-badge-anfrage"><span class="badge-dot"></span>Auf Anfrage</span>
                    <?php endif; ?>
                    <div class="detail-tag" style="margin-bottom:0;"><span class="card-tag-dot"></span> <?= e($workshop['tag_label']) ?></div>
                    <?php if ($workshop['featured']): ?>
                        <span style="display:inline-block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#000;background:#fff;padding:4px 10px;border-radius:4px;">Empfohlen</span>
                    <?php endif; ?>
                </div>

                <h1><?= e($workshop['title']) ?></h1>
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

                <div class="detail-meta-grid">
                    <?php if ($isOpen && $eventDate): ?>
                    <div class="detail-meta-item" style="grid-column:1/-1;">
                        <div class="label">Datum &amp; Uhrzeit</div>
                        <div class="value"><?= format_event_date($eventDate, $eventDateEnd) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($isOpen && $location): ?>
                    <div class="detail-meta-item" style="grid-column:1/-1;">
                        <div class="label">Veranstaltungsort</div>
                        <div class="value"><?= e($location) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!$isOpen): ?>
                    <div class="detail-meta-item">
                        <div class="label">Termin</div>
                        <div class="value">Auf Anfrage</div>
                    </div>
                    <?php endif; ?>
                    <div class="detail-meta-item">
                        <div class="label">Format</div>
                        <div class="value"><?= e($workshop['format']) ?></div>
                    </div>
                    <div class="detail-meta-item">
                        <div class="label">Dauer</div>
                        <div class="value"><?= e($workshop['tag_label']) ?></div>
                    </div>
                    <?php if ($capacity > 0): ?>
                    <div class="detail-meta-item">
                        <div class="label">Kapazität</div>
                        <div class="value"><?= $capacity ?> Plätze</div>
                    </div>
                    <div class="detail-meta-item">
                        <div class="label">Verfügbar</div>
                        <div class="value"><?= $isFull ? 'Ausgebucht' : ($spotsLeft . ' frei') ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if ($minP > 0): ?>
                    <div class="detail-meta-item">
                        <div class="label">Mindest-Teilnehmende</div>
                        <div class="value"><?= $minP ?> Personen</div>
                    </div>
                    <?php endif; ?>
                </div>

                <?php if ($minP > 0): ?>
                <div class="min-participants-note" style="margin-bottom:1.5rem;">
                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                    Dieser Workshop findet nur statt, wenn mindestens <?= $minP ?> Personen buchen.
                </div>
                <?php endif; ?>

                <?php if ($capacity > 0): ?>
                <div class="seats-indicator <?= $belowMin ? 'below-min' : ($aboveMin ? 'above-min' : '') ?>"
                     style="max-width:400px;margin-bottom:2rem;"
                     <?= ($minP > 0) ? 'title="Mindest-Teilnehmende: ' . $minP . '"' : '' ?>>
                    <div class="seats-bar">
                        <div class="seats-bar-track">
                            <div class="seats-bar-fill <?= $fillClass ?>" style="width:<?= $fillPct ?>%"></div>
                        </div>
                        <?php if ($minP > 0 && $capacity > 0): ?>
                        <div class="seats-bar-marker" style="left:<?= $minPct ?>%">
                            <span class="seats-bar-marker-label">min <?= $minP ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                    <span class="seats-text"><?= $booked ?> / <?= $capacity ?> gebucht</span>
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
                    <a href="kontakt.php" class="btn-submit" style="margin-top:1.5rem;display:block;text-align:center;text-decoration:none;">Kontakt aufnehmen</a>
                <?php else: ?>
                    <h3>Platz buchen</h3>

                    <?php if ($price > 0): ?>
                    <div style="background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:var(--radius);padding:0.875rem 1rem;margin-bottom:1.5rem;">
                        <div style="font-size:0.7rem;text-transform:uppercase;letter-spacing:1.5px;color:var(--dim);margin-bottom:0.25rem;">Preis pro Person</div>
                        <div style="font-size:1.1rem;font-weight:600;color:#fff;"><?= e(format_price($price, $currency)) ?> <span style="font-size:0.75rem;font-weight:400;color:var(--muted);">netto zzgl. MwSt.</span></div>
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

                    <form method="POST" action="workshop.php?slug=<?= e($slug) ?>">
                        <?= csrf_field() ?>
                        <input type="hidden" name="book" value="1">

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
                            <label for="participants">Anzahl Teilnehmer</label>
                            <select id="participants" name="participants">
                                <?php for ($i = 1; $i <= min(20, $spotsLeft ?? 20); $i++): ?>
                                    <option value="<?= $i ?>" <?= $formData['participants'] == $i ? 'selected' : '' ?>><?= $i ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

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
                        <div id="price-summary" style="background:rgba(255,255,255,0.04);border:1px solid var(--border);border-radius:var(--radius);padding:0.875rem 1rem;margin-bottom:1.25rem;font-size:0.88rem;color:var(--muted);">
                            Gesamtpreis (Netto): <strong id="price-total" style="color:#fff;font-size:1rem;"><?= e(format_price($price, $currency)) ?></strong>
                        </div>
                        <?php endif; ?>

                        <button type="submit" class="btn-submit">Buchung anfragen &rarr;</button>

                        <p class="form-disclaimer">
                            Mit dem Absenden erklären Sie sich mit unserer
                            <a href="https://disinfoconsulting.eu/datenschutz/" target="_blank">Datenschutzerklärung</a> einverstanden.
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
       <a href="https://disinfoconsulting.eu/impressum/">Impressum</a> &nbsp;&middot;&nbsp;
       <a href="https://disinfoconsulting.eu/datenschutz/">Datenschutz</a>
    </p>
</footer>

<script>
const burger = document.getElementById('burger');
const navLinks = document.getElementById('nav-links');
burger.addEventListener('click', () => {
    const open = navLinks.classList.toggle('open');
    burger.setAttribute('aria-expanded', open);
});

<?php if ($price > 0): ?>
// Live price calculation
const pricePerPerson = <?= $price ?>;
const participantsSelect = document.getElementById('participants');
const priceTotal = document.getElementById('price-total');
const currency = '<?= e($currency) ?>';
const symbols = { EUR: '€', CHF: 'CHF', USD: '$' };

function formatPrice(amount) {
    const formatted = amount.toLocaleString('de-DE', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const sym = symbols[currency] || currency;
    return currency === 'USD' ? sym + ' ' + formatted : formatted + ' ' + sym;
}

participantsSelect.addEventListener('change', function () {
    const total = pricePerPerson * parseInt(this.value, 10);
    priceTotal.textContent = formatPrice(total);
});
<?php endif; ?>

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); }
    });
}, { threshold: 0.08 });
document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

// ── Booking mode + individual participant fields ──────────────────────────────
(function () {
    const modeRadios       = document.querySelectorAll('input[name="booking_mode"]');
    const countSelect      = document.getElementById('participants');
    const wrap             = document.getElementById('participant-fields-wrap');
    const inner            = document.getElementById('participant-fields-inner');
    const nameInput        = document.getElementById('name');
    const emailInput       = document.getElementById('email');
    const modeGroupWrap    = document.getElementById('mode_individual').closest('.form-group');

    function escAttr(s) {
        return String(s).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    function updateModeVisibility() {
        const count = parseInt(countSelect.value, 10) || 1;
        if (count <= 1) {
            // Hide booking mode toggle and force group mode
            modeGroupWrap.style.display = 'none';
            document.getElementById('mode_group').checked = true;
            wrap.style.display = 'none';
            inner.innerHTML = '';
        } else {
            modeGroupWrap.style.display = '';
        }
    }

    function buildParticipantFields() {
        const isIndividual = document.getElementById('mode_individual').checked;
        if (!isIndividual) { wrap.style.display = 'none'; inner.innerHTML = ''; return; }

        const count   = parseInt(countSelect.value, 10) || 1;
        const prefill = window.__prefillParticipants || [];
        wrap.style.display = '';
        inner.innerHTML    = '';

        for (let i = 0; i < count; i++) {
            const pName  = prefill[i] ? prefill[i][0] : (i === 0 ? nameInput.value  : '');
            const pEmail = prefill[i] ? prefill[i][1] : (i === 0 ? emailInput.value : '');
            const entry  = document.createElement('div');
            entry.className = 'participant-entry';
            entry.innerHTML = `
                <div class="participant-entry-num">Teilnehmer ${i + 1}</div>
                <div class="form-group">
                    <label>Name *</label>
                    <input type="text" name="participant_name[]" value="${escAttr(pName)}"
                           required placeholder="Vollständiger Name">
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
    countSelect.addEventListener('change', function() {
        updateModeVisibility();
        buildParticipantFields();
    });
    updateModeVisibility();  // run on load
    buildParticipantFields(); // run on load (handles repopulation after errors)
})();

// Mobile description expand/collapse
const descWrap   = document.getElementById('detailDescWrap');
const descToggle = document.getElementById('detailDescToggle');
if (descToggle && descWrap) {
    descToggle.addEventListener('click', () => {
        const expanded = descWrap.classList.toggle('expanded');
        descToggle.setAttribute('aria-expanded', expanded);
        descToggle.innerHTML = expanded
            ? 'Weniger anzeigen <span class="toggle-arrow" style="transform:rotate(180deg);display:inline-block;">&#8595;</span>'
            : 'Vollständig lesen <span class="toggle-arrow">&#8595;</span>';
    });
}
</script>

</body>
</html>
