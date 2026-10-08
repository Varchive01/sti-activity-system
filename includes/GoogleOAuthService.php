<?php
// includes/GoogleOAuthService.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/google_config.php';

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * GoogleOAuthService
 *
 * Lightweight service that exchanges a configured Google OAuth refresh token
 * for a short-lived bearer access token without requiring the heavyweight
 * Google API Client library.
 */
class GoogleOAuthService
{
    public const SCOPES = 'https://www.googleapis.com/auth/forms.body https://www.googleapis.com/auth/forms.responses.readonly';
    private const AUTH_ENDPOINT = 'https://accounts.google.com/o/oauth2/v2/auth';
    private const TOKEN_ENDPOINT = 'https://oauth2.googleapis.com/token';

    private array $config;
    private ClientInterface $httpClient;

    public function __construct(?array $config = null, ?ClientInterface $httpClient = null)
    {
        $this->config = $config ?? getGoogleConfig();
        $this->httpClient = $httpClient ?? new Client([
            'timeout'     => 15.0,
            'http_errors' => false,
        ]);
    }

    /**
     * Verifies that the required Google OAuth credentials are set.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateCredentials(): array
    {
        if (empty($this->config['GOOGLE_CLIENT_ID'])) {
            return ['valid' => false, 'error' => 'Missing Google Client ID configuration.'];
        }
        if (empty($this->config['GOOGLE_CLIENT_SECRET'])) {
            return ['valid' => false, 'error' => 'Missing Google Client Secret configuration.'];
        }
        if (empty($this->config['GOOGLE_REFRESH_TOKEN'])) {
            return ['valid' => false, 'error' => 'Missing Google Refresh Token configuration.'];
        }

        return ['valid' => true];
    }

    /**
     * Exchanges the configured refresh token for a short-lived access token.
     * Never logs or exposes credentials in errors or output.
     *
     * @return array{
     *     success: bool,
     *     access_token?: string,
     *     expires_in?: int,
     *     token_type?: string,
     *     error?: string
     * }
     */
    public function getAccessToken(): array
    {
        $validation = $this->validateCredentials();
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error'   => $validation['error'],
            ];
        }

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
                'http_errors' => false,
                'form_params' => [
                    'client_id'     => $this->config['GOOGLE_CLIENT_ID'],
                    'client_secret' => $this->config['GOOGLE_CLIENT_SECRET'],
                    'refresh_token' => $this->config['GOOGLE_REFRESH_TOKEN'],
                    'grant_type'    => 'refresh_token',
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode !== 200 || !is_array($data)) {
                $errorDesc = '';
                if (is_array($data) && !empty($data['error'])) {
                    $rawDesc = $data['error_description'] ?? $data['error'];
                    $errorDesc = is_string($rawDesc) ? preg_replace('/[^\w\s\.\-]/', '', $rawDesc) : 'Authentication failed';
                }
                $msg = $errorDesc ? "Google OAuth error: {$errorDesc}" : "Google OAuth request failed with HTTP {$statusCode}.";
                return [
                    'success' => false,
                    'error'   => $msg,
                ];
            }

            if (empty($data['access_token']) || !is_string($data['access_token'])) {
                return [
                    'success' => false,
                    'error'   => 'Malformed response from Google OAuth: access_token missing.',
                ];
            }

            return [
                'success'      => true,
                'access_token' => $data['access_token'],
                'expires_in'   => (int)($data['expires_in'] ?? 3600),
                'token_type'   => $data['token_type'] ?? 'Bearer',
            ];

        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $response = $e->getResponse();
            $errorDesc = '';
            if ($response) {
                $body = (string)$response->getBody();
                $data = json_decode($body, true);
                if (is_array($data) && !empty($data['error'])) {
                    $rawDesc = $data['error_description'] ?? $data['error'];
                    $errorDesc = is_string($rawDesc) ? preg_replace('/[^\w\s\.\-]/', '', $rawDesc) : 'Authentication failed';
                }
            }
            return [
                'success' => false,
                'error'   => $errorDesc ? "Google OAuth error: {$errorDesc}" : 'Google OAuth request rejected.',
            ];
        } catch (\Throwable $e) {
            error_log('GoogleOAuthService: Network or communication error during token exchange.');
            return [
                'success' => false,
                'error'   => 'Network or communication error during Google OAuth token exchange.',
            ];
        }
    }

    /**
     * Verifies that the client ID and client secret are set for the authorization flow.
     *
     * @return array{valid: bool, error?: string}
     */
    public function validateClientCredentials(): array
    {
        if (empty($this->config['GOOGLE_CLIENT_ID'])) {
            return ['valid' => false, 'error' => 'Missing Google Client ID configuration.'];
        }
        if (empty($this->config['GOOGLE_CLIENT_SECRET'])) {
            return ['valid' => false, 'error' => 'Missing Google Client Secret configuration.'];
        }

        return ['valid' => true];
    }

    /**
     * Builds the Google OAuth 2.0 authorization URL for user consent.
     *
     * @param string $redirectUri
     * @param string|null $state Optional CSRF protection state
     * @return string
     */
    public function getAuthorizationUrl(string $redirectUri, ?string $state = null): string
    {
        $params = [
            'client_id'     => $this->config['GOOGLE_CLIENT_ID'] ?? '',
            'redirect_uri'  => $redirectUri,
            'response_type' => 'code',
            'scope'         => self::SCOPES,
            'access_type'   => 'offline',
            'prompt'        => 'consent',
        ];

        if (!empty($state)) {
            $params['state'] = $state;
        }

        return self::AUTH_ENDPOINT . '?' . http_build_query($params);
    }

    /**
     * Exchanges an authorization code for a refresh token and access token.
     *
     * @param string $code Authorization code returned by Google consent redirect
     * @param string $redirectUri Redirect URI matching the authorization request
     * @return array{
     *     success: bool,
     *     refresh_token?: string,
     *     access_token?: string,
     *     expires_in?: int,
     *     token_type?: string,
     *     scope?: string,
     *     error?: string
     * }
     */
    public function exchangeAuthorizationCode(string $code, string $redirectUri): array
    {
        $validation = $this->validateClientCredentials();
        if (!$validation['valid']) {
            return [
                'success' => false,
                'error'   => $validation['error'],
            ];
        }

        $code = trim($code);
        if (empty($code)) {
            return [
                'success' => false,
                'error'   => 'Authorization code is required for exchange.',
            ];
        }

        try {
            $response = $this->httpClient->request('POST', self::TOKEN_ENDPOINT, [
                'http_errors' => false,
                'form_params' => [
                    'client_id'     => $this->config['GOOGLE_CLIENT_ID'],
                    'client_secret' => $this->config['GOOGLE_CLIENT_SECRET'],
                    'code'          => $code,
                    'grant_type'    => 'authorization_code',
                    'redirect_uri'  => trim($redirectUri),
                ],
                'headers' => [
                    'Accept' => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode !== 200 || !is_array($data)) {
                $errorDesc = '';
                if (is_array($data) && !empty($data['error'])) {
                    $rawDesc = $data['error_description'] ?? $data['error'];
                    $errorDesc = is_string($rawDesc) ? preg_replace('/[^\w\s\.\-]/', '', $rawDesc) : 'Token exchange failed';
                }
                $msg = $errorDesc ? "Google OAuth error: {$errorDesc}" : "Google OAuth request failed with HTTP {$statusCode}.";
                if (!empty($this->config['GOOGLE_CLIENT_SECRET'])) {
                    $msg = str_replace($this->config['GOOGLE_CLIENT_SECRET'], '[REDACTED]', $msg);
                }
                if (!empty($code)) {
                    $msg = str_replace($code, '[REDACTED]', $msg);
                }
                return [
                    'success' => false,
                    'error'   => $msg,
                ];
            }

            if (empty($data['access_token']) || !is_string($data['access_token'])) {
                return [
                    'success' => false,
                    'error'   => 'Malformed response from Google OAuth: access_token missing.',
                ];
            }

            return [
                'success'       => true,
                'refresh_token' => $data['refresh_token'] ?? null,
                'access_token'  => $data['access_token'],
                'expires_in'    => (int)($data['expires_in'] ?? 3600),
                'token_type'    => $data['token_type'] ?? 'Bearer',
                'scope'         => $data['scope'] ?? '',
            ];
        } catch (\GuzzleHttp\Exception\BadResponseException $e) {
            $response = $e->getResponse();
            $errorDesc = '';
            if ($response) {
                $body = (string)$response->getBody();
                $data = json_decode($body, true);
                if (is_array($data) && !empty($data['error'])) {
                    $rawDesc = $data['error_description'] ?? $data['error'];
                    $errorDesc = is_string($rawDesc) ? preg_replace('/[^\w\s\.\-]/', '', $rawDesc) : 'Authentication failed';
                }
            }
            $errMsg = $errorDesc ? "Google OAuth error: {$errorDesc}" : 'Google OAuth request rejected.';
            if (!empty($this->config['GOOGLE_CLIENT_SECRET'])) {
                $errMsg = str_replace($this->config['GOOGLE_CLIENT_SECRET'], '[REDACTED]', $errMsg);
            }
            if (!empty($code)) {
                $errMsg = str_replace($code, '[REDACTED]', $errMsg);
            }
            return [
                'success' => false,
                'error'   => $errMsg,
            ];
        } catch (\Throwable $e) {
            error_log('GoogleOAuthService: Network error during authorization code exchange.');
            return [
                'success' => false,
                'error'   => 'Network or communication error during Google OAuth token exchange.',
            ];
        }
    }
}
