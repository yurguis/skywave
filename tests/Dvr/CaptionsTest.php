<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\Recorder;
use Skywave\Dvr\RecordingPlayback;
use Skywave\Dvr\RecordingStore;

/**
 * Captions beside a converted recording.
 *
 * A browser reads the captions carried inside a playlist by itself, but not the ones inside
 * a plain file, so the converter writes them out as WebVTT and the player attaches them.
 */
class CaptionsTest extends TestCase
{
    private string $directory;

    private RecordingStore $store;

    private RecordingPlayback $playback;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-captions-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/recordings', 0777, true);

        $this->store    = new RecordingStore($this->directory . '/guide.sqlite');
        $this->playback = new RecordingPlayback($this->store, $this->directory . '/recordings');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testCaptionsSitBesideTheirRecording(): void
    {
        $this->assertSame('/tmp/A Show.vtt', Recorder::captionsPath('/tmp/A Show.mp4'));
    }

    public function testAHalfFinishedConversionKeepsTheSameCaptionName(): void
    {
        // The conversion writes to ".part" and renames; the captions must not end up as
        // "A Show.mp4.part.vtt" and be left behind.
        $this->assertSame('/tmp/A Show.vtt', Recorder::captionsPath('/tmp/A Show.mp4.part'));
    }

    public function testNoCaptionsMeansNothingIsOffered(): void
    {
        $id = $this->addConverted();

        $this->assertNull($this->playback->captionsFile($id));
    }

    public function testAnEmptyCaptionFileCountsAsNone(): void
    {
        // ffmpeg writes a WebVTT header even when a programme carries no captions.
        $id = $this->addConverted();
        touch($this->directory . '/recordings/A Show.vtt');

        $this->assertNull($this->playback->captionsFile($id));
    }

    public function testCaptionsAreFoundWhenTheyExist(): void
    {
        $id = $this->addConverted();
        file_put_contents($this->directory . '/recordings/A Show.vtt', "WEBVTT\n\n00:01.000 --> 00:02.000\nHello\n");

        $this->assertSame($this->directory . '/recordings/A Show.vtt', $this->playback->captionsFile($id));
    }

    public function testABroadcastRecordingHasNoSidecar(): void
    {
        // Only a converted recording has one: a ts plays as HLS, which carries its own.
        $id = $this->store->addRecording($this->recording());
        $this->store->updateRecording($id, ['status' => RecordingStore::STATUS_DONE]);
        file_put_contents($this->directory . '/recordings/A Show.ts', 'x');

        $this->assertNull($this->playback->captionsFile($id));
    }

    private function addConverted(): int
    {
        $id = $this->store->addRecording($this->recording());
        $this->store->updateRecording($id, [
            'status'        => RecordingStore::STATUS_DONE,
            'convertedPath' => 'A Show.mp4',
        ]);
        file_put_contents($this->directory . '/recordings/A Show.mp4', 'x');

        return $id;
    }

    /**
     * @return array<string, mixed>
     */
    private function recording(): array
    {
        return [
            'scheduleId'  => null,
            'device'      => '192.168.1.62',
            'physical'    => 29,
            'program'     => 3,
            'virtual'     => '6.1',
            'channelName' => 'WTVJ',
            'title'       => 'A Show',
            'description' => null,
            'path'        => 'A Show.ts',
            'format'      => 'ts',
            'tuner'       => 0,
            'pid'         => 1234,
            'startedAt'   => time() - 3600,
            'stopsAt'     => time() - 60,
            'reservation' => null,
        ];
    }
}
