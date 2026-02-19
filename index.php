<?php
require __DIR__ . '/includes/config.php';

// Fetch active workshops
$result = $db->query('SELECT * FROM workshops WHERE active = 1 ORDER BY sort_order ASC, id ASC');
$workshops = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $row['booked'] = count_confirmed_bookings($db, $row['id']);
    $workshops[] = $row;
}

// Collect unique audience keys for filter buttons
$allAudiences = [];
foreach ($workshops as $w) {
    foreach (explode(',', $w['audiences']) as $a) {
        $a = trim($a);
        if ($a) $allAudiences[$a] = true;
    }
}
$allAudiences = array_keys($allAudiences);

$audienceLabels = [
    'unternehmen' => 'Unternehmen',
    'ngo'         => 'NGOs',
    'verwaltung'  => 'Verwaltung',
    'bildung'     => 'Bildung',
];
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(SITE_NAME) ?></title>
    <meta name="description" content="Praktische Workshops zu Desinformation, FIMI-Abwehr und Medienkompetenz – für Unternehmen, NGOs und öffentliche Einrichtungen.">
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
            <li><a href="#workshops" class="active">Workshops</a></li>
            <li><a href="https://disinfoconsulting.eu/whitepaper-anfordern/">Whitepaper</a></li>
            <li><a href="https://disinfoconsulting.eu/das-team/">Das Team</a></li>
            <li><a href="https://disinfoconsulting.eu/kontakt/" class="nav-cta">Kontakt</a></li>
        </ul>
    </div>
</nav>

<!-- HERO -->
<section id="hero" role="banner" aria-label="Seiteneinleitung">
    <div class="hero-noise"></div>
    <div class="hero-spotlight"></div>
    <div class="hero-content">
        <span class="hero-eyebrow">Disinfo Consulting – Workshops</span>
        <h1 class="hero-h1">
            Kompetenz, die schützt.<br>Wissen, das wirkt.
        </h1>
        <p class="hero-p">
            Praktische Workshops zur Desinformationsabwehr – maßgeschneidert für Unternehmen, NGOs und öffentliche Einrichtungen. Von Experten mit direkter Regierungserfahrung.
        </p>
        <div class="hero-stats">
            <div class="stat-item fade-in" style="transition-delay:0.6s">
                <span class="stat-num">78 Mrd.</span>
                <span class="stat-label">USD jährlicher Schaden</span>
            </div>
            <div class="stat-item fade-in" style="transition-delay:0.75s">
                <span class="stat-num"><?= count($workshops) ?>+</span>
                <span class="stat-label">Workshop-Formate</span>
            </div>
            <div class="stat-item fade-in" style="transition-delay:0.9s">
                <span class="stat-num">EU-weit</span>
                <span class="stat-label">Referenznetzwerk</span>
            </div>
        </div>
    </div>
</section>

<!-- WORKSHOPS -->
<main id="main-content">
<section id="workshops" class="section">
    <div class="container">
        <span class="section-eyebrow fade-in">Unser Angebot</span>
        <h2 class="section-title fade-in">Wählen Sie Ihr Format.</h2>
        <p class="section-sub fade-in">Alle Workshops sind auf Deutsch oder Englisch buchbar und können auf Ihre Organisation angepasst werden.</p>

        <!-- Filter -->
        <div class="filter-row fade-in" role="group" aria-label="Workshop-Filter">
            <button class="filter-btn active" data-filter="all">Alle</button>
            <?php foreach ($allAudiences as $aud): ?>
                <button class="filter-btn" data-filter="<?= e($aud) ?>"><?= e($audienceLabels[$aud] ?? ucfirst($aud)) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="workshops-grid" id="workshopGrid">
            <?php foreach ($workshops as $i => $w):
                $booked = $w['booked'];
                $capacity = (int) $w['capacity'];
                $spotsLeft = $capacity > 0 ? max(0, $capacity - $booked) : null;
                $fillPct = ($capacity > 0) ? min(100, round(($booked / $capacity) * 100)) : 0;
                $fillClass = ($fillPct >= 85) ? 'high' : (($fillPct >= 50) ? 'medium' : '');
                $audienceLabelsArr = array_filter(array_map('trim', explode(',', $w['audience_labels'])));
            ?>
            <article class="workshop-card <?= $w['featured'] ? 'featured' : '' ?> fade-in"
                     data-audiences="<?= e($w['audiences']) ?>"
                     style="transition-delay:<?= $i * 0.1 ?>s">
                <?php if ($w['featured']): ?>
                    <div class="featured-badge">Empfohlen</div>
                <?php endif; ?>
                <div class="card-tag"><span class="card-tag-dot"></span><?= e($w['tag_label']) ?></div>
                <h3 class="card-h3"><?= e($w['title']) ?></h3>
                <p class="card-main-text"><?= e($w['description']) ?></p>

                <div class="card-meta">
                    <?php if ($capacity > 0): ?>
                    <span class="meta-pill">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                        <?= $capacity ?> Plätze
                    </span>
                    <?php endif; ?>
                    <span class="meta-pill">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                        Auf Anfrage
                    </span>
                    <span class="meta-pill">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                        <?= e($w['format']) ?>
                    </span>
                </div>

                <?php if ($capacity > 0): ?>
                <div class="seats-indicator">
                    <div class="seats-bar">
                        <div class="seats-bar-fill <?= $fillClass ?>" style="width: <?= $fillPct ?>%"></div>
                    </div>
                    <span class="seats-text"><?= $spotsLeft ?> von <?= $capacity ?> frei</span>
                </div>
                <?php endif; ?>

                <div class="card-divider"></div>
                <p class="card-audience">Zielgruppen</p>
                <div class="card-audience-tags">
                    <?php foreach ($audienceLabelsArr as $al): ?>
                        <span class="aud-tag"><?= e($al) ?></span>
                    <?php endforeach; ?>
                </div>
                <a href="workshop.php?slug=<?= e($w['slug']) ?>" class="btn-book">Details &amp; Buchen &rarr;</a>
            </article>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<!-- CTA -->
<section id="cta-section">
    <div class="container">
        <h2 class="cta-h2 fade-in">Bereit für den nächsten Schritt?</h2>
        <p class="cta-sub fade-in" style="transition-delay:0.1s">
            Kein Workshop passt exakt? Kontaktieren Sie uns für ein individuelles Konzept – wir entwickeln auch vollständig maßgeschneiderte Formate.
        </p>
        <div class="cta-btns fade-in" style="transition-delay:0.2s">
            <a href="https://disinfoconsulting.eu/kontakt/" class="btn-primary">
                Kontakt aufnehmen
            </a>
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
// Burger menu
const burger = document.getElementById('burger');
const navLinks = document.getElementById('nav-links');
burger.addEventListener('click', () => {
    const open = navLinks.classList.toggle('open');
    burger.setAttribute('aria-expanded', open);
});

// Fade-in observer
const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) {
            entry.target.classList.add('visible');
            observer.unobserve(entry.target);
        }
    });
}, { threshold: 0.08 });
document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

// Filter
const filterBtns = document.querySelectorAll('.filter-btn');
const cards = document.querySelectorAll('#workshopGrid .workshop-card');
filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        filterBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        const f = btn.dataset.filter;
        cards.forEach(card => {
            const audiences = card.dataset.audiences || '';
            const show = f === 'all' || audiences.includes(f);
            card.style.display = show ? '' : 'none';
        });
    });
});
</script>

</body>
</html>
