<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

class HelperLoader
{
    /**
     * Load helper functions from the helper file.
     *
     * @return void
     * @throws MachinjiriException If the helper file does not exist or is not readable.
     */
    public static function getHelperMethods(): void
    {
        $helperFile = __DIR__ . '/helpers.php';
        if (!is_file($helperFile)) {
            throw new MachinjiriException("Helper file not found: {$helperFile}");
        }
        require_once $helperFile;
    }
    
}