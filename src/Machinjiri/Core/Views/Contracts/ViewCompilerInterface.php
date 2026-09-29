<?php

namespace Mlangeni\Machinjiri\Core\Views\Contracts;

interface ViewCompilerInterface
{
    /**
     * Compile view content with custom tags into PHP.
     *
     * @param string      $content Raw template source.
     * @param string|null $path    Source path (for error reporting).
     */
    public function compile(string $content, ?string $path = null): string;
}