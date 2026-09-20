<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{Logger, LoggerFactory};
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Http\{HttpRequest, HttpResponse};
use Mlangeni\Machinjiri\Core\Transport\Mail\MailManager;

class ErrorHandler
{
    private static ?Container $app = null;
    private static ?HttpRequest $httpRequest = null;
    private static ?HttpResponse $httpResponse = null;
    private static bool $displayErrors = false;
    private static string $logFile;

    private static array $ignoredErrors = [];

    public static function register(
        Container $app,
        bool $displayErrors = false,
        ?string $logFile = null,
        int $detailLevel = 1,
        array $config = []
    ): void {
        self::$displayErrors = $displayErrors;
        self::$app = $app;
        self::$httpRequest = self::$app->bound(HttpRequest::class)
            ? self::$app->resolve(HttpRequest::class)
            : HttpRequest::createFromGlobals();

        self::$httpResponse = self::$app->bound(HttpResponse::class)
            ? self::$app->resolve(HttpResponse::class)
            : new HttpResponse();

        self::$logFile = $logFile ?: self::resolvePath() . '/app-error-log.log';

        // Configure sub‑components
        ErrorRenderer::setDisplayErrors($displayErrors);
        ErrorRenderer::setDetailLevel($detailLevel);
        ErrorRenderer::setApp(self::$app);
        ErrorRenderer::setHttpRequest(self::$httpRequest);
        ErrorRenderer::setHttpResponse(self::$httpResponse);

        ErrorThrottle::setConfig($config['throttle'] ?? []);
        ErrorReporter::setReportErrors(self::shouldReport($config));
        ErrorReporter::setEventListener(new EventListener(LoggerFactory::system("exceptions", "exception", true)));

        $logger = LoggerFactory::system("exceptions", "exception", false);
        ErrorLogger::setLogger($logger);
        ErrorThrottle::setLogger($logger);
        ErrorReporter::setLogger($logger);

        // Set mail manager if available
        if (self::$app->bound(MailManager::class)) {
            ErrorReporter::setMailManager(self::$app->resolve(MailManager::class));
        }

        // Set error reporting level
        error_reporting($displayErrors ? E_ALL : E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR);

        // Register handlers
        set_error_handler([self::class, 'handleError']);
        set_exception_handler([self::class, 'handleException']);
        register_shutdown_function([self::class, 'handleShutdown']);

        if (!ob_get_level()) {
            ob_start();
        }
    }

    private static function resolvePath(): string
    {
        $path = self::$app->getRootPath() . 'storage/logs/';
        return is_dir($path) ? $path : self::$app::getSystemTempDir();
    }

    public static function setExceptionRenderer(callable $renderer): void
    {
        ErrorRenderer::setExceptionRenderer($renderer);
    }

    public static function addContext(array $context): void
    {
        ErrorContext::addContext($context);
    }

    public static function clearContext(): void
    {
        ErrorContext::clearContext();
    }

    public static function handleError(int $errno, string $errstr, string $errfile, int $errline): bool
    {
        $ignored = self::getIgnoredErrors();
        if (in_array($errno, $ignored)) {
            return true;
        }

        $errorKey = md5($errstr . $errfile . $errline);
        if (!ErrorThrottle::shouldReportError($errorKey)) {
            return true;
        }
        
        $listener = ErrorReporter::getEventListener();
        if ($listener) {
            $listener->trigger('error.occurred', [
                'errno'   => $errno,
                'errstr'  => $errstr,
                'errfile' => $errfile,
                'errline' => $errline,
            ]);
        }

        if (!self::$displayErrors) {
            ErrorRenderer::renderGenericErrorPage();
        }

        throw new \ErrorException($errstr, 0, $errno, $errfile, $errline);
    }

    public static function handleException(\Throwable $exception): void
    {
        self::report($exception);

        if (ErrorRenderer::shouldRenderException($exception)) {
            ErrorRenderer::renderException($exception);
        } else {
            ErrorLogger::logError($exception, ErrorContext::getContext());
        }
        exit(1);
    }

    public static function handleShutdown(): void
    {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            $exception = new \ErrorException(
                $error['message'], 0, $error['type'], $error['file'], $error['line']
            );
            self::report($exception);
            if (ErrorRenderer::shouldRenderException($exception)) {
                ErrorRenderer::renderException($exception);
            }
            exit(1);
        }
    }

    public static function renderErrorPage(\Throwable $exception): void
    {
        ErrorRenderer::renderErrorPage($exception);
    }

    public static function renderGenericErrorPage(): void
    {
        ErrorRenderer::renderGenericErrorPage();
    }

    public static function getErrorStats(): array
    {
        return ErrorThrottle::getErrorStats();
    }

    public static function resetErrorCounters(): void
    {
        ErrorThrottle::resetErrorCounters();
    }

    public static function dumpException(\Throwable $exception): array
    {
        return [
            'class'    => get_class($exception),
            'message'  => $exception->getMessage(),
            'code'     => $exception->getCode(),
            'file'     => $exception->getFile(),
            'line'     => $exception->getLine(),
            'trace'    => $exception->getTrace(),
            'previous' => $exception->getPrevious() ? self::dumpException($exception->getPrevious()) : null,
            'timestamp' => time(),
            'memory'   => memory_get_usage(true),
            'peak_memory' => memory_get_peak_usage(true),
        ];
    }

    private static function getIgnoredErrors(): array
    {
        return self::$ignoredErrors;
    }

    public static function httpRequest(): HttpRequest
    {
        return self::$httpRequest;
    }

    public static function httpResponse(): HttpRequest
    {
        return self::$httpResponse;
    }

    public static function getLogFile(): ?string 
    {
        return self::$logFile;
    }

    private static function report(\Throwable $exception): void 
    {
        ErrorReporter::reportException($exception, ErrorContext::getContext());
    }

    private static function shouldReport(array $config = []): bool
    {
        $fromConfig = $config['report_errors'] ?? filter_var(env('REPORT_ERRORS'), FILTER_VALIDATE_BOOLEAN);
        if ($fromConfig && self::$app->getEnvironment() == "production") {
            return true;
        }
        return false;
    }

}