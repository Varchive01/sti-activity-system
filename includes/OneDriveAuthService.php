<?php
// includes/OneDriveAuthService.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/onedrive_config.php';

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OneDriveAuthService
 *
 * Dedicated authentication and token management service for Microsoft Graph OneDrive integration.
 * Manages configuration validation, exchanging authorization codes, and acquiring short-lived
 * bearer tokens via the dedicated ONEDRIVE_REFRESH_TOKEN (with Files.ReadWrite and offline_access).
 *
 * Strictly separates OneDrive file storage credentials from email (Mail.Send) and login authentication.
 */
class OneDriveAuthService
{
    private const GRAPH_SCOPE = 'https://graph.microsoft.com/Files.ReadWrite offline_access';

    private array $config;
    private ClientInterface $httpClient;

    /**
     * @param array|null $config Optional config array overriding getOneDriveConfig()
     * @param ClientInterface|null $httpClient Optional Guzzle client for testing/mocking
     */
    public function __construct(?array $config = null, ?ClientInterface $httpClient = null)
    {
        $this->config = $config ?? getOneDriveConfig();
        $this->httpClient = $httpClient ?? $this->createDefaultHttpClient();
    }

    /**
     * Check if OneDrive backup/sync is explicitly enabled in configuration.
     */
    public function isEnabled(): bool
    {
        return !empty($this->config['enabled']);
    }

    /**
     * Get the configured root folder name in OneDrive.
     */
    public function getRootFolder(): string
    {
        return !empty($this->config['root_folder']) ? $this->config['root_folder'] : 'STI_Activity_System_Documents';
    }

    /**
     * Validates that all required OneDrive Microsoft Graph credentials are set.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateConfig(): array
    {
        if (empty($this->config['client_id'])) {
            return ['valid' => false, 'error' => 'Missing Microsoft Client ID (OAUTH_CLIENT_ID / ONEDRIVE_CLIENT_ID).'];
        }
        if (empty($this->config['client_secret'])) {
            return ['valid' => false, 'error' => 'Missing Microsoft Client Secret (OAUTH_CLIENT_SECRET / ONEDRIVE_CLIENT_SECRET).'];
        }
        if (empty($this->config['tenant_id'])) {
            return ['valid' => false, 'error' => 'Missing Microsoft Tenant ID (OAUTH_TENANT_ID / ONEDRIVE_TENANT_ID).'];
        }
        if (empty($this->config['refresh_token'])) {
            return ['valid' => false, 'error' => 'Missing OneDrive Refresh Token (ONEDRIVE_REFRESH_TOKEN). Authorization is required.'];
        }

        return ['valid' => true];
    }

    /**
     * Returns the OAuth 2.0 authorization URL for one-time consent to acquire the OneDrive refresh token.
     *
     * @param string $redirectUri The registered redirect URI in Azure App Registration
     * @param string $state Optional state parameter for CSRF prevention
     * @return string
     */
    public function getAuthorizationUrl(string $redirectUri, string $state = ''): string
    {
        $tenantId = !empty($this->config['tenant_id']) ? $this->config['tenant_id'] : 'common';
        $clientId = $this->config['client_id'] ?? '';

        $params = [
            'client_id'     => $clientId,
            'response_type' => 'code',
            'redirect_uri'  => $redirectUri,
            'response_mode' => 'query',
            'scope'         => self::GRAPH_SCOPE,
        ];

        if ($state !== '') {
            $params['state'] = $state;
        }

        return "https://login.microsoftonline.com/" . rawurlencode($tenantId) . "/oauth2/v2.0/authorize?" . http_build_query($params);
    }

    /**
     * Exchanges a one-time authorization code from Microsoft consent for access and refresh tokens.
     *
     * @param string $code
     * @param string $redirectUri
     * @return array{
     *     success: bool,
     *     refresh_token?: string,
     *     access_token?: string,
     *     expires_in?: int,
     *     error?: string
     * }
     */
    public function exchangeCodeForTokens(string $code, string $redirectUri): array
    {
        if (empty($this->config['client_id']) || empty($this->config['client_secret']) || empty($this->config['tenant_id'])) {
            return [
                'success' => false,
                'error'   => 'Incomplete Microsoft App Registration credentials in configuration.',
            ];
        }

        $endpoint = $this->getTokenEndpoint();

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'http_errors' => false,
                'form_params' => [
                    'client_id'     => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'grant_type'    => 'authorization_code',
                    'code'          => trim($code),
                    'redirect_uri'  => trim($redirectUri),
                    'scope'         => self::GRAPH_SCOPE,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode === 200 && is_array($data) && !empty($data['access_token']) && !empty($data['refresh_token'])) {
                return [
                    'success'       => true,
                    'refresh_token' => $data['refresh_token'],
                    'access_token'  => $data['access_token'],
                    'expires_in'    => (int)($data['expires_in'] ?? 3600),
                ];
            }

            $errMsg = $this->parseErrorResponse($statusCode, $data);
            return [
                'success' => false,
                'error'   => $errMsg,
            ];
        } catch (\Throwable $e) {
            error_log("OneDriveAuthService exchangeCodeForTokens exception: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Failed to connect to Microsoft token endpoint: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Exchanges the configured ONEDRIVE_REFRESH_TOKEN for a short-lived bearer access token.
     * Never leaks credentials or secrets in output or errors.
     *
     * @return array{
     *     success: bool,
     *     access_token?: string,
     *     expires_in?: int,
     *     refresh_token?: string,
     *     error?: string
     * }
     */
    public function getAccessToken(): array
    {
        $validation = $this->validateConfig();
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error'   => $validation['error'],
            ];
        }

        $endpoint = $this->getTokenEndpoint();

        try {
            $response = $this->httpClient->request('POST', $endpoint, [
                'http_errors' => false,
                'form_params' => [
                    'client_id'     => $this->config['client_id'],
                    'client_secret' => $this->config['client_secret'],
                    'grant_type'    => 'refresh_token',
                    'refresh_token' => $this->config['refresh_token'],
                    'scope'         => self::GRAPH_SCOPE,
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode === 200 && is_array($data) && !empty($data['access_token'])) {
                $result = [
                    'success'      => true,
                    'access_token' => $data['access_token'],
                    'expires_in'   => (int)($data['expires_in'] ?? 3600),
                ];
                if (!empty($data['refresh_token'])) {
                    $result['refresh_token'] = $data['refresh_token'];
                }
                return $result;
            }

            $errMsg = $this->parseErrorResponse($statusCode, $data);
            return [
                'success' => false,
                'error'   => $errMsg,
            ];
        } catch (\Throwable $e) {
            error_log("OneDriveAuthService getAccessToken exception: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Failed to connect to Microsoft token service: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Builds the token endpoint URL based on tenant configuration.
     */
    private function getTokenEndpoint(): string
    {
        $tenantId = !empty($this->config['tenant_id']) ? $this->config['tenant_id'] : 'common';
        return "https://login.microsoftonline.com/" . rawurlencode($tenantId) . "/oauth2/v2.0/token";
    }

    /**
     * Safely parses error responses from Microsoft OAuth without exposing secrets.
     */
    private function parseErrorResponse(int $statusCode, mixed $data): string
    {
        if (is_array($data)) {
            $error = $data['error'] ?? '';
            $desc = $data['error_description'] ?? '';
            if (!empty($error) || !empty($desc)) {
                $safeError = preg_replace('/[^\w\s\.\-_:]/', '', (string)$error);
                $firstLineDesc = strtok((string)$desc, "\r\n");
                $safeDesc = preg_replace('/[^\w\s\.\-_:\(\)]/', '', (string)$firstLineDesc);
                return "Microsoft OAuth error [{$safeError}]: {$safeDesc}";
            }
        }
        return "Microsoft OAuth token request failed with HTTP {$statusCode}.";
    }

    /**
     * Creates default Guzzle client configured with available SSL CA bundles for Windows/XAMPP.
     */
    private function createDefaultHttpClient(): ClientInterface
    {
        $verify = true;
        if (file_exists('C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem')) {
            $verify = 'C:/xampp/phpMyAdmin/vendor/composer/ca-bundle/res/cacert.pem';
        } elseif (file_exists('C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem')) {
            $verify = 'C:/xampp/perl/vendor/lib/Mozilla/CA/cacert.pem';
        }

        return new Client([
            'timeout' => 25.0,
            'verify'  => $verify,
        ]);
    }

    /**
     * Generates a cryptographically secure 64-character hex state token.
     */
    public static function generateState(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Resolves the secure local bridge directory outside the public web root.
     */
    public static function getBridgeDirectory(): string
    {
        // Place bridge outside public web document root (C:/xampp/tmp on XAMPP, or sys_get_temp_dir())
        $candidate = 'C:/xampp/tmp/sti_onedrive_bridge';
        if (!is_dir('C:/xampp/tmp') || !is_writable('C:/xampp/tmp')) {
            $candidate = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'sti_onedrive_bridge';
        }

        if (!is_dir($candidate)) {
            @mkdir($candidate, 0700, true);
        }

        // Additional defense-in-depth: Deny direct HTTP access if directory is ever web-mapped
        $htaccess = $candidate . DIRECTORY_SEPARATOR . '.htaccess';
        if (!file_exists($htaccess)) {
            @file_put_contents($htaccess, "<IfModule authz_core_module>\n    Require all denied\n</IfModule>\n<IfModule !authz_core_module>\n    Deny from all\n</IfModule>\n");
        }

        return $candidate;
    }

    /**
     * Stores a pending authorization transaction with expected state outside public web access.
     * Maximum lifetime: 10 minutes (600 seconds).
     */
    public static function savePendingTransaction(string $state): bool
    {
        if (empty($state)) {
            return false;
        }

        self::cleanExpiredTransactions();
        $dir = self::getBridgeDirectory();
        $filePath = $dir . DIRECTORY_SEPARATOR . 'tx_' . hash('sha256', $state) . '.json';

        $data = [
            'state'      => $state,
            'created_at' => time(),
            'code'       => null,
            'status'     => 'pending',
        ];

        $written = @file_put_contents($filePath, json_encode($data), LOCK_EX);
        if ($written !== false) {
            @chmod($filePath, 0600);
            return true;
        }

        return false;
    }

    /**
     * Securely verifies returned state and stores the authorization code in the pending transaction.
     * Uses constant-time hash_equals() and enforces 10-minute maximum lifetime.
     * Never logs or exposes credentials or codes.
     *
     * @param string $state
     * @param string $code
     * @return array{success: bool, error?: string, message?: string}
     */
    public static function storeAuthorizationCode(string $state, string $code): array
    {
        if (empty($state)) {
            return [
                'success' => false,
                'error'   => 'missing_state',
                'message' => 'Missing OAuth state parameter.',
            ];
        }

        $code = trim($code);
        if (empty($code)) {
            return [
                'success' => false,
                'error'   => 'missing_code',
                'message' => 'Missing authorization code.',
            ];
        }

        $dir = self::getBridgeDirectory();
        $filePath = $dir . DIRECTORY_SEPARATOR . 'tx_' . hash('sha256', $state) . '.json';

        if (!file_exists($filePath)) {
            return [
                'success' => false,
                'error'   => 'invalid_state',
                'message' => 'OAuth state is invalid or transaction does not exist.',
            ];
        }

        $content = @file_get_contents($filePath);
        $data = json_decode((string)$content, true);

        if (!is_array($data) || empty($data['created_at']) || empty($data['state'])) {
            @unlink($filePath);
            return [
                'success' => false,
                'error'   => 'invalid_state',
                'message' => 'Corrupt or invalid transaction record.',
            ];
        }

        // Check expiration: 10 minutes (600 seconds)
        if (time() - (int)$data['created_at'] > 600) {
            @unlink($filePath);
            return [
                'success' => false,
                'error'   => 'expired',
                'message' => 'Authorization transaction has expired (10-minute limit exceeded).',
            ];
        }

        // Constant-time state comparison to prevent timing attacks
        if (!hash_equals($data['state'], $state)) {
            return [
                'success' => false,
                'error'   => 'state_mismatch',
                'message' => 'OAuth state mismatch.',
            ];
        }

        $data['code']          = $code;
        $data['status']        = 'authorized';
        $data['authorized_at'] = time();

        $written = @file_put_contents($filePath, json_encode($data), LOCK_EX);
        if ($written !== false) {
            @chmod($filePath, 0600);
            return ['success' => true];
        }

        return [
            'success' => false,
            'error'   => 'storage_failed',
            'message' => 'Failed to store authorization code in local bridge.',
        ];
    }

    /**
     * Retrieves the pending transaction record for the matching state if valid and unexpired.
     */
    public static function retrievePendingTransaction(string $state): ?array
    {
        if (empty($state)) {
            return null;
        }

        $dir = self::getBridgeDirectory();
        $filePath = $dir . DIRECTORY_SEPARATOR . 'tx_' . hash('sha256', $state) . '.json';

        if (!file_exists($filePath)) {
            return null;
        }

        $content = @file_get_contents($filePath);
        $data = json_decode((string)$content, true);

        if (!is_array($data) || empty($data['created_at']) || empty($data['state'])) {
            @unlink($filePath);
            return null;
        }

        // Expiration check (10 minutes)
        if (time() - (int)$data['created_at'] > 600) {
            @unlink($filePath);
            return null;
        }

        if (!hash_equals($data['state'], $state)) {
            return null;
        }

        return $data;
    }

    /**
     * Clears the pending transaction file for the specified state.
     */
    public static function clearPendingTransaction(string $state): void
    {
        if (!empty($state)) {
            $dir = self::getBridgeDirectory();
            $filePath = $dir . DIRECTORY_SEPARATOR . 'tx_' . hash('sha256', $state) . '.json';
            if (file_exists($filePath)) {
                @unlink($filePath);
            }
        }
        self::cleanExpiredTransactions();
    }

    /**
     * Removes any orphaned transaction files older than 10 minutes (600 seconds).
     */
    public static function cleanExpiredTransactions(): void
    {
        $dir = self::getBridgeDirectory();
        $files = @glob($dir . DIRECTORY_SEPARATOR . 'tx_*.json');
        if ($files !== false) {
            $now = time();
            foreach ($files as $file) {
                if (is_file($file)) {
                    $mtime = @filemtime($file);
                    if ($mtime && ($now - $mtime > 600)) {
                        @unlink($file);
                    }
                }
            }
        }
    }
}

