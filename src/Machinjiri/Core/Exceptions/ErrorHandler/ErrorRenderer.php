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

        $codeSnippet = self::getCodeSnippet($errorFile, $errorLine);
        $requestData = self::getRequestData();
        $environmentData = self::getEnvironmentData();
        $sessionData = self::getSessionData();
        $routesData = self::getRoutesData();
        $logsData = self::getRecentLogs();

        $showTrace = self::$detailLevel >= 1;
        $showEnvironment = self::$detailLevel >= 2;

        $appName = ucfirst(getenv("APP_NAME") ?? "Machinjiri");
        $appVersion = getenv("APP_VERSION") ?? "1.0.0";
        $environment = getenv("APP_ENV") ?? "development";

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$appName} - Error Details</title>
    <style>
        :root {
            --bg: #f5f3ff;
            --panel: rgba(255, 255, 255, 0.82);
            --panel-strong: #ffffff;
            --border: rgba(109, 91, 208, 0.14);
            --primary: #6d5bd0;
            --primary-strong: #4d3eb6;
            --primary-soft: #efeaff;
            --accent: #1f2937;
            --text: #1f2430;
            --muted: #5f6676;
            --danger: #ef4444;
            --shadow: 0 18px 40px rgba(50, 36, 94, 0.12);
            --radius: 20px;
            --mono: "SFMono-Regular", "Consolas", "Liberation Mono", "Menlo", monospace;
            --sans: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #120f1d;
                --panel: rgba(22, 24, 37, 0.88);
                --panel-strong: #181b2a;
                --border: rgba(173, 160, 255, 0.16);
                --primary: #a78bfa;
                --primary-strong: #8b5cf6;
                --primary-soft: rgba(167, 139, 250, 0.12);
                --accent: #f4f1ff;
                --text: #edf2ff;
                --muted: #a4acc5;
                --danger: #f87171;
                --shadow: 0 18px 40px rgba(10, 8, 20, 0.55);
            }

            body {
                background:
                    radial-gradient(circle at top left, rgba(167, 139, 250, 0.18), transparent 30%),
                    linear-gradient(180deg, #120f1d 0%, #171824 100%);
            }

            .panel,
            .info-card,
            .trace-item,
            .preformatted {
                border-color: var(--border);
            }

            .location-bar,
            .trace-item,
            .preformatted,
            .error-card {
                background: rgba(18, 22, 35, 0.9);
            }

            .status-pill,
            .tab-button.active {
                background: rgba(167, 139, 250, 0.12);
            }
        }

        * { box-sizing: border-box; }

        html { scroll-behavior: smooth; }

        body {
            margin: 0;
            min-height: 100vh;
            font-family: var(--sans);
            background:
                radial-gradient(circle at top left, rgba(109, 91, 208, 0.12), transparent 30%),
                linear-gradient(180deg, #f7f7ff 0%, #f0f3ff 100%);
            color: var(--text);
        }

        .error-shell {
            max-width: 1320px;
            margin: 0 auto;
            padding: 32px 20px 48px;
        }

        .topbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 16px;
            margin-bottom: 26px;
        }

        .brand {
            display: flex;
            align-items: center;
            gap: 14px;
        }

        .brand-mark {
            width: 42px;
            height: 42px;
            border-radius: 14px;
            display: grid;
            place-items: center;
            color: #fff;
            font-weight: 700;
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            box-shadow: var(--shadow);
        }

        .eyebrow {
            font-size: 0.72rem;
            letter-spacing: 0.1em;
            text-transform: uppercase;
            color: var(--muted);
            margin-bottom: 4px;
        }

        .brand h1 {
            margin: 0;
            font-size: clamp(1.35rem, 2vw, 2rem);
            line-height: 1.1;
            color: var(--accent);
        }

        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            border-radius: 999px;
            padding: 8px 14px;
            font-size: 0.8rem;
            font-weight: 600;
            color: var(--primary-strong);
            background: var(--primary-soft);
            border: 1px solid rgba(109, 91, 208, 0.14);
        }

        .layout {
            display: grid;
            grid-template-columns: minmax(0, 2fr) minmax(280px, 0.9fr);
            gap: 24px;
            align-items: start;
        }

        .panel {
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
        }

        .panel-hero {
            padding: 26px 26px 20px;
        }

        .hero-meta {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            margin-bottom: 18px;
            color: var(--muted);
            font-size: 0.78rem;
        }

        .status-badge {
            display: inline-flex;
            align-items: center;
            padding: 7px 10px;
            border-radius: 999px;
            background: #fff4f4;
            color: var(--danger);
            border: 1px solid rgba(239, 68, 68, 0.16);
            font-weight: 700;
            letter-spacing: 0.04em;
            text-transform: uppercase;
        }

        .panel-hero h2 {
            margin: 0 0 12px;
            font-size: clamp(1.6rem, 3vw, 2.3rem);
            line-height: 1.2;
            color: var(--accent);
        }

        .subheading {
            margin: 0;
            color: var(--muted);
            font-size: 0.98rem;
        }

        .subheading code {
            font-family: var(--mono);
            font-size: 0.9em;
            background: rgba(109, 91, 208, 0.08);
            border-radius: 8px;
            padding: 2px 8px;
            color: var(--primary-strong);
        }

        .location-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-top: 24px;
            padding: 12px 14px;
            border-radius: 12px;
            background: rgba(109, 91, 208, 0.06);
            color: var(--text);
            border: 1px solid rgba(109, 91, 208, 0.1);
            font-size: 0.86rem;
        }

        .location-bar .label {
            font-weight: 700;
            color: var(--primary-strong);
            text-transform: uppercase;
            letter-spacing: 0.06em;
            font-size: 0.72rem;
        }

        .location-bar strong {
            color: var(--accent);
        }

        .code-snippet {
            margin-top: 20px;
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 14px;
            overflow: hidden;
            background: #0f172a;
            color: #dfe7ff;
        }

        .code-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 12px;
            padding: 11px 14px;
            background: rgba(255,255,255,0.04);
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 0.76rem;
            color: rgba(223, 231, 255, 0.85);
        }

        .copy-btn {
            appearance: none;
            border: 1px solid rgba(223, 231, 255, 0.18);
            background: rgba(255,255,255,0.04);
            color: #dfe7ff;
            border-radius: 999px;
            padding: 5px 10px;
            font-size: 0.74rem;
            cursor: pointer;
        }

        .code-wrapper {
            display: flex;
            overflow-x: auto;
        }

        .code-line-numbers {
            padding: 16px 0;
            background: rgba(255,255,255,0.02);
            border-right: 1px solid rgba(255,255,255,0.08);
            font-family: var(--mono);
            font-size: 0.78rem;
            line-height: 1.6;
            color: rgba(223, 231, 255, 0.6);
            min-width: 58px;
            text-align: right;
        }

        .line-number {
            padding: 0 12px;
        }

        .code-content {
            flex: 1;
            padding: 16px 16px 16px 10px;
            font-family: var(--mono);
            font-size: 0.78rem;
            line-height: 1.6;
            white-space: pre-wrap;
            overflow-x: auto;
        }

        .line-error {
            background: rgba(250, 204, 21, 0.12);
            box-shadow: inset 2px 0 0 #fbbf24;
        }

        .content-stack {
            margin-top: 24px;
        }

        .section-title {
            margin: 0 0 12px;
            font-size: 1.1rem;
            color: var(--accent);
        }

        .tabs {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            padding-bottom: 6px;
            margin-bottom: 14px;
            border-bottom: 1px solid var(--border);
        }

        .tab-button {
            border: 1px solid transparent;
            background: transparent;
            color: var(--muted);
            padding: 8px 12px;
            border-radius: 999px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s ease;
        }

        .tab-button.active {
            color: var(--primary-strong);
            background: var(--primary-soft);
            border-color: rgba(109, 91, 208, 0.12);
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }

        .trace-list {
            display: grid;
            gap: 10px;
        }

        .trace-item {
            background: rgba(109, 91, 208, 0.03);
            border: 1px solid rgba(109, 91, 208, 0.08);
            border-left: 4px solid var(--primary);
            border-radius: 12px;
            padding: 12px 14px;
        }

        .trace-location {
            font-family: var(--mono);
            font-size: 0.78rem;
            color: var(--accent);
            word-break: break-word;
        }

        .trace-file {
            margin-top: 8px;
            font-size: 0.72rem;
            color: var(--muted);
        }

        .preformatted {
            background: rgba(17, 24, 39, 0.04);
            border: 1px solid rgba(15, 23, 42, 0.08);
            border-radius: 12px;
            padding: 14px;
            font-family: var(--mono);
            font-size: 0.75rem;
            line-height: 1.55;
            overflow-x: auto;
            white-space: pre-wrap;
            max-height: 300px;
            overflow-y: auto;
            color: var(--text);
        }

        .sidebar {
            display: grid;
            gap: 18px;
        }

        .info-card {
            background: var(--panel-strong);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 20px 20px 14px;
        }

        .info-list {
            display: grid;
            gap: 14px;
        }

        .info-item {
            padding-bottom: 12px;
            border-bottom: 1px solid rgba(109, 91, 208, 0.1);
        }

        .info-item:last-child {
            padding-bottom: 0;
            border-bottom: none;
        }

        .info-label {
            margin-bottom: 6px;
            font-size: 0.68rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: var(--muted);
        }

        .info-value {
            font-size: 0.9rem;
            color: var(--text);
            word-break: break-word;
        }

        .expand-btn {
            margin-top: 8px;
            padding: 0;
            border: none;
            background: transparent;
            color: var(--primary-strong);
            font-weight: 600;
            cursor: pointer;
        }

        .expandable {
            max-height: 60px;
            overflow: hidden;
            transition: max-height 0.2s ease;
        }

        .expandable.expanded {
            max-height: none;
        }

        @media (max-width: 900px) {
            .layout {
                grid-template-columns: 1fr;
            }
        }

        @media (max-width: 640px) {
            .error-shell {
                padding: 24px 14px 36px;
            }

            .topbar {
                flex-direction: column;
                align-items: flex-start;
            }

            .panel-hero {
                padding: 20px 18px 16px;
            }
        }
    </style>
</head>
<body>
    <div class="error-shell">
        <main class="layout">
            <section class="panel panel-hero">
                <div class="hero-meta">
                    <span class="status-badge">Exception</span>
                    <span>{$context['timestamp']}</span>
                </div>

                <h3>{$errorMessage}</h3>
                <p class="subheading">Raised by <code>{$errorClass}</code> while handling this request.</p>

                <div class="location-bar">
                    <span class="label">Location</span>
                    <span>{$errorFile}</span>
                    <strong>line {$errorLine}</strong>
                </div>

                {$codeSnippet}

HTML;

        if ($showTrace && !empty($errorTrace)) {
            echo <<<HTML
                <div class="content-stack">
                    <h3 class="section-title">Stack Trace</h3>
                    <div class="tabs">
                        <button class="tab-button trace-tab active" type="button" onclick="switchTraceTab('trace-full', this)">Full Trace</button>
                        <button class="tab-button trace-tab" type="button" onclick="switchTraceTab('trace-simple', this)">Simple</button>
                    </div>

                    <div id="trace-full" class="tab-content active">
                        <div class="trace-list">
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
                                <div class="trace-location"><strong>#{$index}</strong> {$class}{$type}{$function}({$args})</div>
                                <div class="trace-file">{$file}:{$line}</div>
                            </div>
HTML;
            }

            echo <<<HTML
                        </div>
                    </div>

                    <div id="trace-simple" class="tab-content">
                        <div class="trace-list">
HTML;

            foreach ($errorTrace as $index => $trace) {
                $file = $trace['file'] ?? 'internal';
                $line = $trace['line'] ?? 0;

                if ($index < 10) {
                    echo <<<HTML
                            <div class="trace-item">
                                <div class="trace-location"><strong>#{$index}</strong> {$file}</div>
                                <div class="trace-file">line {$line}</div>
                            </div>
HTML;
                }
            }

            echo <<<HTML
                        </div>
                    </div>
                </div>
HTML;
        }

        if ($showEnvironment) {
            echo <<<HTML
                <div class="content-stack">
                    <h3 class="section-title">Debug Information</h3>
                    <div class="tabs">
                        <button class="tab-button debug-tab active" type="button" onclick="switchDebugTab('debug-request', this)">Request</button>
                        <button class="tab-button debug-tab" type="button" onclick="switchDebugTab('debug-session', this)">Session</button>
                        <button class="tab-button debug-tab" type="button" onclick="switchDebugTab('debug-environment', this)">Environment</button>
                        <button class="tab-button debug-tab" type="button" onclick="switchDebugTab('debug-routes', this)">Routes</button>
                        <button class="tab-button debug-tab" type="button" onclick="switchDebugTab('debug-logs', this)">Logs</button>
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
                    <div id="debug-logs" class="tab-content">
                        <div class="preformatted">{$logsData}</div>
                    </div>
                </div>
HTML;
        }

        echo <<<HTML
            </section>

            <aside class="sidebar">
                <div class="info-card">
                    <h3 class="section-title">Error Details</h3>
                    <div class="info-list">
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
                </div>

                <div class="info-card">
                    <h3 class="section-title">Request</h3>
                    <div class="info-list">
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
                            <div class="info-value expandable" id="user-agent">{$_SERVER['HTTP_USER_AGENT']}</div>
                            <button class="expand-btn" type="button" onclick="toggleExpand('user-agent', this)">Expand</button>
                        </div>
                    </div>
                </div>
            </aside>
        </main>
    </div>

    <script>
        function switchTraceTab(tabId, button) {
            document.querySelectorAll('.trace-tab').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('#trace-full, #trace-simple').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            button.classList.add('active');
        }

        function switchDebugTab(tabId, button) {
            document.querySelectorAll('.debug-tab').forEach(btn => btn.classList.remove('active'));
            document.querySelectorAll('#debug-request, #debug-session, #debug-environment, #debug-routes, #debug-logs').forEach(tab => tab.classList.remove('active'));
            document.getElementById(tabId).classList.add('active');
            button.classList.add('active');
        }

        function toggleExpand(elementId, button) {
            const element = document.getElementById(elementId);
            if (!element || !button) return;

            if (element.classList.contains('expanded')) {
                element.classList.remove('expanded');
                button.textContent = 'Expand';
            } else {
                element.classList.add('expanded');
                button.textContent = 'Collapse';
            }
        }

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
        $environment = getenv("APP_ENV") ?? "production";

        echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0" />
    <title>{$appName} - 500 Internal Server Error</title>
    <style>
        :root {
            --bg: #f4f3ff;
            --panel: rgba(255, 255, 255, 0.85);
            --panel-strong: #ffffff;
            --primary: #6d5bd0;
            --primary-strong: #4d3eb6;
            --text: #1f2937;
            --muted: #5f6676;
            --border: rgba(109, 91, 208, 0.14);
            --shadow: 0 22px 50px rgba(44, 34, 87, 0.14);
            --radius: 22px;
            --font: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
        }

        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #120f1d !important;
                --panel: rgba(22, 24, 37, 0.88);
                --panel-strong: #181b2a;
                --primary: #a78bfa;
                --primary-strong: #8b5cf6;
                --text: #edf2ff;
                --muted: #a4acc5;
                --border: rgba(173, 160, 255, 0.16);
                --shadow: 0 22px 50px rgba(10, 8, 20, 0.55);
            }

            body {
                background:
                    radial-gradient(circle at top left, rgba(167, 139, 250, 0.18), transparent 28%),
                    linear-gradient(180deg, #120f1d 0%, #171824 100%);
            }

            .error-card,
            .app-tag,
            .btn-secondary {
                border-color: var(--border);
            }
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            display: grid;
            place-items: center;
            background:
                radial-gradient(circle at top left, rgba(109, 91, 208, 0.18), transparent 28%),
                linear-gradient(180deg, #f6f5ff 0%, #eef1ff 100%);
            font-family: var(--font);
            color: var(--text);
            padding: 24px;
        }

        .error-card {
            width: min(100%, 560px);
            background: var(--panel);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            backdrop-filter: blur(10px);
            padding: 28px 28px 24px;
        }

        .error-top {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 14px;
        }

        .error-code {
            font-size: clamp(2.5rem, 7vw, 4.2rem);
            line-height: 1;
            font-weight: 800;
            letter-spacing: -0.06em;
            color: var(--primary);
        }

        .app-tag {
            background: rgba(109, 91, 208, 0.08);
            border: 1px solid rgba(109, 91, 208, 0.12);
            color: var(--primary-strong);
            border-radius: 999px;
            padding: 7px 12px;
            font-size: 0.78rem;
            font-weight: 700;
            letter-spacing: 0.05em;
            text-transform: uppercase;
        }

        h1 {
            margin: 0 0 12px;
            font-size: clamp(1.9rem, 4vw, 2.6rem);
            line-height: 1.1;
            color: var(--text);
        }

        p {
            margin: 0;
            color: var(--muted);
            line-height: 1.7;
            font-size: 1rem;
        }

        .actions {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            margin-top: 22px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 999px;
            padding: 11px 18px;
            font-weight: 700;
            text-decoration: none;
            transition: transform 0.2s ease, box-shadow 0.2s ease;
        }

        .btn:hover {
            transform: translateY(-1px);
        }

        .btn-primary {
            background: linear-gradient(135deg, var(--primary), var(--primary-strong));
            color: #fff;
            box-shadow: 0 12px 24px rgba(109, 91, 208, 0.2);
        }

        .btn-secondary {
            color: var(--primary-strong);
            background: rgba(109, 91, 208, 0.08);
            border: 1px solid rgba(109, 91, 208, 0.12);
        }

        .meta {
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            padding-top: 18px;
            margin-top: 22px;
            border-top: 1px solid var(--border);
            font-size: 0.78rem;
            color: var(--muted);
        }

        @media (max-width: 520px) {
            .error-card {
                padding: 22px 18px 18px;
            }

            .error-top {
                align-items: flex-start;
                flex-direction: column;
            }

            .actions {
                flex-direction: column;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="error-card" role="alert" aria-live="assertive">
        <div class="error-top">
            <span class="error-code">500</span>
            <span class="app-tag">{$appName}</span>
        </div>

        <h1>Something went wrong</h1>
        <p>We hit an unexpected error while processing your request. Please try again in a moment, or contact support if the issue persists.</p>

        <div class="actions">
            <a class="btn btn-primary" href="javascript:location.reload()">Reload page</a>
            <a class="btn btn-secondary" href="/">Go home</a>
        </div>

        <div class="meta">
            <span>Environment: {$environment}</span>
            <span>Issue logged</span>
        </div>
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