<?php
// Cron job: sends a reminder to the restaurant before each guest arrives,
// retries alerts that failed earlier, and clears bookings older than 30 days.
// Hostinger hPanel → Advanced → Cron Jobs → PHP, every 15 minutes:
//   /home/<your-user>/public_html/api/remind.php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/lib.php';

$before = (int) (hno_config()['reminder_minutes_before'] ?? 120);
$now = time();
$sentCount = 0;

hno_with_bookings(function (array $list) use ($before, $now, &$sentCount) {
    $keep = [];
    foreach ($list as $b) {
        $arrive = strtotime($b['date'] . ' ' . $b['time']);
        if ($arrive === false) {
            continue;
        }
        // Drop bookings more than 30 days in the past.
        if ($arrive < $now - 30 * 86400) {
            continue;
        }
        $upcoming = $arrive > $now;

        // 1) The first alert failed: try again while the booking is still upcoming.
        if (empty($b['notified']) && $upcoming) {
            if (hno_notify($b, 'new')) {
                $b['notified'] = true;
                $sentCount++;
            }
        }

        // 2) Reminder, but only if the booking was made well before the reminder window
        //    (a booking made 1 hour ahead has only just been announced; don't send it twice).
        $created = strtotime((string) ($b['created_at'] ?? '')) ?: $now;
        $windowStart = $arrive - $before * 60;
        if (empty($b['reminded']) && !empty($b['notified']) && $upcoming
            && $now >= $windowStart && $created < $windowStart) {
            if (hno_notify($b, 'reminder')) {
                $b['reminded'] = true;
                $sentCount++;
            }
        }
        $keep[] = $b;
    }
    return [null, $keep];
});

echo date('c') . " sent {$sentCount}\n";
