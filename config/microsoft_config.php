<?php
// config/microsoft_config.php

/**
 * Loads and returns Microsoft OAuth configuration from .env.
 * Reuses existing OAUTH_* keys from the project with support for MICROSOFT_* overrides.
 *
 * @return array{
 *     client_id: string,
 *     client_secret: string,
 *     tenant_id: string,
 *     redirect_uri: string
 * }
 */
function getMicrosoftOAuthConfig(): array {
    $envFile = __DIR__ . '/../.env';
    
    $rawEnv = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $value = trim($value, "\"'\r\n");
                    $rawEnv[$key] = $value;
                }
            }
        }
    }

    $clientId = getenv('MICROSOFT_CLIENT_ID')
        ?: ($rawEnv['MICROSOFT_CLIENT_ID'] ?? (getenv('OAUTH_CLIENT_ID') ?: ($rawEnv['OAUTH_CLIENT_ID'] ?? '')));

    $clientSecret = getenv('MICROSOFT_CLIENT_SECRET')
        ?: ($rawEnv['MICROSOFT_CLIENT_SECRET'] ?? (getenv('OAUTH_CLIENT_SECRET') ?: ($rawEnv['OAUTH_CLIENT_SECRET'] ?? '')));

    // Do NOT silently default to 'common' if missing in configuration
    $tenantId = getenv('MICROSOFT_TENANT_ID')
        ?: ($rawEnv['MICROSOFT_TENANT_ID'] ?? (getenv('OAUTH_TENANT_ID') ?: ($rawEnv['OAUTH_TENANT_ID'] ?? '')));

    $baseUrl = defined('BASE_URL') ? BASE_URL : 'http://localhost/sti-activity-system';
    $defaultRedirect = rtrim($baseUrl, '/') . '/auth/microsoft-callback.php';

    $redirectUri = getenv('MICROSOFT_REDIRECT_URI')
        ?: ($rawEnv['MICROSOFT_REDIRECT_URI'] ?? (getenv('OAUTH_REDIRECT_URI') ?: ($rawEnv['OAUTH_REDIRECT_URI'] ?? $defaultRedirect)));

    return [
        'client_id'     => trim($clientId),
        'client_secret' => trim($clientSecret),
        'tenant_id'     => trim($tenantId),
        'redirect_uri'  => trim($redirectUri),
    ];
}

/**
 * Loads and returns OneDrive Microsoft Graph configuration from .env.
 * Reuses existing Azure App Registration credentials (client_id, client_secret, tenant_id)
 * while requiring a separate, dedicated ONEDRIVE_REFRESH_TOKEN (distinct from mail OAUTH_REFRESH_TOKEN).
 *
 * @return array{
 *     client_id: string,
 *     client_secret: string,
 *     tenant_id: string,
 *     refresh_token: string,
 *     root_folder: string,
 *     enabled: bool,
 *     scopes: string
 * }
 */
function getOneDriveConfig(): array {
    $envFile = __DIR__ . '/../.env';

    $rawEnv = [];
    if (file_exists($envFile)) {
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#')) {
                    continue;
                }
                $parts = explode('=', $line, 2);
                if (count($parts) === 2) {
                    $key = trim($parts[0]);
                    $value = trim($parts[1]);
                    $value = trim($value, "\"'\r\n");
                    $rawEnv[$key] = $value;
                }
            }
        }
    }

    $clientId = getenv('ONEDRIVE_CLIENT_ID')
        ?: ($rawEnv['ONEDRIVE_CLIENT_ID'] ?? (getenv('MICROSOFT_CLIENT_ID') ?: ($rawEnv['MICROSOFT_CLIENT_ID'] ?? (getenv('OAUTH_CLIENT_ID') ?: ($rawEnv['OAUTH_CLIENT_ID'] ?? '')))));

    $clientSecret = getenv('ONEDRIVE_CLIENT_SECRET')
        ?: ($rawEnv['ONEDRIVE_CLIENT_SECRET'] ?? (getenv('MICROSOFT_CLIENT_SECRET') ?: ($rawEnv['MICROSOFT_CLIENT_SECRET'] ?? (getenv('OAUTH_CLIENT_SECRET') ?: ($rawEnv['OAUTH_CLIENT_SECRET'] ?? '')))));

    // Do NOT silently default to 'common' if missing in configuration
    $tenantId = getenv('ONEDRIVE_TENANT_ID')
        ?: ($rawEnv['ONEDRIVE_TENANT_ID'] ?? (getenv('MICROSOFT_TENANT_ID') ?: ($rawEnv['MICROSOFT_TENANT_ID'] ?? (getenv('OAUTH_TENANT_ID') ?: ($rawEnv['OAUTH_TENANT_ID'] ?? '')))));

    // STRICT SEPARATION: Dedicated OneDrive refresh token with Files.ReadWrite
    // NEVER fall back to OAUTH_REFRESH_TOKEN (which lacks Files.ReadWrite)
    $refreshToken = getenv('ONEDRIVE_REFRESH_TOKEN')
        ?: ($rawEnv['ONEDRIVE_REFRESH_TOKEN'] ?? '');

    $rootFolder = getenv('ONEDRIVE_ROOT_FOLDER')
        ?: ($rawEnv['ONEDRIVE_ROOT_FOLDER'] ?? 'STI_Activity_System_Documents');

    $enabledRaw = getenv('ONEDRIVE_ENABLED')
        ?: ($rawEnv['ONEDRIVE_ENABLED'] ?? 'false');
    $enabled = filter_var($enabledRaw, FILTER_VALIDATE_BOOLEAN);

    return [
        'client_id'     => trim($clientId),
        'client_secret' => trim($clientSecret),
        'tenant_id'     => trim($tenantId),
        'refresh_token' => trim($refreshToken),
        'root_folder'   => trim($rootFolder),
        'enabled'       => $enabled,
        'scopes'        => 'https://graph.microsoft.com/Files.ReadWrite offline_access',
    ];
}
