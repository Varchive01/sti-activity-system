<?php
// includes/OneDriveService.php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../config/onedrive_config.php';
require_once __DIR__ . '/OneDriveAuthService.php';

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;

/**
 * OneDriveService
 *
 * Isolated Microsoft Graph REST API Service for OneDrive file management.
 * Interacts with Microsoft Graph v1.0 using short-lived Bearer tokens
 * acquired via the dedicated OneDriveAuthService.
 *
 * Responsibilities:
 * - Ensure/create configured root folder (defaults to STI_Activity_System_Documents).
 * - Ensure/create nested folders on demand.
 * - Upload local files to OneDrive with metadata extraction.
 * - Safe Microsoft Graph API error handling without leaking tokens or file payloads.
 *
 * SECURITY GUARANTEES:
 * - Never logs or exposes access tokens, refresh tokens, client secrets, or file contents.
 * - Does not create public sharing links.
 * - Keeps existing local file storage and uploadFile() completely unmodified.
 */
class OneDriveService
{
    private const GRAPH_BASE_URL = 'https://graph.microsoft.com/v1.0';

    private OneDriveAuthService $authService;
    private ClientInterface $httpClient;
    private string $rootFolderName;

    private ?string $cachedAccessToken = null;
    private int $tokenExpiresAt = 0;

    /**
     * @param OneDriveAuthService|null $authService
     * @param ClientInterface|null $httpClient
     */
    public function __construct(
        ?OneDriveAuthService $authService = null,
        ?ClientInterface $httpClient = null
    ) {
        $this->authService = $authService ?? new OneDriveAuthService();
        $this->httpClient  = $httpClient ?? $this->createDefaultHttpClient();
        $this->rootFolderName = $this->authService->getRootFolder();
    }

    /**
     * Check if OneDrive sync is enabled in configuration.
     */
    public function isEnabled(): bool
    {
        return $this->authService->isEnabled();
    }

    /**
     * Get the configured root folder name.
     */
    public function getRootFolderName(): string
    {
        return $this->rootFolderName;
    }

    /**
     * Ensures the configured root folder exists in the user's OneDrive drive root.
     * If it already exists, returns its metadata; otherwise creates it.
     *
     * @param string|null $customName Optional override for root folder name
     * @return array{
     *     success: bool,
     *     folder_id?: string,
     *     name?: string,
     *     web_url?: string,
     *     created?: bool,
     *     error?: string
     * }
     */
    public function ensureRootFolder(?string $customName = null): array
    {
        $folderName = !empty($customName) ? trim($customName) : $this->rootFolderName;
        if (empty($folderName)) {
            $folderName = 'STI_Activity_System_Documents';
        }

        $tokenResult = $this->getValidAccessToken();
        if (!$tokenResult['success']) {
            return [
                'success' => false,
                'error'   => $tokenResult['error'] ?? 'Authentication failed.',
            ];
        }

        $token = $tokenResult['token'];

        // Check if root folder already exists: GET /me/drive/root:/{folderName}
        $checkUrl = self::GRAPH_BASE_URL . '/me/drive/root:/' . rawurlencode($folderName);

        try {
            $response = $this->httpClient->request('GET', $checkUrl, [
                'http_errors' => false,
                'headers'     => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/json',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode === 200 && is_array($data) && !empty($data['id'])) {
                return [
                    'success'   => true,
                    'folder_id' => $data['id'],
                    'name'      => $data['name'] ?? $folderName,
                    'web_url'   => $data['webUrl'] ?? '',
                    'created'   => false,
                ];
            }

            // If 404, create the root folder
            if ($statusCode === 404) {
                return $this->createRootFolderInternal($folderName, $token);
            }

            $errMsg = $this->parseGraphError($statusCode, $data ?: $body);
            return [
                'success' => false,
                'error'   => $errMsg,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => 'Failed to connect to Microsoft Graph: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Creates a folder under a parent folder by ID (or root folder if parentId is omitted).
     *
     * @param string $folderName
     * @param string|null $parentFolderId
     * @return array{
     *     success: bool,
     *     folder_id?: string,
     *     name?: string,
     *     web_url?: string,
     *     parent_id?: string,
     *     created?: bool,
     *     error?: string
     * }
     */
    public function createFolder(string $folderName, ?string $parentFolderId = null): array
    {
        $folderName = trim($folderName);
        if (empty($folderName)) {
            return [
                'success' => false,
                'error'   => 'Folder name cannot be empty.',
            ];
        }

        // If no parent specified, default to the configured root folder
        if (empty($parentFolderId)) {
            $rootResult = $this->ensureRootFolder();
            if (!$rootResult['success']) {
                return [
                    'success' => false,
                    'error'   => 'Failed to resolve root folder: ' . ($rootResult['error'] ?? 'Unknown error'),
                ];
            }
            $parentFolderId = $rootResult['folder_id'];
        }

        $tokenResult = $this->getValidAccessToken();
        if (!$tokenResult['success']) {
            return [
                'success' => false,
                'error'   => $tokenResult['error'] ?? 'Authentication failed.',
            ];
        }

        $token = $tokenResult['token'];
        $url = self::GRAPH_BASE_URL . '/me/drive/items/' . rawurlencode($parentFolderId) . '/children';

        try {
            $response = $this->httpClient->request('POST', $url, [
                'http_errors' => false,
                'headers'     => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json'        => [
                    'name'                              => $folderName,
                    'folder'                            => new \stdClass(),
                    '@microsoft.graph.conflictBehavior' => 'fail',
                ],
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if ($statusCode === 201 && is_array($data) && !empty($data['id'])) {
                return [
                    'success'   => true,
                    'folder_id' => $data['id'],
                    'name'      => $data['name'] ?? $folderName,
                    'web_url'   => $data['webUrl'] ?? '',
                    'parent_id' => $parentFolderId,
                    'created'   => true,
                ];
            }

            // If 409 Conflict (folder already exists), query existing folder
            if ($statusCode === 409) {
                return $this->getExistingChildFolder($parentFolderId, $folderName, $token);
            }

            $errMsg = $this->parseGraphError($statusCode, $data ?: $body);
            return [
                'success' => false,
                'error'   => $errMsg,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => 'Failed to create folder in OneDrive: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Ensures a nested folder path exists (e.g. "posters" or "post-event/2026/09").
     * Traverses and creates intermediate folders as needed.
     *
     * @param string $relativePath
     * @param string|null $baseFolderId Optional parent item ID (defaults to root folder)
     * @return array{
     *     success: bool,
     *     folder_id?: string,
     *     name?: string,
     *     path?: string,
     *     created?: bool,
     *     error?: string
     * }
     */
    public function ensureNestedFolder(string $relativePath, ?string $baseFolderId = null): array
    {
        $cleanPath = trim(str_replace('\\', '/', $relativePath), '/');

        if (empty($baseFolderId)) {
            $rootResult = $this->ensureRootFolder();
            if (!$rootResult['success']) {
                return [
                    'success' => false,
                    'error'   => 'Failed to resolve root folder: ' . ($rootResult['error'] ?? 'Unknown error'),
                ];
            }
            $currentParentId = $rootResult['folder_id'];
        } else {
            $currentParentId = $baseFolderId;
        }

        if ($cleanPath === '') {
            return [
                'success'   => true,
                'folder_id' => $currentParentId,
                'name'      => $this->rootFolderName,
                'path'      => '',
                'created'   => false,
            ];
        }

        $segments = explode('/', $cleanPath);
        $lastFolderId = $currentParentId;
        $lastName = '';
        $anyCreated = false;

        $tokenResult = $this->getValidAccessToken();
        if (!$tokenResult['success']) {
            return [
                'success' => false,
                'error'   => $tokenResult['error'] ?? 'Authentication failed.',
            ];
        }
        $token = $tokenResult['token'];

        foreach ($segments as $segment) {
            $segment = trim($segment);
            if ($segment === '') {
                continue;
            }

            // Check if this child segment exists under current parent
            $checkChildUrl = self::GRAPH_BASE_URL . '/me/drive/items/' . rawurlencode($lastFolderId) . ':/' . rawurlencode($segment);

            try {
                $childRes = $this->httpClient->request('GET', $checkChildUrl, [
                    'http_errors' => false,
                    'headers'     => [
                        'Authorization' => "Bearer {$token}",
                        'Accept'        => 'application/json',
                    ],
                ]);

                $childStatus = $childRes->getStatusCode();
                $childData = json_decode((string)$childRes->getBody(), true);

                if ($childStatus === 200 && is_array($childData) && !empty($childData['id'])) {
                    $lastFolderId = $childData['id'];
                    $lastName = $childData['name'] ?? $segment;
                    continue;
                }

                // If not found, create it
                $createRes = $this->createFolder($segment, $lastFolderId);
                if (!$createRes['success']) {
                    return [
                        'success' => false,
                        'error'   => "Failed to create folder segment '{$segment}': " . ($createRes['error'] ?? 'Unknown error'),
                    ];
                }

                $lastFolderId = $createRes['folder_id'];
                $lastName = $createRes['name'] ?? $segment;
                $anyCreated = true;
            } catch (\Throwable $e) {
                return [
                    'success' => false,
                    'error'   => "Error resolving folder segment '{$segment}': " . $e->getMessage(),
                ];
            }
        }

        return [
            'success'   => true,
            'folder_id' => $lastFolderId,
            'name'      => $lastName,
            'path'      => $cleanPath,
            'created'   => $anyCreated,
        ];
    }

    /**
     * Uploads a local file to Microsoft Graph OneDrive.
     *
     * @param string $localFilePath Absolute path to local file on disk
     * @param string|null $targetFileName Optional custom filename in OneDrive
     * @param string|null $parentFolderId Optional parent folder item ID
     * @param string|null $targetFolderPath Optional relative nested folder path (e.g. "posters" or "post-event")
     * @return array{
     *     success: bool,
     *     item_id?: string,
     *     name?: string,
     *     size?: int,
     *     web_url?: string,
     *     mime_type?: string,
     *     created_at?: string,
     *     modified_at?: string,
     *     parent_id?: string,
     *     error?: string
     * }
     */
    public function uploadFile(
        string $localFilePath,
        ?string $targetFileName = null,
        ?string $parentFolderId = null,
        ?string $targetFolderPath = null
    ): array {
        if (!file_exists($localFilePath) || !is_readable($localFilePath)) {
            return [
                'success' => false,
                'error'   => 'Local file does not exist or is not readable: ' . basename($localFilePath),
            ];
        }

        $fileName = !empty($targetFileName) ? trim($targetFileName) : basename($localFilePath);
        $fileSize = filesize($localFilePath);

        // Resolve destination folder ID
        if (empty($parentFolderId)) {
            if (!empty($targetFolderPath)) {
                $folderRes = $this->ensureNestedFolder($targetFolderPath);
                if (!$folderRes['success']) {
                    return [
                        'success' => false,
                        'error'   => 'Failed to resolve destination folder path: ' . ($folderRes['error'] ?? 'Unknown error'),
                    ];
                }
                $parentFolderId = $folderRes['folder_id'];
            } else {
                $rootRes = $this->ensureRootFolder();
                if (!$rootRes['success']) {
                    return [
                        'success' => false,
                        'error'   => 'Failed to resolve root destination folder: ' . ($rootRes['error'] ?? 'Unknown error'),
                    ];
                }
                $parentFolderId = $rootRes['folder_id'];
            }
        }

        $tokenResult = $this->getValidAccessToken();
        if (!$tokenResult['success']) {
            return [
                'success' => false,
                'error'   => $tokenResult['error'] ?? 'Authentication failed.',
            ];
        }

        $token = $tokenResult['token'];

        // Determine MIME type safely
        $mimeType = 'application/octet-stream';
        if (function_exists('mime_content_type')) {
            $detected = @mime_content_type($localFilePath);
            if (!empty($detected)) {
                $mimeType = $detected;
            }
        }

        // Upload endpoint: PUT /me/drive/items/{parent-id}:/{filename}:/content
        $uploadUrl = self::GRAPH_BASE_URL . '/me/drive/items/' . rawurlencode($parentFolderId) . ':/' . rawurlencode($fileName) . ':/content';

        try {
            $stream = fopen($localFilePath, 'r');
            if ($stream === false) {
                return [
                    'success' => false,
                    'error'   => 'Failed to open local file stream for reading.',
                ];
            }

            $response = $this->httpClient->request('PUT', $uploadUrl, [
                'http_errors' => false,
                'headers'     => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => $mimeType,
                    'Accept'        => 'application/json',
                ],
                'body'        => $stream,
            ]);

            $statusCode = $response->getStatusCode();
            $body = (string)$response->getBody();
            $data = json_decode($body, true);

            if (($statusCode === 200 || $statusCode === 201) && is_array($data) && !empty($data['id'])) {
                return [
                    'success'     => true,
                    'item_id'     => $data['id'],
                    'name'        => $data['name'] ?? $fileName,
                    'size'        => (int)($data['size'] ?? $fileSize),
                    'web_url'     => $data['webUrl'] ?? '',
                    'mime_type'   => $data['file']['mimeType'] ?? $mimeType,
                    'created_at'  => $data['createdDateTime'] ?? '',
                    'modified_at' => $data['lastModifiedDateTime'] ?? '',
                    'parent_id'   => $data['parentReference']['id'] ?? $parentFolderId,
                ];
            }

            $errMsg = $this->parseGraphError($statusCode, $data ?: $body);
            return [
                'success' => false,
                'error'   => $errMsg,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => 'Failed to upload file to Microsoft Graph: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Fetches an existing child folder by name under a parent ID (used upon 409 conflict).
     */
    private function getExistingChildFolder(string $parentId, string $folderName, string $token): array
    {
        $url = self::GRAPH_BASE_URL . '/me/drive/items/' . rawurlencode($parentId) . ':/' . rawurlencode($folderName);

        try {
            $res = $this->httpClient->request('GET', $url, [
                'http_errors' => false,
                'headers'     => [
                    'Authorization' => "Bearer {$token}",
                    'Accept'        => 'application/json',
                ],
            ]);

            $status = $res->getStatusCode();
            $data = json_decode((string)$res->getBody(), true);

            if ($status === 200 && is_array($data) && !empty($data['id'])) {
                return [
                    'success'   => true,
                    'folder_id' => $data['id'],
                    'name'      => $data['name'] ?? $folderName,
                    'web_url'   => $data['webUrl'] ?? '',
                    'parent_id' => $parentId,
                    'created'   => false,
                ];
            }

            return [
                'success' => false,
                'error'   => 'Folder already exists but could not retrieve its metadata.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => 'Error retrieving existing folder metadata: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Creates the root folder under /me/drive/root/children.
     */
    private function createRootFolderInternal(string $folderName, string $token): array
    {
        $createUrl = self::GRAPH_BASE_URL . '/me/drive/root/children';

        try {
            $res = $this->httpClient->request('POST', $createUrl, [
                'http_errors' => false,
                'headers'     => [
                    'Authorization' => "Bearer {$token}",
                    'Content-Type'  => 'application/json',
                    'Accept'        => 'application/json',
                ],
                'json'        => [
                    'name'                              => $folderName,
                    'folder'                            => new \stdClass(),
                    '@microsoft.graph.conflictBehavior' => 'fail',
                ],
            ]);

            $status = $res->getStatusCode();
            $body = (string)$res->getBody();
            $data = json_decode($body, true);

            if ($status === 201 && is_array($data) && !empty($data['id'])) {
                return [
                    'success'   => true,
                    'folder_id' => $data['id'],
                    'name'      => $data['name'] ?? $folderName,
                    'web_url'   => $data['webUrl'] ?? '',
                    'created'   => true,
                ];
            }

            if ($status === 409) {
                // If conflict, another process just created it; fetch it
                $fetchUrl = self::GRAPH_BASE_URL . '/me/drive/root:/' . rawurlencode($folderName);
                $fetchRes = $this->httpClient->request('GET', $fetchUrl, [
                    'http_errors' => false,
                    'headers'     => [
                        'Authorization' => "Bearer {$token}",
                        'Accept'        => 'application/json',
                    ],
                ]);
                $fetchData = json_decode((string)$fetchRes->getBody(), true);
                if ($fetchRes->getStatusCode() === 200 && is_array($fetchData) && !empty($fetchData['id'])) {
                    return [
                        'success'   => true,
                        'folder_id' => $fetchData['id'],
                        'name'      => $fetchData['name'] ?? $folderName,
                        'web_url'   => $fetchData['webUrl'] ?? '',
                        'created'   => false,
                    ];
                }
            }

            $errMsg = $this->parseGraphError($status, $data ?: $body);
            return [
                'success' => false,
                'error'   => $errMsg,
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'error'   => 'Failed to create root folder in OneDrive: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Acquires or reuses a valid bearer access token from OneDriveAuthService.
     *
     * @return array{success: bool, token?: string, error?: string}
     */
    private function getValidAccessToken(): array
    {
        if ($this->cachedAccessToken !== null && time() < ($this->tokenExpiresAt - 60)) {
            return ['success' => true, 'token' => $this->cachedAccessToken];
        }

        $authRes = $this->authService->getAccessToken();
        if (!$authRes['success'] || empty($authRes['access_token'])) {
            return [
                'success' => false,
                'error'   => $authRes['error'] ?? 'Failed to acquire Microsoft Graph access token.',
            ];
        }

        $this->cachedAccessToken = $authRes['access_token'];
        $expiresIn = (int)($authRes['expires_in'] ?? 3600);
        $this->tokenExpiresAt = time() + $expiresIn;

        return ['success' => true, 'token' => $this->cachedAccessToken];
    }

    /**
     * Safely parses Microsoft Graph error responses without exposing secrets, tokens, or raw payloads.
     */
    private function parseGraphError(int $statusCode, mixed $data): string
    {
        if (is_string($data)) {
            $decoded = json_decode($data, true);
            if (is_array($decoded)) {
                $data = $decoded;
            }
        }

        if (is_array($data) && isset($data['error'])) {
            $err = $data['error'];
            $code = is_array($err) ? ($err['code'] ?? '') : '';
            $desc = is_array($err) ? ($err['message'] ?? '') : (string)$err;

            $safeCode = preg_replace('/[^\w\s\.\-_:]/', '', (string)$code);
            $firstLineDesc = strtok((string)$desc, "\r\n");
            $safeDesc = preg_replace('/[^\w\s\.\-_:\(\)]/', '', (string)$firstLineDesc);

            if (!empty($safeCode) || !empty($safeDesc)) {
                return "Microsoft Graph error [{$safeCode}]: {$safeDesc}";
            }
        }

        return "Microsoft Graph request failed with HTTP {$statusCode}.";
    }

    /**
     * Creates default Guzzle client configured with SSL CA bundles for Windows/XAMPP.
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
            'timeout' => 30.0,
            'verify'  => $verify,
        ]);
    }
}
