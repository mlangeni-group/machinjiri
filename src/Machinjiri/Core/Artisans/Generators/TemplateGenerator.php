<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Generators;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Views\View;
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

/**
 * Rich template generator for creating views, layouts and partials.
 *
 * Supports:
 * - Dot notation (e.g., 'admin.dashboard')
 * - Namespaced templates (e.g., 'blog::posts.index')
 * - Automatic directory creation
 * - Customisable stub content
 * - Batch generation for resources (index, create, edit, show)
 */
class TemplateGenerator
{
    /**
     * Base directory for views (without trailing slash).
     */
    protected string $basePath;

    /**
     * Extension mapping (same as View class).
     */
    protected array $extensions = [
        'view'    => '.view.php',
        'layout'  => '.layout.php',
        'partial' => '.frag.php',
    ];

    /**
     * Constructor.
     *
     * @param string|null $basePath Override the base views directory.
     *                               If null, auto‑detect from View::$basePath or default location.
     * @throws MachinjiriException If base path is invalid.
     */
    public function __construct(?string $basePath = null)
    {
        if ($basePath === null) {
            // Try to reuse View's configured base path
            if (property_exists(View::class, 'basePath') && !empty(View::$basePath)) {
                $basePath = View::$basePath;
            } else {
                $appBase = Container::$appBasePath ?? dirname(__DIR__, 5);
                $basePath = $appBase . DIRECTORY_SEPARATOR . 'resources' . DIRECTORY_SEPARATOR . 'views';
            }
        }

        $this->basePath = rtrim($basePath, '/\\') . DIRECTORY_SEPARATOR;

        if (!is_dir($this->basePath) && !mkdir($this->basePath, 0755, true)) {
            throw new MachinjiriException("Cannot create views directory: {$this->basePath}");
        }

    }

    /**
     * Generate a view file.
     *
     * @param string      $name     View name (dot notation or namespace::view)
     * @param string|null $layout   Optional layout to extend (e.g., 'layouts.app')
     * @param array       $data     Placeholder replacements (e.g., ['title' => 'My Page'])
     * @param bool        $force    Overwrite existing file
     * @return string     Generated file path
     * @throws MachinjiriException
     */
    public function makeView(string $name, ?string $layout = null, array $data = [], bool $force = false): string
    {
        $layoutStub = null;
        if ($layout !== null) {
            $layoutStub .= "<%= extend('" . addslashes($layout) . "'); %>";
        }
        $stub = $this->replacePlaceholders($this->viewStub($layoutStub), $data + ['__view__' => $name]);

        return $this->writeTemplate($name, 'view', $this->viewStub($layoutStub), $force);
    }
    
    public function makeLayout(string $name, array $data = [], bool $force = false): string
    {
        $stub = $this->replacePlaceholders($this->layoutStub(), $data);
        return $this->writeTemplate($name, 'layout', $stub, $force);
    }
    
    public function makePartial(string $name, array $data = [], bool $force = false): string
    {
        $stub = $this->replacePlaceholders($this->partialStub(), $data);
        return $this->writeTemplate($name, 'partial', $stub, $force);
    }

    /**
     * Write a template file to disk.
     *
     * @param string $name      Template name (dot notation or namespace::name)
     * @param string $type      One of 'view', 'layout', 'partial'
     * @param string $content   The file content
     * @param bool   $force     Overwrite if exists
     * @return string           Absolute file path
     * @throws MachinjiriException
     */
    protected function writeTemplate(string $name, string $type, string $content, bool $force): string
    {
        $targetPath = $this->resolveDestinationPath($name, $type);
        $directory = dirname($targetPath);

        if (!is_dir($directory) && !mkdir($directory, 0755, true)) {
            throw new MachinjiriException("Cannot create directory: {$directory}");
        }

        if (file_exists($targetPath) && !$force) {
            throw new MachinjiriException("Template already exists: {$targetPath} (use force=true to overwrite)");
        }

        if (file_put_contents($targetPath, $content, LOCK_EX) === false) {
            throw new MachinjiriException("Failed to write template: {$targetPath}");
        }

        return $targetPath;
    }

    /**
     * Resolve the full filesystem path for a given template name and type.
     *
     * Supports:
     * - Dot notation: 'admin.dashboard' -> 'admin/dashboard.view.php'
     * - Namespaced: 'blog::posts.index' -> uses registered namespace path
     *
     * @param string $name
     * @param string $type
     * @return string
     * @throws MachinjiriException
     */
    protected function resolveDestinationPath(string $name, string $type): string
    {
        $ext = $this->extensions[$type] ?? '.view.php';

        // Namespace resolution (must be pre‑registered in View::$namespaces)
        if (str_contains($name, '::')) {
            [$namespace, $relative] = explode('::', $name, 2);
            if (!isset(View::$namespaces[$namespace])) {
                throw new MachinjiriException("Namespace '{$namespace}' is not registered in View::\$namespaces");
            }
            $base = rtrim(View::$namespaces[$namespace], '/\\');
            $path = $base . DIRECTORY_SEPARATOR . str_replace('.', DIRECTORY_SEPARATOR, $relative) . $ext;
            return $path;
        }

        // Normal dot notation
        $relativePath = str_replace('.', DIRECTORY_SEPARATOR, $name);
        return $this->basePath . $relativePath . $ext;
    }

    /**
     * Replace placeholders like {{ var }} or {{ $var }} in stub content.
     *
     * @param string $content
     * @param array  $data
     * @return string
     */
    protected function replacePlaceholders(string $content, array $data): string
    {
        foreach ($data as $key => $value) {
            $content = str_replace(
                ['{{ $' . $key . ' }}', '{{ $' . $key . '}}', '{{' . $key . '}}', '{{ ' . $key . ' }}'],
                htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'),
                $content
            );
            // Also replace plain {{ key }} variations without $
            $content = str_replace(
                ['{{ ' . $key . ' }}', '{{' . $key . '}}'],
                htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'),
                $content
            );
        }
        return $content;
    }

    /**
     * Get the base views directory used by the generator.
     *
     * @return string
     */
    public function getBasePath(): string
    {
        return $this->basePath;
    }
    
    private function viewStub (?string $layout = null) { 
      $layout = ($layout !== null) ? $layout : "";
    return <<<HTML
<?php use Mlangeni\Machinjiri\Core\Views\View; ?>
$layout

@section('title')
    {{ \$title ?? 'Welcome' }}
@endsection

@section('content')
    <div class="container">
        <h1>{{ \$heading ?? 'Hello World' }}</h1>
        <p>This is your view: {{ \$__view__ }}</p>
    </div>
@endsection

@section('styles')
    <style>
        .container { max-width: 800px; margin: 2rem auto; }
    </style>
@endsection
HTML;
    }
    
    private function partialStub () { return <<<'STUB'
<div class="partial">
    <h3>{{ $title ?? 'Partial title' }}</h3>
    <p>{{ $content ?? 'Partial content' }}</p>
</div> 
STUB;
    }
    
    private function layoutStub () { return <<<'STUB'
<?php use Mlangeni\Machinjiri\Core\Views\View; ?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{{ $title ?? 'Page Title' }}</title>
    <?php style('css/app.css') ?>
    @yield('styles')
</head>
<body>
    
    @yield('content')

</body>
</html>
STUB;
    }
}