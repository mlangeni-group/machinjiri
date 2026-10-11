<?php

namespace Mlangeni\Machinjiri\Core\Authentication\UserProviders;

use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Authentication\ThirdParty\ThirdPartyAuth;
use Mlangeni\Machinjiri\Core\Exceptions\AuthenticationException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;

class OAuthUserProvider implements UserProvider
{
    protected ThirdPartyAuth $thirdPartyAuth;
    protected string $modelClass;
    protected Logger $logger;
    protected EventListener $events;

    public function __construct(
        ThirdPartyAuth $thirdPartyAuth,
        string $modelClass,
        Logger $logger,
        EventListener $events
    ) {
        $this->thirdPartyAuth = $thirdPartyAuth;
        $this->modelClass = $modelClass;
        $this->logger = $logger;
        $this->events = $events;
    }

    public function retrieveById($id): ?Authenticatable
    {
        // Use ThirdPartyAuth to get the user from the local database
        $userData = $this->thirdPartyAuth->findUserById((int) $id);
        if (!$userData) {
            return null;
        }
        return $this->hydrateModel($userData);
    }

    /**
     * Retrieve a user by OAuth credentials.
     * Expected $credentials keys: 'provider' and 'access_token' (or 'token').
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable
    {
        $provider = $credentials['provider'] ?? null;
        $accessToken = $credentials['access_token'] ?? $credentials['token'] ?? null;


        // if (!$provider || !$accessToken) {
        //     $this->logger->warning('OAuth credentials missing provider or token');
        //     return null;
        // }

        try {
            $userData = $this->thirdPartyAuth->authenticateWithToken($provider, $accessToken);
            if (!$userData) {
                $this->events->trigger('auth.oauth.failed', ['provider' => $provider]);
                return null;
            }

            $user = $this->hydrateModel($userData);
            $this->events->trigger('auth.oauth.retrieved', ['user' => $user, 'provider' => $provider]);
            return $user;
        } catch (AuthenticationException $e) {
            $this->logger->error('OAuth retrieval failed: {message}', ['message' => $e->getMessage()]);
            return null;
        }
    }

    /**
     * Retrieve by remember token – not supported for OAuth.
     * Delegate to a database provider if needed.
     */
    public function retrieveByRememberToken(string $token): ?Authenticatable
    {
        // OAuth does not use remember tokens; return null.
        // Could be extended to check a local 'remember_token' column.
        return null;
    }

    /**
     * Update remember token – not supported for OAuth.
     * If needed, you could update the local user model.
     */
    public function updateRememberToken(Authenticatable $user, string $token): void
    {
        // OAuth does not use remember tokens; do nothing.
        // Optionally, you could implement it by storing the token on the local user.
    }

    protected function hydrateModel(array $data): Authenticatable
    {
        $model = new $this->modelClass($data);
        if (method_exists($model, 'exists')) {
            $model->exists = true;
        }
        return $model;
    }
}