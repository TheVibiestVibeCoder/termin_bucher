<?php
require __DIR__ . '/../includes/config.php';
require_admin();

function workshop_group_slug_exists(SQLite3 $db, string $slug, int $excludeId = 0): bool {
    $sql = 'SELECT id FROM workshop_groups WHERE slug = :slug';
    if ($excludeId > 0) {
        $sql .= ' AND id != :id';
    }
    $sql .= ' LIMIT 1';

    $stmt = $db->prepare($sql);
    $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
    if ($excludeId > 0) {
        $stmt->bindValue(':id', $excludeId, SQLITE3_INTEGER);
    }

    return (bool) $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function workshop_group_unique_slug(SQLite3 $db, string $raw, int $excludeId = 0): string {
    $base = slugify($raw);
    if ($base === '') {
        $base = 'gruppe';
    }

    $candidate = $base;
    $suffix = 2;
    while (workshop_group_slug_exists($db, $candidate, $excludeId)) {
        $candidate = $base . '-' . $suffix;
        $suffix++;
    }

    return $candidate;
}

function workshop_group_exists(SQLite3 $db, int $groupId): bool {
    $stmt = $db->prepare('SELECT 1 FROM workshop_groups WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);

    return (bool) $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

function workshop_exists(SQLite3 $db, int $workshopId): bool {
    $stmt = $db->prepare('SELECT 1 FROM workshops WHERE id = :id LIMIT 1');
    $stmt->bindValue(':id', $workshopId, SQLITE3_INTEGER);

    return (bool) $stmt->execute()->fetchArray(SQLITE3_ASSOC);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify()) {
        flash('error', 'Ungueltige Sitzung.');
        redirect(admin_url('groups'));
    }

    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'create_group') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $sortRaw = trim((string) ($_POST['sort_order'] ?? ''));
        $active = isset($_POST['active']) ? 1 : 0;

        if (mb_strlen($name) < 2) {
            flash('error', 'Bitte einen Gruppennamen mit mindestens 2 Zeichen angeben.');
            redirect(admin_url('groups'));
        }

        $slug = workshop_group_unique_slug($db, $slugInput !== '' ? $slugInput : $name);
        $sortOrder = $sortRaw === ''
            ? (int) $db->querySingle('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM workshop_groups')
            : (int) $sortRaw;

        $stmt = $db->prepare('INSERT INTO workshop_groups (name, slug, description, sort_order, active) VALUES (:name, :slug, :description, :sort_order, :active)');
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
        $stmt->bindValue(':sort_order', $sortOrder, SQLITE3_INTEGER);
        $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
        $stmt->execute();

        flash('success', 'Gruppe wurde erstellt.');
        redirect(admin_url('groups'));
    }

    if ($action === 'update_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $name = trim((string) ($_POST['name'] ?? ''));
        $slugInput = trim((string) ($_POST['slug'] ?? ''));
        $description = trim((string) ($_POST['description'] ?? ''));
        $sortOrder = (int) ($_POST['sort_order'] ?? 0);
        $active = isset($_POST['active']) ? 1 : 0;

        if ($groupId <= 0 || !workshop_group_exists($db, $groupId) || mb_strlen($name) < 2) {
            flash('error', 'Gruppendaten sind ungueltig.');
            redirect(admin_url('groups'));
        }

        $slug = workshop_group_unique_slug($db, $slugInput !== '' ? $slugInput : $name, $groupId);

        $stmt = $db->prepare('UPDATE workshop_groups SET name = :name, slug = :slug, description = :description, sort_order = :sort_order, active = :active, updated_at = datetime("now") WHERE id = :id');
        $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);
        $stmt->bindValue(':name', $name, SQLITE3_TEXT);
        $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
        $stmt->bindValue(':description', $description, SQLITE3_TEXT);
        $stmt->bindValue(':sort_order', $sortOrder, SQLITE3_INTEGER);
        $stmt->bindValue(':active', $active, SQLITE3_INTEGER);
        $stmt->execute();

        flash('success', 'Gruppe gespeichert.');
        redirect(admin_url('groups'));
    }

    if ($action === 'delete_group') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        if ($groupId > 0) {
            $stmt = $db->prepare('DELETE FROM workshop_groups WHERE id = :id');
            $stmt->bindValue(':id', $groupId, SQLITE3_INTEGER);
            $stmt->execute();
            flash('success', 'Gruppe geloescht.');
        }
        redirect(admin_url('groups'));
    }

    if ($action === 'add_assignment') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $workshopId = (int) ($_POST['workshop_id'] ?? 0);

        if ($groupId <= 0 || $workshopId <= 0) {
            flash('error', 'Bitte Gruppe und Workshop auswaehlen.');
            redirect(admin_url('groups'));
        }
        if (!workshop_group_exists($db, $groupId) || !workshop_exists($db, $workshopId)) {
            flash('error', 'Gruppe oder Workshop nicht gefunden.');
            redirect(admin_url('groups'));
        }

        $existsStmt = $db->prepare('SELECT 1 FROM workshop_group_workshops WHERE group_id = :gid AND workshop_id = :wid LIMIT 1');
        $existsStmt->bindValue(':gid', $groupId, SQLITE3_INTEGER);
        $existsStmt->bindValue(':wid', $workshopId, SQLITE3_INTEGER);
        $exists = $existsStmt->execute()->fetchArray(SQLITE3_ASSOC);

        if ($exists) {
            flash('error', 'Dieser Workshop ist der Gruppe bereits zugeordnet.');
            redirect(admin_url('groups'));
        }

        $sortStmt = $db->prepare('SELECT COALESCE(MAX(sort_order), 0) + 10 FROM workshop_group_workshops WHERE group_id = :gid');
        $sortStmt->bindValue(':gid', $groupId, SQLITE3_INTEGER);
        $nextSortOrder = (int) $sortStmt->execute()->fetchArray(SQLITE3_NUM)[0];

        $ins = $db->prepare('INSERT INTO workshop_group_workshops (group_id, workshop_id, sort_order) VALUES (:gid, :wid, :sort)');
        $ins->bindValue(':gid', $groupId, SQLITE3_INTEGER);
        $ins->bindValue(':wid', $workshopId, SQLITE3_INTEGER);
        $ins->bindValue(':sort', $nextSortOrder, SQLITE3_INTEGER);
        $ins->execute();

        flash('success', 'Workshop zur Gruppe hinzugefuegt.');
        redirect(admin_url('groups'));
    }

    if ($action === 'save_assignments') {
        $groupId = (int) ($_POST['group_id'] ?? 0);
        $orderedRaw = (string) ($_POST['ordered_workshop_ids'] ?? '[]');
        $boardRaw = (string) ($_POST['board_state_json'] ?? '');

        $decoded = json_decode($orderedRaw, true);
        $orderedIds = [];
        if (is_array($decoded)) {
            foreach ($decoded as $wid) {
                $wid = (int) $wid;
                if ($wid > 0) {
                    $orderedIds[$wid] = true;
                }
            }
        }
        $orderedIds = array_keys($orderedIds);

        if ($groupId <= 0 || !workshop_group_exists($db, $groupId)) {
            flash('error', 'Gruppe nicht gefunden.');
            redirect(admin_url('groups'));
        }

        $workshopExistsStmt = $db->prepare('SELECT 1 FROM workshops WHERE id = :wid LIMIT 1');
        $deleteStmt = $db->prepare('DELETE FROM workshop_group_workshops WHERE group_id = :gid');
        $insertStmt = $db->prepare('INSERT INTO workshop_group_workshops (group_id, workshop_id, sort_order) VALUES (:gid, :wid, :sort)');

        $normalizedBoard = [];
        if ($boardRaw !== '') {
            $decodedBoard = json_decode($boardRaw, true);
            if (is_array($decodedBoard)) {
                foreach ($decodedBoard as $rawGroupId => $rawWorkshopIds) {
                    $boardGroupId = (int) $rawGroupId;
                    if ($boardGroupId <= 0 || !workshop_group_exists($db, $boardGroupId)) {
                        continue;
                    }
                    $ids = [];
                    if (is_array($rawWorkshopIds)) {
                        foreach ($rawWorkshopIds as $wid) {
                            $wid = (int) $wid;
                            if ($wid > 0) {
                                $ids[$wid] = true;
                            }
                        }
                    }
                    $normalizedBoard[$boardGroupId] = array_keys($ids);
                }
            }
        }

        $inTx = false;
        try {
            $db->exec('BEGIN IMMEDIATE');
            $inTx = true;

            if (!empty($normalizedBoard)) {
                foreach ($normalizedBoard as $boardGroupId => $ids) {
                    $deleteStmt->bindValue(':gid', $boardGroupId, SQLITE3_INTEGER);
                    $deleteStmt->execute();

                    $sortOrder = 10;
                    foreach ($ids as $wid) {
                        $workshopExistsStmt->bindValue(':wid', $wid, SQLITE3_INTEGER);
                        $exists = $workshopExistsStmt->execute()->fetchArray(SQLITE3_ASSOC);
                        if (!$exists) {
                            continue;
                        }

                        $insertStmt->bindValue(':gid', $boardGroupId, SQLITE3_INTEGER);
                        $insertStmt->bindValue(':wid', $wid, SQLITE3_INTEGER);
                        $insertStmt->bindValue(':sort', $sortOrder, SQLITE3_INTEGER);
                        $insertStmt->execute();
                        $sortOrder += 10;
                    }
                }
                flash('success', 'Gruppenzuordnung gespeichert.');
            } else {
                $deleteStmt->bindValue(':gid', $groupId, SQLITE3_INTEGER);
                $deleteStmt->execute();

                $sortOrder = 10;
                foreach ($orderedIds as $wid) {
                    $workshopExistsStmt->bindValue(':wid', $wid, SQLITE3_INTEGER);
                    $exists = $workshopExistsStmt->execute()->fetchArray(SQLITE3_ASSOC);
                    if (!$exists) {
                        continue;
                    }

                    $insertStmt->bindValue(':gid', $groupId, SQLITE3_INTEGER);
                    $insertStmt->bindValue(':wid', $wid, SQLITE3_INTEGER);
                    $insertStmt->bindValue(':sort', $sortOrder, SQLITE3_INTEGER);
                    $insertStmt->execute();
                    $sortOrder += 10;
                }
                flash('success', 'Reihenfolge gespeichert.');
            }

            $db->exec('COMMIT');
            $inTx = false;
        } catch (Throwable $e) {
            if ($inTx) {
                $db->exec('ROLLBACK');
            }
            flash('error', 'Zuordnung konnte nicht gespeichert werden.');
        }

        redirect(admin_url('groups'));
    }
    flash('error', 'Aktion nicht erkannt.');
    redirect(admin_url('groups'));
}

$workshops = [];
$workshopRes = $db->query('SELECT id, title, slug, active, sort_order FROM workshops ORDER BY active DESC, sort_order ASC, id ASC');
while ($row = $workshopRes->fetchArray(SQLITE3_ASSOC)) {
    $workshops[] = $row;
}

$groups = [];
$groupRes = $db->query('SELECT g.*, (SELECT COUNT(*) FROM workshop_group_workshops m WHERE m.group_id = g.id) AS assignment_count FROM workshop_groups g ORDER BY g.sort_order ASC, g.id ASC');
while ($row = $groupRes->fetchArray(SQLITE3_ASSOC)) {
    $row['assignments'] = [];
    $groups[(int) $row['id']] = $row;
}

if (!empty($groups)) {
    $assignRes = $db->query('SELECT m.group_id, m.workshop_id, m.sort_order, w.title, w.slug, w.active FROM workshop_group_workshops m JOIN workshops w ON w.id = m.workshop_id ORDER BY m.group_id ASC, m.sort_order ASC, w.sort_order ASC, w.id ASC');
    while ($row = $assignRes->fetchArray(SQLITE3_ASSOC)) {
        $gid = (int) ($row['group_id'] ?? 0);
        if (!isset($groups[$gid])) {
            continue;
        }
        $groups[$gid]['assignments'][] = $row;
    }
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
    <title>Workshop-Gruppen - Admin</title>
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
            <h1>Workshop-Gruppen</h1>
            <button type="button" class="btn-admin btn-success" data-modal-open="groupCreateModal">+ Neue Gruppe</button>
        </div>

        <?= render_flash() ?>

        <div class="group-admin-modal" id="groupCreateModal" aria-hidden="true">
            <div class="group-admin-modal-backdrop" data-modal-close="groupCreateModal"></div>
            <div class="group-admin-modal-panel" role="dialog" aria-modal="true" aria-labelledby="groupCreateModalTitle">
                <button type="button" class="group-admin-modal-close btn-admin" aria-label="Dialog schliessen" data-modal-close="groupCreateModal">&times;</button>
                <h2 id="groupCreateModalTitle">Neue Gruppe</h2>
                <p>Workshops koennen in mehreren Gruppen gleichzeitig erscheinen. Gruppen werden auf der Startseite in ihrer Reihenfolge angezeigt.</p>

                <form method="POST" class="group-admin-create-form">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="create_group">
                    <div class="group-admin-grid group-admin-grid-4">
                        <div class="form-group">
                            <label for="new_group_name">Gruppenname *</label>
                            <input id="new_group_name" type="text" name="name" required placeholder="z.B. Basismodule">
                        </div>
                        <div class="form-group">
                            <label for="new_group_slug">Slug (optional)</label>
                            <input id="new_group_slug" type="text" name="slug" placeholder="wird automatisch erzeugt">
                        </div>
                        <div class="form-group">
                            <label for="new_group_sort">Reihenfolge (optional)</label>
                            <input id="new_group_sort" type="number" name="sort_order" placeholder="auto">
                        </div>
                        <div class="form-group group-admin-checkbox-wrap">
                            <label class="group-switch" for="new_group_active">
                                <input id="new_group_active" type="checkbox" name="active" value="1" checked>
                                <span class="group-switch-ui" aria-hidden="true"></span>
                                <span class="group-switch-label">Aktiv auf Startseite</span>
                            </label>
                        </div>
                    </div>
                    <div class="form-group">
                        <label for="new_group_description">Kurzbeschreibung (optional)</label>
                        <textarea id="new_group_description" name="description" rows="2" placeholder="Kurzer Untertitel fuer die Gruppensektion auf der Startseite"></textarea>
                    </div>
                    <button type="submit" class="btn-admin btn-success">Gruppe erstellen</button>
                </form>
            </div>
        </div>

        <?php if (empty($groups)): ?>
            <div class="group-admin-empty">Noch keine Gruppen vorhanden.</div>
        <?php else: ?>
            <div class="group-admin-list">
                <?php foreach ($groups as $group): ?>
                    <?php
                        $groupId = (int) $group['id'];
                        $assignmentIds = [];
                        foreach ($group['assignments'] as $assignmentRow) {
                            $assignmentIds[(int) $assignmentRow['workshop_id']] = true;
                        }
                    ?>
                    <section class="group-admin-card">
                        <div class="group-admin-card-head">
                            <h2><?= e($group['name']) ?></h2>
                            <span class="status-badge <?= (int) $group['active'] ? 'status-confirmed' : 'status-pending' ?>">
                                <?= (int) $group['active'] ? 'Aktiv' : 'Inaktiv' ?>
                            </span>
                        </div>

                        <details class="group-admin-details">
                            <summary class="group-admin-details-summary">
                                Gruppen-Details bearbeiten
                                <span class="group-admin-details-hint">eingeklappt</span>
                            </summary>
                            <div class="group-admin-details-content">
                                <form method="POST" class="group-admin-meta-form">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="update_group">
                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">

                                    <div class="group-admin-grid group-admin-grid-4">
                                        <div class="form-group">
                                            <label for="group_name_<?= $groupId ?>">Name</label>
                                            <input id="group_name_<?= $groupId ?>" type="text" name="name" required value="<?= e($group['name']) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="group_slug_<?= $groupId ?>">Slug</label>
                                            <input id="group_slug_<?= $groupId ?>" type="text" name="slug" value="<?= e($group['slug']) ?>">
                                        </div>
                                        <div class="form-group">
                                            <label for="group_sort_<?= $groupId ?>">Reihenfolge</label>
                                            <input id="group_sort_<?= $groupId ?>" type="number" name="sort_order" value="<?= (int) $group['sort_order'] ?>">
                                        </div>
                                        <div class="form-group group-admin-checkbox-wrap">
                                            <label class="group-switch" for="group_active_<?= $groupId ?>">
                                                <input id="group_active_<?= $groupId ?>" type="checkbox" name="active" value="1" <?= (int) $group['active'] ? 'checked' : '' ?>>
                                                <span class="group-switch-ui" aria-hidden="true"></span>
                                                <span class="group-switch-label">Aktiv auf Startseite</span>
                                            </label>
                                        </div>
                                    </div>
                                    <div class="form-group">
                                        <label for="group_desc_<?= $groupId ?>">Kurzbeschreibung</label>
                                        <textarea id="group_desc_<?= $groupId ?>" name="description" rows="2"><?= e($group['description']) ?></textarea>
                                    </div>
                                    <div class="group-admin-inline-actions">
                                        <button type="submit" class="btn-admin btn-success">Gruppendaten speichern</button>
                                    </div>
                                </form>

                                <form method="POST" class="group-admin-delete-form" onsubmit="return confirm('Gruppe wirklich loeschen?')">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="action" value="delete_group">
                                    <input type="hidden" name="group_id" value="<?= $groupId ?>">
                                    <button type="submit" class="btn-admin btn-danger">Gruppe loeschen</button>
                                </form>
                            </div>
                        </details>

                        <div class="group-admin-divider"></div>

                        <div class="group-admin-assignment-head">
                            <h3>Zugeordnete Workshops</h3>
                            <p>Desktop: per Drag-and-Drop sortieren. Mobil: Pfeile verwenden.</p>
                        </div>

                        <form method="POST" class="group-admin-add-form">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="add_assignment">
                            <input type="hidden" name="group_id" value="<?= $groupId ?>">
                            <div class="group-admin-add-row">
                                <div class="group-select-wrap">
                                    <select name="workshop_id" required>
                                        <option value="">Workshop auswaehlen...</option>
                                        <?php foreach ($workshops as $workshop): ?>
                                            <?php $wid = (int) $workshop['id']; ?>
                                            <option value="<?= $wid ?>" <?= isset($assignmentIds[$wid]) ? 'disabled' : '' ?>>
                                                <?= e($workshop['title']) ?><?= (int) $workshop['active'] ? '' : ' (inaktiv)' ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <span class="group-select-icon" aria-hidden="true">&#9662;</span>
                                </div>
                                <button type="submit" class="btn-admin">Hinzufuegen</button>
                            </div>
                        </form>

                        <form method="POST" class="group-admin-order-form group-drag-shell" data-group-order-form data-group-id="<?= $groupId ?>">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="save_assignments">
                            <input type="hidden" name="group_id" value="<?= $groupId ?>">
                            <input type="hidden" name="ordered_workshop_ids" value='<?= e(json_encode(array_map(static fn(array $row): int => (int) $row['workshop_id'], $group['assignments']))) ?>' data-order-input>
                            <input type="hidden" name="board_state_json" value="{}" data-board-input>

                            <ul class="group-assignment-list group-assignment-dropzone" data-assignment-list data-group-id="<?= $groupId ?>">
                                <?php foreach ($group['assignments'] as $assignment): ?>
                                    <?php $wid = (int) $assignment['workshop_id']; ?>
                                    <li class="group-assignment-item" draggable="true" data-workshop-id="<?= $wid ?>">
                                        <span class="group-assignment-drag" aria-hidden="true">&#9776;</span>
                                        <span class="group-assignment-title"><?= e($assignment['title']) ?></span>
                                        <?php if (!(int) ($assignment['active'] ?? 0)): ?>
                                            <span class="status-badge status-pending">Inaktiv</span>
                                        <?php endif; ?>
                                        <div class="group-assignment-buttons">
                                            <button type="button" class="btn-admin group-move-btn" data-move="up" aria-label="Nach oben">&#8593;</button>
                                            <button type="button" class="btn-admin group-move-btn" data-move="down" aria-label="Nach unten">&#8595;</button>
                                            <button type="button" class="btn-admin btn-danger group-remove-btn" data-remove="1" aria-label="Entfernen">&times;</button>
                                        </div>
                                    </li>
                                <?php endforeach; ?>
                                <li class="group-assignment-empty" data-empty-state <?= !empty($group['assignments']) ? 'hidden' : '' ?>>
                                    Noch keine Workshops zugeordnet.
                                </li>
                            </ul>

                            <div class="group-admin-inline-actions">
                                <button type="submit" class="btn-admin btn-success">Aenderungen speichern</button>
                            </div>
                        </form>
                    </section>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<script>
(function () {
    function setModalState(modal, open) {
        if (!modal) {
            return;
        }
        modal.classList.toggle('is-open', open);
        modal.setAttribute('aria-hidden', open ? 'false' : 'true');
        document.body.classList.toggle('group-modal-open', open);
    }

    function closeModal(modal) {
        setModalState(modal, false);
    }

    function openModal(modal) {
        setModalState(modal, true);
        var firstInput = modal.querySelector('input, textarea, select, button');
        if (firstInput) {
            firstInput.focus();
        }
    }

    document.querySelectorAll('[data-modal-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modalId = btn.getAttribute('data-modal-open');
            if (!modalId) {
                return;
            }
            openModal(document.getElementById(modalId));
        });
    });

    document.querySelectorAll('[data-modal-close]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var modalId = btn.getAttribute('data-modal-close');
            if (!modalId) {
                return;
            }
            closeModal(document.getElementById(modalId));
        });
    });

    document.addEventListener('keydown', function (event) {
        if (event.key !== 'Escape') {
            return;
        }
        document.querySelectorAll('.group-admin-modal.is-open').forEach(function (modal) {
            closeModal(modal);
        });
    });

    function getIdsFromList(list) {
        var ids = [];
        if (!list) {
            return ids;
        }
        list.querySelectorAll('.group-assignment-item').forEach(function (item) {
            var wid = parseInt(item.getAttribute('data-workshop-id') || '0', 10);
            if (wid > 0) {
                ids.push(wid);
            }
        });
        return ids;
    }

    function updateEmptyState(list) {
        var emptyState = list ? list.querySelector('[data-empty-state]') : null;
        if (!emptyState) {
            return;
        }
        emptyState.hidden = getIdsFromList(list).length !== 0;
    }

    function buildBoardState() {
        var board = {};
        document.querySelectorAll('[data-group-order-form]').forEach(function (form) {
            var groupId = parseInt(form.getAttribute('data-group-id') || '0', 10);
            if (groupId <= 0) {
                return;
            }
            var list = form.querySelector('[data-assignment-list]');
            board[groupId] = getIdsFromList(list);
        });
        return board;
    }

    function syncAllStates() {
        var board = buildBoardState();
        var boardJson = JSON.stringify(board);

        document.querySelectorAll('[data-group-order-form]').forEach(function (form) {
            var list = form.querySelector('[data-assignment-list]');
            var input = form.querySelector('[data-order-input]');
            var boardInput = form.querySelector('[data-board-input]');
            if (input) {
                input.value = JSON.stringify(getIdsFromList(list));
            }
            if (boardInput) {
                boardInput.value = boardJson;
            }
            if (list) {
                updateEmptyState(list);
            }
        });
    }

    function getDragAfterElement(list, y) {
        var draggableElements = Array.prototype.slice.call(list.querySelectorAll('.group-assignment-item:not(.dragging)'));

        var closest = { offset: Number.NEGATIVE_INFINITY, element: null };
        draggableElements.forEach(function (child) {
            var box = child.getBoundingClientRect();
            var offset = y - box.top - box.height / 2;
            if (offset < 0 && offset > closest.offset) {
                closest = { offset: offset, element: child };
            }
        });

        return closest.element;
    }

    var draggedItem = null;
    document.querySelectorAll('[data-assignment-list]').forEach(function (list) {
        list.addEventListener('dragstart', function (event) {
            var item = event.target.closest('.group-assignment-item');
            if (!item) {
                return;
            }
            draggedItem = item;
            item.classList.add('dragging');
            list.classList.add('is-dragover');
            if (event.dataTransfer) {
                event.dataTransfer.effectAllowed = 'move';
                event.dataTransfer.setData('text/plain', item.getAttribute('data-workshop-id') || '');
            }
        });

        list.addEventListener('dragover', function (event) {
            if (!draggedItem) {
                return;
            }
            event.preventDefault();
            list.classList.add('is-dragover');
            var afterElement = getDragAfterElement(list, event.clientY);
            if (afterElement === null) {
                var emptyState = list.querySelector('[data-empty-state]');
                if (emptyState) {
                    list.insertBefore(draggedItem, emptyState);
                } else {
                    list.appendChild(draggedItem);
                }
            } else {
                list.insertBefore(draggedItem, afterElement);
            }
        });

        list.addEventListener('dragleave', function () {
            list.classList.remove('is-dragover');
        });

        list.addEventListener('drop', function () {
            list.classList.remove('is-dragover');
            syncAllStates();
        });

        list.addEventListener('dragend', function () {
            list.classList.remove('is-dragover');
            if (draggedItem) {
                draggedItem.classList.remove('dragging');
                draggedItem = null;
            }
            syncAllStates();
        });

        list.addEventListener('click', function (event) {
            var button = event.target.closest('button');
            if (!button) {
                return;
            }

            var item = button.closest('.group-assignment-item');
            if (!item) {
                return;
            }

            var move = button.getAttribute('data-move');
            if (move === 'up') {
                var prev = item.previousElementSibling;
                if (prev && prev.classList.contains('group-assignment-item')) {
                    list.insertBefore(item, prev);
                }
                syncAllStates();
                return;
            }
            if (move === 'down') {
                var next = item.nextElementSibling;
                if (next && next.classList.contains('group-assignment-item')) {
                    list.insertBefore(next, item);
                }
                syncAllStates();
                return;
            }

            if (button.getAttribute('data-remove') === '1') {
                item.remove();
                syncAllStates();
            }
        });
    });

    document.querySelectorAll('[data-group-order-form]').forEach(function (form) {
        form.addEventListener('submit', function () {
            syncAllStates();
        });
    });

    syncAllStates();
})();
</script>
<script src="/assets/site-ui.js"></script>

</body>
</html>


