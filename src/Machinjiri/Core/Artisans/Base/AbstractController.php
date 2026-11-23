<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Base;

use Mlangeni\Machinjiri\Core\Authentication\Cookie;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\FileSystemManager;
use Mlangeni\Machinjiri\Core\Forms\FileUpload;
use Mlangeni\Machinjiri\Core\Forms\FormErrorBag;
use Mlangeni\Machinjiri\Core\Forms\FormRequest;
use Mlangeni\Machinjiri\Core\Forms\FormValidator;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Security\Tokens\CSRFToken;
use Mlangeni\Machinjiri\Core\Views\View;

/**
 * Base Controller Class
 *
 * All application controllers must extend this class.
 *
 * @method void abort(int $code, string $message = '')
 * @method array validate(array $rules, array $messages = [])
 * @method string csrfField()
 */
abstract class AbstractController
{
    protected HttpRequest $request;
    protected HttpResponse $response;
    protected Session $session;
    protected Cookie $cookie;
    protected CSRFToken $csrfToken;
    protected FileSystemManager $fileSystem;

    /**
     * Constructor. Automatically injects dependencies from container if available.
     * Can be overridden by child controllers.
     *
     * @param HttpRequest|null $request
     * @param HttpResponse|null $response
     * @param Session|null $session
     * @param Cookie|null $cookie
     * @param CSRFToken|null $csrfToken
     * @param FileSystemManager|null $fileSystem
     */
    public function __construct(
        ?HttpRequest $request = null,
        ?HttpResponse $response = null,
        ?Session $session = null,
        ?Cookie $cookie = null,
        ?CSRFToken $csrfToken = null,
        ?FileSystemManager $fileSystem = null
    ) {
        // Use provided or resolve from container
        $this->request = $request ?? resolve(HttpRequest::class);
        $this->response = $response ?? resolve(HttpResponse::class);
        $this->session = $session ?? resolve(Session::class);
        $this->cookie = $cookie ?? resolve(Cookie::class);
        $this->csrfToken = $csrfToken ?? resolve(CSRFToken::class);
        $this->fileSystem = $fileSystem ?? resolve(FileSystemManager::class);
    }

    /**
     * Set the request and response instances (used by router if not injected via constructor).
     *
     * @param HttpRequest $request
     * @param HttpResponse $response
     * @return void
     */
    public function setRequestResponse(HttpRequest $request, HttpResponse $response): void
    {
        $this->request = $request;
        $this->response = $response;
    }

    /**
     * Render a view and return its content as a string.
     *
     * @param string $view   View name (dot notation or namespace::view)
     * @param array  $data   Data to pass to the view
     * @return string
     * @throws MachinjiriException
     */
    protected function view(string $view, array $data = []): string
    {
        return View::make($view, $data)->render();
    }

    /**
     * Return a JSON response.
     *
     * @param mixed $data
     * @param int   $statusCode
     * @return HttpResponse
     */
    protected function json($data, int $statusCode = 200): HttpResponse
    {
        return $this->response
            ->setStatusCode($statusCode)
            ->setJsonBody($data);
    }

    /**
     * Redirect to a given URL.
     *
     * @param string $url
     * @param int    $statusCode
     * @return HttpResponse
     */
    protected function redirect(string $url, int $statusCode = 302): HttpResponse
    {
        $this->response
            ->redirect($url, $statusCode)
            ->send();
        return $this->response;
    }

    /**
     * Redirect back to the previous URL.
     *
     * @param int $statusCode
     * @return HttpResponse
     */
    protected function back(int $statusCode = 302): HttpResponse
    {
        $referer = $this->safeReferer();
        return $this->redirect($referer, $statusCode);
    }

    /**
     * Redirect to a named route.
     *
     * @param string $routeName
     * @param array  $params
     * @param int    $statusCode
     * @return HttpResponse
     * @throws MachinjiriException if route not found
     */
    protected function redirectToRoute(string $routeName, array $params = [], int $statusCode = 302): HttpResponse
    {
        $url = \Mlangeni\Machinjiri\Core\Routing\Router::route($routeName, $params);
        return $this->redirect($url, $statusCode);
    }
    
    /**
     * Generate a versioned asset URL.
     *
     * @param string $path
     * @return string
     * @throws MachinjiriException
     */
    protected function asset(string $path): string
    {
        return View::asset($path);
    }

    /**
     * Output a <link rel="stylesheet"> tag for the given asset.
     *
     * @param string $path
     * @param array  $attributes
     * @return void
     */
    protected function style(string $path, array $attributes = []): void
    {
        View::style($path, $attributes);
    }

    /**
     * Output a <script> tag for the given asset.
     *
     * @param string $path
     * @param array  $attributes
     * @return void
     */
    protected function script(string $path, array $attributes = []): void
    {
        View::script($path, $attributes);
    }

    /**
     * Share data globally with all views.
     *
     * @param array|string $key
     * @param mixed|null   $value
     * @return void
     */
    protected function share($key, $value = null): void
    {
        View::share($key, $value);
    }

    // -------------------------------------------------------------------------
    // Session & Flash Helpers
    // -------------------------------------------------------------------------

    /**
     * Get the session instance.
     *
     * @return Session
     */
    protected function session(): Session
    {
        return $this->session;
    }

    /**
     * Flash a message to the session.
     *
     * @param string $key
     * @param mixed  $value
     * @return void
     */
    protected function flash(string $key, $value): void
    {
        $this->session->set('flash_' . $key, $value);
    }

    /**
     * Get a flashed message and remove it.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function getFlash(string $key, $default = null)
    {
        $value = $this->session->get('flash_' . $key, $default);
        $this->session->set('flash_' . $key, null);
        return $value;
    }

    /**
     * Keep old input for repopulating forms.
     *
     * @param array $input
     * @return void
     */
    protected function withInput(?array $input = null): void
    {
        $data = $input ?? $this->request->all();
        $this->session->set('old_input', $data);
    }

    /**
     * Retrieve old input value.
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function old(string $key, $default = null)
    {
        $old = $this->session->get('old_input', []);
        return $old[$key] ?? $default;
    }

    /**
     * Retrieve validation errors from session.
     *
     * @return FormErrorBag
     */
    protected function errors(): FormErrorBag
    {
        $errors = $this->session->get('form_errors', []);
        $bag = new FormErrorBag();
        $bag->merge($errors);
        return $bag;
    }
    
    /**
     * Generate a CSRF token input field.
     *
     * @return string
     */
    protected function csrfField(): string
    {
        $token = $this->csrfToken->getToken();
        return '<input type="hidden" name="_token" value="' . htmlspecialchars($token, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">';
    }

    /**
     * Verify the CSRF token from the request.
     *
     * @return bool
     */
    protected function verifyCsrf(): bool
    {
        $token = $this->request->input('_token') ?? $this->request->getHeader('X-CSRF-TOKEN');
        return $this->csrfToken->validateToken((string) $token);
    }

    /**
     * Accept only same-origin or root-relative return URLs.
     */
    private function safeReferer(): string
    {
        $referer = trim($this->request->getReferer());
        if ($referer === '') {
            return '/';
        }

        if (str_starts_with($referer, '/') && !str_starts_with($referer, '//')) {
            return $referer;
        }

        $parts = parse_url($referer);
        if (!is_array($parts) || !isset($parts['host'])) {
            return '/';
        }

        $expectedHost = (string) ($this->request->getServerParam('HTTP_HOST')
            ?? $this->request->getServerParam('SERVER_NAME')
            ?? '');
        $expectedHost = strtolower(preg_replace('/:\d+$/', '', $expectedHost) ?? $expectedHost);
        $actualHost = strtolower(preg_replace('/:\d+$/', '', $parts['host']) ?? $parts['host']);

        if ($expectedHost === '' || $actualHost !== $expectedHost) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        $query = isset($parts['query']) ? '?' . $parts['query'] : '';
        return $path . $query;
    }

    // -------------------------------------------------------------------------
    // Validation Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate request data using a FormRequest class.
     *
     * @param string $formRequestClass Fully qualified FormRequest class name
     * @return array Validated data
     * @throws MachinjiriException
     */
    protected function validateWith(string $formRequestClass): array
    {
        if (!is_subclass_of($formRequestClass, FormRequest::class)) {
            throw new MachinjiriException("{$formRequestClass} must extend " . FormRequest::class);
        }

        /** @var FormRequest $formRequest */
        $formRequest = Container::resolve($formRequestClass, [
            'httpRequest' => $this->request,
            'httpResponse' => $this->response,
            'session' => $this->session,
            'csrfToken' => $this->csrfToken,
            'fileSystem' => $this->fileSystem
        ]);

        if (!$formRequest->validate()) {
            // For AJAX requests, send JSON error response
            if ($this->request->isAjax() || $this->request->expectsJson()) {
                $formRequest->respondWithErrors()->send();
                return [];
            }

            // Otherwise redirect back with errors and old input
            $formRequest->redirectBack()->send();
            return [];
        }

        return $formRequest->validated();
    }

    /**
     * Validate request data using simple rules array.
     *
     * @param array $rules
     * @param array $messages
     * @return array Validated data
     * @throws MachinjiriException
     */
    protected function validate(array $rules, array $messages = []): array
    {
        $validator = new FormValidator($this->request->all());

        foreach ($rules as $field => $ruleString) {
            $parts = explode('|', $ruleString);
            foreach ($parts as $rule) {
                $params = [];
                if (strpos($rule, ':') !== false) {
                    [$ruleName, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                    $rule = $ruleName;
                }
                $validator->addRule($field, $rule, ...$params);
            }
        }

        foreach ($messages as $key => $msg) {
            if (strpos($key, '.') !== false) {
                [$field, $rule] = explode('.', $key, 2);
                $validator->setCustomMessage($field, $rule, $msg);
            }
        }

        if (!$validator->validate()) {
            $errors = $validator->getErrors();
            $this->session->set('form_errors', $errors);
            $this->withInput();

            if ($this->request->isAjax() || $this->request->expectsJson()) {
                $this->response->setStatusCode(422)
                               ->setJsonBody(['errors' => $errors])
                               ->send();
                return [];
            }

            $this->back();
            return [];
        }

        return $validator->getValidatedData();
    }
    
    /**
     * Handle a file upload.
     *
     * @param string $disk 'local' or 'ftp'
     * @param string $baseDir Directory inside disk root
     * @return FileUpload
     */
    protected function upload(string $disk = 'local', string $baseDir = 'uploads'): FileUpload
    {
        return new FileUpload($this->fileSystem, $disk, $baseDir);
    }

    /**
     * Abort the request with an HTTP exception.
     *
     * @param int    $code
     * @param string $message
     * @return void
     * @throws MachinjiriException
     */
    protected function abort(int $code, string $message = ''): void
    {
        $message = $message ?: "HTTP {$code}";
        throw new MachinjiriException($message, $code);
    }

    /**
     * Abort if the given condition is true.
     *
     * @param bool   $condition
     * @param int    $code
     * @param string $message
     * @return void
     * @throws MachinjiriException
     */
    protected function abortIf(bool $condition, int $code, string $message = ''): void
    {
        if ($condition) {
            $this->abort($code, $message);
        }
    }
    
}