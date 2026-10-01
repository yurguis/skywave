<?php

namespace Skywave\Tests\Radio;

use PHPUnit\Framework\TestCase;
use Skywave\Radio\ScanJobs;

/**
 * What the page is told about a scan it cannot see: whether one is running, how far it has
 * got, and why it stopped. All of it is read from files the scan leaves, so the files are
 * what is tested.
 */
class ScanJobsTest extends TestCase
{
    private string $directory;

    private ScanJobs $jobs;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-scan-' . bin2hex(random_bytes(6));
        $this->jobs      = new ScanJobs($this->directory, '/nowhere/radio-scan.php');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        @rmdir($this->directory);
    }

    public function testBeforeAnyScanThereIsNothingToSay(): void
    {
        $this->assertFalse($this->jobs->isRunning());
        $this->assertNull($this->jobs->status());
    }

    public function testAScanIsRunningForAsLongAsItHoldsItsLock(): void
    {
        $lock = $this->jobs->acquire();

        $this->assertNotNull($lock);
        $this->assertTrue($this->jobs->isRunning());
        // A second scan is refused the lock rather than sharing the dongle.
        $this->assertNull($this->jobs->acquire());
        // Running but not yet reported is still running, with nothing found so far.
        $this->assertSame(['running' => true, 'done' => 0, 'total' => 0, 'frequency' => null, 'found' => [], 'error' => null], $this->jobs->status());

        fclose($lock);

        $this->assertFalse($this->jobs->isRunning());
    }

    public function testProgressIsWhatTheScanLastReported(): void
    {
        $lock = $this->jobs->acquire();
        $this->jobs->report(['startedAt' => 100, 'finishedAt' => null, 'total' => 101, 'done' => 13, 'frequency' => 90.5, 'found' => [], 'error' => null]);

        $status = $this->jobs->status();

        $this->assertTrue($status['running']);
        $this->assertSame(13, $status['done']);
        $this->assertSame(90.5, $status['frequency']);
        $this->assertNull($status['error']);

        fclose($lock);
    }

    public function testAScanThatDiedMidwaySaysWhatItLastPrinted(): void
    {
        mkdir($this->directory);
        $this->jobs->report(['startedAt' => 100, 'finishedAt' => null, 'total' => 101, 'done' => 13, 'frequency' => 90.5, 'found' => [], 'error' => null]);
        file_put_contents($this->directory . '/radio-scan.log', "90.5  KUT\nPHP Fatal error: something gave way\n");

        $status = $this->jobs->status();

        $this->assertFalse($status['running']);
        $this->assertSame('PHP Fatal error: something gave way', $status['error']);
    }

    public function testAFinishedScanIsNotMistakenForOneThatDied(): void
    {
        mkdir($this->directory);
        $this->jobs->report(['startedAt' => 100, 'finishedAt' => 700, 'total' => 101, 'done' => 101, 'frequency' => null, 'found' => [], 'error' => null]);
        file_put_contents($this->directory . '/radio-scan.log', "Finished: 101 of 101 frequencies tried, 0 stations found\n");

        $this->assertNull($this->jobs->status()['error']);
    }

    public function testOnlyARunningScanCanBeAskedToStop(): void
    {
        $this->jobs->stop();
        $this->assertFalse($this->jobs->stopRequested());

        $lock = $this->jobs->acquire();
        $this->jobs->stop();
        $this->assertTrue($this->jobs->stopRequested());

        fclose($lock);
    }
}
