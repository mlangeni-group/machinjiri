<?php
namespace Mlangeni\Machinjiri\Core\Transport\SMS;

use Mlangeni\Machinjiri\Core\Transport\SMS\Builder\MessageBuilder;

class Message
{
    public string $to;
    public string $text;
    public ?string $from;
    public ?string $encoding;
    public ?int $validityPeriod;
    public bool $deliveryReceipt;
    public array $options;

    public function __construct() {}

    public function getTo(): string { return $this->to; }
    public function getText(): string { return $this->text; }
    public function getFrom(): ?string { return $this->from; }
    public function getEncoding(): ?string { return $this->encoding; }
    public function getValidityPeriod(): ?int { return $this->validityPeriod; }
    public function wantsDeliveryReceipt(): bool { return $this->deliveryReceipt; }
    public function getOptions(): array { return $this->options; }

    public static function builder(): MessageBuilder
    {
        return new MessageBuilder();
    }

    public static function fromArray(array $build): self
    {
        $message = new self()->builder();
        if (isset($build['to'])) {
            $message->to($build['to']);
        }

        if (isset($build['text'])) {
            $message->text($build['text'] ?? $build['body']);
        }

        if (isset($build['from'])) {
            $message->from($build['from']);
        }

        if (isset($build['encoding'])) {
            $message->encoding($build['encoding']);
        }

        if (isset($build['validityPeriod'])) {
            $message->validityPeriod($build['validityPeriod']);
        }

        if (isset($build['withDeliveryReceipt'])) {
            $message->withDeliveryReceipt($build['withDeliveryReceipt']);
        }

        if (isset($build['withOption'])) {
            $message->withOption($build['withOption']);
        }

        return $message->build();
    }
}