<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait InteractsWithQueue
{
    private array $queueFakeJobs = [];

    protected function setUpQueueFake(): void
    {
        $this->queueFakeJobs = [];
        // Bind fake queue driver
        $this->bind('queue.connection', function () {
            return new class {
                public function push($job, $data = [], $queue = null)
                {
                    $GLOBALS['__queue_fake_jobs'][] = ['job' => $job, 'data' => $data, 'queue' => $queue];
                }
            };
        });
    }

    protected function assertJobPushed(string $jobClass, ?callable $callback = null): void
    {
        $jobs = $GLOBALS['__queue_fake_jobs'] ?? [];
        $found = false;
        foreach ($jobs as $job) {
            if ($job['job'] === $jobClass) {
                if (!$callback || $callback($job['data'])) {
                    $found = true;
                    break;
                }
            }
        }
        $this->assertTrue($found, "Job {$jobClass} was not pushed.");
    }

    protected function tearDownQueueFake(): void
    {
        unset($GLOBALS['__queue_fake_jobs']);
    }
}