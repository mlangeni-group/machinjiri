<?php

declare(strict_types=1);

namespace Mlangeni\Machinjiri\Core\Debug;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ErrorHandler;
use ReflectionFunction;
use ReflectionObject;
use DateTimeInterface;
use Closure;


final class Dumper
{
    /** @var array{maxDepth:int,maxItems:int,sensitiveKeys:list<string>,redact:string} */
    private static array $config = [
        'maxDepth'      => 16,
        'maxItems'      => 1000,
        'sensitiveKeys' => [
            'password', 'passwd', 'pwd',
            'secret', 'token', 'api_key', 'apikey',
            'access_token', 'refresh_token', 'private_key',
            'authorization', 'auth',
        ],
        'redact'        => '***REDACTED***',
    ];

    /**
     * Optional exit handler. If null, `dd()` uses `exit()`.
     * Tests can set this to a closure that throws instead of terminating.
     *
     * @var null|callable(int):never
     */
    public static $exitHandler = null;

    /** @var array<int, true> Guard against circular object references. */
    private static array $seenObjects = [];

    /** @var bool Whether CSS has been emitted in this process. */
    private static bool $stylesEmitted = false;


    /**
     * Merge user config over defaults.
     *
     * @param array<string,mixed> $config
     */
    public static function configure(array $config): void
    {
        self::$config = array_merge(self::$config, $config);
    }

    /**
     * Dump one or more variables.
     */
    public static function dump(mixed ...$args): void
    {
        foreach ($args as $arg) {
            self::output($arg);
        }
    }

    /**
     * Dump and die.
     */
    public static function dd(mixed ...$args): never
    {
        if (Container::instancePresent()) {
            $app = Container::getInstance();
            if (method_exists($app, 'isDevelopment') && !$app->isDevelopment()) {
                ErrorHandler::renderGenericErrorPage();
                self::terminate(1);
            }
        }

        self::dump(...$args);
        self::terminate(1);
    }

    /**
     * Format a variable to a string without emitting it.
     * Useful for logging pipelines.
     */
    public static function format(mixed $var, bool $html = false): string
    {
        ob_start();
        self::$seenObjects = [];
        if ($html) {
            self::formatHtml($var, 0);
        } else {
            echo self::formatCli($var, 0);
        }
        return (string) ob_get_clean();
    }

    private static function output(mixed $var): void
    {
        if (PHP_SAPI === 'cli') {
            echo self::formatCli($var, 0) . PHP_EOL;
        } else {
            self::htmlDump($var);
        }
    }

    private static function terminate(int $code): never
    {
        if (self::$exitHandler !== null) {
            (self::$exitHandler)($code);
        }
        exit($code);
    }


    private static function formatCli(mixed $var, int $depth): string
    {
        $indent = str_repeat('  ', $depth);

        if ($var === null)     return $indent . 'null';
        if (is_bool($var))     return $indent . ($var ? 'true' : 'false');
        if (is_int($var))      return $indent . (string) $var;
        if (is_float($var))    return $indent . var_export($var, true);
        if (is_string($var))   return $indent . '"' . $var . '"';

        if (is_array($var)) {
            if ($depth >= self::$config['maxDepth']) {
                return $indent . 'array(...) [max depth]';
            }
            if (empty($var)) {
                return $indent . 'array(0) []';
            }
            $out = $indent . 'array(' . count($var) . ") [\n";
            $i = 0;
            foreach ($var as $k => $v) {
                if (++$i > self::$config['maxItems']) {
                    $out .= $indent . '  ... (truncated)' . "\n";
                    break;
                }
                $keyDisplay = is_string($k) ? '"' . $k . '"' : (string) $k;
                if (is_string($k) && self::isSensitive($k)) {
                    $keyDisplay = '"' . $k . '"';
                    $v = self::$config['redact'];
                }
                $out .= $indent . '  ' . $keyDisplay . ' => '
                      . ltrim(self::formatCli($v, $depth + 1)) . "\n";
            }
            return $out . $indent . ']';
        }

        if (is_object($var)) {
            return self::formatObjectCli($var, $depth, $indent);
        }

        if (is_resource($var)) {
            return $indent . 'resource(' . get_resource_type($var) . ')';
        }

        return $indent . gettype($var);
    }

    private static function formatObjectCli(object $var, int $depth, string $indent): string
    {
        if ($depth >= self::$config['maxDepth']) {
            return $indent . 'object(' . get_class($var) . ') [max depth]';
        }

        $oid = spl_object_id($var);
        if (isset(self::$seenObjects[$oid])) {
            return $indent . 'object(' . get_class($var) . ') [circular ref]';
        }
        self::$seenObjects[$oid] = true;

        try {
            if ($var instanceof DateTimeInterface) {
                return $indent . get_class($var)
                    . '("' . $var->format('Y-m-d H:i:s.u T') . '")';
            }
            if ($var instanceof Closure) {
                $r = new ReflectionFunction($var);
                return $indent . 'Closure(' . $r->getFileName() . ':' . $r->getStartLine() . ')';
            }
            if (enum_exists(get_class($var))) {
                return $indent . get_class($var) . '::' . $var->name;
            }

            $props = self::extractProperties($var);
            if (empty($props)) {
                return $indent . get_class($var) . ' {}';
            }

            $out = $indent . get_class($var) . " {\n";
            $i = 0;
            foreach ($props as $entry) {
                if (++$i > self::$config['maxItems']) {
                    $out .= $indent . '  ... (truncated)' . "\n";
                    break;
                }
                [$name, $visibility, $value] = $entry;
                $label = ($visibility !== 'public' ? $visibility . ' ' : '') . $name;
                if (self::isSensitive($name)) {
                    $value = self::$config['redact'];
                }
                $out .= $indent . '  ' . $label . ' => '
                      . ltrim(self::formatCli($value, $depth + 1)) . "\n";
            }
            return $out . $indent . '}';
        } finally {
            unset(self::$seenObjects[$oid]);
        }
    }


    private static function htmlDump(mixed $var): void
    {
        if (!self::$stylesEmitted) {
            echo self::getStyles();
            self::$stylesEmitted = true;
        }

        self::$seenObjects = [];
        echo '<section class="machinjiri-dump cozy-dump" aria-label="Variable dump">';
        echo '<div class="dump-header"><span class="dump-mark" aria-hidden="true">&gt;</span>';
        echo '<span class="dump-title">Machinjiri Dumper</span><span class="dump-badge">PHP</span></div>';
        echo '<pre class="dump-content">';
        self::formatHtml($var, 0);
        echo '</pre></section>' . "\n";
    }

    private static function formatHtml(mixed $var, int $depth = 0): void
    {
        $indent = str_repeat('  ', $depth);

        if ($var === null) {
            echo '<span class="dump-null">null</span>';
        } elseif (is_bool($var)) {
            echo '<span class="dump-bool">' . ($var ? 'true' : 'false') . '</span>';
        } elseif (is_int($var)) {
            echo '<span class="dump-int">' . $var . '</span>';
        } elseif (is_float($var)) {
            echo '<span class="dump-float">' . self::e(var_export($var, true)) . '</span>';
        } elseif (is_string($var)) {
            echo '<span class="dump-string">"' . self::e($var) . '"</span>';
        } elseif (is_array($var)) {
            self::formatArrayHtml($var, $depth, $indent);
        } elseif (is_object($var)) {
            self::formatObjectHtml($var, $depth, $indent);
        } elseif (is_resource($var)) {
            echo '<span class="dump-resource">resource(' . get_resource_type($var) . ')</span>';
        } else {
            echo '<span class="dump-unknown">' . self::e(gettype($var)) . '</span>';
        }
    }

    private static function formatArrayHtml(array $var, int $depth, string $indent): void
    {
        echo '<span class="dump-array">array</span> (' . count($var) . ') [';
        if (empty($var)) {
            echo ']';
            return;
        }
        if ($depth >= self::$config['maxDepth']) {
            echo ' ... max depth ... ]';
            return;
        }
        echo "\n";
        $i = 0;
        foreach ($var as $key => $value) {
            if (++$i > self::$config['maxItems']) {
                echo $indent . '  ... (truncated)' . "\n";
                break;
            }
            echo $indent . '  ';
            echo '<span class="dump-key">'
                . (is_string($key) ? '"' . self::e($key) . '"' : (string) $key)
                . '</span> => ';
            if (is_string($key) && self::isSensitive($key)) {
                echo '<span class="dump-string">"' . self::e((string) self::$config['redact']) . '"</span>';
            } else {
                self::formatHtml($value, $depth + 1);
            }
            echo "\n";
        }
        echo $indent . ']';
    }

    private static function formatObjectHtml(object $var, int $depth, string $indent): void
    {
        $class = get_class($var);
        $oid = spl_object_id($var);

        if ($depth >= self::$config['maxDepth']) {
            echo '<span class="dump-object">' . self::e($class) . '</span> { ... max depth ... }';
            return;
        }
        if (isset(self::$seenObjects[$oid])) {
            echo '<span class="dump-object">' . self::e($class) . '</span> { <em>circular ref</em> }';
            return;
        }
        self::$seenObjects[$oid] = true;

        try {
            if ($var instanceof DateTimeInterface) {
                echo '<span class="dump-object">' . self::e($class) . '</span> ("'
                    . self::e($var->format('Y-m-d H:i:s.u T')) . '")';
                return;
            }
            if ($var instanceof Closure) {
                $r = new ReflectionFunction($var);
                echo '<span class="dump-object">Closure</span> ('
                    . self::e($r->getFileName()) . ':' . (int) $r->getStartLine() . ')';
                return;
            }
            if (enum_exists($class)) {
                echo '<span class="dump-object">' . self::e($class) . '::' . self::e($var->name) . '</span>';
                return;
            }

            echo '<span class="dump-object">' . self::e($class) . '</span> {';

            $props = self::extractProperties($var);
            if (empty($props)) {
                echo '}';
                return;
            }

            echo "\n";
            $i = 0;
            foreach ($props as $entry) {
                if (++$i > self::$config['maxItems']) {
                    echo $indent . '  ... (truncated)' . "\n";
                    break;
                }
                [$name, $visibility, $value] = $entry;
                $label = ($visibility !== 'public' ? $visibility . ' ' : '') . $name;

                echo $indent . '  <span class="dump-property">' . self::e($label) . '</span> => ';
                if (self::isSensitive($name)) {
                    echo '<span class="dump-string">"' . self::e((string) self::$config['redact']) . '"</span>';
                } else {
                    self::formatHtml($value, $depth + 1);
                }
                echo "\n";
            }
            echo $indent . '}';
        } finally {
            unset(self::$seenObjects[$oid]);
        }
    }


    /**
     * Extract object properties with visibility, including private/protected
     * from ancestors, using reflection.
     *
     * @return list<array{string,string,mixed}> [name, visibility, value]
     */
    private static function extractProperties(object $obj): array
    {
        $result = [];
        $reflection = new ReflectionObject($obj);

        do {
            foreach ($reflection->getProperties() as $prop) {
                $name = $prop->getName();
                if (array_key_exists($name, $result)) {
                    continue; // child class already exposes this name
                }
                if (!$prop->isInitialized($obj)) {
                    $result[$name] = [$name, self::visibilityOf($prop), '<uninitialized>'];
                    continue;
                }
                
                $result[$name] = [
                    $name,
                    self::visibilityOf($prop),
                    $prop->getValue($obj),
                ];
            }
            $reflection = $reflection->getParentClass();
        } while ($reflection !== false);

        // Include dynamic public properties too.
        foreach (get_object_vars($obj) as $name => $value) {
            if (!isset($result[$name])) {
                $result[$name] = [$name, 'public', $value];
            }
        }

        return array_values($result);
    }

    private static function visibilityOf(\ReflectionProperty $prop): string
    {
        return $prop->isPrivate() ? 'private' : ($prop->isProtected() ? 'protected' : 'public');
    }

    private static function isSensitive(string $key): bool
    {
        $lower = strtolower($key);
        foreach (self::$config['sensitiveKeys'] as $needle) {
            if (str_contains($lower, $needle)) {
                return true;
            }
        }
        return false;
    }

    private static function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function getStyles(): string
    {
        return <<<CSS
<style>
.machinjiri-dump.cozy-dump {
    --dump-panel: rgba(255, 255, 255, 0.88);
    --dump-header: rgba(109, 91, 208, 0.04);
    --dump-border: rgba(109, 91, 208, 0.16);
    --dump-text: #1f2430;
    --dump-muted: #5f6676;
    --dump-primary: #6d5bd0;
    --dump-primary-strong: #4d3eb6;
    --dump-primary-soft: #efeaff;
    --dump-string: #26734d;
    --dump-number: #3866a8;
    --dump-key: #6554b8;
    --dump-property: #a45b20;
    --dump-danger: #c83d4b;
    --dump-shadow: 0 14px 36px rgba(44, 34, 87, 0.12);
    margin: 1rem 0;
    overflow: hidden;
    color: var(--dump-text);
    background: var(--dump-panel);
    border: 1px solid var(--dump-border);
    border-radius: 16px;
    box-shadow: var(--dump-shadow);
    backdrop-filter: blur(10px);
    font-family: "SFMono-Regular", Consolas, "Liberation Mono", Menlo, monospace;
    font-size: 13px;
    line-height: 1.65;
}
.machinjiri-dump.cozy-dump .dump-header {
    display: flex;
    align-items: center;
    gap: 9px;
    min-height: 46px;
    padding: 9px 16px;
    background: var(--dump-header);
    border-bottom: 1px solid var(--dump-border);
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
}
.machinjiri-dump.cozy-dump .dump-mark {
    display: grid;
    width: 23px;
    height: 23px;
    place-items: center;
    color: #fff;
    background: linear-gradient(135deg, var(--dump-primary), var(--dump-primary-strong));
    border-radius: 7px;
    font-size: 15px;
    font-weight: 700;
}
.machinjiri-dump.cozy-dump .dump-title {
    color: var(--dump-text);
    font-size: 0.84rem;
    font-weight: 650;
    letter-spacing: 0.01em;
}
.machinjiri-dump.cozy-dump .dump-badge {
    margin-left: auto;
    padding: 4px 9px;
    color: var(--dump-primary-strong);
    background: var(--dump-primary-soft);
    border: 1px solid var(--dump-border);
    border-radius: 999px;
    font-size: 0.68rem;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
}
.machinjiri-dump.cozy-dump .dump-content {
    max-height: 70vh;
    margin: 0;
    padding: 16px 18px;
    overflow-x: auto;
    color: inherit;
    white-space: pre-wrap;
    overflow-wrap: anywhere;
    font: inherit;
}
.machinjiri-dump .dump-null     { color: var(--dump-muted); font-weight: 700; }
.machinjiri-dump .dump-bool     { color: var(--dump-danger); font-weight: 700; }
.machinjiri-dump .dump-int,
.machinjiri-dump .dump-float    { color: var(--dump-number); }
.machinjiri-dump .dump-string   { color: var(--dump-string); }
.machinjiri-dump .dump-array    { color: var(--dump-primary); font-weight: 700; }
.machinjiri-dump .dump-object   { color: var(--dump-primary-strong); font-weight: 700; }
.machinjiri-dump .dump-resource { color: #8b5a9f; }
.machinjiri-dump .dump-key      { color: var(--dump-key); }
.machinjiri-dump .dump-property { color: var(--dump-property); }
.machinjiri-dump .dump-unknown  { color: var(--dump-muted); }
.machinjiri-dump em             { color: var(--dump-danger); font-style: italic; }

@media (prefers-color-scheme: dark) {
    .machinjiri-dump.cozy-dump {
        --dump-panel: rgba(22, 24, 37, 0.92);
        --dump-header: rgba(18, 22, 35, 0.9);
        --dump-border: rgba(173, 160, 255, 0.16);
        --dump-text: #edf2ff;
        --dump-muted: #a4acc5;
        --dump-primary: #a78bfa;
        --dump-primary-strong: #c4b5fd;
        --dump-primary-soft: rgba(167, 139, 250, 0.12);
        --dump-string: #86d6a5;
        --dump-number: #93c5fd;
        --dump-key: #c4b5fd;
        --dump-property: #f0b37e;
        --dump-danger: #f87171;
        --dump-shadow: 0 18px 40px rgba(10, 8, 20, 0.5);
    }
}

@media (max-width: 560px) {
    .machinjiri-dump.cozy-dump .dump-content {
        padding: 13px;
        font-size: 12px;
    }
}
</style>
CSS;
    }
}