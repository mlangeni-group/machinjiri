<?php

namespace Mlangeni\Machinjiri\Core\Authentication\UserProviders;

use Mlangeni\Machinjiri\Core\Authentication\Authenticatable;

interface UserProvider
{
    /**
     * Retrieve a user by their unique identifier.
     */
    public function retrieveById($id): ?Authenticatable;

    /**
     * Retrieve a user by the given credentials.
     */
    public function retrieveByCredentials(array $credentials): ?Authenticatable;

    /**
     * Retrieve a user by a remember token (the token is the plain value from the cookie).
     * The provider should extract the ID and verify the hashed token from storage.
     */
    public function retrieveByRememberToken(string $token): ?Authenticatable;

    /**
     * Update the user's remember token (store the hashed token).
     */
    public function updateRememberToken(Authenticatable $user, string $token): void;
}