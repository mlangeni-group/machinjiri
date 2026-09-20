<?php

namespace Mlangeni\Machinjiri\Core\Routing;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
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
                'message' => $this->httpResponse->statusTexts[503], 
                'description' => 'Could not complete your request at the moment due to: Service Unavailable.'
            ]);
            return;
        }

        // Method spoofing
        if ($this->httpRequest->getMethod() === 'POST' && $method = $this->httpRequest->getPostParam('_method')) {
            // $this->httpRequest->setMethod(strtoupper($method));
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
                'message' => $this->httpResponse->statusTexts[404], 
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
                    'message' => $this->httpResponse->statusTexts[429], 
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
            // It can optionally extend a layout using View::extend()
            $view = View::make("errors.{$code}", array_merge($context, [
                'request' => $this->httpRequest,
                'response' => $this->httpResponse
            ]));
            $body = $view->render();
        } catch (\Exception $e) {
            // Fallback if view not found
            $message = $context['message'] ?? 'An error occurred';
            $description = $context['description'] ?? 'An internal error has occurred';
            $body = $this->buitInErrorPage($code, $message, $description);
        }

        $this->httpResponse->setStatusCode($code)->setBody($body)->send();
        
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
        } elseif ($result === null) {
            $this->httpResponse->setStatusCode(204);
            $this->httpResponse->setBody(
                $this->buitInErrorPage(204, "No Content", "There was no content returned from your request")
            );
        } else {
            throw new MachinjiriException(
                'Returning an array or object in a traditional (non‑AJAX) route is not allowed. ' .
                'Use $this->httpResponse->setJsonBody() or mark the route as AJAX.'
            );
        }

        if (!$this->httpResponse->isSent()) {
            $this->httpResponse->send();
        }
    }

    // Helper methods (unchanged from original where appropriate)
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
         $appName = ucfirst(env('APP_NAME', 'Machinjiri'));
         return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{$appName} - {$code} Error</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #f7f9fc;
            --card-bg: #ffffff;
            --text-primary: #1a1a2e;
            --text-muted: #4a4a6a;
            --border-color: #e2e8f0;
            --accent: #e68a5e;
            --accent-hover: #d4794a;
            --shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.15);
            --radius: 1.25rem;
            --font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, Cantarell, 'Open Sans', 'Helvetica Neue', sans-serif;
            --code-font: 'SF Mono', 'Fira Code', 'Cascadia Code', monospace;
            --transition: 0.2s ease-in-out;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1117;
                --card-bg: #1a1c23;
                --text-primary: #edf2f7;
                --text-muted: #a0aec0;
                --border-color: #2d3748;
                --shadow: 0 20px 40px -12px rgba(0, 0, 0, 0.6);
                --accent: #f09b74;
                --accent-hover: #e68a5e;
            }
        }

        body {
            background: var(--bg);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: var(--font-family);
            color: var(--text-primary);
            padding: 1.5rem;
            margin: 0;
            transition: background var(--transition), color var(--transition);
        }

        .error-container {
            background: var(--card-bg);
            max-width: 600px;
            width: 100%;
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 2.5rem 2.5rem 2rem;
            transition: background var(--transition), box-shadow var(--transition);
            border: 1px solid var(--border-color);
        }

        .error-header {
            display: flex;
            align-items: baseline;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 0.75rem 1rem;
            margin-bottom: 1.5rem;
        }

        .error-code {
            font-size: 4rem;
            font-weight: 700;
            line-height: 1;
            color: var(--accent);
            letter-spacing: -0.04em;
            font-family: var(--code-font);
        }

        .app-name {
            font-size: 0.875rem;
            font-weight: 500;
            color: var(--text-muted);
            background: var(--bg);
            padding: 0.4rem 1rem;
            border-radius: 2rem;
            border: 1px solid var(--border-color);
            white-space: nowrap;
        }

        .error-message {
            font-size: 1.5rem;
            font-weight: 600;
            margin: 0.25rem 0 0.75rem 0;
            color: var(--text-primary);
        }

        .error-description {
            font-size: 1rem;
            line-height: 1.6;
            color: var(--text-muted);
            margin: 0 0 2rem 0;
            padding: 1rem 0 0 0;
            border-top: 1px solid var(--border-color);
        }

        /* ----- Responsive ----- */
        @media (max-width: 480px) {
            .error-container {
                padding: 1.75rem 1.25rem 1.5rem;
            }

            .error-code {
                font-size: 3rem;
            }

            .error-message {
                font-size: 1.25rem;
            }
        }

        @media (prefers-reduced-motion: reduce) {
            *,
            *::before,
            *::after {
                transition-duration: 0.01ms !important;
                animation-duration: 0.01ms !important;
            }
        }
    </style>
</head>
<body>
    <div class="error-container" role="alert" aria-live="assertive">
        <div class="error-header">
            <span class="error-code">{$code}</span>
            <span class="app-name">{$appName}</span>
        </div>

        <h1 class="error-message">{$message}</h1>
        <p class="error-description">{$description}</p>

    </div>
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