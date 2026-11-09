<?php
namespace Mlangeni\Machinjiri\Core\Forms;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Http\HttpRequest;
use Mlangeni\Machinjiri\Core\Http\HttpResponse;
use Mlangeni\Machinjiri\Core\Authentication\Session;
use Mlangeni\Machinjiri\Core\Security\Tokens\CSRFToken;
use Mlangeni\Machinjiri\Core\FileSystem\FileSystemManager;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

abstract class FormRequest
{
    protected HttpRequest $httpRequest;
    protected HttpResponse $httpResponse;
    protected Session $session;
    protected CSRFToken $csrfToken;
    protected FileSystemManager $fileSystem;
    protected FormValidatorExtended $validator;
    protected FormErrorBag $errorBag;
    protected array $oldInput = [];
    protected array $uploadedFiles = [];
    protected array $validatedData = [];
    protected bool $validated = false;

    // If true, uses double-submit cookie + session token
    protected bool $doubleSubmitCsrf = false;
    protected string $csrfTokenName = '_token';

    public function __construct(
        ?HttpRequest $httpRequest = null,
        ?HttpResponse $httpResponse = null,
        ?Session $session = null,
        ?CSRFToken $csrfToken = null,
        ?FileSystemManager $fileSystem = null
    ) {
        $app = Container::getInstance();
        $this->httpRequest = $httpRequest ?? $app->resolve(HttpRequest::class);
        $this->httpResponse = $httpResponse ?? $app->resolve(HttpResponse::class);
        $this->session = $session ?? $app->resolve(Session::class);
        $this->csrfToken = $csrfToken ?? $app->resolve(CSRFToken::class);
        $this->fileSystem = $fileSystem ?? $app->resolve(FileSystemManager::class);
        $this->errorBag = new FormErrorBag();
        $this->validator = new FormValidatorExtended();
    }

    /**
     * Define validation rules (e.g., ['name' => 'required|min:3'])
     */
    abstract public function rules(): array;

    /**
     * Custom error messages (field.rule => message)
     */
    public function messages(): array
    {
        return [];
    }

    /**
     * Field labels for error messages
     */
    public function labels(): array
    {
        return [];
    }

    /**
     * Authorize the request (override for permissions)
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Which filesystem disk to use for uploads ('local' or 'ftp')
     */
    public function uploadDisk(): string
    {
        return 'local';
    }

    /**
     * Directory where uploaded files are stored (relative to disk root).
     * Defaults to 'uploads'.
     */
    public function uploadDirectory(): string
    {
        return 'uploads';
    }

    /**
     * Handle file uploads after validation.
     * Override to customise naming, subdirectories, etc.
     */
    protected function handleUploads(array $validatedData): array
    {
        $disk = $this->fileSystem->disk($this->uploadDisk());
        $baseDir = rtrim($this->uploadDirectory(), '/') . '/';

        foreach ($this->rules() as $field => $ruleString) {
            $ruleNames = array_map(
                static fn(string $rule) => explode(':', $rule, 2)[0],
                explode('|', $ruleString)
            );

            $fileRules = ['file', 'image', 'mimes', 'maxSize', 'minSize', 'dimensions'];

            if (array_intersect($ruleNames, $fileRules) && isset($validatedData[$field])) {
                $file = $validatedData[$field];
                if ($file instanceof UploadedFile && $file->getError() === UPLOAD_ERR_OK) {
                    $clientName = $file->getClientOriginalName();
                    $safeName = preg_replace('/[^a-zA-Z0-9_.-]/', '_', $clientName);
                    $path = $baseDir . $safeName;

                    // Avoid overwriting
                    $counter = 1;
                    while ($disk->exists($path)) {
                        $info = pathinfo($safeName);
                        $path = $baseDir . $info['filename'] . '_' . $counter++ . '.' . ($info['extension'] ?? '');
                    }

                    $stream = fopen($file->getTmpName(), 'rb');
                    $disk->writeStream($path, $stream, ['visibility' => 'private']);
                    fclose($stream);

                    // Replace file object with stored path
                    $validatedData[$field] = $path;
                }
            }
        }
        return $validatedData;
    }

    /**
     * Prepare input data before validation (trim, etc.)
     */
    protected function prepareForValidation(array $data): array
    {
        return $data;
    }

    /**
     * Execute validation and return whether successful
     */
    public function validate(): bool
    {
        $this->validatedData = [];

        if (!$this->authorize()) {
            $this->errorBag->add('_form', 'Unauthorized action.');
            return false;
        }

        if (!$this->validateCsrf()) {
            $this->errorBag->add($this->csrfTokenName, 'Invalid CSRF token.');
            return false;
        }

        $allInput = $this->getAllInput();
        $prepared = $this->prepareForValidation($allInput);
        $this->oldInput = $prepared;

        $this->validator->setData($prepared);

        foreach ($this->labels() as $field => $label) {
            $this->validator->setFieldLabel($field, $label);
        }

        foreach ($this->messages() as $key => $msg) {
            if (strpos($key, '.') !== false) {
                [$field, $rule] = explode('.', $key, 2);
                $this->validator->setCustomMessage($field, $rule, $msg);
            } else {
                $this->validator->setCustomMessage($key, 'custom', $msg);
            }
        }

        foreach ($this->rules() as $field => $ruleString) {
            $rules = explode('|', $ruleString);
            foreach ($rules as $rule) {
                $params = [];
                if (strpos($rule, ':') !== false) {
                    [$ruleName, $paramStr] = explode(':', $rule, 2);
                    $params = explode(',', $paramStr);
                    $rule = $ruleName;
                }
                if (in_array($rule, ['file', 'image', 'mimes', 'maxSize', 'minSize', 'dimensions'])) {
                    $this->validator->addRule($field, $rule, ...$params);
                } else {
                    $this->validator->addRule($field, $rule, ...$params);
                }
            }
        }

        $valid = $this->validator->validate();
        $this->validated = $valid;

        if (!$valid) {
            $this->errorBag->merge($this->validator->getErrors());
            $this->saveErrorsToSession();
            $this->saveOldInputToSession();
        } else {
            $validatedData = $this->validator->getValidatedData();
            $this->validatedData = $this->handleUploads($validatedData);
            $this->session->set('form_old_input', []);
            $this->session->set('form_errors', []);
        }

        return $valid;
    }

    /**
     * Get validated data after successful validation
     */
    public function validated(): array
    {
        if (!$this->validated) {
            throw new MachinjiriException("Cannot get validated data before validation.");
        }
        return $this->validatedData;
    }

    /**
     * Redirect back with errors
     */
    public function redirectBack(): HttpResponse
    {
        $referer = $this->httpRequest->getReferer() ?: '/';
        return $this->httpResponse->redirect($referer);
    }

    /**
     * Send JSON error response (for AJAX requests)
     */
    public function respondWithErrors(int $statusCode = 422): HttpResponse
    {
        return $this->httpResponse
            ->setStatusCode($statusCode)
            ->setJsonBody([
                'success' => false,
                'errors' => $this->errorBag->all(),
                'message' => 'Validation failed'
            ]);
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function getAllInput(): array
    {
        $method = $this->httpRequest->getMethod();
        $data = [];

        if ($method === 'GET') {
            $data = $this->httpRequest->getQueryParams();
        } else {
            $contentType = $this->httpRequest->getContentType();
            if (strpos($contentType, 'application/json') !== false) {
                $data = $this->httpRequest->getJsonBody(true) ?: [];
            } elseif (in_array($method, ['PUT', 'PATCH', 'DELETE'])) {
                parse_str($this->httpRequest->getBody(), $data);
            } else {
                $data = $this->httpRequest->getPostData();
            }
        }

        // Merge with file inputs
        foreach ($this->httpRequest->getFiles() as $key => $fileArray) {
            $data[$key] = $this->normalizeFileInput($fileArray);
        }

        // Method spoofing
        if (isset($data['_method'])) {
            $this->httpRequest = new HttpRequest(
                strtoupper($data['_method']),
                $this->httpRequest->getUri(),
                $this->httpRequest->getQueryParams(),
                $this->httpRequest->getPostData(),
                $this->httpRequest->getCookies(),
                $this->httpRequest->getServer(),
                $this->httpRequest->getHeaders(),
                $this->httpRequest->getBody()
            );
            unset($data['_method']);
        }

        return $data;
    }

    private function normalizeFileInput(array $fileArray)
    {
        if (is_array($fileArray['tmp_name'])) {
            $files = [];
            foreach ($fileArray['tmp_name'] as $index => $tmpName) {
                if ($fileArray['error'][$index] === UPLOAD_ERR_OK) {
                    $files[] = new UploadedFile(
                        $tmpName,
                        $fileArray['name'][$index],
                        $fileArray['type'][$index],
                        $fileArray['error'][$index],
                        true
                    );
                }
            }
            return $files;
        }
        if ($fileArray['error'] === UPLOAD_ERR_OK) {
            return new UploadedFile(
                $fileArray['tmp_name'],
                $fileArray['name'],
                $fileArray['type'],
                $fileArray['error'],
                true
            );
        }
        return null;
    }

    private function validateCsrf(): bool
    {
        $token = $this->httpRequest->input($this->csrfTokenName);
        if ($this->doubleSubmitCsrf) {
            return $this->csrfToken->validateTokenWithCookie($token);
        }
        return $this->csrfToken->validateToken($token);
    }

    public function loadOldInput(): self
    {
        $old = $this->session->get('form_old_input', []);
        $this->oldInput = is_array($old) ? $old : [];
        return $this;
    }

    private function saveOldInputToSession(): void
    {
        $this->session->set('form_old_input', $this->oldInput);
    }

    private function saveErrorsToSession(): void
    {
        $this->errorBag->toSession($this->session, 'form_errors');
    }

    /**
     * Generate CSRF token (to be used in FormBuilder)
     */
    public static function generateCsrfToken(
        CSRFToken $csrfToken,
        bool $doubleSubmit = false
    ): string {
        return $doubleSubmit
            ? $csrfToken->generateTokenWithCookie()
            : $csrfToken->generateToken();
    }

    public function getErrorBag(): FormErrorBag
    {
        return $this->errorBag;
    }

    public function getErrors(): array
    {
        return $this->errorBag->all();
    }
}