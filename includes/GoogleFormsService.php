<?php
// includes/GoogleFormsService.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/GoogleOAuthService.php';

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;

/**
 * GoogleFormsService
 *
 * Generic, reusable service for interacting with the Google Forms REST API (v1).
 * Authenticates using GoogleOAuthService Bearer tokens without exposing secrets.
 */
class GoogleFormsService
{
    private const BASE_API_URL = 'https://forms.googleapis.com/v1/forms';

    private GoogleOAuthService $oauthService;
    private ClientInterface $httpClient;

    public function __construct(
        ?GoogleOAuthService $oauthService = null,
        ?ClientInterface $httpClient = null
    ) {
        $this->oauthService = $oauthService ?? new GoogleOAuthService();
        $this->httpClient   = $httpClient ?? new Client([
            'timeout'     => 20.0,
            'http_errors' => false,
        ]);
    }

    /**
     * Creates a new Google Form.
     * Corresponds to forms.create (POST https://forms.googleapis.com/v1/forms).
     *
     * @param array|string $formInput Title string or full form payload array (e.g. ['info' => ['title' => '...']])
     * @return array{
     *     success: bool,
     *     form_id?: string,
     *     responder_uri?: string,
     *     revision_id?: string,
     *     data?: array,
     *     error?: string
     * }
     */
    public function createForm(array|string $formInput): array
    {
        $payload = is_string($formInput)
            ? ['info' => ['title' => trim($formInput)]]
            : $formInput;

        if (empty($payload['info']['title'])) {
            return [
                'success' => false,
                'error'   => 'Form title is required to create a Google Form.',
            ];
        }

        return $this->sendAuthenticatedRequest('POST', self::BASE_API_URL, $payload, function(array $data) {
            return [
                'success'       => true,
                'form_id'       => $data['formId'] ?? '',
                'responder_uri' => $data['responderUri'] ?? '',
                'revision_id'   => $data['revisionId'] ?? '',
                'data'          => $data,
            ];
        });
    }

    /**
     * Applies batch updates to an existing Google Form.
     * Corresponds to forms.batchUpdate (POST https://forms.googleapis.com/v1/forms/{formId}:batchUpdate).
     *
     * @param string $formId The ID of the form to update.
     * @param array $requests List of requests or a full payload containing a 'requests' key.
     * @param array $extraOptions Optional extra parameters (e.g. ['includeFormInResponse' => true]).
     * @return array{
     *     success: bool,
     *     replies?: array,
     *     data?: array,
     *     error?: string
     * }
     */
    public function batchUpdate(string $formId, array $requests, array $extraOptions = []): array
    {
        $formId = trim($formId);
        if (empty($formId)) {
            return [
                'success' => false,
                'error'   => 'Form ID is required for batchUpdate.',
            ];
        }

        $payload = isset($requests['requests'])
            ? $requests
            : array_merge(['requests' => $requests], $extraOptions);

        $url = self::BASE_API_URL . '/' . rawurlencode($formId) . ':batchUpdate';

        return $this->sendAuthenticatedRequest('POST', $url, $payload, function(array $data) {
            return [
                'success' => true,
                'replies' => $data['replies'] ?? [],
                'data'    => $data,
            ];
        });
    }

    /**
     * Executes an authenticated HTTP request with Bearer token against the Google Forms API.
     *
     * @param string $method HTTP method (POST, GET, etc.)
     * @param string $url Target endpoint URL
     * @param array $payload JSON payload
     * @param callable $onSuccess Callback formatting the normalized return array
     * @return array
     */
    private function sendAuthenticatedRequest(string $method, string $url, array $payload, callable $onSuccess): array
    {
        $tokenResult = $this->oauthService->getAccessToken();
        if (empty($tokenResult['success']) || empty($tokenResult['access_token'])) {
            return [
                'success' => false,
                'error'   => $tokenResult['error'] ?? 'Failed to obtain Google OAuth access token.',
            ];
        }

        $accessToken = $tokenResult['access_token'];

        try {
            $response = $this->httpClient->request($method, $url, [
                'http_errors' => false,
                'headers'     => [
                    'Authorization' => 'Bearer ' . $accessToken,
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json'        => $payload,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode < 200 || $statusCode >= 300 || !is_array($data)) {
                $errorMsg = '';
                if (is_array($data) && !empty($data['error'])) {
                    $rawMsg = is_array($data['error']) ? ($data['error']['message'] ?? $data['error']['status'] ?? '') : $data['error'];
                    $errorMsg = is_string($rawMsg) ? preg_replace('/[^\w\s\.\-:,]/', '', $rawMsg) : '';
                }
                if (empty($errorMsg)) {
                    $errorMsg = "Google Forms API request failed with HTTP {$statusCode}.";
                }

                // Security: ensure access token is never leaked in error messages
                $safeError = str_replace($accessToken, '[REDACTED]', $errorMsg);

                return [
                    'success' => false,
                    'error'   => $safeError,
                ];
            }

            return $onSuccess($data);

        } catch (\Throwable $e) {
            error_log('GoogleFormsService: Network or communication error during API call.');
            return [
                'success' => false,
                'error'   => 'Network or communication error while communicating with Google Forms API.',
            ];
        }
    }
}
