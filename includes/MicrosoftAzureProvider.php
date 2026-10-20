<?php
// includes/MicrosoftAzureProvider.php

namespace App\OAuth;

require_once __DIR__ . '/../vendor/autoload.php';

use Greew\OAuth2\Client\Provider\Azure;
use League\OAuth2\Client\Provider\Exception\IdentityProviderException;
use League\OAuth2\Client\Token\AccessToken;
use Psr\Http\Message\ResponseInterface;

/**
 * Extends Greew\OAuth2\Client\Provider\Azure to comply with RFC 6750 & Microsoft Graph v1.0:
 * 1. Sends Authorization: Bearer <token> in HTTP request headers.
 * 2. Cleans the resource owner endpoint URL (avoids exposing access tokens in URL query strings).
 * 3. Gracefully parses Microsoft Graph JSON error responses ({ error: { code, message } }).
 */
class MicrosoftAzureProvider extends Azure
{
    /**
     * Overrides AbstractProvider::getAuthorizationHeaders() to supply the standard Bearer header.
     *
     * @param mixed|null $token
     * @return array<string, string>
     */
    protected function getAuthorizationHeaders($token = null): array
    {
        if ($token) {
            $tokenStr = (is_object($token) && method_exists($token, 'getToken'))
                ? (string)$token->getToken()
                : (string)$token;

            return ['Authorization' => 'Bearer ' . $tokenStr];
        }

        return [];
    }

    /**
     * Returns the clean Microsoft Graph resource owner endpoint URL without
     * appending the access token into the query string.
     *
     * @param AccessToken $token
     * @return string
     */
    public function getResourceOwnerDetailsUrl(AccessToken $token): string
    {
        return $this->urlResourceOwnerDetails;
    }

    /**
     * Validates provider and Microsoft Graph API responses for errors.
     * Correctly handles nested Graph API error structures: { error: { code: "...", message: "..." } }.
     *
     * @param ResponseInterface $response
     * @param mixed $data
     * @return void
     * @throws IdentityProviderException
     */
    protected function checkResponse(ResponseInterface $response, $data): void
    {
        if (isset($data['error'])) {
            $errorMessage = null;

            if (is_array($data['error']) && !empty($data['error']['message'])) {
                $errorMessage = (string)$data['error']['message'];
            } elseif (isset($data['error_description'])) {
                $errorMessage = (string)$data['error_description'];
            } elseif (is_string($data['error'])) {
                $errorMessage = $data['error'];
            } else {
                $errorMessage = $response->getReasonPhrase();
            }

            throw new IdentityProviderException(
                $errorMessage,
                $response->getStatusCode(),
                (string)$response->getBody()
            );
        }
    }
}
