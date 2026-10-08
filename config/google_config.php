<?php
// config/google_config.php

/**
 * Loads and returns Google OAuth configuration from .env.
 * Follows the parsing pattern of mail_config.php without hard-coding credentials.
 *
 * @return array{
 *     GOOGLE_CLIENT_ID: string,
 *     GOOGLE_CLIENT_SECRET: string,
 *     GOOGLE_REFRESH_TOKEN: string,
 *     GOOGLE_REDIRECT_URI: string
 * }
 */
function getGoogleConfig(): array {
    $envFile = __DIR__ . '/../.env';
    $config = [
        'GOOGLE_CLIENT_ID'     => '',
        'GOOGLE_CLIENT_SECRET' => '',
        'GOOGLE_REFRESH_TOKEN' => '',
        'GOOGLE_REDIRECT_URI'  => '',
    ];

    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                if (strpos(trim($line), '#') === 0) continue;

                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $value = trim($value, "\"'\r\n"); // Remove quotes
                    if (array_key_exists($key, $config)) {
                        $config[$key] = $value;
                    }
                }
            }
        }
    }

    return $config;
}
