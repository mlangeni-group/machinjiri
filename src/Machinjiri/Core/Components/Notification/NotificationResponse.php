<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification;

class NotificationResponse
{
    public function __construct(
        public readonly string $channel,
        public readonly bool $success,
        public readonly mixed $result = null,
        public readonly ?string $error = null,
        public readonly array $meta = []
    ) {}

    public static function success(string $channel, mixed $result = null, array $meta = []): self
    {
        return new self($channel, true, $result, null, $meta);
    }

    public static function failure(string $channel, string $error, array $meta = []): self
    {
        return new self($channel, false, null, $error, $meta);
    }

    public function toArray(): array
    {
        return [
            'channel' => $this->channel,
            'success' => $this->success,
            'result'  => $this->result,
            'error'   => $this->error,
            'meta'    => $this->meta,
        ];
    }
}