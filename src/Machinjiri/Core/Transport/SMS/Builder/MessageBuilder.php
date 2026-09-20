<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS\Builder;

use Mlangeni\Machinjiri\Core\Transport\SMS\Message;

class MessageBuilder 
{
    private Message $message;

    public function __construct()
    {
        $this->message = new Message();
        $this->message->deliveryReceipt = false;
        $this->message->options = [];
    }

    public function to(string $phone): self
    {
        $this->message->to = $this->normalizePhone($phone);
        return $this;
    }

    public function text(string $text): self
    {
        $this->message->text = $text;
        return $this;
    }

    public function from(?string $sender): self
    {
        $this->message->from = $sender;
        return $this;
    }

    public function encoding(string $encoding): self
    {
        $this->message->encoding = $encoding;
        return $this;
    }

    public function validityPeriod(int $seconds): self
    {
        $this->message->validityPeriod = $seconds;
        return $this;
    }

    public function withDeliveryReceipt(bool $enable = true): self
    {
        $this->message->deliveryReceipt = $enable;
        return $this;
    }

    public function withOption(string $key, $value): self
    {
        $this->message->options[$key] = $value;
        return $this;
    }

    public function build(): Message
    {
        if (empty($this->message->to) || empty($this->message->text)) {
            throw new \InvalidArgumentException('Message requires "to" and "text"');
        }
        return clone $this->message;
    }

    private function normalizePhone(string $phone): string
    {
        return preg_replace('/[^0-9+]/', '', $phone);
    }
}