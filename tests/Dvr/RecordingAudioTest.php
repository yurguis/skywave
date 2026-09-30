<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skywave\Dvr\RecordingPlayback;
use Skywave\Dvr\RecordingStore;

/**
 * Choosing the audio on a recording that has been converted.
 *
 * A converted recording used to be handed to the browser as a plain file, always. Outside
 * Safari a <video> offers no way to change audio track, so a programme carrying a second
 * language -- or an audio description, which is a whole second track in the same language --
 * played whichever ffmpeg wrote first and gave the viewer no way back. Converting quietly
 * cost them the choice the un-converted recording had.
 *
 * The probe is a stand-in here. RecordingPlayback takes the ffmpeg path and reads ffprobe
 * beside it, so a script that prints a fixed answer drives this without any media, and
 * without needing ffmpeg on the machine running the tests.
 */
class RecordingAudioTest extends TestCase
{
    private string $directory;

    private RecordingStore $store;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-recaudio-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/recordings', 0777, true);
        mkdir($this->directory . '/bin', 0777, true);

        $this->store = new RecordingStore($this->directory . '/guide.sqlite');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testOneTrackStillPlaysAsAFile(): void
    {
        // Nothing to choose between, so the converted file goes straight to the browser and
        // costs no CPU -- which is the whole point of having converted it.
        $playback = $this->playbackReporting([['eng', false]]);

        $this->assertSame('file', $playback->play($this->addConverted(), 'viewer-abcdefgh')['kind']);
    }

    public function testASecondTrackSendsItThroughThePlaylistInstead(): void
    {
        // Two tracks and no way to pick between them in a plain file, so it is transcoded
        // to a playlist that can name both. Here that gets as far as looking for the
        // original recording, which this test never wrote: reaching that failure is what
        // proves it did not take the play-as-a-file path.
        $playback = $this->playbackReporting([['eng', false], ['eng', true]]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recording file is missing');

        $playback->play($this->addConverted(), 'viewer-abcdefgh');
    }

    public function testAnUnreadableRecordingFallsBackToPlayingAsAFile(): void
    {
        // A probe that answers nothing must not strand the viewer: better the file it
        // always used to serve than an error about a track list nobody asked about.
        $playback = $this->playbackReporting([]);

        $this->assertSame('file', $playback->play($this->addConverted(), 'viewer-abcdefgh')['kind']);
    }

    /**
     * A RecordingPlayback whose ffprobe reports exactly these audio tracks.
     *
     * @param list<array{0: string, 1: bool}> $tracks language, and whether it describes the picture
     */
    private function playbackReporting(array $tracks): RecordingPlayback
    {
        $streams = array_map(static fn (array $track): array => [
            'channels'    => 2,
            'disposition' => ['visual_impaired' => $track[1] ? 1 : 0],
            'tags'        => ['language' => $track[0]],
        ], $tracks);

        $probe = $this->directory . '/bin/ffprobe';
        file_put_contents($probe, "#!/bin/sh\ncat <<'JSON'\n" . json_encode(['streams' => $streams]) . "\nJSON\n");
        chmod($probe, 0755);

        // audioTracks() reads ffprobe from beside the ffmpeg it was given.
        return new RecordingPlayback($this->store, $this->directory . '/recordings', $this->directory . '/bin/ffmpeg');
    }

    private function addConverted(): int
    {
        $id = $this->store->addRecording([
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
        ]);

        $this->store->updateRecording($id, [
            'status'        => RecordingStore::STATUS_DONE,
            'bytes'         => 1_000_000,
            'endedAt'       => time() - 60,
            'convertedPath' => 'A Show.mp4',
        ]);

        file_put_contents($this->directory . '/recordings/A Show.mp4', 'x');

        return $id;
    }
}
