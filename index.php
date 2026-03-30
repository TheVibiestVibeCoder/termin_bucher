<?php
require __DIR__ . '/includes/config.php';

archive_expired_unconfirmed_bookings($db, 48);

$bookedByWorkshop = [];
$bookedByOccurrence = [];
$legacyBookedByWorkshop = [];
$occurrencesByWorkshop = [];
$bookedRes = $db->query('SELECT workshop_id, occurrence_id, COALESCE(SUM(participants), 0) AS booked FROM bookings WHERE confirmed = 1 AND COALESCE(archived, 0) = 0 GROUP BY workshop_id, occurrence_id');
while ($row = $bookedRes->fetchArray(SQLITE3_ASSOC)) {
    $workshopId = (int) ($row['workshop_id'] ?? 0);
    $booked = (int) ($row['booked'] ?? 0);
    $occurrenceRaw = $row['occurrence_id'] ?? null;

    $bookedByWorkshop[$workshopId] = ($bookedByWorkshop[$workshopId] ?? 0) + $booked;

    if ($occurrenceRaw === null || (int) $occurrenceRaw === 0) {
        $legacyBookedByWorkshop[$workshopId] = ($legacyBookedByWorkshop[$workshopId] ?? 0) + $booked;
    } else {
        $occurrenceId = (int) $occurrenceRaw;
        if (!isset($bookedByOccurrence[$workshopId])) {
            $bookedByOccurrence[$workshopId] = [];
        }
        $bookedByOccurrence[$workshopId][$occurrenceId] = $booked;
    }
}

$occurrencesRes = $db->query('SELECT * FROM workshop_occurrences WHERE active = 1 ORDER BY workshop_id ASC, sort_order ASC, start_at ASC, id ASC');
while ($occurrenceRow = $occurrencesRes->fetchArray(SQLITE3_ASSOC)) {
    $workshopId = (int) ($occurrenceRow['workshop_id'] ?? 0);
    if ($workshopId <= 0) {
        continue;
    }
    $occurrencesByWorkshop[$workshopId][] = $occurrenceRow;
}

$result = $db->query('SELECT * FROM workshops WHERE active = 1 AND COALESCE(archived, 0) = 0 ORDER BY sort_order ASC, id ASC');
$workshops = [];
$workshopsById = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $workshopId = (int) ($row['id'] ?? 0);
    $row['booked'] = $bookedByWorkshop[$workshopId] ?? 0;
    $row['occurrences'] = [];

    $isOpenWorkshop = (($row['workshop_type'] ?? 'auf_anfrage') === 'open');
    if ($isOpenWorkshop) {
        $occRows = $occurrencesByWorkshop[$workshopId] ?? [];
        if (empty($occRows) && trim((string) ($row['event_date'] ?? '')) !== '') {
            $occRows[] = [
                'id' => 0,
                'start_at' => (string) ($row['event_date'] ?? ''),
                'end_at' => (string) ($row['event_date_end'] ?? ''),
                'sort_order' => 0,
                'active' => 1,
            ];
        }

        $legacyBooked = (int) ($legacyBookedByWorkshop[$workshopId] ?? 0);
        if ($legacyBooked > 0 && !empty($occRows)) {
            $firstOccurrenceId = (int) ($occRows[0]['id'] ?? 0);
            if ($firstOccurrenceId > 0) {
                $bookedByOccurrence[$workshopId][$firstOccurrenceId] = (int) ($bookedByOccurrence[$workshopId][$firstOccurrenceId] ?? 0) + $legacyBooked;
            }
        }

        $capacity = (int) ($row['capacity'] ?? 0);
        $minParticipants = (int) ($row['min_participants'] ?? 0);

        foreach ($occRows as $occurrenceIndex => $occurrenceRow) {
            $occurrenceId = (int) ($occurrenceRow['id'] ?? 0);
            $occBooked = $occurrenceId > 0
                ? (int) ($bookedByOccurrence[$workshopId][$occurrenceId] ?? 0)
                : $legacyBooked;

            $occSpotsLeft = $capacity > 0 ? max(0, $capacity - $occBooked) : null;
            $occFillPct = ($capacity > 0) ? min(100, round(($occBooked / $capacity) * 100)) : 0;
            $occFillClass = ($occFillPct >= 85) ? 'high' : (($occFillPct >= 50) ? 'medium' : '');
            $occQuery = ['slug' => (string) ($row['slug'] ?? '')];
            if ($occurrenceId > 0) {
                $occQuery['occurrence'] = $occurrenceId;
            }

            $occRows[$occurrenceIndex]['booked'] = $occBooked;
            $occRows[$occurrenceIndex]['spots_left'] = $occSpotsLeft;
            $occRows[$occurrenceIndex]['fill_pct'] = $occFillPct;
            $occRows[$occurrenceIndex]['fill_class'] = $occFillClass;
            $occRows[$occurrenceIndex]['is_full'] = ($capacity > 0 && $occSpotsLeft <= 0);
            $occRows[$occurrenceIndex]['is_guaranteed'] = ($minParticipants > 0 && $occBooked >= $minParticipants);
            $occRows[$occurrenceIndex]['below_min'] = ($minParticipants > 0 && $capacity > 0 && $occBooked < $minParticipants);
            $occRows[$occurrenceIndex]['above_min'] = ($minParticipants > 0 && $capacity > 0 && $occBooked >= $minParticipants);
            $occRows[$occurrenceIndex]['formatted_date'] = format_event_date((string) ($occurrenceRow['start_at'] ?? ''), (string) ($occurrenceRow['end_at'] ?? ''));
            $occRows[$occurrenceIndex]['detail_url'] = app_url('workshop', $occQuery);
        }

        $row['occurrences'] = $occRows;

        if (!empty($occRows)) {
            $firstOccurrence = $occRows[0];
            $row['booked'] = (int) ($firstOccurrence['booked'] ?? 0);
            $row['event_date'] = (string) ($firstOccurrence['start_at'] ?? '');
            $row['event_date_end'] = (string) ($firstOccurrence['end_at'] ?? '');
        }
    }

    $workshops[] = $row;
    $workshopsById[$workshopId] = $row;
}

$allAudiences = [];
foreach ($workshops as $w) {
    foreach (explode(',', $w['audiences']) as $a) {
        $a = trim($a);
        if ($a !== '') {
            $allAudiences[$a] = true;
        }
    }
}
$allAudiences = array_keys($allAudiences);

$audienceLabels = [
    'unternehmen' => 'Unternehmen',
    'ngo'         => 'NGOs',
    'verwaltung'  => 'Verwaltung',
    'bildung'     => 'Bildung',
];

$groupRows = [];
$groupRes = $db->query('SELECT id, name, description, sort_order FROM workshop_groups WHERE active = 1 ORDER BY sort_order ASC, id ASC');
while ($group = $groupRes->fetchArray(SQLITE3_ASSOC)) {
    $groupRows[(int) $group['id']] = [
        'id' => (int) $group['id'],
        'name' => (string) $group['name'],
        'description' => (string) ($group['description'] ?? ''),
        'workshops' => [],
    ];
}

$assignedWorkshopIds = [];
if (!empty($groupRows)) {
    $mapRes = $db->query('SELECT m.group_id, m.workshop_id FROM workshop_group_workshops m JOIN workshop_groups g ON g.id = m.group_id AND g.active = 1 JOIN workshops w ON w.id = m.workshop_id AND w.active = 1 ORDER BY g.sort_order ASC, g.id ASC, m.sort_order ASC, w.sort_order ASC, w.id ASC');
    while ($map = $mapRes->fetchArray(SQLITE3_ASSOC)) {
        $groupId = (int) ($map['group_id'] ?? 0);
        $workshopId = (int) ($map['workshop_id'] ?? 0);
        if (!isset($groupRows[$groupId], $workshopsById[$workshopId])) {
            continue;
        }
        $groupRows[$groupId]['workshops'][] = $workshopsById[$workshopId];
        $assignedWorkshopIds[$workshopId] = true;
    }
}

$workshopSections = [];
foreach ($groupRows as $group) {
    if (empty($group['workshops'])) {
        continue;
    }
    $workshopSections[] = [
        'key' => 'group-' . $group['id'],
        'title' => $group['name'],
        'description' => $group['description'],
        'workshops' => $group['workshops'],
    ];
}

$ungroupedWorkshops = [];
foreach ($workshops as $workshopRow) {
    if (!isset($assignedWorkshopIds[(int) $workshopRow['id']])) {
        $ungroupedWorkshops[] = $workshopRow;
    }
}

if (empty($workshopSections)) {
    $workshopSections[] = [
        'key' => 'all-workshops',
        'title' => 'Alle Workshops',
        'description' => '',
        'workshops' => $workshops,
    ];
} elseif (!empty($ungroupedWorkshops)) {
    $workshopSections[] = [
        'key' => 'ungrouped-workshops',
        'title' => 'Weitere Workshops',
        'description' => '',
        'workshops' => $ungroupedWorkshops,
    ];
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= e(SITE_NAME) ?></title>
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
    <meta name="description" content="Praktische Workshops zu Desinformation, FIMI-Abwehr und Medienkompetenz &ndash; f&uuml;r Unternehmen, NGOs und &ouml;ffentliche Einrichtungen.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body>

<a href="#main-content" class="skip-link">Direkt zum Inhalt</a>

<nav role="navigation" aria-label="Hauptnavigation">
    <div class="nav-inner">
        <a href="https://workshops.disinfoconsulting.eu/" class="nav-logo" aria-label="Disinfo Consulting Workshops &ndash; Startseite">
            <img src="https://disinfoconsulting.eu/wp-content/uploads/2026/02/Gemini_Generated_Image_vjal0gvjal0gvjal-scaled.png"
                 alt="Disinfo Consulting" height="30">
        </a>
        <button class="nav-burger" aria-label="Navigation &ouml;ffnen" aria-expanded="false" id="burger">
            <span></span><span></span><span></span>
        </button>
        <ul class="nav-links" id="nav-links" role="list">
            <li><button type="button" class="theme-toggle" id="themeToggle" aria-pressed="false">&#9790;</button></li>
            <li><a href="<?= e(app_url('kontakt')) ?>" class="nav-cta">Kontakt</a></li>
        </ul>
    </div>
</nav>

<section id="hero" role="banner" aria-label="Seiteneinleitung">
    <div class="hero-noise"></div>
    <div class="hero-spotlight"></div>
    <div class="hero-content">
        <span class="hero-eyebrow">Disinfo Consulting &ndash; Workshops</span>
        <h1 class="hero-h1">Kompetenz, die sch&uuml;tzt.<br>Wissen, das wirkt.</h1>
        <p class="hero-p">
            Praktische Workshops zur Abwehr von Informationsmanipulation &ndash; ma&szlig;geschneidert f&uuml;r Unternehmen, NGOs und &ouml;ffentliche Einrichtungen. Von Experten mit echter operativer Erfahrung.
        </p>
    </div>
</section>

<main id="main-content">
<section id="workshops" class="section">
    <div class="container">
        <span class="section-eyebrow fade-in">Unser Angebot</span>
        <h2 class="section-title fade-in">W&auml;hlen Sie Ihr Format.</h2>
        <p class="section-sub fade-in">Alle Workshops sind auf Deutsch oder Englisch buchbar und k&ouml;nnen auf Ihre Organisation angepasst werden.</p>

        <div class="filter-row fade-in" role="group" aria-label="Workshop-Filter">
            <button class="filter-btn active" data-filter="all">Alle</button>
            <?php foreach ($allAudiences as $aud): ?>
                <button class="filter-btn" data-filter="<?= e($aud) ?>"><?= e($audienceLabels[$aud] ?? ucfirst($aud)) ?></button>
            <?php endforeach; ?>
        </div>

        <div class="workshop-groups" id="workshopGroups">
            <?php $cardDelayIndex = 0; ?>
            <?php foreach ($workshopSections as $section): ?>
                <section class="workshop-group-section fade-in" data-group-section="<?= e($section['key']) ?>">
                    <header class="workshop-group-head">
                        <h3 class="workshop-group-title"><?= e($section['title']) ?></h3>
                        <?php if (trim((string) ($section['description'] ?? '')) !== ''): ?>
                            <p class="workshop-group-sub"><?= e((string) $section['description']) ?></p>
                        <?php endif; ?>
                    </header>

                    <div class="workshops-grid workshop-group-grid">
                        <?php foreach ($section['workshops'] as $w):
                            $capacity     = (int) $w['capacity'];
                            $audLabels    = array_filter(array_map('trim', explode(',', $w['audience_labels'])));
                            $isOpen       = ($w['workshop_type'] ?? 'auf_anfrage') === 'open';
                            $price        = (float) ($w['price_netto'] ?? 0);
                            $currency     = $w['price_currency'] ?? 'EUR';
                            $minP         = (int) ($w['min_participants'] ?? 0);
                            $location     = $w['location']       ?? '';
                            $occurrences  = is_array($w['occurrences'] ?? null) ? $w['occurrences'] : [];
                            $selectedOccurrence = !empty($occurrences) ? $occurrences[0] : null;

                            $booked       = $selectedOccurrence !== null ? (int) ($selectedOccurrence['booked'] ?? 0) : (int) ($w['booked'] ?? 0);
                            $spotsLeft    = $selectedOccurrence !== null
                                ? ($selectedOccurrence['spots_left'] ?? ($capacity > 0 ? max(0, $capacity - $booked) : null))
                                : ($capacity > 0 ? max(0, $capacity - $booked) : null);
                            $fillPct      = $selectedOccurrence !== null
                                ? (int) ($selectedOccurrence['fill_pct'] ?? 0)
                                : (($capacity > 0) ? min(100, round(($booked / $capacity) * 100)) : 0);
                            $fillClass    = $selectedOccurrence !== null
                                ? (string) ($selectedOccurrence['fill_class'] ?? '')
                                : (($fillPct >= 85) ? 'high' : (($fillPct >= 50) ? 'medium' : ''));
                            $eventDate    = $selectedOccurrence !== null ? (string) ($selectedOccurrence['start_at'] ?? '') : (string) ($w['event_date'] ?? '');
                            $eventDateEnd = $selectedOccurrence !== null ? (string) ($selectedOccurrence['end_at'] ?? '') : (string) ($w['event_date_end'] ?? '');
                            $eventDateFormatted = $selectedOccurrence !== null
                                ? (string) ($selectedOccurrence['formatted_date'] ?? format_event_date($eventDate, $eventDateEnd))
                                : format_event_date($eventDate, $eventDateEnd);
                            $belowMin     = ($minP > 0 && $capacity > 0 && $booked < $minP);
                            $aboveMin     = ($minP > 0 && $capacity > 0 && $booked >= $minP);
                            $isGuaranteed = ($isOpen && $minP > 0 && $booked >= $minP);
                            $minPct       = ($minP > 0 && $capacity > 0) ? min(100, round(($minP / $capacity) * 100)) : 0;

                            $occurrencePayload = [];
                            foreach ($occurrences as $occurrenceRow) {
                                $occurrencePayload[] = [
                                    'id' => (int) ($occurrenceRow['id'] ?? 0),
                                    'date' => (string) ($occurrenceRow['formatted_date'] ?? ''),
                                    'booked' => (int) ($occurrenceRow['booked'] ?? 0),
                                    'spotsLeft' => $occurrenceRow['spots_left'] ?? null,
                                    'fillPct' => (int) ($occurrenceRow['fill_pct'] ?? 0),
                                    'fillClass' => (string) ($occurrenceRow['fill_class'] ?? ''),
                                    'isGuaranteed' => (bool) ($occurrenceRow['is_guaranteed'] ?? false),
                                    'belowMin' => (bool) ($occurrenceRow['below_min'] ?? false),
                                    'aboveMin' => (bool) ($occurrenceRow['above_min'] ?? false),
                                    'detailUrl' => (string) ($occurrenceRow['detail_url'] ?? app_url('workshop', ['slug' => (string) $w['slug']])),
                                ];
                            }

                            $defaultDetailUrl = !empty($occurrencePayload)
                                ? (string) ($occurrencePayload[0]['detailUrl'] ?? app_url('workshop', ['slug' => (string) $w['slug']]))
                                : app_url('workshop', ['slug' => (string) $w['slug']]);
                        ?>
                        <article class="workshop-card <?= $w['featured'] ? 'featured' : '' ?> fade-in"
                                 data-audiences="<?= e($w['audiences']) ?>"
                                 data-occurrence-payload="<?= e(json_for_html($occurrencePayload)) ?>"
                                 data-occurrence-index="0"
                                 data-min-participants="<?= $minP ?>"
                                 data-capacity="<?= $capacity ?>"
                                 style="transition-delay:<?= ($cardDelayIndex++) * 0.08 ?>s">
                            <?php if ($w['featured']): ?>
                                <div class="featured-badge">Empfohlen</div>
                            <?php endif; ?>

                            <div class="badge-row badge-row-card">
                                <?php if ($isOpen): ?>
                                    <?php if ($isGuaranteed): ?>
                                        <span class="type-badge type-badge-confirmed js-status-badge"><span class="badge-dot"></span>Findet statt</span>
                                    <?php elseif ($minP > 0): ?>
                                        <span class="type-badge type-badge-open-pending js-status-badge"><span class="badge-dot"></span>Mindestanzahl offen</span>
                                    <?php else: ?>
                                        <span class="type-badge type-badge-open js-status-badge"><span class="badge-dot"></span>Anmeldung offen</span>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="type-badge type-badge-anfrage"><span class="badge-dot"></span>Auf Anfrage</span>
                                <?php endif; ?>
                                <div class="card-tag"><span class="card-tag-dot"></span><?= e($w['tag_label']) ?></div>
                            </div>

                            <h3 class="card-h3"><?= e($w['title']) ?></h3>
                            <?php
                                $cardDesc = trim($w['description_short'] ?? '');
                                if (!$cardDesc) {
                                    $cardDesc = mb_substr(strip_tags($w['description']), 0, 155);
                                    if (mb_strlen($w['description']) > 155) $cardDesc .= '...';
                                }
                            ?>
                            <p class="card-main-text"><?= e($cardDesc) ?></p>

                            <?php if ($isOpen && $eventDate): ?>
                            <div class="event-details-panel">
                                <?php if (count($occurrencePayload) > 1): ?>
                                <div class="event-occurrence-switch" data-occurrence-switch>
                                    <button type="button" class="event-occurrence-btn" data-occurrence-prev aria-label="Vorheriger Termin">&#10094;</button>
                                    <span class="event-occurrence-count"><span data-occurrence-current>1</span> / <?= count($occurrencePayload) ?></span>
                                    <button type="button" class="event-occurrence-btn" data-occurrence-next aria-label="Naechster Termin">&#10095;</button>
                                </div>
                                <?php endif; ?>
                                <div class="event-details-row">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                                    <span class="js-occurrence-date"><?= e($eventDateFormatted) ?></span>
                                </div>
                                <?php if ($location): ?>
                                <div class="event-details-row">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                                    <span class="js-occurrence-location"><?= e($location) ?></span>
                                </div>
                                <?php endif; ?>
                                <div class="event-details-price-row">
                                    <?php if ($price > 0): ?>
                                        <span class="event-price-main">
                                            <?= e(format_price($price, $currency)) ?>
                                            <span class="event-price-label">&nbsp;/ Person &middot; netto</span>
                                        </span>
                                    <?php else: ?>
                                        <span class="event-price-main event-price-onrequest">Preis auf Anfrage</span>
                                    <?php endif; ?>
                                    <?php if ($minP > 0): ?>
                                        <span class="min-p-badge">
                                            <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                            min. <?= $minP ?>
                                        </span>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <?php else: ?>
                            <?php if ($price > 0): ?>
                                <div class="price-pill">
                                    <span><?= e(format_price($price, $currency)) ?></span>
                                    <span class="price-label">/ Person &middot; netto</span>
                                </div>
                            <?php else: ?>
                                <div class="price-pill price-pill-free">Preis auf Anfrage</div>
                            <?php endif; ?>
                            <?php endif; ?>

                            <div class="card-meta">
                                <?php if ($isOpen): ?>
                                <span class="meta-pill">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/></svg>
                                    Fester Termin
                                </span>
                                <?php endif; ?>
                                <?php if ($capacity > 0): ?>
                                <span class="meta-pill">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
                                    <?= $capacity ?> Pl&auml;tze
                                </span>
                                <?php endif; ?>
                                <span class="meta-pill">
                                    <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
                                    <?= e($w['format']) ?>
                                </span>
                            </div>

                            <?php if ($minP > 0): ?>
                            <div class="min-participants-note js-occurrence-min-note">
                                <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="12" y1="8" x2="12" y2="12"/><line x1="12" y1="16" x2="12.01" y2="16"/></svg>
                                <?php if ($isOpen): ?>
                                    <span class="js-occurrence-min-note-text"><?= $isGuaranteed ? 'Mindestanzahl erreicht: findet statt.' : 'Findet statt ab ' . $minP . ' Teilnehmer:innen.' ?></span>
                                <?php else: ?>
                                    Mindestens <?= $minP ?> Teilnehmende erforderlich
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($capacity > 0): ?>
                            <div class="seats-indicator js-occurrence-seats <?= $belowMin ? 'below-min' : ($aboveMin ? 'above-min' : '') ?>"
                                 style="margin-top:1rem;"
                                 <?= ($minP > 0) ? 'title="Mindest-Teilnehmende: ' . $minP . '"' : '' ?>>
                                <div class="seats-bar">
                                    <div class="seats-bar-track">
                                        <div class="seats-bar-fill js-occurrence-fill <?= $fillClass ?>" style="width:<?= $fillPct ?>%"></div>
                                    </div>
                                    <?php if ($minP > 0 && $capacity > 0): ?>
                                    <div class="seats-bar-marker" style="left:<?= $minPct ?>%">
                                        <span class="seats-bar-marker-label">min <?= $minP ?></span>
                                    </div>
                                    <?php endif; ?>
                                </div>
                                <span class="seats-text js-occurrence-seats-text"><?= $spotsLeft ?> / <?= $capacity ?> frei</span>
                            </div>
                            <?php endif; ?>

                            <div class="card-divider" style="margin-top:1.25rem;"></div>
                            <p class="card-audience">Zielgruppen</p>
                            <div class="card-audience-tags">
                                <?php foreach ($audLabels as $al): ?>
                                    <span class="aud-tag"><?= e($al) ?></span>
                                <?php endforeach; ?>
                            </div>
                            <a href="<?= e($defaultDetailUrl) ?>" class="btn-book js-occurrence-detail-link">Details &amp; Buchen &rarr;</a>
                        </article>
                        <?php endforeach; ?>
                    </div>
                </section>
            <?php endforeach; ?>
        </div>
    </div>
</section>

<section id="cta-section">
    <div class="container">
        <h2 class="cta-h2 fade-in">Bereit f&uuml;r den n&auml;chsten Schritt?</h2>
        <p class="cta-sub fade-in" style="transition-delay:0.1s">
            Kein Workshop passt exakt? Kontaktieren Sie uns f&uuml;r ein individuelles Konzept &ndash; wir entwickeln auch vollst&auml;ndig ma&szlig;geschneiderte Formate.
        </p>
        <div class="cta-btns fade-in" style="transition-delay:0.2s">
            <a href="<?= e(app_url('kontakt')) ?>" class="btn-primary">Kontakt aufnehmen</a>
        </div>
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

const observer = new IntersectionObserver((entries) => {
    entries.forEach(entry => {
        if (entry.isIntersecting) { entry.target.classList.add('visible'); observer.unobserve(entry.target); }
    });
}, { threshold: 0.08 });
document.querySelectorAll('.fade-in').forEach(el => observer.observe(el));

const filterBtns = document.querySelectorAll('.filter-btn');
const cards = document.querySelectorAll('.workshop-group-section .workshop-card');
const groupSections = document.querySelectorAll('[data-group-section]');

const initOccurrenceCards = () => {
    document.querySelectorAll('.workshop-card').forEach(card => {
        const payloadRaw = card.dataset.occurrencePayload || '[]';
        let payload = [];
        try {
            payload = JSON.parse(payloadRaw);
        } catch (e) {
            payload = [];
        }

        if (!Array.isArray(payload) || payload.length === 0) {
            return;
        }

        let index = parseInt(card.dataset.occurrenceIndex || '0', 10);
        if (!Number.isFinite(index) || index < 0 || index >= payload.length) {
            index = 0;
        }

        const prevBtn = card.querySelector('[data-occurrence-prev]');
        const nextBtn = card.querySelector('[data-occurrence-next]');
        const currentEl = card.querySelector('[data-occurrence-current]');
        const dateEl = card.querySelector('.js-occurrence-date');
        const statusBadge = card.querySelector('.js-status-badge');
        const minNoteText = card.querySelector('.js-occurrence-min-note-text');
        const seatsWrap = card.querySelector('.js-occurrence-seats');
        const seatsFill = card.querySelector('.js-occurrence-fill');
        const seatsText = card.querySelector('.js-occurrence-seats-text');
        const detailLink = card.querySelector('.js-occurrence-detail-link');

        const minParticipants = parseInt(card.dataset.minParticipants || '0', 10) || 0;
        const capacity = parseInt(card.dataset.capacity || '0', 10) || 0;

        let animationTimer = null;
        let animating = false;

        const startAnimation = (direction) => {
            card.classList.remove('is-occurrence-next', 'is-occurrence-prev', 'is-occurrence-animating');
            // Reflow so repeated quick switches still animate.
            void card.offsetWidth;
            card.classList.add('is-occurrence-animating', direction === 'prev' ? 'is-occurrence-prev' : 'is-occurrence-next');

            if (animationTimer) {
                window.clearTimeout(animationTimer);
            }
            animationTimer = window.setTimeout(() => {
                card.classList.remove('is-occurrence-next', 'is-occurrence-prev', 'is-occurrence-animating');
                animating = false;
            }, 360);
        };

        const applyOccurrence = (nextIndex, direction = 'next', withAnimation = true) => {
            if (withAnimation) {
                if (animating) {
                    return;
                }
                animating = true;
                startAnimation(direction);
            }

            index = ((nextIndex % payload.length) + payload.length) % payload.length;
            card.dataset.occurrenceIndex = String(index);

            const occurrence = payload[index] || {};

            if (dateEl) {
                dateEl.textContent = String(occurrence.date || '');
            }

            if (currentEl) {
                currentEl.textContent = String(index + 1);
            }

            if (detailLink && occurrence.detailUrl) {
                detailLink.setAttribute('href', String(occurrence.detailUrl));
            }

            if (statusBadge) {
                statusBadge.classList.remove('type-badge-confirmed', 'type-badge-open-pending', 'type-badge-open');
                if (minParticipants > 0 && occurrence.isGuaranteed) {
                    statusBadge.classList.add('type-badge-confirmed');
                    statusBadge.innerHTML = '<span class="badge-dot"></span>Findet statt';
                } else if (minParticipants > 0) {
                    statusBadge.classList.add('type-badge-open-pending');
                    statusBadge.innerHTML = '<span class="badge-dot"></span>Mindestanzahl offen';
                } else {
                    statusBadge.classList.add('type-badge-open');
                    statusBadge.innerHTML = '<span class="badge-dot"></span>Anmeldung offen';
                }
            }

            if (minNoteText && minParticipants > 0) {
                minNoteText.textContent = occurrence.isGuaranteed
                    ? 'Mindestanzahl erreicht: findet statt.'
                    : ('Findet statt ab ' + minParticipants + ' Teilnehmer:innen.');
            }

            if (seatsWrap) {
                seatsWrap.classList.remove('below-min', 'above-min');
                if (occurrence.belowMin) {
                    seatsWrap.classList.add('below-min');
                }
                if (occurrence.aboveMin) {
                    seatsWrap.classList.add('above-min');
                }
            }

            if (seatsFill) {
                const fillPct = Number(occurrence.fillPct || 0);
                seatsFill.style.width = String(Math.max(0, Math.min(100, fillPct))) + '%';
                seatsFill.classList.remove('high', 'medium');
                if (occurrence.fillClass === 'high' || occurrence.fillClass === 'medium') {
                    seatsFill.classList.add(occurrence.fillClass);
                }
            }

            if (seatsText && capacity > 0) {
                const booked = Number(occurrence.booked || 0);
                const spotsLeft = (occurrence.spotsLeft === null || typeof occurrence.spotsLeft === 'undefined')
                    ? Math.max(0, capacity - booked)
                    : Number(occurrence.spotsLeft || 0);
                seatsText.textContent = String(spotsLeft) + ' / ' + String(capacity) + ' frei';
            }
        };

        if (prevBtn) {
            prevBtn.addEventListener('click', () => applyOccurrence(index - 1, 'prev', true));
        }
        if (nextBtn) {
            nextBtn.addEventListener('click', () => applyOccurrence(index + 1, 'next', true));
        }

        applyOccurrence(index, 'next', false);
    });
};
const applyFilter = (filter) => {
    cards.forEach(card => {
        const audiences = (card.dataset.audiences || '')
            .split(',')
            .map(v => v.trim())
            .filter(Boolean);
        const show = filter === 'all' || audiences.includes(filter);
        card.hidden = !show;
        card.style.display = show ? '' : 'none';
    });

    groupSections.forEach(section => {
        const visibleCards = Array.from(section.querySelectorAll('.workshop-card'))
            .filter(card => card.style.display !== 'none').length;
        section.hidden = visibleCards === 0;
        section.style.display = visibleCards === 0 ? 'none' : '';
    });
};

filterBtns.forEach(btn => {
    btn.addEventListener('click', () => {
        filterBtns.forEach(b => b.classList.remove('active'));
        btn.classList.add('active');
        applyFilter(btn.dataset.filter || 'all');
    });
});

initOccurrenceCards();
applyFilter('all');
</script>
<script src="/assets/site-ui.js"></script>

</body>
</html>

