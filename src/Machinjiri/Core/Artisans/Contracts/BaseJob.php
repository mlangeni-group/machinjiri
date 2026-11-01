<?php

namespace Mlangeni\Machinjiri\Core\Artisans\Contracts;

use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Exceptions\ContractsException\JobException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\{LoggerFactory, Logger};
use Mlangeni\Machinjiri\Core\Components\UUID\ULID\UlidComponent;
use Mlangeni\Machinjiri\Core\Security\Encryption\Cipher;

/**
 * Abstract Base Job
 */
abstract class BaseJob implements JobInterface
{
    protected string $id;
    protected string $name;
    protected array $payload;
    protected int $attempts = 0;
    protected int $maxAttempts = 3;
    protected string $queue = 'default';
    protected int $delay = 0;
    protected int $timeout = 60;
    protected int $retryDelay = 60;
    protected array $metadata = [];
    protected bool $compressPayload = false;
    private const KEY = "base-job";
    protected Logger $logger;

    // Cache for decompressed payload
    private ?array $decompressedPayload = null;
    
    /**
     * Create a new job instance
     */
    public function __construct(
        protected readonly Container $app,
        array $payload = [],
        array $options = []
    ) {
        $this->id = $options['id'] ?? $this->generateJobId();
        $this->name = $options['name'] ?? static::class;
        $this->payload = $payload;

        if (isset($options['maxAttempts'])) $this->maxAttempts = $options['maxAttempts'];
        if (isset($options['queue'])) $this->queue = $options['queue'];
        if (isset($options['delay'])) $this->delay = $options['delay'];
        if (isset($options['timeout'])) $this->timeout = $options['timeout'];
        if (isset($options['retryDelay'])) $this->retryDelay = $options['retryDelay'];
        if (isset($options['metadata'])) $this->metadata = $options['metadata'];
        
        if (isset($options['compressPayload'])) {
            $this->compressPayload = (bool) $options['compressPayload'];
            
            if ($this->compressPayload && empty($options['precompressed'])) {
                $this->payload = $this->compressPayload($payload);
            }
        }

        $this->logger = LoggerFactory::system('queue-jobs', 'queue');
        
    }

    private function generateJobId(): string
    {
        return 'job_' . strtolower(UlidComponent::generate());
    }

    protected function getApp(): Container
    {
        return $this->app;
    }
    
    /**
     * Get the job ID
     */
    public function getId(): string
    {
        return $this->id;
    }
    
    /**
     * Get the job name/class
     */
    public function getName(): string
    {
        return $this->name;
    }
    
    /**
     * Get the job payload
     */
    public function getPayload(): array
    {
        if ($this->compressPayload && is_string($this->payload)) {
            return $this->decompressedPayload ??= $this->decompressPayload($this->payload);
        }
        return $this->payload;
    }
    
    /**
     * Get the number of attempts
     */
    public function getAttempts(): int
    {
        return $this->attempts;
    }
    
    /**
     * Increment the number of attempts
     */
    public function incrementAttempts(): void
    {
        $this->attempts++;
    }
    
    /**
     * Get the maximum number of attempts
     */
    public function getMaxAttempts(): int
    {
        return $this->maxAttempts;
    }
    
    /**
     * Get the queue name
     */
    public function getQueue(): string
    {
        return $this->queue;
    }
    
    /**
     * Get the job delay in seconds
     */
    public function getDelay(): int
    {
        return $this->delay;
    }
    
    /**
     * Get the job timeout in seconds
     */
    public function getTimeout(): int
    {
        return $this->timeout;
    }
    
    /**
     * Get the job retry delay in seconds
     */
    public function getRetryDelay(): int
    {
        return $this->retryDelay;
    }
    
    /**
     * Get job metadata
     */
    public function getMetadata(): array
    {
        return $this->metadata;
    }
    
    /**
     * Set job metadata
     */
    public function setMetadata(array $metadata): void
    {
        $this->metadata = $metadata;
    }
    
    /**
     * Add metadata
     */
    public function addMetadata(string $key, $value): void
    {
        $this->metadata[$key] = $value;
    }
    
    /**
     * Called when the job fails
     */
    public function failed(JobException $exception): void
    {
        $this->getLogger()->error('Job permanently failed', [
            'name'       => $this->getName(),
            'id'         => $this->getId(),
            'attempts'   => $this->getAttempts(),
            'maxAttempts'=> $this->getMaxAttempts(),
            'exception'  => $exception->getMessage(),
        ]);
    }
    
    /**
     * Serialize the job for storage
     */
    public function serialize(): array
    {
        $payload = $this->compressPayload 
            ? ['compressed' => $this->payload, 'compressed_flag' => true]
            : $this->payload;

        return [
            'id'                => $this->id,
            'name'              => $this->name,
            'payload'           => $payload,
            'compressPayload'   => $this->compressPayload,
            'attempts'          => $this->attempts,
            'maxAttempts'       => $this->maxAttempts,
            'queue'             => $this->queue,
            'delay'             => $this->delay,
            'timeout'           => $this->timeout,
            'retryDelay'        => $this->retryDelay,
            'metadata'          => $this->metadata,
            'created_at'        => time(),
        ];
    }
    
    /**
     * Create a job from serialized data
     */
    public static function unserialize(array $data, ?Container $app = null): self
    {
        if (!$app) {
            throw new JobException('Container instance is required to unserialize a job', 60010);
        }

        $compressPayload = $data['compressPayload'] ?? false;
        $payload = $data['payload'] ?? [];
        
        if ($compressPayload && isset($payload['compressed'])) {
            $payload = $payload['compressed'];
        }

        $job = new static($app, $payload, [
            'id'              => $data['id'] ?? $this->generateJobId(),
            'name'            => $data['name'] ?? static::class,
            'maxAttempts'     => $data['maxAttempts'] ?? 3,
            'queue'           => $data['queue'] ?? 'default',
            'delay'           => $data['delay'] ?? 0,
            'timeout'         => $data['timeout'] ?? 60,
            'retryDelay'      => $data['retryDelay'] ?? 60,
            'metadata'        => $data['metadata'] ?? [],
            'compressPayload' => $compressPayload,
            'precompressed'   => true,
        ]);
        
        if (isset($data['attempts'])) {
            $job->attempts = (int) $data['attempts'];
        }
        
        return $job;
    }

    public function setAttempts(int $attempts): void
    {
        $this->attempts = $attempts;
    }
    
    protected function compressPayload(array $payload): string
    {
        return (new Cipher(self::KEY))
            ->encrypt(gzcompress(serialize($payload), 1));
    }
    
    protected function decompressPayload(string $compressed): array
    {
        return (new Cipher(self::KEY))
            ->decrypt(unserialize(gzuncompress($compressed)));
    }

    protected function calculateBackoffDelay(int $attempt): int
    {
        return $this->retryDelay * pow(2, $attempt - 1);
    }
    
    public function getNextRetryDelay(): int
    {
        return $this->calculateBackoffDelay($this->attempts + 1);
    }
    
    protected function getLogger(): Logger
    {
        return $this->logger;
    }
    
}