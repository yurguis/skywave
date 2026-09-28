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
     * Two layers of quoting, because every shell in the chain takes its own bite. The
     * child's arguments are quoted the way Windows puts a command line back together, since
     * Start-Process otherwise splits any value holding a space. That puts double quotes
     * inside the script, so the script travels as base64 rather than inside the quotes
     * cmd.exe would cut it on.
     *
     * @param string[] $arguments
     */
    public static function windowsDetachedCommand(array $arguments, string $logFile): string
    {
        $program = array_shift($arguments) ?? '';

        $parts = [
            '-FilePath ' . self::quoteForPowerShell($program),
        ];

        // One pre-quoted string rather than a PowerShell array, because Start-Process does
        // not quote array elements that contain a space: it joins them raw, and the process
        // on the other side sees two arguments where one was meant. That is not theoretical
        // -- "-var_stream_map v:0,a:0 v:1,a:1" arrived at ffmpeg as four arguments, and it
        // went looking for a file called "v:10.ts". Quoting the child's command line here
        // means CommandLineToArgvW puts it back together exactly as it was handed over.
        //
        // An empty -ArgumentList is an error rather than an omission, so it is left out.
        if ($arguments !== []) {
            $parts[] = '-ArgumentList ' . self::quoteForPowerShell(
                implode(' ', array_map([self::class, 'windowsArgument'], $arguments))
            );
        }

        $parts[] = '-RedirectStandardOutput ' . self::quoteForPowerShell($logFile);
        $parts[] = '-RedirectStandardError ' . self::quoteForPowerShell(self::errorLog($logFile));
        $parts[] = '-WindowStyle Hidden';
        $parts[] = '-PassThru';

        // -EncodedCommand rather than -Command. Quoting the child's command line above puts
        // double quotes inside the script, and shell_exec runs all of this through "cmd /c",
        // where the first inner quote would end the outer one and hand PowerShell a script
        // cut in half. Base64 has no quotes in it, so cmd has nothing to cut.
        //
        // 2>&1 because PowerShell reports its failures on stderr and shell_exec keeps only
        // stdout. Without it a Start-Process that started nothing came back as an empty
        // string, indistinguishable from any other kind of nothing.
        return sprintf(
            'powershell -NoProfile -NonInteractive -EncodedCommand %s 2>&1',
            self::encodeForPowerShell(sprintf('(Start-Process %s).Id', implode(' ', $parts)))
        );
    }

    /**
     * A PowerShell script as -EncodedCommand wants it: base64 of UTF-16LE.
     */
    public static function encodeForPowerShell(string $script): string
    {
        return base64_encode(mb_convert_encoding($script, 'UTF-16LE', 'UTF-8'));
    }

    /**
     * The script inside a Windows spawn command, for reading it back.
     */
    public static function decodeFromPowerShell(string $encoded): string
    {
        return mb_convert_encoding((string) base64_decode($encoded, true), 'UTF-8', 'UTF-16LE');
    }

    /**
     * One argument, quoted the way Windows takes a command line apart again.
     *
     * CommandLineToArgvW splits on whitespace unless a run is quoted, treats a backslash as
     * an escape only when a quote follows it, and reads two quotes inside a quoted run as
     * one literal quote. Anything with neither whitespace nor a quote in it needs nothing
     * doing, which keeps the common case readable.
     */
    public static function windowsArgument(string $value): string
    {
        if ($value !== '' && !preg_match('/[\s"]/', $value)) {
            return $value;
        }

        // Double the backslashes that precede a quote, then escape the quote itself; and
        // double a trailing run, which would otherwise escape the closing quote.
        $escaped = (string) preg_replace('/(\\\\*)"/', '$1$1\\\\"', $value);
        $escaped = (string) preg_replace('/(\\\\+)$/', '$1$1', $escaped);

        return '"' . $escaped . '"';
    }

    /**
     * The pid a spawn reported, or zero when it reported none.
     *
     * The last all-digit line rather than the whole output: with stderr folded in, a
     * warning can arrive ahead of the number, and casting the lot with (int) would read
     * that warning as a pid of zero and blame the wrong thing.
     */
    public static function pidFromOutput(string $output): int
    {
        $lines = array_reverse(preg_split('/\R/', trim($output)) ?: []);

        foreach ($lines as $line) {
            $line = trim($line);

            if ($line !== '' && ctype_digit($line)) {
                return (int) $line;
            }
        }

        return 0;
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
