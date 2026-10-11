<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Guards;

use Mlangeni\Machinjiri\Core\Authentication\Guard;
use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;
use Mlangeni\Machinjiri\Core\Authentication\UserProviders\UserProvider;
use Mlangeni\Machinjiri\Core\Security\Encryption\Bangwe;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class JwtGuard implements Guard
{
    protected ?Authenticatable $user = null;
    protected UserProvider $provider;
    protected Bangwe $bangwe;
    protected HttpRequest $request;

    public function __construct(UserProvider $provider, Bangwe $bangwe, ?HttpRequest $request = null)
    {
        $this->provider = $provider;
        $this->bangwe = $bangwe;
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

        try {
            $payload = $this->bangwe->decodeToken($token);
            $id = $payload->sub ?? null;
            if (!$id) {
                return null;
            }
            $this->user = $this->provider->retrieveById($id);
            return $this->user;
        } catch (MachinjiriException $e) {
            // Token invalid or expired
            return null;
        }
    }

    public function id(): mixed
    {
        $user = $this->user();
        return $user ? $user->getAuthIdentifier() : null;
    }

    public function validate(array $credentials): bool
    {
        $token = $credentials['token'] ?? $this->getTokenFromRequest();
        if (!$token) {
            return false;
        }
        return $this->bangwe->validateToken($token);
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
        return $this->request->input('token');
    }

    /**
     * Generate a JWT for a given user.
     * The expiration is controlled by the global JWT_EXPIRATION setting.
     */
    public function generateToken(Authenticatable $user, array $claims = []): string
    {
        $claims['sub'] = $user->getAuthIdentifier();
        return $this->bangwe->createToken($claims);
    }
}