<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Cloud\TokenProviders;

use Mlangeni\Machinjiri\Core\FileSystem\Contracts\TokenProviderInterface;

class GoogleServiceAccountTokenProvider implements TokenProviderInterface
{
    private ?array $cache = null;

    public function __construct(
        private string $clientEmail,
        private string $privateKey,
        private string $scope = 'https://www.googleapis.com/auth/drive'
    ) {}

    public function getToken(): array
    {
        if ($this->cache && $this->cache['expires_at'] - 300 > time()) {
            return $this->cache;
        }

        $now = time();
        $header  = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims  = [
            'iss'   => $this->clientEmail,
            'scope' => $this->scope,
            'aud'   => 'https://oauth2.googleapis.com/token',
            'iat'   => $now,
            'exp'   => $now + 3600,
        ];

        $encode = fn($data) => rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
        $signingInput = $encode($header) . '.' . $encode($claims);


        @openssl_sign($signingInput, $signature, $this->privateKey, OPENSSL_ALGO_SHA256);
        $jwt = $signingInput . '.' . rtrim(strtr(base64_encode($signature), '+/', '-_'), '=');

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt,
            ]),
        ]);
        $body = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $this->cache = [
            'token'      => $body['access_token'],
            'expires_at' => $now + ($body['expires_in'] ?? 3600),
        ];
    }
}