<?php

use Mlangeni\Machinjiri\Core\Views\{View, Services\AssetManager};

if (!function_exists('view')) {
    /**
     * Render a view and return its content.
     *
     * @param string $view
     * @param array $data
     * @return string
     */
    function view(string $view, array $data = []): void
    {
        View::make($view, $data)->display();
    }
}

if (!function_exists('display_view')) {
    /**
     * Render and directly output a view.
     *
     * @param string $view
     * @param array $data
     */
    function display_view(string $view, array $data = []): void
    {
        View::make($view, $data)->display();
    }
}

if (!function_exists('asset')) {
    /**
     * Generate a versioned asset URL.
     *
     * @param string $path
     * @return string
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function asset(string $path): string
    {
        return View::asset($path);
    }
}

if (!function_exists('style')) {
    /**
     * Generate a <link> tag for a CSS asset.
     *
     * @param string $path
     * @param array $attributes
     * @return void
     */
    function style(string $path, array $attributes = []): void
    {
        (new AssetManager())->style($path, $attributes);
    }
}

if (!function_exists('script')) {
    /**
     * Generate a <script> tag for a JavaScript asset.
     *
     * @param string $path
     * @param array $attributes
     * @return string
     */
    function script(string $path, array $attributes = []): void
    {
        (new AssetManager())->script($path, $attributes);
    }
}


if (!function_exists('share')) {
    /**
     * Share data across all views.
     *
     * @param string|array $key
     * @param mixed|null $value
     */
    function share($key, $value = null): void
    {
        View::share($key, $value);
    }
}

// ---- Template tag helpers (for use inside view files) ----

if (!function_exists('section')) {
    /**
     * Start or set a named section.
     *
     * @param string $name
     * @param string|null $content
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function section(string $name, ?string $content = null): void
    {
        View::section($name, $content);
    }
}

if (!function_exists('endSection')) {
    /**
     * End the currently open section.
     *
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function endSection(): void
    {
        View::endSection();
    }
}

if (!function_exists('view_yield')) {
    /**
     * Output the content of a section.
     *
     * @param string $name
     */
    function view_yield(string $name): void
    {
        View::yield($name);
    }
}

if (!function_exists('extend')) {
    /**
     * Specify a layout to extend.
     *
     * @param string $layout
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function extend(string $layout): void
    {
        View::extend($layout);
    }
}

if (!function_exists('include_view')) {
    /**
     * Include a partial view (shortcut for View::include).
     *
     * @param string $view
     * @param array $data
     * @throws \Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException
     */
    function include_view(string $view, array $data = []): void
    {
        View::include($view, $data);
    }
}

if (!function_exists('hasSection')) {
    /**
     * Check if a section exists.
     *
     * @param string $name
     * @return bool
     */
    function hasSection(string $name): bool
    {
        return View::hasSection($name);
    }
}

if (!function_exists('getSection')) {
    /**
     * Get the content of a section (without outputting).
     *
     * @param string $name
     * @return string
     */
    function getSection(string $name): string
    {
        return View::getSection($name);
    }
}

if (!function_exists('parent')) {
    /**
     * Output the parent section content (when using inheritance).
     */
    function parent(): void
    {
        View::parent();
    }
}