<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave\Dvr;

use RuntimeException;
use Skywave\Platform;

/**
 * An ffmpeg process that outlives the request or tick that started it.
 *
 * On a POSIX host setsid puts the process in its own session, so it survives the PHP worker
 * that spawned it, and the pid it reports is also its process group, which is what stop()
 * signals. Windows has neither, so it starts the process through PowerShell and stops it
 * with taskkill; see Skywave\Platform, where both forms are built.
 *
 * Only the container that started a process can signal it: process ids do not cross
 * containers.
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
        $program = basename($arguments[0] ?? 'the process');
        $output  = (string) shell_exec(Platform::detachedCommand($arguments, $logFile));
        $pid     = Platform::pidFromOutput($output);

        if ($pid <= 0) {
            // Which witness survives depends on how far it got: the program's own log when
            // it ran and failed, and what the shell printed when it never ran at all -- on
            // Windows that is the only one there is, since the log is never created.
            $printed = trim($output);
            $why     = self::lastLogLine($logFile)
                ?? ($printed === '' ? "it printed nothing and wrote no $logFile" : $printed);

            throw new RuntimeException("Unable to start $program ($why)");
        }

        return $pid;
    }

    public static function isRunning(int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }

        if (Platform::isWindows()) {
            // There is no ext-posix here and no /proc, so the process table is the only
            // answer. tasklist filters by pid and prints the row, or an "INFO:" line when
            // nothing matches, so the pid itself is what to look for.
            $shown = (string) shell_exec(Platform::windowsRunningCommand($pid));

            return str_contains($shown, (string) $pid);
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
        // Windows cannot send both streams to one file, so the other half sits beside it.
        // On a POSIX host that file never exists and this reads exactly what it always did.
        $written = array_merge(
            @file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [],
            @file(Platform::errorLog($file), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []
        );

        $lines = array_filter(
            $written,
            fn (string $line) => !preg_match('/Invalid frame dimensions 0x0|Last message repeated|corrupt decoded frame/', $line)
        );

        return $lines === [] ? null : (string) end($lines);
    }

    private static function signal(int $pid, int $signal): void
    {
        if (Platform::isWindows()) {
            // Windows has no signals; SIGKILL becomes taskkill's /F.
            exec(Platform::windowsKillCommand($pid, $signal === self::SIGKILL));

            return;
        }

        if (function_exists('posix_kill')) {
            // A negative pid signals the whole process group, so ffmpeg's children go too.
            posix_kill(-$pid, $signal);

            return;
        }

        exec(sprintf('kill -%d -- -%d 2>/dev/null', $signal, $pid));
    }
}
