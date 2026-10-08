<?php
// includes/MicrosoftOAuthService.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/microsoft_config.php';

use Greew\OAuth2\Client\Provider\Azure;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;

class MicrosoftOAuthService
{
    private array $config;
    private Azure $provider;
    private ?ClientInterface $httpClient;

    /**
     * @param array|null $config Optional config array overriding getMicrosoftOAuthConfig()
     * @param ClientInterface|null $httpClient Optional Guzzle HTTP client for mocking or custom SSL
     */
    public function __construct(?array $config = null, ?ClientInterface $httpClient = null)
    {
        $this->config = $config ?? getMicrosoftOAuthConfig();
        $this->httpClient = $httpClient;

        $collaborators = [];
        if ($this->httpClient !== null) {
            $collaborators['httpClient'] = $this->httpClient;
        } else {
            $collaborators['httpClient'] = $this->createDefaultHttpClient();
        }

        $this->provider = new Azure([
            'clientId'      => $this->config['client_id'] ?? '',
            'clientSecret'  => $this->config['client_secret'] ?? '',
            'redirectUri'   => $this->config['redirect_uri'] ?? '',
            'tenantId'      => $this->config['tenant_id'] ?? '',
            'defaultScopes' => ['openid', 'profile', 'email', 'User.Read'],
        ], $collaborators);
    }

    /**
     * Validates whether required Microsoft OAuth configuration parameters are populated.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateConfig(): array
    {
        if (empty($this->config['client_id'])) {
            return ['valid' => false, 'error' => 'Missing Microsoft Client ID (OAUTH_CLIENT_ID / MICROSOFT_CLIENT_ID).'];
        }
        if (empty($this->config['client_secret'])) {
            return ['valid' => false, 'error' => 'Missing Microsoft Client Secret (OAUTH_CLIENT_SECRET / MICROSOFT_CLIENT_SECRET).'];
        }
        if (empty($this->config['tenant_id'])) {
            return ['valid' => false, 'error' => 'Missing Microsoft Tenant ID (OAUTH_TENANT_ID / MICROSOFT_TENANT_ID).'];
        }
        if (empty($this->config['redirect_uri'])) {
            return ['valid' => false, 'error' => 'Missing Microsoft Redirect URI configuration.'];
        }

        return ['valid' => true];
    }

    /**
     * Generates a cryptographically secure random state token.
     */
    public function generateState(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * Validates the returned OAuth state using constant-time string comparison.
     */
    public function validateState(?string $returnedState, ?string $sessionState): bool
    {
        if (empty($returnedState) || empty($sessionState)) {
            return false;
        }
        return hash_equals($sessionState, $returnedState);
    }

    /**
     * Generates the authorization URL to redirect the user to Microsoft login.
     *
     * @param string $state Secure CSRF state token
     * @return string
     */
    public function getAuthorizationUrl(string $state): string
    {
        return $this->provider->getAuthorizationUrl([
            'state' => $state,
            'scope' => ['openid', 'profile', 'email', 'User.Read'],
        ]);
    }

    /**
     * Exchanges an authorization code for an access token, fetches the user's
     * profile via Microsoft Graph (/v1.0/me), and returns normalized profile data.
     *
     * @param string $code
     * @return array{
     *     success: bool,
     *     email?: string,
     *     name?: string,
     *     id?: string,
     *     error?: string
     * }
     */
    public function getUserFromCode(string $code): array
    {
        $validation = $this->validateConfig();
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error'   => $validation['error'],
            ];
        }

        try {
            $accessToken = $this->provider->getAccessToken('authorization_code', [
                'code' => $code,
            ]);

            $resourceOwner = $this->provider->getResourceOwner($accessToken);
            $ownerData = $resourceOwner->toArray();

            // Extract email with fallback: Graph API provides 'mail' or 'userPrincipalName'
            $rawEmail = $ownerData['mail'] ?? ($ownerData['userPrincipalName'] ?? '');
            $email = is_string($rawEmail) ? trim($rawEmail) : '';

            // Handle cases where userPrincipalName contains '#EXT#' (guest accounts) or trailing suffixes
            if (str_contains($email, '#EXT#')) {
                // If mail is available, prioritize it
                if (!empty($ownerData['mail']) && is_string($ownerData['mail'])) {
                    $email = trim($ownerData['mail']);
                }
            }

            if ($email === '') {
                return [
                    'success' => false,
                    'error'   => 'Unable to retrieve an email address from your Microsoft profile.',
                ];
            }

            $displayName = is_string($ownerData['displayName'] ?? null)
                ? trim($ownerData['displayName'])
                : '';

            return [
                'success' => true,
                'email'   => $email,
                'name'    => $displayName,
                'id'      => (string)($resourceOwner->getId() ?? ''),
            ];
        } catch (IdentityProviderException $e) {
            error_log("Microsoft OAuth IdentityProviderException: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'Microsoft authentication service returned an error. Please try again.',
            ];
        } catch (\Throwable $e) {
            error_log("Microsoft OAuth General Exception: " . $e->getMessage());
            return [
                'success' => false,
                'error'   => 'An unexpected error occurred during Microsoft authentication.',
            ];
        }
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
}
