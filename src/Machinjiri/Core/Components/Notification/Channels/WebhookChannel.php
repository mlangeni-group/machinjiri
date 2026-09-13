<?php

namespace Mlangeni\Machinjiri\Core\Components\Notification\Channels;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\ChannelInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Contracts\NotifiableInterface;
use Mlangeni\Machinjiri\Core\Components\Notification\Notification;
use Mlangeni\Machinjiri\Core\Components\Notification\NotificationResponse;
use Mlangeni\Machinjiri\Core\Http\{HttpRequest, HttpResponse, HttpClient};
use Mlangeni\Machinjiri\Core\Exceptions\MachinjiriException;

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

        try {
             $request = (Container::instancePresent()) 
                ? Container::getInstance()->resolve(HttpRequest::class)
                : HttpRequest::createFromGlobals();
    
            $response = $request->api($url, 'POST', $body, $headers); 

            $status = $response->getStatusCode();

            if ($status >= 200 && $status < 300) {
                return NotificationResponse::success($this->name(), [
                    'status' => $status,
                    'body'   => $response->getBody(),
                ], ['url' => $url]);
            }

            return NotificationResponse::failure(
                $this->name(),
                "Webhook responded with HTTP {$status}.",
                ['url' => $url, 'status' => $status, 'body' => $responseBody]
            );
            
        } catch (MachinjiriException $e) {
            $this->logger->error('Webhook notification failed (transport error)', [
                'url'   => $url,
                'error' => $error,
            ]);
            return NotificationResponse::failure($this->name(), $error, ['url' => $url]);
        }
    }
}