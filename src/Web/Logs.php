<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Web;

/**
 * The logs the page is allowed to show.
 *
 * A source is asked for by an opaque id, never by a path, and only the files listed here
 * can be named: nothing the browser sends reaches a file that is not a log.
 *
 * Several of these are written by other containers. They arrive here through the shared
 * data volume, because what a container keeps on its own stdout is invisible to the page.
 */
class Logs
{
    /** Read no more than this from the end of a file, however many lines are asked for. */
    private const MAX_BYTES = 256 * 1024;

    private const DEFAULT_LINES = 200;
    private const MAX_LINES     = 2000;

    private string $dataDirectory;

    private string $recordingsDirectory;

    private string $hlsDirectory;

    public function __construct(string $dataDirectory, string $recordingsDirectory, string $hlsDirectory)
    {
        $this->dataDirectory       = rtrim($dataDirectory, '/');
        $this->recordingsDirectory = rtrim($recordingsDirectory, '/');
        $this->hlsDirectory        = rtrim($hlsDirectory, '/');
    }

    /**
     * Settings from GUIDE_DB, RECORDINGS_DIR and HLS_DIR, read the same way the services
     * that write these logs read them, so the paths cannot drift apart.
     */
    public static function fromEnvironment(): self
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        };

        return new self(
            dirname($env('GUIDE_DB', dirname(__DIR__, 2) . '/data/guide.sqlite')),
            $env('RECORDINGS_DIR', dirname(__DIR__, 2) . '/data/recordings'),
            $env('HLS_DIR', sys_get_temp_dir() . '/hdhomerun-hls')
        );
    }

    /**
     * Every log that exists right now, newest activity first within each group.
     *
     * @return list<array<string, mixed>>
     */
    public function sources(): array
    {
        $sources = [];

        foreach ($this->files() as $id => $file) {
            if (!is_file($file['path'])) {
                continue;
            }

            $sources[] = [
                'id'         => $id,
                'name'       => $file['name'],
                'group'      => $file['group'],
                'bytes'      => (int) filesize($file['path']),
                'modifiedAt' => (int) filemtime($file['path']),
            ];
        }

        usort($sources, static fn (array $a, array $b) => $b['modifiedAt'] <=> $a['modifiedAt']);

        return $sources;
    }

    /**
     * The end of one log, or null when the id names nothing.
     *
     * @return array<string, mixed>|null
     */
    public function tail(string $id, int $lines = self::DEFAULT_LINES): ?array
    {
        $files = $this->files();

        if (!isset($files[$id]) || !is_file($files[$id]['path'])) {
            return null;
        }

        $file  = $files[$id];
        $lines = max(1, min(self::MAX_LINES, $lines));

        return [
            'id'         => $id,
            'name'       => $file['name'],
            'group'      => $file['group'],
            'bytes'      => (int) filesize($file['path']),
            'modifiedAt' => (int) filemtime($file['path']),
            'lines'      => self::readTail($file['path'], $lines),
        ];
    }

    /**
     * Every readable log, by id.
     *
     * @return array<string, array{path: string, name: string, group: string}>
     */
    private function files(): array
    {
        $files = [
            'web' => [
                'path'  => "$this->dataDirectory/logs/access.log",
                'name'  => 'Web requests',
                'group' => 'Server',
            ],
            'recorder' => [
                'path'  => "$this->dataDirectory/logs/recorder.log",
                'name'  => 'Recorder service',
                'group' => 'Server',
            ],
            'guide-service' => [
                'path'  => "$this->dataDirectory/logs/guide.log",
                'name'  => 'Guide service',
                'group' => 'Server',
            ],
        ];

        foreach (self::glob("$this->dataDirectory/locks/guide-*.log") as $path) {
            $device = preg_replace('/^guide-|\.log$/', '', basename($path));

            $files['guide-job-' . self::key($path)] = [
                'path'  => $path,
                'name'  => "Last guide job · $device",
                'group' => 'Guide',
            ];
        }

        foreach (self::glob("$this->recordingsDirectory/*.log") as $path) {
            $files['recording-' . self::key($path)] = [
                'path'  => $path,
                'name'  => preg_replace('/\.ts\.log$|\.log$/', '', basename($path)),
                'group' => 'Recordings',
            ];
        }

        foreach (self::glob("$this->hlsDirectory/*/ffmpeg.log") as $path) {
            $files['stream-' . self::key($path)] = [
                'path'  => $path,
                'name'  => 'Live stream ' . basename(dirname($path)),
                'group' => 'Live',
            ];
        }

        return $files;
    }

    /**
     * @return list<string>
     */
    private static function glob(string $pattern): array
    {
        $paths = @glob($pattern);

        return $paths === false ? [] : array_values($paths);
    }

    /**
     * A short id for a path. Stable for as long as the file keeps its name, which is all
     * that a page refreshing itself needs.
     */
    private static function key(string $path): string
    {
        return substr(sha1($path), 0, 10);
    }

    /**
     * The last $lines lines, read from the end so a large file costs no more than a small
     * one. A first line cut in half by the read window is dropped rather than shown.
     *
     * @return list<string>
     */
    private static function readTail(string $path, int $lines): array
    {
        $handle = @fopen($path, 'rb');

        if ($handle === false) {
            return [];
        }

        $size   = (int) filesize($path);
        $offset = max(0, $size - self::MAX_BYTES);

        if ($offset > 0) {
            fseek($handle, $offset);
        }

        $text = (string) stream_get_contents($handle);
        fclose($handle);

        $text = rtrim($text, "\r\n");
        // Splitting an empty file would otherwise report one empty line, which the page
        // would draw as a blank row instead of saying the log is empty.
        $rows = $text === '' ? [] : (preg_split('/\r?\n/', $text) ?: []);

        if ($offset > 0 && $rows !== []) {
            array_shift($rows);
        }

        return array_values(array_slice($rows, -$lines));
    }
}
