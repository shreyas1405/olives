<?php
// Herbs n Olives: booking alert settings.
//
// On Render: set these as Environment Variables in the Render dashboard (recommended,
// keeps secrets out of GitHub). On Hostinger: you can type the values straight into the
// '' fallbacks below instead. Never commit a real token to a public repository.
$env = function (string $key, string $fallback = ''): string {
    $v = getenv($key);
    return ($v === false || $v === '') ? $fallback : trim($v);
};

return [
    // Telegram (free). Create a bot with @BotFather; paste its token.
    'telegram_bot_token' => $env('TELEGRAM_BOT_TOKEN', ''),

    // Where alerts go: comma-separated chat IDs, e.g. "123456789,-1001234567890" (groups start with -).
    'telegram_chat_ids'  => array_values(array_filter(array_map('trim', explode(',', $env('TELEGRAM_CHAT_IDS', ''))))),

    // Optional email copy (works on Hostinger; not on Render, which has no mail server). '' = off.
    'email_to'   => $env('EMAIL_TO', ''),
    'email_from' => $env('EMAIL_FROM', ''),

    // Reminder sent this many minutes before arrival.
    'reminder_minutes_before' => (int) $env('REMINDER_MINUTES_BEFORE', '120'),

    // Where bookings are saved. On Render with a disk, set STORAGE_DIR to the disk's mount path.
    'storage_dir' => $env('STORAGE_DIR', __DIR__ . '/../storage'),

    // Your website's domain, to block form posts from other sites, e.g. 'herbsnolives.onrender.com'.
    'site_host' => $env('SITE_HOST', ''),
];
