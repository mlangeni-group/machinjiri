<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat;

use Mlangeni\Machinjiri\Core\Exceptions\ChatException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatMessage;
use Mlangeni\Machinjiri\Core\Transport\Chat\Contracts\TransportInterface;
use Mlangeni\Machinjiri\Core\Transport\Chat\Transports\FacebookTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\Transports\InstagramTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\Transports\TikTokTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\Transports\WhatsAppTransport;

class ChatManager
{
    /** @var array<string, TransportInterface> */
    private array $transports = [];

    public function __construct(iterable $transports = [], private ?string $defaultTransport = null)
    {
        foreach ($transports as $name => $transport) {
            $this->addTransport($transport, is_string($name) ? $name : null);
        }
    }

    public static function fromConfig(array $config, ?HttpClient $httpClient = null): self
    {
        $transports = [];
        foreach ($config['transports'] ?? [] as $name => $transportConfig) {
            $driver = strtolower((string) ($transportConfig['driver'] ?? $name));
            $transports[$name] = match ($driver) {
                'whatsapp'      => new WhatsAppTransport($transportConfig, $httpClient),
                'facebook'      => new FacebookTransport($transportConfig, $httpClient),
                'instagram'     => new InstagramTransport($transportConfig, $httpClient),
                'tiktok'        => new TikTokTransport($transportConfig, $httpClient),
                default         => throw ChatException::configError("Unsupported chat transport driver: {$driver}"),
            };
        }

        return new self($transports, $config['default'] ?? null);
    }

    public function addTransport(TransportInterface $transport, ?string $name = null): void
    {
        $this->transports[$name ?? $transport->getName()] = $transport;
    }

    public function send(ChatMessage $message, ?string $transport = null): ChatResponse
    {
        $name = $transport ?? $this->defaultTransport;
        if ($name === null || !isset($this->transports[$name])) {
            throw ChatException::configError(
                $name === null
                    ? 'A default chat transport must be configured or specified.'
                    : "Chat transport is not registered: {$name}"
            );
        }

        return $this->transports[$name]->send($message);
    }

    public function post(ChatMessage $message, ?string $transport = null): ChatResponse
    {
        return $this->send($message, $transport);
    }
}
