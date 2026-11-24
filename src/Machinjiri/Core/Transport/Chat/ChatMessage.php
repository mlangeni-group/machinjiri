<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat;

class ChatMessage
{
    private ?string $recipient = null;
    private string $text = '';
    private ?string $mediaUrl = null;
    private string $mediaType = 'image';
    private array $options = [];

    public function recipient(string $recipient): self
    {
        $this->recipient = $recipient;
        return $this;
    }

    public function text(string $text): self
    {
        $this->text = $text;
        return $this;
    }

    public function media(string $url, string $type = 'image'): self
    {
        $this->mediaUrl = $url;
        $this->mediaType = $type;
        return $this;
    }

    public function option(string $key, mixed $value): self
    {
        $this->options[$key] = $value;
        return $this;
    }

    public function getRecipient(): ?string
    {
        return $this->recipient;
    }

    public function getText(): string
    {
        return $this->text;
    }

    public function getMediaUrl(): ?string
    {
        return $this->mediaUrl;
    }

    public function getMediaType(): string
    {
        return $this->mediaType;
    }

    public function getOptions(): array
    {
        return $this->options;
    }
}
