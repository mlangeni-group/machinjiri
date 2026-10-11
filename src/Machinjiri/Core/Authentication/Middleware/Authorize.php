<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Middleware;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Authentication\Auth;

class Authorize
{
    public function handle(HttpRequest $request, HttpResponse $response, callable $next, string $ability, ...$parameters)
    {
        if (!Auth::check()) {
            return $this->unauthorized($request, $response);
        }

        if (!Auth::hasPermission($ability)) {
            return $this->unauthorized($request, $response);
        }

        return $next($request, $response);
    }

    protected function unauthorized(HttpRequest $request, HttpResponse $response)
    {
        if ($request->expectsJson()) {
            return $response->sendError('Unauthorized', 403);
        }
        
        return $response->redirect('/')->send();
    }
}