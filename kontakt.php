<?php
require __DIR__ . '/includes/config.php';
require __DIR__ . '/includes/email.php';

$errors   = [];
$success  = false;
$formData = ['name' => '', 'email' => '', 'subject' => '', 'message' => ''];
$maxLen   = ['name' => 120, 'email' => 254, 'subject' => 180, 'message' => 4000];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['contact_submit'])) {
    if (!csrf_verify()) {
        $errors[] = 'Ungültige Sitzung. Bitte laden Sie die Seite neu.';
    }

    // Honeypot anti-spam check
    if (!empty($_POST['website'])) {
        // Silent redirect for bots
        redirect('kontakt.php');
    }

    if (!rate_limit('contact', 3)) {
        $errors[] = 'Zu viele Anfragen. Bitte warten Sie einen Moment.';
    }

    $formData['name']    = trim($_POST['name']    ?? '');
    $formData['email']   = trim($_POST['email']   ?? '');
    $formData['subject'] = trim($_POST['subject'] ?? '');
    $formData['message'] = trim($_POST['message'] ?? '');

    if (strlen($formData['name']) < 2)    $errors[] = 'Bitte geben Sie Ihren Namen ein.';
    if (!filter_var($formData['email'], FILTER_VALIDATE_EMAIL)) $errors[] = 'Bitte geben Sie eine gültige E-Mail-Adresse ein.';
    if (strlen($formData['subject']) < 3) $errors[] = 'Bitte geben Sie einen Betreff ein.';
    if (strlen($formData['message']) < 10) $errors[] = 'Bitte geben Sie eine Nachricht ein (mindestens 10 Zeichen).';

    if (mb_strlen($formData['name']) > $maxLen['name']) $errors[] = 'Name ist zu lang.';
    if (mb_strlen($formData['email']) > $maxLen['email']) $errors[] = 'E-Mail-Adresse ist zu lang.';
    if (mb_strlen($formData['subject']) > $maxLen['subject']) $errors[] = 'Betreff ist zu lang.';
    if (mb_strlen($formData['message']) > $maxLen['message']) $errors[] = 'Nachricht ist zu lang.';

    if (empty($errors)) {
        // Notify admin
        $adminHtml = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; padding: 20px;">
            <h2>Neue Kontaktanfrage</h2>
            <p><strong>Name:</strong> ' . e($formData['name']) . '</p>
            <p><strong>E-Mail:</strong> ' . e($formData['email']) . '</p>
            <p><strong>Betreff:</strong> ' . e($formData['subject']) . '</p>
            <p><strong>Nachricht:</strong></p>
            <div style="background:#f5f5f5;padding:12px;border-radius:4px;white-space:pre-wrap;">' . e($formData['message']) . '</div>
        </div>';
        $adminSent = send_email(MAIL_FROM, 'Kontaktanfrage: ' . $formData['subject'], $adminHtml);

        // Auto-reply to sender
        $replyHtml = '
        <div style="font-family: Arial, sans-serif; max-width: 600px; margin: 0 auto; background: #0d0d0d; color: #ffffff; padding: 40px; border-radius: 8px;">
            <h2 style="font-family: Georgia, serif; font-weight: normal; font-size: 24px; margin-bottom: 20px;">Ihre Nachricht ist angekommen.</h2>
            <p style="color: #a0a0a0; line-height: 1.7;">Hallo ' . e($formData['name']) . ',</p>
            <p style="color: #a0a0a0; line-height: 1.7;">vielen Dank für Ihre Nachricht. Wir haben Ihre Anfrage erhalten und melden uns so bald wie möglich bei Ihnen.</p>
            <p style="color: #a0a0a0; line-height: 1.7;"><strong style="color:#ffffff;">Ihr Betreff:</strong> ' . e($formData['subject']) . '</p>
            <hr style="border: none; border-top: 1px solid #222; margin: 30px 0;">
            <p style="color: #666; font-size: 12px;">' . e(MAIL_FROM_NAME) . ' &middot; ' . e(MAIL_FROM) . '</p>
        </div>';
        $replySent = send_email($formData['email'], 'Ihre Anfrage: ' . $formData['subject'], $replyHtml);

        if (!$adminSent || !$replySent) {
            $errors[] = 'Ihre Nachricht konnte aktuell nicht zugestellt werden. Bitte versuchen Sie es erneut.';
        } else {
            flash('success', 'Vielen Dank! Ihre Nachricht wurde gesendet. Wir melden uns so bald wie möglich.');
            redirect('kontakt.php');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kontakt – <?= e(SITE_NAME) ?></title>
    <meta name="description" content="Kontaktieren Sie uns für individuelle Workshop-Anfragen oder allgemeine Fragen.">
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
            <li><button type="button" class="theme-toggle" id="themeToggle" aria-pressed="false">Light Mode</button></li>
            <li><a href="kontakt.php" class="nav-cta active">Kontakt</a></li>
        </ul>
    </div>
</nav>

<main id="main-content">
<section class="detail-hero">
    <div class="hero-noise"></div>
    <div class="hero-spotlight"></div>
    <div class="container" style="position:relative;z-index:2;padding-top:6rem;padding-bottom:4rem;">

        <a href="index.php" class="detail-back">&larr; Alle Workshops</a>

        <?= render_flash() ?>

        <div style="max-width:640px;margin:0 auto;">
            <span class="hero-eyebrow" style="margin-bottom:1rem;display:block;">Kontakt</span>
            <h1 style="font-family:var(--font-h);font-size:clamp(2rem,4vw,3rem);font-weight:400;line-height:1.15;margin-bottom:0.75rem;">
                Sprechen Sie uns an.
            </h1>
            <p style="color:var(--muted);line-height:1.7;margin-bottom:2.5rem;">
                Kein Workshop passt genau? Wir entwickeln auch vollständig maßgeschneiderte Formate. Schreiben Sie uns – wir antworten in der Regel innerhalb von einem Werktag.
            </p>

            <?php if ($errors): ?>
                <div class="flash flash-error" style="margin-bottom:1.5rem;">
                    <?php foreach ($errors as $err): ?>
                        <div><?= e($err) ?></div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <div class="booking-box" style="position:static;">
                <form method="POST" action="kontakt.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="contact_submit" value="1">

                    <!-- Honeypot (hidden from real users, bots fill it) -->
                    <div style="position:absolute;left:-9999px;top:-9999px;" aria-hidden="true">
                        <label for="website">Website leer lassen</label>
                        <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
                    </div>

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
                        <label for="subject">Betreff *</label>
                        <input type="text" id="subject" name="subject" required placeholder="Worum geht es?"
                               value="<?= e($formData['subject']) ?>">
                    </div>
                    <div class="form-group">
                        <label for="message">Nachricht *</label>
                        <textarea id="message" name="message" rows="6" required
                                  placeholder="Beschreiben Sie Ihr Anliegen, Ihre Organisation und die Anzahl möglicher Teilnehmer:innen..."><?= e($formData['message']) ?></textarea>
                    </div>

                    <button type="submit" class="btn-submit">Nachricht senden &rarr;</button>

                    <p class="form-disclaimer">
                        Mit dem Absenden erklären Sie sich mit unserer
                        <a href="https://disinfoconsulting.eu/datenschutz/" target="_blank">Datenschutzerklärung</a> einverstanden.
                    </p>
                </form>
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
</script>

<script src="assets/site-ui.js"></script>

</body>
</html>

