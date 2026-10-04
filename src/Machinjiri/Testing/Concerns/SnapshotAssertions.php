<?php

namespace Mlangeni\Machinjiri\Testing\Concerns;

trait SnapshotAssertions
{
    /**
     * Assert that a value matches a snapshot.
     */
    protected function assertMatchesSnapshot($value, ?string $snapshotName = null): void
    {
        $snapshotDir = __DIR__ . '/../../../tests/__snapshots__';
        if (!is_dir($snapshotDir)) {
            mkdir($snapshotDir, 0777, true);
        }
        $testName = $snapshotName ?? $this->getName(false);
        $snapshotFile = $snapshotDir . '/' . str_replace('\\', '_', get_class($this)) . '__' . $testName . '.snap';

        $encoded = json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (!file_exists($snapshotFile)) {
            file_put_contents($snapshotFile, $encoded);
            $this->markTestIncomplete('Snapshot created. Run again to verify.');
        } else {
            $expected = file_get_contents($snapshotFile);
            $this->assertJsonStringEqualsJsonString($expected, $encoded);
        }
    }
}