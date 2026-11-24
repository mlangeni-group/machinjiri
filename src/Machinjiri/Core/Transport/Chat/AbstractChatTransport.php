<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat;

use Mlangeni\Machinjiri\Core\Exceptions\ChatException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Transport\Chat\Contracts\TransportInterface;

abstract class AbstractChatTransport implements TransportInterface
{
    protected HttpClient $httpClient;

    public function __construct(protected array $config, ?HttpClient $httpClient = null)
    {
        $this->httpClient = $httpClient ?? new HttpClient(
            $config['api_base'] ?? $this->defaultApiBase()
        );
    }

    abstract protected function defaultApiBase(): string;

    protected function requireConfig(string ...$keys): void
    {
        foreach ($keys as $key) {
            if (!isset($this->config[$key]) || $this->config[$key] === '') {
                throw ChatException::configError("Missing {$this->getName()} config: {$key}");
            }
        }
    }

    protected function postJson(string $endpoint, array $payload): ChatResponse
    {
        $result = $this->httpClient->post($endpoint, $payload);
        $decoded = json_decode($result['data'] ?? '', true);
        $raw = is_array($decoded) ? $decoded : ['body' => $result['data'] ?? ''];
        $status = (int) ($result['http_code'] ?? 0);
        $providerError = $raw['error'] ?? null;
        if (is_array($providerError) && ($providerError['code'] ?? null) === 'ok') {
            $providerError = null;
        }
        $success = $status >= 200 && $status < 300
            && ($providerError === null || $providerError === '' || $providerError === 'ok')
            && is_array($decoded);

        return new ChatResponse(
            $success,
            $success ? $this->findMessageId($raw) : null,
            $success ? null : (
                is_array($decoded)
                    ? $this->errorMessage($providerError, $status)
                    : 'Provider returned an invalid JSON response.'
            ),
            $raw
        );
    }

    protected function findMessageId(array $data): ?string
    {
        foreach ([
            $data['id'] ?? null,
            $data['publish_id'] ?? null,
            $data['data']['id'] ?? null,
            $data['data']['publish_id'] ?? null,
            $data['messages'][0]['id'] ?? null,
        ] as $id) {
            if (is_scalar($id) && (string) $id !== '') {
                return (string) $id;
            }
        }

        return null;
    }

    private function errorMessage(mixed $error, int $status): string
    {
        if (is_array($error)) {
            $message = $error['message'] ?? $error['error_user_msg'] ?? $error['code'] ?? null;
            if (is_scalar($message) && (string) $message !== '') {
                return (string) $message;
            }
        } elseif (is_scalar($error) && (string) $error !== '') {
            return (string) $error;
        }

        return $status === 0 ? 'The provider returned an invalid HTTP response.' : "Provider returned HTTP {$status}.";
    }
}
