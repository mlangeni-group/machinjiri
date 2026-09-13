<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Channels;

use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\ChannelInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResponse;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailManager;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;

class MailChannel implements ChannelInterface
{
    public function __construct(private MailManager $mailManager) {}

    public function name(): string
    {
        return 'mail';
    }

    public function send(Notification $notification, NotifiableInterface $notifiable): NotificationResponse
    {
        $payload = $notification->toMail($notifiable);

        if ($payload === null) {
            return NotificationResponse::failure(
                $this->name(),
                'Notification does not provide a mail payload.'
            );
        }

        try {
            $message = $this->buildMessage($payload, $notifiable);
            $response = $this->mailManager->send($message);

            return NotificationResponse::success(
                $this->name(),
                $response,
                ['message_id' => method_exists($response, 'getMessageId') ? $response->getMessageId() : null]
            );
        } catch (\Throwable $e) {
            return NotificationResponse::failure(
                $this->name(),
                $e->getMessage(),
                ['exception' => get_class($e)]
            );
        }
    }

    protected function buildMessage(mixed $payload, NotifiableInterface $notifiable): MailMessage
    {
        if ($payload instanceof MailMessage) {
            return $payload;
        }

        $message = new MailMessage();

        $to = $notifiable->routeNotificationFor('mail');
        if ($to) {
            method_exists($message, 'to') && $message->to($to);
        }

        if (is_string($payload)) {
            $message->html($payload);
            return $message;
        }

        if (is_array($payload)) {
            if (!empty($payload['to'])) {
                method_exists($message, 'to') && $message->to($payload['to']);
            }
            if (!empty($payload['cc'])) {
                method_exists($message, 'cc') && $message->cc($payload['cc']);
            }
            if (!empty($payload['bcc'])) {
                method_exists($message, 'bcc') && $message->bcc($payload['bcc']);
            }
            if (!empty($payload['subject'])) {
                method_exists($message, 'subject') && $message->subject($payload['subject']);
            }
            if (!empty($payload['html']) && !empty($payload['text'])) {
                $message->html($payload['html'], $payload['text']);
            } elseif (!empty($payload['html'])) {
                $message->html($payload['html']);
            } elseif (!empty($payload['text'])) {
                method_exists($message, 'text') && $message->text($payload['text']);
            }
            if (!empty($payload['attachments']) && method_exists($message, 'attach')) {
                foreach ($payload['attachments'] as $attachment) {
                    $message->attach($attachment);
                }
            }
            return $message;
        }

        return $message;
    }
}