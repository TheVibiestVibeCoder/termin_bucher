<?php
require __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Datenschutz – <?= e(SITE_NAME) ?></title>
    <meta name="description" content="Datenschutzerklärung der Disinfo Combat GmbH.">
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
        <a href="index.php" class="nav-logo" aria-label="Disinfo Consulting Workshops – Startseite">
            <img src="https://disinfoconsulting.eu/wp-content/uploads/2026/02/Gemini_Generated_Image_vjal0gvjal0gvjal-scaled.png"
                 alt="Disinfo Consulting" height="30">
        </a>
        <button class="nav-burger" aria-label="Navigation öffnen" aria-expanded="false" id="burger">
            <span></span><span></span><span></span>
        </button>
        <ul class="nav-links" id="nav-links" role="list">
            <li><button type="button" class="theme-toggle" id="themeToggle" aria-pressed="false">&#9790;</button></li>
            <li><a href="kontakt.php" class="nav-cta">Kontakt</a></li>
        </ul>
    </div>
</nav>

<main id="main-content">
<section class="detail-hero legal-page">
    <div class="hero-noise"></div>
    <div class="hero-spotlight"></div>
    <div class="container legal-wrap" style="position:relative;z-index:2;">
        <a href="index.php" class="detail-back">&larr; Zur Startseite</a>

        <article class="legal-card">
            <span class="hero-eyebrow legal-eyebrow">Rechtliches</span>
            <h1 class="legal-title">Datenschutz</h1>
            <p class="legal-intro">Erklärung zur Informationspflicht / Datenschutzerklärung</p>

            <div class="legal-content">
                <p>Der Schutz Ihrer persönlichen Daten ist uns ein besonderes Anliegen. Wir verarbeiten Ihre Daten daher ausschließlich auf Grundlage der gesetzlichen Bestimmungen (DSGVO, TKG 2003).</p>

                <h2>1. Kontaktaufnahme &amp; PDF-Zusendung</h2>
                <p>Wenn Sie uns per E-Mail oder über das Kontaktformular kontaktieren oder ein PDF anfordern, verarbeiten wir Ihre angegebenen Daten (z. B. Name, E-Mail-Adresse) zur Bearbeitung Ihrer Anfrage und zur Übermittlung des Dokuments. Diese Daten werden sechs Monate gespeichert und nicht ohne Ihre Einwilligung weitergegeben.<br>Rechtsgrundlage: Art. 6 Abs. 1 lit. b bzw. a DSGVO.</p>

                <h2>2. Zugriff auf unsere Website</h2>
                <p>Beim Besuch unserer Website werden Ihre IP-Adresse sowie Beginn und Ende der Sitzung erfasst. Dies ist technisch notwendig und dient unserem berechtigten Interesse gemäß Art. 6 Abs. 1 lit. f DSGVO.</p>

                <h2>3. Cookies</h2>
                <p>Unsere Website verwendet Cookies, um benutzerfreundliche Funktionen bereitzustellen. Sie können das Setzen von Cookies im Browser unterbinden. Die Deaktivierung kann jedoch die Funktionalität einschränken.</p>

                <h2>4. Ihre Rechte</h2>
                <p>Sie haben das Recht auf Auskunft, Berichtigung, Löschung, Einschränkung, Datenübertragbarkeit sowie Widerspruch. Bei Datenschutzverstößen können Sie sich bei uns oder der österreichischen Datenschutzbehörde beschweren.</p>

                <h2>Kontakt</h2>
                <p>Heihoko GmbH<br>
                E-Mail: <a href="mailto:kontakt@disinfoconsulting.eu">kontakt@disinfoconsulting.eu</a><br>
                Tel.: <a href="tel:+436642035772">+43 664 2035772</a></p>
            </div>
        </article>
    </div>
</section>
</main>

<footer>
    <p>&copy; <?= date('Y') ?> Disinfo Combat GmbH &nbsp;&middot;&nbsp;
       <a href="impressum.php">Impressum</a> &nbsp;&middot;&nbsp;
       <a href="datenschutz.php">Datenschutz</a>
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
