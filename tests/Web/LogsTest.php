<?php

namespace Skywave\Tests\Web;

use PHPUnit\Framework\TestCase;
use Skywave\Web\Logs;

/**
 * The page asks for logs by id, never by path. What matters here is that unknown ids are
 * refused rather than resolved, and that reading the end of a large file stays cheap and
 * honest about where it started.
 */
class LogsTest extends TestCase
{
    private string $directory;

    private Logs $logs;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/skywave-logs-' . bin2hex(random_bytes(6));
        mkdir($this->directory . '/data/logs', 0777, true);
        mkdir($this->directory . '/data/locks', 0777, true);
        mkdir($this->directory . '/recordings', 0777, true);
        mkdir($this->directory . '/hls/abc123', 0777, true);

        $this->logs = new Logs(
            $this->directory . '/data',
            $this->directory . '/recordings',
            $this->directory . '/hls'
        );
    }

    protected function tearDown(): void
    {
        exec('rm -rf ' . escapeshellarg($this->directory));
    }

    public function testOnlyLogsThatExistAreOffered(): void
    {
        $this->assertSame([], $this->logs->sources());

        file_put_contents($this->directory . '/data/logs/access.log', "a request\n");

        $sources = $this->logs->sources();

        $this->assertCount(1, $sources);
        $this->assertSame('web', $sources[0]['id']);
        $this->assertSame('Web requests', $sources[0]['name']);
    }

    public function testEachKindOfLogIsFound(): void
    {
        file_put_contents($this->directory . '/data/logs/recorder.log', "tick\n");
        file_put_contents($this->directory . '/data/locks/guide-192.168.1.62.log', "channel 21\n");
        file_put_contents($this->directory . '/recordings/The Late Show.ts.log', "frame=1\n");
        file_put_contents($this->directory . '/hls/abc123/ffmpeg.log', "frame=2\n");

        $groups = array_column($this->logs->sources(), 'group');

        sort($groups);
        $this->assertSame(['Guide', 'Live', 'Recordings', 'Server'], $groups);
    }

    public function testARecordingKeepsItsNameWithoutTheExtension(): void
    {
        file_put_contents($this->directory . '/recordings/The Late Show.ts.log', "frame=1\n");

        $this->assertSame('The Late Show', $this->logs->sources()[0]['name']);
    }

    public function testAnUnknownIdIsRefused(): void
    {
        file_put_contents($this->directory . '/data/logs/access.log', "a request\n");

        $this->assertNull($this->logs->tail('nonsense'));
        $this->assertNull($this->logs->tail('recorder'));
    }

    public function testAPathCannotBeAskedFor(): void
    {
        file_put_contents($this->directory . '/data/logs/access.log', "a request\n");
        file_put_contents($this->directory . '/secret.txt', "not a log\n");

        $this->assertNull($this->logs->tail('../secret.txt'));
        $this->assertNull($this->logs->tail($this->directory . '/secret.txt'));
        $this->assertNull($this->logs->tail('/etc/passwd'));
    }

    public function testTheEndOfTheFileIsWhatComesBack(): void
    {
        $lines = array_map(static fn (int $n) => "line $n", range(1, 50));
        file_put_contents($this->directory . '/data/logs/access.log', implode("\n", $lines) . "\n");

        $log = $this->logs->tail('web', 10);

        $this->assertNotNull($log);
        $this->assertCount(10, $log['lines']);
        $this->assertSame('line 41', $log['lines'][0]);
        $this->assertSame('line 50', $log['lines'][9]);
    }

    public function testAskingForMoreLinesThanExistIsFine(): void
    {
        file_put_contents($this->directory . '/data/logs/access.log', "one\ntwo\n");

        $this->assertSame(['one', 'two'], $this->logs->tail('web', 500)['lines']);
    }

    public function testAHugeLogIsReadFromItsEndWithoutAHalfLine(): void
    {
        // Bigger than the 256 KB read window, so the window lands mid-line.
        $line = str_repeat('x', 500);
        $rows = [];

        for ($i = 0; $i < 2000; $i++) {
            $rows[] = "$i $line";
        }

        file_put_contents($this->directory . '/data/logs/access.log', implode("\n", $rows) . "\n");

        $log = $this->logs->tail('web', 5);

        $this->assertSame('1999 ' . $line, $log['lines'][4]);

        // Every line is whole: a first line cut in half by the window is dropped, never
        // shown as if the log said it.
        foreach ($log['lines'] as $row) {
            $this->assertSame(505, strlen($row), 'a line came back truncated');
        }
    }

    public function testAnEmptyLogReadsAsEmptyRatherThanMissing(): void
    {
        touch($this->directory . '/data/logs/access.log');

        $log = $this->logs->tail('web');

        $this->assertNotNull($log);
        $this->assertSame([], $log['lines']);
        $this->assertSame(0, $log['bytes']);
    }
}
