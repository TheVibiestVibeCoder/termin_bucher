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

$booked   = count_confirmed_bookings($db, $workshop['id']);
$capacity = (int) $workshop['capacity'];
$spotsLeft = $capacity > 0 ? max(0, $capacity - $booked) : null;
$fillPct   = ($capacity > 0) ? min(100, round(($booked / $capacity) * 100)) : 0;
$fillClass = ($fillPct >= 85) ? 'high' : (($fillPct >= 50) ? 'medium' : '');
$isFull    = $capacity > 0 && $spotsLeft <= 0;
$audienceLabelsArr = array_filter(array_map('trim', explode(',', $workshop['audience_labels'])));

$errors = [];
$formData = ['name' => '', 'email' => '', 'organization' => '', 'phone' => '', 'participants' => 1, 'message' => ''];

// Handle booking submission
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

    if (strlen($formData['name']) < 2) $errors[] = 'Bitte geben Sie Ihren Namen ein.';
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    if ($formData['participants'] < 1 || $formData['participants'] > 50) $errors[] = 'Ungültige Teilnehmerzahl.';

    if ($capacity > 0 && $formData['participants'] > $spotsLeft) {
        $errors[] = "Leider sind nur noch {$spotsLeft} Plätze verfügbar.";
    }

    // Check duplicate pending/confirmed booking
    if (empty($errors)) {
        $stmt = $db->prepare('SELECT id FROM bookings WHERE workshop_id = :wid AND email = :email AND confirmed = 1');
        $stmt->bindValue(':wid', $workshop['id'], SQLITE3_INTEGER);
        $stmt->bindValue(':email', $formData['email'], SQLITE3_TEXT);
        if ($stmt->execute()->fetchArray()) {
            $errors[] = 'Diese E-Mail-Adresse ist bereits für diesen Workshop angemeldet.';
        }
    }

    if (empty($errors)) {
        $token = generate_token();

        $stmt = $db->prepare('
            INSERT INTO bookings (workshop_id, name, email, organization, phone, participants, message, token)
            VALUES (:wid, :name, :email, :org, :phone, :participants, :msg, :token)
        ');
        $stmt->bindValue(':wid',          $workshop['id'],           SQLITE3_INTEGER);
        $stmt->bindValue(':name',         $formData['name'],         SQLITE3_TEXT);
        $stmt->bindValue(':email',        $formData['email'],        SQLITE3_TEXT);
        $stmt->bindValue(':org',          $formData['organization'], SQLITE3_TEXT);
        $stmt->bindValue(':phone',        $formData['phone'],        SQLITE3_TEXT);
        $stmt->bindValue(':participants', $formData['participants'], SQLITE3_INTEGER);
        $stmt->bindValue(':msg',          $formData['message'],      SQLITE3_TEXT);
        $stmt->bindValue(':token',        $token,                    SQLITE3_TEXT);
        $stmt->execute();

        send_confirmation_email($formData['email'], $formData['name'], $workshop['title'], $token);

        flash('success', 'Vielen Dank! Wir haben Ihnen eine Bestätigungs-E-Mail gesendet. Bitte klicken Sie auf den Link in der E-Mail, um Ihre Buchung abzuschließen.');
        redirect('workshop.php?slug=' . urlencode($slug));
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e($workshop['title']) ?> – <?= e(SITE_NAME) ?></title>
    <meta name="description" content="<?= e(mb_substr($workshop['description'], 0, 160)) ?>">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<a href="#main-content" class="skip-link">Direkt zum Inhalt</a>

<!-- NAV -->
<nav role="navigation" aria-label="Hauptnavigation">
    <div class="nav-inner">
        <a href="https://disinfoconsulting.eu/" class="nav-logo" aria-label="Disinfo Consulting – Startseite">
            <img src="https://disinfoconsulting.eu/wp-content/uploads/2025/04/DCblau1-scaled.jpg"
                 alt="Disinfo Consulting" height="30">
        </a>
        <button class="nav-burger" aria-label="Navigation öffnen" aria-expanded="false" id="burger">
            <span></span><span></span><span></span>
        </button>
        <ul class="nav-links" id="nav-links" role="list">
            <li><a href="https://disinfoconsulting.eu/leistungen/">Leistungen</a></li>
            <li><a href="index.php" class="active">Workshops</a></li>
            <li><a href="https://disinfoconsulting.eu/whitepaper-anfordern/">Whitepaper</a></li>
            <li><a href="https://disinfoconsulting.eu/das-team/">Das Team</a></li>
            <li><a href="https://disinfoconsulting.eu/kontakt/" class="nav-cta">Kontakt</a></li>
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
                <div class="detail-tag"><span class="card-tag-dot"></span> <?= e($workshop['tag_label']) ?></div>
                <?php if ($workshop['featured']): ?>
                    <span style="display:inline-block;font-size:0.65rem;font-weight:700;text-transform:uppercase;letter-spacing:2px;color:#000;background:#fff;padding:4px 10px;border-radius:4px;margin-bottom:1rem;">Empfohlen</span>
                <?php endif; ?>
                <h1><?= e($workshop['title']) ?></h1>
                <p class="detail-desc"><?= nl2br(e($workshop['description'])) ?></p>

                <div class="detail-meta-grid">
                    <?php if ($capacity > 0): ?>
                    <div class="detail-meta-item">
                        <div class="label">Kapazität</div>
                        <div class="value"><?= $capacity ?> Plätze</div>
                    </div>
                    <div class="detail-meta-item">
                        <div class="label">Verfügbar</div>
                        <div class="value"><?= $isFull ? 'Ausgebucht' : ($spotsLeft . ' Plätze frei') ?></div>
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
                </div>

                <?php if ($capacity > 0): ?>
                <div class="seats-indicator" style="max-width:400px;margin-bottom:2rem;">
                    <div class="seats-bar">
                        <div class="seats-bar-fill <?= $fillClass ?>" style="width: <?= $fillPct ?>%"></div>
                    </div>
                    <span class="seats-text"><?= $booked ?> / <?= $capacity ?> gebucht</span>
                </div>
                <?php endif; ?>

                <p class="card-audience" style="margin-bottom:0.5rem;">Zielgruppen</p>
                <div class="card-audience-tags" style="margin-bottom:0;">
                    <?php foreach ($audienceLabelsArr as $al): ?>
                        <span class="aud-tag"><?= e($al) ?></span>
                    <?php endforeach; ?>
                </div>
            </div>

            <!-- Right: Booking form -->
            <div class="booking-box">
                <?php if ($isFull): ?>
                    <h3>Ausgebucht</h3>
                    <p style="color:var(--muted);line-height:1.7;">Dieser Workshop ist leider voll ausgebucht. Kontaktieren Sie uns für Alternativtermine.</p>
                    <a href="https://disinfoconsulting.eu/kontakt/" class="btn-submit" style="margin-top:1.5rem;display:block;text-align:center;text-decoration:none;">Kontakt aufnehmen</a>
                <?php else: ?>
                    <h3>Platz buchen</h3>
                    <p style="color:var(--muted);font-size:0.85rem;line-height:1.6;margin-bottom:1.5rem;">
                        Füllen Sie das Formular aus. Sie erhalten eine E-Mail zur Bestätigung.
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

                        <div class="form-group">
                            <label for="message">Nachricht (optional)</label>
                            <textarea id="message" name="message" placeholder="Besondere Anforderungen, Fragen..."><?= e($formData['message']) ?></textarea>
                        </div>

                        <button type="submit" class="btn-submit">Buchung anfragen &rarr;</button>

                        <p class="form-disclaimer">
                            Mit dem Absenden erklären Sie sich mit unserer
                            <a href="https://disinfoconsulting.eu/datenschutz/" target="_blank">Datenschutzerklärung</a> einverstanden.
                            Sie erhalten eine E-Mail zur Bestätigung – erst danach ist Ihr Platz reserviert.
                        </p>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>
</section>
</main>

<!-- FOOTER -->
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

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.08 });
document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));
</script>

</body>
</html>
