<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingStore;

/**
 * Which recordings the recorder picks up to convert.
 *
 * "both" recordings are converted on their own; anything else only when it has been asked
 * for from the page. Either way it must happen once and not again.
 */
class ConversionRequestTest extends TestCase
{
    private string $directory;

    private RecordingStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-convert-' . bin2hex(random_bytes(6));
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

    public function testABroadcastRecordingIsLeftAloneUntilItIsAskedFor(): void
    {
        $id = $this->addRecording(['format' => 'ts']);

        $this->assertSame([], $this->store->getRecordingsToConvert());

        $this->store->updateRecording($id, ['convertRequested' => 1]);

        $this->assertCount(1, $this->store->getRecordingsToConvert());
    }

    public function testABothRecordingIsPickedUpWithoutAsking(): void
    {
        $this->addRecording(['format' => 'both']);

        $this->assertCount(1, $this->store->getRecordingsToConvert());
    }

    public function testOneThatIsStillRecordingIsNotConverted(): void
    {
        $id = $this->addRecording(['format' => 'ts'], RecordingStore::STATUS_RECORDING);
        $this->store->updateRecording($id, ['convertRequested' => 1]);

        $this->assertSame([], $this->store->getRecordingsToConvert());
    }

    public function testOneAlreadyConvertedIsNotPickedUpAgain(): void
    {
        $id = $this->addRecording(['format' => 'ts']);
        $this->store->updateRecording($id, ['convertRequested' => 1]);
        $this->store->updateRecording($id, ['convertedPath' => 'something.mp4', 'convertedBytes' => 123]);

        $this->assertSame([], $this->store->getRecordingsToConvert());
    }

    public function testOneThatFailedIsNotRetriedOnItsOwn(): void
    {
        // Otherwise the recorder would try the same broken file every ten seconds, forever.
        $id = $this->addRecording(['format' => 'ts']);
        $this->store->updateRecording($id, ['convertRequested' => 1, 'convertError' => 'ffmpeg gave up']);

        $this->assertSame([], $this->store->getRecordingsToConvert());

        // Clearing the error is what makes another attempt possible.
        $this->store->updateRecording($id, ['convertError' => null]);

        $this->assertCount(1, $this->store->getRecordingsToConvert());
    }

    public function testOneAlreadyUnderWayIsNotStartedTwice(): void
    {
        $id = $this->addRecording(['format' => 'ts']);
        $this->store->updateRecording($id, ['convertRequested' => 1, 'convertPid' => 4321]);

        $this->assertSame([], $this->store->getRecordingsToConvert());
    }

    public function testTheChosenPictureSizeSurvives(): void
    {
        $id = $this->addRecording(['format' => 'ts']);

        $this->store->updateRecording($id, ['convertRequested' => 1, 'convertHeight' => 720]);
        $this->assertSame(720, $this->store->getRecording($id)['convertHeight']);

        // Nothing means "keep what was broadcast", and must not read back as zero.
        $this->store->updateRecording($id, ['convertHeight' => null]);
        $this->assertNull($this->store->getRecording($id)['convertHeight']);
        $this->assertTrue($this->store->getRecording($id)['convertRequested']);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function addRecording(array $overrides = [], string $status = RecordingStore::STATUS_DONE): int
    {
        $id = $this->store->addRecording($overrides + [
            'scheduleId'  => null,
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => 'The Late Show',
            'description' => null,
            'path'        => 'The Late Show.ts',
            'format'      => 'ts',
            'tuner'       => 0,
            'pid'         => 1234,
            'startedAt'   => time() - 3600,
            'stopsAt'     => time() - 60,
            'reservation' => null,
        ]);

        $this->store->updateRecording($id, ['status' => $status, 'bytes' => 1_000_000, 'endedAt' => time() - 60]);

        return $id;
    }
}
