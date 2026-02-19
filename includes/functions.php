<?php
/**
 * Shared helper functions.
 */

// ── CSRF ────────────────────────────────────────────────────────────────────
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

// ── Escaping ────────────────────────────────────────────────────────────────
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── Slug ────────────────────────────────────────────────────────────────────
function slugify(string $text): string {
    $text = transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $text);
    $text = preg_replace('/[^a-z0-9]+/', '-', $text);
    return trim($text, '-');
}

// ── Flash messages ──────────────────────────────────────────────────────────
function flash(string $type, string $msg): void {
    $_SESSION['flash'][] = ['type' => $type, 'msg' => $msg];
}

function render_flash(): string {
    if (empty($_SESSION['flash'])) return '';
    $html = '';
    foreach ($_SESSION['flash'] as $f) {
        $cls = $f['type'] === 'error' ? 'flash-error' : 'flash-success';
        $html .= '<div class="flash ' . $cls . '">' . e($f['msg']) . '</div>';
    }
    $_SESSION['flash'] = [];
    return $html;
}

// ── Auth check (admin) ──────────────────────────────────────────────────────
function is_admin(): bool {
    return !empty($_SESSION['admin_logged_in']);
}

function require_admin(): void {
    if (!is_admin()) {
        header('Location: index.php');
        exit;
    }
}

// ── Workshop helpers ────────────────────────────────────────────────────────
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

// ── Booking token ───────────────────────────────────────────────────────────
function generate_token(): string {
    return bin2hex(random_bytes(32));
}

// ── Rate limiting (simple per-session) ──────────────────────────────────────
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

// ── Redirect ────────────────────────────────────────────────────────────────
function redirect(string $url): never {
    header('Location: ' . $url);
    exit;
}

// ── Price formatting ─────────────────────────────────────────────────────────
function format_price(float $price, string $currency = 'EUR'): string {
    $symbols = ['EUR' => '€', 'CHF' => 'CHF', 'USD' => '$'];
    $symbol  = $symbols[$currency] ?? $currency;
    $formatted = number_format($price, 2, ',', '.');
    return $currency === 'USD' ? $symbol . ' ' . $formatted : $formatted . ' ' . $symbol;
}

// ── Date formatting (German) ─────────────────────────────────────────────────
function format_event_date(string $date, string $dateEnd = ''): string {
    if (!$date) return '';
    $ts = strtotime($date);
    if ($ts === false) return e($date);

    $days   = ['Sun'=>'So','Mon'=>'Mo','Tue'=>'Di','Wed'=>'Mi','Thu'=>'Do','Fri'=>'Fr','Sat'=>'Sa'];
    $months = ['January'=>'Januar','February'=>'Februar','March'=>'März','April'=>'April',
               'May'=>'Mai','June'=>'Juni','July'=>'Juli','August'=>'August',
               'September'=>'September','October'=>'Oktober','November'=>'November','December'=>'Dezember'];

    $dow   = $days[date('D', $ts)]   ?? date('D', $ts);
    $day   = (int) date('j', $ts);
    $month = $months[date('F', $ts)] ?? date('F', $ts);
    $year  = date('Y', $ts);
    $time  = date('H:i', $ts);
    $str   = "{$dow}., {$day}. {$month} {$year}, {$time} Uhr";

    if ($dateEnd) {
        $tsEnd = strtotime($dateEnd);
        if ($tsEnd) {
            if (date('Y-m-d', $ts) === date('Y-m-d', $tsEnd)) {
                $str .= ' – ' . date('H:i', $tsEnd) . ' Uhr';
            } else {
                $dowE   = $days[date('D', $tsEnd)]   ?? date('D', $tsEnd);
                $dayE   = (int) date('j', $tsEnd);
                $monthE = $months[date('F', $tsEnd)] ?? date('F', $tsEnd);
                $yearE  = date('Y', $tsEnd);
                $str   .= ' – ' . "{$dowE}., {$dayE}. {$monthE} {$yearE}";
            }
        }
    }
    return $str;
}
