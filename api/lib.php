<?php
// Shared helpers for the booking scripts. Not meant to be opened in a browser.
declare(strict_types=1);
date_default_timezone_set('Asia/Kolkata');

const HNO_OPEN = '11:30';
const HNO_LAST_DINEIN = '22:00';
const HNO_LAST_TAKEAWAY = '22:30';
const HNO_DINEIN_FIRST = '12:00';
const HNO_SLOT_MIN = 30;
const HNO_LEAD_DINEIN = 60;
const HNO_LEAD_TAKEAWAY = 30;
const HNO_MAX_GUESTS = 20;
const HNO_DAYS_AHEAD = 60;

function hno_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
        $cfg['storage_dir'] = rtrim($cfg['storage_dir'] ?? (__DIR__ . '/../storage'), '/\\');
    }
    return $cfg;
}

function hno_h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function hno_minutes(string $hhmm): int
{
    [$h, $m] = array_map('intval', explode(':', $hhmm));
    return $h * 60 + $m;
}

function hno_pretty_time(string $hhmm): string
{
    return date('g:i A', strtotime('2000-01-01 ' . $hhmm));
}

function hno_pretty_date(string $ymd): string
{
    return date('D, j M Y', strtotime($ymd));
}

/** Run $fn with the bookings array under an exclusive lock; whatever $fn returns as 'save' is written back. */
function hno_with_bookings(callable $fn)
{
    $dir = hno_config()['storage_dir'];
    if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
        throw new RuntimeException('Storage folder missing');
    }
    $lock = fopen($dir . '/bookings.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) {
        throw new RuntimeException('Could not lock storage');
    }
    try {
        $file = $dir . '/bookings.json';
        $list = is_file($file) ? json_decode((string) file_get_contents($file), true) : [];
        if (!is_array($list)) {
            $list = [];
        }
        [$result, $save] = $fn($list);
        if ($save !== null) {
            $tmp = $file . '.tmp';
            file_put_contents($tmp, json_encode(array_values($save), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            rename($tmp, $file);
        }
        return $result;
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

/** Send an HTML-formatted Telegram message to every configured chat. Returns how many chats got it. */
function hno_telegram(string $html): int
{
    $cfg = hno_config();
    $token = trim((string) ($cfg['telegram_bot_token'] ?? ''));
    if ($token === '') {
        return 0;
    }
    $sent = 0;
    foreach ((array) ($cfg['telegram_chat_ids'] ?? []) as $chat) {
        $payload = http_build_query([
            'chat_id' => (string) $chat,
            'text' => $html,
            'parse_mode' => 'HTML',
            'disable_web_page_preview' => 'true',
        ]);
        $url = 'https://api.telegram.org/bot' . $token . '/sendMessage';
        $ok = false;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT => 10,
            ]);
            $res = curl_exec($ch);
            $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $ok = $res !== false && $code === 200;
        } else {
            $ctx = stream_context_create(['http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $payload,
                'timeout' => 10,
            ]]);
            $ok = @file_get_contents($url, false, $ctx) !== false;
        }
        if ($ok) {
            $sent++;
        } else {
            error_log('[hno] Telegram send failed for chat ' . $chat);
        }
    }
    return $sent;
}

/** Optional plain-text email copy. Returns true if PHP accepted it for delivery. */
function hno_email(string $subject, string $text): bool
{
    $cfg = hno_config();
    $to = trim((string) ($cfg['email_to'] ?? ''));
    if ($to === '') {
        return false;
    }
    $from = trim((string) ($cfg['email_from'] ?? '')) ?: $to;
    $headers = "From: Herbs n Olives Website <{$from}>\r\nContent-Type: text/plain; charset=UTF-8";
    return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', $text, $headers);
}

/** Build the staff message for a booking. $kind = 'new' | 'reminder'. */
function hno_message(array $b, string $kind): array
{
    $take = $b['mode'] === 'takeaway';
    $what = $take ? 'TAKEAWAY' : 'TABLE';
    $when = hno_pretty_date($b['date']) . ' at ' . hno_pretty_time($b['time']);

    $head = $kind === 'reminder'
        ? "⏰ <b>Reminder: {$what} at " . hno_pretty_time($b['time']) . '</b> (' . hno_h($b['ref']) . ')'
        : "🍽 <b>New {$what} booking</b> (" . hno_h($b['ref']) . ')';

    $lines = [$head, ''];
    $lines[] = '👤 ' . hno_h($b['name']);
    $lines[] = '📞 <a href="tel:+91' . hno_h($b['phone']) . '">+91 ' . hno_h($b['phone']) . '</a>';
    if (!$take) {
        $lines[] = '👥 ' . (int) $b['guests'] . ((int) $b['guests'] === 1 ? ' guest' : ' guests');
    }
    $lines[] = ($take ? '🛍 Pickup: ' : '🗓 Arriving: ') . hno_h($when);
    if ($take && $b['items'] !== '') {
        $lines[] = '📋 Order: ' . hno_h($b['items']);
    }
    if ($b['notes'] !== '') {
        $lines[] = '📝 ' . hno_h($b['notes']);
    }
    $lines[] = '';
    $lines[] = '🏷 Booked on the website: <b>10% off the total bill</b>';

    $plain = html_entity_decode(strip_tags(implode("\n", $lines)), ENT_QUOTES, 'UTF-8');
    $subject = ($kind === 'reminder' ? 'Reminder: ' : 'New ') . strtolower($what) . " booking: {$b['name']}, {$when}";
    return [implode("\n", $lines), $subject, $plain];
}

function hno_notify(array $b, string $kind): bool
{
    [$html, $subject, $plain] = hno_message($b, $kind);
    $tg = hno_telegram($html);
    $mail = hno_email($subject, $plain);
    return $tg > 0 || $mail;
}
