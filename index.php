<?php
declare(strict_types=1);

require __DIR__ . '/src/bootstrap.php';

$workshops = get_live_workshops();

render_public_header('Workshops');
?>
<section class="hero">
    <span class="eyebrow">Workshops</span>
    <h1>Bookable Workshops fuer Unternehmen, NGOs und Verwaltung</h1>
</section>

<?php if ($workshops === []): ?>
    <section class="empty-state">
        <h2>Derzeit keine offenen Workshops</h2>
        <p>Bitte spaeter erneut pruefen oder direkt Kontakt aufnehmen.</p>
    </section>
<?php else: ?>
    <section class="cards-grid">
        <?php foreach ($workshops as $workshop): ?>
            <article class="workshop-card">
                <span class="badge"><?= e(mb_strtoupper((string) $workshop['language'])) ?></span>
                <h3><?= e($workshop['title']) ?></h3>
                <p class="card-short"><?= e($workshop['short_description']) ?></p>
                <ul class="meta-list">
                    <li><strong>Datum:</strong> <?= e(format_datetime_de((string) $workshop['date_starts'])) ?> Uhr</li>
                    <li><strong>Plaetze frei:</strong> <?= e((string) $workshop['seats_left']) ?></li>
                    <li><strong>Preis:</strong> <?= e(format_price_eur((int) $workshop['price_cents'])) ?></li>
                </ul>
                <a class="btn btn-primary" href="<?= e(base_url('workshop.php?id=' . (int) $workshop['id'])) ?>">Details und buchen</a>
            </article>
        <?php endforeach; ?>
    </section>
<?php endif; ?>
<?php
render_public_footer();

