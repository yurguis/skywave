<?php

namespace Skywave\Tests;

use PHPUnit\Framework\TestCase;
use Skywave\Platform;

/**
 * The commands that start a detached process, checked as text.
 *
 * The Windows forms cannot be executed here, so they are built as strings and read back.
 * That is the only coverage they can have short of a Windows runner, and it is worth having:
 * the bug these replace was a command that was never valid under cmd.exe in the first place.
 */
class PlatformTest extends TestCase
{
    public function testTheHostIsReportedFromPhpItself(): void
    {
        $this->assertSame(PHP_OS_FAMILY === 'Windows', Platform::isWindows());
    }

    public function testThePosixCommandIsUnchangedFromWhatShipped(): void
    {
        // Character for character what this project has always run. A difference here is a
        // change to the one path that is known to work.
        $this->assertSame(
            "setsid 'ffmpeg' '-i' 'x' > '/tmp/a.log' 2>&1 < /dev/null & echo $!",
            Platform::posixDetachedCommand(['ffmpeg', '-i', 'x'], '/tmp/a.log')
        );
    }

    public function testTheWindowsCommandAsksPowerShellForTheChildsOwnPid(): void
    {
        $script = $this->script(['ffmpeg.exe', '-i', 'x'], 'C:\\logs\\a.log');

        // -PassThru and .Id are what make this usable: start /B detaches but reports no pid,
        // and a cmd /c wrapper would report the wrapper's.
        $this->assertStringContainsString('-PassThru', $script);
        $this->assertStringContainsString(').Id', $script);
        $this->assertStringContainsString('-WindowStyle Hidden', $script);
        $this->assertStringContainsString("-FilePath 'ffmpeg.exe'", $script);
        // One pre-quoted string, not a PowerShell array -- see the space-in-an-argument test.
        $this->assertStringContainsString("-ArgumentList '-i x'", $script);
    }

    public function testTheWindowsCommandSendsEachStreamToItsOwnFile(): void
    {
        // Start-Process refuses to point both at one file, so the halves are kept apart and
        // the readers put them back together.
        $script = $this->script(['ffmpeg.exe'], 'a.log');

        $this->assertStringContainsString("-RedirectStandardOutput 'a.log'", $script);
        $this->assertStringContainsString("-RedirectStandardError 'a.log.err'", $script);
        $this->assertSame('a.log.err', Platform::errorLog('a.log'));
    }

    public function testAProgramWithNoArgumentsLeavesTheListOutEntirely(): void
    {
        // An empty -ArgumentList is an error in PowerShell rather than an omission.
        $this->assertStringNotContainsString('-ArgumentList', $this->script(['ffmpeg.exe'], 'a.log'));
    }

    public function testAQuoteInsideAnArgumentCannotEndTheArgument(): void
    {
        // PowerShell reads two single quotes inside a single-quoted string as one literal
        // quote, which is what keeps a path like O'Brien from becoming two arguments.
        $this->assertStringContainsString("'O''Brien'", $this->script(['ffmpeg.exe', "O'Brien"], 'a.log'));
    }

    public function testNothingOnTheCommandLineIsLeftForCmdToMangle(): void
    {
        // The reason the first attempt started nothing at all on Windows. shell_exec runs
        // this through "cmd /c", and cmd's rules for stripping quotes around an argument
        // are unpredictable enough that the script arrived taken apart -- no pid, and not
        // even the redirect files it was told to create. Base64 has no quotes in it, so
        // there is nothing to take apart.
        $command = Platform::windowsDetachedCommand(['ffmpeg.exe', '-i', 'x'], 'a.log');

        $this->assertStringNotContainsString('"', $command);
        $this->assertStringContainsString('-EncodedCommand ', $command);
    }

    public function testAnArgumentWithASpaceInItStaysOneArgument(): void
    {
        // Start-Process does not quote array elements that contain a space; it joins them
        // raw. "-var_stream_map v:0,a:0 v:1,a:1" reached ffmpeg as four arguments, and it
        // went looking for a file called v:10.ts -- v:1 followed by 0.ts. Measured on
        // Windows, not reasoned about.
        $script = $this->script(['ffmpeg.exe', '-var_stream_map', 'v:0,a:0 v:1,a:1'], 'a.log');

        $this->assertStringContainsString('-ArgumentList \'-var_stream_map "v:0,a:0 v:1,a:1"\'', $script);
    }

    public function testQuotingLeavesOrdinaryArgumentsAlone(): void
    {
        // The common case stays readable, which matters when the next person is reading
        // this command out of an error message.
        $this->assertSame('-hide_banner', Platform::windowsArgument('-hide_banner'));
        $this->assertSame('[0:v:0]split=2[s0][s1]', Platform::windowsArgument('[0:v:0]split=2[s0][s1]'));
    }

    public function testQuotingHandlesQuotesAndTrailingBackslashes(): void
    {
        // CommandLineToArgvW reads two quotes inside a quoted run as one literal, and a
        // trailing backslash run would otherwise escape the closing quote.
        $this->assertSame('"a\\"b"', Platform::windowsArgument('a"b'));
        $this->assertSame('""', Platform::windowsArgument(''));
        $this->assertSame('"a b\\\\\\\\"', Platform::windowsArgument('a b\\\\'));
    }

    public function testTheEncodedScriptIsWhatPowerShellWillRead(): void
    {
        // -EncodedCommand wants base64 of UTF-16LE, and a script it cannot decode is a
        // silent failure of exactly the kind this replaced.
        $script = '(Start-Process -FilePath \'x\').Id';

        $this->assertSame($script, Platform::decodeFromPowerShell(Platform::encodeForPowerShell($script)));
    }

    /**
     * The script inside a Windows spawn command, decoded.
     */
    private function script(array $arguments, string $logFile): string
    {
        preg_match('/-EncodedCommand (\S+)/', Platform::windowsDetachedCommand($arguments, $logFile), $match);

        return Platform::decodeFromPowerShell($match[1] ?? '');
    }

    public function testStoppingAsksBeforeItInsists(): void
    {
        // The same two steps the POSIX path makes with SIGTERM and then SIGKILL.
        $this->assertStringNotContainsString(' /F', Platform::windowsKillCommand(42, false));
        $this->assertStringContainsString(' /F', Platform::windowsKillCommand(42, true));

        // /T because ffmpeg may have children, and a half-written recording is worse than a
        // slow stop.
        $this->assertStringContainsString('/T', Platform::windowsKillCommand(42, false));
    }

    public function testALivenessCheckFiltersByPidAndDropsTheHeader(): void
    {
        $command = Platform::windowsRunningCommand(42);

        $this->assertStringContainsString('"PID eq 42"', $command);
        $this->assertStringContainsString('/NH', $command);
    }

    public function testTheWindowsCommandKeepsWhatPowerShellSaysWhenItFails(): void
    {
        // shell_exec keeps only stdout and PowerShell reports failures on stderr, so
        // without this a Start-Process that started nothing came back as an empty string --
        // and the log file it was told to write does not exist either, leaving no witness.
        $this->assertStringEndsWith('2>&1', Platform::windowsDetachedCommand(['ffmpeg.exe'], 'a.log'));
    }

    public function testAReportedPidIsReadBackAsANumber(): void
    {
        $this->assertSame(5432, Platform::pidFromOutput('5432'));
        $this->assertSame(5432, Platform::pidFromOutput("5432\n"));
        $this->assertSame(5432, Platform::pidFromOutput("5432\r\n"));
    }

    public function testAWarningAheadOfThePidDoesNotHideIt(): void
    {
        // With stderr folded in, a warning can arrive before the number. Casting the whole
        // output with (int) would read that warning as a pid of zero and blame the spawn
        // for something that actually succeeded.
        $this->assertSame(5432, Platform::pidFromOutput("WARNING: something happened\n5432"));
    }

    public function testOutputWithNoPidInItIsNotAPid(): void
    {
        $this->assertSame(0, Platform::pidFromOutput('Start-Process : This command cannot be run'));
        $this->assertSame(0, Platform::pidFromOutput(''));
        $this->assertSame(0, Platform::pidFromOutput("   \n  "));
    }

    public function testThePidSurvivesPowerShellWrappingItInClixml(): void
    {
        // Verbatim from Windows. Folding stderr in with 2>&1 makes PowerShell serialise its
        // progress records around the answer, so the pid arrives in the middle of an XML
        // envelope. Reading the whole thing with (int) gives zero, and the spawn would be
        // reported as failed while the process it started ran happily on.
        $real = "#< CLIXML\n19920\n<Objs Version=\"1.1.0.1\""
              . " xmlns=\"http://schemas.microsoft.com/powershell/2004/04\">"
              . "<Obj S=\"progress\" RefId=\"0\"></Obj></Objs>";

        $this->assertSame(19920, Platform::pidFromOutput($real));
        $this->assertSame(0, (int) trim($real), 'what the naive reading would have given');
    }

    public function testTheHostPicksItsOwnForm(): void
    {
        $chosen = Platform::detachedCommand(['ffmpeg', '-i', 'x'], 'a.log');
        $expect = Platform::isWindows()
            ? Platform::windowsDetachedCommand(['ffmpeg', '-i', 'x'], 'a.log')
            : Platform::posixDetachedCommand(['ffmpeg', '-i', 'x'], 'a.log');

        $this->assertSame($expect, $chosen);
    }
}
