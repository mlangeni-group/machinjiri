<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

use Mlangeni\Machinjiri\Core\Authentication\AuthManager;
use Mlangeni\Machinjiri\Core\Container;

/**
 * Facade for the authentication system.
 *
 * All methods are proxied to the underlying AuthManager instance.
 */
class Auth
{
    protected static ?AuthManager $manager = null;

    /**
     * Get the underlying AuthManager instance.
     */
    protected static function getManager(): AuthManager
    {
        if (static::$manager === null) {
            static::$manager = resolve(AuthManager::class);
        }
        return static::$manager;
    }

    /**
     * Initialize the authentication system (legacy compatibility).
     * Now uses the container to set up the manager.
     */
    public static function initialize(array $config = []): void
    {
        // The manager is configured via the container; this is a no‑op for backward compatibility.
        // If you need to override config, you can pass it to the container binding.
        // For now, we ensure the manager is resolved.
        static::getManager();
    }

    /**
     * Dynamically proxy static calls to the manager.
     */
    public static function __callStatic(string $method, array $parameters)
    {
        return static::getManager()->$method(...$parameters);
    }

    // --- Explicit methods for IDE completion ---

    public static function guard(?string $name = null): Guard
    {
        return static::getManager()->guard($name);
    }

    public static function check(): bool
    {
        return static::getManager()->check();
    }

    public static function guest(): bool
    {
        return static::getManager()->guest();
    }

    public static function user(): ?Authenticatable
    {
        return static::getManager()->user();
    }

    public static function id(): mixed
    {
        return static::getManager()->id();
    }

    public static function attempt(array $credentials, bool $remember = false): bool
    {
        return static::getManager()->attempt($credentials, $remember);
    }

    public static function login(Authenticatable $user, bool $remember = false): void
    {
        static::getManager()->login($user, $remember);
    }

    public static function logout(): void
    {
        static::getManager()->logout();
    }

    public static function validate(array $credentials): bool
    {
        return static::getManager()->validate($credentials);
    }

}