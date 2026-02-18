<?php
declare(strict_types=1);

function render_flash_messages(): void
{
    $success = flash_get('success');
    $error = flash_get('error');
    $warning = flash_get('warning');

    if ($success !== null) {
        echo '<div class="flash flash-success">' . e($success) . '</div>';
    }
    if ($error !== null) {
        echo '<div class="flash flash-error">' . e($error) . '</div>';
    }
    if ($warning !== null) {
        echo '<div class="flash flash-warning">' . e($warning) . '</div>';
    }
}

function render_public_header(string $title): void
{
    $appName = (string) config('app.name');
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e($appName) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/site.css')) ?>">
</head>
<body>
<header class="site-header">
    <div class="container header-row">
        <a class="brand" href="<?= e(base_url('index.php')) ?>">Disinformation Consulting</a>
        <a class="admin-link" href="<?= e(base_url('admin/login.php')) ?>">Admin</a>
    </div>
</header>
<main class="container">
    <?php render_flash_messages(); ?>
    <?php
}

function render_public_footer(): void
{
    ?>
</main>
<footer class="site-footer">
    <div class="container">
        <small>&copy; <?= date('Y') ?> Disinformation Consulting</small>
    </div>
</footer>
</body>
</html>
    <?php
}

function render_admin_header(string $title): void
{
    $appName = (string) config('app.name');
    ?>
<!doctype html>
<html lang="de">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($title) ?> | <?= e($appName) ?> Admin</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="<?= e(base_url('assets/css/admin.css')) ?>">
</head>
<body>
<header class="admin-header">
    <div class="container admin-header-row">
        <a class="admin-brand" href="<?= e(base_url('admin/index.php')) ?>">Workshop Admin</a>
        <nav class="admin-nav">
            <a href="<?= e(base_url('index.php')) ?>">Zur Website</a>
            <a href="<?= e(base_url('admin/settings.php')) ?>">Einstellungen</a>
            <a href="<?= e(base_url('admin/logout.php')) ?>">Logout</a>
        </nav>
    </div>
</header>
<main class="container">
    <?php render_flash_messages(); ?>
    <?php
}

function render_admin_footer(): void
{
    ?>
</main>
</body>
</html>
    <?php
}

