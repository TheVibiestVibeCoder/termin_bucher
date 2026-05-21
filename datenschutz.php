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
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<a href="#main-content" class="skip-link">Direkt zum Inhalt</a>

<nav role="navigation" aria-label="Hauptnavigation">
    <div class="nav-inner">
        <a href="<?= e(app_url()) ?>" class="nav-logo" aria-label="Disinfo Consulting Workshops – Startseite">
            <img src="<?= e(SITE_LOGO) ?>"
                 alt="Disinfo Consulting" height="30">
        </a>
        <button class="nav-burger" aria-label="Navigation öffnen" aria-expanded="false" id="burger">
            <span></span><span></span><span></span>
        </button>
        <ul class="nav-links" id="nav-links" role="list">
            <li><button type="button" class="theme-toggle" id="themeToggle" aria-pressed="false">&#9790;</button></li>
            <li><a href="<?= e(app_url('kontakt')) ?>" class="nav-cta">Kontakt</a></li>
        </ul>
    </div>
</nav>

<main id="main-content">
<section class="detail-hero legal-page">
    <div class="hero-noise"></div>
    <div class="hero-spotlight"></div>
    <div class="container legal-wrap" style="position:relative;z-index:2;">
        <a href="<?= e(app_url()) ?>" class="detail-back">&larr; Zur Startseite</a>

        <article class="legal-card">
            <span class="hero-eyebrow legal-eyebrow">Rechtliches</span>
            <h1 class="legal-title">Datenschutz</h1>
            <p class="legal-intro">Erklärung zur Informationspflicht gemäß Art. 13 DSGVO / Datenschutzerklärung</p>

            <div class="legal-content">
                <p>Der Schutz Ihrer persönlichen Daten ist uns ein besonderes Anliegen. Wir verarbeiten Ihre Daten daher ausschließlich auf Grundlage der gesetzlichen Bestimmungen (DSGVO, TKG 2003). In dieser Datenschutzerklärung informieren wir Sie über die wichtigsten Aspekte der Datenverarbeitung im Rahmen unserer Website und unserer Dienstleistungen.</p>

                <h2>1. Verantwortlicher</h2>
                <p>Verantwortlicher im Sinne der DSGVO ist:<br>
                Disinfo Combat GmbH<br>
                E-Mail: <a href="mailto:kontakt@disinfoconsulting.eu">kontakt@disinfoconsulting.eu</a><br>
                Tel.: <a href="tel:+436642035772">+43 664 2035772</a></p>

                <h2>2. Kontaktaufnahme &amp; PDF-Zusendung</h2>
                <p>Wenn Sie uns per E-Mail oder über das Kontaktformular kontaktieren oder ein PDF anfordern, verarbeiten wir Ihre angegebenen Daten (z. B. Name, E-Mail-Adresse) zur Bearbeitung Ihrer Anfrage und zur Übermittlung des angeforderten Dokuments.</p>
                <p>Im Einzelnen werden folgende Daten verarbeitet:</p>
                <ul>
                    <li>Name (sofern angegeben)</li>
                    <li>E-Mail-Adresse</li>
                    <li>Inhalt Ihrer Nachricht</li>
                    <li>Zeitpunkt der Kontaktaufnahme</li>
                </ul>
                <p>Diese Daten werden ausschließlich zur Bearbeitung Ihres Anliegens verwendet, nicht an Dritte weitergegeben und nach Abschluss der Bearbeitung bzw. spätestens nach sechs Monaten gelöscht, sofern keine gesetzlichen Aufbewahrungspflichten entgegenstehen.<br>
                Rechtsgrundlage: Art. 6 Abs. 1 lit. b DSGVO (Vertragserfüllung bzw. vorvertragliche Maßnahmen) sowie Art. 6 Abs. 1 lit. a DSGVO (Einwilligung), soweit zutreffend.</p>

                <h2>3. Zugriff auf unsere Website / Server-Logfiles</h2>
                <p>Beim Besuch unserer Website werden durch den Hosting-Anbieter automatisch Informationen in sogenannten Server-Logfiles erfasst. Dies umfasst insbesondere:</p>
                <ul>
                    <li>IP-Adresse des anfragenden Geräts</li>
                    <li>Datum und Uhrzeit des Zugriffs</li>
                    <li>Aufgerufene URL</li>
                    <li>Browsertyp und -version</li>
                    <li>Betriebssystem</li>
                    <li>HTTP-Statuscode und übertragene Datenmenge</li>
                </ul>
                <p>Diese Daten sind technisch notwendig, um die Website korrekt auszuliefern, und dienen darüber hinaus der Sicherheit und Stabilität des Betriebs. Eine Zusammenführung mit anderen Datenquellen findet nicht statt.<br>
                Rechtsgrundlage: Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse).</p>

                <h2>4. Hosting &amp; Drittlandübermittlung</h2>
                <p>Unsere Website und die damit verbundenen Daten werden auf einem Server in England (Vereinigtes Königreich) gehostet. Das Vereinigte Königreich verfügt über einen Angemessenheitsbeschluss der Europäischen Kommission gemäß Art. 45 DSGVO, wonach ein dem europäischen Standard vergleichbares Datenschutzniveau gewährleistet ist. Eine Übermittlung Ihrer Daten in dieses Land ist daher datenschutzrechtlich zulässig.</p>
                <p>Sofern sich die Rechtslage diesbezüglich ändern sollte, werden wir geeignete Garantien gemäß Art. 46 DSGVO (z. B. Standardvertragsklauseln) implementieren und diese Erklärung entsprechend aktualisieren.</p>

                <h2>5. Cookies</h2>
                <p>Unsere Website verwendet Cookies, um benutzerfreundliche Funktionen bereitzustellen. Cookies sind kleine Textdateien, die von Ihrem Browser auf Ihrem Endgerät gespeichert werden. Wir setzen ausschließlich technisch notwendige Cookies ein, die für den Betrieb der Website erforderlich sind (z. B. zur Speicherung Ihrer Theme-Präferenz).</p>
                <p>Cookies, die für den Betrieb der Website zwingend erforderlich sind, werden auf Grundlage von Art. 6 Abs. 1 lit. f DSGVO (berechtigtes Interesse) gesetzt. Sie können das Setzen von Cookies in Ihrem Browser jederzeit deaktivieren. Beachten Sie jedoch, dass dies die Funktionalität der Website einschränken kann.</p>

                <h2>6. Ihre Rechte</h2>
                <p>Sie haben gegenüber uns folgende Rechte hinsichtlich Ihrer gespeicherten personenbezogenen Daten:</p>
                <ul>
                    <li><strong>Auskunft</strong> (Art. 15 DSGVO): Sie können jederzeit Auskunft über die bei uns gespeicherten Daten verlangen.</li>
                    <li><strong>Berichtigung</strong> (Art. 16 DSGVO): Sie haben das Recht, unrichtige Daten berichtigen zu lassen.</li>
                    <li><strong>Löschung</strong> (Art. 17 DSGVO): Sie können die Löschung Ihrer Daten verlangen, sofern keine gesetzliche Aufbewahrungspflicht besteht.</li>
                    <li><strong>Einschränkung der Verarbeitung</strong> (Art. 18 DSGVO): Sie können die Einschränkung der Verarbeitung Ihrer Daten verlangen.</li>
                    <li><strong>Datenübertragbarkeit</strong> (Art. 20 DSGVO): Sie haben das Recht, Ihre Daten in einem strukturierten, gängigen Format zu erhalten.</li>
                    <li><strong>Widerspruch</strong> (Art. 21 DSGVO): Sie können der Verarbeitung Ihrer Daten auf Basis berechtigter Interessen jederzeit widersprechen.</li>
                    <li><strong>Widerruf der Einwilligung</strong> (Art. 7 Abs. 3 DSGVO): Sofern die Verarbeitung auf einer Einwilligung beruht, können Sie diese jederzeit mit Wirkung für die Zukunft widerrufen.</li>
                </ul>
                <p>Zur Geltendmachung Ihrer Rechte wenden Sie sich bitte an: <a href="mailto:kontakt@disinfoconsulting.eu">kontakt@disinfoconsulting.eu</a></p>

                <h2>7. Beschwerderecht</h2>
                <p>Sie haben das Recht, sich bei der zuständigen Datenschutzbehörde zu beschweren. In Österreich ist dies die:</p>
                <p>Österreichische Datenschutzbehörde<br>
                Barichgasse 40–42, 1030 Wien<br>
                Telefon: +43 1 52 152-0<br>
                E-Mail: <a href="mailto:dsb@dsb.gv.at">dsb@dsb.gv.at</a><br>
                Web: <a href="https://www.dsb.gv.at" target="_blank" rel="noopener">www.dsb.gv.at</a></p>

                <h2>8. Aktualität dieser Erklärung</h2>
                <p>Wir behalten uns vor, diese Datenschutzerklärung bei Bedarf zu aktualisieren, um sie an geänderte Rechtslage oder Leistungsänderungen anzupassen. Es gilt jeweils die zum Zeitpunkt Ihres Besuchs aktuelle Fassung.</p>

                <h2>Kontakt</h2>
                <p>Disinfo Combat GmbH<br>
                E-Mail: <a href="mailto:kontakt@disinfoconsulting.eu">kontakt@disinfoconsulting.eu</a><br>
                Tel.: <a href="tel:+436642035772">+43 664 2035772</a></p>
            </div>
        </article>
    </div>
</section>
</main>

<footer>
    <p>&copy; <?= date('Y') ?> Disinfo Combat GmbH &nbsp;&middot;&nbsp;
       <a href="<?= e(app_url('impressum')) ?>">Impressum</a> &nbsp;&middot;&nbsp;
       <a href="<?= e(app_url('datenschutz')) ?>">Datenschutz</a>
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

<script src="/assets/site-ui.js"></script>
</body>
</html>