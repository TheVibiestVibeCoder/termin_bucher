<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/workshops.php';
require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/view.php';

date_default_timezone_set((string) config('app.timezone', 'Europe/Berlin'));
start_secure_session();
apply_security_headers();
db();

