<?php

namespace Mlangeni\Machinjiri\Core\Transport\SMS\Transports;

use Mlangeni\Machinjiri\Core\Transport\SMS\Contracts\TransportInterface;
use Mlangeni\Machinjiri\Core\Transport\SMS\{AbstractTransport, Message, Response};
use Mlangeni\Machinjiri\Core\Transport\SMS\Retry\RetryPolicy;
use Mlangeni\Machinjiri\Core\Transport\SMS\Circuit\CircuitBreaker;
use Mlangeni\Machinjiri\Core\Transport\SMS\Idempotency\IdempotencyStore;
use Mlangeni\Machinjiri\Core\Transport\SMS\RateLimit\RateLimiter;
use Mlangeni\Machinjiri\Core\Artisans\Events\EventListener;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\SMSException;
use AfricasTalking\SDK\AfricasTalking;

class AfricasTalkingTransport extends AbstractTransport implements TransportInterface
{
    private AfricasTalking $at;

    public function __construct(
        Container $app,
        RetryPolicy $retryPolicy,
        CircuitBreaker $circuitBreaker,
        IdempotencyStore $idempotencyStore,
        ?RateLimiter $rateLimiter = null
    ) {
        parent::__construct($app, $retryPolicy, $circuitBreaker, $idempotencyStore, $rateLimiter);

        if (!class_exists(AfricasTalking::class)) {
            throw SMSException::transportError("AfricasTalking library not installed. Install via composer with 'composer require africastalking/africastalking'");
        }
        
        $this->at = new AfricasTalking(
            $this->getConfig()['username'],
            $this->getConfig()['apiKey']
        );
    }

    public function getName(): string
    {
        return 'africastalking';
    }

    public function doSend(Message $message): Response
    {
        $sms = $this->at->sms();
        $data = $sms->send([
            'to'      => $message->getTo(),
            'message' => $message->getText(),
            'from'    => $message->getFrom(),
        ]);

        if ($data['status'] === 'success') {
            $messageId = $data['SMSMessageData']['Recipients'][0]['messageId'] ?? null;
            return new Response(true, $messageId, null, $data);
        }

        $error = $data['SMSMessageData']['Recipients'][0]['status'] ?? 'Unknown error';
        return new Response(false, null, $error, $data);
    }

    private function getConfig(): array 
    {
        return $this->app->configurations['sms']['transports'][$this->getName()];
    }

    protected function isRetryableFailure(Response $response): bool
    {
        return false;
    }
    
    protected function isRetryableException(\Throwable $e): bool
    {
        return false;
    }
    
}