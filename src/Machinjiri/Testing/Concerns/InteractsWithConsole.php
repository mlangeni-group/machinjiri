<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Artisans\Terminal\Terminal;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

trait InteractsWithConsole
{
    protected function artisan(string $command, array $parameters = []): int
    {
        $terminal = new Terminal();
        $terminal->setAutoExit(false);

        $input = new ArrayInput(array_merge([
            'command' => $command,
        ], $parameters));

        $output = new BufferedOutput();
        return $terminal->run($input, $output);
    }
}
