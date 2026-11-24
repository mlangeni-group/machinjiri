<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat\Transports;

use Mlangeni\Machinjiri\Core\Exceptions\ChatException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Transport\Chat\AbstractChatTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatMessage;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatResponse;

class FacebookTransport extends AbstractChatTransport
{
    public function __construct(array $config, ?HttpClient $httpClient = null)
    {
        parent::__construct($config, $httpClient);
        $this->requireConfig('page_id', 'access_token');
    }

    public function getName(): string
    {
        return 'facebook';
    }

    protected function defaultApiBase(): string
    {
        return 'https://graph.facebook.com';
    }

    public function send(ChatMessage $message): ChatResponse
    {
        if ($message->getText() === '' && $message->getMediaUrl() === null) {
            throw ChatException::validationError('Facebook posts require text or a link.');
        }

        $payload = $message->getOptions();
        $payload['access_token'] = $this->config['access_token'];
        if ($message->getText() !== '') {
            $payload['message'] = $message->getText();
        }
        if ($message->getMediaUrl() !== null) {
            $payload['link'] = $message->getMediaUrl();
        }

        $version = $this->config['api_version'] ?? 'v22.0';
        return $this->postJson(
            '/' . $version . '/' . rawurlencode($this->config['page_id']) . '/feed',
            $payload
        );
    }
}
