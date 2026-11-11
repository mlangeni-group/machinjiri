<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\{MachinjiriException, ViewEngineException};
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Views\View;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Cors as CorsAttribute;
use Mlangeni\Machinjiri\Core\Routing\Attributes\Middleware as MiddlewareAttribute;

class Router
{
    protected static ?self $instance = null;
    protected RouteCollection $collection;
    protected RouteMatcher $matcher;
    protected UrlGenerator $urlGenerator;
    protected MiddlewareDispatcher $middlewareDispatcher;
    protected CorsManager $corsManager;
    protected RateLimiter $rateLimiter;
    protected RouteHandlerResolver $handlerResolver;
    protected HttpRequest $httpRequest;
    protected HttpResponse $httpResponse;
    protected array $groupStack = [];
    protected ?Route $lastRoute = null;
    protected string $basePath;
    protected string $documentRoot;
    protected RoutingConfig $config;
    protected ?Route $fallbackRoute = null;
    protected array $bindings = [];
    protected $constraints;

    private Container $container;

    private function __construct(?RoutingConfig $config = null)
    {
        $this->container = Container::instancePresent() ? Container::getInstance() : null;   
        $this->config = $config ?? $this->container->resolve(RoutingConfig::class);
        $this->httpRequest = HttpRequest::createFromGlobals();
        $this->httpResponse = new HttpResponse();
        $this->documentRoot = rtrim($_SERVER['DOCUMENT_ROOT'] ?? '', '/');
        $this->basePath = $this->autoDetectBasePath();

        $this->collection = new RouteCollection();
        $this->matcher = new RouteMatcher($this->basePath);
        $this->urlGenerator = new UrlGenerator($this->basePath);
        $this->urlGenerator->setCollection($this->collection);
        $this->middlewareDispatcher = new MiddlewareDispatcher($this->httpRequest, $this->httpResponse);
        $this->corsManager = new CorsManager($this->httpRequest, $this->httpResponse);
        $this->rateLimiter = new RateLimiter($this->container->resolve('cache.manager'), $this->config->rateLimiters);
        $this->handlerResolver = new RouteHandlerResolver();

        // Load cached routes if available
        $this->loadCachedRoutes();

    }

    protected function loadCachedRoutes(): void
    {
        $cache = new RouteCache($this->config->cacheFile, $this->collection);
        $cached = $cache->load();
        if ($cached !== null) {
            $this->collection = $cached;
            $this->urlGenerator->setCollection($this->collection);
        }
    }

    public static function getInstance(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public static function resetInstance(): void
    {
        self::$instance = null;
    }

    // Static shortcuts
    public static function get(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->addRoute(['GET'], $pattern, $handler, $name, $options);
    }

    public static function post(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->addRoute(['POST'], $pattern, $handler, $name, $options);
    }

    public static function put(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->addRoute(['PUT'], $pattern, $handler, $name, $options);
    }

    public static function delete(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->addRoute(['DELETE'], $pattern, $handler, $name, $options);
    }

    public static function any(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        return self::getInstance()->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH', 'OPTIONS'], $pattern, $handler, $name, $options);
    }

    public static function ajax(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        $options['ajax_only'] = true;
        return self::getInstance()->addRoute(['GET', 'POST', 'PUT', 'DELETE', 'PATCH'], $pattern, $handler, $name, $options);
    }

    public static function traditional(string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        $options['no_ajax'] = true;
        return self::getInstance()->addRoute(['GET', 'POST'], $pattern, $handler, $name, $options);
    }

    public static function group(array $attributes, callable $callback): self
    {
        return self::getInstance()->instanceGroup($attributes, $callback);
    }

    public static function middleware(mixed $middleware, ?callable $callback = null): self
    {
        return self::getInstance()->instanceMiddleware($middleware, $callback);
    }

    public static function cors(array $config = []): self
    {
        $instance = self::getInstance();
        if (func_num_args() > 1) {
            $callback = func_get_arg(1);
            return $instance->instanceCors($config, $callback);
        }
        return $instance->instanceCors($config);
    }

    public static function baseUrl(): string
    {
        return self::getInstance()->urlGenerator->getBaseUrl();
    }

    public static function route(string $name, array $params = []): string
    {
        return self::getInstance()->urlGenerator->url($name, $params);
    }

    public static function absoluteRoute(string $name, array $params = []): string
    {
        return self::getInstance()->urlGenerator->absoluteUrl($name, $params);
    }

    public static function dispatch(): void
    {
        self::getInstance()->instanceDispatch();
    }

    public static function resource(string $name, string $controller, array $options = []): self
    {
        return self::getInstance()->addResource($name, $controller, $options);
    }

    public static function fallback(callable $handler): self
    {
        return self::getInstance()->setFallback($handler);
    }

    public static function bind(string $param, string|callable $resolver): self
    {
        return self::getInstance()->addBinding($param, $resolver);
    }

    public static function controller(string $controllerClass): self
    {
        return self::getInstance()->registerController($controllerClass);
    }

    public static function controllers(array $controllerClasses): self
    {
        return self::getInstance()->registerControllers($controllerClasses);
    }

    // Instance methods
    public function addRoute(array $methods, string $pattern, mixed $handler, ?string $name = null, array $options = []): self
    {
        $pattern = $this->applyGroupPrefix($pattern);
        if ($this->basePath && strpos($pattern, $this->basePath) !== 0) {
            $pattern = $this->basePath . '/' . ltrim($pattern, '/');
        }
        $pattern = rtrim($pattern, '/') ?: '/';

        // Support optional parameters {param?}
        $constraints = $options['where'] ?? [];
        $regex = $this->compilePattern($pattern, $constraints);

        $route = new Route(
            methods: array_map('strtoupper', $methods),
            pattern: $pattern,
            handler: $handler,
            name: $name,
            regex: $regex,
            middleware: array_merge($this->getGroupMiddleware(), $options['middleware'] ?? []),
            cors: $options['cors'] ?? null,
            rateLimit: $options['rate_limit'] ?? null,
            ajaxOnly: $options['ajax_only'] ?? false,
            noAjax: $options['no_ajax'] ?? false,
            constraints: $constraints,
            bindings: $options['bindings'] ?? []
        );

        $this->collection->add($route);
        $this->lastRoute = $route;

        if ($route->getCors() && !in_array('OPTIONS', $route->getMethods())) {
            $this->addCorsRoute($route);
        }

        // Event: routeAdded($route)
        return $this;
    }

    public function addResource(string $name, string $controller, array $options = []): self
    {
        $only = $options['only'] ?? ['index', 'create', 'store', 'show', 'edit', 'update', 'destroy'];
        $except = $options['except'] ?? [];
        $actions = array_diff($only, $except);

        $patterns = [
            'index'   => ['GET', '', 'index'],
            'create'  => ['GET', '/create', 'create'],
            'store'   => ['POST', '', 'store'],
            'show'    => ['GET', '/{id}', 'show'],
            'edit'    => ['GET', '/{id}/edit', 'edit'],
            'update'  => ['PUT', '/{id}', 'update'],
            'destroy' => ['DELETE', '/{id}', 'destroy'],
        ];

        foreach ($actions as $action) {
            [$method, $suffix, $handlerMethod] = $patterns[$action];
            $routePattern = $name . $suffix;
            $routeName = "{$name}.{$action}";
            $this->addRoute([$method], $routePattern, "$controller@$handlerMethod", $routeName);
        }
        return $this;
    }

    public function setFallback(callable $handler): self
    {
        $this->fallbackRoute = new Route(
            methods: ['*'],
            pattern: '*',
            handler: $handler,
            name: null,
            regex: '#^.*$#',
            middleware: [],
            cors: null,
            rateLimit: null
        );
        return $this;
    }

    public function addBinding(string $param, string|callable $resolver): self
    {
        $this->bindings[$param] = $resolver;
        return $this;
    }

    public function where(string|array $param, ?string $regex = null): self
    {
        if (!$this->lastRoute) {
            throw new MachinjiriException('No route defined to apply constraints to.');
        }
        $constraints = $this->lastRoute->getConstraints();
        if (is_array($param)) {
            $constraints = array_merge($constraints, $param);
        } else {
            $constraints[$param] = $regex;
        }
        $newRegex = $this->compilePattern($this->lastRoute->getPattern(), $constraints);
        $this->lastRoute->setRegex($newRegex);
        // Update constraints via reflection (or make property writable)
        (fn() => $this->constraints = $constraints)->call($this->lastRoute);
        return $this;
    }

    public function instanceGroup(array $attributes, callable $callback): self
    {
        // Apply prefix and name prefix to routes inside
        $this->groupStack[] = $attributes;
        $callback($this);
        array_pop($this->groupStack);
        return $this;
    }

    public function instanceMiddleware(mixed $middleware, ?callable $callback = null): self
    {
        if ($callback) {
            return $this->instanceGroup(['middleware' => $middleware], $callback);
        }
        // Not used in original logic, but kept for consistency
        return $this;
    }

    public function instanceCors(array $config, ?callable $callback = null): self
    {
        $defaults = [
            'allowed_origins' => ['*'],
            'allowed_methods' => ['GET', 'POST', 'PUT', 'DELETE', 'OPTIONS'],
            'allowed_headers' => ['*'],
            'max_age' => 86400
        ];
        $config = array_merge($defaults, $config);

        if ($callback) {
            return $this->instanceGroup(['cors' => $config], $callback);
        }

        $this->instanceGroup(['cors' => $config], function () {});
        return $this;
    }

    public function instanceDispatch(): void
    {
        // if app is in maintenance render maintenance page
        if ($this->container->isDownForMaintenance()) {
            $this->sendError(503, [
                'message' => HttpResponse::getStatusTexts()[503], 
                'description' => 'Could not complete your request at the moment due to: Service Unavailable.'
            ]);
            return;
        }

        // Method spoofing
        if ($this->httpRequest->getMethod() === 'POST' && $method = $this->httpRequest->getPostParam('_method')) {
            $this->httpRequest->setMethod(strtoupper($method));
        }

        // CORS preflight
        if ($this->corsManager->handlePreflight(null)) {
            return;
        }

        $match = $this->matcher->match($this->collection, $this->httpRequest);
        if (!$match && $this->fallbackRoute) {
            $match = ['route' => $this->fallbackRoute, 'params' => []];
        }

        if (!$match) {
            $this->sendError(404, [
                'message' => HttpResponse::getStatusTexts()[404], 
                'description' => 'The page you are looking for could not be found.'
            ]);
            return;
        }

        /** @var Route $route */
        $route = $match['route'];
        $params = $match['params'];

        // Apply route model binding
        foreach ($route->getBindings() as $param => $resolver) {
            if (isset($params[$param])) {
                if (is_string($resolver) && class_exists($resolver)) {
                    $params[$param] = $resolver::findOrFail($params[$param]);
                } elseif (is_callable($resolver)) {
                    $params[$param] = $resolver($params[$param]);
                }
            }
        }

        // Rate limiting
        if ($rateLimit = $route->getRateLimit()) {
            $clientId = $this->httpRequest->getIp();
            if (!$this->rateLimiter->attempt($rateLimit, $clientId)) {
                $this->sendError(429, [
                    'message' => HttpResponse::getStatusTexts()[429], 
                    'description' => 'You have been rate limited. try again after some time.'
                ]);
                return;
            }
        }

        // CORS headers
        if ($cors = $route->getCors()) {
            $this->corsManager->applyHeaders($cors);
        }

        // Set JSON content type if needed
        if ($this->httpRequest->isAjax() || $this->expectsJson()) {
            $this->httpResponse->setHeader('Content-Type', 'application/json');
        }

        // Dispatch middleware and handler
        $this->middlewareDispatcher->dispatch(
            $route->getMiddleware(),
            function ($params) use ($route) {
                $result = $this->handlerResolver->execute($route->getHandler(), $params, $this->httpRequest, $this->httpResponse);
                $this->handleHandlerResult($result);
            },
            $params
        );
    }

    protected function sendError(int $code, array $context = []): void
    {
        if ($this->httpResponse->isSent()) {
            return;
        }

        if ($this->httpRequest->isAjax() || $this->expectsJson()) {
            $this->httpResponse
                ->setStatusCode($code)
                ->setJsonBody(['error' => 'Error ' . $code, 'message' => $context['message'] ?? ''])
                ->send();
            return;
        }

        // Use View class to render error page
        $body = '';
        try {
            // The error template should be located in $this->config->errorsDir . "/$code.php"
            $view = View::make("errors.{$code}", array_merge($context, [
                'request' => $this->httpRequest,
                'response' => $this->httpResponse
            ]));
            $body = $view->render();
        } catch (\Throwable $e) {
            // Fallback if view not found
            $message = $context['message'] ?? 'An error occurred';
            $description = $context['description'] ?? 'An internal error has occurred';
            $body = $this->buitInErrorPage($code, $message, $description);
        }

        $this->httpResponse->setStatusCode($code)->setBody($body)->send();
        return;
    }

    protected function handleHandlerResult(mixed $result): void
    {
        if ($this->httpResponse->isSent()) {
            return;
        }

        if ($this->httpRequest->isAjax() || $this->expectsJson()) {
            $this->httpResponse->setJsonBody(is_array($result) || is_object($result) ? $result : ['data' => $result]);
            $this->httpResponse->send();
            return;
        }

        if (is_string($result)) {
            $this->httpResponse->setBody($result);
            $this->httpResponse->send();
            return;
        }

        if ($result === null || is_object($result)) {

            if ($this->httpRequest->isAjax() || $this->expectsJson()) {
                $this->httpResponse->setJsonBody([
                    "Error" => true,
                    "code" => 204,
                    "message" => "No Content"
                ]);
                $this->httpResponse->send();
                return;
            }

            $this->httpResponse->setBody($this->buitInErrorPage(204, "No Content", "There was no content returned from your request"));
            $this->httpResponse->send();
            return;
        }

    }

    protected function autoDetectBasePath(): string
    {
        $containerBase = Container::getRoutingBase();
        if ($containerBase) {
            return rtrim($containerBase, '/');
        }
        $scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? '';
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        if (!$scriptFilename || !$scriptName) {
            return '';
        }
        $scriptDir = dirname($scriptFilename);
        if (strpos($scriptDir, $this->documentRoot) === 0) {
            $relativePath = substr($scriptDir, strlen($this->documentRoot));
            $basePath = rtrim($relativePath, '/');
            if (basename($scriptName) !== 'index.php') {
                $basePath = dirname($scriptName);
                $basePath = $basePath === '/' ? '' : rtrim($basePath, '/');
            }
            return $basePath;
        }
        $basePath = dirname($scriptName);
        return $basePath === '/' ? '' : rtrim($basePath, '/');
    }

    protected function compilePattern(string $pattern, array $constraints = []): string
    {
        $regex = preg_quote($pattern, '#');
        $regex = preg_replace_callback(
            '/\\\{([a-zA-Z_][a-zA-Z0-9_]*)\\\}/',
            function ($matches) use ($constraints) {
                $name = $matches[1];
                $pattern = $constraints[$name] ?? '[^/]+';
                return '(?P<' . $name . '>' . $pattern . ')';
            },
            $regex
        );
        return '#^' . $regex . '$#i';
    }

    protected function getGroupMiddleware(): array
    {
        $middleware = [];
        foreach ($this->groupStack as $group) {
            if (isset($group['middleware'])) {
                $middleware = array_merge($middleware, (array)$group['middleware']);
            }
        }
        return $middleware;
    }

    protected function applyGroupPrefix(string $pattern): string
    {
        $prefix = '';
        foreach ($this->groupStack as $group) {
            $prefix .= $group['prefix'] ?? '';
        }
        return $prefix . $pattern;
    }

    protected function addCorsRoute(Route $original): void
    {
        $this->addRoute(['OPTIONS'], $original->getPattern(), function () use ($original) {
            $this->corsManager->applyHeaders($original->getCors());
            $this->httpResponse->setStatusCode(204)->send();
        }, null, ['cors' => false]);
    }

    protected function getRateLimitMax(string $limiterName): int
    {
        return 60;
    }

    protected function getRateLimitPeriod(string $limiterName): int
    {
        return 60;
    }

    protected function expectsJson(): bool
    {
        $accept = $this->httpRequest->getHeader('Accept') ?? '';
        return str_contains($accept, 'application/json');
    }

    protected function sendNotFound(): void
    {
        if ($this->httpResponse->isSent()) {
            return;
        }
    
        if ($this->httpRequest->isAjax() || $this->expectsJson()) {
            $this->httpResponse
                ->setStatusCode(404)
                ->setJsonBody([
                    'error' => 'Not Found',
                    'message' => 'The requested resource was not found',
                    'code' => 404,
                    'timestamp' => time()
                ])
                ->send();
        } else {
            $requestUri = htmlspecialchars($this->httpRequest->getServerParam('REQUEST_URI') ?? '/', ENT_QUOTES, 'UTF-8');
            $body = $this->renderErrorPage(404, [
                'requestUri' => $requestUri,
                'appName' => env('APP_NAME') ?? 'Machinjiri',
                'code' => 404,
                'error' => 'Not Found',
                'message' => "The requested resource was not found"
            ]);
            $this->httpResponse
                ->setStatusCode(404)
                ->setBody($body)
                ->send();
        }
    }
    
    protected function sendRateLimitExceeded(): void
    {
        if ($this->httpRequest->isAjax() || $this->expectsJson()) {
            $this->httpResponse
                ->setStatusCode(429)
                ->setHeader('Retry-After', '60')
                ->setJsonBody([
                    'error' => 'Rate Limit Exceeded',
                    'message' => 'Too many requests. Please try again later.',
                    'code' => 429,
                    'retry_after' => 60,
                    'timestamp' => time()
                ])
                ->send();
        } else {
            $body = $this->renderErrorPage(429, [
                'retryAfter' => 60,
                'appName' => env('APP_NAME') ?? 'Machinjiri'
            ]);
            $this->httpResponse
                ->setStatusCode(429)
                ->setHeader('Retry-After', '60')
                ->setBody($body)
                ->send();
        }
    }

    /**
     * Render an error page from the resources/errors directory.
     *
     * @param int $statusCode HTTP status code (e.g., 404, 429)
     * @param array $context Additional variables to extract in the error template
     * @return string Rendered HTML
     */
    protected function renderErrorPage(int $statusCode, array $context = []): string
    {
        $errorFile = $this->getErrorPagePath($statusCode);
        if (file_exists($errorFile)) {
            // Extract variables so the template can use them directly
            extract($context, EXTR_SKIP);
            ob_start();
            include $errorFile;
            return ob_get_clean();
        }
    
        // Fallback in case the error template is missing
        return $this->buitInErrorPage(
            $statusCode,
            $context['message'] ?? 'An error occurred'
        );
    }
    
    /**
     * Get the full filesystem path to the error page template for a given HTTP status code.
     *
     * @param int $statusCode
     * @return string
     */
    protected function getErrorPagePath(int $statusCode): string
    {
        // Container::$appBasePath is e.g. /var/www/project/app
        $projectRoot = dirname(Container::$appBasePath);
        return $projectRoot . '/resources/errors/' . $statusCode . '.php';
    }

    public function buitInErrorPage(int $code, string $message, ?string $description = null): string
    {
        $appName = htmlspecialchars(ucfirst(env('APP_NAME', 'Machinjiri')), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $description = htmlspecialchars($description ?? '', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <meta name="color-scheme" content="light dark" />
    <title>{$code} · {$appName}</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f5f3ff;
            --panel: rgba(255, 255, 255, 0.82);
            --panel-strong: #ffffff;
            --border: rgba(109, 91, 208, 0.14);
            --primary: #6d5bd0;
            --primary-strong: #4d3eb6;
            --primary-soft: #efeaff;
            --text: #1f2430;
            --muted: #5f6676;
            --danger: #ef4444;
            --shadow: 0 22px 50px rgba(44, 34, 87, 0.14);
            --radius: 22px;
            --mono: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
            --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                color-scheme: dark;
                --bg: #120f1d;
                --panel: rgba(22, 24, 37, 0.9);
                --panel-strong: #181b2a;
                --border: rgba(173, 160, 255, 0.16);
                --primary: #a78bfa;
                --primary-strong: #c4b5fd;
                --primary-soft: rgba(167, 139, 250, 0.12);
                --text: #edf2ff;
                --muted: #a4acc5;
                --danger: #f87171;
                --shadow: 0 22px 50px rgba(10, 8, 20, 0.55);
            }
        }

        *,
        *::before,
        *::after {
            box-sizing: border-box;
        }

        html { min-height: 100%; }

        body {
            display: grid;
            min-height: 100vh;
            margin: 0;
            padding: 24px;
            background:
                radial-gradient(circle at top left, rgba(109, 91, 208, 0.14), transparent 32%),
                linear-gradient(180deg, #f7f7ff 0%, var(--bg) 100%);
            color: var(--text);
            font-family: var(--sans);
        }

        @media (prefers-color-scheme: dark) {
            body {
                background:
                    radial-gradient(circle at top left, rgba(167, 139, 250, 0.18), transparent 32%),
                    linear-gradient(180deg, #120f1d 0%, #171824 100%);
            }
        }

        .error-shell {
            width: min(100%, 620px);
            margin: 0 auto;
        }

        .error-container {
            overflow: hidden;
            padding: clamp(24px, 6vw, 42px);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            backdrop-filter: blur(12px);
        }

        .error-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            margin-bottom: 34px;
        }

        .error-label {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            color: var(--muted);
            font-size: 0.76rem;
            font-weight: 700;
            letter-spacing: 0.1em;
            text-transform: uppercase;
        }

        .error-label::before {
            width: 8px;
            height: 8px;
            background: var(--danger);
            border-radius: 50%;
            content: "";
        }

        .app-name {
            max-width: 55%;
            overflow: hidden;
            padding: 7px 12px;
            color: var(--primary-strong);
            background: var(--primary-soft);
            border: 1px solid var(--border);
            border-radius: 999px;
            font-size: 0.78rem;
            font-weight: 650;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .error-code {
            display: block;
            margin-bottom: 18px;
            color: var(--primary);
            font-family: var(--mono);
            font-size: clamp(4.5rem, 18vw, 7rem);
            font-weight: 750;
            letter-spacing: -0.09em;
            line-height: 0.9;
        }

        .error-message {
            margin: 0 0 12px;
            color: var(--text);
            font-size: clamp(1.45rem, 5vw, 2rem);
            font-weight: 700;
            letter-spacing: -0.035em;
            line-height: 1.2;
        }

        .error-description {
            margin: 0;
            padding-top: 18px;
            color: var(--muted);
            border-top: 1px solid var(--border);
            font-size: 0.98rem;
            line-height: 1.7;
            overflow-wrap: anywhere;
        }

        @media (max-width: 480px) {
            body { padding: 16px; }

            .error-header {
                align-items: flex-start;
                margin-bottom: 28px;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                scroll-behavior: auto !important;
            }
        }
    </style>
</head>
<body>
    <main class="error-shell">
        <section class="error-container" role="alert" aria-live="assertive">
            <header class="error-header">
                <span class="error-label">Request error</span>
                <span class="app-name">{$appName}</span>
            </header>
            <span class="error-code">{$code}</span>
            <h1 class="error-message">{$message}</h1>
            <p class="error-description">{$description}</p>
        </section>
    </main>
</body>
</html>
HTML;
    }

    /**
     * Register all routes declared with PHP attributes on a controller.
     */
    public function registerController(string $controllerClass): self
    {
        (new AttributeRouteRegistrar($this))->register($controllerClass);
        return $this;
    }

    /**
     * Register attribute-declared routes for many controllers.
     */
    public function registerControllers(array $controllerClasses): self
    {
        (new AttributeRouteRegistrar($this))->registerMany($controllerClasses);
        return $this;
    }

}