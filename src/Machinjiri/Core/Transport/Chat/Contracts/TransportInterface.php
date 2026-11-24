<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat\Contracts;

use Mlangeni\Machinjiri\Core\Transport\Chat\ChatMessage;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatResponse;

interface TransportInterface
{
    public function send(ChatMessage $message): ChatResponse;

    public function getName(): string;
}
