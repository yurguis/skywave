<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\RecordingPlayback;
use Skywave\Dvr\RecordingStore;

/**
 * Which files of a recording being watched the page is allowed to ask for.
 *
 * The name comes from the browser, so this is a boundary: anything unrecognised is not a
 * path at all. Segments now live in one file per size with the playlist pointing at byte
 * ranges inside it, which changed the names -- and a recording somebody is part way
 * through has the older ones on disk, so those are still served.
 */
class RecordingPlaybackFilesTest extends TestCase
{
    private string $directory;

    private string $session;

    private RecordingPlayback $playback;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-playfiles-' . bin2hex(random_bytes(6));
        $this->session   = $this->directory . '/recordings/.playback/7';
        mkdir($this->session, 0777, true);

        $store          = new RecordingStore($this->directory . '/guide.sqlite');
        $this->playback = new RecordingPlayback($store, $this->directory . '/recordings');
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testTheNamesThePlayerAsksForAreServed(): void
    {
        $names = [
            'index.m3u8', // the master playlist, listing the sizes
            'v0.m3u8',    // one size's playlist
            'v0.ts',      // that size, whole, read in byte ranges
            'seg.ts',     // and the single-size form, which needs no master
        ];

        foreach ($names as $name) {
            touch("$this->session/$name");

            $this->assertSame("$this->session/$name", $this->playback->resolveFile(7, $name), $name);
        }
    }

    public function testTheOlderNumberedSegmentsAreStillServed(): void
    {
        // A recording being watched while the server is upgraded has these on disk. Left
        // recognised so playback does not stop halfway through.
        foreach (['seg_00000.ts', 'v1_00042.ts'] as $name) {
            touch("$this->session/$name");

            $this->assertSame("$this->session/$name", $this->playback->resolveFile(7, $name), $name);
        }
    }

    public function testAnythingElseIsNotAPath(): void
    {
        $names = [
            '../guide.sqlite', // a step upwards
            'a/b.ts',          // a path rather than a name
            'session.json',    // the session's own bookkeeping
            'ffmpeg.log',      // and the transcoder's log
            'v0.mp4',
            '',
        ];

        foreach ($names as $name) {
            $this->assertNull($this->playback->resolveFile(7, $name), $name);
        }
    }

    public function testARecognisedNameThatIsNotThereIsAlsoNothing(): void
    {
        // The same answer as a name that was never allowed, so the difference between the
        // two tells a caller nothing.
        $this->assertNull($this->playback->resolveFile(7, 'v9.ts'));
    }

    public function testARecordingIdMustBeARecordingId(): void
    {
        touch("$this->session/v0.ts");

        $this->assertNull($this->playback->resolveFile(0, 'v0.ts'));
        $this->assertNull($this->playback->resolveFile(-1, 'v0.ts'));
    }
}
