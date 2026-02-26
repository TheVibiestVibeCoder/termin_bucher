<?php
require __DIR__ . '/../includes/config.php';
require_admin();

// ── Stats ────────────────────────────────────────────────────────────────────
$totalWorkshops    = (int) $db->querySingle('SELECT COUNT(*) FROM workshops WHERE active = 1');
$totalBookings     = (int) $db->querySingle('SELECT COUNT(*) FROM bookings WHERE confirmed = 1');
$pendingBookings   = (int) $db->querySingle('SELECT COUNT(*) FROM bookings WHERE confirmed = 0');
$totalParticipants = (int) $db->querySingle('SELECT COALESCE(SUM(participants), 0) FROM bookings WHERE confirmed = 1');

// ── Revenue per workshop (confirmed bookings × price_netto) ──────────────────
// Group by currency so we can display mixed currencies correctly
$revenueResult = $db->query('
    SELECT
        w.id AS workshop_id,
        w.title,
        w.price_netto,
        w.price_currency,
        COALESCE(SUM(b.participants), 0) AS confirmed_participants,
        COUNT(b.id) AS confirmed_bookings
    FROM workshops w
    LEFT JOIN bookings b ON b.workshop_id = w.id AND b.confirmed = 1
    WHERE w.price_netto > 0
    GROUP BY w.id
    ORDER BY (w.price_netto * COALESCE(SUM(b.participants), 0)) DESC
');
$revenueByWorkshop = [];
$totalRevenueByCurrency = [];
while ($row = $revenueResult->fetchArray(SQLITE3_ASSOC)) {
    $rev = (float) $row['price_netto'] * (int) $row['confirmed_participants'];
    $row['revenue'] = $rev;
    $revenueByWorkshop[] = $row;
    $cur = $row['price_currency'];
    $totalRevenueByCurrency[$cur] = ($totalRevenueByCurrency[$cur] ?? 0) + $rev;
}

// ── Recent bookings ──────────────────────────────────────────────────────────
$recentResult = $db->query('
    SELECT b.*, w.id AS workshop_id, w.title AS workshop_title, w.price_netto, w.price_currency
    FROM bookings b
    JOIN workshops w ON b.workshop_id = w.id
    ORDER BY b.created_at DESC
    LIMIT 10
');
$recentBookings = [];
while ($row = $recentResult->fetchArray(SQLITE3_ASSOC)) {
    $recentBookings[] = $row;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
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
    <title>Dashboard – Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>Dashboard</h1>
        </div>

        <?= render_flash() ?>

        <!-- ── Stats row ── -->
        <div class="stats-row">
            <div class="stat-card">
                <div class="stat-card-label">Aktive Workshops</div>
                <div class="stat-card-num"><?= $totalWorkshops ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Bestätigte Buchungen</div>
                <div class="stat-card-num"><?= $totalBookings ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Ausstehend</div>
                <div class="stat-card-num"><?= $pendingBookings ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Teilnehmer:innen gesamt</div>
                <div class="stat-card-num"><?= $totalParticipants ?></div>
            </div>
        </div>

        <!-- ── Revenue section ── -->
        <h2 style="font-family:var(--font-h);font-size:1.5rem;font-weight:400;margin-bottom:1.25rem;">
            Umsatz (Netto)
        </h2>

        <?php if (empty($revenueByWorkshop)): ?>
            <div style="background:var(--surface);border:1px solid var(--border);border-radius:var(--radius);padding:1.5rem;margin-bottom:2.5rem;color:var(--muted);font-size:0.9rem;">
                Noch kein Umsatz. Setzen Sie Preise für Workshops, um diese Übersicht zu nutzen.
            </div>
        <?php else: ?>

            <!-- Total revenue tiles per currency -->
            <div class="stats-row" style="margin-bottom:1.5rem;">
                <?php foreach ($totalRevenueByCurrency as $cur => $total): ?>
                <div class="stat-card" style="border-color:var(--border-h);background:var(--surface-soft);">
                    <div class="stat-card-label">Gesamtumsatz (<?= e($cur) ?>) · Netto</div>
                    <div class="stat-card-num" style="font-size:1.6rem;">
                        <?= e(format_price($total, $cur)) ?>
                    </div>
                    <div style="font-size:0.72rem;color:var(--dim);margin-top:0.4rem;">zzgl. MwSt. · bestätigte Buchungen</div>
                </div>
                <?php endforeach; ?>
            </div>

            <!-- Per-workshop revenue breakdown -->
            <div style="overflow-x:auto;margin-bottom:2.5rem;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Workshop</th>
                        <th>Preis / Person</th>
                        <th>Best. Buchungen</th>
                        <th>Teilnehmer:innen</th>
                        <th>Umsatz (Netto)</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($revenueByWorkshop as $r): ?>
                    <tr>
                        <td style="color:var(--text);">
                            <a href="bookings.php?workshop_id=<?= (int) $r['workshop_id'] ?>" style="color:var(--text);text-decoration:none;border-bottom:1px solid var(--border-h);">
                                <?= e($r['title']) ?>
                            </a>
                        </td>
                        <td><?= e(format_price((float)$r['price_netto'], $r['price_currency'])) ?></td>
                        <td><?= (int) $r['confirmed_bookings'] ?></td>
                        <td><?= (int) $r['confirmed_participants'] ?></td>
                        <td style="color:var(--text);font-weight:500;">
                            <?= $r['confirmed_participants'] > 0
                                ? e(format_price($r['revenue'], $r['price_currency']))
                                : '<span style="color:var(--dim);">–</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>

        <!-- ── Recent bookings ── -->
        <h2 style="font-family:var(--font-h);font-size:1.5rem;font-weight:400;margin-bottom:1.25rem;">Letzte Buchungen</h2>

        <?php if (empty($recentBookings)): ?>
            <p style="color:var(--muted);">Noch keine Buchungen vorhanden.</p>
        <?php else: ?>
            <div style="overflow-x:auto;">
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Workshop</th>
                        <th>TN</th>
                        <th>Wert (Netto)</th>
                        <th>Status</th>
                        <th>Datum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $b):
                        $bPrice = (float) $b['price_netto'];
                        $bRev   = $bPrice * (int) $b['participants'];
                    ?>
                    <tr>
                        <td style="color:var(--text);"><?= e($b['name']) ?></td>
                        <td><?= e($b['email']) ?></td>
                        <td>
                            <a href="bookings.php?workshop_id=<?= (int) $b['workshop_id'] ?>" style="color:var(--text);text-decoration:none;border-bottom:1px solid var(--border-h);">
                                <?= e($b['workshop_title']) ?>
                            </a>
                        </td>
                        <td><?= (int) $b['participants'] ?></td>
                        <td>
                            <?php if ($bRev > 0): ?>
                                <?= e(format_price($bRev, $b['price_currency'])) ?>
                            <?php else: ?>
                                <span style="color:var(--dim);">–</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($b['confirmed']): ?>
                                <span class="status-badge status-confirmed">Bestätigt</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">Ausstehend</span>
                            <?php endif; ?>
                        </td>
                        <td style="white-space:nowrap;"><?= e(date('d.m.Y H:i', strtotime($b['created_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            </div>
        <?php endif; ?>
    </div>
</div>
<script src="../assets/site-ui.js"></script>
</body>
</html>
