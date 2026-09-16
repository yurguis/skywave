<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingStore;

/**
 * What the recorder acts on every ten seconds: which schedules are due, which were missed
 * while it was not running, and what happens when the same showing is asked for twice.
 */
class RecordingStoreTest extends TestCase
{
    private string $directory;

    private RecordingStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-recordings-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
        $this->store = new RecordingStore($this->directory . '/guide.sqlite');
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testAScheduleComesBackAsItWentIn(): void
    {
        $id = $this->store->addSchedule($this->schedule(['title' => 'Jeopardy!']));

        $schedule = $this->store->getSchedule($id);

        $this->assertNotNull($schedule);
        $this->assertSame('Jeopardy!', $schedule['title']);
        $this->assertSame('192.168.1.62', $schedule['device']);
        $this->assertSame(29, $schedule['physical']);
        $this->assertSame('6.1', $schedule['virtual']);
        $this->assertSame(RecordingStore::STATUS_SCHEDULED, $schedule['status']);
    }

    public function testAskingForTheSameShowingTwiceSchedulesItOnce(): void
    {
        // The guide's Record button can be pressed again, from another browser or another
        // tab, and must not record the same programme twice.
        $first  = $this->store->addSchedule($this->schedule());
        $second = $this->store->addSchedule($this->schedule());

        $this->assertSame($first, $second);
        $this->assertCount(1, $this->store->getSchedules());
    }

    public function testAnUnknownFormatFallsBackToTheBroadcastAsSent(): void
    {
        $id = $this->store->addSchedule($this->schedule(['format' => 'wmv']));

        $this->assertSame('ts', $this->store->getSchedule($id)['format']);
    }

    public function testPaddingBringsAScheduleForward(): void
    {
        $now = time();

        // Starts in five minutes, but ten minutes of padding means recording starts now.
        $this->store->addSchedule($this->schedule(['start' => $now + 300, 'padStart' => 600]));

        $this->assertCount(1, $this->store->getDueSchedules($now));
    }

    public function testAScheduleIsNotDueBeforeItsPaddedStart(): void
    {
        $now = time();

        $this->store->addSchedule($this->schedule(['start' => $now + 300, 'padStart' => 60]));

        $this->assertSame([], $this->store->getDueSchedules($now));
    }

    public function testAProgrammeThatHasFinishedIsMissedRatherThanDue(): void
    {
        $now = time();

        // Aired an hour ago and never recorded: the recorder was not running.
        $this->store->addSchedule($this->schedule([
            'start'    => $now - 7200,
            'duration' => 1800,
            'padEnd'   => 0,
        ]));

        $this->assertSame([], $this->store->getDueSchedules($now));
        $this->assertCount(1, $this->store->getMissedSchedules($now));
    }

    public function testAProgrammeStillAiringIsDueRatherThanMissed(): void
    {
        $now = time();

        $this->store->addSchedule($this->schedule(['start' => $now - 60, 'duration' => 1800]));

        $this->assertCount(1, $this->store->getDueSchedules($now));
        $this->assertSame([], $this->store->getMissedSchedules($now));
    }

    public function testARetryIsHeldBackUntilItsTime(): void
    {
        $now = time();
        $id  = $this->store->addSchedule($this->schedule(['start' => $now, 'duration' => 3600]));

        $this->store->retrySchedule($id, 1, $now + 60);

        $this->assertSame([], $this->store->getDueSchedules($now));
        $this->assertCount(1, $this->store->getDueSchedules($now + 60));
        $this->assertSame(1, $this->store->getSchedule($id)['attempts']);
    }

    public function testACancelledScheduleIsNeitherDueNorMissed(): void
    {
        $now = time();
        $id  = $this->store->addSchedule($this->schedule(['start' => $now, 'duration' => 1800]));

        $this->store->markSchedule($id, RecordingStore::STATUS_CANCELLED);

        $this->assertSame([], $this->store->getDueSchedules($now));
        $this->assertSame([], $this->store->getMissedSchedules($now + 7200));
    }

    public function testSchedulesCanBeFilteredByDevice(): void
    {
        $this->store->addSchedule($this->schedule());
        $this->store->addSchedule($this->schedule(['device' => '192.168.1.99', 'start' => time() + 9000]));

        $this->assertCount(1, $this->store->getSchedules('192.168.1.62'));
        $this->assertCount(2, $this->store->getSchedules());
    }

    public function testDeletingAScheduleReportsWhetherThereWasOne(): void
    {
        $id = $this->store->addSchedule($this->schedule());

        $this->assertTrue($this->store->deleteSchedule($id));
        $this->assertFalse($this->store->deleteSchedule($id));
        $this->assertNull($this->store->getSchedule($id));
    }

    public function testOpeningAnExistingDatabaseAgainIsSafe(): void
    {
        // Every release may add columns to a database that is already out there; opening
        // one twice has to be a no-op rather than an error.
        $id = $this->store->addSchedule($this->schedule());

        $reopened = new RecordingStore($this->directory . '/guide.sqlite');

        $this->assertNotNull($reopened->getSchedule($id));
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function schedule(array $overrides = []): array
    {
        return $overrides + [
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WFOR-TV',
            'eventId'     => 1234,
            'start'       => time() + 3600,
            'duration'    => 1800,
            'title'       => 'The Late Show',
            'description' => 'Tonight: a guest.',
            'padStart'    => 60,
            'padEnd'      => 180,
            'format'      => 'ts',
        ];
    }
}
