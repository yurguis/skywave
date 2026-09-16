<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Guide;

use RuntimeException;

/**
 * Background guide scans and collections (tools/guide.php), one at a time per device.
 *
 * The per-device lock files live next to the guide database, so the web UI and a
 * separate collector container sharing that volume see each other's work.
 */
class GuideJobs
{
    private string $lockDirectory;
    private string $script;
    private string $php;

    public function __construct(string $lockDirectory, string $script, string $php = 'php')
    {
        $this->lockDirectory = rtrim($lockDirectory, '/');
        $this->script        = $script;
        $this->php           = $php;
    }

    /**
     * Locks beside GUIDE_DB; GUIDE_PHP names the PHP CLI binary (default "php").
     */
    public static function fromEnvironment(): self
    {
        $database = getenv('GUIDE_DB');
        $database = $database === false || $database === '' ? dirname(__DIR__, 2) . '/data/guide.sqlite' : $database;
        $php      = getenv('GUIDE_PHP');

        return new self(dirname($database) . '/locks', dirname(__DIR__, 2) . '/tools/guide.php', $php === false || $php === '' ? 'php' : $php);
    }

    /**
     * Take the device's lock, trying for up to $waitSeconds.
     *
     * @return resource|null the held lock, or null when another process holds it
     */
    public function acquire(string $device, float $waitSeconds = 0.0)
    {
        if (!is_dir($this->lockDirectory) && !@mkdir($this->lockDirectory, 0775, true) && !is_dir($this->lockDirectory)) {
            throw new RuntimeException("Unable to create $this->lockDirectory");
        }

        $handle = fopen($this->path($device, 'lock'), 'c');

        if ($handle === false) {
            throw new RuntimeException("Unable to open the lock for $device");
        }

        $deadline = microtime(true) + $waitSeconds;

        while (!flock($handle, LOCK_EX | LOCK_NB)) {
            if (microtime(true) >= $deadline) {
                fclose($handle);

                return null;
            }

            usleep(50000);
        }

        return $handle;
    }

    /**
     * @param resource $handle
     */
    public function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    public function isRunning(string $device): bool
    {
        $handle = $this->acquire($device);

        if ($handle === null) {
            return true;
        }

        $this->release($handle);

        return false;
    }

    /**
     * Start "scan" or "collect" for a device in the background.
     *
     * @return bool false when a job is already running for the device
     */
    public function start(string $command, string $device): bool
    {
        if (!in_array($command, ['scan', 'collect'], true)) {
            throw new RuntimeException("Unknown guide job: $command");
        }

        if ($this->isRunning($device)) {
            return false;
        }

        // setsid detaches the job from the web request that started it.
        exec(sprintf(
            'setsid %s %s %s %s > %s 2>&1 < /dev/null &',
            escapeshellarg($this->php),
            escapeshellarg($this->script),
            $command,
            escapeshellarg($device),
            escapeshellarg($this->path($device, 'log'))
        ));

        // Only report success once the job holds its lock, so a status request right after
        // this one already sees it running. The job retries its lock while we look.
        $deadline = microtime(true) + 3.0;

        while (!$this->isRunning($device) && microtime(true) < $deadline) {
            usleep(50000);
        }

        return true;
    }

    /**
     * Output of the device's most recent background job.
     */
    public function getLog(string $device): ?string
    {
        $log = @file_get_contents($this->path($device, 'log'));

        return $log === false ? null : $log;
    }

    private function path(string $device, string $extension): string
    {
        return sprintf('%s/guide-%s.%s', $this->lockDirectory, preg_replace('/[^A-Za-z0-9.-]/', '_', $device), $extension);
    }
}
