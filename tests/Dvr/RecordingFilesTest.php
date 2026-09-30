<?php

namespace Skywave\Tests\Dvr;

use PHPUnit\Framework\TestCase;
use Skywave\Dvr\Recorder;
use Skywave\Dvr\RecordingStore;
use Skywave\Dvr\TunerReservations;

/**
 * Everything a recording owns, and getting rid of it.
 *
 * A browser-ready copy made as HLS is a directory rather than a single file, so deleting a
 * recording has to reach inside it. Missing that leaves the segments -- gigabytes of them --
 * behind with no recording left to point at them.
 */
class RecordingFilesTest extends TestCase
{
    private string $directory;

    private Recorder $recorder;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-recfiles-' . bin2hex(random_bytes(6));
        mkdir($this->directory, 0777, true);

        $this->recorder = new Recorder(
            new RecordingStore($this->directory . '/guide.sqlite'),
            new TunerReservations($this->directory),
            $this->directory,
            'ffmpeg',
            720,
            'hls',
            [480]
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testTheFilesInsideAnHlsCopyAreListed(): void
    {
        $this->writeCopy();

        $files = $this->recorder->filesFor(['path' => 'A Show.ts', 'convertedPath' => 'A Show.hls']);

        sort($files);

        $this->assertSame([
            "$this->directory/A Show.hls/index.m3u8",
            "$this->directory/A Show.hls/v0.m3u8",
            "$this->directory/A Show.hls/v0.ts",
            "$this->directory/A Show.ts",
        ], $files);
    }

    public function testDiscardingTakesTheDirectoryWithIt(): void
    {
        $this->writeCopy();

        $this->recorder->discard(['path' => 'A Show.ts', 'convertedPath' => 'A Show.hls']);

        $this->assertFalse(is_dir("$this->directory/A Show.hls"), 'the directory goes too');
        $this->assertFalse(is_file("$this->directory/A Show.ts"));
        $this->assertSame([], glob("$this->directory/A Show*") ?: []);
    }

    public function testAHalfFinishedHlsCopyIsAlsoCleanedUp(): void
    {
        // A conversion that was interrupted leaves the directory it was writing into, and
        // the log beside it.
        mkdir("$this->directory/A Show.hls.part");
        file_put_contents("$this->directory/A Show.hls.part/v0.ts", 'x');
        file_put_contents("$this->directory/A Show.hls.part.log", 'x');
        file_put_contents("$this->directory/A Show.ts", 'x');

        $this->recorder->discard(['path' => 'A Show.ts', 'convertedPath' => 'A Show.hls']);

        $this->assertSame([], glob("$this->directory/A Show*") ?: []);
    }

    public function testAnMp4CopyIsStillJustItsFiles(): void
    {
        file_put_contents("$this->directory/A Show.ts", 'x');
        file_put_contents("$this->directory/A Show.mp4", 'x');
        file_put_contents("$this->directory/A Show.vtt", 'x');

        $files = $this->recorder->filesFor(['path' => 'A Show.ts', 'convertedPath' => 'A Show.mp4']);

        sort($files);

        $this->assertSame([
            "$this->directory/A Show.mp4",
            "$this->directory/A Show.ts",
            "$this->directory/A Show.vtt",
        ], $files);
    }

    private function writeCopy(): void
    {
        file_put_contents("$this->directory/A Show.ts", 'x');
        mkdir("$this->directory/A Show.hls");

        foreach (['index.m3u8', 'v0.m3u8', 'v0.ts'] as $name) {
            file_put_contents("$this->directory/A Show.hls/$name", 'x');
        }
    }
}
