<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail;

class MailMessage implements \JsonSerializable
{
    private array $from = [];
    private array $to = [];
    private array $cc = [];
    private array $bcc = [];
    private array $replyTo = [];
    private string $subject = '';
    private string $htmlBody = '';
    private string $textBody = '';
    private array $attachments = []; // each: ['path' => string, 'name' => ?string, 'content' => ?string, 'type' => ?string]
    private array $embeddedImages = []; // each: ['cid' => string, 'path' => string]
    private array $headers = [];
    private int $priority = 3; // 1 highest, 3 normal, 5 lowest

    public function from(string $email, ?string $name = null): self
    {
        $this->from = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function to(string $email, ?string $name = null): self
    {
        $this->to[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function toMany(array $recipients): self
    {
        foreach ($recipients as $recipient) {
            if (is_string($recipient)) {
                $this->to($recipient);
            } elseif (is_array($recipient) && isset($recipient['email'])) {
                $this->to($recipient['email'], $recipient['name'] ?? null);
            }
        }
        return $this;
    }

    public function cc(string $email, ?string $name = null): self
    {
        $this->cc[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function bcc(string $email, ?string $name = null): self
    {
        $this->bcc[] = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function replyTo(string $email, ?string $name = null): self
    {
        $this->replyTo = ['email' => $email, 'name' => $name];
        return $this;
    }

    public function subject(string $subject): self
    {
        $this->subject = $subject;
        return $this;
    }

    public function html(string $html, ?string $text = null): self
    {
        $this->htmlBody = $html;
        $this->textBody = $text ?? strip_tags($html);
        return $this;
    }

    public function text(string $text): self
    {
        $this->textBody = $text;
        return $this;
    }

    public function attachFile(string $path, ?string $name = null, ?string $contentType = null): self
    {
        $this->attachments[] = [
            'path' => $path,
            'name' => $name,
            'content' => null,
            'type' => $contentType,
        ];
        return $this;
    }

    public function attachContent(string $content, string $name, ?string $contentType = null): self
    {
        $this->attachments[] = [
            'path' => null,
            'name' => $name,
            'content' => $content,
            'type' => $contentType,
        ];
        return $this;
    }

    public function embedImage(string $path, string $cid): self
    {
        $this->embeddedImages[] = ['path' => $path, 'cid' => $cid];
        return $this;
    }

    public function header(string $key, string $value): self
    {
        $this->headers[$key] = $value;
        return $this;
    }

    public function priority(int $level): self
    {
        $this->priority = $level;
        return $this;
    }

    // Getters
    public function getFrom(): array { return $this->from; }
    public function getTo(): array { return $this->to; }
    public function getCc(): array { return $this->cc; }
    public function getBcc(): array { return $this->bcc; }
    public function getReplyTo(): array { return $this->replyTo; }
    public function getSubject(): string { return $this->subject; }
    public function getHtmlBody(): string { return $this->htmlBody; }
    public function getTextBody(): string { return $this->textBody; }
    public function getAttachments(): array { return $this->attachments; }
    public function getEmbeddedImages(): array { return $this->embeddedImages; }
    public function getHeaders(): array { return $this->headers; }
    public function getPriority(): int { return $this->priority; }
    
    public function jsonSerialize(): array
    {
        return [
            'from' => $this->from,
            'to' => $this->to,
            'cc' => $this->cc,
            'bcc' => $this->bcc,
            'replyTo' => $this->replyTo,
            'subject' => $this->subject,
            'htmlBody' => $this->htmlBody,
            'textBody' => $this->textBody,
            'attachments' => $this->attachments,
            'embeddedImages' => $this->embeddedImages,
            'headers' => $this->headers,
            'priority' => $this->priority,
        ];
    }

    public function toArray(): array
    {
        return $this->jsonSerialize();
    }
    
    public static function fromArray(array $data): self
    {
        $message = new self();

        if (isset($data['from']) && !empty($data['from'])) {
            $message->from($data['from']['email'], $data['from']['name'] ?? null);
        }

        if (isset($data['to']) && is_array($data['to'])) {
            foreach ($data['to'] as $recipient) {
                $message->to($recipient['email'], $recipient['name'] ?? null);
            }
        }

        if (isset($data['cc']) && is_array($data['cc'])) {
            foreach ($data['cc'] as $recipient) {
                $message->cc($recipient['email'], $recipient['name'] ?? null);
            }
        }

        if (isset($data['bcc']) && is_array($data['bcc'])) {
            foreach ($data['bcc'] as $recipient) {
                $message->bcc($recipient['email'], $recipient['name'] ?? null);
            }
        }

        if (isset($data['replyTo']) && !empty($data['replyTo'])) {
            $message->replyTo($data['replyTo']['email'], $data['replyTo']['name'] ?? null);
        }

        if (isset($data['subject'])) {
            $message->subject($data['subject']);
        }

        if (isset($data['htmlBody']) || isset($data['textBody'])) {
            $html = $data['htmlBody'] ?? '';
            $text = $data['textBody'] ?? null;
            $message->html($html, $text);
        } elseif (isset($data['textBody'])) {
            $message->text($data['textBody']);
        }

        if (isset($data['attachments']) && is_array($data['attachments'])) {
            foreach ($data['attachments'] as $attachment) {
                if (!empty($attachment['path'])) {
                    $message->attachFile($attachment['path'], $attachment['name'] ?? null, $attachment['type'] ?? null);
                } elseif (!empty($attachment['content'])) {
                    $message->attachContent($attachment['content'], $attachment['name'], $attachment['type'] ?? null);
                }
            }
        }

        if (isset($data['embeddedImages']) && is_array($data['embeddedImages'])) {
            foreach ($data['embeddedImages'] as $image) {
                if (!empty($image['path']) && !empty($image['cid'])) {
                    $message->embedImage($image['path'], $image['cid']);
                }
            }
        }

        if (isset($data['headers']) && is_array($data['headers'])) {
            foreach ($data['headers'] as $key => $value) {
                $message->header($key, $value);
            }
        }

        if (isset($data['priority'])) {
            $message->priority((int) $data['priority']);
        }

        return $message;
    }
}