<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

use Mlangeni\Machinjiri\Core\Mail\Mailer;

trait InteractsWithMail
{
    private array $mailFakeMessages = [];
    private bool $mailFakeEnabled = false;

    /**
     * Fake mail sending (no real emails sent).
     */
    protected function setUpMailFake(): void
    {
        $this->mailFakeEnabled = true;
        $this->mailFakeMessages = [];
        $this->bind(Mailer::class, $this->getMailFake());
    }

    /**
     * Get a fake mailer instance.
     */
    protected function getMailFake(): Mailer
    {
        return new class extends Mailer {
            public function send(array $to, string $subject, string $htmlContent, ?string $textContent = null): string
            {
                $GLOBALS['__mail_fake_messages'][] = [
                    'to' => $to,
                    'subject' => $subject,
                    'html' => $htmlContent,
                    'text' => $textContent,
                    'timestamp' => time()
                ];
                return 'fake-message-id';
            }
        };
    }

    /**
     * Assert that a mail was sent.
     */
    protected function assertMailSent(?callable $callback = null): void
    {
        $messages = $GLOBALS['__mail_fake_messages'] ?? [];
        $this->assertNotEmpty($messages, 'No mail was sent.');

        if ($callback) {
            $found = false;
            foreach ($messages as $msg) {
                if ($callback($msg)) {
                    $found = true;
                    break;
                }
            }
            $this->assertTrue($found, 'No mail matched the given criteria.');
        }
    }

    /**
     * Assert a specific number of mails were sent.
     */
    protected function assertMailCount(int $expectedCount): void
    {
        $count = count($GLOBALS['__mail_fake_messages'] ?? []);
        $this->assertEquals($expectedCount, $count, "Expected {$expectedCount} mails, got {$count}.");
    }

    protected function tearDownMailFake(): void
    {
        unset($GLOBALS['__mail_fake_messages']);
        $this->mailFakeEnabled = false;
    }
}