<?php
// POST /api/reserve.php: receives a booking from the website and alerts the restaurant.
declare(strict_types=1);
require __DIR__ . '/lib.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

function reply(int $code, array $body): void
{
    http_response_code($code);
    echo json_encode($body, JSON_UNESCAPED_UNICODE);
    exit;
}
function fail(string $msg, int $code = 400): void
{
    reply($code, ['ok' => false, 'error' => $msg]);
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    fail('Method not allowed.', 405);
}

$cfg = hno_config();

// Only accept posts from our own site (when site_host is set).
$siteHost = strtolower(trim((string) ($cfg['site_host'] ?? '')));
if ($siteHost !== '') {
    $origin = (string) ($_SERVER['HTTP_ORIGIN'] ?? $_SERVER['HTTP_REFERER'] ?? '');
    $host = strtolower((string) parse_url($origin, PHP_URL_HOST));
    if ($host !== $siteHost && $host !== 'www.' . $siteHost) {
        fail('Bookings can only be made from our website.', 403);
    }
}

$raw = file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode((string) $raw, true);
if (!is_array($in)) {
    fail('Invalid request.');
}

// Honeypot: bots fill the hidden "website" field. Pretend success, do nothing.
if (trim((string) ($in['website'] ?? '')) !== '') {
    reply(200, ['ok' => true, 'ref' => 'HNO-0000']);
}

// ---- Rate limit: 5 bookings per 10 minutes per IP ----
$ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0');
// Behind Render's proxy every request comes from the proxy, so use the client IP it appends.
if (getenv('TRUST_PROXY') === '1' && !empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $parts = array_map('trim', explode(',', (string) $_SERVER['HTTP_X_FORWARDED_FOR']));
    $ip = (string) end($parts);
}
$rlFile = $cfg['storage_dir'] . '/ratelimit.json';
if (is_dir($cfg['storage_dir'])) {
    $fh = fopen($rlFile, 'c+');
    if ($fh && flock($fh, LOCK_EX)) {
        $rl = json_decode((string) stream_get_contents($fh), true) ?: [];
        $now = time();
        foreach ($rl as $k => $times) {
            $rl[$k] = array_values(array_filter((array) $times, function ($t) use ($now) { return $t > $now - 600; }));
            if (!$rl[$k]) unset($rl[$k]);
        }
        $key = hash('sha256', $ip);
        if (count($rl[$key] ?? []) >= 5) {
            flock($fh, LOCK_UN); fclose($fh);
            fail('Too many booking attempts. Please wait a few minutes or call us.', 429);
        }
        $rl[$key][] = $now;
        ftruncate($fh, 0); rewind($fh); fwrite($fh, json_encode($rl));
        flock($fh, LOCK_UN); fclose($fh);
    }
}

// ---- Validate ----
$clean = function ($v, int $max): string {
    $s = trim(preg_replace('/\s+/u', ' ', (string) $v) ?? '');
    return mb_substr($s, 0, $max);
};
$mode  = (string) ($in['mode'] ?? '');
$date  = (string) ($in['date'] ?? '');
$time  = (string) ($in['time'] ?? '');
$name  = $clean($in['name'] ?? '', 60);
$notes = $clean($in['notes'] ?? '', 400);
$items = $clean($in['items'] ?? '', 600);
$phone = preg_replace('/\D/', '', (string) ($in['phone'] ?? '')) ?? '';
$phone = preg_replace('/^(91|0)(?=\d{10}$)/', '', $phone) ?? '';

if (!in_array($mode, ['dinein', 'takeaway'], true)) fail('Please choose dine-in or takeaway.');
$take = $mode === 'takeaway';

$d = DateTime::createFromFormat('!Y-m-d', $date);
if (!$d || $d->format('Y-m-d') !== $date) fail('Please choose a valid date.');
$today = new DateTime('today');
$diffDays = (int) $today->diff($d)->format('%r%a');
if ($diffDays < 0 || $diffDays > HNO_DAYS_AHEAD) fail('Please choose a date within the next ' . HNO_DAYS_AHEAD . ' days.');

if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) fail('Please pick a time.');
$t = hno_minutes($time);
$first = hno_minutes($take ? HNO_OPEN : HNO_DINEIN_FIRST);
$last  = hno_minutes($take ? HNO_LAST_TAKEAWAY : HNO_LAST_DINEIN);
if ($t < $first || $t > $last || ($t - $first) % HNO_SLOT_MIN !== 0) fail('That time is outside our booking hours.');
if ($diffDays === 0) {
    $nowMin = (int) date('G') * 60 + (int) date('i');
    if ($t < $nowMin + ($take ? HNO_LEAD_TAKEAWAY : HNO_LEAD_DINEIN)) fail('That time is too soon. Please pick a later slot or call us.');
}

if ($name === '') fail('Please tell us your name.');
if (!preg_match('/^[6-9]\d{9}$/', $phone)) fail('Please enter a valid 10-digit Indian mobile number.');

$guests = 0;
if (!$take) {
    $guests = (int) ($in['guests'] ?? 0);
    if ($guests < 1 || $guests > HNO_MAX_GUESTS) fail('Please choose between 1 and ' . HNO_MAX_GUESTS . ' guests.');
} elseif ($items === '') {
    fail('Please tell us what you would like to pick up.');
}

// ---- Save ----
$booking = [
    'ref'        => 'HNO-' . strtoupper(substr(bin2hex(random_bytes(3)), 0, 5)),
    'mode'       => $mode,
    'date'       => $date,
    'time'       => $time,
    'guests'     => $guests,
    'name'       => $name,
    'phone'      => $phone,
    'items'      => $take ? $items : '',
    'notes'      => $notes,
    'created_at' => date('c'),
    'notified'   => false,
    'reminded'   => false,
];

try {
    hno_with_bookings(function (array $list) use ($booking) {
        $list[] = $booking;
        return [null, $list];
    });
} catch (Throwable $e) {
    error_log('[hno] save failed: ' . $e->getMessage());
    // Still try to alert the staff even if saving failed.
}

// ---- Alert the restaurant ----
$sent = hno_notify($booking, 'new');

try {
    hno_with_bookings(function (array $list) use ($booking, $sent) {
        foreach ($list as &$b) {
            if (($b['ref'] ?? '') === $booking['ref']) {
                $b['notified'] = $sent;
            }
        }
        return [null, $list];
    });
} catch (Throwable $e) { /* ignore */ }

if (!$sent) {
    // Nobody at the restaurant was told, so be honest with the guest.
    fail('We could not pass your booking to the restaurant just now. Please call us on 097402 80453 to confirm.', 502);
}

reply(200, ['ok' => true, 'ref' => $booking['ref']]);
