<?php
require __DIR__ . '/../includes/config.php';
require __DIR__ . '/../includes/email.php';
require_admin();

function fetch_workshop_cancellation_recipients(SQLite3 $db, int $workshopId): array {
    if ($workshopId <= 0) {
        return [];
    }

    $rows = [];
    $stmt = $db->prepare('SELECT name, email FROM bookings WHERE workshop_id = :wid AND confirmed = 1 AND COALESCE(archived, 0) = 0 ORDER BY id ASC');
    $stmt->bindValue(':wid', $workshopId, SQLITE3_INTEGER);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $mail = trim((string) ($row['email'] ?? ''));
        if (!filter_var($mail, FILTER_VALIDATE_EMAIL)) {
            continue;
        }

        $rows[] = [
            'name' => trim((string) ($row['name'] ?? '')),
            'email' => $mail,
        ];
    }

    return $rows;
}

// Handle delete
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_id'])) {
    if (!csrf_verify()) {
        flash('error', 'Ungueltige Sitzung.');
        redirect(admin_url('workshops'));
    }

    $deleteId = max(0, (int) ($_POST['delete_id'] ?? 0));
    if ($deleteId <= 0) {
        flash('error', 'Workshop konnte nicht geloescht werden.');
        redirect(admin_url('workshops'));
    }

    $workshopStmt = $db->prepare('SELECT id, title FROM workshops WHERE id = :id LIMIT 1');
    $workshopStmt->bindValue(':id', $deleteId, SQLITE3_INTEGER);
    $workshopRow = $workshopStmt->execute()->fetchArray(SQLITE3_ASSOC);
    if (!$workshopRow) {
        flash('error', 'Workshop nicht gefunden.');
        redirect(admin_url('workshops'));
    }

    $recipients = fetch_workshop_cancellation_recipients($db, $deleteId);

    $deleteStmt = $db->prepare('DELETE FROM workshops WHERE id = :id');
    $deleteStmt->bindValue(':id', $deleteId, SQLITE3_INTEGER);
    $deleteResult = $deleteStmt->execute();

    if ($deleteResult !== false && $db->changes() === 1) {
        $sent = 0;
        $failed = 0;
        $workshopTitle = trim((string) ($workshopRow['title'] ?? ''));

        foreach ($recipients as $recipient) {
            $ok = send_booking_cancelled_email(
                (string) ($recipient['email'] ?? ''),
                (string) ($recipient['name'] ?? ''),
                $workshopTitle
            );
            if ($ok) {
                $sent++;
            } else {
                $failed++;
            }
        }

        $msg = 'Workshop geloescht.';
        if (!empty($recipients)) {
            $msg .= ' Stornomails gesendet: ' . $sent;
            if ($failed > 0) {
                $msg .= ', fehlgeschlagen: ' . $failed . '.';
            } else {
                $msg .= '.';
            }
        }
        flash('success', $msg);
    } else {
        flash('error', 'Workshop konnte nicht geloescht werden.');
    }

    redirect(admin_url('workshops'));
}

// Handle toggle active
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_id'])) {
    if (csrf_verify()) {
        $stmt = $db->prepare('UPDATE workshops SET active = CASE WHEN active = 1 THEN 0 ELSE 1 END, updated_at = datetime("now") WHERE id = :id');
        $stmt->bindValue(':id', (int) $_POST['toggle_id'], SQLITE3_INTEGER);
        $stmt->execute();
        flash('success', 'Status aktualisiert.');
    }
    redirect(admin_url('workshops'));
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
    <title>Workshops verwalten – Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/assets/style.css">
</head>
<body class="admin-page">
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>
<div class="admin-layout">

    <?php include __DIR__ . '/sidebar.php'; ?>

    <div class="admin-main">
        <div class="admin-header">
            <h1>Workshops</h1>
            <a href="<?= e(admin_url('workshop-edit')) ?>" class="btn-admin">+ Neuer Workshop</a>
        </div>

        <?= render_flash() ?>

        <?php if (empty($workshops)): ?>
            <p style="color:var(--muted);">Noch keine Workshops vorhanden. <a href="<?= e(admin_url('workshop-edit')) ?>" style="color:var(--text);">Jetzt erstellen</a></p>
        <?php else: ?>
            <div class="admin-table-scroll">
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
                        <td style="color:var(--text);">
                            <?= e($w['title']) ?>
                            <?php if ($w['featured']): ?>
                                <span style="font-size:0.65rem;background:var(--featured-pill-bg);color:var(--featured-pill-text);padding:2px 6px;border-radius:3px;margin-left:0.5rem;">Featured</span>
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
                                <a href="<?= e(admin_url('workshop-edit', ['id' => (int) $w['id']])) ?>" class="btn-admin">Bearbeiten</a>
                                <a href="<?= e(admin_url('bookings', ['workshop_id' => (int) $w['id']])) ?>" class="btn-admin">Buchungen</a>
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
            </div>
        <?php endif; ?>
    </div>
</div>
<script src="/assets/site-ui.js"></script>
</body>
</html>
