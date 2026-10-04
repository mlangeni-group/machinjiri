<?php

namespace Mlangeni\Machinjiri\Core\Views;

use Mlangeni\Machinjiri\Core\Exceptions\ViewEngineException;
use Mlangeni\Machinjiri\Core\Views\Config\ViewConfig;
use Mlangeni\Machinjiri\Core\Views\Services\AssetManager;
use Mlangeni\Machinjiri\Core\Views\Services\ViewCompiler;
use Mlangeni\Machinjiri\Core\Views\Services\ViewRenderer;

class View
{
    /** Maximum nesting depth for render() to protect against recursion. */
    protected const MAX_RENDER_DEPTH = 50;

    /** Active view stack (for nesting). */
    protected static array $instanceStack = [];

    /** Service instances (lazy-loaded). */
    protected static ?AssetManager $assetManager = null;
    protected static ?ViewRenderer $renderer = null;

    // ---------------------------------------------------------------------
    // Instance state
    // ---------------------------------------------------------------------

    protected string $view;
    protected array $data;
    protected ?string $layout = null;
    protected array $sections = [];
    protected array $parentSections = [];
    protected array $sectionStack = [];
    protected int $renderDepth = 0;

    // =====================================================================
    // Static API
    // =====================================================================

    public static function share(array|string $key, mixed $value = null): void
    {
        ViewConfig::share($key, $value);
    }

    public static function composer(string $view, callable $callback): void
    {
        ViewConfig::composer($view, $callback);
    }

    public static function addNamespace(string $namespace, string $path): void
    {
        ViewConfig::addNamespace($namespace, $path);
    }

    public static function setAssetsPath(string $path): void
    {
        self::getAssetManager()->setAssetsPath($path);
    }

    public static function setAssetsUrl(string $url): void
    {
        self::getAssetManager()->setAssetsUrl($url);
    }

    public static function asset(string $path): string
    {
        return self::getAssetManager()->asset($path);
    }

    public static function style(string $path, array $attributes = []): void
    {
        self::getAssetManager()->style($path, $attributes);
    }

    public static function script(string $path, array $attributes = []): void
    {
        self::getAssetManager()->script($path, $attributes);
    }

    public static function make(string $view, array $data = []): self
    {
        return new self($view, $data);
    }

    // ---- Section / layout directives -----------------------------------

    public static function section(string $name, ?string $content = null): void
    {
        self::getCurrentInstance()->sectionInternal($name, $content);
    }

    public static function endSection(): void
    {
        self::getCurrentInstance()->endSectionInternal();
    }

    public static function yield(string $name): void
    {
        self::getCurrentInstance()->yieldInternal($name);
    }

    public static function extend(string $layout): void
    {
        self::getCurrentInstance()->extendInternal($layout);
    }

    public static function parent(): void
    {
        self::getCurrentInstance()->parentInternal();
    }

    public static function include(string $view, array $data = []): void
    {
        self::getCurrentInstance()->includeInternal($view, $data);
    }

    public static function hasSection(string $name): bool
    {
        return self::getCurrentInstance()->hasSectionInternal($name);
    }

    public static function getSection(string $name): string
    {
        return self::getCurrentInstance()->getSectionInternal($name);
    }

    // ---- Stacks ---------------------------------------------------------

    public static function push(string $name): void
    {
        self::getCurrentInstance()->pushInternal($name);
    }

    public static function endPush(): void
    {
        self::getCurrentInstance()->endPushInternal();
    }

    public static function prepend(string $name): void
    {
        self::getCurrentInstance()->prependInternal($name);
    }

    public static function endPrepend(): void
    {
        self::getCurrentInstance()->endPrependInternal();
    }

    public static function stack(string $name): void
    {
        self::getCurrentInstance()->stackInternal($name);
    }

    // ---- Cache management ----------------------------------------------

    public static function clearCache(): int
    {
        return self::getRenderer()->clearCache();
    }

    public static function warmCache(?string $subPath = null): int
    {
        return self::getRenderer()->warmCache($subPath);
    }

    // =====================================================================
    // Instance
    // =====================================================================

    public function __construct(string $view, array $data = [])
    {
        $this->view = $view;
        $this->data = array_merge(ViewConfig::$shared, $data);
    }

    public function render(): string
    {
        if ($this->renderDepth >= self::MAX_RENDER_DEPTH) {
            throw new ViewEngineException("Max render depth exceeded for view: {$this->view}");
        }
        $this->renderDepth++;

        self::$instanceStack[] = $this;
        try {
            // Run view composer if registered.
            if (isset(ViewConfig::$composers[$this->view])) {
                (ViewConfig::$composers[$this->view])($this);
            }

            // Capture main view content.
            $content = $this->getRenderer()->compileAndInclude(
                $this->view,
                'view',
                $this->data
            );

            // Apply layout if one was declared via @extend.
            if ($this->layout !== null) {
                if (!isset($this->sections['content'])) {
                    $this->sections['content'] = $content;
                }

                // Merge fresh shared data (in case share() ran mid-render).
                $layoutData = array_merge(ViewConfig::$shared, $this->data);
                $content = $this->getRenderer()->compileAndInclude(
                    $this->layout,
                    'layout',
                    $layoutData
                );
            }

            return $content;
        } finally {
            // Close any dangling section buffers before unwinding.
            while (!empty($this->sectionStack)) {
                $this->endSectionInternal();
            }
            // Close any dangling stack buffers.
            while (!empty($this->openStacks)) {
                $op = array_pop($this->openStacks);
                ob_end_clean(); // discard partial stack content on error
            }
            array_pop(self::$instanceStack);
            $this->renderDepth--;
        }
    }

    public function with(string $key, mixed $value): self
    {
        $this->data[$key] = $value;
        return $this;
    }

    public function display(): void
    {
        try {
            print $this->render();
        } catch (ViewEngineException $e) {
            $e->show();
        } catch (\Throwable $e) {
            // Never leak raw errors in production; log and show generic.
            error_log((string)$e);
            http_response_code(500);
            print '<!-- Internal view error -->';
        }
    }

    public function __toString(): string
    {
        try {
            return $this->render();
        } catch (\Throwable $e) {
            error_log('[View::__toString] ' . $e->getMessage());
            return '<!-- View render error: '
                 . htmlspecialchars($e->getMessage(), ENT_QUOTES)
                 . ' -->';
        }
    }

    // =====================================================================
    // Internal instance methods (used by static wrappers)
    // =====================================================================

    protected function sectionInternal(string $name, ?string $content = null): void
    {
        if ($content !== null) {
            $this->sections[$name] = $content;
            return;
        }

        // Preserve parent content when re-opening a section (@parent).
        if (isset($this->sections[$name])) {
            $this->parentSections[$name] = $this->sections[$name];
        }

        $this->sectionStack[] = $name;
        ob_start();
    }

    protected function endSectionInternal(): void
    {
        if (empty($this->sectionStack)) {
            // Nothing to close; be forgiving if called from cleanup.
            return;
        }
        $name = array_pop($this->sectionStack);
        $this->sections[$name] = ob_get_clean();
    }

    protected function yieldInternal(string $name): void
    {
        print $this->sections[$name] ?? '';
    }

    protected function extendInternal(string $layout): void
    {
        $layout = trim($layout, '\'"');
        if ($layout === '' || strpos($layout, '..') !== false) {
            throw new ViewEngineException("Invalid layout name: {$layout}");
        }
        $this->layout = $layout;
    }

    protected function parentInternal(): void
    {
        $name = end($this->sectionStack) ?: null;
        if ($name === null) {
            throw new ViewEngineException('@parent must be used inside a section');
        }
        echo $this->parentSections[$name] ?? '';
    }

    protected function includeInternal(string $view, array $data = []): void
    {
        $view = trim($view, '\'"');

        if (is_string($data) && str_starts_with($data, '[')) {
            $data = json_decode($data, true) ?? [];
        }

        // Fresh shared data + parent data + explicit overrides.
        $merged = array_merge(ViewConfig::$shared, $this->data, $data);

        // Fragments must not open sections that the parent doesn't close.
        $stackBefore = $this->sectionStack;

        try {
            echo $this->getRenderer()->compileAndInclude($view, 'fragment', $merged);
        } finally {
            // If a fragment left sections open, close them and warn.
            if (count($this->sectionStack) > count($stackBefore)) {
                $dangling = array_slice($this->sectionStack, count($stackBefore));
                error_log('[View] Fragment opened sections without closing them: '
                        . implode(', ', $dangling));
                while (count($this->sectionStack) > count($stackBefore)) {
                    $this->endSectionInternal();
                }
            }
        }
    }

    protected function hasSectionInternal(string $name): bool
    {
        return isset($this->sections[$name]) && $this->sections[$name] !== '';
    }

    protected function getSectionInternal(string $name): string
    {
        return $this->sections[$name] ?? '';
    }

    // ---- Stacks ---------------------------------------------------------

    protected function pushInternal(string $name): void
    {
        ViewConfig::ensureStack($name);
        ob_start();
    }

    protected function endPushInternal(): void
    {
        $content = ob_get_clean();
        // We don't know the stack name here; track it via a local stack.
        // To keep things simple, we store the last opened stack.
        if (empty($this->openStacks)) {
            $this->openStacks = [];
        }
        // Placeholder — see openStacks handling below.
    }

    /**
     * Pending stack operations. Because @push/@endpush delimit a buffer
     * without passing the name to endPush, we track the name on a stack.
     */
    protected array $openStacks = [];

    protected function prependInternal(string $name): void
    {
        ViewConfig::ensureStack($name);
        $this->openStacks[] = ['name' => $name, 'mode' => 'prepend'];
        ob_start();
    }

    protected function endPrependInternal(): void
    {
        if (empty($this->openStacks)) {
            return;
        }
        $op = array_pop($this->openStacks);
        $content = ob_get_clean();
        if ($op['mode'] === 'prepend') {
            array_unshift(ViewConfig::$stacks[$op['name']]['prepend'], $content);
        } else {
            ViewConfig::$stacks[$op['name']]['push'][] = $content;
        }
    }

    protected function stackInternal(string $name): void
    {
        ViewConfig::ensureStack($name);
        $stack = ViewConfig::$stacks[$name];
        echo implode('', $stack['prepend']);
        echo implode('', $stack['push']);
    }

    // =====================================================================
    // Service providers (lazy-loaded)
    // =====================================================================    
    protected static function getAssetManager(): AssetManager
    {
        if (self::$assetManager === null) {
            self::$assetManager = new AssetManager();
        }
        return self::$assetManager;
    }

    protected static function getRenderer(): ViewRenderer
    {
        if (self::$renderer === null) {
            self::$renderer = new ViewRenderer(new ViewCompiler());
        }
        return self::$renderer;
    }

    protected static function getCurrentInstance(): self
    {
        if (empty(self::$instanceStack)) {
            throw new ViewEngineException('No active view instance');
        }
        return end(self::$instanceStack);
    }

    // ---------------------------------------------------------------------
    // Used by @includeIf / @includeWhen / @includeUnless
    // ---------------------------------------------------------------------

    /**
     * Check whether a view can be resolved without rendering it.
     */
    public static function viewExists(string $view, string $type = 'view'): bool
    {
        try {
            return self::getRenderer()->resolveViewPath($view, $type) !== null;
        } catch (\Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------------
    // Used by @show
    // ---------------------------------------------------------------------

    /**
     * Used inside a layout to define a default that a child can override.
     */
    public static function show(): void
    {
        $instance = self::getCurrentInstance();
        $name = end($instance->sectionStack) ?: null;
        $instance->endSectionInternal();
        if ($name !== null) {
            $instance->yieldInternal($name);
        }
    }
}