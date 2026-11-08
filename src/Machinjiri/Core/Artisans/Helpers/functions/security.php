<?php

use Mlangeni\Machinjiri\Core\Security\Tokens\CSRFToken;


if (!function_exists('csrf_token_instance')) {
    /**
     * Get the CSRFToken instance.
     *
     * @return CSRFToken
     */
    function csrf_token_instance(): CSRFToken
    {
        return resolve(CSRFToken::class);
    }
}

if (!function_exists('csrf_token')) {
    /**
     * Get the current CSRF token.
     *
     * @return string
     */
    function csrf_token(): string
    {
        return csrf_token_instance()->getToken();
    }
}

if (!function_exists('csrf_field')) {
    /**
     * Generate a hidden input field with the CSRF token.
     *
     * @return string
     */
    function csrf_field(): string
    {
        return csrf_token_instance()->csrfField();
    }
}