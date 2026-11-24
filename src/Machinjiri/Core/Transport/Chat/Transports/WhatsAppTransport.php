<?php

namespace Mlangeni\Machinjiri\Core\Transport\Chat\Transports;

use Mlangeni\Machinjiri\Core\Exceptions\ChatException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Transport\Chat\AbstractChatTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatMessage;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatResponse;

class WhatsAppTransport extends AbstractChatTransport
{
    public function __construct(array $config, ?HttpClient $httpClient = null)
    {
        parent::__construct($config, $httpClient);
        $this->requireConfig('phone_number_id', 'access_token');
    }

    public function getName(): string
    {
        return 'whatsapp';
    }

    protected function defaultApiBase(): string
    {
        return 'https://graph.facebook.com';
    }

    public function send(ChatMessage $message): ChatResponse
    {
        if ($message->getRecipient() === null || $message->getRecipient() === '') {
            throw ChatException::validationError('WhatsApp messages require a recipient phone number.');
        }
        if ($message->getMediaUrl() === null && $message->getText() === '') {
            throw ChatException::validationError('WhatsApp messages require text or media.');
        }

        $payload = [
            'messaging_product' => 'whatsapp',
            'recipient_type' => 'individual',
            'to' => $message->getRecipient(),
        ];

        if ($message->getMediaUrl() !== null) {
            $type = $message->getMediaType();
            if (!in_array($type, ['image', 'video', 'audio', 'document'], true)) {
                throw ChatException::validationError("Unsupported WhatsApp media type: {$type}");
            }
            $payload['type'] = $type;
            $payload[$type] = ['link' => $message->getMediaUrl()];
            if ($message->getText() !== '' && in_array($type, ['image', 'video', 'document'], true)) {
                $payload[$type]['caption'] = $message->getText();
            }
        } else {
            $payload['type'] = 'text';
            $payload['text'] = [
                'body' => $message->getText(),
                'preview_url' => (bool) ($message->getOptions()['preview_url'] ?? false),
            ];
        }

        $this->httpClient->setHeaders([
            'Authorization' => 'Bearer ' . $this->config['access_token'],
            'Accept' => 'application/json',
        ]);
        $version = $this->config['api_version'] ?? 'v22.0';

        return $this->postJson(
            '/' . $version . '/' . rawurlencode($this->config['phone_number_id']) . '/messages',
            $payload
        );
    }
}
