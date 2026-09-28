<?php

declare(strict_types=1);
/**
 * @author Yurguis Garcia <yurguis@gmail.com>
 */

namespace Skywave;

/**
 * Where the difference between a POSIX host and Windows is spelled out, once.
 *
 * Three places start a process that has to outlive the request or tick that spawned it, and
 * all three did it the same POSIX way: `setsid`, a backgrounding `&`, and the shell's `$!`
 * for the pid. None of that exists under `cmd.exe`. `setsid` is util-linux, `/dev/null` is
 * not a device, `&` separates commands rather than backgrounding them, and `echo $!` prints
 * those two characters. So on Windows the command started nothing and reported either a pid
 * of zero or -- in the guide's case, which never looked at a pid -- success.
 *
 * The commands are built here as strings rather than run here, so the Windows forms can be
 * tested on a machine that cannot execute them. That is the only coverage they can have
 * short of a Windows runner.
 */
final class Platform
{
    public static function isWindows(): bool
    {
        return PHP_OS_FAMILY === 'Windows';
    }

    /**
     * The command that starts a process detached, for this host.
     *
     * @param string[] $arguments the command and its arguments, unescaped
     */
    public static function detachedCommand(array $arguments, string $logFile): string
    {
        return self::isWindows()
            ? self::windowsDetachedCommand($arguments, $logFile)
            : self::posixDetachedCommand($arguments, $logFile);
    }

    /**
     * Unchanged from what this project has always run, character for character.
     *
     * setsid puts the process in its own session so it survives the PHP worker that spawned
     * it, and the pid it reports is also its process group, which is what a signal targets.
     *
     * @param string[] $arguments
     */
    public static function posixDetachedCommand(array $arguments, string $logFile): string
    {
        return sprintf(
            'setsid %s > %s 2>&1 < /dev/null & echo $!',
            implode(' ', array_map('escapeshellarg', $arguments)),
            escapeshellarg($logFile)
        );
    }

    /**
     * PowerShell's Start-Process, which is the one form that both detaches and hands back
     * the child's own pid.
     *
     * `start /B` detaches but reports no pid at all, and a `cmd /c` wrapper would report the
     * wrapper's pid rather than ffmpeg's, which would defeat the image-name check in
     * isRunning(). -PassThru gives the real child.
     *
     * Start-Process refuses to point both streams at one file, so stdout goes to the log and
     * stderr beside it. ffmpeg writes to stderr and a PHP job writes to stdout, so between
     * the two every caller's output is kept; the readers merge them. On a POSIX host the
     * second file is never created and nothing changes.
     *
     * Every value is single-quoted for PowerShell, so the whole script can sit inside the
     * double quotes cmd.exe needs without the two levels ever meeting.
     *
     * @param string[] $arguments
     */
    public static function windowsDetachedCommand(array $arguments, string $logFile): string
    {
        $program = array_shift($arguments) ?? '';

        $parts = [
            '-FilePath ' . self::quoteForPowerShell($program),
        ];

        // An empty -ArgumentList is an error rather than an omission, so it is left out.
        if ($arguments !== []) {
            $parts[] = '-ArgumentList ' . implode(',', array_map([self::class, 'quoteForPowerShell'], $arguments));
        }

        $parts[] = '-RedirectStandardOutput ' . self::quoteForPowerShell($logFile);
        $parts[] = '-RedirectStandardError ' . self::quoteForPowerShell(self::errorLog($logFile));
        $parts[] = '-WindowStyle Hidden';
        $parts[] = '-PassThru';

        return sprintf(
            'powershell -NoProfile -NonInteractive -Command "(Start-Process %s).Id"',
            implode(' ', $parts)
        );
    }

    /**
     * Where the second half of a Windows process's output lands.
     */
    public static function errorLog(string $logFile): string
    {
        return $logFile . '.err';
    }

    /**
     * Whether a pid is still a live process, asked of Windows.
     *
     * tasklist prints a header even when nothing matches unless /NH is given, and prints an
     * "INFO:" line instead of a row when the filter finds nothing, so the caller looks for
     * the pid in the output rather than for emptiness.
     */
    public static function windowsRunningCommand(int $pid): string
    {
        return sprintf('tasklist /FI "PID eq %d" /NH 2>NUL', $pid);
    }

    /**
     * Stop a process and its children.
     *
     * /T takes the tree, because ffmpeg is often started behind a wrapper and a recording
     * left half written is worse than a slow stop. Without /F this asks; with /F it insists,
     * which is the same two-step the POSIX path makes with SIGTERM then SIGKILL.
     */
    public static function windowsKillCommand(int $pid, bool $force): string
    {
        return sprintf('taskkill /PID %d /T%s 2>NUL', $pid, $force ? ' /F' : '');
    }

    /**
     * A value PowerShell will read as one literal string.
     */
    private static function quoteForPowerShell(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }
}
