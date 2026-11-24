<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat;

class ChatResponse
{
    public function __construct(
        private bool $success,
        private ?string $messageId = null,
        private ?string $error = null,
        private array $raw = []
    ) {}

    public function isSuccess(): bool
    {
        return $this->success;
    }

    public function getMessageId(): ?string
    {
        return $this->messageId;
    }

    public function getError(): ?string
    {
        return $this->error;
    }

    public function getRaw(): array
    {
        return $this->raw;
    }
}
