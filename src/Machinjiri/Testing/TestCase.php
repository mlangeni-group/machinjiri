<?php

namespace Mlangeni\Machinjiri\Testing;

use PHPUnit\Framework\TestCase as BaseTestCase;
use Mlangeni\Machinjiri\Core\Container;
use Mlangeni\Machinjiri\Core\Machinjiri;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithApplication;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithAuthentication;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithConsole;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithContainer;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithCoverage;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithDatabase;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithEvents;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithException;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithFactories;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithHttp;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithMail;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithMocks;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithQueue;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithSession;
use Mlangeni\Machinjiri\Testing\Concerns\InteractsWithTime;
use Mlangeni\Machinjiri\Testing\Concerns\SnapshotAssertions;
use Mlangeni\Machinjiri\Testing\Traits\RefreshDatabase;
use Mlangeni\Machinjiri\Testing\Traits\WithFaker;
use Mlangeni\Machinjiri\Testing\Traits\WithoutMiddleware;

abstract class TestCase extends BaseTestCase
{
    use InteractsWithApplication,
        InteractsWithContainer,
        InteractsWithHttp,
        InteractsWithDatabase,
        InteractsWithSession,
        InteractsWithAuthentication,
        InteractsWithConsole,
        InteractsWithException,
        InteractsWithCoverage,
        InteractsWithMail,
        InteractsWithQueue,
        InteractsWithEvents,
        InteractsWithMocks,
        InteractsWithFactories,
        SnapshotAssertions,
        InteractsWithTime,
        RefreshDatabase,
        WithoutMiddleware,
        WithFaker {
            InteractsWithDatabase::setUpDatabase as baseSetUpDatabase;
            RefreshDatabase::setUpDatabase as refreshDatabaseSetUp;
        }

    protected function setUpDatabase(): void
    {
        $this->baseSetUpDatabase();

        if (method_exists($this, 'beginDatabaseTransaction')) {
            $this->beginDatabaseTransaction();
        }
    }

    /**
     * The application instance.
     */
    protected ?Machinjiri $app = null;

    /**
     * Set up the test environment.
     */
    protected function setUp(): void
    {
        parent::setUp();

        // Start coverage if Xdebug is enabled
        if ($this->shouldCollectCoverage()) {
            $this->startCoverage();
        }

        $this->setUpApplication();
        $this->setUpWithoutMiddleware();
        $this->setUpFaker();
        $this->setUpDatabase();
        $this->setUpSession();
        $this->setUpMailFake();
        $this->setUpQueueFake();
        $this->setUpEventFake();
    }

    /**
     * Boot the application for testing.
     */
    protected function setUpApplication(): void
    {
        $basePath = dirname(__DIR__, 3); // Project root

        $requiredPaths = [
            $basePath . '/config',
            $basePath . '/routes',
            $basePath . '/storage',
        ];

        if (count(array_filter($requiredPaths, 'is_dir')) !== count($requiredPaths)) {
            return;
        }

        $envPath = $basePath . '/.env';
        if (!is_file($envPath)) {
            file_put_contents($envPath, "APP_NAME=Machinjiri\nAPP_ENV=testing\nAPP_DEBUG=true\nAPP_KEY=local-testing-key\nDB_CONNECTION=sqlite\nDB_DATABASE=:memory:\nMAIL_MAILER=array\nSESSION_DRIVER=array\nSESSION_LIFETIME=120\nCSRF_TOKEN_NAME=csrf_token\n");
        }

        $this->app = Machinjiri::App($basePath . '/src', true);
        $this->app->initialize();

        // Bind test-specific services
        Container::setInstance($this->app);
    }

    /**
     * Refresh the entire application (for repeated runs in same process).
     */
    protected function refreshApplication(): void
    {
        $this->tearDown();
        $this->setUp();
    }

    /**
     * Tear down after each test.
     */
    protected function tearDown(): void
    {
        $this->tearDownDatabase();
        $this->tearDownMailFake();
        $this->tearDownQueueFake();
        $this->tearDownEventFake();

        // Stop coverage and report if needed
        if ($this->shouldCollectCoverage()) {
            $this->stopCoverage();
        }

        parent::tearDown();
    }

    /**
     * Determine if coverage should be collected.
     */
    protected function shouldCollectCoverage(): bool
    {
        return function_exists('xdebug_start_code_coverage') 
            && getenv('COLLECT_COVERAGE') !== 'false';
    }

    /**
     * Get the application instance.
     */
    public function app(): ?Machinjiri
    {
        return $this->app;
    }
}