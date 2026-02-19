<?php
/**
 * Install script – seeds the 6 workshops from the mockup.
 * Run once, then delete this file for security.
 *
 * Usage: php install.php  (CLI)
 *   or visit /install.php in browser (will check for admin session)
 */

require __DIR__ . '/includes/config.php';

// If accessed via browser, require admin login
if (php_sapi_name() !== 'cli') {
    if (!is_admin()) {
        die('Please log in to admin first, then visit this page. <a href="admin/index.php">Login</a>');
    }
}

// Check if workshops already exist
$count = (int) $db->querySingle('SELECT COUNT(*) FROM workshops');
if ($count > 0) {
    $msg = "Database already contains {$count} workshop(s). Skipping seed.\n";
    if (php_sapi_name() === 'cli') {
        echo $msg;
    } else {
        echo "<p>{$msg}</p><p><a href='admin/workshops.php'>Go to admin</a></p>";
    }
    exit;
}

$workshops = [
    [
        'title'           => 'FIMI-Grundlagen: Erkennen & Verstehen',
        'slug'            => 'fimi-grundlagen',
        'description'     => 'Einführung in Foreign Information Manipulation and Interference. Ihre Mitarbeitenden lernen, Desinformationskampagnen zu erkennen, einzuordnen und erste Gegenmaßnahmen zu ergreifen.',
        'tag_label'       => 'Halbtag – 4 h',
        'capacity'        => 30,
        'audiences'       => 'unternehmen,ngo,verwaltung',
        'audience_labels' => 'Unternehmen,NGOs,Verwaltung',
        'format'          => 'Präsenz od. online',
        'featured'        => 1,
        'sort_order'      => 1,
    ],
    [
        'title'           => 'Strategische Resilienz & Krisenreaktion',
        'slug'            => 'strategische-resilienz',
        'description'     => 'Fortgeschrittenes Format für Führungskräfte und Kommunikationsteams. Entwicklung eines organisationsspezifischen Abwehr- und Reaktionsplans für den Ernstfall.',
        'tag_label'       => 'Ganztag – 7 h',
        'capacity'        => 20,
        'audiences'       => 'unternehmen,verwaltung',
        'audience_labels' => 'Unternehmen,Verwaltung',
        'format'          => 'Präsenz',
        'featured'        => 0,
        'sort_order'      => 2,
    ],
    [
        'title'           => 'Medienkompetenz für Lehrkräfte',
        'slug'            => 'medienkompetenz-lehrkraefte',
        'description'     => 'Speziell entwickelt für Bildungseinrichtungen. Lehrkräfte erhalten praxisnahe Werkzeuge, um Desinformation im Unterricht zu thematisieren und Schüler:innen zu stärken.',
        'tag_label'       => '2 h – Kurzformat',
        'capacity'        => 40,
        'audiences'       => 'bildung,ngo',
        'audience_labels' => 'Bildung,NGOs',
        'format'          => 'Flexibel',
        'featured'        => 0,
        'sort_order'      => 3,
    ],
    [
        'title'           => 'Hybride Bedrohungen & OSINT',
        'slug'            => 'hybride-bedrohungen-osint',
        'description'     => 'Einführung in Open-Source-Intelligence-Methoden zur Erkennung hybrider Bedrohungen. Mit praktischen Übungen zur Quellenanalyse und Netzwerkerkennung.',
        'tag_label'       => 'Halbtag – 3 h',
        'capacity'        => 15,
        'audiences'       => 'verwaltung,ngo',
        'audience_labels' => 'Verwaltung,NGOs',
        'format'          => 'Präsenz od. online',
        'featured'        => 0,
        'sort_order'      => 4,
    ],
    [
        'title'           => 'Awareness Campaign Design',
        'slug'            => 'awareness-campaign-design',
        'description'     => 'Entwickeln Sie mit uns eine interne oder öffentliche Awareness-Kampagne. Interaktiver Workshop-Prozess inklusive Konzept, Messaging und Rollout-Strategie.',
        'tag_label'       => 'Maßgeschneidert',
        'capacity'        => 0,
        'audiences'       => 'ngo,unternehmen',
        'audience_labels' => 'NGOs,Unternehmen',
        'format'          => 'Flexibel',
        'featured'        => 0,
        'sort_order'      => 5,
    ],
    [
        'title'           => 'Keynote: Desinformation als Geschäftsrisiko',
        'slug'            => 'keynote-desinformation-geschaeftsrisiko',
        'description'     => 'Kompakter Impuls für Konferenzen, Tagungen und interne Veranstaltungen. Mit aktuellen Fallbeispielen, wirtschaftlichen Schadensszenarien und konkreten Handlungsempfehlungen.',
        'tag_label'       => 'Vortrag – 1 h',
        'capacity'        => 0,
        'audiences'       => 'verwaltung,unternehmen,ngo',
        'audience_labels' => 'Alle Sektoren',
        'format'          => 'Präsenz od. online',
        'featured'        => 0,
        'sort_order'      => 6,
    ],
];

$stmt = $db->prepare('
    INSERT INTO workshops (title, slug, description, tag_label, capacity, audiences, audience_labels, format, featured, sort_order, active)
    VALUES (:title, :slug, :desc, :tag, :cap, :aud, :audl, :fmt, :feat, :sort, 1)
');

foreach ($workshops as $w) {
    $stmt->bindValue(':title', $w['title'],           SQLITE3_TEXT);
    $stmt->bindValue(':slug',  $w['slug'],            SQLITE3_TEXT);
    $stmt->bindValue(':desc',  $w['description'],     SQLITE3_TEXT);
    $stmt->bindValue(':tag',   $w['tag_label'],       SQLITE3_TEXT);
    $stmt->bindValue(':cap',   $w['capacity'],        SQLITE3_INTEGER);
    $stmt->bindValue(':aud',   $w['audiences'],       SQLITE3_TEXT);
    $stmt->bindValue(':audl',  $w['audience_labels'], SQLITE3_TEXT);
    $stmt->bindValue(':fmt',   $w['format'],          SQLITE3_TEXT);
    $stmt->bindValue(':feat',  $w['featured'],        SQLITE3_INTEGER);
    $stmt->bindValue(':sort',  $w['sort_order'],      SQLITE3_INTEGER);
    $stmt->execute();
    $stmt->reset();
}

$msg = count($workshops) . " workshops seeded successfully!\n";
if (php_sapi_name() === 'cli') {
    echo $msg;
    echo "You can now visit your site. Remember to delete install.php.\n";
} else {
    echo "<p style='font-family:sans-serif;padding:2rem;'>{$msg}<br><br>";
    echo "<strong>IMPORTANT:</strong> Delete this file (install.php) for security.<br><br>";
    echo "<a href='index.php'>View site</a> &nbsp;|&nbsp; <a href='admin/dashboard.php'>Admin dashboard</a></p>";
}
