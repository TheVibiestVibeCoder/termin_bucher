<?php
require __DIR__ . '/../includes/config.php';

// Handle logout
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['logout'])) {
    if (csrf_verify()) {
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                (bool) $params['secure'],
                (bool) $params['httponly']
            );
        }
        session_destroy();
    } else {
        flash('error', 'Ungueltige Sitzung.');
    }
    redirect('index.php');
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    if (!csrf_verify()) {
        flash('error', 'Ungueltige Sitzung.');
    } elseif (!rate_limit('admin_login', 5)) {
        flash('error', 'Zu viele Versuche. Bitte warten Sie.');
    } elseif (!admin_password_configured()) {
        flash('error', 'Admin-Passwort ist nicht konfiguriert.');
    } else {
        $password = $_POST['password'] ?? '';
        if (verify_admin_password($password)) {
            session_regenerate_id(true);
            $_SESSION['admin_logged_in'] = true;
            redirect('dashboard.php');
        } else {
            flash('error', 'Falsches Passwort.');
        }
    }
}

// Already logged in?
if (is_admin()) {
    redirect('dashboard.php');
}
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Login &ndash; <?= e(SITE_NAME) ?></title>
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
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Cardo:ital,wght@0,400;0,700;1,400&family=Inter:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/style.css">
</head>
<body>
<button type="button" class="theme-toggle theme-toggle-floating" id="themeToggle" aria-pressed="false">&#9790;</button>

<div class="login-page">
    <div class="login-box">
        <h1>Admin</h1>
        <p class="login-sub">Workshop-Verwaltung</p>

        <?= render_flash() ?>

        <form method="POST">
            <?= csrf_field() ?>
            <input type="hidden" name="login" value="1">
            <div class="form-group">
                <label for="password">Passwort</label>
                <input type="password" id="password" name="password" required placeholder="Admin-Passwort" autofocus>
            </div>
            <button type="submit" class="btn-submit">Anmelden &rarr;</button>
        </form>

        <p style="text-align:center;margin-top:1.5rem;">
            <a href="../index.php" style="color:var(--muted);font-size:0.85rem;text-decoration:none;">&larr; Zur&uuml;ck zur Website</a>
        </p>
    </div>
</div>

<script src="../assets/site-ui.js"></script>

</body>
</html>
