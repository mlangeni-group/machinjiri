<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail\Transport;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as PHPMailerException;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailerInterface;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailResponse;
use Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions\MailException;
use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;

class PhpMailerTransport implements MailerInterface
{
    private PHPMailer $mailer;
    private Logger $logger;
    private array $config;
    private int $maxRetries;

    public function __construct(array $config, Logger $logger, int $maxRetries = 3)
    {
        $this->validateConfig($config);
        $this->config = $config;
        $this->logger = $logger;
        $this->maxRetries = $maxRetries;
        $this->mailer = new PHPMailer(true);
        $this->configure();
    }

    private function validateConfig(array $config): void
    {
        $required = ['host', 'username', 'password', 'port', 'encryption', 'from_email'];
        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw MailException::TransportException("Missing PHPMailer config: {$key}");
            }
        }
    }

    private function configure(): void
    {
        $this->mailer->isSMTP();
        $this->mailer->Host = $this->config['host'];
        $this->mailer->SMTPAuth = true;
        $this->mailer->Username = $this->config['username'];
        $this->mailer->Password = $this->config['password'];
        $this->mailer->SMTPSecure = $this->config['encryption'];
        $this->mailer->Port = (int) $this->config['port'];
        $this->mailer->CharSet = 'UTF-8';
        $this->mailer->setFrom(
            $this->config['from_email'],
            $this->config['from_name'] ?? ''
        );
        $this->mailer->SMTPDebug = $this->config['debug'] ?? false;
    }

    public function send(MailMessage $message): MailResponse
    {
        $this->resetMailer();

        // From (override default if provided)
        $from = $message->getFrom();
        if (!empty($from)) {
            $this->mailer->setFrom($from['email'], $from['name'] ?? '');
        }

        // To
        foreach ($message->getTo() as $to) {
            $this->mailer->addAddress($to['email'], $to['name'] ?? '');
        }

        // CC
        foreach ($message->getCc() as $cc) {
            $this->mailer->addCC($cc['email'], $cc['name'] ?? '');
        }

        // BCC
        foreach ($message->getBcc() as $bcc) {
            $this->mailer->addBCC($bcc['email'], $bcc['name'] ?? '');
        }

        // Reply-To
        $replyTo = $message->getReplyTo();
        if (!empty($replyTo)) {
            $this->mailer->addReplyTo($replyTo['email'], $replyTo['name'] ?? '');
        }

        // Subject & Body
        $this->mailer->Subject = $message->getSubject();
        $this->mailer->isHTML(true);
        $this->mailer->Body = $message->getHtmlBody();
        $this->mailer->AltBody = $message->getTextBody() ?: strip_tags($message->getHtmlBody());

        // Attachments
        foreach ($message->getAttachments() as $attachment) {
            if ($attachment['path']) {
                $this->mailer->addAttachment($attachment['path'], $attachment['name'], 'base64', $attachment['type'] ?? '');
            } elseif ($attachment['content']) {
                $this->mailer->addStringAttachment($attachment['content'], $attachment['name'], 'base64', $attachment['type'] ?? '');
            }
        }

        // Embedded images
        foreach ($message->getEmbeddedImages() as $image) {
            $this->mailer->addEmbeddedImage($image['path'], $image['cid']);
        }

        // Custom headers
        foreach ($message->getHeaders() as $key => $value) {
            $this->mailer->addCustomHeader($key, $value);
        }

        // Priority
        $this->mailer->Priority = $message->getPriority();

        $this->logger->info('Sending email via PHPMailer', [
            'subject' => $message->getSubject(),
            'to' => $message->getTo()
        ]);

        $attempt = 0;
        $lastException = null;
        while ($attempt < $this->maxRetries) {
            try {
                $this->mailer->send();
                $messageId = $this->mailer->getLastMessageID() ?: 'generated-id';
                $this->logger->info('Email sent via PHPMailer', ['message_id' => $messageId]);
                return new MailResponse($messageId, 'phpmailer', ['attempt' => $attempt + 1]);
            } catch (PHPMailerException $e) {
                $lastException = $e;
                $attempt++;
                if ($attempt < $this->maxRetries) {
                    $delay = 1 * (2 ** ($attempt - 1));
                    $this->logger->warning("PHPMailer attempt {$attempt} failed, retrying in {$delay}s", [
                        'error' => $e->getMessage()
                    ]);
                    sleep($delay);
                }
            }
        }

        throw MailException::TransportException('PHPMailer Transport Error: ' . ($lastException?->getMessage() ?? 'Unknown error'), $lastException);
    }

    private function resetMailer(): void
    {
        $this->mailer->clearAddresses();
        $this->mailer->clearCCs();
        $this->mailer->clearBCCs();
        $this->mailer->clearAttachments();
        $this->mailer->clearReplyTos();
        $this->mailer->clearCustomHeaders();
        $this->mailer->Priority = 3;
    }
}