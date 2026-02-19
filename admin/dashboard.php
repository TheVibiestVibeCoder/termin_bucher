<?php
require __DIR__ . '/../includes/config.php';
require_admin();

// Stats
$totalWorkshops = (int) $db->querySingle('SELECT COUNT(*) FROM workshops WHERE active = 1');
$totalBookings  = (int) $db->querySingle('SELECT COUNT(*) FROM bookings WHERE confirmed = 1');
$pendingBookings = (int) $db->querySingle('SELECT COUNT(*) FROM bookings WHERE confirmed = 0');
$totalParticipants = (int) $db->querySingle('SELECT COALESCE(SUM(participants), 0) FROM bookings WHERE confirmed = 1');

// Recent bookings
$recentResult = $db->query('
    SELECT b.*, w.title AS workshop_title
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
    <title>Dashboard – Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>Dashboard</h1>
        </div>

        <?= render_flash() ?>

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
                <div class="stat-card-label">Ausstehende Bestätigungen</div>
                <div class="stat-card-num"><?= $pendingBookings ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-card-label">Gesamtteilnehmer</div>
                <div class="stat-card-num"><?= $totalParticipants ?></div>
            </div>
        </div>

        <h2 style="font-family:var(--font-h);font-size:1.5rem;font-weight:400;margin-bottom:1.5rem;">Letzte Buchungen</h2>

        <?php if (empty($recentBookings)): ?>
            <p style="color:var(--muted);">Noch keine Buchungen vorhanden.</p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>E-Mail</th>
                        <th>Workshop</th>
                        <th>Teilnehmer</th>
                        <th>Status</th>
                        <th>Datum</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($recentBookings as $b): ?>
                    <tr>
                        <td style="color:#fff;"><?= e($b['name']) ?></td>
                        <td><?= e($b['email']) ?></td>
                        <td><?= e($b['workshop_title']) ?></td>
                        <td><?= (int) $b['participants'] ?></td>
                        <td>
                            <?php if ($b['confirmed']): ?>
                                <span class="status-badge status-confirmed">Bestätigt</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">Ausstehend</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(date('d.m.Y H:i', strtotime($b['created_at']))) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
