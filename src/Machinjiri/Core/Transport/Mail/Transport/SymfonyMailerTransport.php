<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail\Transport;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions\MailException;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailerInterface;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailResponse;

class SymfonyMailerTransport implements MailerInterface
{
    private object $mailer;
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger, ?object $mailer = null)
    {
        if ($mailer === null && empty($config['dsn'])) {
            if (empty($config['username']) && empty($config['password']) && empty($config['host'])) {
                throw MailException::TransportException('Missing Symfony Mailer config: dsn');
            }
        }

        $this->config = $config;
        $this->logger = $logger;
        if ($mailer !== null) {
            $this->mailer = $mailer;
        } elseif (
            class_exists(\Symfony\Component\Mailer\Mailer::class)
            && class_exists(\Symfony\Component\Mailer\Transport::class)
        ) {
            $transport = \Symfony\Component\Mailer\Transport::fromDsn($config['dsn'] ?? $this->buildDsn());
            $this->mailer = new \Symfony\Component\Mailer\Mailer($transport);
        } else {
            throw MailException::TransportException(
                'Symfony Mailer requires the symfony/mailer package.'
            );
        }
    }

    public function send(MailMessage $message): MailResponse
    {
        try {
            $email = new \Symfony\Component\Mime\Email();
            $from = $message->getFrom() ?: [
                'email' => $this->config['from_email'] ?? '',
                'name' => $this->config['from_name'] ?? null,
            ];
            if ($from['email'] === '') {
                throw MailException::TransportException('Symfony Mailer requires a from address.');
            }
            $email->from(new \Symfony\Component\Mime\Address($from['email'], $from['name'] ?? ''));

            foreach ($message->getTo() as $recipient) {
                $email->addTo(new \Symfony\Component\Mime\Address($recipient['email'], $recipient['name'] ?? ''));
            }
            foreach ($message->getCc() as $recipient) {
                $email->addCc(new \Symfony\Component\Mime\Address($recipient['email'], $recipient['name'] ?? ''));
            }
            foreach ($message->getBcc() as $recipient) {
                $email->addBcc(new \Symfony\Component\Mime\Address($recipient['email'], $recipient['name'] ?? ''));
            }
            $replyTo = $message->getReplyTo();
            if ($replyTo) {
                $email->replyTo(new \Symfony\Component\Mime\Address($replyTo['email'], $replyTo['name'] ?? ''));
            }

            $email->subject($message->getSubject());
            if ($message->getTextBody() !== '') {
                $email->text($message->getTextBody());
            }
            if ($message->getHtmlBody() !== '') {
                $email->html($message->getHtmlBody());
            }
            foreach ($message->getAttachments() as $attachment) {
                if (!empty($attachment['path'])) {
                    $email->attachFromPath(
                        $attachment['path'],
                        $attachment['name'] ?? null,
                        $attachment['type'] ?? null
                    );
                } elseif (isset($attachment['content'])) {
                    $email->attach(
                        $attachment['content'],
                        $attachment['name'],
                        $attachment['type'] ?? null
                    );
                }
            }
            foreach ($message->getEmbeddedImages() as $image) {
                $email->embedFromPath($image['path'], $image['cid']);
            }
            foreach ($message->getHeaders() as $name => $value) {
                $email->getHeaders()->addTextHeader($name, $value);
            }
            $email->priority($message->getPriority());
            $messageId = bin2hex(random_bytes(16)) . '@' . (gethostname() ?: 'localhost');
            $email->getHeaders()->addIdHeader('Message-ID', $messageId);

            $this->mailer->send($email);
            $this->logger->info('Email sent via Symfony Mailer', ['message_id' => $messageId]);

            return new MailResponse($messageId, 'symfony-mailer');
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Symfony Mailer email delivery failed', ['error' => $e->getMessage()]);
            throw MailException::TransportException('Symfony Mailer Transport Error: ' . $e->getMessage(), $e);
        }
    }

    private function buildDsn(): string 
    {
        $cfg = $this->config;
        $protocol       = $cfg['protocol'] ?? 'smtp';
        $username       = $cfg['username'] ?? '';
        $password       = $cfg['password'] ?? '';
        $port           = $cfg['port'] ?? 25;
        $host           = $cfg['host'] ?? '';

        return $protocol . "://" . $username . ":" . $password . "@" . $host . ":" . $port;
    }
}
