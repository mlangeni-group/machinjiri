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
    /**
     * Africa's Talking SDK client reused for all outbound SMS sends.
     */
    private AfricasTalking $at;

    /**
     * Build the transport, wire in shared framework services,
     * and initialize the provider SDK using the configured credentials.
     */
    public function __construct(
        Container $app,
        RetryPolicy $retryPolicy,
        CircuitBreaker $circuitBreaker,
        IdempotencyStore $idempotencyStore,
        ?RateLimiter $rateLimiter = null
    ) {
        parent::__construct($app, $retryPolicy, $circuitBreaker, $idempotencyStore, $rateLimiter);
        $this->logger = $this->app->resolve(Logger::class);

        $this->at = new AfricasTalking(
            $this->getConfig()['username'] ?? '',
            $this->getConfig()['api_key'] ?? ''
        );
        
    }

    public function getName(): string
    {
        return 'africastalking';
    }

    /**
     * Send a single message to Africa's Talking and map the SDK payload
     * back into the framework's standardized Response object.
     */
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

        // The provider responds with a nested status in the recipient entry,
        // so we surface that as the human-readable failure reason.
        $error = $data['SMSMessageData']['Recipients'][0]['status'] ?? 'Unknown error';
        return new Response(false, null, $error, $data);
    }

    /**
     * Read the SMS transport configuration for this provider from the app settings.
     */
    private function getConfig(): array 
    {
        return $this->app->configurations['sms']['transports'][$this->getName()];
    }

    /**
     * Africa's Talking failures are currently treated as non-retryable.
     */
    protected function isRetryableFailure(Response $response): bool
    {
        return false;
    }
    
    /**
     * Exceptions from the SDK are also not retried by default.
     */
    protected function isRetryableException(\Throwable $e): bool
    {
        return false;
    }
    
}