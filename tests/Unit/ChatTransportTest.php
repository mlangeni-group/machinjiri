<?php

namespace Mlangeni\Machinjiri\Tests\Unit;

use Mlangeni\Machinjiri\Core\Exceptions\ChatException;
use Mlangeni\Machinjiri\Core\Http\HttpClient;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatMessage;
use Mlangeni\Machinjiri\Core\Transport\Chat\ChatManager;
use Mlangeni\Machinjiri\Core\Transport\Chat\Transports\InstagramTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\Transports\TikTokTransport;
use Mlangeni\Machinjiri\Core\Transport\Chat\Transports\WhatsAppTransport;
use PHPUnit\Framework\TestCase;

class ChatTransportTest extends TestCase
{
    public function testWhatsAppSendsTextToTheConfiguredPhoneNumberId(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                '/v22.0/12345/messages',
                self::callback(static fn (array $payload): bool =>
                    $payload['to'] === '+265991234567'
                    && $payload['type'] === 'text'
                    && $payload['text']['body'] === 'Hello'
                )
            )
            ->willReturn(['data' => '{"messages":[{"id":"wamid.1"}]}', 'http_code' => 200]);

        $transport = new WhatsAppTransport([
            'phone_number_id' => '12345',
            'access_token' => 'token',
        ], $client);

        $response = $transport->send(
            (new ChatMessage())->recipient('+265991234567')->text('Hello')
        );

        self::assertTrue($response->isSuccess());
        self::assertSame('wamid.1', $response->getMessageId());
    }

    public function testFacebookPublishesToTheConfiguredPageFeed(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                '/v22.0/page-1/feed',
                self::callback(static fn (array $payload): bool =>
                    $payload['message'] === 'Hello'
                    && $payload['access_token'] === 'page-token'
                )
            )
            ->willReturn(['data' => '{"id":"page-1_post-1"}', 'http_code' => 200]);

        $manager = ChatManager::fromConfig([
            'default' => 'fb',
            'transports' => [
                'fb' => [
                    'driver' => 'facebook',
                    'page_id' => 'page-1',
                    'access_token' => 'page-token',
                ],
            ],
        ], $client);

        $response = $manager->send((new ChatMessage())->text('Hello'));

        self::assertTrue($response->isSuccess());
        self::assertSame('page-1_post-1', $response->getMessageId());
    }

    public function testInstagramCreatesAndPublishesMedia(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects(self::exactly(2))
            ->method('post')
            ->willReturnOnConsecutiveCalls(
                ['data' => '{"id":"container-1"}', 'http_code' => 200],
                ['data' => '{"id":"media-1"}', 'http_code' => 200]
            );

        $transport = new InstagramTransport([
            'account_id' => 'account-1',
            'access_token' => 'token',
        ], $client);

        $response = $transport->send(
            (new ChatMessage())->text('Caption')->media('https://cdn.example/image.jpg')
        );

        self::assertTrue($response->isSuccess());
        self::assertSame('media-1', $response->getMessageId());
        self::assertArrayHasKey('container', $response->getRaw());
        self::assertArrayHasKey('publish', $response->getRaw());
    }

    public function testTikTokInitializesAVideoPostUsingUrlPull(): void
    {
        $client = $this->createMock(HttpClient::class);
        $client->expects(self::once())
            ->method('post')
            ->with(
                '/v2/post/publish/video/init/',
                self::callback(static fn (array $payload): bool =>
                    $payload['post_info']['title'] === 'Clip'
                    && $payload['source_info']['source'] === 'PULL_FROM_URL'
                    && $payload['source_info']['video_url'] === 'https://cdn.example/clip.mp4'
                )
            )
            ->willReturn(['data' => '{"data":{"publish_id":"publish-1"},"error":{"code":"ok"}}', 'http_code' => 200]);

        $transport = new TikTokTransport(['access_token' => 'token'], $client);
        $response = $transport->send(
            (new ChatMessage())->text('Clip')->media('https://cdn.example/clip.mp4', 'video')
        );

        self::assertTrue($response->isSuccess());
        self::assertSame('publish-1', $response->getMessageId());
    }

    public function testWhatsAppRequiresARecipient(): void
    {
        $transport = new WhatsAppTransport([
            'phone_number_id' => '12345',
            'access_token' => 'token',
        ], $this->createMock(HttpClient::class));

        $this->expectException(ChatException::class);
        $transport->send((new ChatMessage())->text('Hello'));
    }
}
