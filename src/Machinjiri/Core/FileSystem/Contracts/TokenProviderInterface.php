<?php

namespace Mlangeni\Machinjiri\Core\FileSystem\Contracts;

interface TokenProviderInterface
{
    /**
     * Return a currently valid bearer token.
     *
     * Implementations MUST refresh when the cached token expires within
     * the next 5 minutes. This method is called on every outbound request,
     * so it must be cheap when the token is still valid.
     *
     * @return array{token:string,expires_at:int}
     */
    public function getToken(): array;
}