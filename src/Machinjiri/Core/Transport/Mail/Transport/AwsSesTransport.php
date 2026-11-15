<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail\Transport;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions\MailException;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailerInterface;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailResponse;
use PHPMailer\PHPMailer\PHPMailer;

class AwsSesTransport implements MailerInterface
{
    private object $client;
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger, ?object $client = null)
    {
        if (empty($config['region']) || empty($config['from_email'])) {
            throw MailException::TransportException('AWS SES requires region and from_email config.');
        }
        if (isset($config['key']) !== isset($config['secret'])) {
            throw MailException::TransportException('AWS SES config must include both key and secret.');
        }

        $this->config = $config;
        $this->logger = $logger;
        if ($client !== null) {
            $this->client = $client;
        } elseif (class_exists(\Aws\Ses\SesClient::class)) {
            $clientConfig = [
                'version' => $config['version'] ?? 'latest',
                'region' => $config['region'],
            ];
            if (isset($config['credentials'])) {
                if (!is_array($config['credentials'])) {
                    throw MailException::TransportException('AWS SES credentials config must be an array.');
                }
                $clientConfig['credentials'] = $config['credentials'];
            } elseif (isset($config['key'], $config['secret'])) {
                $clientConfig['credentials'] = [
                    'key' => $config['key'],
                    'secret' => $config['secret'],
                ];
                if (isset($config['session_token'])) {
                    $clientConfig['credentials']['token'] = $config['session_token'];
                }
            }
            $this->client = new \Aws\Ses\SesClient($clientConfig);
        } else {
            throw MailException::TransportException(
                'AWS SES requires the aws/aws-sdk-php package.'
            );
        }
    }

    public function send(MailMessage $message): MailResponse
    {
        try {
            $mail = $this->buildMimeMessage($message);
            $result = $this->client->sendRawEmail([
                'RawMessage' => ['Data' => $mail->getSentMIMEMessage()],
                'Destinations' => array_column(
                    array_merge($message->getTo(), $message->getCc(), $message->getBcc()),
                    'email'
                ),
            ]);
            $messageId = (string) ($result['MessageId'] ?? '');
            if ($messageId === '') {
                throw MailException::TransportException('AWS SES response did not include a message ID.');
            }

            $this->logger->info('Email sent via AWS SES', ['message_id' => $messageId]);
            return new MailResponse($messageId, 'aws-ses', $result->toArray());
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('AWS SES email delivery failed', ['error' => $e->getMessage()]);
            throw MailException::TransportException('AWS SES Transport Error: ' . $e->getMessage(), $e);
        }
    }

    private function buildMimeMessage(MailMessage $message): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->setFrom(
            ($message->getFrom()['email'] ?? null) ?: $this->config['from_email'],
            $message->getFrom()['name'] ?? $this->config['from_name'] ?? ''
        );

        foreach ($message->getTo() as $recipient) {
            $mail->addAddress($recipient['email'], $recipient['name'] ?? '');
        }
        foreach ($message->getCc() as $recipient) {
            $mail->addCC($recipient['email'], $recipient['name'] ?? '');
        }
        foreach ($message->getBcc() as $recipient) {
            $mail->addBCC($recipient['email'], $recipient['name'] ?? '');
        }
        $replyTo = $message->getReplyTo();
        if ($replyTo) {
            $mail->addReplyTo($replyTo['email'], $replyTo['name'] ?? '');
        }

        $mail->Subject = $message->getSubject();
        $mail->isHTML($message->getHtmlBody() !== '');
        $mail->Body = $message->getHtmlBody() !== '' ? $message->getHtmlBody() : $message->getTextBody();
        $mail->AltBody = $message->getTextBody();

        foreach ($message->getAttachments() as $attachment) {
            if (!empty($attachment['path'])) {
                $mail->addAttachment(
                    $attachment['path'],
                    $attachment['name'] ?? '',
                    'base64',
                    $attachment['type'] ?? ''
                );
            } elseif (isset($attachment['content'])) {
                $mail->addStringAttachment(
                    $attachment['content'],
                    $attachment['name'],
                    'base64',
                    $attachment['type'] ?? ''
                );
            }
        }
        foreach ($message->getEmbeddedImages() as $image) {
            $mail->addEmbeddedImage($image['path'], $image['cid']);
        }
        foreach ($message->getHeaders() as $name => $value) {
            $mail->addCustomHeader($name, $value);
        }
        $mail->Priority = $message->getPriority();

        $mail->preSend();
        return $mail;
    }
}
