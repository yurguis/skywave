<?php

namespace Skywave\Tests\Radio;

use PHPUnit\Framework\TestCase;
use Skywave\Radio\Listener;

/**
 * nrsc5 joined to ffmpeg, with PHP standing in for both.
 *
 * Neither is needed to check the joining: one process that prints a station and writes
 * sound, another that reads until the sound stops. What is being tested is that the station
 * is written down, that the sound reaches the far end, and that a failure is explained.
 */
class ListenerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-listener-' . bin2hex(random_bytes(6));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . '/*') ?: [] as $file) {
            unlink($file);
        }

        rmdir($this->directory);
    }

    public function testTheStationIsWrittenDownAndTheSoundReachesTheEncoder(): void
    {
        $receiver = [PHP_BINARY, '-r', '
            fwrite(STDERR, "14:31:16 Synchronized\n14:31:16 Station name: KUT \n14:31:19 Title: Morning Edition\n");
            fwrite(STDERR, "14:31:19 MER: 12.9 dB (lower), 12.2 dB (upper)\n");
            echo str_repeat("\0", 4096);
        '];
        $encoder = [PHP_BINARY, '-r', 'file_put_contents($argv[1], strlen(stream_get_contents(STDIN)));', "$this->directory/heard"];

        $status = (new Listener($this->directory, $receiver, $encoder))->run();

        $station = json_decode((string) file_get_contents("$this->directory/" . Listener::STATE_FILE), true);

        $this->assertSame(0, $status);
        $this->assertSame('KUT', $station['station']);
        $this->assertSame('Morning Edition', $station['title']);
        $this->assertTrue($station['synchronized']);
        $this->assertSame('4096', file_get_contents("$this->directory/heard"));

        // The once-a-second signal reports are in the station file and kept out of the log.
        $log = (string) file_get_contents("$this->directory/" . Listener::LOG_FILE);
        $this->assertStringContainsString('Station name: KUT', $log);
        $this->assertStringNotContainsString('MER', $log);
    }

    public function testAFailureIsExplainedInWordsThePageCanShow(): void
    {
        $receiver = [PHP_BINARY, '-r', 'fwrite(STDERR, "14:31:16 Connection failed.\n"); exit(1);'];
        $encoder  = [PHP_BINARY, '-r', 'stream_get_contents(STDIN);'];
        $report   = fopen('php://memory', 'w+');

        $status = (new Listener($this->directory, $receiver, $encoder, 0, $report))->run();

        rewind($report);

        $this->assertSame(1, $status);
        $this->assertSame("The rtl_tcp server could not be reached\n", stream_get_contents($report));
    }

    public function testAStopThatSaysNothingIsStillReportedAsOne(): void
    {
        $receiver = [PHP_BINARY, '-r', 'exit(3);'];
        $encoder  = [PHP_BINARY, '-r', 'stream_get_contents(STDIN);'];
        $report   = fopen('php://memory', 'w+');

        $status = (new Listener($this->directory, $receiver, $encoder, 0, $report))->run();

        rewind($report);

        $this->assertSame(1, $status);
        $this->assertStringContainsString('exit status 3', (string) stream_get_contents($report));
    }

    public function testTheEncoderWritesThePlaylistLiveTelevisionDoes(): void
    {
        $arguments = Listener::encoderArguments('ffmpeg', '/tmp/session', 300);

        // Raw sound in, exactly as nrsc5 writes it.
        $this->assertSame(['-f', 's16le', '-ar', '44100', '-ac', '2', '-i', 'pipe:0'], array_slice($arguments, 5, 8));
        // The same names a television session uses, so one player and one route serve both.
        $this->assertContains('/tmp/session/v%v.m3u8', $arguments);
        $this->assertContains('/tmp/session/v%v_%05d.ts', $arguments);
        $this->assertSame('index.m3u8', $arguments[array_search('-master_pl_name', $arguments, true) + 1]);
        // Five minutes of two-second segments.
        $this->assertSame('150', $arguments[array_search('-hls_list_size', $arguments, true) + 1]);
    }
}
