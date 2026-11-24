<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat\Transports;

use Mlangeni\Machinjiri\Core\Exceptions\ChatException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Transport\Chat\AbstractChatTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatMessage;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatResponse;

class InstagramTransport extends AbstractChatTransport
{
    public function __construct(array $config, ?HttpClient $httpClient = null)
    {
        parent::__construct($config, $httpClient);
        $this->requireConfig('account_id', 'access_token');
    }

    public function getName(): string
    {
        return 'instagram';
    }

    protected function defaultApiBase(): string
    {
        return 'https://graph.facebook.com';
    }

    public function send(ChatMessage $message): ChatResponse
    {
        $mediaUrl = $message->getMediaUrl();
        if ($mediaUrl === null || !in_array($message->getMediaType(), ['image', 'video'], true)) {
            throw ChatException::validationError('Instagram posts require an image or video URL.');
        }

        $version = $this->config['api_version'] ?? 'v22.0';
        $accountId = rawurlencode($this->config['account_id']);
        $accessToken = $this->config['access_token'];
        $containerPayload = [
            'access_token' => $accessToken,
            'caption' => $message->getText(),
        ];
        if ($message->getMediaType() === 'video') {
            $containerPayload['media_type'] = 'REELS';
            $containerPayload['video_url'] = $mediaUrl;
        } else {
            $containerPayload['image_url'] = $mediaUrl;
        }

        $container = $this->postJson(
            '/' . $version . '/' . $accountId . '/media',
            $containerPayload
        );
        if (!$container->isSuccess()) {
            return $container;
        }

        $containerId = $container->getMessageId();
        if ($containerId === null) {
            return new ChatResponse(
                false,
                null,
                'Instagram did not return a media container ID.',
                ['container' => $container->getRaw()]
            );
        }

        $published = $this->postJson(
            '/' . $version . '/' . $accountId . '/media_publish',
            [
                'access_token' => $accessToken,
                'creation_id' => $containerId,
            ]
        );

        return new ChatResponse(
            $published->isSuccess(),
            $published->getMessageId(),
            $published->getError(),
            [
                'container' => $container->getRaw(),
                'publish' => $published->getRaw(),
            ]
        );
    }
}
