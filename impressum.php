<?php
require __DIR__ . '/includes/config.php';
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Impressum – <?= e(SITE_NAME) ?></title>
    <meta name="description" content="Impressum der Disinfo Combat GmbH.">
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
            <img src="https://disinfoconsulting.eu/wp-content/uploads/2026/02/Gemini_Generated_Image_vjal0gvjal0gvjal-scaled.png"
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
            <h1 class="legal-title">Impressum</h1>
            <p class="legal-intro">Informationen gemäß §5 (1) ECG, § 25 MedienG, § 63 GewO und § 14 UGB.</p>

            <div class="legal-content">
                <h2>Webseitenbetreiber</h2>
                <p>Disinfo Combat GmbH</p>

                <h2>Anschrift</h2>
                <p>Hettenkofergasse 34/I<br>1160 Wien<br>Österreich</p>

                <h2>Firmenbuch</h2>
                <p>FN 563690 g<br>Handelsgericht Wien</p>

                <h2>UID-Nummer</h2>
                <p>ATU77349503</p>

                <h2>Aufsichtsbehörde</h2>
                <p>Magistrat der Stadt Wien</p>

                <h2>Rechtsvorschriften</h2>
                <p><a href="https://www.ris.bka.gv.at" target="_blank" rel="noopener">www.ris.bka.gv.at</a></p>

                <h2>Urheberrecht</h2>
                <p>Die Inhalte dieser Webseite unterliegen, soweit dies rechtlich möglich ist, diversen Schutzrechten (z.B. dem Urheberrecht). Jegliche Verwendung oder Verbreitung von bereitgestelltem Material, welche urheberrechtlich untersagt ist, bedarf schriftlicher Zustimmung des Webseitenbetreibers.</p>
                <p>Die Urheberrechte Dritter werden vom Betreiber dieser Webseite mit größter Sorgfalt beachtet. Sollten Sie trotzdem auf eine Urheberrechtsverletzung aufmerksam werden, bitten wir um einen entsprechenden Hinweis. Bei Bekanntwerden derartiger Rechtsverletzungen werden wir den betroffenen Inhalt umgehend entfernen.</p>

                <h2>Haftungsausschluss</h2>
                <p>Trotz sorgfältiger inhaltlicher Kontrolle übernimmt der Webseitenbetreiber dieser Webseite keine Haftung für die Inhalte externer Links. Für den Inhalt der verlinkten Seiten sind ausschließlich deren Betreiber verantwortlich. Sollten Sie dennoch auf ausgehende Links aufmerksam werden, welche auf eine Webseite mit rechtswidriger Tätigkeit oder Information verweisen, ersuchen wir um dementsprechenden Hinweis, um diese nach § 17 Abs. 2 ECG umgehend zu entfernen.</p>

                <p class="legal-source">Quelle: Impressum Generator Österreich</p>
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
