<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Helpers;

use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;
use Mlangeni\Machinjiri\Core\FileSystem\Adapters\LocalAdapter;

class HelperLoader
{

    private static LocalAdapter $adapter;

    /**
     * Load helper functions from the helper file.
     *
     * @return void
     * @throws MachinjiriException If the helper file does not exist or is not readable.
     */
    public static function getHelperMethods(): void
    {
        $loaded = [];
        $helpers = self::loadHelpers();
        foreach ($helpers as $helper) {

            if (!is_file($helper)) throw new MachinjiriException("Application Error: Unable to locate App file: {$helper}");

            if (in_array($helper, $loaded)) {
                continue;
            } else {
                $loaded[] = $helper;
                require_once $helper;
            }
        }
    }

    private static function loadHelpers(): array 
    {
        $dir = realpath(__DIR__ . '/functions/');
        if (!is_dir($dir)) {
            throw new MachinjiriException('Application Error: could not locate app functions');
        }

        $adapter = new LocalAdapter($dir);

        $files = $adapter->listContents();

        $funcs = [];

        foreach ($files as $file) {
            $fileName = $file['basename'] ?? 'unknown';
            if (!in_array($fileName, self::requiredFiles())) {
                throw new MachinjiriException("App file: {$fileName} does not exist");
            } 
            $funcs[] = $dir . DIRECTORY_SEPARATOR . $fileName;
        }
        
        return $funcs;
    }

    private static function requiredFiles(): array 
    {
        return [
            'app.php', 'events.php', 'dateTime.php', 'dump.php', 'viewEngine.php',
            'routing.php', 'http.php', 'database.php', 'caching.php',
        ];
    }
    
}