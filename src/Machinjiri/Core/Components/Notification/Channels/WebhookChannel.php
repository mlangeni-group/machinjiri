<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Channels;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\ChannelInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResponse;

class WebhookChannel implements ChannelInterface
{
    public function __construct(private Logger $logger) {}

    public function name(): string
    {
        return 'webhook';
    }

    public function send(Notification $notification, NotifiableInterface $notifiable): NotificationResponse
    {
        $payload = $notification->toWebhook($notifiable);

        if ($payload === null || $payload === []) {
            return NotificationResponse::failure(
                $this->name(),
                'Notification does not provide a webhook payload.'
            );
        }

        $url = $payload['url'] ?? $notifiable->routeNotificationFor('webhook');

        if (!$url || !filter_var($url, FILTER_VALIDATE_URL)) {
            return NotificationResponse::failure(
                $this->name(),
                'No valid webhook URL provided.'
            );
        }

        $body = json_encode([
            'notification_id' => $notification->id(),
            'event'           => $payload['event'] ?? get_class($notification),
            'data'            => $payload['data'] ?? [],
            'occurred_at'     => date(DATE_ATOM),
        ], JSON_THROW_ON_ERROR);

        $headers = array_merge(
            ['Content-Type: application/json', 'Accept: application/json'],
            $payload['headers'] ?? []
        );

        if (!empty($payload['secret'])) {
            $headers[] = 'X-Notification-Signature: ' . hash_hmac('sha256', $body, $payload['secret']);
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $body,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => (int) ($payload['timeout'] ?? 10),
            CURLOPT_CONNECTTIMEOUT => (int) ($payload['connect_timeout'] ?? 5),
        ]);

        $responseBody = curl_exec($ch);
        $status       = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error        = curl_error($ch);
        curl_close($ch);

        if ($error) {
            $this->logger->error('Webhook notification failed (transport error)', [
                'url'   => $url,
                'error' => $error,
            ]);

            return NotificationResponse::failure($this->name(), $error, ['url' => $url]);
        }

        if ($status >= 200 && $status < 300) {
            return NotificationResponse::success($this->name(), [
                'status' => $status,
                'body'   => $responseBody,
            ], ['url' => $url]);
        }

        return NotificationResponse::failure(
            $this->name(),
            "Webhook responded with HTTP {$status}.",
            ['url' => $url, 'status' => $status, 'body' => $responseBody]
        );
    }
}