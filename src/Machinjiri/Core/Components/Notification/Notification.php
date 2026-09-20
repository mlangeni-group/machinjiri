<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification;

use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\UUID\Random\UuidGenerator;

abstract class Notification
{
    protected string $id;
    protected ?string $queueName = null;
    protected ?int $delay = null;

    public function __construct(?string $id = null)
    {
        $this->id = $id ?? $this->generateId();
    }

    /**
     * Channels the notification should be sent through.
     *
     * @return string[] e.g. ['mail', 'sms', 'database']
     */
    abstract public function via(NotifiableInterface $notifiable): array;

    /* -----------------------------------------------------------------
     |  Routing / scheduling
     | ----------------------------------------------------------------- */

    public function id(): ?string
    {
        return $this->id ?? $this->generateId();
    }

    public function onQueue(?string $queue): static
    {
        $this->queueName = $queue;
        return $this;
    }

    public function queueName(): ?string
    {
        return $this->queueName;
    }

    public function delay(?int $seconds): static
    {
        $this->delay = $seconds;
        return $this;
    }

    public function delaySeconds(): ?int
    {
        return $this->delay;
    }

    /* -----------------------------------------------------------------
     |  Conditional delivery
 | ----------------------------------------------------------------- */

    /**
     * Allow a notification to opt‑out per recipient / channel.
     */
    public function shouldSend(NotifiableInterface $notifiable, string $channel): bool
    {
        return true;
    }

    /* -----------------------------------------------------------------
     |  Channel payload builders.
     |
     |  Return `null` (or omit) to signal "this channel is unsupported".
     | ----------------------------------------------------------------- */

    public function toMail(NotifiableInterface $notifiable): mixed
    {
        return null;
    }

    public function toSms(NotifiableInterface $notifiable): mixed
    {
        return null;
    }

    public function toDatabase(NotifiableInterface $notifiable): ?array
    {
        return null;
    }

    public function toWebhook(NotifiableInterface $notifiable): ?array
    {
        return null;
    }

    /* -----------------------------------------------------------------
     |  Internals
     | ----------------------------------------------------------------- */

    protected function generateId(): string
    {
        return UuidGenerator::v1()->toString();
    }

    public function __serialize(): array
    {
        return [
            'id'        => $this->id,
            'queueName' => $this->queueName,
            'delay'     => $this->delay,
        ];
    }

    public function __unserialize(array $data): void
    {
        $this->id        = $data['id'] ?? $this->generateId();
        $this->queueName = $data['queueName'] ?? null;
        $this->delay     = $data['delay'] ?? null;
    }
}