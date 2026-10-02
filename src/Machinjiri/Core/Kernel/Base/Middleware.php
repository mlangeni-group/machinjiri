<?php

namespace Mlangeni\Machinjiri\Core\Kernel\Base;

use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;

interface Middleware 
{
    public function handle(HttpRequest $request, HttpResponse $response, callable $next, array $params = []);
}