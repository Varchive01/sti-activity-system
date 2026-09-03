<?php
// config/mail_config.php

function getMailConfig() {
    $envFile = __DIR__ . '/../.env';
    $config = [
        'SMTP_HOST' => 'smtp.office365.com',
        'SMTP_PORT' => 587,
        'SMTP_USERNAME' => '',
        'SMTP_PASSWORD' => '',
        'SMTP_ENCRYPTION' => 'tls',
        'FROM_EMAIL' => '',
        'FROM_NAME' => 'STI Marikina Activity System',
        'OAUTH_CLIENT_ID' => '',
        'OAUTH_CLIENT_SECRET' => '',
        'OAUTH_TENANT_ID' => 'common',
        'OAUTH_REFRESH_TOKEN' => '',
    ];

    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            
            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $value = trim($parts[1]);
                $value = trim($value, '"\''); // Remove quotes
                if (array_key_exists($key, $config)) {
                    $config[$key] = $value;
                }
            }
        }
    }

    return $config;
}
