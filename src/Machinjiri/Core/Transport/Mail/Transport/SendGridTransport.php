<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail\Transport;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions\MailException;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailerInterface;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailResponse;

class SendGridTransport implements MailerInterface
{
    private object $client;
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger, ?object $client = null)
    {
        $this->validateConfig($config);
        $this->config = $config;
        $this->logger = $logger;

        if ($client !== null) {
            $this->client = $client;
        } elseif (class_exists(\SendGrid::class) && class_exists(\SendGrid\Mail\Mail::class)) {
            $this->client = new \SendGrid($config['api_key']);
        } else {
            throw MailException::TransportException(
                'SendGrid requires the sendgrid/sendgrid package.'
            );
        }
    }

    private function validateConfig(array $config): void
    {
        foreach (['api_key', 'from_email'] as $key) {
            if (empty($config[$key])) {
                throw MailException::TransportException("Missing SendGrid config: {$key}");
            }
        }
    }

    public function send(MailMessage $message): MailResponse
    {
        try {
            $email = new \SendGrid\Mail\Mail();

            $from = $message->getFrom() ?: [
                'email' => $this->config['from_email'],
                'name' => $this->config['from_name'] ?? null,
            ];
            $email->setFrom($from['email'], $from['name'] ?? null);

            foreach ($message->getTo() as $recipient) {
                $email->addTo($recipient['email'], $recipient['name'] ?? null);
            }
            foreach ($message->getCc() as $recipient) {
                $email->addCc($recipient['email'], $recipient['name'] ?? null);
            }
            foreach ($message->getBcc() as $recipient) {
                $email->addBcc($recipient['email'], $recipient['name'] ?? null);
            }

            $replyTo = $message->getReplyTo();
            if ($replyTo) {
                $email->setReplyTo($replyTo['email'], $replyTo['name'] ?? null);
            }

            $email->setSubject($message->getSubject());
            if ($message->getTextBody() !== '') {
                $email->addContent('text/plain', $message->getTextBody());
            }
            if ($message->getHtmlBody() !== '') {
                $email->addContent('text/html', $message->getHtmlBody());
            }

            foreach ($message->getAttachments() as $attachment) {
                $content = $this->attachmentContent($attachment);
                $email->addAttachment(
                    base64_encode($content),
                    $attachment['type'] ?? 'application/octet-stream',
                    $attachment['name'] ?? basename($attachment['path']),
                    'attachment'
                );
            }
            foreach ($message->getEmbeddedImages() as $image) {
                $email->addAttachment(
                    base64_encode($this->readFile($image['path'])),
                    mime_content_type($image['path']) ?: 'application/octet-stream',
                    basename($image['path']),
                    'inline',
                    $image['cid']
                );
            }

            foreach ($message->getHeaders() as $name => $value) {
                $email->addHeader($name, $value);
            }
            $email->addHeader('X-Priority', (string) $message->getPriority());

            $this->logger->info('Sending email via SendGrid', [
                'subject' => $message->getSubject(),
                'to' => $message->getTo(),
            ]);

            $response = $this->client->send($email);
            
            $statusCode = $response->statusCode();
            $rawResponse = $response->body();
            if ($statusCode < 200 || $statusCode >= 300) {
                throw MailException::TransportException(
                    "SendGrid returned HTTP {$statusCode}: {$rawResponse}"
                );
            }

            $headers = $response->headers();
            $messageId = '';
            foreach ($headers as $name => $value) {
                if (strcasecmp((string) $name, 'X-Message-Id') === 0) {
                    $messageId = is_array($value) ? (string) reset($value) : (string) $value;
                    break;
                }
            }
            if ($messageId === '') {
                throw MailException::TransportException('SendGrid response did not include a message ID.');
            }

            return new MailResponse($messageId, 'sendgrid', ['status_code' => $statusCode], $rawResponse);
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('SendGrid email delivery failed', ['error' => $e->getMessage()]);
            throw MailException::TransportException('SendGrid Transport Error: ' . $e->getMessage(), $e);
        }
    }

    private function attachmentContent(array $attachment): string
    {
        if (isset($attachment['path']) && $attachment['path'] !== null) {
            return $this->readFile($attachment['path']);
        }

        return $attachment['content'] ?? '';
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw MailException::TransportException("Unable to read mail attachment: {$path}");
        }

        return $content;
    }
}
