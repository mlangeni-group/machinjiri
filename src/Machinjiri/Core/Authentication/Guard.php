<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

interface Guard
{
    /**
     * Check if user is authenticated
     */
    public function check(): bool;

    /**
     * Check if user is a guest (not authenticated)
     */
    public function guest(): bool;

    /**
     * Get the authenticated user
     */
    public function user(): ?Authenticatable;

    /**
     * Get the authenticated user's ID
     */
    public function id(): mixed;

    /**
     * Validate user credentials
     */
    public function validate(array $credentials): bool;

    /**
     * Set the current user
     */
    public function setUser(Authenticatable $user): void;

    /**
     * Attempt to authenticate a user
     */
    public function attempt(array $credentials, bool $remember = false): bool;

    /**
     * Log the user out
     */
    public function logout(): void;
}