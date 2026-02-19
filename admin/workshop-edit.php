<?php
require __DIR__ . '/../includes/config.php';
require_admin();

$id = (int) ($_GET['id'] ?? 0);
$isEdit = $id > 0;
$workshop = null;

if ($isEdit) {
    $workshop = get_workshop_by_id($db, $id);
    if (!$workshop) {
        flash('error', 'Workshop nicht gefunden.');
        redirect('workshops.php');
    }
}

$formData = $workshop ?? [
    'title'           => '',
    'slug'            => '',
    'description'     => '',
    'tag_label'       => '',
    'capacity'        => 30,
    'audiences'       => '',
    'audience_labels' => '',
    'format'          => 'Präsenz od. online',
    'featured'        => 0,
    'sort_order'      => 0,
    'active'          => 1,
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!csrf_verify()) {
        $errors[] = 'Ungültige Sitzung.';
    }

    $formData['title']           = trim($_POST['title'] ?? '');
    $formData['slug']            = trim($_POST['slug'] ?? '') ?: slugify($formData['title']);
    $formData['description']     = trim($_POST['description'] ?? '');
    $formData['tag_label']       = trim($_POST['tag_label'] ?? '');
    $formData['capacity']        = max(0, (int) ($_POST['capacity'] ?? 0));
    $formData['audiences']       = trim($_POST['audiences'] ?? '');
    $formData['audience_labels'] = trim($_POST['audience_labels'] ?? '');
    $formData['format']          = trim($_POST['format'] ?? '');
    $formData['featured']        = isset($_POST['featured']) ? 1 : 0;
    $formData['sort_order']      = (int) ($_POST['sort_order'] ?? 0);
    $formData['active']          = isset($_POST['active']) ? 1 : 0;

    if (strlen($formData['title']) < 2) $errors[] = 'Titel ist erforderlich.';
    if (strlen($formData['slug']) < 2)  $errors[] = 'Slug ist erforderlich.';

    // Check unique slug
    if (empty($errors)) {
        $stmt = $db->prepare('SELECT id FROM workshops WHERE slug = :slug AND id != :id');
        $stmt->bindValue(':slug', $formData['slug'], SQLITE3_TEXT);
        $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        if ($stmt->execute()->fetchArray()) {
            $errors[] = 'Dieser Slug wird bereits verwendet.';
        }
    }

    if (empty($errors)) {
        if ($isEdit) {
            $stmt = $db->prepare('
                UPDATE workshops SET
                    title = :title, slug = :slug, description = :desc, tag_label = :tag,
                    capacity = :cap, audiences = :aud, audience_labels = :audl,
                    format = :fmt, featured = :feat, sort_order = :sort, active = :active,
                    updated_at = datetime("now")
                WHERE id = :id
            ');
            $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
        } else {
            $stmt = $db->prepare('
                INSERT INTO workshops (title, slug, description, tag_label, capacity, audiences, audience_labels, format, featured, sort_order, active)
                VALUES (:title, :slug, :desc, :tag, :cap, :aud, :audl, :fmt, :feat, :sort, :active)
            ');
        }
        $stmt->bindValue(':title', $formData['title'],           SQLITE3_TEXT);
        $stmt->bindValue(':slug',  $formData['slug'],            SQLITE3_TEXT);
        $stmt->bindValue(':desc',  $formData['description'],     SQLITE3_TEXT);
        $stmt->bindValue(':tag',   $formData['tag_label'],       SQLITE3_TEXT);
        $stmt->bindValue(':cap',   $formData['capacity'],        SQLITE3_INTEGER);
        $stmt->bindValue(':aud',   $formData['audiences'],       SQLITE3_TEXT);
        $stmt->bindValue(':audl',  $formData['audience_labels'], SQLITE3_TEXT);
        $stmt->bindValue(':fmt',   $formData['format'],          SQLITE3_TEXT);
        $stmt->bindValue(':feat',  $formData['featured'],        SQLITE3_INTEGER);
        $stmt->bindValue(':sort',  $formData['sort_order'],      SQLITE3_INTEGER);
        $stmt->bindValue(':active',$formData['active'],          SQLITE3_INTEGER);
        $stmt->execute();

        flash('success', $isEdit ? 'Workshop aktualisiert.' : 'Workshop erstellt.');
        redirect('workshops.php');
    }
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $isEdit ? 'Workshop bearbeiten' : 'Neuer Workshop' ?> – Admin</title>
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
            <h1><?= $isEdit ? 'Workshop bearbeiten' : 'Neuer Workshop' ?></h1>
            <a href="workshops.php" class="btn-admin">&larr; Zurück</a>
        </div>

        <?= render_flash() ?>

        <?php if ($errors): ?>
            <div class="flash flash-error">
                <?php foreach ($errors as $err): ?>
                    <div><?= e($err) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <form method="POST" style="max-width:700px;">
            <?= csrf_field() ?>
            <input type="hidden" name="save" value="1">

            <div class="form-group">
                <label for="title">Titel *</label>
                <input type="text" id="title" name="title" required value="<?= e($formData['title']) ?>"
                       placeholder="z.B. FIMI-Grundlagen: Erkennen & Verstehen">
            </div>

            <div class="form-group">
                <label for="slug">Slug (URL)</label>
                <input type="text" id="slug" name="slug" value="<?= e($formData['slug']) ?>"
                       placeholder="Wird automatisch generiert">
            </div>

            <div class="form-group">
                <label for="description">Beschreibung *</label>
                <textarea id="description" name="description" rows="5" required
                          placeholder="Ausführliche Beschreibung des Workshops..."><?= e($formData['description']) ?></textarea>
            </div>

            <div class="form-group">
                <label for="tag_label">Format-Label</label>
                <input type="text" id="tag_label" name="tag_label" value="<?= e($formData['tag_label']) ?>"
                       placeholder="z.B. Halbtag – 4 h, Ganztag – 7 h, Vortrag – 1 h">
            </div>

            <div class="form-group">
                <label for="capacity">Kapazität (0 = unbegrenzt)</label>
                <input type="number" id="capacity" name="capacity" min="0" value="<?= (int) $formData['capacity'] ?>">
            </div>

            <div class="form-group">
                <label for="audiences">Zielgruppen (Schlüssel, kommagetrennt)</label>
                <input type="text" id="audiences" name="audiences" value="<?= e($formData['audiences']) ?>"
                       placeholder="unternehmen,ngo,verwaltung,bildung">
            </div>

            <div class="form-group">
                <label for="audience_labels">Zielgruppen-Labels (kommagetrennt)</label>
                <input type="text" id="audience_labels" name="audience_labels" value="<?= e($formData['audience_labels']) ?>"
                       placeholder="Unternehmen,NGOs,Verwaltung,Bildung">
            </div>

            <div class="form-group">
                <label for="format">Durchführungsformat</label>
                <input type="text" id="format" name="format" value="<?= e($formData['format']) ?>"
                       placeholder="z.B. Präsenz od. online, Flexibel">
            </div>

            <div class="form-group">
                <label for="sort_order">Reihenfolge</label>
                <input type="number" id="sort_order" name="sort_order" value="<?= (int) $formData['sort_order'] ?>">
            </div>

            <div class="form-group" style="display:flex;gap:2rem;">
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                    <input type="checkbox" name="featured" value="1" <?= $formData['featured'] ? 'checked' : '' ?>
                           style="width:auto;">
                    Empfohlen (Featured)
                </label>
                <label style="display:flex;align-items:center;gap:0.5rem;cursor:pointer;">
                    <input type="checkbox" name="active" value="1" <?= $formData['active'] ? 'checked' : '' ?>
                           style="width:auto;">
                    Aktiv (sichtbar)
                </label>
            </div>

            <button type="submit" class="btn-submit" style="max-width:300px;margin-top:1rem;">
                <?= $isEdit ? 'Speichern' : 'Erstellen' ?> &rarr;
            </button>
        </form>
    </div>
</div>

<script>
// Auto-generate slug from title
const titleInput = document.getElementById('title');
const slugInput = document.getElementById('slug');
<?php if (!$isEdit): ?>
titleInput.addEventListener('input', () => {
    if (!slugInput.dataset.manual) {
        slugInput.value = titleInput.value
            .toLowerCase()
            .normalize('NFD').replace(/[\u0300-\u036f]/g, '')
            .replace(/[äÄ]/g, 'ae').replace(/[öÖ]/g, 'oe').replace(/[üÜ]/g, 'ue').replace(/ß/g, 'ss')
            .replace(/[^a-z0-9]+/g, '-')
            .replace(/^-|-$/g, '');
    }
});
slugInput.addEventListener('input', () => { slugInput.dataset.manual = '1'; });
<?php endif; ?>
</script>

</body>
</html>
