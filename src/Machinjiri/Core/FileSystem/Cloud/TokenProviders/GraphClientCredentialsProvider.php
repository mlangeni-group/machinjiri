<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Cloud\TokenProviders;

use Mlangeni\Machinjiri\Core\FileSystem\Contracts\TokenProviderInterface;

class GraphClientCredentialsProvider implements TokenProviderInterface
{
    private ?array $cache = null;

    public function __construct(
        private string $tenantId,
        private string $clientId,
        private string $clientSecret
    ) {}

    public function getToken(): array
    {
        if ($this->cache && $this->cache['expires_at'] - 300 > time()) {
            return $this->cache;
        }

        $ch = curl_init("https://login.microsoftonline.com/{$this->tenantId}/oauth2/v2.0/token");
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => http_build_query([
                'client_id'     => $this->clientId,
                'client_secret' => $this->clientSecret,
                'scope'         => 'https://graph.microsoft.com/.default',
                'grant_type'    => 'client_credentials',
            ]),
        ]);
        $body = json_decode(curl_exec($ch), true);
        curl_close($ch);

        return $this->cache = [
            'token'      => $body['access_token'],
            'expires_at' => time() + ($body['expires_in'] ?? 3600),
        ];
    }
}