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
        $command = Platform::windowsDetachedCommand(['ffmpeg.exe', '-i', 'x'], 'C:\\logs\\a.log');

        // -PassThru and .Id are what make this usable: start /B detaches but reports no pid,
        // and a cmd /c wrapper would report the wrapper's.
        $this->assertStringContainsString('-PassThru', $command);
        $this->assertStringContainsString(').Id', $command);
        $this->assertStringContainsString('-WindowStyle Hidden', $command);
        $this->assertStringContainsString("-FilePath 'ffmpeg.exe'", $command);
        $this->assertStringContainsString("-ArgumentList '-i','x'", $command);
    }

    public function testTheWindowsCommandSendsEachStreamToItsOwnFile(): void
    {
        // Start-Process refuses to point both at one file, so the halves are kept apart and
        // the readers put them back together.
        $command = Platform::windowsDetachedCommand(['ffmpeg.exe'], 'a.log');

        $this->assertStringContainsString("-RedirectStandardOutput 'a.log'", $command);
        $this->assertStringContainsString("-RedirectStandardError 'a.log.err'", $command);
        $this->assertSame('a.log.err', Platform::errorLog('a.log'));
    }

    public function testAProgramWithNoArgumentsLeavesTheListOutEntirely(): void
    {
        // An empty -ArgumentList is an error in PowerShell rather than an omission.
        $this->assertStringNotContainsString('-ArgumentList', Platform::windowsDetachedCommand(['ffmpeg.exe'], 'a.log'));
    }

    public function testAQuoteInsideAnArgumentCannotEndTheArgument(): void
    {
        // PowerShell reads two single quotes inside a single-quoted string as one literal
        // quote, which is what keeps a path like O'Brien from becoming two arguments.
        $command = Platform::windowsDetachedCommand(['ffmpeg.exe', "O'Brien"], 'a.log');

        $this->assertStringContainsString("'O''Brien'", $command);
    }

    public function testTheWholeScriptStaysFreeOfDoubleQuotes(): void
    {
        // cmd.exe needs the script wrapped in double quotes, so a double quote anywhere
        // inside it would end the script early.
        $command = Platform::windowsDetachedCommand(['ffmpeg.exe', '-i', 'x'], 'a.log');
        $script  = substr($command, (int) strpos($command, '-Command ') + strlen('-Command '));

        $this->assertSame(2, substr_count($script, '"'), 'only the pair cmd.exe needs');
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

    public function testTheHostPicksItsOwnForm(): void
    {
        $chosen = Platform::detachedCommand(['ffmpeg', '-i', 'x'], 'a.log');
        $expect = Platform::isWindows()
            ? Platform::windowsDetachedCommand(['ffmpeg', '-i', 'x'], 'a.log')
            : Platform::posixDetachedCommand(['ffmpeg', '-i', 'x'], 'a.log');

        $this->assertSame($expect, $chosen);
    }
}
