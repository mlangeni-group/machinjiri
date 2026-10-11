<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Guards;

use Mlangeni\Machinjiri\Core\Authentication\Guard;
use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Authentication\UserProviders\UserProvider;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Container;

class TokenGuard implements Guard
{
    protected ?Authenticatable $user = null;
    protected UserProvider $provider;
    protected HttpRequest $request;

    public function __construct(UserProvider $provider, ?HttpRequest $request = null)
    {
        $this->provider = $provider;
        $this->request = $request ?? resolve(HttpRequest::class);
    }

    public function check(): bool
    {
        return !is_null($this->user());
    }

    public function guest(): bool
    {
        return !$this->check();
    }

    public function user(): ?Authenticatable
    {
        if (!is_null($this->user)) {
            return $this->user;
        }

        $token = $this->getTokenFromRequest();
        if (!$token) {
            return null;
        }

        // Use the 'api_token' column to retrieve the user
        $this->user = $this->provider->retrieveByCredentials(['api_token' => $token]);
        return $this->user;
    }

    public function id(): mixed
    {
        $user = $this->user();
        return $user ? $user->getAuthIdentifier() : null;
    }

    public function validate(array $credentials): bool
    {
        $token = $credentials['api_token'] ?? $this->getTokenFromRequest();
        if (!$token) {
            return false;
        }
        return !is_null($this->provider->retrieveByCredentials(['api_token' => $token]));
    }

    public function setUser(Authenticatable $user): void
    {
        $this->user = $user;
    }

    public function attempt(array $credentials, bool $remember = false): bool
    {
        return $this->validate($credentials);
    }

    public function logout(): void
    {
        $this->user = null;
    }

    protected function getTokenFromRequest(): ?string
    {
        $header = $this->request->getHeader('Authorization');
        if ($header && preg_match('/Bearer\s+(.*)$/i', $header, $matches)) {
            return $matches[1];
        }
        return $this->request->input('api_token');
    }
}