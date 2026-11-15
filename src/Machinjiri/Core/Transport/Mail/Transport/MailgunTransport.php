<?php

namespace Mlangeni\Machinjiri\Core\Transport\Mail\Transport;

use Mlangeni\Machinjiri\Core\Artisans\Logging\Logger;
use Mlangeni\Machinjiri\Core\Exceptions\TransportExceptions\MailException;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailerInterface;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailMessage;
use Mlangeni\Machinjiri\Core\Transport\Mail\MailResponse;

class MailgunTransport implements MailerInterface
{
    private object $client;
    private array $config;
    private Logger $logger;

    public function __construct(array $config, Logger $logger, ?object $client = null)
    {
        foreach (['api_key', 'domain', 'from_email'] as $key) {
            if (empty($config[$key])) {
                throw MailException::TransportException("Missing Mailgun config: {$key}");
            }
        }

        $this->config = $config;
        $this->logger = $logger;
        if ($client !== null) {
            $this->client = $client;
        } elseif (class_exists(\Mailgun\Mailgun::class)) {
            $endpoint = $config['endpoint'] ?? null;
            $this->client = \Mailgun\Mailgun::create($config['api_key'], null, $endpoint);
        } else {
            throw MailException::TransportException(
                'Mailgun requires the mailgun/mailgun-php package.'
            );
        }
    }

    public function send(MailMessage $message): MailResponse
    {
        $temporaryDirectory = null;
        try {
            $from = $message->getFrom() ?: [
                'email' => $this->config['from_email'],
                'name' => $this->config['from_name'] ?? null,
            ];
            $data = [
                'from' => $this->formatAddress($from),
                'to' => implode(', ', $this->formatAddresses($message->getTo())),
                'subject' => $message->getSubject(),
            ];
            if ($message->getCc()) {
                $data['cc'] = implode(', ', $this->formatAddresses($message->getCc()));
            }
            if ($message->getBcc()) {
                $data['bcc'] = implode(', ', $this->formatAddresses($message->getBcc()));
            }
            if ($message->getReplyTo()) {
                $data['h:Reply-To'] = $this->formatAddress($message->getReplyTo());
            }
            if ($message->getTextBody() !== '') {
                $data['text'] = $message->getTextBody();
            }
            if ($message->getHtmlBody() !== '') {
                $data['html'] = $message->getHtmlBody();
            }
            foreach ($message->getHeaders() as $name => $value) {
                $data["h:{$name}"] = $value;
            }
            $data['h:X-Priority'] = (string) $message->getPriority();

            $files = [];
            foreach ($message->getAttachments() as $attachment) {
                if (!empty($attachment['path'])) {
                    $files['attachment'][] = $attachment['path'];
                } elseif (isset($attachment['content'])) {
                    $files['attachment'][] = $this->writeTemporaryFile(
                        $attachment['content'],
                        $attachment['name'],
                        $temporaryDirectory
                    );
                }
            }
            foreach ($message->getEmbeddedImages() as $image) {
                $files['inline'][] = $this->writeTemporaryFile(
                    $this->readFile($image['path']),
                    basename($image['cid']),
                    $temporaryDirectory
                );
            }

            $this->logger->info('Sending email via Mailgun', [
                'subject' => $message->getSubject(),
                'to' => $message->getTo(),
            ]);

            $response = $this->client->messages()->send($this->config['domain'], $data, $files);
            $messageId = method_exists($response, 'getId') ? (string) $response->getId() : '';
            if ($messageId === '') {
                throw MailException::TransportException('Mailgun response did not include a message ID.');
            }
            $rawResponse = method_exists($response, 'getMessage') ? (string) $response->getMessage() : null;

            return new MailResponse($messageId, 'mailgun', [], $rawResponse);
        } catch (MailException $e) {
            throw $e;
        } catch (\Throwable $e) {
            $this->logger->error('Mailgun email delivery failed', ['error' => $e->getMessage()]);
            throw MailException::TransportException('Mailgun Transport Error: ' . $e->getMessage(), $e);
        } finally {
            $this->removeTemporaryFiles($temporaryDirectory);
        }
    }

    private function formatAddresses(array $recipients): array
    {
        return array_map(fn (array $recipient): string => $this->formatAddress($recipient), $recipients);
    }

    private function formatAddress(array $recipient): string
    {
        $name = trim((string) ($recipient['name'] ?? ''));
        return $name === '' ? $recipient['email'] : "{$name} <{$recipient['email']}>";
    }

    private function writeTemporaryFile(string $content, string $filename, ?string &$directory): string
    {
        if ($directory === null) {
            $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'machinjiri-mail-' . bin2hex(random_bytes(16));
            if (!mkdir($directory, 0700)) {
                throw MailException::TransportException('Unable to create temporary mail attachment directory.');
            }
        }

        $filename = basename($filename);
        if ($filename === '' || $filename === '.' || $filename === '..') {
            $filename = 'attachment';
        }
        $path = $directory . DIRECTORY_SEPARATOR . $filename;
        if (file_put_contents($path, $content) === false) {
            throw MailException::TransportException("Unable to create temporary mail attachment: {$filename}");
        }

        return $path;
    }

    private function readFile(string $path): string
    {
        $content = file_get_contents($path);
        if ($content === false) {
            throw MailException::TransportException("Unable to read mail attachment: {$path}");
        }

        return $content;
    }

    private function removeTemporaryFiles(?string $directory): void
    {
        if ($directory === null) {
            return;
        }

        $files = scandir($directory);
        if ($files === false) {
            $this->logger->warning('Unable to list temporary Mailgun attachments for cleanup', [
                'directory' => $directory,
            ]);
            return;
        }
        foreach (array_diff($files, ['.', '..']) as $file) {
            if (!unlink($directory . DIRECTORY_SEPARATOR . $file)) {
                $this->logger->warning('Unable to remove temporary Mailgun attachment', ['file' => $file]);
            }
        }
        if (!rmdir($directory)) {
            $this->logger->warning('Unable to remove temporary Mailgun attachment directory', [
                'directory' => $directory,
            ]);
        }
    }
}
