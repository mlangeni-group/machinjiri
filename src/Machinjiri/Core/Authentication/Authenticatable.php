<?php

namespace Mlangeni\Machinjiri\Core\Authentication;

interface Authenticatable
{
    /**
     * Get the unique identifier for the user
     */
    public function getAuthIdentifier(): mixed;

    /**
     * Get the password for the user
     */
    public function getAuthPassword(): string;

    /**
     * Get the "remember me" token value
     */
    public function getRememberToken(): string;

    /**
     * Set the "remember me" token value
     */
    public function setRememberToken(string $token): void;

    /**
     * Get the column name for the "remember me" token
     */
    public function getRememberTokenName(): string;

    /**
     * Get the user's name for display purposes
     */
    public function getAuthIdentifierName(): string;

    /**
     * Check if user has a specific role/permission
     */
    public function hasRole(string $role): bool;

    /**
     * Check if user has permission
     */
    public function hasPermission(string $permission): bool;
}