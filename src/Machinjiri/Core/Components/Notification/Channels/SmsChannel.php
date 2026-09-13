<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Channels;

use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\ChannelInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResponse;
use Mlangeni\Machinjiri\Core\Transport\SMS\Message;
use Mlangeni\Machinjiri\Core\Transport\SMS\SMSManager;

class SmsChannel implements ChannelInterface
{
    public function __construct(private SMSManager $smsManager) {}

    public function name(): string
    {
        return 'sms';
    }

    public function send(Notification $notification, NotifiableInterface $notifiable): NotificationResponse
    {
        $payload = $notification->toSms($notifiable);

        if ($payload === null) {
            return NotificationResponse::failure(
                $this->name(),
                'Notification does not provide an SMS payload.'
            );
        }

        try {
            $message = $payload instanceof Message
                ? $payload
                : $this->buildMessage($payload, $notifiable);

            $async = $notification->queueName() !== null;
            $response = $this->smsManager->send($message, $async);

            return NotificationResponse::success($this->name(), $response, ['queued' => $async]);
        } catch (\Throwable $e) {
            return NotificationResponse::failure(
                $this->name(),
                $e->getMessage(),
                ['exception' => get_class($e)]
            );
        }
    }

    protected function buildMessage(mixed $payload, NotifiableInterface $notifiable): Message
    {
        $to   = is_array($payload) && isset($payload['to'])
            ? $payload['to']
            : $notifiable->routeNotificationFor('sms');

        $body = is_array($payload)
            ? (string) ($payload['body'] ?? $payload['message'] ?? '')
            : (string) $payload;

        return new Message($to, $body);
    }
}