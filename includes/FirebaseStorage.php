<?php

class FirebaseStorage
{
    private string $bucket;
    private array $credentials;

    public function __construct(string $credentialsPath, string $bucket)
    {
        if (!file_exists($credentialsPath)) {
            throw new Exception("Firebase credentials file not found at: {$credentialsPath}");
        }

        $json = file_get_contents($credentialsPath);
        $this->credentials = json_decode($json, true);
        if (!$this->credentials || !isset($this->credentials['client_email'], $this->credentials['private_key'])) {
            throw new Exception("Invalid Firebase credentials format.");
        }

        $this->bucket = $bucket;
    }

    private function base64UrlEncode(string $data): string
    {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    private function getAccessToken(): string
    {
        $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
        $now = time();
        $claimSet = json_encode([
            'iss' => $this->credentials['client_email'],
            'scope' => 'https://www.googleapis.com/auth/devstorage.read_write',
            'aud' => 'https://oauth2.googleapis.com/token',
            'exp' => $now + 3600,
            'iat' => $now
        ]);

        $base64UrlHeader = $this->base64UrlEncode($header);
        $base64UrlClaimSet = $this->base64UrlEncode($claimSet);

        $signatureInput = $base64UrlHeader . '.' . $base64UrlClaimSet;
        $signature = '';

        if (!openssl_sign($signatureInput, $signature, $this->credentials['private_key'], 'sha256')) {
            throw new Exception("Failed to sign JWT for Firebase auth.");
        }

        $jwt = $signatureInput . '.' . $this->base64UrlEncode($signature);

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion' => $jwt
        ]));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); // For local development safely

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to get Google OAuth2 access token: {$response}");
        }

        $data = json_decode($response, true);
        if (!isset($data['access_token'])) {
            throw new Exception("Access token not found in Google OAuth2 response.");
        }

        return $data['access_token'];
    }

    public function uploadFile(string $localFilePath, string $objectName, string $mimeType): string
    {
        $accessToken = $this->getAccessToken();
        
        $urlEncodedObjectName = urlencode($objectName);
        // Firebase Storage REST API Endpoint
        $url = "https://firebasestorage.googleapis.com/v0/b/{$this->bucket}/o?name={$urlEncodedObjectName}";

        $fileContent = file_get_contents($localFilePath);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $fileContent);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            "Authorization: Bearer {$accessToken}",
            "Content-Type: {$mimeType}"
        ]);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode !== 200) {
            throw new Exception("Failed to upload file to Firebase Storage. HTTP {$httpCode}: {$response}");
        }

        $data = json_decode($response, true);
        $downloadToken = $data['downloadTokens'] ?? '';

        if (empty($downloadToken)) {
            // Fallback just in case token is not provided (unlikely with Firebase endpoint)
            return "https://firebasestorage.googleapis.com/v0/b/{$this->bucket}/o/{$urlEncodedObjectName}?alt=media";
        }

        return "https://firebasestorage.googleapis.com/v0/b/{$this->bucket}/o/{$urlEncodedObjectName}?alt=media&token={$downloadToken}";
    }
}
