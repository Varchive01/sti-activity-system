<?php
// includes/MicrosoftOAuthService.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/microsoft_config.php';
require_once __DIR__ . '/MicrosoftAzureProvider.php';

use App\OAuth\MicrosoftAzureProvider;
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

        $this->provider = new MicrosoftAzureProvider([
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

            // Gather all candidate email identifiers from Microsoft Graph /me and ID token claims
            $candidateEmails = [];

            $addCandidate = function ($val) use (&$candidateEmails) {
                if (!is_string($val)) {
                    return;
                }
                $clean = strtolower(trim($val));
                if ($clean === '') {
                    return;
                }
                // Decode Microsoft Entra B2B guest UPNs: e.g. user_domain.com#ext#@tenant.onmicrosoft.com -> user@domain.com
                if (str_contains($clean, '#ext#')) {
                    $extParts = explode('#ext#', $clean, 2);
                    $prefix = $extParts[0] ?? '';
                    $lastUnderscore = strrpos($prefix, '_');
                    if ($lastUnderscore !== false) {
                        $decodedGuest = substr($prefix, 0, $lastUnderscore) . '@' . substr($prefix, $lastUnderscore + 1);
                        if (filter_var($decodedGuest, FILTER_VALIDATE_EMAIL)) {
                            $candidateEmails[] = $decodedGuest;
                        }
                    }
                }
                if (filter_var($clean, FILTER_VALIDATE_EMAIL)) {
                    $candidateEmails[] = $clean;
                }
            };

            // 1. Primary Graph /me fields
            $addCandidate($ownerData['mail'] ?? null);
            $addCandidate($ownerData['userPrincipalName'] ?? null);
            $addCandidate($ownerData['preferred_username'] ?? null);
            $addCandidate($ownerData['email'] ?? null);
            $addCandidate($ownerData['upn'] ?? null);

            // 2. Graph otherMails array
            if (!empty($ownerData['otherMails']) && is_array($ownerData['otherMails'])) {
                foreach ($ownerData['otherMails'] as $otherMail) {
                    $addCandidate($otherMail);
                }
            }

            // 3. Graph proxyAddresses array (e.g., "SMTP:alias@marikina.sti.edu.ph", "smtp:user@sti.edu.ph")
            if (!empty($ownerData['proxyAddresses']) && is_array($ownerData['proxyAddresses'])) {
                foreach ($ownerData['proxyAddresses'] as $proxy) {
                    if (is_string($proxy) && stripos($proxy, 'smtp:') === 0) {
                        $addCandidate(substr($proxy, 5));
                    }
                }
            }

            // 4. OIDC id_token JWT payload claims (email, preferred_username, upn, unique_name)
            $tokenValues = method_exists($accessToken, 'getValues') ? $accessToken->getValues() : [];
            $idToken = $tokenValues['id_token'] ?? null;
            if (is_string($idToken) && substr_count($idToken, '.') === 2) {
                $jwtParts = explode('.', $idToken);
                $payloadJson = base64_decode(strtr($jwtParts[1], '-_', '+/'), true);
                if (is_string($payloadJson)) {
                    $claims = json_decode($payloadJson, true);
                    if (is_array($claims)) {
                        $addCandidate($claims['email'] ?? null);
                        $addCandidate($claims['preferred_username'] ?? null);
                        $addCandidate($claims['upn'] ?? null);
                        $addCandidate($claims['unique_name'] ?? null);
                    }
                }
            }

            $candidateEmails = array_values(array_unique($candidateEmails));
            $email = $candidateEmails[0] ?? '';

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
                'emails'  => $candidateEmails,
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
