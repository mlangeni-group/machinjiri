<?php

namespace Mlangeni\Machinjiri\Core\Authentication\ThirdParty;

use Mlangeni\Machinjiri\Core\Authentication\OAuth;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Database\Builders\QueryBuilder;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

/**
 * Third-Party Authentication System
 * Supports: Google, GitHub, Facebook, Twitter, Yahoo, LinkedIn, Microsoft, Instagram, GitLab, Bitbucket, Amazon, Slack
 */
class ThirdPartyAuth
{
    private array $providers = [];
    private array $config = [];
    private Session $session;
    private Cookie $cookie;
    private ?QueryBuilder $queryBuilder = null;
    private ?HttpClient $httpClient = null;
    private ?Logger $logger = null;
    private string $defaultProvider = 'google';
    private array $userMapping = [
        'id' => 'provider_id',
        'name' => 'name',
        'email' => 'email',
        'avatar' => 'avatar_url'
    ];

    public function __construct(
        array $config = [],
        ?Session $session = null,
        ?Cookie $cookie = null,
        ?QueryBuilder $queryBuilder = null,
        ?HttpClient $httpClient = null,
        ?Logger $logger = null
    ) {
        $this->session = $session ?? new Session();
        $this->cookie = $cookie ?? new Cookie();
        $this->queryBuilder = $queryBuilder;
        $this->httpClient = $httpClient;
        $this->logger = $logger;
        $this->config = $this->loadDefaultConfig($config);
        $this->initializeProviders();
    }

    private function loadDefaultConfig(array $config): array
    {
        $defaults = [
            'redirect_uri' => $_ENV['APP_URL'] ?? 'http://localhost:3000' . '/auth/callback',
            'session_key_prefix' => 'thirdparty_auth_',
            'user_table' => 'users',
            'provider_table' => 'user_providers',
            'token_table' => 'user_tokens',
            'auto_create_users' => true,
            'auto_sync_profile' => true,
            'default_role' => 'user',
            'scopes' => [
                'google' => ['email', 'profile'],
                'github' => ['user:email'],
                'facebook' => ['email', 'public_profile'],
                'twitter' => ['users.read', 'tweet.read'],
                'yahoo' => ['profile', 'email'],
                'linkedin' => ['r_liteprofile', 'r_emailaddress'],
                'microsoft' => ['User.Read', 'email'],
                'instagram' => ['user_profile', 'user_media'],
                'gitlab' => ['read_user'],
                'bitbucket' => ['account', 'email'],
                'amazon' => ['profile'],
                'slack' => ['users:read', 'users:read.email']
            ],
            'endpoints' => $this->getDefaultEndpoints(),
            'user_info_endpoints' => $this->getUserInfoEndpoints()
        ];

        return array_merge($defaults, $config);
    }

    private function getDefaultEndpoints(): array
    {
        return [
            'google' => [
                'authorization' => 'https://accounts.google.com/o/oauth2/auth',
                'token' => 'https://oauth2.googleapis.com/token',
                'revoke' => 'https://oauth2.googleapis.com/revoke'
            ],
            'github' => [
                'authorization' => 'https://github.com/login/oauth/authorize',
                'token' => 'https://github.com/login/oauth/access_token',
                'revoke' => null
            ],
            'facebook' => [
                'authorization' => 'https://www.facebook.com/v12.0/dialog/oauth',
                'token' => 'https://graph.facebook.com/v12.0/oauth/access_token',
                'revoke' => 'https://graph.facebook.com/v12.0/{user_id}/permissions'
            ],
            'twitter' => [
                'authorization' => 'https://twitter.com/i/oauth2/authorize',
                'token' => 'https://api.twitter.com/2/oauth2/token',
                'revoke' => 'https://api.twitter.com/2/oauth2/revoke'
            ],
            'yahoo' => [
                'authorization' => 'https://api.login.yahoo.com/oauth2/request_auth',
                'token' => 'https://api.login.yahoo.com/oauth2/get_token',
                'revoke' => 'https://api.login.yahoo.com/oauth2/revoke'
            ],
            'linkedin' => [
                'authorization' => 'https://www.linkedin.com/oauth/v2/authorization',
                'token' => 'https://www.linkedin.com/oauth/v2/accessToken',
                'revoke' => null
            ],
            'microsoft' => [
                'authorization' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
                'token' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
                'revoke' => 'https://login.microsoftonline.com/common/oauth2/v2.0/logout'
            ],
            'instagram' => [
                'authorization' => 'https://api.instagram.com/oauth/authorize',
                'token' => 'https://api.instagram.com/oauth/access_token',
                'revoke' => null
            ],
            'gitlab' => [
                'authorization' => 'https://gitlab.com/oauth/authorize',
                'token' => 'https://gitlab.com/oauth/token',
                'revoke' => 'https://gitlab.com/oauth/revoke'
            ],
            'bitbucket' => [
                'authorization' => 'https://bitbucket.org/site/oauth2/authorize',
                'token' => 'https://bitbucket.org/site/oauth2/access_token',
                'revoke' => 'https://bitbucket.org/site/oauth2/revoke'
            ],
            'amazon' => [
                'authorization' => 'https://www.amazon.com/ap/oa',
                'token' => 'https://api.amazon.com/auth/o2/token',
                'revoke' => null
            ],
            'slack' => [
                'authorization' => 'https://slack.com/oauth/v2/authorize',
                'token' => 'https://slack.com/api/oauth.v2.access',
                'revoke' => 'https://slack.com/api/auth.revoke'
            ]
        ];
    }

    private function getUserInfoEndpoints(): array
    {
        return [
            'google' => 'https://www.googleapis.com/oauth2/v3/userinfo',
            'github' => 'https://api.github.com/user',
            'facebook' => 'https://graph.facebook.com/v12.0/me?fields=id,name,email,picture',
            'twitter' => 'https://api.twitter.com/2/users/me',
            'yahoo' => 'https://api.login.yahoo.com/openid/v1/userinfo',
            'linkedin' => 'https://api.linkedin.com/v2/me',
            'microsoft' => 'https://graph.microsoft.com/v1.0/me',
            'instagram' => 'https://graph.instagram.com/me?fields=id,username,account_type,media_count',
            'gitlab' => 'https://gitlab.com/api/v4/user',
            'bitbucket' => 'https://api.bitbucket.org/2.0/user',
            'amazon' => 'https://api.amazon.com/user/profile',
            'slack' => 'https://slack.com/api/users.profile.get'
        ];
    }

    private function initializeProviders(): void
    {
        foreach ($this->config['endpoints'] as $provider => $endpoints) {
            $env = $this->getProviderEnvConfig(strtoupper($provider));
            if (($env['id'] === null && $env['secret'] === null)) {
                throw new MachinjiriException("Configuration for OAuth provider [ {$provider} ] not provided in .env");
            }
            $this->providers[$provider] = $this->createOAuthProvider(
                $provider,
                $env['id'],
                $env['secret'],
                $this->config['redirect_uri'] . '?provider=' . $provider,
                $endpoints['authorization'],
                $endpoints['token'],
                $this->config['scopes'][$provider] ?? []
            );
            
        }
    }

    private function getProviderEnvConfig(string $provider): array
    {
        return [
            'id' => env($provider . '_CLIENT_ID', null),
            'secret' => env($provider . '_CLIENT_SECRET', null)
        ];
    }

    private function createOAuthProvider(
        string $provider,
        string $clientId,
        string $clientSecret,
        string $redirectUri,
        string $authorizationEndpoint,
        string $tokenEndpoint,
        array $scopes
    ): OAuth {
        $oauth = new OAuth(
            $clientId,
            $clientSecret,
            $redirectUri,
            $authorizationEndpoint,
            $tokenEndpoint,
            $scopes,
            $this->session,
            $this->cookie,
            $this->httpClient ?? new HttpClient(),
            $this->logger
        );

        if ($this->queryBuilder) {
            $oauth->setQueryBuilder($this->queryBuilder);
        }

        return $oauth;
    }

    private function getProviderHttpOptions(string $provider): array
    {
        // HTTP options are now handled by HttpClient in OAuth class
        return [];
    }

    public function getProvider(string $provider): ?OAuth
    {
        return $this->providers[$provider] ?? null;
    }

    public function getAllProviders(): array
    {
        return $this->providers;
    }

    public function getAvailableProviders(): array
    {
        return array_keys($this->providers);
    }

    public function isProviderAvailable(string $provider): bool
    {
        return isset($this->providers[$provider]);
    }

    public function redirectToProvider(string $provider, HttpResponse $response): void
    {
        if (!$this->isProviderAvailable($provider)) {
            $this->log('error', 'Provider not available: {provider}', ['provider' => $provider]);
            throw new MachinjiriException("Provider '{$provider}' is not available or configured");
        }

        $this->session->set('auth_provider', $provider);
        $this->log('debug', 'Redirecting to provider: {provider}', ['provider' => $provider]);
        $this->providers[$provider]->handleAuthorizationRedirect($response);
    }

    public function handleCallback(HttpRequest $request, ?string $provider = null): array
    {
        $provider = $provider ?? $request->getQueryParam('provider') ?? $this->session->get('auth_provider');
        
        if (!$provider || !$this->isProviderAvailable($provider)) {
            $this->log('error', 'Invalid or missing provider in callback');
            throw new MachinjiriException('Invalid or missing provider');
        }

        try {
            // Clear stored provider from session
            $this->session->remove('auth_provider');

            // Handle OAuth callback
            $tokenData = $this->providers[$provider]->handleCallback($request);
            
            if (empty($tokenData['access_token'])) {
                throw new MachinjiriException('Failed to obtain access token');
            }

            // Get user info from provider
            $userInfoEndpoint = $this->config['user_info_endpoints'][$provider] ?? null;
            if (!$userInfoEndpoint) {
                throw new MachinjiriException("User info endpoint not configured for provider '{$provider}'");
            }

            $userInfo = $this->getUserInfo($provider, $tokenData['access_token']);
            
            // Store or update user in database
            $user = $this->processUser($provider, $userInfo, $tokenData);
            $this->log('info', "User authenticated with provider: {provider}", ['provider' => $provider]);

            return [
                'provider' => $provider,
                'user' => $user,
                'token' => $tokenData,
                'user_info' => $userInfo
            ];
        } catch (\Exception $e) {
            $this->log('error', "Authentication callback failed: {message}", ['message' => $e->getMessage()]);
            throw $e;
        }
    }

    public function getUserInfo(string $provider, string $accessToken): array
    {
        if (!isset($this->config['user_info_endpoints'][$provider])) {
            throw new MachinjiriException("User info endpoint not configured for provider '{$provider}'");
        }

        try {
            $url = $this->config['user_info_endpoints'][$provider];
            $headers = $this->getUserInfoHeaders($provider, $accessToken);

            $client = $this->httpClient ?? new HttpClient();
            $client->setHeaders($headers);
            
            $response = $client->get($url);

            if (isset($response['error']) && $response['error']) {
                throw new MachinjiriException("Failed to fetch user info: {$response['error']}");
            }

            if ($response['http_code'] >= 400) {
                throw new MachinjiriException("HTTP {$response['http_code']} from {$provider}: {$response['data']}");
            }

            $data = $this->parseJsonResponse($response['data'], $provider);

            // Handle provider-specific response formats
            return $this->normalizeUserInfo($provider, $data);
        } catch (\Throwable $e) {
            $this->log('error', "Failed to fetch user info from {provider}: {message}", 
                ['provider' => $provider, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function getUserInfoHeaders(string $provider, string $accessToken): array
    {
        $headers = [];

        switch ($provider) {
            case 'github':
                $headers[] = 'Authorization: token ' . $accessToken;
                $headers[] = 'Accept: application/vnd.github.v3+json';
                $headers[] = 'User-Agent: Machinjiri-App';
                break;
            case 'facebook':
                $headers[] = 'Authorization: Bearer ' . $accessToken;
                break;
            case 'twitter':
                $headers[] = 'Authorization: Bearer ' . $accessToken;
                break;
            case 'microsoft':
                $headers[] = 'Authorization: Bearer ' . $accessToken;
                break;
            case 'slack':
                $headers[] = 'Authorization: Bearer ' . $accessToken;
                break;
            default:
                $headers[] = 'Authorization: Bearer ' . $accessToken;
        }

        return $headers;
    }

    private function normalizeUserInfo(string $provider, array $data): array
    {
        if (empty($data)) {
            throw new MachinjiriException("Empty response from {$provider}");
        }

        $normalized = [
            'provider' => $provider,
            'provider_id' => '',
            'name' => '',
            'email' => '',
            'avatar_url' => ''
        ];

        switch ($provider) {
            case 'google':
                $normalized['provider_id'] = $data['sub'] ?? '';
                $normalized['name'] = $data['name'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = $data['picture'] ?? '';
                break;
            case 'github':
                $normalized['provider_id'] = (string)($data['id'] ?? '');
                $normalized['name'] = $data['name'] ?? $data['login'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = $data['avatar_url'] ?? '';
                break;
            case 'facebook':
                $normalized['provider_id'] = $data['id'] ?? '';
                $normalized['name'] = $data['name'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                // Safely access nested picture data
                $normalized['avatar_url'] = $data['picture']['data']['url'] ?? '';
                break;
            case 'twitter':
                $normalized['provider_id'] = $data['data']['id'] ?? '';
                $normalized['name'] = $data['data']['name'] ?? '';
                $normalized['email'] = ''; // Twitter OAuth 2.0 doesn't provide email by default
                $normalized['avatar_url'] = $data['data']['profile_image_url'] ?? '';
                break;
            case 'yahoo':
                $normalized['provider_id'] = $data['sub'] ?? '';
                $normalized['name'] = $data['name'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = '';
                break;
            case 'linkedin':
                $normalized['provider_id'] = $data['id'] ?? '';
                $normalized['name'] = ($data['localizedFirstName'] ?? '') . ' ' . ($data['localizedLastName'] ?? '');
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = '';
                break;
            case 'microsoft':
                $normalized['provider_id'] = $data['id'] ?? '';
                $normalized['name'] = $data['displayName'] ?? '';
                $normalized['email'] = $data['userPrincipalName'] ?? $data['mail'] ?? '';
                $normalized['avatar_url'] = ''; // Microsoft requires separate request for photo
                break;
            case 'slack':
                $normalized['provider_id'] = $data['user']['id'] ?? '';
                $normalized['name'] = $data['user']['real_name'] ?? '';
                $normalized['email'] = $data['user']['profile']['email'] ?? '';
                $normalized['avatar_url'] = $data['user']['profile']['image_192'] ?? '';
                break;
            case 'gitlab':
                $normalized['provider_id'] = (string)($data['id'] ?? '');
                $normalized['name'] = $data['name'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = $data['avatar_url'] ?? '';
                break;
            case 'bitbucket':
                $normalized['provider_id'] = $data['uuid'] ?? '';
                $normalized['name'] = $data['display_name'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = $data['links']['avatar']['href'] ?? '';
                break;
            case 'amazon':
                $normalized['provider_id'] = $data['user_id'] ?? '';
                $normalized['name'] = $data['name'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = '';
                break;
            case 'instagram':
                $normalized['provider_id'] = $data['id'] ?? '';
                $normalized['name'] = $data['username'] ?? '';
                $normalized['email'] = '';
                $normalized['avatar_url'] = '';
                break;
            default:
                // Generic mapping
                $normalized['provider_id'] = $data['id'] ?? $data['sub'] ?? '';
                $normalized['name'] = $data['name'] ?? $data['username'] ?? '';
                $normalized['email'] = $data['email'] ?? '';
                $normalized['avatar_url'] = $data['picture'] ?? $data['avatar_url'] ?? '';
        }

        // Trim whitespace and filter empty values
        return array_filter(array_map('trim', $normalized));
    }

    private function processUser(string $provider, array $userInfo, array $tokenData): array
    {
        if (!$this->queryBuilder) {
            return $userInfo;
        }

        try {
            // Check if user exists by email
            $existingUser = null;
            if (!empty($userInfo['email'])) {
                $existingUser = $this->queryBuilder
                    ->select(['*'])
                    ->from($this->config['user_table'])
                    ->where('email', '=', $userInfo['email'])
                    ->first();
            }

            // Check if provider connection exists
            $existingProvider = $this->queryBuilder
                ->select(['*'])
                ->from($this->config['provider_table'])
                ->where('provider', '=', $provider)
                ->where('provider_id', '=', $userInfo['provider_id'])
                ->first();

            if ($existingProvider) {
                // Update existing provider connection
                $this->queryBuilder
                    ->update([
                        'access_token' => $tokenData['access_token'],
                        'refresh_token' => $tokenData['refresh_token'] ?? null,
                        'token_expires' => date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600)),
                        'updated_at' => date('Y-m-d H:i:s')
                    ])
                    ->where('id', '=', $existingProvider['id'])
                    ->execute();

                $user = $this->queryBuilder
                    ->select(['*'])
                    ->from($this->config['user_table'])
                    ->where('id', '=', $existingProvider['user_id'])
                    ->first();

                if ($this->config['auto_sync_profile'] && $user) {
                    $this->syncUserProfile($user['id'], $userInfo);
                }

                $this->log('info', 'Provider connection updated for provider: {provider}', ['provider' => $provider]);
                return $user ?? $userInfo;
            }

            // Create new user or link to existing
            if ($existingUser) {
                $userId = $existingUser['id'];
                $this->log('info', 'Linking existing user to provider: {provider}', ['provider' => $provider]);
            } elseif ($this->config['auto_create_users']) {
                // Create new user
                $userId = $this->createUser($userInfo);
                $this->log('info', 'New user created and linked to provider: {provider}', ['provider' => $provider]);
            } else {
                throw new MachinjiriException('User does not exist and auto-create is disabled');
            }

            // Create provider connection
            $this->queryBuilder
                ->insert([
                    'user_id' => $userId,
                    'provider' => $provider,
                    'provider_id' => $userInfo['provider_id'],
                    'access_token' => $tokenData['access_token'],
                    'refresh_token' => $tokenData['refresh_token'] ?? null,
                    'token_expires' => date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600)),
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s')
                ])
                ->into($this->config['provider_table'])
                ->execute();

            // Store token separately if needed
            $this->storeUserToken($userId, $provider, $tokenData);

            $user = $this->queryBuilder
                ->select(['*'])
                ->from($this->config['user_table'])
                ->where('id', '=', $userId)
                ->first();

            return $user ?: $userInfo;
        } catch (\Exception $e) {
            $this->log('error', 'Failed to process user for provider {provider}: {message}', 
                ['provider' => $provider, 'message' => $e->getMessage()]);
            throw $e;
        }
    }

    private function createUser(array $userInfo): int
    {
        $userData = [
            'name' => $userInfo['name'] ?? 'Unknown User',
            'email' => $userInfo['email'] ?? '',
            'avatar_url' => $userInfo['avatar_url'] ?? null,
            'email_verified_at' => !empty($userInfo['email']) ? date('Y-m-d H:i:s') : null,
            'role' => $this->config['default_role'],
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s')
        ];

        $this->queryBuilder
            ->insert($userData)
            ->into($this->config['user_table'])
            ->execute();

        return $this->queryBuilder->lastInsertId();
    }

    private function syncUserProfile(int $userId, array $userInfo): void
    {
        $updateData = [];

        if (!empty($userInfo['name'])) {
            $updateData['name'] = $userInfo['name'];
        }

        if (!empty($userInfo['avatar_url'])) {
            $updateData['avatar_url'] = $userInfo['avatar_url'];
        }

        if (!empty($updateData)) {
            $updateData['updated_at'] = date('Y-m-d H:i:s');
            
            $this->queryBuilder
                ->update($updateData)
                ->where('id', '=', $userId)
                ->execute();
        }
    }

    private function storeUserToken(int $userId, string $provider, array $tokenData): void
    {
        $tokenRecord = [
            'user_id' => $userId,
            'provider' => $provider,
            'access_token' => $tokenData['access_token'],
            'refresh_token' => $tokenData['refresh_token'] ?? null,
            'token_type' => $tokenData['token_type'] ?? 'bearer',
            'expires_in' => $tokenData['expires_in'] ?? 3600,
            'scope' => is_array($tokenData['scope'] ?? null) ? implode(' ', $tokenData['scope']) : ($tokenData['scope'] ?? ''),
            'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => date('Y-m-d H:i:s', time() + ($tokenData['expires_in'] ?? 3600))
        ];

        $this->queryBuilder
            ->insert($tokenRecord)
            ->into($this->config['token_table'])
            ->execute();
    }

    public function disconnectProvider(string $provider, ?int $userId = null): bool
    {
        if (!$this->queryBuilder) {
            return false;
        }

        try {
            // Delete from provider table
            $query = $this->queryBuilder
                ->delete()
                ->from($this->config['provider_table'])
                ->where('provider', '=', $provider);
            
            if ($userId) {
                $query->where('user_id', '=', $userId);
            }
            
            $query->execute();

            // Also remove tokens
            $tokenQuery = $this->queryBuilder
                ->delete()
                ->from($this->config['token_table'])
                ->where('provider', '=', $provider);
            
            if ($userId) {
                $tokenQuery->where('user_id', '=', $userId);
            }
            
            $tokenQuery->execute();

            $this->log('info', 'Provider disconnected: {provider}', ['provider' => $provider]);
            return true;
        } catch (\Exception $e) {
            $this->log('error', 'Failed to disconnect provider {provider}: {message}', 
                ['provider' => $provider, 'message' => $e->getMessage()]);
            return false;
        }
    }

    public function getUserProviders(int $userId): array
    {
        if (!$this->queryBuilder) {
            return [];
        }

        return $this->queryBuilder
            ->select(['provider', 'provider_id', 'created_at'])
            ->from($this->config['provider_table'])
            ->where('user_id', '=', $userId)
            ->get();
    }

    public function refreshToken(string $provider, ?int $userId = null): ?array
    {
        $oauth = $this->getProvider($provider);
        if (!$oauth) {
            return null;
        }

        return $oauth->refreshToken();
    }

    public function isAuthenticated(?string $provider = null): bool
    {
        if ($provider) {
            $oauth = $this->getProvider($provider);
            return $oauth ? $oauth->isAuthenticated() : false;
        }

        // Check if any provider is authenticated
        foreach ($this->providers as $oauth) {
            if ($oauth->isAuthenticated()) {
                return true;
            }
        }

        return false;
    }

    public function getStoredToken(string $provider): ?array
    {
        $oauth = $this->getProvider($provider);
        return $oauth ? $oauth->getStoredToken() : null;
    }

    public function logout(?string $provider = null): void
    {
        if ($provider) {
            $oauth = $this->getProvider($provider);
            if ($oauth) {
                $oauth->logout();
            }
        } else {
            // Logout from all providers
            foreach ($this->providers as $oauth) {
                $oauth->logout();
            }
        }

        // Clear session data
        $this->session->destroy();
    }

    public function getLoginUrls(): array
    {
        $urls = [];
        foreach ($this->providers as $name => $oauth) {
            $urls[$name] = $oauth->getAuthorizationUrl();
        }
        return $urls;
    }

    public function getProviderButton(string $provider, array $attributes = []): string
    {
        if (!$this->isProviderAvailable($provider)) {
            return '';
        }

        $defaultAttrs = [
            'class' => 'btn btn-' . $provider,
            'title' => 'Login with ' . ucfirst($provider)
        ];

        $attrs = array_merge($defaultAttrs, $attributes);
        $attrString = '';
        foreach ($attrs as $key => $value) {
            $attrString .= " {$key}=\"" . htmlspecialchars($value) . "\"";
        }

        $url = $this->providers[$provider]->getAuthorizationUrl();

        return <<<HTML
<a href="{$url}"{$attrString}>
    <i class="fab fa-{$provider}"></i> Sign in with {$provider}
</a>
HTML;
    }

    public function getLoginButtons(?array $providers = null, array $attributes = []): string
    {
        $providers = $providers ?? $this->getAvailableProviders();
        $buttons = [];

        foreach ($providers as $provider) {
            $buttons[] = $this->getProviderButton($provider, $attributes);
        }

        return implode('<br>', $buttons);
    }

    public function setDefaultProvider(string $provider): self
    {
        if ($this->isProviderAvailable($provider)) {
            $this->defaultProvider = $provider;
        }
        return $this;
    }

    public function getDefaultProvider(): string
    {
        return $this->defaultProvider;
    }

    public function setUserMapping(array $mapping): self
    {
        $this->userMapping = array_merge($this->userMapping, $mapping);
        return $this;
    }

    public function setConfig(array $config): self
    {
        $this->config = array_merge($this->config, $config);
        return $this;
    }

    public function getConfig(): array
    {
        return $this->config;
    }

    public function setHttpClient(HttpClient $httpClient): self
    {
        $this->httpClient = $httpClient;
        return $this;
    }

    public function setLogger(Logger $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    private function parseJsonResponse(string $response, string $provider): array
    {
        $decoded = json_decode($response, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new MachinjiriException("Invalid JSON response from {$provider}: " . json_last_error_msg());
        }

        if (!is_array($decoded)) {
            throw new MachinjiriException("Expected JSON object from {$provider}, got " . gettype($decoded));
        }

        return $decoded;
    }

    private function log(string $level, string $message, array $context = []): void
    {
        if ($this->logger) {
            $this->logger->log($level, $message, $context);
        }
    }

    /**
     * Authenticate a user using an OAuth access token.
     * 
     * @param string $provider  Provider name (google, github, etc.)
     * @param string $accessToken
     * @return array|null  The user data (as stored in the users table) or null if invalid.
     * @throws MachinjiriException
     */
    public function authenticateWithToken(string $provider, string $accessToken): ?array
    {
        if (!$this->isProviderAvailable($provider)) {
            throw new MachinjiriException("Provider '{$provider}' not available.");
        }
    
        // Fetch user info from provider
        $userInfo = $this->getUserInfo($provider, $accessToken);
        if (empty($userInfo['provider_id'])) {
            throw new MachinjiriException("Invalid user info from provider.");
        }
    
        // Check if we already have a provider connection
        $existingProvider = $this->queryBuilder
            ->select(['*'])
            ->from($this->config['provider_table'])
            ->where('provider', '=', $provider)
            ->where('provider_id', '=', $userInfo['provider_id'])
            ->first();
    
        if ($existingProvider) {
            // Update token information
            $this->queryBuilder
                ->update([
                    'access_token' => $accessToken,
                    'updated_at' => date('Y-m-d H:i:s')
                ])
                ->where('id', '=', $existingProvider['id'])
                ->execute();
    
            // Return the user
            return $this->queryBuilder
                ->select(['*'])
                ->from($this->config['user_table'])
                ->where('id', '=', $existingProvider['user_id'])
                ->first();
        }
    
        // No existing provider connection. If auto-create is on, create user.
        if (!$this->config['auto_create_users']) {
            return null;
        }
    
        // Check if user exists by email
        $existingUser = null;
        if (!empty($userInfo['email'])) {
            $existingUser = $this->queryBuilder
                ->select(['*'])
                ->from($this->config['user_table'])
                ->where('email', '=', $userInfo['email'])
                ->first();
        }
    
        if ($existingUser) {
            $userId = $existingUser['id'];
        } else {
            // Create new user
            $userId = $this->createUser($userInfo);
        }
    
        // Create provider connection
        $this->queryBuilder
            ->insert([
                'user_id' => $userId,
                'provider' => $provider,
                'provider_id' => $userInfo['provider_id'],
                'access_token' => $accessToken,
                'refresh_token' => null, // We don't have refresh token here
                'token_expires' => null,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s')
            ])
            ->into($this->config['provider_table'])
            ->execute();
    
        // Return the user
        return $this->queryBuilder
            ->select(['*'])
            ->from($this->config['user_table'])
            ->where('id', '=', $userId)
            ->first();
    }
    
    /**
     * Retrieve a user by ID from the local users table.
     */
    public function findUserById(int $id): ?array
    {
        return $this->queryBuilder
            ->select(['*'])
            ->from($this->config['user_table'])
            ->where('id', '=', $id)
            ->first();
    }
    
}