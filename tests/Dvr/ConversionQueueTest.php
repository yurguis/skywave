<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\Recorder;
use Skywave\Dvr\RecordingStore;
use Skywave\Dvr\TunerReservations;

/**
 * How many recordings are converted at once.
 *
 * One. A conversion already spreads itself across the cores, so a second beside it makes
 * both take twice as long instead of getting more done, and both compete with whatever is
 * being recorded. Several "both" recordings ending in the same minute is the ordinary way
 * this happens, and it used to start a conversion for every one of them.
 */
class ConversionQueueTest extends TestCase
{
    private string $directory;

    private RecordingStore $store;

    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-convqueue-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);

        $this->store    = new RecordingStore($this->directory . '/guide.sqlite');
        $this->recorder = new Recorder($this->store, new TunerReservations($this->directory), $this->directory);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testATickStartsOnlyOneOfThem(): void
    {
        // None of the broadcasts are on disk, so a conversion that is reached gives up
        // straight away and says so. Counting those counts how many were tried, without
        // starting an encoder from a test.
        $this->addWaiting('A Show.ts');
        $this->addWaiting('B Show.ts');
        $this->addWaiting('C Show.ts');

        $this->recorder->tick();

        $this->assertSame(1, $this->attempted(), 'one tick, one conversion');
    }

    public function testTheOthersAreNotForgotten(): void
    {
        $this->addWaiting('A Show.ts');
        $this->addWaiting('B Show.ts');

        $this->recorder->tick();
        $this->recorder->tick();

        // Queued rather than dropped: the second one is picked up on a later tick.
        $this->assertSame(2, $this->attempted());
    }

    public function testNothingStartsWhileOneIsStillConverting(): void
    {
        // This process is certainly running, so it stands in for a conversion under way.
        $busy = $this->addWaiting('A Show.ts');
        $this->store->updateRecording($busy, ['convertPid' => getmypid()]);

        $this->addWaiting('B Show.ts');

        $this->recorder->tick();

        $this->assertSame(0, $this->attempted(), 'the machine is already busy');
    }

    /** How many recordings have been through startConversion. */
    private function attempted(): int
    {
        $tried = 0;

        foreach ($this->store->getRecordings() as $recording) {
            if ($recording['convertError'] !== null) {
                $tried++;
            }
        }

        return $tried;
    }

    private function addWaiting(string $path): int
    {
        $id = $this->store->addRecording([
            'scheduleId'  => null,
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => $path,
            'description' => null,
            'path'        => $path,
            'format'      => 'both',
            'tuner'       => 0,
            'pid'         => 1234,
            'startedAt'   => time() - 3600,
            'stopsAt'     => time() - 60,
            'reservation' => null,
        ]);

        $this->store->updateRecording($id, [
            'status'  => RecordingStore::STATUS_DONE,
            'bytes'   => 1_000_000,
            'endedAt' => time() - 60,
        ]);

        return $id;
    }
}
