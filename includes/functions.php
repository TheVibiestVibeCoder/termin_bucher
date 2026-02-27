<?php
/**
 * Shared helper functions.
 */

function admin_password_configured(): bool {
    $hash = trim((string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? ''));
    if ($hash !== '') {
        $info = password_get_info($hash);
        if (!empty($info['algo'])) {
            return true;
        }
    }

    return ADMIN_PASSWORD !== '';
}

function verify_admin_password(string $password): bool {
    $hash = trim((string) ($_ENV['ADMIN_PASSWORD_HASH'] ?? ''));
    if ($hash !== '') {
        $info = password_get_info($hash);
        if (!empty($info['algo'])) {
            return password_verify($password, $hash);
        }
    }

    return ADMIN_PASSWORD !== '' && hash_equals(ADMIN_PASSWORD, $password);
}

// CSRF
function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_token" value="' . csrf_token() . '">';
}

function csrf_verify(): bool {
    $token = $_POST['_token'] ?? '';

    return hash_equals(csrf_token(), $token);
}

// Escaping
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Slug
function slugify(string $text): string {
    if (function_exists('transliterator_transliterate')) {
        $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    } else {
        $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
        $text = $converted !== false ? strtolower($converted) : strtolower($text);
    }
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);

    return trim((string) $text, '-');
}

// Flash messages
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): string {
    if (empty($_SESSION['flash'])) {
        return '';
    }

    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = $f['type'] === 'error' ? 'flash-error' : 'flash-success';
        $html .= '<div class="flash ' . $cls . '">' . e($f['msg']) . '</div>';
    }
    $_SESSION['flash'] = [];

    return $html;
}

// Auth check (admin)
function is_admin(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
}

// Workshop helpers
function get_workshop_by_slug(SQLite3 $db, string $slug): ?array {
    $stmt = $db->prepare('SELECT * FROM workshops WHERE slug = :slug AND active = 1');
    $stmt->bindValue(':slug', $slug, SQLITE3_TEXT);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    return $result ?: null;
}

function get_workshop_by_id(SQLite3 $db, int $id): ?array {
    $stmt = $db->prepare('SELECT * FROM workshops WHERE id = :id');
    $stmt->bindValue(':id', $id, SQLITE3_INTEGER);
    $result = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    return $result ?: null;
}

function count_confirmed_bookings(SQLite3 $db, int $workshopId): int {
    $stmt = $db->prepare('SELECT COALESCE(SUM(participants), 0) FROM bookings WHERE workshop_id = :wid AND confirmed = 1');
    $stmt->bindValue(':wid', $workshopId, SQLITE3_INTEGER);

    return (int) $stmt->execute()->fetchArray()[0];
}

// Booking token
function generate_token(): string {
    return bin2hex(random_bytes(32));
}

// Rate limiting (simple per-session)
function rate_limit(string $key, int $maxPerMinute = 5): bool {
    $now = time();
    $windowKey = 'rl_' . $key;
    if (!isset($_SESSION[$windowKey])) {
        $_SESSION[$windowKey] = [];
    }

    // Clean old entries
    $_SESSION[$windowKey] = array_filter($_SESSION[$windowKey], fn($t) => $t > $now - 60);
    if (count($_SESSION[$windowKey]) >= $maxPerMinute) {
        return false; // rate limited
    }

    $_SESSION[$windowKey][] = $now;

    return true;
}

// Redirect
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

function json_for_html(mixed $value): string {
    $json = json_encode(
        $value,
        JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE
    );

    return $json === false ? 'null' : $json;
}

// Price formatting
function format_price(float $price, string $currency = 'EUR'): string {
    $symbols = ['EUR' => '€', 'CHF' => 'CHF', 'USD' => '$'];
    $symbol = $symbols[$currency] ?? $currency;
    $formatted = number_format($price, 2, ',', '.');

    return $currency === 'USD' ? $symbol . ' ' . $formatted : $formatted . ' ' . $symbol;
}

function money_round(float $amount): float {
    return round(max(0, $amount), 2);
}

function apply_discount_to_subtotal(float $subtotalNetto, string $discountType = '', float $discountValue = 0): array {
    $subtotal = money_round($subtotalNetto);
    $discount = 0.0;

    if ($subtotal > 0 && $discountValue > 0) {
        if ($discountType === 'percent') {
            $percent = min(100, max(0, $discountValue));
            $discount = money_round($subtotal * ($percent / 100));
        } elseif ($discountType === 'fixed') {
            $discount = money_round($discountValue);
        }
    }

    $discount = min($discount, $subtotal);
    $total = money_round($subtotal - $discount);

    return [
        'subtotal' => $subtotal,
        'discount' => $discount,
        'total' => $total,
    ];
}

function calculate_booking_totals(
    float $pricePerPersonNetto,
    int $participants,
    string $discountType = '',
    float $discountValue = 0
): array {
    $people = max(1, $participants);
    $price = max(0, $pricePerPersonNetto);
    $subtotal = money_round($price * $people);

    return apply_discount_to_subtotal($subtotal, $discountType, $discountValue);
}

function format_discount_value(string $discountType, float $discountValue, string $currency = 'EUR'): string {
    if ($discountType === 'percent') {
        $raw = number_format($discountValue, 2, ',', '.');
        $raw = preg_replace('/,00$/', '', $raw) ?? $raw;

        return $raw . ' %';
    }

    return format_price($discountValue, $currency);
}

function normalize_discount_code(string $code): string {
    $code = strtoupper(trim($code));
    $code = preg_replace('/\s+/', '', $code) ?? $code;

    return $code;
}

function parse_discount_email_list(string $raw): array {
    $parts = preg_split('/[\s,;]+/', strtolower($raw)) ?: [];
    $emails = [];
    foreach ($parts as $email) {
        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $emails[$email] = true;
        }
    }

    return array_keys($emails);
}

function normalize_discount_email_list(string $raw): string {
    return implode("\n", parse_discount_email_list($raw));
}

function parse_discount_workshop_ids(string $raw): array {
    $parts = preg_split('/[^0-9]+/', $raw) ?: [];
    $ids = [];
    foreach ($parts as $part) {
        $id = (int) $part;
        if ($id > 0) {
            $ids[$id] = true;
        }
    }

    $list = array_map('intval', array_keys($ids));
    sort($list, SORT_NUMERIC);

    return $list;
}

function normalize_discount_workshop_ids(string $raw): string {
    $ids = parse_discount_workshop_ids($raw);

    return implode(',', $ids);
}

function normalize_admin_datetime_input(string $raw): string {
    $raw = trim($raw);
    if ($raw === '') {
        return '';
    }

    $raw = str_replace('T', ' ', $raw);
    foreach (['Y-m-d H:i', 'Y-m-d H:i:s'] as $format) {
        $dt = DateTime::createFromFormat($format, $raw);
        if ($dt instanceof DateTime) {
            return $dt->format('Y-m-d H:i');
        }
    }

    $ts = strtotime($raw);
    if ($ts === false) {
        return '';
    }

    return date('Y-m-d H:i', $ts);
}

function datetime_input_value(string $dbValue): string {
    $val = normalize_admin_datetime_input($dbValue);
    if ($val === '') {
        return '';
    }

    return str_replace(' ', 'T', $val);
}

function format_admin_datetime(string $dbValue): string {
    $val = normalize_admin_datetime_input($dbValue);
    if ($val === '') {
        return '—';
    }

    $ts = strtotime($val);
    if ($ts === false) {
        return $val;
    }

    return date('d.m.Y H:i', $ts);
}

function discount_datetime_ts(string $value): ?int {
    $val = normalize_admin_datetime_input($value);
    if ($val === '') {
        return null;
    }

    $ts = strtotime($val);

    return $ts === false ? null : $ts;
}

function discount_code_status(array $code, ?int $now = null): string {
    $nowTs = $now ?? time();
    if (!(int) ($code['active'] ?? 0)) {
        return 'inactive';
    }

    $startTs = discount_datetime_ts((string) ($code['starts_at'] ?? ''));
    if ($startTs !== null && $nowTs < $startTs) {
        return 'scheduled';
    }

    $endTs = discount_datetime_ts((string) ($code['expires_at'] ?? ''));
    if ($endTs !== null && $nowTs > $endTs) {
        return 'expired';
    }

    return 'active';
}

function count_discount_code_usages(SQLite3 $db, int $discountCodeId, ?string $email = null): int {
    if ($email !== null && $email !== '') {
        $stmt = $db->prepare('SELECT COUNT(*) FROM bookings WHERE discount_code_id = :cid AND lower(email) = :mail');
        $stmt->bindValue(':cid', $discountCodeId, SQLITE3_INTEGER);
        $stmt->bindValue(':mail', strtolower(trim($email)), SQLITE3_TEXT);

        return (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
    }

    $stmt = $db->prepare('SELECT COUNT(*) FROM bookings WHERE discount_code_id = :cid');
    $stmt->bindValue(':cid', $discountCodeId, SQLITE3_INTEGER);

    return (int) $stmt->execute()->fetchArray(SQLITE3_NUM)[0];
}

function find_discount_code_by_code(SQLite3 $db, string $rawCode): ?array {
    $code = normalize_discount_code($rawCode);
    if ($code === '') {
        return null;
    }

    $stmt = $db->prepare('SELECT * FROM discount_codes WHERE code = :code COLLATE NOCASE LIMIT 1');
    $stmt->bindValue(':code', $code, SQLITE3_TEXT);
    $row = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    return $row ?: null;
}

function validate_discount_for_booking(
    SQLite3 $db,
    string $rawCode,
    int $workshopId,
    string $email,
    int $participants,
    float $subtotalNetto
): array {
    $subtotal = money_round($subtotalNetto);
    $base = [
        'ok' => false,
        'message' => '',
        'code' => null,
        'subtotal' => $subtotal,
        'discount' => 0.0,
        'total' => $subtotal,
    ];

    $codeValue = normalize_discount_code($rawCode);
    if ($codeValue === '') {
        $base['ok'] = true;

        return $base;
    }

    if ($subtotal <= 0) {
        $base['message'] = 'Fuer diesen Workshop ist kein Rabattcode anwendbar.';

        return $base;
    }

    $code = find_discount_code_by_code($db, $codeValue);
    if (!$code) {
        $base['message'] = 'Rabattcode nicht gefunden.';

        return $base;
    }

    $status = discount_code_status($code);
    if ($status === 'inactive') {
        $base['message'] = 'Dieser Rabattcode ist deaktiviert.';

        return $base;
    }
    if ($status === 'scheduled') {
        $base['message'] = 'Dieser Rabattcode ist noch nicht aktiv.';

        return $base;
    }
    if ($status === 'expired') {
        $base['message'] = 'Dieser Rabattcode ist abgelaufen.';

        return $base;
    }

    $minParticipants = max(0, (int) ($code['min_participants'] ?? 0));
    if ($minParticipants > 0 && $participants < $minParticipants) {
        $base['message'] = 'Dieser Rabattcode gilt erst ab ' . $minParticipants . ' Teilnehmer:innen.';

        return $base;
    }

    $allowedIds = parse_discount_workshop_ids((string) ($code['allowed_workshop_ids'] ?? ''));
    if (!empty($allowedIds) && !in_array($workshopId, $allowedIds, true)) {
        $base['message'] = 'Dieser Rabattcode gilt nicht fuer diesen Workshop.';

        return $base;
    }

    $allowedEmails = parse_discount_email_list((string) ($code['allowed_emails'] ?? ''));
    $email = strtolower(trim($email));
    if (!empty($allowedEmails)) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $base['message'] = 'Bitte geben Sie zuerst eine gueltige E-Mail-Adresse ein.';

            return $base;
        }
        if (!in_array($email, $allowedEmails, true)) {
            $base['message'] = 'Dieser Rabattcode ist nicht fuer diese E-Mail-Adresse freigeschaltet.';

            return $base;
        }
    }

    $maxTotalUses = max(0, (int) ($code['max_total_uses'] ?? 0));
    if ($maxTotalUses > 0) {
        $used = count_discount_code_usages($db, (int) $code['id']);
        if ($used >= $maxTotalUses) {
            $base['message'] = 'Dieser Rabattcode wurde bereits vollstaendig aufgebraucht.';

            return $base;
        }
    }

    $maxEmailUses = max(0, (int) ($code['max_uses_per_email'] ?? 0));
    if ($maxEmailUses > 0) {
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $base['message'] = 'Bitte geben Sie zuerst eine gueltige E-Mail-Adresse ein.';

            return $base;
        }

        $usedByEmail = count_discount_code_usages($db, (int) $code['id'], $email);
        if ($usedByEmail >= $maxEmailUses) {
            $base['message'] = 'Dieser Rabattcode kann pro E-Mail nur ' . $maxEmailUses . 'x genutzt werden.';

            return $base;
        }
    }

    $discount = apply_discount_to_subtotal(
        $subtotal,
        (string) ($code['discount_type'] ?? ''),
        (float) ($code['discount_value'] ?? 0)
    );
    if ($discount['discount'] <= 0) {
        $base['message'] = 'Dieser Rabattcode ist ungueltig konfiguriert.';

        return $base;
    }

    $base['ok'] = true;
    $base['message'] = 'Rabattcode angewendet.';
    $base['code'] = $code;
    $base['discount'] = $discount['discount'];
    $base['total'] = $discount['total'];

    return $base;
}

// Date formatting (German)
function format_event_date(string $date, string $dateEnd = ''): string {
    if (!$date) {
        return '';
    }

    $ts = strtotime($date);
    if ($ts === false) {
        return e($date);
    }

    $days = ['Sun' => 'So', 'Mon' => 'Mo', 'Tue' => 'Di', 'Wed' => 'Mi', 'Thu' => 'Do', 'Fri' => 'Fr', 'Sat' => 'Sa'];
    $months = [
        'January' => 'Januar',
        'February' => 'Februar',
        'March' => 'Maerz',
        'April' => 'April',
        'May' => 'Mai',
        'June' => 'Juni',
        'July' => 'Juli',
        'August' => 'August',
        'September' => 'September',
        'October' => 'Oktober',
        'November' => 'November',
        'December' => 'Dezember',
    ];

    $dow = $days[date('D', $ts)] ?? date('D', $ts);
    $day = (int) date('j', $ts);
    $month = $months[date('F', $ts)] ?? date('F', $ts);
    $year = date('Y', $ts);
    $time = date('H:i', $ts);
    $str = "{$dow}., {$day}. {$month} {$year}, {$time} Uhr";

    if ($dateEnd) {
        $tsEnd = strtotime($dateEnd);
        if ($tsEnd) {
            if (date('Y-m-d', $ts) === date('Y-m-d', $tsEnd)) {
                $str .= ' - ' . date('H:i', $tsEnd) . ' Uhr';
            } else {
                $dowE = $days[date('D', $tsEnd)] ?? date('D', $tsEnd);
                $dayE = (int) date('j', $tsEnd);
                $monthE = $months[date('F', $tsEnd)] ?? date('F', $tsEnd);
                $yearE = date('Y', $tsEnd);
                $str .= ' - ' . "{$dowE}., {$dayE}. {$monthE} {$yearE}";
            }
        }
    }

    return $str;
}
