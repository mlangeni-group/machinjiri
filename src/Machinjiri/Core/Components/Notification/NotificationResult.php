<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification;

class NotificationResult implements \JsonSerializable
{
    /** @var NotificationResponse[] */
    private array $responses = [];

    public function add(NotificationResponse $response): self
    {
        $this->responses[] = $response;
        return $this;
    }

    public function merge(self $other): self
    {
        foreach ($other->all() as $response) {
            $this->add($response);
        }
        return $this;
    }

    /** @return NotificationResponse[] */
    public function all(): array
    {
        return $this->responses;
    }

    /** @return NotificationResponse[] */
    public function successes(): array
    {
        return array_values(array_filter(
            $this->responses,
            static fn(NotificationResponse $r) => $r->success
        ));
    }

    /** @return NotificationResponse[] */
    public function failures(): array
    {
        return array_values(array_filter(
            $this->responses,
            static fn(NotificationResponse $r) => !$r->success
        ));
    }

    public function isSuccessful(): bool
    {
        return $this->failures() === [];
    }

    public function isEmpty(): bool
    {
        return $this->responses === [];
    }

    public function count(): int
    {
        return count($this->responses);
    }

    public function jsonSerialize(): array
    {
        return [
            'successful' => $this->isSuccessful(),
            'responses'  => array_map(
                static fn(NotificationResponse $r) => $r->toArray(),
                $this->responses
            ),
        ];
    }
}