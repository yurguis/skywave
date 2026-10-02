<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Radio;

use RuntimeException;
use Skywave\Platform;

/**
 * A scan of the dial running in the background (tools/radio-scan.php), one at a time.
 *
 * A scan takes minutes, so it cannot live inside the request that asks for it. The page
 * starts it, asks how it is getting on, and may ask it to stop; all three go through files
 * in the live playback directory, which every PHP worker can see:
 *
 *   radio-scan.lock   held by the scan for as long as it runs, which is how anyone knows
 *   radio-scan.json   how far it has got and what it has found
 *   radio-scan.stop   exists once somebody has asked it to stop
 *   radio-scan.log    what the scan printed, for when it ends badly
 *
 * It is asked to stop rather than made to: a file the scan looks for between frequencies
 * works the same on every host, where a signal does not.
 */
class ScanJobs
{
    private string $directory;
    private string $script;
    private string $php;

    public function __construct(string $directory, string $script, string $php = 'php')
    {
        $this->directory = rtrim($directory, '/');
        $this->script    = $script;
        $this->php       = $php;
    }

    /**
     * Beside the live sessions in HLS_DIR; GUIDE_PHP names the PHP command line binary.
     */
    public static function fromEnvironment(): self
    {
        $env = static function (string $name, string $default): string {
            $value = getenv($name);

            return $value === false || $value === '' ? $default : $value;
        };

        return new self(
            $env('HLS_DIR', sys_get_temp_dir() . '/hdhomerun-hls'),
            dirname(__DIR__, 2) . '/tools/radio-scan.php',
            $env('GUIDE_PHP', 'php')
        );
    }

    public function getDirectory(): string
    {
        return $this->directory;
    }

    /**
     * Take the scan's lock, or null when a scan already holds it.
     *
     * @return resource|null
     */
    public function acquire()
    {
        if (!is_dir($this->directory) && !@mkdir($this->directory, 0700, true) && !is_dir($this->directory)) {
            throw new RuntimeException("Unable to create $this->directory");
        }

        $handle = fopen($this->path('lock'), 'c');

        if ($handle === false) {
            throw new RuntimeException('Unable to open the radio scan lock');
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    public function isRunning(): bool
    {
        $handle = $this->acquire();

        if ($handle === null) {
            return true;
        }

        flock($handle, LOCK_UN);
        fclose($handle);

        return false;
    }

    /**
     * Start scanning between two frequencies in the background.
     *
     * @return bool false when a scan is already running, or when the one started here never
     *              showed any sign of life
     */
    public function start(float $from = Scanner::FIRST, float $to = Scanner::LAST): bool
    {
        if ($this->isRunning()) {
            return false;
        }

        // Last scan's leavings would otherwise look like this one's.
        foreach (['json', 'stop', 'log'] as $extension) {
            @unlink($this->path($extension));
        }

        @unlink(Platform::errorLog($this->path('log')));

        shell_exec(Platform::detachedCommand([
            $this->php, $this->script,
            "--directory=$this->directory",
            sprintf('--from=%.1f', $from),
            sprintf('--to=%.1f', $to),
        ], $this->path('log')));

        // The lock is the only trustworthy sign that it started; see GuideJobs::start().
        $deadline = microtime(true) + 3.0;

        while (microtime(true) < $deadline) {
            if ($this->isRunning()) {
                return true;
            }

            usleep(50000);
        }

        return false;
    }

    /**
     * Ask a running scan to stop. It does so at the next frequency, or sooner.
     */
    public function stop(): void
    {
        if ($this->isRunning()) {
            @touch($this->path('stop'));
        }
    }

    public function stopRequested(): bool
    {
        return is_file($this->path('stop'));
    }

    /**
     * Record how far the scan has got. Called by the scan itself.
     *
     * @param array<string, mixed> $progress
     */
    public function report(array $progress): void
    {
        $file = $this->path('json');

        if (@file_put_contents("$file.tmp", json_encode($progress, JSON_INVALID_UTF8_SUBSTITUTE)) !== false) {
            @rename("$file.tmp", $file);
        }
    }

    /**
     * How the latest scan is getting on, or null when none has run since the server started.
     *
     * @return array<string, mixed>|null
     */
    public function status(): ?array
    {
        $json     = @file_get_contents($this->path('json'));
        $progress = $json === false ? null : json_decode($json, true);
        $running  = $this->isRunning();

        if (!is_array($progress)) {
            // Started a moment ago and not yet reported, or never run at all.
            return $running ? ['running' => true, 'done' => 0, 'total' => 0, 'frequency' => null, 'found' => [], 'error' => null] : null;
        }

        $progress['running'] = $running;

        // It stopped without saying it had finished: whatever it last printed is why.
        if (!$running && ($progress['finishedAt'] ?? null) === null && ($progress['error'] ?? null) === null) {
            $lines             = @file($this->path('log'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
            $progress['error'] = $lines === [] ? 'The scan stopped unexpectedly' : (string) end($lines);
        }

        return $progress;
    }

    private function path(string $extension): string
    {
        return "$this->directory/radio-scan.$extension";
    }
}
