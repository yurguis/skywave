<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Skywave\Dvr\RecordingPlayback;
use Skywave\Dvr\RecordingStore;

/**
 * Watching a recording whose browser-ready copy was made as HLS.
 *
 * RECORDING_CONVERT_TO=hls writes a finished playlist beside the broadcast instead of an
 * mp4. The player opens it directly: the length and the seek bar are there from the first
 * frame, and nothing is transcoded while somebody watches.
 */
class StoredHlsTest extends TestCase
{
    private string $directory;

    private string $recordings;

    private RecordingStore $store;

    private RecordingPlayback $playback;

    protected function setUp(): void
    {
        $this->directory  = sys_get_temp_dir() . '/skywave-storedhls-' . bin2hex(random_bytes(6));
        $this->recordings = $this->directory . '/recordings';
        mkdir($this->recordings, 0777, true);

        $this->store    = new RecordingStore($this->directory . '/guide.sqlite');
        $this->playback = new RecordingPlayback($this->store, $this->recordings);
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testAStoredCopyIsPlayedFromItsOwnDirectory(): void
    {
        $id      = $this->addStored();
        $playing = $this->playback->play($id, 'viewer-1');

        $this->assertSame('hls', $playing['kind']);
        $this->assertSame("/recordings/$id/hls/index.m3u8", $playing['playlist']);
        $this->assertTrue($playing['ready'], 'there is nothing left to wait for');
        $this->assertFalse($playing['converting'], 'and nothing being converted');
        $this->assertSame(2, $playing['segments']);
        $this->assertNull($playing['error']);
    }

    public function testNothingIsStartedForOne(): void
    {
        $id = $this->addStored();
        $this->playback->play($id, 'viewer-1');

        // A session would leave its working directory and a pid behind. Playing a copy that
        // is already made must spawn nothing at all.
        $this->assertFalse(is_dir("$this->recordings/.playback/$id"));
    }

    public function testPollingAStoredCopyDoesNotSayItStopped(): void
    {
        // There is no session to expire, so status() must answer from the copy on disk
        // rather than reporting that the playback has stopped.
        $id     = $this->addStored();
        $status = $this->playback->status($id, 'viewer-1');

        $this->assertTrue($status['ready']);
        $this->assertSame("/recordings/$id/hls/index.m3u8", $status['playlist']);
    }

    public function testTheStoredPlaylistAndItsSegmentsAreServed(): void
    {
        $id = $this->addStored();

        $this->assertSame("$this->recordings/A Show.hls/index.m3u8", $this->playback->resolveFile($id, 'index.m3u8'));
        $this->assertSame("$this->recordings/A Show.hls/v0.ts", $this->playback->resolveFile($id, 'v0.ts'));
        $this->assertNull($this->playback->resolveFile($id, 'ffmpeg.log'));
    }

    public function testDownloadingStillGivesTheBroadcast(): void
    {
        // The copy is a directory, so there is nothing to hand over whole but the original.
        $id = $this->addStored();

        $this->assertSame("$this->recordings/A Show.ts", $this->playback->sourceFile($id));
    }

    public function testThereAreNoSidecarCaptions(): void
    {
        // They travel inside the video, where a player reading a playlist finds them.
        $id = $this->addStored();

        $this->assertNull($this->playback->captionsFile($id));
    }

    public function testADamagedCopyIsConvertedOnDemandInstead(): void
    {
        $id = $this->addStored();
        unlink("$this->recordings/A Show.hls/index.m3u8");

        // Without a playlist there is nothing to play, so it falls back to converting the
        // broadcast as it always did -- which here cannot start, because the broadcast has
        // been taken away too. The point is which of the two routes it took.
        unlink("$this->recordings/A Show.ts");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The recording file is missing');

        $this->playback->play($id, 'viewer-1');
    }

    public function testEverySizeIsOfferedToDownload(): void
    {
        $id     = $this->addStored();
        $offers = $this->playback->downloads($id);

        $this->assertSame(['As broadcast', '1080p', '720p'], array_column($offers, 'name'));
        $this->assertSame([
            "/recordings/$id/file?download=1",
            "/recordings/$id/download/1080",
            "/recordings/$id/download/720",
        ], array_column($offers, 'url'));

        // A size is picture only; what arrives is it and the sound together, so the size
        // quoted counts both.
        $this->assertSame([1, 25, 15], array_column($offers, 'bytes'));
    }

    public function testASizeIsSentWithItsSound(): void
    {
        // The whole point: a rendition on its own is silent, because HLS keeps the languages
        // in renditions of their own. Both files have to go into what is handed over.
        $id   = $this->addStored();
        $plan = $this->playback->downloadPlan($id, 720);

        $this->assertNotNull($plan);
        $this->assertStringContainsString('A Show.hls/v1.ts', $plan['command'], 'the picture');
        $this->assertStringContainsString('A Show.hls/v2.ts', $plan['command'], 'and the sound');
        $this->assertStringContainsString('aac_adtstoasc', $plan['command'], 'which mp4 will not take raw');
        $this->assertSame('A Show 720p.mp4', $plan['name']);
    }

    public function testASizeThatWasNeverMadeIsNotOffered(): void
    {
        $id = $this->addStored();

        $this->assertNull($this->playback->downloadPlan($id, 2160));
    }

    public function testASizeIsSavedUnderTheRecordingsName(): void
    {
        // v0.ts says nothing once it is sitting in somebody's downloads folder.
        $id = $this->addStored();

        $this->assertSame('A Show 1080p.ts', $this->playback->downloadName($id, "$this->recordings/A Show.hls/v0.ts"));
        $this->assertSame('A Show.ts', $this->playback->downloadName($id, "$this->recordings/A Show.ts"));
    }

    public function testAudioOnlyVariantsAreNotOfferedAsPictures(): void
    {
        // The languages sit in the master playlist too, as EXT-X-MEDIA with no RESOLUTION,
        // and a language is not a size to download.
        $id = $this->addStored();

        $this->assertNotContains('v2.ts', array_column($this->playback->downloads($id), 'url'));
    }

    public function testARecordingWithNoCopyOffersTheBroadcastOnly(): void
    {
        $id = $this->addStored();
        exec('rm -rf ' . escapeshellarg("$this->recordings/A Show.hls"));

        $this->assertSame(['As broadcast'], array_column($this->playback->downloads($id), 'name'));
    }

    private function addStored(): int
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
            'format'      => 'both',
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
            'convertedPath' => 'A Show.hls',
        ]);

        file_put_contents("$this->recordings/A Show.ts", 'x');
        mkdir("$this->recordings/A Show.hls");
        file_put_contents(
            "$this->recordings/A Show.hls/index.m3u8",
            "#EXTM3U\n"
            . "#EXT-X-MEDIA:TYPE=AUDIO,GROUP-ID=\"group_aud\",NAME=\"audio_2\",LANGUAGE=\"eng\",URI=\"v2.m3u8\"\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=1,RESOLUTION=1920x1080,AUDIO=\"group_aud\"\nv0.m3u8\n"
            . "#EXT-X-STREAM-INF:BANDWIDTH=1,RESOLUTION=1280x720,AUDIO=\"group_aud\"\nv1.m3u8\n"
        );
        file_put_contents("$this->recordings/A Show.hls/v1.ts", str_repeat('x', 10));
        file_put_contents("$this->recordings/A Show.hls/v2.ts", str_repeat('x', 5));
        file_put_contents(
            "$this->recordings/A Show.hls/v0.m3u8",
            "#EXTM3U\n#EXT-X-PLAYLIST-TYPE:VOD\n#EXTINF:4.0,\n#EXT-X-BYTERANGE:10@0\nv0.ts\n"
            . "#EXTINF:4.0,\n#EXT-X-BYTERANGE:10@10\nv0.ts\n#EXT-X-ENDLIST\n"
        );
        file_put_contents("$this->recordings/A Show.hls/v0.ts", str_repeat('x', 20));

        return $id;
    }
}
