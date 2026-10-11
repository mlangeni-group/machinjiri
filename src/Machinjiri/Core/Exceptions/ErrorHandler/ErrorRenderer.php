<?php

namespace Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Http\{HttpRequest, HttpResponse};
use Mlangeni\Machinjiri\Core\Routing\Router;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Console\Style\SymfonyStyle;

class ErrorRenderer
{
    private static bool $displayErrors = false;
    private static int $detailLevel = 1;
    private static $exceptionRenderer = null;
    private static array $genericRendered = [];
    private static ?Container $app = null;
    private static ?HttpRequest $httpRequest = null;
    private static ?HttpResponse $httpResponse = null;

    public static function setDisplayErrors(bool $display): void
    {
        self::$displayErrors = $display;
    }

    public static function setDetailLevel(int $level): void
    {
        self::$detailLevel = max(0, min(2, $level));
    }

    public static function setExceptionRenderer(?callable $renderer): void
    {
        self::$exceptionRenderer = $renderer;
    }

    public static function setApp(Container $app): void
    {
        self::$app = $app;
    }

    public static function setHttpRequest(HttpRequest $request): void
    {
        self::$httpRequest = $request;
    }

    public static function setHttpResponse(HttpResponse $response): void
    {
        self::$httpResponse = $response;
    }

    public static function shouldRenderException(\Throwable $exception): bool
    {
        if (!self::$displayErrors && $exception instanceof \ErrorException) {
            return in_array($exception->getSeverity(), [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR]);
        }
        return true;
    }

    public static function renderException(\Throwable $exception): void
    {
        // Clear output buffers
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        if ($exception instanceof MachinjiriException) {
            if (self::shouldRenderAsJson()) {
                $exception->renderJson(self::getEnvironment());
                return;
            }
            $exception->show();
            return;
        }

        if (self::$exceptionRenderer) {
            call_user_func(self::$exceptionRenderer, $exception);
            return;
        }

        if (self::isCli()) {
            self::renderCli($exception);
            return;
        }

        if (self::$displayErrors) {
            if (self::shouldRenderAsJson()) {
                self::renderJson($exception, true);
            } else {
                self::renderErrorPage($exception);
            }
        } else {
            if (self::shouldRenderAsJson()) {
                self::renderJson($exception, false);
            } else {
                self::renderGenericErrorPage();
            }
        }
    }

    public static function renderJson(\Throwable $exception, bool $dev = false): void
    {
        $body = $dev ? [
            'timestamp'          => date('Y-m-d H:i:s'),
            'exception_class'    => get_class($exception),
            'message'            => $exception->getMessage(),
            'file'               => $exception->getFile(),
            'line'               => $exception->getLine(),
            'code'               => $exception->getCode(),
            'trace'              => $exception->getTraceAsString(),
            'request_uri'        => $_SERVER['REQUEST_URI'] ?? 'CLI',
            'request_method'     => $_SERVER['REQUEST_METHOD'] ?? 'CLI',
            'user_agent'         => $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown',
            'ip_address'         => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
            'session_id'         => session_id() ?: 'none',
            'memory_usage'       => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'peak_memory'        => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'additional_context' => ErrorContext::getContext(),
            'PHP_VERSION'        => PHP_VERSION,
        ] : [
            'success' => false,
            'error'   => [
                'code'      => $exception->getCode(),
                'message'   => $exception->getMessage(),
                'timestamp' => time(),
            ]
        ];

        self::$httpResponse->setStatusCode(500)
            ->setJsonBody($body)
            ->send();
    }

    public static function renderCli(\Throwable $exception): void
    {
        $output = new ConsoleOutput();
        $io = new SymfonyStyle(new ArrayInput([]), $output);
        $io->title('Machinjiri - CLI Exception');
        $io->section('Error Details');
        $io->error("Message: " . $exception->getMessage());
        $io->writeln("File: {$exception->getFile()}:{$exception->getLine()}");
        $io->writeln("Code: {$exception->getCode()}");
        if ($output->isVerbose()) {
            $io->section('Stack Trace');
            $io->text($exception->getTraceAsString());
        }
    }

    private static function getCodeSnippet(string $file, int $errorLine): string
    {
        if (!file_exists($file) || !is_readable($file)) {
            return '';
        }

        $lines = file($file);
        $start = max(0, $errorLine - 7);
        $end = min(count($lines), $errorLine + 3);
        
        $snippet = '<div class="code-snippet">';
        $snippet .= '<div class="code-header">';
        $snippet .= '<span>Code around line ' . $errorLine . '</span>';
        $snippet .= '<button class="copy-btn" onclick="copyCodeSnippet()"><i class="far fa-copy"></i> Copy</button>';
        $snippet .= '</div>';
        $snippet .= '<div class="code-wrapper">';
        $snippet .= '<div class="code-line-numbers">';
        
        for ($i = $start; $i < $end; $i++) {
            $lineNumber = $i + 1;
            $lineClass = $lineNumber === $errorLine ? 'line-error' : '';
            $snippet .= '<div class="line-number ' . $lineClass . '">' . $lineNumber . '</div>';
        }
        
        $snippet .= '</div>';
        $snippet .= '<div class="code-content">';
        
        for ($i = $start; $i < $end; $i++) {
            $lineNumber = $i + 1;
            $lineContent = htmlspecialchars($lines[$i], ENT_QUOTES, 'UTF-8');
            $lineClass = $lineNumber === $errorLine ? 'line-error' : '';
            $snippet .= '<div class="' . $lineClass . '">' . $lineContent . '</div>';
        }
        
        $snippet .= '</div></div></div>';
        
        return $snippet;
    }

    private static function formatTraceArgs(array $args): string
    {
        $formatted = [];
        foreach ($args as $arg) {
            if (is_object($arg)) {
                $formatted[] = get_class($arg) . ' object';
            } elseif (is_array($arg)) {
                $formatted[] = 'Array(' . count($arg) . ')';
            } elseif (is_string($arg)) {
                $formatted[] = "'" . (strlen($arg) > 50 ? substr($arg, 0, 50) . '...' : $arg) . "'";
            } elseif (is_bool($arg)) {
                $formatted[] = $arg ? 'true' : 'false';
            } elseif ($arg === null) {
                $formatted[] = 'null';
            } else {
                $formatted[] = (string)$arg;
            }
        }
        return implode(', ', $formatted);
    }

    private static function getRequestData(): string
    {
        $data = [
            'GET Parameters' => $_GET,
            'POST Parameters' => $_POST,
            'Cookies' => $_COOKIE,
            'Headers' => self::getAllHeaders(),
            'Server' => array_diff_key($_SERVER, ['HTTP_COOKIE' => '', 'PATH' => '']),
            'Files' => !empty($_FILES) ? array_keys($_FILES) : []
        ];

        return self::formatDebugData($data);
    }

    private static function getSessionData(): string
    {
        $sessionData = [];
        
        if (session_status() === PHP_SESSION_ACTIVE) {
            $sessionData = $_SESSION;
        }
        
        $data = [
            'Session Status' => session_status() === PHP_SESSION_ACTIVE ? 'Active' : 'Inactive',
            'Session ID' => session_id() ?: 'None',
            'Session Data' => $sessionData,
            'Cookie Parameters' => session_get_cookie_params()
        ];

        return self::formatDebugData($data);
    }

    private static function getEnvironmentData(): string
    {
        $info = [
            'PHP Version' => PHP_VERSION,
            'Zend Engine' => zend_version(),
            'OS' => PHP_OS,
            'Server API' => PHP_SAPI,
            'Loaded Extensions' => get_loaded_extensions(),
            'PHP INI File' => php_ini_loaded_file(),
            'Include Path' => get_include_path(),
            'Memory Limit' => ini_get('memory_limit'),
            'Max Execution Time' => ini_get('max_execution_time'),
            'Upload Max Filesize' => ini_get('upload_max_filesize'),
            'Post Max Size' => ini_get('post_max_size'),
            'Timezone' => date_default_timezone_get(),
            'Locale' => setlocale(LC_ALL, 0),
            'App Environment' => getenv('APP_ENV') ?: 'Not set',
            'App Debug' => getenv('APP_DEBUG') ?: 'Not set',
            'App URL' => getenv('APP_URL') ?: 'Not set'
        ];

        return self::formatDebugData($info);
    }
    
    private static function getRoutesData(): string
    {
        $routeCollection = new \Mlangeni\Machinjiri\Core\Routing\RouteCollection();

        $data = [
            'Current Route' => self::request()->getUri(),
            'Request Method' => self::request()->getMethod(),
            'Registered Routes' => $routeCollection->all(),
        ];

        return self::formatDebugData($data);
    }

    private static function getRecentLogs(): string
    {
        $logFile = ErrorHandler::getLogFile();
        
        if (!file_exists($logFile)) {
            return 'No log file found';
        }

        $lines = file($logFile, FILE_IGNORE_NEW_LINES);
        $recent = array_slice($lines, -50, 50); // Last 50 lines
        
        return implode("\n", $recent);
    }

    private static function getAllHeaders(): array
    {
        $headers = [];
        foreach ($_SERVER as $name => $value) {
            if (substr($name, 0, 5) == 'HTTP_') {
                $headers[str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($name, 5)))))] = $value;
            }
        }
        return $headers;
    }

    private static function formatDebugData(array $data): string
    {
        $output = '';
        foreach ($data as $key => $value) {
            $output .= "=== {$key} ===\n";
            if (is_array($value)) {
                $output .= self::formatArray($value);
            } else {
                $output .= print_r($value, true) . "\n";
            }
            $output .= "\n";
        }
        return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    }    
    
    private static function formatArray(array $array, int $indent = 0): string
    {
        $output = '';
        $indentStr = str_repeat('  ', $indent);
        
        foreach ($array as $key => $value) {
            if (is_array($value)) {
                $output .= "{$indentStr}[{$key}] => Array\n";
                $output .= self::formatArray($value, $indent + 1);
            } else {
                $value = is_string($value) ? "'{$value}'" : $value;
                $output .= "{$indentStr}[{$key}] => {$value}\n";
            }
        }
        
        return $output;
    }
    
    private static function getEnvironmentInfo(): string
    {
        $info = [
            'PHP Version' => PHP_VERSION,
            'OS' => PHP_OS,
            'Server API' => PHP_SAPI,
            'Memory Usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'Memory Peak Usage' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'Include Path' => get_include_path(),
            'Loaded Extensions' => implode(', ', get_loaded_extensions()),
        ];

        $output = '';
        foreach ($info as $key => $value) {
            $output .= "$key: $value\n";
        }

        return htmlspecialchars($output, ENT_QUOTES, 'UTF-8');
    }

    public static function shouldRenderAsJson(): bool
    {
        if (self::$httpRequest && self::$httpRequest->isAjax()) {
            return true;
        }
        return self::expectsJson();
    }

    public static function expectsJson(): bool
    {
        $accept = self::$httpRequest ? self::$httpRequest->getHeader('Accept') : ($_SERVER['HTTP_ACCEPT'] ?? '');
        return str_contains($accept, 'application/json');
    }

    public static function isCli(): bool
    {
        return PHP_SAPI === 'cli' || (defined('STDIN') && defined('STDOUT'));
    }

    public static function getEnvironment(): string
    {
        return self::$app ? self::$app->getEnvironment() : 'production';
    }

    public static function renderErrorPage(\Throwable $exception): void
    {
        $request = self::request();
        $context = [
            'timestamp' => date('Y-m-d H:i:s'),
            'exception_class' => get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'trace' => $exception->getTraceAsString(),
            'request_uri' => $request->getUri(),
            'request_method' => $request->getMethod(),
            'user_agent' => $request->getServerParam('HTTP_USER_AGENT'),
            'ip_address' => $request->getServerParam('REMOTE_ADDR'),
            'session_id' => session_id() ?: 'none',
            'memory_usage' => memory_get_usage(true) / 1024 / 1024 . ' MB',
            'peak_memory' => memory_get_peak_usage(true) / 1024 / 1024 . ' MB',
            'PHP_VERSION' => PHP_VERSION
        ];
        
        $errorClass = get_class($exception);
        $errorMessage = htmlspecialchars($exception->getMessage(), ENT_QUOTES, 'UTF-8');
        $errorFile = htmlspecialchars($exception->getFile(), ENT_QUOTES, 'UTF-8');
        $errorLine = $exception->getLine();
        $errorCode = $exception->getCode();
        $errorTrace = $exception->getTrace();
        
        // Get code snippet
        $codeSnippet = self::getCodeSnippet($errorFile, $errorLine);
        
        // Get request data
        $requestData = self::getRequestData();
        
        // Get environment data
        $environmentData = self::getEnvironmentData();
        
        // Get session data
        $sessionData = self::getSessionData();
        
        // Get registered routes
        $routesData = self::getRoutesData();
        
        // Get recent logs
        $logsData = self::getRecentLogs();

        $showTrace = self::$detailLevel >= 1;
        $showEnvironment = self::$detailLevel >= 2;
        
        $appName = ucfirst(getenv("APP_NAME") ?? "Machinjiri");
        $appVersion = getenv("APP_VERSION") ?? "1.0.0";
        $environment = getenv("APP_ENV") ?? "development";

        $primaryColor = '';
        $primaryDark = '#8b93a5';
        $bgColor = '#dedede';
        $cardBg = '#f6f8fa';
        $textColor = '#161922';
        $subtleBorder = '#e6e8f1';
        $errorHighlight = '#FDE8E8';
        $errorBorder = '#F5C6C6';

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$appName} - Error/Exception</title>
    <style>
        :root {
            --primary: rgba(109, 91, 208, .10);
            --primary-dark: rgb(109, 91, 208);
            --danger: #D9735A;
            --warning: #E8A87C;
            --info: #7F9EB5;
            --bg: {$bgColor};
            --card-bg: {$cardBg};
            --text: {$textColor};
            --text-light: {$textColor};
            --border: {$subtleBorder};
            --shadow: 0 12px 32px -14px rgba(109, 91, 208, .10);
            --radius: 12px;
            --radius-sm: 6px;
            --transition: all 0.2s ease;
            --body-bg: radial-gradient(1200px 520px at 12% -15%, rgba(109, 91, 208, .20), transparent 60%),
                        radial-gradient(900px 420px at 88% -5%, rgba(56, 189, 248, .15), transparent 55%),
                        linear-gradient(180deg, #f7f8fc 0%, #ffffff 100%);
        }

        html {
            scroll-behavior: smooth;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0f1116 !important;
                --card-bg: #161922;
                --text: #c8cdd8;
                --text-light: #CBBBA8;
                --border: #262a35;
                --body-bg: radial-gradient(1200px 520px at 12% -15%, rgba(109, 91, 208, .24), transparent 60%),
                            radial-gradient(900px 420px at 88% -5%, rgba(56, 189, 248, .13), transparent 55%),
                            linear-gradient(180deg, #12141b 0%, #0f1116 100%) !important;
            }

            body.dark-mode .error-message { background: #3E332A; }
            body.dark-mode .error-location { background: #3E3530; }
            body.dark-mode .preformatted { background: #3A332C; }
            body.dark-mode .btn-secondary { background: #4A4038; color: #F0DCC0; border-color: #6B5A4A; }

            .app-info .environment {
                border-color: var(--border) !important;
            }

            .error-badge {
                background: var(--bg) !important;
                border-color: var(--border) !important;
                color: var(--primary-dark) !important;
            }

            .error-message {
                background: var(--bg) !important;
            }
            
            .error-message h3 {
                color: var(--primary-dark);
            }
            
            .error-location {
                background: var(--bg) !important;
                color: var(--text) !important;
            }

            .trace-item {
                background: var(--bg) !important;
            }
            
            .trace-location {
                color: var(--text) !important;
                font-family: monospace;
                font-size: 0.8rem;
                margin-bottom: 0.3rem;
            }
            
            .trace-file {
                color: var(--text-light);
            }
        }
        
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            background: var(--bg);
            font-family: 'Inter', system-ui, -apple-system, 'Segoe UI', 'Poppins', sans-serif;
            color: var(--text);
            line-height: 1.5;
            min-height: 100vh;
            padding: 2rem 1.3rem;
            position: relative;
        }
        
        .error-wrapper {
            max-width: 100%;
            margin: 0 auto;
            position: relative;
            z-index: 2;
        }
        
        .error-header {
            width: 100%;
            padding: 1.5rem 2rem;
            margin-bottom: 2rem;
            border-bottom: 1px solid #a99cf5;
            display: flex;
            justify-content: space-between;
            align-items: center;
            flex-wrap: wrap;
            gap: 1rem;
            position: fixed;
            z-index: inherit;
            background: var(--card-bg);
            left: 0;
            right: 0;
            top: 0;
            max-hieght: 80px;
            box-shadow: var(--shadow);
        }
        
        .app-info h1 {
            color: var(--primary-dark);
            margin-bottom: 0.25rem;
            font-size: 1.6rem;
            display: flex;
            align-items: center;
            gap: 0.5rem;
        }
        
        .app-info .environment {
            display: inline-block;
            padding: 0.2rem 0.8rem;
            border-radius: var(--radius-sm);
            font-size: 0.75rem;
            font-weight: 500;
            color: var(--primary-dark);
        }
        
        .error-badge {
            color: var(--primary-dark);
            padding: 0.5rem 1.2rem;
            font-weight: 600;
            font-size: 0.85rem;
            border: 1px solid var(--primary-dark);
            border-radius: var(--radius-sm);
        }
        
        .main-error {
            width: 100% !important;
            display: flex;
            justify-content: space-between;
            flex-direction: row;
            gap: 3px;
            position: absolute;
            top: calc(80px + 2rem);
        }

        .sidebar {
            display: flex;
            flex-direction: column;
            gap: 1.5rem;
            width: 25%;
        }
        
        .error-section {
            width: 74%;
            background: var(--card-bg);
            backdrop-filter: blur(2px);
            border-radius: var(--radius);
            padding: 1.6rem;
            margin-bottom: 1.8rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        
        .section-title {
            color: var(--primary-dark);
            font-size: 1.3rem;
            margin-bottom: 1.2rem;
            display: flex;
            align-items: center;
            gap: 0.7rem;
            font-weight: 600;
        }
        
        .error-message {
            background: var(--bg);
            border-left: 4px solid var(--primary);
            border-right: 4px solid var(--primary);
            padding: 1rem;
            border-radius: var(--radius-sm);
            margin-bottom: 1.5rem;
        }
        
        .error-message h3 {
            color: var(--primary-dark);
            margin-bottom: 0.5rem;
            font-size: 1.1rem;
        }
        
        .error-location {
            background: var(--bg);
            padding: 0.8rem 1rem;
            border-radius: var(--radius-sm);
            font-family: 'SF Mono', 'Fira Code', monospace;
            font-size: 0.85rem;
            margin-bottom: 1.5rem;
            color: #5E4B3A;
            word-break: break-word;
            white-space: normal;
            overflow-wrap: break-word;
        }
        
        .code-snippet {
            background: #15181f;
            border-radius: var(--radius-sm);
            border: 1px solid var(--primary);
            overflow: hidden;
            margin-bottom: 1.5rem;
            color: #CBBBA8;
        }
        
        .code-header {
            background: #0f1116;
            padding: 0.7rem 1rem;
            font-size: 0.8rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
            color: #CBBBA8 !important;
        }

        .code-wrapper {
            display: flex;
            flex-direction: row;
            justify-content: space-between;
        }
        
        .code-line-numbers {
            display: flex;
            padding: 0.8rem 0;
            font-family: monospace;
            font-size: 0.75rem;
        }
        
        .code-content {
            padding: 0.8rem;
            font-family: monospace;
            font-size: 0.75rem;
            overflow-x: auto;
        }
        
        .line-number {
            padding: 0 0.8rem;
            text-align: right;
            min-width: 45px;
            color: #CBBBA8;
        }
        
        .line-error {
            background: #262a35;
            color: #CBBBA8;
        }
        
        .trace-item {
            background: #FEF9F4;
            border: 1px solid var(--border);
            border-left: 4px solid var(--primary);
            border-radius: var(--radius-sm);
            padding: 1rem;
            margin-bottom: 0.8rem;
            overflow-x: auto;
        }
        
        .trace-location {
            color: var(--primary-dark);
            font-family: monospace;
            font-size: 0.8rem;
            margin-bottom: 0.3rem;
        }
        
        .trace-file {
            color: var(--text-light);
            font-size: 0.7rem;
        }
        
        .info-card {
            background: var(--card-bg);
            backdrop-filter: blur(2px);
            border-radius: var(--radius);
            padding: 1.4rem;
            border: 1px solid var(--border);
            box-shadow: var(--shadow);
        }
        
        .info-item {
            margin-bottom: 0.8rem;
            padding-bottom: 0.8rem;
            border-bottom: 1px solid var(--border);
        }
        
        .info-item:last-child {
            margin-bottom: 0;
            padding-bottom: 0;
            border-bottom: none;
        }
        
        .info-label {
            font-size: 0.7rem;
            color: var(--text-light);
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-bottom: 0.2rem;
        }
        
        .info-value {
            font-weight: 500;
            word-break: break-all;
            font-size: 0.85rem;
        }
        
        .tabs {
            display: flex;
            gap: 0.2rem;
            border-bottom: 1px solid var(--border);
            margin-bottom: 1rem;
        }
        
        .tab-button {
            padding: 0.5rem 1.2rem;
            background: none;
            border: none;
            border-bottom: 2px solid transparent;
            cursor: pointer;
            font-weight: 500;
            color: var(--text-light);
            transition: var(--transition);
            font-size: 0.85rem;
        }
        
        .tab-button:hover {
            color: var(--primary-dark);
        }
        
        .tab-button.active {
            color: var(--primary-dark);
            border-bottom-color: var(--primary);
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .preformatted {
            background: #FEF9F4;
            padding: 0.8rem;
            border-radius: var(--radius-sm);
            font-family: monospace;
            font-size: 0.7rem;
            overflow-x: auto;
            max-height: 300px;
            overflow-y: auto;
            border: 1px solid var(--border);
        }
        
        .copy-btn {
            background: var(--info);
            color: white;
            border: none;
            padding: 0.2rem 0.6rem;
            border-radius: 30px;
            cursor: pointer;
            font-size: 0.7rem;
        }
        
        .expand-btn {
            background: none;
            border: none;
            color: var(--primary-dark);
            cursor: pointer;
            font-size: 0.7rem;
            display: inline-flex;
            align-items: center;
            gap: 0.3rem;
            margin-top: 0.3rem;
        }
        
        .expandable {
            max-height: 70px;
            overflow: hidden;
            transition: max-height 0.2s ease;
        }
        
        .expandable.expanded {
            max-height: none;
        }
        
        @media (max-width: 768px) {
            .error-container {
                flex-direction: column;
            }
            .error-header {
                flex-direction: column;
                align-items: flex-start;
            }
            .main-error {
                width: 100% !important;
                display: flex;
                justify-content: space-between;
                flex-direction: column;
                gap: 3px;
                top: calc(120px + 2rem);
            }
    
            .sidebar {
                width: 100%;
            }
            
            .error-section {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="error-wrapper">
        <div class="error-header">
            <div class="app-info">
                <h1>{$appName} <span style="font-size:0.8rem;">v{$appVersion}</span></h1>
                <div>
                    <span class="environment"><b>App env: {$environment}</b></span>
                    <span class="environment">Error #: {$errorCode}</span>
                </div>
            </div>
            <div class="error-badge">
                {$errorClass}
            </div>
        </div>
        
        <div class="error-container">
            <div class="main-error">
                <div class="error-section">
                    <h2 class="section-title">Oops! Something went wrong</h2>
                    
                    <div class="error-message">
                        <h3>{$errorMessage}</h3>
                        <p>Exception thrown in <code>{$errorClass}</code></p>
                    </div>
                    
                    <div class="error-location">
                        {$errorFile} <strong>on line {$errorLine}</strong>
                    </div>
                    
                    {$codeSnippet}
                    
HTML;

        if ($showTrace && !empty($errorTrace)) {
            echo <<<HTML
                    <div>
                        <h2 class="section-title">Stack Trace</h2>
                        <div class="tabs">
                            <button class="tab-button active" onclick="switchTraceTab('trace-full')">Full Trace</button>
                            <button class="tab-button" onclick="switchTraceTab('trace-simple')">Simple</button>
                        </div>
                        
                        <div id="trace-full" class="tab-content active">
HTML;
            
            foreach ($errorTrace as $index => $trace) {
                $file = $trace['file'] ?? 'internal';
                $line = $trace['line'] ?? 0;
                $class = $trace['class'] ?? '';
                $type = $trace['type'] ?? '';
                $function = $trace['function'] ?? '';
                $args = isset($trace['args']) ? self::formatTraceArgs($trace['args']) : '';
                
                echo <<<HTML
                            <div class="trace-item">
                                <div class="trace-location">
                                    <strong>#{$index}</strong> {$class}{$type}{$function}({$args})
                                </div>
                                <div class="trace-file">
                                    {$file}:{$line}
                                </div>
                            </div>
HTML;
            }
            
            echo <<<HTML
                        </div>
                        
                        <div id="trace-simple" class="tab-content">
HTML;
            
            // Show simplified trace
            foreach ($errorTrace as $index => $trace) {
                $file = $trace['file'] ?? 'internal';
                $line = $trace['line'] ?? 0;
                
                if ($index < 10) { // Show only first 10
                    echo <<<HTML
                            <div class="trace-item">
                                <div class="trace-location">
                                    <strong>#{$index}</strong> {$file}
                                </div>
                            </div>
HTML;
                }
            }
            
            echo <<<HTML
                        </div>
                    </div>
HTML;
        }

        if ($showEnvironment) {
            echo <<<HTML
                    <div>
                        <h2 class="section-title">Debug Info</h2>
                        <div class="tabs">
                            <button class="tab-button active" onclick="switchDebugTab('debug-request')">Request</button>
                            <button class="tab-button" onclick="switchDebugTab('debug-session')">Session</button>
                            <button class="tab-button" onclick="switchDebugTab('debug-environment')">Environment</button>
                            <button class="tab-button" onclick="switchDebugTab('debug-routes')">Routes</button>
                        </div>
                        
                        <div id="debug-request" class="tab-content active">
                            <div class="preformatted">{$requestData}</div>
                        </div>
                        
                        <div id="debug-session" class="tab-content">
                            <div class="preformatted">{$sessionData}</div>
                        </div>
                        
                        <div id="debug-environment" class="tab-content">
                            <div class="preformatted">{$environmentData}</div>
                        </div>
                        
                        <div id="debug-routes" class="tab-content">
                            <div class="preformatted">{$routesData}</div>
                        </div>
                    </div>
HTML;
        }

        echo <<<HTML
                
            </div>
            
            <div class="sidebar">
                <div class="info-card">
                    <h3 class="section-title">Error Details</h3>
                    <div class="info-item">
                        <div class="info-label">Error Code</div>
                        <div class="info-value">#{$errorCode}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Exception Type</div>
                        <div class="info-value">{$errorClass}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Timestamp</div>
                        <div class="info-value">{$context['timestamp']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Memory Usage</div>
                        <div class="info-value">{$context['memory_usage']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Peak Memory</div>
                        <div class="info-value">{$context['peak_memory']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">PHP Version</div>
                        <div class="info-value">PHP {$context['PHP_VERSION']}</div>
                    </div>
                </div>
                
                <div class="info-card">
                    <h3 class="section-title">Request</h3>
                    <div class="info-item">
                        <div class="info-label">URL</div>
                        <div class="info-value">{$_SERVER['REQUEST_URI']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">Method</div>
                        <div class="info-value">{$_SERVER['REQUEST_METHOD']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">IP Address</div>
                        <div class="info-value">{$_SERVER['REMOTE_ADDR']}</div>
                    </div>
                    <div class="info-item">
                        <div class="info-label">User Agent</div>
                        <div class="info-value expandable" id="user-agent">
                            {$_SERVER['HTTP_USER_AGENT']}
                        </div>
                        <button class="expand-btn" onclick="toggleExpand('user-agent')">
                            <i class="fas fa-expand"></i> Expand
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script>
        function switchTraceTab(tabId) {
            document.querySelectorAll('#trace-full, #trace-simple').forEach(tab => {
                tab.classList.remove('active');
            });
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            document.getElementById(tabId).classList.add('active');
            event.target.classList.add('active');
        }
        
        function toggleExpand(elementId) {
            const element = document.getElementById(elementId);
            const button = event.target;
            if (element.classList.contains('expanded')) {
                element.classList.remove('expanded');
                button.innerHTML = '<i class="fas fa-expand"></i> Expand';
            } else {
                element.classList.add('expanded');
                button.innerHTML = '<i class="fas fa-compress"></i> Collapse';
            }
        }
        
        function copyErrorDetails() {
            const details = `Error: {$errorMessage}\\nFile: {$errorFile}\\nLine: {$errorLine}\\nCode: {$errorCode}\\nClass: {$errorClass}\\nTime: {$context['timestamp']}\\nURL: {$_SERVER['REQUEST_URI']}\\nIP: {$_SERVER['REMOTE_ADDR']}`;
            navigator.clipboard.writeText(details).then(() => {
                alert('Error details copied!');
            });
        }
        
        // Highlight error line in code
        document.querySelectorAll('.code-content div').forEach(line => {
            if (line.textContent.includes('{$errorLine}')) {
                line.classList.add('line-error');
            }
        });
    </script>
</body>
</html>
HTML;
    }

    public static function renderGenericErrorPage(): void
    {
        if (isset(self::$genericRendered['error'])) return;
        self::$genericRendered['error'] = true;
        
        $appName = getenv("APP_NAME") ?? "Machinjiri";
        $supportEmail = getenv("APP_SUPPORT_EMAIL") ?? "";
        $errorId = uniqid('ERR-', true);

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{$appName} - 500 Internal Server Error</title>
    <style>
        *,
        *::before,
        *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --bg: #dedede;
            --card-bg: #f6f8fa;
            --text-primary: #161922;
            --text-muted: #4a4a6a;
            --border-color: #e2e8f0;
            --accent: rgb(109, 91, 208);
            --accent-hover: rgb(109, 91, 208);
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
            <span class="error-code">500</span>
        </div>

        <h1 class="error-message">Internal Server error</h1>
        <p class="error-description">{$errorId}</p>
    </div>
</body>
</html>
HTML;
    }

    private static function request(): HttpRequest
    {
        return ErrorHandler::httpRequest();
    }
    
}