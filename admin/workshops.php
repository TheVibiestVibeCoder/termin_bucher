<?php
require __DIR__ . '/../includes/config.php';
require_admin();

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('DELETE FROM workshops WHERE id = :id');
        $stmt->bindValue(':id', (int) $_POST['delete_id'], SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Workshop gelöscht.');
    }
    redirect('workshops.php');
}

// Handle toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('UPDATE workshops SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END, updated_at = datetime("now") WHERE id = :id');
        $stmt->bindValue(':id', (int) $_POST['toggle_id'], SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Status aktualisiert.');
    }
    redirect('workshops.php');
}

// Fetch all workshops
$result = $db->query('SELECT * FROM workshops ORDER BY sort_order ASC, id ASC');
$workshops = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $row['booking_count'] = count_confirmed_bookings($db, $row['id']);
    $workshops[] = $row;
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Workshops verwalten – Admin</title>
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
            <h1>Workshops</h1>
            <a href="workshop-edit.php" class="btn-admin">+ Neuer Workshop</a>
        </div>

        <?= render_flash() ?>

        <?php if (empty($workshops)): ?>
            <p style="color:var(--muted);">Noch keine Workshops vorhanden. <a href="workshop-edit.php" style="color:#fff;">Jetzt erstellen</a></p>
        <?php else: ?>
            <table class="admin-table">
                <thead>
                    <tr>
                        <th>Titel</th>
                        <th>Format</th>
                        <th>Kapazität</th>
                        <th>Gebucht</th>
                        <th>Status</th>
                        <th>Reihenfolge</th>
                        <th>Aktionen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($workshops as $w): ?>
                    <tr>
                        <td style="color:#fff;">
                            <?= e($w['title']) ?>
                            <?php if ($w['featured']): ?>
                                <span style="font-size:0.65rem;background:#fff;color:#000;padding:2px 6px;border-radius:3px;margin-left:0.5rem;">Featured</span>
                            <?php endif; ?>
                        </td>
                        <td><?= e($w['tag_label']) ?></td>
                        <td><?= (int) $w['capacity'] ?: '∞' ?></td>
                        <td><?= $w['booking_count'] ?></td>
                        <td>
                            <?php if ($w['active']): ?>
                                <span class="status-badge status-confirmed">Aktiv</span>
                            <?php else: ?>
                                <span class="status-badge status-pending">Inaktiv</span>
                            <?php endif; ?>
                        </td>
                        <td><?= (int) $w['sort_order'] ?></td>
                        <td>
                            <div class="admin-actions">
                                <a href="workshop-edit.php?id=<?= $w['id'] ?>" class="btn-admin">Bearbeiten</a>
                                <a href="bookings.php?workshop_id=<?= $w['id'] ?>" class="btn-admin">Buchungen</a>
                                <form method="POST" style="display:inline;">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="toggle_id" value="<?= $w['id'] ?>">
                                    <button type="submit" class="btn-admin"><?= $w['active'] ? 'Deaktivieren' : 'Aktivieren' ?></button>
                                </form>
                                <form method="POST" style="display:inline;" onsubmit="return confirm('Workshop wirklich löschen? Alle Buchungen gehen verloren!')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_id" value="<?= $w['id'] ?>">
                                    <button type="submit" class="btn-admin btn-danger">Löschen</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>
</body>
</html>
