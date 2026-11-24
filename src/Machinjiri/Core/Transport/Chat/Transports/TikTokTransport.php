<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat\Transports;

use Mlangeni\Machinjiri\Core\Exceptions\ChatException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Transport\Chat\AbstractChatTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatMessage;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatResponse;

class TikTokTransport extends AbstractChatTransport
{
    public function __construct(array $config, ?HttpClient $httpClient = null)
    {
        parent::__construct($config, $httpClient);
        $this->requireConfig('access_token');
    }

    public function getName(): string
    {
        return 'tiktok';
    }

    protected function defaultApiBase(): string
    {
        return 'https://open.tiktokapis.com';
    }

    public function send(ChatMessage $message): ChatResponse
    {
        if ($message->getMediaUrl() === null || $message->getMediaType() !== 'video') {
            throw ChatException::validationError('TikTok posts require a video URL.');
        }

        $options = $message->getOptions();
        $this->httpClient->setHeaders([
            'Authorization' => 'Bearer ' . $this->config['access_token'],
            'Accept' => 'application/json',
        ]);

        return $this->postJson('/v2/post/publish/video/init/', [
            'post_info' => [
                'title' => $options['title'] ?? $message->getText(),
                'privacy_level' => $options['privacy_level'] ?? 'PUBLIC_TO_EVERYONE',
                'disable_duet' => (bool) ($options['disable_duet'] ?? false),
                'disable_comment' => (bool) ($options['disable_comment'] ?? false),
                'disable_stitch' => (bool) ($options['disable_stitch'] ?? false),
            ],
            'source_info' => [
                'source' => 'PULL_FROM_URL',
                'video_url' => $message->getMediaUrl(),
            ],
        ]);
    }
}
