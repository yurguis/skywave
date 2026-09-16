<?php declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use RuntimeException;

/**
 * An ffmpeg process that outlives the request or tick that started it.
 *
 * setsid puts the process in its own session, so it survives the PHP worker that spawned
 * it; the pid it reports is also its process group, which is what stop() signals. Only the
 * container that started a process can signal it: process ids do not cross containers.
 */
class DetachedProcess
{
    private const SIGTERM = 15;
    private const SIGKILL = 9;

    /**
     * @param string[] $arguments the command and its arguments, unescaped
     * @return int the pid, which is also the process group
     */
    public static function start(array $arguments, string $logFile): int
    {
        $pid = (int) trim((string) shell_exec(sprintf(
            'setsid %s > %s 2>&1 < /dev/null & echo $!',
            implode(' ', array_map('escapeshellarg', $arguments)),
            escapeshellarg($logFile)
        )));

        if ($pid <= 0) {
            throw new RuntimeException('Unable to start ' . basename($arguments[0] ?? 'the process'));
        }

        return $pid;
    }

    public static function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (is_dir('/proc/self')) {
            // Make sure the pid still belongs to an ffmpeg that has not exited.
            $cmdline = @file_get_contents("/proc/$pid/cmdline");
            $stat    = @file_get_contents("/proc/$pid/stat");

            return $cmdline !== false && str_contains($cmdline, 'ffmpeg') && !preg_match('/\) [ZX] /', (string) $stat);
        }

        return function_exists('posix_kill') && posix_kill($pid, 0);
    }

    /**
     * Ask the process to finish, then insist. ffmpeg closes its output cleanly on SIGTERM,
     * so a recording or playlist is never left half written unless it ignores the signal.
     */
    public static function stop(int $pid, float $graceSeconds = 3.0): void
    {
        if (!self::isRunning($pid)) {
            return;
        }

        self::signal($pid, self::SIGTERM);

        for ($waited = 0.0; $waited < $graceSeconds && self::isRunning($pid); $waited += 0.1) {
            usleep(100000);
        }

        if (self::isRunning($pid)) {
            self::signal($pid, self::SIGKILL);
        }
    }

    /**
     * The last meaningful line of a process log. Probing a broadcast's other programs logs
     * harmless decoder complaints that would otherwise hide the real cause.
     */
    public static function lastLogLine(string $file): ?string
    {
        $lines = array_filter(
            @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            fn(string $line) => !preg_match('/Invalid frame dimensions 0x0|Last message repeated|corrupt decoded frame/', $line)
        );

        return $lines === [] ? null : (string) end($lines);
    }

    private static function signal(int $pid, int $signal): void
    {
        if (function_exists('posix_kill')) {
            // A negative pid signals the whole process group, so ffmpeg's children go too.
            posix_kill(-$pid, $signal);

            return;
        }

        exec(sprintf('kill -%d -- -%d 2>/dev/null', $signal, $pid));
    }
}
