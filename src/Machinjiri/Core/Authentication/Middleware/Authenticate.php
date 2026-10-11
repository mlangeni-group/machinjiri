<?php

namespace Mlangeni\Machinjiri\Core\Authentication\Middleware;

use Mlangeni\Machinjiri\Core\Artisans\Base\AbstractMiddleware;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Authentication\Auth;

class Authenticate extends AbstractMiddleware
{
    protected ?string $redirectTo = null;
    protected array $guards = [];

    public function __construct(array $guards = [])
    {
        $this->guards = $guards;
    }

    public function handle(HttpRequest $request, HttpResponse $response, callable $next, array $params = [])
    {
        $guards = empty($params) ? $this->guards : $params;
        
        foreach ($guards as $guard) {
            if (Auth::guard($guard)->check()) {
                return $next($request, $response);
            }
        }
        
        return $this->unauthenticated($request, $response);
    }

    protected function unauthenticated(HttpRequest $request, HttpResponse $response)
    {
        if ($request->expectsJson()) {
            return $response->sendError('Unauthenticated', 401);
        }
        return $response->redirect($this->redirectTo ?? '/login');
    }

    public function redirectTo(string $path): self
    {
        $this->redirectTo = $path;
        return $this;
    }
}