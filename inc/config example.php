<?php
// inc/config.php - example configuration with placeholders. Fill values in your local
// inc/config.php (DO NOT commit real secrets).
return [
    'host' => 'DB_HOST_PLACEHOLDER',
    'port' => 3306,
    'dbname' => 'DB_NAME_PLACEHOLDER',
    'user' => 'DB_USER_PLACEHOLDER',
    'pass' => 'DB_PASS_PLACEHOLDER',
    'charset' => 'utf8mb4',
    'turnstile_site_key' => 'TURNSTILE_SITE_KEY_PLACEHOLDER',
    'turnstile_secret' => 'TURNSTILE_SECRET_PLACEHOLDER',
    // Discord webhook to notify maintenance toggles (keep secret)
    'discord_webhook' => 'DISCORD_WEBHOOK_URL_PLACEHOLDER',
    'discord_channel' => 'DISCORD_CHANNEL_ID_PLACEHOLDER'
];
